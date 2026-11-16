import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
import { callsOf, emitEvent, fail, resetTauriMock, respond } from "../../test/tauri";
import { makePrivacyStatus, makeSettings } from "../../test/fixtures";
import type { ShareCheckView } from "../../lib/ipc";
import { GUIDED_ALONE, HIDDEN_WHILE_SHARING, LIMITS, ONE_WINDOW_TIP, OPEN_SYSTEM_SETTINGS, OnboardingShareCheck, PATTERN_FLASH, SHARE_NOTICE, ShareCheckPage, ShareNotice, SharePrivacyPage, lastCheckValue } from "./PrivacySettings";
import { resetClosedBanners } from "../../components/StateBanner";
import { setting } from "../../lib/settingsRegistry";
import ShareCheckWindow, { CONTROL_TEXT } from "../sharecheck/ShareCheckWindow";
import { PrivacyBadge, PrivacyBadgeView } from "../../components/PrivacyBadge";

const PICTURE = "data:image/webp;base64,UklGRg==";

function view(overrides: Partial<ShareCheckView> = {}): ShareCheckView {
  return {
    result: "pass", reason: null, methods: [{ method: "sck", result: "pass", reason: null }], system: "macOS 27.3", at: new Date().toISOString(),
    picture: PICTURE, width: 960, height: 620, testArea: [0.3, 0.4, 0.1, 0.1], controlArea: [0.5, 0.4, 0.1, 0.1], ...overrides,
  };
}

describe("Share Check", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => resetTauriMock());

  it("shows what you see next to what others see, and the plain line, after a pass", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", system: "macOS 27.3" }));
    respond("share_check_run", view());
    respond("share_check_card", null);
    render(<ShareCheckPage />);
    fireEvent.click(await screen.findByRole("button", { name: "Check now" }));
    expect(await screen.findByText("Hidden in this check. Each meeting app captures differently on a Mac: test yours below. Checked on macOS 27.3, today.")).toBeInTheDocument();
    expect(screen.getByText("What you see")).toBeInTheDocument();
    expect(screen.getByText("What others see")).toBeInTheDocument();
    expect(screen.getByAltText("Your screen as a screen share captured it")).toHaveAttribute("src", PICTURE);
    expect(callsOf("share_check_run")).toEqual([{ why: "asked" }]);
    expect(screen.getAllByRole("button").filter((b) => b.classList.contains("primary"))).toHaveLength(1);
  });

  it("says plainly when EchoLedger is visible, naming only the apps not confirmed hidden", async () => {
    respond("privacy_status", makePrivacyStatus({
      platform: "macos", badge: "visible",
      apps: [
        { app: "zoom", name: "Zoom", version: "7.1.9", state: "hidden", line: "Zoom: hidden", needsTest: false },
        { app: "teams", name: "Teams", version: "25.1", state: "not_checked", line: "Teams: not checked", needsTest: true },
        { app: "meet", name: "Meet", version: "142.0", state: "share_tab", line: "Meet: share a tab", needsTest: true },
      ],
    }));
    respond("share_check_run", view({ result: "fail", methods: [{ method: "sck", result: "fail", reason: null }] }));
    respond("share_check_card", null);
    render(<ShareCheckPage />);
    fireEvent.click(await screen.findByRole("button", { name: "Check now" }));
    expect(await screen.findByText("Visible on this Mac in Teams and Meet. Share one window or a tab instead.")).toBeInTheDocument();
  });

  it("says before the first run that the Screen Recording permission is missing, with its fix", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", permission: false }));
    respond("open_privacy_settings", null);
    render(<ShareCheckPage />);
    const line = await screen.findByTestId("permission-first");
    expect(line).toHaveTextContent("Screen Recording");
    fireEvent.click(within(line).getByRole("button", { name: "Open System Settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "screen" }]);
  });

  it("keeps Check now off while an automatic run is on", async () => {
    respond("privacy_status", makePrivacyStatus({ checkRunning: true }));
    render(<ShareCheckPage />);
    expect(await screen.findByRole("button", { name: "Checking…" })).toBeDisabled();
  });

  it("names a missing permission with Open System Settings, and a failed capture with Try again", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", permission: true }));
    respond("share_check_run", view({ result: "error", reason: "permission_missing", picture: null, testArea: null, controlArea: null }));
    respond("share_check_card", null);
    respond("open_privacy_settings", null);
    render(<ShareCheckPage />);
    fireEvent.click(await screen.findByRole("button", { name: "Check now" }));
    const result = await screen.findByTestId("check-result");
    fireEvent.click(within(result).getByRole("button", { name: "Open System Settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "screen" }]);
    expect(screen.queryByText("What others see")).toBeNull();

    respond("share_check_run", view({ result: "error", reason: "capture_failed", picture: null, testArea: null, controlArea: null }));
    fireEvent.click(screen.getByRole("button", { name: "Check again" }));
    expect(await screen.findByRole("button", { name: "Try again" })).toBeInTheDocument();
  });

  it("asks per meeting app on a Mac and keeps the answer", async () => {
    respond("privacy_status", makePrivacyStatus({
      platform: "macos",
      apps: [
        { app: "zoom", name: "Zoom", version: "7.1.9", state: "not_checked", line: "Zoom: not checked", needsTest: true },
        { app: "meet", name: "Meet", version: "142.0", state: "share_tab", line: "Meet: share a tab", needsTest: true },
      ],
    }));
    respond("share_check_card", null);
    respond("share_check_answer", makePrivacyStatus({ platform: "macos" }));
    render(<ShareCheckPage />);
    expect(await screen.findByText("Chrome 142.0")).toBeInTheDocument();
    fireEvent.click((await screen.findAllByRole("button", { name: "Test" }))[0]);
    const question = await screen.findByText(/A test card is on your screen now/);
    expect(callsOf("share_check_card")).toEqual([{ open: true }]);
    await waitFor(() => expect(question).toHaveFocus());
    expect(screen.getByText(GUIDED_ALONE)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "I can't see it" }));
    await waitFor(() => expect(callsOf("share_check_answer")).toEqual([{ meetingApp: "zoom", visible: false }]));
  });

  it("lets the test with the phone be cancelled without an answer", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", apps: [{ app: "zoom", name: "Zoom", version: "7.1.9", state: "not_checked", line: "Zoom: not checked", needsTest: true }] }));
    respond("share_check_card", null);
    render(<ShareCheckPage />);
    fireEvent.click(await screen.findByRole("button", { name: "Test" }));
    fireEvent.click(await screen.findByRole("button", { name: "Cancel" }));
    expect(callsOf("share_check_card")).toEqual([{ open: true }, { open: false }]);
    expect(callsOf("share_check_answer")).toEqual([]);
    expect(await screen.findByRole("button", { name: "Test" })).toBeInTheDocument();
  });
});

