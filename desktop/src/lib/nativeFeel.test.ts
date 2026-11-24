// FIX-10 (PLAN_V2_1_POLISH): no browser leftovers. In a release build: no browser context menu outside text fields, no
// reload, print-page, find bar, save, source, downloads, history or devtools keys; F5 and ⌘R (Ctrl+R) sync now instead,
// ⌘P (Ctrl+P) prints the open transcript where a page offers it, ⌘F stays the app's search. And the checklist: the arrow
// cursor, UI text that can't be selected, nothing dragged out, no whole-window bounce, focus rings for the keyboard only,
// no white flash, and WebView2's browser keys, autofill and password saving off on Windows.
import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { browserKey, installNativeFeel } from "./nativeFeel";

const read = (...path: string[]) => readFileSync(join(__dirname, ...path), "utf8");
let stop: (() => void) | null = null;
afterEach(() => {
  stop?.();
  stop = null;
  document.body.innerHTML = "";
});
const key = (k: string, mods: Partial<KeyboardEventInit> = {}, target: EventTarget = document.body) => fireEvent.keyDown(target, { key: k, ...mods });

describe("browser keys (FIX-10)", () => {
  it("knows the browser's own keys on a Mac and on Windows", () => {
    const k = (key: string, mods: Partial<KeyboardEvent> = {}) => browserKey({ key, metaKey: false, ctrlKey: false, altKey: false, shiftKey: false, ...mods } as KeyboardEvent);
    expect(k("F5")).toBe("reload");
    expect(k("r", { metaKey: true })).toBe("reload");
    expect(k("R", { ctrlKey: true, shiftKey: true })).toBe("reload");
    expect(k("p", { metaKey: true })).toBe("print");
    expect(k("f", { ctrlKey: true })).toBe("find");
    expect(k("g", { metaKey: true })).toBe("find");
    expect(k("F3")).toBe("find");
    expect(k("s", { ctrlKey: true })).toBe("save");
    expect(k("u", { ctrlKey: true })).toBe("source");
    expect(k("j", { ctrlKey: true })).toBe("downloads");
    expect(k("h", { ctrlKey: true })).toBe("history");
    expect(k("F12")).toBe("devtools");
    expect(k("I", { ctrlKey: true, shiftKey: true })).toBe("devtools");
    expect(k("i", { metaKey: true, altKey: true })).toBe("devtools");
    expect(k("ArrowLeft", { altKey: true })).toBe("navigate");
    expect(k("BrowserBack")).toBe("navigate");
    // Not the browser's: typing, the app's own keys.
    expect(k("r")).toBeNull();
    expect(k("k", { metaKey: true })).toBeNull();
    expect(k("1", { metaKey: true })).toBeNull();
  });

  it("in a release build they do nothing of the browser's; F5 and ⌘R sync now, ⌘P prints the open transcript", () => {
    const sync = vi.fn();
    stop = installNativeFeel({ release: true, onSyncNow: sync });
    expect(key("F5")).toBe(false);
    expect(key("r", { metaKey: true })).toBe(false);
    expect(key("r", { ctrlKey: true })).toBe(false);
    expect(sync).toHaveBeenCalledTimes(3);
    // ⌘P: a page with a transcript open takes it first (its own listener); otherwise nothing prints the page.
    const print = vi.fn((e: KeyboardEvent) => e.preventDefault());
    document.addEventListener("keydown", print);
    key("p", { metaKey: true });
    expect(print).toHaveBeenCalledTimes(1);
    document.removeEventListener("keydown", print);
    for (const [k, mods] of [["p", { metaKey: true }], ["f", { ctrlKey: true }], ["s", { metaKey: true }], ["F12", {}], ["i", { metaKey: true, altKey: true }], ["j", { ctrlKey: true }], ["ArrowLeft", { altKey: true }]] as const) {
      expect(key(k, mods), `${k}`).toBe(false);
    }
    expect(sync).toHaveBeenCalledTimes(3);
  });

  it("leaves what the app takes first (⌘F's search, Alt+← Back) and every other key alone", () => {
    stop = installNativeFeel({ release: true, onSyncNow: () => undefined });
    const search = vi.fn((e: KeyboardEvent) => { if (e.key === "f") e.preventDefault(); });
    window.addEventListener("keydown", search);
    expect(key("f", { metaKey: true })).toBe(false);
    expect(search).toHaveBeenCalled();
    window.removeEventListener("keydown", search);
    expect(key("a")).toBe(true);
    expect(key("k", { metaKey: true })).toBe(true);
  });

  it("in a development build the browser keeps its keys (reload, devtools)", () => {
    const sync = vi.fn();
    stop = installNativeFeel({ release: false, onSyncNow: sync });
    expect(key("F5")).toBe(true);
    expect(key("i", { metaKey: true, altKey: true })).toBe(true);
    expect(sync).not.toHaveBeenCalled();
  });
});

