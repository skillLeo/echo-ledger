import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { CUSTOM_RANGE, HideDurations, MainShareControl, PinnedShareControl, WindowShareBadge, customMinutes, hideChoices, mainShareValue } from "./ShareVisibility";
import { ShareDefaultNotice } from "../views/main/ShareDefaultNotice";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond } from "../test/tauri";
import { makePrivacyStatus } from "../test/fixtures";
import { resetClosedBanners } from "./StateBanner";
import type { WindowShareView } from "../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

const view = (over: Partial<WindowShareView> = {}): WindowShareView => ({
  which: "card", shown: false, state: "hidden", badge: "Hidden from screen shares", switchLabel: "Show this card in screen shares", ...over,
});

beforeEach(() => {
  resetTauriMock();
  resetClosedBanners();
  respond("privacy_status", makePrivacyStatus());
  respond("privacy_main_hide", makePrivacyStatus());
  respond("privacy_main_show", makePrivacyStatus());
  respond("share_notice_dismiss", null);
});
afterEach(cleanup);

describe("the main window's durations", () => {
  it("offers Until this call ends only while a call is detected", () => {
    expect(hideChoices(false).map((c) => c.label)).toEqual(["15 min", "1 hour", "Until tomorrow", "Until I turn it off", "Custom…"]);
    expect(hideChoices(true).map((c) => c.label)).toEqual(["Until this call ends", "15 min", "1 hour", "Until tomorrow", "Until I turn it off", "Custom…"]);
  });

  it("takes a custom time from 1 minute to 7 days", () => {
    expect(customMinutes(1, "minutes")).toBe(1);
    expect(customMinutes(7, "days")).toBe(7 * 24 * 60);
    expect(customMinutes(2, "hours")).toBe(120);
    expect(customMinutes(0, "minutes")).toBeNull();
    expect(customMinutes(8, "days")).toBeNull();
    expect(customMinutes(Number.NaN, "minutes")).toBeNull();
  });

  it("sends each choice as the core reads it", async () => {
    const expected = [{ kind: "call_ends" }, { kind: "minutes15" }, { kind: "hour1" }, { kind: "tomorrow" }, { kind: "until_off" }];
    render(<HideDurations callDetected />);
    for (const label of ["Until this call ends", "15 min", "1 hour", "Until tomorrow", "Until I turn it off"]) {
      fireEvent.click(screen.getByRole("menuitem", { name: label }));
      await act(flushAsync);
    }
    expect(callsOf("privacy_main_hide").map((c) => c.choice)).toEqual(expected);
  });

  it("Custom… sends minutes, and refuses a time outside 1 minute to 7 days before sending anything", async () => {
    render(<HideDurations callDetected={false} />);
    fireEvent.click(screen.getByRole("menuitem", { name: "Custom…" }));
    fireEvent.change(screen.getByLabelText("How long"), { target: { value: "8" } });
    fireEvent.change(screen.getByLabelText("Unit"), { target: { value: "days" } });
    fireEvent.click(screen.getByRole("button", { name: "Hide" }));
    expect(screen.getByRole("alert")).toHaveTextContent(CUSTOM_RANGE);
    expect(callsOf("privacy_main_hide")).toEqual([]);
    fireEvent.change(screen.getByLabelText("How long"), { target: { value: "90" } });
    fireEvent.change(screen.getByLabelText("Unit"), { target: { value: "minutes" } });
    fireEvent.click(screen.getByRole("button", { name: "Hide" }));
    await act(flushAsync);
    expect(callsOf("privacy_main_hide")).toEqual([{ choice: { kind: "custom", minutes: 90 } }]);
  });

  it("says shown, or hidden until when with Show now", async () => {
    expect(mainShareValue(makePrivacyStatus())).toBe("Shown in screen shares");
    const hidden = makePrivacyStatus({ mainHide: { until: { kind: "at", at_ms: 1 }, sinceMs: 0 }, mainHiddenUntil: "until 14:30" });
    expect(mainShareValue(hidden)).toBe("Hidden until 14:30");
    render(<MainShareControl status={hidden} />);
    fireEvent.click(screen.getByRole("button", { name: "Show now" }));
    await act(flushAsync);
    expect(callsOf("privacy_main_show")).toHaveLength(1);
    cleanup();
    render(<MainShareControl status={makePrivacyStatus()} />);
    fireEvent.click(screen.getByRole("button", { name: "Hide from screen shares" }));
    expect(screen.getByTestId("share-durations")).toBeInTheDocument();
  });
});

