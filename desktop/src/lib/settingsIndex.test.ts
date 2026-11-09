// The golden queries of the shared settings search rules, as they come out on the desktop's registry (no phone-only
// rows), plus normalising, folding, the fuzzy pass, pins, availability and the limits.
import { describe, expect, it } from "vitest";
import { distance, firstSentence, fold, normalise, searchSettings } from "./settingsIndex";
import { breadcrumbOf, depthOf, pathOf, setting, SETTINGS } from "./settingsRegistry";

const ids = (q: string, available?: Parameters<typeof searchSettings>[1]) => searchSettings(q, available).settings.map((r) => r.entry.id);
const first = (q: string) => ids(q)[0];
const scoreOf = (q: string, id: string) => searchSettings(q).settings.find((r) => r.entry.id === id)?.score;

describe("normalising", () => {
  it("drops case, accents and punctuation and keeps one space", () => {
    expect(normalise("  Calls & Meetings ")).toBe("calls meetings");
    expect(normalise("Café › Résumé")).toBe("cafe resume");
    expect(normalise("Sign-in & security")).toBe("sign in security");
    expect(normalise("Colour COLOR gray")).toBe("colour colour grey");
  });

  it("folds Arabic letters to their Urdu forms and drops marks", () => {
    expect(normalise("يكه")).toBe("یکہ");
    expect(normalise("الـفَ")).toBe("الف");
  });

  it("folds doubled letters only in words of four letters or more", () => {
    expect(fold("aawaz")).toBe("awaz");
    expect(fold("raseed")).toBe("rased");
    expect(fold("kaal")).toBe("kal");
    expect(fold("all")).toBe("all");
  });

  it("measures typos with transpositions", () => {
    expect(distance("transcrption", "transcription")).toBe(1);
    expect(distance("kaal", "kala")).toBe(1);
    expect(distance("abc", "abc")).toBe(0);
  });
});

describe("the registry", () => {
  it("gives every entry a path, a depth and a breadcrumb", () => {
    expect(pathOf(setting("appearance.theme"))).toEqual(["App", "Appearance"]);
    expect(pathOf(setting("captions.size"))).toEqual(["Recording", "Captions", "Style"]);
    expect(pathOf(setting("captions.style"))).toEqual(["Recording", "Captions", "Style"]);
    expect(pathOf(setting("transcription.language"))).toEqual(["Transcription", "Language"]);
    expect(breadcrumbOf(setting("appearance.theme"))).toBe("App › Appearance");
    expect(breadcrumbOf(setting("appearance"))).toBe("App");
    expect(depthOf(setting("signout"))).toBe(1);
    expect(depthOf(setting("lock.after"))).toBe(2);
    expect(depthOf(setting("captions.size"))).toBe(3);
  });

  it("has unique ids and titles of 31 characters or fewer", () => {
    const seen = new Set<string>();
    for (const e of SETTINGS) {
      expect(seen.has(e.id), e.id).toBe(false);
      seen.add(e.id);
      if (e.kind !== "help") expect(e.title.length, e.id).toBeLessThanOrEqual(31);
      if (e.page) expect(SETTINGS.some((p) => p.id === e.page && p.kind === "page"), `${e.id} is on a page`).toBe(true);
    }
  });

  it("writes headers and titles in sentence case, never in capitals or with selling words", () => {
    for (const e of SETTINGS) {
      if (e.title !== "PIN") expect(e.title, e.id).not.toMatch(/^[A-Z]{3,}\b/);
      expect(e.title, e.id).not.toMatch(/unlock|supercharge|pro tip|popular|!/i);
      if (e.section) expect(e.section.slice(1), e.id).toBe(e.section.slice(1).toLowerCase().replace(/\bi\b/g, "i"));
    }
  });
});

