// Desktop Urdu, D-261: a project's page in the new design (WorkspaceV2) in the account's language: its header (Back, the
// breadcrumbs, the line under its name, Request files, Share, Copy for AI, the project menu), its tabs and their counts,
// and each tab's words, states, confirmations and notices (Recordings, Files with its screens, Memory & instructions
// with the call notes, Members). Every count goes through tc(), every value sits in its own isolate, a line with a name
// in it reads in its own words' direction; the names of projects, folders, recordings, files and people are as they are.
// English is unchanged (workspace.test.tsx keeps every English name; here the helpers and a few lines side by side).
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import type { CallNotes, ClientFile, ClientFolder, ClientWorkspace, MemoryLine } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem } from "../../test/fixtures";
import { answerConfirmForTests, type ConfirmQuestion } from "../../lib/confirm";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { dragLabel } from "../../lib/dragMove";
import { NO_AI_NOTES, noAiNotes } from "../../lib/plan";
import { rowDate } from "../../lib/format";
import { budgetLine } from "../../views/main/ClientWorkspace";
import { clientSummary } from "../../views/main/ClientAvatar";
import { NotesSections } from "../../views/main/CallNotes";
import { FSI, PDI, resetLanguageForTests, setLanguage, stripIsolates } from "../../i18n";
import { textsIn } from "../../i18n/rawText";
import ur from "../../i18n/ur.json";
import { MEMORY_KINDS, memoryGroups, summaryLine, WorkspaceV2 } from "./WorkspaceV2";
import { fileLine } from "./FilesTab";
import { memberRows } from "./MembersTab";
import { PROJECT_SORTS } from "./RecordingsTab";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const here = dirname(fileURLToPath(import.meta.url));
const iso = (value: string | number) => `${FSI}${value}${PDI}`;
const urdu = () => act(() => setLanguage("ur", { root: false }));
const english = () => act(() => setLanguage("en", { root: false }));
const NOW = new Date(2026, 10, 26, 15, 0);
const at = (day: number, hour: number) => new Date(2026, 10, day, hour).toISOString();

const PROJECT = { id: "c1", name: "Qureshi Textiles", ai_notes: true };
const PROJECTS: ClientFolder[] = [{ id: "c1", name: "Qureshi Textiles" }, { id: "c1a", name: "March order", parent_id: "c1" }];
const line = (id: string, kind: MemoryLine["kind"], text: string, over: Partial<MemoryLine> = {}): MemoryLine =>
  ({ id, kind, text, happened_on: null, pinned: false, source: null, ...over });
const file = (id: string, name: string, over: Partial<ClientFile> = {}): ClientFile =>
  ({ id, name, mime: "application/pdf", size_bytes: 120_000, pages: 2, status: "ready", error: null, instructions: false, ...over });
const WS: ClientWorkspace = {
  client: { id: "c1", name: "Qureshi Textiles", ai_notes: true },
  ai: { configured: true, spent_usd: 1.2, budget_usd: 5, client_spent_usd: 0.4 },
  brief: { sections: { goal: "Ship 40,000 metres of cotton lawn in March.", deadlines: ["Packing done by 21 March"], budget: ["40% advance"] }, edited: true, status: "ready", error: null, generated_at: null, updated_at: null },
  memory: [
    line("m1", "decision", "40% in advance, the balance against the bill of lading", { source: { type: "call", id: "bilal", at_ms: 31_000 } }),
    line("m2", "question", "Which bank opens the LC?", { source: { type: "file", id: "f1", at_ms: null } }),
    line("m3", "decision", "Packing done by 21 March", { pinned: true }),
    line("m4", "change", "Delivery moved to the 25th", { happened_on: "2026-11-14" }),
  ],
  // A note still being written and one with none (classic's NotesSections, which draws a written one, isn't this page's).
  notes: [
    { id: "n1", session_id: "bilal", status: "pending", error: null, language: "en", created_at: at(26, 10), sections: [] as never,
      session: { id: "bilal", title: "Payment terms for the March shipment", started_at: at(26, 9), duration_ms: 1_104_000 } },
    { id: "n2", session_id: "x1", status: "skipped", error: null, language: "en", created_at: at(25, 10), sections: [] as never, session: null },
  ],
  files: [
    file("f1", "Proforma March.pdf"),
    file("f2", "House rules", { mime: "text/plain", size_bytes: 2048, pages: null, instructions: true }),
    file("f3", "Scan.docx", { status: "failed", pages: null, size_bytes: null }),
  ],
};
const ITEMS = [
  makeItem("bilal", { title: "Payment terms for the March shipment", client: { id: "c1", name: "Qureshi Textiles" }, started_at: at(26, 9), duration_ms: 1_104_000 }),
  makeItem("order", { title: "Cutting schedule", client: { id: "c1a", name: "March order" }, started_at: at(25, 11) }),
  makeItem("other", { title: "Weekly sales review", client: { id: "c2", name: "Mehran Foods" } }),
];
const ACCOUNT = { name: "Ayesha Siddiqui", email: "ayesha@qureshitextiles.pk", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null };
const link = (over: Record<string, unknown>) => ({ id: "l1", session_id: null, has_password: false, include_audio: true, expires_at: null, revoked_at: null, active: true,
  view_count: 2, last_viewed_at: null, created_by: null, created_at: "2026-11-20T08:00:00Z", kind: "project", client_id: "c1", ...over });
