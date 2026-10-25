import { describe, expect, it } from "vitest";
import { cleanLabel, filterByClient, filterByLabel, folderBadge, foldersOf, inSmartView, libraryLabels, smartViewCounts, sortFolders, startOfWeek, withLabel } from "./organise";
import { makeItem } from "../test/fixtures";

// Wednesday 28 October 2026, 15:00 local time.
const NOW = new Date(2026, 9, 28, 15, 0, 0);
const at = (y: number, m: number, d: number, h = 9) => new Date(y, m, d, h).toISOString();

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
    expect(ids("today")).toEqual(["today", "waiting"]);
    expect(ids("this_week")).toEqual(["today", "monday", "waiting"]);
    expect(ids("bookmarked")).toEqual(["sunday"]);
    expect(ids("failed")).toEqual(["failed", "tfailed"]);
    expect(ids("waiting")).toEqual(["waiting"]);
    expect(ids("pinned")).toEqual(["monday"]);
    expect(ids("all")).not.toContain("gone");
  });

  it("counts every view", () => {
    expect(smartViewCounts(items, NOW)).toEqual({ all: 6, today: 2, this_week: 3, waiting: 1, bookmarked: 1, failed: 2, pinned: 1 });
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
