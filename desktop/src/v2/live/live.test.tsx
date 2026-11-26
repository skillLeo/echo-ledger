import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import type { Prep } from "../assist/adapter";
import { PrepScreen, TEMPLATES, newDraft, withType } from "../assist/PrepScreen";
import { CONSENT_LINE, INTERVIEW_NOTE } from "../assist/AssistCard";
import { LiveV2 } from "./LiveV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PREP: Prep = {
  ...newDraft("existing_client"), id: "pr1", title: "March order with Qureshi Textiles", counterpart: "Bilal Qureshi", company: "Qureshi Textiles",
  project_id: "c1", status: "scheduled", scheduled_at: "2026-11-18T05:00:00Z", documents: [],
};

beforeEach(() => {
  resetTauriMock();
  respond("get_settings", makeSettings());
});

describe("prep templates", () => {
  it("bring the type's brief in, never over what the person wrote", () => {
    const d = newDraft("interview");
    expect(d.hint_level).toBe("outline");
    const client = withType(d, "existing_client");
    expect(client.goals).toBe(TEMPLATES.existing_client.goals);
    expect(client.hint_level).toBe("full");
    const own = withType({ ...d, goals: "Talk about the Lahore launch." }, "boss");
    expect(own.goals).toBe("Talk about the Lahore launch.");
  });
});

