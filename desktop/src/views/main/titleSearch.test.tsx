// UX-20: the title row's search of everything. Under the row's field, 560 px wide, in groups: Recordings (a title or a
// transcript that matches, the words marked, and "Show all in the list"), Projects, Ask, Settings, Commands (the
// palette's and the action list's, UX-21). Arrow keys and Enter choose; Esc closes.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { CommandPalette } from "./CommandPalette";
import { resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeStatus } from "../../test/fixtures";
import { registerAction } from "../../v2/actions/registry";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

beforeEach(() => resetTauriMock());
const offs: (() => void)[] = [];
afterEach(() => offs.splice(0).forEach((off) => off()));

function search(over: Partial<Parameters<typeof CommandPalette>[0]> = {}) {
  respond("search_library", { data: [
    { session: makeItem("s1", { title: "Acme kickoff" }), hit_count: 1,
      hits: [{ transcript_id: "t", kind: "final", segment_index: 1, start_ms: 65_000, end_ms: 66_000, speaker: "Client", snippet: "acme pays the invoice", highlights: [[0, 4]] }] },
  ], next_page: null });
  const props = {
    status: makeStatus(), onClose: vi.fn(), onGo: vi.fn(), run: vi.fn(), onOpenRecording: vi.fn(), look: "v2" as const, inRow: true,
    pages: [
      { id: "project-p1", label: "Acme Holdings", group: "Projects", keywords: "project client", open: vi.fn() },
      { id: "projects", label: "Projects", keywords: "clients folders", open: vi.fn() },
      { id: "activity", label: "Activity", keywords: "notifications bell", open: vi.fn() },
    ],
    settings: (q: string) => (/acme/i.test(q) ? [{ id: "acme-sync", title: "Acme sync", breadcrumb: "Account › Devices", open: vi.fn() }] : []),
    onShowAll: vi.fn(), onAsk: vi.fn(), ...over,
  };
  render(<CommandPalette {...props} />);
  return props;
}
const groups = () => screen.getAllByRole("group").map((g) => g.getAttribute("aria-labelledby") && document.getElementById(g.getAttribute("aria-labelledby")!)!.textContent);
const group = (name: string) => within(screen.getAllByRole("group").find((g) => document.getElementById(g.getAttribute("aria-labelledby")!)!.textContent === name)!);

describe("the title row's search of everything (UX-20)", () => {
  it("groups what it finds: Recordings, Projects, Ask, Settings, Commands", async () => {
    search();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "acme" } });
    await screen.findByRole("option", { name: /Acme kickoff/ });
    expect(groups()).toEqual(["Recordings", "Projects", "Ask", "Settings"]);
    expect(group("Projects").getByRole("option", { name: /Acme Holdings/ })).toBeInTheDocument();
    expect(group("Settings").getByRole("option", { name: /Acme sync/ })).toBeInTheDocument();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "activity" } });
    expect(groups()).toContain("Commands");
    expect(group("Commands").getByRole("option", { name: /Go to Activity/ })).toBeInTheDocument();
  });

  it("a recording found by its title or its transcript, the words marked", async () => {
    const p = search();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "acme" } });
    const row = await screen.findByRole("option", { name: /Acme kickoff/ });
    const marks = [...row.querySelectorAll("mark")].map((m) => m.textContent);
    expect(marks).toEqual(["Acme", "acme"]);
    fireEvent.mouseDown(row);
    expect(p.onOpenRecording).toHaveBeenCalledWith("s1", 65_000);
  });

  it("\"Show all in the list\" turns the Library into the results", async () => {
    const p = search();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "invoice" } });
    const all = await group("Recordings").findByRole("option", { name: "Show all in the list" });
    fireEvent.mouseDown(all);
    expect(p.onShowAll).toHaveBeenCalledWith("invoice");
    expect(p.onClose).toHaveBeenCalled();
  });

  it("Ask: asks the projects what was typed", async () => {
    const p = search();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "what did acme agree" } });
    fireEvent.mouseDown(group("Ask").getByRole("option", { name: "Ask about “what did acme agree”" }));
    expect(p.onAsk).toHaveBeenCalledWith("what did acme agree");
  });

  it("Commands include the action list's commands this window can run now (UX-21)", () => {
    const run = vi.fn();
    offs.push(registerAction("file.importAudio", run));
    search();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "import" } });
    const row = group("Commands").getByRole("option", { name: /Import audio files…/ });
    fireEvent.mouseDown(row);
    expect(run).toHaveBeenCalledTimes(1);
    // One the window has no way to run is not listed.
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "print transcript" } });
    expect(screen.queryByRole("option", { name: /Print transcript/ })).toBeNull();
  });

  it("arrow keys and Enter choose (the best row first, a recording only when nothing else matches); Esc closes", async () => {
    const p = search();
    const input = screen.getByRole("combobox");
    fireEvent.change(input, { target: { value: "acme" } });
    await screen.findByRole("option", { name: /Acme kickoff/ });
    // The best match is the project (its name starts with it); recordings come in later and never take the highlight.
    expect(screen.getByRole("option", { selected: true })).toHaveTextContent("Acme Holdings");
    fireEvent.keyDown(input, { key: "ArrowDown" });
    expect(screen.getByRole("option", { selected: true })).toHaveTextContent(/^Ask about/);
    fireEvent.keyDown(input, { key: "ArrowUp" });
    fireEvent.keyDown(input, { key: "Enter" });
    expect(p.pages[0].open).toHaveBeenCalled();
    fireEvent.keyDown(input, { key: "Escape" });
    expect(p.onClose).toHaveBeenCalled();
  });

  it("drops down 560 px wide under the row's field", () => {
    const css = readFileSync(join(__dirname, "../../v2/styles/v2.css"), "utf8");
    expect(css).toContain("inline-size: min(var(--el-desktop-search-results-width), calc(100% - var(--el-space-16)))");
    search();
    expect(document.querySelector(".palette-backdrop")).toHaveClass("el-palette--row");
  });

  it("outside the title row (classic, the sidebar shell) the palette keeps its groups", () => {
    search({ inRow: false });
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "activity" } });
    expect(groups()[0]).toBe("Actions");
    expect(groups()).not.toContain("Commands");
    expect(screen.queryByRole("option", { name: "Show all in the list" })).toBeNull();
  });
});
