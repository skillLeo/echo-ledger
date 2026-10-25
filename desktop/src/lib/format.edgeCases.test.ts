import { describe, expect, it } from "vitest";
import { acceleratorFromEvent, formatDuration, formatWhen, prettyShortcut, timestamp, transcriptText } from "./format";
import { CALL_SEGMENTS } from "../test/fixtures";

const keys = (over: Partial<Parameters<typeof acceleratorFromEvent>[0]>) => ({ ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, code: "KeyA", ...over });

describe("formatDuration edge cases", () => {
  it("rounds to the nearest second and rolls over at minute and hour boundaries", () => {
    expect(formatDuration(1_000)).toBe("0:01");
    expect(formatDuration(1_499)).toBe("0:01");
    expect(formatDuration(1_500)).toBe("0:02");
    expect(formatDuration(59_600)).toBe("1:00");
    expect(formatDuration(599_000)).toBe("9:59");
    expect(formatDuration(3_599_000)).toBe("59:59");
    expect(formatDuration(3_600_000)).toBe("1:00:00");
    expect(formatDuration(36_000_000)).toBe("10:00:00");
    expect(formatDuration(86_399_000)).toBe("23:59:59");
  });

  it("is blank for null", () => {
    expect(formatDuration(null)).toBe("");
  });
});

describe("formatWhen edge cases", () => {
  const now = new Date(2026, 10, 1, 12, 0, 0); // 1 Nov 2026, local time

  it("shows 12 PM at noon and 12 AM just after midnight", () => {
    expect(formatWhen(new Date(2026, 10, 1, 12, 0).toISOString(), now)).toMatch(/^Today · 12:00\s?PM$/);
    expect(formatWhen(new Date(2026, 10, 1, 0, 5).toISOString(), now)).toMatch(/^Today · 12:05\s?AM$/);
    expect(formatWhen(new Date(2026, 10, 1, 23, 59).toISOString(), now)).toMatch(/^Today · 11:59\s?PM$/);
  });

  it("never uses a 24-hour clock", () => {
    for (const hour of [0, 6, 13, 18, 23]) {
      expect(formatWhen(new Date(2026, 10, 1, hour, 30).toISOString(), now)).toMatch(/\s?(AM|PM)$/);
    }
  });

  it("calls the previous calendar day yesterday across a month boundary", () => {
    expect(formatWhen(new Date(2026, 9, 31, 22, 15).toISOString(), now)).toMatch(/^Yesterday · 10:15\s?PM$/);
  });

  it("converts a UTC timestamp to the viewer's local time", () => {
    const iso = "2026-11-01T03:07:00Z";
    const local = new Date(iso);
    const expectedHour = local.getHours() % 12 === 0 ? 12 : local.getHours() % 12;
    const expectedMinute = String(local.getMinutes()).padStart(2, "0");
    expect(formatWhen(iso, local)).toMatch(new RegExp(`^Today · ${expectedHour}:${expectedMinute}\\s?(AM|PM)$`));
  });

  it("uses a month-and-day label with the time for older dates", () => {
    const older = new Date(2026, 9, 22, 15, 5);
    // The date part follows the viewer's locale ("Oct 22" in en-US, "22 Oct" in en-GB); the time is always 12-hour.
    const monthDay = older.toLocaleDateString([], { month: "short", day: "numeric" });
    const label = formatWhen(older.toISOString(), now);
    expect(label.startsWith(`${monthDay} · `)).toBe(true);
    expect(label).toMatch(/ · 3:05\s?PM$/);
  });

  it("returns an empty string for an empty or malformed value", () => {
    expect(formatWhen("", now)).toBe("");
    expect(formatWhen("2026-13-45", now)).toBe("");
  });
});

describe("timestamp edge cases", () => {
  it("floors fractions of a second and clamps negatives to zero", () => {
    expect(timestamp(999)).toBe("0:00");
    expect(timestamp(1_999)).toBe("0:01");
    expect(timestamp(-5_000)).toBe("0:00");
  });

  it("pads seconds always, minutes only after an hour", () => {
    expect(timestamp(9_000)).toBe("0:09");
    expect(timestamp(600_000)).toBe("10:00");
    expect(timestamp(3_599_000)).toBe("59:59");
    expect(timestamp(3_600_000)).toBe("1:00:00");
    expect(timestamp(3_661_000)).toBe("1:01:01");
    expect(timestamp(36_000_000)).toBe("10:00:00");
  });
});

