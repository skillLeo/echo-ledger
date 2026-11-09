// Screen moments on the recording page (since v1.5): thumbnails between the transcript lines at their moments, the
// viewer with previous / next and Play from here, the gallery, Copy for AI with the screens, and Export → Meeting pack.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { LibraryView } from "./LibraryView";
import type { LibraryItem, ScreenMoment, TranscriptDetail } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeScreen, makeTranscript, anyTarget } from "../../test/fixtures";
import { forgetPictures } from "../../lib/screens";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const done = { final_status: "completed", final_transcript_id: "tr-a", live_available: false, preview: "Let me share" };
const SEGMENTS = [
  { channel: 1, speaker: "Me", start_ms: 10_000, end_ms: 14_000, text: "Let me share my screen." },
  { channel: 2, speaker: "Client", start_ms: 60_000, end_ms: 64_000, text: "Look at this page, the Pay button." },
  { channel: 1, speaker: "Me", start_ms: 872_000, end_ms: 876_000, text: "And here is the invoice." },
];
let screens: ScreenMoment[] = [];
let transcript: TranscriptDetail;
const writeText = vi.fn((_text: string) => Promise.resolve());
const play = vi.fn(() => Promise.resolve());
let times = new WeakMap<HTMLMediaElement, number>();

async function openRecording(item: LibraryItem) {
  respond("library_list", [item]);
  respond("library_refresh", [item]);
  respondWith("transcript_detail", () => transcript);
  respondWith("session_detail", ({ id }) => ({ id, playback_url: "https://audio.example.test/a.wav" }));
  respond("list_clients", { data: [] });
  respondWith("session_screens", () => ({ data: screens }));
  respondWith("screen_image", ({ url }) => `data:image/webp;base64,${btoa(String(url))}`);
  const notify = vi.fn();
  render(<LibraryView notify={notify} />);
  await flushAsync();
  fireEvent.click(within(screen.getByTestId("library-list")).getByText(item.title ?? "").closest("button") as HTMLElement);
  await flushAsync();
  await flushAsync();
  return notify;
}

/** The recording with `count` screens; null: a server older than screen moments (no `screen_count`). */
const withScreens = (count: number | null = 2) =>
  makeItem("a", { title: "Checkout review", started_at: "2026-11-04T09:30:00Z", duration_ms: 900_000, transcript: done, ...(count === null ? {} : { screen_count: count }) });

beforeEach(() => {
  resetTauriMock();
  forgetPictures();
  transcript = makeTranscript({ id: "tr-a", segments: SEGMENTS });
  screens = [makeScreen(1, 61_000, { description: "The checkout page with the Pay button", ocr_text: "Pay $120" }), makeScreen(2, 880_000)];
  writeText.mockClear();
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText } });
  times = new WeakMap();
  play.mockClear();
  Object.defineProperty(HTMLMediaElement.prototype, "play", { configurable: true, value: play });
  Object.defineProperty(HTMLMediaElement.prototype, "pause", { configurable: true, value: vi.fn() });
  Object.defineProperty(HTMLMediaElement.prototype, "currentTime", {
    configurable: true,
    get(this: HTMLMediaElement) { return times.get(this) ?? 0; },
    set(this: HTMLMediaElement, v: number) { times.set(this, v); },
  });
});
afterEach(async () => {
  await flushAsync();
  vi.restoreAllMocks();
});

const lineOf = (text: string) => screen.getByText(text).closest("[data-seg]") as HTMLElement;

