// Desktop Urdu, D-261: the Record page (HomeV2 and its Record section) and Live (LiveV2, Live Assist's sessions, Live
// Translate's panel) in the account's language. Every count goes through tc(), the app's and people's names, the keys and
// the file formats stay as they are; the status strip speaks the same language as its buttons. The Record page is
// named as the Record menu and the window's search name it (ریکارڈ کا صفحہ), Live as the rail does (لائیو). English is
// the pages' own tests', unchanged; a few checks here say so too. Classic's Record page keeps Live Assist's section in
// English.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { callsOf, emitEvent, flushAsync, listenerCount, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { answerConfirm } from "../../test/confirm";
import { answerConfirmForTests } from "../../lib/confirm";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import type { AppStatus, RecordingInfo } from "../../lib/ipc";
import { TRANSLATE_FEED, type TranslateFeed } from "../../lib/translate";
import { FSI, PDI, resetLanguageForTests, setLanguage, stripIsolates } from "../../i18n";
import { newDraft } from "../assist/PrepScreen";
import type { Prep } from "../assist/adapter";
import { HomeV2, checklist, needsAction } from "./HomeV2";
import { recordingLine, type Run } from "./RecordSection";
import { LiveV2, callsLine } from "../live/LiveV2";
import { LiveAssistClassic } from "../live/LiveAssistSection";
import { LiveTranslateV2 } from "../live/LiveTranslateV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const iso = (value: string | number) => `${FSI}${value}${PDI}`;
const urdu = () => act(() => setLanguage("ur", { root: false }));
/** Latin letters but the names kept as they are (people's, apps', keys, file formats): what an Urdu line must not have. */
const latinIn = (words: string, kept: readonly string[] = []) =>
  /[A-Za-z]/.test([...kept].sort((a, b) => b.length - a.length).reduce((rest, name) => rest.split(name).join(""), stripIsolates(words)));
/** Everything a person can read or hear on the page: its text, and every name and tooltip. */
const allWords = (root: HTMLElement = document.body) => [
  root.textContent ?? "",
  ...[...root.querySelectorAll("[aria-label], [title], [aria-valuetext]")].flatMap((el) =>
    ["aria-label", "title", "aria-valuetext"].map((a) => el.getAttribute(a) ?? "")),
];
const latinLines = (kept: readonly string[], root?: HTMLElement) => allWords(root).filter((w) => latinIn(w, kept));

const CALL: RecordingInfo = { clientRef: "r1", mode: "call", sourceApp: "zoom", clientId: null, startedAt: "", backend: "mock" };
const NOTE: RecordingInfo = { ...CALL, clientRef: "r2", mode: "mic_note", sourceApp: "voice_note" };
const ITEMS = [
  makeItem("bilal", { title: "Bilal Qureshi", source_app: "whatsapp", started_at: "2026-11-17T06:42:00Z",
    transcript: { final_status: "completed", final_transcript_id: "t1", live_available: false, preview: "Payment terms for the March shipment" } }),
  makeItem("ayesha", { title: "Ayesha Siddiqui", source_app: "zoom", started_at: "2026-11-17T04:18:00Z", status: "uploaded",
    transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } }),
];
/** The data on the Record page: the recordings' names and lines, the apps' names and the formats it takes. */
const HOME_KEPT = ["Bilal Qureshi", "Ayesha Siddiqui", "Payment terms for the March shipment", "WhatsApp", "Zoom", "EchoLedger",
  "OPUS", "OGG", "MP3", "M4A", "AAC", "WAV", "MP4", "WEBM"];

const run: Run = async (fn) => {
  try {
    return await fn();
  } catch {
    return undefined;
  }
};
const homeProps = () => ({
  onRecord: vi.fn(), onStop: vi.fn(), onSetup: vi.fn(), onReadyCheck: vi.fn(), onOpenRecording: vi.fn(), onLibrary: vi.fn(), onAction: vi.fn(), onPlan: vi.fn(),
  onImport: vi.fn(), run,
});
async function home(status: Partial<AppStatus> = {}, over: Record<string, unknown> = {}) {
  const view = render(<HomeV2 status={makeStatus({ platform: "macos", ...status })} {...homeProps()} {...over} />);
  await act(flushAsync);
  return view;
}

