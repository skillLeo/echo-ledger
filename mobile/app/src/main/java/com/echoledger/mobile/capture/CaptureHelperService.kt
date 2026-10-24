package com.echoledger.mobile.capture

import android.annotation.SuppressLint
import android.content.Context
import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord
import android.media.MediaRecorder
import android.os.Build
import android.os.ParcelFileDescriptor
import android.os.Process
import com.echoledger.mobile.recording.Pcm
import java.io.FileOutputStream
import kotlin.concurrent.thread

/**
 * The privileged helper (shell UID 2000): detects WhatsApp calls and captures both sides concurrently.
 * Runs inside a Shizuku UserService or an `app_process` launched over ADB; never inside the app process.
 *
 * Near end: concurrent mic AudioRecord — not silenced during the call because shell holds CAPTURE_AUDIO_OUTPUT
 * (canBypassConcurrentPolicy). Far end: [FarPartyCapture]. docs/research/android-capture.md §2–§4.
 */
class CaptureHelperService(private val context: Context) : ICaptureHelper.Stub() {
    /** Shizuku v13 tries a (Context) constructor first; a no-arg one is kept for older Shizuku. */
    constructor() : this(HelperContext.system())

    private val audioManager = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager

    @Volatile private var listener: ICallListener? = null

    @Volatile private var capturing = false

    private var captureThread: Thread? = null
    private var farParty: FarPartyCapture? = null

    init {
        startCallMonitor()
    }

    override fun version(): Int = 1

    override fun uid(): Int = Process.myUid()

    override fun capabilities(): String = buildString {
        append("sdk=").append(Build.VERSION.SDK_INT)
        append(";uid=").append(Process.myUid())
        append(";far_party=").append(Build.VERSION.SDK_INT >= 34)
    }

    override fun setCallListener(listener: ICallListener?) {
        this.listener = listener
    }

    @SuppressLint("MissingPermission") // shell holds RECORD_AUDIO
    override fun startCapture(sink: ParcelFileDescriptor, sampleRate: Int, micSource: Int): Boolean {
        if (capturing) return true
        capturing = true
        val rate = if (sampleRate > 0) sampleRate else Pcm.CAPTURE_RATE
        val frames = rate / 100 // 10 ms
        val format = AudioFormat.Builder().setSampleRate(
            rate
        ).setChannelMask(AudioFormat.CHANNEL_IN_MONO).setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
        val minBuffer = AudioRecord.getMinBufferSize(
            rate,
            AudioFormat.CHANNEL_IN_MONO,
            AudioFormat.ENCODING_PCM_16BIT
        ).coerceAtLeast(frames * 2 * 8)

        val micBuilder = AudioRecord.Builder()
            .setAudioSource(if (micSource > 0) micSource else MediaRecorder.AudioSource.VOICE_COMMUNICATION)
            .setAudioFormat(format)
            .setBufferSizeInBytes(minBuffer * 2)
        if (Build.VERSION.SDK_INT >= 31) runCatching { micBuilder.setContext(context) }
        val mic = micBuilder.build()

        val far = FarPartyCapture(context, audioManager, rate)
        val farRecord = runCatching {
            far.start()
        }.onFailure { listener?.onError("Far-party capture unavailable: ${it.message}") }.getOrNull()
        farParty = far

        captureThread = thread(name = "el-helper-capture") {
            val out = FileOutputStream(sink.fileDescriptor)
            val micBuf = ShortArray(frames)
            val farBuf = ShortArray(frames)
            try {
                mic.startRecording()
                farRecord?.startRecording()
                while (capturing) {
                    val m = mic.read(micBuf, 0, frames)
                    val f = farRecord?.read(farBuf, 0, frames, AudioRecord.READ_NON_BLOCKING) ?: 0
                    if (m <= 0) continue
                    val farSlice = if (f > 0) farBuf.copyOf(m) else ShortArray(m)
                    out.write(Pcm.toLittleEndianBytes(Pcm.interleave(micBuf.copyOf(m), farSlice)))
                }
            } catch (e: Exception) {
                listener?.onError("Capture stopped: ${e.message}")
            } finally {
                runCatching { mic.stop() }
                mic.release()
                far.stop()
                runCatching { out.close() }
                runCatching { sink.close() }
                capturing = false
            }
        }
        return true
    }

    override fun stopCapture() {
        capturing = false
        captureThread?.join(2_000)
        captureThread = null
        farParty = null
    }

    override fun destroy() {
        stopCapture()
        System.exit(0)
    }

    /**
     * Call detection: VoIP sets MODE_IN_COMMUNICATION; the helper (holding MODIFY_AUDIO_ROUTING) can see the real UID of
     * the voice-communication player and confirm it belongs to WhatsApp / WhatsApp Business.
     */
    private fun startCallMonitor() {
        thread(name = "el-helper-calls", isDaemon = true) {
            var inCall = false
            while (true) {
                runCatching {
                    val communicating = audioManager.mode == AudioManager.MODE_IN_COMMUNICATION
                    val pkg = if (communicating) whatsappVoicePlayer() else null
                    if (!inCall && pkg != null) {
                        inCall = true
                        listener?.onCallStarted(pkg)
                    } else if (inCall && !communicating) {
                        inCall = false
                        listener?.onCallEnded()
                    }
                }
                Thread.sleep(500)
            }
        }
    }

    private fun whatsappVoicePlayer(): String? {
        val pm = context.packageManager
        for (config in audioManager.activePlaybackConfigurations) {
            if (config.audioAttributes.usage != AudioAttributes.USAGE_VOICE_COMMUNICATION) continue
            val uid = runCatching { config.javaClass.getMethod("getClientUid").invoke(config) as Int }.getOrNull() ?: continue
            val packages = pm.getPackagesForUid(uid).orEmpty()
            packages.firstOrNull { it == "com.whatsapp" || it == "com.whatsapp.w4b" }?.let { return it }
        }
        return null
    }
}
