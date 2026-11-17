// Screen-share privacy v2 (the v1.8.1 headline): today's behaviour tests, held against the new page, and parity with
// today's page in each of its states.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactElement } from "react";
import type { PrivacyStatus, ShareCheckView } from "../../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond } from "../../../test/tauri";
import { makePrivacyStatus, makeSettings } from "../../../test/fixtures";
import { collectControls, findControls, stale, type ControlRole } from "../../../test/parity";
import { setting } from "../../../lib/settingsRegistry";
import { GUIDED_ALONE, HIDDEN_WHILE_SHARING, LIMITS, ONE_WINDOW_TIP, OPEN_SYSTEM_SETTINGS, OnboardingShareCheck, SHARE_PRIVACY_INTRO, SHARE_PRIVACY_OFF, ShareCheckPage, SharePrivacyPage } from "../../../views/main/PrivacySettings";
import { ShareCheckPageV2, SharePrivacyPageV2 } from "./SharePrivacyV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../../test/tauri")).eventModule);

const PICTURE = "data:image/webp;base64,UklGRg==";
const view = (overrides: Partial<ShareCheckView> = {}): ShareCheckView => ({
  result: "pass", reason: null, methods: [{ method: "sck", result: "pass", reason: null }], system: "macOS 27.3", at: new Date().toISOString(),
  picture: PICTURE, width: 960, height: 620, testArea: [0.3, 0.4, 0.1, 0.1], controlArea: [0.5, 0.4, 0.1, 0.1], ...overrides,
});
const MAC_APPS: PrivacyStatus["apps"] = [
  { app: "zoom", name: "Zoom", version: "7.1.9", state: "hidden", line: "Zoom: hidden", needsTest: false },
  { app: "teams", name: "Teams", version: null, state: "not_checked", line: "Teams: not checked", needsTest: false },
  { app: "meet", name: "Meet", version: "142.0", state: "share_tab", line: "Meet: share a tab", needsTest: true },
];
const page = (settings = makeSettings(), patch = vi.fn(async () => true), onCheck = vi.fn()) =>
  render(<div className="el-root"><SharePrivacyPageV2 settings={settings} patch={patch} onCheck={onCheck} /></div>);

beforeEach(() => resetTauriMock());
afterEach(() => resetTauriMock());

