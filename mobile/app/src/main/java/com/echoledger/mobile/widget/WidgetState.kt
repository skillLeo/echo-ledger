package com.echoledger.mobile.widget

import kotlinx.serialization.Serializable

/** Everything the home-screen widget renders (pure model, unit-tested). */
@Serializable
data class WidgetState(
    val recording: Boolean = false,
    val armed: Boolean = false,
    val paired: Boolean = false,
    val lastTitle: String? = null,
    val lastPreview: String? = null,
    val pendingDecisions: Int = 0
) {
    val statusLine: String
        get() = when {
            !paired -> "Open EchoLedger to pair"
            recording -> "Recording…"
            pendingDecisions > 0 -> "$pendingDecisions recording(s) to review"
            armed -> "Ready to record calls"
            else -> "Tap to record"
        }

    val buttonLabel: String get() = if (recording) "Stop" else "Record"
}
