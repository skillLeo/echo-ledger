// Desktop Urdu, D-261: Ask (the Ask page, and a project's Ask tab) in the account's language: the title, the project
// choice, the question and its sample, the answer languages by their names in Urdu (انگریزی, رومن اردو), Ask, the idle,
// asking, failed and no-AI states, the answer's Copy and Clear and its sources (ماخذ, a file's page). The projects'
// names, the answer and its sources are as they are; a sentence the server wrote for people is shown as it comes.
// English is unchanged (ask.test.tsx keeps every English name).
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { fail, flushAsync, respond, respondWith, resetTauriMock } from "../../test/tauri";
import { FSI, PDI, resetLanguageForTests, setLanguage, stripIsolates } from "../../i18n";
import { textsIn } from "../../i18n/rawText";
import ur from "../../i18n/ur.json";
import { AskV2, OFFLINE, TRY_AGAIN, askFailureWords, citationValue, offlineWords, tryAgainWords } from "./AskV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const here = dirname(fileURLToPath(import.meta.url));
const iso = (value: string | number) => `${FSI}${value}${PDI}`;
const urdu = () => act(() => setLanguage("ur", { root: false }));
const PROJECTS = [{ id: "c1", name: "Qureshi Textiles" }, { id: "c2", name: "Mehran Foods" }];
const ANSWER = {
  data: {
    answer: "Bilal agreed to 40% in advance and the balance against the bill of lading.",
    citations: [
      { type: "call" as const, session_id: "bilal", title: "Payment terms for the March shipment", at_ms: 31_000, quote: "This time we need forty percent in advance." },
      { type: "file" as const, file_id: "f1", title: "Proforma March.pdf", page: 2, quote: null },
    ],
  },
};
const WORKSPACE = (configured: boolean) => ({ data: { client: { id: "c1", name: "Qureshi Textiles", ai_notes: true }, ai: { configured, spent_usd: 0, budget_usd: 5, client_spent_usd: 0 },
  brief: null, memory: [], notes: [], files: [] } });

/** The names and the data Ask shows as they are (longest first). */
const KEPT = ["Qureshi Textiles", "Mehran Foods", ANSWER.data.answer, ...ANSWER.data.citations.flatMap((c) => [c.title, c.quote ?? ""]).filter(Boolean)]
  .sort((a, b) => b.length - a.length);
const latinIn = (words: string) => /[A-Za-z]/.test(KEPT.reduce((rest, name) => rest.split(name).join(""), stripIsolates(words)));
/** Every word Ask shows or says: its text, and its labels, titles and placeholders. */
function wordsOf(root: Element): string[] {
  const out: string[] = [];
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) if (node.textContent?.trim()) out.push(node.textContent.trim());
  for (const element of root.querySelectorAll("[aria-label], [title], [placeholder]")) {
    for (const name of ["aria-label", "title", "placeholder"]) if (element.getAttribute(name)) out.push(element.getAttribute(name)!);
  }
  return out;
}
const latinWords = () => wordsOf(document.querySelector("[data-v2=ask]")!).filter(latinIn);
const props = () => ({ onOpenAt: vi.fn(), onOpenProject: vi.fn(), onLibrary: vi.fn() });

beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
  respond("client_workspace", WORKSPACE(true));
});
afterEach(async () => {
  await flushAsync();
  resetLanguageForTests();
});

describe("Ask: every word has its Urdu, and English is as it was", () => {
  it("every text AskV2 gives to t() or tc() is in the catalogue", () => {
    const path = join(here, "AskV2.tsx");
    const keys = textsIn(path, readFileSync(path, "utf8")).filter((f) => f.translated).map((f) => f.text);
    expect(keys.length).toBeGreaterThan(20);
    expect(keys.filter((key) => !(key in ur))).toEqual([]);
  });

  it("the failure words and a file's page, in English as before and in Urdu", async () => {
    expect([OFFLINE, TRY_AGAIN]).toEqual(["The answer couldn't be made. Check the internet connection and try again.", "The answer couldn't be made. Try again."]);
    expect([askFailureWords("Network error: dns error", true), askFailureWords("Server returned 502: Bad gateway", true)]).toEqual([OFFLINE, TRY_AGAIN]);
    expect(citationValue({ type: "file", title: "x", page: 3 })).toBe("Page 3");
    await urdu();
    expect(askFailureWords("Network error: dns error", true)).toBe("جواب نہیں بن سکا۔ انٹرنیٹ کنکشن چیک کریں اور دوبارہ کوشش کریں۔");
    expect(askFailureWords("Server returned 502: Bad gateway", true)).toBe("جواب نہیں بن سکا۔ دوبارہ کوشش کریں۔");
    // The server's own sentence for people, as it comes.
    expect(askFailureWords("This month's AI budget ($5.00) is used up.", true)).toBe("This month's AI budget ($5.00) is used up.");
    expect(citationValue({ type: "file", title: "x", page: 3 })).toBe(`صفحہ ${iso(3)}`);
    expect(citationValue({ type: "call", title: "x", at_ms: 754_000 })).toBe("12:34");
    // Their English whatever the window's language is.
    expect([offlineWords("en"), tryAgainWords("en"), OFFLINE]).toEqual([OFFLINE, TRY_AGAIN, "The answer couldn't be made. Check the internet connection and try again."]);
  });
});

