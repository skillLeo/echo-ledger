package com.echoledger.mobile.overlay

import com.echoledger.mobile.translate.TranslateLanguages
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** The original's line above Live Translate's text (review of v1.8.1): it reads from its own language's side. */
class TranslateTextViewTest {
    @Test
    fun `an Urdu or Arabic original line is right-aligned, others start on the left, subtitles are centred`() {
        assertTrue(TranslateLanguages.rtl("ur") && TranslateLanguages.rtl("ar"))
        assertEquals(
            "right edge minus the padding",
            400f - 12f - 100f,
            TranslateTextView.originalX(400f, 100f, 12f, subtitles = false, rtl = true)
        )
        assertEquals(12f, TranslateTextView.originalX(400f, 100f, 12f, subtitles = false, rtl = false))
        assertEquals(150f, TranslateTextView.originalX(400f, 100f, 8f, subtitles = true, rtl = true))
    }
}
