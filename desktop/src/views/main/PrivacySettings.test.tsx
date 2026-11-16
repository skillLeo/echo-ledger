import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
import { callsOf, emitEvent, resetTauriMock, respond } from "../../test/tauri";
import { makePrivacyStatus, makeSettings } from "../../test/fixtures";
import type { ShareCheckView } from "../../lib/ipc";
import { LIMITS, ONE_WINDOW_TIP, ShareCheckPage, SharePrivacyPage } from "./PrivacySettings";
import { PrivacyBadge } from "../../components/PrivacyBadge";

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
    expect(await screen.findByText("Hidden. People who see your whole screen won't see EchoLedger. Checked on macOS 27.3, today.")).toBeInTheDocument();
    expect(screen.getByText("What you see")).toBeInTheDocument();
    expect(screen.getByText("What others see")).toBeInTheDocument();
    expect(screen.getByAltText("Your screen as a screen share captured it")).toHaveAttribute("src", PICTURE);
    expect(callsOf("share_check_run")).toEqual([{ why: "asked" }]);
    expect(screen.getAllByRole("button").filter((b) => b.classList.contains("primary"))).toHaveLength(1);
  });

  it("says plainly when EchoLedger is visible", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", badge: "visible" }));
    respond("share_check_run", view({ result: "fail", methods: [{ method: "sck", result: "fail", reason: null }] }));
    respond("share_check_card", null);
    render(<ShareCheckPage />);
    fireEvent.click(await screen.findByRole("button", { name: "Check now" }));
    expect(await screen.findByText("Visible on this Mac in apps like Zoom and Teams. Use one-window sharing or your phone.")).toBeInTheDocument();
  });

  it("names a missing permission with Open Settings, and a failed capture with Try again", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "macos", permission: false }));
    respond("share_check_run", view({ result: "error", reason: "permission_missing", picture: null, testArea: null, controlArea: null }));
    respond("share_check_card", null);
    respond("open_privacy_settings", null);
    render(<ShareCheckPage />);
    fireEvent.click(await screen.findByRole("button", { name: "Check now" }));
    fireEvent.click(await screen.findByRole("button", { name: "Open Settings" }));
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
    fireEvent.click((await screen.findAllByRole("button", { name: "Test" }))[0]);
    expect(await screen.findByText(/A test card is on your screen now/)).toBeInTheDocument();
    expect(callsOf("share_check_card")).toEqual([{ open: true }]);
    fireEvent.click(screen.getByRole("button", { name: "I can't see it" }));
    await waitFor(() => expect(callsOf("share_check_answer")).toEqual([{ meetingApp: "zoom", visible: false }]));
  });
});

describe("Settings › Screen-share privacy", () => {
  beforeEach(() => resetTauriMock());

  it("shows the status, the last check, the tip and the limits; Windows' amber lines", async () => {
    respond("privacy_status", makePrivacyStatus({ badge: "visible", badgeLabel: "Visible in shares", oldWindows: true, lines: [{ warning: true, text: "In Remote Desktop, EchoLedger windows are visible to the remote side." }] }));
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
    expect(screen.getByTestId("zoom-steps")).toHaveTextContent("Settings › Share Screen › Advanced and set Screen capture mode to Advanced capture with window filtering");
    expect(screen.getByRole("switch", { name: "I share one window or a tab" })).toBeInTheDocument();
  });
});

describe("the privacy badge", () => {
  beforeEach(() => resetTauriMock());

  it.each([
    ["private", "Hidden from screen share. Open Share Check"],
    ["not_checked", "Not checked on this system yet. Open Share Check"],
    ["visible", "EchoLedger can show in screen shares. Open Share Check"],
  ] as const)("%s opens Share Check when clicked", async (badge, name) => {
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
    expect(await screen.findByRole("button", { name: /Hidden from screen share/ })).toBeInTheDocument();
  });
});
