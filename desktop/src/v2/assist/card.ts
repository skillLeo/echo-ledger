// The Live Assist card's state from the relay's messages (docs/API_ASSIST.md 1.9.0-f), as a pure reducer:
// - a suggestion streams token by token onto the card;
// - one that ends `superseded` (a refusal after words were shown, a draft replaced by its upgrade, or the next
//   question taking the card) has its text cleared at that moment, before its replacement's first token;
// - `cancelled` (they kept talking) leaves the card as it was before it started;
// - a failure ends with an `error` naming the suggestion, never a `suggestion_end`;
// - `mode_changed` says why the mode changed; captions never stop.
import type { AssistMessage, AssistMode, LiveMode, MeetingType, SuggestionKind } from "./adapter";

export type Suggestion = {
  id: string;
  turnId: string | null;
  kind: SuggestionKind;
  replaces: string | null;
  draft: boolean;
  speculative: boolean;
  text: string;
  status: "streaming" | "done" | "cancelled" | "superseded" | "failed";
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
  error: { text: string; fatal: boolean; suggestionId: string | null } | null;
  /** The last other turn needed no reply. */
  skipped: boolean;
  budget: { left: number | null; total: number | null };
  ended: boolean;
};

export const EMPTY_CARD: CardState = {
  sessionId: null, meetingType: null, mode: "balanced", requestedMode: null, allowedModes: [], suggestions: [], shownId: null, turns: {},
  captions: [], modeNote: null, error: null, skipped: false, budget: { left: null, total: null }, ended: false,
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

export function cardReducer(state: CardState, m: AssistMessage): CardState {
  switch (m.type) {
    case "ready":
      return {
        ...state, sessionId: m.session_id, mode: m.mode, requestedMode: m.requested_mode, allowedModes: m.allowed_modes,
        meetingType: m.meeting_type ?? state.meetingType, ended: false, error: state.error?.fatal ? null : state.error,
      };
    case "suggestion_start": {
      const s: Suggestion = { id: m.suggestion_id, turnId: m.turn_id, kind: m.kind, replaces: m.replaces, draft: m.draft, speculative: m.speculative, text: "", status: "streaming" };
      return { ...state, suggestions: [...state.suggestions.slice(-19), s], shownId: m.suggestion_id, skipped: false, error: null };
    }
    case "token": {
      const s = state.suggestions.find((x) => x.id === m.suggestion_id);
      if (!s || s.status !== "streaming") return state;
      return patch(state, m.suggestion_id, (x) => ({ ...x, text: x.text + m.text }));
    }
    case "suggestion_end": {
      if (m.status === "superseded") return patch(state, m.suggestion_id, (x) => ({ ...x, text: "", status: "superseded" }));
      if (m.status === "cancelled") {
        const next = patch(state, m.suggestion_id, (x) => ({ ...x, status: "cancelled" }));
        return state.shownId === m.suggestion_id ? { ...next, shownId: previousShown(state, m.suggestion_id) } : next;
      }
      return patch(state, m.suggestion_id, (x) => ({ ...x, status: "done" }));
    }
    case "skip":
      return { ...state, skipped: true };
    case "mode_changed":
      return { ...state, mode: m.mode, requestedMode: m.requested ?? state.requestedMode, modeNote: modeNote(m.mode, m.reason) };
    case "error": {
      const next = m.suggestion_id ? patch(state, m.suggestion_id, (x) => ({ ...x, text: "", status: "failed" })) : state;
      return { ...next, error: { text: m.message, fatal: m.fatal, suggestionId: m.suggestion_id } };
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
