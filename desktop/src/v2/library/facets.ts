// The Library's views and filters, as contract 2.0.0-p defines them (docs/API.md "Library filters (since 2.0.0-p)"):
// one definition of each library view (All, Needs you, Unread, Important, Not transcribed, Audio only), of every filter
// section (Date, Source, Device, Project, Language, Status, Labels, Marked, Bookmarks) with "is" and "is not", of the
// search, and of the live counts the filter panel shows (the facet rule). The desktop works them out from its synced
// library, so they are instant and work offline; the server, the web console and the phone get the same numbers, and
// all of them check the shared file web/tests/Fixtures/library-facets.json. Filters are kept in the contract's own
// shape (a filter set), so a saved view goes to the server as it is. Pure, so the rules are tested without a window.
import type { LibraryItem } from "../../lib/ipc";

/** The library views in the sidebar's LIBRARY section, in its order. */
export type LibraryViewId = "all" | "needs" | "unread" | "important" | "not_transcribed" | "audio_only";
export const LIBRARY_VIEWS: readonly LibraryViewId[] = ["all", "needs", "unread", "important", "not_transcribed", "audio_only"];
export const LIBRARY_VIEW_NAMES: Record<LibraryViewId, string> = {
  all: "All", needs: "Needs you", unread: "Unread", important: "Important", not_transcribed: "Not transcribed", audio_only: "Audio only",
};

/** The filter sections, in the panel's (and the contract's) order. */
export type SectionKey = "date" | "source" | "device" | "project" | "language" | "status" | "labels" | "marked" | "bookmarks";
export const SECTIONS: readonly SectionKey[] = ["date", "source", "device", "project", "language", "status", "labels", "marked", "bookmarks"];
export const SECTION_NAMES: Record<SectionKey, string> = {
  date: "Date", source: "Source", device: "Device", project: "Project", language: "Language", status: "Status", labels: "Labels", marked: "Marked",
  bookmarks: "Bookmarks",
};

/** One section's choice, as the contract writes it: any of `values` ("is"), or none of them ("is_not"). Date's `custom`
 * has its days in `from` and `to` (YYYY-MM-DD, both kept, either end open). */
export type Op = "is" | "is_not";
export type Clause = { op?: Op; values: readonly string[]; from?: string; to?: string };
/** A filter set: one entry per section in use. */
export type FilterSpec = Partial<Record<SectionKey, Clause>>;

export type DatePreset = "today" | "last_7_days" | "this_month" | "custom";
export const DATE_PRESETS: readonly DatePreset[] = ["today", "last_7_days", "this_month", "custom"];
export const DATE_NAMES: Record<DatePreset, string> = { today: "Today", last_7_days: "Last 7 days", this_month: "This month", custom: "Custom" };

/** A recording's status, one of four (Status's options; Not transcribed is also a library view). */
export type StatusValue = "transcribed" | "not_transcribed" | "processing" | "failed";
export const STATUS_NAMES: Record<StatusValue, string> = { transcribed: "Transcribed", not_transcribed: "Not transcribed", processing: "Processing", failed: "Failed" };
const STATUS_ORDER: readonly StatusValue[] = ["transcribed", "not_transcribed", "processing", "failed"];

export const MARKED_NAMES = { pinned: "Pinned", important: "Important", keep_forever: "Keep forever" } as const;
const MARKED_ORDER = Object.keys(MARKED_NAMES) as (keyof typeof MARKED_NAMES)[];

export const DEVICE_NAMES: Record<string, string> = { phone: "Phone", desktop: "Desktop", web: "Web" };
const DEVICE_ORDER = ["phone", "desktop", "web"];

/** Source's options; today's smart folders (WhatsApp, Meet, Zoom, Teams, Voice notes, Imported…) are these. */
const SOURCE_NAMES: Record<string, string> = {
  whatsapp: "WhatsApp", whatsapp_business: "WhatsApp Business", google_meet: "Meet", zoom: "Zoom", microsoft_teams: "Teams", phone_call: "SIM calls",
  telegram: "Telegram", signal: "Signal", messenger: "Messenger", instagram: "Instagram", skype: "Skype", slack: "Slack", discord: "Discord",
  voice_note: "Voice notes", imported: "Imported", other: "Other",
};
const SOURCE_ALIASES: Record<string, string> = { whatsapp_web: "whatsapp" };

