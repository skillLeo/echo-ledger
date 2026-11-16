import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, renderHook, screen, waitFor, within } from "@testing-library/react";
import { CommandPalette, paletteCommands, paletteMatches, paletteScore, emptyLine } from "./CommandPalette";
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

  it("ranks a name before a keyword and never matches a word inside another (D-087)", () => {
    expect(paletteScore("new", { label: "New project", keywords: "create client folder add" })).toBe(3);
    expect(paletteScore("new", { label: "Record a voice note", keywords: "microphone new dictate n" })).toBe(1);
    expect(paletteScore("proj new", { label: "New project" })).toBe(2);
    expect(paletteScore("ew", { label: "New project" })).toBe(0);
    expect(paletteScore("ording", { label: "Stop recording" })).toBe(0);
    expect(paletteMatches("record", { label: "Stop recording" })).toBe(true);
  });

  it("names a Go to command by its place: \"library\" is Go to Library first, then Sync the library (D-087)", () => {
    expect(paletteScore("library", { label: "Go to Library", keywords: "recordings transcripts" })).toBe(3);
    expect(paletteScore("libr", { label: "Go to Library" })).toBe(3);
    expect(paletteScore("go to lib", { label: "Go to Library" })).toBe(3);
    expect(paletteScore("library", { label: "Sync the library", keywords: "refresh download" })).toBe(2);
    expect(paletteScore("to", { label: "Go to Library" })).toBe(2);
    const onGo = vi.fn();
    const run = vi.fn();
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={onGo} run={run} onOpenRecording={vi.fn()} />);
    const input = screen.getByRole("combobox");
    fireEvent.change(input, { target: { value: "library" } });
    const actions = within(screen.getByRole("group", { name: "Actions" })).getAllByRole("option").map((o) => o.textContent);
    expect(actions.indexOf("Go to Library")).toBeLessThan(actions.indexOf("Sync the library"));
    fireEvent.keyDown(input, { key: "Enter" });
    expect(onGo).toHaveBeenCalledWith("library");
    expect(run).not.toHaveBeenCalled();
  });

  it("splits what is typed as it splits names, keeps marks inside a word, and ignores case (D-087)", () => {
    expect(paletteScore("d'souza", { label: "D'Souza Traders" })).toBe(3);
    expect(paletteScore("D'Souza tra", { label: "D'Souza Traders" })).toBe(3);
    expect(paletteScore("ali & sons", { label: "Ali & Sons" })).toBe(3);
    expect(paletteScore("ali sons", { label: "Ali & Sons" })).toBe(3);
    expect(paletteScore("r&d", { label: "R&D budget" })).toBe(3);
    expect(paletteScore("a-team", { label: "The A-Team" })).toBe(2);
    expect(paletteScore("ACME", { label: "Acme Holdings" })).toBe(3);
    // Urdu with a pesh, and an accent typed as its own mark: one word each, never cut at the mark.
    expect(paletteScore("مُلا", { label: "مُلاقات نوٹس" })).toBe(3);
    expect(paletteScore("لا", { label: "مُلاقات نوٹس" })).toBe(0);
    expect(paletteScore("cafe\u0301", { label: "Cafe\u0301 Noir" })).toBe(3);
    expect(paletteScore("sume", { label: "Re\u0301sume\u0301 review" })).toBe(0);
    // Nothing typed lists everything; punctuation alone finds nothing.
    expect(paletteScore("", { label: "Ali & Sons" })).toBe(1);
    expect(paletteScore("   ", { label: "Ali & Sons" })).toBe(1);
    expect(paletteScore("&", { label: "Ali & Sons" })).toBe(0);
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
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["Go to Library", "Go to Projects", "Go to Home", "Go to Activity", "Go to Shared", "Go to Settings"]);
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
    expect(input).toHaveAttribute("placeholder", "Search recordings, settings, pages… or type a command");
    fireEvent.change(input, { target: { value: "new project" } });
    fireEvent.keyDown(input, { key: "Enter" });
    expect(extra.newProject).toHaveBeenCalled();
    cleanup();
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} extra={extra}
      pages={[{ id: "project-c1", label: "Acme Holdings", group: "Projects", open }]} />);
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "settings" } });
    fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
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
      expect(invite).toHaveTextContent("Invite people to the workspaceUltra");
      expect(invite).toHaveAccessibleName("Invite people to the workspace, Team workspace is in Ultra");
      fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
      expect(sheet.result.current?.feature).toBe("team");
      expect(extra.invite).not.toHaveBeenCalled();
      show("live translate");
      expect(screen.getByRole("option", { name: /^Live Translate/ })).toHaveTextContent("Live TranslateUltra");
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

  it("makes a new project from \"new\" and Enter, never a voice note (D-087)", () => {
    const extra = { newProject: vi.fn() };
    const run = vi.fn();
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={run} onOpenRecording={vi.fn()} extra={extra} />);
    const input = screen.getByRole("combobox");
    fireEvent.change(input, { target: { value: "new" } });
    const actions = within(screen.getByRole("group", { name: "Actions" })).getAllByRole("option").map((o) => o.textContent);
    expect(actions[0]).toBe("New project");
    expect(actions.indexOf("Record a voice note")).toBeGreaterThan(0);
    fireEvent.keyDown(input, { key: "Enter" });
    expect(extra.newProject).toHaveBeenCalled();
    expect(run).not.toHaveBeenCalled();
  });

  it("finds pages by their name, group or words, every word typed, and opens the best one with Enter", () => {
    const open = { devices: vi.fn(), acme: vi.fn() };
    const pages = [
      { id: "devices", label: "Devices", group: "Account", keywords: "phones computers", open: open.devices },
      { id: "p1", label: "Acme Holdings", group: "Projects", open: open.acme },
      { id: "p2", label: "D'Souza Traders", group: "Projects", open: vi.fn() },
      { id: "p3", label: "Ali & Sons", group: "Projects", open: vi.fn() },
    ];
    const show = (query: string) => {
      cleanup();
      render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} pages={pages} />);
      fireEvent.change(screen.getByRole("combobox"), { target: { value: query } });
      return screen.queryByRole("group", { name: "Pages" });
    };
    expect(within(show("dev")!).getAllByRole("option").map((o) => o.textContent)).toEqual(["Go to Devices · Account"]);
    expect(within(show("account phones")!).getAllByRole("option").map((o) => o.textContent)).toEqual(["Go to Devices · Account"]);
    expect(show("vices")).toBeNull();
    // Names with punctuation, typed with it (D-087); capitals ignored; nothing typed lists no pages.
    expect(within(show("d'souza")!).getAllByRole("option").map((o) => o.textContent)).toEqual(["Go to D'Souza Traders · Projects"]);
    expect(within(show("ali & sons")!).getAllByRole("option").map((o) => o.textContent)).toEqual(["Go to Ali & Sons · Projects"]);
    expect(within(show("ACME")!).getAllByRole("option").map((o) => o.textContent)).toEqual(["Go to Acme Holdings · Projects"]);
    expect(show("  ")).toBeNull();
    expect(show("zzz")).toBeNull();
    show("acme");
    fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
    expect(open.acme).toHaveBeenCalled();
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

  it("keeps the highlight on a row already shown when recordings come in later, so Enter runs it (D-087)", async () => {
    respond("search_library", { data: [{ session: makeItem("s1", { title: "Budget review" }), hit_count: 1, hits: [] }], next_page: null });
    const open = vi.fn();
    const onOpenRecording = vi.fn();
    const settings = () => [{ id: "limit", title: "Monthly limit", breadcrumb: "Account › Plan & usage", open }];
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={onOpenRecording} settings={settings} />);
    const input = screen.getByRole("combobox");
    fireEvent.change(input, { target: { value: "budget" } });
    // Found by what it is about, not its name: still the one row on screen, highlighted at once.
    expect(screen.getByRole("option", { selected: true })).toHaveTextContent("Monthly limit");
    // "Budget review" (its name starts with what was typed) arrives after 250 ms: the highlight stays.
    await screen.findByRole("option", { name: /Budget review/ });
    expect(screen.getByRole("option", { selected: true })).toHaveTextContent("Monthly limit");
    fireEvent.keyDown(input, { key: "Enter" });
    expect(open).toHaveBeenCalled();
    expect(onOpenRecording).not.toHaveBeenCalled();
  });

  it("highlights the best recording when only recordings match", async () => {
    respond("search_library", { data: [
      { session: makeItem("s1", { title: "Weekly sync" }), hit_count: 1, hits: [] },
      { session: makeItem("s2", { title: "Budget review" }), hit_count: 1, hits: [] },
    ], next_page: null });
    const props = renderPalette();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "budget" } });
    await screen.findByRole("option", { name: /Budget review/ });
    expect(screen.getByRole("option", { selected: true })).toHaveTextContent("Budget review");
    fireEvent.keyDown(screen.getByRole("combobox"), { key: "Enter" });
    expect(props.onOpenRecording).toHaveBeenCalledWith("s2", 0);
  });

  it("asks for one more letter rather than saying nothing matches a single letter", () => {
    expect(emptyLine("q", false)).toBe("Type one more letter to search recordings.");
    expect(emptyLine(" q ", true)).toBe("Type one more letter to search recordings.");
    expect(emptyLine("qz", true)).toBe("Searching recordings and transcripts…");
    expect(emptyLine("qz", false)).toBe("Nothing matches “qz” in recordings, transcripts, settings or pages.");
    renderPalette();
    // A letter no command, page or setting has.
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "ж" } });
    expect(screen.getByRole("status")).toHaveTextContent("Type one more letter to search recordings.");
  });

  it("closes with Escape and says when nothing matches", async () => {
    respond("search_library", { data: [], next_page: null });
    const props = renderPalette();
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "zzzz" } });
    // It says where it looked (recordings and transcripts too), once the recordings' search has answered.
    await waitFor(() => expect(screen.getByRole("status")).toHaveTextContent("Nothing matches “zzzz” in recordings, transcripts, settings or pages."));
    fireEvent.keyDown(screen.getByRole("combobox"), { key: "Escape" });
    expect(props.onClose).toHaveBeenCalled();
  });
});

