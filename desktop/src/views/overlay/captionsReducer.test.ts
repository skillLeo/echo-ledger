import { describe, expect, it } from "vitest";
import { captionsReducer, initialCaptions } from "./OverlayApp";

describe("captionsReducer", () => {
  it("keeps the current partial per channel", () => {
    const state = captionsReducer(initialCaptions, { kind: "partial", channel: 2, text: "hel", speaker: "Client" });
    expect(state.partial[2]).toBe("hel");
    expect(state.lines).toHaveLength(0);
  });

  it("promotes a final turn and clears that channel's partial, keeping only the last three lines", () => {
    let state = captionsReducer(initialCaptions, { kind: "partial", channel: 1, text: "typing", speaker: "Me" });
    for (let i = 1; i <= 4; i++) {
      state = captionsReducer(state, { kind: "final", channel: 1, speaker: "Me", text: `line ${i}`, start_ms: 0, end_ms: 1 });
    }
    expect(state.partial[1]).toBeUndefined();
    expect(state.lines.map((l) => l.text)).toEqual(["line 2", "line 3", "line 4"]);
  });

  it("tracks live status and message, and clears on clear", () => {
    const live = captionsReducer(initialCaptions, { kind: "status", live: true, recording: true, provider: "assemblyai", message: null });
    expect(live.live).toBe(true);
    const withLine = captionsReducer(live, { kind: "final", channel: 2, speaker: "Client", text: "hi", start_ms: 0, end_ms: 1 });
    expect(captionsReducer(withLine, { kind: "clear" })).toEqual(initialCaptions);
  });
});