/** Language's options: the offered codes, then Unknown. */
const LANGUAGE_NAMES: Record<string, string> = {
  en: "English", ur: "Urdu", ar: "Arabic", fr: "French", de: "German", es: "Spanish", it: "Italian", pt: "Portuguese", nl: "Dutch", tr: "Turkish", ru: "Russian",
  unknown: "Unknown",
};

/** A transcript on its way, or a recording still moving: Processing. */
const UNDER_WAY = new Set(["pending", "submitted", "processing", "awaiting_device"]);
const MOVING = new Set(["uploading", "processing"]);
/** How long a recording may be processing after it ended before it needs the person. */
export const STUCK_MS = 24 * 60 * 60_000;

/** The facets the server sends with a library item (since 2.0.0-p): the apps count with these. */
export type ServerFacets = { source?: string; status?: string; language?: string };
const facetsOf = (item: LibraryItem): ServerFacets | undefined => (item as LibraryItem & { facets?: ServerFacets }).facets;

/** A recording's status: the server's when it sent one; else worked out the same way (the first that holds). */
export function statusOf(item: LibraryItem): StatusValue {
  const sent = facetsOf(item)?.status;
  if (sent && (STATUS_ORDER as readonly string[]).includes(sent)) return sent as StatusValue;
  const final = item.transcript?.final_status ?? null;
  if (item.status === "failed" || final === "failed") return "failed";
  if (final === "completed" || (final === null && item.status === "completed")) return "transcribed";
  if (MOVING.has(item.status ?? "") || UNDER_WAY.has(final ?? "")) return "processing";
  return "not_transcribed";
}

/** A language as one of the offered codes, or null: "en+ur" and Hindi are Urdu, "ar-EG" is Arabic, others pass over. */
function languageCode(raw: string | null | undefined): string | null {
  const s = (raw ?? "").trim().toLowerCase();
  if (!s || s === "auto") return null;
  if (s.includes("+")) return s.split("+").includes("ur") ? "ur" : languageCode(s.split("+")[0]);
  if (s === "hi" || s === "hindi") return "ur";
  const code = s.split(/[-_]/)[0];
  return code !== "unknown" && LANGUAGE_NAMES[code] ? code : null;
}

/** A recording's language: the server's when it sent one; else the final transcript's, else the one chosen, else Unknown. */
export function languageOf(item: LibraryItem): string {
  const sent = facetsOf(item)?.language;
  if (sent && LANGUAGE_NAMES[sent]) return sent;
  return languageCode(item.transcript?.final_language) ?? languageCode(item.language) ?? languageCode(item.language_chip) ?? "unknown";
}

/**
 * A recording's Source value (Imported, else its app; WhatsApp Web is WhatsApp; an app the contract doesn't name is
 * Other, as the server files it) and its words: a row's logo filters by it.
 */
export function sourceOf(item: LibraryItem, imported?: ReadonlySet<string>): { value: string; label: string } {
  const raw = facetsOf(item)?.source ?? (imported?.has(item.id) ? "imported" : item.source_app ? SOURCE_ALIASES[item.source_app] ?? item.source_app : "other");
  const value = raw in SOURCE_NAMES ? raw : "other";
  return { value, label: SOURCE_NAMES[value] };
}

/** When a recording ended (else started), as ms. */
const endedAt = (item: LibraryItem) => Date.parse((item as LibraryItem & { ended_at?: string | null }).ended_at || item.started_at || "");

/**
 * Why a recording needs the person, or null (Needs you, one reason at most, the first that holds): `waiting` (a call
 * still waiting for Keep or Discard on the server: session `recording`), `failed` (Status Failed: an upload, a
 * transcript or the recording failed), `stuck` (still Processing 24 hours or more after it ended). The calls and
 * uploads waiting on this computer are not in the library list: needsYouCount adds them.
 */
