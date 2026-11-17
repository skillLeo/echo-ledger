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
        "AssemblyAI" to "checked to be absent (no engine or vendor names)"
    )

    private val call = Regex("""Ui\.(clickText|awaitText|has|hasContaining)\(\s*"((?:[^"\\]|\\.)*)"""")

    private fun unescape(s: String) = s.replace("\\\"", "\"").replace("\\'", "'").replace("\\n", "\n").replace("\\\\", "\\")

    /** Every Kotlin string literal of the app (templates kept as written); comments are not the app's words. */
    private fun appStrings(): List<String> {
        val kotlin = File("src/main/java").walkTopDown().filter { it.extension == "kt" }.flatMap { f -> KotlinStrings.of(f.readText()) }
        val resource = Regex("""<string name="[^"]+"[^>]*>(.*?)</string>""", RegexOption.DOT_MATCHES_ALL)
        val resources = File("src/main/res").listFiles { f -> f.name.startsWith("values") }.orEmpty()
            .flatMap { it.listFiles { f -> f.extension == "xml" }.orEmpty().toList() }
            .flatMap { f -> resource.findAll(f.readText()).map { unescape(it.groupValues[1]).replace("&amp;", "&") } }
        return (kotlin + resources).toList()
    }

    /** A template ("Uploading ${n} recording(s)…", "%1${'$'}d s") as a pattern its filled-in text matches. */
    private fun template(s: String): Regex? {
        val holes = Regex("""\$\{.*?\}|\$[a-zA-Z_][a-zA-Z0-9_.]*|%\d\$[sd]|%[sd]""")
        if (!holes.containsMatchIn(s)) return null
        val fixed = holes.split(s)
        // A template that is mostly holes ("${'$'}name", "%1${'$'}s: %2${'$'}s") would match any text: it says nothing.
        if (fixed.sumOf { it.count(Char::isLetter) } < 4) return null
        val parts = fixed.map(Regex::escape)
        // One hole is one value: a number, a name, a date, at most a few words, never any continuation.
        return Regex(parts.joinToString(HOLE))
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
            // Commented-out calls are not looked for (line numbers kept).
            val text = KotlinStrings.withoutComments(f.readText())
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
        assertTrue("words only in a comment are not the app's", !isAppText("Add your free Groq key", app, patterns))
        assertTrue(
            "a hole is one value, not any continuation",
            !isAppText("Uploading 1 recording(s)… and then anything else at all", app, patterns)
        )
    }

    @Test
    fun theLexerReadsKotlinStringsNotCommentsOrCode() {
        val source = """
            // "in a line comment"
            /* "in a block comment" /* "nested" */ still comment */
            /** KDoc with "a quoted word" */
            val a = "plain ${'$'}{list.joinToString(", ") { "inner" }} end"
            val b = ${"\"\"\""}raw "quoted" text${"\"\"\""}
            val c = '"'
            val d = "esc\"aped"
        """.trimIndent()
        val strings = KotlinStrings.of(source)
        // A template's own strings come out first (they are read while its outer string is), so compare as a set.
        assertEquals(
            setOf("plain ${'$'}{list.joinToString(\", \") { \"inner\" }} end", ", ", "inner", "raw \"quoted\" text", "esc\"aped"),
            strings.toSet()
        )
        assertEquals(5, strings.size)
    }

    private companion object {
        const val HOLE = """\S+(?: \S+){0,3}"""
    }
}

/**
 * A small Kotlin lexer for the string scan: string literals (plain and raw, with `${'$'}{…}` expressions whose own
 * strings are read as literals too), never comments (line, block, nested, KDoc) or character literals.
 */
internal object KotlinStrings {
    /** Every string literal in [source], each as written between its quotes (a plain one unescaped). */
    fun of(source: String): List<String> {
        val out = mutableListOf<String>()
        Lexer(source, out).run()
        return out
    }

    /** [source] with its comments blanked (newlines kept, so line numbers stay). */
    fun withoutComments(source: String): String {
        val sb = StringBuilder(source)
        val lexer = Lexer(source, mutableListOf()) { from, to ->
            for (i in from until to) if (sb[i] != '\n') sb.setCharAt(i, ' ')
        }
        lexer.run()
        return sb.toString()
    }

    private class Lexer(val s: String, val out: MutableList<String>, val comment: (Int, Int) -> Unit = { _, _ -> }) {
        var i = 0

        fun run() {
            while (i < s.length) code(stopAtBrace = false)
        }

        /** Code until its end, or (inside a template expression) the brace that closes it. */
        private fun code(stopAtBrace: Boolean) {
            var depth = 0
            while (i < s.length) {
                val c = s[i]
                when {
                    s.startsWith("//", i) -> {
                        val end = s.indexOf('\n', i).let { if (it < 0) s.length else it }
                        comment(i, end)
                        i = end
                    }

                    s.startsWith("/*", i) -> {
                        val start = i
                        var nest = 0
                        while (i < s.length) {
                            if (s.startsWith("/*", i)) {
                                nest++
                                i += 2
                            } else if (s.startsWith("*/", i)) {
                                nest--
                                i += 2
                                if (nest ==
                                    0
                                ) {
                                    break
                                }
                            } else {
                                i++
                            }
                        }
                        comment(start, i)
                    }

                    s.startsWith("\"\"\"", i) -> raw()

                    c == '"' -> plain()

                    c == '\'' -> {
                        i++
                        while (i < s.length && s[i] != '\'') i += if (s[i] == '\\') 2 else 1
                        i++
                    }

                    c == '{' -> {
                        depth++
                        i++
                    }

                    c == '}' -> {
                        if (stopAtBrace && depth == 0) {
                            i++
                            return
                        }
                        depth--
                        i++
                    }

                    else -> i++
                }
            }
        }

        private fun plain() {
            i++
            val sb = StringBuilder()
            while (i < s.length && s[i] != '"') {
                when {
                    s[i] == '\\' && i + 1 < s.length -> {
                        sb.append(s, i, i + 2)
                        i += 2
                    }

                    s.startsWith("${'$'}{", i) -> template(sb)

                    else -> {
                        sb.append(s[i])
                        i++
                    }
                }
            }
            i++
            out += sb.toString().replace("\\\"", "\"").replace("\\'", "'").replace("\\n", "\n").replace("\\\\", "\\")
        }

        private fun raw() {
            i += 3
            val sb = StringBuilder()
            while (i < s.length && !s.startsWith("\"\"\"", i)) {
                if (s.startsWith("${'$'}{", i)) {
                    template(sb)
                } else {
                    sb.append(s[i])
                    i++
                }
            }
            i += 3
            // Quotes right before the closing three belong to the text.
            while (i < s.length && s[i] == '"') {
                sb.append('"')
                i++
            }
            out += sb.toString()
        }

        /** A `${'$'}{…}` expression, kept as written in the string, its own strings read as literals. */
        private fun template(sb: StringBuilder) {
            val start = i
            i += 2
            code(stopAtBrace = true)
            sb.append(s, start, i)
        }
    }
}
