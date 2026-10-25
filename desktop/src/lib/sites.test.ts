import { describe, expect, it } from "vitest";
import {
  BUILTIN_SITE_RULES,
  NEVER,
  NEVER_PRIORITY,
  SITE_RULE_APPS,
  USER_CALL_PRIORITY,
  builtinEnabled,
  customRules,
  hasSameRule,
  hostOf,
  ruleFromInput,
  rulePattern,
  uuidV4,
  withBuiltinEnabled,
} from "./sites";
import { SOURCE_APP_LABELS } from "./format";
import type { SiteRule } from "./ipc";
import { makeSiteRule } from "../test/fixtures";
import sitesRs from "../../src-tauri/src/detect/sites.rs?raw";

/** `builtin("meet", "google_meet", "meet.google.com", Some("Meet - "), Some("Google Meet"), None, 30)` lines of sites.rs. */
function rustBuiltins(): SiteRule[] {
  const start = sitesRs.indexOf("pub fn builtin_rules()");
  const body = sitesRs.slice(start, sitesRs.indexOf("\n}\n", start));
  return [...body.matchAll(/^\s*builtin\((.*)\),?\s*$/gm)].map((line) => {
    // Arguments in order: "id", "app" | NEVER, "host", Option x3, priority.
    const args = [...line[1].matchAll(/Some\("([^"]*)"\)|None|NEVER|"([^"]*)"|(\d+)/g)].map((t) => {
      if (t[0] === "None") return null;
      if (t[0] === "NEVER") return NEVER;
      return t[1] ?? t[2] ?? t[3];
    });
    const [id, app, host, titlePrefix, titleContains, titleEquals, priority] = args;
    return { id: `builtin.${id}`, app: app!, host, titlePrefix, titleContains, titleEquals, priority: Number(priority), enabled: true };
  });
}

describe("built-in rules", () => {
  it("match the table the core ships, in the same order", () => {
    const rust = rustBuiltins();
    expect(rust).toHaveLength(8);
    expect(BUILTIN_SITE_RULES).toEqual(rust);
  });

  it("name only call apps the UI can label, or never", () => {
    for (const rule of BUILTIN_SITE_RULES) {
      expect(rule.app === NEVER || rule.app in SOURCE_APP_LABELS, rule.id).toBe(true);
    }
  });
});

describe("switching a built-in", () => {
  const meet = BUILTIN_SITE_RULES[0];

  it("is on without an override and follows an override with its id", () => {
    expect(builtinEnabled(meet, [])).toBe(true);
    expect(builtinEnabled(meet, [{ ...meet, enabled: false }])).toBe(false);
    expect(builtinEnabled(meet, [{ ...meet, enabled: true }])).toBe(true);
  });

  it("off adds a disabled copy under the built-in id and keeps the other rules", () => {
    const mine = makeSiteRule();
    expect(withBuiltinEnabled([mine], meet, false)).toEqual([mine, { ...meet, enabled: false }]);
  });

  it("on removes the override (the built-in applies again) and never duplicates it", () => {
    const mine = makeSiteRule();
    const off = withBuiltinEnabled([mine], meet, false);
    expect(withBuiltinEnabled(off, meet, true)).toEqual([mine]);
    expect(withBuiltinEnabled(withBuiltinEnabled(off, meet, false), meet, false).filter((r) => r.id === meet.id)).toHaveLength(1);
  });

  it("customRules leaves out the overrides", () => {
    const mine = makeSiteRule();
    expect(customRules([mine, { ...meet, enabled: false }])).toEqual([mine]);
  });
});

describe("hostOf", () => {
  it.each([
    ["meet.example.test", "meet.example.test"],
    ["https://Meet.Example.test/abc-defg?x=1", "meet.example.test"],
    ["http://user@call.example.test:8443/room", "call.example.test"],
    ["call.example.test.", "call.example.test"],
    ["Jitsi Meet", null],
    ["localhost", null],
    ["", null],
    ["  ", null],
  ])("%s → %s", (text, host) => {
    expect(hostOf(text)).toBe(host);
  });
});

describe("ruleFromInput", () => {
  it("turns an address into a host rule for a call app, above the built-in priorities", () => {
    expect(ruleFromInput("other", " https://call.example.test/r ", "id-1")).toEqual({
      id: "id-1", app: "other", host: "call.example.test", titlePrefix: null, titleContains: null, titleEquals: null, priority: USER_CALL_PRIORITY, enabled: true,
    });
    expect(USER_CALL_PRIORITY).toBeGreaterThan(Math.max(...BUILTIN_SITE_RULES.filter((r) => r.app !== NEVER).map((r) => r.priority)));
  });

  it("turns other text into a title rule, and never-record rules get priority 100", () => {
    expect(ruleFromInput(NEVER, "Voice typing", "id-2")).toEqual({
      id: "id-2", app: NEVER, host: null, titlePrefix: null, titleContains: "Voice typing", titleEquals: null, priority: NEVER_PRIORITY, enabled: true,
    });
    expect(NEVER_PRIORITY).toBe(100);
  });

  it("returns null for empty input and generates a v4 id when none is given", () => {
    expect(ruleFromInput("zoom", "   ")).toBeNull();
    const rule = ruleFromInput("zoom", "zoom.example.test")!;
    expect(rule.id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
  });
});

describe("rulePattern and hasSameRule", () => {
  it("shows the address, else the title text", () => {
    expect(rulePattern(BUILTIN_SITE_RULES[0])).toBe("meet.google.com");
    expect(rulePattern(makeSiteRule({ host: null, titleContains: "Standup" }))).toBe("Standup");
    expect(rulePattern(makeSiteRule({ host: null, titleEquals: "Recorder.exe", app: NEVER }))).toBe("Recorder.exe");
    expect(rulePattern(makeSiteRule({ host: null, titlePrefix: "Call - " }))).toBe("Call - ");
  });

  it("finds a duplicate by app and pattern, ignoring case and built-in overrides", () => {
    const rules = [makeSiteRule({ app: "zoom", host: "zoom.example.test" }), { ...BUILTIN_SITE_RULES[0], enabled: false }];
    expect(hasSameRule(rules, ruleFromInput("zoom", "ZOOM.example.test")!)).toBe(true);
    expect(hasSameRule(rules, ruleFromInput("google_meet", "zoom.example.test")!)).toBe(false);
    expect(hasSameRule(rules, ruleFromInput("google_meet", "meet.google.com")!)).toBe(false);
  });
});

describe("uuidV4 and the app choices", () => {
  it("produces distinct RFC 4122 v4 ids", () => {
    const ids = new Set(Array.from({ length: 50 }, uuidV4));
    expect(ids.size).toBe(50);
    for (const id of ids) expect(id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
  });

  it("offers the web call apps, Other and Never record, with the shared labels", () => {
    expect(SITE_RULE_APPS.map(([v]) => v)).toEqual(["google_meet", "zoom", "microsoft_teams", "whatsapp_web", "other", NEVER]);
    for (const [value, label] of SITE_RULE_APPS) if (value !== NEVER) expect(label).toBe(SOURCE_APP_LABELS[value]);
  });
});
