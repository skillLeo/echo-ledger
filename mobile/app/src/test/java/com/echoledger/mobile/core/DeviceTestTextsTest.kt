package com.echoledger.mobile.core

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The device tests (src/androidTest) look for the app's own words: every text they wait for, tap or check with
 * Ui.clickText, awaitText, has or hasContaining appears in the app (a Kotlin string or a string resource in any
 * language), so a wording change can't leave them waiting for words the app no longer says. Words that come from the
 * server or the test's own data are listed below, each with its reason.
 */
class DeviceTestTextsTest {
    /** Texts that are not the app's own words. */
    private val notTheAppsWords = mapOf(
        "This code is invalid, expired, or already used." to "the test server's refusal of a used pairing code",
        "The artifacts.0.mime field is invalid." to "the test server's validation message for a bad upload",
        "This is a test of the EchoLedger transcription system." to "the transcript of the test's own audio file",
        "Settings phone" to "the device name the test pairs with",
        "Certificate pinning" to "checked to be absent (P-021: no pins are shown to people)",
        "AssemblyAI" to "checked to be absent (no engine or vendor names)",
        "Reduce echo" to "the microphone's value, made at run time from \"Reduce echo (recommended)\" without its last words"
    )

    private val call = Regex("""Ui\.(clickText|awaitText|has|hasContaining)\(\s*"((?:[^"\\]|\\.)*)"""")

    private fun unescape(s: String) = s.replace("\\\"", "\"").replace("\\'", "'").replace("\\n", "\n").replace("\\\\", "\\")

    /** Every Kotlin string literal of the app (templates kept as written). */
    private fun appStrings(): List<String> {
        val literal = Regex(""""((?:[^"\\]|\\.)*)"""")
        val kotlin = File("src/main/java").walkTopDown().filter { it.extension == "kt" }
            .flatMap { f -> literal.findAll(f.readText()).map { unescape(it.groupValues[1]) } }
        val resource = Regex("""<string name="[^"]+"[^>]*>(.*?)</string>""", RegexOption.DOT_MATCHES_ALL)
        val resources = File("src/main/res").listFiles { f -> f.name.startsWith("values") }.orEmpty()
            .flatMap { it.listFiles { f -> f.extension == "xml" }.orEmpty().toList() }
            .flatMap { f -> resource.findAll(f.readText()).map { unescape(it.groupValues[1]).replace("&amp;", "&") } }
        return (kotlin + resources).toList()
    }

    /** A template ("Uploading ${n} recording(s)…", "%1${'$'}d s") as a pattern its filled-in text matches. */
    private fun template(s: String): Regex? {
        val holes = Regex("""\$\{[^}]*\}|\$[a-zA-Z_][a-zA-Z0-9_.]*|%\d\$[sd]|%[sd]""")
        if (!holes.containsMatchIn(s)) return null
        val fixed = holes.split(s)
        // A template that is mostly holes ("${'$'}name", "%1${'$'}s: %2${'$'}s") would match any text: it says nothing.
        if (fixed.sumOf { it.count(Char::isLetter) } < 4) return null
        val parts = fixed.map(Regex::escape)
        return Regex(parts.joinToString(".+"))
    }

    /**
     * [text] is something the app shows: the whole of one of its strings (or a filled-in template) for the exact
     * lookups, part of one for hasContaining and for the fixed parts of a test's own template.
     */
    private fun isAppText(text: String, app: List<String>, patterns: List<Regex>, partOf: Boolean = false): Boolean = if (partOf) {
        app.any { it.contains(text) } || patterns.any { it.containsMatchIn(text) }
    } else {
        app.any { it.trim() == text } || patterns.any { it.matches(text) }
    }

    @Test
    fun everyTextTheDeviceTestsLookForIsTheAppsOwnOrListed() {
        val app = appStrings()
        val patterns = app.mapNotNull(::template)
        val missing = mutableListOf<String>()
        var checked = 0
        File("src/androidTest/java").walkTopDown().filter { it.extension == "kt" }.forEach { f ->
            val text = f.readText()
            call.findAll(text).forEach { m ->
                val words = unescape(m.groupValues[2])
                checked++
                // A template in the test ("Settings phone · ${'$'}{Backend.serverUrl}"): its fixed parts are the app's.
                val hole = Regex("""\$\{[^}]*\}|\$[a-zA-Z_][a-zA-Z0-9_.]*""")
                val ok = when {
                    words in notTheAppsWords -> true

                    hole.containsMatchIn(words) ->
                        words.split(hole).map { it.trim() }.filter { it.length >= 3 }
                            .all { part -> part in notTheAppsWords || isAppText(part, app, patterns, partOf = true) }

                    else -> isAppText(words, app, patterns, partOf = m.groupValues[1] == "hasContaining")
                }
                if (!ok) missing += "${f.name}:${text.substring(0, m.range.first).count { it == '\n' } + 1} \"$words\""
            }
        }
        assertTrue("the scan found the device tests' texts ($checked)", checked > 50)
        assertEquals(emptyList<String>(), missing)
    }

    @Test
    fun theRuleCatchesWordsTheAppNoLongerSays() {
        val app = appStrings()
        val patterns = app.mapNotNull(::template)
        assertTrue(!isAppText("Near-end microphone source", app, patterns))
        assertTrue("part of a text is not the whole text", !isAppText("Near-end", app, patterns))
        assertTrue(isAppText("Your microphone", app, patterns))
        assertTrue("a filled-in template counts", isAppText("Uploading 1 recording(s)…", app, patterns))
    }
}
