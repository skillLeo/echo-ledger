import type { TranscriptionLanguage } from "./ipc";
import { language, wordsIn, type Lang } from "../i18n/locale.ts";
import { whenParts } from "./format.ts";

// The words are read when they are asked for, in the account's language (src/i18n), or in English when the caller says
// `lang` "en": a window still in English (classic's) keeps exactly the English it always had, with no Urdu piece in an
// English sentence. A choice is its code (`value`); its words are never compared.

/** A language choice: a code, or null for Auto (the client's or the console default). */
export type LanguageChoice = TranscriptionLanguage | null;

type Say = (text: string) => string;
/**
 * Each choice's words in the language `t` speaks: `name` is the name inside a sentence ("Re-transcribing in Urdu");
 * `menu` says what the choice does (since v1.3, the same words as the server's language options).
 */
const WORDS: Readonly<Record<string, { name: (t: Say) => string; menu?: (t: Say) => string }>> = {
  auto: { name: (t) => t("Auto"), menu: (t) => t("Auto (English / Urdu / Arabic)") },
  en: { name: (t) => t("English"), menu: (t) => t("English only") },
  ur: { name: (t) => t("Urdu"), menu: (t) => t("Urdu (with English words)") },
  ar: { name: (t) => t("Arabic"), menu: (t) => t("Arabic (with English words)") },
  fr: { name: (t) => t("French") },
  de: { name: (t) => t("German") },
  es: { name: (t) => t("Spanish") },
  it: { name: (t) => t("Italian") },
  pt: { name: (t) => t("Portuguese") },
  nl: { name: (t) => t("Dutch") },
  tr: { name: (t) => t("Turkish") },
  ru: { name: (t) => t("Russian") },
};
const wordsOf = (value: LanguageChoice) => WORDS[value ?? "auto"] ?? WORDS.auto;
/** A choice's chip: its code in capitals ("UR"), Auto's name for Auto. */
const chipOf = (value: LanguageChoice, lang?: Lang) => (value ? value.toUpperCase() : wordsOf(null).name(wordsIn(lang).t));

export type LanguageOption = { readonly value: LanguageChoice; readonly label: string; readonly menu?: string; readonly chip: string; readonly more?: true };
/** One choice, its words read in the window's language whenever they are asked for. */
function choice(value: LanguageChoice, more?: true): LanguageOption {
  return {
    value,
    get label() {
      return wordsOf(value).name(wordsIn().t);
    },
    get menu() {
      return wordsOf(value).menu?.(wordsIn().t);
    },
    get chip() {
      return chipOf(value);
    },
    ...(more ? { more } : {}),
  };
}

/**
 * The choices offered everywhere (docs/API.md "Languages", since v1.2): Auto, English, Urdu and Arabic first, then
 * the More languages (`more`). Urdu and Arabic always allow English words inside; Hindi and the mixed choices are gone.
 * `label`, `menu` and `chip` are in the window's language; a window still in English asks languageLabel, menuLabel and
 * languageChip with "en".
 */
