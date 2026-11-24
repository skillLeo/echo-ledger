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
import { ACTIONS } from "../v2/actions/registry";

const read = (...path: string[]) => readFileSync(join(__dirname, ...path), "utf8");
let stop: (() => void) | null = null;
afterEach(() => {
  stop?.();
  stop = null;
  document.body.innerHTML = "";
});
const key = (k: string, mods: Partial<KeyboardEventInit> = {}, target: EventTarget = document.body) => fireEvent.keyDown(target, { key: k, ...mods });

describe("browser keys (FIX-10)", () => {
  const k = (key: string, mods: Partial<KeyboardEvent>, mac: boolean) =>
    browserKey({ key, metaKey: false, ctrlKey: false, altKey: false, shiftKey: false, ...mods } as KeyboardEvent, mac);

  it("only the true browser leftovers: reload, print the page, the developer tools (⌘ on a Mac, Ctrl on Windows)", () => {
    // A Mac.
    expect(k("F5", {}, true)).toBe("reload");
    expect(k("r", { metaKey: true }, true)).toBe("reload");
    expect(k("R", { metaKey: true, shiftKey: true }, true)).toBe("reload");
    expect(k("p", { metaKey: true }, true)).toBe("print");
    expect(k("i", { metaKey: true, altKey: true }, true)).toBe("devtools");
    // Windows.
    expect(k("F5", {}, false)).toBe("reload");
    expect(k("r", { ctrlKey: true }, false)).toBe("reload");
    expect(k("p", { ctrlKey: true }, false)).toBe("print");
    expect(k("F12", {}, false)).toBe("devtools");
    expect(k("I", { ctrlKey: true, shiftKey: true }, false)).toBe("devtools");
    expect(k("BrowserBack", {}, false)).toBe("navigate");
  });

  it("never the app's keys or a text field's: ⌘F and find-next, ⌃⌘ anything, ⌥/Alt and the arrows, a Mac's Ctrl keys", () => {
    for (const mac of [true, false]) {
      const mod = mac ? { metaKey: true } : { ctrlKey: true };
      expect(k("f", mod, mac)).toBeNull();
      expect(k("g", mod, mac)).toBeNull();
      expect(k("F3", {}, mac)).toBeNull();
      expect(k("s", mod, mac)).toBeNull();
      expect(k("s", { metaKey: true, ctrlKey: true }, mac)).toBeNull();
      expect(k("r", { metaKey: true, ctrlKey: true }, mac)).toBeNull();
      expect(k("ArrowLeft", { altKey: true }, mac)).toBeNull();
      expect(k("ArrowRight", { altKey: true, shiftKey: true }, mac)).toBeNull();
      expect(k("[", mod, mac)).toBeNull();
      expect(k("k", mod, mac)).toBeNull();
    }
    // On a Mac, Ctrl+P, Ctrl+F, Ctrl+H, Ctrl+R move and edit text.
    for (const letter of ["p", "f", "h", "r"]) expect(k(letter, { ctrlKey: true }, true)).toBeNull();
    // On a Mac there is no Browser Back key of the web view's.
    expect(k("BrowserBack", {}, true)).toBeNull();
  });

  it("in a release build they do nothing of the browser's; F5 and ⌘R sync now, ⌘P prints the open transcript", () => {
    const sync = vi.fn();
    stop = installNativeFeel({ release: true, mac: true, onSyncNow: sync });
    expect(key("F5")).toBe(false);
    expect(key("r", { metaKey: true })).toBe(false);
    expect(sync).toHaveBeenCalledTimes(2);
    // ⌘P: a page with a transcript open takes it first (its own listener); otherwise nothing prints the page.
    const print = vi.fn((e: KeyboardEvent) => e.preventDefault());
    document.addEventListener("keydown", print);
    key("p", { metaKey: true });
    expect(print).toHaveBeenCalledTimes(1);
    document.removeEventListener("keydown", print);
    expect(key("p", { metaKey: true })).toBe(false);
    expect(key("i", { metaKey: true, altKey: true })).toBe(false);
    expect(sync).toHaveBeenCalledTimes(2);
    // Not claimed: the app's and a text field's keys reach them untouched.
    for (const [k2, mods] of [["f", { metaKey: true }], ["s", { metaKey: true }], ["s", { metaKey: true, ctrlKey: true }], ["ArrowLeft", { altKey: true }], ["p", { ctrlKey: true }]] as const) {
      expect(key(k2, mods), `${k2}`).toBe(true);
    }
  });

  it("never takes a key from a text field (the title row's search, its Esc, a note)", () => {
    const sync = vi.fn();
    stop = installNativeFeel({ release: true, mac: true, onSyncNow: sync });
    document.body.innerHTML = `<input id="search" type="search" /><div id="note" contenteditable="true">x</div>`;
    for (const id of ["search", "note"]) {
      const field = document.getElementById(id)!;
      expect(key("r", { metaKey: true }, field)).toBe(true);
      expect(key("p", { metaKey: true }, field)).toBe(true);
      expect(key("Escape", {}, field)).toBe(true);
      expect(key("f", { metaKey: true }, field)).toBe(true);
    }
    expect(sync).not.toHaveBeenCalled();
  });

  it("a held key repeats: stopped each time, acted on once", () => {
    const sync = vi.fn();
    stop = installNativeFeel({ release: true, mac: true, onSyncNow: sync });
    expect(key("r", { metaKey: true })).toBe(false);
    expect(key("r", { metaKey: true, repeat: true })).toBe(false);
    expect(key("F5", { repeat: true })).toBe(false);
    expect(sync).toHaveBeenCalledTimes(1);
  });

  it("no key of the app's own commands is a browser key, but Sync Now's and Print Transcript's (answered here)", () => {
    // A shortcut as actions.json writes it, pressed: a Mac's "⌥⌘I", Windows's "Ctrl+Shift+I", "F5".
    const NAMES: Record<string, string> = { "⌫": "Backspace", "⌦": "Delete", "←": "ArrowLeft", "→": "ArrowRight", "↑": "ArrowUp", "↓": "ArrowDown", Esc: "Escape", Del: "Delete", Space: " " };
    const press = (shortcut: string, mac: boolean) => {
      const mods = { metaKey: false, ctrlKey: false, altKey: false, shiftKey: false };
      let rest = shortcut;
      if (mac) {
        const marks: Record<string, keyof typeof mods> = { "⌃": "ctrlKey", "⌥": "altKey", "⇧": "shiftKey", "⌘": "metaKey" };
        while (marks[rest[0]]) { mods[marks[rest[0]]] = true; rest = rest.slice(1); }
      } else {
        const parts = shortcut.split("+");
        rest = parts.pop() || "+";
        for (const part of parts) mods[({ Ctrl: "ctrlKey", Alt: "altKey", Shift: "shiftKey" } as const)[part as "Ctrl"]] = true;
      }
      const named = NAMES[rest] ?? rest;
      return { key: named.length === 1 ? named.toLowerCase() : named, ...mods };
    };
    const taken: string[] = [];
    for (const a of ACTIONS) {
      for (const [mac, keys] of [[true, [a.mac, ...a.macAlso]], [false, [a.win, ...a.winAlso]]] as const) {
        for (const shortcut of keys) {
          if (!shortcut) continue;
          const what = browserKey(press(shortcut, mac) as KeyboardEvent, mac);
          if (what) taken.push(`${a.id} ${shortcut}: ${what}`);
        }
      }
    }
    expect(taken.sort()).toEqual(["file.printTranscript Ctrl+P: print", "file.printTranscript ⌘P: print", "file.syncNow Ctrl+R: reload", "file.syncNow F5: reload", "file.syncNow ⌘R: reload"]);
    // The guard needs nothing of the app's: it runs before the app.
    expect(read("nativeFeel.ts")).not.toMatch(/^import /m);
  });

  it("never a key the app's own commands have (⌥⌘C and Ctrl+Shift+C copy the transcript)", () => {
    expect(k("c", { metaKey: true, altKey: true }, true)).toBeNull();
    expect(k("C", { ctrlKey: true, shiftKey: true }, false)).toBeNull();
    // Its own two stay: Sync Now and Print Transcript are what ⌘R and ⌘P become here.
    expect(k("r", { metaKey: true }, true)).toBe("reload");
    expect(k("p", { metaKey: true }, true)).toBe("print");
  });

  it("knows the keys on an Urdu or Arabic keyboard by where they are (e.code)", () => {
    const urdu = (key: string, code: string, mods: Partial<KeyboardEvent>, mac: boolean) =>
      browserKey({ key, code, metaKey: false, ctrlKey: false, altKey: false, shiftKey: false, ...mods } as KeyboardEvent, mac);
    expect(urdu("ق", "KeyR", { metaKey: true }, true)).toBe("reload");
    expect(urdu("پ", "KeyP", { ctrlKey: true }, false)).toBe("print");
    expect(urdu("ی", "KeyI", { metaKey: true, altKey: true }, true)).toBe("devtools");
    expect(urdu("ف", "KeyF", { metaKey: true }, true)).toBeNull();
  });

  it("Windows: Ctrl+R syncs, Ctrl+P and F12 do nothing", () => {
    const sync = vi.fn();
    stop = installNativeFeel({ release: true, mac: false, onSyncNow: sync });
    expect(key("r", { ctrlKey: true })).toBe(false);
    expect(sync).toHaveBeenCalledTimes(1);
    expect(key("p", { ctrlKey: true })).toBe(false);
    expect(key("F12")).toBe(false);
    expect(key("f", { ctrlKey: true })).toBe(true);
    expect(key("ArrowLeft", { altKey: true })).toBe(true);
  });

  it("leaves what the app takes first (a listener added after it, on the window) and every other key alone", () => {
    const sync = vi.fn();
    stop = installNativeFeel({ release: true, mac: true, onSyncNow: sync });
    const reload = vi.fn((e: KeyboardEvent) => { if (e.key === "r" || e.key === "F5") e.preventDefault(); });
    window.addEventListener("keydown", reload);
    expect(key("r", { metaKey: true })).toBe(false);
    expect(key("F5")).toBe(false);
    expect(reload).toHaveBeenCalledTimes(2);
    expect(sync).not.toHaveBeenCalled();
    window.removeEventListener("keydown", reload);
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

  it("on the selected text itself (a transcript's words) the system's menu stays; not on what holds it", () => {
    stop = installNativeFeel({ release: true, onSyncNow: () => undefined });
    document.body.innerHTML = `<ol id="list"><li id="line"><span id="words">Assalam o alaikum, the invoice is due</span> </li></ol>`;
    const words = document.getElementById("words")!;
    // jsdom draws nothing: the selected words sit at 100–300 × 40–60.
    const drawn = [{ left: 100, right: 300, top: 40, bottom: 60 }] as unknown as DOMRectList;
    const had = Object.getOwnPropertyDescriptor(Range.prototype, "getClientRects");
    Object.defineProperty(Range.prototype, "getClientRects", { configurable: true, value: () => drawn });
    try {
      const range = document.createRange();
      range.selectNodeContents(words);
      window.getSelection()!.removeAllRanges();
      window.getSelection()!.addRange(range);
      expect(fireEvent.contextMenu(words, { clientX: 150, clientY: 50 })).toBe(true);
      // The line that holds it, the list, the page: a press beside the words, nothing.
      expect(fireEvent.contextMenu(document.getElementById("line")!, { clientX: 400, clientY: 50 })).toBe(false);
      expect(fireEvent.contextMenu(document.getElementById("list")!, { clientX: 20, clientY: 80 })).toBe(false);
      expect(fireEvent.contextMenu(document.body, { clientX: 500, clientY: 500 })).toBe(false);
      // Select All: the page is selected, but only a press on drawn text keeps the menu.
      const all = document.createRange();
      all.selectNodeContents(document.body);
      window.getSelection()!.removeAllRanges();
      window.getSelection()!.addRange(all);
      expect(fireEvent.contextMenu(document.body, { clientX: 500, clientY: 500 })).toBe(false);
      expect(fireEvent.contextMenu(words, { clientX: 120, clientY: 45 })).toBe(true);
      // Nothing selected (a collapsed selection): nothing.
      window.getSelection()!.collapse(words, 0);
      expect(fireEvent.contextMenu(words, { clientX: 150, clientY: 50 })).toBe(false);
    } finally {
      if (had) Object.defineProperty(Range.prototype, "getClientRects", had);
      else delete (Range.prototype as unknown as { getClientRects?: unknown }).getClientRects;
      window.getSelection()!.removeAllRanges();
    }
  });

  it("after Select All only a press on drawn text keeps the system's menu, not one on the boxes that hold it", () => {
    stop = installNativeFeel({ release: true, onSyncNow: () => undefined });
    document.body.innerHTML = `<ol id="list"><li id="line"><span id="words">Assalam o alaikum</span> <span id="more">the invoice is due</span></li></ol>`;
    // As a web view draws them: each text where it is, and each element inside a range as its whole box.
    const boxes = new Map<Node, { left: number; right: number; top: number; bottom: number }>([
      [document.getElementById("words")!.firstChild!, { left: 100, right: 220, top: 40, bottom: 60 }],
      [document.getElementById("more")!.firstChild!, { left: 230, right: 380, top: 40, bottom: 60 }],
      [document.getElementById("list")!, { left: 0, right: 800, top: 0, bottom: 400 }],
      [document.getElementById("line")!, { left: 0, right: 800, top: 30, bottom: 70 }],
    ]);
    const had = Object.getOwnPropertyDescriptor(Range.prototype, "getClientRects");
    Object.defineProperty(Range.prototype, "getClientRects", {
      configurable: true,
      value(this: Range) {
        const out: unknown[] = [];
        for (const [node, box] of boxes) {
          const inside = node.nodeType === Node.TEXT_NODE
            ? this.intersectsNode(node)
            : this.comparePoint(node, 0) === 0 && this.comparePoint(node, node.childNodes.length) === 0;
          if (inside) out.push(box);
        }
        return out as unknown as DOMRectList;
      },
    });
    try {
      const all = document.createRange();
      all.selectNodeContents(document.body);
      window.getSelection()!.removeAllRanges();
      window.getSelection()!.addRange(all);
      // The whole range's rects cover the list and the line: a press on their blank space is still no text.
      expect([...(all.getClientRects() as unknown as unknown[])]).toHaveLength(4);
      expect(fireEvent.contextMenu(document.getElementById("list")!, { clientX: 600, clientY: 300 })).toBe(false);
      expect(fireEvent.contextMenu(document.getElementById("line")!, { clientX: 500, clientY: 50 })).toBe(false);
      // On either text: the system's menu.
      expect(fireEvent.contextMenu(document.getElementById("words")!, { clientX: 150, clientY: 50 })).toBe(true);
      expect(fireEvent.contextMenu(document.getElementById("more")!, { clientX: 300, clientY: 50 })).toBe(true);
      // A selection inside one text (part of the words): still found.
      const part = document.createRange();
      part.setStart(document.getElementById("more")!.firstChild!, 4);
      part.setEnd(document.getElementById("more")!.firstChild!, 11);
      window.getSelection()!.removeAllRanges();
      window.getSelection()!.addRange(part);
      expect(fireEvent.contextMenu(document.getElementById("more")!, { clientX: 300, clientY: 50 })).toBe(true);
    } finally {
      if (had) Object.defineProperty(Range.prototype, "getClientRects", had);
      else delete (Range.prototype as unknown as { getClientRects?: unknown }).getClientRects;
      window.getSelection()!.removeAllRanges();
    }
  });

  it("after Select All a press on a control's words (user-select: none, never drawn as selected) is no press on text", () => {
    stop = installNativeFeel({ release: true, onSyncNow: () => undefined });
    document.body.innerHTML = `<div id="bar" style="user-select: none"><button id="save"><span id="label">Save</span></button></div>`
      + `<p id="words" style="user-select: text">the invoice is due</p><span id="plain">Assalam o alaikum</span>`;
    const boxes = new Map<Node, { left: number; right: number; top: number; bottom: number }>([
      [document.getElementById("label")!.firstChild!, { left: 10, right: 60, top: 10, bottom: 30 }],
      [document.getElementById("words")!.firstChild!, { left: 100, right: 300, top: 40, bottom: 60 }],
      [document.getElementById("plain")!.firstChild!, { left: 100, right: 300, top: 70, bottom: 90 }],
    ]);
    const had = Object.getOwnPropertyDescriptor(Range.prototype, "getClientRects");
    Object.defineProperty(Range.prototype, "getClientRects", {
      configurable: true,
      value(this: Range) {
        return [...boxes].filter(([node]) => this.intersectsNode(node)).map(([, box]) => box) as unknown as DOMRectList;
      },
    });
    try {
      const all = document.createRange();
      all.selectNodeContents(document.body);
      window.getSelection()!.removeAllRanges();
      window.getSelection()!.addRange(all);
      // The button's words: its own menu's place, not the system's (the page's menu is held back).
      expect(fireEvent.contextMenu(document.getElementById("label")!, { clientX: 30, clientY: 20 })).toBe(false);
      // Selectable text, set or by default ("auto" all the way up): the system's menu.
      expect(fireEvent.contextMenu(document.getElementById("words")!, { clientX: 150, clientY: 50 })).toBe(true);
      expect(fireEvent.contextMenu(document.getElementById("plain")!, { clientX: 150, clientY: 80 })).toBe(true);
      // A selection inside the control's words alone: still not the system's menu.
      const inside = document.createRange();
      inside.setStart(document.getElementById("label")!.firstChild!, 0);
      inside.setEnd(document.getElementById("label")!.firstChild!, 4);
      window.getSelection()!.removeAllRanges();
      window.getSelection()!.addRange(inside);
      expect(fireEvent.contextMenu(document.getElementById("label")!, { clientX: 30, clientY: 20 })).toBe(false);
    } finally {
      if (had) Object.defineProperty(Range.prototype, "getClientRects", had);
      else delete (Range.prototype as unknown as { getClientRects?: unknown }).getClientRects;
      window.getSelection()!.removeAllRanges();
    }
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
    expect(css).toMatch(/:where\(input, textarea, \[contenteditable="true"\], \.el-toast__message\) \{ -webkit-user-select: text; user-select: text; \}/);
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
    expect(rust).toMatch(/if !cfg!\(debug_assertions\)[\s\S]*SetAreBrowserAcceleratorKeysEnabled/);
    expect(rust).toContain("SetIsPasswordAutosaveEnabled(false)");
    expect(rust).toContain("SetIsGeneralAutofillEnabled(false)");
    expect(read("..", "..", "src-tauri", "src", "lifecycle.rs")).toContain("crate::native_feel::quiet_browser(&window);");
  });
});

describe("the app's own keys come first (FIX-10 with the title row)", () => {
  it("installed before the app (as main.tsx does), it never takes ⌘F, Alt+← → or ⌘[ ⌘] from the window's own listeners", () => {
    stop = installNativeFeel({ release: true, mac: true, onSyncNow: () => undefined });
    // The shell's listener (NavShell: ⌘F's search, Back and Forward), added after, on the window too.
    const seen: string[] = [];
    const shell = (e: KeyboardEvent) => {
      if (e.defaultPrevented) return;
      if ((e.metaKey && (e.key === "f" || e.key === "[" || e.key === "]")) || (e.altKey && e.key.startsWith("Arrow"))) {
        e.preventDefault();
        seen.push(`${e.metaKey ? "⌘" : "Alt+"}${e.key}`);
      }
    };
    window.addEventListener("keydown", shell);
    try {
      expect(key("f", { metaKey: true })).toBe(false);
      expect(key("[", { metaKey: true })).toBe(false);
      expect(key("]", { metaKey: true })).toBe(false);
      expect(key("ArrowLeft", { altKey: true })).toBe(false);
      expect(key("ArrowRight", { altKey: true })).toBe(false);
      expect(seen).toEqual(["⌘f", "⌘[", "⌘]", "Alt+ArrowLeft", "Alt+ArrowRight"]);
    } finally {
      window.removeEventListener("keydown", shell);
    }
  });

  it("a key nobody took is still stopped, even when one before it stopped its own propagation", () => {
    stop = installNativeFeel({ release: true, mac: true, onSyncNow: () => undefined });
    const stopper = (e: KeyboardEvent) => e.stopPropagation();
    document.body.addEventListener("keydown", stopper);
    key("p", { metaKey: true });
    document.body.removeEventListener("keydown", stopper);
    // The next key, an ordinary one, isn't taken by what was left waiting for the stopped one.
    expect(key("a")).toBe(true);
    expect(key("p", { metaKey: true })).toBe(false);
  });
});
