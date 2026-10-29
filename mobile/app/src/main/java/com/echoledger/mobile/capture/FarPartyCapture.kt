package com.echoledger.mobile.capture

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord
import android.media.AudioTrack
import android.media.MediaRecorder
import android.os.Build
import android.os.SystemClock
import com.echoledger.mobile.recording.Pcm
import kotlin.math.PI
import kotlin.math.sin
import org.lsposed.hiddenapibypass.HiddenApiBypass

/**
 * The other side of an app call (WhatsApp, Meet, Zoom…): a loop-back playback mix of USAGE_VOICE_COMMUNICATION (the
 * user keeps hearing the call), read through createAudioRecordSink. Runs in the shell-UID helper only (it holds
 * MODIFY_AUDIO_ROUTING and CAPTURE_VOICE_COMMUNICATION_OUTPUT). Hidden APIs, reached by reflection.
 *
 * Android fixes a player's routing when the player is created, so the mix has to be registered ("armed") before the call
 * app creates its voice track; a mix registered when the call starts leaves the other side silent for the whole call.
 * [arm] therefore runs when call capture is switched on and whenever the helper (re)starts, and the sink is opened only
 * when a call starts ([openSink]). Arming holds no wake lock and costs nothing while no sink reads.
 *
 * Measured on the S25 Ultra (Android 17): the policy is built with a null Context (permission checks then use the
 * helper's own uid and the sink's recorder names no package, which the audio server accepts; the shell or system
 * context made every sink fail with "Cannot create AudioRecord"), and the rule never asks for privileged media capture
 * (that caps the mix at 16 kHz mono; voice-communication capture does not need it). docs/research/android-capture.md §2.
 */
class FarPartyCapture(private val audioManager: AudioManager, val sampleRate: Int = Pcm.CAPTURE_RATE) {
    private var policy: Any? = null
    private var policyClass: Class<*>? = null
    private var mix: Any? = null
    private var mixClass: Class<*>? = null

    var record: AudioRecord? = null
        private set

    /** When the mix was registered ([SystemClock.elapsedRealtime]); 0 while not armed. */
    @Volatile var armedAtMs: Long = 0L
        private set

    /** Players that already existed when the mix was registered: their audio never reaches it. */
    @Volatile var playersBeforeArm: Set<Int> = emptySet()
        private set

    val armed: Boolean get() = policy != null

    init {
        runCatching { HiddenApiBypass.addHiddenApiExemptions("L") }
    }

    /** Registers the mix. Null when it is armed (or already was), else the reason it could not be. */
    @Synchronized
    fun arm(): String? {
        if (policy != null) return null
        return runCatching { register() }.fold(onSuccess = { null }, onFailure = { reason(it) })
    }

    private fun register() {
        val format = AudioFormat.Builder()
            .setSampleRate(sampleRate)
            .setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
            .setEncoding(AudioFormat.ENCODING_PCM_16BIT)
            .build()
        val usage = AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_VOICE_COMMUNICATION).build()

        val ruleBuilderClass = Class.forName("android.media.audiopolicy.AudioMixingRule\$Builder")
        val ruleBuilder = ruleBuilderClass.getConstructor().newInstance()
        val addMixRule = runCatching { ruleBuilderClass.getMethod("addMixRule", Int::class.javaPrimitiveType, Any::class.java) }.getOrNull()
        if (addMixRule != null) {
            addMixRule.invoke(ruleBuilder, RULE_MATCH_ATTRIBUTE_USAGE, usage)
        } else {
            ruleBuilderClass.getMethod("addRule", AudioAttributes::class.java, Int::class.javaPrimitiveType)
                .invoke(ruleBuilder, usage, RULE_MATCH_ATTRIBUTE_USAGE)
        }
        // Voice-communication capture only. Never allowPrivilegedPlaybackCapture: it limits the mix to 16 kHz mono.
        runCatching {
            ruleBuilderClass.getMethod("voiceCommunicationCaptureAllowed", Boolean::class.javaPrimitiveType).invoke(ruleBuilder, true)
        }
        val rule = ruleBuilderClass.getMethod("build").invoke(ruleBuilder)

        val ruleClass = Class.forName("android.media.audiopolicy.AudioMixingRule")
        val mixBuilderClass = Class.forName("android.media.audiopolicy.AudioMix\$Builder")
        val mixBuilder = mixBuilderClass.getConstructor(ruleClass).newInstance(rule)
        mixBuilderClass.getMethod("setFormat", AudioFormat::class.java).invoke(mixBuilder, format)
        mixBuilderClass.getMethod("setRouteFlags", Int::class.javaPrimitiveType).invoke(mixBuilder, ROUTE_FLAG_LOOP_BACK_RENDER)
        val builtMix = mixBuilderClass.getMethod("build").invoke(mixBuilder)
        val builtMixClass = Class.forName("android.media.audiopolicy.AudioMix")

