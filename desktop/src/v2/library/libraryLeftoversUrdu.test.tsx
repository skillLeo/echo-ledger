// Desktop Urdu, D1b: the Library's leftovers in the account's language. The filter panel (its sections, options and
// counts, a section's Clear and search, Custom's days, Save as view), the pills and a pill's editor, the facets' names
// (read when shown, so counts made before a change of language still read right), labels (the list, New label with its
// name checks, Rename, Colour and Delete with their notices, Labels ▸), My views' messages (save, rename, the full and
// taken errors, a refusal), the queue at the top of the list (waiting calls, imports, uploads that need you, another
// account's, checking) and the import sheet with its drop hint. Every count goes through tc(), every value sits in its
// own isolate, a line of pieces reads right to left, and the dates are lib/format's. The classic callers of the words
// changed here keep their exact English. English is the area's own tests', unchanged.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import type { ClientFolder, LibraryItem, ListItem, ServerView } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makePendingItem, makeUploadItem } from "../../test/fixtures";
import { answerConfirm } from "../../test/confirm";
import { answerConfirmForTests } from "../../lib/confirm";
import { formatWhen } from "../../lib/format";
import { heldLine, importProgressText, waitingLine } from "../../lib/pending";
import { learnPrompt } from "../../lib/languages";
import { filedByRuleText, ruleText } from "../../lib/sharing";
import { CALL_WORDS } from "../../lib/callChoice";
import { FSI, PDI, RLI, resetLanguageForTests, setLanguage, t } from "../../i18n";
import { FiledByLine } from "../../views/main/FilingRules";
import { LibraryQueue } from "../../views/main/LibraryQueue";
import { importNotice } from "../../lib/importFiles";
import {
  facetCounts, LIBRARY_TAB_NAMES, LIBRARY_VIEW_NAMES, libraryViewCounts, libraryViewName, pillWords, prepare, SECTION_NAMES, sourceOf, setDate,
  type FacetOption, type FilterSpec, type SectionKey,
} from "./facets";
import { GROUP_NAMES, sections, SORT_NAMES } from "./filters";
import { groupTitle } from "./model";
import { FilterPanel, FilterPills } from "./FilterPanel";
import { addToListMenu, LabelsNav } from "./lists";
import { MAX_VIEWS, nameProblem, syncedViewsStore, VIEW_WORDS, viewsStore, type ViewSpec } from "./myViews";
import { LibraryNav, ViewNameDialog } from "./LibraryNav";
import { DropHintV2, ImportSheetV2, importHeading } from "./ImportSheetV2";
import { FILTER_DEBOUNCE_MS, LibraryV2 } from "./LibraryV2";
import { ALL_SCOPE, scopeKey, type LibraryScope } from "./scope";
import { resetSyncClock } from "./syncClock";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const iso = (value: string | number) => `${FSI}${value}${PDI}`;
/** Any Arabic-script letter or an isolate mark: what an English line must not have. */
const URDU_OR_ISOLATE = /[\u0600-\u06FF\u2066-\u2069]/;
/** Any Latin letter: what an Urdu line of the app's own words must not have. */
const LATIN = /[A-Za-z]/;

