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
import com.echoledger.mobile.recording.PcmQueue
import java.io.FileOutputStream
import kotlin.concurrent.thread

/**
 * The privileged helper (shell UID 2000): detects WhatsApp calls and captures both sides concurrently.
 * Runs inside a Shizuku UserService or an `app_process` launched over ADB; never inside the app process.
 *
 * Near end: concurrent mic AudioRecord — not silenced during the call because shell holds CAPTURE_AUDIO_OUTPUT
 * (canBypassConcurrentPolicy). Far end: [FarPartyCapture]. docs/research/android-capture.md §2–§4.
 */
class CaptureHelperService(base: Context) : ICaptureHelper.Stub() {
    /** Shizuku v13 tries a (Context) constructor first; a no-arg one is kept for older Shizuku. */
    constructor() : this(HelperContext.system())

    /** Always the shell's context: the one Shizuku passes names this app's package, which the audio server refuses for UID 2000. */
    private val context: Context = HelperContext.shell(base)

    private val audioManager = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager

    companion object {
        /** About half a second of failed reads in a row ends the capture with a reason. */
        private const val MAX_FAILED_READS = 50

        /** The other side's rate: the highest Android allows a privileged call capture. */
        private const val FAR_RATE = Pcm.STT_RATE

        /** Said once when Android feeds the microphone silence during another app's call. */
        private const val MIC_MUTED = "Android muted the microphone during this call, so your side is silent. " +
            "This Android version lets only system apps record while another app is in a call."
    }

    @Volatile private var listener: ICallListener? = null

    @Volatile private var capturing = false

    private var captureThread: Thread? = null
    private var farParty: FarPartyCapture? = null

    init {
        startCallMonitor()
    }

    /** Changes with every app build, so the app can tell an old helper still running from the one it ships. */
    override fun version(): Int = com.echoledger.mobile.BuildConfig.HELPER_BUILD

    override fun uid(): Int = Process.myUid()

    override fun capabilities(): String = buildString {
        append("sdk=").append(Build.VERSION.SDK_INT)
        append(";uid=").append(Process.myUid())
        append(";far_party=").append(Build.VERSION.SDK_INT >= 34)
        // Which package the recorders are opened as (must be com.android.shell for UID 2000).
        append(";as=").append(runCatching { context.opPackageName }.getOrNull())
    }

    override fun setCallListener(listener: ICallListener?) {
        this.listener = listener
    }

    override fun startCapture(sink: ParcelFileDescriptor, sampleRate: Int, micSource: Int): Boolean =
        begin(sink, sampleRate, micSource, phone = false)

    override fun startPhoneCapture(sink: ParcelFileDescriptor, sampleRate: Int, micSource: Int): Boolean =
        begin(sink, sampleRate, micSource, phone = true)

