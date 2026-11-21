// The v2 recording page's remaining parts against classic's recording view (docs/v2/audit/parity.csv DT-REC-033,
// DT-REC-034, DT-REC-037, DT-REC-044, DT-REC-049, DT-REC-058, DT-REC-071, DT-REC-088, DT-REC-104, DT-REC-105): the
// steps and Notify me when ready, the backup engine's Re-transcribe, Current / Previous, Filed by, the Groq key, the
// converting bar, speaker turns, and the offers after an edit. Each asserts the command sent and what the page shows.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem, TranscriptDetail, TranscriptViewsState } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { NOTIFY_ASKED } from "./recordingExtras";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 17, 15, 0);
const call = (extra: Partial<LibraryItem> = {}, transcript: Partial<NonNullable<LibraryItem["transcript"]>> = {}): LibraryItem => makeItem("bilal", {
  title: "Bilal Qureshi", started_at: new Date(2026, 10, 17, 11, 42).toISOString(), duration_ms: 3_730_000, language: "ur", screen_count: 0,
  transcript: { final_status: "completed", final_transcript_id: "tr-bilal", live_available: false, preview: "Hello", ...transcript },
  ...extra,
});
const ACME = { id: "p1", name: "Acme" } as LibraryItem["client"];

async function open(item: LibraryItem, transcript: TranscriptDetail | null = makeTranscript({ id: "tr-bilal" })) {
  respond("library_list", [item]);
  respond("library_refresh", [item]);
  respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", speaker_names: { Client: "Bilal" } });
  // null: the test answers transcript_detail itself.
  if (transcript) respond("transcript_detail", transcript);
  render(<LibraryV2 />);
  await act(flushAsync);
  fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi" }));
  await act(flushAsync);
}
const toast = () => screen.getByRole("status", { name: "Notifications" });
const lines = () => [...screen.getByRole("list", { name: "Transcript" }).querySelectorAll(".el-segment__text")].map((p) => p.textContent);

beforeEach(() => {
  resetTauriMock();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(NOW);
  Object.assign(HTMLMediaElement.prototype, { play: vi.fn(() => Promise.resolve()), pause: vi.fn() });
});