describe("Settings › Screen-share privacy", () => {
  beforeEach(() => resetTauriMock());

  it("shows the status, the last check, the tip and the limits; Windows' amber lines", async () => {
    respond("privacy_status", makePrivacyStatus({ badge: "visible", badgeLabel: "Visible in screen share", oldWindows: true, lines: [{ warning: true, text: "In Remote Desktop, EchoLedger windows are visible to the remote side.", fix: null }] }));
    const patch = vi.fn(async () => true);
    render(<SharePrivacyPage settings={makeSettings()} patch={patch} onCheck={() => undefined} />);
    expect(await screen.findByText("In Remote Desktop, EchoLedger windows are visible to the remote side.")).toBeInTheDocument();
    expect(screen.getByText(ONE_WINDOW_TIP)).toBeInTheDocument();
    expect(screen.getByText(LIMITS)).toBeInTheDocument();
    const old = screen.getByLabelText("On this Windows version");
    fireEvent.change(old, { target: { value: "show" } });
    expect(patch).toHaveBeenCalledWith({ oldWindowsSharing: "show" });
    fireEvent.click(screen.getByRole("switch", { name: "Send diagnostics" }));
    expect(patch).toHaveBeenCalledWith({ sendDiagnostics: true });
  });

  it("shows Zoom's one setting and the per-app lines on a Mac", async () => {
    respond("privacy_status", makePrivacyStatus({
      platform: "macos", zoomInstalled: true,
      apps: [
        { app: "zoom", name: "Zoom", version: "7.1.9", state: "hidden", line: "Zoom: hidden", needsTest: false },
        { app: "teams", name: "Teams", version: null, state: "not_checked", line: "Teams: not checked", needsTest: false },
        { app: "meet", name: "Meet", version: "142.0", state: "share_tab", line: "Meet: share a tab", needsTest: true },
      ],
    }));
    render(<SharePrivacyPage settings={makeSettings()} patch={vi.fn(async () => true)} onCheck={() => undefined} />);
    expect(await screen.findByText("Zoom: hidden")).toBeInTheDocument();
    expect(screen.getByText("Meet: share a tab")).toBeInTheDocument();
    expect(screen.queryByText("Teams: not checked"), "not installed").toBeNull();
    expect(screen.getByText("Chrome 142.0"), "Meet's result is kept for Chrome's version").toBeInTheDocument();
    expect(screen.getByText("Hidden"), "the tags are a pair: Hidden / Visible").toBeInTheDocument();
    expect(screen.queryByTestId("zoom-steps"), "a step, not a value read from Zoom").toBeNull();
    const zoom = screen.getByRole("button", { name: /Set up Zoom/ });
    expect(zoom).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(zoom);
    expect(screen.getByTestId("zoom-steps")).toHaveTextContent("Settings › Share Screen › Advanced and set Screen capture mode to Advanced capture with window filtering");
    expect(zoom).toHaveAttribute("aria-expanded", "true");
    expect(within(zoom).queryByText("Hide"), "a disclosure, not a Hide button").toBeNull();
    fireEvent.click(zoom);
    expect(screen.queryByTestId("zoom-steps")).toBeNull();
    expect(screen.getByRole("switch", { name: "I share one window or a tab" })).toBeInTheDocument();
    // The switch's own words, in the section's footer.
    expect(screen.getByText(setting("share_privacy.one_window").description!)).toBeInTheDocument();
  });

  it("shows and changes Hide EchoLedger windows, and counts the demo down", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      respond("privacy_status", makePrivacyStatus({ demoSecondsLeft: 120 }));
      respond("privacy_set_hidden", null);
      render(<SharePrivacyPage settings={makeSettings()} patch={vi.fn(async () => true)} onCheck={() => undefined} />);
      expect(await screen.findByText("2 min left")).toBeInTheDocument();
      await act(async () => { vi.advanceTimersByTime(61_000); });
      expect(screen.getByText("1 min left"), "counted down here").toBeInTheDocument();
      fireEvent.click(screen.getByRole("button", { name: "Hide now" }));
      expect(callsOf("privacy_set_hidden")).toEqual([{ hidden: true }]);
    } finally {
      vi.useRealTimers();
    }
  });

  it("says why the windows are hidden and brings them back", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", hiddenForShare: true }));
    respond("privacy_set_hidden", null);
    render(<SharePrivacyPage settings={makeSettings()} patch={vi.fn(async () => true)} onCheck={() => undefined} />);
    expect(await screen.findByText("Hidden while you share your screen")).toBeInTheDocument();
    expect(screen.queryByTestId("back-after-share")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Show EchoLedger windows" }));
    expect(callsOf("privacy_set_hidden")).toEqual([{ hidden: false }]);
  });

  it("does not offer Show while the sharing bar is on screen, and says when they come back in the tray's words", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", hiddenForShare: true, barOnScreen: true }));
    render(<SharePrivacyPage settings={makeSettings()} patch={vi.fn(async () => true)} onCheck={() => undefined} />);
    const line = await screen.findByTestId("back-after-share");
    expect(line).toHaveTextContent(/^Hidden while you share your screen\. They come back when the meeting ends\.$/);
    expect(HIDDEN_WHILE_SHARING).toBe("Hidden while you share your screen. They come back when the meeting ends.");
    expect(line).not.toHaveTextContent(/one window/i);
    expect(screen.getAllByText(ONE_WINDOW_TIP), "the one-window tip once, on the next line").toHaveLength(1);
    expect(screen.queryByText("Hidden while you share your screen"), "said once, in the line").toBeNull();
    expect(screen.queryByRole("button", { name: "Show EchoLedger windows" }), "it would last until the next look").toBeNull();
    // The bar was hidden (Zoom hid its controls): Show works again.
    respond("privacy_status", makePrivacyStatus({ platform: "macos", hiddenForShare: true, barOnScreen: false }));
    await act(async () => emitEvent("privacy:changed", null));
    expect(await screen.findByRole("button", { name: "Show EchoLedger windows" })).toBeInTheDocument();
    expect(screen.queryByTestId("back-after-share")).toBeNull();
  });

  it("labels every button to the system's settings the same way", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", permission: false }));
    render(<ShareCheckPage />);
    expect(within(await screen.findByTestId("permission-first")).getByRole("button", { name: OPEN_SYSTEM_SETTINGS })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Open Settings" })).toBeNull();
  });

  it("moves the floating windows only when one is open, and says what happened", async () => {
    respond("privacy_status", makePrivacyStatus({ displays: 2, floatingOpen: false }));
    render(<SharePrivacyPage settings={makeSettings()} patch={vi.fn(async () => true)} onCheck={() => undefined} />);
    expect(await screen.findByRole("button", { name: /Move to the other display/ })).toBeDisabled();
    expect(screen.getByText("Nothing open to move")).toBeInTheDocument();
  });

  it("shows why a move did not happen", async () => {
    respond("privacy_status", makePrivacyStatus({ displays: 2, floatingOpen: true }));
    fail("privacy_move_to_other_display", "Only one display is connected now.");
    render(<SharePrivacyPage settings={makeSettings()} patch={vi.fn(async () => true)} onCheck={() => undefined} />);
    fireEvent.click(await screen.findByRole("button", { name: /Move to the other display/ }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Only one display is connected now.");
  });
});

