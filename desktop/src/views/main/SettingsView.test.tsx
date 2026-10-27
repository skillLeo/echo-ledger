import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { SettingsView } from "./SettingsView";
import { errorMessage, type AppStatus, type Settings, type SiteRule } from "../../lib/ipc";
import { callsOf, emitEvent, listenerCount, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeRecording, makeSettings, makeSiteRule, makeStatus } from "../../test/fixtures";
import commandsRs from "../../../src-tauri/src/commands.rs?raw";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const CLIENTS = { data: [{ id: "c1", name: "Acme Holdings", keyterm_count: 4 }, { id: "c2", name: "Northwind", keyterm_count: 0 }] };

/** Same contract as MainApp.run: executes the action, swallows and reports failures, resolves to undefined on error. */
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

async function setup(settings: Partial<Settings> = {}, status: Partial<AppStatus> = {}) {
  respond("get_settings", makeSettings(settings));
  respond("list_clients", CLIENTS);
  respond("update_settings", makeSettings(settings));
  const helpers = makeRun();
  const onUnpaired = vi.fn();
  const view = render(<SettingsView status={makeStatus(status)} run={helpers.run} onUnpaired={onUnpaired} />);
  await screen.findByRole("heading", { name: "Settings" });
  return { ...helpers, onUnpaired, ...view };
}

/** The `update_settings` command echoes the patch back merged into the current settings, like the Rust side. */
function echoSettings(base: Settings) {
  let current = base;
  respondWith("update_settings", ({ patch }) => {
    current = { ...current, ...(patch as Partial<Settings>) };
    return current;
  });
}

const row = (label: string | RegExp) => screen.getByText(label).closest(".toggle") as HTMLElement;
const keyDown = (init: KeyboardEventInit) => act(() => void window.dispatchEvent(new KeyboardEvent("keydown", { bubbles: true, cancelable: true, ...init })));

