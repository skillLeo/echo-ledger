import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { UpdateBanner, UpdatesPanel, updateLine } from "./UpdateSettings";
import type { UpdateStatus } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeRecording, makeStatus, anyTarget } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

function update(overrides: Partial<UpdateStatus> = {}): UpdateStatus {
  return { phase: "idle", current: "1.5.0-beta.1", version: null, notes: null, checkedAt: null, message: null, progress: null, ...overrides };
}

async function panel(initial: UpdateStatus, recording = false) {
  respond("update_status", initial);
  await act(async () => {
    render(<UpdatesPanel appStatus={makeStatus({ recording: recording ? makeRecording() : null })} />);
  });
}

describe("Settings → About: updates", () => {
  beforeEach(() => resetTauriMock());

  it("checks on request and follows the check and the download", async () => {
    respond("update_check", update({ phase: "checking" }));
    await panel(update());
    expect(screen.getByRole("status")).toHaveTextContent("EchoLedger looks for updates when it starts and every 6 hours.");

    fireEvent.click(screen.getByRole("button", { name: "Check for updates" }));
    await flushAsync();
    expect(callsOf("update_check")).toHaveLength(1);
    expect(screen.getByRole("status")).toHaveTextContent("Checking for updates…");
    expect(screen.getByRole("button", { name: "Check for updates" })).toBeDisabled();

    await emitEvent("update:status", update({ phase: "downloading", version: "1.5.1", progress: 40 }));
    expect(screen.getByRole("status")).toHaveTextContent("Downloading EchoLedger 1.5.1 · 40%");

    respond("update_install", undefined);
    await emitEvent("update:status", update({ phase: "ready", version: "1.5.1", notes: "Faster sign-in." }));
    expect(screen.getByRole("status")).toHaveTextContent("Update ready — EchoLedger 1.5.1. Restart to install it, or it installs the next time EchoLedger starts.");
    expect(screen.getByText("Faster sign-in.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Restart" }));
    await flushAsync();
    expect(callsOf("update_install")).toHaveLength(1);
  });

  it("says when there is nothing new, and when it last looked", async () => {
    await panel(update({ phase: "up_to_date", message: "No update is published on your server yet.", checkedAt: new Date().toISOString() }));
    expect(screen.getByRole("status")).toHaveTextContent("No update is published on your server yet.");
    expect(screen.getByText(/^Last checked/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Check for updates" })).toBeEnabled();
  });

  it("shows a failed check or install in plain words", async () => {
    await panel(update({ phase: "failed", message: "The update's signature did not match EchoLedger's, so it was not installed." }));
    expect(screen.getByRole("status")).toHaveTextContent("signature did not match");
    expect(screen.getByRole("status")).toHaveClass("error-text");
  });

  it("never restarts while a recording runs", async () => {
    await panel(update({ phase: "ready", version: "1.5.1" }), true);
    expect(screen.getByRole("button", { name: "Restart" })).toBeDisabled();
    expect(screen.getByText("Stop the recording first: EchoLedger restarts to install the update.")).toBeInTheDocument();
  });

  it("shows why Restart did not work", async () => {
    fail("update_install", "The downloaded update is missing. Check for updates again.");
    await panel(update({ phase: "ready", version: "1.5.1" }));
    fireEvent.click(screen.getByRole("button", { name: "Restart" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("The downloaded update is missing.");
  });

  it("describes every phase", () => {
    expect(updateLine(update({ phase: "downloading", version: "1.5.1" }))).toBe("Downloading EchoLedger 1.5.1…");
    expect(updateLine(update({ phase: "unavailable", message: "Updates work in the installed app only." }))).toBe("Updates work in the installed app only.");
  });
});

describe("the update prompt", () => {
  beforeEach(() => resetTauriMock());

  async function banner(initial: UpdateStatus, recording = false) {
    respond("update_status", initial);
    const notify = vi.fn();
    await act(async () => {
      render(<UpdateBanner appStatus={makeStatus({ recording: recording ? makeRecording() : null })} notify={notify} />);
    });
    return notify;
  }

  it("stays away until an update is downloaded", async () => {
    await banner(update({ phase: "downloading", version: "1.5.1" }));
    expect(screen.queryByTestId("update-banner")).not.toBeInTheDocument();
    await emitEvent("update:status", update({ phase: "ready", version: "1.5.1" }));
    expect(screen.getByTestId("update-banner")).toHaveTextContent("Update ready — EchoLedger 1.5.1");
  });

  it("restarts now, or waits for the next start on Later", async () => {
    respond("update_install", undefined);
    const notify = await banner(update({ phase: "ready", version: "1.5.1" }));
    fireEvent.click(screen.getByRole("button", { name: "Restart" }));
    await waitFor(() => expect(callsOf("update_install")).toHaveLength(1));

    fireEvent.click(screen.getByRole("button", { name: "Later" }));
    expect(screen.queryByTestId("update-banner")).not.toBeInTheDocument();
    expect(notify).toHaveBeenCalledWith("The update installs the next time EchoLedger starts.", false, anyTarget);
    // A newer download asks again.
    await emitEvent("update:status", update({ phase: "ready", version: "1.5.2" }));
    expect(screen.getByTestId("update-banner")).toHaveTextContent("1.5.2");
  });

  it("cannot restart in the middle of a recording, and reports a failed install", async () => {
    const recordingNotify = await banner(update({ phase: "ready", version: "1.5.1" }), true);
    expect(screen.getByRole("button", { name: "Restart" })).toBeDisabled();
    expect(recordingNotify).not.toHaveBeenCalled();
  });

  it("tells when Restart failed", async () => {
    fail("update_install", "macOS did not allow EchoLedger to replace itself. Install the update from the downloads page instead.");
    const notify = await banner(update({ phase: "ready", version: "1.5.1" }));
    fireEvent.click(screen.getByRole("button", { name: "Restart" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith(expect.stringContaining("macOS did not allow EchoLedger"), true));
  });
});
