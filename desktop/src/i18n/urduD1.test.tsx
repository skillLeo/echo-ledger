// Desktop Urdu, package D1: the Library (its list, rows, table, sidebar and Get started), its search with the matches in
// transcripts, the transcript's Find and tools, and Trash, in Urdu from the catalogue (src/i18n/ur.json), each value in
// its own isolate; the same words in English as before (the Library's own tests are in English, unchanged). The app's
// English data labels are said in Urdu where the Library shows them; a person's names (a project, a label, a view)
// never are. D-223: each choice of a Segmented control in its own direction, "اردو" in its own language.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../lib/ipc";
import { flushAsync, resetTauriMock, respond } from "../test/tauri";
import { makeItem } from "../test/fixtures";
import { LibraryV2 } from "../v2/library/LibraryV2";
import { LibraryFoot, LibraryNav } from "../v2/library/LibraryNav";
import { binSentence, eraseInWords, groupTitle, recordings, rowModel, STATE_WORDS, trashTime, VIEW_NAMES } from "../v2/library/model";
import { scopeName } from "../v2/library/scope";
import { syncWords } from "../v2/library/syncClock";
import { sourceWords } from "../v2/library/SourceAvatar";
import { COLUMN_NAMES } from "../v2/library/LibraryTable";
import { FindBar } from "../v2/library/TranscriptToolsV2";
import { SegmentedSetting } from "../v2/settings/pages/rows";
import { FSI, PDI, language, resetLanguageForTests, setLanguage, stripIsolates } from "./index";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 24, 15, 0);
const done = (preview: string, id: string) => ({ final_status: "completed", final_transcript_id: id, live_available: false, preview });
const ITEMS: LibraryItem[] = [
  makeItem("bilal", { title: "Bilal Qureshi", source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone", started_at: new Date(2026, 10, 24, 11).toISOString(), opened: true, transcript: done("Payment terms", "t1") }),
  makeItem("call", { title: "", source_app: "phone_call", started_at: new Date(2026, 10, 23, 9).toISOString(), opened: true, transcript: done("Steel", "t2") }),
];
const urdu = () => act(() => setLanguage("ur", { root: false }));
const iso = (value: string | number) => `${FSI}${value}${PDI}`;

beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
  window.localStorage.clear();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(NOW);
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("list_clients", { data: [] });
  respond("trash_keep_hours", 48);
});
afterEach(() => {
  vi.useRealTimers();
  resetLanguageForTests();
});

