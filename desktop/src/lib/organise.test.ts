import { describe, expect, it } from "vitest";
import { cleanLabel, filterByClient, filterByLabel, folderBadge, foldersOf, inSmartView, libraryLabels, smartViewCounts, sortFolders, startOfWeek, withLabel } from "./organise";
import { makeItem } from "../test/fixtures";

// Wednesday 28 October 2026, 15:00 local time.
const NOW = new Date(2026, 9, 28, 15, 0, 0);
const at = (y: number, m: number, d: number, h = 9) => new Date(y, m, d, h).toISOString();

describe("the classic Waiting view (contract 2.0.0-z)", () => {
  const ago = (ms: number) => new Date(NOW.getTime() - ms).toISOString();
  const call = (id: string, extra: Parameters<typeof makeItem>[1]) => makeItem(id, { status: "recording", ...extra });
  it("has a call once it is over (ended_at) or started more than 4 hours ago, never one still being recorded", () => {
    const items = [
      call("live", { started_at: ago(3 * 60_000) }),
      call("over", { started_at: ago(3 * 60_000), ended_at: ago(60_000) }),
      call("four", { started_at: ago(4 * 3_600_000) }),
      call("older", { started_at: ago(4 * 3_600_000 + 1000) }),
    ];
    expect(items.filter((i) => inSmartView(i, "waiting", NOW)).map((i) => i.id)).toEqual(["over", "older"]);
    expect(smartViewCounts(items, NOW).waiting).toBe(2);
    // A server state that may be stale (Waiting to upload, synced while the call ran) doesn't stop the clock; its
    // Waiting wins.
    expect(inSmartView(call("stale", { started_at: ago(4 * 3_600_000 + 1000), facets: { state: "waiting_to_upload" } }), "waiting", NOW)).toBe(true);
    expect(inSmartView(call("sent", { started_at: ago(60_000), facets: { state: "waiting" } }), "waiting", NOW)).toBe(true);
    // All has none of them, as on the server.
    expect(items.filter((i) => inSmartView(i, "all", NOW))).toEqual([]);
  });

  it("D-243: a session still recording is in no view but Waiting, pinned, bookmarked, today's or this week's though it is", () => {
    const call = makeItem("call", { status: "recording", started_at: ago(5 * 3_600_000), ended_at: ago(60_000), pinned: true, marker_count: 2 });
    const views = (["all", "today", "this_week", "waiting", "bookmarked", "failed", "pinned"] as const).filter((v) => inSmartView(call, v, NOW));
    expect(views).toEqual(["waiting"]);
    // Kept, it is in them as any recording is.
    const kept = { ...call, status: "uploaded" };
    expect((["all", "today", "this_week", "bookmarked", "pinned"] as const).every((v) => inSmartView(kept, v, NOW))).toBe(true);
  });
});

describe("smart views (B15)", () => {
  const today = makeItem("today", { started_at: at(2026, 9, 28) });
  const monday = makeItem("monday", { started_at: at(2026, 9, 26), pinned: true });
  const lastSunday = makeItem("sunday", { started_at: at(2026, 9, 25, 23), marker_count: 2 });
  const failed = makeItem("failed", { started_at: at(2026, 8, 1), status: "failed" });
  const transcriptFailed = makeItem("tfailed", { started_at: at(2026, 8, 1), transcript: { final_status: "failed", final_transcript_id: "t", live_available: false, preview: null } });
  const waiting = makeItem("waiting", { started_at: at(2026, 9, 28), status: "recording" });
  const gone = makeItem("gone", { deleted: true, pinned: true });
  const items = [today, monday, lastSunday, failed, transcriptFailed, waiting, gone];

  it("starts the week on Monday", () => {
    expect(startOfWeek(NOW)).toEqual(new Date(2026, 9, 26));
    expect(startOfWeek(new Date(2026, 9, 25, 12))).toEqual(new Date(2026, 9, 19));
  });

  it("puts each recording in the right views", () => {
    const ids = (view: Parameters<typeof inSmartView>[1]) => items.filter((i) => inSmartView(i, view, NOW)).map((i) => i.id);
    // D-243: the call still waiting (session `recording`) is only in Waiting, as on the server, not in Today or This week.
    expect(ids("today")).toEqual(["today"]);
    expect(ids("this_week")).toEqual(["today", "monday"]);
    expect(ids("bookmarked")).toEqual(["sunday"]);
    expect(ids("failed")).toEqual(["failed", "tfailed"]);
    expect(ids("waiting")).toEqual(["waiting"]);
    expect(ids("pinned")).toEqual(["monday"]);
    expect(ids("all")).not.toContain("gone");
  });

  it("counts every view", () => {
    // Every view but Waiting leaves out the call whose session is still `recording` (contract 2.0.0-z, D-243: only
    // Waiting has it).
    expect(smartViewCounts(items, NOW)).toEqual({ all: 5, today: 1, this_week: 2, waiting: 1, bookmarked: 1, failed: 2, pinned: 1 });
  });
});

describe("labels and folders (B15)", () => {
  it("lists labels once, ignoring capitals, and filters by them", () => {
    const items = [makeItem("a", { labels: ["Invoice", "Q4"] }), makeItem("b", { labels: ["invoice"] }), makeItem("c", { deleted: true, labels: ["Gone"] })];
    expect(libraryLabels(items)).toEqual(["Invoice", "Q4"]);
    expect(filterByLabel(items, "INVOICE").map((i) => i.id)).toEqual(["a", "b"]);
    expect(filterByLabel(items, null)).toHaveLength(3);
  });

  it("cleans labels like the server and refuses duplicates and the 21st", () => {
    expect(cleanLabel("  Follow \u0007 up  ")).toBe("Follow up");
    expect(cleanLabel("   ")).toBeNull();
    expect(cleanLabel("x".repeat(41))).toBeNull();
    expect(withLabel(["Invoice"], " invoice ")).toBeNull();
    expect(withLabel(["Invoice"], "Q4")).toEqual(["Invoice", "Q4"]);
    expect(withLabel(Array.from({ length: 20 }, (_, i) => `l${i}`), "more")).toBeNull();
  });

  it("filters by folder and builds the folder list from recordings and the server", () => {
    const acme = { id: "c1", name: "Acme Holdings" };
    const items = [makeItem("a", { client: acme }), makeItem("b", { client: null })];
    expect(filterByClient(items, "c1").map((i) => i.id)).toEqual(["a"]);
    expect(filterByClient(items, "").map((i) => i.id)).toEqual(["b"]);
    expect(filterByClient(items, null)).toHaveLength(2);
    const folders = foldersOf(items, [{ id: "c2", name: "Zeta", pinned: true }, { id: "c1", name: "Acme Holdings", avatar: "🦊" }]);
    expect(folders.map((f) => f.name)).toEqual(["Zeta", "Acme Holdings"]);
    expect(folderBadge(folders[1])).toBe("🦊");
    expect(folderBadge({ name: "Acme Holdings" })).toBe("AH");
    expect(folderBadge({ name: "northwind" })).toBe("NO");
    expect(sortFolders([{ id: "1", name: "b" }, { id: "2", name: "A" }]).map((f) => f.name)).toEqual(["A", "b"]);
  });
});