    /**
     * Starts both sides. A WhatsApp call's far side comes from a playback mix ([FarPartyCapture]); a SIM call's from the
     * phone line ([openPhoneLine]). When the line gives only both voices mixed, the microphone is left out so nothing is
     * transcribed twice.
     */
    @SuppressLint("MissingPermission") // shell holds RECORD_AUDIO
    private fun begin(sink: ParcelFileDescriptor, sampleRate: Int, micSource: Int, phone: Boolean): Boolean {
        if (capturing) return true
        val rate = if (sampleRate > 0) sampleRate else Pcm.CAPTURE_RATE
        val frames = rate / 100 // 10 ms
        val problems = mutableListOf<String>()

        // The other side is captured at 16 kHz: Android refuses a privileged call capture above it ("Privileged audio
        // capture sample rate 48000 can not be over 16000kHz"). It is raised to the microphone's rate when written.
        val far = FarPartyCapture(context, audioManager, FAR_RATE)
        val line = if (phone) openPhoneLine(FAR_RATE, FAR_RATE / 100, problems) else null
        val mic = if (line?.second == true) null else openMic(rate, frames, micSource, problems)
        val farRecord = if (phone) {
            line?.first
        } else {
            runCatching { far.start() }
                .onFailure { problems += "far side: ${reason(it)}" }
                .getOrNull()
                ?.takeIf { record -> started(record).also { if (!it) problems += "far side: did not start" } }
        }
        if (farRecord == null || phone) far.stop()
        if (line?.second == true) problems += "the phone line gives both voices on one track"
        farParty = far

        // Nothing to record: say why (the app shows it) instead of a recording that stays silent.
        if (mic == null && farRecord == null) {
            report("Call capture could not start: ${problems.joinToString("; ")}")
            runCatching { sink.close() }
            return false
        }
        if (problems.isNotEmpty()) report("Call capture is partly working: ${problems.joinToString("; ")}")
        capturing = true

        captureThread = thread(name = "el-helper-capture") {
            val out = FileOutputStream(sink.fileDescriptor)
            val micBuf = ShortArray(frames)
            val farChunk = FAR_RATE / 100
            val farBuf = ShortArray(farChunk * 4)
            val factor = (rate / FAR_RATE).coerceAtLeast(1)
            val farQueue = PcmQueue(rate) // at most 1 s of the other side waits for the microphone
            var farLast: Short = 0
            var failedReads = 0
            var reads = 0
            var mutedSaid = false
            try {
                while (capturing) {
                    // Android may keep the recorder running but feed it silence while another app is in a call
                    // (Android 17 lets only system apps record then): say so once instead of saving a silent side.
                    if (!mutedSaid && mic != null && ++reads % 200 == 0 && silenced(mic)) {
                        mutedSaid = true
                        report(MIC_MUTED)
                    }
                    // The microphone paces the stream; without it the far side does (blocking read).
                    val m = mic?.read(micBuf, 0, frames) ?: 0
                    val f = if (mic != null) {
                        farRecord?.read(farBuf, 0, farBuf.size, AudioRecord.READ_NON_BLOCKING) ?: 0
                    } else {
                        farRecord?.read(farBuf, 0, farChunk) ?: 0
                    }
                    if (f > 0) {
                        farQueue.push(Pcm.upsample(farBuf, f, factor, farLast))
                        farLast = farBuf[f - 1]
                    }
                    val n = if (mic != null) m else f * factor
                    if (n <= 0) {
                        // A recorder that keeps failing would leave a silent file: stop and say so.
                        if (++failedReads >= MAX_FAILED_READS) {
                            report("Call capture stopped: the recorder returned error $n")
                            break
                        }
                        Thread.sleep(10)
                        continue
                    }
                    failedReads = 0
                    val me = if (mic != null && m > 0) micBuf.copyOf(n) else ShortArray(n)
                    val client = farQueue.take(n)
                    out.write(Pcm.toLittleEndianBytes(Pcm.interleave(me, client)))
                }
            } catch (e: Exception) {
                report("Capture stopped: ${e.message}")
            } finally {
                mic?.let {
                    runCatching { it.stop() }
                    it.release()
                }
                if (phone) {
                    farRecord?.let {
                        runCatching { it.stop() }
                        it.release()
                    }
                }
                far.stop()
                runCatching { out.close() }
                runCatching { sink.close() }
                capturing = false
            }
        }
        return true
    }

    /**
     * The near-end microphone: the chosen source first, then the others, each with and without the shell attribution
     * (OEM builds differ). Only a recorder that really started recording is used.
     */
    @SuppressLint("MissingPermission")
    private fun openMic(rate: Int, frames: Int, micSource: Int, problems: MutableList<String>): AudioRecord? {
        val format = AudioFormat.Builder().setSampleRate(rate).setChannelMask(AudioFormat.CHANNEL_IN_MONO)
            .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
        val minBuffer = AudioRecord.getMinBufferSize(rate, AudioFormat.CHANNEL_IN_MONO, AudioFormat.ENCODING_PCM_16BIT)
            .coerceAtLeast(frames * 2 * 8)
        val sources = listOf(
            if (micSource > 0) micSource else MediaRecorder.AudioSource.VOICE_COMMUNICATION,
            MediaRecorder.AudioSource.MIC,
            MediaRecorder.AudioSource.VOICE_RECOGNITION
        ).distinct()
        val mine = mutableListOf<String>()
        for (source in sources) {
            for (withContext in listOf(true, false)) {
                if (withContext && Build.VERSION.SDK_INT < 31) continue
                val record = runCatching {
                    val builder = AudioRecord.Builder().setAudioSource(source).setAudioFormat(format).setBufferSizeInBytes(minBuffer * 2)
                    if (withContext && Build.VERSION.SDK_INT >= 31) builder.setContext(context)
                    builder.build()
                }.getOrElse {
                    mine += "mic source $source: ${reason(it)}"
                    null
                } ?: continue
                // An earlier attempt failing does not matter once one works; the other side's notes are kept.
                if (started(record)) return record
                mine += "mic source $source: did not start"
                record.release()
            }
        }
        problems += mine
        return null
    }

