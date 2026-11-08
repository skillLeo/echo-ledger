package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ProgressDto
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.update

/**
 * Where a recording is (since v1.5, Phase 1C): Uploading %, then Transcribing % with the time left, then Improving % while
 * its views are made. The same words in the Library row, on a shared note's page and in Activity. Pure; unit-tested.
 */
object RowProgress {
    enum class Step { UPLOADING, TRANSCRIBING, IMPROVING }

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
        if (views.status != "making") return null
        val progress = views.progress[views.default] ?: views.progress.values.firstOrNull()
        val percent = progress?.takeIf { it.total > 0 }?.let { (it.done * 100 / it.total).coerceIn(0, 100) }
        val name = viewName(views.default)
        return Stage(Step.IMPROVING, percent, "Improving $name" + (percent?.let { "… $it%" } ?: "…"))
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
