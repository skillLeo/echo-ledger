import { describe, expect, it } from "vitest";
import type { LibraryItem } from "./ipc";
import { dayGroup, groupItems, itemState, searchItems, SORT_LABELS, sortItems } from "./library";
import { filterItems } from "../views/main/LibraryView";
import { makeItem } from "../test/fixtures";

const ids = (items: LibraryItem[]) => items.map((i) => i.id);
const at = (y: number, m: number, d: number, h = 9) => new Date(y, m, d, h, 0, 0).toISOString();

describe("searchItems edge cases", () => {
  const items = [
    makeItem("a", { title: "Kickoff", device: { id: "d1", name: "Sam's Pixel" }, source_device_label: "Phone", source_app_label: "WhatsApp Business" }),
    makeItem("b", { title: "Budget review", client: { id: "c", name: "Northwind (EU)" }, transcript: { final_status: "completed", final_transcript_id: "t", live_available: false, preview: "Please send the revised quote" } }),
    makeItem("c", { title: undefined }),
  ];

  it("matches device names and source labels, not only titles", () => {
    expect(ids(searchItems(items, "pixel"))).toEqual(["a"]);
    expect(ids(searchItems(items, "business"))).toEqual(["a"]);
    expect(ids(searchItems(items, "phone"))).toEqual(["a"]);
  });

  it("matches transcript previews and client names, trimming the query", () => {
    expect(ids(searchItems(items, "  REVISED quote "))).toEqual(["b"]);
    expect(ids(searchItems(items, "northwind"))).toEqual(["b"]);
  });

  it("treats regular-expression characters literally", () => {
    expect(ids(searchItems(items, "(eu)"))).toEqual(["b"]);
    expect(searchItems(items, "[")).toEqual([]);
    expect(searchItems(items, ".*")).toEqual([]);
  });

  it("does not throw for items without any searchable fields", () => {
    expect(searchItems([{ id: "x", deleted: false, updated_at: "" }], "anything")).toEqual([]);
  });
});

describe("sortItems edge cases", () => {
  it("puts items without a start time last when newest first, and first when oldest first", () => {
    const items = [makeItem("none", { started_at: undefined }), makeItem("new", { started_at: at(2026, 9, 20) }), makeItem("bad", { started_at: "garbage" })];
    expect(ids(sortItems(items, "newest"))[0]).toBe("new");
    expect(ids(sortItems(items, "oldest"))[2]).toBe("new");
  });

  it("keeps the original order for equal keys", () => {
    const items = [makeItem("1", { duration_ms: 5_000 }), makeItem("2", { duration_ms: 5_000 }), makeItem("3", { duration_ms: 5_000 })];
    expect(ids(sortItems(items, "longest"))).toEqual(["1", "2", "3"]);
  });

  it("treats a missing duration as zero for longest first", () => {
    const items = [makeItem("none", { duration_ms: undefined }), makeItem("null", { duration_ms: null }), makeItem("long", { duration_ms: 90_000 })];
    expect(ids(sortItems(items, "longest"))[0]).toBe("long");
  });

  it("sorts titles alphabetically ignoring case, with untitled items first", () => {
    const items = [makeItem("b", { title: "bravo" }), makeItem("a", { title: "Alpha" }), makeItem("u", { title: undefined }), makeItem("c", { title: "Charlie" })];
    expect(ids(sortItems(items, "title"))).toEqual(["u", "a", "b", "c"]);
  });

  it("offers a label for every sort key", () => {
    expect(SORT_LABELS).toEqual({ newest: "Newest first", oldest: "Oldest first", longest: "Longest first", title: "Title A–Z" });
  });
});

