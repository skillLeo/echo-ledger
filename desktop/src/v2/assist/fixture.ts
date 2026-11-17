// A sample Live Assist session for development builds and tests; a release build never loads it (AssistApp imports
// it behind an inline import.meta.env.DEV check, and release.test.ts checks the output).
import type { AssistMessage } from "./adapter";

/**
 * A short interview session for development and tests: a question, a balanced draft replaced by its upgrade, then a
 * streamed answer refused after its first words and replaced by the fallback (1.9.0-f).
 */
export const FIXTURE_SESSION: readonly AssistMessage[] = [
  { type: "ready", session_id: "as-1", mode: "balanced", requested_mode: "balanced", allowed_modes: ["balanced", "fast"], resumed: false, practice: false, meeting_type: "interview" },
  { type: "turn", turn_id: "t1", channel: "other", text: "Tell me about a time you handled a difficult client.", final: true },
  { type: "suggestion_start", suggestion_id: "s1", turn_id: "t1", kind: "answer", speculative: false, replaces: null, mode: "balanced", model: "haiku", draft: true },
  { type: "token", suggestion_id: "s1", text: "Use the Qureshi Textiles payment dispute" },
  { type: "suggestion_end", suggestion_id: "s1", status: "superseded" },
  { type: "suggestion_start", suggestion_id: "s2", turn_id: "t1", kind: "upgrade", speculative: false, replaces: "s1", mode: "balanced", model: "sonnet", draft: false },
  { type: "token", suggestion_id: "s2", text: "Talk about the Qureshi Textiles payment dispute you settled in two weeks.\n" },
  { type: "token", suggestion_id: "s2", text: "- **Listened first**: called the owner, not just emailed\n- **Split the issue**: price vs. delivery date\n- **Result**: kept the account, 40% advance agreed\n" },
  { type: "token", suggestion_id: "s2", text: "Say next: Would you like the numbers from that quarter?\nRoman Urdu: Woh poochh rahe hain ke mushkil client ko kaise sambhala." },
  { type: "suggestion_end", suggestion_id: "s2", status: "done" },
  { type: "turn", turn_id: "t2", channel: "me", text: "Sure, it was with a textile exporter in Faisalabad.", final: true },
];

/** Plays `messages` to `cb`, one every `stepMs` (0: all at once); returns a stop function. */
export function fixtureStream(cb: (m: AssistMessage) => void, messages: readonly AssistMessage[] = FIXTURE_SESSION, stepMs = 400): () => void {
  if (stepMs <= 0) {
    messages.forEach(cb);
    return () => undefined;
  }
  let i = 0;
  const timer = setInterval(() => {
    if (i >= messages.length) return clearInterval(timer);
    cb(messages[i++]);
  }, stepMs);
  return () => clearInterval(timer);
}
