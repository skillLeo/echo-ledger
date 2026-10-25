import { describe, expect, it } from "vitest";
import { MAX_CAPTION_LINES, captionsReducer, initialCaptions, isAtEnd, visibleLines, type CaptionState } from "./OverlayApp";

const final = (text: string, channel = 1) => ({ kind: "final" as const, channel, speaker: channel === 2 ? "Client" : "Me", text, startMs: 0, endMs: 1 });

describe("captionsReducer", () => {
  it("keeps the current partial per channel", () => {
    const state = captionsReducer(initialCaptions, { kind: "partial", channel: 2, text: "hel", speaker: "Client" });
    expect(state.partial[2]).toBe("hel");
    expect(state.lines).toHaveLength(0);
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