const PREP: Prep = {
  ...newDraft("existing_client"), id: "pr1", title: "March order", counterpart: "Bilal Qureshi", company: "Qureshi Textiles",
  project_id: "c1", status: "armed", scheduled_at: "2026-11-27T05:00:00Z", documents: [],
};
const liveProps = () => ({ onRecord: vi.fn(), onVoiceNote: vi.fn(), onCaptions: vi.fn(), onSettings: vi.fn(), onAutoRecord: vi.fn(), run: <T,>(fn: () => Promise<T>) => fn() });
const feed = (over: Partial<TranslateFeed> = {}): TranslateFeed =>
  ({ mode: "game", app: "Valorant", text: "", partial: "", original: "", status: "live", notice: null, locked: true, hidden: false, hide_keys: "", lock_keys: "", ...over });

beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
  answerConfirmForTests(null);
  resetEntitlementsForTests(makeEntitlements({ limits: { monthly_minutes: { limit: 300, used: 212 } } }));
  respond("ready_last", { ready: false, checkedAt: "2026-11-17T10:00:00Z", checks: [{ id: "other_side", label: "The other side of calls", ok: false, detail: null, fix: null }] });
  respond("permissions_status", { microphone: "allowed", systemAudio: "unknown", screen: "allowed", browserAutomation: true, platform: "macos", missing: [] });
  respond("library_list", ITEMS);
  respond("skipped_list", [{ id: "s1", sourceApp: "whatsapp", title: null, callerName: "Usman", startedAt: "2026-11-17T09:00:00Z", durationMs: 4000, reason: "short", expiresAt: "2026-11-18T09:00:00Z" }]);
  respond("input_devices", [{ id: "mac", name: "MacBook Microphone", isDefault: true, bluetooth: false }]);
  respond("get_settings", makeSettings());
  respond("assist_preps_list", [PREP]);
  respond("translate_apps", [{ pid: 4242, name: "Valorant" }, { pid: 77, name: "Chrome" }]);
  respond("translate_status", null);
  respond("translate_start", []);
  respond("live_translate_status", { available: true, reason: null });
  for (const c of ["pause_recording", "resume_recording", "cancel_recording"]) respond(c, makeStatus());
});
afterEach(() => {
  resetLanguageForTests();
  answerConfirmForTests(null);
});

