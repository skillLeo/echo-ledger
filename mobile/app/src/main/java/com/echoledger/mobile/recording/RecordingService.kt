package com.echoledger.mobile.recording

import android.annotation.SuppressLint
import android.app.ForegroundServiceStartNotAllowedException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.media.AudioFormat
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
import com.echoledger.mobile.capture.ICallListener
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.Speakers
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.net.SttEvent
import com.echoledger.mobile.net.SttStreamer
import com.echoledger.mobile.net.UploadWorker
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.overlay.CaptionBubble
import com.echoledger.mobile.widget.WidgetStateStore
import java.io.File
import java.time.Instant
import java.util.UUID
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit
import kotlin.concurrent.thread
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
        private const val TAG = "EchoLedgerRec"

        @Volatile var isRecording = false
            private set

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
            main.post { onCallEvent(CallEvent.CallStarted(app, UUID.randomUUID().toString(), System.currentTimeMillis())) }
        }

        override fun onCallEnded() {
            main.post { onCallEvent(CallEvent.CallEnded(System.currentTimeMillis())) }
        }

        override fun onError(message: String?) {
            Log.w(TAG, "helper: $message")
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

            else -> settle()
        }
        return START_NOT_STICKY
    }

    /** Enters (or updates) the foreground state. Returns false when the system refuses; never crashes. */
    private fun goForeground(mic: Boolean, title: String, detail: String): Boolean {
        val notification = if (!mic && capture == null) Notifications.armed(this) else Notifications.recording(this, title, detail)
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

    private fun arm() {
        if (Build.VERSION.SDK_INT < 34 || graph.helper.helper == null) {
            Log.w(TAG, "auto-record needs Android 14+ and the capture helper")
            persistArmed(false)
            settle()
            return
        }
        armed = true
        if (capture == null && !goForeground(mic = false, title = "", detail = "")) {
            armed = false
            persistArmed(false)
            settle()
            return
        }
        graph.helper.setCallListener(callListener)
        persistArmed(true)
    }

    private fun disarm() {
        armed = false
        graph.helper.setCallListener(null)
        persistArmed(false)
        settle()
    }

    private fun persistArmed(value: Boolean) {
        lifecycleScope.launch {
            graph.settings.update { it.copy(autoRecordArmed = value) }
            WidgetStateStore.refresh(this@RecordingService)
        }
    }

    private fun onCallEvent(event: CallEvent) {
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
        capture = session
        if (!goForeground(mic = false, title = "Recording call", detail = "Both sides · ${if (fromCall) "auto" else "manual"}")) {
            session.cancel()
            capture = null
            settle()
            return
        }
        val pipe = ParcelFileDescriptor.createPipe()
        val micSource = if (settings.micSource == "mic") MediaRecorder.AudioSource.MIC else MediaRecorder.AudioSource.VOICE_COMMUNICATION
        val started = runCatching { helper.startCapture(pipe[1], Pcm.CAPTURE_RATE, micSource) }.getOrDefault(false)
        pipe[1].close()
        if (!started) {
            pipe[0].close()
            session.cancel()
            capture = null
            if (fromCall) settle() else startMicCapture(id)
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
        capture = session
        if (!goForeground(mic = true, title = "Recording", detail = "Microphone")) {
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
        lifecycleScope.launch {
            WidgetStateStore.refresh(this@RecordingService)
            if (settings.captionsEnabled) startCaptions(session, session.clientId)
        }
    }

    private suspend fun startCaptions(session: Capture, clientId: String?) {
        val api = graph.api() ?: return
        val channels = if (session.channels == 2) listOf("me", "client") else listOf("me")
        val token = runCatching { api.sttToken("live_captions", channels, clientId) }.getOrNull() ?: return
        if (token.mode != "cloud" || !session.active) return
        if (Settings.canDrawOverlays(this)) {
            bubble = CaptionBubble(this).also { it.show() }
        }
        session.provider = token.provider
        for (grant in token.grants) {
            if (grant.protocol !in setOf("assemblyai_v3", "elevenlabs_realtime")) continue
            val channel = if (grant.channel == "client") 2 else 1
            val streamer = SttStreamer(grant, { event -> onCaption(session, channel, event) }, wsClient)
            streamer.start()
            session.streamers[channel] = streamer
        }
    }

    private fun onCaption(session: Capture, channel: Int, event: SttEvent) {
        val speaker = Speakers.forChannel(channel, session.channels == 2)
        when (event) {
            is SttEvent.Partial -> main.post { bubble?.partial(speaker, event.text) }

            is SttEvent.Final -> {
                val offset = session.elapsedMs()
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
        finalizing++
        thread(name = "el-finish") {
            val stored = runCatching {
                session.finish()
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
                    liveProvider = session.provider
                )
                graph.recordings.importPlainFile(meta, session.file)
            }.onFailure {
                Log.e(TAG, "saving recording failed", it)
                session.file.delete()
            }.getOrNull()
            main.post {
                if (stored != null) {
                    if (stored.state == "pending_decision") {
                        Notifications.showSaveDecision(
                            this,
                            stored.id,
                            SourceApp.from(stored.sourceApp).label,
                            formatDuration(stored.durationMs)
                        )
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

    private fun formatDuration(ms: Long): String {
        val s = ms / 1000
        return if (s >= 3600) "%d:%02d:%02d".format(s / 3600, (s % 3600) / 60, s % 60) else "%d:%02d".format(s / 60, s % 60)
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
        var readerThread: Thread? = null
        var helperStop: (() -> Unit)? = null

        @Volatile var active = true

        fun elapsedMs(): Long = (System.nanoTime() - startNanos) / 1_000_000

        fun consume(bytes: ByteArray, length: Int) {
            encoder.feed(bytes, length)
            if (streamers.isEmpty()) return
            val samples = Pcm.fromLittleEndianBytes(bytes, length)
            if (channels == 2) {
                val (me, client) = Pcm.deinterleave(samples)
                streamers[1]?.sendPcm(Pcm.toLittleEndianBytes(Pcm.downsample48To16(me)))
                streamers[2]?.sendPcm(Pcm.toLittleEndianBytes(Pcm.downsample48To16(client)))
            } else {
                streamers[1]?.sendPcm(Pcm.toLittleEndianBytes(Pcm.downsample48To16(samples)))
            }
        }

        fun finish() {
            active = false
            helperStop?.invoke()
            readerThread?.join(3_000)
            streamers.values.forEach { it.stop() }
            if (streamers.isNotEmpty()) Thread.sleep(1_500) // last caption turns arrive
            encoder.finish()
        }

        fun cancel() {
            active = false
            helperStop?.invoke()
            readerThread?.join(3_000)
            streamers.values.forEach { it.stop() }
            runCatching { encoder.finish() }
            file.delete()
        }
    }
}
