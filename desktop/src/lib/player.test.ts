import { describe, expect, it } from "vitest";
import { activeIndex, activeWord, continuesTurn, findMatches, hasUncertainWords, markParts, newTerms, playerKey, skipTarget, snippetParts, speakerLabels, speakerName, suggestionSpan, timelinePercent, UNCERTAIN_BELOW, uncertainWord, waveformBars } from "./player";

const segments = [
  { speaker: "Me", start_ms: 0, end_ms: 4_000 },
  { speaker: "Me", start_ms: 4_000, end_ms: 5_000 },
  { speaker: "Client", start_ms: 10_000, end_ms: 12_000 },
  { speaker: "Me", start_ms: 12_500, end_ms: 13_000 },
];

describe("transcript player helpers (B6)", () => {
  it("finds the line being played", () => {
    expect([-1, 0, 4_000, 9_999, 10_000, 60_000].map((t) => activeIndex([{ start_ms: 1 }, ...segments.slice(1)], t))).toEqual([-1, -1, 1, 1, 2, 3]);
    expect(activeIndex([], 5)).toBe(-1);
  });

  it("finds the word being spoken", () => {
    const words = [{ start_ms: 0 }, { start_ms: 400 }, { start_ms: 900 }];
    expect([0, 399, 400, 2_000].map((t) => activeWord(words, t))).toEqual([0, 0, 1, 2]);
    expect(activeWord(undefined, 10)).toBe(-1);
  });

  it("skips only silences longer than 1.5 s, landing just before the next line", () => {
    expect(skipTarget(segments, 2_000)).toBeNull(); // in speech
    expect(skipTarget(segments, 5_500)).toBe(9_800);
    expect(skipTarget(segments, 12_100)).toBeNull(); // 0.4 s to the next line
    expect(skipTarget(segments, 20_000)).toBeNull(); // after the last line
  });

  it("uses renamed speakers and groups turns", () => {
    expect(speakerName({ Client: "Jane" }, "Client")).toBe("Jane");
    expect(speakerName({ Client: "  " }, "Client")).toBe("Client");
    expect(speakerName(undefined, "Me")).toBe("Me");
    expect(segments.map((_, i) => continuesTurn(segments, i))).toEqual([false, true, false, false]);
    expect(speakerLabels(segments)).toEqual(["Me", "Client"]);
  });

  it("maps keys to player actions and leaves fields and controls alone", () => {
    expect(playerKey(" ", "DIV")).toBe("toggle");
    expect(playerKey("K", "DIV")).toBe("toggle");
    expect(playerKey("j", "BUTTON")).toBe("back10");
    expect(playerKey("l", "DIV")).toBe("forward10");
    expect(playerKey("ArrowLeft", "DIV")).toBe("back5");
    expect(playerKey("ArrowRight", "DIV")).toBe("forward5");
    expect(playerKey(" ", "BUTTON")).toBeNull();
    expect(playerKey("ArrowLeft", "AUDIO")).toBeNull();
    expect(playerKey("k", "TEXTAREA")).toBeNull();
    expect(playerKey("k", "DIV", true)).toBeNull();
    expect(playerKey("Enter", "DIV")).toBeNull();
  });

  it("places positions on the timeline", () => {
    expect([timelinePercent(0, 100), timelinePercent(50, 100), timelinePercent(150, 100), timelinePercent(5, 0)]).toEqual([0, 50, 100, 0]);
  });

  it("splits a snippet into highlighted parts by character, never by HTML", () => {
    expect(snippetParts("The <b>invoice</b> is due", [[7, 7]])).toEqual([
      { text: "The <b>", hit: false }, { text: "invoice", hit: true }, { text: "</b> is due", hit: false },
    ]);
    expect(snippetParts("قیمت ٹھیک ہے", [[5, 4]]).find((p) => p.hit)?.text).toBe("ٹھیک");
    expect(snippetParts("abc", [[10, 2]])).toEqual([{ text: "abc", hit: false }]);
  });

  it("suggests the names and numbers an edit added for the vocabulary", () => {
    expect(newTerms("hello acme limited", "Hello Acme Ltd, see Farah at Q3.")).toEqual(["Ltd", "Farah", "Q3"]);
    expect(newTerms("same words", "same words")).toEqual([]);
    expect(newTerms("x", "A B C D E")).toEqual([]); // single letters are not terms
  });
});

describe("uncertain words", () => {
  it("marks a word below the threshold, never one without a score", () => {
    expect(uncertainWord({ confidence: 0.31 })).toBe(true);
    expect(uncertainWord({ confidence: UNCERTAIN_BELOW })).toBe(false);
    expect(uncertainWord({ confidence: 0.98 })).toBe(false);
    expect(uncertainWord({ confidence: null })).toBe(false);
    expect(uncertainWord({})).toBe(false);
  });

  it("finds a line with an uncertain word", () => {
    expect(hasUncertainWords({ words: [{ text: "a", start_ms: 0, end_ms: 1, confidence: 0.9 }, { text: "b", start_ms: 1, end_ms: 2, confidence: 0.2 }] })).toBe(true);
    expect(hasUncertainWords({ words: [{ text: "a", start_ms: 0, end_ms: 1 }] })).toBe(false);
    expect(hasUncertainWords({})).toBe(false);
  });
});

describe("the docked player's waveform", () => {
  const lines = [
    { channel: 1, speaker: "Me", start_ms: 0, end_ms: 10_000, text: "one two three four" },
    { channel: 2, speaker: "Client", start_ms: 20_000, end_ms: 40_000, text: "five six" },
  ];

  it("draws speech high, silence low, and colours a bar by the side that talks", () => {
    const bars = waveformBars(lines, 40_000, 4);
    expect(bars.map((b) => b.channel)).toEqual([1, 1, 2, 2]);
    expect(bars[1].level).toBe(0.1);
    for (const i of [0, 2, 3]) expect(bars[i].level).toBeGreaterThan(0.5);
    expect(bars.every((b) => b.level > 0 && b.level <= 1)).toBe(true);
  });

  it("draws nothing for an unknown length and ignores lines outside the recording", () => {
    expect(waveformBars(lines, 0)).toEqual([]);
    expect(waveformBars([{ channel: 1, speaker: "Me", start_ms: 50_000, end_ms: 60_000, text: "late" }], 40_000, 4).every((b) => b.level === 0.1)).toBe(true);
  });
});

describe("Find in a transcript", () => {
  it("finds every literal match, ignoring case, in reading order", () => {
    expect(findMatches(["Launch is soon", "the LAUNCH party, launch day"], "launch")).toEqual([
      { seg: 0, start: 0, length: 6 }, { seg: 1, start: 4, length: 6 }, { seg: 1, start: 18, length: 6 },
    ]);
    expect(findMatches(["a.b"], ".")).toEqual([{ seg: 0, start: 1, length: 1 }]);
    expect(findMatches(["anything"], "   ")).toEqual([]);
  });

  it("splits a line around its matches", () => {
    expect(markParts("the launch party", [{ seg: 0, start: 4, length: 6, index: 3 }])).toEqual([
      { text: "the ", match: null }, { text: "launch", match: 3 }, { text: " party", match: null },
    ]);
  });

  it("finds where a suggested fix's words are, or says they are gone", () => {
    expect(suggestionSpan("We use La Ravel here", "la ravel")).toEqual([7, 15]);
    expect(suggestionSpan("We use Laravel here", "la ravel")).toBeNull();
  });
});
