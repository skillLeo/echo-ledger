import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import type { BinItem, LibraryItem } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeScreen, makeTranscript } from "../../test/fixtures";
import { answerConfirm } from "../../test/confirm";
import { forgetPictures } from "../../lib/screens";
import { BIN_HOURS, binHours, binSentence, dayGroups, isUntranscribed, rowDuration, rowModel, rowWhen, TIME_ONLY_GROUPS, viewCounts, viewItems } from "./model";
import { LibraryV2 } from "./LibraryV2";
import { NEEDS_TRANSCRIPTION_KEY_TEXT } from "./organise";
import { OPEN_SECTION_EVENT } from "../../lib/entitlements";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import type { LibraryScope } from "./scope";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 17, 15, 0);
/** The rows' own buttons (each row also has its ⋮). */
const rowButtons = (list: HTMLElement) => [...list.querySelectorAll<HTMLElement>("button[data-row-id]")];
const at = (day: number, hour: number, minute = 0) => new Date(2026, 10, day, hour, minute).toISOString();

const ITEMS: LibraryItem[] = [
  makeItem("bilal", {
    title: "Bilal Qureshi · Qureshi Textiles", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: at(17, 11, 42), duration_ms: 1_104_000, screen_count: 3,
    transcript: { final_status: "completed", final_transcript_id: "tr-bilal", live_available: false, preview: "Payment terms for the March shipment" },
  }),
  makeItem("voice", {
    title: "Voice note", source_app: "voice_note", source_app_label: "Voice note", started_at: at(17, 9, 18), duration_ms: 134_000, status: "uploaded",
    transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null },
  }),
  makeItem("usman", {
    title: "Usman Chaudhry · Chaudhry Steel", source_app: "phone_call", source_app_label: "Phone call", started_at: at(17, 8, 47), duration_ms: 452_000, status: "failed",
    transcript: { final_status: "failed", final_transcript_id: null, live_available: false, preview: null },
  }),
  makeItem("habib", {
    title: "Audit kick-off with Habib & Co.", source_app: "zoom", source_app_label: "Zoom", started_at: at(16, 16, 30), duration_ms: 2_282_000,
    transcript: { final_status: "completed", final_transcript_id: "tr-habib", live_available: false, preview: "Quarterly sales tax return" },
  }),
  makeItem("gone", { title: "Deleted call", deleted: true, started_at: at(16, 10) }),
];

const BIN: BinItem[] = [
  { id: "old", title: "Sadia Hassan · Zamzam Travels", client: null, started_at: at(10, 9, 40), duration_ms: 348_000, deleted_at: at(16, 12), erase_at: at(18, 12) },
];