describe("D1's words, in English as before and in Urdu", () => {
  it("the Trash words: the time it keeps, when one is erased, the sentence; a count of recordings", async () => {
    expect([trashTime(48), trashTime(1), trashTime(720)]).toEqual(["48 hours", "1 hour", "30 days"]);
    expect(binSentence(48)).toBe("Deleted recordings stay here for 48 hours, then their audio and transcripts are erased.");
    const now = Date.parse("2026-10-28T10:00:00Z");
    expect([eraseInWords("2026-10-28T15:30:00Z", now), eraseInWords("2026-10-28T11:10:00Z", now), eraseInWords("2026-10-28T10:20:00Z", now)])
      .toEqual(["erased in 5 hours", "erased in 1 hour", "erased soon"]);
    expect([recordings(1), recordings(3000)]).toEqual(["1 recording", "3000 recordings"]);
    await urdu();
    expect(trashTime(48)).toBe(`${iso(48)} گھنٹے`);
    expect(trashTime(720)).toBe(`${iso(30)} دن`);
    expect(binSentence(48)).toBe(`حذف شدہ ریکارڈنگز ${iso(`${iso(48)} گھنٹے`)} تک یہاں رہتی ہیں، پھر ان کی آڈیو اور ٹرانسکرپٹس مٹا دیے جاتے ہیں۔`);
    expect(eraseInWords("2026-10-28T15:30:00Z", now)).toBe(`${iso(5)} گھنٹوں میں مٹ جائے گی`);
    expect(eraseInWords("2026-10-28T11:10:00Z", now)).toBe(`${iso(1)} گھنٹے میں مٹ جائے گی`);
    expect(recordings(1)).toBe(`${iso(1)} ریکارڈنگ`);
    expect(recordings(3)).toBe(`${iso(3)} ریکارڈنگز`);
  });

  it("the sync line, a row's words, the places' names and the columns", async () => {
    const at = NOW.getTime();
    expect(syncWords({ at, syncing: false, error: null }, at + 5 * 60_000)).toBe("Synced 5 min ago");
    expect(syncWords({ at, syncing: false, error: "offline" }, at)).toBe("Last synced just now");
    expect(rowModel(ITEMS[1], NOW).title).toBe("Unknown caller");
    expect(sourceWords(ITEMS[0])).toBe("WhatsApp, on a phone");
    expect([STATE_WORDS.failed, VIEW_NAMES.today, COLUMN_NAMES.length, scopeName({ kind: "trash" })]).toEqual(["Failed · Retry", "Today", "Length", "Trash"]);
    await urdu();
    expect(syncWords({ at, syncing: false, error: null }, at + 5 * 60_000)).toBe(`${iso(5)} منٹ پہلے سنک ہوا`);
    expect(syncWords({ at: null, syncing: false, error: null }, at)).toBe("ابھی سنک نہیں ہوا");
    expect(rowModel(ITEMS[1], NOW).title).toBe("نامعلوم کالر");
    expect(rowModel(ITEMS[1], NOW).when).toBe("کل");
    expect(sourceWords(ITEMS[0])).toBe(`${iso("WhatsApp")}، فون پر`);
    expect([STATE_WORDS.failed, VIEW_NAMES.today, COLUMN_NAMES.length, scopeName({ kind: "trash" })]).toEqual(["ناکام · دوبارہ کوشش کریں", "آج", "دورانیہ", "ردی"]);
    expect(scopeName({ kind: "view", view: "all" })).toBe("تمام ریکارڈنگز");
    // A word table's keys stay its ids (Object.keys), whatever the language.
    expect(Object.keys(COLUMN_NAMES)).toEqual(["date", "length", "app", "project", "status", "labels", "device"]);
  });

  it("a group's heading: the app's own words in Urdu, a person's names as they are", async () => {
    await urdu();
    expect(groupTitle({ key: "date:Today", label: "Today" })).toBe("آج");
    expect(groupTitle({ key: "status:Ready", label: "Ready" })).toBe("تیار");
    expect(groupTitle({ key: "pinned", label: "Pinned" })).toBe("پن شدہ");
    expect(groupTitle({ key: "project:No project", label: "No project" })).toBe("کوئی پروجیکٹ نہیں");
    // A project or a source named like one of the app's words keeps its own name.
    expect(groupTitle({ key: "project:Today", label: "Today" })).toBe("Today");
    expect(groupTitle({ key: "app:Other", label: "Other" })).toBe("Other");
  });
});