const NOW = new Date(2026, 10, 18, 15, 0);
const at = (day: number, hour: number) => new Date(2026, 10, day, hour).toISOString();
const done = (preview: string) => ({ final_status: "completed", final_transcript_id: `tr-${preview}`, live_available: false, preview });
const none = { final_status: null, final_transcript_id: null, live_available: false, preview: null };
const ACME = { id: "c1", name: "Acme" };
const ITEMS: LibraryItem[] = [
  makeItem("a", { title: "Ali", source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone", started_at: at(18, 11), duration_ms: 60_000, client: ACME, language_chip: "UR", labels: ["Q4"], transcript: done("one") }),
  makeItem("b", { title: "Bina", source_app: "zoom", source_app_label: "Zoom", source_device: "desktop", started_at: at(18, 9), duration_ms: 600_000, language_chip: "EN", transcript: done("two") }),
  makeItem("c", { title: "Chand", source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone", started_at: at(17, 9), duration_ms: 30_000, client: ACME, language_chip: "UR", pinned: true, status: "uploaded", transcript: none }),
  makeItem("d", { title: "Dawood", source_app: "voice_note", source_app_label: "Voice note", source_device: "desktop", started_at: at(10, 9), duration_ms: 120_000, marker_count: 2, tags: ["Price"], status: "failed", transcript: { ...none, final_status: "failed" } }),
];
/** A day as Custom's pill says it in Urdu: Urdu's month, Latin digits. */
const urduDay = (day: string) => new Date(`${day}T00:00:00`).toLocaleDateString("ur-PK", { day: "numeric", month: "short" });

beforeEach(async () => {
  resetTauriMock();
  resetSyncClock();
  resetLanguageForTests();
  window.localStorage.clear();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(NOW);
  await setLanguage("ur", { root: false });
});
afterEach(async () => {
  await flushAsync();
  answerConfirmForTests(null);
  vi.useRealTimers();
  resetLanguageForTests();
});

describe("the facets' names in Urdu", () => {
  it("the views, the tabs and the sections are read when shown; a view's English name stays for what the server reserves", () => {
    expect(Object.values(LIBRARY_VIEW_NAMES)).toEqual(["تمام ریکارڈنگز", "آپ کی توجہ چاہیے", "نہ پڑھی گئی", "اہم", "صرف آڈیو"]);
    expect(Object.values(LIBRARY_TAB_NAMES)).toEqual(["سب", "کالز", "میٹنگز", "وائس نوٹس", "دیگر"]);
    expect(Object.values(SECTION_NAMES)).toEqual(["تاریخ", "ذریعہ", "ڈیوائس", "پروجیکٹ", "زبان", "حالت", "لیبلز", "نشان زد", "بک مارکس"]);
    expect(libraryViewName("unread", "en")).toBe("Unread");
    expect([GROUP_NAMES.app, GROUP_NAMES.none, SORT_NAMES.newest, SORT_NAMES.title]).toEqual(["ذریعہ", "کچھ نہیں", "نئی پہلے", "نام (حروفِ تہجی)"]);
  });

  it("an app keeps its own name (Meet, Teams), the kinds that are words are Urdu", () => {
    const source = (app: string) => sourceOf(makeItem(app, { source_app: app })).label;
    expect(["whatsapp", "whatsapp_web", "google_meet", "microsoft_teams", "zoom", "whatsapp_business"].map(source))
      .toEqual(["WhatsApp", "WhatsApp", "Meet", "Teams", "Zoom", "WhatsApp Business"]);
    expect(["phone_call", "voice_note", "other", "facetime"].map(source)).toEqual(["سم کالز", "وائس نوٹس", "دیگر", "دیگر"]);
    expect(sourceOf(makeItem("i"), new Set(["i"])).label).toBe("امپورٹ شدہ");
  });

  it("counts worked out in English read in Urdu once the language changes (an option's words are read when shown)", async () => {
    await setLanguage("en", { root: false });
    const { facts, names } = prepare(ITEMS, { now: NOW.getTime() });
    const counts = facetCounts(facts, {}, names, NOW.getTime());
    const words = (o: FacetOption[]) => o.map((x) => `${x.label}${x.count}`);
    expect(words(counts.status)).toEqual(["Transcribed2", "Audio only1", "In progress0", "Failed1"]);
    await setLanguage("ur", { root: false });
    expect(words(counts.date)).toEqual(["آج2", "پچھلے 7 دن3", "اس مہینے4", "اپنی تاریخیں0"]);
    expect(words(counts.status)).toEqual(["ٹرانسکرپٹ تیار2", "صرف آڈیو1", "جاری ہے0", "ناکام1"]);
    expect(words(counts.device)).toEqual(["فون2", "کمپیوٹر2"]);
    expect(words(counts.language)).toEqual(["اردو2", "انگریزی1", "نامعلوم1"]);
    expect(words(counts.marked)).toEqual(["پن شدہ1", "اہم0", "ہمیشہ رکھیں0"]);
    expect(words(counts.project)).toEqual(["Acme2", "کوئی پروجیکٹ نہیں2"]);
    expect(words(counts.bookmarks)).toEqual(["کوئی بھی بک مارک1", "Price1"]);
  });

  it("a pill's words: the section and its values each in an isolate, is not, Custom's days in Urdu's months", () => {
    const { names } = prepare(ITEMS, { now: NOW.getTime() });
    const spec: FilterSpec = { source: { op: "is", values: ["whatsapp", "zoom"] }, status: { op: "is_not", values: ["failed"] } };
    expect(pillWords(spec, "source", names)).toBe(`${iso("ذریعہ")}: ${iso("WhatsApp، Zoom")}`);
    expect(pillWords(spec, "status", names)).toBe(`${iso("حالت")} نہیں ہے: ${iso("ناکام")}`);
    expect(pillWords({ date: { op: "is_not", values: ["today"] } }, "date", names)).toBe(`${iso("تاریخ")} نہیں ہے: ${iso("آج")}`);
    const range = setDate({}, "custom", { from: "2026-11-09", to: "2026-11-11" });
    expect(pillWords(range, "date", names)).toBe(`${iso("تاریخ")}: ${iso(`${iso(urduDay("2026-11-09"))} – ${iso(urduDay("2026-11-11"))}`)}`);
    expect(urduDay("2026-11-09")).not.toMatch(/Nov/);
    expect(pillWords(setDate({}, "custom", { from: "2026-11-09" }), "date", names)).toBe(`${iso("تاریخ")}: ${iso(`${iso(urduDay("2026-11-09"))} سے`)}`);
    expect(pillWords(setDate({}, "custom", { to: "2026-11-11" }), "date", names)).toBe(`${iso("تاریخ")}: ${iso(`${iso(urduDay("2026-11-11"))} تک`)}`);
  });

  it("a section's heading stays lib/libraryGroups' English, said in Urdu where it is shown", () => {
    const groups = sections(ITEMS, "none", "title", {}, true, NOW);
    expect(groups.map((g) => g.label)).toEqual(["Pinned", "Recordings"]);
    expect(groups.map(groupTitle)).toEqual(["پن شدہ", "ریکارڈنگز"]);
  });
});

describe("the filter panel in Urdu", () => {
  const counts = () => {
    const { facts, names } = prepare(ITEMS, { now: NOW.getTime() });
    return { counts: facetCounts(facts, {}, names, NOW.getTime()), names };
  };
  const panel = () => within(screen.getByTestId("filter-panel"));
  const section = (name: string) => within(panel().getByRole("region", { name }));
  const options = (name: string, role = "checkbox") => section(name).queryAllByRole(role).map((c) => c.closest("label")!.textContent);

  it("its head, its sections with their options and live counts, Save as view, the edge", () => {
    const onSaveView = vi.fn();
    const onClose = vi.fn();
    render(<FilterPanel spec={{}} onSpec={vi.fn()} counts={counts().counts} drawer={false} onClose={onClose} onSaveView={onSaveView} canSave />);
    expect(screen.getByTestId("filter-panel")).toHaveAccessibleName("فلٹرز");
    expect(panel().getByRole("heading", { level: 2 })).toHaveTextContent(/^فلٹرز$/);
    expect(panel().getAllByRole("region").map((r) => r.getAttribute("aria-label"))).toEqual(
      ["تاریخ", "ذریعہ", "ڈیوائس", "پروجیکٹ", "زبان", "حالت", "لیبلز", "نشان زد", "بک مارکس"]);
    expect(options("تاریخ", "radio")).toEqual(["کسی بھی وقت", "آج2", "پچھلے 7 دن3", "اس مہینے4", "اپنی تاریخیں"]);
    expect(section("تاریخ").getByRole("radiogroup")).toHaveAccessibleName("تاریخ");
    expect(options("ذریعہ")).toEqual(["WhatsApp2", "Zoom1", "وائس نوٹس1"]);
    expect(options("حالت")).toEqual(["ٹرانسکرپٹ تیار2", "صرف آڈیو1", "جاری ہے0", "ناکام1"]);
    expect(options("نشان زد")).toEqual(["پن شدہ1", "اہم0", "ہمیشہ رکھیں0"]);
    fireEvent.click(panel().getByRole("button", { name: "ویو کے طور پر محفوظ کریں" }));
    expect(onSaveView).toHaveBeenCalled();
    expect(panel().getByRole("separator")).toHaveAccessibleName("فلٹرز کا سائز بدلیں");
    fireEvent.click(panel().getByRole("button", { name: "فلٹرز بند کریں" }));
    expect(onClose).toHaveBeenCalled();
  });

  it("a section that is on: its dot and Clear; a long one searches; an empty one says so; Custom's two days", () => {
    const onSpec = vi.fn();
    const many: FacetOption[] = Array.from({ length: 8 }, (_, i) => ({ value: `p${i}`, label: `Project ${i}`, count: 1 }));
    const spec: FilterSpec = { source: { op: "is", values: ["whatsapp"] }, ...setDate({}, "custom", { from: "2026-11-09" }) };
    render(<FilterPanel spec={spec} onSpec={onSpec} counts={{ ...counts().counts, project: many, labels: [] }} drawer={false} onClose={vi.fn()} onSaveView={vi.fn()} canSave />);
    // Two sections on: the head counts them.
    expect(panel().getByRole("heading", { level: 2 })).toHaveTextContent(/^فلٹرز2$/);
    expect(section("ذریعہ").getByRole("img")).toHaveAccessibleName("لگا ہے");
    fireEvent.click(section("ذریعہ").getByRole("button", { name: `${iso("ذریعہ")} صاف کریں` }));
    expect(onSpec).toHaveBeenLastCalledWith(expect.not.objectContaining({ source: expect.anything() }));
    expect(section("ذریعہ").getByRole("button", { name: `${iso("ذریعہ")} صاف کریں` })).toHaveTextContent(/^صاف کریں$/);
    const find = section("پروجیکٹ").getByRole("searchbox");
    expect(find).toHaveAccessibleName(`${iso("پروجیکٹ")} میں تلاش کریں`);
    // Empty, its words read right to left (they start with the section's name in an isolate); typed, its own direction.
    expect(find).toHaveAttribute("dir", "rtl");
    fireEvent.change(find, { target: { value: "zzz" } });
    expect(find).toHaveAttribute("dir", "auto");
    expect(section("پروجیکٹ").getByRole("button", { name: "تلاش صاف کریں" })).toBeInTheDocument();
    expect(section("پروجیکٹ").getByText("کوئی نتیجہ نہیں")).toBeInTheDocument();
    expect(section("لیبلز").getByText("ابھی کچھ نہیں")).toBeInTheDocument();
    expect(section("تاریخ").getByLabelText("سے")).toHaveValue("2026-11-09");
    expect(section("تاریخ").getByLabelText("تک")).toHaveValue("");
  });

  it("the pills: each whole and right to left, its title and ×, then Clear all, Save changes and Save as view; a pill's editor", () => {
    const onSpec = vi.fn();
    const { counts: all, names } = counts();
    const spec: FilterSpec = { source: { op: "is", values: ["whatsapp", "zoom"] }, status: { op: "is_not", values: ["failed"] } };
    render(<FilterPills spec={spec} onSpec={onSpec} counts={() => all} names={names} onClearAll={vi.fn()} onSaveView={vi.fn()} onSaveChanges={vi.fn()} />);
    const pills = within(screen.getByRole("group", { name: "لگے ہوئے فلٹرز" }));
    const source = pills.getByRole("button", { name: `${iso("ذریعہ")}: ${iso("WhatsApp، Zoom")}` });
    expect(source).toHaveAttribute("title", `${iso(`${iso("ذریعہ")}: ${iso("WhatsApp، Zoom")}`)} بدلیں`);
    expect(source.querySelector("bdi")).toHaveAttribute("dir", "rtl");
    expect(pills.getByRole("button", { name: `${iso(`${iso("حالت")} نہیں ہے: ${iso("ناکام")}`)} ہٹائیں` })).toBeInTheDocument();
    expect(pills.getAllByRole("button").slice(-3).map((b) => b.textContent)).toEqual(["سب صاف کریں", "تبدیلیاں محفوظ کریں", "ویو کے طور پر محفوظ کریں"]);
    fireEvent.click(source);
    const editor = within(screen.getByRole("dialog", { name: `${iso("ذریعہ")} بدلیں` }));
    expect(editor.getByRole("radiogroup", { name: `${iso("ذریعہ")} ہے یا نہیں ہے` })).toBeInTheDocument();
    expect(editor.getAllByRole("radio").map((r) => r.textContent)).toEqual(["ہے", "نہیں ہے"]);
    expect(editor.getAllByRole("checkbox").map((c) => c.closest("label")!.textContent)).toEqual(["WhatsApp2", "Zoom1", "وائس نوٹس1"]);
    fireEvent.click(editor.getByRole("radio", { name: "نہیں ہے" }));
    expect(onSpec).toHaveBeenLastCalledWith({ ...spec, source: { op: "is_not", values: ["whatsapp", "zoom"] } });
    expect(editor.getByRole("button", { name: "ہو گیا" })).toBeInTheDocument();
  });

  it("in the Library: Filters opens the panel, a tick puts its pill up in Urdu after the list follows", async () => {
    Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("library_folder", []);
    respond("bin_list", { data: [] });
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: /^فلٹرز/ }));
    fireEvent.click(section("ذریعہ").getByRole("checkbox", { name: /^WhatsApp/ }));
    await act(() => new Promise((r) => setTimeout(r, FILTER_DEBOUNCE_MS + 30)));
    expect(within(screen.getByRole("group", { name: "لگے ہوئے فلٹرز" })).getByRole("button", { name: `${iso("ذریعہ")}: ${iso("WhatsApp")}` })).toBeInTheDocument();
    Object.defineProperty(window, "innerWidth", { value: 1024, configurable: true, writable: true });
  });
});

describe("labels in Urdu", () => {
  const LISTS: ListItem[] = [
    { key: "clients", name: "Clients", color: "#26978f", position: 0, count: 2 },
    { key: "home", name: "Home", color: "#b3820f", position: 1, count: 1 },
    { key: "family", name: "Family", color: null, position: 2, count: 0 },
  ];
  const show = async () => {
    respond("lists_get", LISTS);
    const say = vi.fn();
    render(<nav aria-label="Main"><LabelsNav counts={{ clients: 2, home: 1 }} current={null} onOpen={vi.fn()} onGone={vi.fn()} say={say} onLibraryChanged={vi.fn()} /></nav>);
    await act(flushAsync);
    return say;
  };
  const labels = () => within(screen.getByRole("list", { name: "لیبلز" }));
  /**
   * A label's menu item by its action (actions.json's words are not this package's: D-248 converts them, and these
   * tests then still find the items).
   */
  const action = (id: string) => document.querySelector<HTMLElement>(`[role^="menuitem"][data-action="${id}"]`)!;

  it("the section, each label by its own name and count, its ⋮, and + New label", async () => {
    await show();
    expect(screen.getByRole("region", { name: "لیبلز" })).toBeInTheDocument();
    expect(labels().getAllByRole("button").filter((b) => !b.className.includes("el-nav-row__more")).map((b) => b.getAttribute("aria-label") ?? b.textContent))
      .toEqual(["Clients, 2", "Home, 1", "Family, 0", "نیا لیبل"]);
    expect(labels().getByRole("button", { name: `${iso("Clients")} کے مزید اختیارات` })).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: "نیا لیبل" })).toHaveLength(2);
  });

  it("New label: its words, the colours' names, and a name in use or a library view's refused, in English or Urdu", async () => {
    respond("list_create", { key: "suppliers", name: "Suppliers", color: "#8e8a80", position: 3, count: 0 });
    await show();
    fireEvent.click(labels().getByRole("button", { name: "نیا لیبل" }));
    const dialog = within(screen.getByRole("dialog", { name: "نیا لیبل" }));
    expect(dialog.getByRole("radiogroup", { name: "رنگ" })).toBeInTheDocument();
    expect(dialog.getAllByRole("radio").map((r) => r.getAttribute("aria-label"))).toEqual(["سرمئی", "سرخ", "نارنجی", "کہربائی", "سبز", "فیروزی", "نیلا", "گلابی"]);
    expect(dialog.getByRole("button", { name: "بند کریں" })).toBeInTheDocument();
    const field = dialog.getByLabelText("لیبل کا نام");
    fireEvent.change(field, { target: { value: "clients" } });
    expect(dialog.getByText(`آپ کے پاس پہلے سے ${iso("Clients")} نام کا لیبل ہے۔`)).toBeInTheDocument();
    fireEvent.change(field, { target: { value: "اہم" } });
    expect(dialog.getByText(`${iso("اہم")} لائبریری کے ویوز میں سے ایک ہے۔ کوئی اور نام چنیں۔`)).toBeInTheDocument();
    fireEvent.change(field, { target: { value: "UNREAD" } });
    expect(dialog.getByText(`${iso("Unread")} لائبریری کے ویوز میں سے ایک ہے۔ کوئی اور نام چنیں۔`)).toBeInTheDocument();
    expect(dialog.getByRole("button", { name: "بنائیں" })).toBeDisabled();
    fireEvent.change(field, { target: { value: "Suppliers" } });
    fireEvent.click(dialog.getByRole("button", { name: "بنائیں" }));
    await act(flushAsync);
    expect(callsOf("list_create")).toEqual([{ name: "Suppliers", color: "#8e8a80" }]);
    fireEvent.click(labels().getByRole("button", { name: "نیا لیبل" }));
    fireEvent.click(within(screen.getByRole("dialog", { name: "نیا لیبل" })).getByRole("button", { name: "منسوخ کریں" }));
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("Rename label says the new name; Colour › names the eight; Delete label asks and says it went", async () => {
    respond("list_update", { key: "customers", name: "Customers", color: "#26978f", position: 0, count: 2 });
    respond("list_delete", 1);
    const say = await show();
    fireEvent.contextMenu(labels().getByRole("button", { name: /^Clients, / }));
    fireEvent.click(action("file.rename"));
    const rename = within(screen.getByRole("dialog", { name: "لیبل کا نام بدلیں" }));
    fireEvent.change(rename.getByLabelText("لیبل کا نام"), { target: { value: "home" } });
    expect(rename.getByText(`آپ کے پاس پہلے سے ${iso("Home")} نام کا لیبل ہے۔`)).toBeInTheDocument();
    fireEvent.change(rename.getByLabelText("لیبل کا نام"), { target: { value: "Customers" } });
    fireEvent.click(rename.getByRole("button", { name: "محفوظ کریں" }));
    await act(flushAsync);
    expect(say).toHaveBeenLastCalledWith(`نام بدل کر ${iso("Customers")} کر دیا گیا`);

    fireEvent.contextMenu(labels().getByRole("button", { name: /^Home, / }));
    fireEvent.click(action("label.colour"));
    // Colour's own menu (named by actions.json's word, D-248's): the last one open.
    const colours = screen.getAllByRole("menu").at(-1)!;
    expect(within(colours).getAllByRole("menuitemradio").map((m) => m.textContent)).toEqual(["سرمئی", "سرخ", "نارنجی", "کہربائی", "سبز", "فیروزی", "نیلا", "گلابی"]);
    expect(within(colours).getByRole("menuitemradio", { name: "کہربائی" })).toHaveAttribute("aria-checked", "true");
    fireEvent.keyDown(screen.getAllByRole("menu")[0], { key: "Escape" });

    fireEvent.contextMenu(labels().getByRole("button", { name: /^Family, / }));
    fireEvent.click(action("label.delete"));
    const ask = screen.getByRole("alertdialog", { name: `${iso("Family")} حذف کریں؟` });
    expect(ask).toHaveTextContent("اس کی ریکارڈنگز آپ کی لائبریری میں رہیں گی؛ صرف لیبل ہٹے گا۔");
    expect(within(ask).getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent)).toEqual(["بند کریں", "منسوخ کریں", "حذف کریں"]);
    fireEvent.click(within(ask).getByRole("button", { name: "حذف کریں" }));
    await act(flushAsync);
    expect(callsOf("list_delete")).toEqual([{ key: "family" }]);
    expect(say).toHaveBeenLastCalledWith(`${iso("Family")} حذف ہو گیا · اس کی ریکارڈنگز آپ کی لائبریری میں موجود ہیں`);
  });

  it("Rename of a label already called by a view's Urdu name: no word against its own name, another view's still refused", async () => {
    respond("lists_get", [{ key: "اہم", name: "اہم", color: "#26978f", position: 0, count: 0 }]);
    render(<nav aria-label="Main"><LabelsNav counts={{}} current={null} onOpen={vi.fn()} onGone={vi.fn()} say={vi.fn()} onLibraryChanged={vi.fn()} /></nav>);
    await act(flushAsync);
    fireEvent.contextMenu(labels().getByRole("button", { name: /^اہم, / }));
    fireEvent.click(action("file.rename"));
    const rename = within(screen.getByRole("dialog", { name: "لیبل کا نام بدلیں" }));
    expect(rename.getByLabelText("لیبل کا نام")).toHaveValue("اہم");
    expect(rename.queryByText(/لائبریری کے ویوز میں سے ایک ہے/)).toBeNull();
    fireEvent.change(rename.getByLabelText("لیبل کا نام"), { target: { value: "نہ پڑھی گئی" } });
    expect(rename.getByText(`${iso("نہ پڑھی گئی")} لائبریری کے ویوز میں سے ایک ہے۔ کوئی اور نام چنیں۔`)).toBeInTheDocument();
    expect(rename.getByRole("button", { name: "محفوظ کریں" })).toBeDisabled();
  });

  it("Labels ▸ in a recording's menu: Labels, in Organise, with New label… at its end", () => {
    const menu = addToListMenu({ lists: LISTS, chosen: [], onToggle: vi.fn(), onNew: vi.fn() });
    expect([menu.label, menu.group]).toEqual(["لیبلز", "منظم کریں"]);
    expect(menu.items?.map((i) => i.label)).toEqual(["Clients", "Home", "Family", "نیا لیبل…"]);
  });
});