describe("the card's and the captions' badge", () => {
  it("shows what the system reads back, not the saved choice", async () => {
    // The person never showed the card, yet the system did not keep it hidden: the badge says so plainly.
    respond("privacy_window_share", view({ state: "could_not_hide", badge: "Couldn't hide this from screen shares" }));
    render(<WindowShareBadge />);
    await act(flushAsync);
    const badge = screen.getByTestId("window-share-badge");
    expect(badge).toHaveAccessibleName("Couldn't hide this from screen shares");
    expect(badge).toHaveTextContent("Couldn't hide this from screen shares");
    expect(callsOf("privacy_window_share")).toEqual([{ which: null }]);
  });

  it("asks again on every privacy change", async () => {
    respond("privacy_window_share", view());
    render(<WindowShareBadge />);
    await act(flushAsync);
    expect(screen.getByTestId("window-share-badge")).toHaveAccessibleName("Hidden from screen shares");
    respond("privacy_window_share", view({ state: "shown", shown: true, badge: "Shown in screen shares" }));
    await act(async () => emitEvent("privacy:changed"));
    await act(flushAsync);
    expect(screen.getByTestId("window-share-badge")).toHaveAccessibleName("Shown in screen shares");
  });

  it("is shown in screen shares only by the person's explicit choice on that window", async () => {
    respond("privacy_window_share", view());
    respond("privacy_window_share_set", view({ shown: true, state: "shown", badge: "Shown in screen shares", switchLabel: "Hide this card from screen shares" }));
    render(<WindowShareBadge />);
    await act(flushAsync);
    expect(callsOf("privacy_window_share_set")).toEqual([], "nothing changes by itself");
    fireEvent.click(screen.getByTestId("window-share-badge"));
    fireEvent.click(screen.getByRole("menuitem", { name: "Show this card in screen shares" }));
    await act(flushAsync);
    expect(callsOf("privacy_window_share_set")).toEqual([{ which: null, shown: true }]);
    expect(screen.getByTestId("window-share-badge")).toHaveAccessibleName("Shown in screen shares");
  });

  it("from Settings names the window, never shows it, and can only hide it again", async () => {
    respond("privacy_window_share", view({ which: "captions", switchLabel: "Show captions in screen shares" }));
    render(<PinnedShareControl which="captions" />);
    await act(flushAsync);
    expect(callsOf("privacy_window_share")).toEqual([{ which: "captions" }]);
    expect(screen.queryByRole("button")).toBeNull();
    expect(screen.getByText("Show them from the captions' badge.")).toBeInTheDocument();
    cleanup();
    respond("privacy_window_share", view({ which: "captions", shown: true, state: "shown", badge: "Shown in screen shares", switchLabel: "Hide captions from screen shares" }));
    respond("privacy_window_share_set", view({ which: "captions" }));
    render(<PinnedShareControl which="captions" />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Hide captions from screen shares" }));
    await act(flushAsync);
    expect(callsOf("privacy_window_share_set")).toEqual([{ which: "captions", shown: false }]);
  });

  it("says a window that is not open is hidden, and an older Windows' black box as such", async () => {
    respond("privacy_window_share", view({ state: "not_open", badge: "Hidden from screen shares" }));
    render(<PinnedShareControl which="card" />);
    await act(flushAsync);
    expect(screen.getByTestId("window-share-card")).toHaveTextContent("Hidden from screen shares");
    expect(screen.queryByText(/Couldn't hide/)).toBeNull();
    cleanup();
    respond("privacy_window_share", view({ state: "black_box", badge: "Shows as a black box" }));
    render(<WindowShareBadge />);
    await act(flushAsync);
    expect(screen.getByTestId("window-share-badge")).toHaveAccessibleName("Shows as a black box");
  });
});

describe("the one-time notice", () => {
  const TEXT = "EchoLedger now shows in screen shares unless you hide it.";

  it("says it with Hide ▸, which opens the durations", async () => {
    render(<ShareDefaultNotice text={TEXT} />);
    await act(flushAsync);
    expect(screen.getByTestId("share-default-notice")).toHaveTextContent(TEXT);
    fireEvent.click(screen.getByRole("button", { name: "Hide ▸" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "1 hour" }));
    await act(flushAsync);
    expect(callsOf("privacy_main_hide")).toEqual([{ choice: { kind: "hour1" } }]);
    expect(screen.queryByTestId("share-default-notice")).toBeNull();
  });

  it("is shown once: closing it is remembered by the core, and nothing shows without it", async () => {
    render(<ShareDefaultNotice text={TEXT} />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: /close|dismiss/i }));
    await act(flushAsync);
    expect(callsOf("share_notice_dismiss")).toHaveLength(1);
    expect(screen.queryByTestId("share-default-notice")).toBeNull();
    cleanup();
    render(<ShareDefaultNotice text={null} />);
    expect(screen.queryByTestId("share-default-notice")).toBeNull();
  });
});
