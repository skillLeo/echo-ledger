import { describe, expect, it, vi } from "vitest";
import { MAX_CAPTION_LINES, captionsReducer, initialCaptions, isAtEnd, reportPainted, visibleLines, type CaptionState } from "./OverlayApp";
import { callsOf, flushAsync, resetTauriMock } from "../../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const final = (text: string, channel = 1) => ({ kind: "final" as const, channel, speaker: channel === 2 ? "Client" : "Me", text, startMs: 0, endMs: 1 });

describe("captionsReducer", () => {
  it("keeps the current partial per channel", () => {
    const state = captionsReducer(initialCaptions, { kind: "partial", channel: 2, text: "hel", speaker: "Client" });
    expect(state.partial[2]).toBe("hel");
    expect(state.lines).toHaveLength(0);
  });

  it("shows interim words as each arrives and replaces them with the final, which nothing holds back", () => {
    // Word by word while the person is still speaking: each interim replaces the last one at once.
    let state = initialCaptions;
    const shown: string[] = [];
    for (const text of ["so", "so the", "so the numbers", "so the numbers are"]) {
      state = captionsReducer(state, { kind: "partial", channel: 1, text, speaker: "Me" });
      shown.push(state.partial[1]);
    }
    expect(shown).toEqual(["so", "so the", "so the numbers", "so the numbers are"]);
    expect(state.lines).toHaveLength(0);
    // The final replaces the interim line with the finished turn.
    state = captionsReducer(state, final("So the numbers are in."));
    expect(state.partial[1]).toBeUndefined();
    expect(state.lines.map((l) => l.text)).toEqual(["So the numbers are in."]);
    // The next turn's interim words show right after, before its final.
    state = captionsReducer(state, { kind: "partial", channel: 1, text: "next", speaker: "Me" });
    expect(state.partial[1]).toBe("next");
  });

  it("clears a channel's interim line when a turn has no words yet, instead of drawing an empty one", () => {
    let state = captionsReducer(initialCaptions, { kind: "partial", channel: 2, text: "hello", speaker: "Client" });
    state = captionsReducer(state, { kind: "partial", channel: 2, text: "  ", speaker: "Client" });
    expect(state.partial).toEqual({});
  });

  it("leaves the captions as they are for the latency probe's note, and says when its word was painted", async () => {
    resetTauriMock();
    const before = captionsReducer(initialCaptions, { kind: "partial", channel: 1, text: "word", speaker: "Me" });
    expect(captionsReducer(before, { kind: "probe", id: 7 })).toBe(before);
    let frame: (() => void) | null = null;
    reportPainted(7, (cb) => { frame = cb; });
    await flushAsync();
    expect(callsOf("caption_probe_painted")).toEqual([], "not before the frame");
    frame!();
    await new Promise((r) => setTimeout(r, 0));
    await flushAsync();
    expect(callsOf("caption_probe_painted")).toEqual([{ id: 7 }]);
  });

  it("promotes a final turn and clears that channel's partial only", () => {
    let state = captionsReducer(initialCaptions, { kind: "partial", channel: 1, text: "typing", speaker: "Me" });
    state = captionsReducer(state, { kind: "partial", channel: 2, text: "also typing", speaker: "Client" });
    state = captionsReducer(state, final("line 1"));
    expect(state.partial[1]).toBeUndefined();
    expect(state.partial[2]).toBe("also typing");
    expect(state.lines.map((l) => l.text)).toEqual(["line 1"]);
  });

  it("keeps up to 200 final lines for scrolling back, then drops the oldest", () => {
    expect(MAX_CAPTION_LINES).toBe(200);
    let state: CaptionState = initialCaptions;
    for (let i = 1; i <= 200; i++) state = captionsReducer(state, final(`line ${i}`));
    expect(state.lines).toHaveLength(200);
    expect(state.lines[0].text).toBe("line 1");

    for (let i = 201; i <= 205; i++) state = captionsReducer(state, final(`line ${i}`));
    expect(state.lines).toHaveLength(200);
    expect(state.lines[0].text).toBe("line 6");
    expect(state.lines[199].text).toBe("line 205");
  });

  it("gives every final line a unique, increasing id that survives dropping old lines", () => {
    let state: CaptionState = initialCaptions;
    for (let i = 1; i <= 203; i++) state = captionsReducer(state, final(`line ${i}`));
    const ids = state.lines.map((l) => l.id);
    expect(new Set(ids).size).toBe(200);
    expect(ids[0]).toBe(3);
    expect(ids[199]).toBe(202);
  });

  it("tracks live status and message, and clears on clear", () => {
    const live = captionsReducer(initialCaptions, { kind: "status", live: true, recording: true, provider: "assemblyai", message: null });
    expect(live.live).toBe(true);
    const withLine = captionsReducer(live, final("hi", 2));
    expect(captionsReducer(withLine, { kind: "clear" })).toEqual(initialCaptions);
  });
});

describe("visibleLines", () => {
  it("accepts 2, 3 and 5 and shows 3 for anything else", () => {
    expect(visibleLines(2)).toBe(2);
    expect(visibleLines(3)).toBe(3);
    expect(visibleLines(5)).toBe(5);
    for (const other of [0, 1, 4, 6, 200, -3, 2.5, "5", null, undefined]) expect(visibleLines(other)).toBe(3);
  });
});

describe("isAtEnd", () => {
  it("counts the last 8 pixels as the live end", () => {
    expect(isAtEnd({ scrollTop: 926, scrollHeight: 1000, clientHeight: 74 })).toBe(true);
    expect(isAtEnd({ scrollTop: 918, scrollHeight: 1000, clientHeight: 74 })).toBe(true);
    expect(isAtEnd({ scrollTop: 917, scrollHeight: 1000, clientHeight: 74 })).toBe(false);
    expect(isAtEnd({ scrollTop: 0, scrollHeight: 1000, clientHeight: 74 })).toBe(false);
  });

  it("treats content shorter than the body as being at the end", () => {
    expect(isAtEnd({ scrollTop: 0, scrollHeight: 40, clientHeight: 74 })).toBe(true);
  });
});
