import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { ExportMenu, HistoryPanel, PlayerControls, ReplaceBar, SharePanel, SpeakersPanel, Timeline, VocabularyHint } from "./TranscriptTools";
import { callsOf, resetTauriMock, respond, respondWith } from "../../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

beforeEach(() => resetTauriMock());

describe("player controls and timeline (B6)", () => {
  it("changes the speed and the two switches", () => {
    const onRate = vi.fn();
    const onSkip = vi.fn();
    const onFollow = vi.fn();
    render(<PlayerControls rate={1} onRate={onRate} skipSilence={false} onSkipSilence={onSkip} follow onFollow={onFollow} />);
    fireEvent.change(screen.getByLabelText("Speed"), { target: { value: "1.5" } });
    fireEvent.click(screen.getByLabelText("Skip silence"));
    fireEvent.click(screen.getByLabelText("Follow"));
    expect(onRate).toHaveBeenCalledWith(1.5);
    expect(onSkip).toHaveBeenCalledWith(true);
    expect(onFollow).toHaveBeenCalledWith(false);
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["0.5×", "0.75×", "1×", "1.25×", "1.5×", "1.75×", "2×"]);
  });

  it("draws each side's speech and the bookmarks, and seeks where it is clicked", () => {
    const onSeek = vi.fn();
    render(<Timeline durationMs={100_000} nowMs={50_000} onSeek={onSeek}
      segments={[{ channel: 1, speaker: "Me", start_ms: 0, end_ms: 10_000, text: "a" }, { channel: 2, speaker: "Client", start_ms: 20_000, end_ms: 30_000, text: "b" }]}
      bookmarks={[{ id: "m", at_ms: 25_000, tag: null, note: null }]} />);
    const timeline = screen.getByTestId("timeline");
    expect(timeline.querySelector(".tl-lane-2 .tl-talk")).toHaveStyle({ left: "20%", width: "10%" });
    expect(timeline.querySelector(".tl-mark")).toHaveStyle({ left: "25%" });
    expect(timeline.querySelector(".tl-head")).toHaveStyle({ left: "50%" });
    timeline.getBoundingClientRect = () => ({ left: 0, width: 200, top: 0, height: 30, right: 200, bottom: 30, x: 0, y: 0, toJSON: () => ({}) });
    fireEvent.click(timeline, { clientX: 50 });
    expect(onSeek).toHaveBeenCalledWith(25_000);
  });

  it("draws nothing without lines or bookmarks", () => {
    const { container } = render(<Timeline durationMs={1000} nowMs={0} onSeek={vi.fn()} segments={[]} bookmarks={[]} />);
    expect(container).toBeEmptyDOMElement();
  });
});

