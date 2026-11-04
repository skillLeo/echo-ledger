// Sharing and filing rules (docs/API.md "Share links", "Sending to a teammate", "Request links", "Filing rules", since
// v1.5): emails as typed, expiry choices, and the words a link, a request or a rule is shown with. Pure; unit-tested.
import type { FilingRule, LibraryItem, RequestLink, RuleKind, ShareLink } from "./ipc";
import { formatWhen, SOURCE_APP_LABELS } from "./format";

/** The most people one link or one send may name. */
export const MAX_PEOPLE = 20;

/** An email address the way the core checks it: one `@`, a dotted domain, no spaces. */
export function looksLikeEmail(text: string): boolean {
  const at = text.indexOf("@");
  if (at <= 0 || at !== text.lastIndexOf("@")) return false;
  const domain = text.slice(at + 1);
  return domain.length >= 3 && domain.includes(".") && !domain.startsWith(".") && !domain.endsWith(".") && !/\s/.test(text);
}

/** Emails typed into one field, split at commas, semicolons, spaces and new lines, without repeats; `invalid` holds the rest. */
export function parseEmails(text: string): { emails: string[]; invalid: string[] } {
  const emails: string[] = [];
  const invalid: string[] = [];
  for (const part of text.split(/[\s,;]+/).map((p) => p.replace(/^<|>$/g, "")).filter(Boolean)) {
    if (!looksLikeEmail(part)) invalid.push(part);
    else if (!emails.some((e) => e.toLowerCase() === part.toLowerCase())) emails.push(part);
  }
  return { emails, invalid };
}

export type ExpiryChoice = "never" | "hour" | "day" | "week" | "month" | "date";
export const EXPIRY_CHOICES: readonly (readonly [ExpiryChoice, string])[] = [
  ["never", "Never"],
  ["hour", "In 1 hour"],
  ["day", "In 1 day"],
  ["week", "In 7 days"],
  ["month", "In 30 days"],
  ["date", "On a date…"],
];
const EXPIRY_MS: Partial<Record<ExpiryChoice, number>> = { hour: 3_600_000, day: 86_400_000, week: 7 * 86_400_000, month: 30 * 86_400_000 };

/** When a choice stops a link: an RFC 3339 time, the end of the picked local day for "date", null for never (or no day yet). */
export function expiryTime(choice: ExpiryChoice, now: Date, day = ""): string | null {
  const ms = EXPIRY_MS[choice];
  if (ms) return new Date(now.getTime() + ms).toISOString();
  if (choice !== "date") return null;
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day);
  if (!m) return null;
  return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), 23, 59, 59).toISOString();
}

/** Who may open a link, in words. */
export function audienceText(link: Pick<ShareLink, "audience" | "people" | "has_password">): string {
  if (link.audience === "people") return link.people && link.people.length > 0 ? `Only ${link.people.join(", ")}` : "Only the people chosen";
  if (link.audience === "password" || (!link.audience && link.has_password)) return "Anyone with the link and the password";
  return "Anyone with the link";
}

export function linkState(link: Pick<ShareLink, "active" | "revoked_at">): "Active" | "Revoked" | "Expired" {
  return link.active ? "Active" : link.revoked_at ? "Revoked" : "Expired";
}

/** "3 views · last viewed Oct 30 · 4:05 pm", "Not viewed yet". */
export function viewsText(link: Pick<ShareLink, "view_count" | "last_viewed_at">): string {
  if (!link.view_count) return "Not viewed yet";
  const views = `${link.view_count} view${link.view_count === 1 ? "" : "s"}`;
  return link.last_viewed_at ? `${views} · last viewed ${formatWhen(link.last_viewed_at)}` : views;
}

/** What a link lets people do besides reading: download, or (an older server's link) listen. */
export function downloadText(link: Pick<ShareLink, "allow_download" | "include_audio">): string {
  const download = link.allow_download ?? link.include_audio;
  return download ? "Downloads allowed" : "Read and listen only";
}

export const RULE_KINDS: readonly (readonly [RuleKind, string])[] = [["title", "Title contains"], ["app", "App"], ["contact", "Contact number or email"]];

/** The apps an app rule can name, by their labels (the `source_app` wire values). */
export const RULE_APPS: readonly string[] = [
  "whatsapp", "whatsapp_business", "whatsapp_web", "google_meet", "zoom", "microsoft_teams", "telegram", "signal",
  "messenger", "instagram", "skype", "slack", "discord", "phone_call", "voice_note",
];

/** A filing rule in words: "Title contains “Acme”", "From Zoom", "Contact +92 300 1234567". */
export function ruleText(rule: Pick<FilingRule, "kind" | "value">): string {
  if (rule.kind === "app") return `From ${SOURCE_APP_LABELS[rule.value] ?? rule.value}`;
  if (rule.kind === "contact") return `Contact ${rule.value}`;
  return `Title contains “${rule.value}”`;
}

/**
 * The rule that filed a recording, the way the server files (docs/API.md "Filing rules"): the first title rule its
 * title contains, ignoring capitals, then the rule of its app. "Filed by rule: title contains Acme"; just "Filed by a
 * rule" when none of the project's rules matches any more (it was removed since).
 */
export function filedByRuleText(item: Pick<LibraryItem, "title" | "source_app">, rules: readonly FilingRule[] | null): string {
  const title = (item.title ?? "").toLowerCase();
  const byTitle = rules?.find((r) => r.kind === "title" && r.value.trim() !== "" && title.includes(r.value.trim().toLowerCase()));
  if (byTitle) return `Filed by rule: title contains ${byTitle.value.trim()}`;
  const byApp = rules?.find((r) => r.kind === "app" && r.value === item.source_app);
  if (byApp) return `Filed by rule: from ${SOURCE_APP_LABELS[byApp.value] ?? byApp.value}`;
  return "Filed by a rule";
}

export function requestState(link: Pick<RequestLink, "revoked_at" | "expires_at">, now: number = Date.now()): "Active" | "Revoked" | "Expired" {
  if (link.revoked_at) return "Revoked";
  if (link.expires_at && Date.parse(link.expires_at) <= now) return "Expired";
  return "Active";
}

/** "4 files received", "Nothing received yet". */
export function receivedText(count: number): string {
  if (!count) return "Nothing received yet";
  return `${count} file${count === 1 ? "" : "s"} received`;
}

/** "Up to 20 files of 100 MB" from a request link's limits; empty when it has none. */
export function limitsText(link: Pick<RequestLink, "max_files" | "max_mb">): string {
  const files = link.max_files ? `up to ${link.max_files} file${link.max_files === 1 ? "" : "s"}` : "";
  const size = link.max_mb ? `${files ? "of" : "files of up to"} ${link.max_mb} MB` : "";
  const text = [files, size].filter(Boolean).join(" ");
  return text ? text[0].toUpperCase() + text.slice(1) : "";
}