describe("SettingsView loading", () => {
  beforeEach(() => resetTauriMock());

  it("renders nothing until the settings have loaded, then loads settings and clients once", async () => {
    let release: (v: unknown) => void = () => undefined;
    respondWith("get_settings", () => new Promise((resolve) => (release = resolve)));
    respond("list_clients", CLIENTS);
    const { container } = render(<SettingsView status={makeStatus()} run={makeRun().run} onUnpaired={vi.fn()} />);
    expect(container).toBeEmptyDOMElement();
    release(makeSettings());
    expect(await screen.findByRole("heading", { name: "Settings" })).toBeInTheDocument();
    expect(callsOf("get_settings")).toHaveLength(1);
    expect(callsOf("list_clients")).toHaveLength(1);
  });

  it("still renders when the client list cannot be loaded", async () => {
    respond("get_settings", makeSettings());
    respondWith("list_clients", () => Promise.reject("Server returned 500: oops"));
    render(<SettingsView status={makeStatus()} run={makeRun().run} onUnpaired={vi.fn()} />);
    await screen.findByRole("heading", { name: "Settings" });
    expect(screen.getByRole("option", { name: "No client" })).toBeInTheDocument();
  });

  it("shows the paired server and whether the certificate is pinned", async () => {
    await setup({}, { serverUrl: "https://ledger.example.test", pinned: true });
    expect(screen.getByText(/Connected to ledger\.example\.test\s+· secure connection/)).toBeInTheDocument();
  });

  it("says when the certificate is not pinned", async () => {
    await setup({}, { pinned: false });
    expect(screen.getByText(/· connection not verified/)).toBeInTheDocument();
  });

  it("shows the device name next to the unpair button", async () => {
    await setup({}, { deviceName: "Studio PC" });
    expect(screen.getByText("Studio PC")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Unpair" })).toBeInTheDocument();
  });
});

describe("SettingsView switches", () => {
  beforeEach(() => resetTauriMock());

  const switches: [string, keyof Settings, boolean][] = [
    ["Auto-record calls", "autoMode", true],
    ["Show live captions while recording", "captionsOnRecord", true],
    ["Hide overlays from screen sharing", "contentProtection", true],
    ["Start EchoLedger when I sign in", "autostart", false],
    ["I understand and will obtain consent where required", "consentAcknowledged", false],
    ["Ask me when an unrecognised app uses the microphone", "askUnknownApps", false],
  ];

  it.each(switches)("%s reflects its state and sends the inverse as `%s`", async (label, key, initial) => {
    await setup({ [key]: initial });
    const toggle = screen.getByRole("switch", { name: label });
    expect(toggle).toHaveAttribute("aria-checked", String(initial));
    fireEvent.click(toggle);
    await waitFor(() => expect(callsOf("update_settings")).toEqual([{ patch: { [key]: !initial } }]));
  });

  it("flips the switch after the backend confirms and flips back on the next click", async () => {
    await setup();
    echoSettings(makeSettings());
    const toggle = screen.getByRole("switch", { name: "Start EchoLedger when I sign in" });
    fireEvent.click(toggle);
    await waitFor(() => expect(toggle).toHaveAttribute("aria-checked", "true"));
    fireEvent.click(toggle);
    await waitFor(() => expect(toggle).toHaveAttribute("aria-checked", "false"));
    expect(callsOf("update_settings").map((c) => c.patch)).toEqual([{ autostart: true }, { autostart: false }]);
  });

  it("keeps the old state and reports the error when the backend rejects the change", async () => {
    const { errors } = await setup();
    respondWith("update_settings", () => Promise.reject("Settings could not be saved: disk full"));
    const toggle = screen.getByRole("switch", { name: "Auto-record calls" });
    fireEvent.click(toggle);
    await waitFor(() => expect(errors).toEqual(["Settings could not be saved: disk full"]));
    expect(toggle).toHaveAttribute("aria-checked", "true");
  });
});

describe("SettingsView selects", () => {
  beforeEach(() => resetTauriMock());

  it("changes the caption corner", async () => {
    await setup({ overlayCorner: "bottom_right" });
    const select = screen.getByDisplayValue("Bottom right");
    expect([...(select as HTMLSelectElement).options].map((o) => [o.value, o.text])).toEqual([
      ["bottom_right", "Bottom right"],
      ["top_right", "Top right"],
      ["bottom_left", "Bottom left"],
      ["top_left", "Top left"],
    ]);
    fireEvent.change(select, { target: { value: "top_left" } });
    await waitFor(() => expect(callsOf("update_settings")).toEqual([{ patch: { overlayCorner: "top_left" } }]));
  });

  it("lists the server's clients, selects one and can clear the choice", async () => {
    await setup({ clientId: null });
    await screen.findByRole("option", { name: "Acme Holdings" });
    const select = screen.getByDisplayValue("No client");
    expect([...(select as HTMLSelectElement).options].map((o) => o.text)).toEqual(["No client", "Acme Holdings", "Northwind"]);

    fireEvent.change(select, { target: { value: "c2" } });
    await waitFor(() => expect(callsOf("update_settings")).toHaveLength(1));
    expect(callsOf("update_settings")[0]).toEqual({ patch: { clientId: "c2" } });
  });

  it("sends clientId null when 'No client' is chosen", async () => {
    await setup({ clientId: "c1" });
    await screen.findByRole("option", { name: "Acme Holdings" });
    const select = screen.getByDisplayValue("Acme Holdings");
    fireEvent.change(select, { target: { value: "" } });
    await waitFor(() => expect(callsOf("update_settings")).toHaveLength(1));
    expect(callsOf("update_settings")[0]).toEqual({ patch: { clientId: null } });
  });

  // A6.DU4: the key must reach the core as an explicit null (clear), never be dropped (keep the stored client).
  it("keeps clientId as an explicit null in the serialised command and shows No client afterwards", async () => {
    await setup({ clientId: "c1" });
    echoSettings(makeSettings({ clientId: "c1" }));
    await screen.findByRole("option", { name: "Acme Holdings" });
    const select = screen.getByLabelText("Client keyterms") as HTMLSelectElement;
    expect(select.value).toBe("c1");
    fireEvent.change(select, { target: { value: "" } });
    await waitFor(() => expect(callsOf("update_settings")).toHaveLength(1));
    // Command arguments travel to Rust as JSON: null survives JSON.stringify, undefined would drop the key.
    expect(JSON.stringify(callsOf("update_settings")[0])).toBe('{"patch":{"clientId":null}}');
    await waitFor(() => expect(select.value).toBe(""));
    expect(screen.getByDisplayValue("No client")).toBe(select);
  });

  // A6.DU4 (core side, DESKTOP-CORE): plain serde turns `"clientId": null` into `None` for an Option<Option<String>>
  // field, which update_settings reads as "keep". The field needs `#[serde(default, deserialize_with = ...)]` (or
  // serde_with's double_option) so null arrives as Some(None).
  it("A6.DU4: the core SettingsPatch keeps an explicit clientId null apart from a missing key", () => {
    const patch = commandsRs.slice(commandsRs.indexOf("pub struct SettingsPatch"), commandsRs.indexOf("\n}\n", commandsRs.indexOf("pub struct SettingsPatch")));
    const lines = patch.split("\n");
    const at = lines.findIndex((l) => /^\s*(pub\s+)?client_id: Option<Option<String>>,/.test(l));
    expect(at, "client_id is an Option<Option<String>>").toBeGreaterThan(0);
    const attributes = lines.slice(0, at).reverse();
    const own = attributes.slice(0, attributes.findIndex((l) => !/^\s*(#\[|\/\/)/.test(l)));
    expect(own.join("\n"), "client_id needs a deserialiser that maps null to Some(None)").toMatch(/deserialize_with|with\s*=|double_option/);
  });

  it("changes the dictation output between typing and clipboard", async () => {
    await setup({ dictationOutput: "type" });
    const select = screen.getByDisplayValue("Type into the focused app");
    fireEvent.change(select, { target: { value: "clipboard" } });
    await waitFor(() => expect(callsOf("update_settings")).toEqual([{ patch: { dictationOutput: "clipboard" } }]));
  });

  // A6.DU7: the caption position, client keyterms and dictation output selects have no accessible name.
  it("gives each select an accessible name", async () => {
    await setup();
    await screen.findByRole("option", { name: "Acme Holdings" });
    for (const select of screen.getAllByRole("combobox")) {
      expect(select, "select has no label / aria-label").toHaveAccessibleName();
    }
  });

  it("names each select after its visible label and describes the client select with its hint", async () => {
    await setup();
    await screen.findByRole("option", { name: "Acme Holdings" });
    expect(screen.getByRole("combobox", { name: "Caption position" })).toHaveValue("bottom_right");
    expect(screen.getByRole("combobox", { name: "Dictation output" })).toHaveValue("type");
    const client = screen.getByRole("combobox", { name: "Client keyterms" });
    expect(client).toHaveAccessibleDescription("Names and jargon from this client boost accuracy.");
  });

  it("describes a switch with its hint", async () => {
    await setup();
    expect(screen.getByRole("switch", { name: "Auto-record calls" })).toHaveAccessibleDescription(
      "Records a call when an app both uses your microphone and plays audio. Dictation and voice typing are never recorded.",
    );
    expect(screen.getByRole("switch", { name: "Start EchoLedger when I sign in" })).not.toHaveAttribute("aria-describedby");
  });
});

describe("SettingsView shortcuts", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => vi.restoreAllMocks());

  it("lists all five actions with readable shortcuts and 'Not set' for the empty one", async () => {
    await setup();
    expect(within(row("Show / hide live captions")).getByText("Ctrl + Shift + Space")).toBeInTheDocument();
    expect(within(row("Start / stop saving a recording")).getByText("Ctrl + Shift + R")).toBeInTheDocument();
    expect(within(row("Copy the last 2 minutes of captions")).getByText("Ctrl + Shift + C")).toBeInTheDocument();
    expect(within(row("Dictation box")).getByText("Ctrl + Shift + D")).toBeInTheDocument();
    expect(within(row("Capture voice note playing now")).getByText("Not set")).toBeInTheDocument();
  });

  it("uses macOS symbols on macOS", async () => {
    await setup({}, { platform: "macos" });
    expect(within(row("Show / hide live captions")).getByText("⌘⇧Space")).toBeInTheDocument();
  });

  it("only offers Clear for actions that have a shortcut", async () => {
    await setup();
    expect(within(row("Show / hide live captions")).getByRole("button", { name: "Clear" })).toBeInTheDocument();
    expect(within(row("Capture voice note playing now")).queryByRole("button", { name: "Clear" })).not.toBeInTheDocument();
  });

  it("records a new shortcut from the keyboard and sends the accelerator", async () => {
    await setup();
    respondWith("set_shortcut", ({ action, accelerator }) => makeSettings({ shortcuts: { [action as string]: accelerator as string } }));
    const target = row("Dictation box");
    fireEvent.click(within(target).getByRole("button", { name: "Change" }));
    expect(within(target).getByText("Press keys…")).toBeInTheDocument();

    keyDown({ key: "K", code: "KeyK", ctrlKey: true, altKey: true });
    await waitFor(() => expect(callsOf("set_shortcut")).toEqual([{ action: "dictation", accelerator: "CommandOrControl+Alt+K" }]));
    await waitFor(() => expect(within(row("Dictation box")).getByText("Ctrl + Alt + K")).toBeInTheDocument());
    expect(screen.queryByText("Press keys…")).not.toBeInTheDocument();
  });

  it("builds the accelerator with Command on macOS", async () => {
    await setup({}, { platform: "macos" });
    respond("set_shortcut", makeSettings());
    fireEvent.click(within(row("Capture voice note playing now")).getByRole("button", { name: "Change" }));
    keyDown({ key: "v", code: "KeyV", metaKey: true, shiftKey: true });
    await waitFor(() => expect(callsOf("set_shortcut")).toEqual([{ action: "voice_note", accelerator: "CommandOrControl+Shift+V" }]));
  });

  it("keeps waiting after a modifier-only or modifier-less key press", async () => {
    await setup();
    respond("set_shortcut", makeSettings());
    fireEvent.click(within(row("Show / hide live captions")).getByRole("button", { name: "Change" }));
    keyDown({ key: "Control", code: "ControlLeft", ctrlKey: true });
    keyDown({ key: "a", code: "KeyA" });
    expect(screen.getByText("Press keys…")).toBeInTheDocument();
    expect(callsOf("set_shortcut")).toHaveLength(0);
  });

  it("cancels recording with Escape without changing anything", async () => {
    await setup();
    fireEvent.click(within(row("Show / hide live captions")).getByRole("button", { name: "Change" }));
    keyDown({ key: "Escape", code: "Escape" });
    expect(screen.queryByText("Press keys…")).not.toBeInTheDocument();
    expect(callsOf("set_shortcut")).toHaveLength(0);
    expect(within(row("Show / hide live captions")).getByText("Ctrl + Shift + Space")).toBeInTheDocument();
  });

  it("reports a conflicting shortcut and keeps the previous one", async () => {
    const { errors } = await setup();
    respondWith("set_shortcut", () => Promise.reject("Ctrl+Shift+R is already registered by another application"));
    fireEvent.click(within(row("Start / stop saving a recording")).getByRole("button", { name: "Change" }));
    keyDown({ key: "R", code: "KeyR", ctrlKey: true, shiftKey: true });
    await waitFor(() => expect(errors).toEqual(["Ctrl+Shift+R is already registered by another application"]));
    expect(within(row("Start / stop saving a recording")).getByText("Ctrl + Shift + R")).toBeInTheDocument();
  });

  it("clears a shortcut by sending an empty accelerator", async () => {
    await setup();
    respondWith("set_shortcut", ({ action }) => makeSettings({ shortcuts: { [action as string]: "" } }));
    fireEvent.click(within(row("Copy the last 2 minutes of captions")).getByRole("button", { name: "Clear" }));
    await waitFor(() => expect(callsOf("set_shortcut")).toEqual([{ action: "copy_last2_min", accelerator: "" }]));
    await waitFor(() => expect(within(row("Copy the last 2 minutes of captions")).getByText("Not set")).toBeInTheDocument());
  });

  it("resets all shortcuts to their defaults", async () => {
    const { notices } = await setup({ shortcuts: { toggle_captions: "CommandOrControl+Alt+X" } });
    respond("reset_shortcuts", makeSettings());
    fireEvent.click(screen.getByRole("button", { name: "Reset defaults" }));
    await waitFor(() => expect(callsOf("reset_shortcuts")).toHaveLength(1));
    await waitFor(() => expect(within(row("Show / hide live captions")).getByText("Ctrl + Shift + Space")).toBeInTheDocument());
    expect(notices).toContain("Shortcuts reset");
  });

  it("shows the registration error of an action under its label", async () => {
    await setup({}, { shortcutErrors: [["dictation", "Ctrl+Shift+D is used by another app"]] });
    expect(within(row("Dictation box")).getByText("Ctrl+Shift+D is used by another app")).toHaveClass("error-text");
    expect(within(row("Show / hide live captions")).queryByText(/another app/)).not.toBeInTheDocument();
  });
});

describe("SettingsView unpair", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => vi.restoreAllMocks());

  it("does nothing when the confirmation is declined", async () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
    const { onUnpaired } = await setup();
    respond("unpair", makeStatus({ paired: false }));
    fireEvent.click(screen.getByRole("button", { name: "Unpair" }));
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining("Unpair this computer?"));
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(callsOf("unpair")).toHaveLength(0);
    expect(onUnpaired).not.toHaveBeenCalled();
  });

  it("unpairs and hands the new status to the app when confirmed", async () => {
    vi.spyOn(window, "confirm").mockReturnValue(true);
    const { onUnpaired } = await setup();
    const unpaired = makeStatus({ paired: false, deviceName: null, serverUrl: null });
    respond("unpair", unpaired);
    fireEvent.click(screen.getByRole("button", { name: "Unpair" }));
    await waitFor(() => expect(onUnpaired).toHaveBeenCalledWith(unpaired));
    expect(callsOf("unpair")).toHaveLength(1);
  });

  it("reports a failed unpair and keeps the computer paired", async () => {
    vi.spyOn(window, "confirm").mockReturnValue(true);
    const { onUnpaired, errors } = await setup();
    respondWith("unpair", () => Promise.reject("Keychain error: access denied"));
    fireEvent.click(screen.getByRole("button", { name: "Unpair" }));
    await waitFor(() => expect(errors).toEqual(["Keychain error: access denied"]));
    expect(onUnpaired).not.toHaveBeenCalled();
  });
});

