// Desktop Urdu, D-248: every command of actions.json in the account's language, said once at the source (the registry's
// actionLabel), so every menu made from it speaks it: a recording's right-click and its ⋯, the selection bar's ⋮, a
// label's menu, the Mac's own right-click menu and its menu bar (drawn again when the language changes), ⌘K. Urdu is as
// written (no Title Case: it has no case); a window still in English asks for "en". English is the menus' own tests',
// unchanged.
import { useEffect } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem, ListItem, NativeMenuBar } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeStatus } from "../../test/fixtures";
import { LibraryV2 } from "../library/LibraryV2";
import { LibraryNav } from "../library/LibraryNav";
import { ActivityV2 } from "../activity/ActivityV2";
import { PROJECT_MENU, useProjectMenu } from "../shell/ProjectMenu";
import { CommandPalette, emptyLine, goToLine } from "../../views/main/CommandPalette";
import { ACTIONS, actionLabel, checkMenu, menuItems, nativeItems, registerAction, type ActionContext } from "./registry";
import { menuBarModel, type NativeEntry } from "./menuBar";
import { MENU_BAR_SETTLE_MS, useMenuBar } from "./useMenuBar";
import { listenToMenuPicks, setMenuPart } from "./menuState";
import { manyTitle } from "./contextMenus";
import { appWords } from "./popup";
import { CONTEXT_EN } from "../../i18n/context";
import { FSI, PDI, resetLanguageForTests, setLanguage, t } from "../../i18n";
import ur from "../../i18n/ur.json";
import list from "./actions.json";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 24, 15, 0);
const iso = (value: string | number) => `${FSI}${value}${PDI}`;
const mac: ActionContext = { platform: "macos" };
const win: ActionContext = { platform: "windows" };
/**
 * The names kept as they are inside Urdu (decision 30, terms-7): the app's, the brands' and the plans'. The longer
 * names first, so "WhatsApp Business" goes whole.
 */
const KEPT = ["WhatsApp Business", "WhatsApp Web", "WhatsApp", "EchoLedger", "Google", "Meet", "Zoom", "Teams", "Groq", "Ultra", "Pro", "Free"];
/** Latin letters, but in the kept names: what an Urdu label must not have. */
const latinIn = (words: string) => /[A-Za-z]/.test(KEPT.reduce((rest, name) => rest.split(name).join(""), words));
/** Any Arabic-script letter, or an isolate mark: what an English line must not have. */
const URDU_OR_ISOLATE = /[؀-ۿ⁦-⁩]/;
const setPlatform = (platform: string) => Object.defineProperty(navigator, "platform", { value: platform, configurable: true });

const done = (preview: string, id: string) => ({ final_status: "completed", final_transcript_id: id, live_available: false, preview });
const none = { final_status: null, final_transcript_id: null, live_available: false, preview: null };
const ITEMS: LibraryItem[] = [
  makeItem("bilal", { title: "Bilal Qureshi", source_app: "whatsapp", started_at: new Date(2026, 10, 24, 11).toISOString(), transcript: done("Payment terms", "t1") }),
  makeItem("voice", { title: "Voice note", source_app: "voice_note", started_at: new Date(2026, 10, 24, 10).toISOString(), status: "uploaded", transcript: none }),
  makeItem("usman", { title: "Usman Chaudhry", source_app: "phone_call", started_at: new Date(2026, 10, 24, 9).toISOString(), pinned: true, transcript: done("Steel", "t2") }),
];
const LISTS: ListItem[] = [
  { key: "clients", name: "Clients", color: "#26978f", position: 0, count: 2 },
  { key: "home", name: "Home", color: "#b3820f", position: 1, count: 1 },
  { key: "family", name: "Family", color: null, position: 2, count: 0 },
];
const row = (id: string) => document.querySelector<HTMLElement>(`button[data-row-id="${id}"]`)!;
/** A menu's first level, group by group (a hairline starts one), each item's words. */
const groups = (menu: HTMLElement) => {
  const out: string[][] = [[]];
  for (const el of menu.querySelectorAll(":scope > li")) {
    if (el.getAttribute("role") === "separator") out.push([]);
    const item = el.querySelector('[role^="menuitem"]');
    if (item) out[out.length - 1].push(item.querySelector(".el-menu__label")?.textContent ?? "");
  }
  return out;
};
const labelsOf = (menu: HTMLElement) => groups(menu).flat();
const urdu = () => act(() => setLanguage("ur", { root: false }));

