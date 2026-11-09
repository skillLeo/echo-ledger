import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MacCheckCard, sendBlocked } from "./MacCheck";
import { SettingsView } from "./SettingsView";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondAll, respondWith } from "../../test/tauri";
import { makeRecording, makeSettings, makeStatus } from "../../test/fixtures";
import type { CheckItem, CheckReport } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
vi.mock("@tauri-apps/api/app", () => ({ getVersion: async () => "1.5.0" }));

const ok: CheckItem = { key: "macos_version", title: "macOS version", state: "ok", detail: "macOS 15.1: call audio works on this version.", fix: null };
const off: CheckItem = {
  key: "system_audio_permission",
  title: "System audio permission",
  state: "off",
  detail: "Off. Without it a recording has your voice, not the other side of the call.",
  fix: { label: "Open System Settings", settings: "system_audio" },
};
const report = (overrides: Partial<CheckReport> = {}): CheckReport => ({
  items: [ok, off],
  stopped: null,
  finishedAt: "2026-11-06T12:00:00Z",
  appVersion: "1.5.0",
  osVersion: "15.1",
  model: "Mac14,2",
  sentAt: null,
  ...overrides,
});
const macStatus = (overrides = {}) => makeStatus({ platform: "macos", ...overrides });
const run = () => screen.getByRole("button", { name: /Run Mac check/ });

async function renderCard(status = macStatus()) {
  const view = render(<MacCheckCard status={status} />);
  await flushAsync();
  return view;
}

