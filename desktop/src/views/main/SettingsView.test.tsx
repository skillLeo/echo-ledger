// Settings (v1.7): the Overview landing with who you are, the plan's button per plan, the one state row, the sections and
// rows of the registry on every page (and only those), search that lands on a single setting, and the rows' saves.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { LEGACY_SECTIONS, SettingsView, settingsAvailability, settingsPages, signOutBody } from "./SettingsView";
import { errorMessage, type Account, type AppStatus, type Entitlements, type Settings } from "../../lib/ipc";
import { callsOf, emitEvent, flushAsync, listenerCount, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeSettings, makeStatus, makeUpgradeRequest, sampleEntitlements } from "../../test/fixtures";
import { findSetting, HELP_ARTICLES, SETTINGS, setting } from "../../lib/settingsRegistry";
import { resetEntitlementsForTests } from "../../lib/entitlements";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
vi.mock("@tauri-apps/api/app", () => ({ getVersion: async () => "1.7.0" }));

const ACCOUNT: Account = {
  name: "Hassam Ali", email: "hassam@example.com", email_verified: true, two_factor: true, google: false,
  created_at: "2025-11-09T09:00:00Z", workspace: { id: 1, name: "Personal", role: "owner" }, deletes_at: null,
};
const CLIENTS = { data: [{ id: "c1", name: "Acme Holdings", keyterm_count: 4 }] };

function makeRun() {
  const errors: string[] = [];
  const notices: string[] = [];
  const run = async (fn: () => Promise<unknown>, ok?: string) => {
    try {
      const result = await fn();
      if (ok) notices.push(ok);
      return result;
    } catch (e) {
      errors.push(errorMessage(e));
      return undefined;
    }
  };
  return { run: run as never, errors, notices };
}

function echoSettings(base: Settings) {
  let current = base;
  respondWith("update_settings", ({ patch }) => {
    current = { ...current, ...(patch as Partial<Settings>) };
    return current;
  });
}

async function setup({ settings = {}, status = {}, account = ACCOUNT, e = null, section = null }: {
  settings?: Partial<Settings>;
  status?: Partial<AppStatus>;
  account?: Account | null;
  e?: Entitlements | null;
  section?: { id: string; seq: number } | null;
} = {}) {
  respond("get_settings", makeSettings(settings));
  respond("list_clients", CLIENTS);
  respond("account_get", account);
  respond("security_overview", { devices: [{ id: "d1", name: "Work laptop", platform: "windows", current: true }, { id: "d2", name: "Pixel 9", platform: "android", current: false }], events: [] });
  respond("storage_info", { recordings_bytes: 125_829_120, recordings_count: 42, disk_free_bytes: null, disk_total_bytes: null, retention_days: 30 });
  respond("input_devices", [{ id: "m1", name: "Realtek Audio", isDefault: true }]);
  respond("permissions_status", { microphone: "allowed", browserAutomation: false, platform: "windows", missing: [] });
  respond("lock_status", { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 });
  respond("screens_status", { supported: true, platform: "windows", borderless: true, permission: true, inPlan: true, onServer: true });
  respond("update_status", { phase: "up_to_date", current: "1.7.0", version: null, notes: null, checkedAt: null, message: null, progress: null });
  respond("dictation_history", []);
  respond("upgrade_request_latest", e?.upgrade_request ?? null);
  respond("mac_check_last", null);
  echoSettings(makeSettings(settings));
  resetEntitlementsForTests(e);
  const helpers = makeRun();
  const onUnpaired = vi.fn();
  const view = render(<SettingsView status={makeStatus({ serverUrl: "https://app.example.test", ...status })} run={helpers.run} onUnpaired={onUnpaired} section={section} />);
  await screen.findAllByRole("heading", { level: 1 });
  await waitFor(() => expect(callsOf("account_get").length).toBeGreaterThan(0));
  return { ...helpers, onUnpaired, ...view };
}

