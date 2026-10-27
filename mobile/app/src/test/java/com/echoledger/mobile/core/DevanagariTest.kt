package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

class DevanagariTest {
    @Test
    fun `common Urdu speech written in Hindi letters is shown in Urdu script`() {
        assertEquals("آپ کیسے ہیں", Devanagari.toUrdu("आप कैसे हैं"))
        assertEquals("میں ٹھیک ہوں", Devanagari.toUrdu("मैं ठीक हूँ"))
        assertEquals("کیا بات ہے", Devanagari.toUrdu("क्या बात है"))
        assertEquals("نہیں", Devanagari.toUrdu("नहीं"))
        assertEquals("زرور", Devanagari.toUrdu("ज़रूर"))
    }

    @Test
    fun `English words, Urdu script and digits stay, the danda becomes an Urdu full stop`() {
        assertEquals("meeting کل ہے۔", Devanagari.toUrdu("meeting कल है।"))
        assertEquals("آپ کیسے ہیں", Devanagari.toUrdu("آپ کیسے ہیں"))
        assertEquals("123", Devanagari.toUrdu("१२३"))
        assertFalse(Devanagari.has("hello"))
    }
}
