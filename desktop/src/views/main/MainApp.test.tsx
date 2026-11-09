import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import MainApp from "./MainApp";
import { callsOf, emitEvent, fail, flushAsync, listenerCount, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeEntitlements, makeItem, makeNavigate, makePendingItem, makeRecording, makeSettings, makeStatus } from "../../test/fixtures";
import { OPEN_SECTION_EVENT, resetEntitlementsForTests } from "../../lib/entitlements";
import type { AppStatus } from "../../lib/ipc";
import { fakeAnimations, prefersReducedMotion } from "../../test/motion";

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

const sidebar = () => document.querySelector(".sidebar") as HTMLElement;
/** A page's button in the sidebar (breadcrumbs and menus name the same pages). */
const nav = (name: string) => within(sidebar()).getByRole("button", { name });

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
    expect(await screen.findByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Library/ })).not.toBeInTheDocument();
    expect(callsOf("library_list")).toHaveLength(0);
  });

  it("enters the app after pairing and tells the user the library will sync", async () => {
    mockBackend({ paired: false, serverUrl: null, deviceName: null });
    respond("pair_device", makeStatus({ deviceName: "Studio PC" }));
    render(<MainApp />);
    fireEvent.click(await screen.findByRole("button", { name: "Sign in with QR / code" }));
    await screen.findByRole("heading", { name: "Sign in with a code" });
    fireEvent.change(screen.getByLabelText(/Server address/), { target: { value: "https://ledger.example.test" } });
    fireEvent.change(screen.getByLabelText(/Sign-in code/), { target: { value: "ABCD-1234" } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in with code" }));

    // After signing in, the Ready check (Phase 1C): Home with it.
    expect(await screen.findByRole("heading", { name: "Home", level: 1 })).toBeInTheDocument();
    expect(screen.getByTestId("ready-card")).toBeInTheDocument();
    // No account route on this server: the avatar is the computer's name.
    expect(screen.getByRole("button", { name: "Account menu: Studio PC" })).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent("Signed in. Your library will sync now.");
  });

  // A6.DU1: pairing errors are never shown; MainApp returns the pairing form before it renders the toast.
  it("shows the pairing error in an error toast and stays on the pairing form", async () => {
    mockBackend({ paired: false });
    respondWith("pair_device", () => Promise.reject("Server returned 422: The pairing code is invalid or has expired."));
    render(<MainApp />);
    fireEvent.click(await screen.findByRole("button", { name: "Sign in with QR / code" }));
    await screen.findByRole("heading", { name: "Sign in with a code" });
    fireEvent.change(screen.getByLabelText(/Sign-in code/), { target: { value: "NOPE" } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in with code" }));
    const toast = await screen.findByRole("status");
    expect(toast).toHaveTextContent("Server returned 422: The pairing code is invalid or has expired.");
    expect(toast).toHaveClass("toast", "error");
    expect(screen.getByRole("heading", { name: "Sign in with a code" })).toBeInTheDocument();
  });

  it("goes back to the pairing form when the backend reports the device was unpaired", async () => {
    await renderPaired();
    await emitEvent("app:status", makeStatus({ paired: false }));
    expect(await screen.findByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
  });

  // A6.DU1: the 401 "signed out on the server" error arrives together with the status that shows the sign-in form.
  it("keeps the 'signed out on the server' error visible on the sign-in form", async () => {
    await renderPaired();
    await emitEvent("app:error", "This computer was signed out on the server. Sign in again to upload.");
    await emitEvent("app:status", makeStatus({ paired: false }));
    expect(await screen.findByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
    const toast = screen.getByRole("status");
    expect(toast).toHaveTextContent("This computer was signed out on the server. Sign in again to upload.");
    expect(toast).toHaveClass("toast", "error");
  });

  // A6.DU1: errors that reach an unpaired window (network, certificate pin) are shown too.
  it("shows an app:error toast while the sign-in form is open", async () => {
    mockBackend({ paired: false, serverUrl: null, deviceName: null });
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Sign in to EchoLedger" });
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
    expect(within(await screen.findByRole("main")).getByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
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
  it("opens on the library with the pages in the sidebar", async () => {
    await renderPaired();
    expect(sidebar()).toHaveTextContent("EchoLedger");
    expect(nav("Library")).toHaveClass("active");
    expect(nav("Home")).not.toHaveClass("active");
    expect(nav("Account")).not.toHaveClass("active");
    expect(await within(screen.getByTestId("library-list")).findByText("Zoom with Acme")).toBeInTheDocument();
  });

  it("switches between Library, Home and Account and moves the active marker", async () => {
    await renderPaired();
    fireEvent.click(nav("Home"));
    expect(screen.getByRole("heading", { name: "Home", level: 1 })).toBeInTheDocument();
    expect(nav("Home")).toHaveClass("active");
    expect(nav("Library")).not.toHaveClass("active");
    expect(screen.queryByRole("heading", { name: "Library" })).not.toBeInTheDocument();

    fireEvent.click(nav("Account"));
    expect(await screen.findByRole("heading", { name: "Account", level: 1 })).toBeInTheDocument();
    expect(nav("Account")).toHaveClass("active");

    fireEvent.click(nav("Library"));
    expect(screen.getByRole("heading", { name: "Library" })).toBeInTheDocument();
    expect(nav("Library")).toHaveClass("active");
    await flushAsync(); // the remounted library's cache read and sync settle inside the test
  });

  it("shows a live dot beside Home only while recording", async () => {
    const view = await renderPaired();
    expect(nav("Home").querySelector(".status-dot")).toBeNull();
    view.unmount();
    resetTauriMock();
    await renderPaired({ recording: makeRecording() });
    expect(nav("Home").querySelector(".status-dot")).toHaveClass("live");
  });

  it("says what is uploading in the sidebar and what is recording in the top bar (A3.4 mirrors the tray)", async () => {
    await renderPaired({ recording: makeRecording(), elapsedMs: 65_000, pendingUploads: 3 });
    expect(within(sidebar()).getByText("Uploading 3 recordings…")).toBeInTheDocument();
    expect(screen.getByTestId("recording-chip")).toHaveTextContent("Recording");
    expect(screen.getByTestId("recording-chip")).toHaveTextContent("1:05");
  });

  it("is quiet when nothing is on its way", async () => {
    await renderPaired();
    expect(within(sidebar()).queryByText(/Uploading/)).not.toBeInTheDocument();
    expect(screen.queryByTestId("recording-chip")).not.toBeInTheDocument();
  });
});

describe("MainApp sidebar and keys", () => {
  it("lists Home, Library, Projects, Activity, Shared and Upload, and collapses to a rail of icons that keep their names", async () => {
    await renderPaired();
    expect([...document.querySelectorAll(".nav .nav-btn")].map((b) => b.textContent)).toEqual(["Home", "Library", "Projects", "Activity", "Shared", "Upload"]);
    expect([...document.querySelectorAll(".sidebar-footer .nav-btn")].map((b) => b.querySelector(".nav-label")?.textContent)).toEqual(["Help & shortcuts", "Account", "Collapse sidebar"]);
    expect(document.querySelector(".app")).toHaveStyle({ "--sidebar-w": "240px" });
    fireEvent.click(screen.getByRole("button", { name: "Collapse the sidebar" }));
    expect(document.querySelector(".app")).toHaveClass("collapsed");
    expect(document.querySelector(".app")).toHaveStyle({ "--sidebar-w": "64px" });
    expect(nav("Projects")).toHaveAttribute("title", "Projects");
    expect(window.localStorage.getItem("echoledger-sidebar")).toBe("collapsed");
    fireEvent.click(screen.getByRole("button", { name: "Expand the sidebar" }));
    expect(document.querySelector(".app")).not.toHaveClass("collapsed");
    expect(window.localStorage.getItem("echoledger-sidebar")).toBe("expanded");
    // "[" does the same from the keyboard, never while typing.
    fireEvent.keyDown(screen.getByLabelText("Search recordings"), { key: "[" });
    expect(document.querySelector(".app")).not.toHaveClass("collapsed");
    fireEvent.keyDown(document.body, { key: "[" });
    expect(document.querySelector(".app")).toHaveClass("collapsed");
  });

  it("resizes the sidebar between 200 and 320 px by its edge and keeps the width", async () => {
    await renderPaired();
    const edge = screen.getByRole("separator", { name: "Resize the sidebar" });
    fireEvent.keyDown(edge, { key: "End" });
    expect(document.querySelector(".app")).toHaveStyle({ "--sidebar-w": "320px" });
    fireEvent.keyDown(edge, { key: "ArrowRight" });
    expect(document.querySelector(".app")).toHaveStyle({ "--sidebar-w": "320px" });
    fireEvent.keyDown(edge, { key: "Home" });
    expect(document.querySelector(".app")).toHaveStyle({ "--sidebar-w": "200px" });
    expect(window.localStorage.getItem("echoledger-sidebar-width")).toBe("200");
    fireEvent.doubleClick(edge);
    expect(window.localStorage.getItem("echoledger-sidebar-width")).toBe("240");
  });

  it("finds a page by name from the top of the sidebar", async () => {
    await renderPaired();
    fireEvent.change(screen.getByRole("combobox", { name: "Find a page" }), { target: { value: "devices" } });
    const pages = screen.getByRole("listbox", { name: "Pages" });
    expect(within(pages).getByRole("option", { name: /Devices/ })).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole("combobox", { name: "Find a page" }), { key: "Enter" });
    expect(await screen.findByRole("heading", { name: "Devices", level: 1 })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: "Find a page" })).toHaveValue("");
  });

  it("opens Help & shortcuts with ? and from the sidebar", async () => {
    await renderPaired();
    fireEvent.keyDown(document.body, { key: "?" });
    const help = await screen.findByRole("dialog", { name: "Help & shortcuts" });
    expect(within(help).getByText("Next / previous recording in the list")).toBeInTheDocument();
    fireEvent.click(within(help).getByRole("button", { name: "Close" }));
    expect(screen.queryByRole("dialog", { name: "Help & shortcuts" })).not.toBeInTheDocument();
  });

  it("opens Projects and Activity from the sidebar", async () => {
    respond("activity_list", { data: [], unread_count: 0 });
    respond("pending_list", []);
    await renderPaired();
    fireEvent.click(nav("Projects"));
    expect(await screen.findByRole("heading", { name: "Projects" })).toBeInTheDocument();
    expect(nav("Projects")).toHaveAttribute("aria-current", "page");
    fireEvent.click(nav("Activity"));
    expect(await screen.findByRole("heading", { name: "Activity" })).toBeInTheDocument();
    await flushAsync();
  });

  it("lists the pinned projects in the sidebar, every project in the Projects column, and opens one from either", async () => {
    mockBackend();
    respond("list_clients", { data: [
      { id: "c1", name: "Acme Holdings", parent_id: null, nested_session_count: 3, pinned: true },
      { id: "c3", name: "Invoices", parent_id: "c1", nested_session_count: 1 },
    ] });
    respond("client_workspace", { data: { client: { id: "c3", name: "Invoices", ai_notes: true }, ai: { configured: true, spent_usd: 0, budget_usd: 5, client_spent_usd: 0 }, brief: null, memory: [], notes: [], files: [] } });
    render(<MainApp />);
    const pinned = await screen.findByRole("list", { name: "Pinned projects" });
    expect(within(pinned).getByRole("button", { name: "Acme Holdings" })).toBeInTheDocument();
    expect(within(pinned).queryByRole("button", { name: "Invoices" })).not.toBeInTheDocument();
    fireEvent.click(nav("Projects"));
    const tree = await screen.findByRole("list", { name: "Projects in the column" });
    expect(within(tree).getByRole("button", { name: "Acme Holdings" })).toBeInTheDocument();
    expect(within(tree).getByText("3")).toBeInTheDocument();
    expect(within(tree).queryByRole("button", { name: "Invoices" })).not.toBeInTheDocument();
    fireEvent.click(within(tree).getByRole("button", { name: "Show the folders in Acme Holdings" }));
    fireEvent.click(within(tree).getByRole("button", { name: "Invoices" }));
    expect(await screen.findByRole("heading", { name: "Invoices" })).toBeInTheDocument();
    expect(within(screen.getByRole("list", { name: "Projects in the column" })).getByRole("button", { name: "Invoices" })).toHaveAttribute("aria-current", "true");
    expect(within(sidebar()).getByRole("button", { name: "Projects" })).toHaveAttribute("aria-current", "page");
    // The tree stays expanded on this computer.
    expect(JSON.parse(window.localStorage.getItem("echoledger-projects-open") ?? "[]")).toEqual(["c1"]);
    fireEvent.click(screen.getByRole("button", { name: "Collapse the sidebar" }));
    expect(screen.queryByRole("list", { name: "Pinned projects" })).not.toBeInTheDocument();
    await flushAsync();
  });

  it("reads the projects again shortly after the library changes", async () => {
    await renderPaired();
    const before = callsOf("list_clients").length;
    await emitEvent("library:updated", 1);
    await waitFor(() => expect(callsOf("list_clients").length).toBe(before + 1), { timeout: 3000 });
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

describe("MainApp theme (the avatar menu, v1.6)", () => {
  const openMenu = () => fireEvent.click(screen.getByRole("button", { name: /^Account menu:/ }));
  const item = (name: string) => screen.getByRole("menuitemradio", { name });

  it("starts on System and changes from the avatar menu", async () => {
    await renderPaired();
    openMenu();
    expect(item("Theme: System")).toHaveAttribute("aria-checked", "true");
    fireEvent.click(item("Theme: Light"));
    openMenu();
    expect(item("Theme: Light")).toHaveAttribute("aria-checked", "true");
    expect(item("Theme: System")).toHaveAttribute("aria-checked", "false");
  });

  it("applies the theme to the document and persists the choice", async () => {
    await renderPaired();
    openMenu();
    fireEvent.click(item("Theme: Light"));
    expect(document.documentElement.dataset.theme).toBe("light");
    expect(document.documentElement.style.colorScheme).toBe("light");
    expect(window.localStorage.getItem(THEME_KEY)).toBe("light");
    openMenu();
    fireEvent.click(item("Theme: Dark"));
    expect(document.documentElement.dataset.theme).toBe("dark");
    expect(window.localStorage.getItem(THEME_KEY)).toBe("dark");
  });

  it("restores the saved choice after a restart", async () => {
    window.localStorage.setItem(THEME_KEY, "dark");
    const first = await renderPaired();
    openMenu();
    expect(item("Theme: Dark")).toHaveAttribute("aria-checked", "true");
    first.unmount();
    resetTauriMock();
    window.localStorage.setItem(THEME_KEY, "light");
    await renderPaired();
    openMenu();
    expect(item("Theme: Light")).toHaveAttribute("aria-checked", "true");
  });

  it("shows a distinct icon for each choice", async () => {
    await renderPaired();
    openMenu();
    const icons = ["Theme: System", "Theme: Light", "Theme: Dark"].map((name) => item(name).querySelector("svg")?.innerHTML);
    expect(new Set(icons).size).toBe(3); // monitor (system), sun (light), moon (dark)
  });
});

describe("MainApp top bar (v1.6, P4.3)", () => {
  const account = (extra: Record<string, unknown> = {}) => ({ name: "Sara Ahmed", email: "sara@example.test", email_verified: true, two_factor: false, google: false,
    created_at: null, workspace: { id: 1, name: "Acme team", role: "owner" }, deletes_at: null, ...extra });

  it("shows the workspace, the avatar's initials, and opens Account and Plan & usage from the avatar menu", async () => {
    respond("account_get", account());
    await renderPaired();
    expect(await screen.findByRole("button", { name: "Workspace: Acme team" })).toBeInTheDocument();
    const avatar = screen.getByRole("button", { name: "Account menu: Sara Ahmed" });
    expect(avatar).toHaveTextContent("SA");
    fireEvent.click(avatar);
    expect(screen.queryByRole("menuitem", { name: "Admin panel" })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("menuitem", { name: "Plan & usage" }));
    expect(await screen.findByRole("heading", { name: "Plan & usage", level: 1 })).toBeInTheDocument();
  });

  it("offers the admin panel only to the super admin, in the browser", async () => {
    respond("account_get", account({ super_admin: true }));
    respond("server_page_open", undefined);
    await renderPaired();
    fireEvent.click(await screen.findByRole("button", { name: "Account menu: Sara Ahmed" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Admin panel" }));
    await waitFor(() => expect(callsOf("server_page_open")).toEqual([{ page: "admin", serverUrl: null }]));
  });

  it("lists the person's workspaces when the server sends them", async () => {
    respond("account_get", account({ workspaces: [{ id: 1, name: "Acme team", role: "owner", current: true }, { id: 2, name: "Personal", role: "owner" }] }));
    await renderPaired();
    fireEvent.click(await screen.findByRole("button", { name: "Workspace: Acme team" }));
    expect(screen.getByRole("menuitemradio", { name: "Acme team" })).toHaveAttribute("aria-checked", "true");
    expect(screen.getByRole("menuitemradio", { name: "Personal" })).toHaveAttribute("aria-checked", "false");
  });

  it("makes things from +: a voice note, a new project, a request link", async () => {
    respond("toggle_mic_note", makeStatus({ recording: makeRecording({ mode: "mic_note" }) }));
    respond("request_links", { data: [] });
    await renderPaired();
    fireEvent.click(screen.getByRole("button", { name: "Create" }));
    expect(screen.getAllByRole("menuitem").map((m) => m.textContent)).toEqual(["Record now", "Record a voice noteN", "Upload files", "New project", "Request link"]);
    fireEvent.click(screen.getByRole("menuitem", { name: "Record a voice note" }));
    await waitFor(() => expect(callsOf("toggle_mic_note")).toHaveLength(1));
    fireEvent.click(screen.getByRole("button", { name: "Create" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "New project" }));
    expect(await screen.findByRole("dialog", { name: "New project" })).toBeInTheDocument();
  });

  it("signs out from the avatar menu after the confirmation", async () => {
    vi.spyOn(window, "confirm").mockReturnValue(true);
    respond("unpair", makeStatus({ paired: false, serverUrl: null, deviceName: null }));
    await renderPaired();
    fireEvent.click(screen.getByRole("button", { name: /^Account menu:/ }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Sign out" }));
    expect(await screen.findByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
  });
});

describe("MainApp events, toasts and polling", () => {
  it("updates the sidebar from app:status events", async () => {
    await renderPaired();
    await emitEvent("app:status", makeStatus({ captionsLive: true, pendingUploads: 1, deviceName: "Renamed PC" }));
    expect(screen.getByRole("button", { name: "Account menu: Renamed PC" })).toBeInTheDocument();
    expect(within(sidebar()).getByText("Uploading 1 recording…")).toBeInTheDocument();
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
    respondWith("record_now", () => Promise.reject("Audio error: no input device"));
    fireEvent.click(nav("Home"));
    fireEvent.click(screen.getByRole("button", { name: "Record now" }));
    const toast = await screen.findByRole("status");
    expect(toast).toHaveTextContent("Audio error: no input device");
    expect(toast).toHaveClass("error");
    expect(screen.getByRole("heading", { name: "Home", level: 1 })).toBeInTheDocument();
  });

  it("shows the success message and refreshes the status after an action", async () => {
    await renderPaired();
    respond("copy_last_minutes", "Me: hi");
    fireEvent.click(nav("Home"));
    const before = callsOf("app_status").length;
    fireEvent.click(screen.getByRole("button", { name: "Copy last 2 minutes" }));
    expect(await screen.findByRole("status")).toHaveTextContent("Copied the last 2 minutes");
    await waitFor(() => expect(callsOf("app_status").length).toBeGreaterThan(before));
  });

  it("flips the captions switch after the status reports captions live", async () => {
    await renderPaired();
    fireEvent.click(nav("Home"));
    respond("toggle_captions", makeStatus({ captionsLive: true }));
    respond("app_status", makeStatus({ captionsLive: true }));
    fireEvent.click(screen.getByRole("switch", { name: "Toggle live captions" }));
    await waitFor(() => expect(screen.getByRole("switch", { name: "Toggle live captions" })).toHaveClass("on"));
  });

  it("signing out from Account → This computer returns to the sign-in form", async () => {
    vi.spyOn(window, "confirm").mockReturnValue(true);
    await renderPaired();
    respond("unpair", makeStatus({ paired: false, serverUrl: null, deviceName: null }));
    fireEvent.click(nav("Account"));
    fireEvent.click(await screen.findByRole("button", { name: "Sign out of this computer…" }));
    expect(await screen.findByRole("heading", { name: "Sign in to EchoLedger" })).toBeInTheDocument();
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
    fireEvent.click(nav("Home"));
    expect(banner()).toHaveTextContent("Monthly transcription limit reached.");
    fireEvent.click(nav("Account"));
    await screen.findByRole("heading", { name: "Account", level: 1 });
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

describe("MainApp shows what the tray says (A3.4)", () => {
  it("says Paused in the top bar while a recording is paused", async () => {
    await renderPaired({ recording: makeRecording(), paused: true, trayState: "paused" });
    expect(screen.getByTestId("recording-chip")).toHaveTextContent("Paused");
    expect(screen.getByTestId("recording-chip").querySelector(".status-dot")).toHaveClass("paused");
  });

  it("does not say Paused while recording", async () => {
    await renderPaired({ recording: makeRecording(), trayState: "recording" });
    expect(screen.getByTestId("recording-chip")).not.toHaveTextContent("Paused");
  });

  it("counts the recordings waiting for a decision on the bell and Waiting, and opens them from Home", async () => {
    await renderPaired({ pendingDecisions: 2, trayState: "attention" });
    expect(screen.getByRole("button", { name: "Notifications, 2 need you" })).toBeInTheDocument();
    const waiting = within(screen.getByRole("list", { name: "Smart folders" })).getByRole("button", { name: "Waiting" });
    expect(waiting).toHaveTextContent("2");
    fireEvent.click(nav("Account"));
    await screen.findByRole("heading", { name: "Account", level: 1 });
    fireEvent.click(nav("Home"));
    fireEvent.click(screen.getByRole("button", { name: "Review" }));
    expect(screen.getByRole("heading", { name: "Waiting", level: 1 })).toBeInTheDocument();
    expect(nav("Library")).toHaveClass("active");
  });

  it("points at uploads that stopped", async () => {
    await renderPaired({ failedUploads: 1, trayState: "attention" });
    expect(within(screen.getByRole("list", { name: "Smart folders" })).getByRole("button", { name: "Failed uploads" })).toHaveTextContent("1");
    fireEvent.click(nav("Home"));
    fireEvent.click(within(screen.getByRole("region", { name: "Needs you" })).getByRole("button", { name: "Open" }));
    expect(screen.getByRole("heading", { name: "Failed uploads", level: 1 })).toBeInTheDocument();
  });

  it("shows nothing that needs you when idle", async () => {
    await renderPaired();
    expect(screen.getByRole("button", { name: "Notifications" })).toBeInTheDocument();
    fireEvent.click(nav("Home"));
    expect(screen.queryByRole("region", { name: "Needs you" })).not.toBeInTheDocument();
    expect(screen.queryByTestId("recording-chip")).not.toBeInTheDocument();
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
    expect(await screen.findByRole("heading", { name: "Account", level: 1 })).toBeInTheDocument();
    expect(nav("Account")).toHaveClass("active");
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
    fireEvent.click(nav("Home"));
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
    fireEvent.click(nav("Home"));
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

  async function renderOnSmartFolder(folder: string, status: Partial<AppStatus> = {}) {
    mockBackend(status);
    respond("library_views", { folders: { inbox: 1, shared: 1 } });
    respond("library_folder", []);
    respond("shared_with_me", { data: [] });
    render(<MainApp />);
    const folders = await screen.findByRole("list", { name: "Smart folders" });
    fireEvent.click(await within(folders).findByRole("button", { name: folder }));
    expect(await screen.findByRole("heading", { name: folder })).toBeInTheDocument();
    await flushAsync();
  }

  it("opens the after-call title field from a smart folder too (the waiting recordings are in the whole Library)", async () => {
    await renderOnSmartFolder("Inbox");
    respond("pending_list", [makePendingItem("pend-1")]);
    await emitEvent("main:navigate", makeNavigate({ view: "library", pendingId: "pend-1", rename: true }));
    expect(await screen.findByRole("heading", { name: "Library" })).toBeInTheDocument();
    const row = await screen.findByTestId("pending-pend-1");
    await waitFor(() => expect(within(row).getByRole("textbox", { name: "Title" })).toHaveFocus());
  });

  it("opens a clicked notification's recording from Shared with me", async () => {
    await renderOnSmartFolder("Shared with me");
    await emitEvent("main:navigate", makeNavigate({ view: "library", recordingId: "a" }));
    expect(await screen.findByRole("heading", { name: "Library" })).toBeInTheDocument();
    expect(await screen.findByRole("heading", { name: "Zoom with Acme" })).toBeInTheDocument();
  });

  it("goes to the whole Library from the command palette", async () => {
    await renderOnSmartFolder("Inbox");
    fireEvent.keyDown(window, { key: "k", ctrlKey: true });
    const input = within(await screen.findByRole("dialog", { name: "Command palette" })).getByRole("combobox");
    fireEvent.change(input, { target: { value: "go to library" } });
    fireEvent.keyDown(input, { key: "Enter" });
    expect(await screen.findByRole("heading", { name: "Library" })).toBeInTheDocument();
  });

  it("shows the waiting recordings from the column while a smart folder is open", async () => {
    await renderOnSmartFolder("Inbox", { pendingDecisions: 1 });
    respond("pending_list", [makePendingItem("pend-1")]);
    fireEvent.click(within(screen.getByRole("list", { name: "Smart folders" })).getByRole("button", { name: "Waiting" }));
    expect(await screen.findByRole("heading", { name: "Waiting", level: 1 })).toBeInTheDocument();
    expect(await screen.findByRole("button", { name: "Review" })).toBeInTheDocument();
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
    fireEvent.change(await paletteInput(), { target: { value: "go to account" } });
    fireEvent.keyDown(await paletteInput(), { key: "Enter" });
    expect(await screen.findByRole("heading", { name: "Account", level: 1 })).toBeInTheDocument();
  });
});

describe("motion and the brand in the main window (v1.5)", () => {
  let anim: ReturnType<typeof fakeAnimations>;
  beforeEach(() => {
    anim = fakeAnimations();
    prefersReducedMotion(false);
  });
  afterEach(() => {
    anim.restore();
    vi.unstubAllGlobals();
  });
  const pageParts = () => anim.calls.filter((c) => c.el.matches("main.content"));

  it("shows the brand's mark next to the name in the sidebar, not an icon", async () => {
    await renderPaired();
    const brand = sidebar().querySelector(".brand")!;
    expect(brand.querySelector("svg.brand-mark")).toHaveAttribute("data-mark", "full");
    expect(brand).toHaveTextContent("EchoLedger");
  });

  it("draws the active page's icon filled and the others outlined", async () => {
    await renderPaired();
    const icon = (name: string) => nav(name).querySelector("svg")!;
    expect(icon("Library").querySelector("[fill=currentColor]")).not.toBeNull();
    expect(icon("Projects").querySelector("[fill=currentColor]")).toBeNull();
    fireEvent.click(nav("Projects"));
    expect(icon("Projects").querySelector("[fill=currentColor]")).not.toBeNull();
  });

  it("fades the new page in from the side it sits on in the sidebar, 200 ms, transform and opacity only", async () => {
    await renderPaired();
    fireEvent.click(nav("Account"));
    await screen.findByRole("heading", { name: "Account", level: 1 });
    expect(pageParts().length).toBeGreaterThan(0);
    for (const call of pageParts()) {
      expect(call.keyframes).toEqual([{ opacity: 0, transform: "translateY(12px)" }, { opacity: 1, transform: "none" }]);
      expect(call.options).toMatchObject({ duration: 200, fill: "none" });
    }
    anim.calls.length = 0;
    fireEvent.click(nav("Library"));
    await screen.findByRole("heading", { name: "Library" });
    expect(pageParts()[0].keyframes[0]).toEqual({ opacity: 0, transform: "translateY(-12px)" });
  });

  it("never leaves a page half-faded: a click during a switch ends the last one at once", async () => {
    await renderPaired();
    fireEvent.click(nav("Account"));
    const first = pageParts();
    fireEvent.click(nav("Home"));
    expect(first.length).toBeGreaterThan(0);
    expect(first.every((c) => c.cancel.mock.calls.length === 1)).toBe(true);
    // Nothing is held by a style: once the animations end, each part is as it is without motion.
    for (const el of document.querySelectorAll<HTMLElement>("main.content, main.content > *")) {
      expect(el.style.opacity).toBe("");
      expect(el.style.transform).toBe("");
    }
  });

  it("switches pages at once when the OS asks for reduced motion", async () => {
    prefersReducedMotion(true);
    await renderPaired();
    fireEvent.click(nav("Account"));
    await screen.findByRole("heading", { name: "Account", level: 1 });
    expect(anim.animate).not.toHaveBeenCalled();
  });

  it("puts the check that pops in on a message that confirms something, not on others", async () => {
    await renderPaired();
    await emitEvent("app:toast", "Voice note saved");
    expect(document.querySelector(".toast [data-testid=success-check]")).not.toBeNull();
    await emitEvent("app:toast", "Recording a voice note. Press N again to stop.");
    expect(document.querySelector(".toast [data-testid=success-check]")).toBeNull();
    await emitEvent("app:error", "Saved nothing: the server is offline");
    expect(document.querySelector(".toast [data-testid=success-check]")).toBeNull();
  });
});

describe("MainApp and what the plan allows (v1.6)", () => {
  it("reads the entitlements once signed in and again when the window comes to the front", async () => {
    resetEntitlementsForTests();
    respond("entitlements_get", makeEntitlements());
    await renderPaired();
    await waitFor(() => expect(callsOf("entitlements_get")).toHaveLength(1));
    await act(async () => {
      window.dispatchEvent(new Event("focus"));
    });
    await waitFor(() => expect(callsOf("entitlements_get")).toHaveLength(2));
  });

  it("keeps working with an older server that has no entitlements", async () => {
    resetEntitlementsForTests();
    respond("entitlements_get", null);
    await renderPaired();
    await waitFor(() => expect(callsOf("entitlements_get")).toHaveLength(1));
    expect(document.querySelector(".plan-badge")).toBeNull();
  });

  it("opens Settings → Account → Transcription key when a recording asks for the key", async () => {
    resetEntitlementsForTests();
    respond("entitlements_get", makeEntitlements());
    respond("account_get", { name: "Sam", email: "sam@example.test", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null });
    await renderPaired();
    await act(async () => {
      window.dispatchEvent(new CustomEvent(OPEN_SECTION_EVENT, { detail: "transcription-key" }));
    });
    expect(await screen.findByRole("heading", { name: "Transcription key", level: 1 })).toBeInTheDocument();
    expect(await screen.findByRole("region", { name: "Transcription key" })).toBeInTheDocument();
  });
});
