// The shared cases of the Library's filters (contract 2.0.0-p): web/tests/Fixtures/library-facets.json, which the server,
// the desktop and the phone all read and must agree on. Every recording is turned into the library item this computer
// holds (with the facets the server sends), and for each filter set the list, its kinds (the tabs, expect.kinds) and
// every option's count must be the file's: the library views, Needs you (and why), and the filter sets with and without
// the search.
import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import type { LibraryItem } from "../../lib/ipc";
import {
  facetCounts, filterFacts, libraryViewCounts, matchesSearch, needsReason, needsYouCount, prepare, readSpec, searchWords, SECTIONS, tabCounts, type FilterSpec,
} from "./facets";

type Recording = {
  id: string; title: string; text: string | null; started_at: string; ended_at: string | null; status: string; source_app: string | null;
  source_device: "phone" | "desktop" | "web" | null; client_id: string | null; language: string | null; final_status: string | null;
  final_language: string | null; labels: string[]; pinned: boolean; important: boolean; keep: boolean; opened: boolean; deleted: boolean;
  bookmarks: (string | null)[]; facets: { source: string; status: string; language: string };
  /** The start of the current upload or transcript attempt, as the library item carries it (the server stores it from
   * transcript_attempt_at and upload_attempt_at, which the apps never read). */
  processing_since?: string | null;
};
type Fixture = {
  now: string; tz: string;
  projects: { id: string; name: string; parent_id: string | null }[];
  lists: { key: string; name: string }[];
  recordings: Recording[];
  needs_you: { count: number; failed: number; waiting: number; stuck: number; ids: Record<string, "failed" | "waiting" | "stuck"> };
  library_counts: { all: number; needs_you: number; unread: number; important: number; not_transcribed: number; audio_only: number };
  filter_sets: {
    name: string; filters: unknown; search: string | null;
    expect: { total: number; ids: string[]; facets: Record<string, Record<string, number>>; kinds: { call: number; meeting: number; voice_note: number; other: number } };
  }[];
};

// The server's file (LIBRARY_FACETS_FIXTURE: another copy of it, to try one before it lands).
const file = process.env.LIBRARY_FACETS_FIXTURE ?? join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..", "..", "web", "tests", "Fixtures", "library-facets.json");
const fixture = JSON.parse(readFileSync(file, "utf8")) as Fixture;
const now = Date.parse(fixture.now);

/** A recording as this computer holds it: a library item from the sync, with the server's facets. */
const asItem = (r: Recording): LibraryItem => ({
  id: r.id, deleted: r.deleted, updated_at: r.started_at, title: r.title, started_at: r.started_at, ended_at: r.ended_at ?? undefined,
  processing_since: r.processing_since ?? undefined,
  status: r.status, source_app: r.source_app ?? undefined, source_device: r.source_device ?? undefined,
  client: r.client_id ? { id: r.client_id, name: fixture.projects.find((p) => p.id === r.client_id)?.name ?? r.client_id } : null,
  language: (r.language ?? null) as LibraryItem["language"], labels: r.labels, pinned: r.pinned, important: r.important, keep: r.keep, opened: r.opened,
  marker_count: r.bookmarks.length, tags: [...new Set(r.bookmarks.filter((b): b is string => !!b))],
  transcript: { final_status: r.final_status, final_transcript_id: null, live_available: false, preview: r.text, final_language: r.final_language },
  facets: r.facets,
} as LibraryItem);

const items = fixture.recordings.map(asItem);
const { facts, names } = prepare(items, { now, tz: fixture.tz, projects: fixture.projects, lists: fixture.lists });

describe("the shared fixture (contract 2.0.0-p)", () => {
  it("reads every recording and filter set", () => {
    expect(fixture.recordings.length).toBeGreaterThan(40);
    // The contract's 28 at least; every set the file has is checked below.
    expect(fixture.filter_sets.length).toBeGreaterThanOrEqual(28);
  });

  it("the library views' counts: All, Needs you, Unread, Important, Audio only (the server's not_transcribed since v2.1)", () => {
    const counts = libraryViewCounts(facts);
    expect({ ...counts, needs: needsYouCount(items, { waiting: 0, failedUploads: 0 }, now) }).toEqual({
      all: fixture.library_counts.all, needs: fixture.library_counts.needs_you, unread: fixture.library_counts.unread,
      important: fixture.library_counts.important, audio_only: fixture.library_counts.not_transcribed,
    });
  });

  it("Needs you: each recording once, for the first reason that holds", () => {
    const reasons = Object.fromEntries(items.map((i) => [i.id, needsReason(i, now)]).filter(([, r]) => r !== null));
    expect(reasons).toEqual(fixture.needs_you.ids);
    const by = (r: string) => Object.values(reasons).filter((x) => x === r).length;
    expect({ count: Object.keys(reasons).length, failed: by("failed"), waiting: by("waiting"), stuck: by("stuck") })
      .toEqual({ count: fixture.needs_you.count, failed: fixture.needs_you.failed, waiting: fixture.needs_you.waiting, stuck: fixture.needs_you.stuck });
  });

  for (const set of fixture.filter_sets) {
    it(`${set.name}: the list, its kinds and every option's count`, () => {
      const spec: FilterSpec = readSpec(set.filters);
      const words = searchWords(set.search ?? "");
      const searched = facts.filter((f) => matchesSearch(f, words));
      const kept = filterFacts(searched, spec, now, fixture.tz);
      const shown = kept.map((f) => f.item.id);
      expect(shown.length, "total").toBe(set.expect.total);
      expect([...shown].sort(), "ids").toEqual([...set.expect.ids].sort());
      // The Library's tabs (contract 2.0.0-q): what the filters and the search keep, by kind, adding up to All.
      const tabs = tabCounts(kept);
      expect({ call: tabs.calls, meeting: tabs.meetings, voice_note: tabs.voice_notes, other: tabs.other }, "kinds").toEqual(set.expect.kinds);
      expect(tabs.calls + tabs.meetings + tabs.voice_notes + tabs.other, "the kinds add up to All").toBe(tabs.all);
      // Counted among what the search keeps; offered from the whole library.
      const counts = facetCounts(searched, spec, names, now, fixture.tz, facts);
      for (const section of SECTIONS) {
        const expected = set.expect.facets[section] ?? {};
        // Custom is an app's own choice (its days); the server counts the three presets.
        const ours = Object.fromEntries(counts[section].filter((o) => !(section === "date" && o.value === "custom")).map((o) => [o.value, o.count]));
        expect(ours, section).toEqual(expected);
      }
    });
  }
});