describe("Run Mac check", () => {
  beforeEach(() => {
    resetTauriMock();
    respondAll({ mac_check_last: null, open_privacy_settings: undefined, mac_check_copy: undefined });
  });

  it("runs one check at a time with a progress line, then shows a mark, the sentence and the fix per item", async () => {
    let finish: (r: CheckReport) => void = () => undefined;
    respondWith("mac_check_run", () => new Promise((resolve) => { finish = resolve; }));
    await renderCard();
    fireEvent.click(run());
    await flushAsync();
    expect(screen.getByRole("button", { name: "Checking…" })).toBeDisabled();

    await emitEvent("mac_check:progress", { index: 4, total: 9, line: "Listening to the microphone for 5 seconds: say something…", done: [ok] });
    expect(screen.getByTestId("mac-check-progress")).toHaveTextContent("5 of 9: Listening to the microphone for 5 seconds");
    expect(screen.getByTestId("check-macos_version")).toBeInTheDocument();

    finish(report());
    await flushAsync();
    expect(screen.queryByTestId("mac-check-progress")).not.toBeInTheDocument();
    const good = screen.getByTestId("check-macos_version");
    expect(within(good).getByRole("img", { name: "OK" })).toBeInTheDocument();
    expect(good).toHaveTextContent("call audio works");
    expect(within(good).queryByRole("button")).not.toBeInTheDocument();

    const bad = screen.getByTestId("check-system_audio_permission");
    expect(within(bad).getByRole("img", { name: "Off" })).toBeInTheDocument();
    expect(bad).toHaveTextContent("your voice, not the other side");
    fireEvent.click(within(bad).getByRole("button", { name: "Open System Settings" }));
    expect(callsOf("open_privacy_settings")[0]).toEqual({ kind: "system_audio" });
  });

  it("says nothing runs during a recording, and offers nothing", async () => {
    await renderCard(macStatus({ recording: makeRecording() }));
    expect(screen.getByTestId("mac-check-recording")).toHaveTextContent("Finish the current recording");
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("does not offer it during dictation either", async () => {
    await renderCard(macStatus({ dictationActive: true }));
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("says when a recording stopped the check, and keeps what was done", async () => {
    respond("mac_check_run", report({ items: [ok], stopped: "A recording started, so the check stopped." }));
    await renderCard();
    fireEvent.click(run());
    await flushAsync();
    expect(screen.getByText("A recording started, so the check stopped.")).toBeInTheDocument();
    expect(screen.getByTestId("check-macos_version")).toBeInTheDocument();
  });

  it("shows why it could not run", async () => {
    fail("mac_check_run", "Finish the current recording to run the Mac check.");
    await renderCard();
    fireEvent.click(run());
    await flushAsync();
    expect(screen.getByText("Finish the current recording to run the Mac check.")).toBeInTheDocument();
    expect(run()).toBeEnabled();
  });

  it("copies the report only when asked", async () => {
    respond("mac_check_run", report());
    await renderCard();
    expect(screen.queryByRole("button", { name: "Copy report" })).not.toBeInTheDocument();
    fireEvent.click(run());
    await flushAsync();
    expect(callsOf("mac_check_copy")).toHaveLength(0);
    fireEvent.click(screen.getByRole("button", { name: "Copy report" }));
    await flushAsync();
    expect(callsOf("mac_check_copy")).toHaveLength(1);
    expect(screen.getByRole("button", { name: "Copied" })).toBeInTheDocument();
  });

  it("uploads only on the tap and says when it was sent", async () => {
    respond("mac_check_run", report());
    respond("mac_check_send", "2026-11-06T12:05:00Z");
    await renderCard();
    fireEvent.click(run());
    await flushAsync();
    expect(callsOf("mac_check_send")).toHaveLength(0);
    expect(screen.queryByTestId("mac-check-sent")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Send to my admin" }));
    await flushAsync();
    expect(callsOf("mac_check_send")).toHaveLength(1);
    expect(screen.getByTestId("mac-check-sent")).toHaveTextContent(/^Sent to your admin .+\.$/);
  });

  it("disables Send when signed out and says why", async () => {
    respond("mac_check_run", report());
    await renderCard(macStatus({ paired: false }));
    fireEvent.click(run());
    await flushAsync();
    expect(screen.getByRole("button", { name: "Send to my admin" })).toBeDisabled();
    expect(screen.getByTestId("mac-check-send-blocked")).toHaveTextContent("Sign in to send this to your admin.");
    expect(sendBlocked({ paired: false })).toBeTruthy();
    expect(sendBlocked({ paired: true })).toBeNull();
    expect(screen.getByRole("button", { name: "Copy report" })).toBeEnabled();
  });

  it("shows a failed send and keeps the report", async () => {
    respond("mac_check_run", report());
    fail("mac_check_send", "This server does not take device reports yet. Copy the report instead.");
    await renderCard();
    fireEvent.click(run());
    await flushAsync();
    fireEvent.click(screen.getByRole("button", { name: "Send to my admin" }));
    await flushAsync();
    expect(screen.getByText(/does not take device reports/)).toBeInTheDocument();
    expect(screen.getByTestId("check-macos_version")).toBeInTheDocument();
  });

  it("shows the last report again when the page opens", async () => {
    respond("mac_check_last", report({ sentAt: "2026-11-06T12:05:00Z" }));
    await renderCard();
    expect(screen.getByTestId("check-macos_version")).toBeInTheDocument();
    expect(screen.getByTestId("mac-check-sent")).toBeInTheDocument();
  });
});

describe("Help & diagnostics in Settings", () => {
  async function renderSettings(platform: "macos" | "windows") {
    resetTauriMock();
    respondAll({
      get_settings: makeSettings(),
      list_clients: { data: [] },
      account_get: null,
      permissions_status: { microphone: "allowed", browserAutomation: false, platform },
      input_devices: [],
      mac_check_last: null,
      screens_status: { supported: true, platform, borderless: true, permission: true, inPlan: true, onServer: true },
      storage_info: { recordings_bytes: 0, recordings_count: 0, disk_free_bytes: 1, disk_total_bytes: 2, retention_days: 30 },
      lock_status: { locked: false, hasPin: false, biometric: false },
      security_overview: { devices: [], events: [] },
      update_status: { phase: "idle", current: "1.5.0" },
      bin_list: { data: [] },
      dictation_history: [],
    });
    respond("account_get", null);
    render(<SettingsView status={makeStatus({ platform })} run={async (fn) => fn()} onUnpaired={vi.fn()} />);
    await waitFor(() => expect(screen.getByRole("heading", { level: 1, name: "Settings" })).toBeInTheDocument());
    await flushAsync();
  }
  const open = (name: string) => fireEvent.click(within(screen.getByRole("navigation", { name: "Settings" })).getByRole("button", { name }));

  it("is there on a Mac under Help and is found by search", async () => {
    await renderSettings("macos");
    fireEvent.change(screen.getByRole("textbox", { name: "Search settings" }), { target: { value: "mac check" } });
    fireEvent.click(screen.getByRole("option", { name: /^Mac check/ }));
    expect(await screen.findByRole("heading", { name: "Mac check", level: 1 })).toBeInTheDocument();
    expect(await screen.findByTestId("mac-check")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Run Mac check" })).toBeInTheDocument();
  });

  it("does not exist anywhere else", async () => {
    await renderSettings("windows");
    open("Help");
    expect(screen.queryByRole("button", { name: /^Mac check/ })).not.toBeInTheDocument();
    fireEvent.change(screen.getByRole("textbox", { name: "Search settings" }), { target: { value: "mac check" } });
    expect(screen.queryByRole("option", { name: /^Mac check/ })).not.toBeInTheDocument();
  });

  it("lists a Mac's keys with their Mac names, and a PC's with theirs", async () => {
    await renderSettings("macos");
    open("Keyboard shortcuts");
    for (const key of ["⌘K", "⌘F", "⌘,", "⌘W", "⌘Q"]) expect(screen.getByText(key)).toBeInTheDocument();
    expect(screen.queryByText("Ctrl+K")).not.toBeInTheDocument();
  });

  it("keeps a PC's keys as they were", async () => {
    await renderSettings("windows");
    open("Keyboard shortcuts");
    expect(screen.getByText("Ctrl+K")).toBeInTheDocument();
    expect(screen.queryByText("⌘W")).not.toBeInTheDocument();
  });

  it("says where the after-call window is on each computer", async () => {
    await renderSettings("macos");
    open("Calls & meetings");
    expect(screen.getByText(/in the corner of the screen/)).toBeInTheDocument();
    expect(screen.queryByText(/near the tray/)).not.toBeInTheDocument();
  });
});
