import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import MainApp from "./MainApp";
import { callsOf, emitEvent, fail, flushAsync, listenerCount, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeNavigate, makePendingItem, makeRecording, makeSettings, makeStatus } from "../../test/fixtures";
import type { AppStatus } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const THEME_KEY = "echoledger-theme";

function mockBackend(status: Partial<AppStatus> = {}) {
  respond("app_status", makeStatus(status));
  respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
  respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
  respond("get_settings", makeSettings());
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("transcript_detail", { id: "t", kind: "final", provider: "p", status: "completed", language: null, text: null, segments: [] });
}

const nav = (name: string) => screen.getByRole("button", { name });
const sidebar = () => document.querySelector(".sidebar") as HTMLElement;

async function renderPaired(status: Partial<AppStatus> = {}) {
  mockBackend(status);
  const view = render(<MainApp />);
  await screen.findByRole("heading", { name: "Library" });
  await flushAsync();
  return view;
}

beforeEach(() => {
  resetTauriMock();
  window.localStorage.clear();
  document.documentElement.removeAttribute("data-theme");
});

afterEach(async () => {
  await flushAsync();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("MainApp start-up and pairing gate", () => {
  it("shows a start-up message until the first status arrives", async () => {
    let release: (v: unknown) => void = () => undefined;
    mockBackend();
    respondWith("app_status", () => new Promise((resolve) => (release = resolve)));
    render(<MainApp />);
    expect(screen.getByText("Starting EchoLedger…")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Library/ })).not.toBeInTheDocument();
    await act(async () => release(makeStatus()));
    expect(await screen.findByRole("heading", { name: "Library" })).toBeInTheDocument();
    expect(screen.queryByText("Starting EchoLedger…")).not.toBeInTheDocument();
  });

  it("shows the pairing form instead of the app when this computer is not paired", async () => {
    mockBackend({ paired: false, serverUrl: null, deviceName: null });
    render(<MainApp />);
    expect(await screen.findByRole("heading", { name: "Pair this computer" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Library/ })).not.toBeInTheDocument();
    expect(callsOf("library_list")).toHaveLength(0);
  });

  it("enters the app after pairing and tells the user the library will sync", async () => {
    mockBackend({ paired: false, serverUrl: null, deviceName: null });
    respond("pair_device", makeStatus({ deviceName: "Studio PC" }));
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Pair this computer" });
    fireEvent.change(screen.getByLabelText(/Server address/), { target: { value: "https://ledger.example.test" } });
    fireEvent.change(screen.getByLabelText(/Pairing code/), { target: { value: "ABCD-1234" } });
    fireEvent.click(screen.getByRole("button", { name: "Pair" }));

    expect(await screen.findByRole("heading", { name: "Library" })).toBeInTheDocument();
    expect(screen.getByText("Studio PC")).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent("Paired. Your library will sync now.");
  });

  // A6.DU1: pairing errors are never shown; MainApp returns the pairing form before it renders the toast.
  it("shows the pairing error in an error toast and stays on the pairing form", async () => {
    mockBackend({ paired: false });
    respondWith("pair_device", () => Promise.reject("Server returned 422: The pairing code is invalid or has expired."));
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Pair this computer" });
    fireEvent.change(screen.getByLabelText(/Pairing code/), { target: { value: "NOPE" } });
    fireEvent.click(screen.getByRole("button", { name: "Pair" }));
    const toast = await screen.findByRole("status");
    expect(toast).toHaveTextContent("Server returned 422: The pairing code is invalid or has expired.");
    expect(toast).toHaveClass("toast", "error");
    expect(screen.getByRole("heading", { name: "Pair this computer" })).toBeInTheDocument();
  });

  it("goes back to the pairing form when the backend reports the device was unpaired", async () => {
    await renderPaired();
    await emitEvent("app:status", makeStatus({ paired: false }));
    expect(await screen.findByRole("heading", { name: "Pair this computer" })).toBeInTheDocument();
  });

  // A6.DU1: the 401 "unpaired on the server" error arrives together with the status that shows the pairing form.
  it("keeps the 'unpaired on the server' error visible on the pairing form", async () => {
    await renderPaired();
    await emitEvent("app:error", "This computer was unpaired on the server. Pair it again to upload.");
    await emitEvent("app:status", makeStatus({ paired: false }));
    expect(await screen.findByRole("heading", { name: "Pair this computer" })).toBeInTheDocument();
    const toast = screen.getByRole("status");
    expect(toast).toHaveTextContent("This computer was unpaired on the server. Pair it again to upload.");
    expect(toast).toHaveClass("toast", "error");
  });

  // A6.DU1: errors that reach an unpaired window (network, certificate pin) are shown too.
  it("shows an app:error toast while the pairing form is open", async () => {
    mockBackend({ paired: false, serverUrl: null, deviceName: null });
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Pair this computer" });
    await emitEvent("app:error", "Network error: the server's TLS certificate was not accepted (pin mismatch)");
    expect(screen.getByRole("status")).toHaveTextContent("Network error: the server's TLS certificate was not accepted (pin mismatch)");
    expect(screen.getByRole("status")).toHaveClass("error");
  });

  // A6.DU7: every screen has a main landmark (start-up, pairing form and the paired app).
  it("has a main landmark before and after pairing", async () => {
    let release: (v: unknown) => void = () => undefined;
    mockBackend({ paired: false, serverUrl: null, deviceName: null });
    respondWith("app_status", () => new Promise((resolve) => (release = resolve)));
    render(<MainApp />);
    expect(screen.getByRole("main")).toHaveTextContent("Starting EchoLedger…");
    await act(async () => release(makeStatus({ paired: false, serverUrl: null, deviceName: null })));
    expect(within(await screen.findByRole("main")).getByRole("heading", { name: "Pair this computer" })).toBeInTheDocument();
    await emitEvent("app:status", makeStatus());
    expect(within(screen.getByRole("main")).getByRole("heading", { name: "Library" })).toBeInTheDocument();
    expect(screen.getAllByRole("main")).toHaveLength(1);
    await flushAsync();
  });

  it("stays on the start-up message when the status can never be read", async () => {
    mockBackend();
    fail("app_status", "state not ready");
    render(<MainApp />);
    await waitFor(() => expect(callsOf("app_status").length).toBeGreaterThan(0));
    expect(screen.getByText("Starting EchoLedger…")).toBeInTheDocument();
  });
});