describe("the privacy badge", () => {
  beforeEach(() => resetTauriMock());

  it.each([
    ["private", "Private: hidden from screen share. Check screen-share privacy"],
    ["black_box", "Black box: people see a black box instead of EchoLedger. Check screen-share privacy"],
    ["not_checked", "Not checked on this system yet. Check screen-share privacy"],
    ["visible", "Visible in screen share. Check screen-share privacy"],
  ] as const)("%s opens Check screen-share privacy when clicked", async (badge, name) => {
    respond("privacy_status", makePrivacyStatus({ badge }));
    respond("privacy_open_check", null);
    render(<PrivacyBadge />);
    const button = await screen.findByRole("button", { name });
    expect(button).toHaveClass(badge);
    expect(button).toHaveAttribute("data-hit");
    fireEvent.click(button);
    expect(callsOf("privacy_open_check")).toHaveLength(1);
  });

  it("follows the status when the core says it changed", async () => {
    respond("privacy_status", makePrivacyStatus({ badge: "not_checked" }));
    render(<PrivacyBadge />);
    await screen.findByRole("button", { name: /Not checked/ });
    respond("privacy_status", makePrivacyStatus({ badge: "private" }));
    await act(async () => emitEvent("privacy:changed", null));
    expect(await screen.findByRole("button", { name: /hidden from screen share/i })).toBeInTheDocument();
  });

  it("names itself to a screen reader where it is not a button", async () => {
    render(<PrivacyBadgeView badge="not_checked" compact />);
    expect(screen.getByRole("img", { name: "Not checked" })).toBeInTheDocument();
  });
});

