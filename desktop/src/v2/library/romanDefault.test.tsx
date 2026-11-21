// An Urdu call is read in Roman Urdu by default (U19): the Urdu transcript first, then "Converting to Roman Urdu…"
// (with the percent once lines are done) on the recording page and its row until the view is written, "Couldn't
// convert" with Try again when it could not be; and every copy (Copy text, Copy for AI and its Language line, Send to
// WhatsApp, the Library's Copy for AI) in the view shown, else the default view once it is written.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem, TranscriptViewsState, ViewsSummary } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { aiHeader, copyForAi } from "./organise";
import { rowModel } from "./model";
import { rowSummary } from "../../lib/rowViews";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 17, 15, 0);
const bilal = (views: ViewsSummary | null = null, extra: Partial<LibraryItem> = {}): LibraryItem => makeItem("bilal", {
  title: "Bilal Qureshi", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: new Date(2026, 10, 17, 11, 42).toISOString(),
  duration_ms: 3_730_000, language: "ur", screen_count: 0,
  transcript: { final_status: "completed", final_transcript_id: "tr-bilal", live_available: false, preview: "السلام علیکم", views }, ...extra,
});
const ROMAN = ["Hello, yeh Sam hai.", "Hi Sam, shukriya.", "Invoice dekh lete hain.", "Theek hai."];
const READY: TranscriptViewsState = {
  status: "ready", available: ["roman", "urdu", "english"], default: "roman", progress: {}, model: "m", made_at: null, error: null,
  roman: ROMAN, english: ["Hello, this is Sam.", "Hi Sam, thanks.", "Let us look at the invoice.", "That works."], urdu: [null, null, null, null],
};
const making = (progress: TranscriptViewsState["progress"] = {}): TranscriptViewsState => ({ ...READY, status: "making", progress, roman: null, english: null, urdu: null });
const FAILED: TranscriptViewsState = { ...making(), status: "failed", error: "Converting took too long. Try again." };
const urduTranscript = (views: { status: TranscriptViewsState["status"]; progress?: TranscriptViewsState["progress"] }) =>
  makeTranscript({ id: "tr-bilal", language: "ur", views: { available: ["roman", "urdu", "english"], default: "roman", progress: {}, ...views } });

const writeText = vi.fn((_text: string) => Promise.resolve());
const lastCopy = () => (writeText.mock.calls.at(-1) as unknown as [string])[0];
const menuItem = (name: string | RegExp) => screen.getByRole("menuitem", { name });

async function open(item: LibraryItem, transcript = urduTranscript({ status: "ready" })) {
  respond("library_list", [item]);
  respond("library_refresh", [item]);
  respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", speaker_names: { Client: "Bilal" } });
  respond("transcript_detail", transcript);
  render(<LibraryV2 />);
  await act(flushAsync);
  fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi" }));
  await act(flushAsync);
}
const tick = async (ms: number) => {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms);
  });
  await act(flushAsync);
};

beforeEach(() => {
  resetTauriMock();
  writeText.mockClear();
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText } });
});
afterEach(() => {
  vi.useRealTimers();
});

