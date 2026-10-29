package com.echoledger.mobile.recording

import android.annotation.SuppressLint
import android.app.ForegroundServiceStartNotAllowedException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord
import android.media.MediaRecorder
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.ParcelFileDescriptor
import android.provider.Settings
import android.util.Log
import androidx.core.app.ServiceCompat
import androidx.lifecycle.LifecycleService
import androidx.lifecycle.lifecycleScope
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.calls.CallEffect
import com.echoledger.mobile.calls.CallEvent
import com.echoledger.mobile.calls.CallState
import com.echoledger.mobile.calls.CallStateMachine
import com.echoledger.mobile.calls.PhoneCallLog
import com.echoledger.mobile.calls.PostCallPrompt
import com.echoledger.mobile.calls.SamsungCallImport
import com.echoledger.mobile.capture.ICallListener
import com.echoledger.mobile.core.CallTargets
import com.echoledger.mobile.core.CallerNames
import com.echoledger.mobile.core.Devanagari
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.core.PhoneCalls
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.Speakers
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.CallerDto
import com.echoledger.mobile.data.LanguageMarkDto
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.OpenSessionRequest
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.UsageReport
import com.echoledger.mobile.net.CaptionTokens
import com.echoledger.mobile.net.DiscardWorker
import com.echoledger.mobile.net.SttEvent
import com.echoledger.mobile.net.SttStreamer
import com.echoledger.mobile.net.UploadWorker
import com.echoledger.mobile.net.UsageWorker
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.overlay.CaptionFeed
import com.echoledger.mobile.overlay.CaptionLine
import com.echoledger.mobile.overlay.CaptionPanel
import com.echoledger.mobile.overlay.ControlPill
import com.echoledger.mobile.ui.formatDuration
import com.echoledger.mobile.widget.WidgetStateStore
import java.io.File
import java.time.Instant
import java.util.UUID
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit
import kotlin.concurrent.thread
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import okhttp3.OkHttpClient

/**
 * Foreground service for all capture.
 * - **Armed** (Android 14+, helper connected): special-use FGS, no mic access in this process. The shell-UID helper
 *   detects WhatsApp calls and streams both channels through a pipe, so a call that happens while the app is in the
 *   background can still be saved (a mic FGS cannot be started from the background).
 * - **Manual** (in-app button, notification action, widget, QS tile): records via the helper when available, else
 *   the local mic (mic-type FGS; started only from these exempt entry points).
 * After a call the user is asked "Save this recording?" via a notification — never during the call.
 *
 * Every start arrives through startForegroundService, so every exit path calls startForeground first: stopping a
 * service that never went foreground crashes the app.
 */
class RecordingService : LifecycleService() {
    companion object {
        const val ACTION_ARM = "com.echoledger.mobile.ARM"
        const val ACTION_DISARM = "com.echoledger.mobile.DISARM"
        const val ACTION_TOGGLE = "com.echoledger.mobile.TOGGLE"
        const val ACTION_STOP = "com.echoledger.mobile.STOP"
        const val ACTION_PAUSE = "com.echoledger.mobile.PAUSE"
        const val ACTION_RESUME = "com.echoledger.mobile.RESUME"
        const val ACTION_MARK = "com.echoledger.mobile.MARK"
        const val ACTION_TAG_LAST = "com.echoledger.mobile.TAG_LAST"
        const val ACTION_UNDO_MARK = "com.echoledger.mobile.UNDO_MARK"

        /** "Record" on the "Record this phone call?" notification (Record phone calls: Ask each time, since v1.2). */
        const val ACTION_RECORD_PHONE_CALL = "com.echoledger.mobile.RECORD_PHONE_CALL"

        /** The language chip (bubble, Record tab): [EXTRA_LANGUAGE] is the new code, absent for automatic. */
        const val ACTION_LANGUAGE = "com.echoledger.mobile.LANGUAGE"
        const val EXTRA_TAG = "tag"
        const val EXTRA_LANGUAGE = "language"
        private const val TAG = "EchoLedgerRec"
        private const val TICK_MS = 1_000L
        private const val WATCHDOG_MS = 5_000L

        @Volatile var isRecording = false
            private set

        /** Auto-record is armed in this process (the app re-arms it on opening when the setting is on but this is not). */
        @Volatile var isArmed = false
            internal set

        /** What the Record tab shows while recording: pause state, timer, meters, bookmarks and the far-side warning. */
        val live = MutableStateFlow(LiveRecording())

        /** The control pill (since v1.3): the overlay draws it and the Live view mirrors it. */
        val pillState = MutableStateFlow(PillState(mode = PillState.Mode.HIDDEN))

        /** Live captions of the recording in progress, for the caption panel and the Live view. */
        val captionFeed = MutableStateFlow(CaptionFeed())

        /** Caption lines kept for the Live view (the oldest go first). */
        private const val MAX_CAPTION_LINES = 500

        const val ACTION_CAPTIONS = "com.echoledger.mobile.CAPTIONS"
        const val ACTION_DISCARD_NOW = "com.echoledger.mobile.DISCARD_NOW"
        const val ACTION_UNDO_DISCARD = "com.echoledger.mobile.UNDO_DISCARD"
        const val ACTION_SHOW_CONTROLS = "com.echoledger.mobile.SHOW_CONTROLS"

        /** Where a Stop or Record tap came from (notification, widget, tile, app), for the capture log. */
        const val EXTRA_FROM = "from"

        fun intent(context: Context, action: String, from: String? = null) =
            Intent(context, RecordingService::class.java).setAction(action).apply { from?.let { putExtra(EXTRA_FROM, it) } }
    }

    private val graph get() = (application as EchoLedgerApp).graph
    private val main = Handler(Looper.getMainLooper())
    private val wsClient by lazy { OkHttpClient.Builder().pingInterval(20, TimeUnit.SECONDS).build() }
    private var callState: CallState = CallState.Idle
    private var armed = false
    private var inForeground = false
    private var finalizing = 0
    private var destroyed = false
    private var capture: Capture? = null
    private var pill: ControlPill? = null
    private var panel: CaptionPanel? = null

