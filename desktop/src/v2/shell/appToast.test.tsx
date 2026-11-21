// The app's messages in the new design's window (docs/v2/audit/parity.csv DT-HLP-013): the new design's toast, a press
// on the message goes where it is about, Esc on it or × closes it, an error is read out at once; classic's toast stays away.
import { beforeEach, describe, expect, it } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { vi } from "vitest";
import MainApp from "../../views/main/MainApp";
import { emitEvent, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus, makeTranscript } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS = [makeItem("a", { title: "Bilal Qureshi" }), makeItem("b", { title: "Weekly Zoom" })];

async function app(flags: Record<string, boolean>) {
  respond("app_status", makeStatus());
  respond("get_settings", makeSettings());
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("transcript_detail", makeTranscript({ id: "tr-a" }));
  respond("bin_list", { data: [] });
  respond("ui_flags", { ui_flags: flags, ui_classic: false });
  render(<MainApp />);
  await act(flushAsync);
  await act(flushAsync);
  await screen.findByRole("navigation", { name: "Main" });
}

beforeEach(() => {
  resetTauriMock();
  resetEntitlementsForTests(makeEntitlements());
});

describe("the app's messages in the new design", () => {
  it("DT-HLP-013: a message about a recording is the new design's toast; pressing it opens the recording", async () => {
    await app({ "ui_v2.shell": true, "ui_v2.library": true });
    await act(() => emitEvent("app:toast", { text: "The transcript is ready", target: { kind: "recording", id: "a" } }));
    await act(flushAsync);
    const toast = await screen.findByTestId("app-toast");
    expect(toast.closest('[aria-live="polite"]')).toBe(screen.getByTestId("app-messages"));
    expect(toast).toHaveTextContent("The transcript is ready");
    expect(document.querySelector(".toast-region")).toBeNull();
    fireEvent.click(within(toast).getByRole("button", { name: "The transcript is ready" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Bilal Qureshi" })).toBeInTheDocument();
    expect(screen.queryByTestId("app-toast")).toBeNull();
  });

  it("DT-HLP-013: an error is read out at once; Esc on it closes it, and so does ×; a message about nothing is plain text", async () => {
    await app({ "ui_v2.shell": true });
    await act(() => emitEvent("app:error", "The upload stopped: the server didn't answer."));
    await act(flushAsync);
    let toast = await screen.findByTestId("app-toast");
    expect(toast.closest('[role="alert"]')).toBe(screen.getByTestId("app-problems"));
    expect(within(toast).queryByRole("button", { name: /The upload stopped/ })).toBeNull();
    fireEvent.keyDown(within(toast).getByRole("button", { name: "Dismiss" }), { key: "Escape" });
    expect(screen.queryByTestId("app-toast")).toBeNull();
    await act(() => emitEvent("app:toast", "Copied"));
    await act(flushAsync);
    toast = await screen.findByTestId("app-toast");
    fireEvent.click(within(toast).getByRole("button", { name: "Dismiss" }));
    expect(screen.queryByTestId("app-toast")).toBeNull();
  });

  it("DT-HLP-013: the message regions are in the page before any message and stay after it; only the words change", async () => {
    await app({ "ui_v2.shell": true });
    const polite = await screen.findByTestId("app-messages");
    const assertive = screen.getByTestId("app-problems");
    expect(polite).toHaveAttribute("aria-live", "polite");
    expect(assertive).toHaveAttribute("aria-live", "assertive");
    expect(polite).toBeEmptyDOMElement();
    await act(() => emitEvent("app:toast", "Copied"));
    await act(flushAsync);
    expect(screen.getByTestId("app-messages")).toBe(polite);
    expect(polite).toHaveTextContent("Copied");
    await act(() => emitEvent("app:toast", "Renamed"));
    await act(flushAsync);
    expect(screen.getByTestId("app-messages")).toBe(polite);
    expect(polite).toHaveTextContent("Renamed");
    expect(polite).not.toHaveTextContent("Copied");
    fireEvent.click(within(polite).getByRole("button", { name: "Dismiss" }));
    expect(screen.getByTestId("app-messages")).toBe(polite);
    expect(polite).toBeEmptyDOMElement();
    expect(screen.getByTestId("app-problems")).toBe(assertive);
  });

  it("DT-HLP-013: classic's window keeps classic's toast", async () => {
    respond("app_status", makeStatus());
    respond("get_settings", makeSettings());
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Library" });
    await act(flushAsync);
    await act(() => emitEvent("app:toast", "Copied"));
    await act(flushAsync);
    expect(document.querySelector(".toast-region")).toHaveTextContent("Copied");
    expect(screen.queryByTestId("app-toast")).toBeNull();
  });
});
