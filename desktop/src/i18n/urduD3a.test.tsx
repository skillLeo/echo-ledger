// Desktop Urdu, package D3a: Settings' own words (its pages, their rows and frame, the home with the account card, the
// groups where they are shown, the Settings panel) in Urdu from the catalogue (src/i18n/ur.json), each value in its own
// isolate; the same words in English as before (Settings' own tests are in English, unchanged). Plan names stay as
// written (LEAD 23). The registry's words (titles, descriptions, options) are D3b's.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import type { SecurityDevice, SettingsPatch } from "../lib/ipc";
import { flushAsync, resetTauriMock, respond, respondWith } from "../test/tauri";
import { makeMicUser, makeSettings, makeStatus, sampleEntitlements } from "../test/fixtures";
import { resetEntitlementsForTests } from "../lib/entitlements";
import { formatWhen } from "../lib/format";
import { GOAL_GROUPS } from "../v2/settings/groups";
import { minutesLine } from "../v2/settings/AccountCard";
import { SettingsV2 } from "../v2/settings/SettingsV2";
import { SettingsPagesV2 } from "../v2/settings/pages/SettingsPagesV2";
import { deviceLine } from "../v2/settings/pages/DevicesV2";
import { retentionWords } from "../v2/settings/pages/StorageV2";
import { SettingsPanel } from "../v2/shell/PlacePanels";
import { CONVERTED } from "./converted";
import { textsIn } from "./rawText";
import ur from "./ur.json";
import { FSI, PDI, resetLanguageForTests, setLanguage, t, tc, withParts } from "./index";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

const src = join(dirname(fileURLToPath(import.meta.url)), "..");
const SETTINGS = makeSettings();
const ACCOUNT = { name: "Ayesha Siddiqui", email: "ayesha@qureshitextiles.pk", email_verified: true, two_factor: true, google: false, created_at: null, workspace: null, deletes_at: null };
const run = <T,>(fn: () => Promise<T>) => fn().catch(() => undefined);
const urdu = () => act(() => setLanguage("ur", { root: false }));
const iso = (value: string | number) => `${FSI}${value}${PDI}`;
const headings = (level: number) => screen.queryAllByRole("heading", { level }).map((h) => h.textContent);

beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
  respond("get_settings", SETTINGS);
  respondWith("update_settings", ({ patch }) => ({ ...SETTINGS, ...(patch as SettingsPatch) }));
  respond("dictation_history", []);
  respond("mic_users", [makeMicUser()]);
  respond("mic_last_not_recorded", null);
  respond("list_clients", { data: [] });
  resetEntitlementsForTests(sampleEntitlements("free"));
  respond("account_get", ACCOUNT);
  respond("security_overview", { devices: [], events: [] });
  respond("input_devices", []);
  respond("permissions_status", { microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "macos" });
  respond("screens_status", { supported: true, platform: "macos", borderless: false, permission: true, inPlan: true, onServer: true });
  respond("lock_status", { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 });
  respond("update_status", { phase: "idle", current: "2.0.0", version: null, notes: null, checkedAt: null, message: null, progress: null });
  respond("storage_info", { recordings_bytes: 1_000_000, recordings_count: 3, disk_free_bytes: null, disk_total_bytes: null, retention_days: 0 });
});
afterEach(() => resetLanguageForTests());

const page = async (section: string, grouped = true) => {
  render(<div className="el-root"><SettingsPagesV2 section={{ id: section, seq: 1 }} grouped={grouped} threeLevels available={() => true} onHome={vi.fn()}
    onSection={vi.fn()} run={run} status={makeStatus({ platform: "macos" })} onUnpaired={vi.fn()} /></div>);
  await act(flushAsync);
  await act(flushAsync);
};

describe("the catalogue has D3a's words", () => {
  it("every text D3a's files give to t() or tc(), the Settings panel's, and the groups' titles and lines have their Urdu", () => {
    const files = [...CONVERTED.filter((f) => f.startsWith("v2/settings/")), "v2/shell/PlacePanels.tsx"];
    const keys = new Set<string>();
    for (const file of files) {
      const path = join(src, file);
      for (const found of textsIn(path, readFileSync(path, "utf8"))) if (found.translated) keys.add(found.text);
    }
    for (const group of GOAL_GROUPS) keys.add(group.title).add(group.description);
    expect(keys.size).toBeGreaterThan(400);
    expect([...keys].filter((key) => !(key in ur))).toEqual([]);
  });
});

