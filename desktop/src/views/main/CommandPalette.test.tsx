import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, renderHook, screen, waitFor } from "@testing-library/react";
import { CommandPalette, paletteCommands, paletteMatches } from "./CommandPalette";
import { resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeRecording, makeStatus, sampleEntitlements } from "../../test/fixtures";
import { closeUpgradeSheet, resetEntitlementsForTests, useUpgradeSheet } from "../../lib/entitlements";

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
    expect(idle).toContain("ready");
    expect(paletteCommands(makeStatus(), actions).find((c) => c.id === "start")?.label).toBe("Record now");
    expect(idle).not.toContain("pause");
    expect(recording).toEqual(expect.arrayContaining(["stop", "pause", "mark"]));
    expect(paused).toContain("resume");
  });

  it("offers the smart folders the sidebar lists, after the Library", () => {
    const openFolder = vi.fn();
    const folders = [{ folder: "inbox" as const, label: "Inbox" }, { folder: "shared" as const, label: "Shared with me" }];
    const commands = paletteCommands(makeStatus(), { go: vi.fn(), run: vi.fn(), folders, openFolder });
    const ids = commands.map((c) => c.id);
    expect(ids.slice(ids.indexOf("library"), ids.indexOf("projects"))).toEqual(["library", "folder-inbox", "folder-shared"]);
    commands.find((c) => c.id === "folder-shared")!.run();
    expect(openFolder).toHaveBeenCalledWith("shared");
    expect(commands.find((c) => c.id === "folder-shared")!.label).toBe("Go to Shared with me");
    // Without a way to open them (a server older than v1.5 lists none), there are none.
    expect(paletteCommands(makeStatus(), { go: vi.fn(), run: vi.fn(), folders }).some((c) => c.id.startsWith("folder-"))).toBe(false);
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
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["Go to Library", "Go to Projects", "Go to Home", "Go to Activity", "Go to Shared", "Go to Account"]);
    expect(screen.getAllByRole("option")[0]).toHaveAttribute("aria-selected", "true");
    fireEvent.keyDown(input, { key: "ArrowDown" });
    fireEvent.keyDown(input, { key: "ArrowDown" });
    fireEvent.keyDown(input, { key: "Enter" });
    expect(props.onClose).toHaveBeenCalled();
    expect(props.onGo).toHaveBeenCalledWith("record");
  });

  it("makes things and opens Account's tabs and any page by name (v1.6, P4.3)", () => {
    const extra = { upload: vi.fn(), newProject: vi.fn(), requestLink: vi.fn(), invite: vi.fn(), account: vi.fn(), help: vi.fn() };
    const open = vi.fn();
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} extra={extra}
      pages={[{ id: "project-c1", label: "Acme Holdings", group: "Projects", open }]} />);
    const input = screen.getByRole("combobox");
    expect(input).toHaveAttribute("placeholder", "Search recordings, transcripts, pages… or type a command");
    fireEvent.change(input, { target: { value: "new project" } });
    fireEvent.keyDown(input, { key: "Enter" });
    expect(extra.newProject).toHaveBeenCalled();
    cleanup();
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} extra={extra}
      pages={[{ id: "project-c1", label: "Acme Holdings", group: "Projects", open }]} />);
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "devices" } });
    fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
    expect(extra.account).toHaveBeenCalledWith("devices");
    cleanup();
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} extra={extra} />);
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "all features" } });
    fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
    expect(extra.account).toHaveBeenCalledWith("all-features");
    cleanup();
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} extra={extra}
      pages={[{ id: "project-c1", label: "Acme Holdings", group: "Projects", open }]} />);
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "acme" } });
    expect(screen.getByRole("option", { name: "Go to Acme Holdings · Projects" })).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
    expect(open).toHaveBeenCalled();
    for (const word of ["upload", "request link", "invite"]) {
      cleanup();
      render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} extra={extra} />);
      fireEvent.change(screen.getByRole("combobox"), { target: { value: word } });
      fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
    }
    expect(extra.upload).toHaveBeenCalled();
    expect(extra.requestLink).toHaveBeenCalled();
    expect(extra.invite).toHaveBeenCalled();
  });

  it("keeps Invite and Live Translate in the palette with their badges on a Free plan, opening the upgrade sheet instead (v1.6.2)", () => {
    resetEntitlementsForTests(sampleEntitlements("free"));
    const sheet = renderHook(() => useUpgradeSheet());
    try {
      const extra = { invite: vi.fn() };
      const onGo = vi.fn();
      let view: ReturnType<typeof render> | null = null;
      const show = (q: string) => {
        view?.unmount();
        view = render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={onGo} run={vi.fn()} onOpenRecording={vi.fn()} extra={extra} />);
        fireEvent.change(screen.getByRole("combobox"), { target: { value: q } });
      };
      show("invite");
      const invite = screen.getByRole("option", { name: /^Invite people to the workspace/ });
      expect(invite).toHaveTextContent("Invite people to the workspaceULTRA ✦");
      expect(invite).toHaveAccessibleName("Invite people to the workspace, Team workspace is in the Ultra plan");
      fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
      expect(sheet.result.current?.feature).toBe("team");
      expect(extra.invite).not.toHaveBeenCalled();
      show("live translate");
      expect(screen.getByRole("option", { name: /^Live Translate/ })).toHaveTextContent("Live TranslateULTRA ✦");
      fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
      expect(sheet.result.current?.feature).toBe("live_translate");
      expect(onGo).not.toHaveBeenCalled();

      act(() => resetEntitlementsForTests(sampleEntitlements("ultra")));
      show("invite");
      expect(screen.getByRole("option", { name: "Invite people to the workspace" })).not.toHaveTextContent("✦");
      fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
      expect(extra.invite).toHaveBeenCalled();
      show("live translate");
      fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
      expect(onGo).toHaveBeenCalledWith("record");
    } finally {
      act(() => {
        resetEntitlementsForTests();
        closeUpgradeSheet();
      });
      sheet.unmount();
    }
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
