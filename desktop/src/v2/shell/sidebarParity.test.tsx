// The new design's sidebar, Help and Home against classic (docs/v2/audit/parity.csv DT-NAV-010, DT-NAV-014, DT-NAV-015,
// DT-NAV-019, DT-NAV-025, DT-HLP-001, DT-HLP-004, DT-HOME-002 to DT-HOME-004, DT-LIB-061): the uploads line, the rail
// ("[" and its button), the edge that resizes it, "?" opening Help's Shortcuts, the recording chip, Home's needs-you
// rows with their ×, and the recording open when the main window closed.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import type { RecordingInfo } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus, makeTranscript } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { restartForTests } from "../../components/StateBanner";
import { Shell } from "./Shell";
import { shellChip } from "./status";
import { HomeV2 } from "../home/HomeV2";
import { sourceAppLabel } from "../../lib/format";
import { loadCollapsed } from "../../lib/shell";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const CALL: RecordingInfo = { clientRef: "r1", mode: "call", sourceApp: "zoom", clientId: null, startedAt: "", backend: "mock" };
const ready = { kind: "ready" as const, text: "Ready to record" };
const nav = () => screen.getByRole("navigation", { name: "Main" });

function backend(flags: Record<string, boolean>, status = makeStatus()) {
  respond("app_status", status);
  respond("library_list", [makeItem("a", { title: "Bilal Qureshi" }), makeItem("b", { title: "Weekly Zoom" })]);
  respond("library_refresh", [makeItem("a", { title: "Bilal Qureshi" }), makeItem("b", { title: "Weekly Zoom" })]);
  respond("get_settings", makeSettings());
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("transcript_detail", makeTranscript({ id: "tr-a" }));
  respond("help_guides", { data: [] });
  respond("help_whats_new", { data: [] });
  respond("ui_flags", { ui_flags: flags, ui_classic: false });
}
async function app() {
  render(<MainApp />);
  await act(flushAsync);
  await act(flushAsync);
  await screen.findByRole("navigation", { name: "Main" });
}

beforeEach(() => {
  // A wide window: the place's panel shows beside the page (under 1100 px it is a flyout from the rail).
  Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
  resetTauriMock();
  restartForTests();
  resetEntitlementsForTests(makeEntitlements());
  try {
    localStorage.clear();
  } catch {
    // no storage here
  }
});