describe("the Record page in Urdu", () => {
  it("its heading, the strip and its buttons, Get started, what needs action, the recent recordings and the month; nothing in English but the data", async () => {
    await urdu();
    await home({ callsReady: false, failedUploads: 1 }, { notifications: 2 });
    expect(screen.getByRole("heading", { level: 1, name: "ریکارڈ کا صفحہ" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "اپ لوڈ کریں" })).toBeInTheDocument();
    const strip = screen.getByRole("region", { name: "حالت" });
    expect(strip).toHaveTextContent("سیٹ اپ باقی ہے");
    expect(within(strip).getByRole("button", { name: "سیٹ اپ ٹھیک کریں" })).toBeInTheDocument();

    const steps = within(screen.getByRole("list", { name: "شروع کریں" })).getAllByRole("listitem");
    expect(steps.map((s) => s.textContent)).toEqual([
      "1مائیکروفون اور سسٹم آڈیو کی اجازت دیںکرنے کے کامسیٹ اپ کریں", "2تیاری کی جانچ پاس کریںکرنے کے کامجانچیں", "ایک آزمائشی ریکارڈنگ کریںہو گیا",
    ]);

    const needs = screen.getByRole("list", { name: "توجہ چاہیے" });
    for (const name of ["1 ریکارڈنگ اپ لوڈ نہیں ہوئی", "1 ریکارڈنگ صرف آڈیو ہے", "1 مختصر کال پچھلے ایک دن میں چھوڑ دی گئی", "2 اطلاعات آپ کی منتظر ہیں",
      "ہٹا دیں: 1 ریکارڈنگ اپ لوڈ نہیں ہوئی"]) {
      expect(within(needs).getByRole("button", { name: (said) => stripIsolates(said) === name }), name).toBeInTheDocument();
    }
    expect(within(needs).getByRole("button", { name: `ہٹا دیں: ${iso(`${iso(1)} ریکارڈنگ اپ لوڈ نہیں ہوئی`)}` })).toHaveAttribute("title", "بدلنے تک چھپائیں");
    expect(within(needs).getByRole("button", { name: `${iso(1)} ریکارڈنگ صرف آڈیو ہے` })).toBeInTheDocument();
    expect(needs).toHaveTextContent("ناکام ٹرانسکرپٹ، ماہانہ حد، یا آپ کی اپ گریڈ کی درخواست کا جواب۔");

    expect(screen.getByRole("heading", { level: 2, name: "حالیہ ریکارڈنگز" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "لائبریری کھولیں" })).toBeInTheDocument();
    expect(within(screen.getByRole("list", { name: "حالیہ ریکارڈنگز" })).getByRole("button", { name: "Bilal Qureshi" })).toBeInTheDocument();
    const minutes = screen.getByRole("progressbar", { name: "استعمال شدہ منٹ" });
    expect(stripIsolates(minutes.getAttribute("aria-valuetext") ?? "")).toBe("اس مہینے 300 میں سے 212 منٹ استعمال ہوئے");
    expect(screen.getByRole("button", { name: "پلان اور استعمال" })).toBeInTheDocument();

    // The Record section: not recording, the meters, the voice notes, the files.
    const record = within(screen.getByRole("region", { name: "ریکارڈ" }));
    expect(record.getByRole("region", { name: "موجودہ ریکارڈنگ" })).toHaveTextContent("ریکارڈنگ نہیں ہو رہی");
    expect(record.getByRole("meter", { name: "میری آواز کی سطح" })).toBeInTheDocument();
    expect(record.getByRole("meter", { name: "کلائنٹ کی آواز کی سطح" })).toBeInTheDocument();
    expect(record.getByRole("switch", { name: "لائیو کیپشنز" })).toBeInTheDocument();
    expect(record.getByText("کیپشنز آپ کی دوسری ونڈوز کے اوپر رہتے ہیں۔ انہیں آن کرنے سے ریکارڈنگ شروع ہو جاتی ہے۔")).toBeInTheDocument();
    expect(record.getByRole("button", { name: "آخری 2 منٹ کاپی کریں" })).toBeInTheDocument();
    expect(record.getByRole("button", { name: "وائس نوٹ ریکارڈ کریں" })).toBeInTheDocument();
    expect(record.getByRole("button", { name: "ابھی چلتی آڈیو کیپچر کریں" })).toBeInTheDocument();
    expect(record.getByRole("button", { name: "فائلیں چنیں…" })).toBeInTheDocument();

    expect(latinLines(HOME_KEPT)).toEqual([]);
  });

  it("recording: the strip's time, the section's line with the app's name, Pause, Bookmark and Cancel (which asks in Urdu)", async () => {
    await urdu();
    const view = await home({ recording: CALL, elapsedMs: 134_000, uploadErrors: ["a", "b", "c", "d", "e"] });
    const strip = screen.getByRole("region", { name: "حالت" });
    expect(strip).toHaveTextContent(`ریکارڈنگ ${iso("02:14")}`);
    expect(within(strip).getByRole("button", { name: "روکیں اور محفوظ کریں" })).toBeInTheDocument();
    const now = within(screen.getByRole("region", { name: "موجودہ ریکارڈنگ" }));
    expect(now.getByRole("status")).toHaveTextContent(`ریکارڈنگ · ${iso("Zoom")}`);
    const controls = within(now.getByRole("group", { name: "ریکارڈنگ کنٹرولز" }));
    expect(controls.getByRole("button", { name: "ریکارڈنگ روکیں" })).toBeInTheDocument();
    expect(controls.getByRole("button", { name: "یہ لمحہ بُک مارک کریں" })).toHaveAttribute("title", "ریکارڈنگ میں یہ لمحہ نشان زد کرتا ہے");
    expect(screen.getByText("کیپشنز آپ کی دوسری ونڈوز کے اوپر رہتے ہیں۔")).toBeInTheDocument();
    const errors = screen.getByRole("region", { name: "دوبارہ کوشش کے منتظر اپ لوڈز" });
    expect(stripIsolates(errors.textContent ?? "")).toContain("اور 2 مزید");

    const asked = answerConfirm();
    asked.mockReturnValue(false);
    fireEvent.click(controls.getByRole("button", { name: "ریکارڈنگ منسوخ کریں" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("یہ ریکارڈنگ منسوخ کریں؟ اب تک جو ریکارڈ ہوا وہ حذف ہو جائے گا۔ یہ واپس نہیں ہو سکتا۔");
    expect(callsOf("cancel_recording")).toHaveLength(0);

    view.rerender(<HomeV2 status={makeStatus({ platform: "macos", recording: NOTE, paused: true })} {...homeProps()} />);
    expect(screen.getByRole("region", { name: "موجودہ ریکارڈنگ" })).toHaveTextContent(`رکی ہوئی · ${iso("وائس نوٹ")}`);
    expect(screen.getByRole("button", { name: "ریکارڈنگ جاری رکھیں" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "وائس نوٹ روکیں اور محفوظ کریں" })).toBeInTheDocument();
    expect(latinLines(HOME_KEPT)).toEqual([]);
  });

  it("a viewer: recording is off, said in Urdu with the workspace's own name", async () => {
    await urdu();
    await home({ plan: { ...makeStatus().plan, role: "viewer", workspace: "Qureshi Textiles" } });
    const viewer = screen.getByTestId("home-viewer");
    expect(viewer).toHaveTextContent("ناظرین کے لیے ریکارڈنگ بند ہے");
    expect(stripIsolates(viewer.textContent ?? "")).toContain(
      "آپ Qureshi Textiles میں ناظر ہیں: آپ کے ساتھ شیئر کی گئی چیزیں کھول سکتے ہیں، ریکارڈ نہیں کر سکتے۔ ورک اسپیس کا مالک یا ایڈمن کنسول میں آپ کا کردار بدل سکتا ہے۔");
    expect(latinLines([...HOME_KEPT, "Qureshi Textiles"], viewer)).toEqual([]);
  });

  it("the words its helpers give: the checklist, what needs action and the recording line, in the window's language", async () => {
    expect(checklist({ permissionsOk: false, ready: false, recordings: 0 })!.map((i) => i.action)).toEqual(["Set up", "Check", "Record"]);
    expect(needsAction({ failed: 2, waiting: 1, untranscribed: 0, missed: 0 }).map((a) => a.title)).toEqual(["2 recordings didn't upload", "1 call waits for your decision"]);
    expect(recordingLine({ recording: { ...CALL, sourceApp: "other" }, paused: false })).toBe("Recording · Other");
    await urdu();
    expect(checklist({ permissionsOk: false, ready: false, recordings: 0 })!.map((i) => i.action)).toEqual(["سیٹ اپ کریں", "جانچیں", "ریکارڈ کریں"]);
    expect(needsAction({ failed: 2, waiting: 1, untranscribed: 0, missed: 0 }).map((a) => stripIsolates(a.title)))
      .toEqual(["2 ریکارڈنگز اپ لوڈ نہیں ہوئیں", "1 کال آپ کے فیصلے کی منتظر ہے"]);
    expect(stripIsolates(recordingLine({ recording: { ...CALL, sourceApp: "other" }, paused: false }))).toBe("ریکارڈنگ · دیگر");
    expect(recordingLine({ recording: null, paused: false })).toBe("ریکارڈنگ نہیں ہو رہی");
  });

  it("English as before: Home, Status, Fix setup, Get started, Needs action, Recent recordings, Minutes used", async () => {
    await home({ callsReady: false, failedUploads: 1 });
    expect(screen.getByRole("heading", { level: 1, name: "Home" })).toBeInTheDocument();
    const strip = screen.getByRole("region", { name: "Status" });
    expect(strip).toHaveTextContent("Needs setup");
    expect(within(strip).getByRole("button", { name: "Fix setup" })).toBeInTheDocument();
    expect(screen.getByRole("list", { name: "Get started" })).toBeInTheDocument();
    expect(within(screen.getByRole("list", { name: "Needs action" })).getByRole("button", { name: "Dismiss: 1 recording didn't upload" }))
      .toHaveAttribute("title", "Hide until it changes");
    expect(screen.getByRole("progressbar", { name: "Minutes used" })).toHaveAttribute("aria-valuetext", "212 of 300 minutes used this month");
    expect(screen.getByRole("region", { name: "Recording now" })).toHaveTextContent("Not recording");
    expect(screen.getByRole("meter", { name: "Me level" })).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/[؀-ۿ⁦-⁩]/);
  });
});

describe("Live in Urdu", () => {
  it("its heading, Record with the strip, Auto-record calls and how calls stand, Live Assist's sessions, During a call; nothing in English but the data", async () => {
    await urdu();
    const p = liveProps();
    render(<LiveV2 status={makeStatus({ platform: "macos", autoMode: true, callsReady: true, plan: { ...makeStatus().plan, liveTranslate: true } })} assistOn projects={[]} {...p} />);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "لائیو" })).toBeInTheDocument();
    const record = screen.getByRole("region", { name: "ریکارڈ" });
    expect(record).toHaveTextContent("ریکارڈ کے لیے تیار");
    fireEvent.click(within(record).getByRole("button", { name: "ریکارڈ کریں" }));
    expect(p.onRecord).toHaveBeenCalled();
    expect(within(record).getByRole("button", { name: "وائس نوٹ" })).toBeInTheDocument();
    const recording = within(screen.getByRole("list", { name: "ریکارڈنگ" }));
    expect(recording.getByRole("switch", { name: "کالز خودکار ریکارڈ کریں" })).toBeChecked();
    expect(recording.getByText("کالز: خودکار ریکارڈنگ آن")).toBeInTheDocument();
    expect(recording.getByRole("button", { name: "کالز اور میٹنگز" })).toBeInTheDocument();

    const assist = screen.getByRole("region", { name: "لائیو اسسٹ" });
    expect(within(assist).getByRole("button", { name: "نیا سیشن" })).toBeInTheDocument();
    const session = within(within(assist).getByRole("list", { name: "آنے والے سیشنز" })).getByRole("button", { name: /^March order/ });
    const meta = assist.querySelector(".el-row__meta-text") as HTMLElement;
    expect(stripIsolates(meta.textContent ?? "")).toMatch(/^موجودہ کلائنٹ · Bilal Qureshi · /);
    expect(meta).toHaveAttribute("dir", "rtl");
    expect(session).toBeInTheDocument();
    expect(within(assist).getByText("تیار")).toBeInTheDocument();

    const during = within(screen.getByRole("list", { name: "کال کے دوران" }));
    expect(during.getByRole("button", { name: "لائیو ترجمہ" })).toBeInTheDocument();
    expect(during.getByText("گیم یا ویڈیو کی آواز کا ساتھ ساتھ ترجمہ")).toBeInTheDocument();
    expect(during.getByRole("button", { name: "ڈکٹیشن" })).toBeInTheDocument();
    expect(during.getByText("بولیں، اور الفاظ وہیں لکھے جاتے ہیں جہاں آپ ہیں")).toBeInTheDocument();
    expect(during.getByText("ریکارڈنگ کے دوران لائیو الفاظ")).toBeInTheDocument();

    // The shortcuts are keys, Latin in every language.
    expect(latinLines(["March order", "Bilal Qureshi", "⌘⇧D", "⌘⇧Space"])).toEqual([]);
  });

  it("while recording: Stop and save, and captions turn on or off in place", async () => {
    await urdu();
    render(<LiveV2 status={makeStatus({ recording: CALL, elapsedMs: 5_000, captionsLive: true, callsReady: false })} assistOn={false} projects={[]} {...liveProps()} />);
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "روکیں اور محفوظ کریں" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "آف کریں" })).toBeInTheDocument();
    expect(screen.getByText("اس ریکارڈنگ کے لیے آن")).toBeInTheDocument();
    expect(screen.getByText("کالز: پہلے سیٹ اپ کریں")).toBeInTheDocument();
    expect(latinLines(["⌘⇧D", "⌘⇧Space", "Ctrl + Shift + D", "Ctrl + Shift + Space"])).toEqual([]);
  });

  it("Live Translate's panel: the form, then running and ended, in Urdu; the apps' names and the keys as they are", async () => {
    await urdu();
    render(<LiveTranslateV2 run={run} />);
    await waitFor(() => expect(listenerCount(TRANSLATE_FEED)).toBeGreaterThan(0));
    await act(flushAsync);
    const panel = screen.getByRole("region", { name: "لائیو ترجمہ" });
    expect(within(panel).getByRole("combobox", { name: /^ترجمے کے لیے ایپ/ })).toHaveTextContent("آواز چلانے والی کوئی ایپ چنیں");
    expect(within(panel).getByRole("combobox", { name: /^موڈ/ })).toHaveTextContent("گیم (اوپر ایک باکس)");
    expect(within(panel).getByRole("combobox", { name: /^ترجمہ کس زبان میں/ })).toHaveTextContent("انگریزی");
    expect(within(panel).getByRole("combobox", { name: /^رفتار/ })).toHaveTextContent("تیز ترین");
    expect(within(panel).getByRole("button", { name: "ریفریش کریں" })).toBeInTheDocument();
    expect(within(panel).getByRole("button", { name: "شروع کریں" })).toBeDisabled();
    expect(panel).toHaveTextContent("تقریباً $0.18 فی گھنٹہ۔");
    expect(latinLines([], panel)).toEqual([]);

    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "live" })));
    expect(stripIsolates(within(panel).getByRole("status").textContent ?? "")).toBe("Valorant · ترجمہ ہو رہا ہے");
    expect(within(panel).getByRole("button", { name: "لائیو ترجمہ کی جگہ بدلیں" })).toBeInTheDocument();
    expect(within(panel).getByRole("button", { name: "روکیں" })).toBeInTheDocument();
    expect(stripIsolates(panel.textContent ?? "")).toContain("Ctrl+Shift+T سے لائیو ترجمہ کی جگہ بدلیں (اسے گھسیٹیں، پھر “ہو گیا” دبائیں)؛ Ctrl+Shift+H اسے چھپاتا ہے۔");
    expect(latinLines(["Valorant", "Ctrl+Shift+T", "Ctrl+Shift+H"], panel)).toEqual([]);

    await act(async () => emitEvent(TRANSLATE_FEED, feed({ status: "ended" })));
    expect(stripIsolates(panel.querySelector(".el-status")?.textContent ?? "")).toBe("Valorant · ختم ہو گیا");
    const ended = screen.getByTestId("translate-ended");
    expect(stripIsolates(ended.textContent ?? "")).toBe("لائیو ترجمہ ختم ہو گیا۔ Valorant بند ہو گئی یا اس نے آواز چلانا بند کر دی۔");
    expect(ended).toHaveAttribute("dir", "rtl");
    expect(within(panel).getByRole("button", { name: "بند کریں" })).toBeInTheDocument();
  });

  it("Live Translate held back by the system or the plan, in Urdu (macOS as it is)", async () => {
    await urdu();
    const view = render(<LiveTranslateV2 run={run} unavailable="platform" />);
    expect(screen.getByRole("status")).toHaveTextContent("لائیو ترجمہ کے لیے macOS 14.2 یا بعد کا ورژن چاہیے");
    expect(screen.getByRole("button", { name: "شروع کریں" })).toBeDisabled();
    expect(latinLines(["macOS"])).toEqual([]);
    view.unmount();
    render(<LiveTranslateV2 run={run} unavailable="plan" />);
    expect(screen.getByRole("status")).toHaveTextContent("آپ کے پلان میں نہیں");
  });

  it("the words its helper gives: how calls stand, in the window's language", async () => {
    expect([callsLine({ autoMode: true, callsReady: true }), callsLine({ autoMode: false, callsReady: true }), callsLine({ autoMode: true, callsReady: false })])
      .toEqual(["Calls: auto-record on", "Calls: ready", "Calls: set up first"]);
    await urdu();
    expect([callsLine({ autoMode: true, callsReady: true }), callsLine({ autoMode: false, callsReady: true }), callsLine({ autoMode: true, callsReady: false })])
      .toEqual(["کالز: خودکار ریکارڈنگ آن", "کالز: تیار", "کالز: پہلے سیٹ اپ کریں"]);
  });

  it("English as before: Live, Record, Calls: ready, Live Assist, During a call", async () => {
    render(<LiveV2 status={makeStatus({ autoMode: false, callsReady: true })} assistOn projects={[]} {...liveProps()} />);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "Live" })).toBeInTheDocument();
    expect(within(screen.getByRole("region", { name: "Record" })).getByRole("button", { name: "Record" })).toBeInTheDocument();
    expect(screen.getByText("Calls: ready")).toBeInTheDocument();
    expect(within(screen.getByRole("region", { name: "Live Assist" })).getByRole("button", { name: "New session" })).toBeInTheDocument();
    expect(screen.getByRole("list", { name: "During a call" })).toBeInTheDocument();
    expect(screen.getByText("Translate what a game or video says, as it is said")).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/[؀-ۿ⁦-⁩]/);
  });
});

describe("classic's Record page in an Urdu window", () => {
  it("keeps Live Assist's section in English, beside its English page", async () => {
    await urdu();
    render(<LiveAssistClassic projects={[]} />);
    await act(flushAsync);
    const assist = screen.getByRole("region", { name: "Live Assist" });
    expect(within(assist).getByRole("heading", { level: 2, name: "Live Assist" })).toBeInTheDocument();
    expect(within(assist).getByRole("button", { name: "New session" })).toBeInTheDocument();
    expect(within(assist).getByRole("list", { name: "Upcoming sessions" })).toBeInTheDocument();
    expect(within(assist).getByText("Ready")).toBeInTheDocument();
    expect(stripIsolates(assist.querySelector(".el-row__meta-text")?.textContent ?? "")).toMatch(/^Existing client · Bilal Qureshi · /);
  });
});
