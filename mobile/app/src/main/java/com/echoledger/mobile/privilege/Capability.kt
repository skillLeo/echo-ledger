package com.echoledger.mobile.privilege

/** What an app call records on this phone (since v1.5). During a call Android gives ordinary apps no sound, so all of it goes through the helper. */
enum class AppCallCapture { BOTH_SIDES, NEEDS_HELPER, VOICE_ONLY, VOICE_ONLY_NEEDS_HELPER, NONE, NOT_IN_THIS_BUILD }

/**
 * What this device can do (pure, unit-tested). Far-party VoIP capture needs Android 14+ because the shell user only
 * holds CAPTURE_VOICE_COMMUNICATION_OUTPUT from API 34; the microphone beside a call needs Android 11+, where the shell
 * user first holds RECORD_AUDIO and CAPTURE_AUDIO_OUTPUT (docs/research/android-capture.md).
 */
data class Capabilities(
    val sdkInt: Int,
    val helperConnected: Boolean,
    val micPermission: Boolean,
    val notificationPermission: Boolean,
    val overlayPermission: Boolean,
    /** False in the Google Play build (since v1.5): it has no capture helper, so no call auto-record. */
    val helperInBuild: Boolean = true
) {
    val appCalls: AppCallCapture
        get() = when {
            !helperInBuild -> AppCallCapture.NOT_IN_THIS_BUILD
            sdkInt >= MIN_CALL_CAPTURE_SDK -> if (helperConnected) AppCallCapture.BOTH_SIDES else AppCallCapture.NEEDS_HELPER
            sdkInt >= MIN_VOICE_ONLY_SDK -> if (helperConnected) AppCallCapture.VOICE_ONLY else AppCallCapture.VOICE_ONLY_NEEDS_HELPER
            else -> AppCallCapture.NONE
        }
    val canCaptureCalls: Boolean get() = sdkInt >= MIN_VOICE_ONLY_SDK && helperInBuild && helperConnected
    val callCaptureSupportedByOs: Boolean get() = sdkInt >= MIN_VOICE_ONLY_SDK
    val canRecordMic: Boolean get() = micPermission
    val canShowBubble: Boolean get() = overlayPermission

    /** Ordered setup steps still missing (shown once in Setup; the app never nags). */
    fun missingSteps(): List<SetupStep> = buildList {
        if (!micPermission) add(SetupStep.MICROPHONE)
        if (!notificationPermission) add(SetupStep.NOTIFICATIONS)
        if (callCaptureSupportedByOs && helperInBuild && !helperConnected) add(SetupStep.HELPER)
        if (!overlayPermission) add(SetupStep.OVERLAY)
    }

    companion object {
        /** Both sides of an app call. */
        const val MIN_CALL_CAPTURE_SDK = 34

        /** Your voice beside a call, through the helper. */
        const val MIN_VOICE_ONLY_SDK = 30

        /** Why the Google Play build records no calls (mobile/DISTRIBUTION.md). */
        const val NOT_IN_THIS_BUILD =
            "Recording calls is in the website version of EchoLedger: Google Play does not allow the capture helper it needs."
    }
}

enum class SetupStep(val title: String) {
    MICROPHONE("Allow microphone"),
    NOTIFICATIONS("Allow notifications"),
    HELPER("Enable call capture (Shizuku or ADB)"),
    OVERLAY("Allow the caption bubble (optional)")
}