describe("MainApp navigation", () => {
  it("opens on the library with the three destinations in the sidebar", async () => {
    await renderPaired();
    expect(sidebar()).toHaveTextContent("EchoLedger");
    expect(nav("Library")).toHaveClass("active");
    expect(nav("Record")).not.toHaveClass("active");
    expect(nav("Settings")).not.toHaveClass("active");
    expect(await within(screen.getByTestId("library-list")).findByText("Zoom with Acme")).toBeInTheDocument();
  });

  it("switches between Library, Record and Settings and moves the active marker", async () => {
    await renderPaired();
    fireEvent.click(nav("Record"));
    expect(screen.getByRole("heading", { name: "Record" })).toBeInTheDocument();
    expect(nav("Record")).toHaveClass("active");
    expect(nav("Library")).not.toHaveClass("active");
    expect(screen.queryByRole("heading", { name: "Library" })).not.toBeInTheDocument();

    fireEvent.click(nav("Settings"));
    expect(await screen.findByRole("heading", { name: "Settings" })).toBeInTheDocument();
    expect(nav("Settings")).toHaveClass("active");

    fireEvent.click(nav("Library"));
    expect(screen.getByRole("heading", { name: "Library" })).toBeInTheDocument();
    expect(nav("Library")).toHaveClass("active");
    await flushAsync(); // the remounted library's cache read and sync settle inside the test
  });

  it("shows a live dot beside Record only while recording", async () => {
    const view = await renderPaired();
    expect(nav("Record").querySelector(".status-dot")).toBeNull();
    view.unmount();
    resetTauriMock();
    await renderPaired({ recording: makeRecording() });
    expect(nav("Record").querySelector(".status-dot")).toHaveClass("live");
  });

  it("summarises captions, device and uploads in the sidebar footer", async () => {
    await renderPaired({ captionsLive: true, deviceName: "Studio PC", pendingUploads: 3 });
    expect(within(sidebar()).getByText("Captions live")).toBeInTheDocument();
    expect(within(sidebar()).getByText("Studio PC")).toBeInTheDocument();
    // A3.4: the footer mirrors the tray, which says "uploading" while anything is in the queue.
    expect(within(sidebar()).getByText("Uploading…")).toBeInTheDocument();
  });

  it("reports captions off and all uploads done by default", async () => {
    await renderPaired();
    expect(within(sidebar()).getByText("Captions off")).toBeInTheDocument();
    expect(within(sidebar()).getByText("All uploads done")).toBeInTheDocument();
  });
});