describe("SettingsView decisions and captions (A2.5, A2.6, A3.2)", () => {
  beforeEach(() => resetTauriMock());

  const optionsOf = (select: HTMLElement) => [...(select as HTMLSelectElement).options].map((o) => [o.value, o.text]);

  it("offers the three ask-before-saving policies and saves the choice", async () => {
    await setup({ askBeforeSaving: "always" });
    const select = screen.getByRole("combobox", { name: "Ask before saving" });
    expect(select).toHaveValue("always");
    expect(optionsOf(select)).toEqual([["always", "Always"], ["auto_only", "Only automatic calls"], ["never", "Never"]]);
    fireEvent.change(select, { target: { value: "auto_only" } });
    await waitFor(() => expect(callsOf("update_settings")).toEqual([{ patch: { askBeforeSaving: "auto_only" } }]));
  });

  it("offers the three timeout policies and saves the choice", async () => {
    await setup({ decisionTimeout: "keep" });
    const select = screen.getByRole("combobox", { name: "If I don't choose within 60 s" });
    expect(select).toHaveValue("keep");
    expect(optionsOf(select)).toEqual([["keep", "Keep the recording"], ["discard", "Discard it"], ["wait", "Keep waiting"]]);
    fireEvent.change(select, { target: { value: "wait" } });
    await waitFor(() => expect(callsOf("update_settings")).toEqual([{ patch: { decisionTimeout: "wait" } }]));
  });

  it("shows the stored timeout policy", async () => {
    await setup({ decisionTimeout: "discard", askBeforeSaving: "never" });
    expect(screen.getByRole("combobox", { name: "If I don't choose within 60 s" })).toHaveValue("discard");
    expect(screen.getByRole("combobox", { name: "Ask before saving" })).toHaveValue("never");
  });

  it("sends caption lines as a number (2, 3 or 5)", async () => {
    await setup({ captionLines: 3 });
    const select = screen.getByRole("combobox", { name: "Caption lines" });
    expect(select).toHaveValue("3");
    expect(optionsOf(select).map(([v]) => v)).toEqual(["2", "3", "5"]);
    fireEvent.change(select, { target: { value: "5" } });
    await waitFor(() => expect(callsOf("update_settings")).toEqual([{ patch: { captionLines: 5 } }]));
    expect(JSON.stringify(callsOf("update_settings")[0])).toBe('{"patch":{"captionLines":5}}');
  });

  it("reports the core's refusal of a caption line count and keeps the old value", async () => {
    const { errors } = await setup({ captionLines: 2 });
    respondWith("update_settings", () => Promise.reject("Choose 2, 3 or 5 lines."));
    const select = screen.getByRole("combobox", { name: "Caption lines" });
    fireEvent.change(select, { target: { value: "5" } });
    await waitFor(() => expect(errors).toEqual(["Choose 2, 3 or 5 lines."]));
    expect(select).toHaveValue("2");
  });

  it("describes the unrecognised-app ask chip", async () => {
    await setup();
    expect(screen.getByRole("switch", { name: "Ask me when an unrecognised app uses the microphone" })).toHaveAccessibleDescription("A small prompt appears for 8 seconds.");
  });

  it("offers reading browser tab addresses only on macOS", async () => {
    const windows = await setup({}, { platform: "windows" });
    expect(screen.queryByRole("switch", { name: "Read browser tab addresses" })).not.toBeInTheDocument();
    windows.unmount();
    resetTauriMock();
    await setup({ browserAutomation: false }, { platform: "macos" });
    const toggle = screen.getByRole("switch", { name: "Read browser tab addresses" });
    expect(toggle).toHaveAccessibleDescription("Lets EchoLedger tell a Google Meet call from dictation. macOS asks for permission once.");
    expect(toggle).toHaveAttribute("aria-checked", "false");
    fireEvent.click(toggle);
    await waitFor(() => expect(callsOf("update_settings")).toEqual([{ patch: { browserAutomation: true } }]));
  });

  it("follows settings:changed from the core while open and stops listening on unmount", async () => {
    const { unmount } = await setup({ decisionTimeout: "keep" });
    await waitFor(() => expect(listenerCount("settings:changed")).toBe(1));
    await emitEvent("settings:changed", makeSettings({ decisionTimeout: "discard" }));
    expect(screen.getByRole("combobox", { name: "If I don't choose within 60 s" })).toHaveValue("discard");
    unmount();
    await waitFor(() => expect(listenerCount("settings:changed")).toBe(0));
  });
});

