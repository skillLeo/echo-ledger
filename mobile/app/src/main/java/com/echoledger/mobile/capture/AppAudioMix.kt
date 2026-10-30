package com.echoledger.mobile.capture

import android.annotation.SuppressLint
import android.content.Context
import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioPlaybackConfiguration
import android.media.AudioRecord
import android.media.MediaRecorder
import android.os.Build
import org.json.JSONArray
import org.json.JSONObject

/**
 * One app's sound for Live Translate (since v1.3, plan B0): a loop-back playback mix that matches the app's UID (and,
 * when given, one usage such as voice chat), read at 16 kHz mono. The person keeps hearing the app.
 *
 * Like the call mix it must be registered before the app creates its players ([arm] when Game mode is on and again
 * when the game starts). It never asks for privileged playback capture, so an app that opts out of capture (Netflix and
 * other protected video) stays silent here; [voiceChat] allows voice-communication players, which is what game voice
 * chat uses on most games. Runs in the shell-UID helper only.
 */
class AppAudioMix(private val audioManager: AudioManager, val uid: Int, val usage: Int, private val voiceChat: Boolean) {
    private var policy: Any? = null
    private var policyClass: Class<*>? = null
    private var mix: Any? = null
    private var mixClass: Class<*>? = null

    var record: AudioRecord? = null
        private set

    val armed: Boolean get() = policy != null

    /** Registers the mix; null when it is registered, else why not. */
    @Synchronized
    fun arm(): String? {
        if (policy != null) return null
        return runCatching { register() }.fold(onSuccess = { null }, onFailure = { FarPartyCapture.reason(it) })
    }

    private fun register() {
        val format = AudioFormat.Builder().setSampleRate(RATE).setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
            .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
        val ruleBuilderClass = Class.forName("android.media.audiopolicy.AudioMixingRule\$Builder")
        val ruleBuilder = ruleBuilderClass.getConstructor().newInstance()
        val addMixRule = ruleBuilderClass.getMethod("addMixRule", Int::class.javaPrimitiveType, Any::class.java)
        addMixRule.invoke(ruleBuilder, RULE_MATCH_UID, uid)
        if (usage >
            0
        ) {
            addMixRule.invoke(
                ruleBuilder,
                FarPartyCapture.RULE_MATCH_ATTRIBUTE_USAGE,
                AudioAttributes.Builder().setUsage(usage).build()
            )
        }
        if (voiceChat) {
            runCatching {
                ruleBuilderClass.getMethod("voiceCommunicationCaptureAllowed", Boolean::class.javaPrimitiveType).invoke(ruleBuilder, true)
            }
        }
        val rule = ruleBuilderClass.getMethod("build").invoke(ruleBuilder)
        val ruleClass = Class.forName("android.media.audiopolicy.AudioMixingRule")
        val mixBuilderClass = Class.forName("android.media.audiopolicy.AudioMix\$Builder")
        val mixBuilder = mixBuilderClass.getConstructor(ruleClass).newInstance(rule)
        mixBuilderClass.getMethod("setFormat", AudioFormat::class.java).invoke(mixBuilder, format)
        mixBuilderClass.getMethod(
            "setRouteFlags",
            Int::class.javaPrimitiveType
        ).invoke(mixBuilder, FarPartyCapture.ROUTE_FLAG_LOOP_BACK_RENDER)
        val builtMix = mixBuilderClass.getMethod("build").invoke(mixBuilder)
        val builtMixClass = Class.forName("android.media.audiopolicy.AudioMix")
        val policyBuilderClass = Class.forName("android.media.audiopolicy.AudioPolicy\$Builder")
        val policyBuilder = policyBuilderClass.getConstructor(Context::class.java).newInstance(*arrayOf<Any?>(null))
        policyBuilderClass.getMethod("addMix", builtMixClass).invoke(policyBuilder, builtMix)
        val builtPolicy = policyBuilderClass.getMethod("build").invoke(policyBuilder)
        val pc = Class.forName("android.media.audiopolicy.AudioPolicy")
        val result = AudioManager::class.java.getMethod("registerAudioPolicy", pc).invoke(audioManager, builtPolicy) as Int
        check(result == 0) { "registerAudioPolicy refused the mix ($result)" }
        policy = builtPolicy
        policyClass = pc
        mix = builtMix
        mixClass = builtMixClass
    }