describe("MainApp sidebar and keys", () => {
  it("lists Library, Clients, Record, Activity and Settings, and collapses to icons that keep their names", async () => {
    await renderPaired();
    expect([...document.querySelectorAll(".nav .nav-btn")].map((b) => b.textContent)).toEqual(["Library", "Clients", "Record", "Activity", "Settings"]);
    fireEvent.click(screen.getByRole("button", { name: "Collapse the sidebar" }));
    expect(document.querySelector(".app")).toHaveClass("collapsed");
    expect(nav("Clients")).toHaveAttribute("title", "Clients");
    expect(window.localStorage.getItem("echoledger-sidebar")).toBe("collapsed");
    fireEvent.click(screen.getByRole("button", { name: "Expand the sidebar" }));
    expect(document.querySelector(".app")).not.toHaveClass("collapsed");
    expect(window.localStorage.getItem("echoledger-sidebar")).toBe("expanded");
  });

  it("opens Clients and Activity from the sidebar", async () => {
    respond("activity_list", { data: [], unread_count: 0 });
    respond("pending_list", []);
    await renderPaired();
    fireEvent.click(nav("Clients"));
    expect(await screen.findByRole("heading", { name: "Clients" })).toBeInTheDocument();
    expect(nav("Clients")).toHaveAttribute("aria-current", "page");
    fireEvent.click(nav("Activity"));
    expect(await screen.findByRole("heading", { name: "Activity" })).toBeInTheDocument();
    await flushAsync();
  });

  it("N starts a voice note, never while typing; Ctrl+K opens search", async () => {
    respond("toggle_mic_note", makeStatus({ recording: makeRecording({ mode: "mic_note" }) }));
    await renderPaired();
    fireEvent.keyDown(screen.getByLabelText("Search recordings"), { key: "n" });
    expect(callsOf("toggle_mic_note")).toHaveLength(0);
    fireEvent.keyDown(document.body, { key: "n" });
    await waitFor(() => expect(callsOf("toggle_mic_note")).toHaveLength(1));
    expect(screen.getByRole("heading", { name: "Record" })).toBeInTheDocument();
    expect(await screen.findByRole("status")).toHaveTextContent("Recording a voice note. Press N again to stop.");
    fireEvent.keyDown(document.body, { key: "k", ctrlKey: true });
    expect(document.querySelector(".palette")).not.toBeNull();
  });

  it("does not start a voice note over a call that is being recorded", async () => {
    await renderPaired({ recording: makeRecording({ mode: "call" }) });
    fireEvent.keyDown(document.body, { key: "n" });
    expect(callsOf("toggle_mic_note")).toHaveLength(0);
    expect(screen.getByRole("status")).toHaveTextContent("A recording is running. Stop it before starting a voice note.");
  });
});