describe("My views in Urdu", () => {
  const SPEC: ViewSpec = { filters: { source: { op: "is", values: ["whatsapp"] } }, search: null, sort: "newest", group: "date", density: null };
  const server = (id: string, name: string): ServerView => ({ id, name, filters: {}, search: null, sort: "newest", group: "date", density: "comfortable", position: 0 });
  /** The server's views: creates made (or refused as full), the version counted. */
  function views(start: ServerView[], full = false) {
    const state = { views: [...start], version: 1 };
    respondWith("views_get", () => ({ data: state.views, views_version: state.version }));
    respondWith("library_views_version", () => state.version);
    respondWith("view_create", ({ view }) => {
      if (full) return { refused: { status: 422, code: "views_limit", message: "The server did not accept this." } };
      const made = { ...(view as ServerView), id: `v${state.views.length + 1}`, position: state.views.length };
      state.views.push(made);
      return { data: made, views_version: ++state.version };
    });
    return state;
  }

  it("a name's problems and the store's refusals, each whole, the numbers and names isolated", async () => {
    views([server("v1", "Clients")]);
    const store = syncedViewsStore();
    await expect(store.create("Early", SPEC)).rejects.toThrow("آپ کے ویوز ابھی لوڈ ہو رہے ہیں۔ تھوڑی دیر میں دوبارہ کوشش کریں۔");
    store.use("a");
    await store.sync();
    await expect(store.create("clients", SPEC)).rejects.toThrow(`آپ کے پاس پہلے سے ${iso("Clients")} نام کا ویو ہے۔`);
    await expect(store.create("   ", SPEC)).rejects.toThrow("ویو کو نام دیں۔");
    await expect(store.create("x".repeat(61), SPEC)).rejects.toThrow(`زیادہ سے زیادہ ${iso(60)} حروف استعمال کریں۔`);
    await expect(store.rename("gone", "Other")).rejects.toThrow("یہ ویو اب نہیں رہا۔ شاید کسی اور ڈیوائس پر حذف ہو گیا ہو۔");
    expect(VIEW_WORDS.full).toBe(`آپ زیادہ سے زیادہ ${iso(MAX_VIEWS)} ویوز رکھ سکتے ہیں۔ ایک حذف کریں پھر دوسرا محفوظ کریں۔`);
    expect(nameProblem("Fine", store.list())).toBeNull();
  });

  it("a view the server refuses as full goes, and the notice says so in one sentence", async () => {
    views([], true);
    const store = syncedViewsStore();
    store.use("a");
    const heard: string[] = [];
    store.onProblem((text) => heard.push(text));
    await store.create("Late", SPEC);
    await store.sync();
    expect(heard).toEqual([`${iso("Late")} محفوظ نہیں ہوا: ${iso(VIEW_WORDS.full)}`]);
    expect(VIEW_WORDS.notChanged("One", "No.")).toBe(`${iso("One")} میں آپ کی تبدیلیاں محفوظ نہیں ہوئیں: ${iso("No.")}`);
    expect(VIEW_WORDS.notDeleted("Two", "No.")).toBe(`${iso("Two")} حذف نہیں ہوا: ${iso("No.")}`);
  });

  it("Save as view from the pills: a name in use is refused in Urdu, a new one saved; Rename view refuses another's name", async () => {
    Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("library_folder", []);
    respond("bin_list", { data: [] });
    respond("lists_get", []);
    views([server("v1", "Clients")]);
    viewsStore.use(null);
    viewsStore.use("https://ledger.example.test|me@example.test|1");
    function App() {
      const [scope, setScope] = useState<LibraryScope>(ALL_SCOPE);
      const [request, setRequest] = useState<{ scope: LibraryScope; seq: number } | null>(null);
      const [seen, setSeen] = useState<{ items: readonly LibraryItem[]; read: ReadonlySet<string> } | null>(null);
      const facts = seen ? prepare(seen.items, { read: seen.read }) : null;
      const open = (next: LibraryScope) => {
        setScope(next);
        setRequest((r) => ({ scope: next, seq: (r?.seq ?? 0) + 1 }));
      };
      return (
        <>
          <nav aria-label="Main">
            <LibraryNav current={scopeKey(scope)} counts={facts ? libraryViewCounts(facts.facts) : null} labelCounts={{}} onScope={open}
              onNewView={() => undefined} say={() => undefined} onLibraryChanged={() => undefined} />
          </nav>
          <LibraryV2 scopeRequest={request} onScope={setScope} onItems={(items, read) => setSeen({ items, read })} />
        </>
      );
    }
    render(<App />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: /^فلٹرز/ }));
    fireEvent.click(within(within(screen.getByTestId("filter-panel")).getByRole("region", { name: "ذریعہ" })).getByRole("checkbox", { name: /^WhatsApp/ }));
    await act(() => new Promise((r) => setTimeout(r, FILTER_DEBOUNCE_MS + 30)));
    fireEvent.click(within(screen.getByRole("group", { name: "لگے ہوئے فلٹرز" })).getByRole("button", { name: "ویو کے طور پر محفوظ کریں" }));
    const dialog = within(screen.getByRole("dialog", { name: "ویو کے طور پر محفوظ کریں" }));
    const field = dialog.getByRole("textbox");
    fireEvent.change(field, { target: { value: "CLIENTS" } });
    expect(dialog.getByText(`آپ کے پاس پہلے سے ${iso("Clients")} نام کا ویو ہے۔`)).toBeInTheDocument();
    fireEvent.change(field, { target: { value: "WhatsApp calls" } });
    fireEvent.click(dialog.getByRole("button", { name: "ویو محفوظ کریں" }));
    await act(flushAsync);
    expect(viewsStore.list().map((v) => v.name)).toEqual(["Clients", "WhatsApp calls"]);
    render(<ViewNameDialog title={t("Rename view")} save={t("Save")} start="WhatsApp calls" except={viewsStore.list()[1].id} onClose={vi.fn()} onSave={vi.fn()} />);
    const rename = within(screen.getByRole("dialog", { name: "ویو کا نام بدلیں" }));
    fireEvent.change(rename.getByRole("textbox"), { target: { value: "clients" } });
    expect(rename.getByText(`آپ کے پاس پہلے سے ${iso("Clients")} نام کا ویو ہے۔`)).toBeInTheDocument();
    viewsStore.use(null);
    Object.defineProperty(window, "innerWidth", { value: 1024, configurable: true, writable: true });
  });
});

