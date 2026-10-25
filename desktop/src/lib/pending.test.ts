import { describe, expect, it } from "vitest";
import { DISCARD_CONFIRM, NO_AUDIO_HINT, failedUploads, pendingTitle, recordingTitle, uploadTitle, verifyingUploads } from "./pending";
import { makePendingItem, makeUploadItem } from "../test/fixtures";

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
