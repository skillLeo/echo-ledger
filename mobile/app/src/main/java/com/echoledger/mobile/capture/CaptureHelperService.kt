package com.echoledger.mobile.capture

import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioPlaybackConfiguration
import android.media.AudioRecord
import android.media.AudioTimestamp
import android.media.MediaRecorder
import android.os.Binder
import android.os.Build
import android.os.ParcelFileDescriptor
import android.os.Process
import android.os.SystemClock
import com.echoledger.mobile.core.CallTargets
import com.echoledger.mobile.core.PhoneCalls
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.recording.MicRetake
import com.echoledger.mobile.recording.Pcm
import com.echoledger.mobile.recording.SilenceWatch
import com.echoledger.mobile.recording.SlotPairer
import java.io.FileOutputStream
import kotlin.concurrent.thread

/**
 * The privileged helper (shell UID 2000): detects calls and captures both sides.
 * Runs inside a Shizuku UserService or an `app_process` launched over ADB; never inside the app process.
 *
 * An app call (any app in the call-app list): my side is the microphone (MIC, never privacy-sensitive, so it never
 * takes the microphone from the call app; replaced when One UI silences it mid-call, never when the call app mutes
 * it: [MicRetake]), the other side is the
 * voice-call mix armed before the call ([FarPartyCapture]). The two are paired by capture time ([SlotPairer]) and
 * checked for digital silence ([SilenceWatch]): a far side that stays silent is reported with its reason, never left
 * as a quiet one-sided recording. A SIM call: one recorder of the phone line ([openPhoneLine]).
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

        /** Since Android 16/17 the permission that lets a recorder keep the microphone beside another app's call. */
        private const val BYPASS_CONCURRENT = "android.permission.BYPASS_CONCURRENT_RECORD_AUDIO_RESTRICTION"

        /** Sources Android never mutes and that cannot mute anyone (they do not compete for the microphone). */
        private val LINE_SOURCES = setOf(
            MediaRecorder.AudioSource.VOICE_UPLINK,
            MediaRecorder.AudioSource.VOICE_DOWNLINK,
            MediaRecorder.AudioSource.VOICE_CALL,
            MediaRecorder.AudioSource.REMOTE_SUBMIX
        )

        /** AudioFormat.CHANNEL_IN_VOICE_UPLINK | CHANNEL_IN_VOICE_DNLINK (hidden): each direction of the line on its own channel. */
        private const val CHANNEL_IN_UPLINK_DOWNLINK = 0x4000 or 0x8000

        /** Said once when Android keeps feeding the microphone silence during another app's call. */
        private const val MIC_MUTED = "Your own voice is not recorded in this call: Android keeps giving EchoLedger's " +
            "microphone silence during this app's call. The other side is still recorded."

        /** Said once when a SIM call's phone line gives only silence (the phone maker keeps it for its own dialer). */
        private const val LINE_SILENT = "The phone line gave no sound, so this SIM call cannot be recorded through the " +
            "helper on this phone. Turn on the Phone app's call recording and EchoLedger imports its recordings."

        /** Reads of 10 ms after which a phone line that gave only zeros is reported as blocked. */
        private const val LINE_SILENT_READS = 800

        /** Said when the helper gave the microphone back because it was muting the call. */
        private const val MIC_GIVEN_BACK = "EchoLedger stopped recording your voice because it was muting the call for the other " +
            "person. The other side is still recorded."

        private const val NS_PER_SECOND = 1_000_000_000L
    }

    @Volatile private var listener: ICallListener? = null

    @Volatile private var capturing = false

    private var captureThread: Thread? = null

    /** The other side of app calls, armed before any call (see [FarPartyCapture]); one per helper process. */
    private val farMix by lazy { FarPartyCapture(audioManager) }

    /** The packages whose voice calls are calls: the app's call-app list (the known apps until it sends one). */
    @Volatile private var callApps: Set<String> = CallTargets.DEFAULT_PACKAGES

    /** The voice player of the call in progress, to tell whether it was created before the mix was armed. */
    @Volatile private var callPlayer: AudioPlaybackConfiguration? = null

    /** The package of the app call in progress, for the far-side messages. */
    @Volatile private var callPackage: String? = null

    /** Channels of the capture last started (see the AIDL). */
    @Volatile private var channels = 2

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
        append(";armed=").append(farMix.armed)
        append(";sink=").append(farMix.sinkKind)
        append(";call_apps=").append(callApps.size)
    }

    override fun setCallListener(listener: ICallListener?) {
        this.listener = listener
    }

    override fun arm(on: Boolean): String = asHelper {
        if (on) {
            farMix.arm()?.let { "error: $it" } ?: "armed"
        } else {
            if (!capturing) farMix.disarm()
            "disarmed"
        }
    }

    override fun setCallApps(packages: Array<out String>?) {
        callApps = packages?.mapNotNull(CallTargets::clean)?.toSet()?.takeIf { it.isNotEmpty() } ?: CallTargets.DEFAULT_PACKAGES
    }

    override fun selfTest(): String = asHelper { if (capturing) "busy: a call is being recorded" else farMix.selfTest(context) }

    override fun captureChannels(): Int = channels

    override fun startCapture(sink: ParcelFileDescriptor, sampleRate: Int, micSource: Int): Boolean =
        asHelper { beginAsHelper(sink, sampleRate, micSource, phone = false) }

    override fun startPhoneCapture(sink: ParcelFileDescriptor, sampleRate: Int, micSource: Int): Boolean =
        asHelper { beginAsHelper(sink, sampleRate, micSource, phone = true) }

    /**
     * Runs [block] as the helper itself. Called over binder from the app: Android's in-process checks (AudioPolicy looks
     * at the calling uid) would see the app, which holds none of the audio permissions ("Cannot use AudioPolicy for …
     * uid 10462, needs MODIFY_AUDIO_ROUTING"), so the app's identity is cleared and the helper's shell uid is checked.
     */
    private inline fun <T> asHelper(block: () -> T): T {
        val identity = Binder.clearCallingIdentity()
        try {
            return block()
        } finally {
            Binder.restoreCallingIdentity(identity)
        }
    }

    /**
     * One recorder feeding the capture: its rate, its channels (2 only for a phone line that gives each direction apart),
     * which lane of the recording it fills (0 = me, 1 = the other side), and whether it is the microphone.
     */
    private class Side(val record: AudioRecord, val rate: Int, val lane: Int, val mic: Boolean, val channels: Int = 1) {
        val chunk = rate / 100 // 10 ms
        val buf = ShortArray(chunk * channels * 8)
        val last = ShortArray(2)
        private val stamp = AudioTimestamp()
        private var framesRead = 0L

        /** Whether any sound at all has arrived (a blocked phone line gives exact zeros). */
        var heard = false

        /** The last chunk read: when its first frame was captured, and whether every sample was 0. */
        var at = 0L
        var zeros = true

        /**
         * The monotonic time the first of [frames] frames just read was captured: from the recorder's own timestamp, so
         * both sides line up by when they were heard, not by when the reads returned; the arrival time when there is none.
         */
        fun startOf(frames: Int): Long {
            val first = framesRead
            framesRead += frames
            val ok = runCatching { record.getTimestamp(stamp, AudioTimestamp.TIMEBASE_MONOTONIC) == AudioRecord.SUCCESS }
                .getOrDefault(false)
            return if (ok && stamp.nanoTime > 0) {
                stamp.nanoTime + (first - stamp.framePosition) * NS_PER_SECOND / rate
            } else {
                System.nanoTime() - frames * NS_PER_SECOND / rate
            }
        }

        fun release() {
            runCatching { record.stop() }
            runCatching { record.release() }
        }
    }

    @SuppressLint("MissingPermission") // shell holds RECORD_AUDIO and CAPTURE_AUDIO_OUTPUT
    private fun beginAsHelper(sink: ParcelFileDescriptor, sampleRate: Int, micSource: Int, phone: Boolean): Boolean {
        if (capturing) return true
        val rate = if (sampleRate > 0) sampleRate else Pcm.CAPTURE_RATE
        val problems = mutableListOf<String>()
        var near: Side? = null
        var far: Side? = null
        if (phone) {
            // A SIM call: one recorder of the phone line and nothing else. A second voice recorder beside it makes
            // Android drop one direction without a word, so the microphone is never opened here.
            near = openPhoneLine(rate, problems)
            channels = if (near?.channels == 2) 2 else 1
        } else {
            // During another app's call the microphone is an ordinary recorder (MIC, not privacy-sensitive): Android then
            // keeps the call app's recorder and never mutes it for ours. VOICE_COMMUNICATION is fed zeros on One UI.
            val source = if (inCallNow() || micSource <= 0) MediaRecorder.AudioSource.MIC else micSource
            near = openMic(rate, source, problems)?.let { Side(it, rate, lane = 0, mic = true) }
            far = runCatching { farMix.openSink(context) }
                .onFailure { problems += "other side: ${FarPartyCapture.reason(it)}" }
                .getOrNull()
                ?.let { Side(it, farMix.sampleRate, lane = 1, mic = false) }
            if (far != null) lateArmReason()?.let { problems += it }
            channels = 2
        }

        // Nothing to record: say why (the app shows it) instead of a recording that stays silent.
        if (near == null && far == null) {
            report("Call capture could not start: ${problems.joinToString("; ").ifEmpty { "no recorder could be opened" }}")
            farMix.closeSink()
            runCatching { sink.close() }
            return false
        }
        if (problems.isNotEmpty()) report("Call capture is partly working: ${problems.joinToString("; ")}")
        capturing = true
        captureThread = thread(name = "el-helper-capture") { pump(sink, rate, near, far, phone) }
        return true
    }

    /**
     * Writes the call as interleaved stereo at [rate] (L = me, R = the other side; a mixed phone line fills L only).
     * The first side paces the loop (blocking reads of 10 ms), the other is read without blocking; both go through the
     * [SlotPairer] by capture time. While the microphone is open it is checked often: if Android starts muting another
     * app's recorder because of it, it is given back at once; if it goes digitally silent while not muted, a new one is
     * opened beside it and used only once it gives sound (the working one is never released first).
     */
    private fun pump(sink: ParcelFileDescriptor, rate: Int, first: Side?, far: Side?, phone: Boolean) {
        val out = FileOutputStream(sink.fileDescriptor)
        var pairer = SlotPairer(rate)
        val watch = SilenceWatch(rate)
        val retake = MicRetake(rate)
        var near = first
        var candidate: Side? = null
        var failedReads = 0
        var reads = 0
        var farSaid = false
        var lineSaid = false
        var suspended = false
        try {
            while (capturing) {
                reads++
                // A SIM call that takes over during an app call holds it: nothing is written until the app call is back.
                if (!phone && reads % CHECK_EVERY_READS == 0) {
                    val held = audioManager.mode == AudioManager.MODE_IN_CALL
                    if (held != suspended) {
                        suspended = held
                        if (held) out.write(Pcm.toLittleEndianBytes(pairer.flush())) else pairer = SlotPairer(rate)
                        state(if (held) "suspended" else "resumed")
                    }
                }
                // A SIM call's phone line that gave nothing but zeros for 8 s is blocked by the phone maker: say so.
                if (phone && !lineSaid && reads == LINE_SILENT_READS && near?.heard != true) {
                    lineSaid = true
                    report(LINE_SILENT)
                    state("line_silent")
                }
                val current = near
                // The microphone is checked on every read for the first half second, then four times a second.
                if (current != null && current.mic && (reads <= 50 || reads % CHECK_EVERY_READS == 0)) {
                    if (mutingAnother(current.record)) {
                        current.release()
                        near = null
                        candidate?.release()
                        candidate = null
                        retake.candidateFailed()
                        report(MIC_GIVEN_BACK)
                        continue
                    }
                }
                val primary = near ?: far ?: break
                val n = pull(primary, pairer, rate, blocking = true, keep = !suspended)
                if (primary === near && far != null) pull(far, pairer, rate, blocking = false, keep = !suspended)
                if (n <= 0) {
                    // A recorder that keeps failing would leave a silent file: stop and say so.
                    if (++failedReads >= MAX_FAILED_READS) {
                        // A microphone that died mid-call (a headset unplugged) leaves the other side running: keep it.
                        if (primary === near && far != null) {
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

                // Digital silence on my microphone: the call app's mute (said, never retaken), or Android silencing
                // it (One UI does): a new recorder is opened beside it and replaces it only once it gives sound.
                val mic = near
                if (mic != null && mic.mic && primary === mic) {
                    when (retake.mic(n, mic.zeros, SystemClock.elapsedRealtime()) { audioManager.isMicrophoneMute }) {
                        MicRetake.Step.MUTED -> state("self_muted")

                        MicRetake.Step.UNMUTED -> state("self_unmuted")

                        MicRetake.Step.OPEN_CANDIDATE -> {
                            candidate =
                                openMic(rate, MediaRecorder.AudioSource.MIC, mutableListOf())?.let { Side(it, rate, lane = 0, mic = true) }
                            if (candidate == null) retake.candidateFailed()
                            state(if (candidate == null) "mic_retake_failed" else "mic_retake_open")
                        }

                        MicRetake.Step.GIVE_UP -> report(MIC_MUTED)

                        MicRetake.Step.NONE -> Unit
                    }
                }
                val next = candidate
                if (next != null) {
                    val got = read(next, blocking = false)
                    when (retake.candidate(got.coerceAtLeast(0), next.zeros, SystemClock.elapsedRealtime())) {
                        MicRetake.Probe.SWAP -> {
                            near?.release()
                            near = next
                            candidate = null
                            if (!suspended) push(next, pairer, rate, got)
                            state("mic_retake_swapped")
                        }

                        MicRetake.Probe.DROP -> {
                            next.release()
                            candidate = null
                            state("mic_retake_kept")
                        }

                        MicRetake.Probe.WAIT -> Unit
                    }
                }

                val block = pairer.drain()
                if (block.isEmpty()) continue
                watch.feed(block)
                out.write(Pcm.toLittleEndianBytes(block))

                if (far != null && !farSaid && watch.farSilent()) {
                    farSaid = true
                    report(farSilentReason())
                    state("far_silent")
                }
            }
        } catch (e: Exception) {
            report("Capture stopped: ${e.message}")
        } finally {
            runCatching { out.write(Pcm.toLittleEndianBytes(pairer.flush())) }
            near?.release()
            candidate?.release()
            farMix.closeSink()
            runCatching { out.close() }
            runCatching { sink.close() }
            capturing = false
        }
    }

    /**
     * Reads one chunk of [side] (a blocking 10 ms, or what is waiting), raises it to [rate] and hands it to the pairer at
     * its capture time; frames read. [keep] false (suspended) reads and drops, so the recorder stays open and current.
     */
    private fun pull(side: Side, pairer: SlotPairer, rate: Int, blocking: Boolean, keep: Boolean): Int {
        val frames = read(side, blocking)
        if (frames > 0 && keep) push(side, pairer, rate, frames)
        return frames
    }

    /** Reads one chunk of [side] without handing it on; frames read (an error code when negative). */
    private fun read(side: Side, blocking: Boolean): Int {
        val got = if (blocking) {
            side.record.read(side.buf, 0, side.chunk * side.channels)
        } else {
            side.record.read(side.buf, 0, side.buf.size, AudioRecord.READ_NON_BLOCKING)
        }
        if (got <= 0) return got
        val frames = got / side.channels
        side.at = side.startOf(frames)
        side.zeros = (0 until got).all { side.buf[it].toInt() == 0 }
        if (!side.zeros) side.heard = true
        return frames
    }

    /** Hands the chunk just read by [read] to the pairer at its capture time. */
    private fun push(side: Side, pairer: SlotPairer, rate: Int, frames: Int) {
        val at = side.at
        val factor = (rate / side.rate).coerceAtLeast(1)
        for (channel in 0 until side.channels) {
            val mono = if (side.channels == 1) side.buf else ShortArray(frames) { side.buf[it * side.channels + channel] }
            val lane = if (side.channels == 1) side.lane else channel
            pairer.push(lane, at, Pcm.upsample(mono, frames, factor, side.last[channel]), frames * factor)
            side.last[channel] = mono[frames - 1]
        }
    }

    /** The reason the other side of an app call is not reaching the recording, as plainly as Android lets us tell. */
    private fun farSilentReason(): String {
        val app = SourceApp.fromPackage(callPackage)?.label ?: "the call app"
        val player = callPlayer
        return when {
            player != null && farMix.createdBeforeArm(player.playerInterfaceId()) ->
                "The other side of this call is not being recorded: the $app call started before call capture was ready " +
                    "(the helper had just started). Calls from now on record both sides."

            player?.audioAttributes?.allowedCapturePolicy == AudioAttributes.ALLOW_CAPTURE_BY_NONE ->
                "The other side of this call is not being recorded: $app does not let other apps record its call audio."

            else ->
                "The other side of this call is silent: Android gave EchoLedger no sound from $app while you talked. " +
                    "Run Settings, Call capture, Test to check the helper."
        }
    }

    /** Said at the start when the call's voice player is older than the armed mix (its audio never reaches it). */
    private fun lateArmReason(): String? {
        val player = callPlayer ?: return null
        if (!farMix.createdBeforeArm(player.playerInterfaceId())) return null
        return "the call started before call capture was ready, so its other side cannot be recorded (the next call will be)"
    }

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
        val sources = listOf(micSource, MediaRecorder.AudioSource.MIC, MediaRecorder.AudioSource.VOICE_RECOGNITION).distinct()
        val mine = mutableListOf<String>()
        for (source in sources) {
            // An earlier attempt failing does not matter once one works; the other side's notes are kept.
            open(source, rate, AudioFormat.CHANNEL_IN_MONO, "mic source $source", mine)?.let { return it }
        }
        problems += mine
        return null
    }

    /**
     * A SIM call (since v1.3): one recorder of the whole phone line (VOICE_CALL; the shell holds CAPTURE_AUDIO_OUTPUT).
     * Each direction on its own channel when the phone offers it (L = my uplink, R = the downlink), else both voices on
     * one track; 48 kHz first, then the 16 kHz most phone lines run at. Null when nothing works.
     */
    private fun openPhoneLine(rate: Int, problems: MutableList<String>): Side? {
        for (lineRate in listOf(rate, Pcm.STT_RATE).distinct()) {
            open(MediaRecorder.AudioSource.VOICE_CALL, lineRate, CHANNEL_IN_UPLINK_DOWNLINK, "phone line (both directions)", problems)
                ?.let { return Side(it, lineRate, lane = 0, mic = false, channels = 2) }
            open(MediaRecorder.AudioSource.VOICE_CALL, lineRate, AudioFormat.CHANNEL_IN_MONO, "phone line", problems)
                ?.let { return Side(it, lineRate, lane = 0, mic = false) }
        }
        return null
    }

    /** Opens and starts [source] at [rate], as the shell first and then with the process's own attribution. */
    @SuppressLint("MissingPermission")
    private fun open(source: Int, rate: Int, mask: Int, label: String, problems: MutableList<String>): AudioRecord? {
        val format = runCatching {
            AudioFormat.Builder().setSampleRate(rate).setEncoding(AudioFormat.ENCODING_PCM_16BIT).setChannelMask(mask).build()
        }.getOrElse {
            problems += "$label: ${FarPartyCapture.reason(it)}"
            return null
        }
        val count = Integer.bitCount(mask)
        val minBuffer = AudioRecord.getMinBufferSize(
            rate,
            if (count ==
                2
            ) {
                AudioFormat.CHANNEL_IN_STEREO
            } else {
                AudioFormat.CHANNEL_IN_MONO
            },
            AudioFormat.ENCODING_PCM_16BIT
        )
            .coerceAtLeast(rate / 100 * 2 * count * 8)
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
                problems += "$label: ${FarPartyCapture.reason(it)}"
                null
            } ?: continue
            if (started(record)) return record
            problems += "$label: did not start"
            runCatching { record.release() }
        }
        return null
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

    private fun state(value: String) {
        runCatching { listener?.onCaptureState(value) }
    }

    override fun stopCapture() {
        capturing = false
        captureThread?.join(2_000)
        captureThread = null
    }

    // ---- Live Translate (since v1.3, plan B0/B1): one app's sound, never a call -------------------------------------

    /** Mixes registered ahead of time, per UID (Android routes a player when it is created). */
    private val appMixes = java.util.concurrent.ConcurrentHashMap<Int, AppAudioMix>()

    @Volatile private var appCapturing = false
    private var appThread: Thread? = null
    private var appMix: AppAudioMix? = null

    override fun players(): String = asHelper {
        AppAudioMix.players(audioManager) { uid -> context.packageManager.getPackagesForUid(uid)?.toList().orEmpty() }
    }

    override fun armApps(uids: IntArray?, voiceChat: Boolean): String = asHelper {
        val wanted = uids?.filter { it > 0 }?.toSet().orEmpty()
        farMix.exclude(wanted)
        appMixes.keys.filter { it !in wanted && appMix?.uid != it }.forEach { appMixes.remove(it)?.disarm() }
        val problems = wanted.mapNotNull { uid ->
            appMixes.getOrPut(uid) { AppAudioMix(audioManager, uid, usage = 0, voiceChat = voiceChat) }.arm()?.let { "$uid: $it" }
        }
        if (problems.isEmpty()) "armed" else "error: ${problems.joinToString("; ")}"
    }

    override fun startAppCapture(sink: ParcelFileDescriptor, uid: Int, usage: Int, voiceChat: Boolean): Boolean = asHelper {
        stopAppCapture()
        // A mix armed before the app started is the one that hears it; a new one only hears players made from now on.
        val mix = appMixes[uid]?.takeIf { usage == 0 } ?: AppAudioMix(audioManager, uid, usage, voiceChat)
        val record = runCatching { mix.open(context) }.getOrElse {
            report("Live Translate could not hear the app: ${FarPartyCapture.reason(it)}")
            if (appMixes[uid] !== mix) mix.disarm()
            runCatching { sink.close() }
            return@asHelper false
        }
        appMix = mix
        appCapturing = true
        appThread = thread(name = "el-helper-translate") {
            val out = FileOutputStream(sink.fileDescriptor)
            val buf = ShortArray(AppAudioMix.RATE / 10)
            val bytes = ByteArray(buf.size * 2)
            try {
                while (appCapturing) {
                    val n = record.read(buf, 0, buf.size)
                    if (n < 0) break
                    for (i in 0 until n) {
                        bytes[2 * i] = (buf[i].toInt() and 0xff).toByte()
                        bytes[2 * i + 1] = (buf[i].toInt() shr 8 and 0xff).toByte()
                    }
                    out.write(bytes, 0, n * 2)
                }
            } catch (_: java.io.IOException) {
                // The app closed its end: Live Translate stopped.
            } finally {
                runCatching { out.close() }
                runCatching { sink.close() }
                if (appMixes[mix.uid] === mix) mix.close() else mix.disarm()
                appCapturing = false
            }
        }
        true
    }

    override fun stopAppCapture() {
        appCapturing = false
        appThread?.join(1_000)
        appThread = null
        appMix = null
    }

    override fun destroy() {
        stopCapture()
        stopAppCapture()
        appMixes.values.forEach { it.disarm() }
        farMix.disarm()
        System.exit(0)
    }

    /**
     * Call detection: VoIP sets MODE_IN_COMMUNICATION; the helper (holding MODIFY_AUDIO_ROUTING) can see the real UID of
     * the voice-communication player and checks it belongs to an app in the call-app list. A SIM call sets
     * MODE_IN_CALL (reported as [CallTargets.TELEPHONY]; the app decides whether to record it).
     */
    private fun startCallMonitor() {
        thread(name = "el-helper-calls", isDaemon = true) {
            var inCall = false
            while (true) {
                runCatching {
                    val mode = audioManager.mode
                    // A SIM call that holds an app call being recorded is not a new call: the capture suspends itself.
                    val pkg = if (capturing && inCall && mode == AudioManager.MODE_IN_CALL) {
                        null
                    } else {
                        PhoneCalls.callApp(mode) { voiceCallApp() }
                    }
                    if (!inCall && pkg != null) {
                        inCall = true
                        callPackage = pkg
                        listener?.onCallStarted(pkg)
                    } else if (inCall && !PhoneCalls.inCall(mode)) {
                        inCall = false
                        callPlayer = null
                        callPackage = null
                        listener?.onCallEnded()
                    }
                }
                Thread.sleep(500)
            }
        }
    }

    /** The call-app package playing a voice call (its player is kept for the far-side checks); null for any other app. */
    private fun voiceCallApp(): String? {
        val pm = context.packageManager
        for (config in audioManager.activePlaybackConfigurations) {
            if (config.audioAttributes.usage != AudioAttributes.USAGE_VOICE_COMMUNICATION) continue
            val uid = runCatching { config.javaClass.getMethod("getClientUid").invoke(config) as Int }.getOrNull() ?: continue
            val pkg = CallTargets.firstTarget(pm.getPackagesForUid(uid), callApps) ?: continue
            callPlayer = config
            return pkg
        }
        return null
    }
}