    /** Opens the recorder of the mix (the shell's own identity first, the policy's sink second). */
    @SuppressLint("MissingPermission") // the helper runs as the shell user, which holds RECORD_AUDIO and CAPTURE_AUDIO_OUTPUT
    @Synchronized
    fun open(recorderContext: Context?): AudioRecord {
        record?.let { return it }
        arm()?.let { error("the app's mix could not be registered: $it") }
        val problems = mutableListOf<String>()
        val made = listOf<Pair<String, () -> AudioRecord?>>(
            "shell" to {
                val registration = mixClass!!.getMethod("getRegistration").invoke(mix) as String?
                check(!registration.isNullOrEmpty()) { "the mix has no address" }
                val attributes = AudioAttributes.Builder()
                val ab = AudioAttributes.Builder::class.java
                ab.getMethod(
                    "setInternalCapturePreset",
                    Int::class.javaPrimitiveType
                ).invoke(attributes, MediaRecorder.AudioSource.REMOTE_SUBMIX)
                ab.getMethod("addTag", String::class.java).invoke(attributes, "addr=$registration")
                ab.getMethod("addTag", String::class.java).invoke(attributes, "fixedVolume")
                val format = AudioFormat.Builder().setSampleRate(RATE).setChannelMask(AudioFormat.CHANNEL_IN_MONO)
                    .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
                val builder = AudioRecord.Builder()
                AudioRecord.Builder::class.java.getMethod(
                    "setAudioAttributes",
                    AudioAttributes::class.java
                ).invoke(builder, attributes.build())
                builder.setAudioFormat(format).setBufferSizeInBytes(RATE / 5 * 2)
                if (recorderContext != null && Build.VERSION.SDK_INT >= 31) builder.setContext(recorderContext)
                builder.build()
            },
            "policy" to { policyClass!!.getMethod("createAudioRecordSink", mixClass).invoke(policy, mix) as AudioRecord? }
        )
        for ((kind, make) in made) {
            val sink = runCatching { make() }.getOrElse {
                problems += "$kind: ${FarPartyCapture.reason(it)}"
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
            return sink
        }
        error(problems.joinToString("; ").ifEmpty { "Android gave no recorder for the app's mix" })
    }

    @Synchronized
    fun close() {
        val r = record ?: return
        record = null
        runCatching { r.stop() }
        runCatching { r.release() }
    }

    @Synchronized
    fun disarm() {
        close()
        val p = policy ?: return
        runCatching { AudioManager::class.java.getMethod("unregisterAudioPolicy", policyClass).invoke(audioManager, p) }
        policy = null
        mix = null
    }

    companion object {
        const val RATE = 16_000

        /** AudioMixingRule.RULE_MATCH_UID (system API). */
        const val RULE_MATCH_UID = 0x4

        /** The players the helper sees, as JSON for the Audio probe: who plays, what kind of sound, and whether apps may capture it. */
        fun players(audioManager: AudioManager, packagesFor: (Int) -> List<String>): String {
            val out = JSONArray()
            for (config in audioManager.activePlaybackConfigurations) {
                val uid = config.clientUid()
                val attributes = config.audioAttributes
                out.put(
                    JSONObject()
                        .put("uid", uid)
                        .put("packages", JSONArray(packagesFor(uid)))
                        .put("usage", attributes.usage)
                        .put("content_type", attributes.contentType)
                        .put("capture_policy", if (Build.VERSION.SDK_INT >= 29) attributes.allowedCapturePolicy else 1)
                        .put("state", config.playerState())
                        .put("piid", config.playerInterfaceId())
                )
            }
            return out.toString()
        }
    }
}

/** The real UID of a player (hidden getter; the helper holds the permission that makes it real); -1 when unavailable. */
internal fun AudioPlaybackConfiguration.clientUid(): Int = runCatching {
    javaClass.getMethod("getClientUid").invoke(this) as Int
}.getOrDefault(-1)

/** The player's state (hidden getter): 2 = started, 3 = paused, 4 = stopped; -1 when unavailable. */
internal fun AudioPlaybackConfiguration.playerState(): Int = runCatching {
    javaClass.getMethod("getPlayerState").invoke(this) as Int
}.getOrDefault(-1)
