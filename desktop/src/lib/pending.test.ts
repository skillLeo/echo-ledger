import { describe, expect, it } from "vitest";
import {
  DISCARD_CONFIRM,
  NO_AUDIO_HINT,
  bulkNotice,
  discardAllConfirm,
  failedUploads,
  pendingTitle,
  recordingTitle,
  uploadTitle,
  verifyingUploads,
  waitingSummary,
} from "./pending";
import { makePendingItem, makeUploadItem } from "../test/fixtures";

describe("waitingSummary", () => {
  const call = (id: string, startedAt: string, durationMs: number) => makePendingItem(id, { startedAt, durationMs });

  it("is empty when nothing waits", () => {
    expect(waitingSummary([])).toBe("");
  });

  it("counts calls, names the oldest day and adds up the time", () => {
    const items = [
      call("a", "2026-10-31T10:00:00Z", 36_000),
      call("b", "2026-10-30T09:00:00Z", 30_000),
      call("c", "2026-10-31T08:00:00Z", 42_000),
      call("d", "2026-10-30T15:00:00Z", 30_000),
    ];
    expect(waitingSummary(items, "UTC")).toBe("4 calls waiting · oldest Oct 30 · 2:18 total");
  });

  it("reads one call without 'oldest'", () => {
    expect(waitingSummary([call("a", "2026-10-30T12:00:00Z", 36_000)], "UTC")).toBe("1 call waiting · Oct 30 · 0:36 total");
  });

  it("says recordings when any item is not a call", () => {
    const voice = makePendingItem("v", { mode: "mic_note", sourceApp: "voice_note", startedAt: "2026-10-29T12:00:00Z", durationMs: 5_000 });
    expect(waitingSummary([voice], "UTC")).toBe("1 recording waiting · Oct 29 · 0:05 total");
    expect(waitingSummary([call("a", "2026-10-30T12:00:00Z", 10_000), voice], "UTC")).toBe("2 recordings waiting · oldest Oct 29 · 0:15 total");
    const byMode = makePendingItem("m", { mode: "voice_note", sourceApp: "zoom", startedAt: "2026-10-30T12:00:00Z", durationMs: 0 });
    expect(waitingSummary([byMode], "UTC")).toBe("1 recording waiting · Oct 30 · 0:00 total");
    const bySource = makePendingItem("s", { mode: "call", sourceApp: "voice_note", startedAt: "2026-10-30T12:00:00Z", durationMs: 0 });
    expect(waitingSummary([bySource], "UTC")).toBe("1 recording waiting · Oct 30 · 0:00 total");
  });

  it("shows 0:00 when no time was recorded, and hours for long totals", () => {
    expect(waitingSummary([call("a", "2026-10-30T12:00:00Z", 0), call("b", "2026-10-30T13:00:00Z", 0)], "UTC")).toBe("2 calls waiting · oldest Oct 30 · 0:00 total");
    expect(waitingSummary([call("a", "2026-10-30T12:00:00Z", 3_600_000), call("b", "2026-10-30T13:00:00Z", 65_000)], "UTC")).toBe("2 calls waiting · oldest Oct 30 · 1:01:05 total");
  });

  it("names the oldest day in the given time zone", () => {
    const items = [call("a", "2026-10-31T02:00:00Z", 60_000), call("b", "2026-10-31T05:00:00Z", 60_000)];
    expect(waitingSummary(items, "UTC")).toBe("2 calls waiting · oldest Oct 31 · 2:00 total");
    expect(waitingSummary(items, "America/New_York")).toBe("2 calls waiting · oldest Oct 30 · 2:00 total");
    expect(waitingSummary(items, "Asia/Karachi")).toBe("2 calls waiting · oldest Oct 31 · 2:00 total");
  });

  it("leaves out the day when no start time can be read", () => {
    expect(waitingSummary([call("a", "not a date", 36_000)], "UTC")).toBe("1 call waiting · 0:36 total");
  });
});

describe("bulk copy", () => {
  it("counts recordings in the Discard all question and the notices", () => {
    expect(discardAllConfirm(4)).toBe("Discard all 4 recordings? It can't be undone.");
    expect(discardAllConfirm(1)).toBe(DISCARD_CONFIRM);
    expect(bulkNotice("Kept", 3)).toBe("Kept 3 recordings");
    expect(bulkNotice("Discarded", 1)).toBe("Discarded 1 recording");
  });
});

describe("recordingTitle", () => {
  it("uses the title when there is one, trimmed", () => {
    expect(recordingTitle("  Weekly sync ", "zoom")).toBe("Weekly sync");
  });

  it("falls back to <Label> call with the display label", () => {
    expect(recordingTitle(null, "zoom")).toBe("Zoom call");
    expect(recordingTitle("", "whatsapp_web")).toBe("WhatsApp Web call");
    expect(recordingTitle("   ", "microsoft_teams")).toBe("Microsoft Teams call");
    expect(recordingTitle(undefined, "other")).toBe("Other call");
  });

  it("calls voice notes Voice note, by mode or by source", () => {
    expect(recordingTitle(null, "voice_note", "mic_note")).toBe("Voice note");
    expect(recordingTitle(null, "zoom", "voice_note")).toBe("Voice note");
    expect(recordingTitle(null, "voice_note")).toBe("Voice note");
  });

  it("titles pending items and uploads the same way", () => {
    expect(pendingTitle(makePendingItem("p", { title: null, sourceApp: "google_meet" }))).toBe("Google Meet call");
    expect(pendingTitle(makePendingItem("p", { mode: "mic_note", sourceApp: "voice_note" }))).toBe("Voice note");
    expect(uploadTitle(makeUploadItem("u", { title: "Board" }))).toBe("Board");
    expect(uploadTitle(makeUploadItem("u", { sourceApp: "whatsapp" }))).toBe("WhatsApp call");
  });
});

describe("upload filters", () => {
  const items = [
    makeUploadItem("a", { state: "queued" }),
    makeUploadItem("b", { state: "failed" }),
    makeUploadItem("c", { state: "verifying" }),
    makeUploadItem("d", { state: "uploading" }),
    makeUploadItem("e", { state: "failed" }),
  ];

  it("keeps the queue order", () => {
    expect(failedUploads(items).map((i) => i.id)).toEqual(["b", "e"]);
    expect(verifyingUploads(items).map((i) => i.id)).toEqual(["c"]);
    expect(failedUploads([])).toEqual([]);
  });
});

describe("copy", () => {
  it("matches the contract", () => {
    expect(NO_AUDIO_HINT).toBe("Check your microphone and the audio device, then try again.");
    expect(DISCARD_CONFIRM).toBe("Discard this recording? It can't be undone.");
  });
});
