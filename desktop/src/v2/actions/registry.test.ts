// UX-21: one list of actions (actions.json) and its registry. Every menu sends an action id and the registry runs it;
// a menu showing a label, a shortcut or an action that isn't in the file fails here, and so do two actions with one
// shortcut.
import { afterEach, describe, expect, it, vi } from "vitest";
import * as Icons from "../components/icons";
import list from "./actions.json";
import {
  ACTIONS, CONDITIONS, MAC_SYSTEM, actionLabel, ariaKeys, available, checkMenu, findAction, macTitle, menuItems, registerAction, runAction, shortcutClashes, shortcutOf,
  type ActionContext,
} from "./registry";

const mac: ActionContext = { platform: "macos" };
const win: ActionContext = { platform: "windows" };

describe("the action list (actions.json)", () => {
  it("has every command once: ids unique, each with a label in sentence case", () => {
    const ids = list.actions.map((a) => a.id);
    expect(new Set(ids).size).toBe(ids.length);
    expect(ids.length).toBeGreaterThan(100);
    for (const a of ACTIONS) {
      expect(a.id, a.id).toMatch(/^(app|file|edit|view|go|recording|live|window|help|account|place|label|trash|player|speaker|text)\.[a-z][A-Za-z0-9]*$/);
      // Sentence case: a capital first, no Title Case words after it but names the file keeps (EchoLedger, Live Assist…).
      const rest = list.titleCase.keep.reduce((text, name) => text.split(name).join(""), a.label.slice(1));
      expect(a.label[0], a.label).toBe(a.label[0].toLocaleUpperCase());
      expect(rest.match(/\b[A-Z][a-z]+/g) ?? [], a.label).toEqual([]);
    }
  });

  it("uses only known icons, conditions and kinds", () => {
    for (const a of ACTIONS) {
      if (a.icon) expect(Object.keys(Icons), `${a.id}: ${a.icon}`).toContain(a.icon);
      for (const c of a.when) expect(Object.keys(CONDITIONS), `${a.id}: ${c}`).toContain(c);
      expect(["none", "one", "many", "oneOrMany"], a.id).toContain(a.appliesTo);
      // "…" means it asks for more before it acts; a destructive one that asks says what.
      if (a.destructive && a.label.endsWith("…")) expect(a.confirm, a.id).toBeTruthy();
    }
  });

  it("no two actions share a shortcut, on a Mac or on Windows, and none takes one macOS keeps for itself", () => {
    expect(shortcutClashes()).toEqual([]);
    for (const a of ACTIONS) {
      if (a.native) continue;
      for (const keys of [a.mac, ...a.macAlso]) if (keys) expect(MAC_SYSTEM, `${a.id}: ${keys}`).not.toContain(keys);
    }
  });

  it("finds a clash when there is one", () => {
    expect(shortcutClashes([
      { id: "a", mac: "⌘N", win: "Ctrl+N" }, { id: "b", mac: "⌘N", win: null }, { id: "c", mac: null, win: "Ctrl+N" },
    ])).toEqual(["mac ⌘N: a, b", "windows Ctrl+N: a, c"]);
  });

  it("the plan's shortcut changes (decision 12): ⌘N new recording, ⇧⌘N voice note, ⇧⌘E export, ⌘R sync now, ⌘. stop", () => {
    expect(shortcutOf("file.newRecording", "macos")).toBe("⌘N");
    expect(shortcutOf("file.newVoiceNote", "macos")).toBe("⇧⌘N");
    expect(shortcutOf("file.export", "macos")).toBe("⇧⌘E");
    expect(shortcutOf("file.syncNow", "macos")).toBe("⌘R");
    expect(shortcutOf("file.syncNow", "windows")).toBe("Ctrl+R");
    expect(shortcutOf("live.stopRecording", "macos")).toBe("⌘.");
    // ⇧⌘R and ⇧⌘V are gone.
    expect(ACTIONS.filter((a) => a.mac === "⇧⌘R" || a.mac === "⇧⌘V")).toEqual([]);
  });
});