export const LANGUAGE_OPTIONS: readonly LanguageOption[] = [
  choice(null),
  choice("en"),
  choice("ur"),
  choice("ar"),
  choice("fr", true),
  choice("de", true),
  choice("es", true),
  choice("it", true),
  choice("pt", true),
  choice("nl", true),
  choice("tr", true),
  choice("ru", true),
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
export function languageChip(value: string | null | undefined, lang?: Lang): string {
  return chipOf(option(value).value, lang);
}

/** "Urdu"; "Auto" for none or an unknown code. */
export function languageLabel(value: string | null | undefined, lang?: Lang): string {
  return wordsOf(option(value).value).name(wordsIn(lang).t);
}

/** A language as a menu shows it: "Urdu (with English words)", "English only", "French". */
export function menuLabel(o: Pick<LanguageOption, "value">, lang?: Lang): string {
  const words = wordsOf(o.value);
  return (words.menu ?? words.name)(wordsIn(lang).t);
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
export function partsText(progress: { done: number; total: number } | null | undefined, lang?: Lang): string | null {
  if (!progress || progress.total < 2) return null;
  const { tc } = wordsIn(lang);
  return tc("part :n of :total", progress.total, { n: Math.min(progress.total, progress.done + 1), total: progress.total });
}

/** "Transcribing… part 3 of 8", or "Transcribing…" when it is not made in parts. */
export function transcribingText(progress: { done: number; total: number } | null | undefined, lang?: Lang): string {
  const { t, tc } = wordsIn(lang);
  if (!progress || progress.total < 2) return t("Transcribing…");
  return tc("Transcribing… part :n of :total", progress.total, { n: Math.min(progress.total, progress.done + 1), total: progress.total });
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
export function transcribedIn(transcript: { final_language?: string | null; final_status?: string | null } | undefined, lang?: Lang): string | null {
  if (transcript?.final_status !== "completed") return null;
  const code = normalizeLanguage(transcript.final_language);
  return code ? languageLabel(code, lang) : null;
}

/** What the recording page says when it has no transcript to read (pure, D-052): plain words for every state, never
 * the server's status id. The ones still being transcribed say so elsewhere (`transcribingLine`); a transcript being
 * read says "Loading the transcript…", and one that came back empty has its own line, so a finished recording never
 * reads as empty here. */
export function transcriptStatusLine(status: string | null | undefined, lang?: Lang): string {
  const { t } = wordsIn(lang);
  switch (status) {
    case null:
    case undefined:
    case "":
    case "not_started":
      return t("Audio only. No transcript yet.");
    case "awaiting_device":
      return t("Waiting for the recording to finish uploading from the device that made it.");
    case "failed":
      return t("Transcription failed.");
    default:
      return t("The transcript isn't ready yet.");
  }
}

/**
 * "Backup engine — Re-transcribe?" (v1.5): an Urdu or Auto recording was transcribed by an engine the routing table
 * ranks lower, because the preferred one stayed unavailable for the whole wait or refused it. An older server sends no
 * `backup_engine`: false.
 */
export const usedBackupEngine = (transcript: { backup_engine?: boolean | null } | null | undefined): boolean => transcript?.backup_engine === true;

/** Why a transcript waits, for the line's tooltip: in plain words, never which provider (D-126). */
export function retryReason(reason: string | null | undefined, lang?: Lang): string | null {
  const { t } = wordsIn(lang);
  if (reason === "rate_limited") return t("The transcription service has reached its limit for now. The transcript waits for it instead of using a less accurate one.");
  if (reason === "unavailable") return t("The transcription service isn't answering. The transcript waits for it instead of using a less accurate one.");
  return null;
}

/**
 * "Waiting to transcribe — retrying at 2:05 pm" while an Urdu or Auto transcript waits for its preferred engine (v1.5
 * `retry_at`); another day says which; never which provider (D-126). Null when it does not wait, also on an older
 * server (no `retry_at`).
 */
export function retryLine(transcript: { provider?: string | null; retry_at?: string | null } | null | undefined, now: Date = new Date(), lang?: Lang): string | null {
  if (!transcript?.retry_at) return null;
  const at = new Date(transcript.retry_at);
  if (Number.isNaN(at.getTime())) return null;
  const { t } = wordsIn(lang);
  if (at.getTime() <= now.getTime()) return t("Waiting to transcribe — retrying now");
  const tomorrow = new Date(now);
  tomorrow.setDate(now.getDate() + 1);
  const today = at.toDateString() === now.toDateString();
  const next = at.toDateString() === tomorrow.toDateString();
  // In Urdu the day and the time are lib/format's whenParts (each isolated by t()); in English "2:05 pm" and "Nov 26",
  // as they always were (newer ICU puts a narrow no-break space before the day period).
  const urdu = lang !== "en" && language() === "ur";
  const parts = urdu ? whenParts(transcript.retry_at, now) : null;
  const time = parts ? parts.time : at.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit", hour12: true }).replace(/\s/g, " ").toLowerCase();
  if (today) return t("Waiting to transcribe — retrying at :time", { time });
  if (next) return t("Waiting to transcribe — retrying tomorrow at :time", { time });
  const day = parts ? parts.day : at.toLocaleDateString("en-US", { month: "short", day: "numeric" });
  return t("Waiting to transcribe — retrying :day at :time", { day, time });
}

/** The wrong-script warning: "devanagari" means Hindi letters came back (lines are flagged, never shown as Hindi). */
export function scriptWarning(code: string | null | undefined, lang?: Lang): string | null {
  const { t } = wordsIn(lang);
  switch (code) {
    case "devanagari": return t("Wrong script detected: Hindi letters came back. Those lines are marked unclear; use Re-transcribe in the console.");
    case "latin": return t("Wrong script detected: Urdu written in English letters. Use Re-transcribe in the console to try the next engine.");
    default: return null;
  }
}

/** The learn prompt after a chip change: ask only when the recording has a client and the client uses another language. */
export function learnPrompt(clientName: string | null, clientLanguage: string | null | undefined, chosen: LanguageChoice, lang?: Lang): string | null {
  if (!clientName || chosen === null || normalizeLanguage(clientLanguage) === chosen) return null;
  const { t } = wordsIn(lang);
  return t("Use :language for :client from now on?", { language: languageLabel(chosen, lang), client: clientName });
}

/** Why a transcription failed, in the app's own plain words per `error_code` (pure, D-130): never the server's message,
 * which can name a provider ("Gemini could not read the audio."); an unknown code gets one generic sentence. */
export function failureLine(code: string | null | undefined, lang?: Lang): string {
  const { t } = wordsIn(lang);
  switch (code) {
    case "no_audio":
      return t("No audio was captured in this recording.");
    case "budget_exceeded":
      return t("This month's transcription limit was reached.");
    case "provider_unreachable":
      return t("The transcription service couldn't be reached. Try again later.");
    case "provider_rejected":
      return t("The transcription service couldn't transcribe this recording.");
    case "provider_not_configured":
      return t("Transcription isn't set up on the server yet. Ask the person who runs the console.");
    case "provider_timeout":
      return t("The transcription service didn't finish in time.");
    case "interrupted":
      return t("Transcription was interrupted.");
    case "recording_missing":
      return t("The audio did not fully reach the server, or it is no longer stored.");
    case "needs_transcription_key":
      return t("No transcription key is added yet.");
    // Contract 1.8.1-b: the provider refused the person's own key (the one vendor name people act on).
    case "own_key_rejected":
      return t("Groq did not accept your key. Check it in Account → Transcription key, then try again.");
    default:
      return t("Something went wrong while transcribing this recording.");
  }
}

/** Failures that trying again as it was cannot fix (D-130): nothing was heard, no key is added or the key was refused,
 * this month's limit is reached, the server has no transcription set up, or the audio is not (fully) on the server.
 * Each needs something else first, so they offer no Try again. */
const NOT_FIXED_BY_TRYING_AGAIN = ["no_audio", "needs_transcription_key", "own_key_rejected", "budget_exceeded", "provider_not_configured", "recording_missing"];

/** Failures fixed in Account → Transcription key (pure): no key yet, or the key was refused. */
export function needsKeyAction(code: string | null | undefined): boolean {
  return code === "needs_transcription_key" || code === "own_key_rejected";
}

/** Whether a failed transcription offers Try again (pure): yes for anything a second try can fix, an unknown code too. */
export function canTryAgain(code: string | null | undefined): boolean {
  return !NOT_FIXED_BY_TRYING_AGAIN.includes(code ?? "");
}