describe("copies in the view shown (U19)", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
  });

  it("an Urdu call opens in Roman Urdu: Copy text, Copy with timestamps, Copy for AI (Language: Roman Urdu) and the ⋮ Copy for AI copy it", async () => {
    respond("transcript_views", READY);
    await open(bilal({ status: "ready", default: "roman", progress: {} }));
    expect(screen.getByRole("radio", { name: "Roman Urdu" })).toBeChecked();
    const tools = screen.getByRole("toolbar", { name: "Share, copy and export" });
    fireEvent.click(within(tools).getByRole("button", { name: "Copy text" }));
    await act(flushAsync);
    expect(lastCopy()).toBe("Me: Hello, yeh Sam hai.\nBilal: Hi Sam, shukriya.\nMe: Invoice dekh lete hain.\nBilal: Theek hai.");
    const pick = async (name: string) => {
      fireEvent.click(within(tools).getByRole("button", { name: "More copy options" }));
      fireEvent.click(menuItem(name));
      await act(flushAsync);
      return lastCopy();
    };
    expect(await pick("Copy with timestamps")).toMatch(/^\[0:00\] Me: Hello, yeh Sam hai\.\n\[0:12\] Bilal: Hi Sam, shukriya\./);
    const ai = await pick("Copy for AI");
    expect(ai).toMatch(/^# Bilal Qureshi\n17 November 2026, 11:42 am · WhatsApp · 1:02:10\nPeople: Me, Bilal\nLanguage: Roman Urdu\n\nNote: \[\?word\]/);
    expect(ai).toContain("Me: Hello, yeh Sam hai.");
    // The recording's ⋮ copies what the page shows, never a fresh read in another view.
    fireEvent.click(screen.getByRole("button", { name: "Recording actions" }));
    fireEvent.click(menuItem("Copy for AI"));
    await act(flushAsync);
    expect(lastCopy()).toBe(ai);
    // Original picked: the words as heard and the recording's own language.
    fireEvent.click(screen.getByRole("radio", { name: "Original" }));
    expect(await pick("Copy for AI")).toMatch(/\nLanguage: ur\n[\s\S]*Me: Hello, this is Sam\./);
  });

  it("English picked: the header says Language: English and the lines are the English view's", async () => {
    respond("transcript_views", READY);
    await open(bilal({ status: "ready", default: "roman", progress: {} }));
    fireEvent.click(screen.getByRole("radio", { name: "English" }));
    await act(flushAsync);
    const tools = screen.getByRole("toolbar", { name: "Share, copy and export" });
    fireEvent.click(within(tools).getByRole("button", { name: "More copy options" }));
    fireEvent.click(menuItem("Copy for AI"));
    await act(flushAsync);
    expect(lastCopy()).toMatch(/\nLanguage: English\n[\s\S]*Me: Let us look at the invoice\./);
  });

  it("Send to WhatsApp from the page sends the Roman Urdu text shown", async () => {
    respond("transcript_views", READY);
    respond("whatsapp_open", false);
    await open(bilal({ status: "ready", default: "roman", progress: {} }));
    fireEvent.click(screen.getByRole("button", { name: "Send to WhatsApp…" }));
    const dialog = screen.getByRole("dialog", { name: "Send to WhatsApp" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Text only" }));
    await act(flushAsync);
    expect(String(callsOf("whatsapp_open")[0].text)).toBe("Me: Hello, yeh Sam hai.\nBilal: Hi Sam, shukriya.\nMe: Invoice dekh lete hain.\nBilal: Theek hai.");
  });

  it("from a row (no view on screen): Send to WhatsApp reads the default view once it is written", async () => {
    respond("transcript_views", READY);
    respond("whatsapp_open", false);
    const item = bilal({ status: "ready", default: "roman", progress: {} });
    respond("library_list", [item]);
    respond("library_refresh", [item]);
    respond("session_detail", { playback_url: null, speaker_names: { Client: "Bilal" } });
    respond("transcript_detail", urduTranscript({ status: "ready" }));
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.contextMenu(screen.getByRole("button", { name: "Bilal Qureshi" }));
    fireEvent.click(menuItem("Send to WhatsApp…"));
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: "Send to WhatsApp" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Text only" }));
    await act(flushAsync);
    expect(String(callsOf("whatsapp_open")[0].text)).toContain("Me: Hello, yeh Sam hai.");
  });
});

describe("the Library's Copy for AI (U19)", () => {
  it("copies each recording in its default view once written, with Language: Roman Urdu; the original words until then", async () => {
    respond("session_detail", { speaker_names: { Client: "Bilal" } });
    respond("transcript_views", READY);
    respond("transcript_detail", urduTranscript({ status: "ready" }));
    const ready = await copyForAi([bilal()]);
    expect(ready.text).toContain("\nLanguage: Roman Urdu\n");
    expect(ready.text).toContain("[0:00] Me: Hello, yeh Sam hai.");
    expect(callsOf("transcript_views")).toEqual([{ id: "tr-bilal" }]);

    resetTauriMock();
    respond("session_detail", { speaker_names: { Client: "Bilal" } });
    respond("transcript_detail", urduTranscript({ status: "making" }));
    const still = await copyForAi([bilal()]);
    expect(still.text).toContain("\nLanguage: ur\n");
    expect(still.text).toContain("[0:00] Me: Hello, this is Sam.");
    expect(callsOf("transcript_views")).toEqual([]);
  });

  it("a view's missing line reads as that line's own words", async () => {
    respond("session_detail", { speaker_names: {} });
    respond("transcript_views", { ...READY, roman: ["Hello, yeh Sam hai.", null, null, null] });
    respond("transcript_detail", urduTranscript({ status: "ready" }));
    const { text } = await copyForAi([bilal()]);
    expect(text).toContain("Me: Hello, yeh Sam hai.\n[0:12] Client: Hi Sam, thanks for calling back.");
  });

  it("the header names the view's language, else the recording's chip", () => {
    const item = bilal(null, { language_chip: "UR" });
    expect(aiHeader(item, ["Me"], "roman")).toMatch(/\nLanguage: Roman Urdu$/);
    expect(aiHeader(item, ["Me"], "urdu")).toMatch(/\nLanguage: Urdu$/);
    expect(aiHeader(item, ["Me"], "english")).toMatch(/\nLanguage: English$/);
    expect(aiHeader(item, ["Me"])).toMatch(/\nLanguage: UR$/);
  });
});

describe("Converting to Roman Urdu… (U19)", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
  });

  it("the page says it from the moment the transcript is done, then the percent, then shows Roman Urdu and stops asking", async () => {
    const answers = [making(), making({ roman: { done: 6, total: 10 } }), READY];
    respondWith("transcript_views", () => answers.shift() ?? READY);
    await open(bilal({ status: "making", default: "roman", progress: {} }), urduTranscript({ status: "making" }));
    expect(screen.getByTestId("converting-line")).toHaveTextContent(/^Converting to Roman Urdu…$/);
    // The page asks at once; its row then leaves the asking to it.
    await tick(10);
    expect(callsOf("transcript_views")).toHaveLength(1);
    expect(screen.getByTestId("converting-line")).toHaveTextContent(/^Converting to Roman Urdu…$/);
    expect(screen.getByText("Hello, this is Sam.")).toBeInTheDocument();
    await tick(5_000);
    expect(screen.getByTestId("converting-line")).toHaveTextContent("Converting to Roman Urdu… 60%");
    const synced = callsOf("library_refresh").length;
    await tick(5_000);
    expect(screen.queryByTestId("converting-line")).toBeNull();
    expect(screen.getByRole("radio", { name: "Roman Urdu" })).toBeChecked();
    expect(screen.getByText("Hello, yeh Sam hai.")).toBeInTheDocument();
    // A fresh read of the Library: the title the server wrote in Roman Urdu meanwhile is the one shown.
    expect(callsOf("library_refresh").length).toBeGreaterThan(synced);
    const asked = callsOf("transcript_views").length;
    await tick(20_000);
    expect(callsOf("transcript_views")).toHaveLength(asked);
  });

  it("a view that went quiet: Couldn't convert to Roman Urdu, and Try again starts it again", async () => {
    respond("transcript_views", FAILED);
    respond("transcript_views_make", making());
    await open(bilal({ status: "failed", default: "roman", progress: {} }), urduTranscript({ status: "failed" }));
    const failed = screen.getByTestId("convert-failed");
    expect(failed).toHaveTextContent("Couldn't convert to Roman Urdu");
    expect(screen.queryByTestId("converting-line")).toBeNull();
    // Nothing is asked while it is failed: it never spins forever.
    await tick(15_000);
    expect(callsOf("transcript_views")).toEqual([]);
    fireEvent.click(within(failed).getByRole("button", { name: "Try again" }));
    await act(flushAsync);
    expect(callsOf("transcript_views_make")).toEqual([{ id: "tr-bilal" }]);
    expect(screen.getByTestId("converting-line")).toHaveTextContent("Converting to Roman Urdu…");
    expect(screen.queryByTestId("convert-failed")).toBeNull();
  });

  it("a row says it right after the transcript is done, the percent once lines are written, and stops when ready", async () => {
    const item = bilal({ status: "making", default: "roman", progress: {} });
    respond("library_list", [item]);
    respond("library_refresh", [item]);
    const answers = [making({ roman: { done: 6, total: 10 } }), READY];
    respondWith("transcript_views", () => answers.shift() ?? READY);
    render(<LibraryV2 />);
    await act(flushAsync);
    expect(screen.getByTestId("row-converting")).toHaveTextContent(/^Converting to Roman Urdu…$/);
    await tick(5_000);
    expect(screen.getByTestId("row-converting")).toHaveTextContent("Converting to Roman Urdu… 60%");
    await tick(5_000);
    expect(screen.queryByTestId("row-converting")).toBeNull();
    expect(callsOf("transcript_views")).toHaveLength(2);
    await tick(20_000);
    expect(callsOf("transcript_views")).toHaveLength(2);
  });
});