describe("the Library in Urdu", () => {
  it("its list: the kinds, the day headings, the rows' words and the empty Trash, then English again as before", async () => {
    respond("bin_list", { data: [] });
    render(<LibraryV2 />);
    await act(flushAsync);
    expect(screen.getByRole("tab", { name: /^Calls/ })).toBeInTheDocument();
    await urdu();
    await act(flushAsync);
    // The list follows at once: the kinds, the list's name, the day headings, an unknown caller.
    const list = screen.getByRole("list", { name: "ریکارڈنگز" });
    expect(screen.getByRole("tablist", { name: "ریکارڈنگ کی اقسام" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /^کالز/ })).toBeInTheDocument();
    expect(within(list).getByText("آج")).toBeInTheDocument();
    expect(within(list).getByText("کل")).toBeInTheDocument();
    expect(within(list).getByRole("button", { name: "نامعلوم کالر" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "فلٹرز" })).toBeInTheDocument();
    await act(() => setLanguage("en", { root: false }));
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
    expect(within(screen.getByRole("list", { name: "Recordings" })).getByRole("button", { name: "Unknown caller" })).toBeInTheDocument();
  });

  it("Trash: its sentence, each deleted recording's line and Restore, in Urdu", async () => {
    respond("bin_list", { data: [{ id: "b1", title: "", client: null, started_at: "2026-11-23T09:00:00Z", duration_ms: 65_000, deleted_at: "2026-11-24T13:00:00Z", erase_at: "2026-11-26T13:00:00Z" }] });
    await urdu();
    render(<LibraryV2 scopeRequest={{ scope: { kind: "trash" }, seq: 1 }} />);
    await act(flushAsync);
    await act(flushAsync);
    const trash = screen.getByRole("list", { name: "حذف شدہ ریکارڈنگز" });
    expect(trash).toHaveClass("el-lib-trash");
    expect(within(trash).getByText("ریکارڈنگ")).toBeInTheDocument();
    expect(within(trash).getByRole("button", { name: "واپس لائیں" })).toBeInTheDocument();
    expect(within(trash).getByRole("button", { name: `"${iso("ریکارڈنگ")}" ہمیشہ کے لیے حذف کریں` })).toBeInTheDocument();
    expect(screen.getByText(/^حذف شدہ ریکارڈنگز .* تک یہاں رہتی ہیں/)).toBeInTheDocument();
  });

  it("the sidebar's views, Trash and the sync line; a view's copy goes to the server without isolates", async () => {
    await urdu();
    render(<><LibraryNav current="view:all" counts={{ all: 2, needs: 0, unread: 1, important: 0, audio_only: 0 }} labelCounts={{}} onScope={vi.fn()} onNewView={vi.fn()} say={vi.fn()} onLibraryChanged={vi.fn()} /><LibraryFoot trashCurrent={false} onTrash={vi.fn()} onSync={vi.fn()} /></>);
    await act(flushAsync);
    expect(screen.getByRole("list", { name: "لائبریری کے ویوز" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "تمام ریکارڈنگز, 2" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "ردی" })).toBeInTheDocument();
    expect(screen.getByTestId("sidebar-synced")).toHaveAttribute("title", "ابھی سنک کریں");
    expect(stripIsolates(`${iso("Calls")} کاپی`)).toBe("Calls کاپی");
  });

  it("Find in a transcript: its field, how many matched, the buttons", async () => {
    await urdu();
    render(<FindBar query="steel" onQuery={vi.fn()} count={4} current={1} onStep={vi.fn()} onClose={vi.fn()} />);
    expect(screen.getByRole("search", { name: "اس ٹرانسکرپٹ میں تلاش کریں" })).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent(`${iso(4)} میں سے ${iso(2)}`);
    expect(screen.getByRole("button", { name: "اگلا نتیجہ" })).toBeInTheDocument();
    expect(language()).toBe("ur");
  });
});

describe("D-223: a Segmented control's choices in their own direction and language", () => {
  it("each label in a dir=auto span; the Language choices in their own languages", () => {
    respond("get_settings", null);
    render(<div className="el-root"><SegmentedSetting id="appearance.language" value="en" onChange={vi.fn()} /><SegmentedSetting id="appearance.clock" value="12" onChange={vi.fn()} /></div>);
    const urduChoice = screen.getByRole("radio", { name: "اردو" });
    expect(urduChoice.querySelector("span")).toHaveAttribute("dir", "auto");
    expect(urduChoice.querySelector("span")).toHaveAttribute("lang", "ur");
    expect(screen.getByRole("radio", { name: "English" }).querySelector("span")).toHaveAttribute("lang", "en");
    // A number-first English label reads "12-hour" in an Urdu window, not "hour-12".
    const clock = screen.getAllByRole("radio").find((r) => /12/.test(r.textContent ?? ""))!;
    expect(clock.querySelector("span")).toHaveAttribute("dir", "auto");
    expect(clock.querySelector("span")).not.toHaveAttribute("lang");
    fireEvent.click(urduChoice);
  });
});