beforeEach(async () => {
  resetTauriMock();
  resetLanguageForTests();
  window.localStorage.clear();
  setPlatform("Win32");
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("library_folder", []);
  respond("bin_list", { data: [] });
  respond("list_clients", { data: [{ id: "c1", name: "Qureshi Textiles" }] });
  respondWith("sessions_bulk", (args) => (args as { ids: string[] }).ids.length);
});
afterEach(() => {
  cleanup();
  setPlatform("");
  resetLanguageForTests();
});

describe("a command's words (actionLabel) in Urdu", () => {
  it("plain, state and “…” labels in the catalogue's words; on a Mac as written, with no Title Case", async () => {
    await urdu();
    expect(actionLabel("recording.labels", win)).toBe("لیبلز");
    expect(actionLabel("recording.pin", win)).toBe("پن کریں");
    expect(actionLabel("recording.pin", { ...win, state: { pinned: true } })).toBe("پن ہٹائیں");
    expect(actionLabel("recording.unread", win)).toBe("نہ پڑھی گئی کریں");
    expect(actionLabel("recording.unread", { ...win, state: { unread: true } })).toBe("پڑھی ہوئی کریں");
    expect(actionLabel("view.sidebar", { ...win, state: { sidebarHidden: true } })).toBe("سائیڈ بار دکھائیں");
    expect(actionLabel("file.rename", win)).toBe("نام بدلیں…");
    expect(actionLabel("label.delete", win)).toBe("لیبل حذف کریں…");
    expect(actionLabel("label.colour", win)).toBe("رنگ");
    expect([actionLabel("view.moveUp", win), actionLabel("view.moveDown", win)]).toEqual(["اوپر لے جائیں", "نیچے لے جائیں"]);
    // The Mac's Title Case is English's: Urdu is the same on a Mac.
    for (const id of ["recording.important", "recording.moveToTrash", "file.rename", "live.autoRecord"]) expect(actionLabel(id, mac)).toBe(actionLabel(id, win));
    expect(actionLabel("recording.unread", { ...mac, state: { unread: true } })).toBe("پڑھی ہوئی کریں");
    // The window's and the avatar's Zoom are a size, not the meeting app (whose name stays Latin).
    expect([actionLabel("window.zoom", win), actionLabel("account.zoom", win), t("Zoom")]).toEqual(["زوم", "زوم", "Zoom"]);
    // "Undo <what>" is English's sentence: in Urdu the command alone.
    expect(actionLabel("edit.undo", { ...mac, state: { undoWhat: "Move to trash" } })).toBe("واپس کریں");
  });

  it("every label and other state's words of actions.json is in the catalogue, in Urdu, with no Latin but the kept names", async () => {
    const keys = ur as Record<string, string>;
    await urdu();
    const missing: string[] = [];
    const latin: string[] = [];
    for (const a of ACTIONS) {
      for (const on of a.state?.key ? [false, true] : [false]) {
        const words = actionLabel(a.id, { ...win, state: a.state?.key ? { [a.state.key]: on } : {} });
        if (latinIn(words)) latin.push(`${a.id}: ${words}`);
      }
      for (const words of [a.label, a.state?.on]) if (words && keys[words] === undefined) missing.push(words);
    }
    expect(missing.filter((words) => words !== "Zoom")).toEqual([]);
    expect(latin).toEqual([]);
    // A kept name stays Latin inside the Urdu (terms-7); any other Latin word is still caught.
    expect(actionLabel("file.sendToWhatsApp", win)).toBe("WhatsApp پر بھیجیں…");
    expect([latinIn("WhatsApp Business پر بھیجیں"), latinIn("Pro پلان"), latinIn("Labels"), latinIn("نیا Label")]).toEqual([false, false, true, true]);
    // The keys of their own are context keys, English as before.
    expect(CONTEXT_EN["actions_context.zoom"]).toBe("Zoom");
  });

  it("a window still in English asks for \"en\": the English, in the Mac's Title Case, with no Urdu piece", async () => {
    await urdu();
    expect(actionLabel("recording.important", mac, "en")).toBe("Mark as Important");
    expect(actionLabel("recording.unread", { ...mac, state: { unread: true } }, "en")).toBe("Mark as Read");
    expect(actionLabel("recording.unread", win, "en")).toBe("Mark as unread");
    expect(actionLabel("edit.undo", { ...mac, state: { undoWhat: "Move to trash" } }, "en")).toBe("Undo Move to Trash");
    expect(actionLabel("window.zoom", win, "en")).toBe("Zoom");
    // A context that says "en" makes the whole menu English.
    const english = menuItems(["recording.pin", "recording.labels", "-", "file.rename"], { ...mac, targets: ["a"], lang: "en" }).map((i) => i.label);
    expect(english).toEqual(["Pin", "Labels", "Rename…"]);
    expect(english.join(" ")).not.toMatch(URDU_OR_ISOLATE);
  });

  it("the app's own words in a menu and a several-things title: as written in Urdu, the count in its isolate", async () => {
    expect([appWords("No project", "macos"), manyTitle(3)]).toEqual(["No Project", "3 recordings"]);
    await urdu();
    expect(appWords(t("No project"), "macos")).toBe(t("No project"));
    expect(manyTitle(3)).toBe(`${iso(3)} ریکارڈنگز`);
    expect(manyTitle(1)).toBeUndefined();
  });
});

