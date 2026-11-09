import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { backAbout, isHoldMessage, ServerBanners, showsAnnouncement } from "./ServerBanners";
import MainApp from "./MainApp";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus, anyTarget } from "../../test/fixtures";
import { MAINTENANCE_TEXT, UPDATE_REQUIRED_TEXT, type ServerNotices } from "../../lib/ipc";
import { setClock24 } from "../../lib/format";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NONE: ServerNotices = { appUpdate: null, announcement: null, maintenance: null, waiting: null };
const UPDATE = { minimum: "1.6.0", required: true, refused: false, message: "Please update EchoLedger to 1.6.0 or later.", url: "https://ledger.example.test/downloads" };

beforeEach(() => {
  resetTauriMock();
  window.localStorage.clear();
  setClock24(false);
});
afterEach(async () => {
  await flushAsync();
  vi.restoreAllMocks();
});

describe("what the server says to every app (v1.6, P2.7)", () => {
  it("shows nothing when the server says nothing", () => {
    const { container } = render(<ServerBanners server={NONE} notify={vi.fn()} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("asks for the update; Update tries the app's own updater first, then the Downloads page", async () => {
    respond("update_check", { phase: "up_to_date", current: "1.5.0", version: null, notes: null, checkedAt: null, message: null, progress: null });
    respond("server_page_open", undefined);
    const notify = vi.fn();
    render(<ServerBanners server={{ ...NONE, appUpdate: UPDATE }} notify={notify} />);
    const banner = screen.getByTestId("app-update");
    expect(banner).toHaveTextContent("Please update EchoLedger to 1.6.0 or later.");
    expect(banner).toHaveClass("warn");
    expect(banner).not.toHaveTextContent("no longer accepts");
    fireEvent.click(within(banner).getByRole("button", { name: "Update" }));
    await waitFor(() => expect(callsOf("server_page_open")).toEqual([{ page: "downloads", serverUrl: null }]));
    expect(callsOf("update_check")).toHaveLength(1);
    expect(notify).toHaveBeenCalledWith("The Downloads page opened in your browser", false, anyTarget);
  });

  it("lets the updater do it when it has a newer build", async () => {
    respond("update_check", { phase: "downloading", current: "1.5.0", version: "1.6.0", notes: null, checkedAt: null, message: null, progress: 10 });
    const notify = vi.fn();
    render(<ServerBanners server={{ ...NONE, appUpdate: UPDATE }} notify={notify} />);
    fireEvent.click(screen.getByRole("button", { name: "Update" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Downloading the update. EchoLedger says when it is ready to restart.", false, anyTarget));
    expect(callsOf("server_page_open")).toHaveLength(0);
  });

  it("says plainly that a refused version waits, and keeps the recordings", () => {
    render(<ServerBanners server={{ ...NONE, appUpdate: { ...UPDATE, refused: true }, waiting: "update_required" }} notify={vi.fn()} />);
    const banner = screen.getByTestId("app-update");
    expect(banner).toHaveClass("danger");
    expect(banner).toHaveTextContent("The server no longer accepts this version: your recordings wait on this computer and upload after the update.");
    expect(within(banner).queryByRole("button", { name: /Close/ })).not.toBeInTheDocument();
  });

  it("shows maintenance as a quiet line that says when it ends and that uploads wait", () => {
    const until = new Date(Date.now() + 3_600_000);
    until.setSeconds(0, 0);
    render(<ServerBanners server={{ ...NONE, maintenance: { message: "Upgrading the database.", until: until.toISOString() }, waiting: "maintenance" }} notify={vi.fn()} />);
    const banner = screen.getByTestId("maintenance");
    expect(banner).toHaveClass("quiet");
    expect(banner).toHaveTextContent("Upgrading the database.");
    expect(banner).toHaveTextContent("Recordings and uploads wait on this computer and go when it is back.");
    expect(banner).toHaveTextContent(/Back about/);
  });

  it("closes an announcement for its text, remembers it, and shows the next one", () => {
    const announcement = { text: "New: Send to WhatsApp from any recording.", level: "info", until: null };
    const { unmount } = render(<ServerBanners server={{ ...NONE, announcement }} notify={vi.fn()} />);
    expect(screen.getByTestId("announcement")).toHaveClass("info");
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    expect(screen.queryByTestId("announcement")).not.toBeInTheDocument();
    unmount();
    const again = render(<ServerBanners server={{ ...NONE, announcement }} notify={vi.fn()} />);
    expect(screen.queryByTestId("announcement")).not.toBeInTheDocument();
    again.unmount();
    render(<ServerBanners server={{ ...NONE, announcement: { text: "Maintenance on Sunday at 02:00.", level: "warning", until: null } }} notify={vi.fn()} />);
    expect(screen.getByTestId("announcement")).toHaveClass("warn");
  });

  it("keeps an announcement up only until its time", () => {
    const now = Date.parse("2026-11-09T10:00:00Z");
    const a = { text: "Hello", level: "info", until: "2026-11-09T12:00:00Z" };
    expect(showsAnnouncement(a, [], now)).toBe(true);
    expect(showsAnnouncement(a, [], Date.parse("2026-11-09T12:00:00Z"))).toBe(false);
    expect(showsAnnouncement({ ...a, until: null }, [], now)).toBe(true);
    expect(showsAnnouncement({ ...a, until: "soon" }, [], now)).toBe(true);
    expect(showsAnnouncement(a, ["Hello"], now)).toBe(false);
    expect(showsAnnouncement({ ...a, text: "  " }, [], now)).toBe(false);
    expect(showsAnnouncement(null, [], now)).toBe(false);
  });

  it("says when maintenance should end, only while it is ahead", () => {
    const now = new Date(2026, 10, 9, 10, 0);
    expect(backAbout(new Date(2026, 10, 9, 11, 30).toISOString(), now)).toBe("Back about 11:30 AM.");
    expect(backAbout(new Date(2026, 10, 10, 9, 0).toISOString(), now)).toMatch(/^Back about Nov 10, 9:00 AM\.$/);
    expect(backAbout(new Date(2026, 10, 9, 9, 0).toISOString(), now)).toBe("");
    expect(backAbout(null, now)).toBe("");
  });

  it("knows the core's two sentences for a held request", () => {
    expect(isHoldMessage(MAINTENANCE_TEXT)).toBe(true);
    expect(isHoldMessage(UPDATE_REQUIRED_TEXT)).toBe(true);
    expect(isHoldMessage("Server returned 500.")).toBe(false);
  });
});

describe("the main window while the server holds the work (v1.6, P2.7)", () => {
  async function renderApp(server: ServerNotices, extra: Parameters<typeof makeStatus>[0] = {}) {
    respond("app_status", makeStatus({ server, ...extra }));
    respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
    render(<MainApp />);
    await screen.findByRole("heading", { name: "Library", level: 1 });
    await flushAsync();
  }

  it("shows the banners above the page, and the waiting recordings as waiting, not uploading", async () => {
    await renderApp({ ...NONE, maintenance: { message: MAINTENANCE_TEXT, until: null }, waiting: "maintenance" }, { pendingUploads: 2 });
    expect(screen.getByTestId("maintenance")).toHaveTextContent("The server is in maintenance.");
    const sidebar = document.querySelector(".sidebar") as HTMLElement;
    expect(within(sidebar).getByText("2 recordings wait: the server is in maintenance")).toBeInTheDocument();
    expect(within(sidebar).queryByText(/Uploading/)).not.toBeInTheDocument();
  });

  it("never shows a held request as a failure", async () => {
    await renderApp(NONE);
    await emitEvent("app:error", MAINTENANCE_TEXT);
    expect(screen.getByRole("status")).toHaveTextContent(MAINTENANCE_TEXT);
    expect(screen.getByRole("status")).not.toHaveClass("error");
    await emitEvent("app:error", "The server could not be reached.");
    expect(screen.getByRole("alert")).toHaveClass("error");
  });

  it("asks for the update when this version is refused, and says the recordings wait", async () => {
    await renderApp({ ...NONE, appUpdate: { ...UPDATE, refused: true }, waiting: "update_required" }, { pendingUploads: 1 });
    expect(screen.getByTestId("app-update")).toHaveTextContent("your recordings wait on this computer");
    expect(within(document.querySelector(".sidebar") as HTMLElement).getByText("1 recording waits: update EchoLedger")).toBeInTheDocument();
  });
});
