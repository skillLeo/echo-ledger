import type { TranscriptionLanguage } from "./ipc";

/** A language choice: a code, or null for automatic (the client's or the console default). */
export type LanguageChoice = TranscriptionLanguage | null;

/** The choices offered everywhere (docs/API.md "Languages"), in chip order. */
export const LANGUAGE_OPTIONS: readonly { value: LanguageChoice; label: string; chip: string }[] = [
  { value: null, label: "Automatic", chip: "Auto" },
  { value: "en", label: "English", chip: "EN" },
  { value: "ur", label: "Urdu", chip: "UR" },
  { value: "en+ur", label: "Urdu + English", chip: "UR+EN" },
  { value: "hi", label: "Hindi", chip: "HI" },
  { value: "en+hi", label: "Hindi + English", chip: "HI+EN" },
];

function option(value: string | null | undefined) {
  return LANGUAGE_OPTIONS.find((o) => o.value === (value || null)) ?? LANGUAGE_OPTIONS[0];
}

/** "UR+EN"; "Auto" for none or an unknown code. */
export function languageChip(value: string | null | undefined): string {
  return option(value).chip;
}

/** "Urdu + English"; "Automatic" for none or an unknown code. */
export function languageLabel(value: string | null | undefined): string {
  return option(value).label;
}

/** The next choice when the chip is clicked (the pill cannot open a menu): Auto, EN, UR, UR+EN, HI, HI+EN, Auto… */
export function nextLanguage(value: string | null | undefined): LanguageChoice {
  const at = LANGUAGE_OPTIONS.indexOf(option(value));
  return LANGUAGE_OPTIONS[(at + 1) % LANGUAGE_OPTIONS.length].value;
}

/** "Urdu + English · ElevenLabs Scribe (scribe_v2)" for the player; null before a final transcript exists. */
export function engineLine(transcript: { final_language?: string | null; final_provider_label?: string | null; final_model?: string | null } | undefined): string | null {
  if (!transcript?.final_provider_label) return null;
  const engine = transcript.final_model ? `${transcript.final_provider_label} (${transcript.final_model})` : transcript.final_provider_label;
  return `${languageLabel(transcript.final_language)} · ${engine}`;
}

/** The wrong-script warning: "devanagari" means an Urdu transcript came back in Hindi letters, and so on. */
export function scriptWarning(code: string | null | undefined): string | null {
  switch (code) {
    case "devanagari": return "Wrong script detected: Hindi letters in an Urdu transcript. Use Re-transcribe in the console to try the next engine.";
    case "latin": return "Wrong script detected: Urdu written in English letters. Use Re-transcribe in the console to try the next engine.";
    case "arabic": return "Wrong script detected: Urdu letters in a Hindi transcript. Use Re-transcribe in the console to try the next engine.";
    default: return null;
  }
}

/** The learn prompt after a chip change: ask only when the recording has a client and the client uses another language. */
export function learnPrompt(clientName: string | null, clientLanguage: string | null | undefined, chosen: LanguageChoice): string | null {
  if (!clientName || chosen === null || (clientLanguage || null) === chosen) return null;
  return `Use ${languageLabel(chosen)} for ${clientName} from now on?`;
}
