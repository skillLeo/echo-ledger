// Desktop Urdu, package D3c: the words Settings shows that classic's and lib's helpers build (the call choice, why a
// call wasn't recorded, the plan's limits and usage, updates, the app's keys, screen-share privacy and its check, screen
// moments' notes, the microphone test, the account rows, sign-in activity) in the window's language. English is exactly
// as before (the helpers' own tests are unchanged); each sentence is one key, its values isolated in Urdu.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { flushAsync, resetTauriMock, respond } from "../test/tauri";
import { makeMicUser, makeSettings, makeStatus, sampleEntitlements } from "../test/fixtures";
import { closeUpgradeSheet, openUpgradeSheet, resetEntitlementsForTests, priceText } from "../lib/entitlements";
import { AFTER_EACH_CALL_OPTIONS, alwaysOffer, groupedAudio } from "../lib/callChoice";
import { decisionText, lastNotRecordedLine } from "../lib/explainer";
import { checkedWhen, nameList, resultLine } from "../lib/privacy";
import { limitText, usageText } from "../views/main/Upgrade";
import { updateLine } from "../views/main/UpdateSettings";
import { ROLE_LABEL } from "../lib/plan";
import * as PrivacySettings from "../views/main/PrivacySettings";
import { CUSTOM_RANGE } from "../components/ShareVisibility";
import { MONTHLY_NOTE, WINDOWS_WHOLE_SCREEN } from "../views/main/ScreenSettings";
import { NOT_SHOWN } from "../views/main/SecuritySettings";
import { CAPTIONS_SHARE_LINE } from "../views/main/CaptureSettings";
import { BADGE_LABEL } from "../lib/privacy";
import { mainShareValue } from "../components/ShareVisibility";
import { clockTime } from "../lib/format";
import { navKeys, windowKeys } from "../views/main/HelpDialog";
import { appKeys } from "../views/main/AppSettings";
import { UpgradeSheet } from "../views/main/UpgradeFlow";
import { SettingsPagesV2 } from "../v2/settings/pages/SettingsPagesV2";
import { textsIn } from "./rawText";
import ur from "./ur.json";
import { FSI, PDI, resetLanguageForTests, setLanguage } from "./index";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

const src = join(dirname(fileURLToPath(import.meta.url)), "..");
const urdu = () => act(() => setLanguage("ur", { root: false }));
const iso = (value: string | number) => `${FSI}${value}${PDI}`;
const NOW = new Date(2026, 10, 26, 15, 0);

/** The files whose words D3c gives to t() and tc(). */
const FILES = [
  "lib/callChoice.ts", "lib/explainer.ts", "lib/zoom.ts", "lib/privacy.ts", "lib/entitlements.ts", "components/ShareVisibility.tsx",
  "views/main/UpdateSettings.tsx", "views/main/AppSettings.tsx", "views/main/HelpDialog.tsx", "views/main/PrivacySettings.tsx", "views/main/ScreenSettings.tsx",
  "views/main/CaptureSettings.tsx", "views/main/SecuritySettings.tsx", "views/main/Upgrade.tsx", "views/main/AccountFlows.tsx", "views/main/MacCheck.tsx",
  "views/main/AccountSettings.tsx", "v2/settings/pages/CallsV2.tsx",
];
/** The English constants Settings says in the window's language where it shows them (t(CONSTANT)). */
const SHOWN_CONSTANTS = [
  PrivacySettings.SHARE_PRIVACY_LEAD, PrivacySettings.SHARE_PRIVACY_MAIN, ...Object.values(PrivacySettings.HONEST_LINE), PrivacySettings.ALWAYS_SHOWN_VALUE,
  ...Object.values(PrivacySettings.ALWAYS_SHOWN).map((r) => r.subtitle), ...Object.values(PrivacySettings.LIVE_TRANSLATE_SUBTITLE), PrivacySettings.ZOOM_SETTING,
  PrivacySettings.HIDDEN_WHILE_SHARING, PrivacySettings.ONE_WINDOW_TIP, PrivacySettings.LIMITS, PrivacySettings.GUIDED_ALONE, ...Object.values(PrivacySettings.LINE_FIX_LABEL),
  PrivacySettings.OPEN_SYSTEM_SETTINGS, MONTHLY_NOTE, WINDOWS_WHOLE_SCREEN.trim(), CUSTOM_RANGE, NOT_SHOWN, CAPTIONS_SHARE_LINE, ...Object.values(ROLE_LABEL),
  ...Object.values(BADGE_LABEL), ...navKeys("windows").map(([, what]) => what),
];

beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
});
afterEach(() => resetLanguageForTests());

describe("the catalogue has D3c's words", () => {
  it("every text those helpers give to t() or tc(), and every constant Settings says where it shows it", () => {
    const keys = new Set<string>(SHOWN_CONSTANTS);
    for (const file of FILES) {
      const path = join(src, file);
      for (const found of textsIn(path, readFileSync(path, "utf8"))) if (found.translated) keys.add(found.text);
    }
    expect(keys.size).toBeGreaterThan(200);
    expect([...keys].filter((key) => !(key in ur))).toEqual([]);
  });
});

describe("D3c's words, in English as before and in Urdu", () => {
  it("why a call wasn't recorded: one sentence each, the app and the time as values", async () => {
    const last = { user: makeMicUser({ name: "Slack.exe", app: null, site: null, decision: "ignored", reason: "unknown_app" }), at: new Date(2026, 10, 26, 9, 40).toISOString() };
    expect(decisionText(last.user)).toBe("Not recorded: it isn't a call app EchoLedger knows");
    expect(lastNotRecordedLine(last, NOW)).toBe("Last not recorded: Slack at 9:40 am, because it isn't a call app EchoLedger knows.");
    expect(lastNotRecordedLine({ ...last, user: { ...last.user, reason: null } }, NOW)).toBe("Last not recorded: Slack at 9:40 am.");
    await urdu();
    expect(decisionText(last.user)).toBe("ریکارڈ نہیں ہوتی: یہ کوئی ایسی کال ایپ نہیں جسے EchoLedger جانتا ہو");
    // The time as every date line has it in Urdu (whenParts: kept whole with a no-break space).
    expect(lastNotRecordedLine(last, NOW)).toBe(`آخری بار ریکارڈ نہیں ہوئی: ${iso("Slack")} ${iso(`${iso(clockTime(new Date(2026, 10, 26, 9, 40)).replace(/\s/g, "\u00A0"))} پر`)}، کیونکہ ${iso("یہ کوئی ایسی کال ایپ نہیں جسے EchoLedger جانتا ہو")}۔`);
  });

  it("the plan's limits and usage: counts agree with their numbers in Urdu; English as it always was", async () => {
    expect([limitText("retention_days", 365), limitText("retention_days", 30), limitText("max_file_minutes", 60), limitText("max_file_minutes", 240), limitText("seats", 10), limitText("seats", 1)])
      .toEqual(["1 year", "30 days", "1 hour", "4 hours", "Up to 10 people", "Just you"]);
    expect(usageText("monthly_minutes", { limit: 600, used: 230 })).toBe("230 of 600 min");
    expect(groupedAudio(1)).toBe("1 calls saved as audio");
    await urdu();
    expect(limitText("retention_days", 365)).toBe(`${iso(1)} سال`);
    expect(limitText("max_file_minutes", 60)).toBe(`${iso(1)} گھنٹہ`);
    expect(limitText("max_file_minutes", 240)).toBe(`${iso(4)} گھنٹے`);
    expect(usageText("monthly_minutes", { limit: 600, used: 230 })).toBe(`${iso(600)} میں سے ${iso(230)} منٹ`);
    expect(groupedAudio(1)).toBe(`${iso(1)} کال کی آڈیو محفوظ ہو گئی`);
  });

  it("the call choice: its options read the window's language each time; an offer is one sentence", async () => {
    expect(AFTER_EACH_CALL_OPTIONS.map((o) => o.label)).toEqual(["Ask me", "Transcribe every call", "Save audio only", "Don't record calls"]);
    expect(alwaysOffer("always_transcribe")).toBe("Always do this? Transcribe every call");
    await urdu();
    expect(AFTER_EACH_CALL_OPTIONS.map((o) => o.value)).toEqual(["ask", "always_transcribe", "always_audio", "off"]);
    expect(AFTER_EACH_CALL_OPTIONS[0].label).toBe("مجھ سے پوچھیں");
    expect(alwaysOffer("always_transcribe")).toBe(`ہمیشہ یہی کریں؟ ${iso("ہر کال ٹرانسکرائب کریں")}`);
  });

  it("screen-share privacy: the check's line, when it was checked, a list of apps; updates; a price from its amount", async () => {
    const at = new Date(2026, 10, 12, 10).toISOString();
    expect(resultLine("pass", null, "Windows 11", at, "windows", NOW).text).toBe("Hidden. People who see your whole screen won't see EchoLedger. Checked on Windows 11, on 12 Nov.");
    expect(nameList(["Zoom", "Teams", "Slack"])).toBe("Zoom, Teams and Slack");
    expect(updateLine({ phase: "downloading", version: "2.1.0", progress: 45 } as never)).toBe("Downloading EchoLedger 2.1.0 · 45%");
    const pro = sampleEntitlements("pro").plans[1];
    expect(priceText(pro)).toBe("Rs 2,999 a month");
    await urdu();
    expect(checkedWhen(at, NOW)).toBe(`${iso("12 نومبر")} کو`);
    // Each name in its own isolate, the run right to left (an RLM first), so they read in order from the right.
    expect(nameList(["Zoom", "Teams", "Slack"])).toBe(`${iso(`\u200F${iso("Zoom")}، ${iso("Teams")}`)} اور ${iso("Slack")}`);
    expect(updateLine({ phase: "downloading", version: "2.1.0", progress: 45 } as never)).toBe(`EchoLedger ${iso("2.1.0")} ڈاؤن لوڈ ہو رہا ہے · ${iso(45)}%`);
    // priceText stays the server's English (classic's upgrade sheet); Plan & usage says its own (planPrice).
    expect(priceText(pro)).toBe("Rs 2,999 a month");
  });

  it("the main window hidden: in English the core's words; in Urdu one sentence for each kind of until", async () => {
    const at = (h: number, m: number, days = 0) => new Date(2026, 10, 26 + days, h, m).getTime();
    const hide = (until: object, words: string) => ({ mainHide: { until, sinceMs: 0 } as never, mainHiddenUntil: words });
    expect(mainShareValue(hide({ kind: "until_off" }, "until you show it"), NOW)).toBe("Hidden until you show it");
    expect(mainShareValue(hide({ kind: "at", at_ms: at(16, 30) }, "until 16:30"), NOW)).toBe("Hidden until 16:30");
    await urdu();
    expect(mainShareValue(hide({ kind: "until_off" }, "until you show it"), NOW)).toBe("جب تک آپ اسے دکھائیں نہیں، چھپا ہوا");
    expect(mainShareValue(hide({ kind: "until_quit" }, "until you quit EchoLedger"), NOW)).toBe("EchoLedger بند کرنے تک چھپا ہوا");
    expect(mainShareValue(hide({ kind: "call_ends" }, "until this call ends"), NOW)).toBe("اس کال کے ختم ہونے تک چھپا ہوا");
    const time = (h: number, m: number, days = 0) => clockTime(new Date(at(h, m, days))).replace(/\s/g, "\u00A0");
    expect(mainShareValue(hide({ kind: "at", at_ms: at(16, 30) }, "until 16:30"), NOW)).toBe(`${iso(time(16, 30))} تک چھپا ہوا`);
    expect(mainShareValue(hide({ kind: "at", at_ms: at(8, 0, 1) }, "until tomorrow 08:00"), NOW)).toBe(`کل ${iso(time(8, 0, 1))} تک چھپا ہوا`);
    expect(mainShareValue(hide({ kind: "at", at_ms: at(14, 30, 3) }, "until Sat 29 14:30"), NOW)).toBe(`${iso("29 نومبر")} کو ${iso(time(14, 30, 3))} تک چھپا ہوا`);
  });

  it("classic's lists stay English in an Urdu window: Help's keys and the upgrade sheet's plans; Settings' own are Urdu", async () => {
    await urdu();
    const help = windowKeys("windows", true).map(([, what]) => what);
    expect(help).toContain("Hide or show the panel");
    expect(help.filter((what) => /[\u0600-\u06FF]/.test(what))).toEqual([]);
    expect(appKeys("windows", true).map(([, what]) => what)).toContain("پینل چھپائیں یا دکھائیں");
    resetEntitlementsForTests(sampleEntitlements("free"));
    respond("upgrade_request_latest", null);
    render(<UpgradeSheet />);
    act(() => openUpgradeSheet(null, {}));
    expect(screen.getByRole("radio", { name: /^Pro, Rs 2,999 a month/ })).toBeInTheDocument();
    act(() => closeUpgradeSheet());
  });
});