describe("the Library's menus in Urdu", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
  });
  afterEach(() => {
    vi.useRealTimers();
  });
  const show = async () => {
    render(<LibraryV2 />);
    await act(flushAsync);
  };

  it("a recording's right-click and its ⋯: the same items in Urdu, its keys Latin and left to right, every word the file's", async () => {
    await urdu();
    await show();
    fireEvent.contextMenu(row("bilal"), { clientX: 40, clientY: 60 });
    const menu = screen.getByRole("menu", { name: t("Recording actions") });
    expect(groups(menu)).toEqual([
      ["کھولیں"], ["پن کریں", "اہم نشان لگائیں", "نہ پڑھی گئی کریں", "لیبلز", "پروجیکٹ میں منتقل کریں"], ["نام بدلیں…", "ایکسپورٹ"], ["ردی میں ڈالیں"],
    ]);
    const keys = [...menu.querySelectorAll("kbd.el-menu__shortcut")];
    expect(keys.map((k) => k.textContent)).toEqual(expect.arrayContaining(["Ctrl+Shift+U", "F2", "Delete"]));
    expect(keys.every((k) => k.getAttribute("dir") === "ltr")).toBe(true);
    const drawn = [...menu.querySelectorAll('[role^="menuitem"]')].map((m) => ({ label: m.querySelector(".el-menu__label")?.textContent ?? "", action: m.getAttribute("data-action") ?? undefined }));
    expect(checkMenu(drawn, "windows")).toEqual([]);
    // Labels › and Move to project ›: the sub-menus are named in Urdu too.
    fireEvent.click(within(menu).getByRole("menuitem", { name: "پروجیکٹ میں منتقل کریں" }));
    expect(labelsOf(screen.getByRole("menu", { name: "پروجیکٹ میں منتقل کریں" }))).toEqual([t("No project"), "Qureshi Textiles"]);
    fireEvent.keyDown(menu, { key: "Escape" });
    fireEvent.keyDown(document.body, { key: "Escape" });
    // A pinned one: Unpin.
    fireEvent.contextMenu(row("usman"), { clientX: 40, clientY: 60 });
    expect(labelsOf(screen.getByRole("menu", { name: t("Recording actions") }))).toContain("پن ہٹائیں");
    fireEvent.keyDown(screen.getByRole("menu", { name: t("Recording actions") }), { key: "Escape" });
    // The row's ⋯ is the same menu.
    fireEvent.click(screen.getByRole("button", { name: t("More for :name", { name: "Bilal Qureshi" }) }));
    expect(labelsOf(screen.getByRole("menu", { name: t("More for :name", { name: "Bilal Qureshi" }) }))).toEqual([
      "کھولیں", "پن کریں", "اہم نشان لگائیں", "نہ پڑھی گئی کریں", "لیبلز", "پروجیکٹ میں منتقل کریں", "نام بدلیں…", "ایکسپورٹ", "ردی میں ڈالیں",
    ]);
  });

  it("several: the title says the count in Urdu", async () => {
    await urdu();
    await show();
    fireEvent.click(row("bilal"), { metaKey: true });
    fireEvent.click(row("voice"), { metaKey: true });
    fireEvent.contextMenu(row("voice"), { clientX: 40, clientY: 60 });
    const menu = screen.getByRole("menu", { name: t("Actions for :count recordings", { count: 2 }) });
    expect(menu.querySelector(".el-menu__title")).toHaveTextContent(`${iso(2)} ریکارڈنگز`);
    expect(groups(menu)).toEqual([["ٹرانسکرائب کریں"], ["پن کریں", "اہم نشان لگائیں", "نہ پڑھی گئی کریں", "لیبلز", "پروجیکٹ میں منتقل کریں"], ["ایکسپورٹ"], ["ردی میں ڈالیں"]]);
  });

  it("the selection bar's ⋮ (More): Pin, Unpin, Keep forever and Mark as read / unread in Urdu", async () => {
    const mixed = ITEMS.map((i) => ({ ...i, opened: i.id !== "usman" }));
    respond("library_list", mixed);
    respond("library_refresh", mixed);
    await urdu();
    await show();
    fireEvent.click(row("bilal"), { metaKey: true });
    fireEvent.click(row("usman"), { metaKey: true });
    fireEvent.click(within(screen.getByRole("toolbar", { name: t("Selected recordings") })).getByRole("button", { name: t("More actions") }));
    const menu = screen.getByRole("menu", { name: t("More actions") });
    const words = [...menu.querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent);
    expect(words).toEqual(expect.arrayContaining(["پن کریں", "پن ہٹائیں", "ہمیشہ رکھیں", "اہم نشان لگائیں", "پڑھی ہوئی کریں", "نہ پڑھی گئی کریں"]));
    expect(words.filter((w) => latinIn(w ?? ""))).toEqual([]);
    expect(within(menu).getByRole("menuitem", { name: /نہ پڑھی گئی کریں/ }).querySelector("kbd")).toHaveAttribute("dir", "ltr");
  });

  it("on a Mac the system's menu gets the Urdu as written (no Title Case), and a title line for several", async () => {
    setPlatform("MacIntel");
    respond("native_menu_popup", null);
    await urdu();
    await show();
    fireEvent.contextMenu(row("bilal"), { clientX: 40, clientY: 60 });
    await act(flushAsync);
    type Line = { action: string | null; label?: string; items?: Line[] };
    const [call] = callsOf("native_menu_popup") as { items: Line[] }[];
    expect(call.items.map((i) => (i.action === null && !i.label ? "—" : i.label))).toEqual([
      "کھولیں", "—", "پن کریں", "اہم نشان لگائیں", "نہ پڑھی گئی کریں", "لیبلز", "پروجیکٹ میں منتقل کریں", "—", "نام بدلیں…", "ایکسپورٹ", "—", "ردی میں ڈالیں",
    ]);
    // The app's own word in its sub-menu, as written too.
    expect(call.items.find((i) => i.action === "recording.moveToProject")!.items!.map((i) => i.label)).toEqual([t("No project"), "Qureshi Textiles"]);
    fireEvent.click(row("bilal"), { metaKey: true });
    fireEvent.click(row("voice"), { metaKey: true });
    fireEvent.contextMenu(row("voice"), { clientX: 40, clientY: 60 });
    await act(flushAsync);
    expect((callsOf("native_menu_popup")[1] as { items: Line[] }).items[0]).toEqual({ action: null, label: `${iso(2)} ریکارڈنگز`, enabled: false });
  });

  it("a label's menu: Show recordings | Rename… · Colour › · Move up · Move down | Delete label…, in Urdu", async () => {
    respond("lists_get", LISTS);
    await urdu();
    render(<nav aria-label="Main"><LibraryNav current="view:all" counts={{ all: 3, needs: 0, unread: 0, important: 0, audio_only: 0 }} labelCounts={{ clients: 2, home: 1 }}
      onScope={vi.fn()} onNewView={vi.fn()} say={vi.fn()} onLibraryChanged={vi.fn()} /></nav>);
    await act(flushAsync);
    const labelRow = (name: string) => screen.getAllByRole("button", { name: new RegExp(`^${name}, `) })[0];
    fireEvent.contextMenu(labelRow("Home"));
    const menu = screen.getAllByRole("menu")[0];
    expect(groups(menu)).toEqual([["ریکارڈنگز دکھائیں"], ["نام بدلیں…", "رنگ", "اوپر لے جائیں", "نیچے لے جائیں"], ["لیبل حذف کریں…"]]);
    // Colour › opens its eight colours, as a sub-menu named in Urdu.
    fireEvent.click(within(menu).getByRole("menuitem", { name: "رنگ" }));
    expect(within(screen.getByRole("menu", { name: "رنگ" })).getAllByRole("menuitemradio")).toHaveLength(8);
    fireEvent.keyDown(document.body, { key: "Escape" });
    cleanup();
    // The first label has no Move up, the last no Move down: the same in Urdu.
    render(<nav aria-label="Main"><LibraryNav current="view:all" counts={{ all: 3, needs: 0, unread: 0, important: 0, audio_only: 0 }} labelCounts={{ clients: 2, home: 1 }}
      onScope={vi.fn()} onNewView={vi.fn()} say={vi.fn()} onLibraryChanged={vi.fn()} /></nav>);
    await act(flushAsync);
    fireEvent.contextMenu(labelRow("Clients"));
    expect(labelsOf(screen.getAllByRole("menu")[0])).toEqual(["ریکارڈنگز دکھائیں", "نام بدلیں…", "رنگ", "نیچے لے جائیں", "لیبل حذف کریں…"]);
  });
});

