// D-221: the app's keys on a non-Latin keyboard layout (Urdu): a letter's key types its Urdu letter, and the key's
// place stands for the Latin key; a Latin layout keeps its own letters.
import { describe, expect, it } from "vitest";
import { latinKey } from "./latinKey";
import { matchesShortcut } from "./registry";
import { keyMatches } from "./contextMenus";

describe("latinKey (D-221)", () => {
  it("an Urdu letter, digit or mark stands for the Latin key at its place", () => {
    expect(latinKey({ key: "ج", code: "KeyJ" })).toBe("j");
    expect(latinKey({ key: "ک", code: "KeyK" })).toBe("k");
    expect(latinKey({ key: "ف", code: "KeyF", shiftKey: true })).toBe("f");
    expect(latinKey({ key: "۱", code: "Digit1" })).toBe("1");
    expect(latinKey({ key: "ظ", code: "Slash" })).toBe("/");
    expect(latinKey({ key: "؟", code: "Slash", shiftKey: true })).toBe("?");
    expect(latinKey({ key: "ج", code: "BracketLeft" })).toBe("[");
  });

  it("a Latin layout keeps its own letters (AZERTY's A is A), and named keys stay as they are", () => {
    expect(latinKey({ key: "a", code: "KeyQ" })).toBe("a");
    expect(latinKey({ key: "/", code: "Digit7", shiftKey: true })).toBe("/");
    expect(latinKey({ key: "ArrowDown", code: "ArrowDown" })).toBe("ArrowDown");
    expect(latinKey({ key: "Enter", code: "Enter" })).toBe("Enter");
    // An Urdu character from no known place, or without a code (a synthetic event), stays as typed.
    expect(latinKey({ key: "ے", code: "IntlRo" })).toBe("ے");
    expect(latinKey({ key: "ج" })).toBe("ج");
  });

  it("the menus' shortcuts answer on an Urdu layout too: ⌘N, Ctrl+T, ⇧⌘L", () => {
    const press = (key: string, code: string, mods: Partial<KeyboardEvent> = {}) =>
      ({ key, code, metaKey: false, ctrlKey: false, altKey: false, shiftKey: false, ...mods }) as KeyboardEvent;
    expect(matchesShortcut(press("ن", "KeyN", { metaKey: true }), "⌘N")).toBe(true);
    expect(matchesShortcut(press("ن", "KeyN", { metaKey: true }), "⌘O")).toBe(false);
    expect(keyMatches(press("ت", "KeyT", { ctrlKey: true }), "Ctrl+T")).toBe(true);
    expect(keyMatches(press("ل", "KeyL", { metaKey: true, shiftKey: true }), "⇧⌘L")).toBe(true);
  });
});
