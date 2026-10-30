package com.echoledger.mobile.translate

/** Saved Live Translate text (plan B3 "Save session text", B4 "Save subtitles (.srt)"): only words, never audio. */
object SrtWriter {
    /** SubRip: numbered cues with "00:01:02,500 --> 00:01:05,000" times from the start of the session. */
    fun srt(sentences: List<TranslatedSentence>): String = buildString {
        sentences.forEachIndexed { i, s ->
            val end = maxOf(s.endMs, s.startMs + 500)
            append(i + 1).append('\n')
            append(stamp(s.startMs)).append(" --> ").append(stamp(end)).append('\n')
            append(s.text.trim()).append("\n\n")
        }
    }

    /** A plain transcript of a game session: "[0:42] text" lines under a short heading. */
    fun text(sentences: List<TranslatedSentence>, app: String): String = buildString {
        append("Game translate · ").append(app).append("\n\n")
        sentences.forEach { s ->
            val seconds = s.startMs / 1_000
            append('[').append(seconds / 60).append(':').append((seconds % 60).toString().padStart(2, '0')).append("] ")
            append(s.text.trim())
            if (s.original.isNotBlank()) append("\n    ").append(s.original.trim())
            append('\n')
        }
    }

    fun stamp(ms: Long): String {
        val t = ms.coerceAtLeast(0)
        val h = t / 3_600_000
        val m = t / 60_000 % 60
        val s = t / 1_000 % 60
        val milli = t % 1_000
        return "%02d:%02d:%02d,%03d".format(java.util.Locale.US, h, m, s, milli)
    }
}

/** "Mask swear words" (plan B3): common English swear words become asterisks; everything else is left as it is. */
object ProfanityMask {
    private val WORDS = Regex(
        """\b(f+u+c+k+\w*|shit\w*|bitch\w*|bastard\w*|asshole\w*|dick\w*|cunt\w*|motherfuck\w*|wtf)\b""",
        RegexOption.IGNORE_CASE
    )

    fun mask(text: String): String = WORDS.replace(text) { "*".repeat(it.value.length) }
}
