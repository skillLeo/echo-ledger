import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { CommandPalette, paletteCommands, paletteMatches } from "./CommandPalette";
import { resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeRecording, makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

beforeEach(() => resetTauriMock());

describe("command palette (B9)", () => {
  it("matches every typed word against the label and keywords", () => {
    expect(paletteMatches("go set", { label: "Go to Settings" })).toBe(true);
    expect(paletteMatches("language", { label: "Go to Settings", keywords: "captions language" })).toBe(true);
    expect(paletteMatches("record zoom", { label: "Start recording" })).toBe(false);
    expect(paletteMatches("", { label: "Anything" })).toBe(true);
  });

  it("offers what makes sense right now", () => {
    const actions = { go: vi.fn(), run: vi.fn() };
    const idle = paletteCommands(makeStatus(), actions).map((c) => c.id);
    const recording = paletteCommands(makeStatus({ recording: makeRecording() }), actions).map((c) => c.id);
    const paused = paletteCommands(makeStatus({ recording: makeRecording(), paused: true }), actions).map((c) => c.id);
    expect(idle).toContain("start");
    expect(idle).not.toContain("pause");
    expect(recording).toEqual(expect.arrayContaining(["stop", "pause", "mark"]));
    expect(paused).toContain("resume");
  });

  function renderPalette(status = makeStatus()) {
    const props = { onClose: vi.fn(), onGo: vi.fn(), run: vi.fn(), onOpenRecording: vi.fn() };
    render(<CommandPalette status={status} {...props} />);
    return props;
  }

  it("filters as you type, moves with the arrows and runs with Enter", () => {
    const props = renderPalette();
    const input = screen.getByRole("combobox");
    fireEvent.change(input, { target: { value: "go to" } });
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["Go to Library", "Go to Clients", "Go to Record", "Go to Activity", "Go to Settings"]);
    expect(screen.getAllByRole("option")[0]).toHaveAttribute("aria-selected", "true");
    fireEvent.keyDown(input, { key: "ArrowDown" });
    fireEvent.keyDown(input, { key: "ArrowDown" });
    fireEvent.keyDown(input, { key: "Enter" });
    expect(props.onClose).toHaveBeenCalled();
    expect(props.onGo).toHaveBeenCalledWith("record");
  });

  it("runs app actions through the shared runner", () => {
    const props = renderPalette();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "start" } });
    fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
    expect(props.run).toHaveBeenCalledTimes(1);
  });

  it("finds recordings by their transcript and opens one at the moment", async () => {
    respond("search_library", { data: [{ session: makeItem("s1", { title: "Acme kickoff" }), hit_count: 1,
      hits: [{ transcript_id: "t", kind: "final", segment_index: 1, start_ms: 65_000, end_ms: 66_000, speaker: "Client", snippet: "the invoice is due", highlights: [[4, 7]] }] }], next_page: null });
    const props = renderPalette();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "invoice" } });
    const option = await screen.findByRole("option", { name: /Acme kickoff/ });
    expect(option.querySelector("mark")).toHaveTextContent("invoice");
    fireEvent.mouseDown(option);
    expect(props.onOpenRecording).toHaveBeenCalledWith("s1", 65_000);
  });

  it("closes with Escape and says when nothing matches", async () => {
    respond("search_library", { data: [], next_page: null });
    const props = renderPalette();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "zzzz" } });
    await waitFor(() => expect(screen.getByText("Nothing matches.")).toBeInTheDocument());
    fireEvent.keyDown(screen.getByRole("combobox"), { key: "Escape" });
    expect(props.onClose).toHaveBeenCalled();
  });
});
