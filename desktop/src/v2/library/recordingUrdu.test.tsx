// Desktop Urdu, D4: the recording page in the account's language. Its head (back, title, the meta line, the badges,
// Transcribed in, Share and the ⋮ with Export ▸), the player (its controls, the keys' hint, the bookmarks), the copy menu
// and its messages, the transcript's tools and states (transcribing with its steps, failed with Try again, the key's
// lines, empty, nothing heard, audio only, waiting for its engine, a wrong script, live captions), the screens and their
// viewer, the details; the dialogs (rename, transcribe, move, label, change language, Send to WhatsApp) with their
// messages; and the lib words they show, each value in its own isolate, the counts in their one|other forms and the
// dates in lib/format's shape. English is the page's own tests', unchanged.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { ClientFolder, LibraryItem, TranscriptDetail } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { answerConfirm } from "../../test/confirm";
import { answerConfirmForTests } from "../../lib/confirm";
import { makeItem, makePendingItem, makeScreen, makeSkippedItem, makeTranscript } from "../../test/fixtures";
import { forgetPictures } from "../../lib/screens";
import { formatWhen, whenParts } from "../../lib/format";
import { LibraryV2 } from "./LibraryV2";
import { LabelDialog, LanguageDialog, MoveDialog, RenameDialog, TranscribeDialog, WhatsAppDialog } from "./organise";
import { failureLine, languageChip, languageLabel, partsText, retryLine, transcribedIn } from "../../lib/languages";
import { convertingText, etaText, transcribingLine, uploadLine } from "../../lib/progress";
import { bulkNotice, deletedNotice, discardAllQuestion, heldLine, skippedLine, skippedMeta, skipShorterThan, waitingLine, waitingSummary } from "../../lib/pending";
import { playerHint } from "../../lib/player";
import { sourceAppLabel } from "../../lib/format";
import { FSI, PDI, RLI, resetLanguageForTests, setLanguage } from "../../i18n";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 17, 15, 0);
const iso = (value: string | number) => `${FSI}${value}${PDI}`;
/** Any Arabic-script letter or an isolate mark: what an English line must not have. */
const URDU_OR_ISOLATE = /[؀-ۿ⁦-⁩]/;
const ACME = { id: "p1", name: "Acme" } as LibraryItem["client"];
const STARTED = new Date(2026, 10, 17, 11, 42).toISOString();
const call = (extra: Partial<LibraryItem> = {}, transcript: Partial<NonNullable<LibraryItem["transcript"]>> = {}): LibraryItem => makeItem("bilal", {
  title: "Bilal Qureshi", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: STARTED, duration_ms: 3_730_000, language: "ur", screen_count: 0,
  transcript: { final_status: "completed", final_transcript_id: "tr-bilal", live_available: false, preview: "Hello", final_language: "ur", ...transcript },
  ...extra,
});

async function open(item: LibraryItem, transcript: TranscriptDetail | null = makeTranscript({ id: "tr-bilal" }), detail: Record<string, unknown> = {}) {
  respond("library_list", [item]);
  respond("library_refresh", [item]);
  respond("bin_list", { data: [] });
  respond("unsure_markers", null);
  respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", speaker_names: { Client: "Bilal" }, ...detail });
  if (transcript) respond("transcript_detail", transcript);
  render(<LibraryV2 />);
  await act(flushAsync);
  fireEvent.click(screen.getByRole("button", { name: /^Bilal Qureshi/ }));
  await act(flushAsync);
}
const toast = () => document.querySelector(".el-toast-region") as HTMLElement;

beforeEach(async () => {
  resetTauriMock();
  resetLanguageForTests();
  window.localStorage.clear();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(NOW);
  Object.assign(HTMLMediaElement.prototype, { play: vi.fn(() => Promise.resolve()), pause: vi.fn() });
  await setLanguage("ur", { root: false });
});
afterEach(async () => {
  await flushAsync();
  answerConfirmForTests(null);
  vi.useRealTimers();
  resetLanguageForTests();
});

