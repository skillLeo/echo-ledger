package com.echoledger.mobile.core

/**
 * A person's initials: one rule on the phone (P-126), the web's (InitialsAvatarProvider::initials and PersonAvatar.php),
 * for the account screens, a contact's row and an Activity item's person (contract 2.0.0-aa). The first letter of the
 * first and the last word, after any leading punctuation ("[Ops] Farah" gives OF, never a "["), in upper case; one word gives one
 * letter; no letter or digit at all gives none, and the caller shows the person icon. Urdu and Arabic letters join in
 * their script, so two of them get a ZERO WIDTH NON-JOINER between them ("عائشہ خان" gives ع\u200Cخ) and read as two
 * initials, not as a word.
 */
object PersonInitials {
    /** U+200C, which keeps two Arabic-script letters from joining. */
    const val ZWNJ = "\u200C"

    fun of(name: String?): String {
        val letters = words(name.orEmpty()).mapNotNull(::firstLetter)
        val picked = if (letters.size > 2) listOf(letters.first(), letters.last()) else letters
        val arabic = picked.size > 1 && picked.any { letter -> letter.codePoints().anyMatch(::isArabic) }
        return picked.joinToString(if (arabic) ZWNJ else "")
    }

    /** The words of [name], split at any kind of space (as the web's `\s+`, also a no-break space). */
    private fun words(name: String): List<String> {
        val out = mutableListOf<String>()
        val word = StringBuilder()
        var i = 0
        while (i < name.length) {
            val cp = name.codePointAt(i)
            if (Character.isWhitespace(cp) || Character.isSpaceChar(cp)) {
                if (word.isNotEmpty()) out += word.toString()
                word.setLength(0)
            } else {
                word.appendCodePoint(cp)
            }
            i += Character.charCount(cp)
        }
        if (word.isNotEmpty()) out += word.toString()
        return out
    }

    /** The word's first letter or number (the web's `\p{L}` and `\p{N}`), in upper case; null when it has none. */
    private fun firstLetter(word: String): String? {
        var i = 0
        while (i < word.length) {
            val cp = word.codePointAt(i)
            if (Character.isLetter(cp) || isNumber(cp)) return String(Character.toChars(cp)).uppercase()
            i += Character.charCount(cp)
        }
        return null
    }

    private fun isNumber(cp: Int): Boolean = when (Character.getType(cp).toByte()) {
        Character.DECIMAL_DIGIT_NUMBER, Character.LETTER_NUMBER, Character.OTHER_NUMBER -> true
        else -> false
    }

    private fun isArabic(cp: Int): Boolean = Character.UnicodeScript.of(cp) == Character.UnicodeScript.ARABIC
}