describe("the Library's rules", () => {
  it("saved views: All, Today and Untranscribed, searched, newest first, without deleted ones", () => {
    expect(viewItems(ITEMS, "all", "", NOW).map((i) => i.id)).toEqual(["bilal", "voice", "usman", "habib"]);
    expect(viewItems(ITEMS, "today", "", NOW).map((i) => i.id)).toEqual(["bilal", "voice", "usman"]);
    expect(viewItems(ITEMS, "untranscribed", "", NOW).map((i) => i.id)).toEqual(["voice", "usman"]);
    expect(viewItems(ITEMS, "all", "march shipment", NOW).map((i) => i.id)).toEqual(["bilal"]);
    expect(viewCounts(ITEMS, NOW)).toMatchObject({ all: 4, today: 3, untranscribed: 2 });
    expect(isUntranscribed(ITEMS[0])).toBe(false);
  });

  it("groups by day", () => {
    expect(dayGroups(viewItems(ITEMS, "all", "", NOW), NOW).map((g) => [g.label, g.items.length])).toEqual([["Today", 3], ["Yesterday", 1]]);
  });

  it("a row: who, the first words or the state in the glossary's words, when, and how long (UX-06)", () => {
    expect(rowModel(ITEMS[0], NOW)).toMatchObject({ title: "Bilal Qureshi · Qureshi Textiles", meta: "Payment terms for the March shipment", state: null, when: "11:42 am", duration: "18m 24s", app: "whatsapp", kind: "call" });
    expect(rowModel(ITEMS[0], NOW).screens).toBe(3);
    // docs/PLAN_V2_1_POLISH.md section 1: Audio only, Transcribing…, Failed · Retry (the only red), Waiting to upload.
    expect(rowModel(ITEMS[1], NOW)).toMatchObject({ title: "Voice note", meta: "Audio only", state: { key: "audio_only", tone: "quiet" }, screens: 0, kind: "voice_note" });
    expect(rowModel(ITEMS[2], NOW)).toMatchObject({ meta: "Failed · Retry", state: { key: "failed", tone: "danger" } });
    const transcribing = makeItem("t", { status: "processing", transcript: { final_status: "processing", final_transcript_id: null, live_available: false, preview: null } });
    expect(rowModel(transcribing, NOW)).toMatchObject({ meta: "Transcribing…", state: { key: "transcribing" }, kind: "meeting" });
    expect(rowModel(makeItem("u", { status: "uploading", transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } }), NOW)).toMatchObject({ meta: "Waiting to upload", state: { key: "waiting_to_upload", tone: "quiet" } });
    // A call still waiting for the person's choice (the session is `recording`) is Waiting, not Audio only (contract 2.0.0-r).
    const deciding = makeItem("w", { source_app: "phone_call", status: "recording", transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } });
    expect(rowModel(deciding, NOW)).toMatchObject({ meta: "Waiting", state: { key: "waiting", tone: "quiet" } });
    // The server's state, when it sent one, is the row's.
    expect(rowModel({ ...deciding, facets: { state: "audio_only" } } as typeof deciding, NOW)).toMatchObject({ state: { key: "audio_only" } });
    // Never "Untranscribed" or "Not transcribed", and no chips.
    for (const item of ITEMS.slice(0, 4)) expect(JSON.stringify(rowModel(item, NOW))).not.toMatch(/untranscribed|not transcribed/i);
  });

  it("contract 2.0.0-z: a call still being recorded reads Waiting to upload; once it is over, Waiting", () => {
    const ago = (ms: number) => new Date(NOW.getTime() - ms).toISOString();
    const live = makeItem("live", { source_app: "zoom", status: "recording", started_at: ago(3 * 60_000), transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } });
    expect(rowModel(live, NOW)).toMatchObject({ meta: "Waiting to upload", state: { key: "waiting_to_upload", tone: "quiet" } });
    expect(rowModel({ ...live, ended_at: ago(60_000) }, NOW)).toMatchObject({ meta: "Waiting", state: { key: "waiting", tone: "quiet" } });
    // Without an end, after more than 4 hours it counts as over; the server's Waiting wins, and its Waiting to upload,
    // synced while the call ran, doesn't stop the clock.
    expect(rowModel({ ...live, started_at: ago(4 * 3_600_000 + 1000) }, NOW)).toMatchObject({ meta: "Waiting" });
    expect(rowModel({ ...live, facets: { state: "waiting" } }, NOW)).toMatchObject({ meta: "Waiting" });
    expect(rowModel({ ...live, facets: { state: "waiting_to_upload" } }, NOW)).toMatchObject({ meta: "Waiting to upload" });
    expect(rowModel({ ...live, started_at: ago(4 * 3_600_000 + 1000), facets: { state: "waiting_to_upload" } }, NOW)).toMatchObject({ meta: "Waiting", state: { key: "waiting" } });
  });

  it("when: the time today, Yesterday, then the date (its year when another); only the time inside a date group", () => {
    expect(rowModel(ITEMS[3], NOW).when).toBe("Yesterday");
    expect(rowModel(ITEMS[3], NOW, undefined, true).when).toBe("4:30 pm");
    expect(rowWhen(at(12, 9), NOW)).toBe("12 Nov");
    expect(rowWhen(new Date(2025, 10, 12, 9).toISOString(), NOW)).toBe("12 Nov 2025");
    expect(TIME_ONLY_GROUPS).toEqual(new Set(["date:Today", "date:Yesterday", "date:This week"]));
  });

  it("how long: 44s, 2m 38s, 1h 04m, never a clock that reads like the time", () => {
    expect([44_000, 158_000, 3_840_000, 1_104_000, 0].map((ms) => rowDuration(ms))).toEqual(["44s", "2m 38s", "1h 04m", "18m 24s", ""]);
  });

  it("the name without Call with, an untitled call is an unknown caller, a voice note takes its first words", () => {
    expect(rowModel(makeItem("c", { title: "Call with +92 300 1234567", source_app: "whatsapp" }), NOW)).toMatchObject({ title: "+92 300 1234567", avatar: { initials: null, icon: "person" } });
    expect(rowModel(makeItem("o", { title: "Call with Hammad Bhai Uol", source_app: "whatsapp" }), NOW)).toMatchObject({ title: "Hammad Bhai Uol", avatar: { initials: "HU" } });
    expect(rowModel(makeItem("x", { title: "", source_app: "phone_call" }), NOW).title).toBe("Unknown caller");
    const said = makeItem("v", { title: "Voice note", source_app: "voice_note",
      transcript: { final_status: "completed", final_transcript_id: "t", live_available: false, preview: "Call the customs agent about the Port Qasim container today" } });
    expect(rowModel(said, NOW)).toMatchObject({ title: "Call the customs agent about the…", avatar: { icon: "mic" } });
    expect(rowModel(ITEMS[3], NOW).avatar).toMatchObject({ initials: null, icon: "calendar" });
  });
});