describe("MainApp theme toggle", () => {
  const toggle = () => screen.getByRole("button", { name: /^Theme:/ });

  it("starts on System and cycles System, Light, Dark, System", async () => {
    await renderPaired();
    expect(toggle()).toHaveTextContent("Theme: System");
    expect(toggle()).toHaveAccessibleName("Theme: system. Click to change.");
    fireEvent.click(toggle());
    expect(toggle()).toHaveTextContent("Theme: Light");
    fireEvent.click(toggle());
    expect(toggle()).toHaveTextContent("Theme: Dark");
    fireEvent.click(toggle());
    expect(toggle()).toHaveTextContent("Theme: System");
  });

  it("applies the theme to the document and persists the choice", async () => {
    await renderPaired();
    fireEvent.click(toggle());
    expect(document.documentElement.dataset.theme).toBe("light");
    expect(document.documentElement.style.colorScheme).toBe("light");
    expect(window.localStorage.getItem(THEME_KEY)).toBe("light");
    fireEvent.click(toggle());
    expect(document.documentElement.dataset.theme).toBe("dark");
    expect(window.localStorage.getItem(THEME_KEY)).toBe("dark");
  });

  it("restores the saved choice after a restart", async () => {
    window.localStorage.setItem(THEME_KEY, "dark");
    const first = await renderPaired();
    expect(toggle()).toHaveTextContent("Theme: Dark");
    first.unmount();
    resetTauriMock();
    window.localStorage.setItem(THEME_KEY, "light");
    await renderPaired();
    expect(toggle()).toHaveTextContent("Theme: Light");
  });

  it("shows a distinct icon for each choice", async () => {
    await renderPaired();
    const icon = () => toggle().querySelector("svg")?.innerHTML;
    const seen = new Set<string | undefined>([icon()]);
    fireEvent.click(toggle());
    seen.add(icon());
    fireEvent.click(toggle());
    seen.add(icon());
    expect(seen.size).toBe(3); // monitor (system), sun (light), moon (dark)
  });
});

