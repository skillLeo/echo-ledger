package com.echoledger.mobile.ui.v2.components

/**
 * A recording row's snippet: the first meaningful sentence of its transcript preview (docs/v2/design-review/
 * D12-library-row.md, "The snippet"), the same rule as the desktop's and the web's. Speaker labels never show; a
 * sentence under three words gives way to the next; null when nothing is left, and the row then says "No speech".
 */
fun firstSentence(preview: String?): String? {
    // Direction marks (U+200E, U+200F) are invisible and would hide a label from the rule.
    val text = preview.orEmpty().replace(DIRECTION_MARKS, "").replace(WHITESPACE, " ").trim()
    if (text.isEmpty()) return null

    // A label is a speaker at the start, as one of the app's own (numbered or not: "Speaker 2"), when it repeats, or
    // anywhere after a sentence end in a transcript that starts with a label; otherwise it is only words.
    val openings = OPENING.findAll(text).toList()
    val labelled = openings.firstOrNull()?.let { it.range.first == 0 && it.groupValues[1].isEmpty() } == true
    val cuts = mutableListOf<IntRange>()
    val known = OWN_LABELS.toMutableSet()
    for (m in openings) {
        val label = m.groupValues[2].trim()
        if (!labelled && !OWN.matches(label) && text.split("$label:").size - 1 < 2) continue
        val start = m.range.first + m.groupValues[1].length
        cuts += start until start + m.groupValues[2].length + 1
        known += label.replace(NUMBERED, "")
    }
    val anywhere = Regex("""(?<=\s|^)(?:${known.joinToString("|") { Regex.escape(it) }})(?: \p{N}{1,2})?:(?=\s|$)""")
    anywhere.findAll(text).forEach { cuts += it.range }

    val utterances = mutableListOf<String>()
    var from = 0
    for (cut in cuts.sortedBy { it.first }) {
        if (cut.first < from) continue
        utterances += text.substring(from, cut.first)
        from = cut.last + 1
    }
    utterances += text.substring(from)

    for (utterance in utterances) {
        for (sentence in sentences(utterance.trim())) {
            if (sentence.split(" ").count { word -> word.any(Char::isLetterOrDigit) } >= MIN_WORDS) return sentence
        }
    }
    return null
}

/** An utterance cut into its sentences, each with its end mark. */
private fun sentences(utterance: String): List<String> {
    val out = mutableListOf<String>()
    var rest = utterance
    while (true) {
        val end = SENTENCE_END.find(rest) ?: break
        out += rest.substring(0, end.range.last + 1).trim()
        rest = rest.substring(end.range.last + 1).trim()
    }
    if (rest.isNotEmpty()) out += rest
    return out
}

/** At least this many words make a sentence worth showing. */
private const val MIN_WORDS = 3

private const val ENDS = ".?!۔؟"

private val WHITESPACE = Regex("""\s+""")
private val DIRECTION_MARKS = Regex("[‎‏]")

/** A label at the start or after a sentence end: up to 24 letters, digits, spaces or brackets, then ": ". */
private val OPENING = Regex("""(^|[$ENDS]\s*)([\p{L}\p{M}()\[\]][\p{L}\p{M}\p{N}()\[\] ]{0,23}):(?=\s|$)""")

/** The first sentence end: a run of marks followed by a space, other punctuation or the end ("3.5" is no end). */
private val SENTENCE_END = Regex("""[$ENDS]+(?=[\s\p{P}]|$)""")

/** The app's own speaker words, never shown in a row wherever they stand. */
private val OWN_LABELS = setOf("Speaker", "Me", "Client", "مقرر")
private val OWN = Regex("""(?i)(?:Speaker|Me|Client|مقرر)(?: \p{N}{1,2})?""")
private val NUMBERED = Regex(""" \p{N}{1,2}$""")