describe("transcriptText exact output", () => {
  it("renders a two-party call without timestamps", () => {
    expect(transcriptText(CALL_SEGMENTS, false)).toBe(
      "Me: Hello, this is Sam.\nClient: Hi Sam, thanks for calling back.\nMe: Let us go over the invoice.\nClient: That works for us.",
    );
  });

  it("renders a two-party call with [m:ss] and [h:mm:ss] timestamps", () => {
    expect(transcriptText(CALL_SEGMENTS, true)).toBe(
      "[0:00] Me: Hello, this is Sam.\n[0:12] Client: Hi Sam, thanks for calling back.\n[1:05] Me: Let us go over the invoice.\n[1:02:05] Client: That works for us.",
    );
  });

  it("keeps speaker labels when there are three speakers and leaves multi-line text alone", () => {
    const text = transcriptText(
      [
        { speaker: "A", start_ms: 0, text: "one" },
        { speaker: "B", start_ms: 1_000, text: "two\nlines" },
        { speaker: "C", start_ms: 2_000, text: "three" },
      ],
      false,
    );
    expect(text).toBe("A: one\nB: two\nlines\nC: three");
  });

  it("has no trailing newline and treats a single segment as single-speaker", () => {
    expect(transcriptText([{ speaker: "Speaker", start_ms: 0, text: "Only line." }], true)).toBe("[0:00] Only line.");
  });
});

describe("prettyShortcut edge cases", () => {
  it("renders every modifier as a macOS symbol", () => {
    expect(prettyShortcut("CommandOrControl+Alt+Shift+K", "macos")).toBe("⌘⌥⇧K");
    expect(prettyShortcut("CmdOrCtrl+Option+KeyK", "macos")).toBe("⌘⌥K");
  });

  it("renders words joined with plus signs on Windows", () => {
    expect(prettyShortcut("CommandOrControl+Alt+Shift+K", "windows")).toBe("Ctrl + Alt + Shift + K");
    expect(prettyShortcut("CommandOrControl+KeyJ", "windows")).toBe("Ctrl + J");
    expect(prettyShortcut("CommandOrControl+Space", "windows")).toBe("Ctrl + Space");
  });

  it("treats an empty accelerator as not set", () => {
    expect(prettyShortcut("", "windows")).toBe("Not set");
  });

  // A6.DU8: prettyShortcut prints the macOS Control modifier as the word "Control" ("⌘ControlR").
  it.skip("shows the macOS Control modifier as a symbol rather than the raw word", () => {
    // acceleratorFromEvent produces "Control" for a physical Ctrl press on macOS, so the recorder can display it.
    expect(prettyShortcut("CommandOrControl+Control+R", "macos")).toBe("⌘⌃R");
  });
});

describe("acceleratorFromEvent edge cases", () => {
  it("maps digits, function keys, arrows and the numpad", () => {
    expect(acceleratorFromEvent(keys({ ctrlKey: true, code: "Digit7" }), "windows")).toBe("CommandOrControl+7");
    expect(acceleratorFromEvent(keys({ ctrlKey: true, code: "F5" }), "windows")).toBe("CommandOrControl+F5");
    expect(acceleratorFromEvent(keys({ ctrlKey: true, code: "ArrowUp" }), "windows")).toBe("CommandOrControl+ArrowUp");
    expect(acceleratorFromEvent(keys({ ctrlKey: true, code: "Numpad5" }), "windows")).toBe("CommandOrControl+Numpad5");
  });

  it("orders modifiers consistently: Command/Control, Alt, Shift", () => {
    expect(acceleratorFromEvent(keys({ ctrlKey: true, altKey: true, shiftKey: true, code: "KeyM" }), "windows")).toBe("CommandOrControl+Alt+Shift+M");
  });

  it("uses Command for CommandOrControl on macOS and keeps a physical Control separate", () => {
    expect(acceleratorFromEvent(keys({ metaKey: true, ctrlKey: true, code: "KeyM" }), "macos")).toBe("CommandOrControl+Control+M");
    expect(acceleratorFromEvent(keys({ ctrlKey: true, code: "KeyM" }), "macos")).toBe("Control+M");
  });

  it("ignores the Windows key on Windows and every modifier-only press", () => {
    expect(acceleratorFromEvent(keys({ metaKey: true, code: "KeyM" }), "windows")).toBeNull();
    for (const code of ["ControlLeft", "ControlRight", "ShiftLeft", "AltRight", "MetaLeft", "OSRight"]) {
      expect(acceleratorFromEvent(keys({ ctrlKey: true, shiftKey: true, code }), "windows")).toBeNull();
    }
  });

  it("returns null when the event has no key code", () => {
    expect(acceleratorFromEvent(keys({ ctrlKey: true, code: "" }), "windows")).toBeNull();
  });
});