        val policyBuilderClass = Class.forName("android.media.audiopolicy.AudioPolicy\$Builder")
        // A null Context: see the class comment.
        val policyBuilder = policyBuilderClass.getConstructor(Context::class.java).newInstance(*arrayOf<Any?>(null))
        policyBuilderClass.getMethod("addMix", builtMixClass).invoke(policyBuilder, builtMix)
        val builtPolicy = policyBuilderClass.getMethod("build").invoke(policyBuilder)
        val pc = Class.forName("android.media.audiopolicy.AudioPolicy")

        val before = currentPlayers()
        val result = AudioManager::class.java.getMethod("registerAudioPolicy", pc).invoke(audioManager, builtPolicy) as Int
        check(result == 0) { "registerAudioPolicy refused the mix ($result)" }
        policy = builtPolicy
        policyClass = pc
        mix = builtMix
        mixClass = builtMixClass
        playersBeforeArm = before
        armedAtMs = SystemClock.elapsedRealtime()
    }

    /** How the last sink was made ("shell" or "policy"), for diagnostics. */
    @Volatile var sinkKind: String? = null
        private set

    /**
     * Opens and starts the sink for one call. Throws with the reasons when Android gives none.
     *
     * First a recorder of the mix's address made here with [recorderContext] (the shell package, which owns UID 2000):
     * the policy's own createAudioRecordSink names the process's current application instead, which in a helper started
     * through ActivityThread.systemMain() (Shizuku, app_process) is package "android" and is refused for UID 2000. The
     * policy's sink is the fallback for builds where that application is valid.
     */
    @Synchronized
    fun openSink(recorderContext: Context?): AudioRecord {
        record?.let { return it }
        arm()?.let { error("the call mix could not be armed: $it") }
        val problems = mutableListOf<String>()
        val made = listOf<Pair<String, () -> AudioRecord?>>(
            "shell" to { shellSink(recorderContext) },
            "policy" to { policyClass!!.getMethod("createAudioRecordSink", mixClass).invoke(policy, mix) as AudioRecord? }
        )
        for ((kind, make) in made) {
            val sink = runCatching { make() }.getOrElse {
                problems += "$kind: ${reason(it)}"
                null
            } ?: continue
            if (sink.state != AudioRecord.STATE_INITIALIZED) {
                runCatching { sink.release() }
                problems += "$kind: did not initialise"
                continue
            }
            runCatching { sink.startRecording() }
            if (sink.recordingState != AudioRecord.RECORDSTATE_RECORDING) {
                runCatching { sink.release() }
                problems += "$kind: did not start"
                continue
            }
            record = sink
            sinkKind = kind
            return sink
        }
        error(problems.joinToString("; ").ifEmpty { "Android gave no recorder for the call mix" })
    }

    /** The recorder createAudioRecordSink would make (remote submix at the mix's address), under [context]'s identity. */
    private fun shellSink(context: Context?): AudioRecord {
        val registration = mixClass!!.getMethod("getRegistration").invoke(mix) as String?
        check(!registration.isNullOrEmpty()) { "the call mix has no address" }
        val attributes = AudioAttributes.Builder()
        val ab = AudioAttributes.Builder::class.java
        ab.getMethod("setInternalCapturePreset", Int::class.javaPrimitiveType).invoke(attributes, MediaRecorder.AudioSource.REMOTE_SUBMIX)
        ab.getMethod("addTag", String::class.java).invoke(attributes, "addr=$registration")
        ab.getMethod("addTag", String::class.java).invoke(attributes, SUBMIX_FIXED_VOLUME)
        val format = AudioFormat.Builder().setSampleRate(sampleRate).setChannelMask(AudioFormat.CHANNEL_IN_MONO)
            .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
        val minBuffer = AudioRecord.getMinBufferSize(sampleRate, AudioFormat.CHANNEL_IN_STEREO, AudioFormat.ENCODING_PCM_16BIT)
        val builder = AudioRecord.Builder()
        AudioRecord.Builder::class.java.getMethod("setAudioAttributes", AudioAttributes::class.java).invoke(builder, attributes.build())
        builder.setAudioFormat(format).setBufferSizeInBytes(minBuffer.coerceAtLeast(sampleRate / 10) * 4)
        if (context != null && Build.VERSION.SDK_INT >= 31) builder.setContext(context)
        return builder.build()
    }

    /** Ends one call's sink; the mix stays armed for the next call. */
    @Synchronized
    fun closeSink() {
        val r = record ?: return
        record = null
        runCatching { r.stop() }
        runCatching { r.release() }
    }

    @Synchronized
    fun disarm() {
        closeSink()
        val p = policy ?: return
        runCatching { AudioManager::class.java.getMethod("unregisterAudioPolicy", policyClass).invoke(audioManager, p) }
        policy = null
        mix = null
        armedAtMs = 0L
        playersBeforeArm = emptySet()
    }

    /** Whether the voice player [piid] existed before the mix was armed (its audio then never reaches the mix). */
    fun createdBeforeArm(piid: Int): Boolean = piid in playersBeforeArm

    private fun currentPlayers(): Set<Int> = runCatching {
        audioManager.activePlaybackConfigurations.map { it.playerInterfaceId() }.toSet()
    }.getOrDefault(emptySet())

    /**
     * Proves the path without a call: plays a soft 1 kHz tone as a voice-call player created after arming and reads it
     * back through the sink. A plain line for diagnostics ("ok …" or "fail …"). Not used during a call.
     */
    fun selfTest(recorderContext: Context?, playerContext: Context? = recorderContext): String {
        if (record != null) return "busy: a call is being recorded"
        arm()?.let { return "fail: arm: $it" }
        val rate = sampleRate
        val track = runCatching {
            val builder = AudioTrack.Builder()
                .setAudioAttributes(
                    AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_VOICE_COMMUNICATION)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).build()
                )
                .setAudioFormat(
                    AudioFormat.Builder().setSampleRate(rate).setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
                        .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
                )
                .setTransferMode(AudioTrack.MODE_STREAM)
                .setBufferSizeInBytes(rate / 5 * 2)
            // The player needs the shell's identity too: the process's own one names package "android" (refused).
            if (playerContext != null && Build.VERSION.SDK_INT >= 34) builder.setContext(playerContext)
            builder.build()
        }.getOrElse { return "fail: test player: ${reason(it)}" }
        val sink = runCatching { openSink(recorderContext) }.getOrElse {
            track.release()
            return "fail: sink: ${reason(it)}"
        }
        val tone = ShortArray(rate / 50) { i -> (TONE_LEVEL * sin(2 * PI * 1000 * i / rate)).toInt().toShort() }
        val playing = java.util.concurrent.atomic.AtomicBoolean(true)
        val player = Thread {
            track.play()
            val until = SystemClock.elapsedRealtime() + 1600
            while (playing.get() && SystemClock.elapsedRealtime() < until) track.write(tone, 0, tone.size)
            runCatching { track.stop() }
        }.apply { start() }
        val buf = ShortArray(rate / 100)
        var frames = 0
        var peak = 0
        var sum = 0.0
        val until = SystemClock.elapsedRealtime() + 1400
        while (SystemClock.elapsedRealtime() < until) {
            val n = sink.read(buf, 0, buf.size)
            if (n <= 0) break
            for (k in 0 until n) {
                val v = buf[k].toInt()
                peak = maxOf(peak, kotlin.math.abs(v))
                sum += v.toDouble() * v
            }
            frames += n
        }
        playing.set(false)
        player.join(2_000)
        track.release()
        closeSink()
        val rms = if (frames > 0) kotlin.math.sqrt(sum / frames).toInt() else 0
        val verdict = if (peak > TONE_LEVEL / 4) "ok" else "fail: silent"
        return "$verdict sink=$sinkKind rate=$rate frames=$frames rms=$rms peak=$peak"
    }

    companion object {
        const val RULE_MATCH_ATTRIBUTE_USAGE = 0x1
        const val ROUTE_FLAG_LOOP_BACK_RENDER = 0x3
        private const val TONE_LEVEL = 1200

        /** AudioRecord.SUBMIX_FIXED_VOLUME (hidden): the mix is captured at full scale whatever the call volume. */
        private const val SUBMIX_FIXED_VOLUME = "fixedVolume"

        /** The real error behind a reflective call ("InvocationTargetException" alone says nothing). */
        fun reason(e: Throwable): String {
            var t = e
            while (true) {
                val next = (t as? java.lang.reflect.InvocationTargetException)?.targetException ?: t.cause
                if (next == null || next === t) break
                t = next
            }
            return t.javaClass.simpleName + (t.message?.let { ": $it" } ?: "")
        }
    }
}

/** The player's id (hidden getter; -1 when unavailable). Ids only grow, so a newer player has a larger one. */
internal fun android.media.AudioPlaybackConfiguration.playerInterfaceId(): Int =
    runCatching { javaClass.getMethod("getPlayerInterfaceId").invoke(this) as Int }.getOrDefault(-1)
