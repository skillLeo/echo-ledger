// The Live Assist card's state from the relay's messages (docs/API_ASSIST.md 1.9.0-f), as a pure reducer:
// - a suggestion streams token by token onto the card;
// - one named by a new start's `replaces` stays on the card until the replacement's first token, or its own
//   `superseded` end, whichever comes first: the card is never blank in between;
// - one that ends `superseded` (a refusal after words were shown, a draft replaced by its upgrade, or the next
//   question taking the card) has its text cleared at that moment, before its replacement's first token;
// - `cancelled` (they kept talking) leaves the card as it was before it started;
// - a failure ends with an `error` naming the suggestion, never a `suggestion_end`;
// - `mode_changed` says why the mode changed (its `to`); captions never stop.
// Since 1.9.0-n: a `skip` naming a suggestion ends it; `ready` with `resumed: true` clears a card still streaming;
// every error code gets the app's own plain sentence (errorWords); a `session_active` refusal offers to end the running
// session and start this one; the core's `offer` asks whether to start Live Assist for the call.
import type { AssistMessage, AssistMode, LiveMode, MeetingType, SessionActive, SuggestionKind } from "./adapter";

export type Suggestion = {
  id: string;
  turnId: string | null;
  kind: SuggestionKind;
  replaces: string | null;
  draft: boolean;
  speculative: boolean;
  text: string;
  status: "streaming" | "done" | "cancelled" | "superseded" | "failed" | "skipped";
};

export type CaptionLine = { turnId: string; channel: "me" | "other"; text: string; final: boolean };

export type CardState = {
  sessionId: string | null;
  meetingType: MeetingType | null;
  mode: LiveMode;
  requestedMode: AssistMode | null;
  allowedModes: AssistMode[];
  suggestions: Suggestion[];
  /** The suggestion on the card (the latest one that took it). */
  shownId: string | null;
  /** The other side's turns by id, for the question line. */
  turns: Record<string, string>;
  /** The captions strip: the last two lines. */
  captions: CaptionLine[];
  /** Why the mode changed, in words, until the next change. */
  modeNote: string | null;
  /** The last error the card shows (a failed suggestion or the session). */
  error: { text: string; fatal: boolean; suggestionId: string | null; code?: string } | null;
  /** The core offers Live Assist for this call (a matching prep). */
  offer: { prepId: string; title: string; clientRef: string; caller: string | null } | null;
  /** Another session runs (409 session_active): the card offers to end it and start this one. */
  sessionActive: SessionActive | null;
  /** What the last start asked for (the offer's prep and call), to ask again after ending the running session. */
  pendingStart: { prepId: string | null; clientRef: string | null } | null;
  /** The last other turn needed no reply. */
  skipped: boolean;
  budget: { left: number | null; total: number | null };
  ended: boolean;
};

export const EMPTY_CARD: CardState = {
  sessionId: null, meetingType: null, mode: "balanced", requestedMode: null, allowedModes: [], suggestions: [], shownId: null, turns: {},
  captions: [], modeNote: null, error: null, skipped: false, budget: { left: null, total: null }, ended: false,
  offer: null, sessionActive: null, pendingStart: null,
};

const MODE_WORDS: Record<LiveMode, string> = { fast: "Fast", balanced: "Balanced", deep: "Deep", max: "Max", captions_only: "Captions only" };
export const modeLabel = (mode: LiveMode) => MODE_WORDS[mode];

/** Why the mode changed, in words (never a code). */
export function modeNote(mode: LiveMode, reason: Extract<AssistMessage, { type: "mode_changed" }>["reason"]): string | null {
  if (reason === "requested") return null;
  if (mode === "captions_only") {
    return reason === "plan" ? "Live Assist isn't in your plan. Captions go on." : "This month's assist hours are used. Assist is paused; captions go on.";
  }
  switch (reason) {
    case "budget": return `Switched to ${MODE_WORDS[mode]} to stay within your assist hours.`;
    case "budget_restored": return `Back to ${MODE_WORDS[mode]}.`;
    case "plan": return `${MODE_WORDS[mode]} is the best mode your plan has.`;
    case "assist_time": return `Switched to ${MODE_WORDS[mode]}: this session is close to its time limit.`;
  }
}

const patch = (state: CardState, id: string, change: (s: Suggestion) => Suggestion): CardState => ({
  ...state,
  suggestions: state.suggestions.map((s) => (s.id === id ? change(s) : s)),
});

