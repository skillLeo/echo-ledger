package com.echoledger.mobile.core

import com.echoledger.mobile.core.SharedNotes.Stage
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.TranscriptSummary
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SharedNotesTest {
    private fun local(state: String, error: String? = null) = LocalRecording(
        id = "L1", sourceApp = "voice_note", clientId = null, startedAt = "2026-10-29T10:00:00Z", endedAt = "2026-10-29T10:00:00Z",
        durationMs = 0, mime = "audio/ogg", codec = "unknown", channels = 1, sampleRate = 0, plainSize = 10, sha256 = "",
        state = state, lastError = error
    )

    private fun item(status: String?, error: String? = null) = LibraryItem(
        id = "S1", sourceDevice = "phone", sourceApp = "voice_note", title = "Voice note", startedAt = "2026-10-29T10:00:00+00:00",
        durationMs = 4_000, status = "processing", transcript = TranscriptSummary(finalStatus = status, finalErrorCode = error),
        updatedAt = "2026-10-29T10:00:05+00:00"
    )

    @Test
    fun `a shared note goes from uploading to transcribing to ready`() {
        assertEquals(Stage.WAITING, SharedNotes.status(null, null, null).stage)
        assertEquals(Stage.UPLOADING, SharedNotes.status(local("queued"), null, null).stage)
        assertEquals("Uploading…", SharedNotes.status(local("uploading"), "S1", null).text)
        // The local copy is gone once the server holds it: the linked recording is followed.
        assertEquals(Stage.TRANSCRIBING, SharedNotes.status(null, "S1", null).stage)
        assertEquals(Stage.TRANSCRIBING, SharedNotes.status(null, "S1", item("processing")).stage)
        val ready = SharedNotes.status(null, "S1", item("completed"))
        assertEquals(Stage.READY, ready.stage)
        assertEquals("S1", ready.sessionId)
    }

    @Test
    fun `failures and silent notes end the watch and say why`() {
        assertEquals(Stage.FAILED, SharedNotes.status(local("failed", "No network"), null, null).stage)
        assertEquals("Upload failed: No network", SharedNotes.status(local("failed", "No network"), null, null).text)
        assertEquals(Stage.FAILED, SharedNotes.status(null, "S1", item("failed")).stage)
        assertEquals(Stage.NO_AUDIO, SharedNotes.status(null, "S1", item("failed", "no_audio")).stage)
        assertTrue(SharedNotes.done(Stage.READY) && SharedNotes.done(Stage.FAILED) && SharedNotes.done(Stage.NO_AUDIO))
        assertFalse(SharedNotes.done(Stage.UPLOADING) || SharedNotes.done(Stage.TRANSCRIBING) || SharedNotes.done(Stage.WAITING))
    }
}
