import { describe, expect, it } from "vitest";
import { audienceText, downloadText, expiryTime, filedByRuleText, limitsText, linkState, looksLikeEmail, parseEmails, receivedText, requestState, ruleText, viewsText } from "./sharing";

describe("emails as typed", () => {
  it("splits at commas, semicolons, spaces and new lines, without repeats", () => {
    expect(parseEmails("ana@example.com, bo@x.org;\nANA@example.com  <cy@y.io>")).toEqual({ emails: ["ana@example.com", "bo@x.org", "cy@y.io"], invalid: [] });
    expect(parseEmails("ana@example, bo")).toEqual({ emails: [], invalid: ["ana@example", "bo"] });
    expect(parseEmails("   ")).toEqual({ emails: [], invalid: [] });
  });

  it("are checked the way the core checks them", () => {
    expect(looksLikeEmail("ana@example.com")).toBe(true);
    for (const bad of ["ana", "@example.com", "ana@@example.com", "ana@.com", "ana@example.", "a b@x.org"]) expect(looksLikeEmail(bad), bad).toBe(false);
  });
});

describe("expiry", () => {
  const now = new Date("2026-11-04T10:00:00Z");

  it("adds the chosen time, or ends the picked day, or never", () => {
    expect(expiryTime("hour", now)).toBe("2026-11-04T11:00:00.000Z");
    expect(expiryTime("week", now)).toBe("2026-11-11T10:00:00.000Z");
    expect(expiryTime("month", now)).toBe("2026-12-04T10:00:00.000Z");
    expect(expiryTime("never", now)).toBeNull();
    expect(expiryTime("date", now, "")).toBeNull();
    expect(expiryTime("date", now, "2026-12-01")).toBe(new Date(2026, 11, 1, 23, 59, 59).toISOString());
  });
});

describe("a link's row", () => {
  it("says who may open it", () => {
    expect(audienceText({ audience: "public", has_password: false })).toBe("Anyone with the link");
    expect(audienceText({ audience: "password", has_password: true })).toBe("Anyone with the link and the password");
    expect(audienceText({ has_password: true })).toBe("Anyone with the link and the password");
    expect(audienceText({ audience: "people", people: ["ana@example.com", "bo@x.org"], has_password: false })).toBe("Only ana@example.com, bo@x.org");
  });

  it("says its state, views and downloads", () => {
    expect(linkState({ active: true, revoked_at: null })).toBe("Active");
    expect(linkState({ active: false, revoked_at: "2026-11-01T00:00:00Z" })).toBe("Revoked");
    expect(linkState({ active: false, revoked_at: null })).toBe("Expired");
    expect(viewsText({ view_count: 0, last_viewed_at: null })).toBe("Not viewed yet");
    expect(viewsText({ view_count: 1, last_viewed_at: null })).toBe("1 view");
    expect(viewsText({ view_count: 3, last_viewed_at: "2026-10-30T11:00:00Z" })).toMatch(/^3 views · last viewed /);
    expect(downloadText({ allow_download: true, include_audio: false })).toBe("Downloads allowed");
    expect(downloadText({ include_audio: true })).toBe("Downloads allowed");
    expect(downloadText({ allow_download: false, include_audio: true })).toBe("Read and listen only");
  });
});

describe("filing rules in words", () => {
  it("names each kind", () => {
    expect(ruleText({ kind: "title", value: "Acme" })).toBe("Title contains “Acme”");
    expect(ruleText({ kind: "app", value: "microsoft_teams" })).toBe("From Microsoft Teams");
    expect(ruleText({ kind: "contact", value: "ana@example.com" })).toBe("Contact ana@example.com");
  });

  it("finds the rule that filed a recording the way the server files", () => {
    const rules = [{ id: 1, kind: "app" as const, value: "zoom" }, { id: 2, kind: "title" as const, value: "Acme" }];
    expect(filedByRuleText({ title: "Weekly ACME sync", source_app: "zoom" }, rules)).toBe("Filed by rule: title contains Acme");
    expect(filedByRuleText({ title: "Standup", source_app: "zoom" }, rules)).toBe("Filed by rule: from Zoom");
    expect(filedByRuleText({ title: "Standup", source_app: "whatsapp" }, rules)).toBe("Filed by a rule");
    expect(filedByRuleText({ title: "Standup", source_app: "zoom" }, null)).toBe("Filed by a rule");
  });
});

describe("request links in words", () => {
  const now = Date.parse("2026-11-04T10:00:00Z");

  it("say their state, what came in and their limits", () => {
    expect(requestState({ revoked_at: null, expires_at: null }, now)).toBe("Active");
    expect(requestState({ revoked_at: "2026-11-01T00:00:00Z", expires_at: null }, now)).toBe("Revoked");
    expect(requestState({ revoked_at: null, expires_at: "2026-11-03T00:00:00Z" }, now)).toBe("Expired");
    expect(receivedText(0)).toBe("Nothing received yet");
    expect(receivedText(1)).toBe("1 file received");
    expect(receivedText(4)).toBe("4 files received");
    expect(limitsText({ max_files: 20, max_mb: 100 })).toBe("Up to 20 files of 100 MB");
    expect(limitsText({ max_files: 1, max_mb: null })).toBe("Up to 1 file");
    expect(limitsText({ max_files: null, max_mb: 50 })).toBe("Files of up to 50 MB");
    expect(limitsText({ max_files: null, max_mb: null })).toBe("");
  });
});
