// A row's snippet: the first meaningful sentence of a transcript's preview (D12 "The snippet"). The same rule runs on
// the web and the phone, so a recording reads the same everywhere.

/** A sentence ends at one of these: the Latin marks and the Urdu full stop and question mark. */
const ENDS = ".?!۔؟";
/** A label: up to 24 letters, spaces or brackets, starting with a letter or a bracket ("Client", "Sara (Acme)", "مقرر"). */
// Letters, then letters, digits, spaces or brackets ("Speaker 1:", "Sara (Acme):"), up to 24 in all.
const LABEL = String.raw`[\p{L}\p{M}()\[\]][\p{L}\p{M}\p{N}()\[\] ]{0,23}`;
/** A label at the start, or after a sentence end, followed by ": " (or the colon ending the preview). */
const OPENING = new RegExp(String.raw`(^|[${ENDS}]\s*)(${LABEL}):(?=\s|$)`, "gu");
/** The app's own speaker words: never shown in a row, wherever they stand. */
const OWN_LABELS = ["Speaker", "Me", "Client", "مقرر"];
/** The first sentence end: a run of marks followed by a space, other punctuation or the end ("3.5" is no end). */
const SENTENCE_END = new RegExp(String.raw`[${ENDS}]+(?=[\s\p{P}]|$)`, "u");
const WORD = /[\p{L}\p{N}]/u;
/** The app's own speaker words, with an optional number ("Speaker 2"). */
const OWN = new RegExp(String.raw`^(?:${OWN_LABELS.join("|")})(?: \p{N}{1,2})?$`, "iu");
const NUMBERED = / \p{N}{1,2}$/u;

/** Looks like a speaker's name: 1 to 3 words, each starting with a capital, a non-cased letter (Urdu), a bracket or a digit. */
const isName = (label: string) => {
  const words = label.split(" ").filter(Boolean);
  return words.length <= 3 && words.every((w) => /^(?:\p{Lu}|\p{Lo}|[([]|\p{N})/u.test(w));
};
const escape = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/**
 * The first meaningful sentence of a preview, or null when there is none (the caller then shows "No speech" for a
 * finished transcript, and nothing while it is still transcribing).
 * 1. The preview splits into utterances at speaker labels: a label at the start or after a sentence end, and that
 *    same label (or one of the app's own: Speaker, Me, Client, مقرر) after a space anywhere else, since an Urdu
 *    transcript often has no full stop before the next speaker.
 * 2. The labels are dropped.
 * 3. Utterances that are empty, only punctuation, or under 3 words are skipped.
 * 4. The first one left is cut at its first sentence end; a longer line is left to the row's ellipsis.
 */
export function firstSentence(preview: string | null | undefined): string | null {
  // Direction marks (U+200E, U+200F) are invisible and would hide a label from the rule.
  const text = (preview ?? "").replace(/[\u200E\u200F]/g, "").replace(/\s+/g, " ").trim();
  if (!text) return null;

  // A label is a speaker at the start, as one of the app's own (numbered or not: "Speaker 2"), when it repeats, or
  // anywhere after a sentence end in a transcript that starts with a label. Otherwise a phrase before a colon after a
  // full stop ("Yes. Next step: …") is only words.
  const cuts: [number, number][] = [];
  const known = new Set(OWN_LABELS);
  const openings = [...text.matchAll(OPENING)];
  const labelled = openings.length > 0 && openings[0].index === 0 && openings[0][1] === "";
  for (const m of openings) {
    const label = m[2].trim();
    // In a labelled transcript a later label still has to look like a name: up to 3 words, each capitalised, a number
    // or in brackets ("Sara (Acme)", "Speaker 2"); Urdu has no capitals, so its words count as names. A phrase such as
    // "The main issue is this:" or "I will send it to you:" stays words.
    const named = m.index === 0 || isName(label);
    if (!(labelled && named) && !OWN.test(label) && text.split(`${label}:`).length - 1 < 2) continue;
    const start = m.index + m[1].length;
    cuts.push([start, start + m[2].length + 1]);
    known.add(label.replace(NUMBERED, ""));
  }
  const anywhere = new RegExp(String.raw`(?<=\s|^)(?:${[...known].map(escape).join("|")})(?: \p{N}{1,2})?:(?=\s|$)`, "gu");
  for (const m of text.matchAll(anywhere)) cuts.push([m.index, m.index + m[0].length]);
  cuts.sort((a, b) => a[0] - b[0]);

  const utterances: string[] = [];
  let from = 0;
  for (const [start, end] of cuts) {
    if (start < from) continue; // the same label found both ways
    utterances.push(text.slice(from, start));
    from = end;
  }
  utterances.push(text.slice(from));

  // The first sentence of 3 words or more, in the first utterance that has one ("Thanks. I'll send it tomorrow."
  // gives the second sentence); a longer line is left to the row's ellipsis.
  for (const raw of utterances) {
    for (const sentence of sentences(raw.trim())) {
      if (sentence.split(" ").filter((w) => WORD.test(w)).length >= 3) return sentence;
    }
  }
  return null;
}

/** An utterance cut into its sentences, each with its end mark. */
function sentences(utterance: string): string[] {
  const out: string[] = [];
  let rest = utterance;
  for (let end = SENTENCE_END.exec(rest); end; end = SENTENCE_END.exec(rest)) {
    out.push(rest.slice(0, end.index + end[0].length).trim());
    rest = rest.slice(end.index + end[0].length).trim();
  }
  if (rest) out.push(rest);
  return out;
}
