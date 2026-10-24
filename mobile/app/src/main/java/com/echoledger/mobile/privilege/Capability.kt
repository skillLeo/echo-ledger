package com.echoledger.mobile.privilege

/**
 * What this device can do (pure, unit-tested). Far-party VoIP capture needs Android 14+ because the shell user only
 * holds CAPTURE_VOICE_COMMUNICATION_OUTPUT from API 34 (docs/research/android-capture.md).
 */
data class Capabilities(
    val sdkInt: Int,
    val helperConnected: Boolean,
    val micPermission: Boolean,
    val notificationPermission: Boolean,
    val overlayPermission: Boolean
) {
    val canCaptureCalls: Boolean get() = sdkInt >= MIN_CALL_CAPTURE_SDK && helperConnected
    val callCaptureSupportedByOs: Boolean get() = sdkInt >= MIN_CALL_CAPTURE_SDK
    val canRecordMic: Boolean get() = micPermission
    val canShowBubble: Boolean get() = overlayPermission

    /** Ordered setup steps still missing (shown once in Setup; the app never nags). */
    fun missingSteps(): List<SetupStep> = buildList {
        if (!micPermission) add(SetupStep.MICROPHONE)
        if (!notificationPermission) add(SetupStep.NOTIFICATIONS)
        if (callCaptureSupportedByOs && !helperConnected) add(SetupStep.HELPER)
        if (!overlayPermission) add(SetupStep.OVERLAY)
    }

    companion object {
        const val MIN_CALL_CAPTURE_SDK = 34
    }
}

enum class SetupStep(val title: String) {
    MICROPHONE("Allow microphone"),
    NOTIFICATIONS("Allow notifications"),
    HELPER("Enable call capture (Shizuku or ADB)"),
    OVERLAY("Allow the caption bubble (optional)")
}