export function needsReason(item: LibraryItem, now: number): "waiting" | "failed" | "stuck" | null {
  if (item.deleted) return null;
  if (item.status === "recording") return "waiting";
  const status = statusOf(item);
  if (status === "failed") return "failed";
  if (status !== "processing") return null;
  const ended = endedAt(item);
  return Number.isFinite(ended) && now - ended >= STUCK_MS ? "stuck" : null;
}
export const needsYou = (item: LibraryItem, now: number) => needsReason(item, now) !== null;

/** Needs you's count: the recordings that need the person, and the calls and uploads waiting on this computer. */
export function needsYouCount(items: readonly LibraryItem[], local: { waiting: number; failedUploads: number }, now: number): number {
  return items.filter((i) => needsYou(i, now)).length + Math.max(0, local.waiting) + Math.max(0, local.failedUploads);
}

export type ValueSection = Exclude<SectionKey, "date">;

/** What the rules read of a recording, worked out once per list (filtering 2,000 recordings stays well under 200 ms). */
export type Facts = {
  item: LibraryItem;
  /** Its local day (in the time zone counted in) as YYYY-MM-DD; "" without a start. */
  day: string;
  values: Record<ValueSection, readonly string[]>;
  unread: boolean;
  needs: boolean;
  /** Its title and transcript, and its project's name with every folder above it, in small letters, for the search. */
  title: string;
  text: string;
  project: string;
};

/** The words of each value met, for the options and the pills ("whatsapp" → "WhatsApp", a project's id → its name). */
export type Names = Record<ValueSection, Map<string, string>>;

/** A project as the panel needs it: its folder above it (Project is Acme keeps Acme's subfolders too). */
export type ProjectNode = { id: string; name: string; parent_id?: string | null };
/** A label (one of the person's lists): its key and name. */
export type LabelNode = { key: string; name: string };