const list = () => screen.getByRole("navigation", { name: "Settings" });
const nav = (name: string) => within(list()).getByRole("button", { name });
const go = async (name: string) => {
  fireEvent.click(nav(name));
  await screen.findByRole("heading", { name, level: 1 });
};

describe("the Overview and who you are", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => resetEntitlementsForTests());

  it("opens on the Overview: the card, the state row and this month, never a page or 'All settings'", async () => {
    await setup({ e: sampleEntitlements("free") });
    const overview = screen.getByTestId("settings-overview");
    const card = within(overview).getByTestId("profile-card");
    expect(await within(card).findByText("Hassam Ali")).toBeInTheDocument();
    expect(within(card).getByText("hassam@example.com")).toBeInTheDocument();
    expect(within(card).getByText("Free")).toHaveClass("plan-chip");
    expect(within(overview).getByText("This month")).toBeInTheDocument();
    expect(screen.queryByText("All settings")).not.toBeInTheDocument();
    expect(screen.queryByText(/Signed in to/)).not.toBeInTheDocument();
    // The sections of the root, in order, in sentence case.
    const heads = within(list()).getAllByRole("heading", { level: 3 }).map((h) => h.textContent);
    expect(heads).toEqual(["Recording", "Transcription", "App", "Account", "Support"]);
  });

  it("gives Free a filled Upgrade, Pro an outlined one, and Ultra none but the date it is paid until", async () => {
    const { unmount } = await setup({ e: sampleEntitlements("free") });
    expect(within(await screen.findByTestId("profile-card")).getByRole("button", { name: "Upgrade" })).toHaveClass("primary");
    unmount();
    const pro = await setup({ e: sampleEntitlements("pro") });
    const card = await screen.findByTestId("profile-card");
    expect(within(card).getByRole("button", { name: "Upgrade" })).toHaveClass("outline");
    expect(within(card).getByText("230 of 600 min this month")).toBeInTheDocument();
    pro.unmount();
    await setup({ e: sampleEntitlements("ultra") });
    const ultra = await screen.findByTestId("profile-card");
    expect(within(ultra).queryByRole("button", { name: "Upgrade" })).not.toBeInTheDocument();
    expect(within(ultra).getByText("Paid until 9 Dec 2026")).toBeInTheDocument();
    expect(within(ultra).getByText("Ultra")).toBeInTheDocument();
  });

  it("shows no Upgrade while a request is open, and the state row carries it instead", async () => {
    const e = { ...sampleEntitlements("pro"), upgrade_request: makeUpgradeRequest("awaiting_payment", { plan: "ultra" }) };
    await setup({ e });
    const overview = screen.getByTestId("settings-overview");
    expect(within(overview).queryByText("Upgrade")).not.toBeInTheDocument();
    expect(within(overview).getByTestId("state-row")).toHaveTextContent("Ultra requested. Waiting for your payment");
  });

  it("puts the deletion first in the state row, with Cancel deletion", async () => {
    await setup({ e: sampleEntitlements("free"), account: { ...ACCOUNT, deletes_at: "2026-12-09T10:00:00Z" } });
    const row = await screen.findByTestId("state-row");
    expect(row).toHaveAttribute("data-state", "deleting");
    expect(row).toHaveTextContent("Your account will be deleted on 9 Dec.");
    fireEvent.click(within(row).getByRole("button", { name: "Cancel deletion" }));
    expect(await screen.findByRole("heading", { name: "Privacy & data", level: 1 })).toBeInTheDocument();
  });

  it("asks a Free account without a key to set up free transcription", async () => {
    await setup({ e: sampleEntitlements("free") });
    const row = await screen.findByTestId("state-row");
    expect(row).toHaveTextContent("Set up free transcription (2 minutes)");
    fireEvent.click(within(row).getByRole("button", { name: /Set up free transcription/ }));
    expect(await screen.findByRole("heading", { name: "Transcription key", level: 1 })).toBeInTheDocument();
  });

  it("the card's body opens Plan & usage, and the Upgrade button beside it is not inside it", async () => {
    await setup({ e: sampleEntitlements("free") });
    const card = await screen.findByTestId("profile-card");
    const body = within(card).getByRole("button", { name: /Hassam Ali, hassam@example.com, Free plan\. Opens plan and usage\./ });
    expect(within(body).queryByRole("button", { name: "Upgrade" })).not.toBeInTheDocument();
    fireEvent.click(body);
    expect(await screen.findByRole("heading", { name: "Plan & usage", level: 1 })).toBeInTheDocument();
  });

  it("still shows the account's pages when this computer's settings can't be read, and offers Try again", async () => {
    respondWith("get_settings", () => Promise.reject("disk"));
    respond("list_clients", CLIENTS);
    respond("account_get", ACCOUNT);
    resetEntitlementsForTests(null);
    render(<SettingsView status={makeStatus()} run={makeRun().run} onUnpaired={vi.fn()} />);
    await screen.findAllByRole("heading", { name: "Settings", level: 1 });
    fireEvent.click(await within(list()).findByRole("button", { name: "Profile" }));
    expect(await screen.findByText("Hassam Ali")).toBeInTheDocument();
    fireEvent.click(nav("Captions"));
    expect(await screen.findByText("Couldn't read this computer's settings.")).toBeInTheDocument();
    respond("get_settings", makeSettings());
    fireEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByRole("switch", { name: "Live captions while recording" })).toBeInTheDocument();
  });
});