describe("dayGroup boundaries", () => {
  const now = new Date(2026, 9, 24, 12, 0, 0);

  it("treats the last second of yesterday and the first second of today correctly", () => {
    expect(dayGroup(new Date(2026, 9, 23, 23, 59, 59).toISOString(), now)).toBe("Yesterday");
    expect(dayGroup(new Date(2026, 9, 24, 0, 0, 0).toISOString(), now)).toBe("Today");
  });

  it("calls the last six days this week and anything older earlier", () => {
    expect(dayGroup(at(2026, 9, 22), now)).toBe("This week");
    expect(dayGroup(at(2026, 9, 18), now)).toBe("This week"); // 6 days ago
    expect(dayGroup(at(2026, 9, 17), now)).toBe("Earlier"); // 7 days ago
  });

  it("puts a timestamp in the future under today", () => {
    expect(dayGroup(at(2026, 9, 26), now)).toBe("Today");
  });

  it("falls back to earlier for an unparseable date", () => {
    expect(dayGroup("not-a-date", now)).toBe("Earlier");
  });

  it("is stable across a daylight-saving change (day arithmetic uses calendar days)", () => {
    const afterDst = new Date(2026, 10, 2, 12, 0, 0);
    expect(dayGroup(new Date(2026, 10, 1, 23, 0, 0).toISOString(), afterDst)).toBe("Yesterday");
  });
});

describe("groupItems ordering", () => {
  const now = new Date(2026, 9, 24, 12, 0, 0);

  it("keeps the sorted order and starts a new heading whenever the day bucket changes", () => {
    const items = [
      makeItem("t1", { started_at: at(2026, 9, 24, 11) }),
      makeItem("y1", { started_at: at(2026, 9, 23, 11) }),
      makeItem("w1", { started_at: at(2026, 9, 20, 11) }),
      makeItem("e1", { started_at: at(2026, 8, 1, 11) }),
    ];
    expect(groupItems(items, "newest", now).map((g) => [g.label, ids(g.items)])).toEqual([
      ["Today", ["t1"]],
      ["Yesterday", ["y1"]],
      ["This week", ["w1"]],
      ["Earlier", ["e1"]],
    ]);
  });

  it("groups oldest-first lists starting with the oldest bucket", () => {
    const items = [makeItem("e1", { started_at: at(2026, 8, 1) }), makeItem("t1", { started_at: at(2026, 9, 24, 8) })];
    expect(groupItems(items, "oldest", now).map((g) => g.label)).toEqual(["Earlier", "Today"]);
  });

  it("returns one unlabelled group for longest and title sorts", () => {
    const items = [makeItem("a"), makeItem("b")];
    expect(groupItems(items, "longest", now)).toEqual([{ label: null, items }]);
    expect(groupItems([], "title", now)).toEqual([]);
  });
});

describe("itemState precedence", () => {
  const withFinal = (final_status: string | null, status?: string) => itemState({ status, transcript: { final_status, final_transcript_id: null, live_available: false, preview: null } });

  it("lets a failed transcript win over a completed session", () => {
    expect(withFinal("failed", "completed")).toBe("failed");
  });

  it("lets a failed session win over a completed transcript", () => {
    expect(withFinal("completed", "failed")).toBe("failed");
  });

  it("is ready when either the session or the final transcript is complete", () => {
    expect(withFinal(null, "completed")).toBe("ready");
    expect(withFinal("completed", "processing")).toBe("ready");
  });

  it("is processing for every in-flight or unknown state", () => {
    for (const status of ["recording", "uploading", "processing", undefined]) expect(withFinal(null, status)).toBe("processing");
    for (const final of ["pending", "submitted", "processing", "awaiting_device"]) expect(withFinal(final, "processing")).toBe("processing");
    expect(itemState({})).toBe("processing");
  });
});

describe("filterItems", () => {
  const items = [
    makeItem("p", { source_device: "phone" }),
    makeItem("d", { source_device: "desktop" }),
    makeItem("w", { source_device: "web" }),
    makeItem("gone", { source_device: "phone", deleted: true }),
  ];

  it("drops tombstones for every filter", () => {
    expect(ids(filterItems(items, "all"))).toEqual(["p", "d", "w"]);
    expect(ids(filterItems(items, "phone"))).toEqual(["p"]);
  });

  it("filters by source device", () => {
    expect(ids(filterItems(items, "desktop"))).toEqual(["d"]);
    expect(ids(filterItems(items, "web"))).toEqual(["w"]);
  });
});