    /**
     * A SIM call's far side (since v1.2): the downlink of the phone line, which needs CAPTURE_AUDIO_OUTPUT (shell holds
     * it). Some phones only give the whole call (both voices); then the second value is true. Null when neither works.
     */
    @SuppressLint("MissingPermission")
    private fun openPhoneLine(rate: Int, frames: Int, problems: MutableList<String>): Pair<AudioRecord, Boolean>? {
        val format = AudioFormat.Builder().setSampleRate(rate).setChannelMask(AudioFormat.CHANNEL_IN_MONO)
            .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
        val minBuffer = AudioRecord.getMinBufferSize(rate, AudioFormat.CHANNEL_IN_MONO, AudioFormat.ENCODING_PCM_16BIT)
            .coerceAtLeast(frames * 2 * 8)
        // Android 13+: the call audio interception API (shell holds CALL_AUDIO_INTERCEPTION). Its recorder is a policy
        // mix, which Android does not mute during the call. System API, reached by reflection.
        downlinkInterception(format, problems)?.let { record ->
            if (started(record)) return record to false
            problems += "phone line interception: did not start"
            record.release()
        }
        for (source in listOf(MediaRecorder.AudioSource.VOICE_DOWNLINK, MediaRecorder.AudioSource.VOICE_CALL)) {
            val record = runCatching {
                AudioRecord.Builder().setAudioSource(source).setAudioFormat(format).setBufferSizeInBytes(minBuffer * 2).build()
            }.getOrElse {
                problems += "phone line $source: ${it.message ?: it.javaClass.simpleName}"
                null
            } ?: continue
            if (started(record)) return record to (source == MediaRecorder.AudioSource.VOICE_CALL)
            problems += "phone line $source: did not start"
            record.release()
        }
        return null
    }

    private fun downlinkInterception(format: AudioFormat, problems: MutableList<String>): AudioRecord? = runCatching {
        if (Build.VERSION.SDK_INT < 33) return null
        val type = AudioManager::class.java
        if (type.getMethod("isPstnCallAudioInterceptable").invoke(audioManager) != true) {
            problems += "phone line interception: not offered for this call"
            return null
        }
        type.getMethod("getCallDownlinkExtractionAudioRecord", AudioFormat::class.java).invoke(audioManager, format) as AudioRecord
    }.getOrElse {
        problems += "phone line interception: ${reason(it)}"
        null
    }

    /** Whether Android is feeding [record] silence (another app's call has the microphone). */
    private fun silenced(record: AudioRecord): Boolean =
        runCatching { record.activeRecordingConfiguration?.isClientSilenced == true }.getOrDefault(false)

    /** The real error behind a reflective call ("InvocationTargetException" alone says nothing). */
    private fun reason(e: Throwable): String {
        var t = e
        while (true) {
            val next = (t as? java.lang.reflect.InvocationTargetException)?.targetException ?: t.cause
            if (next == null || next === t) break
            t = next
        }
        return t.javaClass.simpleName + (t.message?.let { ": $it" } ?: "")
    }

    /** Starts [record] and checks it is really recording (Android can refuse silently: state stays stopped). */
    private fun started(record: AudioRecord): Boolean {
        if (record.state != AudioRecord.STATE_INITIALIZED) return false
        runCatching { record.startRecording() }.onFailure { return false }
        if (record.recordingState == AudioRecord.RECORDSTATE_RECORDING) return true
        runCatching { record.stop() }
        return false
    }

    private fun report(message: String) {
        runCatching { listener?.onError(message) }
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
     * the voice-communication player and confirm it belongs to WhatsApp / WhatsApp Business. A SIM call sets
     * MODE_IN_CALL (since v1.2 reported as [com.echoledger.mobile.core.CallTargets.TELEPHONY]; the app decides whether
     * to record it).
     */
    private fun startCallMonitor() {
        thread(name = "el-helper-calls", isDaemon = true) {
            var inCall = false
            while (true) {
                runCatching {
                    val mode = audioManager.mode
                    val pkg = com.echoledger.mobile.core.PhoneCalls.callApp(mode) { whatsappVoicePlayer() }
                    if (!inCall && pkg != null) {
                        inCall = true
                        listener?.onCallStarted(pkg)
                    } else if (inCall && !com.echoledger.mobile.core.PhoneCalls.inCall(mode)) {
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
            com.echoledger.mobile.core.CallTargets.firstTarget(pm.getPackagesForUid(uid))?.let { return it }
        }
        return null
    }
}