describe("D3a's words, in English as before and in Urdu", () => {
  it("a count's words agree with its number in Urdu, though English has one form", async () => {
    expect([minutesLine(1, null), minutesLine(212, 300)]).toEqual(["1 minutes used this month", "212 of 300 minutes used this month"]);
    expect([retentionWords(365), retentionWords(730), retentionWords(1), retentionWords(30)]).toEqual(["1 year", "2 years", "1 day", "30 days"]);
    expect([tc(":count rule|:count rules", 1), tc(":count rule|:count rules", 0), tc(":count app|:count apps", 2)]).toEqual(["1 rule", "0 rules", "2 apps"]);
    await urdu();
    expect(minutesLine(1, null)).toBe(`اس مہینے ${iso(1)} منٹ استعمال ہوا`);
    expect(minutesLine(212, 300)).toBe(`اس مہینے ${iso(300)} میں سے ${iso(212)} منٹ استعمال ہوئے`);
    expect(retentionWords(730)).toBe(`${iso(2)} سال`);
    expect(tc(":count rule|:count rules", 1)).toBe(`${iso(1)} قاعدہ`);
    expect(tc(":count rule|:count rules", 3)).toBe(`${iso(3)} قواعد`);
  });

  it("a device's line: the app's word for a phone, a system's name as it is, the time in its isolate", async () => {
    const at = new Date().toISOString();
    const phone = { id: "d2", name: "Ali's phone", platform: "android", current: false, last_seen_at: at } as SecurityDevice;
    expect(deviceLine(phone)).toBe(`Android phone, active ${formatWhen(at).toLowerCase()}`);
    await urdu();
    expect(deviceLine(phone)).toBe(`${iso("اینڈرائیڈ فون")}، آخری بار فعال ${iso(formatWhen(at).toLowerCase())}`);
    expect(deviceLine({ ...phone, platform: "macos" })).toBe(`${iso("Mac")}، آخری بار فعال ${iso(formatWhen(at).toLowerCase())}`);
    expect(deviceLine({ ...phone, current: true })).toBe("یہ کمپیوٹر");
  });

  it("a sentence with an element in it: English as it was written; in Urdu the element in its own isolate", async () => {
    const step = () => <p data-testid="step">{withParts(t("Open :site and sign in. It's free."), { site: <strong>console.groq.com</strong> })}</p>;
    const view = render(step());
    expect(screen.getByTestId("step").innerHTML).toBe("Open <strong>console.groq.com</strong> and sign in. It's free.");
    await urdu();
    view.rerender(step());
    expect(screen.getByTestId("step").innerHTML).toBe("<bdi><strong>console.groq.com</strong></bdi> کھولیں اور سائن اِن کریں۔ یہ مفت ہے۔");
  });
});

describe("Settings in Urdu", () => {
  it("the home: each group's title and line in Urdu, its English title still its id", async () => {
    await urdu();
    render(<div className="el-root"><SettingsV2 available={() => true} renderPage={() => null} /></div>);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "ترتیبات" })).toBeInTheDocument();
    const recording = screen.getByRole("region", { name: "ریکارڈنگ" });
    expect(recording).toHaveAttribute("aria-describedby", "group-recording");
    expect(document.getElementById("group-recording")).toHaveTextContent("کون سی کالز ریکارڈ ہوتی ہیں، اور کون سا مائیکروفون، اسکرین اور آواز استعمال ہوتی ہے۔");
    expect(screen.getByRole("searchbox", { name: "ترتیبات میں تلاش" })).toBeInTheDocument();
  });

  it("the panel: the search and the groups in Urdu; the group shown is still found by its English title", async () => {
    await urdu();
    render(<div className="el-root"><SettingsPanel current="calls" onOpen={vi.fn()} available={() => true} /></div>);
    expect(screen.getByRole("searchbox", { name: "ترتیبات میں تلاش" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "ریکارڈنگ" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("button", { name: "اکاؤنٹ اور پلان" })).not.toHaveAttribute("aria-current");
  });

  it("a group's page: its title and its own section titles in Urdu; a page named as its group still isn't titled twice", async () => {
    await page("privacy");
    const english = headings(2);
    cleanup();
    await urdu();
    await page("privacy");
    expect(headings(1)).toEqual(["پرائیویسی اور ڈیٹا"]);
    expect(headings(2)).toHaveLength(english.length);
    cleanup();
    await page("calls");
    expect(headings(1)).toEqual(["ریکارڈنگ"]);
    expect(screen.getByRole("heading", { level: 3, name: "خودکار ریکارڈنگ" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 3, name: "کال ریکارڈ کیوں نہیں ہوئی" })).toBeInTheDocument();
  });

  it("Plan & usage: plan names as written, each value in its isolate, the word Upgrade in its sentence", async () => {
    const e = sampleEntitlements("pro");
    e.limits.monthly_minutes = { limit: 600, used: 510, resets_at: "2026-12-01" };
    resetEntitlementsForTests(e);
    await urdu();
    await page("plan", false);
    expect(screen.getByText(`${iso("Pro")} پلان`)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: `${iso("Ultra")} پر اپ گریڈ کریں` })).toBeInTheDocument();
    const minutes = screen.getByTestId("meter-monthly_minutes");
    expect(minutes).toHaveTextContent(`آپ اپنے ${iso(85)}% منٹ استعمال کر چکے ہیں۔`);
    // The word in the sentence is the noun (اپ گریڈ); the sentence says کریں after it (EL-UX).
    expect(minutes).toHaveTextContent(`آپ اپنے ${iso(85)}% منٹ استعمال کر چکے ہیں۔ اپ گریڈ کریں یا`);
    expect(within(minutes).getByRole("button", { name: "اپ گریڈ" }).parentElement?.tagName).toBe("BDI");
    cleanup();
    resetEntitlementsForTests(sampleEntitlements("free"));
    await page("plan", false);
    expect(screen.getByText(`${iso("Free")} پلان`)).toBeInTheDocument();
  });
});
