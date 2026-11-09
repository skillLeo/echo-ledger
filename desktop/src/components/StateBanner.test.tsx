import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { resetClosedBanners, StateBanner } from "./StateBanner";
import { HomeView } from "../views/main/HomeView";
import { UpdateBanner } from "../views/main/UpdateSettings";
import { makeStatus } from "../test/fixtures";
import { callsOf, flushAsync, resetTauriMock, respond } from "../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

describe("a banner about something still true (docs/DESIGN_SYSTEM.md Notices 3)", () => {
  const banner = (state: string, onOpen = vi.fn()) => (
    <StateBanner id="calls" state={state} testId="calls" onOpen={onOpen}>Calls are not being recorded</StateBanner>
  );

  it("has no timer, goes to its fix when clicked, and has ×", () => {
    const onOpen = vi.fn();
    render(banner("mic-off", onOpen));
    expect(screen.getByTestId("calls").querySelector(".notice-timer")).toBeNull();
    expect(screen.getByRole("button", { name: "Dismiss" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Calls are not being recorded" }));
    expect(onOpen).toHaveBeenCalled();
    // It closes as it goes (Notices 1).
    expect(screen.queryByTestId("calls")).not.toBeInTheDocument();
  });

  it("stays closed until its state changes or the app starts again", () => {
    const view = render(banner("mic-off"));
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    expect(screen.queryByTestId("calls")).not.toBeInTheDocument();
    view.rerender(banner("mic-off"));
    expect(screen.queryByTestId("calls")).not.toBeInTheDocument();
    view.rerender(banner("detection-off"));
    expect(screen.getByTestId("calls")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    view.unmount();
    resetClosedBanners();
    render(banner("detection-off"));
    expect(screen.getByTestId("calls")).toBeInTheDocument();
  });

  it("closes with a swipe too", () => {
    render(banner("mic-off"));
    const shell = screen.getByTestId("calls");
    shell.getBoundingClientRect = () => ({ width: 300, height: 44, top: 0, left: 0, right: 300, bottom: 44, x: 0, y: 0, toJSON: () => ({}) });
    const matchMedia = window.matchMedia;
    window.matchMedia = ((q: string) => ({ matches: q.includes("reduce"), media: q, addEventListener() {}, removeEventListener() {} })) as unknown as typeof window.matchMedia;
    try {
      fireEvent.pointerDown(shell, { pointerId: 1, button: 0, clientX: 200, clientY: 10 });
      fireEvent.pointerMove(shell, { pointerId: 1, clientX: 40, clientY: 12 });
      fireEvent.pointerUp(shell, { pointerId: 1, clientX: 40, clientY: 12 });
    } finally {
      window.matchMedia = matchMedia;
    }
    expect(screen.queryByTestId("calls")).not.toBeInTheDocument();
  });
});

describe("Home's cards and the update banner", () => {
  it("open what they are about, and close until their count changes", async () => {
    resetTauriMock();
    respond("library_list", []);
    respond("mic_users", []);
    respond("ready_last", null);
    respond("ready_check", { ready: true, checks: [], checkedAt: null });
    const onReview = vi.fn();
    const props = { run: vi.fn() as never, needsYou: [], onReview, onFailed: vi.fn(), onActivity: vi.fn(), onOpenRecording: vi.fn(), onLibrary: vi.fn() };
    const view = render(<HomeView status={makeStatus({ pendingDecisions: 2 })} {...props} />);
    await flushAsync();
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    expect(screen.queryByText("Keep or discard?")).not.toBeInTheDocument();
    view.rerender(<HomeView status={makeStatus({ pendingDecisions: 2 })} {...props} />);
    expect(screen.queryByText("Keep or discard?")).not.toBeInTheDocument();
    view.rerender(<HomeView status={makeStatus({ pendingDecisions: 3 })} {...props} />);
    expect(screen.getByText("Keep or discard?")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: /Keep or discard\?/ }));
    expect(onReview).toHaveBeenCalledTimes(1);
  });

  it("the update banner opens the updates, and its Restart is its own", async () => {
    resetTauriMock();
    respond("update_status", { phase: "ready", current: "1.6.0", version: "1.6.1", notes: null, checkedAt: null, message: null, progress: null });
    respond("update_install", undefined);
    const onOpen = vi.fn();
    render(<UpdateBanner appStatus={makeStatus()} notify={vi.fn()} onOpen={onOpen} />);
    const banner = await screen.findByTestId("update-banner");
    fireEvent.click(screen.getByRole("button", { name: "Restart" }));
    await flushAsync();
    expect(callsOf("update_install")).toHaveLength(1);
    expect(onOpen).not.toHaveBeenCalled();
    fireEvent.click(banner);
    expect(onOpen).toHaveBeenCalledTimes(1);
  });
});
