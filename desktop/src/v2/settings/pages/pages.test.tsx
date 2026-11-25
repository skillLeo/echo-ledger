import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import type { ReactElement } from "react";
import type { AppStatus, PermissionsStatus, ScreensStatus, Settings, SettingsPatch } from "../../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../../test/tauri";
import { answerConfirm } from "../../../test/confirm";
import { collectControls, disabledSwitches, stale, type ControlRole, type RolelessField } from "../../../test/parity";
import { makeItem, makeMicUser, makeSettings, makeStatus, makeUpgradeRequest, sampleEntitlements } from "../../../test/fixtures";
import { resetEntitlementsForTests } from "../../../lib/entitlements";
import { AboutPage, AppearancePage, LanguagePage, ShortcutsPage, WordsPage } from "../../../views/main/AppSettings";
import { getThemeChoice } from "../../../lib/theme";
import { loadPreferences } from "../../../lib/shell";
import { TranscriptionKeyPage } from "../../../views/main/Upgrade";
import { ConsentPage, PrivacyPage, ProfilePage, SecurityPage } from "../../../views/main/AccountSettings";
import { PlanUsagePage } from "../../../views/main/Upgrade";
import { useUpgradeSheet } from "../../../lib/entitlements";
import { DevicesPage, LockPage, NotificationsPage, StoragePage } from "../../../views/main/SecuritySettings";
import { resetTrashKeepHoursForTests } from "../../../lib/trash";
import type { Account } from "../../../lib/ipc";
import MainApp from "../../../views/main/MainApp";
import { CallSitesPage, CallsPage, CaptionStylePage, CaptionsPage, MicrophonePage } from "../../../views/main/CaptureSettings";
import { MacCheckCard } from "../../../views/main/MacCheck";
import type { CheckItem, CheckReport } from "../../../lib/ipc";
import { ExcludedAppsPage, MONTHLY_NOTE, ScreenMomentsPage, TARGET_HINT, WINDOWS_WHOLE_SCREEN, screensBlocked } from "../../../views/main/ScreenSettings";
import { setting } from "../../../lib/settingsRegistry";
import { HistoryPage, ReplacementsPage, SnippetsPage, VoicePage } from "../../../views/main/DictationSettings";
import { SettingsPagesV2 } from "./SettingsPagesV2";
import { hasV2Page, sectionTarget } from "./route";

vi.mock("@tauri-apps/api/core", async () => (await import("../../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../../test/tauri")).eventModule);

const SETTINGS = makeSettings({
  consentAcknowledged: false,
  dictationReplacements: [{ from: "echo ledger", to: "EchoLedger" }],
  dictationSnippets: [{ trigger: "insert signature", text: "Regards,\nAyesha" }],
});
const ACCOUNT: Account = { name: "Ayesha Siddiqui", email: "ayesha@qureshitextiles.pk", email_verified: true, two_factor: true, google: false, created_at: null, workspace: null, deletes_at: null };
/** An account on a server with the account routes of 2.0.0-l (a picture, two-step on). */
const NATIVE: Account = { ...ACCOUNT, has_password: true, avatar_url: "https://ledger.example.test/a", avatar_version: 3, pending_email: null, passkeys_available: true };
const NATIVE_SECURITY = { password: true, google: { linked: false, email: null }, passkeys: [{ id: "pk1", name: "Phone", created_at: null, last_used_at: null }],
  two_factor: { enabled: true, recovery_codes_left: 6 }, browsers: [], devices: [] };
const HISTORY = [{ at: "2026-11-17T08:00:00Z", text: "Send the proforma today." }];
const run = <T,>(fn: () => Promise<T>) => fn().catch(() => undefined);
const CHECK_OK: CheckItem = { key: "macos_version", title: "macOS version", state: "ok", detail: "macOS 15.1: call audio works on this version.", fix: null };
const CHECK_OFF: CheckItem = { key: "system_audio_permission", title: "System audio permission", state: "off",
  detail: "Off. Without it a recording has your voice, not the other side of the call.", fix: { label: "Open System Settings", settings: "system_audio" } };
const CHECK_REOPEN: CheckItem = { key: "screen_permission", title: "Screen Recording", state: "limited", detail: "Allowed while EchoLedger ran: it applies after a reopen.",
  fix: { label: "Quit & reopen", settings: "screen", reopen: true } };
const macReport = (over: Partial<CheckReport> = {}): CheckReport =>
  ({ items: [CHECK_OK, CHECK_OFF, CHECK_REOPEN], stopped: null, finishedAt: "2026-11-17T08:00:00Z", appVersion: "2.0.0", osVersion: "15.1", model: "Mac14,2", sentAt: null, ...over });
const UPDATE_IDLE = { phase: "idle", current: "2.0.0", version: null, notes: null, checkedAt: "2026-11-17T08:00:00Z", message: null, progress: null };
const DEVICES = [
  { id: "d2", name: "Ayesha's S25", platform: "android", app_version: "2.0.0", last_seen_at: "2026-11-17T07:00:00Z", paired_at: null, current: false },
  { id: "d1", name: "Work laptop", platform: "windows", app_version: "2.0.0", last_seen_at: null, paired_at: null, current: true },
];

beforeEach(() => {
  resetTauriMock();
  respond("get_settings", SETTINGS);
  respondWith("update_settings", ({ patch }) => ({ ...SETTINGS, ...(patch as SettingsPatch) }));
  respond("dictation_history", HISTORY);
  respond("dictation_history_clear", null);
  respond("mic_users", [makeMicUser()]);
  respond("mic_last_not_recorded", null);
  respond("list_clients", { data: [{ id: "c1", name: "Qureshi Textiles" }] });
  resetEntitlementsForTests(sampleEntitlements("free"));
  respond("account_get", ACCOUNT);
  respond("security_overview", { devices: [], events: [{ id: 1, event: "sign_in", label: "Signed in", summary: "Signed in", actor: "device", device: "Work laptop", at: "2026-11-17T08:00:00Z" }] });
  respond("input_devices", [{ id: "usb", name: "Jabra Evolve2", isDefault: false }, { id: "builtin", name: "MacBook Microphone", isDefault: true }]);
  respond("permissions_status", { microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "windows" });
  respond("test_capture", { seconds: 10, micPeak: 0.6, systemPeak: 0, micHeard: true, systemHeard: false, wavBase64: "UklGRg==" });
  respond("screens_status", { supported: true, platform: "windows", borderless: false, permission: true, inPlan: true, onServer: true });
  respond("screens_permission", false);
  respond("lock_status", { enabled: false, locked: false, hasPin: false, osMethod: "windows_hello", retryAfterSeconds: 0 });
  respond("update_status", UPDATE_IDLE);
  respond("mac_check_last", null);
});

let onUnpaired = vi.fn();
const show = async (section: string, platform = "windows") => {
  const onHome = vi.fn();
  onUnpaired = vi.fn();
  render(<div className="el-root"><SettingsPagesV2 section={{ id: section, seq: 1 }} onHome={onHome} run={run} status={makeStatus({ platform: platform as "windows" })} onUnpaired={onUnpaired} /></div>);
  await act(flushAsync);
  return onHome;
};
const patches = () => callsOf("update_settings").map((c) => (c as { patch: SettingsPatch }).patch);

describe("which sections open a v2 page", () => {
  it("the pages with a v2 page and their settings, by page, setting or v1.6 name", () => {
    expect(sectionTarget("voice")).toEqual({ page: "voice", setting: null });
    expect(sectionTarget("voice#voice.cleanup")).toEqual({ page: "voice", setting: "voice.cleanup" });
    expect(sectionTarget("captions.opacity")).toEqual({ page: "captions.style", setting: "captions.opacity" });
    expect(hasV2Page("voice.history")).toBe(true);
    expect(hasV2Page("captions")).toBe(true);
    expect(hasV2Page("calls")).toBe(true);
    expect(hasV2Page("lock")).toBe(true);
    expect(hasV2Page("notifications")).toBe(true);
    expect(hasV2Page("appearance")).toBe(true);
    expect(hasV2Page("help")).toBe(false);
    expect(hasV2Page("nonsense")).toBe(false);
  });
});

