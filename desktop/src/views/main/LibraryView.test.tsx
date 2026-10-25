import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { LibraryView } from "./LibraryView";
import type { LibraryItem, Segment, TranscriptDetail } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, listenerCount, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 9, 24, 12, 0, 0); // Saturday 24 Oct 2026, local time
const local = (monthIndex: number, day: number, hour: number, minute = 0) => new Date(2026, monthIndex, day, hour, minute).toISOString();

const ITEMS: LibraryItem[] = [
  makeItem("a", {
    title: "Zoom with Acme",
    source_device: "desktop", source_device_label: "Desktop", source_app: "zoom", source_app_label: "Zoom",
    client: { id: "c1", name: "Acme Holdings" }, started_at: local(9, 24, 9, 30), duration_ms: 125_000,
    transcript: { final_status: "completed", final_transcript_id: "tr-a", live_available: false, preview: "Let us discuss the invoice" },
  }),
  makeItem("b", {
    title: "WhatsApp call", source_device: "phone", source_device_label: "Phone", source_app: "whatsapp", source_app_label: "WhatsApp",
    started_at: local(9, 24, 8, 15), duration_ms: 3_725_000, status: "processing",
    transcript: { final_status: "processing", final_transcript_id: null, live_available: false, preview: null },
  }),
  makeItem("c", {
    title: "Google Meet standup", source_device: "desktop", source_app: "google_meet", source_app_label: "Google Meet",
    started_at: local(9, 23, 16, 0), duration_ms: 900_000, status: "failed",
    transcript: { final_status: "failed", final_transcript_id: "tr-c", live_available: false, preview: null },
  }),
  makeItem("d", {
    title: "Voice note from Sam", source_device: "phone", source_device_label: "Phone", source_app: "voice_note", source_app_label: "Voice note",
    started_at: local(9, 21, 10, 0), duration_ms: 42_000,
    transcript: { final_status: "completed", final_transcript_id: "tr-d", live_available: false, preview: "Remember to call back" },
  }),
  makeItem("e", {
    title: "Client briefing", source_device: "web", source_device_label: "Web", source_app: "other", source_app_label: "Other",
    started_at: local(8, 1, 14, 0), duration_ms: 2_000_000,
    transcript: { final_status: "completed", final_transcript_id: "tr-e", live_available: false, preview: "Quarterly roadmap" },
  }),
  makeItem("gone", { title: "Deleted recording", deleted: true }),
];

// Rebuilt before every test, so a test that adds or overrides a transcript cannot affect the next one.
const baseTranscripts = (): Record<string, TranscriptDetail> => ({
  "tr-a": makeTranscript({ id: "tr-a" }),
  "tr-c": makeTranscript({ id: "tr-c", status: "failed", segments: [], error: "The speech provider rejected the audio (unsupported codec)." }),
  "tr-d": makeTranscript({ id: "tr-d", provider: "assemblyai", segments: [
    { channel: 1, speaker: "Speaker", start_ms: 0, end_ms: 3_000, text: "Remember to call back." },
    { channel: 1, speaker: "Speaker", start_ms: 4_500, end_ms: 6_000, text: "Before Friday." },
  ] }),
  "tr-e": makeTranscript({ id: "tr-e", segments: [] }),
});
let TRANSCRIPTS = baseTranscripts();

let currentTimes = new WeakMap<HTMLMediaElement, number>();
const play = vi.fn(() => Promise.resolve());
const writeText = vi.fn((_text: string) => Promise.resolve());

function installMediaStubs() {
  currentTimes = new WeakMap();
  play.mockClear();
  Object.defineProperty(HTMLMediaElement.prototype, "play", { configurable: true, value: play });
  Object.defineProperty(HTMLMediaElement.prototype, "currentTime", {
    configurable: true,
    get(this: HTMLMediaElement) {
      return currentTimes.get(this) ?? 0;
    },
    set(this: HTMLMediaElement, value: number) {
      currentTimes.set(this, value);
    },
  });
}

function mockBackend(items: LibraryItem[] = ITEMS, extra: { playbackUrl?: unknown } = { playbackUrl: "https://audio.example.test/rec.wav" }) {
  respond("library_list", items);
  respond("library_refresh", items);
  respondWith("transcript_detail", ({ id }) => {
    const found = TRANSCRIPTS[id as string];
    return found ? Promise.resolve(found) : Promise.reject("Server returned 404: Not found");
  });
  respondWith("session_detail", ({ id }) => ({ id, playback_url: extra.playbackUrl }));
  respond("delete_session", undefined);
}

async function renderLibrary(items: LibraryItem[] = ITEMS) {
  mockBackend(items);
  const notify = vi.fn();
  const view = render(<LibraryView notify={notify} />);
  await flushAsync(); // the initial cache read and sync settle inside act
  if (items.length) await within(screen.getByTestId("library-list")).findByText(items[0].title ?? "");
  return { notify, ...view };
}

const list = () => screen.getByTestId("library-list");
const itemButton = (title: string) => within(list()).getByText(title).closest("button") as HTMLElement;
const titlesInList = () => [...list().querySelectorAll(".item-title")].map((n) => n.textContent);
const detail = () => document.querySelector(".detail") as HTMLElement;
const selectItem = async (title: string, transcriptText?: string) => {
  fireEvent.click(itemButton(title));
  await flushAsync(); // transcript and playback URL requests settle inside act
  if (transcriptText) await within(detail()).findByText(transcriptText);
};

