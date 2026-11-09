import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { activitySubtitle, ActivityView } from "./ActivityCentre";
import { NOTIFICATION_CATEGORIES, NOT_SHOWN, NotificationsCard } from "./SecuritySettings";
import MainApp from "./MainApp";
import { callsOf, emitEvent, fail, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makePendingItem, makeSettings, makeSkippedItem, makeStatus, anyTarget } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS = [
  { id: "n2", kind: "transcript_ready", title: "Transcript ready", body: "Acme kickoff", session_id: "s1", read: false, at: "2026-10-28T09:00:00Z" },
  { id: "n1", kind: "device_paired", title: "Pixel 9 paired", body: "It can now record.", session_id: null, read: true, at: "2026-10-27T09:00:00Z" },
];

beforeEach(() => resetTauriMock());

describe("activity page (B18)", () => {
  function renderPage(status = makeStatus()) {
    const props = { onOpenRecording: vi.fn(), onReview: vi.fn(), onItems: vi.fn() };
    render(<ActivityView status={status} {...props} />);
    return props;
  }
  beforeEach(() => respond("pending_list", []));

  it("lists the notifications with the unread one marked, and hands the list to the badge", async () => {
    respond("activity_list", { data: ITEMS, unread_count: 1 });
    const { onItems } = renderPage();
    expect(await screen.findByText("Acme kickoff")).toBeInTheDocument();
    expect(screen.getByText("Pixel 9 paired")).toBeInTheDocument();
    expect(screen.getByText("Transcript ready").closest("button")).toHaveClass("unread");
    expect(screen.getByText("Pixel 9 paired").closest("button")).not.toHaveClass("unread");
    // The badge gets the list in an effect after the list is shown (a slow runner sees the list first).
    await waitFor(() => expect(onItems).toHaveBeenCalledWith(ITEMS));
  });

  it("has the page header: breadcrumbs, the title and one line that says what needs you (v1.6, P4.4)", async () => {
    respond("activity_list", { data: [...ITEMS, { id: "n3", kind: "transcript_failed", title: "Transcription failed", body: "Call", session_id: null, read: false, at: "2026-10-28T09:00:00Z" }], unread_count: 2 });
    renderPage(makeStatus({ pendingDecisions: 1 }));
    expect(await screen.findByText("2 things need you. Your other notifications follow, newest first.")).toBeInTheDocument();
    expect(within(screen.getByRole("navigation", { name: "Breadcrumbs" })).getByText("Activity")).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("heading", { level: 1, name: "Activity" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Mark all read" })).toBeInTheDocument();
  });

  it("words the line for each case", () => {
    expect(activitySubtitle(1, 3)).toBe("1 thing needs you. Your other notifications follow, newest first.");
    expect(activitySubtitle(0, 1)).toBe("Nothing needs you. 1 notification you have not read yet.");
    expect(activitySubtitle(0, 4)).toBe("Nothing needs you. 4 notifications you have not read yet.");
    expect(activitySubtitle(0, 0)).toBe("Nothing needs you. Finished transcripts, problems, devices and shares show up here.");
  });

  it("opens a recording from its notification and marks it read", async () => {
    respond("activity_list", { data: ITEMS, unread_count: 1 });
    respond("activity_read", { unread_count: 0 });
    const { onOpenRecording, onItems } = renderPage();
    fireEvent.click(await screen.findByText("Acme kickoff"));
    await waitFor(() => expect(onOpenRecording).toHaveBeenCalledWith("s1"));
    await waitFor(() => expect(callsOf("activity_read")).toEqual([{ ids: ["n2"] }]));
    await waitFor(() => expect(onItems.mock.lastCall?.[0].every((i: { read: boolean }) => i.read)).toBe(true));
    await waitFor(() => expect(screen.getByText("Transcript ready").closest("button")).not.toHaveClass("unread"));
  });

  it("marks everything read", async () => {
    respondWith("activity_list", () => ({ data: ITEMS, unread_count: 1 }));
    respond("activity_read", { unread_count: 0 });
    const { onItems } = renderPage();
    fireEvent.click(await screen.findByRole("button", { name: "Mark all read" }));
    await waitFor(() => expect(callsOf("activity_read")).toEqual([{ ids: null }]));
    await waitFor(() => expect(onItems.mock.lastCall?.[0].every((i: { read: boolean }) => i.read)).toBe(true));
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
    render(<ActivityView status={makeStatus()} notify={notify} onOpenRecording={vi.fn()} onReview={vi.fn()} onItems={vi.fn()} />);
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
    expect(notify).toHaveBeenCalledWith("Restored. It uploads now and shows in the Library.", false, anyTarget);
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
  function mockApp(status = makeStatus()) {
    respond("app_status", status);
    respond("library_list", []);
    respond("library_refresh", []);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
  }
  const failed = (id: string, read = false) => ({ id, kind: "transcript_failed", title: "Transcription failed", body: "Call", session_id: null, read, at: "2026-10-28T09:00:00Z" });
  const ready = (id: string) => ({ id, kind: "transcript_ready", title: "Transcript ready", body: "Call", session_id: null, read: false, at: "2026-10-28T09:00:00Z" });

  it("counts only what needs the person, and follows updates from the core (v1.6, P6)", async () => {
    mockApp();
    let list = [...ITEMS, failed("f1")];
    respondWith("activity_list", () => ({ data: list, unread_count: list.filter((i) => !i.read).length }));
    render(<MainApp />);
    // One unread finished transcript and one failure: the badge says 1, not 2.
    expect(await screen.findByRole("button", { name: /^Activity\s*,\s*1 needs you$/ })).toBeInTheDocument();
    list = [...list, failed("f2"), failed("f3"), { ...failed("u1"), kind: "upgrade_approved" }];
    await emitEvent("activity:updated", 6);
    expect(await screen.findByRole("button", { name: /^Activity\s*,\s*4 need you$/ })).toBeInTheDocument();
    list = list.map((i) => ({ ...i, read: true }));
    await emitEvent("activity:updated", 0);
    await waitFor(() => expect(screen.getByRole("button", { name: "Activity" })).toBeInTheDocument());
  });

  it("is never 21: twenty finished transcripts count nothing, and more than nine say 9+", async () => {
    mockApp(makeStatus({ pendingDecisions: 2 }));
    const list = [...Array.from({ length: 20 }, (_, i) => ready(`r${i}`)), ...Array.from({ length: 9 }, (_, i) => failed(`f${i}`))];
    respond("activity_list", { data: list, unread_count: 29 });
    render(<MainApp />);
    const activity = await screen.findByRole("button", { name: /^Activity\s*,\s*11 need you$/ });
    expect(within(activity).getByText("9+")).toBeInTheDocument();
  });

  it("counts the calls waiting for Keep or Discard", async () => {
    mockApp(makeStatus({ pendingDecisions: 1 }));
    respond("activity_list", { data: ITEMS, unread_count: 1 });
    render(<MainApp />);
    expect(await screen.findByRole("button", { name: /^Activity\s*,\s*1 needs you$/ })).toBeInTheDocument();
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

  it("mirrors the phone's categories, each on or off with its sound, and marks Critical", () => {
    expect(NOTIFICATION_CATEGORIES.map(([, label]) => label)).toEqual([
      "Recording", "Transcription", "Enhancement", "Uploads", "Call capture & readiness", "Sharing", "Account & security",
      "Usage & limits", "Live Translate", "App updates", "Other",
    ]);
    const patch = vi.fn(async () => true);
    render(<NotificationsCard settings={makeSettings({ notificationCategories: { sharing: false }, notificationSounds: { uploads: false } })} patch={patch} />);
    expect(screen.getByRole("switch", { name: "Sharing" })).toHaveAttribute("aria-checked", "false");
    expect(screen.getByRole("switch", { name: "Transcription" })).toHaveAttribute("aria-checked", "true");
    expect(screen.getByRole("button", { name: "Sound for Uploads" })).toHaveTextContent("Silent");
    expect(screen.getByRole("button", { name: "Sound for Sharing" })).toBeDisabled();
    expect(screen.getByTestId("notify-call_capture")).toHaveTextContent("Critical");
    expect(screen.getByTestId("notify-call_capture")).toHaveTextContent("quiet hours never hold these back");
    fireEvent.click(screen.getByRole("switch", { name: "Sharing" }));
    fireEvent.click(screen.getByRole("switch", { name: "Uploads" }));
    fireEvent.click(screen.getByRole("button", { name: "Sound for Uploads" }));
    expect(patch.mock.calls).toEqual([[{ notificationCategories: { sharing: true } }], [{ notificationCategories: { uploads: false } }], [{ notificationSounds: { uploads: true } }]]);
  });

  it("turns every kind off with Show notifications", () => {
    render(<NotificationsCard settings={makeSettings({ activityNotifications: false })} patch={vi.fn(async () => true)} />);
    expect(screen.getByRole("switch", { name: "Recording" })).toBeDisabled();
  });

  it("sends a test of a kind and says when the system would not show it", async () => {
    respondWith("notification_test", ({ category }) => category !== "other");
    const said: string[] = [];
    const run = vi.fn(async <T,>(fn: () => Promise<T>, ok?: string) => {
      try {
        const r = await fn();
        if (ok) said.push(ok);
        return r;
      } catch (e) {
        said.push(String(e));
        return undefined;
      }
    });
    render(<NotificationsCard settings={makeSettings()} patch={vi.fn(async () => true)} run={run as never} />);
    fireEvent.click(screen.getByRole("button", { name: "Test Transcription" }));
    fireEvent.click(screen.getByRole("button", { name: "Test Other" }));
    await waitFor(() => expect(said).toHaveLength(2));
    expect(callsOf("notification_test")).toEqual([{ category: "transcription" }, { category: "other" }]);
    expect(said).toEqual(["Test sent: Transcription", NOT_SHOWN]);
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

describe("In progress (Phase 1C)", () => {
  it("lists uploads with their percent and recordings being transcribed, as the Library says them", async () => {
    const { inProgressRows } = await import("./ActivityCentre");
    const rows = inProgressRows(
      [{ id: "u1", sourceApp: "zoom", title: "Imported memo", startedAt: "", durationMs: 0, state: "uploading", lastError: null, attempts: 0, imported: true, progress: 42 },
        { id: "u2", sourceApp: "zoom", title: "Stopped", startedAt: "", durationMs: 0, state: "failed", lastError: "x", attempts: 3, imported: false, progress: null }],
      [makeItem("s1", { title: "Acme call", status: "processing", transcript: { final_status: "processing", final_transcript_id: null, live_available: false, preview: null, final_progress: { done: 0, total: 1, eta_seconds: 20 } } }),
        makeItem("s2", { title: "Done call" })],
    );
    expect(rows).toEqual([
      { id: "upload-u1", title: "Imported memo", line: "Uploading… 42%", recordingId: null },
      { id: "s1", title: "Acme call", line: "Transcribing… about 20 s left", recordingId: "s1" },
    ]);
  });
});
