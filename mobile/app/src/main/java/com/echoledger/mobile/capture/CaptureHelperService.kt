package com.echoledger.mobile.capture

import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord
import android.media.MediaRecorder
import android.os.Binder
import android.os.Build
import android.os.ParcelFileDescriptor
import android.os.Process
import com.echoledger.mobile.recording.Pcm
import com.echoledger.mobile.recording.PcmQueue
import java.io.FileOutputStream
import kotlin.concurrent.thread

/**
 * The privileged helper (shell UID 2000): detects calls and captures both sides.
 * Runs inside a Shizuku UserService or an `app_process` launched over ADB; never inside the app process.
 *
 * Near end: the microphone, but never while it would mute another app's call (see [micSafeNow]). Far end of a WhatsApp
 * call: a playback mix ([FarPartyCapture]). A SIM call: both sides from the phone line ([openPhoneLine], [openUplink]).
 * docs/research/android-capture.md §2–§4.
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

        /** How often (in reads of 10 ms) the helper checks that it is not muting anyone and is not muted itself. */
        private const val CHECK_EVERY_READS = 25

        /** The phone line's and the other side's rate: the highest Android allows a privileged call capture. */
        private const val LINE_RATE = Pcm.STT_RATE

        /** Since Android 16/17 the permission that lets a recorder keep the microphone beside another app's call. */
        private const val BYPASS_CONCURRENT = "android.permission.BYPASS_CONCURRENT_RECORD_AUDIO_RESTRICTION"

        /** Sources Android never mutes and that cannot mute anyone (they do not compete for the microphone). */
        private val LINE_SOURCES = setOf(
            MediaRecorder.AudioSource.VOICE_UPLINK,
            MediaRecorder.AudioSource.VOICE_DOWNLINK,
            MediaRecorder.AudioSource.VOICE_CALL,
            MediaRecorder.AudioSource.REMOTE_SUBMIX
        )

        /** Said once when Android feeds the microphone silence during another app's call. */
        private const val MIC_MUTED = "Your own voice is not recorded in this call: during another app's call this Android " +
            "version gives the microphone only to that app and to system apps. The other side is still recorded."

        /** Said once when a SIM call's phone line gives only silence (the phone maker keeps it for its own dialer). */
        private const val LINE_SILENT = "The phone line gave no sound, so this SIM call cannot be recorded on this phone: " +
            "the phone maker lets only its own Phone app record calls."

        /** Reads of 10 ms after which a phone line that gave only zeros is reported as blocked. */
        private const val LINE_SILENT_READS = 800

        /** Said when the helper gave the microphone back because it was muting the call. */
        private const val MIC_GIVEN_BACK = "EchoLedger stopped recording your voice because it was muting the call for the other " +
            "person. The other side is still recorded."
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
        append(";mic_beside_calls=").append(runCatching { bypassesConcurrentCapture() }.getOrNull())
    }

    override fun setCallListener(listener: ICallListener?) {
        this.listener = listener
    }

    override fun startCapture(sink: ParcelFileDescriptor, sampleRate: Int, micSource: Int): Boolean =
        begin(sink, sampleRate, micSource, phone = false)

    override fun startPhoneCapture(sink: ParcelFileDescriptor, sampleRate: Int, micSource: Int): Boolean =
        begin(sink, sampleRate, micSource, phone = true)

    /** One side of the call: a started recorder, its rate, and whether it is the microphone (which can mute or be muted). */
    private class Side(val record: AudioRecord, val rate: Int, val mic: Boolean) {
        val chunk = rate / 100 // 10 ms
        val buf = ShortArray(chunk * 4)
        var last: Short = 0

        /** Whether any sound at all has arrived (a blocked phone line gives exact zeros). */
        var heard = false

        fun release() {
            runCatching { record.stop() }
            runCatching { record.release() }
        }
    }

    /**
     * Starts both sides. Called over binder from the app: Android's in-process checks (AudioPolicy's policyReadyToUse
     * looks at the calling uid) would see the app, which holds none of the audio permissions ("Cannot use AudioPolicy
     * for … uid 10462, needs MODIFY_AUDIO_ROUTING"), so the app's identity is cleared and the helper's own shell uid
     * is checked.
     */
    private fun begin(sink: ParcelFileDescriptor, sampleRate: Int, micSource: Int, phone: Boolean): Boolean {
        val identity = Binder.clearCallingIdentity()
        try {
            return beginAsHelper(sink, sampleRate, micSource, phone)
        } finally {
            Binder.restoreCallingIdentity(identity)
        }
    }

    @SuppressLint("MissingPermission") // shell holds RECORD_AUDIO and CAPTURE_AUDIO_OUTPUT
    private fun beginAsHelper(sink: ParcelFileDescriptor, sampleRate: Int, micSource: Int, phone: Boolean): Boolean {
        if (capturing) return true
        val rate = if (sampleRate > 0) sampleRate else Pcm.CAPTURE_RATE
        val problems = mutableListOf<String>()

        // The other side is captured at 16 kHz: Android refuses a privileged call capture above it ("Privileged audio
        // capture sample rate 48000 can not be over 16000kHz"). Each side is raised to the file's rate when written.
        val far = FarPartyCapture(context, audioManager, LINE_RATE)
        var near: Side? = null
        val farSide: Side?
        if (phone) {
            // A SIM call: the call's own audio is not an app recorder, and the phone line's sources are never muted.
            val line = openPhoneLine(problems)
            farSide = line?.first?.let { Side(it, LINE_RATE, mic = false) }
            if (line?.second == true) {
                problems += "the phone line gives both voices on one track"
            } else {
                near = openUplink(problems)?.let { Side(it, LINE_RATE, mic = false) }
                    ?: openMic(rate, MediaRecorder.AudioSource.MIC, problems)?.let { Side(it, rate, mic = true) }
            }
            far.stop()
        } else {
            // During another app's call the microphone is an ordinary recorder (MIC, not privacy-sensitive): Android then
            // keeps the call app's recorder and never mutes it for ours; ours may be fed silence instead (said once).
            val source = if (inCallNow()) MediaRecorder.AudioSource.MIC else micSource
            near = openMic(rate, source, problems)?.let { Side(it, rate, mic = true) }
            farSide = runCatching { far.start() }
                .onFailure { problems += "far side: ${reason(it)}" }
                .getOrNull()
                ?.takeIf { record -> started(record).also { if (!it) problems += "far side: did not start" } }
                ?.let { Side(it, LINE_RATE, mic = false) }
            if (farSide == null) far.stop()
        }
        farParty = far

        // Nothing to record: say why (the app shows it) instead of a recording that stays silent.
        if (near == null && farSide == null) {
            report("Call capture could not start: ${problems.joinToString("; ").ifEmpty { "no recorder could be opened" }}")
            runCatching { sink.close() }
            return false
        }
        if (problems.isNotEmpty()) report("Call capture is partly working: ${problems.joinToString("; ")}")
        capturing = true
        captureThread = thread(name = "el-helper-capture") { pump(sink, rate, near, farSide, far, phone) }
        return true
    }

    /**
     * Writes both sides as interleaved stereo at [rate] (L = me, R = the other side). The near side paces the stream
     * (blocking reads); without it the far side does. Each side is raised to [rate] and queued, so recorders that deliver
     * at their own pace line up. While the microphone is open it is checked often: if Android starts muting another
     * app's recorder because of it, the microphone is given back at once.
     */
    private fun pump(sink: ParcelFileDescriptor, rate: Int, first: Side?, farSide: Side?, far: FarPartyCapture, phone: Boolean) {
        val out = FileOutputStream(sink.fileDescriptor)
        var near = first
        val nearQueue = PcmQueue(rate)
        val farQueue = PcmQueue(rate) // at most 1 s of the other side waits for the near side
        var failedReads = 0
        var reads = 0
        var mutedSaid = false
        var lineSaid = false
        try {
            while (capturing) {
                reads++
                // A SIM call's phone line that gave nothing but zeros for 8 s is blocked by the phone maker: say so.
                if (phone && !lineSaid && reads == LINE_SILENT_READS && listOfNotNull(near, farSide).none { !it.mic && it.heard }) {
                    lineSaid = true
                    report(LINE_SILENT)
                }
                val current = near
                // The microphone is checked on every read for the first half second, then four times a second.
                if (current != null && current.mic && (reads <= 50 || reads % CHECK_EVERY_READS == 0)) {
                    if (mutingAnother(current.record)) {
                        current.release()
                        near = null
                        report(MIC_GIVEN_BACK)
                        continue
                    }
                    if (!mutedSaid && silenced(current.record)) {
                        mutedSaid = true
                        report(MIC_MUTED)
                    }
                }
                val primary = near ?: farSide ?: break
                val n = pull(primary, if (primary === near) nearQueue else farQueue, rate, blocking = true)
                if (primary === near && farSide != null) pull(farSide, farQueue, rate, blocking = false)
                if (n <= 0) {
                    // A recorder that keeps failing would leave a silent file: stop and say so.
                    if (++failedReads >= MAX_FAILED_READS) {
                        // A microphone that died mid-call (a headset unplugged) leaves the other side running: keep it.
                        if (primary === near && farSide != null) {
                            primary.release()
                            near = null
                            failedReads = 0
                            report("Your microphone stopped during the call (error $n); the other side is still recorded.")
                            continue
                        }
                        report("Call capture stopped: the recorder returned error $n")
                        break
                    }
                    Thread.sleep(10)
                    continue
                }
                failedReads = 0
                out.write(Pcm.toLittleEndianBytes(Pcm.interleave(nearQueue.take(n), farQueue.take(n))))
            }
        } catch (e: Exception) {
            report("Capture stopped: ${e.message}")
        } finally {
            near?.release()
            if (phone) farSide?.release()
            far.stop()
            runCatching { out.close() }
            runCatching { sink.close() }
            capturing = false
        }
    }

    /** Reads one chunk of [side] (a blocking 10 ms, or what is waiting), raises it to [rate] and queues it; samples queued. */
    private fun pull(side: Side, queue: PcmQueue, rate: Int, blocking: Boolean): Int {
        val got = if (blocking) {
            side.record.read(side.buf, 0, side.chunk)
        } else {
            side.record.read(side.buf, 0, side.buf.size, AudioRecord.READ_NON_BLOCKING)
        }
        if (got <= 0) return got
        if (!side.heard) side.heard = (0 until got).any { side.buf[it].toInt() != 0 }
        val factor = (rate / side.rate).coerceAtLeast(1)
        queue.push(Pcm.upsample(side.buf, got, factor, side.last))
        side.last = side.buf[got - 1]
        return got * factor
    }

    /**
     * Two privacy-sensitive recorders (VOICE_COMMUNICATION, CAMCORDER) never share the microphone: the one that started
     * last gets the sound and the other is muted, so a helper recording VOICE_COMMUNICATION during a WhatsApp call could
     * mute the user for the other person. The helper's microphone is therefore never privacy-sensitive (see [open]).
     * Whether Android also feeds it the sound depends on the concurrency bypass, which needs [BYPASS_CONCURRENT] since
     * Android 16/17 (system apps only); before it CAPTURE_AUDIO_OUTPUT, which the shell holds, was enough.
     */
    private fun inCallNow(): Boolean {
        val mode = audioManager.mode
        return mode == AudioManager.MODE_IN_COMMUNICATION || mode == AudioManager.MODE_IN_CALL
    }

    private fun bypassesConcurrentCapture(): Boolean {
        val defined = runCatching { context.packageManager.getPermissionInfo(BYPASS_CONCURRENT, 0) }.isSuccess
        return !defined || context.checkSelfPermission(BYPASS_CONCURRENT) == PackageManager.PERMISSION_GRANTED
    }

    /** True when our microphone has the sound while another app's real recorder is being fed silence (we are muting it). */
    private fun mutingAnother(mine: AudioRecord): Boolean = runCatching {
        val session = mine.audioSessionId
        val configs = audioManager.activeRecordingConfigurations
        val ours = configs.firstOrNull { it.clientAudioSessionId == session } ?: return false
        !ours.isClientSilenced &&
            configs.any { it.clientAudioSessionId != session && it.isClientSilenced && it.clientAudioSource !in LINE_SOURCES }
    }.getOrDefault(false)

    /**
     * The near-end microphone: the chosen source first, then the others, each with and without the shell attribution
     * (OEM builds differ). Only a recorder that really started recording is used.
     */
    private fun openMic(rate: Int, micSource: Int, problems: MutableList<String>): AudioRecord? {
        val sources = listOf(
            if (micSource > 0) micSource else MediaRecorder.AudioSource.VOICE_COMMUNICATION,
            MediaRecorder.AudioSource.MIC,
            MediaRecorder.AudioSource.VOICE_RECOGNITION
        ).distinct()
        val mine = mutableListOf<String>()
        for (source in sources) {
            // An earlier attempt failing does not matter once one works; the other side's notes are kept.
            open(source, rate, "mic source $source", mine)?.let { return it }
        }
        problems += mine
        return null
    }

    /** A SIM call's own voice (since v1.2): the uplink of the phone line, which Android never mutes. */
    private fun openUplink(problems: MutableList<String>): AudioRecord? =
        open(MediaRecorder.AudioSource.VOICE_UPLINK, LINE_RATE, "phone line uplink", problems)

    /**
     * A SIM call's far side (since v1.2): the call audio interception API first, then the downlink of the phone line
     * (both need permissions the shell holds). Some phones only give the whole call (both voices); then the second value
     * is true. Null when nothing works.
     */
    private fun openPhoneLine(problems: MutableList<String>): Pair<AudioRecord, Boolean>? {
        downlinkInterception(lineFormat(), problems)?.let { record ->
            if (started(record)) return record to false
            problems += "phone line interception: did not start"
            runCatching { record.release() }
        }
        open(MediaRecorder.AudioSource.VOICE_DOWNLINK, LINE_RATE, "phone line downlink", problems)?.let { return it to false }
        open(MediaRecorder.AudioSource.VOICE_CALL, LINE_RATE, "phone line", problems)?.let { return it to true }
        return null
    }

    /** Opens and starts [source] at [rate], as the shell first and then with the process's own attribution. */
    @SuppressLint("MissingPermission")
    private fun open(source: Int, rate: Int, label: String, problems: MutableList<String>): AudioRecord? {
        val format = AudioFormat.Builder().setSampleRate(rate).setChannelMask(AudioFormat.CHANNEL_IN_MONO)
            .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
        val minBuffer = AudioRecord.getMinBufferSize(rate, AudioFormat.CHANNEL_IN_MONO, AudioFormat.ENCODING_PCM_16BIT)
            .coerceAtLeast(rate / 100 * 2 * 8)
        for (withContext in listOf(true, false)) {
            if (withContext && Build.VERSION.SDK_INT < 31) continue
            val record = runCatching {
                val builder = AudioRecord.Builder().setAudioSource(source).setAudioFormat(format).setBufferSizeInBytes(minBuffer * 2)
                if (withContext && Build.VERSION.SDK_INT >= 31) builder.setContext(context)
                // A microphone is never privacy-sensitive: such a recorder would take the microphone from another app's
                // call. The phone line's sources are left alone: Android refuses the flag on them (build() throws
                // "Cannot request private capture with source"), and they never compete for the microphone anyway.
                if (Build.VERSION.SDK_INT >= 30 && source !in LINE_SOURCES) builder.setPrivacySensitive(false)
                builder.build()
            }.getOrElse {
                problems += "$label: ${reason(it)}"
                null
            } ?: continue
            if (started(record)) return record
            problems += "$label: did not start"
            runCatching { record.release() }
        }
        return null
    }

    private fun lineFormat(): AudioFormat = AudioFormat.Builder().setSampleRate(LINE_RATE).setChannelMask(AudioFormat.CHANNEL_IN_MONO)
        .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()

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
