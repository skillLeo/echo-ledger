// The new design against classic (docs/v2/audit/parity.csv, desktop batch 2): Try again in the same language, rename by
// clicking the title, the sync error banner with Retry, syncing every 5 s while something is under way, a row's part,
// Density in the Library's menu, the viewer's notice on Home and the super admin's Admin panel.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../test/tauri";
import { makeItem, makePlan, makeStatus, makeTranscript } from "../test/fixtures";
import { resetEntitlementsForTests } from "../lib/entitlements";
import { loadPreferences } from "../lib/shell";
import { LibraryV2, RESYNC_MS } from "./library/LibraryV2";
import { rowModel } from "./library/model";
import { HomeV2 } from "./home/HomeV2";
import { Shell } from "./shell/Shell";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

const done = { final_status: "completed", final_transcript_id: "tr-1", live_available: false, preview: "Payment terms" };
const item = (over: Partial<LibraryItem> = {}): LibraryItem => makeItem("bilal", {
  title: "Bilal Qureshi", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: "2026-11-17T06:42:00Z", duration_ms: 600_000, language: "ur", transcript: done, ...over,
});

function library(items: LibraryItem[]) {
  respond("library_list", items);
  respond("library_refresh", items);
  respond("bin_list", { data: [] });
  render(<LibraryV2 />);
}
async function openRecording(it: LibraryItem) {
  respond("session_detail", { playback_url: null });
  respond("transcript_detail", it.status === "failed" ? makeTranscript({ id: "tr-1", status: "failed", segments: [], error_code: "provider_unreachable" }) : makeTranscript({ id: "tr-1" }));
  library([it]);
  await act(flushAsync);
  fireEvent.click(screen.getByRole("button", { name: /^Bilal Qureshi/ }));
  await act(flushAsync);
}

beforeEach(() => {
  resetTauriMock();
  respond("unsure_markers", null);
  respond("transcript_views", { status: "none", available: [], model: null, made_at: null, error: null });
});
afterEach(() => vi.useRealTimers());

describe("the recording page (parity batch 2)", () => {
  it("DT-REC-056: after a failed transcription, Try again uses the same language", async () => {
    respond("session_retranscribe", {});
    await openRecording(item({ status: "failed", transcript: { ...done, final_status: "failed", final_error_code: "provider_unreachable" } }));
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    await act(flushAsync);
    expect(callsOf("session_retranscribe")).toEqual([{ id: "bilal", language: "ur" }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("The current transcript stays until the new one is ready.");
  });

  it("DT-REC-004: clicking the title renames in place; Enter saves, Esc cancels", async () => {
    respondWith("session_organise", (args) => ({ data: { ...item(), title: (args as { patch: { title: string } }).patch.title, title_auto: false } }));
    await openRecording(item());
    fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi" }));
    const field = screen.getByRole("textbox", { name: "Recording title" });
    fireEvent.change(field, { target: { value: "Ignored" } });
    fireEvent.keyDown(field, { key: "Escape" });
    expect(callsOf("session_organise")).toEqual([]);
    fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi" }));
    fireEvent.change(screen.getByRole("textbox", { name: "Recording title" }), { target: { value: "  Bilal · March shipment " } });
    fireEvent.keyDown(screen.getByRole("textbox", { name: "Recording title" }), { key: "Enter" });
    await act(flushAsync);
    expect(callsOf("session_organise")).toEqual([{ id: "bilal", patch: { title: "Bilal · March shipment" } }]);
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Bilal · March shipment");
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Renamed");
  });
});

describe("Show as (parity batch 2)", () => {
  it("DT-REC-069: a recording opens in the console's default view once it is ready", async () => {
    respond("transcript_views", { status: "ready", available: ["roman", "english"], model: "m", made_at: null, error: null,
      roman: ["Hello, yeh Sam hai.", "Hi Sam, shukriya.", "Invoice dekh lete hain.", "Theek hai."], english: ["Hello.", "Hi.", "Invoice.", "Fine."], urdu: [null, null, null, null], kept: {} });
    respond("session_detail", { playback_url: null });
    respond("transcript_detail", makeTranscript({ id: "tr-1", views: { available: ["roman", "english"], status: "ready", default: "roman" } }));
    library([item()]);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: /^Bilal Qureshi/ }));
    await act(flushAsync);
    await act(flushAsync);
    expect(within(screen.getByRole("radiogroup", { name: "Show as" })).getByRole("radio", { name: "Roman Urdu" })).toBeChecked();
    expect(screen.getByText("Invoice dekh lete hain.")).toBeInTheDocument();
  });
});

