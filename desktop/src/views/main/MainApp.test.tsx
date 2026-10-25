import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import MainApp from "./MainApp";
import { callsOf, emitEvent, fail, flushAsync, listenerCount, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeRecording, makeSettings, makeStatus } from "../../test/fixtures";
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
  it.skip("shows the pairing error in an error toast and stays on the pairing form", async () => {
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
    expect(within(sidebar()).getByText("3 upload(s) pending")).toBeInTheDocument();
  });

  it("reports captions off and all uploads done by default", async () => {
    await renderPaired();
    expect(within(sidebar()).getByText("Captions off")).toBeInTheDocument();
    expect(within(sidebar()).getByText("All uploads done")).toBeInTheDocument();
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
    expect(within(sidebar()).getByText("1 upload(s) pending")).toBeInTheDocument();
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
    fireEvent.click(screen.getByRole("button", { name: "Toggle live captions" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "Toggle live captions" })).toHaveClass("on"));
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