const sentTo = (id: string, name: string | null, email: string) => ({ id, kind: "project", to: { name, email }, title: "Qureshi Textiles", note: null, sent_at: "2026-11-21T08:00:00Z", saved_at: null });
/** A written call note: the server's sections (docs/API.md "Call notes"), a change of each kind, a line to verify, and a section the app doesn't know. */
const WRITTEN: CallNotes = {
  id: "n3", session_id: "bilal", status: "ready", error: null, language: "en", created_at: at(26, 10),
  session: { id: "bilal", title: "Payment terms for the March shipment", started_at: at(26, 9), duration_ms: 1_104_000 },
  sections: [
    { key: "asked_for", heading: "Asked for", items: [{ text: "Samples of the lawn print", verify: true, lines: [2], at_ms: 31_000 }] },
    { key: "changes", heading: "Changes", items: [
      { text: "Delivery moved to the 25th", verify: false, lines: [3], at_ms: 64_000, kind: "modify" },
      { text: "Insurance by the buyer", verify: false, lines: [4], at_ms: null, kind: "add" },
      { text: "Air freight", verify: false, lines: [5], at_ms: 90_000, kind: "remove" },
    ] },
    { key: "numbers", heading: "Deadlines, prices, numbers", items: [{ text: "Rs 420 a metre", verify: false, lines: [6], at_ms: 95_000 }] },
    { key: "my_actions", heading: "My action items", items: [{ text: "Send the proforma by Monday", verify: false, lines: [7], at_ms: 97_000 }] },
    { key: "risks", heading: "Risks", items: [{ text: "Port strike", verify: false, lines: [8], at_ms: 99_000 }] },
  ],
};
const WRITTEN_TEXTS = WRITTEN.sections.flatMap((s) => s.items.map((i) => i.text));

/** The names and the data the page shows as they are: never translated (longest first, so a name inside another goes with it). */
const KEPT = [
  "Qureshi Textiles", "March order", "Payment terms for the March shipment", "Cutting schedule", "Proforma March.pdf", "House rules", "Scan.docx",
  "Ship 40,000 metres of cotton lawn in March.", "Packing done by 21 March", "40% advance", "40% in advance, the balance against the bill of lading",
  "Which bank opens the LC?", "Delivery moved to the 25th", "Qureshi", "Ayesha Siddiqui", "ayesha@qureshitextiles.pk", "Ana Siddiqui",
  "ana@qureshitextiles.pk", "sara@acme.pk", "bilal@qureshitextiles.pk", "EchoLedger", "Zoom", "PDF", "Word", "Markdown", "KB", "Pro",
  ...WRITTEN_TEXTS,
].sort((a, b) => b.length - a.length);
/**
 * Shared controls this page shows that are still in English (another part's, listed in the report): the Back to top
 * button (v2/components/BackToTop.tsx, the Library's and Activity's too).
 */
const OTHERS = ["Back to top"];
const latinIn = (words: string, kept: readonly string[] = KEPT) => /[A-Za-z]/.test(kept.reduce((rest, name) => rest.split(name).join(""), stripIsolates(words)));
/** Every word a part of the page shows or says: its text (not the avatars' initials), its labels, titles and placeholders. */
function wordsOf(root: Element): string[] {
  const out: string[] = [];
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const text = node.textContent?.trim();
    if (text && !node.parentElement?.closest(".el-avatar")) out.push(text);
  }
  for (const element of root.querySelectorAll("[aria-label], [title], [placeholder]")) {
    for (const name of ["aria-label", "title", "placeholder"]) {
      const value = element.getAttribute(name);
      if (value && !OTHERS.includes(value)) out.push(value);
    }
  }
  return out;
}
const latinWords = (words: string[]) => words.filter((w) => latinIn(w));
const panel = () => screen.getByRole("tabpanel");
const head = () => document.querySelector<HTMLElement>("[data-v2=workspace] .el-page__head")!;
const openTab = async (name: string) => {
  fireEvent.click(screen.getByRole("tab", { name }));
  await act(flushAsync);
};
const notice = () => document.querySelector<HTMLElement>(".el-toast__message");

let asked: ConfirmQuestion[] = [];
beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
  resetEntitlementsForTests(null);
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(NOW);
  asked = [];
  answerConfirmForTests((q) => {
    asked.push(q);
    return false;
  });
  respond("library_list", ITEMS);
  respond("client_workspace", { data: WS });
  respond("client_screens", null);
  respond("client_rules", { data: [{ id: 7, kind: "title", value: "Qureshi" }] });
  respond("search_library", { data: [], next_page: null });
  respond("account_get", ACCOUNT);
});
afterEach(async () => {
  await flushAsync();
  answerConfirmForTests(null);
  vi.useRealTimers();
  resetLanguageForTests();
});

const show = async (over: Partial<Parameters<typeof WorkspaceV2>[0]> = {}) => {
  render(<WorkspaceV2 project={PROJECT} projects={PROJECTS} parent={null} folders={[{ id: "c1a", name: "March order", count: 1 }]}
    menu={[{ label: "نام بدلیں", onPick: vi.fn() }]} onShare={vi.fn()} onSend={vi.fn()} onRequest={vi.fn()} rules onBack={vi.fn()} onProjects={vi.fn()}
    onOpenProject={vi.fn()} onOpenRecording={vi.fn()} onLibrary={vi.fn()} onMoveRecordings={vi.fn()} onProjectChanged={vi.fn()} {...over} />);
  await act(flushAsync);
  await act(flushAsync);
};

