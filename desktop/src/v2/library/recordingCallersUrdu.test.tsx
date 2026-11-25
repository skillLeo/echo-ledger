// Desktop Urdu, D4: the lib words the recording page shows have classic callers too. With Urdu on, the windows still in
// English (the classic Library's waiting list and uploads, the import sheet, the recording's steps and converting bar,
// the Export menu, Activity's In progress, the transcription language list, the pill's chip, the Calls settings' app
// names) and the new design's lines that are still English (Home's recording line, the shell's status, v2 Activity's
// waiting and skipped lines) ask for them in English: exactly the English they had, with no Urdu piece and no isolate.
// English is their own tests', unchanged.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { answerConfirm } from "../../test/confirm";
import { answerConfirmForTests } from "../../lib/confirm";
import { makeItem, makePendingItem, makeSkippedItem, makeStatus, makeUploadItem } from "../../test/fixtures";
import { ActivityV2 } from "../activity/ActivityV2";
import { micUserName } from "../../lib/explainer";
import { LibraryView } from "../../views/main/LibraryView";
import { ImportSheet } from "../../views/main/ImportSheet";
import { ConvertFailed, ConvertingBar, RecordingSteps } from "../../views/main/RecordingProgress";
import { ExportMenu } from "../../views/main/TranscriptTools";
import { inProgressRows } from "../../views/main/ActivityCentre";
import { LANGUAGES } from "../../views/main/AppSettings";
import { NEEDS_KEY_TEXT, needsKeyText } from "../../views/main/Upgrade";
import { recordingLine } from "../home/RecordSection";
import { DISCARD_QUESTION, NO_AUDIO_HINT, noAudioHint } from "../../lib/pending";
import { PLAYER_HINT, playerHint } from "../../lib/player";
import { NEEDS_TRANSCRIPTION_KEY_TEXT, needsTranscriptionKeyText } from "./organise";
import { NOTIFY_ASKED, notifyAsked } from "./recordingExtras";
import { SECOND_HEARD, UNSURE_LINE, UNSURE_WORD } from "./RecordingPage";
import { MAIN_LANGUAGES, MORE_LANGUAGES, menuLabel } from "../../lib/languages";
import { resetLanguageForTests, setLanguage } from "../../i18n";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

/** Any Arabic-script letter, or an isolate mark: what an English line must not have. */
const URDU_OR_ISOLATE = /[\u0600-\u06FF\u2066-\u2069]/;
const NOW = new Date(2026, 9, 24, 12, 0, 0);

beforeEach(async () => {
  resetTauriMock();
  resetLanguageForTests();
  vi.useFakeTimers({ toFake: ["Date"], now: NOW });
  Object.defineProperty(Element.prototype, "scrollIntoView", { configurable: true, value: vi.fn() });
  await setLanguage("ur", { root: false });
});
afterEach(async () => {
  await flushAsync();
  answerConfirmForTests(null);
  vi.useRealTimers();
  resetLanguageForTests();
});

