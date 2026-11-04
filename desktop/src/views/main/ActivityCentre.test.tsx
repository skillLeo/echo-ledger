import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { ActivityView } from "./ActivityCentre";
import { NotificationsCard } from "./SecuritySettings";
import MainApp from "./MainApp";
import { callsOf, emitEvent, fail, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makePendingItem, makeSettings, makeSkippedItem, makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS = [
  { id: "n2", kind: "transcript_ready", title: "Transcript ready", body: "Acme kickoff", session_id: "s1", read: false, at: "2026-10-28T09:00:00Z" },
  { id: "n1", kind: "device_paired", title: "Pixel 9 paired", body: "It can now record.", session_id: null, read: true, at: "2026-10-27T09:00:00Z" },
];

beforeEach(() => resetTauriMock());

describe("activity page (B18)", () => {
  function renderPage(status = makeStatus()) {
    const props = { onOpenRecording: vi.fn(), onReview: vi.fn(), onUnread: vi.fn() };
    render(<ActivityView status={status} {...props} />);
    return props;
  }
  beforeEach(() => respond("pending_list", []));

  it("lists the notifications with the unread one marked, and reports the unread count", async () => {
    respond("activity_list", { data: ITEMS, unread_count: 1 });
    const { onUnread } = renderPage();
    expect(await screen.findByText("Acme kickoff")).toBeInTheDocument();
    expect(screen.getByText("Pixel 9 paired")).toBeInTheDocument();
    expect(screen.getByText("Transcript ready").closest("button")).toHaveClass("unread");
    expect(screen.getByText("Pixel 9 paired").closest("button")).not.toHaveClass("unread");
    expect(onUnread).toHaveBeenCalledWith(1);
  });

  it("opens a recording from its notification and marks it read", async () => {
    respond("activity_list", { data: ITEMS, unread_count: 1 });
    respond("activity_read", { unread_count: 0 });
    const { onOpenRecording, onUnread } = renderPage();
    fireEvent.click(await screen.findByText("Acme kickoff"));
    await waitFor(() => expect(onOpenRecording).toHaveBeenCalledWith("s1"));
    expect(callsOf("activity_read")).toEqual([{ ids: ["n2"] }]);
    expect(onUnread).toHaveBeenLastCalledWith(0);
    expect(screen.getByText("Transcript ready").closest("button")).not.toHaveClass("unread");
  });

  it("marks everything read", async () => {
    respondWith("activity_list", () => ({ data: ITEMS, unread_count: 1 }));
    respond("activity_read", { unread_count: 0 });
    const { onUnread } = renderPage();
    fireEvent.click(await screen.findByRole("button", { name: "Mark all read" }));
    await waitFor(() => expect(callsOf("activity_read")).toEqual([{ ids: null }]));
    expect(onUnread).toHaveBeenLastCalledWith(0);
    expect(screen.queryByRole("button", { name: "Mark all read" })).not.toBeInTheDocument();
  });

  it("says when nothing has happened yet", async () => {
    respond("activity_list", { data: [], unread_count: 0 });
    renderPage();
    expect(await screen.findByText(/Nothing yet/)).toBeInTheDocument();
  });

  it("puts what needs the person first: calls waiting for Keep or Discard and failed transcriptions", async () => {
    respond("pending_list", [makePendingItem("p1")]);
    respond("activity_list", { data: [{ id: "n3", kind: "transcript_failed", title: "Transcription failed", body: "Call with Omar", session_id: "s3", read: false, at: "2026-10-28T09:00:00Z" }, ...ITEMS], unread_count: 2 });
    const { onReview } = renderPage(makeStatus({ pendingDecisions: 1 }));
    const needs = await screen.findByRole("region", { name: "Needs action" });
    expect(await within(needs).findByText("Keep or discard?")).toBeInTheDocument();
    expect(within(needs).getByText("Transcription failed")).toBeInTheDocument();
    // The card says what waits once the core's list is in (the status only has the count).
    await waitFor(() => expect(within(needs).queryByText("1 waiting for your decision")).not.toBeInTheDocument());
    fireEvent.click(within(needs).getByRole("button", { name: "Review" }));
    expect(onReview).toHaveBeenCalledWith("p1");
  });

  it("shows the error with Retry when the list cannot be read", async () => {
    respond("activity_list", { data: [], unread_count: 0 });
    fail("activity_list", "Network error");
    renderPage();
    expect(await screen.findByRole("alert")).toHaveTextContent("Couldn't load the activity. Network error");
    respond("activity_list", { data: ITEMS, unread_count: 1 });
    fireEvent.click(screen.getByRole("button", { name: "Retry" }));
    expect(await screen.findByText("Acme kickoff")).toBeInTheDocument();
  });
});

describe("skipped calls (v1.5)", () => {
  const local = (hour: number, minute = 0) => new Date(2026, 9, 24, hour, minute).toISOString();
  const SKIPPED = () => [
    makeSkippedItem("a", { startedAt: local(9, 12), durationMs: 6_000 }),
    makeSkippedItem("b", { sourceApp: "zoom", callerName: "Omar Khan", reason: "no_speech", startedAt: local(8, 5), durationMs: 95_000 }),
    makeSkippedItem("c", { sourceApp: "microsoft_teams", title: "Stand-up", startedAt: local(7, 30), durationMs: 4_000 }),
  ];
  function renderPage() {
    const notify = vi.fn();
    render(<ActivityView status={makeStatus()} notify={notify} onOpenRecording={vi.fn()} onReview={vi.fn()} onUnread={vi.fn()} />);
    return notify;
  }
  const line = () => screen.queryByTestId("skipped-calls");

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"], now: new Date(2026, 9, 24, 12, 0, 0) });
    respond("pending_list", []);
    respond("activity_list", { data: ITEMS, unread_count: 1 });
    respond("skipped_restore", undefined);
  });
  afterEach(() => vi.useRealTimers());

  it("is absent when nothing was skipped", async () => {
    respond("skipped_list", []);
    renderPage();
    await screen.findByText("Acme kickoff");
    await waitFor(() => expect(callsOf("skipped_list")).toHaveLength(1));
    expect(line()).not.toBeInTheDocument();
  });

  it("is one quiet line; Show lists each call with why it was skipped", async () => {
    respond("skipped_list", SKIPPED());
    renderPage();
    expect(await screen.findByText("Skipped 3 short calls")).toBeInTheDocument();
    expect(within(line()!).queryByRole("button", { name: /^Restore/ })).not.toBeInTheDocument();
    fireEvent.click(within(line()!).getByRole("button", { name: "Show" }));
    expect(within(line()!).getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "WhatsApp · Today · 9:12 AM · 0:06 · Too shortRestore",
      "Call with Omar Khan · Today · 8:05 AM · 1:35 · No speechRestore",
      "Stand-up · Today · 7:30 AM · 0:04 · Too shortRestore",
    ]);
    expect(within(line()!).getByRole("button", { name: "Hide" })).toHaveAttribute("aria-expanded", "true");
  });

  it("says Skipped 1 short call for one, and sits above the notifications", async () => {
    respond("skipped_list", [makeSkippedItem("a")]);
    renderPage();
    const text = await screen.findByText("Skipped 1 short call");
    const notification = await screen.findByText("Acme kickoff");
    expect(text.compareDocumentPosition(notification) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it("Restore keeps the call, takes it off the line and says so", async () => {
    respond("skipped_list", SKIPPED());
    const notify = renderPage();
    fireEvent.click(await screen.findByRole("button", { name: "Show" }));
    fireEvent.click(screen.getByRole("button", { name: /^Restore Call with Omar Khan/ }));
    await waitFor(() => expect(callsOf("skipped_restore")).toEqual([{ id: "b" }]));
    await waitFor(() => expect(screen.getByText("Skipped 2 short calls")).toBeInTheDocument());
    expect(screen.queryByText(/Call with Omar Khan/)).not.toBeInTheDocument();
    expect(notify).toHaveBeenCalledWith("Restored. It uploads now and shows in the Library.");
  });

  it("says why a Restore failed and reads the list again", async () => {
    respond("skipped_list", SKIPPED());
    fail("skipped_restore", "This call is no longer in the skipped list.");
    const notify = renderPage();
    fireEvent.click(await screen.findByRole("button", { name: "Show" }));
    respond("skipped_list", SKIPPED().slice(1));
    fireEvent.click(screen.getByRole("button", { name: /^Restore WhatsApp/ }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("This call is no longer in the skipped list.", true));
    await waitFor(() => expect(screen.getByText("Skipped 2 short calls")).toBeInTheDocument());
    expect(callsOf("skipped_list")).toHaveLength(2);
  });

  it("follows skipped:changed and goes when the list is empty (24 hours later, or all restored)", async () => {
    respond("skipped_list", [makeSkippedItem("a")]);
    renderPage();
    await screen.findByText("Skipped 1 short call");
    fireEvent.click(screen.getByRole("button", { name: "Show" }));
    await emitEvent("skipped:changed", SKIPPED());
    expect(screen.getByText("Skipped 3 short calls")).toBeInTheDocument();
    await emitEvent("skipped:changed", []);
    expect(line()).not.toBeInTheDocument();
    await emitEvent("skipped:changed", [makeSkippedItem("z")]);
    expect(within(line()!).getByRole("button", { name: "Show" })).toHaveAttribute("aria-expanded", "false");
  });

  it("keeps a long or Urdu title on one line in its own direction", async () => {
    respond("skipped_list", [makeSkippedItem("u", { title: "کال اسلم کے ساتھ" })]);
    renderPage();
    fireEvent.click(await screen.findByRole("button", { name: "Show" }));
    const meta = screen.getByText(/کال اسلم کے ساتھ/);
    expect(meta).toHaveAttribute("dir", "auto");
    expect(meta).toHaveClass("skipped-meta");
  });
});

describe("Activity in the sidebar", () => {
  it("counts the unread notifications and follows updates from the core", async () => {
    respond("app_status", makeStatus());
    respond("library_list", []);
    respond("library_refresh", []);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
    respond("activity_list", { data: ITEMS, unread_count: 1 });
    render(<MainApp />);
    expect(await screen.findByRole("button", { name: /^Activity\s*,\s*1 unread$/ })).toBeInTheDocument();
    await emitEvent("activity:updated", 4);
    expect(screen.getByRole("button", { name: /^Activity\s*,\s*4 unread$/ })).toBeInTheDocument();
    await emitEvent("activity:updated", 0);
    expect(screen.getByRole("button", { name: "Activity" })).toBeInTheDocument();
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