describe("hidden windows and the flags", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => resetTauriMock());

  it("says in the main window that it may be in the screen share once the person opened it during one", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", hiddenForShare: true, mainInShare: true }));
    render(<ShareNotice />);
    expect(await screen.findByText(SHARE_NOTICE)).toBeInTheDocument();
    expect(screen.getByTestId("main-in-share")).toHaveAttribute("role", "status");
  });

  it("says it again in the next share once closed in one", async () => {
    resetClosedBanners();
    respond("privacy_status", makePrivacyStatus({ platform: "macos", hiddenForShare: true, mainInShare: true, shareNumber: 1 }));
    const { unmount } = render(<ShareNotice />);
    const notice = await screen.findByTestId("main-in-share");
    fireEvent.click(within(notice).getByRole("button", { name: /close|dismiss/i }));
    expect(screen.queryByTestId("main-in-share")).toBeNull();
    unmount();
    // The same share: it stays closed.
    render(<ShareNotice />);
    await waitFor(() => expect(callsOf("privacy_status").length).toBeGreaterThan(1));
    expect(screen.queryByTestId("main-in-share")).toBeNull();
    // A later share, with the main window opened during it again.
    respond("privacy_status", makePrivacyStatus({ platform: "macos", hiddenForShare: true, mainInShare: true, shareNumber: 2 }));
    await act(async () => emitEvent("privacy:changed", null));
    expect(await screen.findByText(SHARE_NOTICE)).toBeInTheDocument();
  });

  it("says nothing when the main window is not on screen for a share", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", hiddenForShare: true, mainInShare: false }));
    const { container } = render(<ShareNotice />);
    await waitFor(() => expect(callsOf("privacy_status").length).toBeGreaterThan(0));
    expect(container).toBeEmptyDOMElement();
  });

  it("offers Hide again when something else changed a flag, and sets them again only when pressed", async () => {
    const changed = "Something on this computer turned off screen-share privacy for an EchoLedger window, so it is visible in screen share. EchoLedger left it as it is.";
    respond("privacy_status", makePrivacyStatus({ badge: "visible", badgeLabel: "Visible in screen share", lines: [{ warning: true, text: changed, fix: "hide_again" }] }));
    respond("privacy_hide_again", null);
    render(<SharePrivacyPage settings={makeSettings()} patch={async () => true} onCheck={() => undefined} />);
    expect(await screen.findByText(changed, { exact: false })).toBeInTheDocument();
    expect(callsOf("privacy_hide_again")).toHaveLength(0);
    fireEvent.click(screen.getByRole("button", { name: "Hide again" }));
    expect(callsOf("privacy_hide_again")).toHaveLength(1);
  });

  it("sends a Mac without Screen Recording to System Settings from the safety net's line", async () => {
    const line = "EchoLedger needs the Screen Recording permission to notice when you share your whole screen and hide its windows. Allow it in System Settings.";
    respond("privacy_status", makePrivacyStatus({ platform: "macos", permission: false, lines: [{ warning: true, text: line, fix: "open_settings" }] }));
    respond("open_privacy_settings", null);
    render(<SharePrivacyPage settings={makeSettings()} patch={async () => true} onCheck={() => undefined} />);
    fireEvent.click(await screen.findByRole("button", { name: "Open System Settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "screen" }]);
  });
});

describe("the last check's row", () => {
  it("says Black box for an older Windows that shows one, and Hidden otherwise", () => {
    const now = new Date(2026, 10, 16, 15, 0);
    const today = new Date(2026, 10, 16, 9, 0).toISOString();
    expect(lastCheckValue("pass", today, now, "old_windows")).toBe("Black box, today");
    expect(lastCheckValue("pass", today, now)).toBe("Hidden, today");
    expect(lastCheckValue(null, null, now)).toBe("Not checked yet");
  });
});

describe("the check's own windows and onboarding", () => {
  beforeEach(() => resetTauriMock());

  it("says on the green control what the flash is", () => {
    respond("share_check_drawn", null);
    render(<ShareCheckWindow kind="control" />);
    expect(screen.getByTestId("check-control")).toHaveTextContent(CONTROL_TEXT);
  });

  it("tells about the flash in onboarding and offers the fix the result names", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos" }));
    respond("share_check_run", view({ result: "error", reason: "capture_failed", picture: null, testArea: null, controlArea: null }));
    render(<OnboardingShareCheck platform="macos" />);
    expect(screen.getByText(PATTERN_FLASH)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Check screen-share privacy" }));
    fireEvent.click(await screen.findByRole("button", { name: "Try again" }));
    await waitFor(() => expect(callsOf("share_check_run")).toHaveLength(2));
  });

  it("has one row for the check, with the last result, and the amber lines in the first section's footer", async () => {
    respond("privacy_status", makePrivacyStatus({ lines: [{ warning: true, text: "In Remote Desktop, EchoLedger windows are visible to the remote side.", fix: null }] }));
    const onCheck = vi.fn();
    render(<SharePrivacyPage settings={makeSettings()} patch={vi.fn(async () => true)} onCheck={onCheck} />);
    const line = await screen.findByText("In Remote Desktop, EchoLedger windows are visible to the remote side.");
    expect(line.closest(".set-foot"), "under the first section, aligned with the footers").not.toBeNull();
    expect(screen.queryByRole("button", { name: "Check now" }), "no second Check now on this page").toBeNull();
    const row = screen.getByRole("button", { name: /Check screen-share privacy/ });
    expect(row).toHaveTextContent(/Hidden, today/);
    fireEvent.click(row);
    expect(onCheck).toHaveBeenCalled();
  });
});
