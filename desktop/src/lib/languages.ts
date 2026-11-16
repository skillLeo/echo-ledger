import type { TranscriptionLanguage } from "./ipc";

/** A language choice: a code, or null for Auto (the client's or the console default). */
export type LanguageChoice = TranscriptionLanguage | null;

/**
 * The choices offered everywhere (docs/API.md "Languages", since v1.2): Auto, English, Urdu and Arabic first, then
 * the More languages (`more`). Urdu and Arabic always allow English words inside; Hindi and the mixed choices are gone.
 */
export const LANGUAGE_OPTIONS: readonly { value: LanguageChoice; label: string; menu?: string; chip: string; more?: true }[] = [
  // `label` is the name inside a sentence ("Re-transcribing in Urdu"); `menu` says what the choice does (since v1.3,
  // the same words as the server's language options).
  { value: null, label: "Auto", menu: "Auto (English / Urdu / Arabic)", chip: "Auto" },
  { value: "en", label: "English", menu: "English only", chip: "EN" },
  { value: "ur", label: "Urdu", menu: "Urdu (with English words)", chip: "UR" },
  { value: "ar", label: "Arabic", menu: "Arabic (with English words)", chip: "AR" },
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

/** A language as a menu shows it: "Urdu (with English words)", "English only", "French". */
export function menuLabel(o: (typeof LANGUAGE_OPTIONS)[number]): string {
  return o.menu ?? o.label;
}

/**
 * "English only" would make the engine translate Urdu speech into English: true when the recording was heard as Urdu
 * (or Arabic), or its live captions have Urdu letters or Roman Urdu words, so the person is warned once.
 */
export function englishOnlyRisk(heard: string | null | undefined, sample: string | null | undefined): boolean {
  const code = normalizeLanguage(heard);
  if (code === "ur" || code === "ar") return true;
  const text = sample ?? "";
  if (/[؀-ۿऀ-ॿ]/.test(text)) return true;
  const roman = new Set(["hai", "hain", "nahi", "nahin", "kya", "kyun", "kaise", "theek", "acha", "achha", "haan", "yaar", "abhi", "aap", "mujhe", "raha", "rahe", "karo", "bohat", "bahut", "kuch", "lekin", "matlab"]);
  const hits = new Set(text.toLowerCase().split(/[^a-z]+/).filter((w) => roman.has(w)));
  return hits.size >= 2;
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

/** The language the final transcript is in ("Urdu"), for "Transcribed in Urdu" (D-041): only once it is finished and
 * its language is known (never "Transcribed in Auto"); which provider or model made it is never shown. */
export function transcribedIn(transcript: { final_language?: string | null; final_status?: string | null } | undefined): string | null {
  if (transcript?.final_status !== "completed") return null;
  const code = normalizeLanguage(transcript.final_language);
  return code ? languageLabel(code) : null;
}

/** What the recording page says when it has no transcript to read (pure, D-052): plain words for every state, never
 * the server's status id. The ones still being transcribed say so elsewhere (`transcribingLine`); a transcript being
 * read says "Loading the transcript…", and one that came back empty has its own line, so a finished recording never
 * reads as empty here. */
export function transcriptStatusLine(status: string | null | undefined): string {
  switch (status) {
    case null:
    case undefined:
    case "":
    case "not_started":
      return "Not transcribed yet.";
    case "awaiting_device":
      return "Waiting for the recording to finish uploading from the device that made it.";
    case "failed":
      return "Transcription failed.";
    default:
      return "The transcript isn't ready yet.";
  }
}

/**
 * "Backup engine — Re-transcribe?" (v1.5): an Urdu or Auto recording was transcribed by an engine the routing table
 * ranks lower, because the preferred one stayed unavailable for the whole wait or refused it. An older server sends no
 * `backup_engine`: false.
 */
export const usedBackupEngine = (transcript: { backup_engine?: boolean | null } | null | undefined): boolean => transcript?.backup_engine === true;

/** Why a transcript waits, for the line's tooltip: in plain words, never which provider (D-126). */
export function retryReason(reason: string | null | undefined): string | null {
  if (reason === "rate_limited") return "The transcription service has reached its limit for now. The transcript waits for it instead of using a less accurate one.";
  if (reason === "unavailable") return "The transcription service isn't answering. The transcript waits for it instead of using a less accurate one.";
  return null;
}

/**
 * "Waiting to transcribe — retrying at 2:05 pm" while an Urdu or Auto transcript waits for its preferred engine (v1.5
 * `retry_at`); another day says which; never which provider (D-126). Null when it does not wait, also on an older
 * server (no `retry_at`).
 */
export function retryLine(transcript: { provider?: string | null; retry_at?: string | null } | null | undefined, now: Date = new Date()): string | null {
  if (!transcript?.retry_at) return null;
  const at = new Date(transcript.retry_at);
  if (Number.isNaN(at.getTime())) return null;
  if (at.getTime() <= now.getTime()) return "Waiting to transcribe — retrying now";
  // "2:05 pm"; newer ICU puts a narrow no-break space before the day period.
  const time = at.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit", hour12: true }).replace(/\s/g, " ").toLowerCase();
  const tomorrow = new Date(now);
  tomorrow.setDate(now.getDate() + 1);
  const day = at.toDateString() === now.toDateString() ? "" : at.toDateString() === tomorrow.toDateString() ? "tomorrow " : `${at.toLocaleDateString("en-US", { month: "short", day: "numeric" })} `;
  return `Waiting to transcribe — retrying ${day}at ${time}`;
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

/** Why a transcription failed, in the app's own plain words per `error_code` (pure, D-130): never the server's message,
 * which can name a provider ("Gemini could not read the audio."); an unknown code gets one generic sentence. */
export function failureLine(code: string | null | undefined): string {
  switch (code) {
    case "no_audio":
      return "No audio was captured in this recording.";
    case "budget_exceeded":
      return "This month's transcription limit was reached.";
    case "provider_unreachable":
      return "The transcription service couldn't be reached. Try again later.";
    case "provider_rejected":
      return "The transcription service couldn't transcribe this recording.";
    case "provider_not_configured":
      return "Transcription isn't set up on the server yet. Ask the person who runs the console.";
    case "provider_timeout":
      return "The transcription service didn't finish in time.";
    case "interrupted":
      return "Transcription was interrupted.";
    case "recording_missing":
      return "The recording is missing or isn't ready yet.";
    case "needs_transcription_key":
      return "No transcription key is added yet.";
    default:
      return "Something went wrong while transcribing this recording.";
  }
}
