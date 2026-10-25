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
    val pendingDecisions: Int = 0,
    /** App lock is on: the widget shows no recording titles or transcript text. */
    val locked: Boolean = false
) {
    val statusLine: String
        get() = when {
            !paired -> "Open EchoLedger to pair"
            recording -> "Recording…"
            pendingDecisions > 0 -> reviewText
            locked -> "Locked"
            armed -> "Ready to record calls"
            else -> "Tap to record"
        }

    /** The recordings waiting for Keep / Discard while a recording runs (the status line shows them otherwise). */
    val reviewLine: String? get() = reviewText.takeIf { paired && recording && pendingDecisions > 0 }

    /** Whether the last recording's title and preview may be shown. */
    val showsContent: Boolean get() = paired && !locked

    val buttonLabel: String get() = if (recording) "Stop" else "Record"

    private val reviewText: String get() = "$pendingDecisions to review"
}
