package com.echoledger.mobile.core

import com.echoledger.mobile.ui.failureText
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
 * The scan reads every string literal of the app's Kotlin sources and every text of its resources, in all builds (main,
 * the website and Play builds, debug). Only the provider ids the server knows ("assemblyai_v3", "soniox"), each listed
 * exactly, are passed over: they are sent and read, never shown.
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
        // A template's value reads as "{}".
        "Your Groq key ending in {} is saved. New recordings are transcribed with it.",
        "The Free plan transcribes with your own free Groq key. It takes two minutes:",
        "1. Open console.groq.com and sign in (free).",
        "Open console.groq.com",
        "https://console.groq.com/keys",
        "Groq API key",
        "Groq did not accept that key. Copy it again from console.groq.com.",
        // The same line in Urdu is allowed by its key, below (groqKeyStepKeys).
        "The Free plan transcribes with your own free Groq key. Add it in Settings › Transcription key, then tap Retry.",
        "Groq did not accept your key. Check it in Transcription key, then try again.",
        // The settings search finds the key step by its vendor's name too (search words, never shown).
        "groq key free own transcription",
        "groq api key free own key transcribe"
    )

    /**
     * Provider ids and protocol names sent to or read from the server, and the settings search's own ids, never shown to
     * people: each one exactly, so a lower-case display name ("whisper.cpp") is still caught.
     */
    private val internalIds = setOf(
        "assemblyai",
        "assemblyai_v3",
        "elevenlabs_realtime",
        "soniox",
        "groq",
        "key.groq"
    )

    /** The app's own source sets (every build: main, the website and Play builds, debug), never the tests. */
    private val sourceSets = listOf("main", "full", "play", "debug").map { File("src/$it") }.filter { it.exists() }

    /**
     * The string literals of Kotlin code, as the compiler reads them: comments are skipped only outside strings (a slash
     * and a star inside a string open no comment), raw strings and escapes are read whole, and a template's own strings
     * are literals too.
     */
    private class Literals(private val s: String) {
        private var i = 0
        val found = mutableListOf<String>()

        fun read(): List<String> {
            code(untilBrace = false)
            return found
        }

        private fun code(untilBrace: Boolean) {
            var depth = 0
            while (i < s.length) {
                when {
                    s.startsWith("//", i) -> while (i < s.length && s[i] != '\n') i++

                    s.startsWith("/*", i) -> blockComment()

                    s[i] == '`' -> i = s.indexOf('`', i + 1).let { if (it < 0) s.length else it + 1 }

                    // a name like `it's`
                    s.startsWith("\"\"\"", i) -> {
                        i += 3
                        found += string(raw = true)
                    }

                    s[i] == '"' -> {
                        i++
                        found += string(raw = false)
                    }

                    s[i] == '\'' -> charLiteral()

                    s[i] == '{' -> {
                        depth++
                        i++
                    }

                    s[i] == '}' -> {
                        i++
                        if (untilBrace && depth == 0) return
                        depth--
                    }

                    else -> i++
                }
            }
        }

        private fun string(raw: Boolean): String {
            val text = StringBuilder()
            while (i < s.length) {
                when {
                    raw && s.startsWith("\"\"\"", i) -> {
                        // """a"""" is a" in Kotlin: the last three quotes close, any before them are text.
                        val end = (i until s.length).firstOrNull { s[it] != '"' } ?: s.length
                        text.append("\"".repeat(end - i - 3))
                        i = end
                        return text.toString()
                    }

                    !raw && s[i] == '"' -> {
                        i++
                        return text.toString()
                    }

                    !raw && s[i] == '\\' && i + 1 < s.length -> {
                        text.append(s[i + 1])
                        i += 2
                    }

                    s.startsWith("\${", i) -> {
                        i += 2
                        code(untilBrace = true)
                        text.append("{}")
                    }

                    else -> text.append(s[i++])
                }
            }
            return text.toString()
        }

        private fun blockComment() {
            var depth = 0
            while (i < s.length) {
                when {
                    s.startsWith("/*", i) -> {
                        depth++
                        i += 2
                    }

                    s.startsWith("*/", i) -> {
                        depth--
                        i += 2
                        if (depth == 0) return
                    }

                    else -> i++
                }
            }
        }

        private fun charLiteral() {
            i++
            if (i < s.length && s[i] == '\\') i += 2 else i++
            while (i < s.length && s[i] != '\'') i++
            i++
        }
    }

    /** Every string literal of the Kotlin sources, with where it is (VendorNames.kt is the list of names itself). */
    private fun kotlinStrings(): List<Pair<String, String>> = sourceSets.flatMap { set ->
        set.walkTopDown().filter { it.extension == "kt" && it.name != "VendorNames.kt" }
            .flatMap { file -> Literals(file.readText()).read().map { "${set.name}/${file.name}: $it" to it } }
            .toList()
    }

    /** The Groq key step's string resources in every language, each by its key ("Groq did not accept that key"). */
    private val groqKeyStepKeys = setOf("v2_vm_key_refused")

    /** Every text of the app's resources (strings, labels, descriptions) and manifests. */

    private fun resourceStrings(): List<Pair<String, String>> = sourceSets.flatMap { set ->
        set.walkTopDown()
            .filter { (it.extension == "xml" && "/res/" in it.path.replace('\\', '/')) || it.name == "AndroidManifest.xml" }
            .flatMap { file ->
                // A string the key step owns is left out by its name, in any language; everything else is read.
                val text = Regex("""<string name="([^"]+)"[^>]*>[^<]*</string>""").replace(file.readText()) {
                    if (it.groupValues[1] in groqKeyStepKeys) "" else it.value
                }
                Regex(">([^<>]+)<|=\"([^\"]+)\"").findAll(text).map { (it.groupValues[1].ifEmpty { it.groupValues[2] }) }
                    .map { "${set.name}/${file.name}: $it" to it }.toList()
            }.toList()
    }

    @Test
    fun `no text the phone shows names an engine or an AI vendor, except the Free plan's Groq key step`() {
        val found = (kotlinStrings() + resourceStrings())
            .filter { (_, s) -> names.containsMatchIn(s) && s.trim() !in internalIds && s !in groqKeyStep }
            .map { it.first }
            .distinct()
        assertEquals("Vendor or engine names in texts people read:\n" + found.joinToString("\n"), emptyList<String>(), found)
    }

    @Test
    fun `the scan reads strings as the compiler does, in every build's sources`() {
        val all = kotlinStrings()
        assertTrue("it reads the sources", all.size > 1_000)
        assertTrue(all.any { it.second.startsWith("Waiting to transcribe") })
        // After "audio/*" (a string, not a comment) the strings that follow are still read.
        assertEquals(listOf("audio/*", "after"), Literals("val a = \"audio/*\"; val b = \"after\" /* c \"no\" */").read())
        assertEquals(listOf("z", "x {} y"), Literals("\"x \${if (a) \"z\" else b} y\"").read())
        assertEquals(listOf("it's \"here\""), Literals("val q = '\\''; val t = \"\"\"it's \"here\"\"\"\"").read())
        assertEquals(listOf("a"), Literals("fun `the plan's step`() = \"a\"").read())
        assertTrue("the debug build's gallery is read", all.any { it.first.startsWith("debug/") })
        assertTrue(names.containsMatchIn("Claude could not answer"))
        assertTrue(names.containsMatchIn("Groq Whisper large-v3 heard:"))
        assertTrue("a lower-case display name", names.containsMatchIn("whisper.cpp") && "whisper.cpp" !in internalIds)
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
        val status = com.echoledger.mobile.translate.TranslateStatus
        // The code decides, never the server's English: a refusal reads "not answering" whatever its text says.
        listOf("Soniox refused: the account has no credit", "Soniox did not accept the key", "Soniox is down", "").forEach {
            assertEquals(it, status.NOT_ANSWERING, refused("provider_error", it))
        }
        assertEquals(status.NOT_SET_UP, refused("provider_not_configured", "No Soniox key", 503))
        assertEquals(status.NOT_SET_UP, refused("provider_not_configured", "The credit and the key are fine", 503))
        assertEquals(status.BUDGET_USED_UP, refused("budget_exceeded", "Soniox key budget credit", 403))
        assertEquals("Live Translate could not start. Try again later.", refused("rate_limited", "Soniox says slow down", 429))
        assertEquals("Too many requests.", refused("rate_limited", "Too many requests.", 429))
        listOf(
            com.echoledger.mobile.translate.LiveTranslateSession.NO_CREDIT,
            status.NOT_SET_UP,
            status.NOT_ANSWERING,
            status.BUDGET_USED_UP
        )
            .forEach { assertFalse(it, VendorNames.mentions(it)) }
    }

    /** A failed transcript with [code] as the server sends it. */
    private fun failed(code: String?) = com.echoledger.mobile.data.ApiJson.decodeFromString(
        com.echoledger.mobile.data.TranscriptDto.serializer(),
        """{"id":"t1","session_id":"s1","kind":"final","provider":"x","status":"failed",
            "error_code":${code?.let { "\"$it\"" } ?: "null"},"error":"The provider said something."}"""
    )

    @Test
    fun `every error_code of the contract gets its own plain sentence on the phone`() {
        val api = File("../../docs/API.md").readText()
        val table = api.substringAfter("**Transcript `error_code` messages**").lines()
            .dropWhile { !it.startsWith("|") }.takeWhile { it.startsWith("|") }
            .drop(2) // the header and its line
        val codes = table.mapNotNull { Regex("^\\| `([a-z_]+)` \\|").find(it)?.groupValues?.get(1) }
        assertTrue("the contract's codes are read: $codes", codes.size >= 9 && "needs_transcription_key" in codes && "no_audio" in codes)
        val unknown = failureText(failed("something_new"))
        assertEquals("a missing code reads as an unknown one", unknown, failureText(failed(null)))
        val sentences = codes.associateWith { failureText(failed(it)) }
        sentences.forEach { (code, sentence) ->
            assertTrue("$code has its own sentence", sentence != unknown)
            assertFalse("$code never shows the server's text", "provider said" in sentence)
            if (code !in setOf("needs_transcription_key", "own_key_rejected")) {
                assertFalse("$code: $sentence", VendorNames.mentions(sentence))
            }
        }
        assertEquals("one sentence per code", codes.size, sentences.values.toSet().size)
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