/** The suggestion the card falls back to when `id` goes without words: the one shown before it, if it still has text. */
function previousShown(state: CardState, id: string): string | null {
  const i = state.suggestions.findIndex((s) => s.id === id);
  for (let j = i - 1; j >= 0; j--) {
    const s = state.suggestions[j];
    if (s.text && (s.status === "done" || s.status === "streaming")) return s.id;
  }
  return null;
}

/**
 * The app's own sentence for a relay error code (1.9.0-n), never the code itself; null when the card says nothing
 * (an unknown message type, a token being renewed). Never the relay's text: its words are not the app's.
 */
export function errorWords(code: string, retry: boolean, _message = ""): string | null {
  switch (code) {
    case "budget_exhausted": return retry ? "Try again in a moment." : "Suggestions are off for this call.";
    case "provider_not_configured": return "Live Assist isn't set up on this server yet.";
    case "provider_unavailable":
    case "provider_timeout": return "That suggestion didn't come through. Try again in a moment.";
    case "model_declined": return "This one can't be answered here. Try Regenerate, or ask in other words.";
    case "nothing_to_rewrite": return "There's nothing on the card to rewrite yet.";
    case "documents_too_large": return "The documents are too large for this session. The earlier ones are still used.";
    case "rate_limited": return "Too many requests at once. Try again in a moment.";
    case "session_active": return "Another Live Assist session is running.";
    case "bad_message": return null;
    case "token_expired": return retry ? null : "Live Assist stopped: sign in again to start a new session.";
    default: return "Something went wrong with Live Assist.";
  }
}

export function cardReducer(state: CardState, m: AssistMessage): CardState {
  switch (m.type) {
    case "ready": {
      // Resumed: a suggestion that was streaming when the connection dropped gets no end; it leaves the card.
      const suggestions = m.resumed
        ? state.suggestions.map((s) => (s.status === "streaming" ? { ...s, text: "", status: "cancelled" as const } : s))
        : state.suggestions;
      const shownGone = m.resumed && state.suggestions.find((s) => s.id === state.shownId)?.status === "streaming";
      return {
        ...state, suggestions, shownId: shownGone ? null : state.shownId,
        sessionId: m.session_id, mode: m.mode, requestedMode: m.requested_mode, allowedModes: m.allowed_modes,
        meetingType: m.meeting_type ?? state.meetingType, ended: false, offer: null, sessionActive: null, pendingStart: null,
        error: state.error?.fatal ? null : state.error,
      };
    }
    case "session_active":
      return { ...state, sessionActive: sessionActiveOf(m) };
    case "offer":
      return { ...state, offer: { prepId: m.prep_id, title: m.title, clientRef: m.client_ref, caller: m.caller?.trim() || null }, ended: false };
    case "suggestion_start": {
      const s: Suggestion = { id: m.suggestion_id, turnId: m.turn_id, kind: m.kind, replaces: m.replaces, draft: m.draft, speculative: m.speculative, text: "", status: "streaming" };
      const current = state.suggestions.find((x) => x.id === state.shownId);
      // A replacement waits off the card while the one it replaces still has words on it.
      const waits = m.replaces !== null && m.replaces === state.shownId && !!current?.text;
      return { ...state, suggestions: [...state.suggestions.slice(-19), s], shownId: waits ? state.shownId : m.suggestion_id, skipped: false, error: null };
    }
    case "token": {
      const s = state.suggestions.find((x) => x.id === m.suggestion_id);
      if (!s || s.status !== "streaming") return state;
      const next = patch(state, m.suggestion_id, (x) => ({ ...x, text: x.text + m.text }));
      // Its first token takes the card from the suggestion it replaces.
      return s.replaces && state.shownId === s.replaces && !s.text ? { ...next, shownId: s.id } : next;
    }
    case "suggestion_end": {
      if (m.status === "superseded") {
        const next = patch(state, m.suggestion_id, (x) => ({ ...x, text: "", status: "superseded" }));
        if (state.shownId !== m.suggestion_id) return next;
        // Its replacement, if one has started, takes the card at once (still writing: never a blank card).
        const replacement = [...state.suggestions].reverse().find((x) => x.replaces === m.suggestion_id);
        return replacement ? { ...next, shownId: replacement.id } : next;
      }
      if (m.status === "cancelled") {
        const next = patch(state, m.suggestion_id, (x) => ({ ...x, status: "cancelled" }));
        return state.shownId === m.suggestion_id ? { ...next, shownId: previousShown(state, m.suggestion_id) } : next;
      }
      return patch(state, m.suggestion_id, (x) => ({ ...x, status: "done" }));
    }
    case "skip": {
      // No reply needed: the suggestion it names ends without words.
      if (!m.suggestion_id) return { ...state, skipped: true };
      const next = patch(state, m.suggestion_id, (x) => ({ ...x, text: "", status: "skipped" }));
      return { ...next, skipped: true, shownId: state.shownId === m.suggestion_id ? null : state.shownId };
    }
    case "mode_changed":
      return { ...state, mode: m.to, requestedMode: m.requested ?? state.requestedMode, modeNote: modeNote(m.to, m.reason) };
    case "error": {
      const next = m.suggestion_id ? patch(state, m.suggestion_id, (x) => ({ ...x, text: "", status: "failed" })) : state;
      if (m.code === "session_active") return { ...next, sessionActive: sessionActiveOf(m), error: null };
      const text = errorWords(m.code, m.retry, m.message);
      return text === null ? next : { ...next, error: { text, fatal: m.fatal, suggestionId: m.suggestion_id, code: m.code } };
    }
    case "turn": {
      const turns = m.channel === "other" ? { ...state.turns, [m.turn_id]: m.text } : state.turns;
      const rest = state.captions.filter((c) => c.turnId !== m.turn_id);
      return { ...state, turns, captions: [...rest, { turnId: m.turn_id, channel: m.channel, text: m.text, final: m.final }].slice(-2) };
    }
    case "budget":
      return { ...state, budget: { left: m.seconds_left, total: m.seconds_total } };
    case "ended":
      return { ...state, ended: true };
  }
}