describe("the queue at the top of the Library in Urdu", () => {
  const CLIENTS = { data: [{ id: "c1", name: "Acme Holdings", language: null }, { id: "c2", name: "Northwind", language: null }] };
  async function show(pending = [makePendingItem("p1")], uploads: ReturnType<typeof makeUploadItem>[] = [], view: "needs" | "all" = "needs") {
    respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("bin_list", { data: [] });
    respond("pending_list", pending);
    respond("upload_list", uploads);
    respond("list_clients", CLIENTS);
    respond("pending_set_language", undefined);
    respond("pending_keep", undefined);
    respond("pending_delete", 1);
    respond("upload_retry", undefined);
    respond("upload_discard", undefined);
    render(<LibraryV2 scopeRequest={{ scope: { kind: "view", view }, seq: 1 }} />);
    await act(flushAsync);
  }
  const rowOf = (title: string) => screen.getByText(title).closest("li") as HTMLElement;
  /** The Library's notices, as shown. */
  const toasts = () => document.querySelector(".el-toast-region") as HTMLElement;

  it("its questions and notices are the window's language: Delete, Transcribe, an upload's Discard, an import's Cancel", async () => {
    const asked = answerConfirm().mockReturnValue(true);
    await show([makePendingItem("p1", { title: "Keep me" }), makePendingItem("p2", { title: "Drop me" })], [
      makeUploadItem("u1", { state: "failed", title: "Zoom call", lastError: "The server could not verify this upload." }),
    ]);
    fireEvent.click(within(rowOf("Drop me")).getByRole("button", { name: "حذف کریں" }));
    await act(flushAsync);
    expect(asked).toHaveBeenLastCalledWith("یہ ریکارڈنگ حذف کریں؟ آپ چند سیکنڈ تک اسے واپس کر سکتے ہیں۔");
    expect(callsOf("pending_delete")).toEqual([{ ids: ["p2"] }]);
    // "Deleted · Undo": the notice, its Undo a button of its own.
    expect(toasts()).toHaveTextContent("حذف ہو گئی");
    expect(within(toasts()).getByRole("button", { name: "واپس کریں" })).toBeInTheDocument();
    fireEvent.click(within(rowOf("Keep me")).getByRole("button", { name: "ٹرانسکرائب کریں" }));
    await act(flushAsync);
    expect(toasts()).toHaveTextContent("ٹرانسکرپٹ بن رہا ہے…");
    fireEvent.click(within(rowOf("Zoom call")).getByRole("button", { name: "ضائع کریں" }));
    await act(flushAsync);
    expect(asked).toHaveBeenLastCalledWith("یہ ریکارڈنگ ضائع کریں؟ یہ واپس نہیں ہو سکتا۔");
    expect(toasts()).toHaveTextContent("ضائع کر دی گئی");
    expect(toasts().textContent).not.toMatch(/Transcribing|Deleted|Discarded|Undo/);
  });

  it("an import's Cancel says so in Urdu", async () => {
    await show([], [makeUploadItem("i1", { imported: true, title: "interview.m4a", state: "uploading", progress: 40 })], "all");
    fireEvent.click(within(rowOf("interview.m4a")).getByRole("button", { name: "منسوخ کریں" }));
    await act(flushAsync);
    expect(callsOf("upload_discard")).toEqual([{ id: "i1" }]);
    expect(toasts()).toHaveTextContent("امپورٹ منسوخ کر دی گئی");
  });

  it("waiting calls: the line, Review and Hide, the bulk buttons, each row's line of pieces and buttons, and its menu", async () => {
    const pending = [
      makePendingItem("p1", { title: "Board call", sourceApp: "zoom", startedAt: new Date(2026, 10, 18, 11, 0).toISOString(), durationMs: 750_000, firstLine: "Thanks for joining" }),
      makePendingItem("p2", { title: "Second" }),
    ];
    await show(pending);
    const card = screen.getByRole("region", { name: "منتظر ریکارڈنگز" });
    expect(within(card).getByTestId("waiting-line")).toHaveTextContent(waitingLine(pending));
    // Needs you opens the list: Hide folds it, Review opens it again.
    fireEvent.click(within(card).getByRole("button", { name: "چھپائیں" }));
    fireEvent.click(within(card).getByRole("button", { name: "دیکھیں" }));
    expect(within(card).getAllByRole("button").slice(1, 4).map((b) => b.textContent)).toEqual(["سب ٹرانسکرائب کریں", CALL_WORDS.save_audio_all, "سب حذف کریں"]);
    const row = rowOf("Board call");
    // A line of pieces, right to left, that wraps onto a second line before its start could be cut.
    expect(row).toHaveClass("el-row--meta-2");
    const meta = row.querySelector<HTMLElement>(".el-row__meta-text [dir=rtl]")!;
    expect([...meta.querySelectorAll("bdi")].map((b) => b.textContent)).toEqual(["Zoom", formatWhen(pending[0].startedAt), "12:30", "Thanks for joining"]);
    expect(formatWhen(pending[0].startedAt).startsWith(RLI)).toBe(true);
    expect(within(row).getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent))
      .toEqual([`${iso("Board call")} کے مزید اختیارات`, "حذف کریں", CALL_WORDS.save_audio_only, "ٹرانسکرائب کریں"]);
    expect(within(card).getByRole("list", { name: "منتظر ریکارڈنگز" })).toBeInTheDocument();
    fireEvent.click(within(row).getByRole("button", { name: `${iso("Board call")} کے مزید اختیارات` }));
    expect(screen.getAllByRole("menuitem").map((m) => m.textContent)).toEqual(["نام بدلیں…", "پروجیکٹ چنیں", "زبان"]);
    fireEvent.click(screen.getByRole("menuitem", { name: "پروجیکٹ چنیں" }));
    expect(within(screen.getByRole("menu", { name: "پروجیکٹ چنیں" })).getAllByRole("menuitemradio").map((m) => m.textContent))
      .toEqual(["کوئی پروجیکٹ نہیں", "Acme Holdings", "Northwind"]);
  });

  it("Language ▸ then Remember the language, asked in Urdu with Yes and No; Rename… in Urdu", async () => {
    respond("pending_set_language", makePendingItem("p1", { title: "A call", clientId: "c1", clientName: "Acme Holdings", language: "ur" }));
    respond("pending_rename", makePendingItem("p1", { title: "Renamed", clientId: "c1", clientName: "Acme Holdings" }));
    await show([makePendingItem("p1", { title: "A call", clientId: "c1", clientName: "Acme Holdings" })]);
    const pick = (path: string[]) => {
      fireEvent.click(within(rowOf("A call")).getByRole("button", { name: `${iso("A call")} کے مزید اختیارات` }));
      for (const [i, name] of path.entries()) {
        const item = screen.getByRole(i === path.length - 1 && path.length > 1 ? "menuitemradio" : "menuitem", { name });
        if (i < path.length - 1) fireEvent.keyDown(item, { key: "ArrowRight" });
        fireEvent.click(item);
      }
    };
    pick(["زبان"]);
    expect(within(screen.getByRole("menu", { name: "زبان" })).getByText("مزید زبانیں")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("menuitemradio", { name: /^اردو/ }));
    await act(flushAsync);
    const ask = screen.getByRole("group", { name: "زبان یاد رکھیں" });
    expect(ask).toHaveTextContent(learnPrompt("Acme Holdings", null, "ur")!);
    expect(ask.textContent).not.toMatch(/Use /);
    expect(within(ask).getAllByRole("button").map((b) => b.textContent)).toEqual(["ہاں", "نہیں"]);
    respond("client_update", { id: "c1", name: "Acme Holdings", language: "ur" });
    fireEvent.click(within(ask).getByRole("button", { name: "ہاں" }));
    await act(flushAsync);
    expect(callsOf("client_update")).toEqual([{ id: "c1", patch: { language: "ur" } }]);
    expect(toasts()).toHaveTextContent("اس پروجیکٹ کے لیے محفوظ ہو گئی");
    pick(["نام بدلیں…"]);
    const dialog = within(screen.getByRole("dialog", { name: "نام بدلیں" }));
    expect(dialog.getByRole("textbox", { name: "عنوان" })).toHaveValue("A call");
    expect(dialog.getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent)).toEqual(["بند کریں", "منسوخ کریں", "محفوظ کریں"]);
  });

  it("imports, uploads that need you, another account's and those being checked: their headings with counts, buttons and lines", async () => {
    const importing = [makeUploadItem("i1", { imported: true, title: "interview.m4a", state: "uploading", progress: 40 }), makeUploadItem("i2", { imported: true, title: "notes.mp3" })];
    await show([], importing, "all");
    expect(screen.getByRole("heading", { name: `امپورٹ ہو رہی ہیں · ${iso(2)}` })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "امپورٹ ہو رہی ہیں" })).toBeInTheDocument();
    expect(rowOf("interview.m4a")).toHaveTextContent(importProgressText(importing[0]));
    expect(screen.getByRole("progressbar", { name: `${iso("interview.m4a")} کا اپ لوڈ` })).toHaveAttribute("aria-valuenow", "40");
    expect(within(rowOf("interview.m4a")).getByRole("button")).toHaveTextContent("منسوخ کریں");
  });

  it("uploads that need you (Retry, Discard), another account's (Discard all N) and Checking upload…", async () => {
    answerConfirm().mockReturnValue(true);
    const held = (id: string, title: string) => makeUploadItem(id, { title, heldFor: "ayesha@example.test" });
    await show([], [
      makeUploadItem("u1", { state: "failed", title: "Zoom call", lastError: "The server could not verify this upload." }),
      held("h1", "First"), held("h2", "Second"),
    ]);
    expect(screen.getByRole("heading", { name: `اپ لوڈز جنہیں آپ کی ضرورت ہے · ${iso(1)}` })).toBeInTheDocument();
    expect(within(rowOf("Zoom call")).getAllByRole("button").map((b) => b.textContent)).toEqual(["دوبارہ کوشش کریں", "ضائع کریں"]);
    fireEvent.click(within(rowOf("Zoom call")).getByRole("button", { name: "دوبارہ کوشش کریں" }));
    await act(flushAsync);
    expect(callsOf("upload_retry")).toEqual([{ id: "u1" }]);
    expect(screen.getByRole("heading", { name: `کسی اور اکاؤنٹ کا انتظار · ${iso(2)}` })).toBeInTheDocument();
    const account = screen.getByRole("region", { name: `${iso("ayesha@example.test")} کا انتظار` });
    expect(account).toHaveTextContent(heldLine("ayesha@example.test", 2));
    expect(within(account).getByRole("button", { name: `تمام ${iso(2)} ضائع کریں` })).toBeInTheDocument();
    expect(within(rowOf("First")).getByRole("button")).toHaveTextContent("ضائع کریں");
  });

  it("Checking upload…: in its own list, without actions", async () => {
    await show([], [makeUploadItem("v1", { state: "verifying", title: "Checked call" })], "all");
    expect(screen.getByRole("list", { name: "اپ لوڈز کی جانچ" })).toBeInTheDocument();
    expect(rowOf("Checked call")).toHaveTextContent("اپ لوڈ کی جانچ ہو رہی ہے…");
    expect(within(rowOf("Checked call")).queryByRole("button")).toBeNull();
  });
});

