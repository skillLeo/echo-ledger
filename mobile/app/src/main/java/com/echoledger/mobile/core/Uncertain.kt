package com.echoledger.mobile.core

import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.WordDto

/**
 * What the engine was not sure of (since v1.2): a word below [BELOW] confidence, or a whole line marked `uncertain`.
 * Shown with a dotted underline, a tap plays it; the text always stays as heard. The same threshold as the server.
 */
object Uncertain {
    const val BELOW = 0.5

    fun word(word: WordDto): Boolean = word.confidence?.let { it < BELOW } ?: false

    fun hasWords(segment: SegmentDto): Boolean = segment.words.any(::word)

    /**
     * Where a tap at character [offset] of the shown line plays from: the uncertain word under it, else the line's
     * start.
     */
    fun playFrom(segment: SegmentDto, ranges: List<IntRange>, offset: Int): Long {
        val hit = segment.words.getOrNull(ranges.indexOfFirst { offset in it })
        return if (hit != null && word(hit)) hit.startMs else segment.startMs
    }

    /**
     * The line as the words joined by spaces (what the phone shows when it has words) and the character range of
     * each word in it.
     */
    fun layout(words: List<WordDto>): Pair<String, List<IntRange>> {
        val text = StringBuilder()
        val ranges = words.mapIndexed { i, w ->
            if (i > 0) text.append(' ')
            val start = text.length
            text.append(w.text)
            start until text.length
        }
        return text.toString() to ranges
    }
}
