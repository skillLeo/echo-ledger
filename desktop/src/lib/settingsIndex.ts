// Settings search (v1.7): every individual setting of lib/settingsRegistry.ts, found by its title, options, what it does,
// its keywords in English, Roman Urdu and Urdu, and where it lives. The rules are the shared ones (the phone and the
// console match the same way): normalise, split into words that must all match, score by the best field each word
// needs, then pins for short ambiguous words and a fuzzy pass only when nothing matched. Pure; settingsIndex.test.ts
// holds the golden queries.
import { breadcrumbOf, depthOf, SETTINGS, type SettingEntry } from "./settingsRegistry";

/** What decides whether an entry is offered here: this computer, the account, the plan. */
export type Availability = (entry: SettingEntry) => boolean;

export interface SettingResult {
  entry: SettingEntry;
  score: number;
  breadcrumb: string;
  /** The query's words found in the title, for the bold part of the row. */
  matched: string[];
}

export interface SearchAnswer {
  settings: SettingResult[];
  help: SettingResult[];
  /** When nothing matched: up to three real entries close to the first word ("Closest matches"). */
  closest: SettingEntry[];
}

/** Short ambiguous words whose answer is fixed (in this order, ahead of every scored result). */
export const PINS: Readonly<Record<string, readonly string[]>> = {
  pro: ["plan.upgrade"], ultra: ["plan.upgrade"], premium: ["plan.upgrade"], price: ["plan.upgrade"], upgrade: ["plan.upgrade"],
  package: ["plan"], plan: ["plan"],
  payment: ["plan.payment_details"],
  "2fa": ["security.two_step"], otp: ["security.two_step"],
  lock: ["lock"],
  colour: ["appearance.wallpaper", "appearance.theme"], rang: ["appearance.wallpaper", "appearance.theme"],
};

const MAX_SETTINGS = 30;
const MAX_HELP = 5;

const URDU_FOLD: Record<string, string> = { "\u064A": "\u06CC", "\u0649": "\u06CC", "\u0643": "\u06A9", "\u0647": "\u06C1", "\u0629": "\u06C1" };
const US_UK: Record<string, string> = { color: "colour", colors: "colours", gray: "grey", organize: "organise", canceled: "cancelled" };