describe("the Mac menu bar in Urdu", () => {
  it("its menus' names and every item's words in Urdu, as written; the core keeps its commands' Urdu", async () => {
    await urdu();
    const bar = menuBarModel({ platform: "macos", state: { unread: true } });
    expect(bar.menus.map((m) => m.title)).toEqual(["EchoLedger", "فائل", "ترمیم", "ویو", "جائیں", "ریکارڈنگ", "لائیو", "ونڈو", "مدد"]);
    const all: NativeEntry[] = [];
    const walk = (items: readonly NativeEntry[]) => items.forEach((e) => { all.push(e); if (e.kind === "submenu") walk(e.items); });
    bar.menus.forEach((m) => walk(m.items));
    const label = (action: string) => (all.find((e) => (e.kind === "item" || e.kind === "submenu") && e.action === action) as { label: string }).label;
    expect([label("file.newRecording"), label("file.importAudio"), label("recording.unread"), label("recording.important"), label("app.settings")])
      .toEqual(["نئی ریکارڈنگ", "آڈیو فائلیں امپورٹ کریں…", "پڑھی ہوئی کریں", "اہم نشان لگائیں", "ترتیبات…"]);
    // The titled sub-menus: Edit › Find, View › Transcript › Script, View › Theme.
    const titled = all.filter((e): e is Extract<NativeEntry, { kind: "submenu" }> => e.kind === "submenu" && !e.action).map((e) => e.label);
    expect(titled).toEqual(["تلاش", "ٹرانسکرپٹ", "رسم الخط", "تھیم"]);
    // macOS's own items carry their words too (the core may draw them with them), in Urdu, the system's action kept.
    const natives = all.filter((e): e is Extract<NativeEntry, { kind: "native" }> => e.kind === "native");
    expect(Object.fromEntries(natives.map((e) => [e.native, e.label]))).toEqual({
      about: "EchoLedger کی معلومات", services: "سروسز", hide: "EchoLedger چھپائیں", hideOthers: "باقی ایپس چھپائیں", showAll: "سب دکھائیں",
      closeWindow: "ونڈو بند کریں", undo: "واپس کریں", redo: "دوبارہ کریں", cut: "کاٹیں", copy: "کاپی", paste: "پیسٹ کریں", selectAll: "سب منتخب کریں",
      fullscreen: "پوری اسکرین پر دکھائیں", minimize: "کم سے کم کریں", maximize: "زوم", bringAllToFront: "سب سامنے لائیں",
    });
    const words = all.flatMap((e) => (e.kind === "item" || e.kind === "submenu" || e.kind === "native" ? [e.label] : []));
    expect(words.filter(latinIn)).toEqual([]);
    // A command the core runs keeps both its words in Urdu for when there is no page.
    const pause = all.find((e) => e.kind === "item" && e.action === "live.pauseRecording") as { core: { off: string; on: string } };
    expect(pause.core).toMatchObject({ off: "ریکارڈنگ روکیں", on: "ریکارڈنگ جاری رکھیں" });
  });

  it("in English macOS's own items carry the Mac's Title Case; what the core draws has its & written && (one & drawn), the page's menus keep one", () => {
    const bar = menuBarModel({ platform: "macos", state: { fullScreen: true } }, { "recording.labels": [{ value: "rd", label: "R&D" }] });
    const all: NativeEntry[] = [];
    const walk = (items: readonly NativeEntry[]) => items.forEach((e) => { all.push(e); if (e.kind === "submenu") walk(e.items); });
    bar.menus.forEach((m) => walk(m.items));
    const native = (name: string) => (all.find((e) => e.kind === "native" && e.native === name) as { label: string }).label;
    expect(["about", "hideOthers", "showAll", "undo", "selectAll", "closeWindow", "fullscreen", "maximize", "bringAllToFront"].map(native))
      .toEqual(["About EchoLedger", "Hide Others", "Show All", "Undo", "Select All", "Close Window", "Exit Full Screen", "Zoom", "Bring All to Front"]);
    const label = (action: string) => all.filter((e) => (e.kind === "item" || e.kind === "submenu") && e.action === action).map((e) => (e as { label: string }).label);
    expect([...label("app.accountPlan"), ...label("help.setupHealth"), ...label("live.autoRecord")]).toEqual(["Account && Plan…", "Setup && Health…", "Auto-record Calls && Meetings"]);
    // A choice of the page's (a label's name) too, and every word the core draws: no single & is left.
    expect(label("recording.labels")).toEqual(["Labels", "R&&D"]);
    const words = [...bar.menus.map((m) => m.title), ...all.flatMap((e) => (e.kind === "separator" ? [] : [e.label]))];
    expect(words.filter((w) => /(^|[^&])&([^&]|$)/.test(w))).toEqual([]);
    // A right-click menu the system draws: its title, its items and its sub-menu's choices.
    const popup = nativeItems([
      { action: "live.autoRecord", label: "Auto-record Calls & Meetings", checked: true },
      { action: "recording.moveToProject", label: "Move to Project", items: [{ action: "recording.moveToProject", choice: "p1", label: "R&D" }] },
    ], "Q&A");
    expect(popup.map((i) => i.label)).toEqual(["Q&&A", undefined, "Auto-record Calls && Meetings", "Move to Project"]);
    expect(popup[3].items!.map((i) => i.label)).toEqual(["R&&D"]);
    // The page's own menus show the words as they are.
    expect(actionLabel("app.accountPlan", mac)).toBe("Account & Plan…");
    expect(menuItems(["live.autoRecord"], win).map((i) => i.label)).toEqual(["Auto-record calls & meetings"]);
  });

  describe("drawn again when the language changes", () => {
    beforeEach(() => {
      respond("app_menu_set", null);
      vi.useFakeTimers();
    });
    afterEach(() => {
      cleanup();
      vi.runOnlyPendingTimers();
      vi.useRealTimers();
    });
    function App() {
      useEffect(() => listenToMenuPicks("macos"), []);
      useMenuBar("macos");
      return null;
    }
    const settle = () => act(() => { vi.advanceTimersByTime(MENU_BAR_SETTLE_MS + 1); });
    const lastBar = () => callsOf("app_menu_set").at(-1)?.bar as NativeMenuBar;
    const newRecording = () => (lastBar().menus[1].items.find((e) => e.kind === "item" && e.action === "file.newRecording") as { label: string }).label;

    it("English at launch, Urdu once the account's language is, English again: never stuck in the first one", async () => {
      render(<App />);
      await settle();
      expect(lastBar().menus[1].title).toBe("File");
      expect(newRecording()).toBe("New Recording");
      const drawn = callsOf("app_menu_set").length;
      await urdu();
      await settle();
      expect(callsOf("app_menu_set").length).toBe(drawn + 1);
      expect(lastBar().menus.map((m) => m.title).slice(1, 3)).toEqual(["فائل", "ترمیم"]);
      expect(newRecording()).toBe("نئی ریکارڈنگ");
      await act(() => setLanguage("en", { root: false }));
      await settle();
      expect(lastBar().menus[1].title).toBe("File");
      expect(newRecording()).toBe("New Recording");
      setMenuPart("typing", null);
    });
  });
});