describe("pages from elsewhere", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => resetEntitlementsForTests());

  it("maps the v1.6 section ids to the new pages", async () => {
    expect(LEGACY_SECTIONS["transcription-key"]).toBe("key");
    expect(LEGACY_SECTIONS.general).toBe("words");
    expect(LEGACY_SECTIONS["all-features"]).toBe("plan#plan.compare");
    await setup({ e: sampleEntitlements("free"), section: { id: "transcription-key", seq: 1 } });
    expect(await screen.findByRole("heading", { name: "Transcription key", level: 1 })).toBeInTheDocument();
  });

  it("opens a page at a setting and highlights its row", async () => {
    await setup({ section: { id: "lock#lock.after", seq: 1 } });
    expect(await screen.findByRole("heading", { name: "App lock", level: 1 })).toBeInTheDocument();
    await waitFor(() => expect(document.getElementById("setting-lock.after")).toHaveClass("is-highlighted"));
    expect(document.activeElement).toBe(document.getElementById("setting-lock.after"));
  });
});

describe("search", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => resetEntitlementsForTests());

  const search = (q: string) => fireEvent.change(screen.getByRole("textbox", { name: "Search settings" }), { target: { value: q } });

  it("finds single settings with where they live, and opens one highlighted", async () => {
    await setup({ e: sampleEntitlements("free") });
    search("lock after");
    expect(screen.getByRole("heading", { name: "Results for “lock after”" })).toBeInTheDocument();
    const results = within(screen.getByTestId("settings-results"));
    const first = results.getAllByRole("option")[0];
    expect(first).toHaveAttribute("data-result", "lock.after");
    expect(first).toHaveTextContent("App › App lock");
    expect(first).toHaveTextContent("After 5 minutes");
    fireEvent.click(first);
    expect(await screen.findByRole("heading", { name: "App lock", level: 1 })).toBeInTheDocument();
    await waitFor(() => expect(document.getElementById("setting-lock.after")).toHaveClass("is-highlighted"));
  });

  it("finds Upgrade for 'pro', never the audio probe, and hides sections with nothing", async () => {
    await setup({ e: sampleEntitlements("free") });
    search("pro");
    const options = within(screen.getByTestId("settings-results")).getAllByRole("option");
    expect(options[0]).toHaveAttribute("data-result", "plan.upgrade");
    expect(options[1]).toHaveAttribute("data-result", "profile");
    expect(within(list()).queryByRole("heading", { name: "App" })).not.toBeInTheDocument();
    expect(within(within(list()).getByRole("button", { name: /^Plan & usage/ })).getByLabelText(/match/)).toBeInTheDocument();
  });

  it("moves through results with the arrows, opens with Enter and clears with Esc", async () => {
    await setup();
    const box = screen.getByRole("textbox", { name: "Search settings" });
    search("quiet");
    fireEvent.keyDown(box, { key: "ArrowDown" });
    fireEvent.keyDown(box, { key: "ArrowUp" });
    expect(within(screen.getByTestId("settings-results")).getAllByRole("option")[0]).toHaveAttribute("aria-selected", "true");
    fireEvent.keyDown(box, { key: "Escape" });
    expect(screen.queryByTestId("settings-results")).not.toBeInTheDocument();
    search("quiet");
    fireEvent.keyDown(box, { key: "Enter" });
    expect(await screen.findByRole("heading", { name: "Notifications", level: 1 })).toBeInTheDocument();
  });

  it("says when nothing matches, with no made-up suggestions, and offers Help", async () => {
    await setup();
    search("xyzzy");
    expect(screen.getByText("No settings match “xyzzy”")).toBeInTheDocument();
    expect(screen.getByText("Check the spelling or try another word.")).toBeInTheDocument();
    expect(screen.queryByText("Closest matches")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Search help" })).toBeInTheDocument();
  });

  it("answers help questions apart and opens the answer", async () => {
    await setup();
    search("dark mode");
    const help = screen.getByRole("listbox", { name: "Help found" });
    fireEvent.click(within(help).getByRole("option", { name: /How do I turn on dark mode/ }));
    expect(await screen.findByRole("heading", { name: "Help", level: 1 })).toBeInTheDocument();
    expect(screen.getByText("Go to Settings › Appearance › Theme and choose Dark.")).toBeInTheDocument();
  });
});