describe("editing tools (B7)", () => {
  it("replaces all and says how many", async () => {
    const onReplace = vi.fn(async () => 3);
    render(<ReplaceBar onReplace={onReplace} onClose={vi.fn()} />);
    expect(screen.getByRole("button", { name: "Replace all" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Find"), { target: { value: "acme" } });
    fireEvent.change(screen.getByLabelText("Replace with"), { target: { value: "Acme Ltd" } });
    fireEvent.click(screen.getByLabelText("Match case"));
    fireEvent.click(screen.getByRole("button", { name: "Replace all" }));
    await waitFor(() => expect(screen.getByRole("status")).toHaveTextContent("Replaced 3 times."));
    expect(onReplace).toHaveBeenCalledWith("acme", "Acme Ltd", true, true);
  });

  it("renames speakers, clears a blank name and offers remembering for the client", async () => {
    const onSave = vi.fn(async () => true);
    const onClose = vi.fn();
    render(<SpeakersPanel labels={["Me", "Client"]} names={{ Me: "Sam" }} hasClient onSave={onSave} onClose={onClose} />);
    expect(screen.getByLabelText("Name for Me")).toHaveValue("Sam");
    fireEvent.change(screen.getByLabelText("Name for Me"), { target: { value: "  " } });
    fireEvent.change(screen.getByLabelText("Name for Client"), { target: { value: " Jane " } });
    fireEvent.click(screen.getByLabelText(/next recordings/));
    fireEvent.click(screen.getByRole("button", { name: "Save names" }));
    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(onSave).toHaveBeenCalledWith({ Me: null, Client: "Jane" }, true);
  });

  it("hides the remember option without a client", () => {
    render(<SpeakersPanel labels={["Speaker"]} names={{}} hasClient={false} onSave={vi.fn(async () => true)} onClose={vi.fn()} />);
    expect(screen.queryByLabelText(/next recordings/)).not.toBeInTheDocument();
  });

  it("lists the history and restores the original after a confirmation", async () => {
    respond("transcript_revisions", { data: [
      { id: "r2", source: "edit", author: "Work laptop", created_at: "2026-10-25T10:00:00Z" },
      { id: "r1", source: "original", author: null, created_at: "2026-10-25T09:00:00Z" },
    ] });
    const onRestore = vi.fn(async () => undefined);
    vi.spyOn(window, "confirm").mockReturnValue(true);
    render(<HistoryPanel transcriptId="t1" edited onRestore={onRestore} onClose={vi.fn()} />);
    expect(await screen.findByText("Original from the engine")).toBeInTheDocument();
    expect(screen.getByText("by Work laptop")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Restore original" }));
    expect(onRestore).toHaveBeenCalled();
  });

  it("offers no restore for a transcript that was never edited", async () => {
    respond("transcript_revisions", { data: [] });
    render(<HistoryPanel transcriptId="t1" edited={false} onRestore={vi.fn()} onClose={vi.fn()} />);
    expect(await screen.findByText(/Not edited yet/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Restore original" })).not.toBeInTheDocument();
  });

  it("offers the vocabulary for new terms and can be dismissed", () => {
    const onAdd = vi.fn();
    const onDismiss = vi.fn();
    render(<VocabularyHint terms={["Farah", "Q3"]} clientName="Acme" onAdd={onAdd} onDismiss={onDismiss} />);
    expect(screen.getByRole("status")).toHaveTextContent("Add to Acme’s vocabulary");
    fireEvent.click(screen.getByRole("button", { name: "Farah" }));
    fireEvent.click(screen.getByRole("button", { name: "Not now" }));
    expect(onAdd).toHaveBeenCalledWith("Farah");
    expect(onDismiss).toHaveBeenCalled();
  });
});

describe("export and share (B10)", () => {
  it("exports in the chosen format", () => {
    const onExport = vi.fn();
    render(<ExportMenu onExport={onExport} />);
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(
      ["Export…", "Text (.txt)", "Word (.docx)", "PDF (print)", "Subtitles (.srt)", "JSON (.json)", "Markdown (.md)", "Web subtitles (.vtt)"]);
    expect(screen.getByLabelText("Export")).toHaveAttribute("title", "PDF (print) opens a print page in your browser: choose Save as PDF there.");
    fireEvent.change(screen.getByLabelText("Export"), { target: { value: "docx" } });
    expect(onExport).toHaveBeenCalledWith("docx");
  });

  it("names the view it exports in", () => {
    render(<ExportMenu onExport={vi.fn()} viewLabel="Roman Urdu" />);
    expect(screen.getAllByRole("option")[0]).toHaveTextContent("Export as Roman Urdu…");
  });

  it("makes a link, shows it once with Copy, lists the links and revokes one", async () => {
    let links = [{ id: "s1", session_id: "x", has_password: false, include_audio: false, expires_at: "2026-10-26T10:00:00Z", revoked_at: null, active: true, view_count: 2, last_viewed_at: null, created_by: "Laptop", created_at: "2026-10-25T10:00:00Z" }];
    respondWith("share_list", () => ({ data: links }));
    respond("share_create", { ...links[0], id: "s2", url: "https://ledger.example.test/s/abc" });
    respondWith("share_revoke", () => { links = links.map((l) => ({ ...l, active: false, revoked_at: "2026-10-25T11:00:00Z" })); });
    const writeText = vi.fn(async () => undefined);
    Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText } });
    const notify = vi.fn();
    render(<SharePanel sessionId="x" hasAudio notify={notify} onClose={vi.fn()} />);
    expect(await screen.findByText(/2 views/)).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText("Expires after"), { target: { value: "604800" } });
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "abc" } });
    expect(screen.getByRole("button", { name: "Make link" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "secret12" } });
    fireEvent.click(screen.getByLabelText("Include the recording"));
    fireEvent.click(screen.getByRole("button", { name: "Make link" }));
    expect(await screen.findByText("https://ledger.example.test/s/abc")).toBeInTheDocument();
    expect(callsOf("share_create")[0]).toEqual({ sessionId: "x", expiresIn: 604800, password: "secret12", includeAudio: true });
    fireEvent.click(screen.getByRole("button", { name: "Copy" }));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith("https://ledger.example.test/s/abc"));

    fireEvent.click(within(screen.getByRole("list", { name: "Share links" })).getByRole("button", { name: "Revoke" }));
    await waitFor(() => expect(screen.getByText("Revoked")).toBeInTheDocument());
  });
});