describe("the labels", () => {
  it("Mac menus use Title Case made from the sentence; Windows, the web and the phone keep the sentence", () => {
    expect(macTitle("Move to trash")).toBe("Move to Trash");
    expect(macTitle("Save audio only")).toBe("Save Audio Only");
    expect(macTitle("Hide from screen shares")).toBe("Hide from Screen Shares");
    expect(macTitle("Search everything…")).toBe("Search Everything…");
    expect(macTitle("Start EchoLedger dictation")).toBe("Start EchoLedger Dictation");
    expect(macTitle("Ask about this recording")).toBe("Ask About This Recording");
    expect(macTitle("Auto-record calls & meetings")).toBe("Auto-record Calls & Meetings");
    expect(macTitle("Show Live Assist card")).toBe("Show Live Assist Card");
    expect(macTitle("Account & plan…")).toBe("Account & Plan…");
    expect(macTitle("For 15 minutes")).toBe("For 15 Minutes");
    expect(actionLabel("recording.moveToTrash", mac)).toBe("Move to Trash");
    expect(actionLabel("recording.moveToTrash", win)).toBe("Move to trash");
  });

  it("labels follow the app's state: Pin ↔ Unpin, Mark as Unread ↔ Mark as Read, Hide ↔ Show Sidebar, Undo <what>", () => {
    expect(actionLabel("recording.pin", { ...mac, state: { pinned: false } })).toBe("Pin");
    expect(actionLabel("recording.pin", { ...mac, state: { pinned: true } })).toBe("Unpin");
    expect(actionLabel("recording.unread", { ...mac, state: { unread: true } })).toBe("Mark as Read");
    expect(actionLabel("view.sidebar", { ...mac, state: { sidebarHidden: true } })).toBe("Show Sidebar");
    expect(actionLabel("view.sidebar", { ...win, state: { sidebarHidden: false } })).toBe("Hide sidebar");
    expect(actionLabel("recording.transcribe", { ...mac, state: { hasTranscript: true } })).toBe("Transcribe Again…");
    expect(actionLabel("edit.undo", { ...mac, state: { undoWhat: "Move to trash" } })).toBe("Undo Move to Trash");
    expect(actionLabel("edit.undo", mac)).toBe("Undo");
  });

  it("an unknown id is an error, never a silent label", () => {
    expect(() => actionLabel("file.nope", mac)).toThrow(/not in actions.json/);
    expect(findAction("file.nope")).toBeUndefined();
  });

  it("aria-keyshortcuts in the names assistive tools read", () => {
    expect(ariaKeys("⇧⌘N")).toBe("Shift+Meta+N");
    expect(ariaKeys("⌃⌘S")).toBe("Control+Meta+S");
    expect(ariaKeys("⌥⌘⌫")).toBe("Alt+Meta+Backspace");
    expect(ariaKeys("Ctrl+Shift+E")).toBe("Control+Shift+E");
    expect(ariaKeys("⌘.")).toBe("Meta+.");
  });
});

describe("one code path: a menu sends the id, the registry runs it", () => {
  afterEach(() => vi.restoreAllMocks());

  it("runs what was registered for the id, with what it works on; greyed when its conditions don't hold", () => {
    const run = vi.fn();
    const stop = registerAction("recording.pin", run);
    runAction("recording.pin", { ...mac, targets: ["r1", "r2"] });
    expect(run).toHaveBeenCalledWith(expect.objectContaining({ targets: ["r1", "r2"] }));
    stop();
    runAction("recording.pin", mac);
    expect(run).toHaveBeenCalledTimes(1);
    // Conditions: Stop recording only while recording; Play not while typing; one-recording actions with one.
    expect(available("live.stopRecording", { ...mac, state: { recording: false } })).toBe(false);
    expect(available("live.stopRecording", { ...mac, state: { recording: true } })).toBe(true);
    expect(available("recording.playPause", { ...mac, state: { typing: true, hasAudio: true }, targets: ["r1"] })).toBe(false);
    expect(available("file.rename", { ...mac, targets: ["r1", "r2"] })).toBe(false);
    expect(available("file.rename", { ...mac, targets: ["r1"] })).toBe(true);
    expect(available("recording.moveToTrash", { ...mac, targets: [] })).toBe(false);
  });

  it("makes a menu from ids: the label, icon and shortcut from the file; Mac right-click menus show no shortcuts", () => {
    const items = menuItems(["file.newRecording", "file.newVoiceNote", "file.importAudio", "-", "live.autoRecord"], { ...mac, state: { autoRecord: true, canRecord: true } });
    expect(items.map((i) => [i.label, i.shortcut, i.checked, i.separator ?? false])).toEqual([
      ["New Recording", "⌘N", undefined, false], ["New Voice Note", "⇧⌘N", undefined, false], ["Import Audio Files…", "⌘O", undefined, false],
      ["Auto-record Calls & Meetings", undefined, true, true],
    ]);
    expect(items[0].action).toBe("file.newRecording");
    const context = menuItems(["recording.pin", "recording.moveToTrash"], { ...mac, targets: ["r1"], context: true });
    expect(context.map((i) => [i.label, i.shortcut, i.danger ?? false])).toEqual([["Pin", undefined, false], ["Move to Trash", undefined, true]]);
    // Right-click menus leave out what doesn't apply; the menu bar greys it.
    expect(menuItems(["file.rename"], { ...mac, targets: ["a", "b"], context: true })).toEqual([]);
    expect(menuItems(["file.rename"], { ...mac, targets: ["a", "b"] })[0].disabled).toBe(true);
    // Windows menus may show shortcuts in right-click menus.
    expect(menuItems(["recording.moveToTrash"], { ...win, targets: ["r1"], context: true })[0].shortcut).toBe("Delete");
  });

  it("a menu with a label, a shortcut or an action not in the file fails the check", () => {
    const good = menuItems(["file.newRecording", "file.importAudio"], mac);
    expect(checkMenu(good, "macos")).toEqual([]);
    expect(checkMenu([{ label: "Record now", onPick: () => undefined }], "macos")).toEqual(['"Record now": no action in actions.json']);
    expect(checkMenu([{ ...good[0], shortcut: "⇧⌘R" }], "macos")).toEqual(['"New Recording": shortcut ⇧⌘R, actions.json says ⌘N']);
    expect(checkMenu([{ ...good[0], label: "New Thing" }], "macos")).toEqual(['"New Thing": file.newRecording is "New Recording"']);
    expect(checkMenu([{ label: "x", action: "file.nope" }], "macos")).toEqual(['"x": file.nope is not in actions.json']);
  });
});