describe("every row comes from the registry, and every entry has its row", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => resetEntitlementsForTests());

  // Rows that show only in a state the fixture is not in (a saved key, a team, a request, macOS, a deletion), and the
  // entries made at run time or found only through search (the upgrade flow's own steps).
  const CONDITIONAL = new Set(["calls.browser_tabs", "mic.perm.system_audio", "screen.permission", "help.mac_check", "privacy.cancel_deletion",
    "profile.workspace", "plan.usage.members", "plan.usage.uploads", "plan.request", "plan.history", "plan.payment_details", "plan.receipt"]);
  const pages = SETTINGS.filter((e) => e.kind === "page").map((e) => e.id);

  it("renders each page's rows with registry ids and titles, and nothing else", async () => {
    // Pro with a key of its own: screen moments unlocked, the key page with its saved key, Ultra above to upgrade to.
    const e = { ...sampleEntitlements("pro"), transcription_key: { provider: "groq", set: true, last4: "3f9a" } };
    const { container } = await setup({ e });
    const available = settingsAvailability({ platform: "windows", account: ACCOUNT, entitlements: e, role: "owner" });
    const seen = new Set<string>();
    for (const id of ["calls", "calls.sites", "mic", "captions", "captions.style", "screen", "screen.excluded", "voice", "voice.replacements", "voice.snippets", "voice.history",
      "transcription.language", "words", "key", "appearance", "notifications", "lock", "storage", "shortcuts", "profile", "plan", "security", "security.activity", "devices",
      "privacy", "privacy.consent", "help", "about", "about.technical"]) {
      if (id !== "transcription.language") expect(pages, id).toContain(id);
      act(() => {
        window.dispatchEvent(new Event("noop"));
      });
      await openPage(id);
      await screen.findByRole("heading", { name: setting(id).title, level: 1 });
      await waitFor(() => expect(container.querySelector(`.settings-page[data-page="${id}"]`)).not.toBeNull());
      await act(async () => { await Promise.resolve(); });
      for (const el of container.querySelectorAll<HTMLElement>(".settings-page [data-setting]")) {
        const rid = el.dataset.setting!;
        if (rid.startsWith("devices.item.")) continue;
        const entry = findSetting(rid);
        expect(entry, `${id}: row ${rid} is in the registry`).toBeDefined();
        const title = el.classList.contains("set-row") ? el.querySelector(".set-title")?.textContent : null;
        if (title && !rid.startsWith("calls.app.") && !rid.startsWith("help.article.")) expect(title, rid).toBe(entry!.title);
        seen.add(rid);
      }
    }
    for (const el of container.querySelectorAll<HTMLElement>("[data-nav]")) seen.add(el.dataset.nav!);
    seen.add("app.autostart");
    seen.add("signout");
    const missing = SETTINGS.filter((e) => available(e) && !CONDITIONAL.has(e.id) && e.kind !== "page" && !seen.has(e.id) && !HELP_ARTICLES.includes(e)).map((e) => e.id);
    expect(missing, "entries with no row").toEqual([]);
    const rootPages = SETTINGS.filter((e) => e.page === null && e.kind === "page" && available(e)).map((e) => e.id);
    for (const p of rootPages) expect(seen.has(p), `${p} is in the list`).toBe(true);
  });

  async function openPage(id: string): Promise<void> {
    const parent = setting(id).page;
    if (parent === null) {
      fireEvent.click(nav(setting(id).title));
      return;
    }
    if (!document.querySelector(`#setting-${CSS.escape(id)}`)) {
      await openPage(parent);
      await screen.findByRole("heading", { name: setting(parent).title, level: 1 });
    }
    fireEvent.click(await waitFor(() => document.querySelector<HTMLElement>(`#setting-${CSS.escape(id)}`)!));
  }

  it("titles each page as the row that opens it", () => {
    for (const p of settingsPages(() => true)) expect(p.label).toBe(setting(p.page).title);
  });
});

