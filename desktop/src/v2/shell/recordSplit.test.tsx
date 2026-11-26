// DES-02 + UX-04: Record is a real split button at the top of the rail: a joined 56×40 pill, the circle with its red dot
// records now, the chevron behind a hairline opens "More ways to record"; "Record" under it. Its menu: New recording ⌘N,
// New voice note ⇧⌘N, Import audio files… ⌘O, a line, ✓ Auto-record calls & meetings (and the Record page). On a Mac the
// menu is the system's own. While recording it is red with ■ and the time; a press, or ⌘., stops. Right-click on
// either half opens the menu. Every item is an action of actions.json (UX-21).
import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { NavShell } from "./NavShell";
import type { Place } from "./NavRail";
import { checkMenu } from "../actions/registry";
import { listenToMenuPicks } from "../actions/menuState";
import { callsOf, emitEvent, respond, resetTauriMock } from "../../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const props = (over: Partial<Parameters<typeof NavShell>[0]> = {}) => ({
  place: "library" as Place | null, onPlace: vi.fn(), onHome: vi.fn(), status: { kind: "ready" as const, text: "Ready to record" }, onSetupHealth: () => undefined,
  onRecord: vi.fn(), onVoiceNote: vi.fn(), onUpload: vi.fn(), callRecording: { on: true, toggle: vi.fn() }, searchKeys: "⌘K", panels: {},
  children: <p>The page</p>, ...over,
});
const rail = () => within(screen.getByRole("navigation", { name: "Main" }));
const items = () => [...screen.getByRole("menu", { name: "More ways to record" }).querySelectorAll('[role^="menuitem"]')];

