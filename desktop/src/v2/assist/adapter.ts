// Live Assist (v1.9 L4) on the desktop: the typed seam between the screens and the core. The core owns the relay
// connection (docs/API_ASSIST.md 1.9.0-n: tokens, refresh, resume, pinning) and the REST calls; the screens only call
// these commands (lib/ipc.ts) and listen to one event. fixture.ts plays a session in development builds and tests.
//
// Commands (core):
//   assist_preps_list { status: string | null }           → Prep[]          GET /api/assist/preps?status=
//   assist_prep_save { prep: PrepDraft }                  → Prep            POST /api/assist/preps, or PATCH with prep.id
//   assist_prep_delete { id }                             → void            DELETE /api/assist/preps/{id}
//   assist_prep_documents_add { id, fileIds }             → Prep            POST /api/assist/preps/{id}/documents
//   assist_prep_document_remove { id, fileId }            → Prep            DELETE /api/assist/preps/{id}/documents/{fileId}
//   assist_start { prepId, mode, practice, clientRef }    → AssistStarted   recording (or the offered call's) + session + relay
//   assist_schedule { prepId, at }                        → Prep            PATCH scheduled_at/status, arming per L4
//   assist_end                                            → void            POST /api/assist/sessions/{id}/end, stop recording
//   assist_send { kind }                                  → void            help_now | regenerate | shorter | formal | translate | cancel
//   assist_set { settings: { mode?, hint_level?, paused?, muted_seconds? } }  → AssistSnapshot   context_update.settings
//   assist_feedback { suggestionId, action }              → void            POST …/suggestions/{id}/action (used | dismissed)
//   assist_card_hide                                      → void            hides the card window (the core keeps the session)
//   assist_state                                          → AssistSnapshot | null   for a window opened mid-session
// Event (core → windows):
//   assist:message  AssistMessage   every relay message the card needs, as the relay sends it (seq dropped), plus the
//                                   core's own `turn` (a caption line), `budget`, `ended` and `offer`
//   assist_start_replacing { sessionId, prepId, mode, practice, clientRef } → AssistStarted  ends the named running
//                                                         session and starts this one (409 session_active, 1.9.0-k)
//   assist_keep_running                                   → void            the person keeps the running session (409 asked)
//   assist_shown { suggestionId, renderMs }               → void            the first text of a suggestion was drawn
//   A start refused because another session runs comes as an `assist:message` of type `session_active`, or as
//   assist_start's rejection carrying the same fields with `code: "session_active"`.
import { api, errorMessage } from "../../lib/ipc";
import { listen } from "@tauri-apps/api/event";
import { wordsIn, type Lang } from "../../i18n";

export type MeetingType = "interview" | "boss" | "existing_client" | "new_client" | "general";
export type HintLevel = "keywords" | "outline" | "full";
export type AssistMode = "fast" | "balanced" | "deep" | "max";
export type LanguageHelp = "off" | "urdu" | "roman_urdu" | "arabic";
export type PrepStatus = "draft" | "scheduled" | "armed" | "done" | "cancelled";

export type PrepDocument = { file_id: string; name: string; tokens: number | null };

export type PrepDraft = {
  id?: string;
  meeting_type: MeetingType;
  title: string;
  counterpart: string;
  company: string;
  project_id: string | null;
  goals: string;
  done: string;
  not_done: string;
  to_discuss: string;
  instructions: string;
  hint_level: HintLevel;
  mode: AssistMode;
  language_help: LanguageHelp;
  scheduled_at: string | null;
};
export type Prep = PrepDraft & { id: string; status: PrepStatus; documents: PrepDocument[] };

export type AssistStarted = { session_id: string; auto_saved: boolean; mode: AssistMode | "captions_only"; allowed_modes: AssistMode[]; seconds_left: number | null };

export type SuggestionKind = "answer" | "help_now" | "regenerate" | "shorter" | "formal" | "translate" | "upgrade";
export type SendKind = "help_now" | "regenerate" | "shorter" | "formal" | "translate" | "cancel";
export type LiveMode = AssistMode | "captions_only";

/** What the card receives: the relay's messages it needs (1.9.0-f) and three of the core's own. */
export type AssistMessage =
  | { type: "ready"; session_id: string; mode: LiveMode; requested_mode: AssistMode; allowed_modes: AssistMode[]; resumed: boolean; practice: boolean; meeting_type?: MeetingType }
  | { type: "suggestion_start"; suggestion_id: string; turn_id: string | null; kind: SuggestionKind; speculative: boolean; replaces: string | null; mode: LiveMode; model: string; draft: boolean }
  | { type: "token"; suggestion_id: string; text: string }
  | { type: "suggestion_end"; suggestion_id: string; status: "done" | "cancelled" | "superseded" }
  | { type: "skip"; suggestion_id?: string | null; turn_id?: string | null }
  | { type: "mode_changed"; from?: LiveMode; to: LiveMode; reason: "budget" | "budget_restored" | "plan" | "assist_time" | "requested"; requested?: AssistMode | null }
  | { type: "error"; code: string; message: string; retry: boolean; suggestion_id: string | null; in_reply_to: number | null; fatal: boolean }
  | { type: "turn"; turn_id: string; channel: "me" | "other"; text: string; final: boolean }
  | { type: "budget"; seconds_left: number | null; seconds_total: number | null }
  | { type: "ended"; reason?: string; message?: string | null }
  /** The core's, for every prepared meeting's call (it never starts by itself): the card asks, and Start passes the
   * recording's client_ref back to assist_start. */
  | { type: "offer"; prep_id: string; title: string; client_ref: string; caller?: string | null; question?: string | null }
  /** The core's, for 409 session_active: the running session, its words and the button's label. */
  | { type: "session_active"; session_id: string; practice: boolean; text: string; action: string };

