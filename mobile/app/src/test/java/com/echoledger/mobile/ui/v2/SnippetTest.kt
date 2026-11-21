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

    @Test
    fun dropsNumberedSpeakerLabels() {
        assertEquals(
            "Can you send the estimate by Monday?",
            firstSentence("Speaker 1: Can you send the estimate by Monday? Speaker 2: Yes.")
        )
    }

    @Test
    fun followsTheReviewedRules() {
        assertEquals("میسج کا آپ کو میں کیا جواب دوں", firstSentence("Speaker 1: میسج کا آپ کو میں کیا جواب دوں Speaker 2: جی"))
        assertEquals("Next step: send the estimate today.", firstSentence("Yes. Next step: send the estimate today."))
        assertEquals("کل کی میٹنگ کا وقت بدل گیا ہے۔", firstSentence("\u200FSpeaker:\u200F کل کی میٹنگ کا وقت بدل گیا ہے۔"))
        assertEquals("I will send it tomorrow.", firstSentence("Me: Thanks. I will send it tomorrow."))
        assertEquals(
            "The second container leaves Karachi on the 14th.",
            firstSentence("Sara (Acme): Hi. Bilal Qureshi: The second container leaves Karachi on the 14th. Sara (Acme): Good.")
        )
    }
}
