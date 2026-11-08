import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { ReadyCheckCard, ReadyChip, chipText, readyHeadline } from "./ReadyCheck";
import MainApp from "./MainApp";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import type { ReadyCheck, ReadyReport } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const check = (id: ReadyCheck["id"], ok: boolean, extra: Partial<ReadyCheck> = {}): ReadyCheck => ({ id, label: id, ok, detail: null, fix: null, ...extra });
const READY: ReadyReport = {
  ready: true,
  checks: [check("account", true), check("microphone", true), check("microphone_permission", true), check("other_side", true), check("detection", true)],
  checkedAt: "2026-11-08T10:00:00Z",
};
const BROKEN: ReadyReport = {
  ready: false,
  checks: [
    check("account", true),
    check("microphone", true),
    check("microphone_permission", true),
    check("other_side", false, { label: "The other side of calls", detail: "EchoLedger may not record this computer's sound: only your voice would be recorded.", fix: { label: "Open System Settings", action: "allow_system_audio" } }),
    check("detection", false, { label: "Meetings recorded by themselves", detail: "Record calls automatically is off.", fix: { label: "Turn on", action: "turn_on_auto_mode" } }),
  ],
  checkedAt: "2026-11-08T10:00:00Z",
};

beforeEach(() => {
  resetTauriMock();
});

describe("the Ready check", () => {
  it("says it in one line: ready, or how many things to fix", () => {
    expect(readyHeadline(READY)).toBe("✓ Ready to record calls");
    expect(readyHeadline(BROKEN)).toBe("2 things to fix before calls are recorded");
    expect(readyHeadline({ ready: false, checks: [check("microphone", false)] })).toBe("1 thing to fix before calls are recorded");
    expect(chipText(true)).toBe("Calls: Ready");
    expect(chipText(false)).toBe("Not ready — Fix");
    expect(chipText(null)).toBeNull();
    expect(chipText(undefined)).toBeNull();
  });

  it("shows the last report, problems first, each with a one-click fix that checks again", async () => {
    respond("ready_last", BROKEN);
    respondWith("ready_fix", () => READY);
    render(<ReadyCheckCard />);
    await flushAsync();
    expect(screen.getByTestId("ready-card")).toHaveTextContent("2 things to fix before calls are recorded");
    const rows = screen.getAllByRole("listitem");
    expect(rows[0]).toHaveAttribute("data-testid", "ready-other_side");
    expect(rows[1]).toHaveAttribute("data-testid", "ready-detection");
    expect(rows[0]).toHaveTextContent("only your voice would be recorded");
    fireEvent.click(screen.getByRole("button", { name: "Turn on" }));
    await flushAsync();
    expect(callsOf("ready_fix")).toEqual([{ action: "turn_on_auto_mode" }]);
    expect(screen.getByTestId("ready-card")).toHaveTextContent("✓ Ready to record calls");
    expect(callsOf("ready_check")).toHaveLength(0);
  });

  it("runs a check when none was made yet, again on request, and follows the core's answers", async () => {
    respond("ready_last", null);
    respond("ready_check", READY);
    render(<ReadyCheckCard />);
    await flushAsync();
    expect(callsOf("ready_check")).toHaveLength(1);
    expect(screen.getByTestId("ready-card")).toHaveTextContent("✓ Ready to record calls");
    fireEvent.click(screen.getByRole("button", { name: "Check again" }));
    await flushAsync();
    expect(callsOf("ready_check")).toHaveLength(2);
    await emitEvent("ready:changed", BROKEN);
    expect(screen.getByTestId("ready-card")).toHaveTextContent("2 things to fix");
  });

  it("has a chip that says Calls: Ready or Not ready — Fix and opens the check", () => {
    const onOpen = vi.fn();
    const { rerender } = render(<ReadyChip status={{ callsReady: true, readyProblems: 0 }} onOpen={onOpen} />);
    expect(screen.getByTestId("ready-chip")).toHaveTextContent("Calls: Ready");
    rerender(<ReadyChip status={{ callsReady: false, readyProblems: 2 }} onOpen={onOpen} />);
    expect(screen.getByTestId("ready-chip")).toHaveTextContent("Not ready — Fix");
    fireEvent.click(screen.getByTestId("ready-chip"));
    expect(onOpen).toHaveBeenCalledTimes(1);
    rerender(<ReadyChip status={{ callsReady: null }} onOpen={onOpen} />);
    expect(screen.queryByTestId("ready-chip")).not.toBeInTheDocument();
  });
});

describe("in the app", () => {
  function backend(status = makeStatus({ callsReady: false, readyProblems: 1 })) {
    respond("app_status", status);
    respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("ready_last", BROKEN);
  }

  it("the sidebar chip opens the Record page with the Ready check", async () => {
    backend();
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Library" });
    fireEvent.click(await screen.findByTestId("ready-chip"));
    expect(await screen.findByRole("heading", { name: "Record" })).toBeInTheDocument();
    expect(screen.getByTestId("ready-card")).toBeInTheDocument();
  });

  it("says Only your voice was recorded after a call, with why and the fix, until dismissed", async () => {
    backend(makeStatus({ callsReady: true }));
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Library" });
    await flushAsync();
    await emitEvent("capture:one_sided", { title: "Only your voice was recorded", reason: "Nothing was heard from the call app.", fix: "Play the test sound in Settings → Recording." });
    const banner = screen.getByTestId("one-sided");
    expect(banner).toHaveTextContent("Only your voice was recorded");
    expect(banner).toHaveTextContent("Nothing was heard from the call app. Play the test sound");
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    expect(screen.queryByTestId("one-sided")).not.toBeInTheDocument();
  });
});