describe("screens on the recording page", () => {
  it("puts each thumbnail after the line of its moment", async () => {
    await openRecording(withScreens());
    expect(callsOf("session_screens")).toEqual([{ id: "a" }]);
    const groups = screen.getAllByRole("list", { name: "Screens at this moment" });
    expect(groups).toHaveLength(2);
    expect(lineOf("Look at this page, the Pay button.").nextElementSibling).toBe(groups[0]);
    expect(lineOf("And here is the invoice.").nextElementSibling).toBe(groups[1]);
    expect(within(groups[0]).getByText("Screen 1 · 1:01")).toBeInTheDocument();
    expect(within(groups[1]).getByText("Screen 2 · 14:40")).toBeInTheDocument();
    expect(within(groups[0]).getByRole("button")).toHaveAccessibleName("Screen 1 · 1:01: The checkout page with the Pay button");
    expect(callsOf("screen_image").map((c) => c.url)).toEqual(screens.map((s) => s.thumb_url));
  });

  it("opens a screen large with previous and next, its text, and Play from here", async () => {
    await openRecording(withScreens());
    fireEvent.click(screen.getByText("Screen 1 · 1:01").closest("button")!);
    await flushAsync();
    const viewer = screen.getByRole("dialog", { name: "Screen 1 · 1:01" });
    expect(within(viewer).getByRole("img", { name: "The checkout page with the Pay button" })).toHaveAttribute("src", `data:image/webp;base64,${btoa(screens[0].image_url)}`);
    expect(within(viewer).getByText("Pay $120")).toBeInTheDocument();
    fireEvent.click(within(viewer).getByRole("button", { name: "Next screen" }));
    expect(screen.getByRole("dialog", { name: "Screen 2 · 14:40" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Play from here" }));
    expect((document.querySelector("audio") as HTMLAudioElement).currentTime).toBe(880);
    expect(play).toHaveBeenCalled();
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });

  it("reads the screens again once for fresh links when a picture's link has expired, and gives the focus back", async () => {
    await openRecording(withScreens());
    // The full pictures' links have expired; the list read again signs them anew.
    respondWith("screen_image", ({ url }) => (String(url).endsWith("sig=1") && !String(url).includes("thumb") ? Promise.reject("The link expired.") : `data:image/webp;base64,${btoa(String(url))}`));
    respondWith("session_screens", () => ({ data: screens.map((sc) => ({ ...sc, image_url: sc.image_url.replace("sig=1", "sig=2") })) }));
    const thumb = screen.getByText("Screen 1 · 1:01").closest("button")!;
    thumb.focus();
    fireEvent.click(thumb);
    await flushAsync();
    await flushAsync();
    expect(callsOf("session_screens")).toHaveLength(2);
    const viewer = screen.getByRole("dialog", { name: "Screen 1 · 1:01" });
    expect(within(viewer).getByRole("img")).toHaveAttribute("src", `data:image/webp;base64,${btoa(screens[0].image_url.replace("sig=1", "sig=2"))}`);
    // A picture that still fails asks no more.
    fail("screen_image", "Offline");
    fireEvent.click(within(viewer).getByRole("button", { name: "Next screen" }));
    await flushAsync();
    expect(screen.getByRole("dialog")).toHaveTextContent("The picture could not be loaded.");
    expect(callsOf("session_screens")).toHaveLength(2);
    fireEvent.keyDown(screen.getByRole("dialog"), { key: "Escape" });
    expect(document.activeElement).toBe(thumb);
  });

  it("shows every screen in the gallery", async () => {
    await openRecording(withScreens());
    fireEvent.click(screen.getByRole("button", { name: "2 screens" }));
    const gallery = screen.getByRole("region", { name: "Screens of this recording" });
    expect(within(gallery).getAllByRole("listitem")).toHaveLength(2);
    fireEvent.click(within(gallery).getByText("Screen 2 · 14:40").closest("button")!);
    expect(screen.getByRole("dialog", { name: "Screen 2 · 14:40" })).toBeInTheDocument();
  });

  it("deletes a screen after asking, and reads the list again", async () => {
    respond("screen_delete", undefined);
    vi.spyOn(window, "confirm").mockReturnValue(true);
    const notify = await openRecording(withScreens());
    fireEvent.click(screen.getByText("Screen 2 · 14:40").closest("button")!);
    screens = [screens[0]];
    fireEvent.click(screen.getByRole("button", { name: "Delete screen" }));
    await flushAsync();
    expect(callsOf("screen_delete")).toEqual([{ sessionId: "a", screen: "sc2" }]);
    expect(callsOf("session_screens")).toHaveLength(2);
    expect(notify).toHaveBeenCalledWith("Deleted screen 2", false, anyTarget);
    expect(screen.queryByText("Screen 2 · 14:40")).not.toBeInTheDocument();
  });

  it("puts the screens into Copy for AI at their moments, and not into the other copies", async () => {
    await openRecording(withScreens());
    fireEvent.click(screen.getByRole("button", { name: "Copy for AI" }));
    await flushAsync();
    const copied = writeText.mock.calls[0][0].split("\n");
    expect(copied.slice(-5)).toEqual([
      "Me: Let me share my screen.",
      "Client: Look at this page, the Pay button.",
      "[Screen 1 at 1:01: The checkout page with the Pay button]",
      "Me: And here is the invoice.",
      "[Screen 2 at 14:40]",
    ]);
    fireEvent.click(screen.getByRole("button", { name: "Copy text" }));
    await flushAsync();
    expect(writeText.mock.calls[1][0]).not.toContain("[Screen");
  });

  it("exports the meeting pack as the print page or a ZIP, in the view on screen", async () => {
    respond("meeting_pack", "/Users/me/Downloads/Checkout review.zip");
    const notify = await openRecording(withScreens());
    const exportMenu = screen.getByRole("combobox", { name: "Export" });
    expect(within(exportMenu).getByRole("group", { name: "With the screens" })).toBeInTheDocument();
    fireEvent.change(exportMenu, { target: { value: "pack-pdf" } });
    await flushAsync();
    expect(notify).toHaveBeenLastCalledWith("Opened the meeting pack in your browser: choose Save as PDF there", false, anyTarget);
    fireEvent.change(exportMenu, { target: { value: "pack-zip-audio" } });
    await flushAsync();
    expect(callsOf("meeting_pack")).toEqual([
      { id: "a", format: "pdf", view: null, includeAudio: false },
      { id: "a", format: "zip", view: null, includeAudio: true },
    ]);
    expect(notify).toHaveBeenLastCalledWith("Saved to /Users/me/Downloads/Checkout review.zip", false, anyTarget);
    expect(callsOf("export_transcript")).toEqual([]);
  });

  it("reads no screens for a recording without any, and offers no pack on a server without them", async () => {
    respond("screens_status", { supported: true, platform: "windows", borderless: true, permission: true, inPlan: true, onServer: false });
    await openRecording(withScreens(null));
    expect(callsOf("session_screens")).toEqual([]);
    expect(screen.queryByRole("list", { name: "Screens at this moment" })).not.toBeInTheDocument();
    expect(within(screen.getByRole("combobox", { name: "Export" })).queryByRole("group", { name: "With the screens" })).not.toBeInTheDocument();
  });

  it("offers the pack for a recording synced before the server was updated, once the server is known to have it", async () => {
    // The cached item still has its old shape (no screen_count); the server's config says it has screen moments now.
    respond("screens_status", { supported: true, platform: "windows", borderless: true, permission: true, inPlan: true, onServer: true });
    await openRecording(withScreens(null));
    expect(callsOf("session_screens")).toEqual([]);
    expect(within(screen.getByRole("combobox", { name: "Export" })).getByRole("group", { name: "With the screens" })).toBeInTheDocument();
  });

  it("keeps the pack but reads no screens when a recording has none", async () => {
    await openRecording(withScreens(0));
    expect(callsOf("session_screens")).toEqual([]);
    expect(screen.queryByRole("button", { name: /screens?$/ })).not.toBeInTheDocument();
    expect(within(screen.getByRole("combobox", { name: "Export" })).getByRole("group", { name: "With the screens" })).toBeInTheDocument();
  });
});
