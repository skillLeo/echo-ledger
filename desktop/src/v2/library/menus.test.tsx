// UX-22 (PLAN_V2_1_POLISH 3b): the Library's right-click menus, made from actions.json. A recording's menu (and its ⋯,
// the same menu): Open · Transcribe (Audio only) or Ask about this | Pin · Important · Unread · Labels › · Move › |
// Rename… · Share › · Export › | Move to trash, only what fits and can run here; several have the title "3 recordings";
// the list's empty space has its own. On a Mac the system's menu (no icons, no shortcuts, Title Case); on Windows ours.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { checkMenu, runAction } from "../actions/registry";
import { answerConfirmForTests } from "../../lib/confirm";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
const native = vi.hoisted(() => ({ menus: [] as unknown[], popups: [] as unknown[] }));
vi.mock("@tauri-apps/api/menu", () => ({
  Menu: { new: async (opts: { items: unknown[] }) => { native.menus.push(opts.items); return { popup: async (at: unknown) => { native.popups.push(at); } }; } },
}));
vi.mock("@tauri-apps/api/dpi", () => ({ LogicalPosition: class { constructor(public x: number, public y: number) {} } }));

const NOW = new Date(2026, 10, 24, 15, 0);
const done = (preview: string, id: string) => ({ final_status: "completed", final_transcript_id: id, live_available: false, preview });
const none = { final_status: null, final_transcript_id: null, live_available: false, preview: null };
const ITEMS: LibraryItem[] = [
  makeItem("bilal", { title: "Bilal Qureshi", source_app: "whatsapp", started_at: new Date(2026, 10, 24, 11).toISOString(), transcript: done("Payment terms", "t1") }),
  makeItem("voice", { title: "Voice note", source_app: "voice_note", started_at: new Date(2026, 10, 24, 10).toISOString(), status: "uploaded", transcript: none }),
  makeItem("usman", { title: "Usman Chaudhry", source_app: "phone_call", started_at: new Date(2026, 10, 24, 9).toISOString(), pinned: true, transcript: done("Steel", "t2") }),
];
const row = (title: string) => within(screen.getByRole("list", { name: "Recordings" })).getByRole("button", { name: title });
const words = (menu: HTMLElement) => [...menu.querySelectorAll(':scope > li [role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent ?? m.textContent);
const groups = (menu: HTMLElement) => {
  const out: string[][] = [[]];
  for (const el of menu.querySelectorAll(':scope > li')) {
    if (el.getAttribute("role") === "separator") out.push([]);
    const item = el.querySelector('[role^="menuitem"]');
    if (item) out[out.length - 1].push(item.querySelector(".el-menu__label")?.textContent ?? "");
  }
  return out;
};
const setPlatform = (platform: string) => Object.defineProperty(navigator, "platform", { value: platform, configurable: true });

async function show() {
  render(<LibraryV2 />);
  await act(flushAsync);
}

describe("the Library's right-click menus (UX-22)", () => {
  beforeEach(() => {
    resetTauriMock();
    window.localStorage.clear();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    setPlatform("Win32");
    native.menus.length = 0;
    native.popups.length = 0;
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("bin_list", { data: [] });
    respond("list_clients", { data: [{ id: "c1", name: "Qureshi Textiles" }] });
    respondWith("sessions_bulk", (args) => (args as { ids: string[] }).ids.length);
  });
  afterEach(() => {
    vi.useRealTimers();
    setPlatform("");
  });

  it("one recording: only what fits, in 3b's groups and words; every item is actions.json's", async () => {
    await show();
    fireEvent.contextMenu(row("Bilal Qureshi"), { clientX: 40, clientY: 60 });
    const menu = screen.getByRole("menu", { name: "Recording actions" });
    // Transcribed: no Transcribe; Ask about this, Mark as unread and Share wait for what runs them (left out, not greyed).
    expect(groups(menu)).toEqual([["Open"], ["Pin", "Mark as important", "Labels", "Move to project"], ["Rename…", "Export"], ["Move to trash"]]);
    expect(within(menu).queryByText(/Select|Keep forever|Copy for AI|WhatsApp/)).toBeNull();
    expect(within(menu).getByRole("menuitem", { name: "Move to trash" })).toHaveClass("el-menu__option--danger");
    // No title for one recording.
    expect(menu.querySelector(".el-menu__title")).toBeNull();
    fireEvent.keyDown(menu, { key: "Escape" });
    // An Audio only recording offers Transcribe; a pinned one Unpin.
    fireEvent.contextMenu(row("Voice note"), { clientX: 40, clientY: 60 });
    expect(words(screen.getByRole("menu", { name: "Recording actions" })).slice(0, 2)).toEqual(["Open", "Transcribe"]);
    fireEvent.keyDown(screen.getByRole("menu", { name: "Recording actions" }), { key: "Escape" });
    fireEvent.contextMenu(row("Usman Chaudhry"), { clientX: 40, clientY: 60 });
    expect(words(screen.getByRole("menu", { name: "Recording actions" }))).toContain("Unpin");
  });

  it("several: the title \"2 recordings\" and the plain words; picks go through the registry to the core", async () => {
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Voice note"), { metaKey: true });
    fireEvent.contextMenu(row("Voice note"), { clientX: 40, clientY: 60 });
    const menu = screen.getByRole("menu", { name: "Actions for 2 recordings" });
    expect(menu.querySelector(".el-menu__title")).toHaveTextContent("2 recordings");
    expect(groups(menu)).toEqual([["Transcribe"], ["Pin", "Mark as important", "Labels", "Move to project"], ["Export"], ["Move to trash"]]);
    fireEvent.click(within(menu).getByRole("menuitemcheckbox", { name: "Mark as important" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["bilal", "voice"], action: "important", clientId: null, label: null }]);
  });

  it("Move to project › and Export › are one level of choices; the ⋯ and Shift+F10 open the same menu", async () => {
    respond("sessions_export", "/tmp/a.zip");
    respond("session_organise", { data: ITEMS[0] });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Bilal Qureshi" }));
    const more = screen.getByRole("menu", { name: "More for Bilal Qureshi" });
    expect(words(more)).toEqual(["Open", "Pin", "Mark as important", "Labels", "Move to project", "Rename…", "Export", "Move to trash"]);
    fireEvent.click(within(more).getByRole("menuitem", { name: "Move to project" }));
    const projects = screen.getByRole("menu", { name: "Move to project" });
    expect(words(projects)).toEqual(["No project", "Qureshi Textiles"]);
    expect(within(projects).getByRole("menuitemradio", { name: "No project" })).toHaveAttribute("aria-checked", "true");
    fireEvent.click(within(projects).getByRole("menuitemradio", { name: "Qureshi Textiles" }));
    await act(flushAsync);
    expect(callsOf("session_organise")).toEqual([{ id: "bilal", patch: { client_id: "c1" } }]);

    act(() => row("Bilal Qureshi").focus());
    fireEvent.keyDown(row("Bilal Qureshi"), { key: "F10", shiftKey: true });
    const menu = screen.getByRole("menu", { name: "Recording actions" });
    fireEvent.click(within(menu).getByRole("menuitem", { name: "Export" }));
    fireEvent.click(within(screen.getByRole("menu", { name: "Export" })).getByRole("menuitem", { name: "Text (.txt)" }));
    await act(flushAsync);
    expect(callsOf("sessions_export")).toEqual([{ ids: ["bilal"], format: "txt", view: undefined }]);
  });

  it("only the file's words and keys: checkMenu finds nothing in any of them", async () => {
    await show();
    fireEvent.contextMenu(row("Bilal Qureshi"), { clientX: 40, clientY: 60 });
    const menu = screen.getByRole("menu", { name: "Recording actions" });
    // The items as drawn are the registry's: each names its action.
    expect([...menu.querySelectorAll('[role^="menuitem"]')].every((m) => m.getAttribute("data-action"))).toBe(true);
    const drawn = [...menu.querySelectorAll('[role^="menuitem"]')].map((m) => ({ label: m.querySelector(".el-menu__label")?.textContent ?? "", action: m.getAttribute("data-action") ?? undefined }));
    expect(checkMenu(drawn, "windows")).toEqual([]);
  });

  it("the list's empty space: New recording · New voice note · Import… (when they can run) | Sort by › · Group by › · As list · As table · Density › | Select all", async () => {
    respond("toggle_mic_note", null);
    render(<LibraryV2 onImport={() => undefined} onRecord={() => undefined} />);
    await act(flushAsync);
    fireEvent.contextMenu(document.querySelector(".el-lib__main") as HTMLElement, { clientX: 400, clientY: 500 });
    const menu = screen.getByRole("menu", { name: "List actions" });
    expect(groups(menu)).toEqual([["New recording", "New voice note", "Import audio files…"], ["Sort by", "Group by", "As list", "As table", "Density"], ["Select all"]]);
    fireEvent.click(within(menu).getByRole("menuitem", { name: "New voice note" }));
    await act(flushAsync);
    expect(callsOf("toggle_mic_note")).toHaveLength(1);
    fireEvent.contextMenu(document.querySelector(".el-lib__main") as HTMLElement, { clientX: 400, clientY: 500 });
    const again = screen.getByRole("menu", { name: "List actions" });
    expect(within(again).getByRole("menuitemcheckbox", { name: "As list" })).toHaveAttribute("aria-checked", "true");
    // Only the list's area under row 2: not the title, row 2 itself or above it.
    fireEvent.keyDown(again, { key: "Escape" });
    fireEvent.contextMenu(screen.getByRole("heading", { level: 1 }), { clientX: 400, clientY: 20 });
    fireEvent.contextMenu(document.querySelector(".el-lib-row2") as HTMLElement, { clientX: 400, clientY: 60 });
    expect(screen.queryByRole("menu", { name: "List actions" })).toBeNull();
    fireEvent.contextMenu(document.querySelector(".el-lib__main") as HTMLElement, { clientX: 400, clientY: 500 });
    fireEvent.click(within(screen.getByRole("menu", { name: "List actions" })).getByRole("menuitemcheckbox", { name: "As table" }));
    await act(flushAsync);
    expect(document.querySelector(".el-lib-list")).toBeNull();
  });

  it("a menu shows only the keys the Library answers, and they work: F2 Rename…, Ctrl+T Transcribe, Ctrl+Shift+L Mark as important", async () => {
    respond("sessions_transcribe_estimate", { minutes: 3, minutes_left: 310, items: [] });
    await show();
    const keysOf = (menu: HTMLElement) => Object.fromEntries([...menu.querySelectorAll('[role^="menuitem"]')]
      .map((m) => [m.querySelector(".el-menu__label")?.textContent, m.querySelector(".el-menu__shortcut")?.textContent ?? null]));
    fireEvent.contextMenu(row("Voice note"), { clientX: 40, clientY: 60 });
    const menu = screen.getByRole("menu", { name: "Recording actions" });
    expect(keysOf(menu)).toEqual({
      Open: "Enter", Transcribe: "Ctrl+T", Pin: null, "Mark as important": "Ctrl+Shift+L", Labels: null, "Move to project": null,
      "Rename…": "F2", Export: null, "Move to trash": "Delete",
    });
    fireEvent.keyDown(menu, { key: "Escape" });
    act(() => row("Bilal Qureshi").focus());
    fireEvent.keyDown(row("Bilal Qureshi"), { key: "F2" });
    expect(screen.getByRole("dialog", { name: /^Rename/ })).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole("dialog", { name: /^Rename/ }), { key: "Escape" });
    act(() => row("Voice note").focus());
    fireEvent.keyDown(row("Voice note"), { key: "t", ctrlKey: true });
    await act(flushAsync);
    expect(screen.getByRole("dialog", { name: /^Transcribe/ })).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole("dialog", { name: /^Transcribe/ }), { key: "Escape" });
    act(() => row("Bilal Qureshi").focus());
    fireEvent.keyDown(row("Bilal Qureshi"), { key: "L", ctrlKey: true, shiftKey: true });
    await act(flushAsync);
    expect(callsOf("sessions_bulk").at(-1)).toEqual({ ids: ["bilal"], action: "important", clientId: null, label: null });
    // The list's space: only Select all's ⌘A / Ctrl+A is the Library's.
    render(<LibraryV2 onImport={() => undefined} onRecord={() => undefined} />);
    await act(flushAsync);
    fireEvent.contextMenu(document.querySelectorAll(".el-lib__main")[1] as HTMLElement, { clientX: 400, clientY: 500 });
    const space = keysOf(screen.getByRole("menu", { name: "List actions" }));
    expect(Object.entries(space).filter(([, key]) => key)).toEqual([["Select all", "Ctrl+A"]]);
  });

  it("on a Mac the same keys in its words: ⇧⌘L Mark as important, ⌘T Transcribe", async () => {
    setPlatform("MacIntel");
    respond("sessions_transcribe_estimate", { minutes: 3, minutes_left: 310, items: [] });
    await show();
    act(() => row("Bilal Qureshi").focus());
    fireEvent.keyDown(row("Bilal Qureshi"), { key: "l", metaKey: true, shiftKey: true });
    await act(flushAsync);
    expect(callsOf("sessions_bulk").at(-1)).toEqual({ ids: ["bilal"], action: "important", clientId: null, label: null });
    act(() => row("Voice note").focus());
    fireEvent.keyDown(row("Voice note"), { key: "t", metaKey: true });
    await act(flushAsync);
    expect(screen.getByRole("dialog", { name: /^Transcribe/ })).toBeInTheDocument();
  });

  it("an empty Library (its empty state) has no list-space menu", async () => {
    respond("library_list", []);
    respond("library_refresh", []);
    await show();
    fireEvent.contextMenu(document.querySelector(".el-lib__main") as HTMLElement, { clientX: 400, clientY: 500 });
    expect(screen.queryByRole("menu", { name: "List actions" })).toBeNull();
  });

  it("Transcribe all… (Audio only's menu, through the registry) says the count, the minutes and the minutes left; over 50, the first 50 now", async () => {
    const many = Array.from({ length: 53 }, (_, i) => makeItem(`a${i}`, { title: `Note ${i}`, source_app: "voice_note", status: "uploaded", transcript: none }));
    respond("library_list", many);
    respond("library_refresh", many);
    respond("sessions_transcribe_estimate", { minutes: 120, minutes_left: 310, items: [] });
    await show();
    act(() => void runAction("view.transcribeAll", { platform: "windows", kind: "place" }));
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: "Transcribe 50 recordings?" });
    expect(dialog).toHaveAccessibleDescription("The first 50 of 53 now. About 120 minutes of the 310 minutes left this month.");
    expect(callsOf("sessions_transcribe_estimate").at(-1)).toMatchObject({ ids: many.slice(0, 50).map((i) => i.id) });
  });

  it("Mark as important is in the selection bar's ⋮ and the recording page's menu too, not only a right-click", async () => {
    respond("session_detail", { playback_url: null });
    respond("transcript_detail", { id: "t1", kind: "final", provider: "x", status: "completed", language: "en", text: null, segments: [] });
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(within(screen.getByRole("toolbar", { name: "Selected recordings" })).getByRole("button", { name: "More actions" }));
    fireEvent.click(within(screen.getByRole("menu", { name: "More actions" })).getByRole("menuitemcheckbox", { name: "Mark as important" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk").at(-1)).toEqual({ ids: ["bilal"], action: "important", clientId: null, label: null });
    fireEvent.keyDown(document.body, { key: "Escape" });
    fireEvent.click(row("Usman Chaudhry"));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Recording actions" }));
    fireEvent.click(within(screen.getByRole("menu", { name: "Recording actions" })).getByRole("menuitemcheckbox", { name: "Mark as important" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk").at(-1)).toEqual({ ids: ["usman"], action: "important", clientId: null, label: null });
  });

  it("Trash: a deleted recording's Restore | Delete immediately…; Trash's own Empty trash… asks, then erases every one", async () => {
    const BIN = [
      { id: "old1", title: "Old call", deleted_at: "2026-11-23T09:00:00Z", erase_at: "2026-11-25T09:00:00Z", duration_ms: 60_000 },
      { id: "old2", title: "Older call", deleted_at: "2026-11-23T08:00:00Z", erase_at: "2026-11-25T08:00:00Z", duration_ms: 60_000 },
    ];
    respond("bin_list", { data: BIN });
    respond("session_restore", { data: ITEMS[0] });
    respond("bin_erase", null);
    answerConfirmForTests(() => true);
    const view = render(<LibraryV2 />);
    await act(flushAsync);
    view.rerender(<LibraryV2 scopeRequest={{ scope: { kind: "trash" }, seq: 1 }} />);
    await act(flushAsync);
    const list = screen.getByRole("list", { name: "Deleted recordings" });
    fireEvent.contextMenu(within(list).getByText("Old call"), { clientX: 30, clientY: 40 });
    const menu = screen.getByRole("menu", { name: "Deleted recording actions" });
    expect(groups(menu)).toEqual([["Restore"], ["Delete immediately…"]]);
    fireEvent.click(within(menu).getByRole("menuitem", { name: "Restore" }));
    await act(flushAsync);
    expect(callsOf("session_restore")).toEqual([{ id: "old1" }]);
    // Trash's empty space: Empty trash…, which asks first.
    fireEvent.contextMenu(screen.getByRole("heading", { level: 1, name: /^Trash/ }).closest(".el-lib__main") as HTMLElement, { clientX: 300, clientY: 600 });
    const trash = screen.getByRole("menu", { name: "Trash actions" });
    expect(groups(trash)).toEqual([["Empty trash…"]]);
    expect(within(trash).getByRole("menuitem", { name: "Empty trash…" })).toHaveClass("el-menu__option--danger");
    fireEvent.click(within(trash).getByRole("menuitem", { name: "Empty trash…" }));
    await act(flushAsync);
    // Old call was restored: the trash held only Older call.
    expect(callsOf("bin_erase")).toEqual([{ id: "old2" }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Trash emptied · 1 recording");
    answerConfirmForTests(null);
  });

  it("on a Mac: the system's menu at the pointer, in Title Case, with no icons or shortcuts; a title line for several", async () => {
    setPlatform("MacIntel");
    await show();
    fireEvent.contextMenu(row("Bilal Qureshi"), { clientX: 40, clientY: 60 });
    await act(flushAsync);
    expect(screen.queryByRole("menu", { name: "Recording actions" })).toBeNull();
    const items = native.menus[0] as { text?: string; item?: string; items?: unknown[]; checked?: boolean; enabled?: boolean }[];
    expect(items.map((i) => i.text ?? i.item)).toEqual([
      "Open", "Separator", "Pin", "Mark as Important", "Labels", "Move to Project", "Separator", "Rename…", "Export", "Separator", "Move to Trash",
    ]);
    expect(JSON.stringify(items)).not.toMatch(/accelerator|icon/);
    expect(items.find((i) => i.text === "Mark as Important")).toMatchObject({ checked: false, enabled: true });
    expect(native.popups[0]).toMatchObject({ x: 40, y: 60 });
    // Several: a greyed title line first.
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Voice note"), { metaKey: true });
    fireEvent.contextMenu(row("Voice note"), { clientX: 40, clientY: 60 });
    await act(flushAsync);
    expect((native.menus[1] as { text?: string; item?: string; enabled?: boolean }[]).slice(0, 2)).toEqual([{ text: "2 recordings", enabled: false }, { item: "Separator" }]);
  });
});