describe("a screen's own menus in an Urdu window: English where the screen still is, Urdu where it is converted", () => {
  const offs: (() => void)[] = [];
  afterEach(() => {
    offs.splice(0).forEach((off) => off());
  });
  const words = (menu: HTMLElement) => [...menu.querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent ?? "");
  type Line = { action: string | null; label?: string };
  const popupWords = (n = 0) => ((callsOf("native_menu_popup")[n] as { items: Line[] }).items).map((i) => i.label ?? "—");

  const activity = async () => {
    respond("pending_list", []);
    respond("skipped_list", []);
    respond("upload_list", []);
    respond("recovered_sessions", []);
    respond("activity_list", { data: [{ id: "n2", kind: "transcript_ready", title: "Transcript ready", body: "Acme kickoff", session_id: "s1", read: false, at: new Date(2026, 10, 24, 9).toISOString() }], unread_count: 1 });
    render(<ActivityV2 status={makeStatus()} onOpenRecording={vi.fn()} onReview={vi.fn()} onFailed={vi.fn()} onItems={vi.fn()} say={vi.fn()} />);
    await act(flushAsync);
    return screen.getByText("Transcript ready").closest("li") as HTMLElement;
  };

  it("Activity (English, not yet in Urdu): an item's right-click menu in English, on Windows and on a Mac", async () => {
    await urdu();
    fireEvent.contextMenu(await activity(), { clientX: 20, clientY: 30 });
    const menu = screen.getByRole("menu");
    expect(words(menu)).toEqual(["Open", "Mark as read"]);
    expect(menu.textContent).not.toMatch(URDU_OR_ISOLATE);
    cleanup();
    setPlatform("MacIntel");
    respond("native_menu_popup", null);
    fireEvent.contextMenu(await activity(), { clientX: 20, clientY: 30 });
    await act(flushAsync);
    expect(popupWords()).toEqual(["Open", "Mark as Read"]);
  });

  function Projects() {
    const { openAt, view } = useProjectMenu();
    return <><button type="button" onClick={() => openAt({ id: "m", name: "Mehran Foods" }, { x: 40, y: 100 })}>Mehran Foods</button>{view}</>;
  }

  it("a project's menu in the panel: in Urdu as the panel is, on Windows and on a Mac; English as before (its & written && for the core)", async () => {
    offs.push(...PROJECT_MENU.filter((id) => id !== "-").map((id) => registerAction(id, () => undefined)));
    await urdu();
    render(<Projects />);
    fireEvent.click(screen.getByRole("button", { name: "Mehran Foods" }));
    const urduWords = ["کھولیں", "پن کریں", "نام بدلیں…", "ہدایات اور یادداشت…", "فائلیں منگوائیں…", "ممبرز…", "آرکائیو کریں", "پروجیکٹ حذف کریں…"];
    expect(words(screen.getByRole("menu", { name: "Mehran Foods" }))).toEqual(urduWords);
    cleanup();
    setPlatform("MacIntel");
    respond("native_menu_popup", null);
    render(<Projects />);
    fireEvent.click(screen.getByRole("button", { name: "Mehran Foods" }));
    await act(flushAsync);
    expect(popupWords()).toEqual(["کھولیں", "پن کریں", "—", "نام بدلیں…", "ہدایات اور یادداشت…", "فائلیں منگوائیں…", "ممبرز…", "—", "آرکائیو کریں", "—", "پروجیکٹ حذف کریں…"]);
    cleanup();
    // English: as before, the Mac's Title Case and its & written && for the core.
    await act(() => setLanguage("en", { root: false }));
    render(<Projects />);
    fireEvent.click(screen.getByRole("button", { name: "Mehran Foods" }));
    await act(flushAsync);
    expect(popupWords(1)).toEqual(["Open", "Pin", "—", "Rename…", "Instructions && Memory…", "Request Files…", "Members…", "—", "Archive", "—", "Delete Project…"]);
  });
});

