package com.echoledger.mobile.core

import java.text.Normalizer

/**
 * Live captions only (display): an engine that guessed Hindi writes Urdu speech in Devanagari. This writes such text
 * in Urdu script letter by letter, so the caption bubble never shows Hindi letters ("आप कैसे हैं" → "آپ کیسے ہیں").
 * Approximate by design; the final transcript is run again as Urdu instead. The same table as the desktop's
 * lib/devanagari.ts. Text without Devanagari is returned as it is.
 */
object Devanagari {
    private val CONSONANTS = mapOf(
        "क" to "ک", "ख" to "کھ", "ग" to "گ", "घ" to "گھ", "ङ" to "ن",
        "च" to "چ", "छ" to "چھ", "ज" to "ج", "झ" to "جھ", "ञ" to "ن",
        "ट" to "ٹ", "ठ" to "ٹھ", "ड" to "ڈ", "ढ" to "ڈھ", "ण" to "ن",
        "त" to "ت", "थ" to "تھ", "द" to "د", "ध" to "دھ", "न" to "ن",
        "प" to "پ", "फ" to "پھ", "ब" to "ب", "भ" to "بھ", "म" to "م",
        "य" to "ی", "र" to "ر", "ल" to "ل", "व" to "و", "श" to "ش",
        "ष" to "ش", "स" to "س", "ह" to "ہ",
        // With nukta: consonant + U+093C (what NFC gives) and the single code points.
        "क़" to "ق", "ख़" to "خ", "ग़" to "غ", "ज़" to "ز",
        "ड़" to "ڑ", "ढ़" to "ڑھ", "फ़" to "ف",
        "क़" to "ق", "ख़" to "خ", "ग़" to "غ", "ज़" to "ز", "ड़" to "ڑ", "ढ़" to "ڑھ", "फ़" to "ف"
    )
    private val VOWEL_START = mapOf(
        'अ' to "ا", 'आ' to "آ", 'इ' to "ا", 'ई' to "ای", 'उ' to "ا", 'ऊ' to "او",
        'ए' to "اے", 'ऐ' to "اے", 'ओ' to "او", 'औ' to "او", 'ऑ' to "آ", 'ऋ' to "ر"
    )
    private val VOWEL_INSIDE = mapOf(
        'अ' to "ا", 'आ' to "آ", 'इ' to "ئ", 'ई' to "ئی", 'उ' to "ؤ", 'ऊ' to "ؤ",
        'ए' to "ئے", 'ऐ' to "ئے", 'ओ' to "ؤ", 'औ' to "ؤ", 'ऑ' to "آ", 'ऋ' to "ر"
    )

    fun has(text: String): Boolean = text.any { it in 'ऀ'..'ॿ' }

    private fun isLetter(c: Char?): Boolean = c != null && (c in 'ऀ'..'ॣ' || c in 'ॱ'..'ॿ')

    fun toUrdu(text: String): String {
        if (!has(text)) return text
        val s = Normalizer.normalize(text, Normalizer.Form.NFC)
        val out = StringBuilder()
        var i = 0
        while (i < s.length) {
            val ch = s[i]
            val next = s.getOrNull(i + 1)
            val endOfWord = !isLetter(next)
            val startOfWord = !isLetter(s.getOrNull(i - 1))
            val pair = if (next == '़') CONSONANTS["$ch$next"] else null
            when {
                pair != null -> {
                    out.append(pair)
                    i++
                }

                CONSONANTS.containsKey(ch.toString()) -> out.append(CONSONANTS.getValue(ch.toString()))

                VOWEL_START.containsKey(ch) -> out.append(if (startOfWord) VOWEL_START.getValue(ch) else VOWEL_INSIDE.getValue(ch))

                ch == 'ा' -> out.append("ا")

                ch == 'ी' -> out.append("ی")

                ch == 'ू' -> out.append("و")

                ch == 'ृ' -> out.append("ر")

                ch == 'े' || ch == 'ै' -> out.append(if (endOfWord) "ے" else "ی")

                ch == 'ॉ' || ch == 'ो' || ch == 'ौ' -> out.append("و")

                ch == 'ं' -> out.append(if (endOfWord) "ں" else "ن")

                ch == 'ँ' -> out.append("ں")

                ch == 'ः' -> out.append("ہ")

                ch == 'ि' || ch == 'ु' || ch == '्' || ch == '़' -> Unit

                ch == '।' || ch == '॥' -> out.append("۔")

                ch in '०'..'९' -> out.append(('0' + (ch - '०')))

                else -> out.append(ch)
            }
            i++
        }
        return out.toString()
    }
}
