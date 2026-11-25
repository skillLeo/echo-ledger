// Desktop Urdu, Phase 0, in the app: with v21_urdu in the new design the main window speaks the account's language
// (right to left in Urdu); signed in, the last one known on this computer while the account loads or can't be read,
// never the system's; signed out, the system's; English with the flag off or in the classic design. Settings ›
// Appearance › Language changes the account's on the server (PATCH /api/account), the same setting as the web's and
// the phone's, one change at a time.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import MainApp from "../views/main/MainApp";
import { SettingsPagesV2 } from "../v2/settings/pages/SettingsPagesV2";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../test/tauri";
import { makeEntitlements, makeSettings, makeStatus } from "../test/fixtures";
import { resetEntitlementsForTests } from "../lib/entitlements";
import { findSetting } from "../lib/settingsRegistry";
import { failureText } from "../lib/account";
import { language, resetLanguageForTests } from "./index";
import { KEPT_LANGUAGE_KEY, readKeptLanguage } from "./language";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

const ACCOUNT = { name: "Ayesha Khan", email: "ayesha@example.test", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null };
const settle = async () => {
  for (let i = 0; i < 4; i++) await act(flushAsync);
};

const REPLY = { ok: true, status: 200, code: null, message: null, accepts: [], errorCodes: null, errors: null };
/** The system's languages, as the web view reports them. */
function systemLanguages(list: string[] | null) {
  if (list) Object.defineProperty(window.navigator, "languages", { value: list, configurable: true });
  else delete (window.navigator as { languages?: unknown }).languages;
}

async function app(flags: Record<string, boolean>, account: object | null | "loading" | "fails", { classic = false, shell = true } = {}) {
  respond("app_status", makeStatus({ platform: "macos" }));
  respond("get_settings", makeSettings());
  respond("library_list", []);
  respond("library_refresh", []);
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("bin_list", { data: [] });
  if (account === "loading") respondWith("account_get", () => new Promise(() => undefined));
  else if (account === "fails") fail("account_get", "Couldn't reach the server.");
  else respond("account_get", account);
  respond("projects_list", { data: [] });
  respond("ui_flags", { ui_flags: { "ui_v2.shell": shell, "ui_v2.library": true, ...flags }, ui_classic: classic });
  render(<MainApp />);
  await settle();
  await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
  await settle();
}

beforeEach(() => {
  resetTauriMock();
  resetEntitlementsForTests(makeEntitlements());
  localStorage.clear();
  resetLanguageForTests();
  document.documentElement.removeAttribute("lang");
  document.documentElement.removeAttribute("dir");
  Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
});
afterEach(() => {
  cleanup();
  resetLanguageForTests();
  systemLanguages(null);
});