describe("Screen-share privacy v2", () => {
  it("the status, the last check, the tip and the limits; the hiding choice and diagnostics save at once", async () => {
    respond("privacy_status", makePrivacyStatus({ badge: "visible", badgeLabel: "Visible in screen share", oldWindows: true, platform: "windows",
      lines: [{ warning: true, text: "In Remote Desktop, EchoLedger windows are visible to the remote side.", fix: null }] }));
    const patch = vi.fn(async () => true);
    const onCheck = vi.fn();
    page(makeSettings(), patch, onCheck);
    await act(flushAsync);
    expect(screen.getByText("In Remote Desktop, EchoLedger windows are visible to the remote side.")).toBeInTheDocument();
    expect(screen.getByText(ONE_WINDOW_TIP)).toBeInTheDocument();
    expect(screen.getByText(LIMITS)).toBeInTheDocument();
    expect(screen.getByText("Visible in screen share")).toHaveClass("el-privacy-value--warning");
    fireEvent.click(screen.getByRole("combobox", { name: /^On this Windows version/ }));
    fireEvent.click(screen.getAllByRole("option").find((o) => o.getAttribute("aria-selected") === "false")!);
    fireEvent.click(screen.getByRole("switch", { name: "Send diagnostics" }));
    expect(patch.mock.calls.map((c) => Object.keys(c[0] as object)[0])).toEqual(["oldWindowsSharing", "sendDiagnostics"]);
    fireEvent.click(screen.getByRole("button", { name: "Check screen-share privacy" }));
    expect(onCheck).toHaveBeenCalled();
  });

  it("a status line's fix: Open System Settings or Hide again", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", lines: [{ warning: true, text: "Screen Recording is off for EchoLedger.", fix: "open_settings" }] }));
    respond("open_privacy_settings", null);
    page();
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: OPEN_SYSTEM_SETTINGS }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "screen" }]);
  });

  it("on a Mac: each installed meeting app, Zoom's one setting opened in place, and the one-window switch", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", zoomInstalled: true, apps: MAC_APPS }));
    page();
    await act(flushAsync);
    expect(screen.getByText("Zoom: hidden")).toBeInTheDocument();
    expect(screen.getByText("Meet: share a tab")).toBeInTheDocument();
    expect(screen.queryByText("Teams: not checked"), "not installed").toBeNull();
    expect(screen.getByText("Chrome 142.0"), "Meet's result is kept for Chrome's version").toBeInTheDocument();
    expect(screen.queryByTestId("zoom-steps")).toBeNull();
    const zoom = screen.getByRole("button", { name: "Set up Zoom" });
    expect(zoom).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(zoom);
    expect(screen.getByTestId("zoom-steps")).toHaveTextContent("Settings › Share Screen › Advanced and set Screen capture mode to Advanced capture with window filtering");
    expect(zoom).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByRole("switch", { name: "I share one window or a tab" })).toBeInTheDocument();
    expect(screen.getByText(setting("share_privacy.one_window").description!)).toBeInTheDocument();
  });

  it("Hide now, and the demo counted down here; Show in screen share waits while hiding is off", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      respond("privacy_status", makePrivacyStatus({ demoSecondsLeft: 120 }));
      respond("privacy_set_hidden", null);
      page();
      await act(flushAsync);
      expect(screen.getByRole("button", { name: "Hide from screen share again" })).toHaveAccessibleDescription("2 min left");
      await act(async () => { vi.advanceTimersByTime(61_000); });
      expect(screen.getByRole("button", { name: "Hide from screen share again" })).toHaveAccessibleDescription("1 min left");
      fireEvent.click(screen.getByRole("button", { name: "Hide now" }));
      expect(callsOf("privacy_set_hidden")).toEqual([{ hidden: true }]);
    } finally {
      vi.useRealTimers();
    }
    cleanup();
    respond("privacy_status", makePrivacyStatus({ hiding: "off" }));
    page(makeSettings({ hideFromSharing: "off" }));
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Show in screen share for 10 min" })).toBeDisabled();
    expect(screen.getByText("Everything EchoLedger shows can be seen by people watching your screen share.")).toBeInTheDocument();
  });

  it("no Show while the sharing bar is on screen, with the tray's words; Show again once the bar went", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", hiddenForShare: true, barOnScreen: true }));
    page();
    await act(flushAsync);
    expect(screen.getByTestId("back-after-share")).toHaveTextContent(HIDDEN_WHILE_SHARING);
    expect(screen.queryByRole("button", { name: "Show EchoLedger windows" })).toBeNull();
    respond("privacy_status", makePrivacyStatus({ platform: "macos", hiddenForShare: true, barOnScreen: false }));
    respond("privacy_set_hidden", null);
    await act(async () => emitEvent("privacy:changed", null));
    fireEvent.click(await screen.findByRole("button", { name: "Show EchoLedger windows" }));
    expect(callsOf("privacy_set_hidden")).toEqual([{ hidden: false }]);
    expect(screen.queryByTestId("back-after-share")).toBeNull();
  });

  it("moves to the other display only when something is open, and says why a move didn't happen", async () => {
    respond("privacy_status", makePrivacyStatus({ displays: 2, floatingOpen: false }));
    page();
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Move to the other display" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Move to the other display" })).toHaveAccessibleDescription("Nothing open to move");
    cleanup();
    respond("privacy_status", makePrivacyStatus({ displays: 2, floatingOpen: true }));
    fail("privacy_move_to_other_display", "Only one display is connected now.");
    page();
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Move to the other display" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("Only one display is connected now.");
  });

  it("Copy diagnostics copies and says so", async () => {
    respond("privacy_status", makePrivacyStatus());
    respond("privacy_diagnostics", "hiding=all badge=private");
    const writeText = vi.fn(async () => undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    page();
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Copy diagnostics" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenCalledWith("hiding=all badge=private");
    expect(screen.getByRole("button", { name: "Copy diagnostics" })).toHaveAccessibleDescription("Copied");
  });

  it("names the floating windows the way people know them, in every hiding choice", async () => {
    const internal = /\b(pill|bubble|overlay|chip|popup|toast)s?\b|Share Check|Hide all|Hide cards/i;
    for (const hiding of ["all", "floating", "off"] as const) {
      cleanup();
      respond("privacy_status", makePrivacyStatus({ platform: "macos", hiding, hiddenForShare: true }));
      page(makeSettings({ hideFromSharing: hiding }));
      await act(flushAsync);
      expect(screen.getByTestId("share-privacy").textContent ?? "", hiding).not.toMatch(internal);
    }
  });
});