describe("the prep screen", () => {
  const show = () => render(<PrepScreen projects={[{ id: "c1", name: "Qureshi Textiles" }]} onBack={vi.fn()} onStarted={vi.fn()} />);

  it("asks for the meeting, documents, brief and how it helps; an interview says to check AI help is allowed", () => {
    show();
    expect(screen.getByRole("heading", { level: 1, name: "New Live Assist session" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: /Meeting type/ })).toHaveAccessibleName("Meeting type Interview");
    for (const name of ["Goals", "Done so far", "Not done yet", "To discuss", "Instructions for the assistant"]) expect(screen.getByRole("textbox", { name })).toBeInTheDocument();
    expect(screen.getByRole("radio", { name: "Outline" })).toHaveAttribute("aria-checked", "true");
    expect(screen.getByRole("note")).toHaveTextContent(INTERVIEW_NOTE);
  });

  it("a client meeting shows the consent line with Copy", () => {
    show();
    fireEvent.click(screen.getByRole("combobox", { name: /Meeting type/ }));
    fireEvent.click(screen.getByRole("option", { name: "Existing client" }));
    expect(screen.getByRole("note")).toHaveTextContent(CONSENT_LINE);
    expect(within(screen.getByRole("note")).getByRole("button", { name: "Copy" })).toBeInTheDocument();
  });

  it("documents come from the linked project; ticking one saves the prep and attaches it", async () => {
    respond("client_workspace", { data: { client: { id: "c1", name: "Qureshi Textiles", ai_notes: true }, ai: { configured: true, spent_usd: 0, budget_usd: 0, client_spent_usd: 0 }, brief: null, memory: [], notes: [],
      files: [{ id: "f1", name: "Proforma March.pdf", mime: "application/pdf", size_bytes: 1000, pages: 3, status: "ready", error: null, instructions: false }] } });
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    respond("assist_prep_documents_add", { ...PREP, id: "pr9", documents: [{ file_id: "f1", name: "Proforma March.pdf", tokens: 1200 }] });
    show();
    fireEvent.click(screen.getByRole("combobox", { name: /Project/ }));
    fireEvent.click(screen.getByRole("option", { name: "Qureshi Textiles" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("checkbox", { name: "Proforma March.pdf" }));
    await act(flushAsync);
    expect(callsOf("assist_prep_save")).toHaveLength(1);
    expect(callsOf("assist_prep_documents_add")).toEqual([{ id: "pr9", fileIds: ["f1"] }]);
    expect(screen.getByRole("checkbox", { name: "Proforma March.pdf" })).toBeChecked();
  });

  it("Start now saves the prep and starts the session in its mode", async () => {
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    respond("assist_start", { session_id: "as-1", auto_saved: true, mode: "balanced", allowed_modes: ["balanced", "fast"], seconds_left: 31200 });
    const onStarted = vi.fn();
    render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={onStarted} />);
    fireEvent.change(screen.getByRole("textbox", { name: "Title" }), { target: { value: "Interview at Mehran Foods" } });
    fireEvent.click(screen.getByRole("button", { name: "Start now" }));
    await act(flushAsync);
    expect(callsOf("assist_prep_save")[0]).toMatchObject({ prep: { title: "Interview at Mehran Foods", meeting_type: "interview" } });
    expect(callsOf("assist_start")).toEqual([{ prepId: "pr9", mode: "balanced", practice: false, clientRef: null }]);
    expect(onStarted).toHaveBeenCalled();
  });

  it("a refusal is said in place (a mode the plan hasn't)", async () => {
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    fail("assist_start", "Deep is in the Ultra plan.");
    render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "Start now" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("Deep is in the Ultra plan.");
  });
});

describe("the prep per contract 1.9.0-n", () => {
  const WORKSPACE = { data: { client: { id: "c1", name: "Qureshi Textiles", ai_notes: true }, ai: { configured: true, spent_usd: 0, budget_usd: 0, client_spent_usd: 0 }, brief: null, memory: [], notes: [],
    files: [{ id: "f1", name: "Proforma March.pdf", mime: "application/pdf", size_bytes: 1000, pages: 3, status: "ready", error: null, instructions: false }] } };
  const pickProject = async () => {
    fireEvent.click(screen.getByRole("combobox", { name: /Project/ }));
    fireEvent.click(screen.getByRole("option", { name: "Qureshi Textiles" }));
    await act(flushAsync);
  };

  it("documents_too_large from attaching is said on the prep; the earlier documents stay", async () => {
    respond("client_workspace", WORKSPACE);
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    respondWith("assist_prep_documents_add", () => { throw { code: "documents_too_large", message: "too large" }; });
    render(<PrepScreen projects={[{ id: "c1", name: "Qureshi Textiles" }]} onBack={vi.fn()} onStarted={vi.fn()} />);
    await pickProject();
    fireEvent.click(screen.getByRole("checkbox", { name: "Proforma March.pdf" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("The documents are too large for this session. The earlier ones are still used.");
  });

  it("documents_too_large from the relay is said on the prep too", async () => {
    respond("client_workspace", WORKSPACE);
    render(<PrepScreen projects={[{ id: "c1", name: "Qureshi Textiles" }]} onBack={vi.fn()} onStarted={vi.fn()} />);
    await pickProject();
    await act(() => emitEvent("assist:message", { type: "error", code: "documents_too_large", message: "x", retry: false, suggestion_id: null, in_reply_to: null, fatal: false }));
    expect(screen.getByRole("alert")).toHaveTextContent("The documents are too large for this session.");
  });

  it("409 session_active on Start now offers to end the running session and start this one", async () => {
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    respondWith("assist_start", () => {
      throw { code: "session_active", session_id: "as-0", practice: true, text: "Your practice session is still running.", action: "End practice and start" };
    });
    respond("assist_start_replacing", { session_id: "as-2", auto_saved: true, mode: "balanced", allowed_modes: ["balanced"], seconds_left: 3600, practice: false });
    const onStarted = vi.fn();
    render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={onStarted} />);
    fireEvent.click(screen.getByRole("button", { name: "Start now" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("Your practice session is still running.");
    expect(onStarted).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "End practice and start" }));
    await act(flushAsync);
    expect(callsOf("assist_start_replacing")).toEqual([{ sessionId: "as-0", prepId: "pr9", mode: "balanced", practice: false, clientRef: null }]);
    expect(onStarted).toHaveBeenCalled();
  });

  it("Keep running on the prep keeps the running session", async () => {
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    respondWith("assist_start", () => { throw { code: "session_active", session_id: "as-0", practice: false, text: "Another Live Assist session is running.", action: "End it and start this one" }; });
    respond("assist_keep_running", null);
    render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "Start now" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Keep running" }));
    await act(flushAsync);
    expect(callsOf("assist_keep_running")).toHaveLength(1);
    expect(screen.queryByRole("alert")).toBeNull();
  });
});

describe("ending the running session from the prep", () => {
  it("asks the core which session runs here when the refusal names none; never ends a guess", async () => {
    const { activeSessionId, NOT_HERE } = await import("../assist/adapter");
    expect(activeSessionId({ messages: [{ type: "ready", session_id: "as-1" }, { type: "turn" }, { type: "ready", session_id: "as-2" }] })).toBe("as-2");
    expect(activeSessionId(null)).toBeNull();

    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    respondWith("assist_start", () => { throw { code: "session_active", practice: false, text: "Another Live Assist session is running.", action: "End it and start this one" }; });
    respond("assist_state", { messages: [{ type: "ready", session_id: "as-local", mode: "fast", requested_mode: "fast", allowed_modes: ["fast"], resumed: false, practice: false }], hint_level: "full", paused: false, muted_until: null, meeting_type: null });
    respond("assist_start_replacing", { session_id: "as-2", auto_saved: true, mode: "balanced", allowed_modes: ["balanced"], seconds_left: 3600, practice: false });
    const onStarted = vi.fn();
    const { unmount } = render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={onStarted} />);
    fireEvent.click(screen.getByRole("button", { name: "Start now" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "End it and start this one" }));
    await act(flushAsync);
    expect(callsOf("assist_start_replacing")).toEqual([{ sessionId: "as-local", prepId: "pr9", mode: "balanced", practice: false, clientRef: null }]);
    expect(callsOf("assist_end")).toHaveLength(0);
    unmount();

    resetTauriMock();
    respond("get_settings", makeSettings());
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    respondWith("assist_start", () => { throw { code: "session_active", practice: false, text: "Another Live Assist session is running.", action: "End it and start this one" }; });
    respond("assist_state", null);
    render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "Start now" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "End it and start this one" }));
    await act(flushAsync);
    expect(callsOf("assist_start_replacing")).toHaveLength(0);
    expect(callsOf("assist_end")).toHaveLength(0);
    expect(screen.getByRole("alert")).toHaveTextContent(NOT_HERE);
  });

  it("leaving the prep while it asks keeps the running session", async () => {
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    respondWith("assist_start", () => { throw { code: "session_active", session_id: "as-0", practice: false, text: "Another Live Assist session is running.", action: "End it and start this one" }; });
    respond("assist_keep_running", null);
    const { unmount } = render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "Start now" }));
    await act(flushAsync);
    unmount();
    await flushAsync();
    expect(callsOf("assist_keep_running")).toHaveLength(1);
  });

  it("leaving the prep after the question was answered keeps nothing extra", async () => {
    respond("assist_keep_running", null);
    const { unmount } = render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={vi.fn()} />);
    unmount();
    await flushAsync();
    expect(callsOf("assist_keep_running")).toHaveLength(0);
  });
});

