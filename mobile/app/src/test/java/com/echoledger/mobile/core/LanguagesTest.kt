package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class LanguagesTest {
    @Test
    fun `mixed languages are written English first and unknown codes are automatic`() {
        assertEquals("en+ur", Languages.canonical("ur+en"))
        assertEquals("en+hi", Languages.canonical(" HI + en "))
        assertEquals("ur", Languages.canonical("UR"))
        assertNull(Languages.canonical("fr"))
        assertNull(Languages.canonical("ur+hi"))
        assertNull(Languages.canonical(""))
        assertNull(Languages.canonical(null))
    }

    @Test
    fun `every offered language has a label and a chip`() {
        assertEquals(listOf("Auto", "EN", "UR", "UR+EN", "HI", "HI+EN"), Languages.OPTIONS.map { it.chip })
        assertEquals("Urdu + English", Languages.label("en+ur"))
        assertEquals("Automatic", Languages.label(null))
        assertEquals("Auto", Languages.chip("xx"))
    }

    @Test
    fun `the chip steps through the languages and back to Auto`() {
        val seen = mutableListOf<String?>(null)
        repeat(6) { seen += Languages.next(seen.last()) }
        assertEquals(listOf(null, "en", "ur", "en+ur", "hi", "en+hi", null), seen)
    }

    @Test
    fun `the learn prompt asks only for a client that uses another language`() {
        assertEquals("Use Urdu for Acme from now on?", Languages.learnPrompt("Acme", "en", "ur"))
        assertEquals("Use Hindi + English for Acme from now on?", Languages.learnPrompt("Acme", null, "en+hi"))
        assertNull(Languages.learnPrompt("Acme", "ur", "ur"))
        assertNull(Languages.learnPrompt("Acme", "ur", null))
        assertNull(Languages.learnPrompt(null, "ur", "en"))
    }
}