describe("SettingsView call sites (A1.4)", () => {
  beforeEach(() => resetTauriMock());

  const lastRules = () => {
    const calls = callsOf("update_settings");
    return (calls[calls.length - 1].patch as { siteRules: SiteRule[] }).siteRules;
  };
  const siteList = (name: string) => screen.getByRole("list", { name });

  it("lists the built-in call sites and the built-in never-record sites, all on", async () => {
    await setup();
    const calls = within(siteList("Call sites"));
    for (const host of ["meet.google.com", "zoom.us", "teams.microsoft.com", "teams.live.com", "web.whatsapp.com"]) {
      expect(calls.getByRole("switch", { name: `Record calls on ${host}` })).toHaveAttribute("aria-checked", "true");
    }
    const never = within(siteList("Never record"));
    for (const host of ["chatgpt.com", "claude.ai", "gemini.google.com"]) {
      expect(never.getByRole("switch", { name: `Never record ${host}` })).toHaveAttribute("aria-checked", "true");
    }
    expect(calls.getByText("meet.google.com").closest(".site-rule")).toHaveTextContent("Google Meet");
  });

  it("switches a built-in off by saving a disabled user rule with its id", async () => {
    await setup();
    echoSettings(makeSettings());
    const meet = screen.getByRole("switch", { name: "Record calls on meet.google.com" });
    fireEvent.click(meet);
    await waitFor(() => expect(callsOf("update_settings")).toHaveLength(1));
    expect(lastRules()).toEqual([
      { id: "builtin.meet", app: "google_meet", host: "meet.google.com", titlePrefix: "Meet - ", titleContains: "Google Meet", titleEquals: null, priority: 30, enabled: false },
    ]);
    await waitFor(() => expect(meet).toHaveAttribute("aria-checked", "false"));
  });

  it("switches a built-in back on by removing the override and keeps the other rules", async () => {
    const mine = makeSiteRule({ id: "u-1", app: "zoom", host: "zoom.example.test" });
    const override = { id: "builtin.never_gemini", app: "never", host: "gemini.google.com", titlePrefix: null, titleContains: null, titleEquals: null, priority: 100, enabled: false };
    await setup({ siteRules: [mine, override] });
    const gemini = screen.getByRole("switch", { name: "Never record gemini.google.com" });
    expect(gemini).toHaveAttribute("aria-checked", "false");
    fireEvent.click(gemini);
    await waitFor(() => expect(callsOf("update_settings")).toHaveLength(1));
    expect(lastRules()).toEqual([mine]);
  });

  it("adds a call site typed as an address as a host rule for the chosen app", async () => {
    await setup();
    fireEvent.change(screen.getByRole("combobox", { name: "App" }), { target: { value: "other" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Address or title contains" }), { target: { value: "https://Meet.Example.test/room-42" } });
    fireEvent.click(screen.getByRole("button", { name: "Add" }));
    await waitFor(() => expect(callsOf("update_settings")).toHaveLength(1));
    const [rule] = lastRules();
    expect(rule).toMatchObject({ app: "other", host: "meet.example.test", titleContains: null, titlePrefix: null, titleEquals: null, priority: 50, enabled: true });
    expect(rule.id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    await waitFor(() => expect(screen.getByRole("textbox", { name: "Address or title contains" })).toHaveValue(""));
  });

  it("adds a never-record entry typed as title text", async () => {
    await setup({ siteRules: [makeSiteRule()] });
    fireEvent.change(screen.getByRole("combobox", { name: "App" }), { target: { value: "never" } });
    const field = screen.getByRole("textbox", { name: "Address or title contains" });
    fireEvent.change(field, { target: { value: "Voice typing" } });
    fireEvent.submit(field.closest("form")!);
    await waitFor(() => expect(callsOf("update_settings")).toHaveLength(1));
    const rules = lastRules();
    expect(rules[0]).toEqual(makeSiteRule());
    expect(rules[1]).toMatchObject({ app: "never", host: null, titleContains: "Voice typing", priority: 100, enabled: true });
  });

  it("disables Add for an empty field and ignores a rule the user already has", async () => {
    await setup({ siteRules: [makeSiteRule({ app: "google_meet", host: "meet.example.test" })] });
    const add = screen.getByRole("button", { name: "Add" });
    expect(add).toBeDisabled();
    fireEvent.change(screen.getByRole("textbox", { name: "Address or title contains" }), { target: { value: "MEET.example.test" } });
    expect(add).toBeEnabled();
    fireEvent.click(add);
    await waitFor(() => expect(screen.getByRole("textbox", { name: "Address or title contains" })).toHaveValue(""));
    expect(callsOf("update_settings")).toHaveLength(0);
  });

  it("lists the user's rules under their list and removes one", async () => {
    const meet = makeSiteRule({ id: "u-meet", app: "google_meet", host: "meet.example.test" });
    const chip = makeSiteRule({ id: "u-app", app: "never", host: null, titleEquals: "Recorder.exe", priority: 100 });
    await setup({ siteRules: [meet, chip] });
    expect(within(siteList("Call sites")).getByText("meet.example.test")).toBeInTheDocument();
    expect(within(siteList("Never record")).getByText("Recorder.exe")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Remove Recorder.exe" }));
    await waitFor(() => expect(callsOf("update_settings")).toHaveLength(1));
    expect(lastRules()).toEqual([meet]);
  });

  it("keeps the typed text when the core refuses the rules", async () => {
    const { errors } = await setup();
    respondWith("update_settings", () => Promise.reject("Settings could not be saved: disk full"));
    const field = screen.getByRole("textbox", { name: "Address or title contains" });
    fireEvent.change(field, { target: { value: "jitsi.example.test" } });
    fireEvent.click(screen.getByRole("button", { name: "Add" }));
    await waitFor(() => expect(errors).toEqual(["Settings could not be saved: disk full"]));
    expect(field).toHaveValue("jitsi.example.test");
  });

  it("gives the app select and the text field accessible names", async () => {
    await setup();
    expect(screen.getByRole("combobox", { name: "App" })).toHaveValue("google_meet");
    expect([...(screen.getByRole("combobox", { name: "App" }) as HTMLSelectElement).options].map((o) => o.text)).toEqual([
      "Google Meet", "Zoom", "Microsoft Teams", "WhatsApp Web", "Other", "Never record",
    ]);
  });
});

describe("capture and control settings (B1, B3, B4, B5)", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("get_settings", makeSettings());
    respond("list_clients", CLIENTS);
    respond("input_devices", []);
    respond("permissions_status", { microphone: "allowed", browserAutomation: false, platform: "windows" });
  });

  it("shows the auto-record apps, caption style and microphone cards", async () => {
    render(<SettingsView status={makeStatus()} run={makeRun().run} onUnpaired={vi.fn()} />);
    expect(await screen.findByText("Auto-record apps")).toBeInTheDocument();
    expect(screen.getByText("Caption style")).toBeInTheDocument();
    expect(screen.getByText("Microphone")).toBeInTheDocument();
  });

  it("offers a shortcut for bookmarking", async () => {
    render(<SettingsView status={makeStatus()} run={makeRun().run} onUnpaired={vi.fn()} />);
    expect(await screen.findByText("Bookmark this moment of a recording")).toBeInTheDocument();
  });

  it("disables the microphone test while recording", async () => {
    render(<SettingsView status={makeStatus({ recording: makeRecording() })} run={makeRun().run} onUnpaired={vi.fn()} />);
    expect(await screen.findByRole("button", { name: "Test 10 seconds" })).toBeDisabled();
  });
});

describe("transcription language (B12)", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("get_settings", makeSettings());
    respond("list_clients", CLIENTS);
    respond("input_devices", []);
    respond("permissions_status", { microphone: "allowed", browserAutomation: false, platform: "windows" });
    respondWith("update_settings", ({ patch }) => makeSettings(patch as Partial<Settings>));
  });

  it("offers the documented languages and sends null for Automatic", async () => {
    render(<SettingsView status={makeStatus()} run={makeRun().run} onUnpaired={vi.fn()} />);
    const select = await screen.findByLabelText("Transcription language");
    expect([...(select as HTMLSelectElement).options].map((o) => o.textContent)).toEqual(["Auto (English, Urdu or Arabic)", "English", "Urdu", "Arabic", "French (more languages)", "German (more languages)", "Spanish (more languages)", "Italian (more languages)", "Portuguese (more languages)", "Dutch (more languages)", "Turkish (more languages)", "Russian (more languages)"]);
    fireEvent.change(select, { target: { value: "ar" } });
    await waitFor(() => expect(callsOf("update_settings").at(-1)).toEqual({ patch: { transcriptionLanguage: "ar" } }));
    fireEvent.change(select, { target: { value: "" } });
    await waitFor(() => expect(callsOf("update_settings").at(-1)).toEqual({ patch: { transcriptionLanguage: null } }));
  });
});
