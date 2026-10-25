import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import MainApp from "./MainApp";
import { activityReporter, LockScreen } from "./LockScreen";
import { eventLine, SecurityCard } from "./SecuritySettings";
import type { LockStatus } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const lockStatus = (overrides: Partial<LockStatus> = {}): LockStatus => ({ enabled: true, locked: true, hasPin: true, osMethod: null, retryAfterSeconds: 0, ...overrides });

beforeEach(() => resetTauriMock());

describe("activity reporting", () => {
  it("tells the core at most every 30 seconds", () => {
    let t = 0;
    const send = vi.fn();
    const report = activityReporter(send, 30_000, () => t);
    report();
    t = 10_000;
    report();
    t = 30_000;
    report();
    expect(send).toHaveBeenCalledTimes(2);
  });
});

describe("lock screen (B16)", () => {
  it("unlocks with the PIN and keeps digits only", async () => {
    respond("lock_unlock_pin", lockStatus({ locked: false }));
    const onUnlocked = vi.fn();
    render(<LockScreen status={lockStatus()} onUnlocked={onUnlocked} />);
    expect(screen.getByRole("heading", { name: "EchoLedger is locked" })).toBeInTheDocument();
    expect(screen.getByText("Recording and uploads keep running while the app is locked.")).toBeInTheDocument();
    const box = screen.getByLabelText("PIN");
    fireEvent.change(box, { target: { value: "12a34" } });
    expect(box).toHaveValue("1234");
    fireEvent.click(screen.getByRole("button", { name: "Unlock" }));
    await waitFor(() => expect(onUnlocked).toHaveBeenCalledWith(lockStatus({ locked: false })));
    expect(callsOf("lock_unlock_pin")).toEqual([{ pin: "1234" }]);
  });

  it("says when the PIN is wrong or tries must wait, and clears the box", async () => {
    fail("lock_unlock_pin", "That PIN is not right.");
    render(<LockScreen status={lockStatus()} onUnlocked={vi.fn()} />);
    fireEvent.change(screen.getByLabelText("PIN"), { target: { value: "9999" } });
    fireEvent.click(screen.getByRole("button", { name: "Unlock" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("That PIN is not right.");
    expect(screen.getByLabelText("PIN")).toHaveValue("");
  });

  it("asks Windows Hello straight away and offers it again", async () => {
    let answers = [lockStatus(), lockStatus({ locked: false })];
    respondWith("lock_unlock_os", () => answers.shift());
    const onUnlocked = vi.fn();
    render(<LockScreen status={lockStatus({ osMethod: "windows_hello" })} onUnlocked={onUnlocked} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("Not verified. Try again or use your PIN.");
    fireEvent.click(screen.getByRole("button", { name: "Use Windows Hello" }));
    await waitFor(() => expect(onUnlocked).toHaveBeenCalled());
    expect(callsOf("lock_unlock_os")).toHaveLength(2);
    answers = [];
  });

  it("offers Touch ID by name on a Mac", async () => {
    respond("lock_unlock_os", lockStatus());
    render(<LockScreen status={lockStatus({ osMethod: "touch_id", hasPin: false })} onUnlocked={vi.fn()} />);
    expect(screen.getByRole("button", { name: "Use Touch ID" })).toBeInTheDocument();
    expect(screen.queryByLabelText("PIN")).not.toBeInTheDocument();
    await flushAsync();
  });
});

describe("the main window follows the lock", () => {
  beforeEach(() => {
    respond("app_status", makeStatus());
    respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("lock_touch", undefined);
  });

  it("shows only the lock screen while locked and the app again once unlocked", async () => {
    respond("lock_status", lockStatus());
    render(<MainApp />);
    expect(await screen.findByRole("heading", { name: "EchoLedger is locked" })).toBeInTheDocument();
    expect(screen.queryByText("Zoom with Acme")).not.toBeInTheDocument();

    await emitEvent("lock:changed", lockStatus({ locked: false }));
    expect(await screen.findByText("Zoom with Acme")).toBeInTheDocument();
    await emitEvent("lock:changed", lockStatus({ locked: true }));
    expect(await screen.findByRole("heading", { name: "EchoLedger is locked" })).toBeInTheDocument();
  });

  it("reports use to reset the lock timer", async () => {
    respond("lock_status", lockStatus({ locked: false }));
    render(<MainApp />);
    await screen.findByText("Zoom with Acme");
    fireEvent.keyDown(window, { key: "a" });
    fireEvent.pointerDown(window);
    expect(callsOf("lock_touch")).toHaveLength(1);
  });
});

describe("privacy & security settings", () => {
  const run = vi.fn(async <T,>(fn: () => Promise<T>) => {
    try {
      return await fn();
    } catch {
      return undefined;
    }
  });
  const overview = {
    devices: [
      { id: "d1", name: "Work laptop", platform: "windows", app_version: "1.1.0", last_seen_at: "2026-10-28T09:00:00Z", paired_at: null, current: true },
      { id: "d2", name: "Old phone", platform: "android", app_version: "1.0.0", last_seen_at: null, paired_at: null, current: false },
    ],
    events: [{ id: 2, event: "session_deleted", label: "Recording deleted", summary: "Recording deleted: Acme kickoff", actor: "device" as const, device: "Old phone", at: "2026-10-28T09:00:00Z" }],
  };

  beforeEach(() => {
    run.mockClear();
    respond("security_overview", overview);
  });

  it("describes who did each audited action", () => {
    expect(eventLine({ summary: "Signed in", actor: "web", device: null })).toBe("Signed in · Console");
    expect(eventLine({ summary: "Recording deleted", actor: "device", device: null })).toBe("Recording deleted · A device");
    expect(eventLine({ summary: "Recording deleted", actor: "system", device: null })).toBe("Recording deleted · EchoLedger");
  });

  it("sets a PIN, then turns the lock on and picks when it locks", async () => {
    respond("lock_status", lockStatus({ locked: false, enabled: false, hasPin: false }));
    respond("lock_set_pin", lockStatus({ locked: false, enabled: false, hasPin: true }));
    const patch = vi.fn(async () => true);
    render(<SecurityCard settings={makeSettings()} patch={patch} run={run} />);
    expect(await screen.findByText(/Set a PIN first/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("PIN"), { target: { value: "2468" } });
    fireEvent.change(screen.getByLabelText("Repeat the PIN"), { target: { value: "2469" } });
    expect(screen.getByText("The PINs differ.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Set PIN" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Repeat the PIN"), { target: { value: "2468" } });
    fireEvent.click(screen.getByRole("button", { name: "Set PIN" }));
    await waitFor(() => expect(callsOf("lock_set_pin")).toEqual([{ pin: "2468" }]));
    expect(await screen.findByRole("button", { name: "Change PIN" })).toBeInTheDocument();

    fireEvent.click(screen.getByRole("switch", { name: "Lock EchoLedger" }));
    fireEvent.change(screen.getByRole("combobox", { name: "Lock when not used" }), { target: { value: "15" } });
    expect(patch.mock.calls).toEqual([[{ appLock: true }], [{ lockAfterMinutes: 15 }]]);
  });

  it("lists devices, revokes another one and shows recent activity", async () => {
    respond("lock_status", lockStatus({ locked: false }));
    respond("device_revoke", undefined);
    vi.spyOn(window, "confirm").mockReturnValue(true);
    render(<SecurityCard settings={makeSettings({ appLock: true })} patch={vi.fn(async () => true)} run={run} />);
    const list = await screen.findByRole("list", { name: "Paired devices" });
    expect(within(list).getByText("this computer", { exact: false })).toBeInTheDocument();
    expect(within(list).getAllByRole("button", { name: "Revoke" })).toHaveLength(1);
    fireEvent.click(within(list).getByRole("button", { name: "Revoke" }));
    await waitFor(() => expect(callsOf("device_revoke")).toEqual([{ id: "d2" }]));
    expect(screen.getByRole("list", { name: "Recent activity" })).toHaveTextContent("Recording deleted: Acme kickoff · Old phone");

    respond("lock_now", lockStatus());
    fireEvent.click(screen.getByRole("button", { name: "Lock now" }));
    await waitFor(() => expect(callsOf("lock_now")).toHaveLength(1));
  });
});
