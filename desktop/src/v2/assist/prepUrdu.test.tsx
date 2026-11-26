// Desktop Urdu, D-264: the Live Assist prep screen (PrepScreen) in the account's language, for a new session and for
// an upcoming one: the meeting type picker and its templates, the documents' loading, empty and failed states, the
// brief, how it helps and the start's refusals; and the Live Assist card's window (AssistCard, AssistApp), which speaks
// the main window's language. People's, companies', projects' and files' names, what the core says and the consent
// line (said to the other side, English in every language, as on the phone) stay as they are. The meeting types are
// the words Live's session rows already say (D-261). English is the screens' own tests' (live.test.tsx,
// assist.test.tsx), unchanged; a few checks here say so too. Classic's prep keeps English in an Urdu window.
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeSettings } from "../../test/fixtures";
import { FSI, PDI, language, resetLanguageForTests, setLanguage, stripIsolates } from "../../i18n";
import { KEPT_LANGUAGE_KEY } from "../../i18n/language";
import { textsIn } from "../../i18n/rawText";
import ur from "../../i18n/ur.json";
import { CONSENT_LINE, NOT_HERE, type AssistMessage, type Prep } from "./adapter";
import { AssistCard, INTERVIEW_NOTE, interviewNote } from "./AssistCard";
import AssistApp from "./AssistApp";
import { EMPTY_CARD, cardReducer, errorWords, modeNote, offerQuestion, type CardState } from "./card";
import { FIXTURE_SESSION } from "./fixture";
import { MEETING_TYPES, PrepScreen, TEMPLATES, meetingTypes, newDraft, templates, withType } from "./PrepScreen";
import { LiveAssistClassic } from "../live/LiveAssistSection";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const here = dirname(fileURLToPath(import.meta.url));
const catalogue = ur as Record<string, string>;
const iso = (value: string | number) => `${FSI}${value}${PDI}`;
const urdu = () => act(() => setLanguage("ur", { root: false }));
/** Latin letters but the names and the data kept as they are: what an Urdu line must not have. */
const latinIn = (words: string, kept: readonly string[] = []) =>
  /[A-Za-z]/.test([...kept].sort((a, b) => b.length - a.length).reduce((rest, name) => rest.split(name).join(""), stripIsolates(words)));
/** Everything a person can read or hear: the text, and every name, tooltip and value said. */
const allWords = (root: HTMLElement = document.body) => [
  root.textContent ?? "",
  ...[...root.querySelectorAll("[aria-label], [title], [aria-valuetext], [placeholder]")].flatMap((el) =>
    ["aria-label", "title", "aria-valuetext", "placeholder"].map((a) => el.getAttribute(a) ?? "")),
];
const latinLines = (kept: readonly string[], root?: HTMLElement) => allWords(root).filter((w) => latinIn(w, kept));
const URDU = /[؀-ۿ⁦-⁩]/;

const PROJECTS = [{ id: "c1", name: "Qureshi Textiles" }];
const PDF = { id: "f1", name: "Proforma March.pdf", mime: "application/pdf", size_bytes: 1000, pages: 3, status: "ready", error: null, instructions: false };
const workspace = (files: readonly unknown[]) => ({ data: { client: { id: "c1", name: "Qureshi Textiles", ai_notes: true },
  ai: { configured: true, spent_usd: 0, budget_usd: 0, client_spent_usd: 0 }, brief: null, memory: [], notes: [], files } });
const PREP: Prep = {
  ...newDraft("existing_client", "en"), id: "pr1", title: "March order", counterpart: "Bilal Qureshi", company: "Qureshi Textiles",
  project_id: "c1", status: "scheduled", scheduled_at: "2026-11-27T05:00:00Z", documents: [{ file_id: "f1", name: "Proforma March.pdf", tokens: 1200 }],
};
/** An upcoming prep's own brief: the English templates it was saved with are its data now. */
const PREP_KEPT = ["March order", "Bilal Qureshi", "Qureshi Textiles", "Proforma March.pdf", CONSENT_LINE, TEMPLATES.existing_client.goals, TEMPLATES.existing_client.to_discuss];

const showNew = (onBack = vi.fn()) => render(<PrepScreen projects={PROJECTS} onBack={onBack} onStarted={vi.fn()} />);
const pick = (combobox: RegExp, option: string) => {
  fireEvent.click(screen.getByRole("combobox", { name: combobox }));
  fireEvent.click(screen.getByRole("option", { name: option }));
};

beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
  localStorage.clear();
  respond("get_settings", makeSettings());
});
afterEach(() => {
  resetLanguageForTests();
  document.documentElement.removeAttribute("lang");
  document.documentElement.removeAttribute("dir");
});

describe("every word has its Urdu", () => {
  it("every text the prep, the card and its window give to t() or tc(), and the words said from a constant", () => {
    const keys = new Set<string>();
    for (const file of ["PrepScreen.tsx", "AssistCard.tsx", "AssistApp.tsx", "card.ts"]) {
      const path = join(here, file);
      for (const found of textsIn(path, readFileSync(path, "utf8"))) if (found.translated) keys.add(found.text);
    }
    expect(keys.size).toBeGreaterThan(80);
    expect([...keys].filter((key) => !(key in catalogue))).toEqual([]);
    // NOT_HERE (failureText says it), and the meeting types' English names Live's session rows give to t().
    expect([NOT_HERE, ...MEETING_TYPES.map((m) => m.label)].filter((key) => !(key in catalogue))).toEqual([]);
    // The consent line is said to the other side: never a key, English in every language.
    expect(CONSENT_LINE in catalogue).toBe(false);
  });
});

describe("the prep screen in Urdu", () => {
  it("a new session: the meeting, documents, brief and how it helps, with the interview's template and note; nothing in English", async () => {
    await urdu();
    const onBack = vi.fn();
    showNew(onBack);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "نیا لائیو اسسٹ سیشن" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "لائیو" }));
    expect(onBack).toHaveBeenCalled();
    for (const name of ["میٹنگ", "دستاویزات", "بریف", "یہ کیسے مدد کرتا ہے"]) {
      expect(within(screen.getByRole("region", { name })).getByRole("heading", { level: 2, name })).toBeInTheDocument();
    }

    expect(screen.getByRole("combobox", { name: /^میٹنگ کی قسم/ })).toHaveAccessibleName("میٹنگ کی قسم انٹرویو");
    expect(screen.getByRole("textbox", { name: "عنوان" })).toHaveAttribute("placeholder", "مہران فوڈز میں انٹرویو");
    expect(screen.getByRole("textbox", { name: "کے ساتھ" })).toHaveAttribute("placeholder", "عائشہ صدیقی");
    expect(screen.getByRole("textbox", { name: "کمپنی" })).toHaveAttribute("placeholder", "مہران فوڈز");
    expect(screen.getByRole("combobox", { name: /^پروجیکٹ/ })).toHaveAccessibleName("پروجیکٹ کوئی پروجیکٹ نہیں");
    expect(screen.getByRole("region", { name: "دستاویزات" })).toHaveTextContent(
      "کوئی پروجیکٹ جوڑیں تاکہ اس کی فائلیں استعمال ہو سکیں: نوکری کی تفصیل، کوئی تجویز، پچھلے مہینے کے بل۔");

    expect(screen.getByRole("textbox", { name: "مقاصد" })).toHaveValue("عہدے کے لیے مطلوب تجربہ دکھائیں، ہر ایک کی ایک مثال کے ساتھ۔");
    expect(screen.getByRole("textbox", { name: "کن باتوں پر بات کرنی ہے" })).toHaveValue("تنخواہ کی حد، نوٹس کی مدت، کام شروع کرنے کی تاریخ۔");
    for (const name of ["اب تک کیا ہو چکا", "ابھی کیا باقی ہے"]) expect(screen.getByRole("textbox", { name })).toHaveValue("");
    expect(screen.getByRole("textbox", { name: "اسسٹنٹ کے لیے ہدایات" }))
      .toHaveAccessibleDescription("یہ کبھی ایسا تجربہ، اعداد یا وعدے خود سے نہیں بناتا جو اس بریف میں نہ ہوں۔");

    expect(screen.getByRole("combobox", { name: /^موڈ/ })).toHaveAccessibleName("موڈ متوازن");
    expect(screen.getByRole("combobox", { name: /^زبان میں مدد/ })).toHaveAccessibleName("زبان میں مدد آف");
    expect(screen.getAllByRole("radio").map((r) => r.textContent)).toEqual(["کلیدی الفاظ", "خاکہ", "پورا جواب"]);
    expect(screen.getByRole("radio", { name: "خاکہ" })).toHaveAttribute("aria-checked", "true");
    expect(screen.getByRole("note")).toHaveTextContent("دیکھ لیں کہ اس انٹرویو میں اے آئی کی مدد کی اجازت ہے یا نہیں۔");

    expect(screen.getByRole("button", { name: "ابھی شروع کریں" })).toBeEnabled();
    expect(screen.getByLabelText("کس وقت کے لیے")).toHaveAttribute("type", "datetime-local");
    expect(screen.getByRole("button", { name: "وقت طے کریں" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "مسودہ محفوظ کریں" })).toBeInTheDocument();
    expect(screen.getByText("“ابھی شروع کریں” کال ریکارڈ کرتا ہے اور ساتھ ہی لائیو اسسٹ شروع کر دیتا ہے؛ یہ کال کے بعد کے کارڈ کے بغیر محفوظ ہوتی ہے۔ طے شدہ سیشن 2 منٹ پہلے تیار ہو جاتا ہے اور میٹنگ کے لیے 10 منٹ انتظار کرتا ہے۔"))
      .toBeInTheDocument();
    expect(latinLines([])).toEqual([]);
  });

  it("the meeting type picker: the types as Live names them, each type's template in Urdu, never over what the person wrote", async () => {
    await urdu();
    showNew();
    fireEvent.click(screen.getByRole("combobox", { name: /^میٹنگ کی قسم/ }));
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["انٹرویو", "موجودہ کلائنٹ", "ممکنہ کلائنٹ", "باس", "دوسری میٹنگ"]);
    fireEvent.click(screen.getByRole("option", { name: "موجودہ کلائنٹ" }));
    expect(screen.getByRole("textbox", { name: "مقاصد" })).toHaveValue("اگلا آرڈر اور اس کی ادائیگی کی شرائط طے کریں۔");
    expect(screen.getByRole("textbox", { name: "کن باتوں پر بات کرنی ہے" })).toHaveValue("بقایا بل، ڈیلیوری کی تاریخیں۔");
    expect(screen.getByRole("radio", { name: "پورا جواب" })).toHaveAttribute("aria-checked", "true");

    // A client meeting: the line to say, English (said to the other side) in the English face, left to right; Copy.
    const note = screen.getByRole("note");
    expect(within(note).getByText("شروع میں یہ کہیں")).toBeInTheDocument();
    expect(within(note).getByText(CONSENT_LINE)).toHaveAttribute("lang", "en");
    expect(within(note).getByText(CONSENT_LINE)).toHaveAttribute("dir", "ltr");
    expect(within(note).getByRole("button", { name: "کاپی" })).toBeInTheDocument();

    pick(/^میٹنگ کی قسم/, "ممکنہ کلائنٹ");
    expect(screen.getByRole("textbox", { name: "مقاصد" })).toHaveValue("سمجھیں کہ انہیں کیا چاہیے، اور پہلا قدم طے کریں۔");
    expect(screen.getByRole("textbox", { name: "کن باتوں پر بات کرنی ہے" })).toHaveValue("بجٹ، ٹائم لائن، فیصلہ کون کرے گا۔");
    fireEvent.change(screen.getByRole("textbox", { name: "مقاصد" }), { target: { value: "لاہور لانچ پر بات کریں۔" } });
    pick(/^میٹنگ کی قسم/, "باس");
    expect(screen.getByRole("textbox", { name: "مقاصد" })).toHaveValue("لاہور لانچ پر بات کریں۔");
    expect(screen.getByRole("textbox", { name: "کن باتوں پر بات کرنی ہے" })).toHaveValue("رکاوٹیں، اور مجھے ان سے کیا چاہیے۔");
    pick(/^میٹنگ کی قسم/, "دوسری میٹنگ");
    expect(screen.getByRole("textbox", { name: "کن باتوں پر بات کرنی ہے" })).toHaveValue("");
    expect(latinLines([CONSENT_LINE])).toEqual([]);

    // The helpers: the window's language when no language is given; a template still in English counts as untouched.
    expect(meetingTypes().map((m) => m.label)).toEqual(["انٹرویو", "موجودہ کلائنٹ", "ممکنہ کلائنٹ", "باس", "دوسری میٹنگ"]);
    expect(templates().boss.goals).toBe("اگلے دو ہفتوں کی ترجیحات طے کریں۔");
    expect(newDraft("existing_client").goals).toBe("اگلا آرڈر اور اس کی ادائیگی کی شرائط طے کریں۔");
    expect(withType(newDraft("interview", "en"), "boss").goals).toBe("اگلے دو ہفتوں کی ترجیحات طے کریں۔");
    expect(withType({ ...newDraft("interview"), goals: "Talk about the Lahore launch." }, "boss").goals).toBe("Talk about the Lahore launch.");
    expect(MEETING_TYPES.map((m) => m.label)).toEqual(["Interview", "Existing client", "Prospective client", "Boss", "Other meeting"]);
    expect(TEMPLATES.boss.goals).toBe("Agree priorities for the next two weeks.");
  });

  it("an upcoming session: Edit prep with its own data, its project's documents and their pages, Scheduled; a type change brings Urdu over the English template", async () => {
    respond("client_workspace", workspace([PDF]));
    await urdu();
    render(<PrepScreen prep={PREP} projects={PROJECTS} onBack={vi.fn()} onStarted={vi.fn()} />);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "تیاری میں ترمیم کریں" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: /^میٹنگ کی قسم/ })).toHaveAccessibleName("میٹنگ کی قسم موجودہ کلائنٹ");
    expect(screen.getByRole("textbox", { name: "عنوان" })).toHaveValue("March order");
    expect(screen.getByRole("textbox", { name: "کے ساتھ" })).toHaveValue("Bilal Qureshi");
    expect(screen.getByRole("combobox", { name: /^پروجیکٹ/ })).toHaveAccessibleName("پروجیکٹ Qureshi Textiles");

    const use = screen.getByRole("group", { name: "اس سیشن میں استعمال کریں" });
    const file = within(use).getByRole("checkbox", { name: "Proforma March.pdf" });
    expect(file).toBeChecked();
    expect(file).toHaveAccessibleDescription(`${iso(3)} صفحات`);
    expect(within(use).getByText("Proforma March.pdf").tagName).toBe("BDI");
    expect(screen.getByText("وقت طے ہے")).toBeInTheDocument();
    expect(latinLines(PREP_KEPT)).toEqual([]);

    pick(/^میٹنگ کی قسم/, "باس");
    expect(screen.getByRole("textbox", { name: "مقاصد" })).toHaveValue("اگلے دو ہفتوں کی ترجیحات طے کریں۔");
    expect(screen.getByRole("note")).toHaveTextContent(CONSENT_LINE);
  });

  it("the documents: loading, none in the project, failed with Try again, then the list; too large said in Urdu", async () => {
    let answer: (v: unknown) => void = () => undefined;
    respondWith("client_workspace", () => new Promise((r) => (answer = r)));
    await urdu();
    const first = showNew();
    pick(/^پروجیکٹ/, "Qureshi Textiles");
    expect(screen.getByRole("status", { busy: true })).toHaveTextContent("پروجیکٹ کی فائلیں پڑھی جا رہی ہیں");
    await act(async () => answer(workspace([])));
    const none = screen.getByRole("region", { name: "اس پروجیکٹ میں کوئی فائل نہیں" });
    expect(none).toHaveTextContent("پروجیکٹ میں فائلیں شامل کریں، پھر انہیں یہاں لگائیں۔");
    expect(latinLines(["Qureshi Textiles"])).toEqual([]);
    first.unmount();

    fail("client_workspace", "offline");
    showNew();
    pick(/^پروجیکٹ/, "Qureshi Textiles");
    await act(flushAsync);
    const failed = screen.getByRole("alert");
    expect(within(failed).getByRole("heading", { name: "پروجیکٹ کی فائلیں لوڈ نہیں ہو سکیں" })).toBeInTheDocument();
    expect(failed).toHaveTextContent("سیشن ان کے بغیر بھی چلتا ہے؛ آپ انہیں بعد میں لگا سکتے ہیں۔");
    expect(screen.queryByText("اس پروجیکٹ میں کوئی فائل نہیں")).toBeNull();
    expect(latinLines(["Qureshi Textiles"])).toEqual([]);

    respond("client_workspace", workspace([PDF]));
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft", documents: [] });
    respondWith("assist_prep_documents_add", () => { throw { code: "documents_too_large", message: "too large" }; });
    fireEvent.click(within(failed).getByRole("button", { name: "دوبارہ کوشش کریں" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("checkbox", { name: "Proforma March.pdf" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("اس سیشن کے لیے دستاویزات بہت بڑی ہیں۔ پہلے والی اب بھی استعمال ہو رہی ہیں۔");
    expect(latinLines(["Qureshi Textiles", "Proforma March.pdf"])).toEqual([]);
  });

  it("the start's refusals: the core's sentence as it comes, a running session in Urdu, another device's, a brief too long", async () => {
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    fail("assist_start", "Deep is in the Ultra plan.");
    await urdu();
    const first = render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "ابھی شروع کریں" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("Deep is in the Ultra plan.");
    expect(screen.getByRole("alert")).toHaveAttribute("dir", "ltr");
    first.unmount();

    // No words from the core: the app's own, and its button; the other session isn't on this computer.
    respondWith("assist_start", () => { throw { code: "session_active", practice: false }; });
    respond("assist_state", null);
    respond("assist_keep_running", null);
    const second = render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "ابھی شروع کریں" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("لائیو اسسٹ کا ایک اور سیشن چل رہا ہے۔");
    expect(screen.getByRole("button", { name: "چلنے دیں" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "اسے ختم کر کے یہ شروع کریں" }));
    await act(flushAsync);
    expect(callsOf("assist_start_replacing")).toHaveLength(0);
    expect(screen.getByRole("alert")).toHaveTextContent("دوسرا سیشن کسی اور ڈیوائس پر چل رہا ہے۔ اسے وہیں ختم کریں، پھر یہ شروع کریں۔");
    expect(screen.getByRole("alert")).toHaveAttribute("dir", "rtl");
    second.unmount();

    respondWith("assist_start", () => { throw { code: "session_active", session_id: "as-0", practice: true }; });
    const third = render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "ابھی شروع کریں" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("مشق کا ایک سیشن چل رہا ہے۔");
    fireEvent.click(screen.getByRole("button", { name: "چلنے دیں" }));
    await act(flushAsync);
    expect(callsOf("assist_keep_running")).toHaveLength(1);
    third.unmount();

    render(<PrepScreen projects={[]} onBack={vi.fn()} onStarted={vi.fn()} />);
    fireEvent.change(screen.getByRole("textbox", { name: "مقاصد" }), { target: { value: "ا".repeat(4001) } });
    expect(screen.getByRole("alert")).toHaveTextContent("بریف کے ہر حصے میں زیادہ سے زیادہ 4,000 حروف ہو سکتے ہیں۔");
    expect(screen.getByRole("button", { name: "ابھی شروع کریں" })).toBeDisabled();
  });
});

describe("the prep in English", () => {
  it("as before in an English window: its words, the template, the note, the refusal's fallback", async () => {
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    respondWith("assist_start", () => { throw { code: "session_active", practice: false }; });
    render(<PrepScreen projects={PROJECTS} onBack={vi.fn()} onStarted={vi.fn()} />);
    expect(screen.getByRole("heading", { level: 1, name: "New Live Assist session" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: /Meeting type/ })).toHaveAccessibleName("Meeting type Interview");
    expect(screen.getByRole("textbox", { name: "Title" })).toHaveAttribute("placeholder", "Interview at Mehran Foods");
    expect(screen.getByRole("textbox", { name: "Goals" })).toHaveValue("Show the experience the role asks for, with one example each.");
    expect(screen.getByRole("combobox", { name: /^Mode/ })).toHaveAccessibleName("Mode Balanced");
    expect(screen.getByRole("note")).toHaveTextContent(INTERVIEW_NOTE);
    expect(screen.getByText("Link a project to use its files: a job description, a proposal, last month's invoices.")).toBeInTheDocument();
    pick(/Meeting type/, "Prospective client");
    expect(screen.getByRole("textbox", { name: "Goals" })).toHaveValue("Understand what they need and agree a first step.");
    expect(within(screen.getByRole("note")).getByText(CONSENT_LINE)).not.toHaveAttribute("lang");
    fireEvent.click(screen.getByRole("button", { name: "Start now" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("Another Live Assist session is running.");
    expect(screen.getByRole("button", { name: "End it and start this one" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Keep running" })).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(URDU);
    expect([templates(), meetingTypes(), interviewNote()]).toEqual([TEMPLATES, MEETING_TYPES, INTERVIEW_NOTE]);
  });

  it("classic's Record page keeps the prep in English in an Urdu window", async () => {
    respond("assist_preps_list", []);
    respond("assist_prep_save", { ...PREP, id: "pr9", status: "draft" });
    respondWith("assist_start", () => { throw { code: "session_active", practice: true }; });
    await urdu();
    render(<LiveAssistClassic projects={PROJECTS} />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "New session" }));
    expect(screen.getByRole("heading", { level: 1, name: "New Live Assist session" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: /Meeting type/ })).toHaveAccessibleName("Meeting type Interview");
    expect(screen.getByRole("textbox", { name: "Goals" })).toHaveValue(TEMPLATES.interview.goals);
    expect(screen.getByRole("note")).toHaveTextContent(INTERVIEW_NOTE);
    pick(/Meeting type/, "Existing client");
    expect(screen.getByRole("textbox", { name: "To discuss" })).toHaveValue(TEMPLATES.existing_client.to_discuss);
    expect(screen.getByRole("button", { name: "Copy" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Start now" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("A practice session is running.");
    expect(document.body.textContent).not.toMatch(URDU);
  });
});

const play = (messages: readonly AssistMessage[], from: CardState = EMPTY_CARD) => messages.reduce(cardReducer, from);
const READY: AssistMessage = { type: "ready", session_id: "as-1", mode: "balanced", requested_mode: "balanced", allowed_modes: ["balanced", "fast"], resumed: false, practice: false, meeting_type: "existing_client" };
const actions = () => ({ onSend: vi.fn(), onMode: vi.fn(), onHint: vi.fn(), onMute: vi.fn(), onHide: vi.fn(), onCopyConsent: vi.fn(), onStartOffer: vi.fn(), onDismissOffer: vi.fn(), onEndAndStart: vi.fn(), onKeepRunning: vi.fn() });
/** The fixture session's own words: the other side's question, the suggestion and the captions. */
const SESSION_KEPT = [
  "Tell me about a time you handled a difficult client.", "Talk about the Qureshi Textiles payment dispute you settled in two weeks.",
  "Listened first", ": called the owner, not just emailed", "Split the issue", ": price vs. delivery date", "Result", ": kept the account, 40% advance agreed",
  "Would you like the numbers from that quarter?", "Woh poochh rahe hain ke mushkil client ko kaise sambhala.", "Sure, it was with a textile exporter in Faisalabad.",
];

describe("the Live Assist card in Urdu", () => {
  it("its bar, the interview's note, the suggestion's parts, the actions and the captions; the session's words as they are", async () => {
    await urdu();
    const a = actions();
    render(<AssistCard state={play([...FIXTURE_SESSION, { type: "budget", seconds_left: 3600, seconds_total: 7200 }])} hint="outline" muted={false} actions={a} />);
    const card = screen.getByRole("region", { name: "لائیو اسسٹ" });
    const mode = within(card).getByRole("button", { name: `موڈ: ${iso("متوازن")}۔ ${iso("تیز")} پر بدلیں` });
    expect(mode).toHaveTextContent("متوازن");
    fireEvent.click(mode);
    expect(a.onMode).toHaveBeenCalledWith("fast");
    fireEvent.click(within(card).getByRole("button", { name: `اشارے: ${iso("خاکہ")}۔ ${iso("پورا جواب")} پر بدلیں` }));
    expect(a.onHint).toHaveBeenCalledWith("full");
    expect(within(card).getByRole("button", { name: "60 سیکنڈ خاموش کریں" })).toBeInTheDocument();
    expect(within(card).getByRole("button", { name: "کارڈ چھپائیں" })).toBeInTheDocument();
    const left = within(card).getByRole("progressbar", { name: "اسسٹ کا باقی وقت" });
    expect(left).toHaveAttribute("aria-valuetext", `اس مہینے اسسٹ کے ${iso(60)} منٹ باقی ہیں`);

    const note = within(card).getByRole("note");
    expect(note).toHaveTextContent("دیکھ لیں کہ اس انٹرویو میں اے آئی کی مدد کی اجازت ہے یا نہیں۔");
    expect(within(note).queryByRole("button", { name: "کاپی" })).toBeNull();
    expect(within(note).getByRole("button", { name: "ٹھیک" })).toBeInTheDocument();

    expect(card.querySelector(".el-assist__bullets")).toHaveAttribute("dir", "auto");
    const next = within(card).getByText("اگلا کہیں").closest("p")!;
    expect(next.querySelector("bdi")).toHaveTextContent("Would you like the numbers from that quarter?");
    const languageLabel = within(card).getByText("رومن اردو");
    expect(languageLabel).toHaveAttribute("lang", "ur");
    expect(languageLabel).toHaveAttribute("dir", "rtl");
    expect(languageLabel.closest("p")).toHaveAttribute("dir", "ltr");

    for (const name of ["ابھی مدد کریں", "دوبارہ لکھیں", "مختصر", "زیادہ رسمی", "اردو میں سمجھائیں"]) fireEvent.click(within(card).getByRole("button", { name }));
    expect(a.onSend.mock.calls.map((c) => c[0])).toEqual(["help_now", "regenerate", "shorter", "formal", "translate"]);
    expect(within(screen.getByRole("list", { name: "کیپشنز" })).getAllByRole("listitem").map((l) => l.textContent)).toEqual([
      "وہ Tell me about a time you handled a difficult client.", "میں Sure, it was with a textile exporter in Faisalabad.",
    ]);
    expect(latinLines(SESSION_KEPT, card)).toEqual([]);
  });

  it("a client meeting's consent line stays English with Copy; muted, writing, no reply and the wait in Urdu; why the mode changed", async () => {
    await urdu();
    const view = render(<AssistCard state={play([READY])} hint="full" muted actions={actions()} />);
    const note = screen.getByRole("note");
    const line = within(note).getByText(CONSENT_LINE);
    expect(line).toHaveAttribute("lang", "en");
    expect(line).toHaveAttribute("dir", "ltr");
    expect(within(note).getByRole("button", { name: "کاپی" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "60 سیکنڈ کے لیے خاموش" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByText("جب وہ آپ سے کچھ پوچھیں گے تو تجاویز یہاں آئیں گی۔")).toBeInTheDocument();
    expect(latinLines([CONSENT_LINE])).toEqual([]);

    view.rerender(<AssistCard state={play([READY, { type: "suggestion_start", suggestion_id: "s1", turn_id: null, kind: "help_now", speculative: false, replaces: null, mode: "balanced", model: "haiku", draft: false }])}
      hint="full" muted={false} actions={actions()} />);
    expect(screen.getByText("تجویز لکھی جا رہی ہے")).toBeInTheDocument();
    view.rerender(<AssistCard state={play([READY, { type: "skip" }])} hint="full" muted={false} actions={actions()} />);
    expect(screen.getByText("جواب کی ضرورت نہیں")).toBeInTheDocument();

    view.rerender(<AssistCard state={play([READY, { type: "mode_changed", from: "fast", to: "captions_only", reason: "budget" }])} hint="full" muted={false} actions={actions()} />);
    expect(screen.getByRole("status")).toHaveTextContent("اس مہینے کے اسسٹ کے گھنٹے ختم ہو گئے۔ اسسٹ رکا ہوا ہے؛ کیپشنز چلتے رہیں گے۔");
    expect(screen.getByRole("button", { name: `موڈ: ${iso("صرف کیپشنز")}` })).toBeDisabled();
    expect(screen.queryByRole("button", { name: "ابھی مدد کریں" })).toBeNull();

    view.rerender(<AssistCard state={play([READY, { type: "error", code: "rate_limited", message: "x", retry: true, suggestion_id: null, in_reply_to: null, fatal: false }])}
      hint="full" muted={false} actions={actions()} />);
    expect(screen.getByRole("alert")).toHaveTextContent("ایک ساتھ بہت زیادہ درخواستیں۔ ذرا دیر بعد دوبارہ کوشش کریں۔");
    expect(latinLines([CONSENT_LINE])).toEqual([]);
  });

  it("the core's offer asks in Urdu, the meeting's and the caller's names as they are, laid out right to left", async () => {
    await urdu();
    const a = actions();
    render(<AssistCard state={play([{ type: "offer", prep_id: "pr1", title: "Interview at Mehran Foods", client_ref: "r1", caller: "Ayesha Siddiqui" }])}
      hint="full" muted={false} actions={a} />);
    const question = screen.getByText((_, el) => el?.tagName === "P" && stripIsolates(el.textContent ?? "") === "Interview at Mehran Foods کے لیے Ayesha Siddiqui کے ساتھ لائیو اسسٹ شروع کریں؟");
    expect(question.textContent).toBe(`${iso("Interview at Mehran Foods")} کے لیے ${iso("Ayesha Siddiqui")} کے ساتھ لائیو اسسٹ شروع کریں؟`);
    expect(question).toHaveAttribute("dir", "rtl");
    fireEvent.click(screen.getByRole("button", { name: "شروع کریں" }));
    fireEvent.click(screen.getByRole("button", { name: "ابھی نہیں" }));
    expect(a.onStartOffer).toHaveBeenCalled();
    expect(a.onDismissOffer).toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "کارڈ چھپائیں" })).toBeInTheDocument();
    expect(latinLines(["Interview at Mehran Foods", "Ayesha Siddiqui"])).toEqual([]);
  });

  it("the card's words from its helpers: every error code, why the mode changed, the offer; English where asked", async () => {
    await urdu();
    for (const code of ["budget_exhausted", "provider_not_configured", "provider_timeout", "model_declined", "nothing_to_rewrite", "documents_too_large", "rate_limited", "session_active", "token_expired", "something_new"]) {
      for (const retry of [true, false]) {
        const words = errorWords(code, retry);
        if (words !== null) expect(latinIn(words), `${code} ${retry}`).toBe(false);
      }
    }
    expect(errorWords("model_declined", false)).toBe("اس کا جواب یہاں نہیں دیا جا سکتا۔ “دوبارہ لکھیں” آزمائیں، یا دوسرے الفاظ میں پوچھیں۔");
    expect(errorWords("token_expired", false)).toBe("لائیو اسسٹ رک گیا: نیا سیشن شروع کرنے کے لیے دوبارہ سائن اِن کریں۔");
    expect(stripIsolates(modeNote("fast", "budget")!)).toBe("اسسٹ کے گھنٹوں میں رہنے کے لیے تیز پر منتقل کر دیا۔");
    expect(stripIsolates(modeNote("balanced", "plan")!)).toBe("متوازن آپ کے پلان کا بہترین موڈ ہے۔");
    expect(modeNote("captions_only", "plan")).toBe("لائیو اسسٹ آپ کے پلان میں شامل نہیں۔ کیپشنز چلتے رہیں گے۔");
    expect(offerQuestion({ title: " ", caller: null })).toBe("اس کال کے لیے لائیو اسسٹ شروع کریں؟");
    expect(stripIsolates(offerQuestion({ title: "", caller: "Bilal Qureshi" }))).toBe("اس کال کے لیے Bilal Qureshi کے ساتھ لائیو اسسٹ شروع کریں؟");
    expect(errorWords("documents_too_large", false, "", "en")).toBe("The documents are too large for this session. The earlier ones are still used.");
    expect(modeNote("fast", "budget", "en")).toBe("Switched to Fast to stay within your assist hours.");
    expect(offerQuestion({ title: "March order", caller: null }, "en")).toBe("Start Live Assist for March order?");
  });
});

describe("the card's window follows the main window's language", () => {
  beforeEach(() => {
    respond("assist_state", null);
    respond("set_hit_regions", null);
    respond("ui_flags", { ui_flags: { live_assist: true, "ui_v2.live": true } });
  });
  const session = async () => {
    for (const m of [READY, { type: "suggestion_start", suggestion_id: "s1", turn_id: null, kind: "help_now", speculative: false, replaces: null, mode: "balanced", model: "haiku", draft: false },
      { type: "token", suggestion_id: "s1", text: "Confirm the March date." }] as AssistMessage[]) await act(() => emitEvent("assist:message", m));
  };

  it("Urdu while the account's is Urdu in the new design (what the main window keeps), laid out right to left", async () => {
    localStorage.setItem(KEPT_LANGUAGE_KEY, JSON.stringify({ on: true, account: "ur" }));
    render(<AssistApp />);
    await waitFor(() => expect(language()).toBe("ur"));
    await act(flushAsync);
    await session();
    expect(await screen.findByText("Confirm the March date.")).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 1, name: "لائیو اسسٹ" })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "لائیو اسسٹ" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "ابھی مدد کریں" })).toBeInTheDocument();
    expect(document.documentElement).toHaveAttribute("dir", "rtl");
    expect(document.documentElement).toHaveAttribute("lang", "ur");

    // The main window changes it: this window follows.
    localStorage.setItem(KEPT_LANGUAGE_KEY, JSON.stringify({ on: true, account: "en" }));
    await act(async () => { window.dispatchEvent(new StorageEvent("storage", { key: KEPT_LANGUAGE_KEY })); });
    await waitFor(() => expect(screen.getByRole("button", { name: "Help me now" })).toBeInTheDocument());
    expect(document.documentElement).toHaveAttribute("dir", "ltr");
  });

  it("English in classic (Urdu off), whatever the account's language", async () => {
    localStorage.setItem(KEPT_LANGUAGE_KEY, JSON.stringify({ on: false, account: "ur" }));
    render(<AssistApp />);
    await act(flushAsync);
    await session();
    expect(await screen.findByText("Confirm the March date.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Help me now" })).toBeInTheDocument();
    expect(language()).toBe("en");
    expect(document.body.textContent).not.toMatch(URDU);
  });
});