    /** Latest settings, kept warm so nothing reads DataStore synchronously. */
    @Volatile private var settings = AppSettings()

    private val callListener = object : ICallListener.Stub() {
        override fun onCallStarted(packageName: String?) {
            val app = SourceApp.fromPackage(packageName) ?: SourceApp.OTHER
            CaptureLog.add(this@RecordingService, "call started", app.label)
            main.post { onCallEvent(CallEvent.CallStarted(app, UUID.randomUUID().toString(), System.currentTimeMillis())) }
        }

        override fun onCallEnded() {
            CaptureLog.add(this@RecordingService, "call ended")
            main.post { onCallEvent(CallEvent.CallEnded(System.currentTimeMillis())) }
        }

        override fun onError(message: String?) {
            Log.w(TAG, "helper: $message")
            message?.let {
                CaptureProblems.record(this@RecordingService, it)
                CaptureLog.add(this@RecordingService, "helper", it)
            }
        }

        override fun onCaptureState(state: String?) {
            state ?: return
            CaptureLog.add(this@RecordingService, "capture", state)
            main.post { onHelperState(state) }
        }
    }

    override fun onCreate() {
        super.onCreate()
        lifecycleScope.launch {
            graph.settings.settings.collect {
                // The call-app list reaches the helper as soon as it is edited.
                if (it.extraCallApps != settings.extraCallApps) graph.helper.setCallApps(CallTargets.packages(it.extraCallApps))
                settings = it
            }
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        super.onStartCommand(intent, flags, startId)
        when (intent?.action) {
            ACTION_ARM -> lifecycleScope.launch {
                settings = graph.settings.current()
                arm()
            }

            ACTION_DISARM -> disarm()

            ACTION_TOGGLE -> if (capture != null) {
                stopCapture(fromCall = false, reason = "record button (${intent.getStringExtra(EXTRA_FROM) ?: "unknown"})")
            } else {
                lifecycleScope.launch {
                    settings = graph.settings.current()
                    if (capture == null) startManual()
                }
            }

            ACTION_STOP -> if (capture != null) {
                stopCapture(fromCall = false, reason = "Stop (${intent.getStringExtra(EXTRA_FROM) ?: "unknown"})")
            } else {
                settle()
            }

            ACTION_PAUSE -> capture?.let { pause(it) } ?: settle()

            ACTION_RESUME -> capture?.let { resume(it) } ?: settle()

            ACTION_MARK -> capture?.let { mark(it, intent.getStringExtra(EXTRA_TAG)) } ?: settle()

            ACTION_TAG_LAST -> capture?.let { session ->
                session.notes.tagLast(intent.getStringExtra(EXTRA_TAG))
                refresh(session)
            } ?: settle()

            ACTION_UNDO_MARK -> capture?.let { session ->
                session.notes.undo()
                refresh(session)
            } ?: settle()

            ACTION_LANGUAGE -> capture?.let { setLanguage(it, Languages.canonical(intent.getStringExtra(EXTRA_LANGUAGE))) } ?: settle()

            ACTION_RECORD_PHONE_CALL -> recordAskedPhoneCall()

            // The notification mirrors the control pill (since v1.3).
            ACTION_CAPTIONS -> if (capture != null) onPillEvent(PillEvent.ToggleCaptions) else settle()

            ACTION_DISCARD_NOW -> if (capture != null) onPillEvent(PillEvent.DiscardNow(System.currentTimeMillis())) else settle()

            ACTION_UNDO_DISCARD -> if (capture != null) onPillEvent(PillEvent.Undo) else settle()

            ACTION_SHOW_CONTROLS -> if (capture != null) {
                showControls()
                onPillEvent(PillEvent.ShowControls)
            } else {
                settle()
            }

            else -> settle()
        }
        return START_NOT_STICKY
    }

    /** Enters (or updates) the foreground state. Returns false when the system refuses; never crashes. */
    private fun goForeground(mic: Boolean, title: String, detail: String): Boolean {
        val notification = if (!mic && capture == null) {
            Notifications.armed(this, ready = helperAlive())
        } else {
            Notifications.recording(
                this,
                callTitle(title),
                detail,
                paused = capture?.paused == true,
                // The clock shows recorded time (paused stretches left out).
                startedAtMs = capture?.let { System.currentTimeMillis() - it.recordedMs() },
                discarding = pillState.value.discarding
            )
        }
        val type = when {
            mic -> ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE
            Build.VERSION.SDK_INT >= 34 -> ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE
            else -> ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE
        }
        return try {
            ServiceCompat.startForeground(this, Notifications.ID_FOREGROUND, notification, type)
            inForeground = true
            true
        } catch (e: Exception) {
            // Background start without an exemption, or mic type while not eligible (Android 14+).
            if ((Build.VERSION.SDK_INT >= 31 && e is ForegroundServiceStartNotAllowedException) || e is SecurityException) {
                Log.w(TAG, "foreground start not allowed: ${e.message}")
                false
            } else {
                throw e
            }
        }
    }

    /** "Recording call with Omar" once the call app's notification named the caller (since v1.3), else [title]. */
    private fun callTitle(title: String): String {
        val session = capture ?: return title
        val name = CallerNames.forCall(session.sourceApp, session.startedAt.toEpochMilli(), System.currentTimeMillis())
        return if (name != null && session.channels == 2) "Recording call with $name" else title
    }

    /** Leaves the service in the right state once nothing is recording or being saved. */
    private fun settle() {
        if (capture != null || finalizing > 0 || destroyed) return
        if (armed && goForeground(mic = false, title = "", detail = "")) return
        armed = false
        if (!inForeground) goForeground(mic = false, title = "", detail = "")
        ServiceCompat.stopForeground(this, ServiceCompat.STOP_FOREGROUND_REMOVE)
        inForeground = false
        stopSelf()
    }

    // ---- Armed mode (auto-record WhatsApp calls) ----------------------------------------------------------------

    /**
     * Auto-record on. Without the helper (after a reboot, or it was stopped) it stays on in a "not ready" state that
     * says so and keeps trying to reconnect (Shizuku), instead of turning itself off without a word.
     */
    private fun arm() {
        if (Build.VERSION.SDK_INT < 34) {
            Log.w(TAG, "auto-record needs Android 14+")
            persistArmed(false)
            settle()
            return
        }
        armed = true
        graph.helper.setCallListener(callListener)
        // The voice-call mix is armed now (and again whenever the helper restarts), before any call's voice track exists.
        graph.helper.setCallApps(CallTargets.packages(settings.extraCallApps))
        graph.helper.setArmed(true)
        if (graph.helper.helper == null) graph.helper.bindShizuku()
        if (capture == null && !goForeground(mic = false, title = "", detail = "")) {
            // Android refused a start from the background: the setting stays on (the next check tries again) and a
            // quiet notification offers to turn it back on, instead of auto-record going off without a word.
            armed = false
            graph.helper.setArmed(false)
            graph.helper.setCallListener(null)
            CaptureLog.add(this, "auto-record could not start", "Android did not allow it from the background")
            Notifications.autoRecordOff(this)
            settle()
            return
        }
        Notifications.cancelAutoRecordOff(this)
        CaptureLog.add(this, "auto-record on", if (helperAlive()) "helper ready" else "helper not running")
        isArmed = true
        AutoRecordKeeper.keepChecking(this)
        persistArmed(true)
        main.removeCallbacks(watchdog)
        main.postDelayed(watchdog, WATCHDOG_MS)
    }

    private fun disarm() {
        armed = false
        isArmed = false
        main.removeCallbacks(watchdog)
        graph.helper.setArmed(false)
        graph.helper.setCallListener(null)
        persistArmed(false)
        AutoRecordKeeper.stopChecking(this)
        CaptureLog.add(this, "auto-record off")
        settle()
    }

    private fun helperAlive(): Boolean =
        graph.helper.helper?.asBinder()?.let { b -> runCatching { b.pingBinder() }.getOrDefault(false) } == true

    private var lastReady: Boolean? = null
    private var warnedThisCall = false

    /**
     * While auto-record is on: reconnects a helper that went away (Shizuku), keeps the "ready / not ready" notice
     * true, and when a call starts while the helper is missing says "Call capture isn't ready — tap to fix" at once.
     */
    private val watchdog = object : Runnable {
        override fun run() {
            if (!armed || destroyed) return
            var ready = helperAlive()
            if (!ready) {
                graph.helper.bindShizuku()
                ready = helperAlive()
            }
            if (ready != lastReady) {
                lastReady = ready
                CaptureLog.add(this@RecordingService, "helper", if (ready) "ready" else "not running")
                if (capture == null) goForeground(mic = false, title = "", detail = "")
            }
            val mode = (getSystemService(AUDIO_SERVICE) as AudioManager).mode
            val inCall = mode == AudioManager.MODE_IN_COMMUNICATION ||
                (mode == AudioManager.MODE_IN_CALL && PhoneCalls.mode(settings.phoneCalls) != PhoneCalls.OFF)
            if (inCall && !ready && !warnedThisCall) {
                warnedThisCall = true
                CaptureLog.add(this@RecordingService, "call seen without the helper")
                Notifications.captureNotReady(this@RecordingService)
            }
            if (!inCall) warnedThisCall = false
            main.postDelayed(this, WATCHDOG_MS)
        }
    }

    private fun persistArmed(value: Boolean) {
        lifecycleScope.launch {
            graph.settings.update { it.copy(autoRecordArmed = value) }
            WidgetStateStore.refresh(this@RecordingService)
        }
    }

    /**
     * The helper's capture states (since v1.3): a SIM call holding the app call being recorded (paused until it is
     * back), an other side or phone line that gives no sound (its reason is kept for the after-call card), and my own
     * mute in the call app.
     */
    private fun onHelperState(state: String) {
        val session = capture ?: return
        when (state) {
            "suspended" -> {
                session.held = true
                pillState.value = PillMachine.reduce(pillState.value, PillEvent.Held(true)).first
                refresh(session, "Paused while a phone call holds this call")
            }

            "resumed" -> {
                session.held = false
                pillState.value = PillMachine.reduce(pillState.value, PillEvent.Held(false)).first
                refresh(session)
            }

            "far_silent", "line_silent" -> {
                session.warning = CaptureProblems.recent(this) ?: "The other side of this call gave no sound."
                pillState.value = PillMachine.reduce(pillState.value, PillEvent.Warning(session.warning)).first
                refresh(session, "Not hearing the other side")
            }

            // The call app's own mute: not a fault, so it is only shown while it lasts.
            "self_muted" -> refresh(session, "You muted yourself in the call")

            "self_unmuted" -> refresh(session)
        }
    }

    /** A phone call is ringing on the "Record this phone call?" notification (Ask each time). */
    private var phoneCallAsked = false

    private fun onCallEvent(event: CallEvent) {
        if (event is CallEvent.CallStarted && event.app == SourceApp.PHONE_CALL) {
            // SIM calls follow "Record phone calls" (since v1.2): off, ask each time, or always.
            when (PhoneCalls.mode(settings.phoneCalls)) {
                PhoneCalls.OFF -> return

                PhoneCalls.ASK -> {
                    phoneCallAsked = true
                    CaptureLog.add(this, "phone call", "asked whether to record")
                    Notifications.askRecordPhoneCall(this)
                    return
                }
            }
        } else if (event is CallEvent.CallStarted && !AutoRecordRules.records(event.app, settings.autoRecordApps)) {
            // A call in an app whose auto-record switch is off is not recorded (B1); recording by hand still works.
            return
        }
        if (event is CallEvent.CallEnded && phoneCallAsked) {
            phoneCallAsked = false
            Notifications.cancelAskRecordPhoneCall(this)
        }
        reduceCall(event)
    }

    /** "Record" on the ask notification: the phone call still going on is recorded from now. */
    private fun recordAskedPhoneCall() {
        Notifications.cancelAskRecordPhoneCall(this)
        if (!phoneCallAsked || capture != null) return settle()
        phoneCallAsked = false
        CaptureLog.add(this, "phone call", "recording (asked)")
        reduceCall(CallEvent.CallStarted(SourceApp.PHONE_CALL, UUID.randomUUID().toString(), System.currentTimeMillis()))
    }

    private fun reduceCall(event: CallEvent) {
        val (next, effects) = CallStateMachine.reduce(callState, event)
        callState = next
        for (effect in effects) {
            when (effect) {
                is CallEffect.StartCapture -> startHelperCapture(effect.app.wire, effect.recordingId, fromCall = true)
                is CallEffect.StopCaptureAndAsk -> stopCapture(fromCall = true, reason = "call ended")
                is CallEffect.Discard -> discardCapture(effect.recordingId)
                is CallEffect.Keep -> UploadWorker.enqueue(this, effect.recordingId)
            }
        }
    }

    // ---- Capture ------------------------------------------------------------------------------------------------

    private fun startManual() {
        val id = UUID.randomUUID().toString()
        if (Build.VERSION.SDK_INT >= 34 && graph.helper.helper != null) {
            startHelperCapture(SourceApp.OTHER.wire, id, fromCall = false)
        } else {
            startMicCapture(id)
        }
    }

    private fun startHelperCapture(sourceApp: String, id: String, fromCall: Boolean) {
        if (capture != null) return
        val helper = graph.helper.helper
        if (helper == null) {
            // A call detected without the helper cannot fall back to the mic from the background.
            if (fromCall) settle() else startMicCapture(id)
            return
        }
        val phone = sourceApp == SourceApp.PHONE_CALL.wire
        val session = Capture(id, sourceApp, channels = 2, fromCall = fromCall, clientId = settings.clientId)
        session.title = if (phone) "Recording phone call" else "Recording call"
        session.detail = "Both sides · ${if (fromCall) "auto" else "manual"}"
        capture = session
        if (!goForeground(mic = false, title = session.title, detail = session.detail)) {
            session.cancel()
            capture = null
            settle()
            return
        }
        val pipe = ParcelFileDescriptor.createPipe()
        val micSource = if (settings.micSource == "mic") MediaRecorder.AudioSource.MIC else MediaRecorder.AudioSource.VOICE_COMMUNICATION
        val started = runCatching {
            if (phone) {
                helper.startPhoneCapture(
                    pipe[1],
                    Pcm.CAPTURE_RATE,
                    micSource
                )
            } else {
                helper.startCapture(pipe[1], Pcm.CAPTURE_RATE, micSource)
            }
        }
            .onFailure { CaptureProblems.record(this, "Call capture could not start: ${it.message ?: it.javaClass.simpleName}") }
            .getOrDefault(false)
        CaptureLog.add(
            this,
            "capture start",
            if (started) "helper recording (both sides)" else "helper refused: ${CaptureProblems.recent(this) ?: "no reason given"}"
        )
        pipe[1].close()
        if (!started) {
            pipe[0].close()
            session.cancel()
            capture = null
            // A call that could not be recorded is said so at once (the helper's reason), never silently skipped.
            if (fromCall) {
                Notifications.noAudio(this, CaptureProblems.recent(this), callNotRecorded = true)
                settle()
            } else {
                startMicCapture(id)
            }
            return
        }
        // A phone line that gives both voices on one track is recorded as one channel (speakers told apart later).
        if (phone) session.channels = runCatching { helper.captureChannels() }.getOrDefault(2).coerceIn(1, 2)
        if (session.channels == 1) session.detail = "Both voices on one track · ${if (fromCall) "auto" else "manual"}"
        session.readerThread = thread(name = "el-pipe-reader") {
            java.io.DataInputStream(ParcelFileDescriptor.AutoCloseInputStream(pipe[0])).use { input ->
                // Whole 20 ms stereo frames only, so the two channels never swap when a read ends mid-frame.
                val block = ByteArray(Pcm.CAPTURE_RATE / 50 * 4)
                while (session.active) {
                    val n = runCatching {
                        input.readFully(block)
                        block.size
                    }.getOrElse { 0 }
                    if (n <= 0) break
                    if (session.channels == 1) {
                        val left = Pcm.deinterleave(Pcm.fromLittleEndianBytes(block, n)).first
                        val bytes = Pcm.toLittleEndianBytes(left)
                        session.consume(bytes, bytes.size)
                    } else {
                        session.consume(block, n)
                    }
                }
            }
        }
        session.helperStop = { runCatching { helper.stopCapture() } }
        afterStart(session)
    }

    @SuppressLint("MissingPermission")
    private fun startMicCapture(id: String) {
        if (capture != null) return
        if (checkSelfPermission(android.Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            Log.w(TAG, "RECORD_AUDIO not granted")
            settle()
            return
        }
        val session = Capture(id, SourceApp.OTHER.wire, channels = 1, fromCall = false, clientId = settings.clientId)
        session.micFgs = true
        session.title = "Recording"
        session.detail = "Microphone"
        capture = session
        if (!goForeground(mic = true, title = session.title, detail = session.detail)) {
            session.cancel()
            capture = null
            settle()
            return
        }
        val frames = Pcm.CAPTURE_RATE / 100
        val record = AudioRecord.Builder()
            .setAudioSource(MediaRecorder.AudioSource.VOICE_RECOGNITION)
            .setAudioFormat(
                AudioFormat.Builder().setSampleRate(
                    Pcm.CAPTURE_RATE
                ).setChannelMask(AudioFormat.CHANNEL_IN_MONO).setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
            )
            .setBufferSizeInBytes(
                AudioRecord.getMinBufferSize(Pcm.CAPTURE_RATE, AudioFormat.CHANNEL_IN_MONO, AudioFormat.ENCODING_PCM_16BIT) * 4
            )
            .build()
        // The chosen microphone while connected (B5); the system's choice otherwise.
        CaptureHealth.preferredDevice(this, settings.inputDevice)?.let { record.setPreferredDevice(it) }
        session.readerThread = thread(name = "el-mic-reader") {
            val shorts = ShortArray(frames * 4)
            try {
                record.startRecording()
                while (session.active) {
                    val n = record.read(shorts, 0, shorts.size)
                    if (n > 0) session.consume(Pcm.toLittleEndianBytes(shorts.copyOf(n)), n * 2)
                }
            } finally {
                runCatching { record.stop() }
                record.release()
            }
        }
        afterStart(session)
    }

    private fun afterStart(session: Capture) {
        isRecording = true
        pillState.value =
            PillState(mode = if (settings.pillDot) PillState.Mode.DOT else PillState.Mode.PILL, language = Languages.chip(session.language))
        captionFeed.value = CaptionFeed()
        publish(session)
        showControls()
        main.removeCallbacks(ticker)
        main.postDelayed(ticker, TICK_MS)
        lifecycleScope.launch { WidgetStateStore.refresh(this@RecordingService) }
        // Captions are one tap away on the pill; they open by themselves only with "Show captions during calls" on.
        if (settings.captionsDuringCalls) onPillEvent(PillEvent.ToggleCaptions)
    }

    /** The control pill (since v1.3; replaces the click-through caption bubble). Needs "Display over other apps". */
    private fun showControls() {
        if (!Settings.canDrawOverlays(this)) return
        val current = pill ?: ControlPill(
            this,
            pillState,
            onEvent = { event -> onPillEvent(event) },
            onMoved = { edge, y -> lifecycleScope.launch { graph.settings.update { it.copy(pillEdge = edge, pillY = y) } } },
            edge = settings.pillEdge,
            yFraction = settings.pillY
        ).also { pill = it }
        current.show()
    }

    private fun hideControls() {
        pill?.hide()
        pill = null
        panel?.hide()
        panel = null
        pillState.value = PillState(mode = PillState.Mode.HIDDEN)
        captionFeed.value = CaptionFeed()
    }

    /** A tap on the pill, the caption panel, the notification or the Live view; the machine decides, the effects run here. */
    private fun onPillEvent(event: PillEvent) {
        val before = pillState.value
        val (next, effects) = PillMachine.reduce(before, event)
        pillState.value = next
        if ((next.mode == PillState.Mode.DOT) != (before.mode == PillState.Mode.DOT) && next.mode != PillState.Mode.HIDDEN) {
            lifecycleScope.launch { graph.settings.update { it.copy(pillDot = next.mode == PillState.Mode.DOT) } }
        }
        val session = capture
        for (effect in effects) {
            when (effect) {
                PillEffect.Pause -> session?.let { pause(it) }
                PillEffect.Resume -> session?.let { resume(it) }
                PillEffect.ShowCaptions -> showCaptions(session)
                PillEffect.HideCaptions -> closeCaptionPanel()
                PillEffect.NextLanguage -> session?.let { setLanguage(it, Languages.next(it.language)) }
                PillEffect.Stop -> if (session != null) stopCapture(fromCall = false, reason = "Stop on the control pill (tapped twice)")
                PillEffect.Discard -> session?.let { discardNow(it) }
            }
        }
        // Not after Stop or Discard: the recording notification must not come back.
        capture?.let { refresh(it) }
    }

    /** Opens the caption panel and, the first time, the live caption streams (captions cost money, so only on request). */
    private fun showCaptions(session: Capture?) {
        if (Settings.canDrawOverlays(this)) {
            val current = panel ?: CaptionPanel(
                this,
                captionFeed,
                pillState,
                settings.captionStyle,
                onClose = {
                    onPillEvent(PillEvent.CaptionsClosed)
                    closeCaptionPanel()
                },
                onNextLanguage = { onPillEvent(PillEvent.NextLanguage) },
                onMoved = { bottom -> pill?.avoid(bottom) }
            ).also { panel = it }
            current.show()
        }
        session ?: return
        if (!settings.captionsEnabled) {
            captionFeed.value = captionFeed.value.copy(notice = "Live captions are off in Settings, Captions. The recording continues.")
            return
        }
        if (session.captionsStarted) return
        session.captionsStarted = true
        lifecycleScope.launch { startCaptions(session, session.clientId) }
    }

    /** Closing captions only closes the panel: the recording, and the captions for the Live view, carry on. */
    private fun closeCaptionPanel() {
        panel?.hide()
        panel = null
        pill?.avoid(null)
    }

    /** Discard now, once its 5-second undo has run out: deleted at once, nothing uploaded, the open server session removed. */
    private fun discardNow(session: Capture) {
        if (capture !== session) return
        CaptureLog.add(this, "discarded", "by you during the recording")
        session.serverSessionId?.let { DiscardWorker.enqueue(this, session.id, it) }
        discardCapture(session.id)
    }

    private suspend fun startCaptions(session: Capture, clientId: String?) {
        val api = graph.api() ?: return
        val channels = if (session.channels == 2) listOf("me", "client") else listOf("me")
        val open = OpenSessionRequest(session.id, session.sourceApp, clientId, session.startedAt.toString(), session.language)
        val outcome = CaptionTokens(api).fetch(settings.serverIsV1_1, open, channels, session.serverSessionId) {
            graph.settings.update { it.copy(clientId = null) }
        }
        session.serverSessionId = outcome.sessionId
        if (!session.active) return
        val token = when (outcome) {
            is CaptionTokens.Outcome.Token -> outcome.token

            is CaptionTokens.Outcome.Refused -> {
                // Captions never stop the recording; the caption panel says why they are missing.
                main.post { captionFeed.value = captionFeed.value.copy(notice = outcome.message) }
                return
            }
        }
        if (token.mode != "cloud") return
        session.provider = token.provider
        for (grant in token.grants) {
            if (grant.protocol !in setOf("assemblyai_v3", "elevenlabs_realtime")) continue
            val channel = if (grant.channel == "client") 2 else 1
            val streamer = SttStreamer(grant, { event -> onCaption(session, channel, event) }, wsClient)
            streamer.start()
            session.streamers[channel] = streamer
        }
    }

    // ---- Pause, bookmarks and capture health (B2, B3, B5) ---------------------------------------------------------

    /** Paused audio is dropped: not written, not streamed. The notification and the Record tab follow. */
    private fun pause(session: Capture) {
        session.pause()
        pillState.value = PillMachine.reduce(pillState.value, PillEvent.Paused(true)).first
        refresh(session)
    }

    private fun resume(session: Capture) {
        session.resume()
        pillState.value = PillMachine.reduce(pillState.value, PillEvent.Paused(false)).first
        refresh(session)
    }

    /**
     * The language chip during a recording: live captions reconnect in the new language (the old sockets close, a new
     * token is fetched); the capture never stops, so no audio is lost. Keep transcribes in the chip's language.
     */
    private fun setLanguage(session: Capture, language: String?) {
        if (session.language == language) return
        // A timestamped mark: the server cuts the audio here and transcribes each piece in its language; the first
        // change also records the language the recording started with.
        synchronized(session.languageMarks) {
            if (session.languageMarks.isEmpty()) session.languageMarks += LanguageMarkDto(0, session.language)
            session.languageMarks += LanguageMarkDto(session.recordedMs(), language)
        }
        session.language = language
        pillState.value = PillMachine.reduce(pillState.value, PillEvent.Language(Languages.chip(language))).first
        refresh(session, "Language: ${Languages.label(language)}")
        if (session.streamers.isEmpty()) return
        val old = session.streamers.values.toList()
        session.streamers.clear()
        thread(name = "el-captions-reconnect") {
            old.forEach { it.stop() }
            session.reportUsage(old)
        }
        lifecycleScope.launch { startCaptions(session, session.clientId) }
    }

    private fun mark(session: Capture, tag: String?) {
        val marker = session.notes.mark(session.recordedMs(), tag)
        refresh(session, if (marker == null) "This recording already has 500 bookmarks." else "Bookmarked ${formatDuration(marker.atMs)}")
    }

    /** Updates the recording notification (with [note] instead of the usual detail) and the Record tab. */
    private fun refresh(session: Capture, note: String? = null) {
        val count = session.notes.count()
        val usual = listOfNotNull(
            session.detail,
            "$count bookmark${if (count == 1) "" else "s"}".takeIf { count > 0 },
            Languages.chip(session.language).takeIf { session.language != null }
        ).joinToString(" · ")
        val warning = session.farSideSeconds?.let { "Not hearing the other side for $it s" }
        goForeground(session.micFgs, session.title, note ?: warning ?: usual)
        publish(session)
    }

    private fun publish(session: Capture?) {
        live.value = if (session == null) {
            LiveRecording()
        } else {
            LiveRecording(
                active = true,
                paused = session.paused,
                elapsedMs = session.recordedMs(),
                twoSided = session.channels == 2,
                meLevel = session.meLevel,
                clientLevel = session.clientLevel,
                markers = session.notes.count(),
                lastMarker = session.notes.last(),
                farSideSilentSeconds = session.farSideSeconds,
                language = session.language
            )
        }
    }

    /** Once a second while recording: the far-side silence check and the Record tab's timer and meters. */
    private val ticker = object : Runnable {
        override fun run() {
            val session = capture ?: return
            if (!session.soundLogged && !session.silenceLogged && session.ageMs() >= 5_000) {
                session.silenceLogged = true
                CaptureLog.add(
                    this@RecordingService,
                    "no sound yet",
                    "5 s after start nothing has arrived from ${if (session.channels == 2) "the helper" else "the microphone"}"
                )
            }
            val change = session.farSide.tick(session.silentMs(System.nanoTime()), settings.farSideWarning)
            if (change != null) {
                session.farSideSeconds = change.seconds.takeIf { change.silent }
                val warning = if (change.silent) "Not hearing the other side for ${change.seconds} s." else session.warning
                pillState.value = PillMachine.reduce(pillState.value, PillEvent.Warning(warning)).first
                refresh(session)
            } else {
                publish(session)
            }
            // The pill's clock, and a discard whose undo time has run out.
            onPillEvent(PillEvent.Tick(session.recordedMs(), System.currentTimeMillis()))
            if (capture === session) main.postDelayed(this, TICK_MS)
        }
    }

    private fun onCaption(session: Capture, channel: Int, event: SttEvent) {
        val speaker = Speakers.forChannel(channel, session.channels == 2)
        when (event) {
            // Display only: Hindi letters from an engine that guessed Hindi are shown in Urdu script.
            is SttEvent.Partial -> main.post {
                captionFeed.value = captionFeed.value.copy(partial = CaptionLine(speaker, Devanagari.toUrdu(event.text)))
            }

            is SttEvent.Final -> {
                val offset = session.recordedMs()
                synchronized(session.segments) {
                    session.segments +=
                        SegmentDto(channel, speaker, event.startMs ?: (offset - 1500).coerceAtLeast(0), event.endMs ?: offset, event.text)
                }
                main.post {
                    val feed = captionFeed.value
                    captionFeed.value =
                        feed.copy(
                            lines = (feed.lines + CaptionLine(speaker, Devanagari.toUrdu(event.text))).takeLast(MAX_CAPTION_LINES),
                            partial = null
                        )
                }
            }

            else -> Unit
        }
    }

    /**
     * Who the recording was with (since v1.2): a phone call's number and contact from the call log (READ_CALL_LOG), a
     * WhatsApp call's name from its call notification. Runs on the finishing thread (the call log may lag a moment).
     */
    private fun callerFor(sourceApp: String, startMs: Long, endMs: Long): CallerDto? = if (sourceApp == SourceApp.PHONE_CALL.wire) {
        PhoneCallLog.find(this, startMs, endMs)
    } else {
        CallerNames.forCall(sourceApp, startMs, endMs)?.let { CallerDto(name = it, source = "whatsapp_notification") }
    }

    /**
     * Stops capture, encrypts the file; calls wait for the user's Keep/Discard, manual recordings upload directly.
     * [reason] (what stopped it) goes to the capture log, so a recording that ends before its call says why.
     */
    private fun stopCapture(fromCall: Boolean, reason: String) {
        val session = capture ?: return
        CaptureLog.add(this, "stopped", if (callState is CallState.InCall && !fromCall) "$reason, call still going on" else reason)
        capture = null
        isRecording = false
        main.removeCallbacks(ticker)
        publish(null)
        // The pill and captions go at once; saving the file takes a moment more.
        hideControls()
        finalizing++
        val endedMs = System.currentTimeMillis()
        thread(name = "el-finish") {
            val stored = runCatching {
                session.finish()
                val (markers, pauses) = session.notes.finish()
                val caller = callerFor(session.sourceApp, session.startedAt.toEpochMilli(), endedMs)
                val meta = LocalRecording(
                    id = session.id,
                    sourceApp = session.sourceApp,
                    clientId = session.clientId,
                    startedAt = session.startedAt.toString(),
                    endedAt = Instant.now().toString(),
                    durationMs = Pcm.durationMs(session.encoder.framesWritten),
                    mime = "audio/ogg",
                    codec = "opus",
                    channels = session.channels,
                    sampleRate = Pcm.CAPTURE_RATE,
                    plainSize = 0,
                    sha256 = "",
                    state = if (session.fromCall || fromCall) "pending_decision" else "queued",
                    liveSegments = synchronized(session.segments) { session.segments.sortedBy { it.startMs } },
                    liveProvider = session.provider,
                    serverSessionId = session.serverSessionId,
                    markers = markers,
                    pauses = pauses,
                    language = session.language,
                    languageMarks = synchronized(session.languageMarks) { session.languageMarks.toList() },
                    // Caller names: the call log's number and contact for a phone call, else the name WhatsApp's call
                    // notification showed during this call, when allowed.
                    callerName = caller?.name,
                    callerSource = caller?.source,
                    callerNumber = caller?.number,
                    captureWarning = session.warning
                )
                graph.recordings.importPlainFile(meta, session.file)
            }.onFailure {
                Log.e(TAG, "saving recording failed", it)
                session.file.delete()
            }.getOrNull()
            // A recording that captured no sound (0 ms, or every side digital silence) has nothing to keep or transcribe:
            // say why instead of asking, and never upload it (a provider fails it with "no spoken audio").
            val silent = stored != null && (stored.durationMs <= 0 || session.levels.silent())
            CaptureLog.add(
                this,
                "recording saved",
                if (stored ==
                    null
                ) {
                    "saving failed"
                } else {
                    "${stored.durationMs / 1000} s, ${stored.plainSize} bytes${if (silent) ", no sound: dropped" else ""}"
                }
            )
            if (silent && stored != null) {
                stored.serverSessionId?.let { DiscardWorker.enqueue(this, stored.id, it) }
                graph.recordings.delete(stored.id)
            }
            val decisionTimeout = settings.afterCallTimeout
            val themeMode = settings.themeMode
            main.post {
                if (silent && session.sourceApp == SourceApp.PHONE_CALL.wire) {
                    // The phone line gave nothing: the Phone app's own recording of this call is imported instead.
                    SamsungCallImport.schedule(this, session.startedAt.toEpochMilli(), endedMs)
                    Notifications.noAudio(
                        this,
                        if (SamsungCallImport.phoneAppRecords(this)) {
                            "The phone line gave no sound. EchoLedger will import the Phone app's own recording of this call in a moment."
                        } else {
                            "The phone line gave no sound. Turn on Phone, Settings, Record calls, Auto record calls: EchoLedger then imports those recordings."
                        }
                    )
                } else if (silent) {
                    Notifications.noAudio(this, CaptureProblems.recent(this))
                } else if (stored != null) {
                    if (stored.state == "pending_decision") {
                        PostCallPrompt.show(this, stored, decisionTimeout, themeMode)
                    } else {
                        UploadWorker.enqueue(this, stored.id)
                    }
                }
                finalizing--
                if (!destroyed) lifecycleScope.launch { WidgetStateStore.refresh(this@RecordingService) }
                settle()
            }
        }
    }

    /** Short (missed/rejected) calls are dropped without asking. */
    private fun discardCapture(recordingId: String) {
        val session = capture
        if (session?.id == recordingId) {
            capture = null
            isRecording = false
            main.removeCallbacks(ticker)
            publish(null)
            finalizing++
            hideControls()
            thread(name = "el-discard") {
                session.cancel()
                main.post {
                    finalizing--
                    if (!destroyed) lifecycleScope.launch { WidgetStateStore.refresh(this@RecordingService) }
                    settle()
                }
            }
        }
        graph.recordings.delete(recordingId)
    }

    override fun onDestroy() {
        armed = false
        isArmed = false
        capture?.let { stopCapture(fromCall = false, reason = "the recording service was closed") }
        destroyed = true
        graph.helper.setCallListener(null)
        hideControls()
        super.onDestroy()
    }

    /** One active capture: encoder + live caption streams. */
    private inner class Capture(val id: String, val sourceApp: String, channels: Int, val fromCall: Boolean, val clientId: String?) {
        val startedAt: Instant = Instant.now()
        private val startNanos = System.nanoTime()
        val file = File(cacheDir, "rec-$id.ogg")

        /**
         * 2 = me and the other side apart, 1 = one track (the microphone, or a phone line that gives both voices
         * together). Settled before the first audio arrives; the encoder is made with it.
         */
        @Volatile var channels = channels
        val encoder by lazy { OpusOggEncoder(file, Pcm.CAPTURE_RATE, this.channels, settings.opusBitsPerSide * this.channels) }

        /** Since v1.3: why this recording may be one-sided (the helper's own words), shown after the call. */
        @Volatile var warning: String? = null

        /** Since v1.3: a SIM call is holding this app call; nothing is written until it is back. */
        @Volatile var held = false

        /** Since v1.3 the live caption streams start when captions are first shown (they cost money). */
        var captionsStarted = false
        val streamers = ConcurrentHashMap<Int, SttStreamer>()
        val segments = mutableListOf<SegmentDto>()

        @Volatile var provider: String? = null

        /** The language chip: Settings' language at the start, changed on the bubble or the Record tab. */
        @Volatile var language: String? = settings.transcriptionLanguage

        /** The chip changes, sent with the upload (v1.2). */
        val languageMarks = mutableListOf<LanguageMarkDto>()

        /** The open server session captions used on a v1.1 server (Keep finishes it, Discard deletes it). */
        @Volatile var serverSessionId: String? = null
        var readerThread: Thread? = null
        var helperStop: (() -> Unit)? = null

        @Volatile var active = true

        val notes = RecordingNotes()
        val farSide = FarSideMonitor()

        @Volatile var paused = false
        private var pausedSinceNanos = 0L

        @Volatile private var runningSinceNanos = startNanos

        @Volatile private var lastHeardNanos = 0L

        @Volatile var meLevel = 0f

        @Volatile var clientLevel = 0f

        @Volatile var farSideSeconds: Long? = null

        @Volatile var soundLogged = false
        var silenceLogged = false
        var micFgs = false
        var title = ""
        var detail = ""

        fun ageMs(): Long = (System.nanoTime() - startNanos) / 1_000_000

        /** Recorded time: what the file holds (paused stretches excluded). */
        fun recordedMs(): Long = Pcm.durationMs(encoder.framesWritten)

        /** How long a running two-sided recording has not heard the other side; null when paused or one-sided. */
        fun silentMs(nowNanos: Long): Long? =
            if (channels != 2 || paused) null else (nowNanos - maxOf(lastHeardNanos, runningSinceNanos)) / 1_000_000

        fun pause() {
            if (paused) return
            notes.paused(recordedMs())
            pausedSinceNanos = System.nanoTime()
            paused = true
            meLevel = 0f
            clientLevel = 0f
        }

        fun resume() {
            if (!paused) return
            val now = System.nanoTime()
            notes.resumed((now - pausedSinceNanos) / 1_000_000)
            runningSinceNanos = now
            paused = false
        }

        /** Each side's level over the whole recording, for the capture log (never audio). */
        val levels by lazy { CaptureLevels(this.channels) }
        private val debugWav by lazy { DebugWav.open(this@RecordingService, id, this.channels, Pcm.CAPTURE_RATE) }

        fun consume(bytes: ByteArray, length: Int) {
            if (paused) return // not captured, not streamed
            if (!soundLogged && length > 0) {
                soundLogged = true
                CaptureLog.add(this@RecordingService, "sound arriving", "${(System.nanoTime() - startNanos) / 1_000_000} ms after start")
            }
            encoder.feed(bytes, length)
            debugWav?.write(bytes, length)
            val samples = Pcm.fromLittleEndianBytes(bytes, length)
            levels.add(samples)
            if (channels == 2) {
                val (me, client) = Pcm.deinterleave(samples)
                meLevel = Levels.rms(me)
                clientLevel = Levels.rms(client)
                if (clientLevel >= Levels.HEARD) lastHeardNanos = System.nanoTime()
                if (streamers.isEmpty()) return
                streamers[1]?.sendPcm(Pcm.toLittleEndianBytes(Pcm.downsample48To16(me)))
                streamers[2]?.sendPcm(Pcm.toLittleEndianBytes(Pcm.downsample48To16(client)))
            } else {
                meLevel = Levels.rms(samples)
                if (streamers.isEmpty()) return
                streamers[1]?.sendPcm(Pcm.toLittleEndianBytes(Pcm.downsample48To16(samples)))
            }
        }

        fun finish() {
            active = false
            helperStop?.invoke()
            readerThread?.join(3_000)
            streamers.values.forEach { it.stop() }
            if (streamers.isNotEmpty()) Thread.sleep(1_500) // last caption turns arrive
            reportUsage()
            encoder.finish()
            debugWav?.close()
            CaptureLog.add(this@RecordingService, "levels", levels.describe())
        }

        /** One usage report per captions run (A5.4); a v1 server has no usage endpoint, so nothing is queued. */
        fun reportUsage(run: Collection<SttStreamer> = streamers.values) {
            val opened = run.filter { it.opened() }
            if (opened.isEmpty() || !settings.serverIsV1_1) return
            UsageWorker.report(
                this@RecordingService,
                UsageReport(
                    reportId = UUID.randomUUID().toString(),
                    useCase = "live_captions",
                    provider = provider ?: "assemblyai",
                    sessionId = serverSessionId,
                    streamedMs = opened.maxOf { it.streamedMs() }.coerceAtMost(86_400_000),
                    channels = opened.size.coerceIn(1, 2),
                    endedAt = Instant.now().toString()
                )
            )
        }

        fun cancel() {
            active = false
            helperStop?.invoke()
            readerThread?.join(3_000)
            streamers.values.forEach { it.stop() }
            reportUsage()
            runCatching { encoder.finish() }
            debugWav?.close()
            file.delete()
        }
    }
}