describe("the page promises no more than the last check saw", () => {
  const OLD = [/people see your other apps/i, /not EchoLedger\./, /notes stay on your screen/i, /keeps its windows out of screen shares in Zoom/i,
    /invisible/i, /undetectable/i, /only you can see/i];
  const hidings = ["all", "floating", "off"] as const;
  const badges = ["private", "visible", "not_checked", "black_box"] as const;
  it.each(hidings.flatMap((hiding) => badges.map((badge) => [hiding, badge] as const)))("hiding %s, badge %s: today's page, onboarding and the v2 page", async (hiding, badge) => {
    expect(SHARE_PRIVACY_INTRO).toBe("EchoLedger hides its windows from screen sharing, checks on this computer whether that works, and tells you what the last check saw.");
    expect(SHARE_PRIVACY_OFF).toBe("Hide from screen sharing is off: EchoLedger's windows show in a screen share.");
    const settings = makeSettings({ hideFromSharing: hiding });
    const pages: [string, () => ReactElement][] = [
      ["today's page", () => <SharePrivacyPage settings={settings} patch={async () => true} onCheck={() => undefined} />],
      ["onboarding", () => <OnboardingShareCheck platform="macos" />],
      ["the v2 page", () => <div className="el-root"><SharePrivacyPageV2 settings={settings} patch={async () => true} onCheck={() => undefined} /></div>],
    ];
    for (const [name, make] of pages) {
      respond("privacy_status", makePrivacyStatus({ badge, hiding, platform: "macos" }));
      render(make());
      await act(flushAsync);
      const text = document.body.textContent ?? "";
      for (const old of OLD) expect(text, `${name}: ${old}`).not.toMatch(old);
      if (hiding === "off") {
        // Off: that it is off, and no general hiding sentence.
        expect(text, name).toContain(SHARE_PRIVACY_OFF);
        expect(text, name).not.toContain(SHARE_PRIVACY_INTRO);
      } else {
        expect(text, name).toContain(SHARE_PRIVACY_INTRO);
        expect(text, name).not.toContain(SHARE_PRIVACY_OFF);
        // What the last check saw, and nothing more.
        const said = {
          visible: "The last check saw EchoLedger's windows in the screen share.",
          not_checked: "not checked yet",
          black_box: "The last check saw a black box where EchoLedger's windows are.",
          private: "The last check saw EchoLedger's windows hidden from the screen share.",
        }[badge];
        expect(text, `${name}: ${badge}`).toContain(said);
        // Only the floating windows hidden: the main window shows.
        if (hiding === "floating") expect(text, name).toContain("The main window is visible in screen share");
      }
      cleanup();
    }
  });
});

describe("Check screen-share privacy v2", () => {
  const check = () => render(<div className="el-root"><ShareCheckPageV2 /></div>);

  it("what you see next to what others see, and the plain line, after a pass; one main button", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", system: "macOS 27.3" }));
    respond("share_check_run", view());
    respond("share_check_card", null);
    check();
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Check now" }));
    await act(flushAsync);
    expect(screen.getByRole("status")).toHaveTextContent("Hidden in this check. Each meeting app captures differently on a Mac: test yours below. Checked on macOS 27.3, today.");
    expect(screen.getByText("What you see")).toBeInTheDocument();
    expect(screen.getByAltText("Your screen as a screen share captured it")).toHaveAttribute("src", PICTURE);
    expect(callsOf("share_check_run")).toEqual([{ why: "asked" }]);
    expect(document.querySelectorAll(".el-button--primary")).toHaveLength(1);
    expect(screen.getByRole("button", { name: "Check again" })).toBeInTheDocument();
  });

  it("a missing permission before any run and after one, with Open System Settings; a failed capture with Try again", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", permission: false }));
    respond("open_privacy_settings", null);
    check();
    await act(flushAsync);
    fireEvent.click(within(screen.getByTestId("permission-first")).getByRole("button", { name: OPEN_SYSTEM_SETTINGS }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "screen" }]);
    cleanup();
    respond("privacy_status", makePrivacyStatus({ platform: "macos", permission: true }));
    respond("share_check_run", view({ result: "error", reason: "capture_failed", picture: null, testArea: null, controlArea: null }));
    check();
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Check now" }));
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
    expect(screen.queryByText("What others see")).toBeNull();
  });

  it("keeps Check now off while an automatic run is on", async () => {
    respond("privacy_status", makePrivacyStatus({ checkRunning: true }));
    check();
    await act(flushAsync);
    expect(screen.getByRole("button", { name: "Checking…" })).toBeDisabled();
  });

  it("asks per meeting app on a Mac and keeps the answer; Cancel leaves no answer", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", apps: [
      { app: "zoom", name: "Zoom", version: "7.1.9", state: "not_checked", line: "Zoom: not checked", needsTest: true },
      { app: "meet", name: "Meet", version: "142.0", state: "share_tab", line: "Meet: share a tab", needsTest: true },
    ] }));
    respond("share_check_card", null);
    respond("share_check_answer", makePrivacyStatus({ platform: "macos" }));
    check();
    await act(flushAsync);
    expect(screen.getByText("Chrome 142.0")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Test Zoom" }));
    await act(flushAsync);
    const question = screen.getByText(/A test card is on your screen now/);
    await waitFor(() => expect(question).toHaveFocus());
    expect(screen.getByText(GUIDED_ALONE)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    expect(callsOf("share_check_answer")).toEqual([]);
    fireEvent.click(screen.getByRole("button", { name: "Test Zoom" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "I can't see it" }));
    await act(flushAsync);
    expect(callsOf("share_check_answer")).toEqual([{ meetingApp: "zoom", visible: false }]);
    expect(callsOf("share_check_card")).toEqual([{ open: true }, { open: false }, { open: true }]);
  });
});

