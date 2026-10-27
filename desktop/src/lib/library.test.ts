import { describe, expect, it } from "vitest";
import type { LibraryItem } from "./ipc";
import { bookmarksOf, dayGroup, filterByTag, groupItems, itemState, libraryTags, searchItems, sortItems } from "./library";

function item(id: string, extra: Partial<LibraryItem> = {}): LibraryItem {
  return { id, deleted: false, updated_at: "2026-10-24T00:00:00Z", ...extra } as LibraryItem;
}

describe("searchItems", () => {
  const items = [
    item("a", { title: "Google Meet call", transcript: { final_status: "completed", final_transcript_id: null, live_available: false, preview: "Let us discuss the invoice" } }),
    item("b", { title: "Voice note", client: { id: "c1", name: "Acme Holdings" } }),
  ];

  it("matches titles, transcript text and client names, ignoring case", () => {
    expect(searchItems(items, "MEET").map((i) => i.id)).toEqual(["a"]);
    expect(searchItems(items, "invoice").map((i) => i.id)).toEqual(["a"]);
    expect(searchItems(items, "acme").map((i) => i.id)).toEqual(["b"]);
  });

  it("returns everything for a blank query and nothing for no match", () => {
    expect(searchItems(items, "  ")).toHaveLength(2);
    expect(searchItems(items, "zzz")).toHaveLength(0);
  });
});

describe("sortItems", () => {
  const items = [
    item("old", { title: "Beta", started_at: "2026-10-01T10:00:00Z", duration_ms: 1000 }),
    item("new", { title: "Alpha", started_at: "2026-10-20T10:00:00Z", duration_ms: 5000 }),
    item("mid", { title: "Gamma", started_at: "2026-10-10T10:00:00Z", duration_ms: 9000 }),
  ];

  it("sorts by time, length and title without mutating the input", () => {
    expect(sortItems(items, "newest").map((i) => i.id)).toEqual(["new", "mid", "old"]);
    expect(sortItems(items, "oldest").map((i) => i.id)).toEqual(["old", "mid", "new"]);
    expect(sortItems(items, "longest").map((i) => i.id)).toEqual(["mid", "new", "old"]);
    expect(sortItems(items, "title").map((i) => i.id)).toEqual(["new", "old", "mid"]);
    expect(items.map((i) => i.id)).toEqual(["old", "new", "mid"]);
  });
});

describe("grouping", () => {
  const now = new Date(2026, 9, 24, 12, 0, 0);

  it("labels days relative to now", () => {
    expect(dayGroup(new Date(2026, 9, 24, 9, 0).toISOString(), now)).toBe("Today");
    expect(dayGroup(new Date(2026, 9, 23, 23, 0).toISOString(), now)).toBe("Yesterday");
    expect(dayGroup(new Date(2026, 9, 20, 9, 0).toISOString(), now)).toBe("This week");
    expect(dayGroup(new Date(2026, 8, 1, 9, 0).toISOString(), now)).toBe("Earlier");
    expect(dayGroup(undefined, now)).toBe("Earlier");
  });

  it("groups sorted items under headings, and keeps other sorts flat", () => {
    const items = [
      item("a", { started_at: new Date(2026, 9, 24, 9, 0).toISOString() }),
      item("b", { started_at: new Date(2026, 9, 24, 8, 0).toISOString() }),
      item("c", { started_at: new Date(2026, 8, 1, 8, 0).toISOString() }),
    ];
    expect(groupItems(items, "newest", now).map((g) => [g.label, g.items.length])).toEqual([["Today", 2], ["Earlier", 1]]);
    expect(groupItems(items, "title", now)).toEqual([{ label: null, items }]);
    expect(groupItems([], "newest", now)).toEqual([]);
  });
});

describe("itemState", () => {
  it("maps server statuses to ready / processing / failed", () => {
    expect(itemState({ status: "completed" })).toBe("ready");
    expect(itemState({ status: "processing" })).toBe("processing");
    expect(itemState({ status: "completed", transcript: { final_status: "failed", final_transcript_id: null, live_available: false, preview: null } })).toBe("failed");
    expect(itemState({ status: "failed" })).toBe("failed");
  });

  it("calls a recording without sound No audio, not failed", () => {
    expect(itemState({ status: "failed", transcript: { final_status: "failed", final_transcript_id: null, live_available: false, preview: null, final_error_code: "no_audio" } })).toBe("no_audio");
    expect(itemState({ status: "failed", transcript: { final_status: "failed", final_transcript_id: null, live_available: false, preview: null, final_error_code: "provider_rejected" } })).toBe("failed");
  });
});

describe("bookmark tags (B3)", () => {
  const items = [
    { id: "a", deleted: false, updated_at: "", title: "Kickoff", marker_count: 3, tags: ["Budget", "Deadline"] },
    { id: "b", deleted: false, updated_at: "", title: "Standup", marker_count: 1, tags: [] },
    { id: "c", deleted: false, updated_at: "", title: "Review" },
    { id: "d", deleted: true, updated_at: "", tags: ["Secret"] },
  ] as LibraryItem[];

  it("lists the tags in use, sorted, without deleted recordings", () => {
    expect(libraryTags(items)).toEqual(["Budget", "Deadline"]);
  });

  it("filters by any bookmark or by one tag", () => {
    expect(filterByTag(items, null)).toHaveLength(4);
    expect(filterByTag(items, "*").map((i) => i.id)).toEqual(["a", "b"]);
    expect(filterByTag(items, "Deadline").map((i) => i.id)).toEqual(["a"]);
  });

  it("finds recordings by tag in the search box", () => {
    expect(searchItems(items, "budget").map((i) => i.id)).toEqual(["a"]);
  });

  it("reads bookmarks from the session detail in time order and skips broken ones", () => {
    const detail = { markers: [{ id: "m2", at_ms: 9_000, tag: "Budget", note: null }, { id: "m1", at_ms: 1_000 }, { at_ms: "x" }, null] };
    expect(bookmarksOf(detail)).toEqual([
      { id: "m1", at_ms: 1_000, tag: null, note: null },
      { id: "m2", at_ms: 9_000, tag: "Budget", note: null },
    ]);
    expect(bookmarksOf({})).toEqual([]);
  });
});