describe("a project's page: every word has its Urdu, and English is as it was", () => {
  it("every text its files give to t() or tc() is in the catalogue", () => {
    const keys = new Set<string>();
    for (const path of ["WorkspaceV2.tsx", "RecordingsTab.tsx", "FilesTab.tsx", "MembersTab.tsx", "../../lib/dragMove.ts"].map((f) => join(here, f))) {
      for (const found of textsIn(path, readFileSync(path, "utf8"))) if (found.translated) keys.add(found.text);
    }
    expect(keys.size).toBeGreaterThan(100);
    expect([...keys].filter((key) => !(key in ur))).toEqual([]);
  });

  it("the helpers in English: the header's line (classic's clientSummary), the files' lines, the members' ways, the drag label, the budget", () => {
    expect(summaryLine(2, at(26, 9))).toBe(clientSummary(2, at(26, 9)));
    expect([summaryLine(1, at(26, 9)), summaryLine(0, undefined)]).toEqual(["1 recording · Last call Today", "0 recordings"]);
    expect(memoryGroups(WS.memory).map((g) => g.heading)).toEqual(["Decisions", "Changes", "Open questions"]);
    expect(MEMORY_KINDS.map((k) => k.label)).toEqual(["Decision", "Scope", "Change", "Open question"]);
    expect(PROJECT_SORTS.map((s) => s.label)).toEqual(["Newest first", "Oldest first", "Name A–Z", "Longest first"]);
    expect(WS.files.map(fileLine)).toEqual(["PDF · 2 pages · 117 KB", "Instructions · 2 KB", "Couldn't read it"]);
    expect([fileLine(file("f", "a.md", { pages: 1 })), fileLine(file("f", "a.txt", { pages: null })), fileLine(file("f", "a.docx", { status: "extracting" })),
      fileLine(file("f", "a.docx", { status: "failed", error: "Scanned image" })), fileLine(file("f", "a.docx", { pages: null, size_bytes: null }))])
      .toEqual(["Markdown · 1 page · 117 KB", "Text · 117 KB", "Reading…", "Couldn't read it: Scanned image", "Word"]);
    expect(memberRows([link({ audience: "people", people: ["sara@acme.pk"] })], [{ item: sentTo("s1", null, "sara@acme.pk") as never, from: { id: "c1", name: "Qureshi Textiles" } }]))
      .toEqual([{ key: "sara@acme.pk", who: "sara@acme.pk", email: null, how: "Sent with Qureshi Textiles · can open it until you stop sharing; Through a share link" }]);
    expect([dragLabel(["Kick-off"]), dragLabel([null]), dragLabel(["a", "b", "c"])]).toEqual(["Moving “Kick-off”", "Moving “recording”", "Moving 3 recordings"]);
    expect(budgetLine(WS.ai)).toBe("AI this month: $1.20 of $5.00 · this project $0.40");
    expect([NO_AI_NOTES, noAiNotes()]).toEqual([
      "Call notes, memory and Ask are not in your workspace's plan. The workspace owner can change the plan in the console.",
      "Call notes, memory and Ask are not in your workspace's plan. The workspace owner can change the plan in the console.",
    ]);
  });

  it("the helpers in Urdu, each value in its isolate; the classic pages' (\"en\") stay English", async () => {
    await urdu();
    expect(summaryLine(1, at(26, 9))).toBe(`${iso(1)} ریکارڈنگ · آخری کال ${iso("آج")}`);
    expect(summaryLine(0, undefined)).toBe(`${iso(0)} ریکارڈنگز`);
    expect(memoryGroups(WS.memory).map((g) => g.heading)).toEqual(["فیصلے", "تبدیلیاں", "حل طلب سوالات"]);
    expect(MEMORY_KINDS.map((k) => k.label)).toEqual(["فیصلہ", "اسکوپ", "تبدیلی", "حل طلب سوال"]);
    expect(WS.files.map(fileLine)).toEqual([`PDF · ${iso(2)} صفحات · 117 KB`, "ہدایات · 2 KB", "پڑھی نہیں جا سکی"]);
    expect([fileLine(file("f", "a.md", { pages: 1 })), fileLine(file("f", "a.txt", { pages: null })), fileLine(file("f", "a.docx", { status: "extracting" })),
      fileLine(file("f", "a.docx", { status: "failed", error: "Scanned image" })), fileLine(file("f", "a.docx", { pages: null, size_bytes: null }))])
      .toEqual([`Markdown · ${iso(1)} صفحہ · 117 KB`, "ٹیکسٹ · 117 KB", "پڑھی جا رہی ہے…", `پڑھی نہیں جا سکی: ${iso("Scanned image")}`, "Word"]);
    expect(memberRows([link({ audience: "people", people: ["sara@acme.pk"] })], [{ item: sentTo("s1", null, "sara@acme.pk") as never, from: { id: "c1", name: "Qureshi Textiles" } }])[0].how)
      .toBe(`${iso("Qureshi Textiles")} کو بھیج دیا گیا · جب تک آپ شیئر کرنا بند نہ کریں، وہ اسے کھول سکتے ہیں؛ شیئر لنک کے ذریعے`);
    expect([dragLabel(["Kick-off"]), dragLabel([null]), dragLabel(["a", "b", "c"])])
      .toEqual([`“${iso("Kick-off")}” منتقل ہو رہی ہے`, "ریکارڈنگ منتقل ہو رہی ہے", `${iso(3)} ریکارڈنگز منتقل ہو رہی ہیں`]);
    expect(budgetLine(WS.ai)).toBe(`اس مہینے اے آئی: ${iso("$5.00")} میں سے ${iso("$1.20")} · یہ پروجیکٹ ${iso("$0.40")}`);
    expect(noAiNotes()).toBe("کال نوٹس، یادداشت اور پوچھیں آپ کے ورک اسپیس کے پلان میں شامل نہیں۔ ورک اسپیس کا مالک کنسول میں پلان بدل سکتا ہے۔");
    // Classic's own pages ask for English whatever the window's language is.
    expect([dragLabel(["Kick-off"], "en"), dragLabel(["a", "b"], "en"), budgetLine(WS.ai, "en"), noAiNotes("en")])
      .toEqual(["Moving “Kick-off”", "Moving 2 recordings", "AI this month: $1.20 of $5.00 · this project $0.40", NO_AI_NOTES]);
  });
});