describe("Trash's length", () => {
  it("is the server's: erase_at less deleted_at, else 48 hours, in the classic Library's words", () => {
    expect(BIN_HOURS).toBe(48);
    expect(binHours([])).toBe(48);
    expect(binHours(BIN)).toBe(48);
    expect(binHours([{ deleted_at: at(16, 12), erase_at: at(17, 12) }])).toBe(24);
    expect(binSentence(48)).toBe("Deleted recordings stay here for 48 hours, then their audio and transcripts are erased.");
  });
});

describe("LibraryV2", () => {
  beforeEach(() => {
    resetTauriMock();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("bin_list", { data: BIN });
  });

  // The sidebar chooses what the Library shows (LibraryNav): `go` does what pressing a sidebar row does.
  let shown: Parameters<typeof LibraryV2>[0] = {};
  let again: (ui: React.ReactElement) => void = () => undefined;
  let seq = 0;
  const show = async (props: Parameters<typeof LibraryV2>[0] = {}) => {
    shown = props;
    again = render(<LibraryV2 {...props} />).rerender;
    await act(flushAsync);
  };
  const go = async (scope: LibraryScope) => {
    again(<LibraryV2 {...shown} scopeRequest={{ scope, seq: ++seq }} />);
    await act(flushAsync);
  };

  it("shows the recordings as rows under day headings, from the core's list", async () => {
    await show();
    const list = screen.getByRole("list", { name: "Recordings" });
    const row = within(list).getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" });
    expect(row).toHaveAccessibleDescription(/^3 screens Payment terms for the March shipment.*11:42 am.*18m 24s/);
    // The screens mark only on a recording with screens.
    expect(within(list).getByRole("button", { name: "Voice note" })).not.toHaveAccessibleDescription(/screen/);
    expect(within(list).getByText("Today")).toBeInTheDocument();
    expect(within(list).getByText("Yesterday")).toBeInTheDocument();
    expect(within(list).queryByText("Deleted call")).toBeNull();
    expect(callsOf("library_refresh")).toHaveLength(1);
  });

  it("Audio only from the sidebar (Not transcribed merged into it), and Date: Today from the filters, with their counts", async () => {
    await show();
    // Audio only: no transcript and none on its way; the failed one is in Needs you (facets.ts).
    await go({ kind: "view", view: "audio_only" });
    expect(screen.getByRole("heading", { level: 1, name: /^Audio only/ })).toHaveTextContent("Audio only1");
    expect(rowButtons(screen.getByRole("list", { name: "Recordings" })).map((b) => b.dataset.rowId)).toEqual(["voice"]);
    await go({ kind: "view", view: "needs" });
    expect(rowButtons(screen.getByRole("list", { name: "Recordings" })).map((b) => b.dataset.rowId)).toEqual(["usman"]);
    await go({ kind: "view", view: "all" });
    fireEvent.click(screen.getByRole("button", { name: /^Filters/ }));
    const date = within(screen.getByRole("region", { name: "Date" }));
    expect(date.getByRole("radio", { name: /^Today/ }).closest("label")).toHaveTextContent("Today3");
    fireEvent.click(date.getByRole("radio", { name: /^Today/ }));
    await act(async () => {
      await new Promise((r) => setTimeout(r, 200));
    });
    expect(rowButtons(screen.getByRole("list", { name: "Recordings" }))).toHaveLength(3);
  });

  it("loading, then an error with its one fix", async () => {
    resetTauriMock();
    fail("library_list", "The server is out of reach");
    fail("library_refresh", "The server is out of reach");
    render(<LibraryV2 />);
    expect(screen.getByRole("status", { busy: true })).toHaveTextContent("Loading recordings");
    await act(flushAsync);
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("The library didn't load");
    respond("library_list", ITEMS);
    fireEvent.click(within(alert).getByRole("button", { name: "Try again" }));
    await act(flushAsync);
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
  });

  it("an empty library says what goes there", async () => {
    respond("library_list", []);
    respond("library_refresh", []);
    await show({ onImport: () => undefined });
    expect(screen.getByRole("region", { name: "No recordings yet" })).toBeInTheDocument();
  });

  it("reloads when the core says the library changed", async () => {
    await show();
    respond("library_list", ITEMS.slice(0, 1));
    await act(() => emitEvent("library:updated"));
    await act(flushAsync);
    expect(rowButtons(screen.getByRole("list", { name: "Recordings" }))).toHaveLength(1);
  });

  describe("its screens (meeting assets)", () => {
    const SCREENS = [
      makeScreen(1, 4_000, { description: "The proforma invoice", ocr_text: "PROFORMA 40,000 m", file_name: "S01_0-04_the-proforma-invoice.webp", said: "Me: Here is the proforma.\nBilal Qureshi: Forty thousand metres." }),
      makeScreen(2, 7_000, { description: "The bank's LC form" }),
      makeScreen(3, 60_000),
    ];
    const open = async () => {
      forgetPictures();
      respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", speaker_names: { Client: "Bilal Qureshi" } });
      respond("transcript_detail", makeTranscript({
        id: "tr-bilal",
        segments: [
          { channel: 1, speaker: "Me", start_ms: 0, end_ms: 5000, text: "Assalam o alaikum, Bilal sahib." },
          { channel: 2, speaker: "Client", start_ms: 6000, end_ms: 9000, text: "Walaikum assalam. The fabric is ready." },
        ],
      }));
      respond("session_screens", { data: SCREENS });
      respondWith("screen_image", ({ url }) => `data:image/webp;base64,${btoa(String(url))}`);
      await show();
      fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" }));
      await act(flushAsync);
    };

    it("a Screens strip under the title, and each screen after the line of its moment", async () => {
      await open();
      expect(callsOf("session_screens")).toEqual([{ id: "bilal" }]);
      const strip = screen.getByRole("region", { name: "3 screens" });
      // "3 screens" keeps its order in a right-to-left window.
      expect(screen.getByRole("heading", { name: "3 screens" })).toHaveAttribute("dir", "auto");
      expect(within(strip).getAllByRole("button").map((b) => b.getAttribute("aria-label"))).toEqual([
        "Screen 1 · 0:04: The proforma invoice", "Screen 2 · 0:07: The bank's LC form", "Screen 3 · 1:00",
      ]);
      const lines = within(screen.getByRole("list", { name: "Transcript" })).getAllByRole("listitem").filter((li) => li.classList.contains("el-segment"));
      // Each line starts with its "Play from" time, then the screens of its moment.
      expect(within(lines[0]).getAllByRole("button").map((b) => b.getAttribute("aria-label")).slice(1)).toEqual(["Screen 1 · 0:04: The proforma invoice"]);
      expect(within(lines[1]).getAllByRole("button").map((b) => b.getAttribute("aria-label")).slice(1)).toEqual(["Screen 2 · 0:07: The bank's LC form", "Screen 3 · 1:00"]);
    });

    it("the viewer: what was said around it, the text on it, its file name, the next one, Play from here", async () => {
      await open();
      fireEvent.click(within(screen.getByRole("region", { name: "3 screens" })).getByRole("button", { name: /^Screen 1/ }));
      await act(flushAsync);
      const viewer = screen.getByRole("dialog", { name: "Screen 1 · 0:04" });
      expect(viewer).toHaveAccessibleDescription("The proforma invoice");
      expect(within(viewer).getByRole("img", { name: "The proforma invoice" })).toBeInTheDocument();
      const said = within(viewer).getByRole("region", { name: "What was said" });
      expect(said).toHaveTextContent("Me: Here is the proforma.");
      expect(said).toHaveTextContent("Bilal Qureshi: Forty thousand metres.");
      expect(within(viewer).getByText("PROFORMA 40,000 m")).toBeInTheDocument();
      expect(viewer).toHaveTextContent("S01_0-04_the-proforma-invoice.webp");
      expect(within(viewer).getByRole("button", { name: "Previous screen" })).toBeDisabled();

      // A server without `said` yet: worked out from the transcript (within 15 s), with the speakers' names.
      fireEvent.click(within(viewer).getByRole("button", { name: "Next screen" }));
      const second = screen.getByRole("dialog", { name: "Screen 2 · 0:07" });
      expect(within(second).getByRole("region", { name: "What was said" })).toHaveTextContent("Me: Assalam o alaikum, Bilal sahib.Bilal Qureshi: Walaikum assalam. The fabric is ready.");
      // No file name until the server sends one: never a name the meeting folder won't use.
      expect(second).not.toHaveTextContent("File name");
      fireEvent.keyDown(within(second).getByRole("region", { name: "What was said" }), { key: "ArrowRight" });
      const third = screen.getByRole("dialog", { name: "Screen 3 · 1:00" });
      expect(within(third).getByRole("region", { name: "What was said" })).toHaveTextContent("Nothing was said within 15 seconds of this screen.");

      const play = vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
      fireEvent.click(within(third).getByRole("button", { name: "Play from here" }));
      expect(screen.queryByRole("dialog")).toBeNull();
      expect(play).toHaveBeenCalled();
      expect(screen.getByRole("slider")).toHaveValue("60");
      play.mockRestore();
    });

    it("Delete screen asks first, then reads the screens again", async () => {
      await open();
      respond("screen_delete", null);
      fireEvent.click(within(screen.getByRole("region", { name: "3 screens" })).getByRole("button", { name: /^Screen 2/ }));
      const asked = answerConfirm();
      asked.mockReturnValue(false);
      fireEvent.click(screen.getByRole("button", { name: "Delete screen" }));
      await act(flushAsync);
      expect(asked).toHaveBeenCalledWith("Delete screen 2? The recording and its transcript stay.");
      expect(callsOf("screen_delete")).toHaveLength(0);
      asked.mockReturnValue(true);
      respond("session_screens", { data: [SCREENS[0], SCREENS[2]] });
      fireEvent.click(screen.getByRole("button", { name: "Delete screen" }));
      await act(flushAsync);
      expect(callsOf("screen_delete")).toEqual([{ sessionId: "bilal", screen: "sc2" }]);
      expect(screen.queryByRole("dialog")).toBeNull();
      expect(screen.getByRole("region", { name: "2 screens" })).toBeInTheDocument();
      expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Screen 2 deleted");
    });

    it("a recording without screens has no strip and doesn't ask for them", async () => {
      respond("session_detail", { playback_url: null });
      respond("transcript_detail", makeTranscript({ id: "tr-1" }));
      await show();
      fireEvent.click(screen.getByRole("button", { name: "Voice note" }));
      await act(flushAsync);
      expect(callsOf("session_screens")).toHaveLength(0);
      expect(screen.queryByRole("region", { name: /screens?$/ })).toBeNull();
    });
  });

  it("opens a recording: the player, the transcript with speakers, and back", async () => {
    respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", speaker_names: { Client: "Bilal Qureshi" } });
    respond("transcript_detail", makeTranscript({
      id: "tr-bilal",
      segments: [
        { channel: 1, speaker: "Me", start_ms: 0, end_ms: 5000, text: "Assalam o alaikum, Bilal sahib." },
        { channel: 2, speaker: "Client", start_ms: 6000, end_ms: 9000, text: "Walaikum assalam. The fabric is ready." },
      ],
    }));
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" }));
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "Bilal Qureshi · Qureshi Textiles" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Play" })).toBeInTheDocument();
    const transcript = screen.getByRole("list", { name: "Transcript" });
    expect(within(transcript).getByText("Bilal Qureshi")).toBeInTheDocument();
    expect(within(transcript).getByText("Walaikum assalam. The fabric is ready.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Library" }));
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
  });

  it("an untranscribed recording offers Transcribe, with its minutes first", async () => {
    respond("session_detail", { playback_url: null });
    respond("sessions_transcribe_estimate", { minutes: 3, minutes_left: 120, items: [] });
    respond("sessions_transcribe", { started: ["voice"], skipped: [], minutes: 3 });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Voice note" }));
    await act(flushAsync);
    // The glossary's word, grey (no warning chip): never "Untranscribed" or "Not transcribed yet".
    const page = document.querySelector(".el-page__meta") as HTMLElement;
    expect(within(page).getByText("Audio only")).not.toHaveClass("el-badge--warning");
    expect(screen.getByRole("heading", { name: "Audio only" })).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent(/untranscribed|not transcribed/i);
    fireEvent.click(screen.getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: "Transcribe this recording?" });
    expect(dialog).toHaveAccessibleDescription("About 3 minutes of the 120 minutes left this month.");
    fireEvent.click(within(dialog).getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    expect(callsOf("sessions_transcribe")).toEqual([{ ids: ["voice"] }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Transcribing 1 recording");
  });

  it("Transcribe on a plan that needs the person's own key says so and offers Add my Groq key", async () => {
    respond("session_detail", { playback_url: null });
    fail("sessions_transcribe_estimate", NEEDS_TRANSCRIPTION_KEY_TEXT);
    const opened: string[] = [];
    const listen = (e: Event) => opened.push((e as CustomEvent<string>).detail);
    window.addEventListener(OPEN_SECTION_EVENT, listen);
    try {
      await show();
      fireEvent.click(screen.getByRole("button", { name: "Voice note" }));
      await act(flushAsync);
      fireEvent.click(screen.getByRole("button", { name: "Transcribe" }));
      await act(flushAsync);
      const dialog = screen.getByRole("dialog", { name: "Transcribe this recording?" });
      expect(dialog).toHaveAccessibleDescription(NEEDS_TRANSCRIPTION_KEY_TEXT);
      expect(within(dialog).queryByRole("button", { name: "Transcribe" })).toBeNull();
      fireEvent.click(within(dialog).getByRole("button", { name: "Add my Groq key" }));
      expect(opened).toEqual(["transcription-key"]);
      expect(screen.queryByRole("dialog", { name: "Transcribe this recording?" })).toBeNull();
      expect(callsOf("sessions_transcribe")).toHaveLength(0);
    } finally {
      window.removeEventListener(OPEN_SECTION_EVENT, listen);
    }
  });

  it("the words for a missing own key are the core's (error.rs CODE_TEXT), so the dialog can never miss them", () => {
    const rust = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "../../../src-tauri/src/error.rs"), "utf8");
    const entry = rust.match(/\("needs_transcription_key",\s*"([^"]+)"\)/);
    expect(entry?.[1]).toBe(NEEDS_TRANSCRIPTION_KEY_TEXT);
  });

  it("Delete moves the recording to Trash and Undo brings it back", async () => {
    respond("session_detail", { playback_url: null });
    respond("transcript_detail", makeTranscript({ id: "tr-bilal" }));
    respond("delete_session", null);
    respondWith("session_restore", () => ({ data: ITEMS[0] }));
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" }));
    await act(flushAsync);
    respond("library_list", ITEMS.slice(1));
    fireEvent.click(screen.getByRole("button", { name: "Recording actions" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Delete" }));
    await act(flushAsync);
    expect(callsOf("delete_session")).toEqual([{ id: "bilal" }]);
    // The deleted recording's place goes to the next one in the list.
    expect(screen.getByRole("button", { name: "Voice note" })).toHaveFocus();
    const toast = screen.getByRole("status", { name: "Notifications" });
    expect(toast).toHaveTextContent('"Bilal Qureshi · Qureshi Textiles" moved to Trash');
    expect(screen.queryByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" })).toBeNull();
    respond("library_list", ITEMS);
    // From the keyboard: focus on Undo, which leaves with the toast.
    act(() => within(toast).getByRole("button", { name: "Undo" }).focus());
    fireEvent.click(within(toast).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("session_restore")).toEqual([{ id: "bilal" }]);
    expect(screen.getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" })).toBeInTheDocument();
    // Keyboard focus comes back to the recording, never lost on the page.
    expect(screen.getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" })).toHaveFocus();
  });

  it("moves focus once after Delete: a later reload never takes it from where it was put", async () => {
    respond("session_detail", { playback_url: null });
    respond("transcript_detail", makeTranscript({ id: "tr-bilal" }));
    respond("delete_session", null);
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" }));
    await act(flushAsync);
    respond("library_list", ITEMS.slice(1));
    fireEvent.click(screen.getByRole("button", { name: "Recording actions" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Delete" }));
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Voice note" })).toHaveFocus();
    const search = screen.getByRole("searchbox");
    act(() => search.focus());
    await act(() => emitEvent("library:updated"));
    await act(flushAsync);
    expect(search).toHaveFocus();
    // Even with focus lost to the page, a reload later doesn't move it.
    act(() => search.blur());
    await act(() => emitEvent("library:updated"));
    await act(flushAsync);
    expect(document.body).toHaveFocus();
  });

  it("after a mouse Undo (focus still on a row), focus stays where it is", async () => {
    respond("session_detail", { playback_url: null });
    respond("transcript_detail", makeTranscript({ id: "tr-bilal" }));
    respond("delete_session", null);
    respondWith("session_restore", () => ({ data: ITEMS[0] }));
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" }));
    await act(flushAsync);
    respond("library_list", ITEMS.slice(1));
    fireEvent.click(screen.getByRole("button", { name: "Recording actions" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Delete" }));
    await act(flushAsync);
    respond("library_list", ITEMS);
    fireEvent.click(within(screen.getByRole("status", { name: "Notifications" })).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Voice note" })).toHaveFocus();
  });

  it("Trash lists deleted recordings to restore or delete forever after a confirmation", async () => {
    respond("session_restore", { data: ITEMS[0] });
    respond("bin_erase", null);
    await show();
    // Trash is at the sidebar's foot now.
    await go({ kind: "trash" });
    expect(screen.getByRole("heading", { level: 1, name: /^Trash/ })).toBeInTheDocument();
    const list = screen.getByRole("list", { name: "Deleted recordings" });
    expect(within(list).getByText("Sadia Hassan · Zamzam Travels")).toBeInTheDocument();
    expect(screen.getByText("Deleted recordings stay here for 48 hours, then their audio and transcripts are erased.")).toBeInTheDocument();
    expect(within(list).getByText(/Deleted 16 Nov · erased in 21 hours/)).toBeInTheDocument();
    fireEvent.click(within(list).getByRole("button", { name: 'Delete "Sadia Hassan · Zamzam Travels" forever' }));
    const dialog = screen.getByRole("alertdialog", { name: "Delete forever?" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Delete forever" }));
    await act(flushAsync);
    expect(callsOf("bin_erase")).toEqual([{ id: "old" }]);
    const empty = screen.getByRole("region", { name: "Trash is empty" });
    expect(empty).toHaveTextContent("Deleted recordings stay here for 48 hours, then their audio and transcripts are erased.");
    expect(document.body).not.toHaveTextContent(/30 days/);
    // All, from the sidebar, goes back to the recordings.
    await go({ kind: "view", view: "all" });
    expect(screen.getByRole("heading", { level: 1, name: /^All/ })).toBeInTheDocument();
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
  });

  describe("parity rows that had no test (docs/v2/audit/parity.csv)", () => {
    it("Import (an empty Library's; the sidebar's ● Record ▾ has it too), clearing a search by its button and by Esc, and a search that matches nothing (DT-LIB-005, 008, 009, 138)", async () => {
      const onImport = vi.fn();
      respond("library_list", []);
      respond("library_refresh", []);
      await show({ onImport });
      fireEvent.click(within(screen.getByRole("region", { name: "No recordings yet" })).getByRole("button", { name: "Import" }));
      expect(onImport).toHaveBeenCalled();
      cleanup();
      respond("library_list", ITEMS);
      respond("library_refresh", ITEMS);
      await show({ onImport });
      const search = screen.getByRole("searchbox", { name: "Search recordings" });
      fireEvent.change(search, { target: { value: "zzz nothing" } });
      const none = screen.getByRole("region", { name: "No recordings match" });
      fireEvent.click(within(none).getByRole("button", { name: "Clear search" }));
      expect(search).toHaveValue("");
      fireEvent.change(search, { target: { value: "Usman" } });
      fireEvent.click(screen.getAllByRole("button", { name: "Clear search" })[0]);
      expect(search).toHaveValue("");
      fireEvent.change(search, { target: { value: "Bilal" } });
      fireEvent.keyDown(search, { key: "Escape" });
      expect(search).toHaveValue("");
    });

    it("a recording where nothing was heard says No audio in its row and Nothing was heard when opened (DT-LIB-072, DT-REC-055)", async () => {
      const quiet = makeItem("quiet", { title: "Quiet call", started_at: at(17, 10), transcript: { final_status: "failed", final_transcript_id: null, live_available: false, preview: null, final_error_code: "no_audio" } });
      respond("library_list", [quiet]);
      respond("library_refresh", [quiet]);
      respond("session_detail", { playback_url: null });
      await show();
      expect(screen.getByRole("list", { name: "Recordings" })).toHaveTextContent("No audio");
      // Grey like the other states: only Failed · Retry is red on a row (PLAN_V2_1_POLISH section 1).
      const state = screen.getByRole("list", { name: "Recordings" }).querySelector(".el-rec-state[data-state=no_audio]");
      expect(state).toHaveClass("el-rec-state--quiet");
      expect(state).not.toHaveClass("el-rec-state--danger");
      expect(rowModel(quiet, NOW).state).toMatchObject({ key: "no_audio", tone: "quiet" });
      fireEvent.click(screen.getByRole("button", { name: "Quiet call" }));
      await act(flushAsync);
      expect(screen.getByRole("region", { name: "Nothing was heard" })).toBeInTheDocument();
    });

    it("Trash: a list that didn't load says so with Try again; All goes back to the recordings (DT-LIB-112, 113)", async () => {
      fail("bin_list", "The server is out of reach");
      await show();
      await go({ kind: "trash" });
      expect(screen.getByRole("alert")).toHaveTextContent("Trash didn't load");
      expect(within(screen.getByRole("alert")).getByRole("button", { name: "Try again" })).toBeInTheDocument();
      await go({ kind: "view", view: "all" });
      expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
    });

    it("a recording: loading, a transcript that didn't load with Try again, Save audio, time and length, and its Details (DT-REC-013, 062, 063, 123, 137 to 142)", async () => {
      const bilal = { ...ITEMS[0], client: { id: "c1", name: "Qureshi Textiles" }, language_chip: "Urdu", device: { id: "d1", name: "Work laptop" } } as unknown as LibraryItem;
      respond("library_list", [bilal]);
      respond("library_refresh", [bilal]);
      respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a" });
      let answer: (v: unknown) => void = () => undefined;
      respondWith("transcript_detail", () => new Promise((_, no) => { answer = no; }));
      respond("save_audio", "/Users/a/Downloads/call.m4a");
      await show();
      fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" }));
      await act(flushAsync);
      expect(screen.getByText("Loading the transcript")).toBeInTheDocument();
      await act(async () => answer("The server is out of reach"));
      await act(flushAsync);
      expect(screen.getByRole("alert", { name: "The transcript didn't load" })).toBeInTheDocument();
      expect(screen.getByText(/0:00 \/ 18:24/)).toBeInTheDocument();
      fireEvent.click(screen.getByRole("button", { name: "Recording actions" }));
      fireEvent.click(screen.getByRole("menuitem", { name: "Save audio" }));
      await act(flushAsync);
      expect(callsOf("save_audio")).toEqual([{ id: "bilal" }]);
      fireEvent.click(screen.getByRole("tab", { name: "Details" }));
      const details = document.querySelector(".el-details") as HTMLElement;
      for (const [term, value] of [["Recorded", /./], ["Length", "18:24"], ["Source", "WhatsApp"], ["Device", "Work laptop"], ["Project", "Qureshi Textiles"], ["Language", "Urdu"]] as const) {
        const dt = within(details).getByText(term);
        expect(dt.nextElementSibling?.textContent ?? "", term).toMatch(value);
      }
    });
  });

  it("Restore from Trash says so and offers to open it", async () => {
    respond("session_restore", { data: ITEMS[0] });
    await show();
    await go({ kind: "trash" });
    fireEvent.click(screen.getByRole("button", { name: "Restore" }));
    await act(flushAsync);
    expect(callsOf("session_restore")).toEqual([{ id: "old" }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent('"Sadia Hassan · Zamzam Travels" restored');
  });
});