describe("Ask in Urdu", () => {
  it("no projects: the title, why, and Open Library", async () => {
    await urdu();
    render(<AskV2 projects={[]} {...props()} />);
    expect(screen.getByRole("heading", { level: 1, name: "پوچھیں" })).toBeInTheDocument();
    const none = screen.getByRole("region", { name: "ابھی کوئی پروجیکٹ نہیں" });
    expect(none).toHaveTextContent("پوچھیں کسی پروجیکٹ کی ریکارڈنگز پر کام کرتا ہے۔ پہلے لائبریری سے ریکارڈنگز کسی پروجیکٹ میں رکھیں۔");
    expect(within(none).getByRole("button", { name: "لائبریری کھولیں" })).toBeInTheDocument();
    expect(latinWords()).toEqual([]);
  });

  it("before asking: the project, the question and its sample, the answer languages by their Urdu names, Ask, the idle state", async () => {
    await urdu();
    render(<AskV2 projects={PROJECTS} {...props()} />);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "پوچھیں" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: /^کس بارے میں پوچھیں/ })).toHaveAccessibleName("کس بارے میں پوچھیں Qureshi Textiles");
    expect(screen.getByRole("textbox", { name: "آپ کا سوال" })).toHaveAttribute("placeholder", "بلال ٹریڈرز نے ادائیگی پر کیا طے کیا؟");
    expect(within(screen.getByRole("radiogroup", { name: "جواب کی زبان" })).getAllByRole("radio").map((r) => r.textContent)).toEqual(["انگریزی", "رومن اردو"]);
    expect(screen.getByRole("button", { name: "پوچھیں" })).toBeDisabled();
    expect(screen.getByRole("region", { name: "اپنی ریکارڈنگز سے پوچھیں" })).toHaveTextContent("پروجیکٹ چنیں، پھر اپنے الفاظ میں پوچھیں۔ جواب بتاتے ہیں کہ وہ کس ریکارڈنگ سے ہے۔");
    expect(latinWords()).toEqual([]);
  });

  it("asking, then the answer: Copy answer and Clear, its sources (ماخذ) with a call's moment and a file's page", async () => {
    let answer: (v: unknown) => void = () => undefined;
    respondWith("client_ask", () => new Promise((r) => (answer = r)));
    const writeText = vi.fn(async () => undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    await urdu();
    render(<AskV2 projects={PROJECTS} {...props()} />);
    await act(flushAsync);
    fireEvent.change(screen.getByRole("textbox", { name: "آپ کا سوال" }), { target: { value: "ادائیگی کب ہو گی؟" } });
    fireEvent.click(screen.getByRole("radio", { name: "رومن اردو" }));
    fireEvent.click(screen.getByRole("button", { name: "پوچھیں" }));
    expect(screen.getByRole("status", { busy: true })).toHaveTextContent("پوچھا جا رہا ہے");
    expect(latinWords()).toEqual([]);
    await act(async () => answer(ANSWER));
    const said = screen.getByRole("region", { name: "جواب" });
    expect(within(said).getByRole("button", { name: "جواب ہٹائیں" })).toBeInTheDocument();
    fireEvent.click(within(said).getByRole("button", { name: "جواب کاپی کریں" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenCalledWith(ANSWER.data.answer);
    expect(within(said).getByRole("button", { name: "کاپی ہو گیا" })).toBeInTheDocument();
    const from = screen.getByRole("list", { name: "ماخذ" });
    expect(within(from).getAllByRole("listitem").map((r) => r.querySelector(".el-row__value")?.textContent).filter(Boolean)).toEqual(["0:31", `صفحہ ${iso(2)}`]);
    expect(latinWords()).toEqual([]);
  });

  it("a failed answer in the app's words with Ask again; no AI on the server says so before anything is asked", async () => {
    fail("client_ask", "Network error: error sending request");
    await urdu();
    const { unmount } = render(<AskV2 projects={PROJECTS} {...props()} />);
    await act(flushAsync);
    fireEvent.change(screen.getByRole("textbox", { name: "آپ کا سوال" }), { target: { value: "ادائیگی کب ہو گی؟" } });
    fireEvent.click(screen.getByRole("button", { name: "پوچھیں" }));
    await act(flushAsync);
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("جواب نہیں بن سکا۔ انٹرنیٹ کنکشن چیک کریں اور دوبارہ کوشش کریں۔");
    expect(within(alert).getByRole("button", { name: "پوچھیں" })).toBeInTheDocument();
    expect(latinWords()).toEqual([]);
    unmount();

    respond("client_workspace", WORKSPACE(false));
    render(<AskV2 projects={PROJECTS} embedded {...props()} />);
    await act(flushAsync);
    expect(screen.getByTestId("ask-no-ai")).toHaveTextContent("پوچھیں کے لیے سرور پر اے آئی سیٹ اپ ہونا ضروری ہے۔ کنسول چلانے والے سے اسے سیٹ اپ کرنے کو کہیں۔");
    expect(screen.queryByRole("heading", { level: 1 })).toBeNull();
    expect(latinWords()).toEqual([]);
  });

  it("follows a change of language while it is on screen, back to the same English", async () => {
    render(<AskV2 projects={PROJECTS} {...props()} />);
    await act(flushAsync);
    expect(screen.getAllByRole("radio").map((r) => r.textContent)).toEqual(["English", "Roman Urdu"]);
    await urdu();
    expect(screen.getAllByRole("radio").map((r) => r.textContent)).toEqual(["انگریزی", "رومن اردو"]);
    expect(screen.getByRole("textbox", { name: "آپ کا سوال" })).toBeInTheDocument();
    await act(() => setLanguage("en", { root: false }));
    expect(screen.getAllByRole("radio").map((r) => r.textContent)).toEqual(["English", "Roman Urdu"]);
    expect(screen.getByRole("textbox", { name: "Your question" })).toHaveAttribute("placeholder", "What did Bilal Traders agree on payment?");
    expect(screen.getByRole("radiogroup", { name: "Answer in" })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "Ask your recordings" })).toBeInTheDocument();
  });
});