describe("the recording page in Urdu", () => {
  it("its head: back, the meta line in the window's direction, the badges, Transcribed in, the project, Share and the ⋮ with Export ▸", async () => {
    await open(call({ client: ACME, title_auto: true, labels: ["Invoices"] }));
    expect(screen.getByRole("button", { name: "لائبریری" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Bilal Qureshi" })).toHaveAttribute("title", "نام بدلیں");
    expect(screen.getByText("ریکارڈنگ کا نام بدلنے کے لیے عنوان دبائیں")).toHaveClass("el-visually-hidden");
    // The pieces joined by dots read right to left, the date lib/format's own run.
    const meta = [...document.querySelectorAll(".el-page__meta span")].find((s) => s.textContent?.startsWith("WhatsApp"))!;
    expect(meta.textContent).toBe(`WhatsApp · ${formatWhen(STARTED)} · 1:02:10`);
    expect(formatWhen(STARTED).startsWith(RLI)).toBe(true);
    expect(meta).toHaveAttribute("dir", "rtl");
    // Each piece in its own isolate: the app's name and the length don't run together left to right.
    expect([...meta.querySelectorAll("bdi")].map((b) => b.textContent)).toEqual(["WhatsApp", formatWhen(STARTED), "1:02:10"]);
    expect(within(document.querySelector(".el-page__meta") as HTMLElement).getByText("Acme")).toBeInTheDocument();
    expect(screen.getByText("خودکار عنوان")).toBeInTheDocument();
    expect(screen.getByText("ٹرانسکرپٹ تیار")).toBeInTheDocument();
    expect(screen.getByRole("list", { name: "لیبلز" })).toHaveTextContent("Invoices");
    expect(screen.getByText(`${iso("اردو")} میں ٹرانسکرائب ہوئی`)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "شیئر کریں" })).toHaveAttribute("title", "صرف دیکھنے کا لنک شیئر کریں یا کسی ساتھی کو بھیجیں");

    fireEvent.click(screen.getByRole("button", { name: "ریکارڈنگ کے اختیارات" }));
    const menu = screen.getByRole("menu", { name: "ریکارڈنگ کے اختیارات" });
    const items = within(menu).getAllByRole("menuitem").map((m) => m.textContent);
    expect(items).toEqual(expect.arrayContaining(["زبان بدلیں اور دوبارہ ٹرانسکرائب کریں…", "ایکسپورٹ", "آڈیو محفوظ کریں"]));
    fireEvent.click(within(menu).getByRole("menuitem", { name: "ایکسپورٹ" }));
    expect(within(screen.getByRole("menu", { name: "ایکسپورٹ" })).getAllByRole("menuitem").map((m) => m.textContent)).toEqual([
      "ورڈ (.docx)", "PDF (پرنٹ)", "متن (.txt)", "مارک ڈاؤن (.md)", "سب ٹائٹلز (.srt)", "ویب سب ٹائٹلز (.vtt)", "JSON (.json)",
    ]);
  });

  it("the player: its controls, the time read out, the keys' hint with each key isolated, the bookmarks", async () => {
    await open(call(), makeTranscript({ id: "tr-bilal" }), { markers: [{ id: "m1", at_ms: 65_000, tag: "Invoice", note: null }] });
    expect(screen.getByRole("button", { name: "چلائیں" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "10 سیکنڈ پیچھے" })).toHaveAttribute("title", "10 سیکنڈ پیچھے");
    expect(screen.getByRole("button", { name: "10 سیکنڈ آگے" })).toBeInTheDocument();
    expect(screen.getByRole("slider", { name: "مقام" })).toHaveAttribute("aria-valuetext", `${iso("1:02:10")} میں سے ${iso("0:00")}`);
    expect(screen.getByRole("combobox", { name: /^چلانے کی رفتار/ })).toBeInTheDocument();
    expect(screen.getByRole("switch", { name: "خاموشی چھوڑ دیں" })).toBeInTheDocument();
    expect(screen.getByRole("switch", { name: "ساتھ ساتھ چلیں" })).toBeInTheDocument();
    expect(document.querySelector(".el-recording__keys")?.textContent).toBe(
      `${iso("Space")} سے چلائیں · ${iso("←")} / ${iso("→")} سے ${iso("5 s")} · ${iso("Shift+←")} / ${iso("Shift+→")} سے ${iso("10 s")}`,
    );
    expect(within(screen.getByRole("list", { name: "بک مارکس" })).getByRole("button")).toHaveTextContent("1:05 Invoice");
    // A line's time plays from it: its name says so, with the speaker.
    const transcript = screen.getByRole("list", { name: "ٹرانسکرپٹ" });
    expect(within(transcript).getAllByRole("button")[0]).toHaveAccessibleName(`${iso("0:00")} سے چلائیں · Me`);
  });

  it("Copy and its menu, the copy messages, Export's hint, the transcript's tools and the details", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    await open(call({ client: ACME }));
    const bar = screen.getByRole("toolbar", { name: "شیئر، کاپی اور ایکسپورٹ" });
    expect(within(bar).getByRole("button", { name: "واٹس ایپ پر بھیجیں…" })).toBeInTheDocument();
    expect(within(bar).getByRole("button", { name: /ایکسپورٹ/ })).toHaveAttribute("title", "PDF (پرنٹ) آپ کے براؤزر میں پرنٹ والا صفحہ کھولتا ہے: وہاں Save as PDF چنیں۔");
    fireEvent.click(within(bar).getByRole("button", { name: "متن کاپی کریں" }));
    await act(flushAsync);
    expect(toast()).toHaveTextContent("کاپی ہو گیا");
    fireEvent.click(within(bar).getByRole("button", { name: "کاپی کے مزید طریقے" }));
    expect(screen.getAllByRole("menuitem").map((m) => m.textContent)).toEqual(["وقت کے ساتھ کاپی کریں", "اے آئی کے لیے کاپی کریں"]);
    fireEvent.click(screen.getByRole("menuitem", { name: "وقت کے ساتھ کاپی کریں" }));
    await act(flushAsync);
    expect(toast()).toHaveTextContent("وقت کے ساتھ کاپی ہو گیا");
    fireEvent.click(within(bar).getByRole("button", { name: "کاپی کے مزید طریقے" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "اے آئی کے لیے کاپی کریں" }));
    await act(flushAsync);
    expect(toast()).toHaveTextContent("اے آئی کے لیے کاپی ہو گیا، غیر یقینی الفاظ کے نوٹ کے ساتھ");
    // What goes to the assistant keeps the web's English header, and never an isolate.
    const copied = (writeText.mock.calls.at(-1) as unknown as [string])[0];
    expect(copied).toMatch(/^# Bilal Qureshi\n/);
    expect(copied).toContain("\n\nNote: [?word]");
    expect(copied).toContain("People: Me, Bilal");
    expect(copied).toContain("Language: ur");
    expect(copied).not.toMatch(/[⁦-⁩]/);

    const tools = screen.getByRole("toolbar", { name: "ٹرانسکرپٹ کے ٹولز" });
    expect(tools).toHaveTextContent("حتمی ٹرانسکرپٹ");
    expect(within(tools).getAllByRole("button").map((b) => b.textContent)).toEqual(["تلاش کریں", "متن میں ترمیم", "ڈھونڈیں اور بدلیں", "بولنے والے", "تاریخچہ"]);
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["ٹرانسکرپٹ", "کال نوٹس", "تفصیلات"]);

    fireEvent.click(screen.getByRole("tab", { name: "تفصیلات" }));
    const details = document.querySelector("dl.el-details") as HTMLElement;
    expect([...details.querySelectorAll("dt")].map((d) => d.textContent)).toEqual([
      "ریکارڈ کا وقت", "دورانیہ", "ذریعہ", "کہاں ریکارڈ ہوئی", "پروجیکٹ", "کب تک رکھی جائے گی", "لیبلز", "پن شدہ",
    ]);
    const value = (term: string) => [...details.querySelectorAll("dt")].find((d) => d.textContent === term)!.nextElementSibling!.textContent;
    expect(value("ریکارڈ کا وقت")).toBe(formatWhen(STARTED));
    expect(value("کہاں ریکارڈ ہوئی")).toBe("کمپیوٹر");
    expect(value("کب تک رکھی جائے گی")).toBe("خودکار صفائی تک (ترتیبات › اسٹوریج)");
    expect(value("لیبلز")).toBe("کچھ نہیں");
    expect(value("پن شدہ")).toBe("نہیں");
  });

  it("transcribing: the steps (done said too), Notify me when ready, the parts and the time left in the empty state", async () => {
    respond("notify_when_ready", null);
    await open(call({ status: "processing" }, { final_status: "processing", final_progress: { done: 1, total: 3, eta_seconds: 40 } }),
      makeTranscript({ id: "tr-bilal", status: "processing", segments: [] }));
    const steps = within(screen.getByRole("list", { name: "پیش رفت" }));
    expect(steps.getAllByRole("listitem").map((li) => li.textContent)).toEqual(["اپ لوڈ ہو گئی (مکمل)", "ٹرانسکرپٹ بن رہا ہے", "بہتری"]);
    expect(screen.getByText("ٹرانسکرپٹ بن رہا ہے…")).toBeInTheDocument();
    const empty = screen.getByRole("heading", { name: "ٹرانسکرپٹ بن رہا ہے" }).parentElement as HTMLElement;
    expect(empty).toHaveTextContent(`ٹرانسکرپٹ بن رہا ہے… ${iso(3)} میں سے حصہ ${iso(2)} · تقریباً ${iso("40 s")} باقی`);
    fireEvent.click(screen.getByRole("button", { name: "تیار ہونے پر اطلاع دیں" }));
    await act(flushAsync);
    expect(callsOf("notify_when_ready")).toEqual([{ sessionId: "bilal" }]);
    expect(screen.getByText("تیار ہونے پر آپ کو اطلاع ملے گی۔")).toHaveAttribute("role", "status");
  });

  it("failed: why, in the app's words, and Try again with its message", async () => {
    respond("session_retranscribe", null);
    await open(call({ status: "failed" }, { final_status: "failed", final_error_code: "provider_timeout" }),
      makeTranscript({ id: "tr-bilal", status: "failed", error_code: "provider_timeout", segments: [] }));
    expect(screen.getByRole("heading", { name: "ٹرانسکرپشن ناکام ہو گئی" })).toBeInTheDocument();
    expect(screen.getByText("ٹرانسکرپشن نہیں ہو سکی")).toBeInTheDocument();
    expect(document.body).toHaveTextContent("ٹرانسکرپشن سروس وقت پر مکمل نہیں کر سکی۔ جو کہا گیا اسے پڑھنے، تلاش کرنے اور شیئر کرنے کے لیے اسے دوبارہ ٹرانسکرائب کریں۔");
    fireEvent.click(screen.getByRole("button", { name: "دوبارہ کوشش کریں" }));
    await act(flushAsync);
    expect(callsOf("session_retranscribe")).toEqual([{ id: "bilal", language: "ur" }]);
    expect(toast()).toHaveTextContent(`${iso("اردو")} میں دوبارہ ٹرانسکرائب ہو رہی ہے۔ نیا ٹرانسکرپٹ تیار ہونے تک موجودہ ٹرانسکرپٹ رہتا ہے۔`);
  });

  it("the key's lines: no key yet (Add my Groq key), the key refused (Check my Groq key)", async () => {
    await open(call({ status: "failed" }, { final_status: "failed", final_error_code: "needs_transcription_key" }),
      makeTranscript({ id: "tr-bilal", status: "failed", error_code: "needs_transcription_key", segments: [] }));
    expect(document.body).toHaveTextContent("یہ ریکارڈنگ ٹرانسکرائب نہیں ہوئی۔ اپنی Groq کلید ترتیبات › ٹرانسکرپشن کی کلید میں شامل کریں۔");
    expect(screen.getByRole("button", { name: "اپنی Groq کلید شامل کریں" })).toBeInTheDocument();
  });

  it("the key refused", async () => {
    await open(call({ status: "failed" }, { final_status: "failed", final_error_code: "own_key_rejected" }),
      makeTranscript({ id: "tr-bilal", status: "failed", error_code: "own_key_rejected", segments: [] }));
    expect(document.body).toHaveTextContent("Groq نے آپ کی کلید قبول نہیں کی۔ اکاؤنٹ ← ٹرانسکرپشن کی کلید میں اسے چیک کریں، پھر دوبارہ کوشش کریں۔");
    expect(screen.getByRole("button", { name: "اپنی Groq کلید چیک کریں" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "دوبارہ کوشش کریں" })).toBeNull();
  });

  it("empty, and nothing heard", async () => {
    await open(call(), makeTranscript({ id: "tr-bilal", segments: [] }));
    expect(screen.getByRole("heading", { name: "ٹرانسکرپٹ خالی ہے" })).toBeInTheDocument();
    expect(screen.getByText("اس ریکارڈنگ میں کوئی بات سنائی نہیں دی۔")).toBeInTheDocument();
  });

  it("nothing heard", async () => {
    await open(call({ status: "failed" }, { final_status: "failed", final_error_code: "no_audio" }),
      makeTranscript({ id: "tr-bilal", status: "failed", error_code: "no_audio", segments: [] }));
    expect(screen.getByRole("heading", { name: "کچھ سنائی نہیں دیا" })).toBeInTheDocument();
    expect(screen.getByText("ریکارڈنگ میں ٹرانسکرائب کرنے کو کوئی آواز نہیں۔ اگلی کال سے پہلے مائیکروفون اور سسٹم کی آڈیو چیک کریں۔")).toBeInTheDocument();
  });

  it("audio only: how long it is, and the Transcribe dialog with its minutes and its message", async () => {
    respond("sessions_transcribe_estimate", { minutes: 62, minutes_left: 120, items: [] });
    respond("sessions_transcribe", { started: ["bilal"], skipped: [], minutes: 62 });
    await open(call({ status: "completed", transcript: null }), null, { playback_url: null });
    expect(document.body).toHaveTextContent(`جو کہا گیا اسے پڑھنے، تلاش کرنے اور شیئر کرنے کے لیے یہ ریکارڈنگ ٹرانسکرائب کریں۔ یہ ${iso("1:02:10")} لمبی ہے۔`);
    fireEvent.click(screen.getByRole("button", { name: "ٹرانسکرائب کریں" }));
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: "یہ ریکارڈنگ ٹرانسکرائب کریں؟" });
    // The minutes are tc()'s (one|other), each one value of the sentence.
    expect(dialog).toHaveAccessibleDescription(`اس مہینے کے باقی ${iso(`${iso(120)} منٹ`)} میں سے تقریباً ${iso(`${iso(62)} منٹ`)}۔`);
    expect(within(dialog).getByRole("button", { name: "منسوخ کریں" })).toBeInTheDocument();
    fireEvent.click(within(dialog).getByRole("button", { name: "ٹرانسکرائب کریں" }));
    await act(flushAsync);
    expect(callsOf("sessions_transcribe")).toEqual([{ ids: ["bilal"] }]);
    expect(toast()).toHaveTextContent(`${iso(1)} ریکارڈنگ ٹرانسکرائب ہو رہی ہے`);
  });

  it("waiting for its engine (the time in lib/format's shape), a wrong script, live captions with their parts", async () => {
    const at = new Date(2026, 10, 17, 15, 30).toISOString();
    await open(call({ status: "processing" }, { final_status: "processing", script_warning: "latin", final_progress: { done: 1, total: 3 } }),
      makeTranscript({ id: "tr-bilal", status: "processing", retry_at: at, segments: [] }));
    const retry = screen.getByText(/ٹرانسکرپشن کا انتظار/);
    expect(retry.textContent).toBe(`ٹرانسکرپشن کا انتظار — ${iso(whenParts(at, NOW)!.time)} پر دوبارہ کوشش ہو گی`);
    expect(retry).toHaveAttribute("dir", "rtl");
    expect(screen.getByText(/غلط رسم الخط ملا/)).toHaveTextContent("غلط رسم الخط ملا: اردو انگریزی حروف میں لکھی گئی۔ اگلا انجن آزمانے کے لیے کنسول میں “دوبارہ ٹرانسکرائب کریں” استعمال کریں۔");
  });

  it("live captions say the parts whole", async () => {
    await open(call({ status: "processing" }, { final_status: "processing", final_transcript_id: null, live_transcript_id: "tr-live", final_progress: { done: 1, total: 3 } }),
      makeTranscript({ id: "tr-live", kind: "live" }));
    expect(screen.getByTestId("live-line")).toHaveTextContent(`لائیو کیپشنز (حتمی ٹرانسکرپٹ باقی · ${iso(`${iso(3)} میں سے حصہ ${iso(2)}`)})`);
    expect(screen.getByTestId("live-line")).toHaveAttribute("dir", "rtl");
  });

  it("the screens: the strip, a line's screens, the viewer with what was said, Delete screen asked and said", async () => {
    forgetPictures();
    const screens = [makeScreen(1, 4_000, { description: "The proforma invoice", file_name: "S01.webp" }), makeScreen(2, 1_000_000)];
    respond("session_screens", { data: screens });
    respondWith("screen_image", ({ url }) => `data:image/webp;base64,${btoa(String(url))}`);
    respond("screen_delete", null);
    await open(call({ screen_count: 2 }));
    const strip = screen.getByRole("region", { name: `${iso(2)} اسکرینز` });
    expect(screen.getByRole("heading", { name: `${iso(2)} اسکرینز` })).toHaveAttribute("dir", "rtl");
    expect(within(strip).getAllByRole("button").map((b) => b.getAttribute("aria-label"))).toEqual([
      `اسکرین ${iso(1)} · ${iso("0:04")}: ${iso("The proforma invoice")}`, `اسکرین ${iso(2)} · ${iso("16:40")}`,
    ]);
    expect(screen.getAllByRole("list", { name: "اس لمحے کی اسکرینز" }).length).toBeGreaterThan(0);
    fireEvent.click(within(strip).getAllByRole("button")[0]);
    await act(flushAsync);
    const viewer = screen.getByRole("dialog", { name: `اسکرین ${iso(1)} · ${iso("0:04")}` });
    expect(within(viewer).getByRole("region", { name: "کیا کہا گیا" })).toBeInTheDocument();
    expect(viewer).toHaveTextContent(`${iso(2)} میں سے ${iso(1)}`);
    expect(viewer).toHaveTextContent("فائل کا نام S01.webp");
    expect(within(viewer).getByRole("button", { name: "پچھلی اسکرین" })).toBeDisabled();
    fireEvent.click(within(viewer).getByRole("button", { name: "اگلی اسکرین" }));
    expect(within(screen.getByRole("dialog")).getByText("اس اسکرین سے 15 سیکنڈ پہلے یا بعد تک کچھ نہیں کہا گیا۔")).toBeInTheDocument();
    const asked = answerConfirm();
    asked.mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "اسکرین حذف کریں" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith(`اسکرین ${iso(2)} حذف کریں؟ ریکارڈنگ اور اس کا ٹرانسکرپٹ رہیں گے۔`);
    expect(toast()).toHaveTextContent(`اسکرین ${iso(2)} حذف ہو گئی`);
  });
});