describe("the sidebar", () => {
  it("DT-NAV-010: the foot says how many recordings are uploading; while the server holds them it says why, without the spinner", () => {
    const { rerender } = render(<Shell tab="library" onGo={() => undefined} status={ready} uploading="Uploading 2 recordings…"><p /></Shell>);
    const line = screen.getByTestId("sidebar-uploads");
    expect(line).toHaveTextContent("Uploading 2 recordings…");
    expect(line).toHaveAttribute("aria-live", "polite");
    expect(line.querySelector(".el-spinner")).not.toBeNull();
    rerender(<Shell tab="library" onGo={() => undefined} status={ready} uploading="2 recordings wait: the server is in maintenance" uploadsWait><p /></Shell>);
    expect(screen.getByTestId("sidebar-uploads")).toHaveTextContent("2 recordings wait: the server is in maintenance");
    expect(screen.getByTestId("sidebar-uploads").querySelector(".el-spinner")).toBeNull();
    rerender(<Shell tab="library" onGo={() => undefined} status={ready} uploading={null}><p /></Shell>);
    expect(screen.queryByTestId("sidebar-uploads")).toBeNull();
  });

  it("DT-NAV-010: in the app, the core's pending uploads show in the sidebar's foot", async () => {
    backend({ "ui_v2.shell": true }, makeStatus({ pendingUploads: 3 }));
    await app();
    expect(within(nav()).getByTestId("sidebar-uploads")).toHaveTextContent("Uploading 3 recordings…");
  });

  it("DT-NAV-014: Collapse sidebar folds it to its rail and back; the state is said and kept on this computer", async () => {
    backend({ "ui_v2.shell": true });
    await app();
    const collapse = within(nav()).getByRole("button", { name: "Collapse sidebar" });
    expect(collapse).toHaveAttribute("aria-expanded", "true");
    expect(collapse).toHaveAttribute("aria-keyshortcuts", "[");
    expect(nav()).toHaveAttribute("data-rail", "off");
    fireEvent.click(collapse);
    expect(nav()).toHaveAttribute("data-rail", "on");
    const expand = within(nav()).getByRole("button", { name: "Expand sidebar" });
    expect(expand).toHaveAttribute("aria-expanded", "false");
    expect(loadCollapsed()).toBe(true);
    fireEvent.click(expand);
    expect(nav()).toHaveAttribute("data-rail", "off");
    expect(loadCollapsed()).toBe(false);
  });

  it("DT-NAV-014: the [ key folds and unfolds it, never while typing in a field", async () => {
    backend({ "ui_v2.shell": true });
    await app();
    fireEvent.keyDown(document.body, { key: "[" });
    expect(nav()).toHaveAttribute("data-rail", "on");
    fireEvent.keyDown(document.body, { key: "[" });
    expect(nav()).toHaveAttribute("data-rail", "off");
    const field = document.createElement("input");
    document.body.appendChild(field);
    fireEvent.keyDown(field, { key: "[" });
    expect(nav()).toHaveAttribute("data-rail", "off");
    field.remove();
  });

  it("DT-NAV-014: a narrow window shows the rail by itself; Expand opens it there without changing the choice", async () => {
    const wide = window.innerWidth;
    Object.defineProperty(window, "innerWidth", { configurable: true, value: 760 });
    try {
      backend({ "ui_v2.shell": true });
      await app();
      expect(nav()).toHaveAttribute("data-rail", "on");
      fireEvent.click(within(nav()).getByRole("button", { name: "Expand sidebar" }));
      expect(nav()).toHaveAttribute("data-rail", "off");
      expect(localStorage.getItem("echoledger-sidebar")).toBeNull();
    } finally {
      Object.defineProperty(window, "innerWidth", { configurable: true, value: wide });
    }
  });

  it("DT-NAV-015: the edge resizes the sidebar from 200 to 320 px with the keys; a double press puts back 240; it is kept", async () => {
    backend({ "ui_v2.shell": true });
    await app();
    const edge = screen.getByRole("separator", { name: "Resize the sidebar" });
    const width = () => (document.querySelector(".el-shell") as HTMLElement).style.getPropertyValue("--el-sidebar-width");
    expect(edge).toHaveAttribute("aria-valuenow", "240");
    expect(width()).toBe("240px");
    fireEvent.keyDown(edge, { key: "ArrowRight" });
    expect(edge).toHaveAttribute("aria-valuenow", "256");
    fireEvent.keyDown(edge, { key: "ArrowRight", shiftKey: true });
    expect(edge).toHaveAttribute("aria-valuenow", "296");
    fireEvent.keyDown(edge, { key: "End" });
    expect(edge).toHaveAttribute("aria-valuenow", "320");
    fireEvent.keyDown(edge, { key: "ArrowRight" });
    expect(edge).toHaveAttribute("aria-valuenow", "320");
    fireEvent.keyDown(edge, { key: "Home" });
    expect(edge).toHaveAttribute("aria-valuenow", "200");
    expect(width()).toBe("200px");
    expect(localStorage.getItem("echoledger-sidebar-width")).toBe("200");
    fireEvent.doubleClick(edge);
    expect(edge).toHaveAttribute("aria-valuenow", "240");
    // The rail has no edge.
    fireEvent.click(within(nav()).getByRole("button", { name: "Collapse sidebar" }));
    expect(screen.queryByRole("separator", { name: "Resize the sidebar" })).toBeNull();
  });

  it("DT-NAV-015: dragging the edge captures the pointer, follows it within 200 to 320 px without redrawing the app, and keeps the width once let go", () => {
    const onWidth = vi.fn();
    render(<Shell tab="library" onGo={() => undefined} status={ready} width={240} onWidth={onWidth} rail={false}><p /></Shell>);
    const edge = screen.getByRole("separator", { name: "Resize the sidebar" });
    const capture = vi.fn();
    Object.assign(edge, { setPointerCapture: capture });
    const shell = document.querySelector<HTMLElement>(".el-shell")!;
    fireEvent.pointerDown(edge, { button: 0, clientX: 240, pointerId: 7 });
    expect(capture).toHaveBeenCalledWith(7);
    expect(document.body).toHaveClass("resizing-sidebar");
    fireEvent.pointerMove(edge, { clientX: 280 });
    expect(shell.style.getPropertyValue("--el-sidebar-width")).toBe("280px");
    expect(edge).toHaveAttribute("aria-valuenow", "280");
    fireEvent.pointerMove(edge, { clientX: 900 });
    expect(shell.style.getPropertyValue("--el-sidebar-width")).toBe("320px");
    // Nothing is told to the app while dragging: the width is set on the shell itself.
    expect(onWidth).not.toHaveBeenCalled();
    fireEvent.pointerUp(edge, { clientX: 900 });
    expect(onWidth.mock.calls).toEqual([[320, true]]);
    expect(document.body).not.toHaveClass("resizing-sidebar");
  });

  it("DT-NAV-015: a cancelled drag puts the width back and keeps nothing", () => {
    const onWidth = vi.fn();
    render(<Shell tab="library" onGo={() => undefined} status={ready} width={240} onWidth={onWidth} rail={false}><p /></Shell>);
    const edge = screen.getByRole("separator", { name: "Resize the sidebar" });
    const shell = document.querySelector<HTMLElement>(".el-shell")!;
    fireEvent.pointerDown(edge, { button: 0, clientX: 240 });
    fireEvent.pointerMove(edge, { clientX: 300 });
    expect(shell.style.getPropertyValue("--el-sidebar-width")).toBe("300px");
    fireEvent.pointerCancel(edge);
    expect(shell.style.getPropertyValue("--el-sidebar-width")).toBe("240px");
    expect(edge).toHaveAttribute("aria-valuenow", "240");
    expect(onWidth).not.toHaveBeenCalled();
    expect(document.body).not.toHaveClass("resizing-sidebar");
    // Later moves do nothing.
    fireEvent.pointerMove(edge, { clientX: 320 });
    expect(shell.style.getPropertyValue("--el-sidebar-width")).toBe("240px");
  });

  it("DT-NAV-025: while recording, the status chip says what, paused or not, and for how long; a press opens the Record page", () => {
    expect(shellChip(makeStatus({ recording: CALL, elapsedMs: 134_000 }), sourceAppLabel)).toEqual({ kind: "recording", text: "Recording · Zoom", time: "02:14" });
    expect(shellChip(makeStatus({ recording: { ...CALL, mode: "mic_note" }, paused: true, elapsedMs: 5_000 }), sourceAppLabel)).toMatchObject({ text: "Paused · voice note", time: "00:05" });
    expect(shellChip(makeStatus(), sourceAppLabel)).toEqual(ready);
    const onGo = vi.fn();
    const { rerender } = render(<Shell tab="library" onGo={onGo} status={{ kind: "recording", text: "Paused · Zoom", time: "02:14" }} recording><p /></Shell>);
    const chip = within(nav()).getByRole("button", { name: /^Paused · Zoom\s*· 02:14/ });
    // Only Recording / Paused and what is in the live region; the ticking time is outside it.
    const live = within(chip).getByRole("status");
    expect(live).toHaveTextContent(/^Paused · Zoom$/);
    rerender(<Shell tab="library" onGo={onGo} status={{ kind: "recording", text: "Paused · Zoom", time: "02:15" }} recording><p /></Shell>);
    expect(within(nav()).getByRole("button", { name: /· 02:15/ })).toBeInTheDocument();
    expect(within(nav()).getByRole("status")).toHaveTextContent(/^Paused · Zoom$/);
    expect(chip).toHaveAccessibleDescription("Opens the Record page");
    fireEvent.click(chip);
    expect(onGo).toHaveBeenCalledWith("record");
  });
});

