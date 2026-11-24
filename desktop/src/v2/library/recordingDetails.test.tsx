// The recording page's header, player and Details against classic (parity.csv DT-REC-006, 010, 019-031, 035, 050, 052,
// 128, 143, 145-147, DT-LIB-066, 073): Share (classic's dialog: links, people, sending), the project link, the
// automatic title chip, the engine and script lines, bookmarks, the keys hint, Details, and the row's labels and
// Recovered chip.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEM: LibraryItem = makeItem("s1", {
  title: "Payment terms", title_auto: true, duration_ms: 3_730_000, client: { id: "c1", name: "Acme" }, labels: ["Q4", "VIP"], keep: true, pinned: true,
  transcript: { final_status: "completed", final_transcript_id: "tr-1", live_available: false, preview: "Hello", final_language: "ur", final_provider: "assemblyai", script_warning: "latin" },
});

describe("the recording page's details", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("library_list", [ITEM]);
    respond("library_refresh", [ITEM]);
    respond("recovered_sessions", ["s1"]);
    respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", speaker_names: {}, markers: [{ id: "m1", at_ms: 12_400, tag: "Price", note: "Ask again" }] });
    respond("transcript_detail", makeTranscript({ id: "tr-1" }));
    respond("shares_of", { data: [] });
    Object.assign(HTMLMediaElement.prototype, { play: vi.fn(() => Promise.resolve()), pause: vi.fn() });
  });

  it("the row: its labels and the Recovered chip", async () => {
    render(<LibraryV2 />);
    await act(flushAsync);
    const row = screen.getByRole("button", { name: "Payment terms" });
    // Its first words, then what the row doesn't all show (pinned, every label), Recovered, when and how long (UX-06).
    expect(row).toHaveAccessibleDescription("Hello Pinned, Q4, VIP Recovered 24 Oct 1h 02m");
    expect(row.closest("li")!.querySelectorAll(".el-rec__label")).toHaveLength(2);
  });

  it("header: the project opens, Automatic title, the engine and the script warning; bookmarks play; the keys hint; Details", async () => {
    const onOpenProject = vi.fn();
    render(<LibraryV2 openRequest={{ id: "s1", seq: 1 }} onOpenProject={onOpenProject} />);
    await act(flushAsync);
    await act(flushAsync);
    expect(screen.getByText("Automatic title")).toBeInTheDocument();
    expect(screen.getByText("Transcribed in Urdu")).toBeInTheDocument();
    expect(screen.getByText(/Wrong script detected: Urdu written in English letters/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Open project Acme" }));
    expect(onOpenProject).toHaveBeenCalledWith("c1");
    const marks = screen.getByRole("list", { name: "Bookmarks" });
    fireEvent.click(within(marks).getByRole("button", { name: "0:12 Price" }));
    expect(screen.getByText(/0:12 \/ 1:02:10/)).toBeInTheDocument();
    expect(screen.getByText("Space plays · ← / → 5 s · Shift+← / Shift+→ 10 s")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("tab", { name: "Details" }));
    const details = document.querySelector(".el-details") as HTMLElement;
    const value = (term: string) => within(details).getByText(term).nextElementSibling?.textContent;
    expect(value("Transcription engine")).toBe("AssemblyAI");
    expect(value("Kept")).toBe("Forever");
    expect(value("Labels")).toBe("Q4, VIP");
    expect(value("Pinned")).toBe("Yes");
  });

  it("Share opens classic's dialog: a view-only link (who may open it, downloads, when it stops) or send to a teammate", async () => {
    render(<LibraryV2 openRequest={{ id: "s1", seq: 1 }} />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Share" }));
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: "Share Payment terms" });
    expect(callsOf("shares_of")).toEqual([{ sessionId: "s1", clientId: null }]);
    expect(dialog).toHaveTextContent("Anyone with the link");
    expect(dialog).toHaveTextContent("Only people I choose");
    expect(within(dialog).getByRole("button", { name: /Send to a teammate/ })).toBeInTheDocument();
  });
});