describe("the import sheet in Urdu", () => {
  const NOTE = "C:\\Users\\me\\Downloads\\PTT-20261030-WA0003.opus";
  const VIDEO = "C:\\Users\\me\\Videos\\Call_with_Acme_Oct.mp4";
  const TEXT = "C:\\Users\\me\\Desktop\\readme.txt";
  const PROJECTS: ClientFolder[] = [{ id: "c1", name: "Acme Holdings" } as ClientFolder];
  beforeEach(() => {
    respond("list_clients", { data: PROJECTS });
    respond("import_voice_notes", { queued: 2, duplicates: 0, unsupported: 0, failed: 0 });
  });

  it("its heading by the count (one form or the other), its words, the files with the one it can't take, the choices and Import", async () => {
    render(<div className="el-root"><ImportSheetV2 paths={[NOTE, VIDEO, TEXT]} notify={vi.fn()} onClose={vi.fn()} /></div>);
    await act(flushAsync);
    const dialog = within(screen.getByRole("dialog", { name: `${iso(2)} فائلیں امپورٹ کریں` }));
    expect(dialog.getByText("ہر فائل اپنی الگ ریکارڈنگ بنتی ہے، فائل کے نام سے۔ ویڈیوز ان کی آواز سے ٹرانسکرائب ہوتی ہیں۔")).toBeInTheDocument();
    expect(dialog.getAllByRole("listitem").map((li) => li.lastElementChild?.textContent)).toEqual([
      "PTT-20261030-WA0003.opus", "Call_with_Acme_Oct.mp4", "امپورٹ نہیں ہو سکتی: یہ وائس نوٹ، آڈیو یا ویڈیو فائل نہیں",
    ]);
    expect(dialog.getByRole("list", { name: "امپورٹ کی جانے والی فائلیں" })).toBeInTheDocument();
    expect(dialog.getByRole("combobox", { name: /^پروجیکٹ/ })).toHaveTextContent("کوئی پروجیکٹ نہیں");
    expect(dialog.getByRole("combobox", { name: /^زبان/ })).toBeInTheDocument();
    expect(dialog.getByText("پروجیکٹ اور زبان ہر فائل پر لاگو ہوتے ہیں۔ جو فائلیں پہلے سے آپ کی لائبریری میں ہیں وہ چھوڑ دی جاتی ہیں۔")).toBeInTheDocument();
    expect(dialog.getAllByRole("button").map((b) => b.textContent).slice(-2)).toEqual(["منسوخ کریں", `${iso(2)} فائلیں امپورٹ کریں`]);
    expect([importHeading(0), importHeading(1), importHeading(3)]).toEqual(["امپورٹ کے لیے کچھ نہیں", `${iso(1)} فائل امپورٹ کریں`, `${iso(3)} فائلیں امپورٹ کریں`]);
  });

  it("one file: Import alone; while it imports, Importing…; a refusal is the core's own words and the sheet stays", async () => {
    let answer: (v: unknown) => void = () => undefined;
    respondWith("import_voice_notes", () => new Promise((r) => { answer = r; }));
    const { unmount } = render(<div className="el-root"><ImportSheetV2 paths={[NOTE]} notify={vi.fn()} onClose={vi.fn()} /></div>);
    await act(flushAsync);
    expect(screen.getByRole("dialog", { name: `${iso(1)} فائل امپورٹ کریں` })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "بند کریں" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "امپورٹ" }));
    expect(screen.getByText("امپورٹ ہو رہی ہیں…")).toBeInTheDocument();
    answer({ queued: 1, duplicates: 0, unsupported: 0, failed: 0 });
    await act(flushAsync);
    unmount();
    fail("import_voice_notes", "The disk is full.");
    const notify = vi.fn();
    render(<div className="el-root"><ImportSheetV2 paths={[NOTE]} notify={notify} onClose={vi.fn()} /></div>);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "امپورٹ" }));
    await act(flushAsync);
    expect(notify).toHaveBeenLastCalledWith("The disk is full.", true);
    expect(screen.getByRole("dialog", { name: `${iso(1)} فائل امپورٹ کریں` })).toBeInTheDocument();
  });

  it("the notice after it: each part a whole sentence with its count, in Urdu, leading to Activity", async () => {
    const notify = vi.fn();
    render(<div className="el-root"><ImportSheetV2 paths={[NOTE, VIDEO]} notify={notify} onClose={vi.fn()} /></div>);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: `${iso(2)} فائلیں امپورٹ کریں` }));
    await act(flushAsync);
    expect(notify).toHaveBeenCalledWith(`${iso(2)} فائلیں امپورٹ ہو رہی ہیں۔ یہ پس منظر میں اپ لوڈ ہوتی ہیں۔`, false, { kind: "page", page: "activity" });
    expect(importNotice({ queued: 1, duplicates: 1, unsupported: 1, failed: 2 })).toBe([
      `${iso(1)} فائل امپورٹ ہو رہی ہے۔ یہ پس منظر میں اپ لوڈ ہوتی ہے۔`,
      `${iso(1)} فائل چھوڑ دی گئی جو پہلے سے اپ لوڈ کے انتظار میں ہے۔`,
      `${iso(1)} فائل چھوڑ دی گئی جو وائس نوٹ، آڈیو یا ویڈیو فائل نہیں۔`,
      `${iso(2)} فائلیں پڑھی نہیں جا سکیں۔`,
    ].join(" "));
    expect(importNotice({ queued: 0, duplicates: 0, unsupported: 0, failed: 0 })).toBe("امپورٹ کے لیے کچھ نہیں۔");
  });

  it("nothing it can take: Nothing to import, Import off; the drop hint", async () => {
    render(<div className="el-root"><ImportSheetV2 paths={[TEXT]} notify={vi.fn()} onClose={vi.fn()} /><DropHintV2 /></div>);
    await act(flushAsync);
    expect(screen.getByRole("dialog", { name: "امپورٹ کے لیے کچھ نہیں" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "امپورٹ" })).toBeDisabled();
    expect(screen.getByTestId("drop-hint")).toHaveTextContent("امپورٹ کرنے کے لیے وائس نوٹس، آڈیو یا ویڈیو فائلیں یہاں چھوڑیں");
  });
});

