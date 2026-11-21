package com.echoledger.mobile.ui.v2.components

/**
 * A recording row's snippet: the first meaningful sentence of its transcript preview (docs/v2/design-review/
 * D12-library-row.md, "The snippet"). Speaker labels ("Client:", "Me:", "Speaker:", "Sara (Acme):") never show; lines
 * that say nothing ("Speaker: .", "the time.") are skipped; null when nothing is left, and the row then says "No speech".
 * The same rule as the desktop's and the web's.
 */
fun firstSentence(preview: String?): String? {
    if (preview.isNullOrBlank()) return null
    return preview.split(SPEAKER_LABEL)
        .map { it.trim() }
        .firstOrNull { utterance -> utterance.split(WHITESPACE).count { word -> word.any(Char::isLetter) } >= MIN_WORDS }
        ?.let { utterance ->
            val end = SENTENCE_END.find(utterance)
            if (end == null) utterance else utterance.substring(0, end.range.first + 1).trim()
        }
}

/** At least this many words make an utterance worth showing. */
private const val MIN_WORDS = 3

private val WHITESPACE = Regex("""\s+""")

/** A sentence's end: . ? ! … and Urdu's ۔ ؟, followed by a space or the end. */
private val SENTENCE_END = Regex("""[.?!…۔؟](?=\s|$)""")

/**
 * A speaker label: at the start or after a sentence end, one or two words with an optional bracket ("Sara (Acme):");
 * anywhere, the known ones the transcripts use (Speaker, Client, Me, You, Caller and Urdu's مقرر).
 */
private val SPEAKER_LABEL = Regex(
    """(?:^|(?<=[.?!…۔؟]))\s*[\p{L}\p{M}]+(?:\s[\p{L}\p{M}]+)?(?:\s\([^)]{1,24}\))?\s?:\s*""" +
        """|\s(?i:speaker|client|me|you|caller|مقرر)\s?:\s*"""
)