describe("⌘K in Urdu", () => {
  const offs: (() => void)[] = [];
  afterEach(() => {
    offs.splice(0).forEach((off) => off());
  });
  const palette = (onSidebar = vi.fn()) => {
    offs.push(registerAction("view.sidebar", onSidebar), registerAction("live.autoRecord", () => undefined), registerAction("help.shortcuts", () => undefined));
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} look="v2" inRow
      actionState={{ autoRecord: true }} />);
    return screen.getByRole("combobox");
  };
  const commands = () => within(screen.getByRole("group", { name: "کمانڈز" })).getAllByRole("option").map((o) => o.textContent);

  it("shows the action list's commands in Urdu, found by their Urdu and by their English; Enter runs the best", async () => {
    respond("search_library", { data: [] });
    await urdu();
    const onSidebar = vi.fn();
    const input = palette(onSidebar);
    expect(input).toHaveAttribute("placeholder", "ریکارڈنگز، ترتیبات، صفحات تلاش کریں… یا کوئی کمانڈ لکھیں");
    fireEvent.change(input, { target: { value: "سائیڈ" } });
    expect(commands()).toEqual(["سائیڈ بار چھپائیں"]);
    fireEvent.change(input, { target: { value: "sidebar" } });
    expect(commands()).toEqual(["سائیڈ بار چھپائیں"]);
    fireEvent.keyDown(input, { key: "Enter" });
    expect(onSidebar).toHaveBeenCalledTimes(1);
  });

  it("a ticked command says On or Off in Urdu; the frame (the groups, the foot, nothing found) is Urdu too", async () => {
    respond("search_library", { data: [] });
    await urdu();
    const input = palette();
    fireEvent.change(input, { target: { value: "auto-record" } });
    const auto = within(screen.getByRole("group", { name: "کمانڈز" })).getByRole("option", { name: /کالز اور میٹنگز خودکار ریکارڈ کریں/ });
    expect(auto.querySelector(".palette-detail")).toHaveTextContent("آن");
    fireEvent.change(input, { target: { value: "کی بورڈ" } });
    expect(commands()).toEqual(["کی بورڈ شارٹ کٹس"]);
    expect(document.querySelector(".palette-foot")).toHaveTextContent("↑ ↓ سے چنیں · Enter سے چلائیں · Esc سے بند کریں");
    fireEvent.change(input, { target: { value: "z" } });
    expect(document.querySelector(".palette-empty")).toHaveTextContent("ریکارڈنگز تلاش کرنے کے لیے ایک اور حرف لکھیں۔");
    // Nothing found: what was typed in its isolate.
    expect(emptyLine("zzqx", false)).toBe(`ریکارڈنگز، ٹرانسکرپٹس، ترتیبات یا صفحات میں “${iso("zzqx")}” سے کچھ نہیں ملا۔`);
    expect(emptyLine("zzqx", true)).toBe("ریکارڈنگز اور ٹرانسکرپٹس میں تلاش ہو رہی ہے…");
  });

  it("a Settings page's line is all Urdu (its name is); a page the shell names in English stays all English", async () => {
    expect(goToLine({ label: "Storage", name: "Storage", group: "Settings › App" })).toBe("Go to Storage · Settings › App");
    await urdu();
    expect(goToLine({ label: "اسٹوریج", name: "Storage", group: "ترتیبات › ایپ" })).toBe(`${iso("اسٹوریج")} پر جائیں · ترتیبات › ایپ`);
    expect(goToLine({ label: "Library" })).toBe("Go to Library");
  });

  it("in English the same rows, words and matching as before", () => {
    respond("search_library", { data: [] });
    const input = palette();
    fireEvent.change(input, { target: { value: "sidebar" } });
    expect(within(screen.getByRole("group", { name: "Commands" })).getAllByRole("option").map((o) => o.textContent)).toEqual(["Hide sidebar"]);
    expect(document.querySelector(".palette-foot")).toHaveTextContent("↑ ↓ to choose · Enter to run · Esc to close");
  });
});

describe("the file itself", () => {
  it("every menu bar title and titled sub-menu has its Urdu, and no label is a context key's English twice over", () => {
    const keys = ur as Record<string, string>;
    for (const key of ["File", "Go", "Window", "Script", "actions_context.menu_edit", "actions_context.menu_view", "actions_context.menu_find", "actions_context.zoom"]) {
      expect(keys[key], key).toBeTruthy();
    }
    // Only the zooms have a key of their own; every other label is its own key.
    expect(list.actions.filter((a) => a.label === "Zoom").map((a) => a.id)).toEqual(["window.zoom", "account.zoom"]);
  });
});
