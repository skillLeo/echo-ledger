import type { TranscriptionLanguage } from "./ipc";

/** A language choice: a code, or null for Auto (the client's or the console default). */
export type LanguageChoice = TranscriptionLanguage | null;

/**
 * The choices offered everywhere (docs/API.md "Languages", since v1.2): Auto, English, Urdu and Arabic first, then
 * the More languages (`more`). Urdu and Arabic always allow English words inside; Hindi and the mixed choices are gone.
 */
export const LANGUAGE_OPTIONS: readonly { value: LanguageChoice; label: string; chip: string; more?: true }[] = [
  { value: null, label: "Auto", chip: "Auto" },
  { value: "en", label: "English", chip: "EN" },
  { value: "ur", label: "Urdu", chip: "UR" },
  { value: "ar", label: "Arabic", chip: "AR" },
  { value: "fr", label: "French", chip: "FR", more: true },
  { value: "de", label: "German", chip: "DE", more: true },
  { value: "es", label: "Spanish", chip: "ES", more: true },
  { value: "it", label: "Italian", chip: "IT", more: true },
  { value: "pt", label: "Portuguese", chip: "PT", more: true },
  { value: "nl", label: "Dutch", chip: "NL", more: true },
  { value: "tr", label: "Turkish", chip: "TR", more: true },
  { value: "ru", label: "Russian", chip: "RU", more: true },
];

/** The main choices (the pill steps through these). */
export const MAIN_LANGUAGES = LANGUAGE_OPTIONS.filter((o) => !o.more);

/** The "More languages" choices. */
export const MORE_LANGUAGES = LANGUAGE_OPTIONS.filter((o) => o.more);

const ALIASES: Record<string, TranscriptionLanguage> = { hi: "ur", hin: "ur", urd: "ur", eng: "en", ara: "ar" };

/** A stored value as today's choice: v1.1 mixed values keep their non-English language, Hindi is Urdu. */
export function normalizeLanguage(value: string | null | undefined): LanguageChoice {
  if (!value) return null;
  const codes = value
    .toLowerCase()
    .split("+")
    .map((c) => c.trim().split(/[-_]/)[0])
    .map((c) => ALIASES[c] ?? c)
    .filter((c): c is TranscriptionLanguage => LANGUAGE_OPTIONS.some((o) => o.value === c));
  return codes.find((c) => c !== "en") ?? codes[0] ?? null;
}

function option(value: string | null | undefined) {
  const code = normalizeLanguage(value);
  return LANGUAGE_OPTIONS.find((o) => o.value === code) ?? LANGUAGE_OPTIONS[0];
}

/** "UR"; "Auto" for none or an unknown code. */
export function languageChip(value: string | null | undefined): string {
  return option(value).chip;
}

/** "Urdu"; "Auto" for none or an unknown code. */
export function languageLabel(value: string | null | undefined): string {
  return option(value).label;
}

/** "part 3 of 8" while a long recording is transcribed in parts (the part being worked on); null otherwise. */
export function partsText(progress: { done: number; total: number } | null | undefined): string | null {
  if (!progress || progress.total < 2) return null;
  return `part ${Math.min(progress.total, progress.done + 1)} of ${progress.total}`;
}

/** "Transcribing… part 3 of 8", or "Transcribing…" when it is not made in parts. */
export function transcribingText(progress: { done: number; total: number } | null | undefined): string {
  const parts = partsText(progress);
  return parts ? `Transcribing… ${parts}` : "Transcribing…";
}

/** The next main choice when the pill's chip is clicked (it cannot open a menu): Auto, EN, UR, AR, Auto… */
export function nextLanguage(value: string | null | undefined): LanguageChoice {
  const current = option(value);
  const at = MAIN_LANGUAGES.indexOf(current);
  // A More language steps back to Auto.
  return at < 0 ? null : MAIN_LANGUAGES[(at + 1) % MAIN_LANGUAGES.length].value;
}

/** "Urdu · Groq Whisper large-v3 (whisper-large-v3)" for the player; null before a final transcript exists. */
export function engineLine(transcript: { final_language?: string | null; final_provider_label?: string | null; final_model?: string | null } | undefined): string | null {
  if (!transcript?.final_provider_label) return null;
  const engine = transcript.final_model ? `${transcript.final_provider_label} (${transcript.final_model})` : transcript.final_provider_label;
  return `${languageLabel(transcript.final_language)} · ${engine}`;
}

/** The wrong-script warning: "devanagari" means Hindi letters came back (lines are flagged, never shown as Hindi). */
export function scriptWarning(code: string | null | undefined): string | null {
  switch (code) {
    case "devanagari": return "Wrong script detected: Hindi letters came back. Those lines are marked unclear; use Re-transcribe in the console.";
    case "latin": return "Wrong script detected: Urdu written in English letters. Use Re-transcribe in the console to try the next engine.";
    default: return null;
  }
}

/** The learn prompt after a chip change: ask only when the recording has a client and the client uses another language. */
export function learnPrompt(clientName: string | null, clientLanguage: string | null | undefined, chosen: LanguageChoice): string | null {
  if (!clientName || chosen === null || normalizeLanguage(clientLanguage) === chosen) return null;
  return `Use ${languageLabel(chosen)} for ${clientName} from now on?`;
}