describe("the organise dialogs in Urdu, with their messages", () => {
  const say = vi.fn();
  beforeEach(() => say.mockClear());

  it("Move: the choices, a project's name isolated, the message; several out of their projects", async () => {
    respond("list_clients", { data: [{ id: "p1", name: "Acme", parent_id: null } as ClientFolder] });
    respond("session_organise", { data: makeItem("a") });
    const { unmount } = render(<MoveDialog ids={["a"]} title="Call a" onClose={vi.fn()} say={say} />);
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: "پروجیکٹ میں منتقل کریں" });
    expect(dialog).toHaveAccessibleDescription("“Call a”");
    fireEvent.click(within(dialog).getByRole("combobox", { name: /^کہاں منتقل کریں/ }));
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["چنیں…", "کوئی پروجیکٹ نہیں", "Acme", "نیا پروجیکٹ…"]);
    fireEvent.click(screen.getByRole("option", { name: "Acme" }));
    fireEvent.click(within(dialog).getByRole("button", { name: "منتقل کریں" }));
    await act(flushAsync);
    expect(say).toHaveBeenLastCalledWith(`“${iso("Call a")}” ${iso("Acme")} میں منتقل ہو گئی`);
    unmount();

    respond("sessions_bulk", 3);
    render(<MoveDialog ids={["a", "b", "c"]} current="p1" onClose={vi.fn()} say={say} />);
    await act(flushAsync);
    const several = screen.getByRole("dialog", { name: "پروجیکٹ میں منتقل کریں" });
    expect(several).toHaveAccessibleDescription(`${iso(3)} ریکارڈنگز`);
    fireEvent.click(within(several).getByRole("combobox", { name: /^کہاں منتقل کریں/ }));
    fireEvent.click(screen.getByRole("option", { name: "کوئی پروجیکٹ نہیں" }));
    fireEvent.click(within(several).getByRole("button", { name: "منتقل کریں" }));
    await act(flushAsync);
    expect(say).toHaveBeenLastCalledWith(`${iso(3)} ریکارڈنگز اپنے پروجیکٹس سے نکال دی گئیں`);
  });

  it("Label: its title for several (tc), the labels in use, the message", async () => {
    respond("sessions_bulk", 3);
    render(<LabelDialog ids={["a", "b", "c"]} known={["Invoices", "Urgent"]} onClose={vi.fn()} say={say} />);
    const dialog = screen.getByRole("dialog", { name: `${iso(3)} ریکارڈنگز پر لیبل لگائیں` });
    expect(dialog).toHaveTextContent(`استعمال میں: ${iso("Invoices, Urgent")}`);
    fireEvent.change(within(dialog).getByRole("combobox", { name: "لیبل" }), { target: { value: "Paid" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "لیبل لگائیں" }));
    await act(flushAsync);
    expect(say).toHaveBeenLastCalledWith(`لیبل لگ گیا · ${iso(3)} ریکارڈنگز`);
  });

  it("Rename: its field and hints, and the messages", async () => {
    const item = makeItem("a", { title: "Call a", title_source: "own" });
    respond("session_organise", { data: { ...item, title: "Budget" } });
    render(<RenameDialog item={item} onClose={vi.fn()} say={say} />);
    const dialog = screen.getByRole("dialog", { name: "نام بدلیں" });
    const field = within(dialog).getByRole("textbox", { name: "عنوان" });
    expect(dialog).toHaveTextContent("EchoLedger کا دیا ہوا عنوان رکھنے کے لیے اسے خالی چھوڑ دیں۔");
    fireEvent.change(field, { target: { value: "Budget" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "محفوظ کریں" }));
    await act(flushAsync);
    expect(say).toHaveBeenLastCalledWith("نام بدل گیا");
  });

  it("Transcribe for several: the first ones now, the minutes, the skipped ones", async () => {
    respond("sessions_transcribe_estimate", { minutes: 1, minutes_left: null, items: [] });
    respond("sessions_transcribe", { started: ["a"], skipped: ["b"], minutes: 1 });
    render(<TranscribeDialog ids={["a", "b"]} of={60} onClose={vi.fn()} say={say} />);
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: `${iso(2)} ریکارڈنگز ٹرانسکرائب کریں؟` });
    // The web's "1 minute" form has its 1 in its words; the other form's count is isolated.
    expect(dialog).toHaveAccessibleDescription(`ابھی ${iso(60)} میں سے پہلی ${iso(2)}۔ تقریباً ${iso("1 منٹ")}۔`);
    fireEvent.click(within(dialog).getByRole("button", { name: "ٹرانسکرائب کریں" }));
    await act(flushAsync);
    expect(say).toHaveBeenLastCalledWith(`${iso(1)} ریکارڈنگ ٹرانسکرائب ہو رہی ہے · ${iso(1)} چھوڑ دی گئی (پہلے سے ٹرانسکرائب شدہ یا آڈیو نہیں)`);
  });

  it("Change language: the choices in words, the English-only warning, the message", async () => {
    respond("session_retranscribe", null);
    const item = makeItem("a", { language: "ur", transcript: { final_status: "completed", final_transcript_id: "t", live_available: false, preview: null, final_language: "ur" } });
    render(<LanguageDialog item={item} onClose={vi.fn()} say={say} />);
    const dialog = screen.getByRole("dialog", { name: "زبان بدلیں اور دوبارہ ٹرانسکرائب کریں" });
    fireEvent.click(within(dialog).getByRole("combobox", { name: /^زبان/ }));
    expect(screen.getAllByRole("option").slice(0, 5).map((o) => o.textContent)).toEqual([
      "خودکار (انگریزی / اردو / عربی)", "صرف انگریزی", "اردو (انگریزی الفاظ کے ساتھ)", "عربی (انگریزی الفاظ کے ساتھ)", "فرانسیسی",
    ]);
    fireEvent.click(screen.getByRole("option", { name: "صرف انگریزی" }));
    fireEvent.click(within(dialog).getByRole("button", { name: "دوبارہ ٹرانسکرائب کریں" }));
    expect(dialog).toHaveAccessibleDescription(/^“صرف انگریزی” پر اس ریکارڈنگ کی اردو/);
    fireEvent.click(within(dialog).getByRole("button", { name: "پھر بھی دوبارہ ٹرانسکرائب کریں" }));
    await act(flushAsync);
    expect(say).toHaveBeenLastCalledWith(`${iso("انگریزی")} میں دوبارہ ٹرانسکرائب ہو رہی ہے۔ نیا ٹرانسکرپٹ تیار ہونے تک موجودہ ٹرانسکرپٹ رہتا ہے۔`);
  });

  it("Send to WhatsApp: the three ways and what each sends", async () => {
    render(<WhatsAppDialog item={makeItem("a")} text="Hello" hasAudio onClose={vi.fn()} say={say} />);
    const dialog = screen.getByRole("dialog", { name: "واٹس ایپ پر بھیجیں" });
    expect(within(dialog).getAllByRole("button").map((b) => b.textContent)).toEqual(expect.arrayContaining(["صرف متن", "متن اور آواز", "لنک (7 دن تک چلے گا)", "منسوخ کریں"]));
    expect(dialog).toHaveTextContent("جس کے پاس یہ لنک ہو، وہ 7 دن تک پڑھ اور سن سکتا ہے۔");
  });
});

