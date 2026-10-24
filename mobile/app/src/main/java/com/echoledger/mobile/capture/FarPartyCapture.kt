package com.echoledger.mobile.capture

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord

/**
 * Far-party (remote caller) audio via a dynamic audio-policy mix — runs in the shell-UID helper only.
 * Players mix matching USAGE_VOICE_COMMUNICATION with ROUTE_FLAG_LOOP_BACK_RENDER (the user keeps hearing the call)
 * → registerAudioPolicy → createAudioRecordSink. Hidden APIs, reached by reflection after HiddenApiBypass.
 * Requires Android 14+ (shell holds CAPTURE_VOICE_COMMUNICATION_OUTPUT). Source: docs/research/android-capture.md §2.
 * VERIFY ON DEVICE: OEM audio HALs may route VoIP outside dynamic mixes.
 */
class FarPartyCapture(private val context: Context, private val audioManager: AudioManager, private val sampleRate: Int) {
    private var policy: Any? = null
    private var policyClass: Class<*>? = null
    var record: AudioRecord? = null
        private set

    fun start(): AudioRecord {
        val format = AudioFormat.Builder()
            .setSampleRate(sampleRate)
            .setChannelMask(AudioFormat.CHANNEL_IN_MONO)
            .setEncoding(AudioFormat.ENCODING_PCM_16BIT)
            .build()
        val usage = AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_VOICE_COMMUNICATION).build()

        val ruleBuilderClass = Class.forName("android.media.audiopolicy.AudioMixingRule\$Builder")
        val ruleBuilder = ruleBuilderClass.getConstructor().newInstance()
        val addMixRule = runCatching { ruleBuilderClass.getMethod("addMixRule", Int::class.javaPrimitiveType, Any::class.java) }.getOrNull()
        if (addMixRule != null) {
            addMixRule.invoke(ruleBuilder, RULE_MATCH_ATTRIBUTE_USAGE, usage)
        } else {
            ruleBuilderClass.getMethod(
                "addRule",
                AudioAttributes::class.java,
                Int::class.javaPrimitiveType
            ).invoke(ruleBuilder, usage, RULE_MATCH_ATTRIBUTE_USAGE)
        }
        // Allow capturing voice-communication playback (hidden; names differ across releases — try each).
        for (name in listOf("voiceCommunicationCaptureAllowed", "allowPrivilegedPlaybackCapture", "allowPrivilegedMediaPlaybackCapture")) {
            runCatching { ruleBuilderClass.getMethod(name, Boolean::class.javaPrimitiveType).invoke(ruleBuilder, true) }
        }
        val rule = ruleBuilderClass.getMethod("build").invoke(ruleBuilder)

        val ruleClass = Class.forName("android.media.audiopolicy.AudioMixingRule")
        val mixBuilderClass = Class.forName("android.media.audiopolicy.AudioMix\$Builder")
        val mixBuilder = mixBuilderClass.getConstructor(ruleClass).newInstance(rule)
        mixBuilderClass.getMethod("setFormat", AudioFormat::class.java).invoke(mixBuilder, format)
        mixBuilderClass.getMethod("setRouteFlags", Int::class.javaPrimitiveType).invoke(mixBuilder, ROUTE_FLAG_LOOP_BACK_RENDER)
        val mix = mixBuilderClass.getMethod("build").invoke(mixBuilder)
        val mixClass = Class.forName("android.media.audiopolicy.AudioMix")

        val policyBuilderClass = Class.forName("android.media.audiopolicy.AudioPolicy\$Builder")
        val policyBuilder = policyBuilderClass.getConstructor(Context::class.java).newInstance(context)
        policyBuilderClass.getMethod("addMix", mixClass).invoke(policyBuilder, mix)
        val builtPolicy = policyBuilderClass.getMethod("build").invoke(policyBuilder)
        val pc = Class.forName("android.media.audiopolicy.AudioPolicy")

        val result = AudioManager::class.java.getMethod("registerAudioPolicy", pc).invoke(audioManager, builtPolicy) as Int
        check(result == 0) { "registerAudioPolicy failed ($result)" }
        policy = builtPolicy
        policyClass = pc

        val sink = pc.getMethod("createAudioRecordSink", mixClass).invoke(builtPolicy, mix) as AudioRecord
        record = sink
        return sink
    }

    fun stop() {
        runCatching { record?.stop() }
        runCatching { record?.release() }
        record = null
        val p = policy ?: return
        runCatching { AudioManager::class.java.getMethod("unregisterAudioPolicy", policyClass).invoke(audioManager, p) }
        policy = null
    }

    companion object {
        const val RULE_MATCH_ATTRIBUTE_USAGE = 0x1
        const val ROUTE_FLAG_LOOP_BACK_RENDER = 0x3
    }
}
