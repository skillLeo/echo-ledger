// Live Assist (v1.9 L4) on the desktop: the typed seam between the screens and the core. The core owns the relay
// connection (docs/API_ASSIST.md 1.9.0-f: tokens, refresh, resume, pinning) and the REST calls; the screens only call
// these commands and listen to one event. Until the core has them, fixture.ts plays a session for development and tests
// (development builds only).
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
//   A start refused because another session runs (409 session_active, 1.9.0-k) comes as an `error` with that code,
//   or as assist_start's rejection carrying `code: "session_active"`.
import { invoke } from "@tauri-apps/api/core";
import { listen } from "@tauri-apps/api/event";

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
  | { type: "offer"; prep_id: string; title: string; client_ref: string; caller?: string | null; question?: string | null };

export type AssistSnapshot = { messages: AssistMessage[]; hint_level: HintLevel; paused: boolean; muted_until: string | null; meeting_type?: MeetingType | null };

export const assist = {
  preps: (status: PrepStatus | null = null) => invoke<Prep[]>("assist_preps_list", { status }),
  savePrep: (prep: PrepDraft) => invoke<Prep>("assist_prep_save", { prep }),
  deletePrep: (id: string) => invoke<void>("assist_prep_delete", { id }),
  addDocuments: (id: string, fileIds: string[]) => invoke<Prep>("assist_prep_documents_add", { id, fileIds }),
  removeDocument: (id: string, fileId: string) => invoke<Prep>("assist_prep_document_remove", { id, fileId }),
  /** `clientRef`: the recording an offer was for (the call already running), so the session joins it. */
  start: (prepId: string | null, mode: AssistMode | null = null, clientRef: string | null = null) =>
    invoke<AssistStarted>("assist_start", { prepId, mode, practice: false, clientRef }),
  schedule: (prepId: string, at: string) => invoke<Prep>("assist_schedule", { prepId, at }),
  end: () => invoke<void>("assist_end"),
  send: (kind: SendKind) => invoke<void>("assist_send", { kind }),
  set: (settings: { mode?: AssistMode; hint_level?: HintLevel; paused?: boolean; muted_seconds?: number }) => invoke<AssistSnapshot>("assist_set", { settings }),
  feedback: (suggestionId: string, action: "used" | "dismissed") => invoke<void>("assist_feedback", { suggestionId, action }),
  hideCard: () => invoke<void>("assist_card_hide"),
  state: () => invoke<AssistSnapshot | null>("assist_state"),
  listen: (cb: (m: AssistMessage) => void) => listen<AssistMessage>("assist:message", (e) => cb(e.payload)),
};