describe("classic windows in an Urdu window keep their English", () => {
  it("the Library's waiting list and uploads: the counts, the titles, the languages, the questions, the notices", async () => {
    respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("transcript_detail", { id: "t", kind: "final", provider: "p", status: "completed", language: null, text: null, segments: [] });
    respond("session_detail", {});
    respond("pending_list", [makePendingItem("p1"), makePendingItem("p2", { sourceApp: "other" })]);
    const held = { heldFor: "ayesha@example.test", state: "queued" as const };
    respond("upload_list", [makeUploadItem("u1", { ...held, sourceApp: "voice_note" }), makeUploadItem("u2", { ...held, sourceApp: "zoom" })]);
    respond("list_clients", { data: [] });
    respond("upload_discard", undefined);
    const notify = vi.fn();
    render(<LibraryView notify={notify} focus={null} />);
    await act(flushAsync);
    const line = screen.getByTestId("waiting-line");
    expect(line).toHaveTextContent("2 calls waiting");
    expect(line.getAttribute("title")).toMatch(/^2 calls waiting · oldest Oct 24 · /);
    fireEvent.click(screen.getByRole("button", { name: /^(Review|Hide)$/ }));
    await act(flushAsync);
    const other = screen.getByTestId("pending-p2");
    expect(other).toHaveTextContent("Other call");
    expect(other.textContent).toMatch(/^[^\u0600-\u06FF\u2066\u2067\u2069]*Other · /);
    const options = [...other.querySelectorAll("option")].map((o) => o.textContent);
    expect(options).toEqual(expect.arrayContaining(["Auto (English / Urdu / Arabic)", "Urdu (with English words)", "French"]));
    expect(screen.getByTestId("upload-u1")).toHaveTextContent("Voice note");
    expect(screen.getByTestId("held-uploads")).toHaveTextContent(
      "2 recordings made while signed in as ayesha@example.test wait for that account. Sign out and sign in as ayesha@example.test to upload them, or discard them.",
    );
    const asked = answerConfirm();
    asked.mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Discard all 2" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Discard all 2 recordings? It can't be undone.");
    expect(notify.mock.calls.at(-1)?.slice(0, 2)).toEqual(["Discarded 2 recordings", false]);
  });

  it("the import sheet's languages, the recording's steps and converting bar, the Export menu's hint", async () => {
    respond("list_clients", { data: [] });
    render(<ImportSheet paths={["C:\\Users\\me\\a.opus"]} notify={vi.fn()} onClose={vi.fn()} />);
    await act(flushAsync);
    const options = [...document.querySelectorAll("option")].map((o) => o.textContent ?? "");
    expect(options).toEqual(expect.arrayContaining(["English only", "Arabic (with English words)", "Russian"]));
    expect(options.join(" ")).not.toMatch(URDU_OR_ISOLATE);
  });

  it("the recording's steps and converting bar, and the Export menu's hint", () => {
    render(<>
      <RecordingSteps item={{ id: "a", status: "uploading", transcript: null }} improving={false} />
      <ConvertingBar view="roman" percent={60} />
      <ConvertFailed view="english" />
      <ExportMenu onExport={vi.fn()} />
    </>);
    expect(screen.getByTestId("step-upload")).toHaveTextContent("Uploading…");
    expect(screen.getByTestId("improving-bar")).toHaveTextContent("Converting to Roman Urdu… 60%");
    expect(screen.getByTestId("convert-failed")).toHaveTextContent("Couldn't convert to English");
    expect(screen.getByRole("combobox")).toHaveAttribute("title", "PDF (print) opens a print page in your browser: choose Save as PDF there.");
    expect(document.body.textContent).not.toMatch(URDU_OR_ISOLATE);
  });

  it("v2 Activity (not in the account's language yet): the waiting summary and the skipped calls' lines in English", async () => {
    respond("pending_list", [makePendingItem("p1"), makePendingItem("p2", { startedAt: "2026-10-23T09:00:00Z" })]);
    respond("skipped_list", [makeSkippedItem("s1"), makeSkippedItem("s2", { callerName: "Omar Khan" })]);
    respond("upload_list", []);
    respond("library_list", []);
    respond("recovered_sessions", []);
    respond("activity_list", { data: [], unread_count: 0 });
    render(<div className="el-root"><ActivityV2 status={makeStatus({ pendingDecisions: 2 })} onOpenRecording={vi.fn()} onReview={vi.fn()} onFailed={vi.fn()} onItems={vi.fn()} say={vi.fn()} /></div>);
    await act(flushAsync);
    expect(screen.getByText(/^2 calls waiting · oldest Oct 23 · /)).toBeInTheDocument();
    expect(screen.getByText("Skipped 2 short calls")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Show" }));
    // The caller, the length and the reason in English; the date is lib/format's, in the window's language (its own run).
    expect(screen.getByText(/^Call with Omar Khan · /).textContent).toMatch(/ · 0:06 · Too short$/);
  });

  it("the Calls settings' app names (classic) in English; the new design's in the window's language", () => {
    expect(micUserName({ app: "phone_call", site: null, name: "x.exe" }, "en")).toBe("Phone call");
    expect(micUserName({ app: "other", site: null, name: "x.exe" }, "en")).toBe("Other");
    expect(micUserName({ app: "phone_call", site: null, name: "x.exe" })).toBe("فون کال");
    expect(micUserName({ app: "zoom", site: null, name: "zoom.exe" })).toBe("Zoom");
  });

  it("Activity's In progress, the transcription languages and Home's recording line", () => {
    const rows = inProgressRows(
      [makeUploadItem("u", { state: "uploading", progress: 42, sourceApp: "phone_call" })],
      [makeItem("b", { title: "Board", status: "processing", transcript: { final_status: "processing", final_transcript_id: null, live_available: false, preview: null, final_progress: { done: 1, total: 3, eta_seconds: 40 } } })],
    );
    expect(rows.map((r) => [r.title, r.line])).toEqual([["Phone call", "Uploading… 42%"], ["Board", "Transcribing… part 2 of 3 · about 40 s left"]]);
    expect(LANGUAGES.map(([, label]) => label)).toEqual(expect.arrayContaining(["Auto (English / Urdu / Arabic)", "English only", "French"]));
    expect(recordingLine({ recording: { mode: "call", sourceApp: "other" } as never, paused: false })).toBe("Recording · Other");
    for (const text of [...rows.flat().map(String), ...LANGUAGES.map(([, label]) => label)]) expect(text).not.toMatch(URDU_OR_ISOLATE);
  });

  it("the English constants classic shows (and the tests read) stay English; the functions speak the window's language", () => {
    expect([NEEDS_KEY_TEXT, NO_AUDIO_HINT, PLAYER_HINT, NEEDS_TRANSCRIPTION_KEY_TEXT, NOTIFY_ASKED, UNSURE_WORD, UNSURE_LINE, SECOND_HEARD, DISCARD_QUESTION.title])
      .toEqual([
        "This recording wasn't transcribed. Add your Groq key in Settings › Transcription key.",
        "Check your microphone and the audio device, then try again.",
        "Space plays · ← / → 5 s · Shift+← / Shift+→ 10 s",
        "This plan transcribes with your own Groq key. Add it in Settings › Transcription key.",
        "You'll get a notification when it's ready.",
        "Not sure of this word. Click to hear it.",
        "The engine was not sure of this line. Click to hear it.",
        "A second transcription heard:",
        "Discard this recording?",
      ]);
    for (const words of [needsKeyText(), noAudioHint(), playerHint(), needsTranscriptionKeyText(), notifyAsked()]) expect(words).toMatch(/[\u0600-\u06FF]/);
  });
});

describe("the new design's callers read the same choices in the window's language", () => {
  it("the language choices' label, menu words and chip are read when asked (QueueV2, the import sheet)", () => {
    expect(MAIN_LANGUAGES.map((o) => [o.label, menuLabel(o), o.chip])).toEqual([
      ["خودکار", "خودکار (انگریزی / اردو / عربی)", "خودکار"], ["انگریزی", "صرف انگریزی", "EN"], ["اردو", "اردو (انگریزی الفاظ کے ساتھ)", "UR"], ["عربی", "عربی (انگریزی الفاظ کے ساتھ)", "AR"],
    ]);
    expect(MORE_LANGUAGES.map((o) => o.label)).toEqual(["فرانسیسی", "جرمن", "ہسپانوی", "اطالوی", "پرتگالی", "ڈچ", "ترکی", "روسی"]);
  });
});
