import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { ReactElement } from "react";
import type { Settings, SettingsPatch } from "../../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../../test/tauri";
import { answerConfirm } from "../../../test/confirm";
import { collectControls, findControls, stale, type ControlRole } from "../../../test/parity";
import { makeItem, makeMicUser, makeSettings, makeStatus, sampleEntitlements } from "../../../test/fixtures";
import { resetEntitlementsForTests } from "../../../lib/entitlements";
import { LanguagePage, WordsPage } from "../../../views/main/AppSettings";
import { TranscriptionKeyPage } from "../../../views/main/Upgrade";
import { ConsentPage, PrivacyPage } from "../../../views/main/AccountSettings";
import type { Account } from "../../../lib/ipc";
import MainApp from "../../../views/main/MainApp";
import { CallSitesPage, CallsPage, CaptionStylePage, CaptionsPage } from "../../../views/main/CaptureSettings";
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
const HISTORY = [{ at: "2026-11-17T08:00:00Z", text: "Send the proforma today." }];
const run = <T,>(fn: () => Promise<T>) => fn().catch(() => undefined);

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
});

const show = async (section: string, platform = "windows") => {
  const onHome = vi.fn();
  render(<div className="el-root"><SettingsPagesV2 section={{ id: section, seq: 1 }} onHome={onHome} run={run} status={makeStatus({ platform: platform as "windows" })} /></div>);
  await act(flushAsync);
  return onHome;
};
const patches = () => callsOf("update_settings").map((c) => (c as { patch: SettingsPatch }).patch);

