package com.echoledger.mobile.core

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The phone never shows an engine's or an AI vendor's name (bug P-001, contract 1.8.1-a): every text a person can read
 * says what happens in plain words ("Waiting to transcribe", "the AI assistant", "A second transcription heard:"). The
 * one exception is the Free plan's step "Add your free Groq key", which the person must act on.
 *
 * The scan reads every string in the app's Kotlin sources and resources. A protocol name, a provider id or a field value
 * (one lowercase word like "assemblyai_v3" or "soniox") is no text for people and is passed over.
 */
class VendorNamesTest {
    private val names = Regex(
        "(?i)\\b(gemini|assembly ?ai|groq|eleven ?labs|open ?ai|anthropic|claude|whisper|soniox|deepgram|speechmatics|" +
            "chat ?gpt|gpt-?\\d\\S*|sonnet|haiku|llama|mistral|cohere|azure|parakeet|stt-rt\\S*|nova-\\d)\\b"
    )

    /** The Free plan's Groq key step: the only texts allowed to name a vendor (Groq), each exactly as written. */
    private val groqKeyStep = setOf(
        "Transcripts use your own free Groq key.",
        "Your Groq key",
        "Your Groq key ending in \${saved.last4 ?: \"····\"} is saved. New recordings are transcribed with it.",
        // The same text as the scan reads it (it stops at the quote inside the template).
        "Your Groq key ending in \${saved.last4 ?: ",
        "The Free plan transcribes with your own free Groq key. It takes two minutes:",
        "1. Open console.groq.com and sign in (free).",
        "Open console.groq.com",
        "https://console.groq.com/keys",
        "Groq API key",
        "Groq did not accept that key. Copy it again from console.groq.com.",
        "The Free plan transcribes with your own free Groq key. Add it in You → Transcription key, then tap Retry.",
        // The settings search finds the key step by its vendor's name too (search words, never shown).
        "groq key free own transcription",
        "groq api key free own key transcribe"
    )

    /** A provider id, protocol or field value: one word in lower case, never text for people. */
    private fun identifier(s: String) = Regex("[a-z0-9_.:/-]+").matches(s)

    private val main = File("src/main")

    /** Every string literal of the Kotlin sources, with where it is, comments left out. */
    private fun kotlinStrings(): List<Pair<String, String>> = main.walkTopDown()
        .filter { it.extension == "kt" && it.name != "VendorNames.kt" } // the list of names itself
        .flatMap { file ->
            val code = file.readText()
                .replace(Regex("/\\*[\\s\\S]*?\\*/"), " ")
                .lines().joinToString("\n") { line -> line.replace(Regex("^\\s*//.*$"), "").replace(Regex("\\s//\\s.*$"), "") }
            val strings = Regex("\"\"\"([\\s\\S]*?)\"\"\"|\"((?:\\\\.|[^\"\\\\\\n])*)\"").findAll(code).map {
                it.groupValues[1].ifEmpty { it.groupValues[2] }
            }
            strings.map { "${file.name}: $it" to it }.toList()
        }.toList()

    /** Every text of the app's resources (strings, labels, descriptions). */
    private fun resourceStrings(): List<Pair<String, String>> = main.walkTopDown()
        .filter { (it.extension == "xml" && "/res/" in it.path.replace('\\', '/')) || it.name == "AndroidManifest.xml" }
        .flatMap { file ->
            Regex(">([^<>]+)<|=\"([^\"]+)\"").findAll(file.readText()).map { (it.groupValues[1].ifEmpty { it.groupValues[2] }) }
                .map { "${file.name}: $it" to it }.toList()
        }.toList()

    @Test
    fun `no text the phone shows names an engine or an AI vendor, except the Free plan's Groq key step`() {
        val found = (kotlinStrings() + resourceStrings())
            .filter { (_, s) -> names.containsMatchIn(s) && !identifier(s.trim()) && s !in groqKeyStep }
            .map { it.first }
            .distinct()
        assertEquals("Vendor or engine names in texts people read:\n" + found.joinToString("\n"), emptyList<String>(), found)
    }

    @Test
    fun `the scan sees the phone's texts and catches a vendor name`() {
        val all = kotlinStrings()
        assertTrue("it reads the sources", all.size > 1_000)
        assertTrue(all.any { it.second == "Waiting to transcribe" || it.second.startsWith("Waiting to transcribe") })
        assertTrue(names.containsMatchIn("Claude could not answer"))
        assertTrue(names.containsMatchIn("Groq Whisper large-v3 heard:"))
        assertFalse("a codec is not a vendor", names.containsMatchIn("16 kbps per side (Opus, 48 kHz)"))
        assertFalse("nor is a Spanish call word", names.containsMatchIn("llamada"))
    }

    /** A transcript as the server sends it, waiting for its preferred engine ([label] that engine's name). */
    private fun waiting(label: String?, reason: String?) = com.echoledger.mobile.data.ApiJson.decodeFromString(
        com.echoledger.mobile.data.TranscriptDto.serializer(),
        """{"id":"t1","session_id":"s1","kind":"final","provider":"groq","status":"pending","language":"ur",
            "retry_at":"2026-11-17T09:05:00Z","retry_reason":${reason?.let { "\"$it\"" } ?: "null"},
            "provider_label":${label?.let { "\"$it\"" } ?: "null"}}"""
    )

    @Test
    fun `a transcript waiting for its engine says it waits to transcribe, never the engine's name`() {
        val utc = java.time.ZoneOffset.UTC
        assertEquals(
            "Waiting to transcribe — retrying at 9:05 am",
            TranscriptText.waitingForEngine(waiting("Groq Whisper large-v3", null), utc)
        )
        assertEquals(
            "Waiting to transcribe (busy) — retrying at 9:05 am",
            TranscriptText.waitingForEngine(waiting(null, "rate_limited"), utc)
        )
    }

    @Test
    fun `a refused Live Translate key says what is wrong in plain words, never the engine or its account`() {
        fun refused(code: String, message: String, status: Int = 502) =
            com.echoledger.mobile.translate.TranslateStatus.tokenProblem(com.echoledger.mobile.net.ApiException(status, message, code))
        assertEquals(
            com.echoledger.mobile.translate.LiveTranslateSession.NO_CREDIT,
            refused("provider_error", "Soniox refused: the account has no credit")
        )
        assertEquals(com.echoledger.mobile.translate.TranslateStatus.NOT_SET_UP, refused("provider_error", "Soniox did not accept the key"))
        assertEquals(com.echoledger.mobile.translate.TranslateStatus.NOT_SET_UP, refused("provider_not_configured", "No Soniox key", 503))
        assertEquals("Live Translate's translation service is not answering. Try again later.", refused("provider_error", "Soniox is down"))
        assertEquals("Live Translate's budget for this month is used up.", refused("budget_exceeded", "Soniox budget", 403))
        listOf(
            com.echoledger.mobile.translate.LiveTranslateSession.NO_CREDIT,
            com.echoledger.mobile.translate.TranslateStatus.NOT_SET_UP
        ).forEach { assertFalse(it, VendorNames.mentions(it)) }
    }

    @Test
    fun `server text that names an engine or a vendor is replaced by plain words`() {
        assertEquals(
            "The view could not be made.",
            VendorNames.plain("Anthropic API returned 529 overloaded", "The view could not be made.")
        )
        assertEquals("Try again later.", VendorNames.plain("Try again later.", "x"))
        assertEquals("x", VendorNames.plain(null, "x"))
        assertEquals("x", VendorNames.plain(" ", "x"))
    }
}