beforeEach(() => {
  resetTauriMock();
  TRANSCRIPTS = baseTranscripts();
  installMediaStubs();
  vi.useFakeTimers({ toFake: ["Date"], now: NOW });
  writeText.mockClear();
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText } });
});

afterEach(async () => {
  await flushAsync();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("LibraryView list", () => {
  it("shows an empty state with guidance", async () => {
    await renderLibrary([]);
    expect(await screen.findByText("No recordings yet.")).toBeInTheDocument();
    expect(screen.getByText("Recordings from your phone, this computer and the web will appear here.")).toBeInTheDocument();
    expect(screen.getByText("Select a recording to read its transcript.")).toBeInTheDocument();
  });

  it("counts recordings with correct pluralisation and ignores tombstones", async () => {
    await renderLibrary(ITEMS);
    expect(screen.getByText("5 recordings from your phone, this computer and the web.")).toBeInTheDocument();
  });

  it("says 'recording' for exactly one", async () => {
    await renderLibrary([ITEMS[0]]);
    expect(screen.getByText("1 recording from your phone, this computer and the web.")).toBeInTheDocument();
  });

  it("renders title, local 12-hour time, duration, client, preview and a state pill per row", async () => {
    await renderLibrary();
    const row = itemButton("Zoom with Acme");
    expect(within(row).getByText(/^Today · 9:30\s?AM · 2:05 · Acme Holdings$/)).toBeInTheDocument();
    expect(within(row).getByText("Let us discuss the invoice")).toBeInTheDocument();
    expect(within(row).getByText("Ready")).toHaveClass("pill", "ready");
    expect(within(itemButton("WhatsApp call")).getByText("Processing")).toHaveClass("processing");
    expect(within(itemButton("WhatsApp call")).getByText(/^Today · 8:15\s?AM · 1:02:05$/)).toBeInTheDocument();
    expect(within(itemButton("Google Meet standup")).getByText("Failed")).toHaveClass("failed");
    expect(within(itemButton("Google Meet standup")).getByText(/^Yesterday · 4:00\s?PM · 15:00$/)).toBeInTheDocument();
  });

  it("colours the avatar by source device and omits the tombstone", async () => {
    await renderLibrary();
    expect(itemButton("Zoom with Acme").querySelector(".avatar")).toHaveClass("desktop");
    expect(itemButton("WhatsApp call").querySelector(".avatar")).toHaveClass("phone");
    expect(itemButton("Client briefing").querySelector(".avatar")).toHaveClass("web");
    expect(screen.queryByText("Deleted recording")).not.toBeInTheDocument();
  });

  it("groups newest-first rows under Today, Yesterday, This week and Earlier", async () => {
    await renderLibrary();
    const labels = [...list().querySelectorAll(".group-label")].map((n) => n.textContent);
    expect(labels).toEqual(["Today", "Yesterday", "This week", "Earlier"]);
    expect(titlesInList()).toEqual(["Zoom with Acme", "WhatsApp call", "Google Meet standup", "Voice note from Sam", "Client briefing"]);
  });

  it("drops the headings for sorts that are not by date", async () => {
    await renderLibrary();
    fireEvent.change(screen.getByLabelText("Sort recordings"), { target: { value: "title" } });
    expect(list().querySelectorAll(".group-label")).toHaveLength(0);
    expect(titlesInList()).toEqual(["Client briefing", "Google Meet standup", "Voice note from Sam", "WhatsApp call", "Zoom with Acme"]);
  });

  it("offers four sort orders and applies each", async () => {
    await renderLibrary();
    const select = screen.getByLabelText("Sort recordings") as HTMLSelectElement;
    expect([...select.options].map((o) => o.text)).toEqual(["Newest first", "Oldest first", "Longest first", "Title A–Z"]);
    fireEvent.change(select, { target: { value: "oldest" } });
    expect(titlesInList()).toEqual(["Client briefing", "Voice note from Sam", "Google Meet standup", "WhatsApp call", "Zoom with Acme"]);
    fireEvent.change(select, { target: { value: "longest" } });
    expect(titlesInList()).toEqual(["WhatsApp call", "Client briefing", "Google Meet standup", "Zoom with Acme", "Voice note from Sam"]);
  });
});

describe("LibraryView search and filters", () => {
  it("filters by title as you type and shows a clear button", async () => {
    await renderLibrary();
    const input = screen.getByLabelText("Search recordings");
    expect(screen.queryByRole("button", { name: "Clear search" })).not.toBeInTheDocument();
    fireEvent.change(input, { target: { value: "meet" } });
    expect(titlesInList()).toEqual(["Google Meet standup"]);
    expect(screen.getByRole("button", { name: "Clear search" })).toBeInTheDocument();
  });

  it("finds recordings by transcript preview and by client name", async () => {
    await renderLibrary();
    const input = screen.getByLabelText("Search recordings");
    fireEvent.change(input, { target: { value: "roadmap" } });
    expect(titlesInList()).toEqual(["Client briefing"]);
    fireEvent.change(input, { target: { value: "acme" } });
    expect(titlesInList()).toEqual(["Zoom with Acme"]);
  });

  it("clears the search with the × button", async () => {
    await renderLibrary();
    const input = screen.getByLabelText("Search recordings") as HTMLInputElement;
    fireEvent.change(input, { target: { value: "zzz" } });
    expect(screen.getByText("Nothing matches. Try a different search or filter.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Clear search" }));
    expect(input.value).toBe("");
    expect(titlesInList()).toHaveLength(5);
  });

  it("filters by source device with the chips and combines with the search", async () => {
    await renderLibrary();
    fireEvent.click(screen.getByRole("button", { name: "Phone" }));
    expect(titlesInList()).toEqual(["WhatsApp call", "Voice note from Sam"]);
    fireEvent.click(screen.getByRole("button", { name: "Desktop" }));
    expect(titlesInList()).toEqual(["Zoom with Acme", "Google Meet standup"]);
    fireEvent.click(screen.getByRole("button", { name: "Web" }));
    expect(titlesInList()).toEqual(["Client briefing"]);
    fireEvent.click(screen.getByRole("button", { name: "Desktop" }));
    fireEvent.change(screen.getByLabelText("Search recordings"), { target: { value: "standup" } });
    expect(titlesInList()).toEqual(["Google Meet standup"]);
    fireEvent.click(screen.getByRole("button", { name: "All" }));
    expect(titlesInList()).toEqual(["Google Meet standup"]);
  });

  // A6.DU7: the source chips are filter toggles, not a tablist without tabs.
  it("exposes the source chips as a named group of pressed / not pressed toggles", async () => {
    await renderLibrary();
    expect(screen.queryByRole("tablist")).not.toBeInTheDocument();
    const group = screen.getByRole("group", { name: "Filter by device" });
    const chips = within(group).getAllByRole("button");
    expect(chips.map((c) => [c.textContent, c.getAttribute("aria-pressed")])).toEqual([
      ["All", "true"], ["Phone", "false"], ["Desktop", "false"], ["Web", "false"],
    ]);
    fireEvent.click(within(group).getByRole("button", { name: "Web" }));
    expect(within(group).getByRole("button", { name: "Web" })).toHaveAttribute("aria-pressed", "true");
    expect(within(group).getByRole("button", { name: "All" })).toHaveAttribute("aria-pressed", "false");
  });

  it("highlights the active chip and explains an empty filter", async () => {
    await renderLibrary([ITEMS[0]]);
    expect(screen.getByRole("button", { name: "All" })).toHaveClass("active");
    fireEvent.click(screen.getByRole("button", { name: "Phone" }));
    expect(screen.getByRole("button", { name: "Phone" })).toHaveClass("active");
    expect(screen.getByRole("button", { name: "All" })).not.toHaveClass("active");
    expect(screen.getByText("Nothing matches. Try a different search or filter.")).toBeInTheDocument();
  });
});

describe("LibraryView syncing", () => {
  it("shows cached rows immediately and a disabled 'Syncing…' button until the refresh finishes", async () => {
    let finish: (v: LibraryItem[]) => void = () => undefined;
    respond("library_list", [ITEMS[0]]);
    respondWith("library_refresh", () => new Promise<LibraryItem[]>((resolve) => (finish = resolve)));
    render(<LibraryView notify={vi.fn()} />);
    expect(await within(list()).findByText("Zoom with Acme")).toBeInTheDocument();
    const sync = screen.getByRole("button", { name: "Syncing…" });
    expect(sync).toBeDisabled();

    await act(async () => finish([ITEMS[0], ITEMS[1]]));
    expect(await screen.findByRole("button", { name: "Sync" })).toBeEnabled();
    expect(titlesInList()).toEqual(["Zoom with Acme", "WhatsApp call"]);
  });

  it("syncs again when Sync is pressed", async () => {
    await renderLibrary();
    expect(callsOf("library_refresh")).toHaveLength(1);
    fireEvent.click(screen.getByRole("button", { name: "Sync" }));
    await waitFor(() => expect(callsOf("library_refresh")).toHaveLength(2));
  });

  it("reports a failed sync and falls back to the cached library", async () => {
    respond("library_list", [ITEMS[0]]);
    fail("library_refresh", "Network error: connection refused");
    const notify = vi.fn();
    render(<LibraryView notify={notify} />);
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Network error: connection refused", true));
    expect(await within(list()).findByText("Zoom with Acme")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Sync" })).toBeEnabled();
  });

  it("re-reads the cache when the backend announces library:updated", async () => {
    await renderLibrary([ITEMS[0]]);
    expect(callsOf("library_list")).toHaveLength(1);
    respond("library_list", [ITEMS[0], ITEMS[1]]);
    await emitEvent("library:updated", 1);
    expect(await within(list()).findByText("WhatsApp call")).toBeInTheDocument();
  });

  it("stops listening for library:updated on unmount", async () => {
    const { unmount } = await renderLibrary([ITEMS[0]]);
    await waitFor(() => expect(listenerCount("library:updated")).toBe(1));
    unmount();
    await waitFor(() => expect(listenerCount("library:updated")).toBe(0));
  });
});

describe("LibraryView transcript", () => {
  it("selecting a recording shows its badges, title, metadata, header and every segment with a timestamp", async () => {
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    const panel = within(detail());
    expect(panel.getByTestId("source-device")).toHaveTextContent("Desktop");
    expect(panel.getByTestId("source-app")).toHaveTextContent("Zoom");
    expect(panel.getByRole("heading", { name: "Zoom with Acme" })).toBeInTheDocument();
    expect(panel.getByText(/Today · 9:30\s?AM · 2:05 · Acme Holdings/)).toBeInTheDocument();
    expect(panel.getByText("Final transcript · assemblyai")).toBeInTheDocument();
    const rows = [...detail().querySelectorAll(".segment")].map((n) => [...n.children].map((c) => c.textContent));
    expect(rows).toEqual([
      ["0:00", "Me", "Hello, this is Sam."],
      ["0:12", "Client", "Hi Sam, thanks for calling back."],
      ["1:05", "Me", "Let us go over the invoice."],
      ["1:02:05", "Client", "That works for us."],
    ]);
    expect(callsOf("transcript_detail")).toEqual([{ id: "tr-a" }]);
    expect(callsOf("session_detail")).toEqual([{ id: "a" }]);
  });

  it("marks the selected row and colours speakers by channel", async () => {
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    expect(itemButton("Zoom with Acme")).toHaveClass("selected");
    expect(itemButton("WhatsApp call")).not.toHaveClass("selected");
    const speakers = [...detail().querySelectorAll(".seg-spk")];
    expect(speakers.map((s) => s.className)).toEqual(["seg-spk spk-1", "seg-spk spk-2", "seg-spk spk-1", "seg-spk spk-2"]);
  });

  it("falls back to the live transcript while the final one is pending", async () => {
    TRANSCRIPTS["tr-live"] = makeTranscript({ id: "tr-live", kind: "live", segments: [{ channel: 1, speaker: "Me", start_ms: 0, end_ms: 900, text: "Live words." }] });
    const live = makeItem("l", { title: "Live only", transcript: { final_status: "processing", final_transcript_id: null, live_available: true, live_transcript_id: "tr-live", preview: null } });
    await renderLibrary([live]);
    await selectItem("Live only", "Live words.");
    expect(callsOf("transcript_detail")).toEqual([{ id: "tr-live" }]);
    expect(within(detail()).getByText("Live captions (final transcript pending)")).toBeInTheDocument();
  });

  // pending / submitted / processing read "Transcribing…" everywhere (phase 1 contract, section 1 item 11).
  it.each([
    [null, "Transcript not available yet."],
    ["processing", "Transcribing…"],
    ["pending", "Transcribing…"],
    ["submitted", "Transcribing…"],
    ["awaiting_device", "Transcript awaiting device"],
  ])("explains a recording without a transcript (final status %s)", async (finalStatus, message) => {
    const pending = makeItem("p", { title: "Pending", status: "processing", transcript: { final_status: finalStatus, final_transcript_id: null, live_available: false, preview: null } });
    await renderLibrary([pending]);
    await selectItem("Pending");
    expect(await within(detail()).findByText(message)).toBeInTheDocument();
    expect(callsOf("transcript_detail")).toHaveLength(0);
  });

  it("shows the failure reason from the server for a failed transcript, without copy buttons", async () => {
    await renderLibrary();
    await selectItem("Google Meet standup");
    const notice = await within(detail()).findByText(/Transcription failed\./);
    expect(notice.closest(".notice")).toHaveClass("error");
    expect(notice.closest(".notice")).toHaveTextContent("The speech provider rejected the audio (unsupported codec).");
    expect(within(detail()).queryByRole("button", { name: /Copy/ })).not.toBeInTheDocument();
  });

  it("uses a generic failure message when the server gave no reason", async () => {
    TRANSCRIPTS["tr-c"] = makeTranscript({ id: "tr-c", status: "failed", segments: [], error: null });
    await renderLibrary();
    await selectItem("Google Meet standup");
    expect(await within(detail()).findByText(/The speech provider could not process this recording\./)).toBeInTheDocument();
  });

  // DR18 client (contract 5.3 item 5): the server says no audio was captured; the desktop says what to check.
  it("adds the microphone hint to a failure with error_code no_audio", async () => {
    TRANSCRIPTS["tr-c"] = makeTranscript({ id: "tr-c", status: "failed", segments: [], error: "No audio was captured in this recording.", error_code: "no_audio" });
    await renderLibrary();
    await selectItem("Google Meet standup");
    const notice = (await within(detail()).findByText(/Transcription failed\./)).closest(".notice") as HTMLElement;
    expect(notice).toHaveTextContent("No audio was captured in this recording.");
    expect(within(notice).getByText("Check your microphone and the audio device, then try again.")).toBeInTheDocument();
  });

  it.each([null, undefined, "budget_exceeded", "provider_error"])("adds no hint for error_code %s", async (code) => {
    TRANSCRIPTS["tr-c"] = makeTranscript({ id: "tr-c", status: "failed", segments: [], error: "Monthly transcription limit reached", error_code: code });
    await renderLibrary();
    await selectItem("Google Meet standup");
    expect(await within(detail()).findByText(/Monthly transcription limit reached/)).toBeInTheDocument();
    expect(within(detail()).queryByText(/Check your microphone/)).not.toBeInTheDocument();
  });

  it("says so when a finished transcript has no speech and disables both copy buttons", async () => {
    await renderLibrary();
    await selectItem("Client briefing");
    expect(await within(detail()).findByText("The transcript is empty: no speech was detected.")).toBeInTheDocument();
    expect(within(detail()).getByRole("button", { name: "Copy text" })).toBeDisabled();
    expect(within(detail()).getByRole("button", { name: "Copy with timestamps" })).toBeDisabled();
  });

  it("shows the transcript status instead of text when the transcript cannot be loaded", async () => {
    await renderLibrary();
    fail("transcript_detail", "Server returned 404: Not found");
    await selectItem("Zoom with Acme");
    await waitFor(() => expect(callsOf("transcript_detail")).toHaveLength(1));
    expect(await within(detail()).findByText("Transcript completed")).toBeInTheDocument();
  });

  it("returns to the placeholder when the selected row is filtered out", async () => {
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    fireEvent.change(screen.getByLabelText("Search recordings"), { target: { value: "briefing" } });
    expect(await within(detail()).findByText("Select a recording to read its transcript.")).toBeInTheDocument();
    expect(within(detail()).queryByText("Hello, this is Sam.")).not.toBeInTheDocument();
  });

  // A6.DU3: the final transcript row exists (status submitted / processing, no segments) while the provider works.
  it.each(["pending", "submitted", "processing"])("shows a %s transcript as Transcribing…, never as empty speech", async (status) => {
    TRANSCRIPTS["tr-wip"] = makeTranscript({ id: "tr-wip", status, segments: [] });
    const wip = makeItem("w", { title: "Still working", status: "processing", transcript: { final_status: status, final_transcript_id: "tr-wip", live_available: false, preview: null } });
    await renderLibrary([wip]);
    await selectItem("Still working");
    expect(await within(detail()).findByText("Transcribing…")).toBeInTheDocument();
    expect(within(detail()).queryByText(/no speech was detected/i)).not.toBeInTheDocument();
    expect(within(detail()).queryByRole("button", { name: /Copy/ })).not.toBeInTheDocument();
    expect(callsOf("transcript_detail")).toEqual([{ id: "tr-wip" }]);
  });

  // A6.DU3: while the final transcript is being made, the live captions are the better thing to show.
  it("shows the live captions while the final transcript is still being made", async () => {
    TRANSCRIPTS["tr-wip"] = makeTranscript({ id: "tr-wip", status: "submitted", segments: [] });
    TRANSCRIPTS["tr-live"] = makeTranscript({ id: "tr-live", kind: "live", segments: [{ channel: 1, speaker: "Me", start_ms: 0, end_ms: 900, text: "Live words." }] });
    const both = makeItem("b2", { title: "Both", status: "processing", transcript: { final_status: "submitted", final_transcript_id: "tr-wip", live_available: true, live_transcript_id: "tr-live", preview: null } });
    await renderLibrary([both]);
    await selectItem("Both", "Live words.");
    expect(callsOf("transcript_detail")).toEqual([{ id: "tr-live" }]);
    expect(within(detail()).getByText("Live captions (final transcript pending)")).toBeInTheDocument();
  });

  it("loads the finished transcript once a sync reports it completed", async () => {
    TRANSCRIPTS["tr-wip"] = makeTranscript({ id: "tr-wip", status: "processing", segments: [] });
    const wip = makeItem("w", { title: "Still working", status: "processing", transcript: { final_status: "processing", final_transcript_id: "tr-wip", live_available: false, preview: null } });
    await renderLibrary([wip]);
    await selectItem("Still working");
    expect(await within(detail()).findByText("Transcribing…")).toBeInTheDocument();

    TRANSCRIPTS["tr-wip"] = makeTranscript({ id: "tr-wip" });
    respond("library_list", [{ ...wip, status: "completed", transcript: { ...wip.transcript!, final_status: "completed" } }]);
    await emitEvent("library:updated", 1);
    expect(await within(detail()).findByText("Hello, this is Sam.")).toBeInTheDocument();
    expect(within(detail()).queryByText("Transcribing…")).not.toBeInTheDocument();
  });

  // A6.DU2: a late transcript response for the previous selection replaces the transcript of the current one.
  it("never shows one recording's transcript under another when responses arrive out of order", async () => {
    let releaseFirst: (t: TranscriptDetail) => void = () => undefined;
    mockBackend();
    respondWith("transcript_detail", ({ id }) => (id === "tr-a" ? new Promise<TranscriptDetail>((resolve) => (releaseFirst = resolve)) : Promise.resolve(TRANSCRIPTS[id as string])));
    render(<LibraryView notify={vi.fn()} />);
    await within(list()).findByText("Zoom with Acme");

    fireEvent.click(itemButton("Zoom with Acme")); // slow response
    fireEvent.click(itemButton("Voice note from Sam")); // fast response
    await within(detail()).findByText("Remember to call back.");
    await act(async () => releaseFirst(TRANSCRIPTS["tr-a"]));

    expect(within(detail()).getByRole("heading", { name: "Voice note from Sam" })).toBeInTheDocument();
    expect(within(detail()).queryByText("Hello, this is Sam.")).not.toBeInTheDocument();
    expect(within(detail()).getByText("Remember to call back.")).toBeInTheDocument();
  });

  // A6.DU2: the same holds for the playback URL and for a failed transcript request.
  it("ignores a late playback URL or a late failure that belongs to the previous selection", async () => {
    let releaseSession: (d: unknown) => void = () => undefined;
    let failTranscript: (reason: string) => void = () => undefined;
    mockBackend();
    respondWith("session_detail", ({ id }) =>
      id === "a" ? new Promise((resolve) => (releaseSession = resolve)) : Promise.resolve({ id, playback_url: "https://audio.example.test/d.wav" }),
    );
    respondWith("transcript_detail", ({ id }) =>
      id === "tr-a" ? new Promise((_, reject) => (failTranscript = reject)) : Promise.resolve(TRANSCRIPTS[id as string]),
    );
    render(<LibraryView notify={vi.fn()} />);
    await within(list()).findByText("Zoom with Acme");

    fireEvent.click(itemButton("Zoom with Acme"));
    fireEvent.click(itemButton("Voice note from Sam"));
    await within(detail()).findByText("Remember to call back.");
    await waitFor(() => expect(document.querySelector("audio")).toHaveAttribute("src", "https://audio.example.test/d.wav"));
    await act(async () => {
      releaseSession({ id: "a", playback_url: "https://audio.example.test/a.wav" });
      failTranscript("Server returned 404: Not found");
    });
    await flushAsync();

    expect(document.querySelector("audio")).toHaveAttribute("src", "https://audio.example.test/d.wav");
    expect(within(detail()).getByText("Remember to call back.")).toBeInTheDocument();
  });
});

describe("LibraryView audio playback", () => {
  const audio = () => document.querySelector("audio") as HTMLAudioElement;

  it("embeds a player with the signed playback URL", async () => {
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    await waitFor(() => expect(audio()).not.toBeNull());
    expect(audio()).toHaveAttribute("src", "https://audio.example.test/rec.wav");
    expect(audio()).toHaveAttribute("controls");
  });

  it("has no player (and segments are not clickable) when the server gives no playback URL", async () => {
    mockBackend(ITEMS, { playbackUrl: undefined });
    render(<LibraryView notify={vi.fn()} />);
    await within(list()).findByText("Zoom with Acme");
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    await waitFor(() => expect(callsOf("session_detail")).toHaveLength(1));
    expect(audio()).toBeNull();
    expect(detail().querySelector(".segment")).not.toHaveClass("seekable");
    expect(within(detail()).queryByRole("button", { name: /Hello, this is Sam/ })).not.toBeInTheDocument();
    fireEvent.click(within(detail()).getByText("Hello, this is Sam."));
    expect(play).not.toHaveBeenCalled();
  });

  it("ignores a playback URL that is not a string", async () => {
    mockBackend(ITEMS, { playbackUrl: 42 });
    render(<LibraryView notify={vi.fn()} />);
    await within(list()).findByText("Zoom with Acme");
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    await waitFor(() => expect(callsOf("session_detail")).toHaveLength(1));
    expect(audio()).toBeNull();
  });

  it("clicking a timestamp row seeks the audio to that second and starts playback", async () => {
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hi Sam, thanks for calling back.");
    await waitFor(() => expect(audio()).not.toBeNull());
    fireEvent.click(within(detail()).getByText("Hi Sam, thanks for calling back."));
    expect(audio().currentTime).toBe(12.4);
    expect(play).toHaveBeenCalledTimes(1);
    fireEvent.click(within(detail()).getByText("That works for us."));
    expect(audio().currentTime).toBe(3725);
    expect(play).toHaveBeenCalledTimes(2);
  });

  it("clicking the timestamp itself also seeks", async () => {
    await renderLibrary();
    await selectItem("Zoom with Acme", "Let us go over the invoice.");
    await waitFor(() => expect(audio()).not.toBeNull());
    fireEvent.click(within(detail()).getByText("1:05"));
    expect(audio().currentTime).toBe(65);
  });

  it("still seeks when the browser refuses to autoplay", async () => {
    play.mockImplementationOnce(() => Promise.reject(new DOMException("blocked", "NotAllowedError")));
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    await waitFor(() => expect(audio()).not.toBeNull());
    fireEvent.click(within(detail()).getByText("Let us go over the invoice."));
    await Promise.resolve();
    expect(audio().currentTime).toBe(65);
  });

  it("highlights the segment being played and follows time updates and seeks", async () => {
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    await waitFor(() => expect(audio()).not.toBeNull());
    const active = () => [...detail().querySelectorAll(".segment.active")].map((n) => n.textContent);

    expect(active()).toEqual([]);
    audio().currentTime = 0.5;
    fireEvent.timeUpdate(audio());
    expect(active()).toEqual(["0:00MeHello, this is Sam."]);
    audio().currentTime = 13;
    fireEvent.timeUpdate(audio());
    expect(active()).toEqual(["0:12ClientHi Sam, thanks for calling back."]);
    audio().currentTime = 3_800;
    fireEvent.seeked(audio());
    expect(active()).toEqual(["1:02:05ClientThat works for us."]);
  });

  it("resets playback state when another recording is selected", async () => {
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    await waitFor(() => expect(audio()).not.toBeNull());
    audio().currentTime = 13;
    fireEvent.timeUpdate(audio());
    await selectItem("Voice note from Sam", "Remember to call back.");
    expect(detail().querySelectorAll(".segment.active")).toHaveLength(0);
  });

  // A6.DU8: a signed playback URL expires after 30 minutes; the player used to fail silently.
  it("asks for a fresh playback URL once when the audio fails to load", async () => {
    let served = 0;
    await renderLibrary();
    respondWith("session_detail", ({ id }) => ({ id, playback_url: `https://audio.example.test/rec.wav?v=${++served}` }));
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    await waitFor(() => expect(audio()).toHaveAttribute("src", "https://audio.example.test/rec.wav?v=1"));
    fireEvent.error(audio());
    await waitFor(() => expect(audio()).toHaveAttribute("src", "https://audio.example.test/rec.wav?v=2"));
    expect(callsOf("session_detail")).toEqual([{ id: "a" }, { id: "a" }]);
    expect(within(detail()).queryByText("Playback link expired. Open the recording again.")).not.toBeInTheDocument();
  });

  it("says the playback link expired when the fresh URL fails as well", async () => {
    let served = 0;
    await renderLibrary();
    respondWith("session_detail", ({ id }) => ({ id, playback_url: `https://audio.example.test/rec.wav?v=${++served}` }));
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    await waitFor(() => expect(audio()).not.toBeNull());
    fireEvent.error(audio());
    await waitFor(() => expect(audio()).toHaveAttribute("src", "https://audio.example.test/rec.wav?v=2"));
    fireEvent.error(audio());
    expect(await within(detail()).findByText("Playback link expired. Open the recording again.")).toBeInTheDocument();
    expect(audio()).toBeNull();
    expect(callsOf("session_detail")).toHaveLength(2);
    expect(within(detail()).getByText("Hello, this is Sam.")).toBeInTheDocument();
  });

  it.each([
    ["the refresh fails", () => Promise.reject("Network error: offline")],
    ["the server returns the same URL", () => ({ playback_url: "https://audio.example.test/rec.wav" })],
    ["the server has no URL any more", () => ({ playback_url: null })],
  ])("says the playback link expired when %s", async (_case, answer) => {
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    await waitFor(() => expect(audio()).not.toBeNull());
    respondWith("session_detail", answer);
    fireEvent.error(audio());
    expect(await within(detail()).findByText("Playback link expired. Open the recording again.")).toBeInTheDocument();
    expect(audio()).toBeNull();
  });

  it("starts with a working player again after another recording is opened", async () => {
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    await waitFor(() => expect(audio()).not.toBeNull());
    respondWith("session_detail", () => Promise.reject("Network error: offline"));
    fireEvent.error(audio());
    await within(detail()).findByText("Playback link expired. Open the recording again.");
    respondWith("session_detail", ({ id }) => ({ id, playback_url: "https://audio.example.test/d.wav" }));
    await selectItem("Voice note from Sam", "Remember to call back.");
    await waitFor(() => expect(audio()).toHaveAttribute("src", "https://audio.example.test/d.wav"));
    expect(within(detail()).queryByText("Playback link expired. Open the recording again.")).not.toBeInTheDocument();
  });

  // A6.DU7: seekable transcript rows have role=button but cannot take focus and ignore Enter and Space.
  it("makes transcript rows reachable and operable from the keyboard when they act as buttons", async () => {
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    await waitFor(() => expect(audio()).not.toBeNull());
    const rows = within(detail()).getAllByRole("button").filter((el) => el.classList.contains("segment"));
    expect(rows.length).toBeGreaterThan(0);
    for (const row of rows) {
      act(() => row.focus());
      expect(row, "a transcript row that acts as a button must take keyboard focus").toHaveFocus();
    }
    // The browser turns Enter and Space on a native <button> into a click (jsdom does not simulate that); any other
    // element that acts as a button has to handle the key itself.
    if (rows[1] instanceof HTMLButtonElement) fireEvent.click(rows[1]);
    else fireEvent.keyDown(rows[1], { key: "Enter", code: "Enter" });
    expect(audio().currentTime, "Enter on a transcript row should seek").toBe(12.4);
  });
});

describe("LibraryView copy", () => {
  const EXPECTED_PLAIN =
    "Me: Hello, this is Sam.\nClient: Hi Sam, thanks for calling back.\nMe: Let us go over the invoice.\nClient: That works for us.";
  const EXPECTED_TIMED =
    "[0:00] Me: Hello, this is Sam.\n[0:12] Client: Hi Sam, thanks for calling back.\n[1:05] Me: Let us go over the invoice.\n[1:02:05] Client: That works for us.";

  it("'Copy text' puts the speaker-labelled transcript on the clipboard", async () => {
    const { notify } = await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    fireEvent.click(within(detail()).getByRole("button", { name: "Copy text" }));
    await waitFor(() => expect(writeText).toHaveBeenCalledTimes(1));
    expect(writeText).toHaveBeenCalledWith(EXPECTED_PLAIN);
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Copied"));
  });

  it("'Copy with timestamps' prefixes every line with [m:ss] / [h:mm:ss]", async () => {
    const { notify } = await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    fireEvent.click(within(detail()).getByRole("button", { name: "Copy with timestamps" }));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith(EXPECTED_TIMED));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Copied with timestamps"));
  });

  it("omits the speaker label for a single-speaker voice note", async () => {
    await renderLibrary();
    await selectItem("Voice note from Sam", "Remember to call back.");
    fireEvent.click(within(detail()).getByRole("button", { name: "Copy text" }));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith("Remember to call back.\nBefore Friday."));
    fireEvent.click(within(detail()).getByRole("button", { name: "Copy with timestamps" }));
    await waitFor(() => expect(writeText).toHaveBeenLastCalledWith("[0:00] Remember to call back.\n[0:04] Before Friday."));
  });

  it("tells the user when the clipboard is refused", async () => {
    writeText.mockImplementationOnce(() => Promise.reject(new DOMException("denied", "NotAllowedError")));
    const { notify } = await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    fireEvent.click(within(detail()).getByRole("button", { name: "Copy text" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Could not access the clipboard", true));
  });

  it("tells the user when the clipboard API does not exist", async () => {
    Object.defineProperty(navigator, "clipboard", { configurable: true, value: undefined });
    const { notify } = await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    fireEvent.click(within(detail()).getByRole("button", { name: "Copy with timestamps" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Could not access the clipboard", true));
  });
});

describe("LibraryView delete", () => {
  it("asks for confirmation and does nothing when declined", async () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
    await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    fireEvent.click(within(detail()).getByRole("button", { name: "Delete" }));
    expect(confirm).toHaveBeenCalledWith("Delete this recording and transcript on every device? This cannot be undone.");
    expect(callsOf("delete_session")).toHaveLength(0);
    expect(within(detail()).getByText("Hello, this is Sam.")).toBeInTheDocument();
  });

  it("deletes the session, reloads the library, clears the selection and confirms", async () => {
    vi.spyOn(window, "confirm").mockReturnValue(true);
    const { notify } = await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    respond("library_list", ITEMS.filter((i) => i.id !== "a"));
    fireEvent.click(within(detail()).getByRole("button", { name: "Delete" }));
    await waitFor(() => expect(callsOf("delete_session")).toEqual([{ id: "a" }]));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Deleted"));
    expect(screen.queryByText("Zoom with Acme")).not.toBeInTheDocument();
    expect(within(detail()).getByText("Select a recording to read its transcript.")).toBeInTheDocument();
  });

  it("reports a failed delete and keeps the recording", async () => {
    vi.spyOn(window, "confirm").mockReturnValue(true);
    const { notify } = await renderLibrary();
    await selectItem("Zoom with Acme", "Hello, this is Sam.");
    fail("delete_session", "Server returned 403: This device may not delete sessions.");
    fireEvent.click(within(detail()).getByRole("button", { name: "Delete" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Server returned 403: This device may not delete sessions.", true));
    expect(within(list()).getByText("Zoom with Acme")).toBeInTheDocument();
    expect(within(detail()).getByText("Hello, this is Sam.")).toBeInTheDocument();
  });
});

describe("LibraryView with a realistic library", () => {
  it("renders 120 recordings and filters them quickly", async () => {
    const many: LibraryItem[] = Array.from({ length: 120 }, (_, i) =>
      makeItem(`m${i}`, {
        title: `Call ${String(i).padStart(3, "0")}`,
        source_device: (["phone", "desktop", "web"] as const)[i % 3],
        started_at: new Date(2026, 9, 24 - (i % 40), 8 + (i % 10)).toISOString(),
        duration_ms: 60_000 + i * 1_000,
      }),
    );
    await renderLibrary(many);
    expect(titlesInList()).toHaveLength(120);
    fireEvent.change(screen.getByLabelText("Search recordings"), { target: { value: "Call 07" } });
    expect(titlesInList()).toHaveLength(10);
    fireEvent.click(screen.getByRole("button", { name: "Phone" }));
    expect([...titlesInList()].sort()).toEqual(["Call 072", "Call 075", "Call 078"]);
    expect(titlesInList().every((t) => t?.startsWith("Call 07"))).toBe(true);
  });

  it("handles a very long transcript without dropping segments", async () => {
    const segments: Segment[] = Array.from({ length: 600 }, (_, i) => ({
      channel: i % 2 === 0 ? 1 : 2,
      speaker: i % 2 === 0 ? "Me" : "Client",
      start_ms: i * 5_000,
      end_ms: i * 5_000 + 4_000,
      text: `Sentence number ${i}.`,
    }));
    TRANSCRIPTS["tr-long"] = makeTranscript({ id: "tr-long", segments });
    await renderLibrary([makeItem("long", { title: "Long call", transcript: { final_status: "completed", final_transcript_id: "tr-long", live_available: false, preview: null } })]);
    await selectItem("Long call", "Sentence number 599.");
    expect(detail().querySelectorAll(".segment")).toHaveLength(600);
    fireEvent.click(within(detail()).getByRole("button", { name: "Copy with timestamps" }));
    await waitFor(() => expect(writeText).toHaveBeenCalledTimes(1));
    const copied = writeText.mock.calls[0][0] as string;
    expect(copied.split("\n")).toHaveLength(600);
    expect(copied.split("\n")[599]).toBe("[49:55] Client: Sentence number 599.");
  });
});