/** Lower case, no accents or Urdu marks, punctuation as spaces, one space between words, US spellings as UK. */
export function normalise(text: string): string {
  const plain = text
    .normalize("NFKD")
    .replace(/\p{M}/gu, "")
    .toLowerCase()
    .replace(/[\u064A\u0649\u0643\u0647\u0629]/g, (c) => URDU_FOLD[c] ?? c)
    .replace(/[\u0640\u200C\u200D]/g, "")
    .replace(/[&,.·›/()\-'"“”‘’!?:;]/g, " ")
    .replace(/\s+/g, " ")
    .trim();
  return plain.split(" ").map((w) => US_UK[w] ?? w).join(" ");
}

/** Doubled letters as one, for words of four letters or more ("aawaz" → "awaz", "raseed" → "rased"). */
export function fold(token: string): string {
  return token.length >= 4 && /^[a-z]+$/.test(token) ? token.replace(/([a-z])\1+/g, "$1") : token;
}

const tokens = (text: string): string[] => normalise(text).split(" ").filter(Boolean);
/** Never indexed from any field: switch values and bare numbers. */
const indexable = (token: string) => token !== "on" && token !== "off" && token !== "—" && !/^\d+$/.test(token);

/** Damerau-Levenshtein (optimal string alignment) distance. */
export function distance(a: string, b: string): number {
  const d: number[][] = Array.from({ length: a.length + 1 }, (_, i) => [i, ...Array(b.length).fill(0)]);
  for (let j = 1; j <= b.length; j++) d[0][j] = j;
  for (let i = 1; i <= a.length; i++) {
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
      if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
    }
  }
  return d[a.length][b.length];
}

/** The tier each field gives a word that matches in it. */
const TIER = { title: 800, option: 650, description: 500, keyword: 400, path: 300 } as const;
type Field = keyof typeof TIER;

interface Indexed {
  entry: SettingEntry;
  titles: string[];
  fields: Record<Field, string[]>;
  /** Multi-word keywords, normalised ("dark mode"). */
  phrases: string[];
  /** Title, alias, option and English keyword words: the only ones a typo may reach. */
  fuzzy: string[];
}

function indexEntry(entry: SettingEntry): Indexed {
  const titles = [entry.title, ...(entry.aliases ?? [])].map(normalise);
  const words = (list: readonly string[] | undefined) => (list ?? []).flatMap(tokens).filter(indexable);
  const withOptions = entry.kind === "choice" || entry.kind === "segmented";
  const keywordList = [...(entry.keywords ?? []), ...(entry.ru ?? []), ...(entry.ur ?? [])];
  const fields: Record<Field, string[]> = {
    title: titles.flatMap((t) => t.split(" ")).filter(indexable),
    option: withOptions ? words(entry.options?.map((o) => o.label)) : [],
    description: words(entry.kind === "help" ? [entry.answer ?? ""] : entry.description ? [entry.description] : []),
    keyword: words(keywordList),
    path: words(breadcrumbOf(entry).split(" › ")),
  };
  return {
    entry,
    titles,
    fields,
    phrases: keywordList.map(normalise).filter((k) => k.includes(" ")),
    fuzzy: [...fields.title, ...fields.option, ...words(entry.keywords)],
  };
}

let cache: Indexed[] | null = null;
function index(): Indexed[] {
  cache ??= SETTINGS.map(indexEntry);
  return cache;
}

/** Whether `word` (one query word) matches the word list of a field: by prefix, or as a whole word once folded. */
function wordMatches(word: string, list: readonly string[], field: Field): boolean {
  // One letter matches title words only.
  if (word.length === 1 && field !== "title") return false;
  const folded = fold(word);
  return list.some((t) => t.startsWith(word) || (folded.length >= 3 && fold(t) === folded));
}

/** The best (highest) tier `word` reaches in the entry, or 0 when it matches nowhere. */
function wordTier(word: string, item: Indexed): number {
  for (const field of ["title", "option", "description", "keyword", "path"] as Field[]) {
    if (wordMatches(word, item.fields[field], field)) return TIER[field];
  }
  return 0;
}

/** The entry's tier for the query words (0: some word matches nowhere). */
function entryTier(words: string[], query: string, item: Indexed): number {
  if (item.titles.some((t) => t === query || t.startsWith(query))) return 1000;
  // Phrase keywords: consecutive query words equal to a multi-word keyword match together at the keyword tier.
  const covered = new Set<number>();
  for (const phrase of item.phrases) {
    const parts = phrase.split(" ");
    for (let i = 0; i + parts.length <= words.length; i++) {
      if (parts.every((p, k) => words[i + k] === p)) parts.forEach((_, k) => covered.add(i + k));
    }
  }
  let tier = 1000;
  for (let i = 0; i < words.length; i++) {
    const own = wordTier(words[i], item);
    const best = Math.max(own, covered.has(i) ? TIER.keyword : 0);
    if (best === 0) return 0;
    tier = Math.min(tier, best);
  }
  return tier === 1000 ? TIER.title : tier;
}

const adjust = (entry: SettingEntry, tier: number) => tier - 15 * depthOf(entry) + (entry.kind === "page" ? 40 : 0);

function ranked(list: { item: Indexed; score: number }[]): { item: Indexed; score: number }[] {
  const order = new Map(SETTINGS.map((e, i) => [e.id, i]));
  return list.sort((a, b) => b.score - a.score || a.item.entry.title.length - b.item.entry.title.length || order.get(a.item.entry.id)! - order.get(b.item.entry.id)!);
}

/** The query word a typo stands for, from title, option and English keyword words only; null when none is close. */
function corrected(word: string, items: Indexed[]): string | null {
  if (word.length < 4) return null;
  const allowed = word.length >= 8 ? 2 : 1;
  let best: { token: string; d: number } | null = null;
  for (const item of items) {
    for (const t of item.fuzzy) {
      if (Math.abs(t.length - word.length) > allowed) continue;
      const d = distance(word, t);
      if (d <= allowed && (!best || d < best.d)) best = { token: t, d };
    }
  }
  return best?.token ?? null;
}

function toResult(item: Indexed, score: number, words: string[]): SettingResult {
  const matched = words.filter((w) => item.fields.title.some((t) => t.startsWith(w)));
  return { entry: item.entry, score, breadcrumb: breadcrumbOf(item.entry), matched };
}

/**
 * Searches the settings that `available` keeps (this computer, the account, the plan). Two characters at least; up
 * to 30 settings and 5 help answers.
 */
export function searchSettings(rawQuery: string, available: Availability = () => true): SearchAnswer {
  const query = normalise(rawQuery);
  const none: SearchAnswer = { settings: [], help: [], closest: [] };
  if (query.length < 2) return none;
  const items = index().filter((i) => available(i.entry));
  let words = query.split(" ");
  const score = (w: string[], q: string) =>
    ranked(items.map((item) => ({ item, score: entryTier(w, q, item) })).filter((s) => s.score > 0).map((s) => ({ item: s.item, score: adjust(s.item.entry, s.score) })));
  let hits = score(words, query);
  if (hits.length === 0) {
    // Fuzzy: each word of four letters or more may stand for a close title, option or keyword word; the tier it then
    // reaches loses 300.
    const fixed = words.map((w) => corrected(w, items) ?? w);
    if (fixed.some((w, i) => w !== words[i])) {
      words = fixed;
      hits = score(fixed, fixed.join(" ")).map((h) => ({ ...h, score: h.score - 300 }));
    }
  }
  const pinned = (PINS[query] ?? []).map((id) => items.find((i) => i.entry.id === id)).filter((i): i is Indexed => !!i && i.entry.kind !== "help");
  const pinnedIds = new Set(pinned.map((i) => i.entry.id));
  const settings = [
    ...pinned.map((item) => toResult(item, 10_000, words)),
    ...hits.filter((h) => h.item.entry.kind !== "help" && !pinnedIds.has(h.item.entry.id)).map((h) => toResult(h.item, h.score, words)),
  ].slice(0, MAX_SETTINGS);
  const help = hits.filter((h) => h.item.entry.kind === "help").slice(0, MAX_HELP).map((h) => toResult(h.item, h.score, words));
  const closest = settings.length === 0 && help.length === 0 ? closestTo(normalise(rawQuery).split(" ")[0] ?? "", items) : [];
  return { settings, help, closest };
}

/** Up to three entries whose title or English keyword words are within 3 edits of `word`, and that a search for that
 * word itself would find (never a suggestion that returns nothing). Never Roman Urdu or Urdu words. */
function closestTo(word: string, items: Indexed[]): SettingEntry[] {
  if (word.length < 3) return [];
  const scored: { entry: SettingEntry; d: number }[] = [];
  for (const item of items) {
    if (item.entry.kind === "help") continue;
    const words = [...item.fields.title, ...(item.entry.keywords ?? []).flatMap(tokens)];
    const d = Math.min(...words.map((t) => distance(word, t)));
    if (d <= 3) scored.push({ entry: item.entry, d });
  }
  const page = (e: SettingEntry) => (e.kind === "page" ? 0 : 1);
  return scored.sort((a, b) => a.d - b.d || page(a.entry) - page(b.entry) || a.entry.title.length - b.entry.title.length).slice(0, 3).map((s) => s.entry);
}

/** The first sentence of a help answer: the result's second line. */
export function firstSentence(text: string | undefined): string {
  if (!text) return "";
  const end = text.search(/[.!?](\s|$)/);
  return end === -1 ? text : text.slice(0, end + 1);
}