describe("the right-click (FIX-10)", () => {
  it("does nothing outside our menus and text fields; a text field keeps the system's Cut, Copy and Paste", () => {
    stop = installNativeFeel({ release: true, onSyncNow: () => undefined });
    document.body.innerHTML = `<p id="text">Words</p><input id="field" /><textarea id="area"></textarea><div id="editable" contenteditable="true">x</div><img id="logo" alt="" />`;
    expect(fireEvent.contextMenu(document.getElementById("text")!)).toBe(false);
    expect(fireEvent.contextMenu(document.getElementById("logo")!)).toBe(false);
    expect(fireEvent.contextMenu(document.getElementById("field")!)).toBe(true);
    expect(fireEvent.contextMenu(document.getElementById("area")!)).toBe(true);
    expect(fireEvent.contextMenu(document.getElementById("editable")!)).toBe(true);
  });

  it("our own menus open as before (they take the press first)", () => {
    stop = installNativeFeel({ release: true, onSyncNow: () => undefined });
    document.body.innerHTML = `<button id="row">A recording</button>`;
    const ours = vi.fn((e: Event) => e.preventDefault());
    document.getElementById("row")!.addEventListener("contextmenu", ours);
    expect(fireEvent.contextMenu(document.getElementById("row")!)).toBe(false);
    expect(ours).toHaveBeenCalledTimes(1);
  });

  it("in a development build the browser's menu (Inspect) stays", () => {
    stop = installNativeFeel({ release: false, onSyncNow: () => undefined });
    document.body.innerHTML = `<p id="text">Words</p>`;
    expect(fireEvent.contextMenu(document.getElementById("text")!)).toBe(true);
  });
});

describe("the checklist (FIX-10)", () => {
  const css = read("..", "styles.css") + read("..", "v2", "styles", "v2.css");
  it("the arrow cursor on buttons, rows, tabs, menus and labels; the hand only for real links", () => {
    expect(css).toMatch(/:where\(button, \[role="button"\], \[role="tab"\], \[role="option"\], \[role="menuitem"\], \[role="menuitemcheckbox"\], \[role="menuitemradio"\], \[role="switch"\], \[role="checkbox"\], \[role="radio"\], label, summary, select\) \{ cursor: default !important; \}/);
    expect(css).toContain("a[href] { cursor: pointer; }");
  });

  it("UI text can't be selected; content can", () => {
    expect(css).toMatch(/:where\(button, \[role="button"\], \[role="tab"\], \[role="menu"\], \[role="menuitem"\], label, nav, header, \.el-rail, \.el-panel__head, \.el-toast, \.el-page__head, \.sidebar, \.topbar\) \{ -webkit-user-select: none; user-select: none; \}/);
    expect(css).toMatch(/:where\(input, textarea, \[contenteditable="true"\]\) \{ -webkit-user-select: text; user-select: text; \}/);
  });

  it("images, logos and links can't be dragged out", () => {
    expect(css).toContain(":where(img, svg, a) { -webkit-user-drag: none; }");
  });

  it("no rubber-band bounce of the whole window; lists keep theirs", () => {
    expect(css).toContain("html, body { overscroll-behavior: none; }");
  });

  it("focus rings only for the keyboard", () => {
    expect(css).toContain(":focus:not(:focus-visible) { outline: none; }");
  });

  it("no white flash: the window shows after its first paint, and its background follows the theme", () => {
    const conf = JSON.parse(read("..", "..", "src-tauri", "tauri.conf.json"));
    expect(conf.app.windows.find((w: { label: string }) => w.label === "main").visible).toBe(false);
    expect(read("..", "..", "index.html")).toMatch(/<meta name="color-scheme" content="light dark"/);
    expect(read("..", "styles.css")).toContain("html { background: var(--bg); }");
  });

  it("the main window installs it, in release builds only", () => {
    const main = read("..", "main.tsx");
    expect(main).toContain("installNativeFeel({ release: import.meta.env.PROD,");
  });

  it("on Windows, WebView2's browser keys, autofill and password saving are off", () => {
    const rust = read("..", "..", "src-tauri", "src", "native_feel.rs");
    expect(rust).toContain("SetAreBrowserAcceleratorKeysEnabled(false)");
    expect(rust).toContain("SetIsPasswordAutosaveEnabled(false)");
    expect(rust).toContain("SetIsGeneralAutofillEnabled(false)");
    expect(read("..", "..", "src-tauri", "src", "lifecycle.rs")).toContain("crate::native_feel::quiet_browser(&window);");
  });
});
