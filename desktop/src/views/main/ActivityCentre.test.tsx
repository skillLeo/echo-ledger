import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { ActivityBell } from "./ActivityCentre";
import { NotificationsCard } from "./SecuritySettings";
import MainApp from "./MainApp";
import { callsOf, emitEvent, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS = [
  { id: "n2", kind: "transcript_ready", title: "Transcript ready", body: "Acme kickoff", session_id: "s1", read: false, at: "2026-10-28T09:00:00Z" },
  { id: "n1", kind: "device_paired", title: "Pixel 9 paired", body: "It can now record.", session_id: null, read: true, at: "2026-10-27T09:00:00Z" },
];

beforeEach(() => resetTauriMock());

describe("activity bell (B18)", () => {
  it("shows the unread count and follows updates from the core", async () => {
    respond("activity_list", { data: ITEMS, unread_count: 1 });
    render(<ActivityBell onOpenRecording={vi.fn()} />);
    expect(await screen.findByRole("button", { name: "Activity, 1 unread" })).toBeInTheDocument();
    await emitEvent("activity:updated", 4);
    expect(screen.getByRole("button", { name: "Activity, 4 unread" })).toBeInTheDocument();
    await emitEvent("activity:updated", 0);
    expect(screen.getByRole("button", { name: "Activity" })).toBeInTheDocument();
  });

  it("opens a recording from its notification and marks it read", async () => {
    respond("activity_list", { data: ITEMS, unread_count: 1 });
    respond("activity_read", { unread_count: 0 });
    const onOpen = vi.fn();
    render(<ActivityBell onOpenRecording={onOpen} />);
    fireEvent.click(await screen.findByRole("button", { name: "Activity, 1 unread" }));
    const panel = await screen.findByRole("dialog", { name: "Activity" });
    fireEvent.click(await within(panel).findByText("Acme kickoff"));
    await waitFor(() => expect(onOpen).toHaveBeenCalledWith("s1"));
    expect(callsOf("activity_read")).toEqual([{ ids: ["n2"] }]);
    expect(screen.queryByRole("dialog", { name: "Activity" })).not.toBeInTheDocument();
  });

  it("closes with its close button, with Escape and with a click anywhere outside", async () => {
    respond("activity_list", { data: ITEMS, unread_count: 1 });
    render(<div><ActivityBell onOpenRecording={vi.fn()} /><p>Somewhere else</p></div>);
    const bell = await screen.findByRole("button", { name: "Activity, 1 unread" });

    fireEvent.click(bell);
    fireEvent.click(await screen.findByRole("button", { name: "Close activity" }));
    expect(screen.queryByRole("dialog", { name: "Activity" })).not.toBeInTheDocument();

    fireEvent.click(bell);
    await screen.findByRole("dialog", { name: "Activity" });
    fireEvent.keyDown(window, { key: "Escape" });
    expect(screen.queryByRole("dialog", { name: "Activity" })).not.toBeInTheDocument();

    fireEvent.click(bell);
    const panel = await screen.findByRole("dialog", { name: "Activity" });
    fireEvent.mouseDown(panel); // a press inside does not close it
    expect(screen.getByRole("dialog", { name: "Activity" })).toBeInTheDocument();
    fireEvent.mouseDown(screen.getByText("Somewhere else"));
    expect(screen.queryByRole("dialog", { name: "Activity" })).not.toBeInTheDocument();
  });
  it("marks everything read and explains an empty list", async () => {
    respondWith("activity_list", () => ({ data: ITEMS, unread_count: 1 }));
    respond("activity_read", { unread_count: 0 });
    render(<ActivityBell onOpenRecording={vi.fn()} />);
    fireEvent.click(await screen.findByRole("button", { name: "Activity, 1 unread" }));
    fireEvent.click(await screen.findByRole("button", { name: "Mark all read" }));
    await waitFor(() => expect(callsOf("activity_read")).toEqual([{ ids: null }]));
    expect(screen.getByRole("button", { name: "Activity" })).toBeInTheDocument();
  });

  it("says when nothing has happened yet", async () => {
    respond("activity_list", { data: [], unread_count: 0 });
    render(<ActivityBell onOpenRecording={vi.fn()} />);
    fireEvent.click(await screen.findByRole("button", { name: "Activity" }));
    expect(await screen.findByText(/Nothing yet/)).toBeInTheDocument();
  });
});

describe("notification settings", () => {
  it("turns notifications and quiet hours on and sets the times", () => {
    const patch = vi.fn(async () => true);
    render(<NotificationsCard settings={makeSettings({ quietHours: true })} patch={patch} />);
    fireEvent.click(screen.getByRole("switch", { name: "Show notifications" }));
    fireEvent.change(screen.getByLabelText("From"), { target: { value: "23:15" } });
    fireEvent.change(screen.getByLabelText("to"), { target: { value: "06:45" } });
    fireEvent.click(screen.getByRole("switch", { name: "Quiet hours" }));
    expect(patch.mock.calls).toEqual([[{ activityNotifications: false }], [{ quietFrom: "23:15" }], [{ quietTo: "06:45" }], [{ quietHours: false }]]);
  });

  it("disables the times while quiet hours are off", () => {
    render(<NotificationsCard settings={makeSettings()} patch={vi.fn(async () => true)} />);
    expect(screen.getByLabelText("From")).toBeDisabled();
  });
});

describe("a clicked notification opens its recording", () => {
  it("navigates to the library and opens the recording", async () => {
    respond("app_status", makeStatus());
    respond("library_list", [makeItem("s1", { title: "Acme kickoff" })]);
    respond("library_refresh", [makeItem("s1", { title: "Acme kickoff" })]);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("activity_list", { data: [], unread_count: 0 });
    respond("lock_status", { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 });
    respond("lock_touch", undefined);
    respondWith("session_detail", ({ id }) => ({ id, playback_url: null }));
    respond("transcript_detail", { id: "t", kind: "final", provider: "p", status: "completed", language: null, text: null, segments: [] });
    render(<MainApp />);
    await screen.findAllByText("Acme kickoff");
    await emitEvent("main:navigate", { view: "library", pendingId: null, rename: false, recordingId: "s1" });
    await waitFor(() => expect(callsOf("session_detail")).toContainEqual({ id: "s1" }));
  });
});
