// Call-site rules as Settings edits them (A1.4). The core ships the built-in rules and evaluates everything;
// `Settings.siteRules` holds only the user's rules, and a user rule with a built-in's id replaces that built-in,
// which is how a built-in is switched off.
import type { SiteRule } from "./ipc";

/** `app` of a never-record rule. */
export const NEVER = "never";
/** Never-record rules always use 100 (the core checks them before any call rule anyway). */
export const NEVER_PRIORITY = 100;
/** A site the user adds outranks the built-in call rules (30, WhatsApp Web 10), so their choice wins a tie of titles. */
export const USER_CALL_PRIORITY = 50;

const builtin = (
  id: string,
  app: string,
  host: string,
  titlePrefix: string | null,
  titleContains: string | null,
  titleEquals: string | null,
  priority: number,
): SiteRule => ({ id: `builtin.${id}`, app, host, titlePrefix, titleContains, titleEquals, priority, enabled: true });

/** Same table, same order as `builtin_rules()` in src-tauri/src/detect/sites.rs (sites.test.ts compares them). */
export const BUILTIN_SITE_RULES: readonly SiteRule[] = [
  builtin("meet", "google_meet", "meet.google.com", "Meet - ", "Google Meet", null, 30),
  builtin("zoom", "zoom", "zoom.us", null, "Zoom Meeting", "Zoom", 30),
  builtin("teams", "microsoft_teams", "teams.microsoft.com", null, "Microsoft Teams", null, 30),
  builtin("teams_live", "microsoft_teams", "teams.live.com", null, null, null, 30),
  builtin("whatsapp_web", "whatsapp_web", "web.whatsapp.com", null, "WhatsApp", null, 10),
  builtin("never_chatgpt", NEVER, "chatgpt.com", null, "ChatGPT", null, 100),
  builtin("never_claude", NEVER, "claude.ai", null, null, null, 100),
  builtin("never_gemini", NEVER, "gemini.google.com", null, null, null, 100),
];

/** Apps a user-added site can be recorded as, then the never-record choice (Settings → Call sites → App). */
export const SITE_RULE_APPS: readonly (readonly [string, string])[] = [
  ["google_meet", "Google Meet"],
  ["zoom", "Zoom"],
  ["microsoft_teams", "Microsoft Teams"],
  ["whatsapp_web", "WhatsApp Web"],
  ["other", "Other"],
  [NEVER, "Never record"],
];

export const isNeverRule = (rule: Pick<SiteRule, "app">): boolean => rule.app === NEVER;
export const isBuiltinId = (id: string): boolean => id.startsWith("builtin.");

/** Whether a built-in applies, given the user's rules (a user rule with its id replaces it). */
export function builtinEnabled(rule: SiteRule, userRules: readonly SiteRule[]): boolean {
  const override = userRules.find((r) => r.id === rule.id);
  return override ? override.enabled : rule.enabled;
}

/** The user rules after switching a built-in on (no override left) or off (a disabled copy under its id). */
export function withBuiltinEnabled(userRules: readonly SiteRule[], rule: SiteRule, enabled: boolean): SiteRule[] {
  const rest = userRules.filter((r) => r.id !== rule.id);
  return enabled ? rest : [...rest, { ...rule, enabled: false }];
}

/** The rules the user added (not the overrides that switch a built-in off). */
export function customRules(userRules: readonly SiteRule[]): SiteRule[] {
  return userRules.filter((r) => !isBuiltinId(r.id));
}

/** What a rule matches, for display: its address, else its title text (an app ignored from the chip is a name). */
export function rulePattern(rule: SiteRule): string {
  return rule.host || rule.titleContains || rule.titleEquals || rule.titlePrefix || "";
}

/** `https://Meet.Example.test:443/room` → `meet.example.test`; null when the text is not an address. */
export function hostOf(text: string): string | null {
  const value = text.trim().toLowerCase();
  if (!value || /\s/.test(value)) return null;
  const rest = value.replace(/^[a-z][a-z0-9+.-]*:\/\//, "");
  const authority = rest.split(/[/?#]/)[0].replace(/^[^@]*@/, "").replace(/:\d+$/, "").replace(/\.$/, "");
  return /^[a-z0-9-]+(\.[a-z0-9-]+)+$/.test(authority) ? authority : null;
}

/** RFC 4122 version 4 id from `crypto.getRandomValues` (available in every webview, secure context or not). */
export function uuidV4(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = [...bytes].map((b) => b.toString(16).padStart(2, "0")).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * A user rule from the Settings form: an address is matched as a host (tab URLs on macOS, and anywhere in a window
 * title), any other text as part of the window title. Null for empty input.
 */
export function ruleFromInput(app: string, text: string, id: string = uuidV4()): SiteRule | null {
  const value = text.trim();
  if (!value) return null;
  const host = hostOf(value);
  return {
    id,
    app,
    host,
    titlePrefix: null,
    titleContains: host ? null : value,
    titleEquals: null,
    priority: app === NEVER ? NEVER_PRIORITY : USER_CALL_PRIORITY,
    enabled: true,
  };
}

/** True when the user already has a rule for the same app and the same address or title text. */
export function hasSameRule(userRules: readonly SiteRule[], rule: SiteRule): boolean {
  const key = rulePattern(rule).toLowerCase();
  return customRules(userRules).some((r) => r.app === rule.app && rulePattern(r).toLowerCase() === key);
}
