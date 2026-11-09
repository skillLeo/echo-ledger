package com.echoledger.mobile.core

import com.echoledger.mobile.core.RecordingSteps.State
import com.echoledger.mobile.core.RecordingSteps.Step
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ProgressDto
import com.echoledger.mobile.data.RowViewsDto
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.data.TranscriptSummary
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** v1.6 P5.4: the recording page's Upload · Transcribe · Improve, and the one notice of "Notify me when ready". */
class RecordingStepsTest {
    private fun item(
        status: String = "completed",
        finalStatus: String? = "completed",
        progress: ProgressDto? = null,
        views: RowViewsDto? = null,
        errorCode: String? = null,
        title: String? = "Call with Omar"
    ) = LibraryItem(
        id = "S1",
        title = title,
        status = status,
        transcript = TranscriptSummary(
            finalStatus = finalStatus,
            finalTranscriptId = finalStatus?.let { "T1" },
            finalProgress = progress,
            finalErrorCode = errorCode,
            preview = "Thanks for calling",
            views = views
        ),
        updatedAt = "2026-11-09T08:00:00+00:00"
    )

    @Test
    fun `a recording still coming in is uploading`() {
        val steps = RecordingSteps.of(item(status = "recording", finalStatus = null))!!
        assertEquals(State.ACTIVE, steps.states[Step.UPLOAD])
        assertEquals(State.WAITING, steps.states[Step.TRANSCRIBE])
        assertEquals("Uploading", steps.label(Step.UPLOAD))
        assertTrue(steps.working)
    }

    @Test
    fun `transcribing says the part and the time left once, with a percent`() {
        val steps = RecordingSteps.of(item(status = "processing", finalStatus = "processing", progress = ProgressDto(1, 4, 50)))!!
        assertEquals(mapOf(Step.UPLOAD to State.DONE, Step.TRANSCRIBE to State.ACTIVE, Step.IMPROVE to State.WAITING), steps.states)
        assertEquals("Transcribing · part 2 of 4 · about 50 s left", steps.line)
        assertEquals(25, steps.percent)
        assertEquals("Uploaded", steps.label(Step.UPLOAD))
        assertEquals("Improve", steps.label(Step.IMPROVE))
        assertFalse(steps.onlyImproving)
    }

    @Test
    fun `no transcript yet on a finished upload is transcribing`() {
        val steps = RecordingSteps.of(item(status = "processing", finalStatus = null))!!
        assertEquals(State.ACTIVE, steps.states[Step.TRANSCRIBE])
        assertNull(steps.percent)
    }

    @Test
    fun `the loaded transcript is fresher than the Library row`() {
        val loaded = TranscriptDto(id = "T1", kind = "final", provider = "groq", status = "processing", progress = ProgressDto(2, 3))
        val steps = RecordingSteps.of(item(finalStatus = "pending"), loaded)!!
        assertEquals("Transcribing · part 3 of 3", steps.line)
    }

    @Test
    fun `while the views are made only Improve runs, and the transcript can be read`() {
        val views = RowViewsDto(status = "making", default = "roman", progress = mapOf("roman" to ProgressDto(3, 5)))
        val steps = RecordingSteps.of(item(views = views))!!
        assertEquals(State.DONE, steps.states[Step.TRANSCRIBE])
        assertTrue(steps.onlyImproving)
        assertEquals("Improving Roman Urdu… 60%", steps.line)
        assertEquals(60, steps.percent)
    }

    @Test
    fun `a ready recording has no steps`() {
        assertNull(RecordingSteps.of(item()))
        assertNull(RecordingSteps.of(item(views = RowViewsDto(status = "ready"))))
    }

    @Test
    fun `a failed transcription stops at Transcribe`() {
        val steps = RecordingSteps.of(item(finalStatus = "failed"))!!
        assertEquals(State.FAILED, steps.states[Step.TRANSCRIBE])
        assertFalse(steps.working)
    }

    @Test
    fun `the notice waits for the transcript, not for the views`() {
        assertNull(RecordingSteps.readyNotice(item(status = "recording", finalStatus = null)))
        assertNull(RecordingSteps.readyNotice(item(status = "processing", finalStatus = "processing")))
        val improving = item(views = RowViewsDto(status = "making", default = "roman"))
        assertEquals("Transcript ready" to "Call with Omar", RecordingSteps.readyNotice(improving))
        assertEquals("Transcript ready" to "Thanks for calling", RecordingSteps.readyNotice(item(title = null)))
    }

    @Test
    fun `the notice says when it failed or nothing was heard`() {
        assertEquals("The transcript could not be made", RecordingSteps.readyNotice(item(finalStatus = "failed"))!!.first)
        assertEquals(
            "No audio in the recording",
            RecordingSteps.readyNotice(item(finalStatus = "failed", errorCode = "no_audio"))!!.first
        )
    }
}