describe("MainApp events, toasts and polling", () => {
  it("updates the sidebar from app:status events", async () => {
    await renderPaired();
    await emitEvent("app:status", makeStatus({ captionsLive: true, pendingUploads: 1, deviceName: "Renamed PC" }));
    expect(within(sidebar()).getByText("Captions live")).toBeInTheDocument();
    expect(within(sidebar()).getByText("Renamed PC")).toBeInTheDocument();
    expect(within(sidebar()).getByText("Uploading…")).toBeInTheDocument();
  });

  it("shows app:error as an error toast and app:toast as a normal toast", async () => {
    await renderPaired();
    await emitEvent("app:error", "No sound was captured, so nothing was saved.");
    expect(screen.getByRole("status")).toHaveTextContent("No sound was captured, so nothing was saved.");
    expect(screen.getByRole("status")).toHaveClass("error");
    await emitEvent("app:toast", "Uploaded 2 recording(s) for transcription");
    expect(screen.getByRole("status")).toHaveTextContent("Uploaded 2 recording(s) for transcription");
    expect(screen.getByRole("status")).not.toHaveClass("error");
  });

  it("hides a toast after 4.5 seconds, and restarts the timer when a new one replaces it", async () => {
    await renderPaired();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    await emitEvent("app:toast", "first");
    await act(async () => {
      await vi.advanceTimersByTimeAsync(4_000);
    });
    expect(screen.getByRole("status")).toHaveTextContent("first");
    await emitEvent("app:toast", "second");
    await act(async () => {
      await vi.advanceTimersByTimeAsync(4_000);
    });
    expect(screen.getByRole("status")).toHaveTextContent("second");
    await act(async () => {
      await vi.advanceTimersByTimeAsync(600);
    });
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("re-reads the status every 1.5 seconds", async () => {
    mockBackend();
    vi.useFakeTimers({ toFake: ["setInterval", "clearInterval"] });
    const { unmount } = render(<MainApp />);
    await act(async () => {
      await Promise.resolve();
    });
    expect(callsOf("app_status")).toHaveLength(1);
    await act(async () => {
      vi.advanceTimersByTime(1_500);
    });
    expect(callsOf("app_status")).toHaveLength(2);
    await act(async () => {
      vi.advanceTimersByTime(3_000);
    });
    expect(callsOf("app_status")).toHaveLength(4);
    unmount();
    await act(async () => {
      vi.advanceTimersByTime(10_000);
    });
    expect(callsOf("app_status")).toHaveLength(4);
  });

  it("subscribes to the three backend events and unsubscribes on unmount", async () => {
    const { unmount } = await renderPaired();
    for (const event of ["app:status", "app:error", "app:toast"]) await waitFor(() => expect(listenerCount(event)).toBe(1));
    unmount();
    for (const event of ["app:status", "app:error", "app:toast"]) await waitFor(() => expect(listenerCount(event)).toBe(0));
  });
});

describe("MainApp action runner", () => {
  it("turns a rejected action into an error toast and keeps the screen", async () => {
    await renderPaired();
    respondWith("start_recording", () => Promise.reject("Audio error: no input device"));
    fireEvent.click(nav("Record"));
    fireEvent.click(screen.getByRole("button", { name: "Start recording" }));
    const toast = await screen.findByRole("status");
    expect(toast).toHaveTextContent("Audio error: no input device");
    expect(toast).toHaveClass("error");
    expect(screen.getByRole("heading", { name: "Record" })).toBeInTheDocument();
  });

  it("shows the success message and refreshes the status after an action", async () => {
    await renderPaired();
    respond("copy_last_minutes", "Me: hi");
    fireEvent.click(nav("Record"));
    const before = callsOf("app_status").length;
    fireEvent.click(screen.getByRole("button", { name: "Copy last 2 minutes" }));
    expect(await screen.findByRole("status")).toHaveTextContent("Copied the last 2 minutes");
    await waitFor(() => expect(callsOf("app_status").length).toBeGreaterThan(before));
  });

  it("flips the captions switch after the status reports captions live", async () => {
    await renderPaired();
    fireEvent.click(nav("Record"));
    respond("toggle_captions", makeStatus({ captionsLive: true }));
    respond("app_status", makeStatus({ captionsLive: true }));
    fireEvent.click(screen.getByRole("switch", { name: "Toggle live captions" }));
    await waitFor(() => expect(screen.getByRole("switch", { name: "Toggle live captions" })).toHaveClass("on"));
    expect(within(sidebar()).getByText("Captions live")).toBeInTheDocument();
  });

  it("unpairing from Settings returns to the pairing form", async () => {
    vi.spyOn(window, "confirm").mockReturnValue(true);
    await renderPaired();
    respond("unpair", makeStatus({ paired: false, serverUrl: null, deviceName: null }));
    fireEvent.click(nav("Settings"));
    fireEvent.click(await screen.findByRole("button", { name: "Unpair" }));
    expect(await screen.findByRole("heading", { name: "Pair this computer" })).toBeInTheDocument();
  });
});

describe("MainApp monthly transcription limit (A5.2)", () => {
  const banner = () => screen.queryByTestId("budget-banner");

  it("shows nothing while the budget is fine", async () => {
    await renderPaired({ budget: "ok" });
    expect(banner()).not.toBeInTheDocument();
    expect(document.querySelector(".content")).not.toHaveClass("has-banner");
  });

  it("keeps a banner up while the limit is reached, on every page, with no way to dismiss it", async () => {
    await renderPaired({ budget: "reached" });
    expect(banner()).toHaveTextContent("Monthly transcription limit reached. Recordings are kept but not transcribed.");
    expect(banner()).toHaveClass("danger");
    expect(within(banner()!).queryByRole("button")).not.toBeInTheDocument();
    expect(document.querySelector(".content")).toHaveClass("has-banner");
    fireEvent.click(nav("Record"));
    expect(banner()).toHaveTextContent("Monthly transcription limit reached.");
    fireEvent.click(nav("Settings"));
    await screen.findByRole("heading", { name: "Settings" });
    expect(banner()).toBeInTheDocument();
  });

  it("warns at 80% and lets the user dismiss the warning for the session", async () => {
    await renderPaired({ budget: "warn" });
    expect(banner()).toHaveTextContent("You have used 80% of this month's transcription limit.");
    expect(banner()).toHaveClass("warn");
    fireEvent.click(within(banner()!).getByRole("button", { name: "Dismiss" }));
    expect(banner()).not.toBeInTheDocument();
    expect(document.querySelector(".content")).not.toHaveClass("has-banner");
    await emitEvent("app:status", makeStatus({ budget: "warn", captionsLive: true }));
    expect(banner()).not.toBeInTheDocument();
  });

  it("replaces a dismissed warning with the limit-reached banner", async () => {
    await renderPaired({ budget: "warn" });
    fireEvent.click(within(banner()!).getByRole("button", { name: "Dismiss" }));
    await emitEvent("app:status", makeStatus({ budget: "reached" }));
    expect(banner()).toHaveTextContent("Monthly transcription limit reached.");
    await emitEvent("app:status", makeStatus({ budget: "ok" }));
    expect(banner()).not.toBeInTheDocument();
  });

  it("does not use the toast's status role, so toasts stay distinguishable", async () => {
    await renderPaired({ budget: "reached" });
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });
});

describe("MainApp sidebar mirrors the tray (A3.4)", () => {
  it("says Paused while a recording is paused", async () => {
    await renderPaired({ recording: makeRecording(), paused: true, trayState: "paused" });
    expect(within(sidebar()).getByText("Paused")).toBeInTheDocument();
    expect(sidebar().querySelector(".footer-state .status-dot")).toHaveClass("paused");
  });

  it("does not say Paused while recording", async () => {
    await renderPaired({ recording: makeRecording(), trayState: "recording" });
    expect(within(sidebar()).queryByText("Paused")).not.toBeInTheDocument();
  });

  it("counts the recordings waiting for a decision and opens the library from there", async () => {
    await renderPaired({ pendingDecisions: 2, trayState: "attention" });
    fireEvent.click(nav("Settings"));
    await screen.findByRole("heading", { name: "Settings" });
    fireEvent.click(within(sidebar()).getByRole("button", { name: "2 waiting for your decision" }));
    expect(screen.getByRole("heading", { name: "Library" })).toBeInTheDocument();
    expect(nav("Library")).toHaveClass("active");
  });

  it("points at uploads that stopped", async () => {
    await renderPaired({ failedUploads: 1, trayState: "attention" });
    fireEvent.click(nav("Record"));
    fireEvent.click(within(sidebar()).getByRole("button", { name: "Uploads that need you" }));
    expect(screen.getByRole("heading", { name: "Library" })).toBeInTheDocument();
  });

  it("shows no tray lines when idle", async () => {
    await renderPaired();
    expect(within(sidebar()).queryByText(/waiting for your decision/)).not.toBeInTheDocument();
    expect(within(sidebar()).queryByText("Uploads that need you")).not.toBeInTheDocument();
    expect(within(sidebar()).queryByText("Paused")).not.toBeInTheDocument();
    expect(within(sidebar()).getByText("All uploads done")).toBeInTheDocument();
  });
});

describe("MainApp main:navigate", () => {
  beforeEach(() => {
    Object.defineProperty(Element.prototype, "scrollIntoView", { configurable: true, value: vi.fn() });
  });

  it("listens for main:navigate and stops on unmount", async () => {
    const { unmount } = await renderPaired();
    await waitFor(() => expect(listenerCount("main:navigate")).toBe(1));
    unmount();
    await waitFor(() => expect(listenerCount("main:navigate")).toBe(0));
  });

  it("switches to the requested view", async () => {
    await renderPaired();
    await emitEvent("main:navigate", makeNavigate({ view: "settings" }));
    expect(await screen.findByRole("heading", { name: "Settings" })).toBeInTheDocument();
    expect(nav("Settings")).toHaveClass("active");
    await emitEvent("main:navigate", makeNavigate({ view: "record" }));
    expect(screen.getByRole("heading", { name: "Record" })).toBeInTheDocument();
  });

  it("ignores an unknown view", async () => {
    await renderPaired();
    await emitEvent("main:navigate", { view: "elsewhere", pendingId: null, rename: false });
    expect(screen.getByRole("heading", { name: "Library" })).toBeInTheDocument();
  });

  it("opens the library on a waiting recording, highlights it and opens its title for renaming", async () => {
    await renderPaired();
    respond("pending_list", [makePendingItem("pend-1"), makePendingItem("pend-2", { sourceApp: "google_meet" })]);
    fireEvent.click(nav("Record"));
    await emitEvent("main:navigate", makeNavigate({ view: "library", pendingId: "pend-2", rename: true }));
    expect(screen.getByRole("heading", { name: "Library" })).toBeInTheDocument();
    // The waiting list opens by itself (it starts as one collapsed summary card).
    const row = await screen.findByTestId("pending-pend-2");
    expect(screen.getByRole("button", { name: "Hide" })).toHaveAttribute("aria-expanded", "true");
    await waitFor(() => expect(row).toHaveClass("highlight"));
    expect(screen.getByTestId("pending-pend-1")).not.toHaveClass("highlight");
    expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
    expect(within(row).getByRole("textbox", { name: "Title" })).toHaveFocus();
  });

  it("does not highlight or reopen the title again after leaving the library and coming back", async () => {
    await renderPaired();
    respond("pending_list", [makePendingItem("pend-1")]);
    await emitEvent("main:navigate", makeNavigate({ view: "library", pendingId: "pend-1", rename: true }));
    await waitFor(() => expect(screen.getByTestId("pending-pend-1")).toHaveClass("highlight"));
    fireEvent.click(nav("Record"));
    fireEvent.click(nav("Library"));
    // Back on the Library the waiting list is the collapsed card again; the old request does not reopen it.
    const review = await screen.findByRole("button", { name: "Review" });
    await flushAsync();
    expect(review).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByTestId("pending-pend-1")).not.toBeInTheDocument();
    fireEvent.click(review);
    const row = screen.getByTestId("pending-pend-1");
    await flushAsync();
    expect(row).not.toHaveClass("highlight");
    expect(within(row).queryByRole("textbox")).not.toBeInTheDocument();
    // A second request for the same recording works again.
    await emitEvent("main:navigate", makeNavigate({ view: "library", pendingId: "pend-1", rename: false }));
    await waitFor(() => expect(screen.getByTestId("pending-pend-1")).toHaveClass("highlight"));
  });
});

describe("command palette (B9)", () => {
  beforeEach(() => resetTauriMock());

  it("opens with Ctrl+K or Cmd+K, closes with Escape, and goes where it is told", async () => {
    await renderPaired();
    fireEvent.keyDown(window, { key: "k", ctrlKey: true });
    const paletteInput = async () => within(await screen.findByRole("dialog", { name: "Command palette" })).getByRole("combobox");
    const input = await paletteInput();
    fireEvent.keyDown(input, { key: "Escape" });
    expect(screen.queryByRole("dialog", { name: "Command palette" })).not.toBeInTheDocument();

    fireEvent.keyDown(window, { key: "K", metaKey: true });
    fireEvent.change(await paletteInput(), { target: { value: "go to settings" } });
    fireEvent.keyDown(await paletteInput(), { key: "Enter" });
    expect(await screen.findByRole("heading", { name: "Settings" })).toBeInTheDocument();
  });
});