describe("golden queries", () => {
  it("dark mode, dark", () => {
    expect(first("dark mode")).toBe("appearance.theme");
    expect(scoreOf("dark mode", "appearance.theme")).toBe(370);
    expect(first("dark")).toBe("appearance.theme");
    expect(scoreOf("dark", "appearance.theme")).toBe(620);
  });

  it("colour and color: the theme first (wallpaper colours are the phone's), then the caption colours", () => {
    for (const q of ["color", "colour", "rang"]) expect(first(q), q).toBe("appearance.theme");
    expect(ids("colour").slice(1, 3).sort()).toEqual(["captions.client_colour", "captions.me_colour"]);
    expect(scoreOf("colour", "captions.me_colour")).toBe(955);
  });

  it("pro finds Upgrade, then Profile, never anything that only looks like it", () => {
    for (const q of ["pro", "ultra", "premium", "price", "upgrade"]) expect(first(q), q).toBe("plan.upgrade");
    expect(ids("pro")[1]).toBe("profile");
    expect(scoreOf("pro", "profile")).toBe(1025);
  });

  it("plan, package and payment", () => {
    expect(first("package")).toBe("plan");
    expect(first("plan")).toBe("plan");
    expect(ids("payment").slice(0, 3)).toEqual(["plan.payment_details", "plan.history", "plan.receipt"]);
    for (const q of ["bank", "iban", "jazzcash"]) expect(first(q), q).toBe("plan.payment_details");
    expect(scoreOf("bank", "plan.payment_details")).toBe(370);
  });

  it("receipts by Roman Urdu, keyword and title", () => {
    expect(first("raseed")).toBe("plan.receipt");
    expect(first("screenshot payment")).toBe("plan.receipt");
    expect(first("receipt")).toBe("plan.receipt");
    expect(scoreOf("receipt", "plan.receipt")).toBe(770);
  });

  it("promo and discount", () => {
    expect(first("promo")).toBe("plan.promo");
    expect(scoreOf("promo", "plan.promo")).toBe(970);
    expect(first("discount")).toBe("plan.promo");
  });

  it("sign out and logout: the root row before Sign out everywhere", () => {
    expect(ids("sign out").slice(0, 2)).toEqual(["signout", "security.signout_all"]);
    expect([scoreOf("sign out", "signout"), scoreOf("sign out", "security.signout_all")]).toEqual([985, 970]);
    expect(ids("logout").slice(0, 2)).toEqual(["signout", "security.signout_all"]);
  });

  it("delete account, skip, short calls, lock after, lock", () => {
    expect(first("delete account")).toBe("privacy.delete_account");
    expect(first("skip")).toBe("calls.skip_short");
    expect(first("short calls")).toBe("calls.skip_short");
    expect(first("lock after")).toBe("lock.after");
    // The pin puts the page first; without it "Lock now" and "Lock after" (970) would beat the App lock page (825).
    expect(ids("lock").slice(0, 3)).toEqual(["lock", "lock.now", "lock.after"]);
  });

  it("pin, quiet, vibration, bluetooth", () => {
    expect(ids("pin").slice(0, 2)).toEqual(["lock.pin", "lock"]);
    expect(first("quiet")).toBe("notifications.quiet");
    expect(ids("vibration").slice(0, 3).every((id) => id.startsWith("notifications.kind."))).toBe(true);
    expect(first("bluetooth")).toBe("mic.device");
  });

  it("Roman Urdu with and without doubled letters", () => {
    expect(first("zuban")).toBe("transcription.language");
    expect(first("roman urdu")).toBe("transcription.language");
    expect(ids("awaz").slice(0, 2)).toEqual(["mic", "voice"]);
    expect(ids("aawaz").slice(0, 2)).toEqual(["mic", "voice"]);
  });

  it("groq, whatsapp, font size, two-step", () => {
    expect(first("groq")).toBe("key");
    expect(ids("whatsapp").slice(0, 2)).toEqual(["calls.app.whatsapp", "calls.app.whatsapp_web"]);
    expect(first("font size")).toBe("captions.size");
    for (const q of ["2fa", "otp", "two step", "2 step"]) expect(first(q), q).toBe("security.two_step");
  });

  it("transcription, and a typo of it", () => {
    expect(ids("transcription").slice(0, 2)).toEqual(["key", "transcription.language"]);
    expect([scoreOf("transcription", "key"), scoreOf("transcription", "transcription.language")]).toEqual([1025, 985]);
    expect(ids("transcrption").slice(0, 2)).toEqual(["key", "transcription.language"]);
    expect(scoreOf("transcrption", "key")).toBe(725);
  });

  it("kaal finds nothing, and Theme is not among the closest matches", () => {
    const answer = searchSettings("kaal");
    expect(answer.settings).toEqual([]);
    expect(answer.closest.map((e) => e.id)).not.toContain("appearance.theme");
    expect(answer.closest.map((e) => e.id)).toContain("calls");
  });

  it("values are never searched, and nonsense has no closest matches", () => {
    expect(ids("on")).not.toContain("calls.auto");
    expect(ids("on")).not.toContain("captions.enabled");
    const answer = searchSettings("xyzzy");
    expect(answer.settings).toEqual([]);
    expect(answer.closest).toEqual([]);
  });
});

describe("availability and limits", () => {
  it("leaves out what this computer or account does not have, and pins fall away with them", () => {
    const noPlans = (e: { requires?: { plans?: boolean } }) => !e.requires?.plans;
    expect(ids("pro", noPlans)[0]).toBe("profile");
    expect(ids("payment", noPlans)).not.toContain("plan.payment_details");
    const windows = (e: { requires?: { os?: string } }) => e.requires?.os !== "macos";
    expect(ids("browser tab", windows)).not.toContain("calls.browser_tabs");
  });

  it("starts at two characters and answers help questions apart", () => {
    expect(searchSettings("a").settings).toEqual([]);
    const help = searchSettings("dark mode").help.map((r) => r.entry.id);
    expect(help).toContain("help.article.dark_mode");
    expect(searchSettings("dark mode").settings.map((r) => r.entry.id)).not.toContain("help.article.dark_mode");
    expect(searchSettings("minutes").settings.length).toBeLessThanOrEqual(30);
  });

  it("reads the first sentence of an answer", () => {
    expect(firstSentence("Go to Settings. Then choose Dark.")).toBe("Go to Settings.");
    expect(firstSentence("No full stop")).toBe("No full stop");
  });

  it("marks the title words that matched, for the bold part of the row", () => {
    expect(searchSettings("lock aft").settings[0].matched).toEqual(["lock", "aft"]);
  });
});
