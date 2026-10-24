import { describe, expect, it } from "vitest";
import {
  acceleratorFromEvent,
  formatDuration,
  formatWhen,
  prettyShortcut,
  SOURCE_APP_LABELS,
  SOURCE_DEVICE_LABELS,
  timestamp,
} from "./format";

describe("formatDuration", () => {
  it("is blank for empty or non-positive input", () => {
    expect(formatDuration(undefined)).toBe("");
    expect(formatDuration(0)).toBe("");
    expect(formatDuration(-5)).toBe("");
  });

  it("formats minutes:seconds and hours:minutes:seconds", () => {
    expect(formatDuration(5_000)).toBe("0:05");
    expect(formatDuration(125_000)).toBe("2:05");
    expect(formatDuration(3_725_000)).toBe("1:02:05");
  });
});

describe("formatWhen", () => {
  const now = new Date("2026-10-24T12:00:00");

  it("labels today and yesterday", () => {
    expect(formatWhen("2026-10-24T09:30:00", now).startsWith("Today")).toBe(true);
    expect(formatWhen("2026-10-23T09:30:00", now).startsWith("Yesterday")).toBe(true);
  });

  it("falls back to a dated label and tolerates junk", () => {
    expect(formatWhen("2026-10-01T09:30:00", now).startsWith("Oct")).toBe(true);
    expect(formatWhen("not a date", now)).toBe("");
    expect(formatWhen(undefined, now)).toBe("");
  });
});

describe("timestamp", () => {
  it("renders session-relative m:ss", () => {
    expect(timestamp(0)).toBe("0:00");
    expect(timestamp(65_000)).toBe("1:05");
  });
});

describe("prettyShortcut", () => {
  it("uses symbols on macOS and words elsewhere", () => {
    expect(prettyShortcut("CommandOrControl+Shift+Space", "macos")).toBe("⌘⇧Space");
    expect(prettyShortcut("CommandOrControl+Shift+R", "windows")).toBe("Ctrl + Shift + R");
    expect(prettyShortcut(undefined, "windows")).toBe("Not set");
  });
});

describe("acceleratorFromEvent", () => {
  it("builds an accelerator with at least one modifier", () => {
    expect(acceleratorFromEvent({ ctrlKey: true, metaKey: false, shiftKey: true, altKey: false, code: "KeyR" }, "windows")).toBe(
      "CommandOrControl+Shift+R",
    );
    expect(acceleratorFromEvent({ ctrlKey: false, metaKey: true, shiftKey: false, altKey: false, code: "Space" }, "macos")).toBe(
      "CommandOrControl+Space",
    );
  });

  it("rejects modifier-only presses and bare keys", () => {
    expect(acceleratorFromEvent({ ctrlKey: true, metaKey: false, shiftKey: false, altKey: false, code: "ShiftLeft" }, "windows")).toBeNull();
    expect(acceleratorFromEvent({ ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, code: "KeyR" }, "windows")).toBeNull();
  });
});

describe("shared labels", () => {
  it("cover every source device and app", () => {
    expect(SOURCE_DEVICE_LABELS).toMatchObject({ phone: "Phone", desktop: "Desktop", web: "Web" });
    expect(SOURCE_APP_LABELS.whatsapp_business).toBe("WhatsApp Business");
    expect(SOURCE_APP_LABELS.voice_note).toBe("Voice note");
  });
});