describe("a project's page in Urdu", () => {
  it("its header: Back, the breadcrumbs, the line under its name, Request files, Share, Copy for AI and its notice, the menu; the tabs and their counts", async () => {
    const writeText = vi.fn(async () => undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    respond("client_bundle", "# Qureshi Textiles");
    await urdu();
    await show();
    expect(screen.getByRole("heading", { level: 1, name: "Qureshi Textiles" })).toBeInTheDocument();
    const summary = screen.getByTestId("project-summary");
    expect(summary.textContent).toBe(`${iso(1)} ریکارڈنگ · آخری کال ${iso("آج")}`);
    expect(summary).toHaveAttribute("dir", "rtl");
    expect(document.querySelector(".el-page__back")).toHaveTextContent(/^پروجیکٹس$/);
    const crumbs = screen.getByRole("navigation", { name: "یہ پروجیکٹ کہاں ہے" });
    expect(within(crumbs).getByRole("button", { name: "پروجیکٹس" })).toBeInTheDocument();
    expect(within(head()).getByRole("button", { name: "فائلیں منگوائیں" }))
      .toHaveAttribute("title", "ایسا لنک جس سے کوئی بھی آپ کو وائس نوٹس اور فائلیں بھیج سکے، جو اس پروجیکٹ میں رکھی جائیں");
    expect(within(head()).getByRole("button", { name: "شیئر کریں" })).toBeInTheDocument();
    expect(within(head()).getByRole("button", { name: "پروجیکٹ کا مینو" })).toBeInTheDocument();
    expect(latinWords(wordsOf(head()))).toEqual([]);
    expect(within(screen.getByRole("tablist", { name: "پروجیکٹ" })).getAllByRole("tab").map((t) => t.textContent))
      .toEqual(["ریکارڈنگز1", "فائلیں3", "یادداشت اور ہدایات", "پوچھیں", "ممبرز"]);
    expect(screen.getByRole("tab", { name: "ریکارڈنگز" })).toHaveAccessibleDescription(`${iso(1)} ریکارڈنگ`);
    expect(screen.getByRole("tab", { name: "فائلیں" })).toHaveAccessibleDescription(`${iso(3)} فائلیں`);
    fireEvent.click(within(head()).getByRole("button", { name: "اے آئی کے لیے کاپی کریں" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenCalledWith("# Qureshi Textiles");
    expect(notice()?.textContent).toBe("بریف، یادداشت اور حالیہ کال نوٹس اے آئی کے لیے کاپی ہو گئے");
    expect(screen.getByRole("status", { name: "اطلاعات" })).toBeInTheDocument();
  });

  it("Recordings: its folders, the search, the sort, Include its folders, the matches in transcripts, the filing rules, no Latin but the names", async () => {
    respond("search_library", { data: [{ session: { ...ITEMS[0], title: "" }, hit_count: 1, hits: [{ transcript_id: "t1", segment_index: 3, start_ms: 31_000, speaker: "بلال", snippet: "چالیس فیصد پیشگی", highlights: [[0, 5]] }] }], next_page: null });
    await urdu();
    await show();
    expect(within(screen.getByRole("list", { name: "فولڈرز" })).getByRole("button", { name: /^March order/ })).toBeInTheDocument();
    const search = screen.getByRole("searchbox", { name: `${iso("Qureshi Textiles")} میں تلاش کریں` });
    expect(screen.getByRole("combobox", { name: /^ریکارڈنگز کی ترتیب/ })).toHaveTextContent("نئی پہلے");
    fireEvent.click(screen.getByRole("combobox", { name: /^ریکارڈنگز کی ترتیب/ }));
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["نئی پہلے", "پرانی پہلے", "نام (حروفِ تہجی)", "لمبی پہلے"]);
    fireEvent.keyDown(screen.getByRole("combobox", { name: /^ریکارڈنگز کی ترتیب/ }), { key: "Escape" });
    fireEvent.click(screen.getByRole("checkbox", { name: "اس کے فولڈرز بھی شامل کریں" }));
    const list = screen.getByRole("list", { name: "ریکارڈنگز" });
    expect(within(list).getByRole("button", { name: "Cutting schedule" })).toBeInTheDocument();
    // Under the day headings, in the window's words.
    expect([...list.querySelectorAll(".el-list-section")].map((h) => h.textContent)).toEqual(["آج", "کل"]);
    expect(screen.getByRole("region", { name: "رکھنے کے قواعد" })).toBeInTheDocument();
    expect(latinWords(wordsOf(panel()))).toEqual([]);

    // What was said: an untitled recording is the app's word for one.
    fireEvent.change(search, { target: { value: "پیشگی" } });
    const hit = await screen.findByRole("button", { name: `${iso("ریکارڈنگ")} ${iso("0:31")} سے چلائیں` }, { timeout: 5000 });
    await act(flushAsync);
    expect(within(screen.getByRole("list", { name: "ٹرانسکرپٹس میں نتائج" })).getByRole("button", { name: hit.getAttribute("aria-label")! })).toBeInTheDocument();
    // The field's own Clear, and the empty list's (no title has the word; the transcripts do).
    expect(within(screen.getByRole("search")).getByRole("button", { name: "تلاش صاف کریں" })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "کوئی ریکارڈنگ نہیں ملی" })).toBeInTheDocument();
    expect(latinWords(wordsOf(panel()))).toEqual([]);

    fireEvent.change(search, { target: { value: "zzzz" } });
    const none = screen.getByRole("region", { name: "کوئی ریکارڈنگ نہیں ملی" });
    expect(none).toHaveTextContent("کوئی اور لفظ آزمائیں، یا تلاش صاف کریں۔");
  });

  it("Recordings: none yet, with Open Library; the header says 0", async () => {
    respond("library_list", []);
    await urdu();
    await show({ folders: [] });
    const none = screen.getByRole("region", { name: "اس پروجیکٹ میں ابھی کوئی ریکارڈنگ نہیں" });
    const body = none.querySelector(".el-empty__body > span")!;
    expect(body.textContent).toBe(`جو ریکارڈنگز ${iso("Qureshi Textiles")} میں رکھی جائیں وہ یہاں نظر آتی ہیں۔ لائبریری سے ریکارڈنگز سائیڈ بار میں اس پر گھسیٹ کر لائیں، یا منتقل کریں استعمال کریں۔`);
    expect(body).toHaveAttribute("dir", "rtl");
    expect(within(none).getByRole("button", { name: "لائبریری کھولیں" })).toBeInTheDocument();
    expect(screen.getByTestId("project-summary").textContent).toBe(`${iso(0)} ریکارڈنگز`);
    expect(latinWords(wordsOf(panel()))).toEqual([]);
  });

  it("Recordings: the library that didn't load, with Try again", async () => {
    fail("library_list", "The disk is full.");
    await urdu();
    await show();
    const alert = screen.getByRole("alert");
    expect(within(alert).getByRole("heading")).toHaveTextContent("ریکارڈنگز لوڈ نہیں ہو سکیں");
    const body = alert.querySelector(".el-empty__body > span")!;
    expect(body.textContent).toBe("EchoLedger لائبریری نہیں پڑھ سکا۔ اس کمپیوٹر پر موجود ریکارڈنگز محفوظ ہیں۔");
    // In Urdu by its words, though the app's name comes first.
    expect(body).toHaveAttribute("dir", "rtl");
    expect(within(alert).getByRole("button", { name: "دوبارہ کوشش کریں" })).toBeInTheDocument();
    expect(latinWords(wordsOf(panel()))).toEqual([]);
  });

  it("Files: Add files, Paste instructions with its form and notice, Remove's question, the files' lines, the screens; no Latin but the names", async () => {
    resetEntitlementsForTests(makeEntitlements());
    respond("client_instructions_add", { data: file("f9", "Payment rules", { mime: "text/plain", size_bytes: 1, pages: null, instructions: true }) });
    await urdu();
    await show();
    await openTab("فائلیں");
    expect(screen.getByTestId("file-drop").textContent).toBe("یہاں PDF، Word، ٹیکسٹ یا Markdown فائلیں چھوڑیں۔ EchoLedger انہیں بریف اور پوچھیں کے لیے پڑھتا ہے۔");
    expect(screen.getByRole("button", { name: "فائلیں شامل کریں" })).toBeInTheDocument();
    const files = screen.getByRole("list", { name: "فائلیں" });
    expect(within(files).getAllByRole("listitem").map((r) => r.querySelector(".el-row__meta")?.textContent))
      .toEqual([`PDF · ${iso(2)} صفحات · 117 KB`, "ہدایات · 2 KB", "پڑھی نہیں جا سکی"]);
    // The screens kept in its meetings: none here; its heading names the plan that has them.
    expect(screen.getByRole("region", { name: "یہاں کوئی اسکرین نہیں" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /^اسکرینز Pro$/ }).querySelector(".plan-badge")).toHaveAttribute("title", `${iso("اسکرینز")}، ${iso("Pro")} میں ہے`);
    fireEvent.click(screen.getByRole("button", { name: "ہدایات پیسٹ کریں" }));
    expect(screen.getByRole("textbox", { name: "نام" })).toHaveAttribute("placeholder", "بنیادی اصول");
    expect(screen.getByRole("textbox", { name: "ہدایات" })).toHaveAttribute("placeholder", "اس پروجیکٹ کے لیے ہدایات، اصول یا نوٹس پیسٹ کریں");
    expect(screen.getByRole("button", { name: "منسوخ کریں" })).toBeInTheDocument();
    expect(latinWords(wordsOf(panel()))).toEqual([]);

    fireEvent.change(screen.getByRole("textbox", { name: "ہدایات" }), { target: { value: "رقم روپوں میں" } });
    fireEvent.click(screen.getByRole("button", { name: "ہدایات محفوظ کریں" }));
    await act(flushAsync);
    expect(callsOf("client_instructions_add")).toHaveLength(1);
    expect(notice()?.textContent).toBe("ہدایات محفوظ ہو گئیں");

    fireEvent.click(screen.getByRole("button", { name: `${iso("Proforma March.pdf")} ہٹائیں` }));
    await act(flushAsync);
    expect(asked.map((q) => [q.title, q.confirm])).toEqual([[`${iso("Proforma March.pdf")} اس پروجیکٹ سے ہٹائیں؟`, "ہٹائیں"]]);
  });

  it("Memory & instructions: the AI notes switch and budget, the brief (Edit, Write again's question), memory, instructions, the call notes", async () => {
    await urdu();
    await show();
    await openTab("یادداشت اور ہدایات");
    expect(screen.getByRole("switch", { name: "اے آئی نوٹس" })).toHaveAccessibleDescription(`EchoLedger ${iso("Qureshi Textiles")} کی ہر محفوظ کی گئی ریکارڈنگ کے بعد کال نوٹس لکھتا ہے۔`);
    expect(screen.getByTestId("ai-budget").textContent).toBe(`اس مہینے اے آئی: ${iso("$5.00")} میں سے ${iso("$1.20")} · یہ پروجیکٹ ${iso("$0.40")}`);
    const brief = screen.getByRole("region", { name: "بریف" });
    expect(within(brief).getByRole("heading").textContent).toBe("بریف · آپ نے ترمیم کی");
    expect([...brief.querySelectorAll("dt")].map((d) => d.textContent)).toEqual(["مقصد", "آخری تاریخیں", "بجٹ"]);
    const memory = screen.getByRole("list", { name: "یادداشت" });
    // Under their kinds' headings (فیصلے, تبدیلیاں, حل طلب سوالات), pinned first; the day in the window's language.
    const metas = within(memory).getAllByRole("listitem").map((r) => r.querySelector(".el-row__meta")?.textContent ?? null).filter(Boolean);
    expect(metas).toEqual(["پن شدہ", `${iso("0:31")} والی کال سے`, rowDate("2026-11-14"), "فائل سے"]);
    expect(rowDate("2026-11-14")).toMatch(/^14 \S+$/);
    expect(rowDate("2026-11-14")).not.toMatch(/[A-Za-z]/);
    expect(memory).toHaveTextContent(/فیصلے.*تبدیلیاں.*حل طلب سوالات/);
    expect(within(memory).getByRole("button", { name: `“${iso("Packing done by 21 March")}” کا پن ہٹائیں` })).toHaveTextContent("پن ہٹائیں");
    expect(within(memory).getByRole("button", { name: `“${iso("Which bank opens the LC?")}” پن کریں` })).toBeInTheDocument();
    expect(within(memory).getByRole("button", { name: `${iso("0:31")} والی کال کھولیں` })).toHaveTextContent("کھولیں");
    expect(screen.getByRole("combobox", { name: /^قسم/ })).toHaveTextContent("فیصلہ");
    expect(screen.getByRole("button", { name: "یادداشت میں شامل کریں" })).toBeInTheDocument();
    expect(within(screen.getByRole("list", { name: "ہدایات" })).getByRole("button", { name: `${iso("House rules")} ہٹائیں` })).toBeInTheDocument();
    const notes = screen.getByRole("region", { name: "کال نوٹس" });
    expect(within(notes).getByRole("status")).toHaveTextContent("نوٹس لکھے جا رہے ہیں…");
    expect(within(notes).getByRole("button", { name: `${iso("Payment terms for the March shipment")} کھولیں` })).toBeInTheDocument();
    expect(within(notes).getByRole("button", { name: "کال کھولیں" })).toBeInTheDocument();
    expect(notes).toHaveTextContent("اس کال کے نوٹس نہیں لکھے گئے۔");
    expect(latinWords(wordsOf(panel()))).toEqual([]);

    fireEvent.click(within(brief).getByRole("button", { name: "ترمیم کریں" }));
    expect(["مقصد", "اسکوپ", "پابندیاں", "آخری تاریخیں", "بجٹ", "لوگ", "اصطلاحات"].every((name) => screen.getByRole("textbox", { name }))).toBe(true);
    expect(screen.getByRole("textbox", { name: "اسکوپ" })).toHaveAccessibleDescription("ہر ایک الگ سطر میں");
    expect(screen.getByRole("button", { name: "بریف محفوظ کریں" })).toBeInTheDocument();
    expect(latinWords(wordsOf(panel()))).toEqual([]);
    fireEvent.click(screen.getByRole("button", { name: "منسوخ کریں" }));

    fireEvent.click(within(brief).getByRole("button", { name: "دوبارہ لکھیں" }));
    await act(flushAsync);
    fireEvent.click(within(memory).getByRole("button", { name: `“${iso("Which bank opens the LC?")}” ہٹائیں` }));
    await act(flushAsync);
    expect(asked.map((q) => [q.title, q.body, q.confirm])).toEqual([
      ["بریف دوبارہ لکھیں؟", "یہ پروجیکٹ کی فائلوں اور کال نوٹس سے لکھا جاتا ہے۔ آپ کی ترامیم بدل جائیں گی۔", "دوبارہ لکھیں"],
      ["یہ سطر یادداشت سے ہٹائیں؟", "“Which bank opens the LC?”", "ہٹائیں"],
    ]);
  });

  it("Memory & instructions: a plan without AI notes (no Ask tab), no brief, an empty memory and no instructions", async () => {
    respond("client_workspace", { data: { ...WS, memory: [], files: [], brief: null, notes: [] } });
    await urdu();
    await show({ aiNotesInPlan: false });
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["ریکارڈنگز1", "فائلیں0", "یادداشت اور ہدایات", "ممبرز"]);
    await openTab("یادداشت اور ہدایات");
    expect(screen.getByTestId("ai-not-in-plan").textContent).toBe(noAiNotes());
    expect(screen.getByRole("region", { name: "بریف" })).toHaveTextContent("ابھی بریف نہیں۔ لکھنے کے لیے ترمیم کریں دبائیں۔");
    expect(screen.getByRole("region", { name: "یادداشت" })).toHaveTextContent("آپ خود بھی ایک سطر شامل کر سکتے ہیں۔");
    expect(screen.getByRole("region", { name: "ہدایات" })).toHaveTextContent("انہیں فائلیں میں ہدایات پیسٹ کریں سے شامل کریں۔");
    expect(screen.queryByRole("region", { name: "کال نوٹس" })).toBeNull();
    expect(latinWords(wordsOf(panel()))).toEqual([]);
  });

  it("Memory & instructions: a written call note's sections, its changes' kinds, verify and each line's moment, in Urdu", async () => {
    respond("client_workspace", { data: { ...WS, notes: [WRITTEN] } });
    await urdu();
    await show();
    await openTab("یادداشت اور ہدایات");
    const notes = screen.getByRole("region", { name: "کال نوٹس" });
    // A section the catalogue doesn't know keeps the server's heading.
    expect([...notes.querySelectorAll("h4")].map((h) => h.textContent)).toEqual(["کیا مانگا گیا", "تبدیلیاں", "آخری تاریخیں، قیمتیں، اعداد", "میرے کام", "Risks"]);
    expect([...notes.querySelectorAll(".change-kind")].map((k) => k.textContent)).toEqual(["ترمیم", "اضافہ", "حذف"]);
    const verify = notes.querySelector(".verify-tag")!;
    expect(verify.textContent).toBe("تصدیق کریں");
    expect(verify).toHaveAttribute("title", "یہاں ٹرانسکرپٹ واضح نہیں تھا: اس پر بھروسا کرنے سے پہلے اسے جانچ لیں");
    expect(notes.querySelector(".note-line")).toHaveAttribute("title", `${iso("0:31")} سے چلائیں`);
    expect(latinWords(wordsOf(notes)).filter((w) => w !== "Risks")).toEqual([]);
  });

  it("NotesSections in English as before, in the window's language, and in English for classic's pages (lang \"en\") in an Urdu window", async () => {
    const english = () => {
      const { unmount, container } = render(<NotesSections notes={WRITTEN} onOpen={vi.fn()} lang="en" />);
      const said = [...container.querySelectorAll("h4, .change-kind, .verify-tag")].map((e) => e.textContent);
      const titles = [...container.querySelectorAll("[title]")].map((e) => e.getAttribute("title"));
      unmount();
      return { said, titles };
    };
    const before = {
      said: ["Asked for", "verify", "Changes", "Change", "Add", "Remove", "Deadlines, prices, numbers", "My action items", "Risks"],
      titles: ["Play from 0:31", "The transcript was unclear here: check it before you rely on it", "Play from 1:04", "Play from 1:30", "Play from 1:35", "Play from 1:37", "Play from 1:39"],
    };
    expect(english()).toEqual(before);
    // The window's language by default: English in an English window, the same words.
    const { container, unmount } = render(<NotesSections notes={WRITTEN} onOpen={vi.fn()} />);
    expect([...container.querySelectorAll("h4, .change-kind, .verify-tag")].map((e) => e.textContent)).toEqual(before.said);
    await urdu();
    expect([...container.querySelectorAll("h4, .change-kind, .verify-tag")].map((e) => e.textContent))
      .toEqual(["کیا مانگا گیا", "تصدیق کریں", "تبدیلیاں", "ترمیم", "اضافہ", "حذف", "آخری تاریخیں، قیمتیں، اعداد", "میرے کام", "Risks"]);
    unmount();
    expect(english()).toEqual(before);
  });

  it("a workspace that didn't load says so with Try again", async () => {
    fail("client_workspace", "The server is out of reach.");
    await urdu();
    await show();
    await openTab("یادداشت اور ہدایات");
    const alert = screen.getByRole("alert");
    expect(within(alert).getByRole("heading")).toHaveTextContent("پروجیکٹ کا ورک اسپیس لوڈ نہیں ہو سکا");
    // The server's own words follow.
    expect(alert).toHaveTextContent("The server is out of reach.");
    expect(within(alert).getByRole("button", { name: "دوبارہ کوشش کریں" })).toBeInTheDocument();
  });

  it("Members: you, the teammates it was sent to, whoever its links let in, the links, Share and Send to a teammate…", async () => {
    respond("shares_of", { data: [
      link({ id: "l1", audience: "people", people: ["sara@acme.pk", "bilal@qureshitextiles.pk"], view_count: 3 }),
      link({ id: "l2", audience: "public", expires_at: "2026-12-01T10:00:00Z" }),
    ] });
    respond("sent_of", { data: [sentTo("s1", "Ana Siddiqui", "ana@qureshitextiles.pk"), sentTo("s2", null, "sara@acme.pk")] });
    await urdu();
    await show();
    await openTab("ممبرز");
    const rows = within(screen.getByRole("list", { name: "ممبرز" })).getAllByRole("listitem")
      .map((r) => [r.querySelector(".el-row__title")?.textContent, r.querySelector(".el-row__meta")?.textContent]);
    const sent = "انہیں بھیجا گیا · جب تک آپ شیئر کرنا بند نہ کریں، کھول سکتے ہیں";
    expect(rows).toEqual([
      [`${iso("Ayesha Siddiqui")} (آپ)`, "آپ نے بنایا"],
      ["Ana Siddiqui", `${iso("ana@qureshitextiles.pk")} · ${sent}`],
      ["sara@acme.pk", `${sent}؛ شیئر لنک کے ذریعے`],
      ["bilal@qureshitextiles.pk", "شیئر لنک کے ذریعے"],
      ["لنک والا کوئی بھی", "شیئر لنک کے ذریعے"],
    ]);
    // A person's line reads right to left from its words, though the email comes first.
    expect(within(screen.getByRole("list", { name: "ممبرز" })).getAllByRole("listitem")[1].querySelector(".el-row__meta-text > span")).toHaveAttribute("dir", "rtl");
    expect(screen.getByText(`صرف آپ اور یہ لوگ ${iso("Qureshi Textiles")} کھول سکتے ہیں۔ کون کھول سکے، یہ شیئر کریں سے بدلیں۔`)).toHaveAttribute("dir", "rtl");
    const links = within(screen.getByRole("list", { name: "شیئر لنکس" })).getAllByRole("listitem").map((r) => r.querySelector(".el-row__meta")?.textContent ?? "");
    expect(links[0]).toBe(`${iso(3)} بار دیکھا گیا`);
    expect(links[1]).toMatch(new RegExp(`^${iso(2)} بار دیکھا گیا · .+ تک$`));
    const send = within(panel()).getByRole("button", { name: "ساتھی کو بھیجیں…" });
    expect(send).toHaveAttribute("title", "جب تک آپ شیئر کرنا بند نہ کریں، وہ اسے کھول سکتے ہیں، صرف پڑھنے کے لیے اور ہمیشہ تازہ");
    expect(within(panel()).getByRole("button", { name: "شیئر کریں" })).toBeInTheDocument();
    expect(latinWords(wordsOf(panel()))).toEqual([]);
  });

  it("Members of a project shared with nobody: only you, and why", async () => {
    respond("shares_of", { data: [] });
    respond("sent_of", { data: [] });
    await urdu();
    await show();
    await openTab("ممبرز");
    const why = screen.getByText(/^صرف آپ /);
    expect(why.textContent).toBe(`صرف آپ ${iso("Qureshi Textiles")} کھول سکتے ہیں: پروجیکٹ تب تک نجی رہتا ہے جب تک آپ اسے شیئر نہ کریں، ٹیم میں بھی۔`);
    expect(latinWords(wordsOf(panel()))).toEqual([]);
  });

  it("follows a change of language while it is on screen, back to the same English", async () => {
    await show();
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["Recordings1", "Files3", "Memory & instructions", "Ask", "Members"]);
    expect(screen.getByTestId("project-summary").textContent).toBe("1 recording · Last call Today");
    expect(screen.getByTestId("project-summary")).toHaveAttribute("dir", "auto");
    await urdu();
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["ریکارڈنگز1", "فائلیں3", "یادداشت اور ہدایات", "پوچھیں", "ممبرز"]);
    expect(screen.getByRole("searchbox", { name: `${iso("Qureshi Textiles")} میں تلاش کریں` })).toBeInTheDocument();
    await english();
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["Recordings1", "Files3", "Memory & instructions", "Ask", "Members"]);
    expect(screen.getByTestId("project-summary").textContent).toBe("1 recording · Last call Today");
    expect(screen.getByRole("searchbox", { name: "Search Qureshi Textiles" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Copy for AI" })).toHaveAttribute("title", "The brief, memory and recent call notes as one text, ready to paste into a chat");
    await openTab("Memory & instructions");
    expect(screen.getByRole("list", { name: "Memory" })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "Brief" }).querySelector("h2")?.textContent).toBe("Brief · edited by you");
  });
});
