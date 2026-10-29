package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class LanguagesTest {
    @Test
    fun `menus say what each choice does, sentences keep the plain name, no Hindi anywhere`() {
        assertEquals(
            listOf("Auto (English / Urdu / Arabic)", "English only", "Urdu (with English words)", "Arabic (with English words)"),
            Languages.MAIN.map { it.menu }
        )
        assertEquals("French", Languages.menu("fr"))
        assertEquals("Urdu", Languages.label("ur"))
        assertEquals("Urdu (with English words)", Languages.menu("hi"))
        assertEquals(false, Languages.OPTIONS.any { it.menu.contains("Hindi", ignoreCase = true) })
    }

    @Test
    fun `english only is risky on Urdu or Arabic speech, Urdu letters or Roman Urdu, never on English`() {
        assertEquals(true, Languages.englishOnlyRisk("ur", null))
        assertEquals(true, Languages.englishOnlyRisk("hi", null))
        assertEquals(true, Languages.englishOnlyRisk(null, "آپ کل کی meeting"))
        assertEquals(true, Languages.englishOnlyRisk("en", "kya kar rahe ho, theek hai"))
        assertEquals(false, Languages.englishOnlyRisk("en", "Hello, this is Sam."))
        assertEquals(false, Languages.englishOnlyRisk(null, "Okay, acha."))
        assertEquals(false, Languages.englishOnlyRisk(null, null))
    }

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
