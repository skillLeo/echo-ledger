// UX-22 (PLAN_V2_1_POLISH 3b) on the recording page: a line's right-click (Play from here), a speaker's (Rename speaker…
// · Merge with ›) and selected words' (Copy · Copy with time | Play from here | Select all; on a Mac the system's own
// text menu), from actions.json.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { checkMenu } from "../actions/registry";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEM = makeItem("bilal", { title: "Bilal Qureshi", transcript: { final_status: "completed", final_transcript_id: "tr-1", live_available: false, preview: "Hello" } });
const setPlatform = (platform: string) => Object.defineProperty(navigator, "platform", { value: platform, configurable: true });
const words = (menu: HTMLElement) => [...menu.querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent);
const drawn = (menu: HTMLElement) => [...menu.querySelectorAll('[role^="menuitem"]')].map((m) => ({ label: m.querySelector(".el-menu__label")?.textContent ?? "", action: m.getAttribute("data-action") ?? undefined }));
const line = (text: string) => within(screen.getByRole("list", { name: "Transcript" })).getByText(text).closest("li") as HTMLElement;

describe("the recording page's right-click menus (UX-22)", () => {
  const play = vi.fn(() => Promise.resolve());
  beforeEach(async () => {
    resetTauriMock();
    setPlatform("Win32");
    play.mockClear();
    Object.assign(HTMLMediaElement.prototype, { play, pause: vi.fn() });
    respond("library_list", [ITEM]);
    respond("library_refresh", [ITEM]);
    respond("bin_list", { data: [] });
    respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", speaker_names: {} });
    respond("transcript_detail", makeTranscript());
    render(<LibraryV2 openRequest={{ id: "bilal", seq: 1 }} />);
    await act(flushAsync);
  });
  afterEach(() => setPlatform(""));

  it("a line: Play from here plays from its start", async () => {
    fireEvent.contextMenu(line("Let us go over the invoice."), { clientX: 30, clientY: 40 });
    const menu = screen.getByRole("menu", { name: "Line at 1:05" });
    expect(words(menu)).toEqual(["Play from here"]);
    expect(checkMenu(drawn(menu), "windows")).toEqual([]);
    fireEvent.click(within(menu).getByRole("menuitem", { name: "Play from here" }));
    expect(play).toHaveBeenCalled();
    expect((document.querySelector("audio") as HTMLAudioElement).currentTime).toBe(65);
  });

  it("a speaker's name: Rename speaker… opens Speakers; Merge with › another gives this one that name", async () => {
    respond("speakers_rename", { speaker_names: { Client: "Me" } });
    const name = line("Hi Sam, thanks for calling back.").querySelector(".el-segment__speaker") as HTMLElement;
    fireEvent.contextMenu(name, { clientX: 30, clientY: 40 });
    const menu = screen.getByRole("menu", { name: "Client" });
    expect(words(menu)).toEqual(["Rename speaker…", "Merge with"]);
    fireEvent.click(within(menu).getByRole("menuitem", { name: "Merge with" }));
    fireEvent.click(within(screen.getByRole("menu", { name: "Merge with" })).getByRole("menuitem", { name: "Me" }));
    await act(flushAsync);
    expect(callsOf("speakers_rename")).toEqual([{ sessionId: "bilal", names: { Client: "Me" }, rememberForClient: false }]);
  });

  it("selected words: Copy · Copy with time | Play from here | Select all (Ask about this waits); on a Mac the system's own menu", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    const text = within(line("Let us go over the invoice.")).getByText("Let us go over the invoice.");
    const select = () => {
      const range = document.createRange();
      range.selectNodeContents(text);
      window.getSelection()!.removeAllRanges();
      window.getSelection()!.addRange(range);
    };
    select();
    fireEvent.contextMenu(text, { clientX: 30, clientY: 40 });
    const menu = screen.getByRole("menu", { name: "Selected words" });
    expect(words(menu)).toEqual(["Copy", "Copy with time", "Play from here", "Select all"]);
    expect(checkMenu(drawn(menu), "windows")).toEqual([]);
    fireEvent.click(within(menu).getByRole("menuitem", { name: "Copy with time" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenCalledWith("[1:05] Let us go over the invoice.");
    // A Mac keeps its own text menu: ours doesn't open.
    setPlatform("MacIntel");
    select();
    const event = fireEvent.contextMenu(text, { clientX: 30, clientY: 40 });
    expect(event).toBe(true);
    expect(screen.queryByRole("menu", { name: "Selected words" })).toBeNull();
  });

  it("the selected-words bar, on every platform: [Play from here] [Copy with time] above the words (Ask about this and Share wait)", async () => {
    setPlatform("MacIntel");
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    const text = within(line("Hi Sam, thanks for calling back.")).getByText("Hi Sam, thanks for calling back.");
    const range = document.createRange();
    range.selectNodeContents(text);
    window.getSelection()!.removeAllRanges();
    window.getSelection()!.addRange(range);
    act(() => void document.dispatchEvent(new Event("selectionchange")));
    const bar = screen.getByRole("toolbar", { name: "Selected words" });
    // A Mac's words (Title Case), as its menus.
    expect(within(bar).getAllByRole("button").map((b) => b.textContent)).toEqual(["Play from Here", "Copy with Time"]);
    expect(checkMenu([...bar.querySelectorAll("button")].map((b) => ({ label: b.textContent ?? "", action: b.getAttribute("data-action") ?? undefined })), "macos")).toEqual([]);
    fireEvent.click(within(bar).getByRole("button", { name: "Copy with Time" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenCalledWith("[0:12] Hi Sam, thanks for calling back.");
    fireEvent.click(within(bar).getByRole("button", { name: "Play from Here" }));
    expect((document.querySelector("audio") as HTMLAudioElement).currentTime).toBe(12.4);
    // The words let go: the bar goes.
    window.getSelection()!.removeAllRanges();
    act(() => void document.dispatchEvent(new Event("selectionchange")));
    expect(screen.queryByRole("toolbar", { name: "Selected words" })).toBeNull();
  });
});
