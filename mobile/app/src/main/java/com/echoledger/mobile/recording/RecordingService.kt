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
import com.echoledger.mobile.calls.PostCallPrompt
import com.echoledger.mobile.capture.ICallListener
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.Speakers
import com.echoledger.mobile.data.AppSettings
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
import com.echoledger.mobile.overlay.CaptionBubble
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

        /** The language chip (bubble, Record tab): [EXTRA_LANGUAGE] is the new code, absent for automatic. */
        const val ACTION_LANGUAGE = "com.echoledger.mobile.LANGUAGE"
        const val EXTRA_TAG = "tag"
        const val EXTRA_LANGUAGE = "language"
        private const val TAG = "EchoLedgerRec"
        private const val TICK_MS = 1_000L
        private const val WATCHDOG_MS = 5_000L

        @Volatile var isRecording = false
            private set

        /** What the Record tab shows while recording: pause state, timer, meters, bookmarks and the far-side warning. */
        val live = MutableStateFlow(LiveRecording())

        fun intent(context: Context, action: String) = Intent(context, RecordingService::class.java).setAction(action)
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
    private var bubble: CaptionBubble? = null

    /** Latest settings, kept warm so nothing reads DataStore synchronously. */
    @Volatile private var settings = AppSettings()

    private val callListener = object : ICallListener.Stub() {
        override fun onCallStarted(packageName: String?) {
            val app = SourceApp.fromPackage(packageName) ?: SourceApp.WHATSAPP
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
    }

    override fun onCreate() {
        super.onCreate()
        lifecycleScope.launch { graph.settings.settings.collect { settings = it } }
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
                stopCapture(fromCall = false)
            } else {
                lifecycleScope.launch {
                    settings = graph.settings.current()
                    if (capture == null) startManual()
                }
            }

            ACTION_STOP -> if (capture != null) stopCapture(fromCall = false) else settle()

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

            else -> settle()
        }
        return START_NOT_STICKY
    }

    /** Enters (or updates) the foreground state. Returns false when the system refuses; never crashes. */
    private fun goForeground(mic: Boolean, title: String, detail: String): Boolean {
        val notification = if (!mic && capture == null) {
            Notifications.armed(this, ready = helperAlive())
        } else {
            Notifications.recording(this, title, detail, paused = capture?.paused == true)
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
        if (graph.helper.helper == null) graph.helper.bindShizuku()
        if (capture == null && !goForeground(mic = false, title = "", detail = "")) {
            armed = false
            persistArmed(false)
            settle()
            return
        }
        CaptureLog.add(this, "auto-record on", if (helperAlive()) "helper ready" else "helper not running")
        persistArmed(true)
        main.removeCallbacks(watchdog)
        main.postDelayed(watchdog, WATCHDOG_MS)
    }

    private fun disarm() {
        armed = false
        main.removeCallbacks(watchdog)
        graph.helper.setCallListener(null)
        persistArmed(false)
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
            val inCall = (getSystemService(AUDIO_SERVICE) as AudioManager).mode == AudioManager.MODE_IN_COMMUNICATION
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

    private fun onCallEvent(event: CallEvent) {
        // A call in an app whose auto-record switch is off is not recorded (B1); recording by hand still works.
        if (event is CallEvent.CallStarted && !AutoRecordRules.records(event.app, settings.autoRecordApps)) return
        val (next, effects) = CallStateMachine.reduce(callState, event)
        callState = next
        for (effect in effects) {
            when (effect) {
                is CallEffect.StartCapture -> startHelperCapture(effect.app.wire, effect.recordingId, fromCall = true)
                is CallEffect.StopCaptureAndAsk -> stopCapture(fromCall = true)
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
        val session = Capture(id, sourceApp, channels = 2, fromCall = fromCall, clientId = settings.clientId)
        session.title = "Recording call"
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
        val started = runCatching { helper.startCapture(pipe[1], Pcm.CAPTURE_RATE, micSource) }
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
        session.readerThread = thread(name = "el-pipe-reader") {
            ParcelFileDescriptor.AutoCloseInputStream(pipe[0]).use { input ->
                val buffer = ByteArray(Pcm.CAPTURE_RATE / 100 * 4 * 4) // 40 ms stereo
                while (session.active) {
                    val n = input.read(buffer)
                    if (n <= 0) break
                    session.consume(buffer, n)
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
        publish(session)
        main.removeCallbacks(ticker)
        main.postDelayed(ticker, TICK_MS)
        lifecycleScope.launch {
            WidgetStateStore.refresh(this@RecordingService)
            if (settings.captionsEnabled) startCaptions(session, session.clientId)
        }
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
                // Captions never stop the recording; the bubble says why they are missing.
                if (Settings.canDrawOverlays(this)) {
                    if (bubble == null) bubble = CaptionBubble(this, settings.captionStyle).also { it.show() }
                    bubble?.notice(outcome.message)
                }
                return
            }
        }
        if (token.mode != "cloud") return
        if (Settings.canDrawOverlays(this) && bubble == null) {
            bubble = CaptionBubble(this, settings.captionStyle).also { it.show() }
        }
        bubble?.language(Languages.chip(session.language)) { setLanguageFromBubble(session) }
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
        refresh(session)
    }

    private fun resume(session: Capture) {
        session.resume()
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
                if (change.silent) bubble?.notice("Not hearing the other side for ${change.seconds} s. Check the call's speaker.")
                refresh(session)
            } else {
                publish(session)
            }
            main.postDelayed(this, TICK_MS)
        }
    }

    private fun setLanguageFromBubble(session: Capture) = main.post {
        if (capture ===
            session
        ) {
            setLanguage(session, Languages.next(session.language))
        }
    }

    private fun onCaption(session: Capture, channel: Int, event: SttEvent) {
        val speaker = Speakers.forChannel(channel, session.channels == 2)
        when (event) {
            is SttEvent.Partial -> main.post { bubble?.partial(speaker, event.text) }

            is SttEvent.Final -> {
                val offset = session.recordedMs()
                synchronized(session.segments) {
                    session.segments +=
                        SegmentDto(channel, speaker, event.startMs ?: (offset - 1500).coerceAtLeast(0), event.endMs ?: offset, event.text)
                }
                main.post { bubble?.final(speaker, event.text) }
            }

            else -> Unit
        }
    }

    /** Stops capture, encrypts the file; calls wait for the user's Keep/Discard, manual recordings upload directly. */
    private fun stopCapture(fromCall: Boolean) {
        val session = capture ?: return
        capture = null
        isRecording = false
        main.removeCallbacks(ticker)
        publish(null)
        finalizing++
        thread(name = "el-finish") {
            val stored = runCatching {
                session.finish()
                val (markers, pauses) = session.notes.finish()
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
                    languageMarks = synchronized(session.languageMarks) { session.languageMarks.toList() }
                )
                graph.recordings.importPlainFile(meta, session.file)
            }.onFailure {
                Log.e(TAG, "saving recording failed", it)
                session.file.delete()
            }.getOrNull()
            // A recording that captured no sound (0 ms) has nothing to keep or transcribe: say why instead of asking.
            val silent = stored != null && stored.durationMs <= 0
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
                if (silent) {
                    Notifications.noAudio(this, CaptureProblems.recent(this))
                } else if (stored != null) {
                    if (stored.state == "pending_decision") {
                        PostCallPrompt.show(this, stored, decisionTimeout, themeMode)
                    } else {
                        UploadWorker.enqueue(this, stored.id)
                    }
                }
                finalizing--
                if (capture == null) {
                    bubble?.hide()
                    bubble = null
                }
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
            bubble?.hide()
            bubble = null
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
        capture?.let { stopCapture(fromCall = false) }
        destroyed = true
        graph.helper.setCallListener(null)
        bubble?.hide()
        bubble = null
        super.onDestroy()
    }

    /** One active capture: encoder + live caption streams. */
    private inner class Capture(val id: String, val sourceApp: String, val channels: Int, val fromCall: Boolean, val clientId: String?) {
        val startedAt: Instant = Instant.now()
        private val startNanos = System.nanoTime()
        val file = File(cacheDir, "rec-$id.ogg")
        val encoder = OpusOggEncoder(file, Pcm.CAPTURE_RATE, channels)
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

        fun consume(bytes: ByteArray, length: Int) {
            if (paused) return // not captured, not streamed
            if (!soundLogged && length > 0) {
                soundLogged = true
                CaptureLog.add(this@RecordingService, "sound arriving", "${(System.nanoTime() - startNanos) / 1_000_000} ms after start")
            }
            encoder.feed(bytes, length)
            val samples = Pcm.fromLittleEndianBytes(bytes, length)
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
            file.delete()
        }
    }
}
