// The Library's views and filters (facets.ts, contract 2.0.0-p): each section's options with live counts, "is" and
// "is not", the dates, the search, Needs you, the library views, and filtering 2,000 recordings in well under 200 ms.
// The shared cases every app must agree on are facetsFixture.test.ts.
import { describe, expect, it } from "vitest";
import { makeItem } from "../../test/fixtures";
import type { LibraryItem } from "../../lib/ipc";
import {
  activeSections, clearSection, cleanSpec, dateRange, facetCounts, filterFacts, inLibraryView, libraryViewCounts, matchesSearch, needsReason, needsYou,
  needsYouCount, pillWords, prepare, readSpec, searchWords, setDate, setNot, statusOf, toggleValue, STUCK_MS, type FilterSpec,
} from "./facets";

const NOW = new Date(2026, 10, 23, 12, 0); // Mon 23 Nov 2026, local
const at = (day: number, hour = 9) => new Date(2026, 10, day, hour).toISOString();
const none = { final_status: null, final_transcript_id: null, live_available: false, preview: null };

const ITEMS: LibraryItem[] = [
  makeItem("wa1", { source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone", started_at: at(23), client: { id: "c1", name: "Acme" }, labels: ["Q4"], pinned: true, language_chip: "UR" }),
  makeItem("wa2", { source_app: "whatsapp_web", source_app_label: "WhatsApp Web", source_device: "web", started_at: at(20), labels: ["q4", "Invoices"], important: true }),
  makeItem("zo1", { source_app: "zoom", started_at: at(18), client: { id: "c1", name: "Acme" }, keep: true, marker_count: 2, tags: ["Budget"] }),
  makeItem("vn1", { source_app: "voice_note", source_app_label: "Voice note", started_at: at(10), status: "audio_only", transcript: none, opened: false }),
  makeItem("tm1", { source_app: "microsoft_teams", started_at: at(2), status: "failed", transcript: { ...none, final_status: "failed" } }),
  makeItem("up1", { source_app: "zoom", started_at: at(23, 8), status: "uploading", transcript: none, opened: false }),
  makeItem("old", { source_app: "zoom", started_at: at(19), status: "processing", transcript: { ...none, final_status: "processing" } }),
  makeItem("gone", { deleted: true }),
];
const prepared = () => prepare(ITEMS, { now: NOW.getTime(), imported: new Set(["vn1"]) });

describe("a recording's status, one of four", () => {
  it("failed first, then transcribed, processing (uploading or under way), else not transcribed (audio only, nothing asked)", () => {
    expect(ITEMS.slice(0, 7).map(statusOf)).toEqual(["transcribed", "transcribed", "transcribed", "not_transcribed", "failed", "processing", "processing"]);
    expect(statusOf(makeItem("u", { status: "uploaded", transcript: none }))).toBe("not_transcribed");
  });
  it("the server's facet when it sends one", () => {
    expect(statusOf({ ...makeItem("s"), facets: { status: "processing" } } as LibraryItem)).toBe("processing");
  });
});

describe("Needs you", () => {
  it("failed, or still processing 24 hours or more after it ended", () => {
    const now = NOW.getTime();
    expect(ITEMS.filter((i) => needsYou(i, now)).map((i) => i.id)).toEqual(["tm1", "old"]);
    const ended = (ms: number) => ({ ...makeItem("p", { status: "processing", transcript: { ...none, final_status: "processing" }, started_at: new Date(now - 3 * STUCK_MS).toISOString() }), ended_at: new Date(now - ms).toISOString() }) as LibraryItem;
    // Counted from when it ended, not when it started: just under 24 hours is not stuck yet.
    expect(needsReason(ended(STUCK_MS - 60_000), now)).toBeNull();
    expect(needsReason(ended(STUCK_MS), now)).toBe("stuck");
  });
  it("one reason each, the first that holds: waiting (a call still to keep or discard), failed, stuck", () => {
    expect(needsReason(makeItem("w", { status: "recording", transcript: none }), NOW.getTime())).toBe("waiting");
    expect(needsReason(ITEMS[4], NOW.getTime())).toBe("failed");
    expect(needsReason(ITEMS[6], NOW.getTime())).toBe("stuck");
    expect(needsReason(ITEMS[7], NOW.getTime())).toBeNull();
  });
  it("counts the calls waiting and the uploads that failed on this computer on top", () => {
    expect(needsYouCount(ITEMS, { waiting: 2, failedUploads: 1 }, NOW.getTime())).toBe(5);
  });
});

describe("the library views", () => {
  it("All, Needs you, Unread, Important, Not transcribed, Audio only; a deleted recording is in none", () => {
    const { facts } = prepared();
    expect(libraryViewCounts(facts)).toEqual({ all: 7, needs: 2, unread: 2, important: 1, not_transcribed: 1, audio_only: 1 });
    expect(facts.filter((f) => inLibraryView(f, "unread")).map((f) => f.item.id)).toEqual(["vn1", "up1"]);
  });
  it("opened on this computer is read at once, before the server says so", () => {
    const { facts } = prepare(ITEMS, { now: NOW.getTime(), read: new Set(["vn1"]) });
    expect(facts.filter((f) => inLibraryView(f, "unread")).map((f) => f.item.id)).toEqual(["up1"]);
  });
});

describe("each section's options with their counts", () => {
  const counts = (spec: FilterSpec = {}) => {
    const { facts, names } = prepared();
    return facetCounts(facts, spec, names, NOW.getTime());
  };
  const table = (options: { label: string; count: number }[]) => Object.fromEntries(options.map((o) => [o.label, o.count]));
  const pairs = (options: { label: string; count: number }[]) => options.map((o) => [o.label, o.count]);

  it("Date: Today, Last 7 days, This month, Custom, in that order", () => {
    // Last 7 days on Monday the 23rd: the 17th to the 23rd.
    expect(pairs(counts().date)).toEqual([["Today", 2], ["Last 7 days", 5], ["This month", 7], ["Custom", 0]]);
  });
  it("Source: WhatsApp Web counts as WhatsApp; Imported is the server's word; most first", () => {
    expect(pairs(counts().source)).toEqual([["Zoom", 3], ["WhatsApp", 2], ["Imported", 1], ["Teams", 1]]);
  });
  it("Device and Status keep their own order; Project and Language most first", () => {
    expect(pairs(counts().device)).toEqual([["Phone", 1], ["Desktop", 5], ["Web", 1]]);
    expect(pairs(counts().status)).toEqual([["Transcribed", 3], ["Not transcribed", 1], ["Processing", 2], ["Failed", 1]]);
    expect(pairs(counts().project)).toEqual([["No project", 5], ["Acme", 2]]);
    expect(pairs(counts().language)).toEqual([["Unknown", 6], ["Urdu", 1]]);
  });
  it("Marked (each offered also at 0, ties A–Z), Labels and Bookmarks whatever their case", () => {
    expect(pairs(counts().marked)).toEqual([["Important", 1], ["Keep forever", 1], ["Pinned", 1]]);
    expect(pairs(counts().labels)).toEqual([["Q4", 2], ["Invoices", 1]]);
    expect(pairs(counts().bookmarks)).toEqual([["Any bookmark", 1], ["Budget", 1]]);
  });
  it("every project and label is offered, empty ones too; a subfolder counts in the folder above it", () => {
    const items = [...ITEMS, makeItem("eu", { client: { id: "c2", name: "Acme EU" }, started_at: at(23) })];
    const { facts, names } = prepare(items, {
      now: NOW.getTime(), projects: [{ id: "c1", name: "Acme" }, { id: "c2", name: "Acme EU", parent_id: "c1" }, { id: "c3", name: "Zed" }],
      lists: [{ key: "q4", name: "Q4" }, { key: "later", name: "Later" }],
    });
    const c = facetCounts(facts, {}, names, NOW.getTime());
    expect(pairs(c.project)).toEqual([["No project", 5], ["Acme", 3], ["Acme EU", 1], ["Zed", 0]]);
    expect(pairs(c.labels)).toEqual([["Q4", 2], ["Invoices", 1], ["Later", 0]]);
    expect(filterFacts(facts, toggleValue({}, "project", "c1"), NOW.getTime()).map((f) => f.item.id)).toEqual(["wa1", "zo1", "eu"]);
  });
  it("a section's counts follow the other sections, never its own ticks", () => {
    const c = counts(toggleValue({}, "source", "whatsapp"));
    // Source still offers every source with its own count.
    expect(table(c.source).Zoom).toBe(3);
    // The others count only WhatsApp's two.
    expect(table(c.device)).toEqual({ Phone: 1, Desktop: 0, Web: 1 });
    expect(table(c.date)).toEqual({ Today: 1, "Last 7 days": 2, "This month": 2, Custom: 0 });
  });
  it("is not counts the same way: the other sections count what it keeps", () => {
    const c = counts(setNot(toggleValue({}, "source", "zoom"), "source", true));
    expect(table(c.source).Zoom).toBe(3);
    expect(table(c.status)).toEqual({ Transcribed: 2, "Not transcribed": 1, Processing: 0, Failed: 1 });
  });
  it("a ticked value stays offered when nothing has it any more", () => {
    // Most first, the one at 0 last: offered, to take off again or for "is not".
    expect(pairs(counts(toggleValue({}, "labels", "gone")).labels)).toEqual([["Q4", 2], ["Invoices", 1], ["gone", 0]]);
  });
  it("options are offered from the whole library, so the search never takes one away", () => {
    const { facts, names } = prepared();
    const searched = facts.filter((f) => f.item.id === "wa1");
    const c = facetCounts(searched, {}, names, NOW.getTime(), undefined, facts);
    expect(pairs(c.source)).toEqual([["WhatsApp", 1], ["Imported", 0], ["Teams", 0], ["Zoom", 0]]);
  });
});

describe("is, is not, and dates", () => {
  const ids = (spec: FilterSpec) => {
    const { facts } = prepared();
    return filterFacts(facts, spec, NOW.getTime()).map((f) => f.item.id);
  };
  it("ticks inside a section add up; sections narrow each other", () => {
    expect(ids(toggleValue(toggleValue({}, "source", "whatsapp"), "source", "microsoft_teams"))).toEqual(["wa1", "wa2", "tm1"]);
    expect(ids(toggleValue(toggleValue({}, "source", "zoom"), "project", "c1"))).toEqual(["zo1"]);
  });
  it("is not keeps the recordings that have none of the values", () => {
    expect(ids(setNot(toggleValue({}, "source", "zoom"), "source", true))).toEqual(["wa1", "wa2", "vn1", "tm1"]);
    expect(ids(setNot(toggleValue({}, "labels", "q4"), "labels", true))).toEqual(["zo1", "vn1", "tm1", "up1", "old"]);
  });
  it("Today, Last 7 days, This month and a Custom range of days, both ends kept; Date is not too", () => {
    expect(ids(setDate({}, "today"))).toEqual(["wa1", "up1"]);
    expect(ids(setDate({}, "last_7_days"))).toEqual(["wa1", "wa2", "zo1", "up1", "old"]);
    expect(ids(setDate({}, "custom", { from: "2026-11-10", to: "2026-11-18" }))).toEqual(["zo1", "vn1"]);
    expect(ids(setNot(setDate({}, "today"), "date", true))).toEqual(["wa2", "zo1", "vn1", "tm1", "old"]);
    expect(dateRange("this_month", NOW.getTime())).toEqual({ from: "2026-11-01", to: "2026-11-30" });
    expect(dateRange("this_month", new Date(2026, 1, 3, 12).getTime())).toEqual({ from: "2026-02-01", to: "2026-02-28" });
  });
  it("days are counted in the time zone given", () => {
    // 20:00 UTC on the 22nd is the 23rd in Karachi (UTC+5).
    const late = prepare([makeItem("k", { started_at: "2026-11-22T20:00:00Z" })], { now: Date.parse("2026-11-23T10:00:00Z"), tz: "Asia/Karachi" });
    expect(late.facts[0].day).toBe("2026-11-23");
    expect(filterFacts(late.facts, setDate({}, "today"), Date.parse("2026-11-23T10:00:00Z"), "Asia/Karachi")).toHaveLength(1);
  });
  it("a Custom date with no end chosen is not on; Any time clears Date", () => {
    expect(activeSections(setDate({}, "custom"))).toEqual([]);
    expect(activeSections(setDate(setDate({}, "today"), null))).toEqual([]);
  });
});

describe("the search", () => {
  const { facts } = prepare([
    makeItem("a", { title: "Budget call with Acme", transcript: { ...none, preview: "We agreed the invoice." } }),
    makeItem("b", { title: "Standup", transcript: { ...none, preview: "Budget for Acme next week." } }),
  ], { now: NOW.getTime() });
  const found = (q: string) => facts.filter((f) => matchesSearch(f, searchWords(q))).map((f) => f.item.id);
  it("every word in the title, or every word in the transcript, whatever the case", () => {
    expect(found("budget acme")).toEqual(["a", "b"]);
    expect(found("ACME invoice")).toEqual([]);
    expect(found("invoice")).toEqual(["a"]);
  });
  it("one character, or only punctuation, is no search", () => {
    expect(searchWords("a")).toEqual([]);
    expect(searchWords("--")).toEqual([]);
    expect(found("x")).toEqual(["a", "b"]);
  });
});

describe("the pills, and a filter set kept or read", () => {
  it("say the section, is not, and the values in their words; × clears the section", () => {
    const { names } = prepared();
    let spec = toggleValue(toggleValue({}, "source", "whatsapp"), "source", "zoom");
    expect(pillWords(spec, "source", names)).toBe("Source: WhatsApp, Zoom");
    spec = setNot(spec, "source", true);
    expect(pillWords(spec, "source", names)).toBe("Source is not: WhatsApp, Zoom");
    expect(pillWords(setDate({}, "last_7_days"), "date", names)).toBe("Date: Last 7 days");
    expect(activeSections(clearSection(spec, "source"))).toEqual([]);
  });
  it("kept without empty sections and with every op; read back leaving out what this app doesn't know", () => {
    expect(cleanSpec({ source: { values: ["zoom"] }, labels: { op: "is_not", values: [] } })).toEqual({ source: { op: "is", values: ["zoom"] } });
    expect(readSpec({ source: { op: "is_not", values: ["zoom", 3] }, colour: { values: ["red"] }, date: { values: ["next_year"] }, marked: { op: "maybe", values: ["pinned"] } }))
      .toEqual({ source: { op: "is_not", values: ["zoom"] }, marked: { op: "is", values: ["pinned"] } });
    expect(readSpec(null)).toEqual({});
  });
});

describe("speed", () => {
  it("filters 2,000 recordings and counts every section in well under 200 ms", () => {
    const apps = ["whatsapp", "zoom", "google_meet", "microsoft_teams", "voice_note", "phone_call"];
    const many = Array.from({ length: 2000 }, (_, i) => makeItem(`r${i}`, {
      source_app: apps[i % apps.length], source_device: (["phone", "desktop", "web"] as const)[i % 3], started_at: at(1 + (i % 28)),
      client: i % 4 ? { id: `c${i % 9}`, name: `Project ${i % 9}` } : null, labels: i % 5 ? [`L${i % 12}`] : [], pinned: i % 17 === 0, tags: i % 7 ? [] : ["t"],
      marker_count: i % 7 ? 0 : 1, language_chip: ["UR", "EN", "UR+EN"][i % 3], important: i % 11 === 0, opened: i % 3 !== 0,
      title: `Call ${i} about budget`, transcript: { ...none, final_status: "completed", preview: `Words of call ${i}, the invoice and the plan.` },
    }));
    const spec: FilterSpec = {
      date: { op: "is", values: ["this_month"] }, source: { op: "is", values: ["whatsapp", "zoom"] }, labels: { op: "is_not", values: ["l3"] },
      status: { op: "is", values: ["transcribed"] },
    };
    const started = performance.now();
    const { facts, names } = prepare(many, { now: NOW.getTime() });
    const searched = facts.filter((f) => matchesSearch(f, searchWords("call budget")));
    const shown = filterFacts(searched, spec, NOW.getTime());
    const counts = facetCounts(searched, spec, names, NOW.getTime(), undefined, facts);
    const took = performance.now() - started;
    expect(shown.length).toBeGreaterThan(0);
    expect(counts.source.length).toBe(apps.length);
    expect(took).toBeLessThan(200);
  });
});