describe("Help & shortcuts in the new design", () => {
  it("DT-NAV-019 and DT-HLP-001: the ? key opens Help on its Shortcuts tab: every key of this window, and the global ones as set", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.settings": true });
    respond("get_settings", { ...makeSettings(), shortcuts: { ...makeSettings().shortcuts, record_now: "CommandOrControl+Shift+R" } });
    await app();
    fireEvent.keyDown(document.body, { key: "?" });
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Help" })).toBeInTheDocument();
    expect(screen.queryByRole("dialog", { name: "Help & shortcuts" })).toBeNull();
    expect(screen.getByRole("tab", { name: "Shortcuts" })).toHaveAttribute("aria-selected", "true");
    const keys = screen.getByLabelText("In this window");
    expect(within(keys).getByText("Collapse or expand the sidebar")).toBeInTheDocument();
    expect(within(keys).getByText("[")).toBeInTheDocument();
    expect(within(keys).getByText("?")).toBeInTheDocument();
    expect(within(keys).getByText("Search this page's list")).toBeInTheDocument();
    const anywhere = screen.getByLabelText("Anywhere on this computer");
    const record = within(anywhere).getByText("Record now").closest("div")!;
    expect(record.querySelector("kbd")!.textContent).not.toBe("Not set");
    expect(within(anywhere).getByText("Bookmark this moment")).toBeInTheDocument();
  });

  it("DT-HLP-001: shortcuts that couldn't be read are said so, never shown as Not set", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.settings": true });
    await app();
    respondWith("get_settings", () => Promise.reject("The settings couldn't be read."));
    fireEvent.keyDown(document.body, { key: "?" });
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByTestId("shortcuts-unread")).toHaveTextContent("Your shortcuts couldn't be read.");
    expect(within(screen.getByLabelText("Anywhere on this computer")).queryByText("Not set")).toBeNull();
  });

  it("DT-NAV-019: Back from Help opened with ? returns to the page it was opened on", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.settings": true });
    await app();
    fireEvent.click(within(nav()).getByRole("button", { name: "Projects" }));
    await act(flushAsync);
    fireEvent.keyDown(document.body, { key: "?" });
    await act(flushAsync);
    await act(flushAsync);
    await screen.findByRole("heading", { level: 1, name: "Help" });
    const back = within(screen.getByRole("main")).getByRole("button", { name: "Projects" });
    expect(back).toHaveClass("el-page__back");
    fireEvent.click(back);
    await act(flushAsync);
    expect(within(nav()).getByRole("button", { name: "Projects" })).toHaveAttribute("aria-current", "page");
    expect(screen.queryByRole("heading", { level: 1, name: "Help" })).toBeNull();
  });

  it("DT-NAV-019: Help & shortcuts at the sidebar's foot opens the same tab", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.settings": true });
    await app();
    fireEvent.click(within(nav()).getByRole("button", { name: "Help & shortcuts" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("tab", { name: "Shortcuts" })).toHaveAttribute("aria-selected", "true");
  });

  it("DT-HLP-004: the screenshot and support tip, with Screen-share privacy one press away", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.settings": true });
    await app();
    fireEvent.keyDown(document.body, { key: "?" });
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByTestId("capture-help")).toHaveTextContent("only the live words (Live Assist, captions, translation) are hidden from them. For support, send Copy diagnostics");
    fireEvent.click(screen.getByRole("button", { name: "Open Screen-share privacy" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(screen.queryByTestId("capture-help")).toBeNull();
    expect(await screen.findByRole("heading", { level: 1, name: /Screen-share privacy/ })).toBeInTheDocument();
  });
});

