// Desktop Urdu, Phase 0, in the app: with v21_urdu the main window speaks the account's language (right to left in
// Urdu), the system's while the account doesn't say, and English with the flag off; Settings › Appearance › Language
// changes the account's on the server (PATCH /api/account), the same setting as the web's and the phone's.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import MainApp from "../views/main/MainApp";
import { SettingsPagesV2 } from "../v2/settings/pages/SettingsPagesV2";
import { callsOf, flushAsync, resetTauriMock, respond } from "../test/tauri";
import { makeEntitlements, makeSettings, makeStatus } from "../test/fixtures";
import { resetEntitlementsForTests } from "../lib/entitlements";
import { findSetting } from "../lib/settingsRegistry";
import { language, resetLanguageForTests } from "./index";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

const ACCOUNT = { name: "Ayesha Khan", email: "ayesha@example.test", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null };
const settle = async () => {
  for (let i = 0; i < 4; i++) await act(flushAsync);
};

async function app(flags: Record<string, boolean>, account: object | null) {
  respond("app_status", makeStatus({ platform: "macos" }));
  respond("get_settings", makeSettings());
  respond("library_list", []);
  respond("library_refresh", []);
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("bin_list", { data: [] });
  respond("account_get", account);
  respond("projects_list", { data: [] });
  respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true, ...flags }, ui_classic: false });
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

  it("an account in English stays in English (left to right)", async () => {
    await app({ v21_urdu: true }, { ...ACCOUNT, language: "en" });
    expect(language()).toBe("en");
    expect(document.documentElement.dir).toBe("ltr");
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
  });
});