describe("Record, a split button (DES-02)", () => {
  afterEach(() => {
    delete document.documentElement.dataset.platform;
    resetTauriMock();
  });

  it("a joined pill: the circle records now, the chevron opens its menu; Record under it", () => {
    const p = props();
    render(<NavShell {...p} />);
    const group = rail().getByRole("group", { name: "Record" });
    expect(group.querySelector(".el-record__pill")).not.toBeNull();
    const now = within(group).getByRole("button", { name: "Record" });
    const more = within(group).getByRole("button", { name: "More ways to record" });
    expect(now).toHaveAttribute("aria-keyshortcuts", "Meta+N");
    expect(more).toHaveAttribute("aria-haspopup", "menu");
    expect(group.querySelector(".el-rail__label")!.textContent).toBe("Record");
    fireEvent.click(now);
    expect(p.onRecord).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("menu")).toBeNull();
    fireEvent.click(more);
    expect(more).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("menu", { name: "More ways to record" })).toBeInTheDocument();
  });

  it("its menu: New recording, New voice note, Import audio files…, a line, the Auto-record tick, then the Record page; all from actions.json", () => {
    const p = props();
    render(<NavShell {...p} />);
    fireEvent.click(rail().getByRole("button", { name: "More ways to record" }));
    expect(items().map((m) => m.textContent)).toEqual([
      "New Recording⌘N", "New Voice Note⇧⌘N", "Import Audio Files…⌘O", "Auto-record Calls & Meetings", "Record Page",
    ]);
    expect(screen.getAllByRole("separator")).toHaveLength(2);
    const tick = screen.getByRole("menuitemcheckbox", { name: "Auto-record Calls & Meetings" });
    expect(tick).toHaveAttribute("aria-checked", "true");
    fireEvent.click(tick);
    expect(p.callRecording.toggle).toHaveBeenCalled();
    fireEvent.click(rail().getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "New Voice Note" }));
    expect(p.onVoiceNote).toHaveBeenCalled();
    fireEvent.click(rail().getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Record Page" }));
    expect(p.onHome).toHaveBeenCalled();
  });

  it("every item it shows, with its words and keys, is in actions.json", () => {
    render(<NavShell {...props()} />);
    fireEvent.click(rail().getByRole("button", { name: "More ways to record" }));
    const shown = items().map((m) => ({
      label: m.querySelector(".el-menu__label")!.textContent!, shortcut: m.querySelector("kbd")?.textContent ?? undefined, action: m.getAttribute("data-action") ?? undefined,
    }));
    expect(checkMenu(shown, "macos")).toEqual([]);
  });

  it("Windows: sentence case and Ctrl keys", () => {
    render(<NavShell {...props({ searchKeys: "Ctrl+K" })} />);
    fireEvent.click(rail().getByRole("button", { name: "More ways to record" }));
    expect(items().map((m) => m.textContent)).toEqual([
      "New recordingCtrl+N", "New voice noteCtrl+Shift+N", "Import audio files…Ctrl+O", "Auto-record calls & meetings", "Record page",
    ]);
  });

  it("right-click on either half opens the menu", () => {
    render(<NavShell {...props()} />);
    fireEvent.contextMenu(rail().getByRole("button", { name: "Record" }), { clientX: 30, clientY: 80 });
    expect(screen.getByRole("menu", { name: "More ways to record" })).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole("menu", { name: "More ways to record" }), { key: "Escape" });
    expect(screen.queryByRole("menu")).toBeNull();
    fireEvent.contextMenu(rail().getByRole("button", { name: "More ways to record" }));
    expect(screen.getByRole("menu", { name: "More ways to record" })).toBeInTheDocument();
  });

  it("on a Mac the menu is the system's own: the same items, its keys as accelerators; a pick runs the action", async () => {
    document.documentElement.dataset.platform = "macos";
    respond("native_menu_popup", null);
    const p = props();
    render(<NavShell {...p} />);
    await act(async () => undefined);
    fireEvent.click(rail().getByRole("button", { name: "More ways to record" }));
    await act(async () => undefined);
    expect(screen.queryByRole("menu")).toBeNull();
    const [call] = callsOf("native_menu_popup");
    expect(call.items).toEqual([
      { action: "file.newRecording", label: "New Recording", accelerator: "CmdOrCtrl+N", enabled: true, checked: null },
      { action: "file.newVoiceNote", label: "New Voice Note", accelerator: "Shift+CmdOrCtrl+N", enabled: true, checked: null },
      { action: "file.importAudio", label: "Import Audio Files…", accelerator: "CmdOrCtrl+O", enabled: true, checked: null },
      { action: null },
      // "&&" for the core, which draws it as one "&".
      { action: "live.autoRecord", label: "Auto-record Calls && Meetings", accelerator: null, enabled: true, checked: true },
      { action: null },
      { action: "go.recordPage", label: "Record Page", accelerator: null, enabled: true, checked: null },
    ]);
    expect(typeof call.x).toBe("number");
    // The pick comes back through the window's one listener (MainApp's, menuState.ts) to the action the shell registered.
    const stop = listenToMenuPicks("macos");
    await emitEvent("menu:action", "file.importAudio");
    stop();
    expect(p.onUpload).toHaveBeenCalledTimes(1);
  });

  it("while recording: red, ■ and the time; a press or ⌘. stops; New recording is greyed", () => {
    const p = props({ recording: true, status: { kind: "recording", text: "Recording", time: "12:04" } });
    render(<NavShell {...p} />);
    const stop = rail().getByRole("button", { name: "Stop recording, 12:04" });
    expect(rail().getByRole("group", { name: "Record" })).toHaveClass("el-rail__record--on");
    expect(rail().getByRole("group", { name: "Record" }).querySelector(".el-rail__label")!.textContent).toBe("12:04");
    expect(stop).toHaveAttribute("aria-keyshortcuts", "Meta+.");
    fireEvent.click(stop);
    expect(p.onRecord).toHaveBeenCalledTimes(1);
    fireEvent.keyDown(window, { key: ".", metaKey: true });
    // One press at a time while the core answers (the first still waits).
    expect(p.onRecord).toHaveBeenCalledTimes(1);
    fireEvent.click(rail().getByRole("button", { name: "More ways to record" }));
    expect(screen.getByRole("menuitem", { name: "New Recording" })).toHaveAttribute("aria-disabled", "true");
  });

  it("the keys: ⌘N records, ⇧⌘N a voice note, ⌘O imports, ⌘. stops (not behind a dialog)", () => {
    const p = props();
    const { rerender } = render(<NavShell {...p} />);
    fireEvent.keyDown(window, { key: "n", metaKey: true });
    expect(p.onRecord).toHaveBeenCalledTimes(1);
    fireEvent.keyDown(window, { key: "N", metaKey: true, shiftKey: true });
    expect(p.onVoiceNote).toHaveBeenCalledTimes(1);
    fireEvent.keyDown(window, { key: "o", metaKey: true });
    expect(p.onUpload).toHaveBeenCalledTimes(1);
    rerender(<NavShell {...p} recording status={{ kind: "recording", text: "Recording", time: "0:03" }} />);
    fireEvent.keyDown(window, { key: ".", metaKey: true });
    expect(p.onRecord).toHaveBeenCalledTimes(2);
    const dialog = document.createElement("div");
    dialog.setAttribute("role", "alertdialog");
    document.body.append(dialog);
    fireEvent.keyDown(window, { key: "o", metaKey: true });
    expect(p.onUpload).toHaveBeenCalledTimes(1);
    dialog.remove();
  });
});