describe("Home's needs you", () => {
  const props = () => ({
    onRecord: vi.fn(), onStop: vi.fn(), onSetup: vi.fn(), onReadyCheck: vi.fn(), onOpenRecording: vi.fn(), onLibrary: vi.fn(), onAction: vi.fn(), onPlan: vi.fn(),
  });
  beforeEach(() => {
    respond("ready_last", { ready: true, checkedAt: "2026-11-17T10:00:00Z", checks: [] });
    respond("permissions_status", { microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "macos" });
    respond("library_list", []);
    respond("skipped_list", []);
  });
  const list = () => within(screen.getByRole("list", { name: "Needs action" }));

  it("DT-HOME-002 and DT-HOME-003: the waiting calls and failed uploads open their views; × hides one until its count changes", async () => {
    const p = props();
    const { rerender } = render(<HomeV2 status={makeStatus({ platform: "macos", pendingDecisions: 2, failedUploads: 1 })} {...p} />);
    await act(flushAsync);
    fireEvent.click(list().getByRole("button", { name: "2 calls wait for your decision" }));
    fireEvent.click(list().getByRole("button", { name: "1 recording didn't upload" }));
    expect(p.onAction.mock.calls).toEqual([["waiting"], ["failed"]]);
    fireEvent.click(list().getByRole("button", { name: "Dismiss: 2 calls wait for your decision" }));
    expect(list().queryByRole("button", { name: "2 calls wait for your decision" })).toBeNull();
    expect(list().getByRole("button", { name: "1 recording didn't upload" })).toBeInTheDocument();
    // A third call waiting brings it back.
    rerender(<HomeV2 status={makeStatus({ platform: "macos", pendingDecisions: 3, failedUploads: 1 })} {...p} />);
    expect(list().getByRole("button", { name: "3 calls wait for your decision" })).toBeInTheDocument();
  });

  it("DT-HOME-004: N notifications need you opens Activity; its line says what they are; × hides it until the count changes", async () => {
    const p = props();
    const { rerender } = render(<HomeV2 status={makeStatus({ platform: "macos" })} {...p} notifications={2} />);
    await act(flushAsync);
    const row = list().getByRole("button", { name: "2 notifications need you" });
    expect(row).toHaveAccessibleDescription("A transcript that failed, the monthly limit, or an answer to your upgrade request.");
    fireEvent.click(row);
    expect(p.onAction).toHaveBeenCalledWith("notifications");
    fireEvent.click(list().getByRole("button", { name: "Dismiss: 2 notifications need you" }));
    expect(screen.queryByRole("button", { name: "2 notifications need you" })).toBeNull();
    rerender(<HomeV2 status={makeStatus({ platform: "macos" })} {...p} notifications={2} />);
    expect(screen.queryByRole("button", { name: "2 notifications need you" })).toBeNull();
    rerender(<HomeV2 status={makeStatus({ platform: "macos" })} {...p} notifications={3} />);
    expect(list().getByRole("button", { name: "3 notifications need you" })).toBeInTheDocument();
  });

  it("DT-HOME-003 in the app: the uploads that need you open the Library's Needs you (Failed uploads' home now)", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.home": true, "ui_v2.library": true }, makeStatus({ platform: "macos", failedUploads: 2 }));
    respond("pending_list", []);
    await app();
    fireEvent.click(within(nav()).getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Record Page" }));
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(await screen.findByRole("button", { name: "2 recordings didn't upload" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: /^Needs you/ })).toBeInTheDocument();
    expect(within(screen.getByTestId("nav-panel")).getByRole("button", { name: /^Needs you/ })).toHaveAttribute("aria-current", "page");
  });

  it("DT-HOME-002 to DT-HOME-004 in the app: Review opens the Library's Needs you (Waiting's home now), and the notifications open Activity", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.home": true, "ui_v2.library": true, "ui_v2.activity": true }, makeStatus({ platform: "macos", pendingDecisions: 1 }));
    respond("activity_list", { data: [{ id: "n1", kind: "transcript_failed", read: false, title: "A transcript failed", body: null, created_at: "2026-11-17T10:00:00Z", session_id: null }] });
    respond("pending_list", []);
    await app();
    fireEvent.click(within(nav()).getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Record Page" }));
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(await screen.findByRole("button", { name: "1 call waits for your decision" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: /^Needs you/ })).toBeInTheDocument();
    expect(within(screen.getByTestId("nav-panel")).getByRole("button", { name: /^Needs you/ })).toHaveAttribute("aria-current", "page");
    fireEvent.click(within(nav()).getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Record Page" }));
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(await screen.findByRole("button", { name: "1 notification needs you" }));
    await act(flushAsync);
    expect(within(nav()).getByRole("button", { name: /^Activity/ })).toHaveAttribute("aria-current", "page");
  });
});

describe("the Library after the main window opens again", () => {
  it("DT-LIB-061: the recording open when it closed opens again; Back is kept, so the next window opens the list", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.library": true });
    respond("main_view_get", JSON.stringify({ tab: "library", recordingId: "a", scroll: {} }));
    respond("main_view_set", undefined);
    await app();
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Bilal Qureshi" })).toBeInTheDocument();
    fireEvent.click(document.querySelector<HTMLButtonElement>(".el-page__back")!);
    await act(async () => {
      await new Promise((r) => setTimeout(r, 300));
    });
    const saved = callsOf("main_view_set").map((c) => JSON.parse((c as { view: string }).view));
    expect(saved.at(-1)).toMatchObject({ recordingId: null });
    cleanup();
  });

  it("DT-LIB-061: a kept recording that was deleted since doesn't open, and isn't kept any more", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.library": true });
    respond("main_view_get", JSON.stringify({ tab: "library", recordingId: "gone", scroll: {} }));
    respond("main_view_set", undefined);
    await app();
    await act(async () => {
      await new Promise((r) => setTimeout(r, 300));
    });
    // The list, on All.
    expect(screen.getByRole("heading", { level: 1, name: /^All/ })).toBeInTheDocument();
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
    const saved = callsOf("main_view_set").map((c) => JSON.parse((c as { view: string }).view));
    expect(saved.at(-1)).toMatchObject({ recordingId: null });
  });

  it("DT-LIB-061: opening another recording is kept for the next window", async () => {
    backend({ "ui_v2.shell": true, "ui_v2.library": true });
    respond("main_view_get", null);
    respond("main_view_set", undefined);
    await app();
    fireEvent.click(await screen.findByRole("button", { name: "Weekly Zoom" }));
    await act(async () => {
      await new Promise((r) => setTimeout(r, 300));
    });
    const saved = callsOf("main_view_set").map((c) => JSON.parse((c as { view: string }).view));
    expect(saved.at(-1)).toMatchObject({ recordingId: "b" });
  });
});
