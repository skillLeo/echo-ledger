package com.echoledger.mobile.dictation

import kotlinx.serialization.Serializable

/** "When I say [from], write [to]": a dictionary entry (fixed spelling) or a replacement (B14). */
@Serializable
data class Replacement(val from: String = "", val to: String = "")

/** A voice snippet: saying [trigger] inserts [text]. */
@Serializable
data class Snippet(val trigger: String = "", val text: String = "")

/**
 * Dictation text (plan B14), the same rules as the desktop: the optional clean-up, then spoken commands ("new line"),
 * the dictionary and replacements, and voice snippets, in that order. Pure; unit-tested.
 */
object DictationText {
    const val MAX_ENTRIES = 200
    const val MAX_CHARS = 100
    const val MAX_SNIPPET_CHARS = 2_000

    /** Hesitation words removed by the clean-up. "mm" is not one of them: it is also millimetres. */
    private val FILLERS = setOf("um", "uh", "erm", "uhm", "hmm")

    /** Marks that end a sentence, in Latin and other scripts (Urdu/Arabic, CJK full-width, Devanagari). */
    private val SENTENCE_ENDS = setOf('.', '!', '?', '…', '۔', '؟', '。', '？', '！', '｡', '．', '।', '॥')

    /** Spoken commands, longest first so "new paragraph" wins over "new line". */
    private val COMMANDS = listOf("new paragraph" to "\n\n", "next paragraph" to "\n\n", "new line" to "\n", "next line" to "\n")

    private fun endsText(c: Char) = c in SENTENCE_ENDS || c in ":;：；"

    private fun attaches(c: Char) = endsText(c) || c in ",،，、"

    /** The final text of one dictation from its turns. */
    fun compose(turns: List<String>, cleanup: Boolean, replacements: List<Replacement>, snippets: List<Snippet>): String {
        val joined = if (cleanup) professionalize(turns) else turns.map { it.trim() }.filter { it.isNotEmpty() }.joinToString(" ")
        return apply(joined, replacements, snippets)
    }

    /** Tidy spacing and punctuation, drop fillers, capitalise sentences and "I", end with a full stop. */
    fun professionalize(turns: List<String>): String {
        val words = turns.flatMap { it.trim().split(Regex("\\s+")) }.filter { it.isNotEmpty() }.filter { w ->
            w.filter { it.isLetterOrDigit() }.lowercase() !in FILLERS
        }
        val tokens = mutableListOf<String>()
        for (word in words) {
            if (tokens.isNotEmpty() && word.all(::attaches)) tokens[tokens.lastIndex] = tokens.last() + word else tokens += word
        }
        val text = tokens.joinToString(" ") { capitalizePronoun(it) }
        val out = StringBuilder(text.length + 1)
        var capitalize = true
        var afterEnd = false
        for (c in text) {
            if (capitalize && c.isLetter()) {
                out.append(c.uppercase())
                capitalize = false
            } else {
                out.append(c)
            }
            if (c in SENTENCE_ENDS) {
                afterEnd = true
            } else if (afterEnd) {
                capitalize = capitalize || c.isWhitespace()
                afterEnd = false
            }
        }
        val trimmed = out.toString().trim().trimEnd(',', '،', '，', '、').trimEnd()
        return when {
            trimmed.isEmpty() -> ""
            endsText(trimmed.last()) -> trimmed
            else -> "$trimmed."
        }
    }

    private fun capitalizePronoun(token: String): String {
        val core = token.trimEnd { !it.isLetterOrDigit() && it != '\'' }
        return if (core == "i" || core.startsWith("i'")) "I" + token.substring(1) else token
    }

    /** Applies the commands, then the replacements, then the snippets. */
    fun apply(text: String, replacements: List<Replacement>, snippets: List<Snippet>): String {
        var out = text
        COMMANDS.forEach { (spoken, written) -> out = replacePhrase(out, spoken, written, true) }
        replacements.forEach { out = replacePhrase(out, it.from, it.to, false) }
        snippets.forEach { out = replacePhrase(out, it.trigger, it.text, true) }
        return tidyBreaks(out)
    }

    private fun isWordChar(c: Char) = c.isLetterOrDigit() || c == '_'

    /**
     * Replaces every whole-word, case-insensitive occurrence of [phrase]. With [eatPunctuation], a full stop or comma
     * the clean-up put right after the phrase goes with it ("new line." → line break).
     */
    private fun replacePhrase(text: String, phrase: String, with: String, eatPunctuation: Boolean): String {
        val needle = phrase.trim().lowercase()
        if (needle.isEmpty()) return text
        val lower = text.lowercase()
        // Lower-casing can change the length for a few scripts; then the text is left as it is.
        if (lower.length != text.length) return text
        val out = StringBuilder(text.length)
        var i = 0
        while (i < text.length) {
            val end = i + needle.length
            val fits = end <= text.length && lower.regionMatches(i, needle, 0, needle.length)
            if (fits && (i == 0 || !isWordChar(text[i - 1])) && (end == text.length || !isWordChar(text[end]))) {
                out.append(with)
                i = end
                if (eatPunctuation && i < text.length && text[i] in ".,۔،") i++
                continue
            }
            out.append(text[i])
            i++
        }
        return out.toString()
    }

    /** No spaces around line breaks, and a capital letter after one. */
    private fun tidyBreaks(text: String): String {
        val out = StringBuilder(text.length)
        var capitalize = false
        for ((i, c) in text.withIndex()) {
            if (c == ' ' && (text.getOrNull(i + 1) == '\n' || out.endsWith("\n"))) continue
            when {
                c == '\n' -> {
                    capitalize = true
                    out.append(c)
                }

                capitalize && c.isLetter() -> {
                    out.append(c.uppercase())
                    capitalize = false
                }

                else -> {
                    if (!c.isWhitespace()) capitalize = false
                    out.append(c)
                }
            }
        }
        return out.toString().trim(' ')
    }

    /** Entries that can be saved: trimmed, non-empty, within limits, no duplicates (ignoring case). */
    fun cleanReplacements(list: List<Replacement>): List<Replacement> {
        val out = mutableListOf<Replacement>()
        for (r in list) {
            val from = r.from.trim()
            val to = r.to.trim()
            if (from.isEmpty() || from.length > MAX_CHARS || to.length > MAX_CHARS || out.any { it.from.equals(from, true) }) continue
            out += Replacement(from, to)
        }
        return out.take(MAX_ENTRIES)
    }

    fun cleanSnippets(list: List<Snippet>): List<Snippet> {
        val out = mutableListOf<Snippet>()
        for (s in list) {
            val trigger = s.trigger.trim()
            val text = s.text.trim()
            if (trigger.isEmpty() || trigger.length > MAX_CHARS || text.isEmpty() || s.text.length > MAX_SNIPPET_CHARS) continue
            if (out.any { it.trigger.equals(trigger, true) }) continue
            out += Snippet(trigger, text)
        }
        return out.take(MAX_ENTRIES)
    }

    /** What to type after [before] (the text left of the cursor): a space between words, none after a break. */
    fun forInsertion(before: CharSequence?, text: String): String {
        val last = before?.lastOrNull() ?: return text
        if (text.isEmpty() || last.isWhitespace() || text.first().isWhitespace() || attaches(text.first())) return text
        return " $text"
    }
}