describe("Settings in Urdu", () => {
  it("D-232: each app's line under Why a call wasn't recorded reads right to left, its name as a value", async () => {
    respond("get_settings", makeSettings());
    respond("mic_users", [makeMicUser({ name: "Krisp.exe", app: null, site: null, decision: "ignored", reason: "unknown_app" })]);
    respond("mic_last_not_recorded", null);
    resetEntitlementsForTests(sampleEntitlements("free"));
    await urdu();
    render(<div className="el-root"><SettingsPagesV2 section={{ id: "calls", seq: 1 }} onHome={vi.fn()} run={(fn) => fn().catch(() => undefined)} status={makeStatus()} onUnpaired={vi.fn()} /></div>);
    await act(flushAsync);
    await act(flushAsync);
    const line = within(screen.getByTestId("why-not-recorded")).getByText("Krisp").closest("li")!;
    expect(line).toHaveAttribute("dir", "rtl");
    expect(line).toHaveTextContent(`Krisp: ${iso("ریکارڈ نہیں ہوتی: یہ کوئی ایسی کال ایپ نہیں جسے EchoLedger جانتا ہو")}`);
  });

  it("Plan & usage: a meter's value reads in its words' direction, the price in Urdu, a highlight in its own", async () => {
    const e = sampleEntitlements("pro");
    resetEntitlementsForTests(e);
    respond("get_settings", makeSettings());
    respond("account_get", null);
    respond("upgrade_request_latest", null);
    render(<div className="el-root"><SettingsPagesV2 section={{ id: "plan", seq: 1 }} onHome={vi.fn()} run={(fn) => fn().catch(() => undefined)} status={makeStatus()} onUnpaired={vi.fn()} /></div>);
    await act(flushAsync);
    expect(screen.getByTestId("meter-monthly_minutes").querySelector(".el-meter-row__value")).toHaveAttribute("dir", "ltr");
    cleanup();
    await urdu();
    render(<div className="el-root"><SettingsPagesV2 section={{ id: "plan", seq: 1 }} onHome={vi.fn()} run={(fn) => fn().catch(() => undefined)} status={makeStatus()} onUnpaired={vi.fn()} /></div>);
    await act(flushAsync);
    const value = screen.getByTestId("meter-monthly_minutes").querySelector(".el-meter-row__value")!;
    expect(value).toHaveAttribute("dir", "rtl");
    expect(value).toHaveTextContent(`${iso(600)} میں سے ${iso(230)} منٹ`);
    // Said in full, the reset day without a stray comma ("1 دسمبر"): the year isn't cut from an Urdu date by an English rule.
    const spoken = within(screen.getByTestId("meter-monthly_minutes")).getByRole("meter").getAttribute("aria-label")!;
    expect(spoken).toContain(`${iso(`${iso(600)} میں سے ${iso(230)} منٹ`)} استعمال ہوا`);
    expect(spoken).toContain(iso(new Date(2026, 11, 1).toLocaleDateString("ur-PK", { day: "numeric", month: "long" })));
    const ultra = screen.getByTestId("plan-ultra");
    expect(ultra).toHaveTextContent(`Rs ${iso("12,499")} ماہانہ`);
    // The server's highlights stay its English, each left to right.
    expect(within(ultra).getByText("A team of up to 10 people")).toHaveAttribute("dir", "ltr");
  });
});