describe("whole lines: no English piece in the app's own Urdu, no Urdu piece in English", () => {
  it("each section's name, option and pill word is Urdu but an app's or a person's name", () => {
    const { facts, names } = prepare(ITEMS, { now: NOW.getTime() });
    const all = facetCounts(facts, {}, names, NOW.getTime());
    const own = (Object.keys(all) as SectionKey[]).flatMap((s) => all[s].filter((o) => !["whatsapp", "zoom", "c1", "q4", "price"].includes(o.value)).map((o) => o.label));
    expect(own.filter((w) => LATIN.test(w))).toEqual([]);
    expect(Object.values(SECTION_NAMES).filter((w) => LATIN.test(w))).toEqual([]);
  });

  it("the filing rules' lines say an app in the line's own language (sourceAppLabel with its lang)", () => {
    expect(ruleText({ kind: "app", value: "phone_call" })).toBe(`${iso("فون کال")} سے`);
    expect(filedByRuleText({ title: "Standup", source_app: "voice_note" }, [{ id: 1, kind: "app", value: "voice_note" }])).toBe(`قاعدے سے رکھی گئی: ${iso("وائس نوٹ")} سے`);
  });
});

describe("classic callers in an Urdu window keep their English", () => {
  it("lib/sharing's rule lines with lang \"en\": the app's word in English too, nothing isolated", () => {
    expect(ruleText({ kind: "app", value: "phone_call" }, "en")).toBe("From Phone call");
    expect(filedByRuleText({ title: "Standup", source_app: "voice_note" }, [{ id: 1, kind: "app", value: "voice_note" }], "en")).toBe("Filed by rule: from Voice note");
  });

  it("lib/importFiles' notice after an import with lang \"en\" (classic's import sheet): its English, nothing isolated", () => {
    const summary = { queued: 3, duplicates: 1, unsupported: 2, failed: 1 };
    expect(importNotice(summary, "en")).toBe("Importing 3 files. They upload in the background. Skipped 1 file already waiting to upload. "
      + "Skipped 2 files that are not a voice note, audio or video file. Couldn't read 1 file.");
    expect(importNotice({ queued: 0, duplicates: 0, unsupported: 0, failed: 0 }, "en")).toBe("Nothing to import.");
  });

  it("the classic queue (views/main/LibraryQueue) asks and says in English, its notices leading where they always did", async () => {
    const asked = answerConfirm().mockReturnValue(true);
    respond("pending_list", [makePendingItem("p1", { title: "Keep me" }), makePendingItem("p2", { title: "Drop me" })]);
    respond("upload_list", [makeUploadItem("u1", { state: "failed", title: "Zoom call" })]);
    respond("list_clients", { data: [] });
    respond("pending_keep", undefined);
    respond("pending_delete", 1);
    respond("upload_discard", undefined);
    const notify = vi.fn();
    render(<LibraryQueue notify={notify} />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: /^Review$/ }));
    fireEvent.click(within(screen.getByTestId("pending-p2")).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(asked).toHaveBeenLastCalledWith("Delete this recording? You can undo it for a few seconds.");
    expect(notify.mock.calls.at(-1)!.slice(0, 2)).toEqual(["Deleted · Undo", false]);
    fireEvent.click(within(screen.getByTestId("pending-p1")).getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    expect(notify).toHaveBeenLastCalledWith("Transcribing…", false, { kind: "library", view: null });
    fireEvent.click(within(screen.getByTestId("upload-u1")).getByRole("button", { name: "Discard" }));
    await act(flushAsync);
    expect(asked).toHaveBeenLastCalledWith("Discard this recording? It can't be undone.");
    expect(notify).toHaveBeenLastCalledWith("Discarded", false, undefined);
    for (const [text] of notify.mock.calls) expect(String(text)).not.toMatch(URDU_OR_ISOLATE);
  });

  it("the classic Filed by line (views/main/FilingRules) is exactly its English", async () => {
    respond("client_rules", { data: [{ id: 1, kind: "app", value: "phone_call" }] });
    render(<FiledByLine item={{ id: "r1", title: "Call", source_app: "phone_call", filed_by: "rule", client: { id: "c1", name: "Acme" } as LibraryItem["client"] }} />);
    await act(flushAsync);
    expect(screen.getByTestId("filed-by")).toHaveTextContent(/^Filed by rule: from Phone call$/);
    expect(screen.getByTestId("filed-by").textContent).not.toMatch(URDU_OR_ISOLATE);
  });
});