describe("a row's views (U19)", () => {
  it("says converting in place of the Urdu first words, failed before them, nothing once ready or for an English call", () => {
    expect(rowModel(bilal({ status: "making", default: "roman", progress: {} }), NOW)).toMatchObject({ meta: "Converting to Roman Urdu…", converting: "making" });
    expect(rowModel(bilal({ status: "making", default: "roman", progress: { roman: { done: 6, total: 10 } } }), NOW).meta).toBe("Converting to Roman Urdu… 60%");
    expect(rowModel(bilal({ status: "failed", default: "roman", progress: {} }), NOW)).toMatchObject({ meta: "Couldn't convert to Roman Urdu · السلام علیکم", converting: "failed" });
    expect(rowModel(bilal({ status: "ready", default: "roman", progress: {} }), NOW)).toMatchObject({ meta: "السلام علیکم", converting: null });
    expect(rowModel(bilal({ status: "making", default: "original", progress: {} }), NOW).converting).toBeNull();
  });

  it("trusts what was read here while the recording stays as it was, and the recording again once it changed", () => {
    const item = bilal({ status: "making", default: "roman", progress: {} });
    const seen = { "tr-bilal": { summary: { status: "ready" as const, default: "roman" as const, progress: {} }, at: 0, basis: item.updated_at } };
    expect(rowSummary(item, seen)?.status).toBe("ready");
    expect(rowSummary({ ...item, updated_at: "2026-11-17T15:00:00Z" }, seen)?.status).toBe("making");
    expect(rowSummary(item, {})?.status).toBe("making");
  });
});
