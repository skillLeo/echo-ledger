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

function inTheApp(flags: Record<string, boolean>, lists: unknown[] = []) {
  respond("app_status", makeStatus({ platform: "macos" }));
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("get_settings", makeSettings());
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("bin_list", { data: [] });
  respond("lists_get", lists);
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
    // A plain pick (the menu bar's View › As Table) isn't the closed list menu's: the registry runs it, for the
    // Library's list (FIX-05: the Library says its kinds), and nothing of the list menu's leftovers runs.
    await emitEvent("menu:action", "view.asTable");
    await settle();
    expect(await screen.findByRole("table", { name: "Recordings" })).toBeInTheDocument();
    expect(callsOf("toggle_mic_note")).toHaveLength(callsBefore);
  });

  it("a label whose id holds \"=\" or ends in \"~12\" is picked as itself: the core encodes it, the one listener decodes it", async () => {
    inTheApp({ "ui_v2.library": true, "ui_v2.shell": true }, [{ key: "a=b~12", name: "Clients", color: null, position: 0, count: 0 }]);
    await settle();
    await screen.findByRole("list", { name: "Recordings" });
    fireEvent.contextMenu(row("Bilal on WhatsApp"), { clientX: 30, clientY: 40 });
    await settle();
    const call = callsOf("native_menu_popup").at(-1) as { items: Line[]; menu: number };
    const labels = call.items.find((i) => i.label === "Labels")!;
    expect(labels.items!.find((i) => i.label === "Clients")).toMatchObject({ action: "recording.labels", value: "a=b~12" });
    // What native_menu.rs builds for it: the value's "=" and "~" percent-encoded, the menu's number last.
    await emitEvent("menu:action", `recording.labels=a%3Db%7E12~${call.menu}`);
    await settle();
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["wa"], action: "label", clientId: null, label: "Clients" }]);
  });

  it("the menu bar's Recording menu greys and runs for the Library's open recording, as a recording's (FIX-05)", async () => {
    respond("app_menu_set", null);
    inTheApp({ "ui_v2.library": true, "ui_v2.shell": true });
    await settle();
    await screen.findByRole("list", { name: "Recordings" });
    const pin = async () => {
      await act(() => new Promise((done) => setTimeout(done, 80)));
      const bar = callsOf("app_menu_set").at(-1)?.bar as { menus: { title: string; items: { kind: string; action?: string; enabled?: boolean; label?: string }[] }[] };
      return bar.menus.find((m) => m.title === "Recording")!.items.find((i) => i.kind === "item" && i.action === "recording.pin")!;
    };
    // Nothing chosen: greyed.
    expect(await pin()).toMatchObject({ enabled: false });
    // A recording open: Pin is the Library's, for it.
    fireEvent.click(row("Bilal on WhatsApp"));
    await settle();
    expect(await pin()).toMatchObject({ enabled: true, label: "Pin" });
    await emitEvent("menu:action", "recording.pin");
    await settle();
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["wa"], action: "pin", clientId: null, label: null }]);
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