describe("the window's language (v21_urdu)", () => {
  it("with the flag, the account's Urdu: the window reads right to left", async () => {
    await app({ v21_urdu: true }, { ...ACCOUNT, language: "ur" });
    expect(language()).toBe("ur");
    expect(document.documentElement.lang).toBe("ur");
    expect(document.documentElement.dir).toBe("rtl");
  });

  it("with the flag off, English, and <html> is left as it was", async () => {
    await app({}, { ...ACCOUNT, language: "ur" });
    expect(language()).toBe("en");
    expect(document.documentElement.getAttribute("dir")).toBeNull();
  });

  it("an account in English stays in English (left to right), and its language is kept on this computer", async () => {
    await app({ v21_urdu: true }, { ...ACCOUNT, language: "en" });
    expect(language()).toBe("en");
    expect(document.documentElement.dir).toBe("ltr");
    expect(readKeptLanguage()).toEqual({ on: true, account: "en" });
  });

  it("only in the new design: classic, or the v2 shell off, stays English with <html> as it was", async () => {
    await app({ v21_urdu: true }, { ...ACCOUNT, language: "ur" }, { classic: true });
    expect(language()).toBe("en");
    expect(document.documentElement.getAttribute("dir")).toBeNull();
    expect(readKeptLanguage().on).toBe(false);
    cleanup();
    await app({ v21_urdu: true }, { ...ACCOUNT, language: "ur" }, { shell: false });
    expect(language()).toBe("en");
    expect(document.documentElement.getAttribute("dir")).toBeNull();
  });

  it("signed in, while the account loads: the last language known here, never the system's", async () => {
    systemLanguages(["ur-PK", "en-US"]);
    localStorage.setItem(KEPT_LANGUAGE_KEY, JSON.stringify({ on: true, account: "en" }));
    await app({ v21_urdu: true }, "loading");
    expect(language()).toBe("en");
    expect(document.documentElement.dir).toBe("ltr");
    cleanup();
    resetLanguageForTests();
    systemLanguages(["en-US"]);
    localStorage.setItem(KEPT_LANGUAGE_KEY, JSON.stringify({ on: true, account: "ur" }));
    await app({ v21_urdu: true }, "loading");
    expect(language()).toBe("ur");
    expect(document.documentElement.dir).toBe("rtl");
  });

  it("signed in, when the account can't be read: the one kept here; none kept, English (not the system's Urdu)", async () => {
    systemLanguages(["en-US"]);
    localStorage.setItem(KEPT_LANGUAGE_KEY, JSON.stringify({ on: true, account: "ur" }));
    await app({ v21_urdu: true }, "fails");
    expect(language()).toBe("ur");
    cleanup();
    resetLanguageForTests();
    localStorage.clear();
    systemLanguages(["ur-PK"]);
    await app({ v21_urdu: true }, "fails");
    expect(language()).toBe("en");
  });

  it("signed out: the system's language, when Urdu was on at the last answer kept here", async () => {
    systemLanguages(["ur-PK", "en-US"]);
    respond("app_status", makeStatus({ paired: false, serverUrl: null, deviceName: null }));
    respond("get_settings", makeSettings());
    respond("ui_flags", { ui_flags: {}, ui_classic: false });
    render(<MainApp />);
    expect(await screen.findByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
    await settle();
    // Urdu was never on here: <html> is left as it is.
    expect(language()).toBe("en");
    expect(document.documentElement.getAttribute("dir")).toBeNull();
    cleanup();
    localStorage.setItem(KEPT_LANGUAGE_KEY, JSON.stringify({ on: true, account: "en" }));
    render(<MainApp />);
    expect(await screen.findByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
    await settle();
    await waitFor(() => expect(language()).toBe("ur"));
    expect(document.documentElement.dir).toBe("rtl");
  });
});

describe("Settings › Appearance › Language", () => {
  const show = async (language?: { value: "en" | "ur"; onChange: (l: "en" | "ur") => void; error?: string | null }) => {
    respond("get_settings", makeSettings());
    render(<div className="el-root"><SettingsPagesV2 section={{ id: "appearance", seq: 1 }} onHome={vi.fn()} run={vi.fn() as never} status={makeStatus()} onUnpaired={vi.fn()} language={language} /></div>);
    await act(flushAsync);
  };

  it("only with v21_urdu and an account; its two choices, each in its own words", async () => {
    expect(findSetting("appearance.language")?.requires).toEqual({ urdu: true, account: true });
    await show();
    expect(screen.queryByRole("radiogroup", { name: "Language" })).toBeNull();
    cleanup();
    const onChange = vi.fn();
    await show({ value: "en", onChange, error: "That didn't save. Try again." });
    const group = screen.getByRole("radiogroup", { name: "Language" });
    expect(within(group).getByRole("radio", { name: "English" })).toBeChecked();
    fireEvent.click(within(group).getByRole("radio", { name: "اردو" }));
    expect(onChange).toHaveBeenCalledWith("ur");
    expect(screen.getByRole("alert")).toHaveTextContent("That didn't save. Try again.");
    expect(screen.getByText("The same on the web and the phone.")).toBeInTheDocument();
  });

  it("in the app, choosing اردو sends the account's language to the server and the window follows", async () => {
    await app({ v21_urdu: true, "ui_v2.settings": true }, { ...ACCOUNT, language: "en" });
    respond("account_call", { ok: true, status: 200, data: { ...ACCOUNT, language: "ur" }, code: null, message: null, accepts: [], errorCodes: null, errors: null });
    fireEvent.click(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Settings" }));
    await settle();
    fireEvent.click(await screen.findByRole("button", { name: /^Appearance/ }));
    await settle();
    fireEvent.click(within(await screen.findByRole("radiogroup", { name: "Language" })).getByRole("radio", { name: "اردو" }));
    await settle();
    expect(callsOf("account_call")).toContainEqual({ action: "language", id: null, body: { language: "ur" } });
    // The Urdu catalogue is loaded first, then the window turns.
    await waitFor(() => expect(language()).toBe("ur"));
    expect(document.documentElement.dir).toBe("rtl");
    expect(readKeptLanguage().account).toBe("ur");
  });

  it("one change at a time: the language already chosen, or a click while one is on its way, sends nothing", async () => {
    await app({ v21_urdu: true, "ui_v2.settings": true }, { ...ACCOUNT, language: "en" });
    let answer: (reply: unknown) => void = () => undefined;
    respondWith("account_call", () => new Promise((resolve) => { answer = resolve; }));
    fireEvent.click(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Settings" }));
    await settle();
    fireEvent.click(await screen.findByRole("button", { name: /^Appearance/ }));
    await settle();
    const group = await screen.findByRole("radiogroup", { name: "Language" });
    // English is the account's already.
    fireEvent.click(within(group).getByRole("radio", { name: "English" }));
    await settle();
    expect(callsOf("account_call")).toHaveLength(0);
    // اردو, then again and back to English while the first is on its way: one request.
    fireEvent.click(within(group).getByRole("radio", { name: "اردو" }));
    fireEvent.click(within(group).getByRole("radio", { name: "اردو" }));
    fireEvent.click(within(group).getByRole("radio", { name: "English" }));
    await settle();
    expect(callsOf("account_call")).toEqual([{ action: "language", id: null, body: { language: "ur" } }]);
    // The server's answer is what the window shows.
    await act(async () => answer({ ...REPLY, data: { ...ACCOUNT, language: "ur" } }));
    await settle();
    await waitFor(() => expect(language()).toBe("ur"));
    // The window is in Urdu now, the setting's title too (D3b): Language is زبان.
    expect(within(screen.getByRole("radiogroup", { name: "زبان" })).getByRole("radio", { name: "اردو" })).toBeChecked();
  });

  it("a change that couldn't be sent is said as the account pages say it", async () => {
    await app({ v21_urdu: true, "ui_v2.settings": true }, { ...ACCOUNT, language: "en" });
    fail("account_call", "Couldn't reach the server.");
    fireEvent.click(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Settings" }));
    await settle();
    fireEvent.click(await screen.findByRole("button", { name: /^Appearance/ }));
    await settle();
    fireEvent.click(within(await screen.findByRole("radiogroup", { name: "Language" })).getByRole("radio", { name: "اردو" }));
    await settle();
    // Said under the choice (the window may have other notices of its own).
    const said = failureText("Couldn't reach the server.", "en");
    await waitFor(() => expect(screen.getAllByRole("alert").some((a) => a.textContent === said && a.classList.contains("el-field__error"))).toBe(true));
    expect(language()).toBe("en");
    // Nothing on its way any more: the next click is sent.
    respond("account_call", { ...REPLY, data: { ...ACCOUNT, language: "ur" } });
    fireEvent.click(within(screen.getByRole("radiogroup", { name: "Language" })).getByRole("radio", { name: "اردو" }));
    await settle();
    expect(callsOf("account_call")).toHaveLength(2);
  });
});