/** YYYY-MM-DD of a moment, in a time zone (the device's when none is given). */
function dayFormatter(tz?: string): (t: number) => string {
  const f = new Intl.DateTimeFormat("en-CA", { timeZone: tz, year: "numeric", month: "2-digit", day: "2-digit" });
  return (t: number) => f.format(t);
}
const addDays = (day: string, n: number) => {
  const d = new Date(`${day}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
};

export type Context = {
  /** Opened on this computer since the list was read: not unread any more, before the server says so. */
  read?: ReadonlySet<string>;
  /** The ids the server files as Imported (for items without the server's facets). */
  imported?: ReadonlySet<string>;
  /** The person's projects as a tree, and their labels in order (the panel offers them all, empty ones too). */
  projects?: readonly ProjectNode[];
  lists?: readonly LabelNode[];
  now?: number;
  /** The time zone dates are counted in (the device's when left out). */
  tz?: string;
};

/** Every recording's facts (not those deleted or still recording: they are in no list), and the names of every value. */
export function prepare(items: readonly LibraryItem[], context: Context = {}): { facts: Facts[]; names: Names } {
  const now = context.now ?? Date.now();
  const dayOf = dayFormatter(context.tz);
  const names: Names = { source: new Map(), device: new Map(), project: new Map(), language: new Map(), status: new Map(), labels: new Map(), marked: new Map(), bookmarks: new Map() };
  const name = (section: ValueSection, value: string, words: string) => {
    if (!names[section].has(value)) names[section].set(value, words);
    return value;
  };
  for (const v of STATUS_ORDER) names.status.set(v, STATUS_NAMES[v]);
  for (const v of MARKED_ORDER) names.marked.set(v, MARKED_NAMES[v]);
  names.bookmarks.set("any", "Any bookmark");
  names.project.set("none", "No project");
  for (const l of context.lists ?? []) names.labels.set(l.key, l.name);
  const parents = new Map<string, string | null>();
  for (const p of context.projects ?? []) {
    parents.set(p.id, p.parent_id ?? null);
    names.project.set(p.id, p.name);
  }
  /** A project and every folder above it. */
  const chain = (id: string) => {
    const out = [id];
    for (let up = parents.get(id) ?? null, guard = 0; up && guard < 8; up = parents.get(up) ?? null, guard++) out.push(up);
    return out;
  };
  const facts: Facts[] = [];
  for (const item of items) {
    if (item.deleted || item.status === "recording") continue;
    const time = item.started_at ? Date.parse(item.started_at) : NaN;
    const source = sourceOf(item, context.imported);
    const language = languageOf(item);
    const marked: string[] = [];
    if (item.pinned) marked.push("pinned");
    if (item.important) marked.push("important");
    if (item.keep) marked.push("keep_forever");
    const bookmarks: string[] = [];
    if ((item.marker_count ?? 0) > 0) bookmarks.push("any");
    for (const t of item.tags ?? []) if (t) bookmarks.push(name("bookmarks", t.toLowerCase(), t));
    facts.push({
      item, day: Number.isFinite(time) ? dayOf(time) : "",
      values: {
        source: [name("source", source.value, source.label)],
        device: item.source_device ? [name("device", item.source_device, DEVICE_NAMES[item.source_device] ?? item.source_device)] : [],
        project: item.client ? chain(name("project", item.client.id, item.client.name)) : ["none"],
        language: [name("language", language, LANGUAGE_NAMES[language] ?? language)],
        status: [statusOf(item)],
        // Labels and bookmark tags match whatever their capitals ("Q4" and "q4" are one), shown in the first spelling met.
        labels: [...new Set((item.labels ?? []).map((l) => name("labels", l.toLowerCase(), l)))],
        marked,
        bookmarks: [...new Set(bookmarks)],
      },
      unread: item.opened === false && !context.read?.has(item.id),
      needs: needsYou(item, now),
      title: foldText(item.title ?? ""),
      text: foldText(item.transcript?.preview ?? ""),
      // Its project's name and the folders above it, kept apart (a word never runs from one name into the next).
      project: item.client ? chain(item.client.id).map((id) => foldText(names.project.get(id) ?? "")).join("\n") : "",
    });
  }
  return { facts, names };
}

/** Whether a recording is in a library view. */
export function inLibraryView(f: Facts, view: LibraryViewId): boolean {
  switch (view) {
    case "all": return true;
    case "needs": return f.needs;
    case "unread": return f.unread;
    case "important": return !!f.item.important;
    case "not_transcribed": return f.values.status[0] === "not_transcribed";
    case "audio_only": return f.item.status === "audio_only";
  }
}

/** Each library view's count. */
export function libraryViewCounts(facts: readonly Facts[]): Record<LibraryViewId, number> {
  const counts = Object.fromEntries(LIBRARY_VIEWS.map((v) => [v, 0])) as Record<LibraryViewId, number>;
  for (const f of facts) for (const v of LIBRARY_VIEWS) if (inLibraryView(f, v)) counts[v]++;
  return counts;
}

// Urdu spelling variants made one for the search, as the server folds them (App\Support\UrduText): Arabic Yeh, Alef
// Maksura and Yeh with Hamza → ی; Arabic Kaf → ک; Arabic Heh, Teh Marbuta and Heh with Yeh above → ہ; Alef with Hamza,
// Madda or Wasla → ا; Waw with Hamza → و; Yeh Barree with Hamza → ے; Arabic-Indic digits → 0-9; harakat, superscript
// Alef, tatweel and zero-width joiners dropped. Latin and Devanagari stay as they are.
const FOLD: Record<string, string> = {
  "\u064A": "\u06CC", "\u0649": "\u06CC", "\u0626": "\u06CC",
  "\u0643": "\u06A9",
  "\u0647": "\u06C1", "\u0629": "\u06C1", "\u06C0": "\u06C1", "\u06C3": "\u06C1",
  "\u0623": "\u0627", "\u0625": "\u0627", "\u0622": "\u0627", "\u0671": "\u0627",
  "\u0624": "\u0648",
  "\u06D3": "\u06D2",
};
const FOLD_DROP = /[\u064B-\u065F\u0670\u0640\u200C\u200D\u200E\u200F]/gu;

/**
 * Text as the search compares it: Urdu spellings folded to one, in small letters one character at a time, as the
 * server's mb_strtolower does (a word-final Σ is σ, never ς).
 */
export function foldText(text: string): string {
  let out = "";
  for (const ch of text.replace(FOLD_DROP, "")) {
    const cp = ch.codePointAt(0)!;
    out += (cp >= 0x0660 && cp <= 0x0669 ? String(cp - 0x0660) : cp >= 0x06F0 && cp <= 0x06F9 ? String(cp - 0x06F0) : FOLD[ch] ?? ch).toLowerCase();
  }
  return out;
}

/**
 * The search's words, as the server reads a query (2 to 200 characters; shorter, or only punctuation, is no search):
 * a "quoted phrase" stays one word, the rest split on spaces; quotes and + - * ( ) < > ~ @ round a word are dropped;
 * each word once, at most 8, each at most 64 characters; folded (foldText).
 */
export function searchWords(q: string): string[] {
  const s = q.trim().slice(0, 200);
  if (s.length < 2 || !/[\p{L}\p{N}]/u.test(s)) return [];
  const words: string[] = [];
  for (const m of s.matchAll(/"([^"]+)"|(\S+)/gu)) {
    const word = foldText((m[1] ?? m[2] ?? "").trim().replace(/^[\s"'+\-*()<>~@]+|[\s"'+\-*()<>~@]+$/gu, "").slice(0, 64));
    if (word && !words.includes(word)) words.push(word);
  }
  return words.slice(0, 8);
}
/**
 * The search keeps a recording when every word is somewhere among its title, its transcript and its project's path (the
 * project's name and the folders above it), each word in any of them: "contracts clause" finds a recording in Contracts
 * whose transcript says "clause"; "acme" finds Acme Holdings › Contracts too.
 */
export const matchesSearch = (f: Facts, words: readonly string[]) =>
  words.every((w) => f.title.includes(w) || f.text.includes(w) || f.project.includes(w));

/** The days a Date choice keeps, inclusive, as YYYY-MM-DD; null for an open end. Nothing after today is in Today or Last 7 days. */
export function dateRange(preset: DatePreset, now: number, tz?: string, custom: { from?: string; to?: string } = {}): { from: string | null; to: string | null } {
  const today = dayFormatter(tz)(now);
  switch (preset) {
    case "today": return { from: today, to: today };
    case "last_7_days": return { from: addDays(today, -6), to: today };
    case "this_month": {
      const [y, m] = today.split("-").map(Number);
      return { from: `${today.slice(0, 8)}01`, to: `${today.slice(0, 8)}${String(new Date(Date.UTC(y, m, 0)).getUTCDate()).padStart(2, "0")}` };
    }
    case "custom": return { from: custom.from || null, to: custom.to || null };
  }
}
const inDays = (day: string, range: { from: string | null; to: string | null }) =>
  !!day && (!range.from || day >= range.from) && (!range.to || day <= range.to);

/** A section is on: a value chosen (Date's Any time, or Custom with neither day, is none). */
export function sectionOn(spec: FilterSpec, section: SectionKey): boolean {
  const c = spec[section];
  if (!c || c.values.length === 0) return false;
  if (section === "date") return c.values[0] !== "custom" || !!c.from || !!c.to;
  return true;
}

/** The sections that are on, in the panel's order: the pills, and the n of [Filters n]. */
export const activeSections = (spec: FilterSpec): SectionKey[] => SECTIONS.filter((s) => sectionOn(spec, s));

/** A section that is on, made ready once per call: its date ranges worked out, its values in a set. */
type Test = { section: SectionKey; passes: (f: Facts) => boolean };
function tests(spec: FilterSpec, now: number, tz?: string): Test[] {
  return activeSections(spec).map((section) => {
    const clause = spec[section]!;
    const not = clause.op === "is_not";
    if (section === "date") {
      const ranges = clause.values.filter((v): v is DatePreset => DATE_PRESETS.includes(v as DatePreset)).map((v) => dateRange(v, now, tz, clause));
      return { section, passes: (f) => ranges.some((r) => inDays(f.day, r)) !== not };
    }
    const values = new Set(clause.values);
    return { section, passes: (f) => f.values[section].some((v) => values.has(v)) !== not };
  });
}

/** Whether a recording passes every section that is on. */
export function matches(f: Facts, spec: FilterSpec, now: number = Date.now(), tz?: string): boolean {
  return tests(spec, now, tz).every((t) => t.passes(f));
}

export type FacetOption = { value: string; label: string; count: number };

/**
 * The panel's options with their live counts (the facet rule): for each section, how many of these recordings (the
 * view's, searched) have each value and pass every *other* section that is on, so a tick never hides its own
 * neighbours; a recording counts once in each option it has. Offered: Date's three, Status's four, Marked's three and
 * Bookmarks' Any always; the values the person's recordings have (`offered`: the whole library, so the search or a view
 * never takes an option away); every project (and No project) and every label, empty ones too; and any value the
 * filters name, at 0. Each section's options come most recordings first (ties A–Z), but Date,
 * Device and Status keep their own order.
 */
export function facetCounts(facts: readonly Facts[], spec: FilterSpec, names: Names, now: number = Date.now(), tz?: string, offered: readonly Facts[] = facts): Record<SectionKey, FacetOption[]> {
  const out = {} as Record<SectionKey, FacetOption[]>;
  // Which sections each recording fails: it counts for a section's options when it fails no other section.
  const checks = tests(spec, now, tz);
  const fails = facts.map((f) => checks.filter((t) => !t.passes(f)).map((t) => t.section));
  const counted = (i: number, section: SectionKey) => fails[i].length === 0 || (fails[i].length === 1 && fails[i][0] === section);

  out.date = (["today", "last_7_days", "this_month"] as const).map((p) => {
    const range = dateRange(p, now, tz);
    return { value: p, label: DATE_NAMES[p], count: facts.reduce((n, f, i) => n + Number(counted(i, "date") && inDays(f.day, range)), 0) };
  });
  out.date.push({ value: "custom", label: DATE_NAMES.custom, count: 0 });
  for (const section of SECTIONS) {
    if (section === "date") continue;
    const counts = new Map<string, number>();
    const offer = (v: string) => { if (!counts.has(v)) counts.set(v, 0); };
    if (section === "status") STATUS_ORDER.forEach(offer);
    if (section === "marked") MARKED_ORDER.forEach(offer);
    if (section === "bookmarks") offer("any");
    if (section === "project") names.project.forEach((_, id) => offer(id));
    if (section === "labels") names.labels.forEach((_, key) => offer(key));
    for (const f of offered) f.values[section].forEach(offer);
    facts.forEach((f, i) => {
      if (counted(i, section)) for (const v of f.values[section]) counts.set(v, (counts.get(v) ?? 0) + 1);
    });
    for (const v of spec[section]?.values ?? []) offer(v);
    out[section] = [...counts.entries()].map(([value, count]) => ({ value, label: optionName(section, value, names), count })).sort(order(section));
  }
  return out;
}

/** An option's words. */
function optionName(section: ValueSection, value: string, names: Names): string {
  return names[section].get(value)
    ?? (section === "language" ? LANGUAGE_NAMES[value] : section === "source" ? SOURCE_NAMES[value] : section === "device" ? DEVICE_NAMES[value] : undefined)
    ?? value;
}

/** Most recordings first, ties A–Z; Status and Device keep their own order. */
function order(section: ValueSection) {
  const fixed = section === "status" ? STATUS_ORDER as readonly string[] : section === "device" ? DEVICE_ORDER : null;
  return (a: FacetOption, b: FacetOption) => fixed ? rank(fixed, a.value) - rank(fixed, b.value)
    : b.count - a.count || a.label.localeCompare(b.label, undefined, { sensitivity: "base" });
}
const rank = (list: readonly string[], v: string) => (list.includes(v) ? list.indexOf(v) : list.length);

/** Ticks a value on or off in a section, keeping its "is not". */
export function toggleValue(spec: FilterSpec, section: ValueSection, value: string): FilterSpec {
  const clause = spec[section];
  const values = clause?.values ?? [];
  const next = values.includes(value) ? values.filter((v) => v !== value) : [...values, value];
  return { ...spec, [section]: { op: clause?.op ?? "is", values: next } };
}

/** Date's one choice (null: Any time), with Custom's days. */
export function setDate(spec: FilterSpec, preset: DatePreset | null, days: { from?: string; to?: string } = {}): FilterSpec {
  if (!preset) return clearSection(spec, "date");
  const op = spec.date?.op ?? "is";
  return { ...spec, date: preset === "custom" ? { op, values: ["custom"], ...(days.from ? { from: days.from } : {}), ...(days.to ? { to: days.to } : {}) } : { op, values: [preset] } };
}

/** "is" or "is not" for a section. */
export const setNot = (spec: FilterSpec, section: SectionKey, not: boolean): FilterSpec =>
  ({ ...spec, [section]: { ...(spec[section] ?? { values: [] }), op: not ? "is_not" : "is" } });

/** A section cleared (its Clear, its pill's ×). */
export function clearSection(spec: FilterSpec, section: SectionKey): FilterSpec {
  const next = { ...spec };
  delete next[section];
  return next;
}

/** A pill's words: "Source: WhatsApp, Zoom", "Source is not: WhatsApp", "Date: Last 7 days", "Date: 1 Nov – 15 Nov". */
export function pillWords(spec: FilterSpec, section: SectionKey, names: Names): string {
  const c = spec[section]!;
  const not = c.op === "is_not" ? " is not" : "";
  if (section === "date") {
    const preset = c.values[0] as DatePreset;
    if (preset !== "custom") return `Date${not}: ${DATE_NAMES[preset] ?? preset}`;
    const day = (s?: string) => (s ? new Date(`${s}T00:00:00`).toLocaleDateString(undefined, { day: "numeric", month: "short" }) : "");
    return `Date${not}: ${c.from && c.to ? `${day(c.from)} – ${day(c.to)}` : c.from ? `from ${day(c.from)}` : `until ${day(c.to)}`}`;
  }
  return `${SECTION_NAMES[section]}${not}: ${c.values.map((v) => optionName(section, v, names)).join(", ")}`;
}

/** The recordings of a view (or a label) that pass the filters: the list before it is sorted and grouped. */
export function filterFacts(facts: readonly Facts[], spec: FilterSpec, now: number = Date.now(), tz?: string): Facts[] {
  const checks = tests(spec, now, tz);
  if (checks.length === 0) return [...facts];
  return facts.filter((f) => checks.every((t) => t.passes(f)));
}

/** A filter set without empty sections, every section with its op: what a saved view keeps and two are compared by. */
export function cleanSpec(spec: FilterSpec): FilterSpec {
  const out: FilterSpec = {};
  for (const s of activeSections(spec)) {
    const c = spec[s]!;
    out[s] = { op: c.op ?? "is", values: [...c.values], ...(c.from ? { from: c.from } : {}), ...(c.to ? { to: c.to } : {}) };
  }
  return out;
}

/** A filter set read from elsewhere (a saved view, the server): sections and options this app doesn't know are left out. */
export function readSpec(raw: unknown): FilterSpec {
  if (!raw || typeof raw !== "object") return {};
  const out: FilterSpec = {};
  for (const s of SECTIONS) {
    const c = (raw as Record<string, unknown>)[s] as { op?: unknown; values?: unknown; from?: unknown; to?: unknown } | undefined;
    if (!c || !Array.isArray(c.values)) continue;
    const values = c.values.filter((v): v is string => typeof v === "string" && (s !== "date" || DATE_PRESETS.includes(v as DatePreset)));
    if (!values.length) continue;
    out[s] = { op: c.op === "is_not" ? "is_not" : "is", values, ...(typeof c.from === "string" ? { from: c.from } : {}), ...(typeof c.to === "string" ? { to: c.to } : {}) };
  }
  return out;
}