describe("the Library (parity batch 2)", () => {
  it("DT-LIB-136: a sync that fails keeps the saved list and offers Retry", async () => {
    respond("library_list", [item()]);
    fail("library_refresh", "The server didn't answer.");
    respond("bin_list", { data: [] });
    render(<LibraryV2 />);
    await act(flushAsync);
    const banner = screen.getByTestId("library-sync");
    expect(banner).toHaveTextContent("Couldn't sync with the server. Showing what is saved on this computer.");
    expect(screen.getByRole("button", { name: /^Bilal Qureshi/ })).toBeInTheDocument();
    respond("library_refresh", [item()]);
    fireEvent.click(within(banner).getByRole("button", { name: "Retry" }));
    await act(flushAsync);
    expect(screen.queryByTestId("library-sync")).toBeNull();
  });

  it("DT-LIB-074: the list syncs every 5 s while something is transcribed, and stops after", async () => {
    vi.useFakeTimers({ toFake: ["setInterval", "clearInterval"] });
    const making = item({ status: "processing", transcript: { final_status: "processing", final_transcript_id: null, live_available: false, preview: null } });
    library([making]);
    await act(flushAsync);
    const before = callsOf("library_refresh").length;
    act(() => vi.advanceTimersByTime(RESYNC_MS));
    await act(flushAsync);
    expect(callsOf("library_refresh").length).toBe(before + 1);
    respond("library_refresh", [item()]);
    act(() => vi.advanceTimersByTime(RESYNC_MS));
    await act(flushAsync);
    const after = callsOf("library_refresh").length;
    act(() => vi.advanceTimersByTime(RESYNC_MS * 3));
    await act(flushAsync);
    expect(callsOf("library_refresh").length).toBe(after);
  });

  it("DT-LIB-070: a row being transcribed in parts says which part", () => {
    const making = item({ status: "processing", transcript: { final_status: "processing", final_transcript_id: null, live_available: false, preview: null, final_progress: { done: 2, total: 5 } } });
    expect(rowModel(making).meta).toBe("WhatsApp · Transcribing… part 3 of 5");
  });

  it("DT-LIB-043: Density is in the Library's Sort and group menu, saved for Settings › Appearance too", async () => {
    library([item()]);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Sort and group" }));
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Compact" }));
    expect(loadPreferences().density).toBe("compact");
    expect(document.documentElement.dataset.density).toBe("compact");
    fireEvent.click(screen.getByRole("button", { name: "Sort and group" }));
    expect(screen.getByRole("menuitemradio", { name: "Compact" })).toHaveAttribute("aria-checked", "true");
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Comfortable" }));
    expect(loadPreferences().density).toBe("comfortable");
  });
});

describe("Home and the shell (parity batch 2)", () => {
  it("DT-HOME-023: a viewer is told recording is off, instead of a missing Record button", async () => {
    resetEntitlementsForTests(null);
    respond("ready_last", null);
    respond("permissions_status", { microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "macos" });
    respond("library_list", []);
    respond("skipped_list", []);
    const viewer = makeStatus({ plan: { ...makePlan(), role: "viewer", workspace: "Qureshi Textiles" } });
    render(<HomeV2 status={viewer} onSetup={vi.fn()} onReadyCheck={vi.fn()} onOpenRecording={vi.fn()} onLibrary={vi.fn()} onAction={vi.fn()} onPlan={vi.fn()} />);
    await act(flushAsync);
    expect(screen.getByTestId("home-viewer")).toHaveTextContent("An owner or admin of the workspace can change your role in the console.");
    expect(screen.queryByRole("button", { name: "Record" })).toBeNull();
  });

  it("DT-NAV-036: the super admin has Admin panel at the sidebar's foot; nobody else does", () => {
    const onAdmin = vi.fn();
    const { rerender } = render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} onAdmin={onAdmin}><p /></Shell>);
    fireEvent.click(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Admin panel" }));
    expect(onAdmin).toHaveBeenCalledTimes(1);
    rerender(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }}><p /></Shell>);
    expect(screen.queryByRole("button", { name: "Admin panel" })).toBeNull();
  });
});
