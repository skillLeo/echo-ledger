// Sharing and filing rules (docs/API.md "Share links", "Sending to a teammate", "Request links", "Filing rules", since
// v1.5): emails as typed, expiry choices, and the words a link, a request or a rule is shown with. Pure; unit-tested.
// The words are read when they are asked for, in the account's language (src/i18n), or in English when the caller says
// `lang` "en": a window not in the account's language yet (classic's) keeps exactly the English it always had, with no
// Urdu piece in an English sentence. A link's state for a check is its id (linkStatus, requestStatus), never its words.
import type { FilingRule, LibraryItem, RequestLink, RuleKind, ShareLink } from "./ipc";
import type { ConfirmQuestion } from "./confirm";
import { formatWhen, sourceAppLabel } from "./format";
import { choose, fill, t as windowWords, tc as windowCounts, type Lang, type Values } from "../i18n";

/**
 * t and tc in `lang`: the window's language (the default), or "en" whatever the window's is, for a window still in
 * English: the English key itself, its values filled in and never isolated, as t() and tc() give it in English.
 */
function wordsIn(lang?: Lang): { t: (text: string, values?: Values) => string; tc: (text: string, n: number, values?: Values) => string } {
  if (lang !== "en") return { t: windowWords, tc: windowCounts };
  return {
    t: (text, values) => (values ? fill(text, values, false) : text),
    tc: (text, n, values) => fill(choose(text, n, "en"), values?.count === undefined || values.count === null ? { ...values, count: n } : values, false),
  };
}

/** The most people one link or one send may name. */
export const MAX_PEOPLE = 20;

/** Revoke asks first (D-072): a link stops working for everyone who has it, and it can't be turned back on. */
export function revokeLinkQuestion(lang?: Lang): ConfirmQuestion {
  const { t } = wordsIn(lang);
  return { title: t("Revoke this link?"), body: t("It stops working for everyone who has it."), confirm: t("Revoke"), tone: "danger" };
}
/** The same for a request link. */
export function revokeRequestQuestion(lang?: Lang): ConfirmQuestion {
  const { t } = wordsIn(lang);
  return { title: t("Revoke this request link?"), body: t("It stops taking files from everyone who has it."), confirm: t("Revoke"), tone: "danger" };
}

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
/** When a new link stops working, each choice with its words. */
export function expiryChoices(lang?: Lang): readonly (readonly [ExpiryChoice, string])[] {
  const { t } = wordsIn(lang);
  return [
    ["never", t("Never")],
    ["hour", t("In 1 hour")],
    ["day", t("In 1 day")],
    ["week", t("In 7 days")],
    ["month", t("In 30 days")],
    ["date", t("On a date…")],
  ];
}
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
export function audienceText(link: Pick<ShareLink, "audience" | "people" | "has_password">, lang?: Lang): string {
  const { t } = wordsIn(lang);
  if (link.audience === "people") return link.people && link.people.length > 0 ? t("Only :people", { people: link.people.join(", ") }) : t("Only the people chosen");
  if (link.audience === "password" || (!link.audience && link.has_password)) return t("Anyone with the link and the password");
  return t("Anyone with the link");
}

/** A link's or a request link's state (never shown as it is): working, revoked, or past its time. */
export type LinkStatus = "active" | "revoked" | "expired";

export function linkStatus(link: Pick<ShareLink, "active" | "revoked_at">): LinkStatus {
  return link.active ? "active" : link.revoked_at ? "revoked" : "expired";
}

/** A state in words: Active, Revoked, Expired. */
export function statusText(status: LinkStatus, lang?: Lang): string {
  const { t } = wordsIn(lang);
  return status === "active" ? t("Active") : status === "revoked" ? t("Revoked") : t("Expired");
}

/** A link's state in words. */
export function linkState(link: Pick<ShareLink, "active" | "revoked_at">, lang?: Lang): string {
  return statusText(linkStatus(link), lang);
}

/** "3 views · last viewed Oct 30 · 4:05 pm", "Not viewed yet". */
export function viewsText(link: Pick<ShareLink, "view_count" | "last_viewed_at">, lang?: Lang): string {
  const { t, tc } = wordsIn(lang);
  if (!link.view_count) return t("Not viewed yet");
  const views = tc(":count view|:count views", link.view_count);
  return link.last_viewed_at ? t(":views · last viewed :when", { views, when: formatWhen(link.last_viewed_at) }) : views;
}

