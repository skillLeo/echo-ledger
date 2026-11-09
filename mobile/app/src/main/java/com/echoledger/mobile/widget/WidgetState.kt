package com.echoledger.mobile.widget

import kotlinx.serialization.Serializable

/** One recording on the 4 x 2 and 4 x 4 widget (since v1.6): a tap opens it. [line]: its progress or its time. */
@Serializable
data class WidgetRow(val id: String, val title: String, val line: String)

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
    val locked: Boolean = false,
    /** The app's "Wallpaper colours" setting: the widget uses the wallpaper's colours too (Android 12+). */
    val dynamicColour: Boolean = false,
    /** The latest recordings, newest first, up to six (since v1.6); none while App lock is on. */
    val recent: List<WidgetRow> = emptyList(),
    /** The Ready check's chip (since v1.6): `READY`, `NOT_READY`, `OFF`, or null when this phone records no calls. */
    val ready: String? = null
) {
    val statusLine: String
        get() = when {
            !paired -> "Open EchoLedger to pair"
            recording -> "Recording…"
            pendingDecisions > 0 -> reviewText
            notReady -> "Not ready: tap to fix"
            locked -> "Locked"
            armed -> "Ready to record calls"
            else -> "Tap to record"
        }

    /** The recordings waiting for Keep / Discard while a recording runs (the status line shows them otherwise). */
    val reviewLine: String? get() = reviewText.takeIf { paired && recording && pendingDecisions > 0 }

    /** Whether the last recording's title and preview may be shown. */
    val showsContent: Boolean get() = paired && !locked

    val buttonLabel: String get() = if (recording) "Stop" else "Record"

    /** Calls would not be recorded: the status opens the Ready check (since v1.6). */
    val notReady: Boolean get() = paired && ready == "NOT_READY"

    /** What the Ready dot says to TalkBack, or null when there is no dot. */
    val readyLabel: String? get() = when (ready.takeIf { paired }) {
        "READY" -> "Calls: Ready"
        "NOT_READY" -> "Calls: Not ready"
        "OFF" -> "Calls: Off"
        else -> null
    }

    private val reviewText: String get() = "$pendingDecisions to review"
}
