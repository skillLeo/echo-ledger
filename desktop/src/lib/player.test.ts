import { describe, expect, it } from "vitest";
import { activeIndex, activeWord, continuesTurn, newTerms, playerKey, skipTarget, snippetParts, speakerLabels, speakerName, timelinePercent } from "./player";

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
