package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class LanguagesTest {
    @Test
    fun `old values read as today, Hindi and the mixed choices are Urdu, unknown codes are Auto`() {
        assertEquals("ur", Languages.canonical("ur+en"))
        assertEquals("ur", Languages.canonical("en+ur"))
        assertEquals("ur", Languages.canonical(" HI + en "))
        assertEquals("ur", Languages.canonical("hi"))
        assertEquals("ar", Languages.canonical("en+ar"))
        assertEquals("ar", Languages.canonical("ar-EG"))
        assertEquals("fr", Languages.canonical("FR"))
        assertNull(Languages.canonical("ja"))
        assertNull(Languages.canonical(""))
        assertNull(Languages.canonical(null))
    }

    @Test
    fun `Auto, English, Urdu and Arabic come first, then the More languages`() {
        assertEquals(listOf("Auto", "EN", "UR", "AR"), Languages.MAIN.map { it.chip })
        assertEquals(listOf("fr", "de", "es", "it", "pt", "nl", "tr", "ru"), Languages.MORE.map { it.code })
        assertEquals("Arabic", Languages.label("ar"))
        assertEquals("Auto", Languages.label(null))
        assertEquals("UR", Languages.chip("en+ur"))
        assertEquals("Auto", Languages.chip("xx"))
    }

    @Test
    fun `the chip steps through the main choices and back to Auto`() {
        val seen = mutableListOf<String?>(null)
        repeat(4) { seen += Languages.next(seen.last()) }
        assertEquals(listOf(null, "en", "ur", "ar", null), seen)
        assertNull(Languages.next("de"))
    }

    @Test
    fun `the learn prompt asks only for a client that uses another language`() {
        assertEquals("Use Urdu for Acme from now on?", Languages.learnPrompt("Acme", "en", "ur"))
        assertEquals("Use Arabic for Acme from now on?", Languages.learnPrompt("Acme", null, "ar"))
        assertNull(Languages.learnPrompt("Acme", "en+ur", "ur"))
        assertNull(Languages.learnPrompt("Acme", "ur", null))
        assertNull(Languages.learnPrompt(null, "ur", "en"))
    }
}