describe("the recording page's progress", () => {
  it("DT-REC-033: Upload · Transcribe · Improve while transcribing, the running one marked; none once it is ready", async () => {
    await open(call({ status: "processing" }, { final_status: "processing", final_progress: { done: 1, total: 3, eta_seconds: 40 } }),
      makeTranscript({ id: "tr-bilal", status: "processing", segments: [] }));
    const steps = within(screen.getByRole("list", { name: "Progress" }));
    expect(steps.getAllByRole("listitem").map((li) => li.textContent)).toEqual(["Uploaded (done)", "Transcribing", "Improve"]);
    expect(steps.getByText("Transcribing").closest("li")).toHaveAttribute("aria-current", "step");
    expect(steps.getByText("Uploaded").closest("li")).toHaveClass("el-rec-step--done");
  });

  it("DT-REC-033: the steps have their own classes, never the Home checklist's", () => {
    const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");
    // .el-steps / .el-step belong to the get-started checklist and Setup: one rule each, none from the recording page.
    expect(css.match(/^\.el-steps \{/gm)).toHaveLength(1);
    expect(css.match(/^\.el-step \{/gm)).toHaveLength(1);
    expect(css).toMatch(/^\.el-rec-steps \{/m);
  });

  it("DT-REC-033: no steps on a recording that is ready", async () => {
    await open(call());
    expect(screen.queryByRole("list", { name: "Progress" })).toBeNull();
  });

  it("DT-REC-034: Notify me when ready asks the core for this recording, then says it will; a refusal is said", async () => {
    respond("notify_when_ready", undefined);
    await open(call({ status: "processing" }, { final_status: "processing" }), makeTranscript({ id: "tr-bilal", status: "processing", segments: [] }));
    fireEvent.click(screen.getByRole("button", { name: "Notify me when ready" }));
    await act(flushAsync);
    expect(callsOf("notify_when_ready")).toEqual([{ sessionId: "bilal" }]);
    expect(screen.getByText(NOTIFY_ASKED)).toHaveAttribute("role", "status");
    expect(screen.queryByRole("button", { name: "Notify me when ready" })).toBeNull();
  });

  it("DT-REC-034: a Notify that fails says why and keeps the button", async () => {
    fail("notify_when_ready", "Notifications are off for EchoLedger.");
    await open(call({ status: "processing" }, { final_status: "processing" }), makeTranscript({ id: "tr-bilal", status: "processing", segments: [] }));
    fireEvent.click(screen.getByRole("button", { name: "Notify me when ready" }));
    await act(flushAsync);
    expect(toast()).toHaveTextContent("Notifications are off for EchoLedger.");
    expect(screen.getByRole("button", { name: "Notify me when ready" })).toBeInTheDocument();
  });

  it("DT-REC-071: the Roman Urdu view being written shows Improving in the steps and a bar with its percent", async () => {
    const making: TranscriptViewsState = {
      status: "making", available: ["roman", "urdu", "english"], default: "roman", progress: { roman: { done: 6, total: 10 } }, model: "m", made_at: null, error: null,
      roman: null, english: null, urdu: null,
    };
    respondWith("transcript_views", () => making);
    await open(call({}, { views: { status: "making", default: "roman", progress: {} } }),
      makeTranscript({ id: "tr-bilal", language: "ur", views: { available: ["roman", "urdu", "english"], default: "roman", status: "making", progress: { roman: { done: 6, total: 10 } } } }));
    await act(flushAsync);
    expect(screen.getByTestId("converting-line")).toHaveTextContent("Converting to Roman Urdu… 60%");
    const bar = screen.getByRole("progressbar", { name: "Converting to Roman Urdu…" });
    expect(bar).toHaveAttribute("aria-valuenow", "60");
    expect(within(screen.getByRole("list", { name: "Progress" })).getByText("Improving").closest("li")).toHaveAttribute("aria-current", "step");
    // The transcript itself is readable meanwhile.
    expect(lines()[0]).toBe("Hello, this is Sam.");
  });
});

describe("the recording page's engine and versions", () => {
  it("DT-REC-037: written by the backup engine: Re-transcribe tries again in the same language", async () => {
    respond("session_retranscribe", null);
    await open(call(), makeTranscript({ id: "tr-bilal", backup_engine: true }));
    const line = screen.getByTestId("backup-engine");
    expect(line).toHaveTextContent("Transcribed with the backup engine. Re-transcribe?");
    fireEvent.click(within(line).getByRole("button", { name: "Re-transcribe" }));
    await act(flushAsync);
    expect(callsOf("session_retranscribe")).toEqual([{ id: "bilal", language: "ur" }]);
    expect(toast()).toHaveTextContent("Re-transcribing in Urdu");
  });

  it("DT-REC-037: no backup line for the preferred engine, or while it is transcribed again", async () => {
    await open(call(), makeTranscript({ id: "tr-bilal", backup_engine: false }));
    expect(screen.queryByTestId("backup-engine")).toBeNull();
  });

  it("DT-REC-037: a backup engine's transcript being transcribed again has no backup line", async () => {
    await open(call({ status: "processing" }, { final_status: "processing" }), makeTranscript({ id: "tr-bilal", backup_engine: true }));
    expect(screen.queryByTestId("backup-engine")).toBeNull();
    expect(screen.getByRole("list", { name: "Progress" })).toBeInTheDocument();
  });

  it("DT-REC-044: after Re-transcribe as, Current / Previous (Urdu) switches the transcript shown", async () => {
    respondWith("transcript_detail", ({ id }) => (id === "tr-old"
      ? makeTranscript({ id: "tr-old", language: "ur", segments: [{ channel: 1, speaker: "Me", start_ms: 0, end_ms: 2000, text: "Purana transcript." }] })
      : makeTranscript({ id: "tr-bilal" })));
    await open(call({}, { alternate_status: "completed", alternate_transcript_id: "tr-old", alternate_language: "ur" }), null);
    const version = screen.getByRole("radiogroup", { name: "Version" });
    expect(within(version).getByRole("radio", { name: "Current" })).toBeChecked();
    expect(lines()[0]).toBe("Hello, this is Sam.");
    fireEvent.click(within(version).getByRole("radio", { name: "Previous (Urdu)" }));
    await act(flushAsync);
    expect(callsOf("transcript_detail").at(-1)).toEqual({ id: "tr-old" });
    expect(lines()).toEqual(["Purana transcript."]);
    fireEvent.click(within(screen.getByRole("radiogroup", { name: "Version" })).getByRole("radio", { name: "Current" }));
    await act(flushAsync);
    expect(callsOf("transcript_detail").at(-1)).toEqual({ id: "tr-bilal" });
    expect(lines()[0]).toBe("Hello, this is Sam.");
  });

  it("DT-REC-044: no Version switch without a kept previous transcript", async () => {
    await open(call({}, { alternate_status: "processing", alternate_transcript_id: "tr-old" }));
    expect(screen.queryByRole("radiogroup", { name: "Version" })).toBeNull();
  });

  it("DT-REC-058: failed for want of a Groq key: Add my Groq key opens Settings there; a refused key says Check my Groq key", async () => {
    const opened: string[] = [];
    const listen = (e: Event) => opened.push((e as CustomEvent<string>).detail);
    window.addEventListener("echoledger:open-settings-section", listen);
    try {
      await open(call({ status: "failed" }, { final_status: "failed", final_error_code: "needs_transcription_key" }),
        makeTranscript({ id: "tr-bilal", status: "failed", error_code: "needs_transcription_key", segments: [] }));
      expect(screen.getByText("This recording wasn't transcribed. Add your Groq key in Settings › Transcription key.")).toBeInTheDocument();
      fireEvent.click(screen.getByRole("button", { name: "Add my Groq key" }));
      expect(opened).toEqual(["transcription-key"]);
      // Trying again can't fix it: no Try again beside it.
      expect(screen.queryByRole("button", { name: "Try again" })).toBeNull();
    } finally {
      window.removeEventListener("echoledger:open-settings-section", listen);
    }
  });

  it("DT-REC-058: a key Groq refused offers Check my Groq key", async () => {
    await open(call({ status: "failed" }, { final_status: "failed", final_error_code: "own_key_rejected" }),
      makeTranscript({ id: "tr-bilal", status: "failed", error_code: "own_key_rejected", segments: [] }));
    expect(screen.getByRole("button", { name: "Check my Groq key" })).toBeInTheDocument();
    expect(screen.getByText(/Groq did not accept your key/)).toBeInTheDocument();
  });
});

describe("the recording page's project lines and transcript", () => {
  it("DT-REC-049: Filed by rule names the project's rule that matched; Filed by contact says why", async () => {
    respond("client_rules", { data: [{ id: 7, kind: "title", value: "Bilal" }] });
    await open(call({ client: ACME, filed_by: "rule" }));
    expect(callsOf("client_rules")).toEqual([{ id: "p1" }]);
    expect(screen.getByTestId("filed-by")).toHaveTextContent("Filed by rule: title contains Bilal");
  });

  it("DT-REC-049: filed by contact, and nothing for a recording moved by hand", async () => {
    await open(call({ client: ACME, filed_by: "contact" }));
    expect(screen.getByTestId("filed-by")).toHaveTextContent("Filed by contact: the caller is one of Acme's contacts");
    expect(callsOf("client_rules")).toEqual([]);
  });

  it("DT-REC-049: a recording moved by hand has no Filed by line", async () => {
    await open(call({ client: ACME, filed_by: "user" }));
    expect(screen.queryByTestId("filed-by")).toBeNull();
  });

  it("DT-REC-088: each turn starts with the speaker's avatar and name; the lines after it keep the name for screen readers only", async () => {
    const segments = [
      { channel: 1, speaker: "Me", start_ms: 0, end_ms: 2_000, text: "Hello." },
      { channel: 1, speaker: "Me", start_ms: 2_000, end_ms: 4_000, text: "Can you hear me?" },
      { channel: 2, speaker: "Client", start_ms: 5_000, end_ms: 7_000, text: "Yes, clearly." },
      { channel: 1, speaker: "Me", start_ms: 8_000, end_ms: 9_000, text: "Good." },
    ];
    await open(call(), makeTranscript({ id: "tr-bilal", segments }));
    const items = within(screen.getByRole("list", { name: "Transcript" })).getAllByRole("listitem");
    expect(items.map((li) => li.classList.contains("el-segment--cont"))).toEqual([false, true, false, false]);
    expect(items.map((li) => li.querySelector(".el-avatar")?.textContent ?? null)).toEqual(["M", null, "B", "M"]);
    // The continuing line's name is there for screen readers, hidden on screen; its play button still says who.
    expect(within(items[1]).getByText("Me")).toHaveClass("el-visually-hidden");
    expect(within(items[1]).getByRole("button", { name: "Play from 0:02 · Me" })).toBeInTheDocument();
    expect(within(items[2]).getByText("Bilal")).not.toHaveClass("el-visually-hidden");
  });
});

describe("the offers after an edit", () => {
  const edit = async (from: string, to: string) => {
    fireEvent.click(screen.getByRole("button", { name: "Edit text" }));
    fireEvent.click(screen.getByRole("button", { name: `Edit line 1: ${from}` }));
    const box = screen.getByRole("textbox", { name: "Edit line" });
    fireEvent.change(box, { target: { value: to } });
    fireEvent.keyDown(box, { key: "Enter", metaKey: true });
    await act(flushAsync);
  };
  const edited = (text: string, learn?: unknown[]) => ({ ...makeTranscript({ id: "tr-bilal", edited_at: "2026-11-17T14:00:00Z" }), segments: [{ channel: 1, speaker: "Me", start_ms: 0, end_ms: 4_000, text }], learn });

  it("DT-REC-104: Always write X as Y for Acme? Yes adds the project's rule; offers come one at a time; No drops it", async () => {
    respondWith("transcript_edit", ({ text }) => edited(text as string, [
      { from: "Akmi", to: "Acme", client_id: "p1", client_name: "Acme" },
      { from: "invois", to: "invoice", client_id: "p1", client_name: "Acme" },
    ]));
    respond("vocabulary_replace_add", { replacements: [] });
    await open(call({ client: ACME }));
    await edit("Hello, this is Sam.", "Hello, this is Sam from Acme.");
    const offer = screen.getByRole("group", { name: "Always write it this way" });
    expect(offer).toHaveTextContent("Always write Akmi as Acme for Acme?");
    // The rule's offer takes the vocabulary's place.
    expect(screen.queryByRole("group", { name: "Add to the vocabulary" })).toBeNull();
    fireEvent.click(within(offer).getByRole("button", { name: "Yes" }));
    await act(flushAsync);
    expect(callsOf("vocabulary_replace_add")).toEqual([{ from: "Akmi", to: "Acme", clientId: "p1" }]);
    expect(toast()).toHaveTextContent("Acme's transcripts will write “Acme” from now on");
    const next = screen.getByRole("group", { name: "Always write it this way" });
    expect(next).toHaveTextContent("Always write invois as invoice for Acme?");
    fireEvent.click(within(next).getByRole("button", { name: "No" }));
    await act(flushAsync);
    expect(callsOf("vocabulary_replace_add")).toHaveLength(1);
    expect(screen.queryByRole("group", { name: "Always write it this way" })).toBeNull();
  });

  it("DT-REC-105: new names typed in an edit are offered for the project's vocabulary; a press adds that word", async () => {
    respondWith("transcript_edit", ({ text }) => edited(text as string));
    respond("vocabulary_add", { keyterms: ["Qureshi"] });
    await open(call({ client: ACME }));
    await edit("Hello, this is Sam.", "Hello, this is Sam Qureshi from Faisalabad.");
    const offer = screen.getByRole("group", { name: "Add to the vocabulary" });
    expect(offer).toHaveTextContent("Add to Acme's vocabulary so the engine gets it right next time:");
    expect(within(offer).getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent)).toEqual([
      "Add Qureshi to the vocabulary", "Add Faisalabad to the vocabulary", "Not now",
    ]);
    fireEvent.click(within(offer).getByRole("button", { name: "Add Qureshi to the vocabulary" }));
    await act(flushAsync);
    expect(callsOf("vocabulary_add")).toEqual([{ term: "Qureshi", clientId: "p1" }]);
    expect(toast()).toHaveTextContent("Added “Qureshi” to the vocabulary");
    expect(within(offer).queryByRole("button", { name: "Add Qureshi to the vocabulary" })).toBeNull();
    fireEvent.click(within(offer).getByRole("button", { name: "Not now" }));
    expect(screen.queryByRole("group", { name: "Add to the vocabulary" })).toBeNull();
    expect(callsOf("vocabulary_add")).toHaveLength(1);
  });

  it("DT-REC-105: a word that couldn't be added says why and stays offered", async () => {
    respondWith("transcript_edit", ({ text }) => edited(text as string));
    fail("vocabulary_add", "The vocabulary is full.");
    await open(call());
    await edit("Hello, this is Sam.", "Hello, this is Sam Qureshi.");
    const offer = screen.getByRole("group", { name: "Add to the vocabulary" });
    expect(offer).toHaveTextContent("Add to the vocabulary so the engine gets it right next time:");
    fireEvent.click(within(offer).getByRole("button", { name: "Add Qureshi to the vocabulary" }));
    await act(flushAsync);
    expect(callsOf("vocabulary_add")).toEqual([{ term: "Qureshi", clientId: null }]);
    expect(toast()).toHaveTextContent("The vocabulary is full.");
    expect(within(offer).getByRole("button", { name: "Add Qureshi to the vocabulary" })).toBeInTheDocument();
  });
});