/** The suggestion on the card, if any. */
export function shown(state: CardState): Suggestion | null {
  return state.suggestions.find((s) => s.id === state.shownId) ?? null;
}

export type ParsedSuggestion = {
  first: string;
  bullets: string[];
  sayNext: string | null;
  language: { label: "Urdu" | "Roman Urdu" | "Arabic"; text: string } | null;
};

/** The output contract's parts: the first line, up to three bullets, "Say next", and the language line. */
export function parseSuggestion(text: string): ParsedSuggestion {
  const lines = text.split("\n").map((l) => l.trim()).filter(Boolean);
  const out: ParsedSuggestion = { first: "", bullets: [], sayNext: null, language: null };
  for (const line of lines) {
    const lang = /^(Urdu|Roman Urdu|Arabic):\s*(.*)$/.exec(line);
    if (lang) out.language = { label: lang[1] as "Urdu" | "Roman Urdu" | "Arabic", text: lang[2] };
    else if (/^say next:/i.test(line)) out.sayNext = line.replace(/^say next:\s*/i, "");
    else if (/^[-•*]\s+/.test(line)) { if (out.bullets.length < 3) out.bullets.push(line.replace(/^[-•*]\s+/, "")); }
    else if (!out.first) out.first = line;
  }
  return out;
}

/** "**key** rest" as parts, the bold ones marked. */
export function boldParts(text: string): { text: string; bold: boolean }[] {
  return text.split(/(\*\*[^*]+\*\*)/).filter(Boolean).map((p) => (p.startsWith("**") && p.endsWith("**") ? { text: p.slice(2, -2), bold: true } : { text: p, bold: false }));
}

/** "Start Live Assist for Interview at Mehran Foods with Ayesha Siddiqui?" ("with …" only when the caller is known). */
export function offerQuestion(offer: { title: string; caller: string | null }): string {
  return `Start Live Assist for ${offer.title.trim() || "this call"}${offer.caller ? ` with ${offer.caller}` : ""}?`;
}

/** Whether a rejection is the server's 409 session_active (the core passes its code along). */
export function isSessionActive(e: unknown): boolean {
  const code = (e as { code?: unknown } | null)?.code;
  return code === "session_active" || (typeof e === "string" && /session_active/.test(e));
}

/** The core's description of the running session, with the app's words where it sends none. */
export function sessionActiveOf(e: unknown): SessionActive {
  const o = (e && typeof e === "object" ? e : {}) as Record<string, unknown>;
  const str = (v: unknown) => (typeof v === "string" && v.trim() ? v.trim() : null);
  return {
    session_id: str(o.session_id),
    practice: o.practice === true,
    text: str(o.text) ?? (o.practice === true ? "A practice session is running." : "Another Live Assist session is running."),
    action: str(o.action) ?? "End it and start this one",
  };
}