describe("the words the page shows, from lib", () => {
  it("in Urdu: whole sentences, each value isolated, counts in their forms, dates in lib/format's shape", () => {
    expect(languageLabel("ur")).toBe("اردو");
    expect(languageChip(null)).toBe("خودکار");
    expect(languageChip("ur")).toBe("UR");
    expect(transcribedIn({ final_status: "completed", final_language: "en" })).toBe("انگریزی");
    expect(partsText({ done: 0, total: 4 })).toBe(`${iso(4)} میں سے حصہ ${iso(1)}`);
    expect(transcribingLine({ done: 0, total: 1, eta_seconds: 200 })).toBe(`ٹرانسکرپٹ بن رہا ہے… تقریباً ${iso("3 min")} باقی`);
    expect(etaText(12)).toBe(`تقریباً ${iso("10 s")} باقی`);
    expect(convertingText("roman", 60)).toBe(`${iso("رومن اردو")} میں تبدیل ہو رہا ہے… ${iso(60)}%`);
    expect(uploadLine({ state: "uploading", progress: 42 })).toBe(`اپ لوڈ ہو رہی ہے… ${iso(42)}%`);
    expect(failureLine("interrupted")).toBe("ٹرانسکرپشن رک گئی۔");
    const tomorrow = new Date(2026, 10, 18, 9, 5).toISOString();
    expect(retryLine({ retry_at: tomorrow }, NOW)).toBe(`ٹرانسکرپشن کا انتظار — آنے والے کل ${iso(whenParts(tomorrow, NOW)!.time)} پر دوبارہ کوشش ہو گی`);
    const later = new Date(2026, 10, 22, 9, 5).toISOString();
    expect(retryLine({ retry_at: later }, NOW)).toBe(`ٹرانسکرپشن کا انتظار — ${iso(whenParts(later, NOW)!.day)} کو ${iso(whenParts(later, NOW)!.time)} پر دوبارہ کوشش ہو گی`);
    expect(sourceAppLabel("other")).toBe("دیگر");
    expect(sourceAppLabel("zoom")).toBe("Zoom");
    expect(playerHint()).toContain(`${iso("Space")} سے چلائیں`);
    // one|other
    expect([skippedLine(1), skippedLine(3)]).toEqual([`${iso(1)} مختصر کال چھوڑ دی گئی`, `${iso(3)} مختصر کالز چھوڑ دی گئیں`]);
    expect([bulkNotice("Saved as audio", 1), bulkNotice("Saved as audio", 2)]).toEqual([
      `${iso(1)} ریکارڈنگ آڈیو کے طور پر محفوظ ہو گئی`, `${iso(2)} ریکارڈنگز آڈیو کے طور پر محفوظ ہو گئیں`,
    ]);
    expect(deletedNotice(3)).toBe(`${iso(3)} ریکارڈنگز حذف ہو گئیں · واپس کریں`);
    expect(discardAllQuestion(1).title).toBe("یہ ریکارڈنگ ضائع کریں؟");
    expect(discardAllQuestion(4).title).toBe(`تمام ${iso(4)} ریکارڈنگز ضائع کریں؟`);
    expect(heldLine("sara@example.com", 1)).toMatch(new RegExp(`^${iso("sara@example.com")} سے سائن اِن ہوتے ہوئے بنی ${iso(1)} ریکارڈنگ`));
    expect(skipShorterThan().map(([, label]) => label)).toEqual(["کبھی نہیں", "5 s", `${iso("10 s")} (ڈیفالٹ)`, "20 s", "30 s"]);
    const items = [makePendingItem("a", { startedAt: new Date(2026, 10, 15, 10).toISOString(), durationMs: 60_000 }),
      makePendingItem("b", { startedAt: new Date(2026, 10, 16, 10).toISOString(), durationMs: 60_000 })];
    expect(waitingLine(items)).toBe(`${iso(2)} کالز انتظار میں`);
    const oldest = whenParts(new Date(2026, 10, 15, 10).toISOString())!.day;
    expect(waitingSummary(items)).toBe(`${iso(2)} کالز انتظار میں · سب سے پرانی ${iso(oldest)} · مجموعی ${iso("2:00")}`);
    // A skipped call's line: the caller isolated, the date formatWhen's own run.
    const skipped = makeSkippedItem("s", { callerName: "Omar", title: null, reason: "too_short" });
    expect(skippedMeta(skipped, NOW).startsWith(`${iso("Omar")} کے ساتھ کال · ${formatWhen(skipped.startedAt, NOW)}`)).toBe(true);
    expect(skippedMeta(skipped, NOW).endsWith(" · بہت مختصر")).toBe(true);
  });

  it("asked in English (a classic caller's \"en\"), the same words in an Urdu window are exactly the English", () => {
    expect(languageLabel("ur", "en")).toBe("Urdu");
    expect(languageChip(null, "en")).toBe("Auto");
    expect(partsText({ done: 0, total: 4 }, "en")).toBe("part 1 of 4");
    expect(transcribingLine({ done: 1, total: 3, eta_seconds: 40 }, "en")).toBe("Transcribing… part 2 of 3 · about 40 s left");
    expect(failureLine("interrupted", "en")).toBe("Transcription was interrupted.");
    expect(retryLine({ retry_at: new Date(2026, 10, 17, 15, 30).toISOString() }, NOW, "en")).toBe("Waiting to transcribe — retrying at 3:30 pm");
    expect(retryLine({ retry_at: new Date(2026, 10, 18, 9, 5).toISOString() }, NOW, "en")).toBe("Waiting to transcribe — retrying tomorrow at 9:05 am");
    expect(retryLine({ retry_at: new Date(2026, 10, 22, 9, 5).toISOString() }, NOW, "en")).toBe("Waiting to transcribe — retrying Nov 22 at 9:05 am");
    expect(skippedLine(3, "en")).toBe("Skipped 3 short calls");
    expect(heldLine("sara@example.com", 2, "en")).toBe("2 recordings made while signed in as sara@example.com wait for that account. Sign out and sign in as sara@example.com to upload them, or discard them.");
    expect(sourceAppLabel("phone_call", "en")).toBe("Phone call");
    expect(playerHint("en")).toBe("Space plays · ← / → 5 s · Shift+← / Shift+→ 10 s");
    for (const line of [languageLabel("ar", "en"), transcribingLine(null, "en"), uploadLine(null, "en"), deletedNotice(2, "en"), waitingLine([makePendingItem("a")], "en")]) {
      expect(line).not.toMatch(URDU_OR_ISOLATE);
    }
  });
});
