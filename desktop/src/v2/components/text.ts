// A row's snippet: the first meaningful sentence of a transcript's preview (D12 "The snippet"). The same rule runs on
// the web and the phone, so a recording reads the same everywhere.

/** A sentence ends at one of these: the Latin marks and the Urdu full stop and question mark. */
const ENDS = ".?!۔؟";
/** A label: up to 24 letters, spaces or brackets, starting with a letter or a bracket ("Client", "Sara (Acme)", "مقرر"). */
const LABEL = String.raw`[\p{L}\p{M}()\[\]][\p{L}\p{M}()\[\] ]{0,23}`;
/** A label at the start, or after a sentence end, followed by ": " (or the colon ending the preview). */
const OPENING = new RegExp(String.raw`(^|[${ENDS}]\s*)(${LABEL}):(?=\s|$)`, "gu");
/** The app's own speaker words: never shown in a row, wherever they stand. */
const OWN_LABELS = ["Speaker", "Me", "Client", "مقرر"];
/** The first sentence end: a run of marks followed by a space, other punctuation or the end ("3.5" is no end). */
const SENTENCE_END = new RegExp(String.raw`[${ENDS}]+(?=[\s\p{P}]|$)`, "u");
const WORD = /[\p{L}\p{N}]/u;

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
  const text = (preview ?? "").replace(/\s+/g, " ").trim();
  if (!text) return null;

  // The labels where the rule finds them for sure, then each of those (and the app's own) wherever it stands.
  const cuts: [number, number][] = [];
  const known = new Set(OWN_LABELS);
  for (const m of text.matchAll(OPENING)) {
    const start = m.index + m[1].length;
    cuts.push([start, start + m[2].length + 1]);
    known.add(m[2]);
  }
  const anywhere = new RegExp(String.raw`(?<=\s|^)(?:${[...known].map(escape).join("|")}):(?=\s|$)`, "gu");
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

  for (const raw of utterances) {
    const words = raw.trim().split(" ").filter((w) => WORD.test(w));
    if (words.length < 3) continue;
    const utterance = raw.trim();
    const end = SENTENCE_END.exec(utterance);
    return end ? utterance.slice(0, end.index + end[0].length) : utterance;
  }
  return null;
}
