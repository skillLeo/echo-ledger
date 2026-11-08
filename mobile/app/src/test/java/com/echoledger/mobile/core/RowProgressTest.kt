package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ProgressDto
import com.echoledger.mobile.data.RowViewsDto
import com.echoledger.mobile.data.TranscriptSummary
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** Phase 1C C/I3: Uploading %, Transcribing with part and time left, Improving %, the same words everywhere. */
class RowProgressTest {
    private fun item(status: String?, progress: ProgressDto? = null, views: RowViewsDto? = null) = LibraryItem(
        id = "s1",
        status = if (status == "completed") "completed" else "processing",
        transcript = TranscriptSummary(finalStatus = status, finalProgress = progress, views = views),
        updatedAt = "2026-11-09T10:00:00+00:00"
    )

    @Test
    fun `uploading says its percent`() {
        assertEquals(RowProgress.Stage(RowProgress.Step.UPLOADING, 40, "Uploading 40%"), RowProgress.uploading(2, 5))
        assertEquals("Uploading", RowProgress.uploading(0, 0).text)
    }

    @Test
    fun `transcribing says the part and the time left when the server knows them`() {
        assertEquals("Transcribing · part 2 of 3 · about 20 s left", RowProgress.of(item("processing", ProgressDto(1, 3, 18)))!!.text)
        assertEquals(33, RowProgress.of(item("processing", ProgressDto(1, 3, 18)))!!.percent)
        assertEquals("Transcribing · about 2 min left", RowProgress.of(item("processing", ProgressDto(0, 1, 100)))!!.text)
        assertEquals("Transcribing", RowProgress.of(item("pending"))!!.text)
    }

    @Test
    fun `improving shows the default view's lines done, and nothing once the views are ready`() {
        val making = RowViewsDto("making", "roman", mapOf("roman" to ProgressDto(12, 20), "english" to ProgressDto(12, 20)))
        assertEquals(
            RowProgress.Stage(RowProgress.Step.IMPROVING, 60, "Improving Roman Urdu… 60%"),
            RowProgress.of(item("completed", views = making))
        )
        assertEquals("Improving English…", RowProgress.of(item("completed", views = RowViewsDto("making", "english")))!!.text)
        assertNull(RowProgress.of(item("completed", views = RowViewsDto("ready", "roman"))))
        assertNull(RowProgress.of(item("completed")))
        assertNull(RowProgress.of(item("failed")))
    }

    @Test
    fun `a shared note says the same words`() {
        val local = com.echoledger.mobile.data.LocalRecording(
            id = "L1", sourceApp = "voice_note", startedAt = "2026-10-29T10:00:00Z", endedAt = "2026-10-29T10:00:00Z",
            durationMs = 0, mime = "audio/ogg", codec = "unknown", channels = 1, sampleRate = 0, plainSize = 10, sha256 = "",
            state = "uploading"
        )
        assertEquals("Uploading 60%", SharedNotes.status(local, null, null, upload = 3 to 5).text)
        val ready = item("completed", views = RowViewsDto("making", "roman", mapOf("roman" to ProgressDto(1, 4))))
        assertEquals("Transcript ready · Improving Roman Urdu… 25%", SharedNotes.status(null, "s1", ready).text)
    }
}
