/**
 * Live captions only (display): an engine that guessed Hindi writes Urdu speech in Devanagari. This writes such text
 * in Urdu script letter by letter, so the captions never show Hindi letters ("आप कैसे हैं" → "آپ کیسے ہیں").
 * Approximate by design (Hindi spelling is not Urdu spelling); the final transcript is run again as Urdu instead.
 * Text without Devanagari is returned as it is.
 */

const CONSONANTS: Record<string, string> = {
  "क": "ک", "ख": "کھ", "ग": "گ", "घ": "گھ", "ङ": "ن", "च": "چ", "छ": "چھ", "ज": "ج", "झ": "جھ", "ञ": "ن",
  "ट": "ٹ", "ठ": "ٹھ", "ड": "ڈ", "ढ": "ڈھ", "ण": "ن", "त": "ت", "थ": "تھ", "द": "د", "ध": "دھ", "न": "ن",
  "प": "پ", "फ": "پھ", "ब": "ب", "भ": "بھ", "म": "م", "य": "ی", "र": "ر", "ल": "ل", "व": "و", "श": "ش",
  "ष": "ش", "स": "س", "ह": "ہ",
  // With nukta: the single code points (U+0958-U+095F) and consonant + U+093C (what NFC gives).
  "क़": "ق", "ख़": "خ", "ग़": "غ", "ज़": "ز", "ड़": "ڑ", "ढ़": "ڑھ", "फ़": "ف", "य़": "ی",
  "क़": "ق", "ख़": "خ", "ग़": "غ", "ज़": "ز", "ड़": "ڑ", "ढ़": "ڑھ", "फ़": "ف",
};

/** Independent vowels at the start of a word, and inside one (after another vowel). */
const VOWEL_START: Record<string, string> = { "अ": "ا", "आ": "آ", "इ": "ا", "ई": "ای", "उ": "ا", "ऊ": "او", "ए": "اے", "ऐ": "اے", "ओ": "او", "औ": "او", "ऑ": "آ", "ऋ": "ر" };
const VOWEL_INSIDE: Record<string, string> = { "अ": "ا", "आ": "آ", "इ": "ئ", "ई": "ئی", "उ": "ؤ", "ऊ": "ؤ", "ए": "ئے", "ऐ": "ئے", "ओ": "ؤ", "औ": "ؤ", "ऑ": "آ", "ऋ": "ر" };

const DEVANAGARI = /[ऀ-ॿ]/;
const LETTER = /[ऀ-ॣॱ-ॿ]/;

export function hasDevanagari(text: string): boolean {
  return DEVANAGARI.test(text);
}

export function devanagariToUrdu(text: string): string {
  if (!hasDevanagari(text)) return text;
  const chars = Array.from(text.normalize("NFC"));
  let out = "";
  for (let i = 0; i < chars.length; i++) {
    const ch = chars[i];
    const next = chars[i + 1] ?? "";
    const endOfWord = !LETTER.test(next);
    const prev = chars[i - 1] ?? "";
    const startOfWord = !LETTER.test(prev);
    if (next === "़" && CONSONANTS[ch + next]) {
      out += CONSONANTS[ch + next];
      i++;
      continue;
    }
    if (CONSONANTS[ch]) {
      out += CONSONANTS[ch];
      continue;
    }
    if (VOWEL_START[ch]) {
      out += startOfWord ? VOWEL_START[ch] : VOWEL_INSIDE[ch];
      continue;
    }
    switch (ch) {
      case "ा": out += "ا"; break; // ा
      case "ी": out += "ی"; break; // ी
      case "ू": out += "و"; break; // ू
      case "ृ": out += "ر"; break; // ृ
      case "े": case "ै": out += endOfWord ? "ے" : "ی"; break; // े ै
      case "ॉ": case "ो": case "ौ": out += "و"; break; // ॉ ो ौ
      case "ं": out += endOfWord ? "ں" : "ن"; break; // ं
      case "ँ": out += "ں"; break; // ँ
      case "ः": out += "ہ"; break; // ः
      case "ि": case "ु": case "्": case "़": break; // ि ु ् ़ (short vowels and the virama are not written)
      case "।": case "॥": out += "۔"; break; // । ॥
      default: {
        const code = ch.codePointAt(0) ?? 0;
        out += code >= 0x0966 && code <= 0x096f ? String(code - 0x0966) : ch;
      }
    }
  }
  return out;
}
