import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { ReactElement } from "react";
import type { Settings, SettingsPatch } from "../../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../../test/tauri";
import { answerConfirm } from "../../../test/confirm";
import { collectControls, stale, unmapped, type ControlRole } from "../../../test/parity";
import { makeItem, makeSettings, makeStatus } from "../../../test/fixtures";
import MainApp from "../../../views/main/MainApp";
import { CaptionStylePage, CaptionsPage } from "../../../views/main/CaptureSettings";
import { HistoryPage, ReplacementsPage, SnippetsPage, VoicePage } from "../../../views/main/DictationSettings";
import { SettingsPagesV2 } from "./SettingsPagesV2";
import { hasV2Page, sectionTarget } from "./route";

vi.mock("@tauri-apps/api/core", async () => (await import("../../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../../test/tauri")).eventModule);

const SETTINGS = makeSettings({
  dictationReplacements: [{ from: "echo ledger", to: "EchoLedger" }],
  dictationSnippets: [{ trigger: "insert signature", text: "Regards,\nAyesha" }],
});
const HISTORY = [{ at: "2026-11-17T08:00:00Z", text: "Send the proforma today." }];
const run = <T,>(fn: () => Promise<T>) => fn().catch(() => undefined);

beforeEach(() => {
  resetTauriMock();
  respond("get_settings", SETTINGS);
  respondWith("update_settings", ({ patch }) => ({ ...SETTINGS, ...(patch as SettingsPatch) }));
  respond("dictation_history", HISTORY);
  respond("dictation_history_clear", null);
});

const show = async (section: string) => {
  const onHome = vi.fn();
  render(<div className="el-root"><SettingsPagesV2 section={{ id: section, seq: 1 }} onHome={onHome} run={run} /></div>);
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
    expect(hasV2Page("calls")).toBe(false);
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

/**
 * Parity with today's pages: every control each page shows (rendered, with the same settings) is on its v2 page.
 * Today's names that differ only in form map to the v2 control by role.
 */
describe("parity with today's Dictation and Captions pages", () => {
  const patch = async () => true;
  type Page = { name: string; section: string; today: () => ReactElement; map?: Record<string, [ControlRole, string | RegExp]> };
  const PAGES: Page[] = [
    { name: "Voice notes & dictation", section: "voice", today: () => <VoicePage settings={SETTINGS} patch={patch} onOpen={() => undefined} />,
      map: { "combobox: How the shortcut works": ["combobox", /^How the shortcut works/], "combobox: Dictation output": ["combobox", /^Dictation output/],
        "button: Replacements1": ["button", "Replacements"], "button: Snippets1": ["button", "Snippets"] } },
    { name: "Replacements", section: "voice.replacements", today: () => <ReplacementsPage settings={SETTINGS} patch={patch} /> },
    { name: "Snippets", section: "voice.snippets", today: () => <SnippetsPage settings={SETTINGS} patch={patch} /> },
    { name: "Recent dictations", section: "voice.history", today: () => <HistoryPage />, map: { "button: Copy": ["button", /^Copy/] } },
    { name: "Captions", section: "captions", today: () => <CaptionsPage settings={SETTINGS} patch={patch} onStyle={() => undefined} /> },
    { name: "Style", section: "captions.style", today: () => <CaptionStylePage settings={SETTINGS} patch={patch} />,
      map: { "combobox: Position": ["combobox", /^Position/], "combobox: Hide when nobody speaks": ["combobox", /^Hide when nobody speaks/] } },
  ];

  it.each(PAGES.map((p) => [p.name, p] as const))("%s: every control today's page has is on the v2 page", async (_, page) => {
    const first = render(page.today());
    await act(flushAsync);
    const today = [...collectControls(new Set())];
    first.unmount();
    expect(today.length).toBeGreaterThan(0);
    await show(page.section);
    const missing = today.filter((control) => {
      const mapped = page.map?.[control];
      const [role, name] = mapped ?? [control.split(": ")[0] as ControlRole, control.slice(control.indexOf(": ") + 2)];
      return screen.queryAllByRole(role, { name }).length === 0;
    });
    expect(missing).toEqual([]);
    expect(stale(today, Object.keys(page.map ?? {}))).toEqual([]);
    expect(unmapped([], [])).toEqual([]);
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
