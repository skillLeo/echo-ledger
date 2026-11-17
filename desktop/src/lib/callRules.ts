// Call rules (docs/PLAN_CALL_RULES.md 1-2): the four choices with one set of words everywhere, the sources a rule can
// name on the desktop, and contact names as the core compares them (src-tauri/src/call_rules.rs).
import type { AfterCall, CallChoice } from "./ipc";

/** The words of each choice, the same in every place. */
export const CHOICE_WORDS: Record<CallChoice, string> = {
  transcribe: "Transcribe",
  keep_audio: "Keep audio",
  dont_record: "Don't record",
  ask: "Ask me",
};

/** What each choice does, in one line. */
export const CHOICE_HINTS: Record<CallChoice, string> = {
  transcribe: "Keep the audio and transcribe it (uses minutes).",
  keep_audio: "Save the recording only. No transcript, no cost. You can transcribe it later.",
  dont_record: "The call is never recorded. Nothing is saved.",
  ask: "A small card asks after the call. Doing nothing keeps the audio.",
};

/** The global default (Settings › After a call) as a choice. */
export const AFTER_CALL_CHOICE: Record<AfterCall, CallChoice> = {
  ask: "ask",
  always_audio: "keep_audio",
  always_transcribe: "transcribe",
  dont_record: "dont_record",
};

/** The sources a rule can name on the desktop, as the core keys them. */
export const RULE_SOURCES: readonly (readonly [string, string, string])[] = [
  ["zoom", "Zoom", "zoom"],
  ["meet", "Google Meet", "google_meet"],
  ["teams", "Microsoft Teams", "microsoft_teams"],
  ["slack", "Slack", "slack"],
  ["whatsapp", "WhatsApp desktop", "whatsapp"],
];

/** "" is "Same as the default": the source has no rule of its own. */
export const SOURCE_OPTIONS: readonly (readonly [CallChoice | "", string])[] = [
  ["", "Same as the default"], ["ask", "Ask me"], ["transcribe", "Transcribe"], ["keep_audio", "Keep audio"], ["dont_record", "Don't record"],
];

/** A contact's choices: Ask me follows the default, so it is the same as no rule. */
export const CONTACT_OPTIONS: readonly (readonly [CallChoice, string])[] = [
  ["transcribe", "Always transcribe"], ["keep_audio", "Keep audio only"], ["dont_record", "Don't record"], ["ask", "Ask me (follow the default)"],
];

/** A contact's rule key, as the core compares names: no case, single spaces. */
export function contactKey(name: string): string {
  return name.trim().split(/\s+/).filter(Boolean).join(" ").toLowerCase();
}

/** A source's rules with one changed ("" removes it). */
export function withSourceRule(rules: Record<string, CallChoice>, source: string, choice: CallChoice | ""): Record<string, CallChoice> {
  const next = { ...rules };
  if (choice) next[source] = choice;
  else delete next[source];
  return next;
}

/** The contacts' rules with one set; Ask me removes it (it follows the default). */
export function withContactRule(rules: Record<string, CallChoice>, name: string, choice: CallChoice): Record<string, CallChoice> {
  const key = contactKey(name);
  const next = { ...rules };
  if (!key) return next;
  if (choice === "ask") delete next[key];
  else next[key] = choice;
  return next;
}

/** "Mum", "ahmed khan" → "Ahmed Khan": names are kept in lower case, shown with capitals. */
export function contactLabel(key: string): string {
  return key.replace(/(^|\s)(\p{L})/gu, (_, space: string, letter: string) => space + letter.toUpperCase());
}