describe("rows that save", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => resetEntitlementsForTests());

  it("flips a switch once the core confirms, and keeps the old state when it refuses", async () => {
    const { errors } = await setup({ settings: { captionsOnRecord: true } });
    await go("Captions");
    const live = screen.getByRole("switch", { name: "Live captions while recording" });
    fireEvent.click(live);
    await waitFor(() => expect(live).toHaveAttribute("aria-checked", "false"));
    expect(callsOf("update_settings").at(-1)).toEqual({ patch: { captionsOnRecord: false } });
    respondWith("update_settings", () => Promise.reject("Settings could not be saved"));
    fireEvent.click(live);
    await waitFor(() => expect(errors).toContain("Settings could not be saved"));
    expect(live).toHaveAttribute("aria-checked", "false");
  });

  it("shows during screen share as the inverse of the old content protection", async () => {
    await setup({ settings: { contentProtection: true } });
    await go("Captions");
    const share = screen.getByRole("switch", { name: "Show during screen share" });
    expect(share).toHaveAttribute("aria-checked", "false");
    fireEvent.click(share);
    await waitFor(() => expect(callsOf("update_settings").at(-1)).toEqual({ patch: { contentProtection: false } }));
  });

  it("sends clientId null for None and the project's id otherwise", async () => {
    await setup({ settings: { clientId: "c1" } });
    await go("Custom words");
    const select = await screen.findByLabelText("Use terms from project");
    await screen.findByRole("option", { name: "Acme Holdings" });
    fireEvent.change(select, { target: { value: "" } });
    await waitFor(() => expect(callsOf("update_settings").at(-1)).toEqual({ patch: { clientId: null } }));
  });

  it("sends Automatic as a null language", async () => {
    await setup({ settings: { transcriptionLanguage: "ur" } });
    fireEvent.click(nav("Language"));
    fireEvent.change(await screen.findByLabelText("Language"), { target: { value: "" } });
    await waitFor(() => expect(callsOf("update_settings").at(-1)).toEqual({ patch: { transcriptionLanguage: null } }));
  });

  it("chooses the theme in a radio group with one stop per option", async () => {
    await setup();
    await go("Appearance");
    const group = screen.getByRole("radiogroup", { name: "Theme" });
    const dark = within(group).getByRole("radio", { name: "Dark" });
    expect(within(group).getByRole("radio", { name: "System" })).toHaveAttribute("tabindex", "0");
    expect(dark).toHaveAttribute("tabindex", "-1");
    fireEvent.click(dark);
    await waitFor(() => expect(dark).toHaveAttribute("aria-checked", "true"));
  });

  it("starts at sign-in from its root row's switch", async () => {
    await setup({ settings: { autostart: false } });
    fireEvent.click(within(list()).getByRole("switch", { name: "Start when I sign in" }));
    await waitFor(() => expect(callsOf("update_settings").at(-1)).toEqual({ patch: { autostart: true } }));
  });

  it("records a new global shortcut, clears one and resets them all", async () => {
    await setup({ settings: { shortcuts: { ...makeSettings().shortcuts } } });
    respondWith("set_shortcut", () => makeSettings());
    respond("reset_shortcuts", makeSettings());
    await go("Keyboard shortcuts");
    fireEvent.click(screen.getByRole("button", { name: "Change Record now" }));
    expect(screen.getByText("Press keys…")).toBeInTheDocument();
    await act(async () => {
      window.dispatchEvent(new KeyboardEvent("keydown", { key: "R", code: "KeyR", ctrlKey: true, shiftKey: true, bubbles: true, cancelable: true }));
    });
    await waitFor(() => expect(callsOf("set_shortcut").at(-1)).toEqual({ action: "record_now", accelerator: "CommandOrControl+Shift+R" }));
    vi.spyOn(window, "confirm").mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Reset to defaults" }));
    await waitFor(() => expect(callsOf("reset_shortcuts")).toHaveLength(1));
  });

  it("adds a call site and switches a built-in one off", async () => {
    await setup({ settings: { siteRules: [] } });
    await go("Calls & meetings");
    fireEvent.click(screen.getByRole("button", { name: /^Call sites/ }));
    await screen.findByRole("heading", { name: "Call sites", level: 1 });
    fireEvent.change(screen.getByPlaceholderText("Address or title contains"), { target: { value: "calls.example.com" } });
    fireEvent.click(screen.getByRole("button", { name: "Add" }));
    await waitFor(() => expect(callsOf("update_settings").length).toBeGreaterThan(0));
    const sent = callsOf("update_settings").at(-1) as { patch: { siteRules: { pattern: string }[] } };
    expect(sent.patch.siteRules.some((r) => JSON.stringify(r).includes("calls.example.com"))).toBe(true);
  });

  it("follows settings:changed from the core and stops listening on unmount", async () => {
    const { unmount } = await setup({ settings: { captionsOnRecord: true } });
    await go("Captions");
    await emitEvent("settings:changed", makeSettings({ captionsOnRecord: false }));
    expect(screen.getByRole("switch", { name: "Live captions while recording" })).toHaveAttribute("aria-checked", "false");
    unmount();
    await flushAsync();
    expect(listenerCount("settings:changed")).toBe(0);
  });
});

describe("Sign out", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => resetEntitlementsForTests());

  it("asks first, says what happens to recordings waiting, and signs out on confirm", async () => {
    expect(signOutBody(0)).toBeNull();
    expect(signOutBody(1)).toBe("1 recording hasn't uploaded yet. It'll stay on this computer, but nobody else can see it until you sign in again.");
    respond("unpair", makeStatus({ paired: false }));
    const { onUnpaired } = await setup({ status: { pendingUploads: 2 } });
    fireEvent.click(within(list()).getByRole("button", { name: "Sign out" }));
    const dialog = screen.getByRole("dialog", { name: "Sign out of this computer?" });
    expect(dialog).toHaveTextContent("2 recordings haven't uploaded yet.");
    fireEvent.click(within(dialog).getByRole("button", { name: "Cancel" }));
    expect(callsOf("unpair")).toHaveLength(0);
    fireEvent.click(within(list()).getByRole("button", { name: "Sign out" }));
    fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Sign out" }));
    await waitFor(() => expect(onUnpaired).toHaveBeenCalled());
  });
});