/** Parity: every control today's pages show, in each state, is on the v2 pages. */
describe("parity with today's Screen-share privacy pages", () => {
  const settings = makeSettings();
  const patch = async () => true;
  type State = { name: string; status: PrivacyStatus; today: () => ReactElement; v2: () => ReactElement; run?: ShareCheckView; guided?: boolean;
    map?: Record<string, [ControlRole | "password", string | RegExp]> };
  const pageMap: State["map"] = {
    "combobox: Hide from screen sharing": ["combobox", /^Hide from screen sharing/],
    "combobox: On this Windows version": ["combobox", /^On this Windows version/],
  };
  const STATES: State[] = [
    { name: "Windows, older, two displays", status: makePrivacyStatus({ platform: "windows", oldWindows: true, displays: 2, floatingOpen: true }),
      today: () => <SharePrivacyPage settings={settings} patch={patch} onCheck={() => undefined} />, v2: () => <SharePrivacyPageV2 settings={settings} patch={patch} onCheck={() => undefined} />,
      map: { ...pageMap, "button: Check screen-share privacyHidden, today": ["button", "Check screen-share privacy"], "button: Move to the other displayThen share your main display": ["button", "Move to the other display"] } },
    { name: "Mac with Zoom, the demo running, a line with its fix", status: makePrivacyStatus({ platform: "macos", zoomInstalled: true, apps: MAC_APPS, demoSecondsLeft: 300,
      lines: [{ warning: true, text: "Something changed a flag.", fix: "hide_again" }] }),
      today: () => <SharePrivacyPage settings={settings} patch={patch} onCheck={() => undefined} />, v2: () => <SharePrivacyPageV2 settings={settings} patch={patch} onCheck={() => undefined} />,
      map: { "combobox: Hide from screen sharing": pageMap["combobox: Hide from screen sharing"], "button: Check screen-share privacyHidden, today": ["button", "Check screen-share privacy"], "button: Set up Zoom1 setting": ["button", "Set up Zoom"],
        "button: Hide from screen share again5 min left": ["button", "Hide from screen share again"] } },
    { name: "Check, Mac, after a run", status: makePrivacyStatus({ platform: "macos", apps: [{ app: "zoom", name: "Zoom", version: "7.1.9", state: "not_checked", line: "Zoom: not checked", needsTest: true }] }),
      run: view(), today: () => <ShareCheckPage />, v2: () => <ShareCheckPageV2 />, map: { "button: Test": ["button", "Test Zoom"] } },
    { name: "Check, Mac, testing with the phone", status: makePrivacyStatus({ platform: "macos", apps: [{ app: "zoom", name: "Zoom", version: "7.1.9", state: "not_checked", line: "Zoom: not checked", needsTest: true }] }),
      guided: true, today: () => <ShareCheckPage />, v2: () => <ShareCheckPageV2 /> },
  ];

  const into = async (state: State) => {
    await act(flushAsync);
    if (state.run) {
      fireEvent.click(screen.getByRole("button", { name: "Check now" }));
      await act(flushAsync);
    }
    if (state.guided) {
      fireEvent.click(screen.getAllByRole("button", { name: /^Test/ })[0]);
      await act(flushAsync);
    }
  };

  it.each(STATES.map((s) => [s.name, s] as const))("%s", async (_, state) => {
    respond("privacy_status", state.status);
    respond("share_check_card", null);
    if (state.run) respond("share_check_run", state.run);
    const first = render(state.today());
    await into(state);
    const today = [...collectControls(new Set())];
    first.unmount();
    expect(today.length).toBeGreaterThan(0);
    render(<div className="el-root">{state.v2()}</div>);
    await into(state);
    const missing = today.filter((control) => {
      const [role, name] = state.map?.[control] ?? [control.split(": ")[0] as ControlRole, control.slice(control.indexOf(": ") + 2)];
      return findControls(role, name).length === 0;
    });
    expect(missing).toEqual([]);
    expect(stale(today, Object.keys(state.map ?? {}))).toEqual([]);
  });
});