describe("Voice notes & dictation v2", () => {
  it("saves each choice at once; Replacements, Snippets and Recent dictations open and come back", async () => {
    const onHome = await show("voice");
    expect(screen.getByRole("heading", { level: 1, name: "Voice notes & dictation" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("combobox", { name: /^How the shortcut works/ }));
    fireEvent.click(screen.getByRole("option", { name: "Hold the shortcut while speaking" }));
    fireEvent.click(screen.getByRole("switch", { name: "Clean up the text" }));
    await act(flushAsync);
    expect(patches()).toEqual([{ dictationMode: "push_to_talk" }, { dictationCleanup: false }]);
    expect(screen.getByRole("button", { name: "Replacements" })).toHaveAccessibleDescription(/1/);

    fireEvent.click(screen.getByRole("button", { name: "Replacements" }));
    expect(screen.getByRole("heading", { level: 1, name: "Replacements" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Voice notes & dictation" }));
    expect(screen.getByRole("heading", { level: 1, name: "Voice notes & dictation" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Settings" }));
    expect(onHome).toHaveBeenCalled();
  });

  it("replacements: add (not twice), remove", async () => {
    await show("voice.replacements");
    fireEvent.change(screen.getByRole("textbox", { name: "When I say" }), { target: { value: "kuraishi" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Write" }), { target: { value: "Qureshi" } });
    fireEvent.click(screen.getByRole("button", { name: "Add replacement" }));
    await act(flushAsync);
    expect(patches()[0]).toEqual({ dictationReplacements: [{ from: "echo ledger", to: "EchoLedger" }, { from: "kuraishi", to: "Qureshi" }] });
    expect(screen.getByRole("textbox", { name: "When I say" })).toHaveValue("");
    fireEvent.change(screen.getByRole("textbox", { name: "When I say" }), { target: { value: "Echo Ledger" } });
    fireEvent.click(screen.getByRole("button", { name: "Add replacement" }));
    await act(flushAsync);
    expect(patches()).toHaveLength(1);
    fireEvent.click(screen.getByRole("button", { name: "Remove echo ledger" }));
    await act(flushAsync);
    expect(patches()[1]).toEqual({ dictationReplacements: [{ from: "kuraishi", to: "Qureshi" }] });
  });

  it("snippets: add with its text, remove", async () => {
    await show("voice.snippets");
    expect(screen.getByRole("button", { name: "Add snippet" })).toBeDisabled();
    fireEvent.change(screen.getByRole("textbox", { name: "Phrase" }), { target: { value: "bank details" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Text to insert" }), { target: { value: "Meezan Bank 0101" } });
    fireEvent.click(screen.getByRole("button", { name: "Add snippet" }));
    await act(flushAsync);
    expect(patches()[0].dictationSnippets).toHaveLength(2);
    fireEvent.click(screen.getByRole("button", { name: "Remove insert signature" }));
    await act(flushAsync);
    expect(patches()[1]).toEqual({ dictationSnippets: [{ trigger: "bank details", text: "Meezan Bank 0101" }] });
  });

  it("recent dictations: copy one; clear after asking", async () => {
    const writeText = vi.fn(async () => undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    await show("voice.history");
    fireEvent.click(screen.getByRole("button", { name: /^Copy/ }));
    await act(flushAsync);
    expect(writeText).toHaveBeenCalledWith("Send the proforma today.");
    expect(screen.getByRole("button", { name: "Copied" })).toBeInTheDocument();
    const asked = answerConfirm();
    asked.mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Clear history" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Clear your recent dictations? They're deleted from this computer.");
    expect(callsOf("dictation_history_clear")).toHaveLength(1);
    expect(screen.getByRole("region", { name: "Nothing dictated yet" })).toBeInTheDocument();
  });

  it("a setting asked for is scrolled to and highlighted", async () => {
    await show("voice#voice.sounds");
    await act(async () => { await new Promise((r) => setTimeout(r, 200)); });
    expect(document.getElementById("setting-voice.sounds")).toHaveClass("is-highlighted");
    expect(document.getElementById("setting-voice.sounds")).toHaveFocus();
  });
});

describe("Captions v2", () => {
  it("live captions while recording; Style saves each change with the rest of the style", async () => {
    await show("captions");
    fireEvent.click(screen.getByRole("switch", { name: "Live captions while recording" }));
    await act(flushAsync);
    expect(patches()).toEqual([{ captionsOnRecord: !SETTINGS.captionsOnRecord }]);
    fireEvent.click(screen.getByRole("button", { name: "Style" }));
    expect(screen.getByRole("heading", { level: 1, name: "Style" })).toBeInTheDocument();
    fireEvent.click(within(screen.getByRole("radiogroup", { name: "Text size" })).getByRole("radio", { name: "Large" }));
    fireEvent.change(screen.getByRole("slider", { name: "Background opacity" }), { target: { value: "60" } });
    fireEvent.click(within(screen.getByRole("radiogroup", { name: "Colour for Me" })).getByRole("radio", { name: "#22d3ee" }));
    await act(flushAsync);
    const style = patches().slice(1).map((p) => p.captionStyle);
    expect(style[0]).toEqual({ ...SETTINGS.captionStyle, size: "large" });
    expect(style[1]?.opacity).toBe(60);
    expect(style[2]?.meColor).toBe("#22d3ee");
  });

  it("Reset to defaults asks first", async () => {
    await show("captions.style");
    const asked = answerConfirm();
    asked.mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Reset to defaults" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Reset Style to defaults?");
    expect(patches()[0]).toMatchObject({ captionLines: 3, overlayCorner: "bottom_right" });
  });
});

describe("Calls & meetings v2", () => {
  it("auto-record and each call app; unknown apps; after a call; short calls; sites; why a call wasn't recorded", async () => {
    await show("calls");
    expect(screen.getByRole("heading", { level: 1, name: "Calls & meetings" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("switch", { name: "Auto-record Zoom" }));
    fireEvent.click(screen.getByRole("switch", { name: "Ask about unknown apps" }));
    fireEvent.click(screen.getByRole("combobox", { name: /^Skip calls shorter than/ }));
    fireEvent.click(screen.getByRole("option", { name: "30 s" }));
    await act(flushAsync);
    expect(patches()).toEqual([{ autoRecordApps: { zoom: false } }, { askUnknownApps: !SETTINGS.askUnknownApps }, { skipCallsShorterThanS: 30 }]);
    expect(screen.queryByRole("switch", { name: "Read browser tab addresses" })).toBeNull();
    expect(screen.getByTestId("why-not-recorded")).toHaveTextContent(/Google Meet|chrome/i);
    fireEvent.click(screen.getByRole("button", { name: "Call sites" }));
    expect(screen.getByRole("heading", { level: 1, name: "Call sites" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Calls & meetings" }));
    // Not read yet: the notice is a link away.
    fireEvent.click(screen.getByRole("button", { name: "Read the recording consent notice" }));
    expect(screen.getByRole("heading", { level: 1, name: "Recording consent" })).toBeInTheDocument();
  });

  it("with auto-record off, the apps can't be switched; on a Mac, browser tabs", async () => {
    respond("get_settings", { ...SETTINGS, autoMode: false });
    await show("calls", "macos");
    expect(screen.getByRole("switch", { name: "Auto-record Zoom" })).toBeDisabled();
    expect(screen.getByRole("switch", { name: "Read browser tab addresses" })).toBeInTheDocument();
  });

  it("call sites: a built-in rule switched off and a site added", async () => {
    await show("calls.sites");
    fireEvent.click(screen.getByRole("switch", { name: "Record calls on zoom.us" }));
    await act(flushAsync);
    expect(patches()[0].siteRules?.some((r) => r.id === "builtin.zoom" && r.enabled === false)).toBe(true);
    fireEvent.change(screen.getByRole("textbox", { name: "Address or title contains" }), { target: { value: "meet.jit.si" } });
    fireEvent.click(screen.getByRole("button", { name: "Add" }));
    await act(flushAsync);
    const added = patches()[1].siteRules!;
    expect(added.some((r) => JSON.stringify(r).includes("meet.jit.si"))).toBe(true);
    expect(screen.getByRole("textbox", { name: "Address or title contains" })).toHaveValue("");
  });
});

describe("Transcription & language v2", () => {
  it("Language: one choice, saved at once, automatic as null", async () => {
    await show("transcription.language");
    expect(screen.getByRole("heading", { level: 1, name: "Language" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("combobox", { name: /^Language/ }));
    fireEvent.click(screen.getAllByRole("option")[1]);
    await act(flushAsync);
    expect(patches()[0].transcriptionLanguage).not.toBeUndefined();
  });

  it("Custom words: a project's terms, or None", async () => {
    await show("words");
    fireEvent.click(screen.getByRole("combobox", { name: /^Use terms from project/ }));
    fireEvent.click(screen.getByRole("option", { name: "Qureshi Textiles" }));
    await act(flushAsync);
    expect(patches()).toEqual([{ clientId: "c1" }]);
  });

  it("Transcription key: add a key; a saved one is removed after asking", async () => {
    respond("transcription_key_set", { provider: "groq", set: true, last4: "9xQ2" });
    respond("groq_console_open", null);
    respond("entitlements_get", sampleEntitlements("free"));
    await show("key");
    fireEvent.click(screen.getByRole("button", { name: "Open console.groq.com" }));
    expect(screen.getByRole("button", { name: "Save" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Groq key"), { target: { value: "gsk_abcdefghijklmnopqrstuvwxyz" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(callsOf("groq_console_open")).toHaveLength(1);
    expect(callsOf("transcription_key_set")).toEqual([{ key: "gsk_abcdefghijklmnopqrstuvwxyz" }]);
    expect(screen.getByRole("status")).toHaveTextContent("Key saved.");

    const withKey = sampleEntitlements("free");
    withKey.transcription_key = { provider: "groq", set: true, last4: "9xQ2" };
    resetEntitlementsForTests(withKey);
    await act(flushAsync);
    respond("transcription_key_delete", null);
    expect(screen.getByRole("heading", { name: "Replace the key" })).toBeInTheDocument();
    const asked = answerConfirm();
    asked.mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Remove key" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Remove your Groq key? New recordings won't be transcribed until you add a key or upgrade.");
    expect(callsOf("transcription_key_delete")).toHaveLength(1);
  });
});

describe("Privacy & data v2", () => {
  it("consent is read and acknowledged, never switched", async () => {
    await show("privacy");
    expect(screen.getByRole("button", { name: "Recording consent" })).toHaveAccessibleDescription(/Not yet/);
    fireEvent.click(screen.getByRole("button", { name: "Recording consent" }));
    expect(screen.getByTestId("consent-notice")).toBeInTheDocument();
    expect(screen.queryByRole("switch")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "I understand" }));
    await act(flushAsync);
    expect(patches()).toEqual([{ consentAcknowledged: true }]);
    expect(screen.getByTestId("consent-done")).toBeInTheDocument();
  });

  it("Export my data: made on the server, asked about, then its link opened once", async () => {
    vi.useFakeTimers({ toFake: ["setInterval", "clearInterval"] });
    try {
      respond("account_export_start", { id: "x1", status: "making", url: null });
      respond("account_export_status", { id: "x1", status: "ready", url: "https://ledger.example.com/x1.zip" });
      respond("account_export_open", null);
      await show("privacy");
      fireEvent.click(screen.getByRole("button", { name: "Export my data" }));
      await act(flushAsync);
      expect(screen.getByRole("button", { name: "Making your export…" })).toBeDisabled();
      await act(async () => { vi.advanceTimersByTime(5000); });
      await act(flushAsync);
      expect(callsOf("account_export_open")).toEqual([{ url: "https://ledger.example.com/x1.zip" }]);
      expect(screen.getByRole("button", { name: "Download your export" })).toBeInTheDocument();
    } finally {
      vi.useRealTimers();
    }
  });

  it("Delete my account: the password or a two-step code; then deletion can be cancelled on the website", async () => {
    respond("account_delete", "2026-11-24T10:00:00Z");
    respond("server_page_open", null);
    await show("privacy");
    fireEvent.click(screen.getByRole("button", { name: "Delete my account" }));
    const dialog = screen.getByRole("alertdialog", { name: "Delete your account?" });
    expect(within(dialog).getByRole("button", { name: "Delete my account" })).toBeDisabled();
    fireEvent.click(within(dialog).getByRole("button", { name: "Use a two-step code instead" }));
    fireEvent.change(within(dialog).getByRole("textbox", { name: "Two-step code" }), { target: { value: " 123456 " } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Delete my account" }));
    await act(flushAsync);
    expect(callsOf("account_delete")).toEqual([{ password: null, otp: "123456" }]);
    expect(screen.queryByRole("alertdialog")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Cancel deletion, opens in browser" }));
    expect(callsOf("server_page_open")).toEqual([{ page: "app/profile", serverUrl: null }]);
  });

  it("a server without accounts keeps only the consent notice", async () => {
    respond("account_get", null);
    await show("privacy");
    expect(screen.getByRole("button", { name: "Recording consent" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Export my data" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Delete my account" })).toBeNull();
  });
});

describe("App lock v2", () => {
  it("off: when it locks and Lock now wait; a PIN is set by typing it twice", async () => {
    respond("lock_set_pin", { enabled: false, locked: false, hasPin: true, osMethod: "windows_hello", retryAfterSeconds: 0 });
    await show("lock");
    expect(screen.getByRole("combobox", { name: /^Lock after/ })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Lock now" })).toBeDisabled();
    expect(screen.getByText(/Windows Hello or your PIN unlocks it/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "PIN" }));
    const dialog = screen.getByRole("dialog", { name: "Set PIN" });
    fireEvent.change(within(dialog).getByLabelText("PIN (4–12 digits)"), { target: { value: "12a34" } });
    fireEvent.change(within(dialog).getByLabelText("Repeat the PIN"), { target: { value: "1235" } });
    expect(within(dialog).getByText("The PINs differ.")).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: "Set PIN" })).toBeDisabled();
    fireEvent.change(within(dialog).getByLabelText("Repeat the PIN"), { target: { value: "1234" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Set PIN" }));
    await act(flushAsync);
    expect(callsOf("lock_set_pin")).toEqual([{ pin: "1234" }]);
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(screen.getByRole("button", { name: "PIN" })).toHaveAccessibleDescription(/Set/);
  });

  it("on: Lock now locks", async () => {
    respond("get_settings", { ...SETTINGS, appLock: true });
    respond("lock_now", { enabled: true, locked: true, hasPin: true, osMethod: null, retryAfterSeconds: 0 });
    await show("lock");
    fireEvent.click(screen.getByRole("button", { name: "Lock now" }));
    await act(flushAsync);
    expect(callsOf("lock_now")).toHaveLength(1);
  });
});

describe("Sign-in & security v2", () => {
  it("password and two-step open the website; activity lists sign-ins; Sign out everywhere asks first", async () => {
    respond("server_page_open", null);
    respond("account_sign_out_everywhere", makeStatus({ paired: false }));
    await show("security");
    fireEvent.click(screen.getByRole("button", { name: "Password, opens in browser" }));
    await act(flushAsync);
    expect(callsOf("server_page_open")).toEqual([{ page: "app/profile", serverUrl: null }]);
    expect(screen.getByRole("button", { name: "Two-step sign-in, opens in browser" })).toHaveAccessibleDescription(/On/);
    fireEvent.click(screen.getByRole("button", { name: "Sign out everywhere" }));
    const dialog = screen.getByRole("alertdialog", { name: "Sign out everywhere?" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Sign out everywhere" }));
    await act(flushAsync);
    expect(callsOf("account_sign_out_everywhere")).toHaveLength(1);
    expect(onUnpaired).toHaveBeenCalled();
  });

  it("Sign-in activity", async () => {
    await show("security.activity");
    expect(within(screen.getByRole("list", { name: "Recent activity" })).getByText("Signed in, Work laptop")).toBeInTheDocument();
  });
});

describe("Notifications v2", () => {
  it("one switch for all, sounds, quiet hours and their times, a switch per kind, and a test", async () => {
    respond("get_settings", { ...SETTINGS, activityNotifications: true, quietHours: true, quietFrom: "22:00", quietTo: "07:00" });
    respond("notification_test", true);
    await show("notifications");
    fireEvent.click(screen.getByRole("switch", { name: "Play sounds" }));
    fireEvent.change(screen.getByLabelText("Quiet hours from"), { target: { value: "21:30" } });
    fireEvent.click(screen.getByRole("switch", { name: "Sharing" }));
    await act(flushAsync);
    const ps = patches();
    expect(Object.values(ps[0].notificationSounds ?? {}).every((v) => v === false)).toBe(true);
    expect(ps[1]).toEqual({ quietFrom: "21:30" });
    expect(ps[2]).toEqual({ notificationCategories: { sharing: !(SETTINGS.notificationCategories?.sharing !== false) } });
    fireEvent.click(screen.getByRole("button", { name: "Send a test notification" }));
    await act(flushAsync);
    expect(callsOf("notification_test")).toEqual([{ category: "other" }]);
  });

  it("with notifications off, everything under them waits; with quiet hours off, the times wait", async () => {
    respond("get_settings", { ...SETTINGS, activityNotifications: false, quietHours: true });
    await show("notifications");
    expect(screen.getByRole("switch", { name: "Play sounds" })).toBeDisabled();
    expect(screen.getByRole("switch", { name: "Recording" })).toBeDisabled();
    expect(screen.getByLabelText("Quiet hours to")).toBeDisabled();
  });

  it("a test the system didn't show says how to allow it", async () => {
    respond("notification_test", false);
    const notices: string[] = [];
    render(<div className="el-root"><SettingsPagesV2 section={{ id: "notifications", seq: 1 }} onHome={vi.fn()} status={makeStatus()} onUnpaired={vi.fn()}
      run={(fn) => fn().catch((e) => { notices.push(String(e)); return undefined; })} /></div>);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Send a test notification" }));
    await act(flushAsync);
    expect(notices).toEqual(["The system didn't show it. Allow notifications for EchoLedger in your computer's settings."]);
  });
});

describe("Microphone & audio v2", () => {
  it("chooses the microphone, tests 10 seconds with what was heard and a playback, and the silent caller warning", async () => {
    await show("mic");
    fireEvent.click(screen.getByRole("combobox", { name: /^Microphone/ }));
    fireEvent.click(screen.getByRole("option", { name: "Jabra Evolve2" }));
    fireEvent.click(screen.getByRole("switch", { name: "Silent caller warning" }));
    await act(flushAsync);
    expect(patches()).toEqual([{ inputDevice: "usb" }, { farSideWarning: !SETTINGS.farSideWarning }]);
    fireEvent.click(screen.getByRole("button", { name: "Test 10 s" }));
    await act(flushAsync);
    expect(callsOf("test_capture")).toEqual([{ seconds: null }]);
    expect(screen.getByText("Your microphone is working.")).toBeInTheDocument();
    expect(screen.getByText(/No sound from this computer was captured/)).toBeInTheDocument();
    expect(screen.getByLabelText("Play back the test recording")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Test again" })).toBeInTheDocument();
    expect(screen.getByRole("meter", { name: "Microphone level" })).toHaveAttribute("aria-valuenow");
  });

  it("while recording the test waits and says why; a permission not given has its Fix, on a Mac for system audio too", async () => {
    respond("permissions_status", { microphone: "denied", systemAudio: "denied", screen: "allowed", browserAutomation: true, platform: "macos" });
    respond("open_privacy_settings", null);
    const onHome = vi.fn();
    render(<div className="el-root"><SettingsPagesV2 section={{ id: "mic", seq: 1 }} onHome={onHome} run={run} onUnpaired={vi.fn()}
      status={makeStatus({ platform: "macos", recording: { clientRef: "r", mode: "call", sourceApp: "zoom", clientId: null, startedAt: "", backend: "mock" } })} /></div>);
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Test 10 s" })).toBeDisabled();
    expect(screen.getByText("Finish the current recording to run a test.")).toBeInTheDocument();
    const fixes = screen.getAllByRole("button", { name: "Fix" });
    expect(fixes).toHaveLength(2);
    fireEvent.click(fixes[1]);
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "system_audio" }]);
  });
});

describe("Screen moments v2", () => {
  it("on Windows: on, what is captured, the border, and the apps never captured", async () => {
    resetEntitlementsForTests(sampleEntitlements("pro"));
    await show("screen");
    expect(screen.getByRole("switch", { name: "Keep screen moments" })).toBeChecked();
    fireEvent.click(screen.getByRole("combobox", { name: /^Capture/ }));
    fireEvent.click(screen.getAllByRole("option")[1]);
    await act(flushAsync);
    expect(patches()[0].screenTarget).toBeDefined();
    expect(screen.getByText("Shown on Windows 10")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Never capture these apps" }));
    expect(screen.getByRole("heading", { level: 1, name: "Never capture these apps" })).toBeInTheDocument();
  });

  it("on a Mac: Allow Screen Recording, and after a refusal Open System Settings", async () => {
    respond("screens_status", { supported: true, platform: "macos", borderless: false, permission: false, inPlan: true, onServer: true });
    resetEntitlementsForTests(sampleEntitlements("pro"));
    await show("screen", "macos");
    fireEvent.click(screen.getByRole("button", { name: "Allow Screen Recording" }));
    await act(flushAsync);
    expect(callsOf("screens_permission")).toHaveLength(1);
    expect(screen.getByRole("button", { name: "Open System Settings" })).toBeInTheDocument();
    expect(screen.getByText(/Turn on EchoLedger in System Settings/)).toBeInTheDocument();
  });

  it("a plan without it shows its plan and captures nothing; a server without it says so", async () => {
    resetEntitlementsForTests(sampleEntitlements("free"));
    await show("screen");
    expect(screen.queryByRole("switch", { name: "Keep screen moments" })).toBeNull();
    expect(screen.getByRole("button", { name: /^Keep screen moments, in / })).toBeInTheDocument();
    cleanup();
    resetEntitlementsForTests(sampleEntitlements("pro"));
    respond("screens_status", { supported: true, platform: "windows", borderless: true, permission: true, inPlan: true, onServer: false });
    await show("screen");
    expect(screen.getByTestId("screens-blocked")).toHaveTextContent("Your server doesn't have screen moments yet.");
  });

  it("never capture these apps: add (not twice, by name), remove", async () => {
    respond("get_settings", { ...SETTINGS, screenExcludedApps: ["1Password"] });
    await show("screen.excluded");
    fireEvent.change(screen.getByRole("textbox", { name: "App or process name" }), { target: { value: "Signal.exe" } });
    fireEvent.click(screen.getByRole("button", { name: "Exclude" }));
    await act(flushAsync);
    expect(patches()[0]).toEqual({ screenExcludedApps: ["1Password", "Signal.exe"] });
    fireEvent.change(screen.getByRole("textbox", { name: "App or process name" }), { target: { value: "1 password" } });
    fireEvent.click(screen.getByRole("button", { name: "Exclude" }));
    await act(flushAsync);
    expect(patches()).toHaveLength(1);
    fireEvent.click(screen.getByRole("button", { name: "Remove 1Password" }));
    await act(flushAsync);
    expect(patches()[1]).toEqual({ screenExcludedApps: ["Signal.exe"] });
  });
});

describe("Storage v2", () => {
  it("what is stored against the plan's limit, how many recordings, how long they are kept, and Trash's hours from the server", async () => {
    resetTrashKeepHoursForTests();
    respond("storage_info", { recordings_bytes: 900_000_000, recordings_count: 1234, disk_free_bytes: null, disk_total_bytes: null, retention_days: 365 });
    respond("trash_keep_hours", 72);
    resetEntitlementsForTests(sampleEntitlements("pro"));
    await show("storage");
    const meter = screen.getByRole("meter");
    expect(Number(meter.getAttribute("aria-valuenow"))).toBeGreaterThan(0);
    expect(screen.getByText("1,234 recordings")).toBeInTheDocument();
    expect(screen.getByText("1 year")).toBeInTheDocument();
    expect(screen.getAllByText(/3 days/).length).toBeGreaterThan(0);
    expect(screen.getByText("Trash")).toBeInTheDocument();
  });

  it("says the same facts as today's page (today's says the Bin keeps 2 days; v2 says Trash, with the server's hours)", async () => {
    resetTrashKeepHoursForTests();
    respond("storage_info", { recordings_bytes: 10_000, recordings_count: 1, disk_free_bytes: null, disk_total_bytes: null, retention_days: 30 });
    respond("trash_keep_hours", 48);
    render(<StoragePage limitBytes={null} />);
    await act(flushAsync);
    const today = document.body.textContent ?? "";
    cleanup();
    await show("storage");
    const v2 = document.body.textContent ?? "";
    for (const fact of ["Your storage", "1 recording", "Recordings kept", "30 days"]) {
      expect(today, fact).toContain(fact);
      expect(v2, fact).toContain(fact);
    }
    expect(v2).toContain("48 hours");
  });
});

describe("Appearance v2", () => {
  it("theme, sidebar, density and clock are kept on this computer and followed at once; Reset puts them back", async () => {
    localStorage.clear();
    const followed = vi.fn();
    window.addEventListener("echoledger:preferences", followed);
    await show("appearance");
    const group = (name: string) => within(screen.getByRole("radiogroup", { name }));
    fireEvent.click(group("Theme").getByRole("radio", { name: "Dark" }));
    fireEvent.click(group("Sidebar style").getByRole("radio", { name: "Soft grey" }));
    fireEvent.click(group("Density").getByRole("radio", { name: "Compact" }));
    fireEvent.click(group("Times").getByRole("radio", { name: "24-hour" }));
    expect(getThemeChoice()).toBe("dark");
    expect(loadPreferences()).toEqual({ sidebarStyle: "grey", density: "compact", clock: "24", compactRail: false });
    expect(document.documentElement.dataset.density).toBe("compact");
    expect(followed).toHaveBeenCalledTimes(3);
    expect(group("Theme").getByRole("radio", { name: "Dark" })).toHaveAttribute("aria-checked", "true");
    answerConfirm().mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Reset to defaults" }));
    await act(flushAsync);
    expect(getThemeChoice()).toBe("system");
    expect(loadPreferences()).toEqual({ sidebarStyle: "light", density: "comfortable", clock: "12", compactRail: false });
    window.removeEventListener("echoledger:preferences", followed);
  });

  it("Compact rail (icons only): only where the window has the rail, off by default, followed at once; Reset turns it off", async () => {
    localStorage.clear();
    await show("appearance");
    expect(screen.queryByRole("switch", { name: "Compact rail (icons only)" })).toBeNull();
    cleanup();
    const followed = vi.fn();
    const listen = (e: Event) => followed((e as CustomEvent).detail.compactRail);
    window.addEventListener("echoledger:preferences", listen);
    try {
      render(<div className="el-root"><SettingsPagesV2 section={{ id: "appearance", seq: 1 }} onHome={vi.fn()} run={run} status={makeStatus()} onUnpaired={vi.fn()} threeLevels /></div>);
      await act(flushAsync);
      const toggle = screen.getByRole("switch", { name: "Compact rail (icons only)" });
      expect(toggle).not.toBeChecked();
      fireEvent.click(toggle);
      expect(loadPreferences().compactRail).toBe(true);
      expect(followed).toHaveBeenLastCalledWith(true);
      answerConfirm().mockReturnValue(true);
      fireEvent.click(screen.getByRole("button", { name: "Reset to defaults" }));
      await act(flushAsync);
      expect(loadPreferences().compactRail).toBe(false);
      expect(screen.getByRole("switch", { name: "Compact rail (icons only)" })).not.toBeChecked();
    } finally {
      window.removeEventListener("echoledger:preferences", listen);
    }
  });

  it("the app's theme is the one shown, and a change goes to the app (the top bar's toggle agrees)", async () => {
    const onTheme = vi.fn();
    render(<div className="el-root"><SettingsPagesV2 section={{ id: "appearance", seq: 1 }} onHome={vi.fn()} run={run} status={makeStatus()} onUnpaired={vi.fn()} theme="light" onTheme={onTheme} /></div>);
    await act(flushAsync);
    const theme = within(screen.getByRole("radiogroup", { name: "Theme" }));
    expect(theme.getByRole("radio", { name: "Light" })).toHaveAttribute("aria-checked", "true");
    fireEvent.click(theme.getByRole("radio", { name: "Dark" }));
    expect(onTheme).toHaveBeenCalledWith("dark");
  });
});

describe("Keyboard shortcuts v2", () => {
  const key = (init: KeyboardEventInit) => act(async () => { window.dispatchEvent(new KeyboardEvent("keydown", init)); await flushAsync(); });

  it("Change listens for the keys and saves them; Escape stops listening; Clear empties one; Reset asks", async () => {
    respondWith("set_shortcut", ({ action, accelerator }) => ({ ...SETTINGS, shortcuts: { ...SETTINGS.shortcuts, [action as string]: accelerator } }));
    respond("reset_shortcuts", SETTINGS);
    await show("shortcuts");
    fireEvent.click(screen.getByRole("button", { name: "Change Record now" }));
    expect(screen.getByText("Press keys… (Esc cancels)")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Change Record now" })).toHaveAttribute("aria-pressed", "true");
    await key({ key: "Escape", code: "Escape" });
    expect(screen.queryByText("Press keys… (Esc cancels)")).toBeNull();
    expect(callsOf("set_shortcut")).toEqual([]);
    fireEvent.click(screen.getByRole("button", { name: "Change Record now" }));
    await key({ key: "Shift", code: "ShiftLeft", shiftKey: true, ctrlKey: true });
    expect(callsOf("set_shortcut")).toEqual([]);
    await key({ key: "k", code: "KeyK", shiftKey: true, ctrlKey: true });
    expect(callsOf("set_shortcut")).toEqual([{ action: "record_now", accelerator: "CommandOrControl+Shift+K" }]);
    expect(screen.getByText("Ctrl + Shift + K")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Clear Dictation" }));
    await act(flushAsync);
    expect(callsOf("set_shortcut")[1]).toEqual({ action: "dictation", accelerator: "" });
    expect(screen.queryByRole("button", { name: "Clear Capture a voice note" })).toBeNull();
    const asked = answerConfirm();
    asked.mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Reset to defaults" }));
    await act(flushAsync);
    expect(asked.mock.calls[0][0]).toBe("Reset Keyboard shortcuts to defaults?");
    expect(callsOf("reset_shortcuts")).toHaveLength(1);
  });

  it("says when another app has a shortcut or the system refused it, and lists a Mac's own keys with ⌘", async () => {
    render(<div className="el-root"><SettingsPagesV2 section={{ id: "shortcuts", seq: 1 }} onHome={vi.fn()} run={run}
      status={makeStatus({ platform: "macos", shortcutErrors: [["dictation", "Another app already uses this shortcut."]] })} onUnpaired={vi.fn()} /></div>);
    await act(flushAsync);
    expect(screen.getByText("Another app already uses this shortcut.")).toBeInTheDocument();
    expect(screen.getByText("⌘K")).toBeInTheDocument();
    expect(screen.getByText("⌘Q")).toBeInTheDocument();
    expect(screen.getByText("Quit EchoLedger; a running recording is saved first")).toBeInTheDocument();
  });

  it("warns when a shortcut is one browsers use", async () => {
    await show("shortcuts");
    // EL-UX (D3a review): who else uses it, never what it does there, so the app's name is the only value.
    // Start or stop saving (Ctrl+Shift+R) and Copy the last 2 minutes (Ctrl+Shift+C), both browsers' keys.
    const notes = screen.getAllByText("This shortcut is also used by browsers; EchoLedger takes it over.");
    expect(notes.map((note) => note.closest("[data-setting]")?.getAttribute("data-setting"))).toEqual(["shortcuts.global.toggle_saving", "shortcuts.global.copy_last2_min"]);
  });
});

describe("Profile v2", () => {
  it("who is signed in, an unverified email, the workspace and role on a team, since when, and Edit profile on the website", async () => {
    respond("account_get", { ...ACCOUNT, email_verified: false, created_at: "2025-03-04T10:00:00Z", workspace: { id: 1, name: "Qureshi Textiles", role: "admin" } });
    respond("server_page_open", null);
    await show("profile");
    expect(screen.getByText("Ayesha Siddiqui")).toBeInTheDocument();
    expect(screen.getByText("ayesha@qureshitextiles.pk")).toBeInTheDocument();
    expect(screen.getByText("Not verified")).toBeInTheDocument();
    expect(screen.getByText("Qureshi Textiles, Admin")).toBeInTheDocument();
    expect(screen.getByText(/2025/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Edit profile, opens in browser" }));
    await act(flushAsync);
    expect(callsOf("server_page_open")).toEqual([{ page: "app/profile", serverUrl: null }]);
  });

  it("one's own workspace isn't named, and a verified email has no mark", async () => {
    await show("profile");
    expect(screen.queryByText("Not verified")).toBeNull();
    expect(screen.queryByText("Workspace")).toBeNull();
  });
});

describe("Plan & usage v2", () => {
  const Sheet = () => {
    const sheet = useUpgradeSheet();
    return <output data-testid="sheet">{sheet ? `${sheet.plan ?? ""}|${sheet.step ?? ""}` : "closed"}</output>;
  };
  const showPlan = async (e: ReturnType<typeof sampleEntitlements>) => {
    resetEntitlementsForTests(e);
    respond("upgrade_request_latest", e.upgrade_request);
    render(<div className="el-root"><Sheet /><SettingsPagesV2 section={{ id: "plan", seq: 1 }} onHome={vi.fn()} run={run} status={makeStatus()} onUnpaired={vi.fn()} /></div>);
    await act(flushAsync);
  };

  it("Free: Upgrade opens the flow at Pro, Compare plans, the own key instead of minutes, and every feature in place", async () => {
    await showPlan(sampleEntitlements("free"));
    expect(screen.getByText("Free plan")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Add key" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Upgrade" }));
    expect(screen.getByTestId("sheet")).toHaveTextContent("pro|");
    expect(screen.getByTestId("plan-pro")).toHaveTextContent("Choose Pro");
    expect(screen.getByTestId("plan-ultra")).toHaveTextContent("Everything in Pro, plus:");
    fireEvent.click(screen.getByRole("button", { name: /^See every feature/ }));
    expect(screen.getByRole("table", { name: "Every feature" })).toBeInTheDocument();
    expect(within(screen.getByRole("table", { name: "Every feature" })).getAllByRole("columnheader").map((c) => c.textContent)).toEqual(["Feature", "Free (yours)", "Pro", "Ultra"]);
    expect(screen.getByRole("button", { name: /^See every feature/ })).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(screen.getByRole("button", { name: "Promo code" }));
    expect(screen.getByTestId("sheet")).toHaveTextContent("pro|choose");
  });

  it("Pro: minutes as a meter with their reset, amber from 80 % and red with what stops when storage is full", async () => {
    const e = sampleEntitlements("pro");
    e.limits.monthly_minutes = { limit: 600, used: 510, resets_at: "2026-12-01" };
    e.limits.storage_bytes = { limit: 21474836480, used: 21474836480 };
    await showPlan(e);
    expect(screen.getByText("Pro plan")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Upgrade to Ultra" })).toBeInTheDocument();
    expect(screen.getByRole("meter", { name: /^Transcription, 510 of 600 minutes used/ })).toHaveAttribute("aria-valuenow", "85");
    expect(screen.getByTestId("meter-monthly_minutes")).toHaveTextContent("You've used 85% of your minutes.");
    expect(screen.getByTestId("meter-storage_bytes")).toHaveTextContent("New recordings can't upload until you free up space or upgrade.");
    fireEvent.click(within(screen.getByTestId("meter-storage_bytes")).getByRole("button", { name: "upgrade" }));
    expect(screen.getByTestId("sheet")).toHaveTextContent("ultra|");
  });

  it("an open request: its card has the main button, no Upgrade anywhere, and Cancel request asks first", async () => {
    respondWith("upgrade_request_cancel", ({ id }) => makeUpgradeRequest("cancelled", { id: id as string }));
    respond("entitlements_get", sampleEntitlements("free"));
    const e = { ...sampleEntitlements("free"), upgrade_request: makeUpgradeRequest("awaiting_payment") };
    await showPlan(e);
    expect(screen.getByTestId("upgrade-request")).toHaveAttribute("data-stage", "awaiting_payment");
    fireEvent.click(screen.getByRole("button", { name: "Pro requested, Waiting for payment. Opens the request" }));
    expect(screen.getByTestId("sheet")).toHaveTextContent("|status");
    expect(screen.queryByRole("button", { name: "Upgrade" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Payment details" }));
    expect(screen.getByTestId("sheet")).toHaveTextContent("|pay");
    fireEvent.click(screen.getByRole("button", { name: "Cancel request" }));
    expect(screen.getByRole("alertdialog", { name: "Cancel this request?" })).toBeInTheDocument();
    fireEvent.click(within(screen.getByRole("alertdialog")).getByRole("button", { name: "Cancel request" }));
    await act(flushAsync);
    expect(callsOf("upgrade_request_cancel")).toEqual([{ id: e.upgrade_request!.id }]);
  });

  it("a cancelled request has no card, and Payments keeps it as Cancelled by you", async () => {
    await showPlan({ ...sampleEntitlements("free"), upgrade_request: makeUpgradeRequest("cancelled") });
    expect(screen.queryByTestId("upgrade-request")).toBeNull();
    expect(screen.getByRole("button", { name: "Upgrade" })).toBeInTheDocument();
    expect(screen.getByText("Cancelled by you")).toBeInTheDocument();
  });

  it("a server without plans says so on Plan & usage and Transcription key, and waits only while it hasn't answered", async () => {
    resetEntitlementsForTests(undefined);
    await show("plan");
    expect(screen.getByText("Loading settings")).toBeInTheDocument();
    expect(screen.queryByText("Your server doesn't have plans yet.")).toBeNull();
    act(() => resetEntitlementsForTests(null));
    expect(screen.getByText("Your server doesn't have plans yet.")).toBeInTheDocument();
    cleanup();
    await show("key");
    expect(screen.getByText("Your server doesn't have plans yet.")).toBeInTheDocument();
  });

  it("D-230: near the uploads a day the meter says uploads, never team members; a team with no plan known says who manages it", async () => {
    const e = sampleEntitlements("pro");
    e.limits.uploads_per_day = { limit: 200, used: 170 };
    await showPlan(e);
    expect(screen.getByTestId("meter-uploads_per_day")).toHaveTextContent("You've used 85% of your daily uploads.");
    expect(screen.getByTestId("meter-uploads_per_day")).not.toHaveTextContent("team members");
    cleanup();
    respond("account_get", { ...ACCOUNT, workspace: { id: 1, name: "Qureshi Textiles", role: "member" } });
    await showPlan({ ...sampleEntitlements("pro"), plan: null });
    expect(screen.getByText("Qureshi Textiles's plan is managed by its owner.")).toBeInTheDocument();
    expect(screen.queryByText(/is on Your/)).toBeNull();
  });

  it("a team's member: the plan's facts, who manages it, and no button", async () => {
    respond("account_get", { ...ACCOUNT, workspace: { id: 1, name: "Qureshi Textiles", role: "member" } });
    await showPlan(sampleEntitlements("pro"));
    expect(screen.getByText("Qureshi Textiles is on Pro. Its owner manages the plan.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Upgrade/ })).toBeNull();
    expect(screen.queryByText("Payments")).toBeNull();
  });
});

describe("Devices v2", () => {
  it("this computer first and said so, the others with when they were active; rename any, sign the others out after asking", async () => {
    respond("security_overview", { devices: DEVICES, events: [] });
    respond("device_rename", "Office laptop");
    respond("device_revoke", null);
    await show("devices");
    const rows = within(screen.getByRole("list", { name: "Signed-in devices" })).getAllByRole("listitem");
    expect(rows[0]).toHaveTextContent("Work laptop");
    expect(rows[0]).toHaveTextContent("This computer");
    expect(rows[1]).toHaveTextContent(/Android phone, active/);
    expect(screen.queryByRole("button", { name: "Sign out Work laptop" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Rename Work laptop" }));
    const dialog = screen.getByRole("dialog", { name: "Rename Work laptop" });
    expect(within(dialog).getByRole("button", { name: "Save" })).toBeDisabled();
    fireEvent.change(within(dialog).getByRole("textbox", { name: "Name" }), { target: { value: "  Office laptop " } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(callsOf("device_rename")).toEqual([{ id: "d1", name: "Office laptop" }]);
    expect(screen.queryByRole("dialog")).toBeNull();
    const asked = answerConfirm();
    asked.mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Sign out Ayesha's S25" }));
    await act(flushAsync);
    expect(asked.mock.calls[0][0]).toMatch(/^Sign out Ayesha's S25\? It will need to sign in again\./);
    expect(callsOf("device_revoke")).toEqual([{ id: "d2" }]);
  });
});

describe("About v2", () => {
  it("version, updates, this computer's name (opens Devices), the server, Technical details, settings export and import, policies", async () => {
    respond("security_overview", { devices: DEVICES, events: [] });
    respond("update_check", { ...UPDATE_IDLE, phase: "up_to_date", message: "EchoLedger is up to date." });
    respond("settings_export", "C:/Users/a/Downloads/echoledger-settings.json");
    respond("server_page_open", null);
    await show("about");
    expect(screen.getByText(/EchoLedger looks for updates when it starts and every 6 hours/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Check for updates" }));
    await act(flushAsync);
    expect(screen.getByText("EchoLedger is up to date.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Export settings" }));
    await act(flushAsync);
    expect(callsOf("settings_export")).toHaveLength(1);
    fireEvent.click(screen.getByRole("button", { name: "Terms, opens in browser" }));
    await act(flushAsync);
    expect(callsOf("server_page_open")).toEqual([{ page: "terms", serverUrl: null }]);
    fireEvent.click(screen.getByRole("button", { name: /^Device name/ }));
    expect(screen.getByRole("heading", { level: 1, name: "Devices" })).toBeInTheDocument();
  });

  it("Technical details: whether the connection is verified, and the server's whole address; back goes to About", async () => {
    render(<div className="el-root"><SettingsPagesV2 section={{ id: "about.technical", seq: 1 }} onHome={vi.fn()} run={run} onUnpaired={vi.fn()}
      status={makeStatus({ pinned: false, serverUrl: "https://app.echoledger.pk" })} /></div>);
    await act(flushAsync);
    expect(screen.getByText("Not verified")).toBeInTheDocument();
    expect(screen.getByText("https://app.echoledger.pk")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "About" }));
    expect(screen.getByRole("heading", { level: 1, name: "About" })).toBeInTheDocument();
  });

  it("imports a settings file and reads it back", async () => {
    respondWith("settings_import", ({ text }) => ({ ...SETTINGS, ...(JSON.parse(text as string) as Partial<Settings>) }));
    await show("about");
    const input = screen.getByLabelText("Import settings", { selector: "input" }) as HTMLInputElement;
    const file = new File([JSON.stringify({ farSideWarning: false })], "s.json", { type: "application/json" });
    Object.defineProperty(file, "text", { value: () => Promise.resolve(JSON.stringify({ farSideWarning: false })) });
    fireEvent.change(input, { target: { files: [file] } });
    await act(flushAsync);
    expect(callsOf("settings_import")).toEqual([{ text: JSON.stringify({ farSideWarning: false }) }]);
  });

  it("an update ready: Restart, its notes, and while recording it waits and says why", async () => {
    respond("update_status", { ...UPDATE_IDLE, phase: "ready", version: "2.0.1", notes: "Fixes for Live Translate." });
    render(<div className="el-root"><SettingsPagesV2 section={{ id: "about", seq: 1 }} onHome={vi.fn()} run={run} onUnpaired={vi.fn()}
      status={makeStatus({ recording: { clientRef: "r", mode: "call", sourceApp: "zoom", clientId: null, startedAt: "", backend: "mock" } })} /></div>);
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Restart" })).toBeDisabled();
    expect(screen.getByText("Fixes for Live Translate.")).toBeInTheDocument();
    expect(screen.getByText("Stop the recording first: EchoLedger restarts to install the update.")).toBeInTheDocument();
  });
});

describe("Mac check v2", () => {
  const mac = (over: Partial<AppStatus> = {}) => makeStatus({ platform: "macos", ...over });
  const showMac = async (status = mac()) => {
    const onSection = vi.fn();
    render(<div className="el-root"><SettingsPagesV2 section={{ id: "help.mac_check", seq: 1 }} onHome={vi.fn()} onSection={onSection} run={run} status={status} onUnpaired={vi.fn()} /></div>);
    await act(flushAsync);
    return onSection;
  };

  it("runs one check at a time with a progress line, then a mark, the state in words, the sentence and the fix per item", async () => {
    let finish: (r: CheckReport) => void = () => undefined;
    respondWith("mac_check_run", () => new Promise((resolve) => { finish = resolve; }));
    respond("open_privacy_settings", null);
    respond("quit_and_reopen", null);
    await showMac();
    fireEvent.click(screen.getByRole("button", { name: "Run Mac check" }));
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Checking…" })).toBeDisabled();
    await act(async () => emitEvent("mac_check:progress", { index: 4, total: 9, line: "Listening to the microphone for 5 seconds: say something…", done: [CHECK_OK] }));
    expect(screen.getByTestId("mac-check-progress")).toHaveTextContent("5 of 9: Listening to the microphone for 5 seconds: say something…");
    await act(async () => finish(macReport()));
    const results = screen.getByRole("list", { name: "Mac check results" });
    const rows = within(results).getAllByRole("listitem");
    expect(rows).toHaveLength(3);
    expect(rows[1]).toHaveAccessibleDescription(/^Off/);
    fireEvent.click(within(rows[1]).getByRole("button", { name: "Open System Settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "system_audio" }]);
    fireEvent.click(within(rows[2]).getByRole("button", { name: "Quit & reopen" }));
    await act(flushAsync);
    expect(callsOf("quit_and_reopen")).toHaveLength(1);
    expect(screen.getByRole("button", { name: "Run Mac check again" })).toBeInTheDocument();
  });

  it("copies and sends only when asked, says when it was sent, and why Send waits when signed out", async () => {
    respond("mac_check_last", macReport());
    respond("mac_check_copy", null);
    respond("mac_check_send", "2026-11-17T08:05:00Z");
    await showMac();
    expect(callsOf("mac_check_copy")).toHaveLength(0);
    fireEvent.click(screen.getByRole("button", { name: "Copy report" }));
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Copied" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Send to my admin" }));
    await act(flushAsync);
    expect(callsOf("mac_check_send")).toHaveLength(1);
    expect(screen.getByTestId("mac-check-sent")).toHaveTextContent(/^Sent to your admin/);
    cleanup();
    await showMac(mac({ paired: false }));
    expect(screen.getByRole("button", { name: "Send to my admin" })).toBeDisabled();
    expect(screen.getByTestId("mac-check-send-blocked")).toHaveTextContent("Sign in to send this to your admin.");
  });

  it("never during a recording; a recording that stopped it is said; a failure is shown; back goes to Help", async () => {
    await showMac(mac({ recording: { clientRef: "r", mode: "call", sourceApp: "zoom", clientId: null, startedAt: "", backend: "mock" } }));
    expect(screen.getByTestId("mac-check-recording")).toHaveTextContent("Finish the current recording to run the Mac check.");
    expect(screen.queryByRole("button", { name: /Run Mac check/ })).toBeNull();
    cleanup();
    respond("mac_check_run", macReport({ items: [CHECK_OK], stopped: "A recording started, so the check stopped." }));
    const onSection = await showMac();
    fireEvent.click(screen.getByRole("button", { name: "Run Mac check" }));
    await act(flushAsync);
    expect(screen.getByText("A recording started, so the check stopped.")).toBeInTheDocument();
    fail("mac_check_run", "The check couldn't start.");
    fireEvent.click(screen.getByRole("button", { name: "Run Mac check again" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("The check couldn't start.");
    fireEvent.click(screen.getByRole("button", { name: "Help" }));
    expect(onSection).toHaveBeenCalledWith("help");
  });

  it("isn't on Windows", async () => {
    await show("help.mac_check");
    expect(screen.queryByTestId("mac-check")).toBeNull();
  });
});

/**
 * Parity with today's pages: each page is rendered as today's and as the v2 page in each of its states (the page as it
 * opens, then each dialog and form opened, from a fresh page each time), and every control today's page shows in a
 * state must be on the v2 page in the same state. A question asked through the app's confirm (Reset to defaults,
 * Remove the key, Clear history) must be the same question on both. Settings that reveal more (the lock on, auto-record
 * off) are states of their own. Today's names that differ only in form map to the v2 control.
 */
describe("parity with today's Settings pages, in every state", () => {
  const patch = async () => true;
  type Act = () => Promise<void>;
  type Step = { name: string; today: Act; v2?: Act };
  type Page = {
    name: string; section: string; platform?: string; settings?: Partial<Settings>; entitlements?: ReturnType<typeof sampleEntitlements>; account?: Account;
    lock?: { hasPin: boolean }; status?: Partial<AppStatus>; devices?: boolean; update?: object; macLast?: CheckReport | null; permissions?: Partial<PermissionsStatus>; screens?: Partial<ScreensStatus>;
    today: (settings: Settings) => ReactElement; stalesOk?: boolean; steps?: Step[]; map?: Record<string, [ControlRole | RolelessField, string | RegExp]>;
    /** Lines the page may say (its footer's): in every state, the ones today's page shows must be the ones the v2 page shows. */
    lines?: readonly string[];
    /** Today's controls the v2 page rightly lacks, each with why (the other side of a switch, tested elsewhere). */
    otherSide?: Record<string, string>;
    /** Today's controls whose names carry data (a date, an amount), matched by pattern. */
    mapLike?: readonly [RegExp, [ControlRole, string | RegExp]][];
  };
  const press = (name: string | RegExp, role: ControlRole = "button"): Act => async () => {
    fireEvent.click(screen.getAllByRole(role, { name })[0]);
    await act(flushAsync);
  };
  const type = (label: string, value: string): Act => async () => {
    const field = screen.getByLabelText(label);
    fireEvent.change(field, { target: { value } });
    await act(flushAsync);
  };
  const both = (...acts: Act[]): Act => async () => {
    for (const a of acts) await a();
  };
  const reset: Step = { name: "Reset to defaults", today: press("Reset to defaults") };
  const PLAN_STATES = ((): [string, ReturnType<typeof sampleEntitlements>, string | null][] => {
    const now = new Date().toISOString();
    const at = (plan: "free" | "pro", stage: Parameters<typeof makeUpgradeRequest>[0]) =>
      ({ ...sampleEntitlements(plan), upgrade_request: makeUpgradeRequest(stage, stage === "approved" || stage === "declined" ? { decided_at: now } : {}) });
    const busy = sampleEntitlements("pro");
    busy.limits.monthly_minutes = { limit: 600, used: 510, resets_at: "2026-12-01" };
    busy.limits.storage_bytes = { limit: 21474836480, used: 21474836480 };
    busy.limits.devices = { limit: 5, used: 5 };
    busy.limits.uploads_per_day = { limit: 200, used: 190 };
    const unlimited = sampleEntitlements("ultra");
    unlimited.limits.monthly_minutes = { limit: null, used: 1200 };
    const keyed = sampleEntitlements("free");
    keyed.transcription_key = { provider: "groq", set: true, last4: "9xQ2" };
    return [
      ["Free", sampleEntitlements("free"), null], ["Free, key added", keyed, null], ["Pro", sampleEntitlements("pro"), null], ["Ultra, the top plan", sampleEntitlements("ultra"), null],
      ["Pro, near and at its limits", busy, null], ["Ultra, minutes without a limit", unlimited, null],
      ["Pro, a team's member", sampleEntitlements("pro"), "member"], ["Pro, a team's admin", sampleEntitlements("pro"), "admin"],
      ["Free, request waiting for payment", at("free", "awaiting_payment"), null], ["Free, request in review", at("free", "in_review"), null],
      ["Free, another receipt asked for", at("free", "needs_receipt"), null], ["Free, request not approved", at("free", "declined"), null],
      ["Pro, request approved", at("pro", "approved"), null],
      ["Free, request cancelled", at("free", "cancelled"), null],
    ];
  })();
  const PLAN_LINES = [
    "Free plan", "Pro plan", "Ultra plan", "Transcripts use your own free Groq key.", "Qureshi Textiles is on Pro. Its owner manages the plan.", "You're on our top plan.",
    "Paid until", "No end date", "There is nothing to cancel.", "Minutes reset on the 1st of each month. Unused minutes don't carry over.",
    "Every plan can record, organise, search, share links and export.", "Your Groq key", "Key added", "Not added",
    "No monthly limit", "You've used 85% of your minutes.", "New recordings can't upload until you free up space", "Sign out a device to add another.", "You've used 95% of your",
    "Everything in Pro, plus:", "Payments are by bank transfer. We switch your plan on after we see the money in our account.",
    "Waiting for payment", "In review", "Action needed", "Not approved", "Approved", "to our bank account, then add a screenshot of the receipt.",
    "Your request is still open. You don't need to pay again.", "If you've paid, reply to our email with your transaction ID and we'll look again.",
    "Cancelled by you", "If you've already paid, add your receipt instead. Cancelling doesn't refund a payment.", "Uploads in the last 24 hours", "(yours)", "Shown below",
  ];
  const SCREENS_OK: ScreensStatus = { supported: true, platform: "windows", borderless: false, permission: true, inPlan: true, onServer: true };
  const SCREEN_LINES = [
    screensBlocked({ ...SCREENS_OK, onServer: false })!, screensBlocked({ ...SCREENS_OK, inPlan: false })!, screensBlocked({ ...SCREENS_OK, supported: false })!,
    setting("screen.enabled").description!,
    TARGET_HINT.meeting_window, TARGET_HINT.whole_screen + WINDOWS_WHOLE_SCREEN, TARGET_HINT.whole_screen,
    "Windows 11 captures without the yellow border. If it still shows, allow borderless capture for EchoLedger in Windows Settings.",
    "Windows 10 draws a yellow border around the meeting window while screens are on. Windows 11 captures without it.",
    MONTHLY_NOTE, `macOS asks once for the Screen Recording permission. ${MONTHLY_NOTE}`,
    "Turn on EchoLedger in System Settings › Privacy & Security › Screen & System Audio Recording, then open EchoLedger again.",
  ];
  const withKey = () => { const e = sampleEntitlements("free"); e.transcription_key = { provider: "groq", set: true, last4: "9xQ2" }; return e; };
  const PAGES: Page[] = [
    { name: "Voice notes & dictation", section: "voice", today: (st) => <VoicePage settings={st} patch={patch} onOpen={() => undefined} />, steps: [reset],
      map: { "combobox: How the shortcut works": ["combobox", /^How the shortcut works/], "combobox: Dictation output": ["combobox", /^Dictation output/],
        "button: Replacements1": ["button", "Replacements"], "button: Snippets1": ["button", "Snippets"] } },
    { name: "Replacements", section: "voice.replacements", today: (st) => <ReplacementsPage settings={st} patch={patch} />,
      steps: [{ name: "a replacement typed", today: both(type("When I say", "kuraishi"), type("Write", "Qureshi")) }] },
    { name: "Snippets", section: "voice.snippets", today: (st) => <SnippetsPage settings={st} patch={patch} />,
      steps: [{ name: "a snippet typed", today: both(type("Phrase", "bank"), type("Text to insert", "Meezan Bank")) }] },
    { name: "Recent dictations", section: "voice.history", today: () => <HistoryPage />, map: { "button: Copy": ["button", /^Copy/] },
      steps: [{ name: "Clear history", today: press("Clear history") }] },
    { name: "Captions", section: "captions", today: (st) => <CaptionsPage settings={st} patch={patch} onStyle={() => undefined} />, steps: [reset] },
    { name: "Style", section: "captions.style", today: (st) => <CaptionStylePage settings={st} patch={patch} />, steps: [reset],
      map: { "combobox: Position": ["combobox", /^Position/], "combobox: Hide when nobody speaks": ["combobox", /^Hide when nobody speaks/] } },
    ...(["windows", "macos"] as const).flatMap((platform) => [true, false].map((autoMode): Page => ({
      name: `Calls & meetings, ${platform}, auto-record ${autoMode ? "on" : "off"}`, section: "calls", platform, settings: { autoMode },
      today: (st) => <CallsPage settings={st} patch={patch} platform={platform} onSites={() => undefined} consentRead={false} onConsent={() => undefined} />, steps: [reset],
      map: { "combobox: Skip calls shorter than": ["combobox", /^Skip calls shorter than/], "button: Call sites0 rules": ["button", "Call sites"] },
    }))),
    { name: "Call sites", section: "calls.sites", today: (st) => <CallSitesPage rules={st.siteRules} onSave={async () => true} />, map: { "combobox: App": ["combobox", /^App/] },
      steps: [{ name: "a site typed", today: type("Address or title contains", "meet.jit.si") }] },
    { name: "Language", section: "transcription.language", today: (st) => <LanguagePage settings={st} patch={patch} />, steps: [reset], map: { "combobox: Language": ["combobox", /^Language/] } },
    { name: "Custom words", section: "words", today: (st) => <WordsPage settings={st} patch={patch} />, steps: [reset], map: { "combobox: Use terms from project": ["combobox", /^Use terms from project/] } },
    { name: "Transcription key", section: "key", today: () => <TranscriptionKeyPage e={sampleEntitlements("free")} />,
      steps: [{ name: "a key typed", today: type("Groq key", "gsk_abcdefghijklmnopqrstuvwxyz") }] },
    { name: "Transcription key, saved", section: "key", entitlements: withKey(), today: () => <TranscriptionKeyPage e={withKey()} />,
      steps: [{ name: "Remove key", today: press("Remove key") }] },
    { name: "Privacy & data", section: "privacy", today: (st) => <PrivacyPage account={ACCOUNT} settings={st} onAccount={() => undefined} onConsent={() => undefined} />,
      map: { "button: Recording consentNot yet": ["button", "Recording consent"] },
      steps: [
        { name: "Delete my account, with the password", today: press("Delete my account") },
        { name: "Delete my account, with a two-step code", today: both(press("Delete my account"), press("Use a two-step code instead")) },
      ] },
    { name: "Privacy & data, deletion pending", section: "privacy", account: { ...ACCOUNT, deletes_at: "2026-11-24T10:00:00Z" },
      today: (st) => <PrivacyPage account={{ ...ACCOUNT, deletes_at: "2026-11-24T10:00:00Z" }} settings={st} onAccount={() => undefined} onConsent={() => undefined} />,
      map: { "button: Recording consentNot yet": ["button", "Recording consent"], "button: Cancel deletion, opens in browser": ["button", "Cancel deletion, opens in browser"] } },
    { name: "Recording consent", section: "privacy.consent", today: (st) => <ConsentPage status={makeStatus()} settings={st} patch={patch} /> },
    ...[false, true].flatMap((appLock) => [false, true].map((hasPin): Page => ({
      name: `App lock ${appLock ? "on" : "off"}, PIN ${hasPin ? "set" : "not set"}`, section: "lock", settings: { appLock }, lock: { hasPin },
      today: (st) => <LockPage settings={st} patch={patch} run={run} />,
      steps: [{ name: hasPin ? "Change PIN" : "Set PIN", today: press(/^PIN/), v2: press("PIN") }, reset],
      map: { "combobox: Lock after": ["combobox", /^Lock after/], [`button: PIN${hasPin ? "Set" : "Not set"}`]: ["button", "PIN"] },
    }))),
    ...[true, false].flatMap((activityNotifications) => [true, false].map((quietHours): Page => ({
      name: `Notifications ${activityNotifications ? "on" : "off"}, quiet hours ${quietHours ? "on" : "off"}`, section: "notifications",
      settings: { activityNotifications, quietHours }, today: (st) => <NotificationsPage settings={st} patch={patch} run={run} />, steps: [reset],
      map: { "time: From": ["time", "Quiet hours from"], "time: To": ["time", "Quiet hours to"] },
    }))),
    ...([["windows", "allowed", false], ["macos", "denied", false], ["windows", "allowed", true]] as const).map(([platform, perm, recording]): Page => ({
      name: `Microphone & audio, ${platform}, permission ${perm}${recording ? ", while recording" : ""}`, section: "mic", platform,
      status: recording ? { recording: { clientRef: "r", mode: "call", sourceApp: "zoom", clientId: null, startedAt: "", backend: "mock" } } : {}, permissions: { platform, microphone: perm, systemAudio: perm },
      today: (st) => <MicrophonePage settings={st} patch={patch} disabled={recording} />,
      steps: recording ? [reset] : [{ name: "Test 10 s", today: press("Test 10 s") }, reset],
      map: { "combobox: Microphone": ["combobox", /^Microphone/] },
    })),
    ...([
      ["Windows, on", "windows", { platform: "windows", permission: true }, {}, "pro"],
      ["Windows 11, on, whole screen", "windows", { platform: "windows", permission: true, borderless: true }, { screenTarget: "whole_screen" }, "pro"],
      ["Windows, off", "windows", { platform: "windows", permission: true }, { screenMoments: false }, "pro"],
      ["Mac, permission not given", "macos", { platform: "macos", permission: false }, {}, "pro"],
      ["not in the plan", "windows", { platform: "windows", permission: true }, {}, "free"],
      ["server without it", "windows", { platform: "windows", permission: true, onServer: false }, {}, "pro"],
      ["the server says not in the plan, the plan has it", "windows", { platform: "windows", permission: true, inPlan: false }, {}, "pro"],
      ["this computer can't capture", "windows", { platform: "windows", permission: true, supported: false }, {}, "pro"],
      ["Mac, allowed", "macos", { platform: "macos", permission: true }, {}, "pro"],
    ] as const).map(([label, platform, screens, settings, plan]): Page => ({
      name: `Screen moments, ${label}`, section: "screen", platform, screens, settings, entitlements: sampleEntitlements(plan),
      today: (st) => <ScreenMomentsPage settings={st} patch={patch} onExcluded={() => undefined} />,
      steps: [
        ...(screens.permission === false ? [{ name: "Allow Screen Recording, refused", today: press("Allow Screen Recording") }] : []),
        reset,
      ],
      map: { "combobox: Capture": ["combobox", /^Capture/], "button: Never capture these apps0 apps": ["button", "Never capture these apps"],
        "button: Never capture these apps3 apps": ["button", "Never capture these apps"], "button: Keep screen moments, in Pro": ["button", "Keep screen moments, in Pro"] },
      stalesOk: true, lines: SCREEN_LINES,
    })),
    { name: "Never capture these apps", section: "screen.excluded", today: (st) => <ExcludedAppsPage settings={st} patch={patch} platform="windows" />,
      steps: [{ name: "an app typed", today: type("App or process name", "Signal.exe") }] },
    { name: "Appearance", section: "appearance",
      today: () => <AppearancePage theme="system" onTheme={() => undefined} prefs={{ sidebarStyle: "light", density: "comfortable", clock: "12", compactRail: false }} setPref={() => undefined} />, steps: [reset],
      otherSide: { "button: Try the new design": "classic's side of the design switch; the new design's Appearance has Switch back to classic (v2/designSwitch.test.tsx)" } },
    ...(["windows", "macos"] as const).map((platform): Page => ({
      name: `Keyboard shortcuts, ${platform}`, section: "shortcuts", platform,
      today: (st) => <ShortcutsPage settings={st} status={makeStatus({ platform })} run={run} onSettings={() => undefined} />,
      steps: [{ name: "Change, listening", today: press("Change Record now") }, reset],
    })),
    ...([[false, null], [true, null], [true, { id: 1, name: "Qureshi Textiles", role: "admin" }]] as const).map(([unverified, workspace]): Page => {
      const account: Account = { ...ACCOUNT, email_verified: !unverified, created_at: "2025-03-04T10:00:00Z", workspace: workspace && { ...workspace } };
      return { name: `Profile${unverified ? ", email not verified" : ""}${workspace ? ", on a team" : ""}`, section: "profile", account,
        today: () => <ProfilePage account={account} status={makeStatus()} run={run} />,
        map: { "button: Edit profile, opens in browser": ["button", "Edit profile, opens in browser"] },
        lines: ["Ayesha Siddiqui", "ayesha@qureshitextiles.pk", "Qureshi Textiles, Admin", "Your name and email are changed on the website."] };
    }),
    ...PLAN_STATES.map(([label, e, role]): Page => ({
      name: `Plan & usage, ${label}`, section: "plan", entitlements: e,
      account: role ? { ...ACCOUNT, workspace: { id: 1, name: "Qureshi Textiles", role } } : ACCOUNT,
      today: () => <PlanUsagePage e={e} role={role} workspace={role ? "Qureshi Textiles" : null} onKey={() => undefined} />,
      steps: [
        { name: "every feature shown", today: press(/^See every feature/) },
        ...(e.upgrade_request?.status === "awaiting_payment" ? [{ name: "Cancel request, asked", today: press("Cancel request") }] : []),
      ],
      lines: PLAN_LINES, stalesOk: true,
      map: { "button: See every featureShown below": ["button", /^See every feature/], "button: See every feature": ["button", /^See every feature/] },
      mapLike: [[/^button: Promo code/, ["button", "Promo code"]], [/^button: (Free|Pro|Ultra), 1 month/, ["button", /^(Free|Pro|Ultra), 1 month$/]]],
    })),
    { name: "Devices", section: "devices", today: () => <DevicesPage run={run} />, devices: true,
      steps: [{ name: "Rename, the dialog", today: press("Rename Work laptop") }, { name: "Sign out, asked", today: press("Sign out Ayesha's S25") }] },
    ...([["idle", {}, false], ["update ready", { phase: "ready", version: "2.0.1", notes: "Fixes." }, false], ["update ready, while recording", { phase: "ready", version: "2.0.1", notes: null }, true],
      ["check failed", { phase: "failed", message: "The server didn't answer." }, false]] as const).map(([label, update, recording]): Page => ({
      name: `About, ${label}`, section: "about", update, status: recording ? { recording: { clientRef: "r", mode: "call", sourceApp: "zoom", clientId: null, startedAt: "", backend: "mock" } } : {},
      today: () => <AboutPage status={makeStatus(recording ? { recording: { clientRef: "r", mode: "call", sourceApp: "zoom", clientId: null, startedAt: "", backend: "mock" } } : {})} run={run} onImported={() => undefined} onDevices={() => undefined} onTechnical={() => undefined} />,
      map: { "button: Device nameWork laptop": ["button", /^Device name/], "button: Privacy policy, opens in browser": ["button", "Privacy policy, opens in browser"],
        "button: Terms, opens in browser": ["button", "Terms, opens in browser"] },
      stalesOk: true,
      lines: ["EchoLedger looks for updates when it starts and every 6 hours.", "Update ready — EchoLedger 2.0.1.", "Fixes.", "Stop the recording first: EchoLedger restarts to install the update.",
        "The server didn't answer.", "Last checked", "The file has your preferences only, never the sign-in, keys or PIN.", "Work laptop"],
    })),
    ...([["no report yet", null, {}], ["the last report", macReport(), {}], ["the last report, sent", macReport({ sentAt: "2026-11-17T08:05:00Z" }), {}],
      // While recording today's page has no controls at all; its sentence is checked by the tests above.
      ["the last report, signed out", macReport(), { paired: false }]] as const).map(([label, last, status]): Page => ({
      name: `Mac check, ${label}`, section: "help.mac_check", platform: "macos", status, macLast: last,
      today: () => <MacCheckCard status={makeStatus({ platform: "macos", ...status })} />,
      lines: ["The Mac check looks at macOS", "Finish the current recording to run the Mac check.", "Sign in to send this to your admin.", "Sent to your admin",
        "The report has no names, no recordings and no account. It is sent only when you press the button.", CHECK_OFF.detail, CHECK_REOPEN.detail],
    })),
    { name: "Sign-in & security", section: "security", today: () => <SecurityPage account={ACCOUNT} run={run} onSignedOut={() => undefined} onActivity={() => undefined} />,
      map: { "button: Two-step sign-in, opens in browser": ["button", "Two-step sign-in, opens in browser"] },
      steps: [{ name: "Sign out everywhere", today: press("Sign out everywhere") }] },
    // Since 2.0.0-l the account is changed in the app: the same rows and the same dialogs in both designs.
    { name: "Profile, changed in the app", section: "profile", account: NATIVE,
      today: () => <ProfilePage account={NATIVE} status={makeStatus()} run={run} />,
      // UX-11 (lead decision 10): with the profile changed in the app, the new look has no way out to the website's.
      otherSide: { "button: Edit profile, opens in browser": "UX-11: the profile is edited in the app; the website is only for billing" },
      steps: [{ name: "Change name", today: press("Change name") }, { name: "Change email", today: press("Change email") }, { name: "Remove picture", today: press("Remove picture") }],
      lines: ["Your teammates see your name and picture.", "This is the name your teammates see.", "Your initials show instead, everywhere."] },
    { name: "Sign-in & security, changed in the app", section: "security", account: NATIVE,
      today: () => <SecurityPage account={NATIVE} run={run} onSignedOut={() => undefined} onActivity={() => undefined} />,
      steps: [{ name: "Change password", today: press("Change password") }, { name: "Turn off two-step", today: press("Turn off two-step sign-in") },
        { name: "New recovery codes", today: press("New recovery codes") }, { name: "Remove a passkey", today: press("Remove passkey Phone") }],
      lines: ["Passkeys sign you in with your phone, fingerprint or face. Add one on the website.", "This computer stays signed in.", "Your old recovery codes stop working."] },
  ];

  /** The controls on screen and the confirm questions asked, after `step` (none: the page as it opens). */
  const capture = async (render_: () => void, step: Act | null): Promise<{ controls: string[]; asked: string[]; off: string[]; text: string }> => {
    const asked = answerConfirm();
    asked.mockReturnValue(false);
    render_();
    await act(flushAsync);
    if (step) await step();
    const controls = [...collectControls(new Set())];
    const off = disabledSwitches();
    const text = (document.body.textContent ?? "").replace(/\s+/g, " ");
    cleanup();
    return { controls, asked: asked.mock.calls.map((c) => c[0]), off, text };
  };

  it.each(PAGES.map((p) => [p.name, p] as const))("%s", async (_, page) => {
    const settings = { ...SETTINGS, ...page.settings } as Settings;
    const states: [string, Step | null][] = [["as it opens", null], ...(page.steps ?? []).map((st) => [st.name, st] as [string, Step])];
    const seen = new Set<string>();
    for (const [state, step] of states) {
      respond("get_settings", settings);
      respond("lock_status", { enabled: !!page.settings?.appLock, locked: false, hasPin: page.lock?.hasPin ?? false, osMethod: "windows_hello", retryAfterSeconds: 0 });
      resetEntitlementsForTests(page.entitlements ?? sampleEntitlements("free"));
      respond("account_get", page.account ?? ACCOUNT);
      respond("account_call", { ok: true, status: 200, data: NATIVE_SECURITY, code: null, message: null, accepts: [], errorCodes: null, errors: null, retryAfter: null, qr: null });
      respond("account_avatar_image", null);
      respond("auth_options", null);
      respond("security_overview", { devices: page.devices ? DEVICES : [], events: [{ id: 1, event: "sign_in", label: "Signed in", summary: "Signed in", actor: "device", device: "Work laptop", at: "2026-11-17T08:00:00Z" }] });
      respond("update_status", { ...UPDATE_IDLE, ...page.update });
      respond("mac_check_last", page.macLast ?? null);
      respond("permissions_status", { microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "windows", ...page.permissions });
      respond("screens_status", { supported: true, platform: "windows", borderless: false, permission: true, inPlan: true, onServer: true, ...page.screens });
      const today = await capture(() => render(page.today(settings)), step?.today ?? null);
      expect(today.controls.length, state).toBeGreaterThan(0);
      today.controls.forEach((c) => seen.add(c));
      const v2 = await capture(() => {
        render(<div className="el-root"><SettingsPagesV2 section={{ id: page.section, seq: 1 }} onHome={vi.fn()} run={run}
          status={makeStatus({ platform: (page.platform ?? "windows") as "windows", ...page.status })} onUnpaired={vi.fn()} /></div>);
      }, step ? step.v2 ?? step.today : null);
      const v2Has = (control: string) => {
        const [role, name] = page.map?.[control] ?? page.mapLike?.find(([re]) => re.test(control))?.[1]
          ?? [control.split(": ")[0] as ControlRole | RolelessField, control.slice(control.indexOf(": ") + 2)];
        return v2.controls.some((c) => {
          const [r, n] = [c.split(": ")[0], c.slice(c.indexOf(": ") + 2)];
          return r === role && (typeof name === "string" ? n === name : name.test(n));
        });
      };
      expect(today.controls.filter((c) => !v2Has(c) && !page.otherSide?.[c]), `${page.name}: ${state}`).toEqual([]);
      expect(v2.asked, `${page.name}: ${state}, the question asked`).toEqual(today.asked);
      expect(today.off.filter((c) => !v2.off.includes(c)), `${page.name}: ${state}, switches that can't be changed`).toEqual([]);
      if (page.lines) {
        const said = (text: string) => page.lines!.filter((l) => text.includes(l.replace(/\s+/g, " ")));
        expect(said(v2.text), `${page.name}: ${state}, what it says`).toEqual(said(today.text));
        expect(said(today.text).length, `${page.name}: ${state}, says something`).toBeGreaterThan(0);
      }
    }
    // A mapping shared by several states of a page only has to match in one of them.
    if (!page.stalesOk) expect(stale([...seen], Object.keys(page.map ?? {}))).toEqual([]);
  });
});

describe("the app with ui_v2.settings", () => {
  it("Settings › Voice notes & dictation opens the v2 page, and its way back returns to Settings", async () => {
    respond("app_status", makeStatus({ platform: "windows" }));
    respond("library_list", [makeItem("a")]);
    respond("library_refresh", [makeItem("a")]);
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("account_get", null);
    respond("storage_info", null);
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.settings": true } });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "Settings" }));
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(await screen.findByRole("button", { name: "Voice notes & dictation" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Voice notes & dictation" })).toBeInTheDocument();
    expect(document.querySelector("[data-v2=settings-page]")).not.toBeNull();
    fireEvent.click(document.querySelector("[data-v2=settings-page] > .el-page__back")!);
    expect(await screen.findByRole("heading", { level: 1, name: "Settings" })).toBeInTheDocument();
  });
});