describe("which sections open a v2 page", () => {
  it("Dictation and Captions pages and their settings, by page, setting or v1.6 name", () => {
    expect(sectionTarget("voice")).toEqual({ page: "voice", setting: null });
    expect(sectionTarget("voice#voice.cleanup")).toEqual({ page: "voice", setting: "voice.cleanup" });
    expect(sectionTarget("captions.opacity")).toEqual({ page: "captions.style", setting: "captions.opacity" });
    expect(hasV2Page("voice.history")).toBe(true);
    expect(hasV2Page("captions")).toBe(true);
    expect(hasV2Page("calls")).toBe(true);
    expect(hasV2Page("lock")).toBe(false);
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

/**
 * Parity with today's pages: every control each page shows (rendered, with the same settings) is on its v2 page.
 * Today's names that differ only in form map to the v2 control by role.
 */
describe("parity with today's Dictation and Captions pages", () => {
  const patch = async () => true;
  type Page = { name: string; section: string; platform?: string; entitlements?: ReturnType<typeof sampleEntitlements>; account?: Account; today: () => ReactElement; map?: Record<string, [ControlRole | "password", string | RegExp]> };
  const PAGES: Page[] = [
    { name: "Voice notes & dictation", section: "voice", today: () => <VoicePage settings={SETTINGS} patch={patch} onOpen={() => undefined} />,
      map: { "combobox: How the shortcut works": ["combobox", /^How the shortcut works/], "combobox: Dictation output": ["combobox", /^Dictation output/],
        "button: Replacements1": ["button", "Replacements"], "button: Snippets1": ["button", "Snippets"] } },
    { name: "Replacements", section: "voice.replacements", today: () => <ReplacementsPage settings={SETTINGS} patch={patch} /> },
    { name: "Snippets", section: "voice.snippets", today: () => <SnippetsPage settings={SETTINGS} patch={patch} /> },
    { name: "Recent dictations", section: "voice.history", today: () => <HistoryPage />, map: { "button: Copy": ["button", /^Copy/] } },
    { name: "Captions", section: "captions", today: () => <CaptionsPage settings={SETTINGS} patch={patch} onStyle={() => undefined} /> },
    { name: "Calls & meetings", section: "calls", today: () => <CallsPage settings={SETTINGS} patch={patch} platform="windows" onSites={() => undefined} consentRead={false} onConsent={() => undefined} />,
      map: { "combobox: After a call": ["combobox", /^After a call/], "combobox: Skip calls shorter than": ["combobox", /^Skip calls shorter than/], "button: Call sites0 rules": ["button", "Call sites"] } },
    { name: "Calls & meetings on a Mac", section: "calls", platform: "macos", today: () => <CallsPage settings={SETTINGS} patch={patch} platform="macos" onSites={() => undefined} consentRead={false} onConsent={() => undefined} />,
      map: { "combobox: After a call": ["combobox", /^After a call/], "combobox: Skip calls shorter than": ["combobox", /^Skip calls shorter than/], "button: Call sites0 rules": ["button", "Call sites"] } },
    { name: "Call sites", section: "calls.sites", today: () => <CallSitesPage rules={SETTINGS.siteRules} onSave={async () => true} />, map: { "combobox: App": ["combobox", /^App/] } },
    { name: "Language", section: "transcription.language", today: () => <LanguagePage settings={SETTINGS} patch={patch} />, map: { "combobox: Language": ["combobox", /^Language/] } },
    { name: "Custom words", section: "words", today: () => <WordsPage settings={SETTINGS} patch={patch} />, map: { "combobox: Use terms from project": ["combobox", /^Use terms from project/] } },
    { name: "Transcription key", section: "key", today: () => <TranscriptionKeyPage e={sampleEntitlements("free")} /> },
    { name: "Transcription key, saved", section: "key", entitlements: (() => { const e = sampleEntitlements("free"); e.transcription_key = { provider: "groq", set: true, last4: "9xQ2" }; return e; })(), today: () => <TranscriptionKeyPage e={(() => { const e = sampleEntitlements("free"); e.transcription_key = { provider: "groq", set: true, last4: "9xQ2" }; return e; })()} /> },
    { name: "Privacy & data", section: "privacy", today: () => <PrivacyPage account={ACCOUNT} settings={SETTINGS} onAccount={() => undefined} onConsent={() => undefined} />,
      map: { "button: Recording consentNot yet": ["button", "Recording consent"] } },
    { name: "Privacy & data, deletion pending", section: "privacy", account: { ...ACCOUNT, deletes_at: "2026-11-24T10:00:00Z" },
      today: () => <PrivacyPage account={{ ...ACCOUNT, deletes_at: "2026-11-24T10:00:00Z" }} settings={SETTINGS} onAccount={() => undefined} onConsent={() => undefined} />,
      map: { "button: Recording consentNot yet": ["button", "Recording consent"], "button: Cancel deletion, opens in browser": ["button", "Cancel deletion, opens in browser"] } },
    { name: "Recording consent", section: "privacy.consent", today: () => <ConsentPage status={makeStatus()} settings={SETTINGS} patch={patch} /> },
    { name: "Style", section: "captions.style", today: () => <CaptionStylePage settings={SETTINGS} patch={patch} />,
      map: { "combobox: Position": ["combobox", /^Position/], "combobox: Hide when nobody speaks": ["combobox", /^Hide when nobody speaks/] } },
  ];

  it.each(PAGES.map((p) => [p.name, p] as const))("%s: every control today's page has is on the v2 page", async (_, page) => {
    const first = render(page.today());
    await act(flushAsync);
    const today = [...collectControls(new Set())];
    first.unmount();
    expect(today.length).toBeGreaterThan(0);
    if (page.entitlements) resetEntitlementsForTests(page.entitlements);
    if (page.account) respond("account_get", page.account);
    await show(page.section, page.platform);
    const missing = today.filter((control) => {
      const mapped = page.map?.[control];
      const [role, name] = mapped ?? [control.split(": ")[0] as ControlRole | "password", control.slice(control.indexOf(": ") + 2)];
      return findControls(role, name).length === 0;
    });
    expect(missing).toEqual([]);
    expect(stale(today, Object.keys(page.map ?? {}))).toEqual([]);
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
