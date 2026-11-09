package com.echoledger.mobile.widget

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ProgressDto
import com.echoledger.mobile.data.TranscriptSummary
import java.time.LocalDate
import org.junit.Assert.assertEquals
import org.junit.Test

/** v1.6 (Phase 1C I4): the widget's recent recordings, with Hide titles. */
class WidgetRowsTest {
    private val today = LocalDate.of(2026, 11, 9)

    private fun item(
        id: String,
        title: String?,
        started: String,
        final: String? = "completed",
        app: String = "whatsapp",
        progress: ProgressDto? = null
    ) = LibraryItem(
        id = id,
        title = title,
        sourceApp = app,
        startedAt = started,
        status = when (final) {
            "completed" -> "completed"
            "failed" -> "failed"
            else -> "processing"
        },
        transcript = TranscriptSummary(finalStatus = final, finalProgress = progress),
        updatedAt = started
    )

    private val items = listOf(
        item("a", "Older", "2026-11-01T09:00:00Z"),
        item("b", "Call with Omar", "2026-11-09T10:00:00Z", final = "processing", progress = ProgressDto(1, 3)),
        item("c", null, "2026-11-08T09:00:00Z", app = "voice_note"),
        item("d", "Broken", "2026-11-07T09:00:00Z", final = "failed")
    )

    @Test
    fun `newest first, with the progress, a problem, or the time`() {
        val rows = WidgetStateStore.recentRows(items, hideTitles = false, today)
        assertEquals(listOf("b", "c", "d", "a"), rows.map { it.id })
        assertEquals("Call with Omar", rows[0].title)
        assertEquals("Transcribing · part 2 of 3", rows[0].line)
        assertEquals("Voice note", rows[1].title)
        assertEquals("Transcription failed", rows[2].line)
    }

    @Test
    fun `Hide titles names each recording by its app only`() {
        val rows = WidgetStateStore.recentRows(items, hideTitles = true, today)
        assertEquals(listOf("WhatsApp call", "Voice note", "WhatsApp call", "WhatsApp call"), rows.map { it.title })
    }

    @Test
    fun `at most six`() {
        val many = (1..9).map { item("i$it", "Call $it", "2026-11-0${it}T09:00:00Z") }
        assertEquals(6, WidgetStateStore.recentRows(many, hideTitles = false, today).size)
    }
}
