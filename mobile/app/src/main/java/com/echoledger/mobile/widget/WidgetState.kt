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
    val ready: String? = null,
    /** Settings › Appearance's app language (`system`, `en`, `ur`) and text size, as the v2 screens use them. */
    val language: String = "system",
    val textSize: String = "system"
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

/** The widget's words in [res]'s language (the app language): the same lines as [WidgetState]'s English ones. */
class WidgetWords(private val res: android.content.res.Resources, private val state: WidgetState) {
    /** The words are Urdu: the widget lines its text up at the end. */
    val urdu: Boolean get() = res.configuration.locales[0]?.language == "ur"

    val statusLine: String
        get() = with(state) {
            when {
                !paired -> res.getString(com.echoledger.mobile.R.string.w2_pair)
                recording -> res.getString(com.echoledger.mobile.R.string.w2_recording)
                pendingDecisions > 0 -> review
                notReady -> res.getString(com.echoledger.mobile.R.string.w2_not_ready)
                locked -> res.getString(com.echoledger.mobile.R.string.w2_locked)
                armed -> res.getString(com.echoledger.mobile.R.string.w2_armed)
                else -> res.getString(com.echoledger.mobile.R.string.w2_tap)
            }
        }

    val reviewLine: String? get() = review.takeIf { state.reviewLine != null }

    val buttonLabel: String get() = res.getString(
        if (state.recording) com.echoledger.mobile.R.string.w2_stop else com.echoledger.mobile.R.string.w2_record
    )

    val readyLabel: String?
        get() = when (state.readyLabel?.let { state.ready }) {
            "READY" -> res.getString(com.echoledger.mobile.R.string.w2_calls_ready)
            "NOT_READY" -> res.getString(com.echoledger.mobile.R.string.w2_calls_not_ready)
            "OFF" -> res.getString(com.echoledger.mobile.R.string.w2_calls_off)
            else -> null
        }

    private val review: String
        get() = res.getQuantityString(com.echoledger.mobile.R.plurals.w2_to_review, state.pendingDecisions, state.pendingDecisions)
}