describe("the Live tab", () => {
  const props = () => ({ onRecord: vi.fn(), onVoiceNote: vi.fn(), onCaptions: vi.fn(), onSettings: vi.fn(), run: <T,>(fn: () => Promise<T>) => fn() });

  it("has Record, Live Assist (when on), Live Translate, Dictation and Captions", async () => {
    respond("assist_preps_list", [PREP]);
    const p = props();
    respond("translate_apps", [{ pid: 4242, name: "Valorant" }]);
    respond("translate_status", null);
    render(<LiveV2 status={makeStatus({ plan: { ...makeStatus().plan, liveTranslate: true } })} assistOn projects={[]} {...p} />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Record" }));
    fireEvent.click(screen.getByRole("button", { name: "Voice note" }));
    expect(p.onRecord).toHaveBeenCalled();
    expect(p.onVoiceNote).toHaveBeenCalled();
    expect(callsOf("assist_preps_list")).toEqual([{ status: "scheduled" }]);
    const upcoming = screen.getByRole("list", { name: "Upcoming sessions" });
    fireEvent.click(within(upcoming).getByRole("button", { name: "March order with Qureshi Textiles" }));
    expect(screen.getByRole("heading", { level: 1, name: "Edit prep" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Live" }));
    await act(flushAsync);
    const during = screen.getByRole("list", { name: "During a call" });
    fireEvent.click(within(during).getByRole("button", { name: "Live Translate" }));
    await act(flushAsync);
    expect(screen.getByRole("region", { name: "Live Translate" })).toBeInTheDocument();
    fireEvent.click(within(during).getByRole("button", { name: "Dictation" }));
    expect(p.onSettings).toHaveBeenCalledWith("voice");
    fireEvent.click(within(during).getByRole("button", { name: "Captions" }));
    expect(p.onSettings).toHaveBeenCalledWith("captions");
  });

  it("Auto-record calls is a switch on the Live tab, as on the phone (UX-15), with how calls stand; Calls & meetings opens its settings", async () => {
    const p = props();
    const onAutoRecord = vi.fn();
    const { rerender } = render(<LiveV2 status={makeStatus({ autoMode: true, callsReady: true })} assistOn={false} projects={[]} {...p} onAutoRecord={onAutoRecord} />);
    await act(flushAsync);
    const recording = screen.getByRole("list", { name: "Recording" });
    const sw = within(recording).getByRole("switch", { name: "Auto-record calls" });
    expect(sw).toBeChecked();
    expect(within(recording).getByText("Calls: auto-record on")).toBeInTheDocument();
    fireEvent.click(sw);
    expect(onAutoRecord).toHaveBeenCalledTimes(1);
    rerender(<LiveV2 status={makeStatus({ autoMode: false, callsReady: true })} assistOn={false} projects={[]} {...p} onAutoRecord={onAutoRecord} />);
    expect(within(recording).getByRole("switch", { name: "Auto-record calls" })).not.toBeChecked();
    expect(within(recording).getByText("Calls: ready")).toBeInTheDocument();
    // The Ready check found a problem: set up first (the switch still says what it is).
    rerender(<LiveV2 status={makeStatus({ autoMode: true, callsReady: false })} assistOn={false} projects={[]} {...p} onAutoRecord={onAutoRecord} />);
    expect(within(recording).getByText("Calls: set up first")).toBeInTheDocument();
    fireEvent.click(within(recording).getByRole("button", { name: "Calls & meetings" }));
    expect(p.onSettings).toHaveBeenCalledWith("calls");
    // A role that can't record: the switch shows the setting, greyed.
    rerender(<LiveV2 status={makeStatus({ autoMode: true, callsReady: true })} assistOn={false} projects={[]} {...p} onAutoRecord={undefined} />);
    expect(within(recording).getByRole("switch", { name: "Auto-record calls" })).toBeDisabled();
  });

  it("without live_assist there is no Live Assist; while recording, captions turn on in place", async () => {
    const p = props();
    render(<LiveV2 status={makeStatus({ recording: { clientRef: "r", mode: "call", sourceApp: "zoom", clientId: null, startedAt: "", backend: "mock" }, captionsLive: false })} assistOn={false} projects={[]} {...p} />);
    await act(flushAsync);
    expect(screen.queryByRole("region", { name: "Live Assist" })).toBeNull();
    expect(callsOf("assist_preps_list")).toHaveLength(0);
    fireEvent.click(screen.getByRole("button", { name: "Turn on" }));
    expect(p.onCaptions).toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Stop and save" })).toBeInTheDocument();
  });
});

describe("the Live tab's and the prep's states", () => {
  const props = () => ({ onRecord: vi.fn(), onVoiceNote: vi.fn(), onCaptions: vi.fn(), onSettings: vi.fn(), run: <T,>(fn: () => Promise<T>) => fn() });

  it("sessions: loading, then an error with Try again, never a false 'none scheduled'", async () => {
    let answer: (v: unknown) => void = () => undefined;
    const { respondWith } = await import("../../test/tauri");
    respondWith("assist_preps_list", () => new Promise((r) => (answer = r)));
    render(<LiveV2 status={makeStatus()} assistOn projects={[]} {...props()} />);
    await act(flushAsync);
    expect(screen.getByRole("status", { busy: true })).toHaveTextContent("Loading sessions");
    expect(screen.queryByText("No sessions scheduled")).toBeNull();
    await act(async () => answer([]));
    expect(screen.getByRole("button", { name: "No sessions scheduled" })).toBeInTheDocument();
  });

  it("sessions that fail to load say so", async () => {
    fail("assist_preps_list", "offline");
    render(<LiveV2 status={makeStatus()} assistOn projects={[]} {...props()} />);
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("Sessions didn't load");
    expect(screen.queryByText("No sessions scheduled")).toBeNull();
  });

  it("the prep's documents: loading, and an error that doesn't claim the project is empty", async () => {
    fail("client_workspace", "offline");
    render(<PrepScreen projects={[{ id: "c1", name: "Qureshi Textiles" }]} onBack={vi.fn()} onStarted={vi.fn()} />);
    fireEvent.click(screen.getByRole("combobox", { name: /Project/ }));
    fireEvent.click(screen.getByRole("option", { name: "Qureshi Textiles" }));
    expect(screen.getByRole("status", { busy: true })).toHaveTextContent("Reading the project's files");
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("The project's files didn't load");
    expect(screen.queryByText("No files in this project")).toBeNull();
  });
});

describe("the app with ui_v2.live", () => {
  it("the shell's Live opens the Live tab", async () => {
    respond("app_status", makeStatus());
    respond("library_list", [makeItem("a")]);
    respond("library_refresh", [makeItem("a")]);
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.live": true } });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "Live" }));
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Live" })).toBeInTheDocument();
    expect(screen.queryByRole("region", { name: "Live Assist" })).toBeNull();
  });

  it("the Live tab's Auto-record calls turns call recording off and on, as Record's menu does (UX-15)", async () => {
    respond("app_status", makeStatus({ autoMode: true }));
    respond("library_list", [makeItem("a")]);
    respond("library_refresh", [makeItem("a")]);
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respondWith("update_settings", (args) => ({ ...makeSettings(), ...(args as { patch: object }).patch }));
    // Every Auto-record control goes through one core command (lead decision 29: the account's After each call while its
    // call rules are kept, this computer's own switch otherwise), as the tray does.
    respondWith("auto_record_set", (args) => makeStatus({ autoMode: (args as { on: boolean }).on }));
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.live": true } });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "Live" }));
    await act(flushAsync);
    fireEvent.click(await screen.findByRole("switch", { name: "Auto-record calls" }));
    await act(flushAsync);
    expect(callsOf("auto_record_set").at(-1)).toEqual({ on: false });
    expect(await screen.findByText("Call recording is off")).toBeInTheDocument();
  });
});
