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
            "Hello, can you hear me now?",
            firstSentence("Client: Hello, can you hear me now? Me: the line. Client: Is the shipment on time?")
        )
        assertEquals(
            "We want the shop live before the holiday sales.",
            firstSentence("Sara (Acme): We want the shop live before the holiday sales. You: Okay")
        )
        assertEquals("No labels here at all, just words.", firstSentence("No labels here at all, just words."))
    }

    @Test
    fun skipsWhatSaysNothing() {
        assertEquals("Is the shipment on time?", firstSentence("Me: the line. Client: Is the shipment on time? Client: All good?"))
        assertNull(firstSentence("Speaker: ."))
        assertNull(firstSentence("  "))
        assertNull(firstSentence(null))
    }

    @Test
    fun urduKeepsItsWordsAndLosesItsLabels() {
        assertEquals(
            "آرڈر کب تک پہنچ جائے گا یہ بتا دیں",
            firstSentence("Speaker: آرڈر کب تک پہنچ جائے گا یہ بتا دیں Speaker: کچھ اور بات")
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
        assertEquals("آرڈر کب تک پہنچ جائے گا یہ بتا دیں", firstSentence("Speaker 1: آرڈر کب تک پہنچ جائے گا یہ بتا دیں Speaker 2: جی"))
        assertEquals("Next step: send the estimate today.", firstSentence("Yes. Next step: send the estimate today."))
        assertEquals("کل کی میٹنگ کا وقت بدل گیا ہے۔", firstSentence("\u200FSpeaker:\u200F کل کی میٹنگ کا وقت بدل گیا ہے۔"))
        assertEquals("I will send it tomorrow.", firstSentence("Me: Thanks. I will send it tomorrow."))
        // In a labelled transcript a later phrase before a colon is a speaker only when it looks like a name.
        assertEquals("The main issue is this: the invoice is late.", firstSentence("Client: Okay. The main issue is this: the invoice is late."))
        assertEquals("I will send it to you: tomorrow.", firstSentence("Me: Thanks. I will send it to you: tomorrow."))
        assertEquals(
            "The second container leaves Karachi on the 14th.",
            firstSentence("Sara (Acme): Hi. Bilal Qureshi: The second container leaves Karachi on the 14th. Sara (Acme): Good.")
        )
    }

    @Test
    fun aNoBreakSpaceIsASpace() {
        // A no-break space (U+00A0) before and after a label, as pasted transcripts have: still a label, still cut.
        assertEquals(
            "The second container leaves Karachi on the 14th.",
            firstSentence("Client: Hi.\u00A0Bilal Qureshi:\u00A0The second container leaves Karachi on the 14th.")
        )
        assertEquals("Hello, can you hear me now?", firstSentence("Client:\u00A0Hello, can you hear me now?"))
    }
}