/** What a link lets people do besides reading: download, or (an older server's link) listen. */
export function downloadText(link: Pick<ShareLink, "allow_download" | "include_audio">, lang?: Lang): string {
  const { t } = wordsIn(lang);
  const download = link.allow_download ?? link.include_audio;
  return download ? t("Downloads allowed") : t("Read and listen only");
}

/** The kinds of filing rule, each with its words. */
export function ruleKinds(lang?: Lang): readonly (readonly [RuleKind, string])[] {
  const { t } = wordsIn(lang);
  return [["title", t("Title contains")], ["app", t("App")], ["contact", t("Contact number or email")]];
}

/** The apps an app rule can name, by their labels (the `source_app` wire values). */
export const RULE_APPS: readonly string[] = [
  "whatsapp", "whatsapp_business", "whatsapp_web", "google_meet", "zoom", "microsoft_teams", "telegram", "signal",
  "messenger", "instagram", "skype", "slack", "discord", "phone_call", "voice_note",
];

/**
 * A filing rule in words: "Title contains “Acme”", "From Zoom", "Contact +92 300 1234567". An app is named in the same
 * language as the line (sourceAppLabel: "Phone call" and "Voice note" are words), so a line is never half one language.
 */
export function ruleText(rule: Pick<FilingRule, "kind" | "value">, lang?: Lang): string {
  const { t } = wordsIn(lang);
  if (rule.kind === "app") return t("From :app", { app: sourceAppLabel(rule.value, lang) });
  if (rule.kind === "contact") return t("Contact :contact", { contact: rule.value });
  return t("Title contains “:text”", { text: rule.value });
}

/**
 * The rule that filed a recording, the way the server files (docs/API.md "Filing rules"): the first title rule its
 * title contains, ignoring capitals, then the rule of its app. "Filed by rule: title contains Acme"; just "Filed by a
 * rule" when none of the project's rules matches any more (it was removed since).
 */
export function filedByRuleText(item: Pick<LibraryItem, "title" | "source_app">, rules: readonly FilingRule[] | null, lang?: Lang): string {
  const { t } = wordsIn(lang);
  const title = (item.title ?? "").toLowerCase();
  const byTitle = rules?.find((r) => r.kind === "title" && r.value.trim() !== "" && title.includes(r.value.trim().toLowerCase()));
  if (byTitle) return t("Filed by rule: title contains :text", { text: byTitle.value.trim() });
  const byApp = rules?.find((r) => r.kind === "app" && r.value === item.source_app);
  if (byApp) return t("Filed by rule: from :app", { app: sourceAppLabel(byApp.value, lang) });
  return t("Filed by a rule");
}

export function requestStatus(link: Pick<RequestLink, "revoked_at" | "expires_at">, now: number = Date.now()): LinkStatus {
  if (link.revoked_at) return "revoked";
  if (link.expires_at && Date.parse(link.expires_at) <= now) return "expired";
  return "active";
}

/** A request link's state in words. */
export function requestState(link: Pick<RequestLink, "revoked_at" | "expires_at">, now: number = Date.now(), lang?: Lang): string {
  return statusText(requestStatus(link, now), lang);
}

/** "4 files received", "Nothing received yet". */
export function receivedText(count: number, lang?: Lang): string {
  const { t, tc } = wordsIn(lang);
  if (!count) return t("Nothing received yet");
  return tc(":count file received|:count files received", count);
}

/** "Up to 20 files of 100 MB" from a request link's limits; empty when it has none. */
export function limitsText(link: Pick<RequestLink, "max_files" | "max_mb">, lang?: Lang): string {
  const { tc } = wordsIn(lang);
  if (link.max_files && link.max_mb) return tc("Up to :count file of :mb MB|Up to :count files of :mb MB", link.max_files, { mb: link.max_mb });
  if (link.max_files) return tc("Up to :count file|Up to :count files", link.max_files);
  if (link.max_mb) return tc("Files of up to :mb MB", link.max_mb, { mb: link.max_mb });
  return "";
}