describe("settings in the palette (v1.7)", () => {
  beforeEach(() => resetTauriMock());

  it("finds single settings in their own group with where they live, and opens them", () => {
    const open = vi.fn();
    const settings = vi.fn(() => [{ id: "lock.after", title: "Lock after", breadcrumb: "App › App lock", open }]);
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} settings={settings} />);
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "lock after" } });
    const group = screen.getByRole("group", { name: "Settings" });
    const option = within(group).getByRole("option", { name: /Lock after/ });
    expect(option).toHaveTextContent("App › App lock");
    fireEvent.mouseDown(option);
    expect(open).toHaveBeenCalled();
  });

  it("offers Upgrade plan, or the open request instead, and a clear button", () => {
    const run = vi.fn();
    const { unmount } = render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} extra={{ upgrade: { open: false, run } }} />);
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "upgrade" } });
    expect(screen.getByRole("option", { name: "Upgrade plan" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Clear" }));
    expect(screen.getByRole("combobox")).toHaveValue("");
    unmount();
    render(<CommandPalette status={makeStatus()} onClose={vi.fn()} onGo={vi.fn()} run={vi.fn()} onOpenRecording={vi.fn()} extra={{ upgrade: { open: true, run } }} />);
    fireEvent.change(screen.getByRole("combobox"), { target: { value: "upgrade request" } });
    expect(screen.getByRole("option", { name: "Your upgrade request" })).toBeInTheDocument();
    expect(screen.queryByRole("option", { name: "Upgrade plan" })).not.toBeInTheDocument();
  });
});
