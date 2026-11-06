// Screen moments in the transcript and Copy for AI (since v1.5): the same placement and wording as the phone.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { forgetPictures, PICTURES_KEPT, screenAlt, screenLabel, screenMarker, screenPicture, screensByLine, screensBySession } from "./screens";
import { copiedTranscript, DEFAULT_UNSURE } from "./format";
import type { ProjectScreen } from "./ipc";
import { callsOf, fail, resetTauriMock, respondWith } from "../test/tauri";
import { makeScreen } from "../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);

const SEGMENTS = [
  { speaker: "Me", start_ms: 10_000, text: "Let me share my screen." },
  { speaker: "Client", start_ms: 60_000, text: "Look at this page, the Pay button." },
  { speaker: "Me", start_ms: 872_000, text: "And here is the invoice." },
];

describe("where a screen goes", () => {
  it("sits after the last line that started at or before its moment, in time order", () => {
    const later = makeScreen(2, 75_000);
    const first = makeScreen(1, 60_000);
    const byLine = screensByLine(SEGMENTS, [later, first, makeScreen(3, 900_000)]);
    expect([...byLine.keys()]).toEqual([1, 2]);
    expect(byLine.get(1)?.map((s) => s.index)).toEqual([1, 2]);
    expect(byLine.get(2)?.map((s) => s.index)).toEqual([3]);
  });

  it("goes with the first line when it comes before every line, and nowhere without lines", () => {
    expect(screensByLine(SEGMENTS, [makeScreen(1, 2_000)]).get(0)?.map((s) => s.index)).toEqual([1]);
    expect(screensByLine([], [makeScreen(1, 2_000)]).size).toBe(0);
    expect(screensByLine(SEGMENTS, []).size).toBe(0);
  });
});

describe("how a screen reads", () => {
  it("is Screen N · m:ss on the page and [Screen N at m:ss: description] for an AI", () => {
    const screen = makeScreen(7, 872_000, { description: "  The client's invoice page with the Pay button " });
    expect(screenLabel(screen)).toBe("Screen 7 · 14:32");
    expect(screenMarker(screen)).toBe("[Screen 7 at 14:32: The client's invoice page with the Pay button]");
    expect(screenMarker(makeScreen(2, 65_000))).toBe("[Screen 2 at 1:05]");
    expect(screenMarker(makeScreen(3, 3_725_000, { description: " " }))).toBe("[Screen 3 at 1:02:05]");
    expect(screenAlt(screen)).toBe("The client's invoice page with the Pay button");
    expect(screenAlt(makeScreen(2, 65_000))).toBe("Screen 2 · 1:05");
  });

  it("goes into Copy for AI after the line of its moment, and never into the other copies", () => {
    const screens = [makeScreen(1, 61_000, { description: "The checkout page" }), makeScreen(2, 900_000)];
    const byLine = screensByLine(SEGMENTS, screens);
    const after = (i: number) => (byLine.get(i) ?? []).map(screenMarker);
    const forAi = copiedTranscript(SEGMENTS, () => null, false, DEFAULT_UNSURE, true, after);
    expect(forAi.split("\n")).toEqual([
      DEFAULT_UNSURE.note_text,
      "",
      "Me: Let me share my screen.",
      "Client: Look at this page, the Pay button.",
      "[Screen 1 at 1:01: The checkout page]",
      "Me: And here is the invoice.",
      "[Screen 2 at 15:00]",
    ]);
    const plain = copiedTranscript(SEGMENTS, () => null, true, DEFAULT_UNSURE, false, after);
    expect(plain).not.toContain("[Screen");
    expect(plain.split("\n")[0]).toBe("[0:10] Me: Let me share my screen.");
  });
});

describe("a project's screens", () => {
  const shot = (index: number, session: string, title: string | null): ProjectScreen => ({
    ...makeScreen(index, index * 1000), id: `${session}-${index}`, session_id: session, session_title: title, session_started_at: "2026-11-04T10:00:00Z",
  });

  it("are grouped by recording in the order the server sends them", () => {
    const groups = screensBySession([shot(1, "s2", "Kick-off"), shot(2, "s2", "Kick-off"), shot(1, "s1", null)]);
    expect(groups.map((g) => [g.sessionId, g.title, g.screens.length])).toEqual([["s2", "Kick-off", 2], ["s1", null, 1]]);
  });
});

describe("pictures", () => {
  beforeEach(() => {
    resetTauriMock();
    forgetPictures();
  });
  afterEach(() => forgetPictures());

  it("are fetched once per link through the core, and again after a failure", async () => {
    respondWith("screen_image", ({ url }) => `data:image/webp;base64,${btoa(String(url))}`);
    const url = "https://ledger.example.test/screens/1.webp?sig=1";
    await expect(screenPicture(url)).resolves.toMatch(/^data:image\/webp;base64,/);
    await screenPicture(url);
    expect(callsOf("screen_image")).toEqual([{ url }]);
    fail("screen_image", "The link expired.");
    const other = "https://ledger.example.test/screens/2.webp?sig=1";
    await expect(screenPicture(other)).rejects.toBe("The link expired.");
    await expect(screenPicture(other)).rejects.toBe("The link expired.");
    expect(callsOf("screen_image")).toHaveLength(3);
  });

  it("keep only the newest ones", async () => {
    respondWith("screen_image", ({ url }) => `data:image/webp;base64,${btoa(String(url))}`);
    for (let i = 0; i <= PICTURES_KEPT; i++) await screenPicture(`https://ledger.example.test/${i}`);
    await screenPicture("https://ledger.example.test/0");
    expect(callsOf("screen_image")).toHaveLength(PICTURES_KEPT + 2);
  });
});