/** The running session a start ran into (409 session_active), as the core describes it. */
/** A running session (409 session_active): the core's own words where it sends them, null for the app's (sessionActiveWords). */
export type SessionActive = { session_id: string | null; practice: boolean; text: string | null; action: string | null };

export type AssistSnapshot = { messages: AssistMessage[]; hint_level: HintLevel; paused: boolean; muted_until: string | null; meeting_type?: MeetingType | null };

/** The running session on this computer, from the core's snapshot: the last `ready`'s id. */
export function activeSessionId(snapshot: { messages: { type: string; session_id?: string }[] } | null | undefined): string | null {
  const ready = [...(snapshot?.messages ?? [])].reverse().find((m) => m.type === "ready" && typeof m.session_id === "string");
  return ready?.session_id ?? null;
}

/** When the running session is not on this computer: it is ended where it runs. */
export const NOT_HERE = "The other session runs on another device. End it there, then start this one.";

/**
 * A refusal's sentence on the prep and the card: the core's own as it comes; this app's own (NOT_HERE) in the window's
 * language, or in `lang` (classic's English prep).
 */
export function failureText(e: unknown, lang?: Lang): string {
  const said = errorMessage(e);
  return said === NOT_HERE ? wordsIn(lang).t(NOT_HERE) : said;
}

/**
 * The consent line of the prompt pack (1.0.1), for boss, client and general meetings: the person says it to the other
 * side, so it is English in every language, as on the phone (its values-ur keeps it English, P-093).
 */
export const CONSENT_LINE = "Just so you know: I record this call and use an AI assistant that takes notes and suggests points to me. Is that okay with you?";

/** The core's commands (lib/ipc.ts), typed for the card and the prep. */
export const assist = {
  preps: (status: PrepStatus | null = null) => api.assistPrepsList(status) as unknown as Promise<Prep[]>,
  savePrep: (prep: PrepDraft) => api.assistPrepSave(prep as never) as unknown as Promise<Prep>,
  deletePrep: (id: string) => api.assistPrepDelete(id),
  addDocuments: (id: string, fileIds: string[]) => api.assistPrepDocumentsAdd(id, fileIds) as unknown as Promise<Prep>,
  removeDocument: (id: string, fileId: string) => api.assistPrepDocumentRemove(id, fileId) as unknown as Promise<Prep>,
  /** `clientRef`: the recording an offer was for (the call already running), so the session joins it. */
  start: (prepId: string | null, mode: AssistMode | null = null, clientRef: string | null = null) =>
    api.assistStart(prepId, mode, false, clientRef) as unknown as Promise<AssistStarted>,
  schedule: (prepId: string, at: string) => api.assistSchedule(prepId, at) as unknown as Promise<Prep>,
  end: () => api.assistEnd(),
  /**
   * Ends the running session (409 session_active) and starts this one. Without its id the core is asked which session
   * runs here (assist_state); a session that isn't on this computer is never ended by a guess.
   */
  startReplacing: async (sessionId: string | null, prepId: string | null, mode: AssistMode | null = null, practice = false, clientRef: string | null = null) => {
    const id = sessionId ?? activeSessionId(await api.assistState().catch(() => null));
    if (!id) throw new Error(NOT_HERE);
    return api.assistStartReplacing(id, prepId, mode, practice, clientRef) as unknown as Promise<AssistStarted>;
  },
  /** The answer to 409 session_active that keeps the running session (also when the card is closed while it asks). */
  keepRunning: () => api.assistKeepRunning(),
  send: (kind: SendKind) => api.assistSend(kind),
  set: (settings: { mode?: AssistMode; hint_level?: HintLevel; paused?: boolean; muted_seconds?: number }) =>
    api.assistSet(settings) as unknown as Promise<AssistSnapshot>,
  feedback: (suggestionId: string, action: "used" | "dismissed") => api.assistFeedback(suggestionId, action),
  /** When a suggestion's first text appeared, from its suggestion_start (1.9.0-n's "shown" report). */
  shown: (suggestionId: string, renderMs: number) => api.assistShown(suggestionId, renderMs),
  hideCard: () => api.assistCardHide(),
  state: () => api.assistState() as unknown as Promise<AssistSnapshot | null>,
  listen: (cb: (m: AssistMessage) => void) => listen<AssistMessage>("assist:message", (e) => cb(e.payload)),
};
