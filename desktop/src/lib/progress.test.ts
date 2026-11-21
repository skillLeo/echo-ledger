import { describe, expect, it } from "vitest";
import { convertFailedText, convertingText, defaultViewOf, etaText, improvingView, rowViewsLine, shownExportView, stepOf, stepStates, transcribingLine, uploadLine, viewPercent } from "./progress";
import { makeItem } from "../test/fixtures";

describe("clear progress", () => {
  it("says the time left in plain words, and nothing without a measure", () => {
    expect(etaText(20)).toBe("about 20 s left");
    expect(etaText(2)).toBe("about 5 s left");
    expect(etaText(170)).toBe("about 3 min left");
    expect(etaText(null)).toBeNull();
    expect(etaText(undefined)).toBeNull();
  });

  it("says the parts and the time left while transcribing, from an older server too", () => {
    expect(transcribingLine({ done: 1, total: 3, eta_seconds: 20 })).toBe("Transcribing… part 2 of 3 · about 20 s left");
    expect(transcribingLine({ done: 0, total: 1, eta_seconds: 40 })).toBe("Transcribing… about 40 s left");
    expect(transcribingLine({ done: 0, total: 1, eta_seconds: null })).toBe("Transcribing…");
    expect(transcribingLine({ done: 2, total: 8 })).toBe("Transcribing… part 3 of 8");
    expect(transcribingLine(null)).toBe("Transcribing…");
  });

  it("gives the views' percent of lines and the slim bar's words", () => {
    expect(viewPercent({ roman: { done: 12, total: 20 } }, "roman")).toBe(60);
    expect(viewPercent({}, "roman")).toBeNull();
    expect(viewPercent(undefined, "roman")).toBeNull();
    expect(viewPercent({ roman: { done: 0, total: 0 } }, "roman")).toBeNull();
    expect(convertingText("roman", 60)).toBe("Converting to Roman Urdu… 60%");
    expect(convertingText("english", null)).toBe("Converting to English…");
    expect(improvingView({ urdu: { done: 1, total: 2 } }, "original", "roman")).toBe("roman");
    expect(improvingView({ urdu: { done: 1, total: 2 } }, "english", "roman")).toBe("english");
    expect(improvingView({ urdu: { done: 1, total: 2 } }, "original", "original")).toBe("urdu");
    expect(improvingView({}, "original", undefined)).toBeNull();
  });

  it("says a row is converting to its default view from the moment the transcript is done, or that it could not (U19)", () => {
    // Making with no lines done yet (the job is about to start): no percent.
    expect(rowViewsLine({ status: "making", default: "roman", progress: {} })).toEqual({ state: "making", view: "roman", text: "Converting to Roman Urdu…", percent: null });
    expect(rowViewsLine({ status: "making", default: "roman", progress: { roman: { done: 6, total: 10 } } })?.text).toBe("Converting to Roman Urdu… 60%");
    expect(rowViewsLine({ status: "failed", default: "roman", progress: {} })).toEqual({ state: "failed", view: "roman", text: "Couldn't convert to Roman Urdu", percent: null });
    expect(rowViewsLine({ status: "ready", default: "roman", progress: {} })).toBeNull();
    // An English call opens in its own words: nothing to say on its row.
    expect(rowViewsLine({ status: "making", default: "original", progress: {} })).toBeNull();
    expect(rowViewsLine(null)).toBeNull();
    expect(convertFailedText("english")).toBe("Couldn't convert to English");
    expect(defaultViewOf({ default: "roman" })).toBe("roman");
    expect(defaultViewOf({ default: "original" })).toBeNull();
    expect(defaultViewOf(undefined)).toBeNull();
  });

  it("says the upload in percent of bytes", () => {
    expect(uploadLine({ state: "uploading", progress: 42.4 })).toBe("Uploading… 42%");
    expect(uploadLine({ state: "queued", progress: null })).toBe("Waiting to upload");
    expect(uploadLine(null)).toBe("Uploading…");
  });

  it("knows a recording's step from the Library's item", () => {
    expect(stepOf(makeItem("a", { status: "uploading" })).step).toBe("upload");
    const transcribing = makeItem("b", { status: "processing", transcript: { final_status: "processing", final_transcript_id: null, live_available: false, preview: null, final_progress: { done: 0, total: 2, eta_seconds: 30 } } });
    expect(stepOf(transcribing)).toEqual({ step: "transcribe", line: "Transcribing… part 1 of 2 · about 30 s left" });
    expect(stepOf(makeItem("c", { status: "completed", transcript: { final_status: "completed", final_transcript_id: "t", live_available: false, preview: null } })).step).toBeNull();
    expect(stepOf(makeItem("d", { status: "failed" })).step).toBeNull();
  });

  it("lays the three steps out as done, running and waiting", () => {
    expect(stepStates("upload", false, false)).toEqual({ upload: "active", transcribe: "waiting", improve: "waiting" });
    expect(stepStates("transcribe", false, false)).toEqual({ upload: "done", transcribe: "active", improve: "waiting" });
    expect(stepStates(null, true, false)).toEqual({ upload: "done", transcribe: "done", improve: "active" });
    expect(stepStates(null, false, false)).toEqual({ upload: "done", transcribe: "done", improve: "done" });
    expect(stepStates(null, false, true)).toEqual({ upload: "done", transcribe: "failed", improve: "waiting" });
  });
});

describe("the view a zip of several recordings is in (U19)", () => {
  const withDefault = (id: string, def: "original" | "roman" | "english") =>
    makeItem(id, { transcript: { final_status: "completed", final_transcript_id: `tr-${id}`, live_available: false, preview: null, views: { status: "ready", default: def, progress: {} } } });

  it("is the view most of them open in, and none when they open in their original words", () => {
    expect(shownExportView([withDefault("a", "roman"), withDefault("b", "original"), withDefault("c", "roman"), withDefault("d", "english")])).toBe("roman");
    expect(shownExportView([withDefault("a", "original"), makeItem("b")])).toBeUndefined();
    expect(shownExportView([])).toBeUndefined();
  });
});
