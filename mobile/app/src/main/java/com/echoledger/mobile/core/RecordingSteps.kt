package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.TranscriptDto

/**
 * The recording page's three steps (since v1.6, P5.4), the same as the desktop's: Upload, Transcribe, Improve, each
 * waiting, running, done or failed, and one line under them that says what the running step is doing ("Transcribing ·
 * part 2 of 3 · about 20 s left", "Improving Roman Urdu… 60%"). Null once the recording is ready. Pure; unit-tested.
 */
object RecordingSteps {
    enum class State { WAITING, ACTIVE, DONE, FAILED }

    enum class Step(val waiting: String, val active: String, val done: String) {
        UPLOAD("Upload", "Uploading", "Uploaded"),
        TRANSCRIBE("Transcribe", "Transcribing", "Transcribed"),
        IMPROVE("Improve", "Improving", "Improved")
    }

    data class Steps(val states: Map<Step, State>, val line: String, val percent: Int?) {
        /** Something is still moving, so the page follows it and "Notify me when ready" makes sense. */
        val working: Boolean get() = states.values.any { it == State.ACTIVE }

        /** Only the views are being made: the transcript itself can be read already. */
        val onlyImproving: Boolean get() = states[Step.IMPROVE] == State.ACTIVE

        fun label(step: Step): String = when (states.getValue(step)) {
            State.ACTIVE -> step.active
            State.DONE -> step.done
            State.WAITING, State.FAILED -> step.waiting
        }
    }

    private val IN_PROGRESS = setOf("pending", "submitted", "processing", "awaiting_device")

    /**
     * [transcript]: the one the page has loaded (fresher than the Library row while it is transcribing), or null.
     */
    fun of(item: LibraryItem, transcript: TranscriptDto? = null): Steps? {
        val summary = item.transcript
        val status = transcript?.takeIf { it.kind == "final" || summary?.finalTranscriptId == null }?.status ?: summary?.finalStatus
        if (status == "failed" || item.status == "failed") {
            return Steps(
                mapOf(Step.UPLOAD to State.DONE, Step.TRANSCRIBE to State.FAILED, Step.IMPROVE to State.WAITING),
                "Transcription failed",
                null
            )
        }
        if (item.status == "recording") {
            return Steps(
                mapOf(Step.UPLOAD to State.ACTIVE, Step.TRANSCRIBE to State.WAITING, Step.IMPROVE to State.WAITING),
                "Uploading",
                null
            )
        }
        if (status == null || status in IN_PROGRESS) {
            if (status == null && item.status == "completed") return null
            val stage = RowProgress.transcribing(transcript?.progress ?: summary?.finalProgress)
            val waiting = transcript?.let { TranscriptText.waitingForEngine(it) }
            return Steps(
                mapOf(Step.UPLOAD to State.DONE, Step.TRANSCRIBE to State.ACTIVE, Step.IMPROVE to State.WAITING),
                waiting ?: stage.text,
                stage.percent
            )
        }
        val improving = RowProgress.of(item)?.takeIf { it.step == RowProgress.Step.IMPROVING } ?: return null
        return Steps(
            mapOf(Step.UPLOAD to State.DONE, Step.TRANSCRIBE to State.DONE, Step.IMPROVE to State.ACTIVE),
            improving.text,
            improving.percent
        )
    }

    /**
     * The one notice "Notify me when ready" gives (title, text), or null while the recording is still uploading or
     * being transcribed. The views are not waited for: the transcript can be read before them.
     */
    fun readyNotice(item: LibraryItem): Pair<String, String>? {
        val steps = of(item)
        if (steps != null && (steps.states[Step.UPLOAD] == State.ACTIVE || steps.states[Step.TRANSCRIBE] == State.ACTIVE)) return null
        val name = item.title?.takeIf { it.isNotBlank() }
        return when (ItemStatus.of(item)) {
            ItemStatus.NO_AUDIO -> "No audio in the recording" to (name ?: "Nothing was heard in it.")
            ItemStatus.FAILED -> "The transcript could not be made" to (name ?: "Open it to try again.")
            else -> "Transcript ready" to (name ?: item.transcript?.preview?.takeIf { it.isNotBlank() } ?: "Tap to read it.")
        }
    }
}
