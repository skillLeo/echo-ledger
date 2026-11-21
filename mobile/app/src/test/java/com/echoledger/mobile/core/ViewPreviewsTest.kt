package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.RowViewsDto
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.data.TranscriptViewsDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** U19: a Library row's snippet in the default view (Roman Urdu) once it is ready, the original words until then. */
class ViewPreviewsTest {
    private fun item(status: String = "ready", default: String = "roman", final: String? = "completed") = LibraryItem(
        id = "s1",
        updatedAt = "2026-11-21T10:00:00Z",
        transcript = TranscriptSummary(
            finalStatus = final,
            finalTranscriptId = "t1",
            preview = "جی بالکل",
            views = RowViewsDto(status, default)
        )
    )

    @Test
    fun `only a finished transcript whose default view is ready is read`() {
        assertEquals("t1:roman", ViewPreviews.key(item()))
        assertNull(ViewPreviews.key(item(status = "making")))
        assertNull(ViewPreviews.key(item(status = "none")))
        assertNull(ViewPreviews.key(item(default = "original")))
        assertNull(ViewPreviews.key(item(final = "processing")))
    }

    @Test
    fun `the first line with words is the snippet, cut to the preview's length`() {
        val views = TranscriptViewsDto(status = "ready", roman = listOf(null, " ", "  Ji bilkul. ", "Shukriya."))
        assertEquals("Ji bilkul.", ViewPreviews.firstLine(views, "roman"))
        assertNull(ViewPreviews.firstLine(views, "english"))
        assertNull(ViewPreviews.firstLine(views.copy(status = "making"), "roman"))
        val long = ViewPreviews.firstLine(TranscriptViewsDto(status = "ready", roman = listOf("a".repeat(300))), "roman")!!
        assertEquals(160, long.length)
        assertEquals('…', long.last())
    }

    @Test
    fun `rows take the snippet in their view and keep the original words otherwise`() {
        val ready = item()
        val making = item(status = "making").copy(id = "s2")
        val rows = ViewPreviews.apply(listOf(ready, making), mapOf("t1:roman" to "Ji bilkul."))
        assertEquals("Ji bilkul.", rows[0].transcript?.preview)
        assertEquals("جی بالکل", rows[1].transcript?.preview)
        assertEquals(listOf(ready), ViewPreviews.apply(listOf(ready), emptyMap()))
    }
}
