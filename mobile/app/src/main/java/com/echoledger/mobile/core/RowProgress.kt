package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ProgressDto
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.update

/**
 * Where a recording is (since v1.5, Phase 1C): Uploading %, then Transcribing % with the time left, then "Converting to
 * Roman Urdu… 60%" while its default view is made (U19: from the moment the transcript is finished, so a view not
 * started yet counts too), or "Couldn't convert to Roman Urdu · Try again" when making it failed. The same words in the
 * Library row, on a shared note's page and in Activity. Pure; unit-tested.
 */
object RowProgress {
    enum class Step { UPLOADING, TRANSCRIBING, IMPROVING, VIEW_FAILED }

    /** [percent] 0-100, or null when the server has no measure yet. [text]: "Transcribing · part 2 of 3 · about 20 s left". */
    data class Stage(val step: Step, val percent: Int?, val text: String)

    /** Uploading: chunks sent of all chunks ([sent], [total]) of a recording still on this phone. */
    fun uploading(sent: Int, total: Int): Stage {
        val percent = if (total > 0) (sent * 100 / total).coerceIn(0, 100) else null
        return Stage(Step.UPLOADING, percent, percent?.let { "Uploading $it%" } ?: "Uploading")
    }

    /** The stage of a recording on the server, or null when it is done (or failed: the row says that itself). */
    fun of(item: LibraryItem): Stage? {
        val transcript = item.transcript ?: return null
        val status = transcript.finalStatus
        if (status == "failed" || item.status == "failed") return null
        if (status != "completed") return transcribing(transcript.finalProgress)
        val views = transcript.views ?: return null
        val name = viewName(views.default)
        return when (converting(views)) {
            true -> viewPercent(views).let { percent -> Stage(Step.IMPROVING, percent, convertingWords(views.default, percent)) }
            false -> Stage(Step.VIEW_FAILED, null, convertFailedWords(views.default, tryAgain = true))
            null -> null
        }
    }

    /**
     * Whether the default view of a finished transcript is on its way: true while it is `making` (since 2.0.0-n the
     * server says so whenever one is on its way, before its job starts too), false when making it failed, null otherwise:
     * `none` means nothing will happen (an older recording, a plan or key without views, or it timed out).
     */
    fun converting(views: com.echoledger.mobile.data.RowViewsDto): Boolean? = when {
        views.default == "original" || views.default.isBlank() -> null
        views.status == "making" -> true
        views.status == "failed" -> false
        else -> null
    }

    /** "Converting to Roman Urdu… 60%", or "Converting to Roman Urdu…" before the lines are counted. */
    fun convertingWords(view: String, percent: Int?): String = "Converting to ${viewName(view)}" + (percent?.let { "… $it%" } ?: "…")

    /** "Couldn't convert to Roman Urdu · Try again" on a row; on a page, whose Try again button follows, without it. */
    fun convertFailedWords(view: String, tryAgain: Boolean): String =
        "Couldn't convert to ${viewName(view)}" + if (tryAgain) " · Try again" else "."

    /** The default view's lines done of lines, 0-100, or null before the server has a measure. */
    fun viewPercent(views: com.echoledger.mobile.data.RowViewsDto): Int? {
        val progress = views.progress[views.default] ?: views.progress.values.firstOrNull()
        return progress?.takeIf { it.total > 0 }?.let { (it.done * 100 / it.total).coerceIn(0, 100) }
    }

    fun transcribing(progress: ProgressDto?): Stage {
        val total = progress?.total ?: 0
        val done = progress?.done ?: 0
        val percent = if (total > 1) (done * 100 / total).coerceIn(0, 100) else null
        val parts = if (total > 1) " · part ${(done + 1).coerceAtMost(total)} of $total" else ""
        val left = progress?.etaSeconds?.let { " · about ${timeLeft(it)} left" }.orEmpty()
        return Stage(Step.TRANSCRIBING, percent, "Transcribing$parts$left")
    }

    /** "20 s", "1 min", "3 min". */
    fun timeLeft(seconds: Long): String = when {
        seconds < 60 -> "${(seconds.coerceAtLeast(5) + 4) / 5 * 5} s"
        else -> "${(seconds + 30) / 60} min"
    }

    fun viewName(view: String): String = when (view) {
        "roman" -> "Roman Urdu"
        "urdu" -> "Urdu"
        "english" -> "English"
        else -> view
    }

    /** Chunks sent of all chunks per recording uploading in this process (the upload worker writes it as it goes). */
    private val sending = MutableStateFlow<Map<String, Pair<Int, Int>>>(emptyMap())
    val uploads: StateFlow<Map<String, Pair<Int, Int>>> = sending

    fun sent(recordingId: String, sent: Int, total: Int) = sending.update { it + (recordingId to (sent to total)) }

    fun doneSending(recordingId: String) = sending.update { it - recordingId }
}
