// UX-22's system menus on a Mac, in the app: a pick comes back as a real `menu:action` event, heard by the window's one
// listener (the shell's; FIX-05's menu bar moves it to MainApp, for the classic shell too). A page's right-click menu
// runs its own picks; a moment after it closes they are let go; Record's menu is never caught by a list menu's leftover
// picks; and a pick of a menu let go (it carries the menu's number, "~<n>") runs nothing, not even its bare id.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import MainApp from "../../views/main/MainApp";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const none = { final_status: null, final_transcript_id: null, live_available: false, preview: null };
const ITEMS: LibraryItem[] = [
  makeItem("wa", { title: "Bilal on WhatsApp", source_app: "whatsapp", started_at: "2026-11-17T06:00:00Z", transcript: { ...none, final_status: "completed", final_transcript_id: "t1", preview: "Hello" } }),
  makeItem("memo", { title: "Imported memo", source_app: "voice_note", started_at: "2026-11-17T04:00:00Z", status: "audio_only", transcript: none }),
];
type Line = { action: string | null; label?: string; value?: string; items?: Line[] };
const setPlatform = (platform: string) => Object.defineProperty(navigator, "platform", { value: platform, configurable: true });
const settle = () => act(flushAsync);
const row = (title: string) => within(screen.getByRole("list", { name: "Recordings" })).getByRole("button", { name: title });
/** What an item of the last system menu comes back as when picked (native_menu.rs: "<id>[=<value>]~<menu>"). */
const pickOf = (label: string) => {
  const call = callsOf("native_menu_popup").at(-1) as { items: Line[]; menu: number | null };
  const item = call.items.find((i) => i.label === label)!;
  return `${item.action}${item.value !== undefined ? `=${item.value}` : ""}${call.menu !== null ? `~${call.menu}` : ""}`;
};

function inTheApp(flags: Record<string, boolean>) {
  respond("app_status", makeStatus({ platform: "macos" }));
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("get_settings", makeSettings());
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("bin_list", { data: [] });
  respond("lists_get", []);
  respond("library_views", { views: {}, labels: [], folders: { inbox: 2, shared: 0 } });
  respond("library_folder", []);
  respond("native_menu_popup", null);
  respondWith("sessions_bulk", (args) => (args as { ids: string[] }).ids.length);
  respond("ui_flags", { ui_flags: flags, ui_classic: false });
  render(<MainApp />);
}

describe("the system menus' picks in the app (UX-22)", () => {
  beforeEach(() => {
    resetTauriMock();
    window.localStorage.clear();
    setPlatform("MacIntel");
  });
  afterEach(() => {
    vi.useRealTimers();
    setPlatform("");
  });

  it("in the new shell: a pick runs once; a second after the menu closes its picks are let go; Record's menu isn't caught by them", async () => {
    inTheApp({ "ui_v2.library": true, "ui_v2.shell": true });
    await settle();
    await screen.findByRole("list", { name: "Recordings" });
    fireEvent.contextMenu(row("Bilal on WhatsApp"), { clientX: 30, clientY: 40 });
    await settle();
    await emitEvent("menu:action", pickOf("Pin"));
    await settle();
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["wa"], action: "pin", clientId: null, label: null }]);
    // Shown again and not picked: a second later its picks are gone.
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    fireEvent.contextMenu(row("Imported memo"), { clientX: 30, clientY: 40 });
    await settle();
    const late = pickOf("Pin");
    act(() => vi.advanceTimersByTime(1000));
    await emitEvent("menu:action", late);
    await settle();
    expect(callsOf("sessions_bulk")).toHaveLength(1);
    vi.useRealTimers();
    // A list menu closed without a pick, then Record's menu: its plain "New voice note" is the shell's, never the
    // Library's (toggle_mic_note would be the Library's).
    respond("toggle_mic_note", makeStatus());
    fireEvent.contextMenu(screen.getByRole("list", { name: "Recordings" }).parentElement as HTMLElement, { clientX: 300, clientY: 600 });
    await settle();
    const callsBefore = callsOf("toggle_mic_note").length;
    await emitEvent("menu:action", "view.asTable");
    await settle();
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
    expect(callsOf("toggle_mic_note")).toHaveLength(callsBefore);
  });

  it("a pick of a menu let go is dropped: a stale \"~n\" runs nothing, never its bare id through the registry", async () => {
    inTheApp({ "ui_v2.library": true, "ui_v2.shell": true });
    respond("toggle_mic_note", makeStatus());
    await settle();
    await screen.findByRole("list", { name: "Recordings" });
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    fireEvent.contextMenu(screen.getByRole("list", { name: "Recordings" }).parentElement as HTMLElement, { clientX: 300, clientY: 600 });
    await settle();
    const stale = pickOf("New Voice Note");
    expect(stale).toMatch(/^file\.newVoiceNote~\d+$/);
    act(() => vi.advanceTimersByTime(1000));
    vi.useRealTimers();
    await emitEvent("menu:action", stale);
    await settle();
    expect(callsOf("toggle_mic_note")).toHaveLength(0);
    // The same action with no number (the menu bar's, Record's menu) is the registry's, and runs.
    await emitEvent("menu:action", "file.newVoiceNote");
    await settle();
    expect(callsOf("toggle_mic_note")).toHaveLength(1);
  });
});
