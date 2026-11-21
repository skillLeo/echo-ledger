package com.echoledger.mobile.ui.v2

import com.echoledger.mobile.ui.v2.components.firstSentence
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** A row's snippet is the first meaningful sentence, with no speaker labels (D12). */
class SnippetTest {
    @Test
    fun takesTheFirstMeaningfulSentenceWithoutLabels() {
        assertEquals(
            "Hello, can you understand?",
            firstSentence("Client: Hello, can you understand? Me: the time. Client: Is everything fine there?")
        )
        assertEquals(
            "We want the shop live before the holiday sales.",
            firstSentence("Sara (Acme): We want the shop live before the holiday sales. You: Okay")
        )
        assertEquals("No labels here at all, just words.", firstSentence("No labels here at all, just words."))
    }

    @Test
    fun skipsWhatSaysNothing() {
        assertEquals("Is everything fine there?", firstSentence("Me: the time. Client: Is everything fine there? Client: All good?"))
        assertNull(firstSentence("Speaker: ."))
        assertNull(firstSentence("  "))
        assertNull(firstSentence(null))
    }

    @Test
    fun urduKeepsItsWordsAndLosesItsLabels() {
        assertEquals(
            "میسج کا آپ کو میں کیا جواب دوں",
            firstSentence("Speaker: میسج کا آپ کو میں کیا جواب دوں Speaker: کچھ اور بات")
        )
        assertEquals("ہم کل بات کریں گے۔", firstSentence("مقرر: ٹھیک ہے۔ مقرر: ہم کل بات کریں گے۔ اور کچھ"))
    }
}
