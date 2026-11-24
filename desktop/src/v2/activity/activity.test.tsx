// Activity in the new design (ActivityV2): the same page, rules and commands as today's Activity.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makePendingItem, makeSkippedItem, makeStatus } from "../../test/fixtures";
import { Shell } from "../shell/Shell";
import { ActivityV2, retryAllWords } from "./ActivityV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 9, 28, 15, 0);
const ITEMS = [
  { id: "n3", kind: "transcript_failed", title: "Transcription failed", body: "Call with Omar", session_id: "s3", read: false, at: new Date(2026, 9, 28, 10, 0).toISOString() },
  { id: "n2", kind: "transcript_ready", title: "Transcript ready", body: "Acme kickoff", session_id: "s1", read: false, at: new Date(2026, 9, 28, 9, 0).toISOString() },
  { id: "n1", kind: "device_paired", title: "Pixel 9 paired", body: "It can now record.", session_id: null, read: true, at: new Date(2026, 9, 27, 9, 0).toISOString() },
];

beforeEach(() => {
  resetTauriMock();
  vi.useFakeTimers({ toFake: ["Date"], now: NOW });
  respond("pending_list", []);
  respond("skipped_list", []);
  respond("upload_list", []);
  respond("library_list", []);
  respond("recovered_sessions", []);
  respond("activity_list", { data: ITEMS, unread_count: 2 });
  respond("activity_read", { unread_count: 0 });
  respond("skipped_restore", undefined);
});
afterEach(() => vi.useRealTimers());

async function show(status = makeStatus()) {
  const props = { onOpenRecording: vi.fn(), onReview: vi.fn(), onFailed: vi.fn(), onItems: vi.fn(), say: vi.fn() };
  render(<div className="el-root"><ActivityV2 status={status} {...props} /></div>);
  await act(flushAsync);
  return props;
}
const row = (title: string) => screen.getByText(title).closest("li") as HTMLElement;

describe("the v2 Activity page", () => {
  it("has its title and the one line that says how many things need you, and hands the list to the badge", async () => {
    const { onItems } = await show(makeStatus({ pendingDecisions: 1 }));
    expect(screen.getByRole("heading", { level: 1, name: "Activity" })).toBeInTheDocument();
    expect(screen.getByText("2 things need you. Your other notifications follow, newest first.")).toBeInTheDocument();
    expect(onItems).toHaveBeenCalledWith(ITEMS);
  });

  it("Needs action: Transcribe or delete? › Review opens the first waiting recording", async () => {
    respond("pending_list", [makePendingItem("p1"), makePendingItem("p2")]);
    const { onReview } = await show();
    const needs = screen.getByRole("list", { name: "Needs action" });
    expect(within(needs).getByText("Transcribe or delete?")).toBeInTheDocument();
    fireEvent.click(within(row("Transcribe or delete?")).getByRole("button", { name: "Review" }));
    expect(onReview).toHaveBeenCalledWith("p1");
  });

  it("Needs action: Uploads that need you › Open, and the unread problems the badge counts", async () => {
    const { onFailed } = await show(makeStatus({ failedUploads: 2 }));
    expect(row("Uploads that need you")).toHaveTextContent("2 recordings did not finish uploading");
    fireEvent.click(within(row("Uploads that need you")).getByRole("button", { name: "Open" }));
    expect(onFailed).toHaveBeenCalled();
    expect(within(screen.getByRole("list", { name: "Needs action" })).getByText("Transcription failed")).toBeInTheDocument();
  });

  it("a notification marks itself read and opens its recording; Mark all read reads them all", async () => {
    const { onOpenRecording } = await show();
    expect(row("Transcript ready")).toHaveTextContent("Unread");
    fireEvent.click(within(row("Transcript ready")).getByRole("button"));
    await act(flushAsync);
    expect(callsOf("activity_read")).toEqual([{ ids: ["n2"] }]);
    expect(onOpenRecording).toHaveBeenCalledWith("s1");
    expect(row("Transcript ready")).not.toHaveTextContent("Unread");
    fireEvent.click(screen.getByRole("button", { name: "Mark all read" }));
    await act(flushAsync);
    expect(callsOf("activity_read")).toEqual([{ ids: ["n2"] }, { ids: null }]);
    expect(screen.queryByText("Unread")).toBeNull();
    expect(screen.queryByRole("button", { name: "Mark all read" })).toBeNull();
  });

  it("an item's right-click menu (UX-22, 3b): Open · Mark as read | Retry for an upload that stopped; only what fits it", async () => {
    respond("activity_list", { data: [...ITEMS,
      { id: "n4", kind: "upload_failed", title: "Upload failed — retry", body: "Voice note", session_id: "s4", read: true, at: new Date(2026, 9, 28, 8, 0).toISOString() },
      { id: "n5", kind: "budget_reached", title: "Minutes used up", body: "This month's minutes are used.", session_id: null, read: true, at: new Date(2026, 9, 28, 7, 0).toISOString() }], unread_count: 2 });
    respond("activity_read", { unread_count: 1 });
    const { onOpenRecording, onFailed } = await show();
    const words = () => [...screen.getByRole("menu").querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent);
    const menuOn = (title: string) => fireEvent.contextMenu(screen.getAllByText(title)[0].closest(".el-row") ?? row(title), { clientX: 20, clientY: 30 });
    // An upload that stopped: Retry, where failed uploads are retried.
    menuOn("Upload failed — retry");
    expect(words()).toEqual(["Open", "Retry"]);
    fireEvent.click(screen.getByRole("menuitem", { name: "Retry" }));
    expect(onFailed).toHaveBeenCalledTimes(1);
    // A transcription that failed is tried again on its recording's page (Open), not in failed uploads; minutes used up
    // have nothing to retry.
    menuOn("Transcription failed");
    expect(words()).toEqual(["Open", "Mark as read"]);
    fireEvent.keyDown(screen.getByRole("menu"), { key: "Escape" });
    expect(screen.queryByRole("menu")).toBeNull();
    menuOn("Minutes used up");
    expect(screen.queryByRole("menu")).toBeNull();
    expect(onFailed).toHaveBeenCalledTimes(1);
    fireEvent.contextMenu(row("Transcript ready"), { clientX: 20, clientY: 30 });
    expect(words()).toEqual(["Open", "Mark as read"]);
    fireEvent.click(screen.getByRole("menuitem", { name: "Mark as read" }));
    await act(flushAsync);
    expect(callsOf("activity_read")).toEqual([{ ids: ["n2"] }]);
    fireEvent.contextMenu(row("Transcript ready"), { clientX: 20, clientY: 30 });
    expect(words()).toEqual(["Open"]);
    fireEvent.click(screen.getByRole("menuitem", { name: "Open" }));
    await act(flushAsync);
    expect(onOpenRecording).toHaveBeenCalledWith("s1");
    // A read item with nothing to open: no menu at all.
    fireEvent.contextMenu(row("Pixel 9 paired"), { clientX: 20, clientY: 30 });
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("an upload that has since reached the server (resolved) offers no Retry: only Open", async () => {
    respond("activity_list", { data: [
      { id: "n6", kind: "upload_failed", title: "Uploaded", body: "Voice note", session_id: "s6", read: true, resolved: true, at: new Date(2026, 9, 28, 8, 0).toISOString() }], unread_count: 0 });
    const { onFailed } = await show();
    fireEvent.contextMenu(row("Uploaded"), { clientX: 20, clientY: 30 });
    const words = [...screen.getByRole("menu").querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent);
    expect(words).toEqual(["Open"]);
    expect(screen.queryByRole("menuitem", { name: "Retry" })).toBeNull();
    expect(onFailed).not.toHaveBeenCalled();
  });

  it("groups the rest by Today and Earlier, the time today and the day before; a recovered recording says Recovered", async () => {
    respond("recovered_sessions", ["s1"]);
    await show();
    expect(within(screen.getByRole("list", { name: "Today" })).getByText("Transcript ready")).toBeInTheDocument();
    expect(within(screen.getByRole("list", { name: "Earlier" })).getByText("Pixel 9 paired")).toBeInTheDocument();
    expect(row("Transcript ready")).toHaveTextContent("9:00 AM");
    expect(row("Transcript ready")).toHaveTextContent("Recovered · Acme kickoff");
    expect(row("Pixel 9 paired")).not.toHaveTextContent("Recovered");
  });

  it("In progress: uploads with their percent and recordings being transcribed, with Open", async () => {
    respond("upload_list", [{ id: "u1", sourceApp: "zoom", title: "Imported memo", startedAt: "", durationMs: 0, state: "uploading", lastError: null, attempts: 0, imported: true, progress: 42, heldFor: null }]);
    respond("library_list", [makeItem("s9", { title: "Acme call", status: "processing", updated_at: NOW.toISOString(), transcript: { final_status: "processing", final_transcript_id: null, live_available: false, preview: null, final_progress: { done: 0, total: 1, eta_seconds: 20 } } })]);
    const { onOpenRecording } = await show();
    const list = screen.getByRole("list", { name: "In progress" });
    expect(within(list).getByText("Imported memo").closest("li")).toHaveTextContent("Uploading… 42%");
    expect(row("Acme call")).toHaveTextContent("Transcribing… about 20 s left");
    fireEvent.click(within(row("Acme call")).getByRole("button", { name: "Open" }));
    expect(onOpenRecording).toHaveBeenCalledWith("s9");
  });

  it("Skipped short calls: one line, Show / Hide, Restore says what happened", async () => {
    respond("skipped_list", [makeSkippedItem("a"), makeSkippedItem("b", { callerName: "Omar Khan" })]);
    const { say } = await show();
    const line = screen.getByTestId("skipped-calls");
    expect(line).toHaveTextContent("Skipped 2 short calls");
    fireEvent.click(within(line).getByRole("button", { name: "Show" }));
    expect(within(line).getByRole("button", { name: "Hide" })).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(within(line).getByRole("button", { name: /^Restore Call with Omar Khan/ }));
    await act(flushAsync);
    expect(callsOf("skipped_restore")).toEqual([{ id: "b" }]);
    expect(line).toHaveTextContent("Skipped 1 short call");
    expect(say).toHaveBeenCalledWith("Restored. It uploads now and shows in the Library.");
  });

  it("2.0.0-v: the server's own problem (super admins only) shows its words as they are, with the server's icon; fixed, it is done", async () => {
    respond("activity_list", { data: [
      { id: "s1", kind: "server_problem", title: "Uploads are failing: the server's PHP is missing sodium", body: "sudo apt install php8.3-sodium", session_id: null, read: false, at: NOW.toISOString() },
      { id: "s2", kind: "server_problem", title: "Fixed: uploads work again", body: "", session_id: null, read: false, at: NOW.toISOString(), resolved: true },
    ], unread_count: 2 });
    const { onItems } = await show();
    const open = row("Uploads are failing: the server's PHP is missing sodium");
    expect(open).toHaveTextContent("sudo apt install php8.3-sodium");
    // The server's icon (Lucide server: its two racks' dots), not the bell or the generic error.
    expect(open.querySelector('svg path[d="M6 6h.01"]')).not.toBeNull();
    const fixed = row("Fixed: uploads work again");
    expect(fixed.querySelector('svg path[d="M6 6h.01"]')).toBeNull();
    expect(fixed.querySelector('svg path[d="m9 12 2 2 4-4"]'), "done: the ready check").not.toBeNull();
    // Only the open one needs the person.
    const counted = (onItems.mock.calls.at(-1)?.[0] ?? []) as { id: string }[];
    expect(counted.map((i) => i.id)).toEqual(["s1", "s2"]);
    expect(screen.getByText(/^1 thing needs you\./)).toBeInTheDocument();
  });

  it("UX-08: uploads that didn't finish are one line with Retry all that opens to show each; a repeated item says how often", async () => {
    const failed = (id: string, body: string, over = {}) =>
      ({ id, kind: "upload_failed", title: "Upload didn't finish", body, session_id: `s-${id}`, read: false, at: NOW.toISOString(), group: "upload_failed", resolved: false, repeat_count: 1, ...over });
    const three = [failed("u1", "WhatsApp call", { repeat_count: 2 }), failed("u2", "Voice note"), failed("u3", "Zoom call", { read: true })];
    respond("activity_list", { data: [...three, ITEMS[0], { ...ITEMS[0], id: "n9", title: "Uploaded", kind: "upload_failed", group: "upload_failed", resolved: true, read: true }], unread_count: 3, retry_requested_at: null });
    respond("activity_groups", { data: [{ group: "upload_failed", count: 3, unread: 2, session_ids: ["s-u1", "s-u2", "s-u3"], at: NOW.toISOString() }] });
    respond("activity_group_list", { data: three });
    respond("activity_retry_all", { retry_requested_at: NOW.toISOString(), count: 3, here: 2 });
    // This computer's own line goes: the server's line counts every device's.
    const { say } = await show(makeStatus({ failedUploads: 2 }));
    const needs = within(screen.getByRole("list", { name: "Needs action" }));
    expect(screen.queryByText("Uploads that need you")).toBeNull();
    const line = row("3 recordings didn't finish uploading");
    // Its items are inside it, not on their own; the one that reached the server since is done, in the day's list.
    expect(needs.queryByText(/WhatsApp call/)).toBeNull();
    expect(screen.queryAllByText("Upload didn't finish")).toHaveLength(0);
    expect(row("Uploaded")).toBeInTheDocument();
    expect(needs.getByText("Transcription failed")).toBeInTheDocument();
    // Opens to show each one, the repeated one saying how often.
    const opener = within(line).getByRole("button", { name: "3 recordings didn't finish uploading" });
    expect(opener).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(opener);
    await act(flushAsync);
    expect(opener).toHaveAttribute("aria-expanded", "true");
    expect(callsOf("activity_group_list")).toEqual([{ group: "upload_failed", limit: 50 }]);
    const group = within(screen.getByTestId("upload-group"));
    expect(group.getAllByText("Upload didn't finish")).toHaveLength(3);
    expect(group.getByText("WhatsApp call · 2 times")).toBeInTheDocument();
    expect(group.getByText("Voice note")).toBeInTheDocument();
    // Retry all: here and on the other devices, said so; the list is read again.
    const before = callsOf("activity_list").length;
    fireEvent.click(within(line).getByRole("button", { name: "Retry all" }));
    await act(flushAsync);
    expect(callsOf("activity_retry_all")).toEqual([{ group: "upload_failed" }]);
    expect(say).toHaveBeenCalledWith("Trying again. Your other devices try theirs too.");
    expect(callsOf("activity_list").length).toBeGreaterThan(before);
    fireEvent.click(opener);
    expect(screen.queryByTestId("upload-group")).toBeNull();
  });

  it("UX-08: one upload that didn't finish says Retry; offline Retry all says only this computer tried; with nothing to retry, the error", async () => {
    respond("activity_groups", { data: [{ group: "upload_failed", count: 1, unread: 1, session_ids: ["s-u1"], at: null }] });
    respond("activity_retry_all", { retry_requested_at: null, count: 1, here: 1 });
    const { say } = await show();
    const line = row("1 recording didn't finish uploading");
    fireEvent.click(within(line).getByRole("button", { name: "Retry" }));
    await act(flushAsync);
    expect(say).toHaveBeenCalledWith("Trying again on this computer. You're offline, so your other devices weren't asked.");
    // Nothing here and the server can't be reached: the error, in red.
    fail("activity_retry_all", "The server is unreachable.");
    fireEvent.click(within(row("1 recording didn't finish uploading")).getByRole("button", { name: "Retry" }));
    await act(flushAsync);
    expect(say).toHaveBeenLastCalledWith("The server is unreachable.", true);
  });

  it("retryAllWords: here and elsewhere, only elsewhere, or only here while offline", () => {
    expect(retryAllWords({ retry_requested_at: "2026-11-25T09:00:00Z", here: 2 })).toBe("Trying again. Your other devices try theirs too.");
    expect(retryAllWords({ retry_requested_at: "2026-11-25T09:00:00Z", here: 0 })).toBe("Asked your other devices to try again.");
    expect(retryAllWords({ retry_requested_at: null, here: 1 })).toBe("Trying again on this computer. You're offline, so your other devices weren't asked.");
  });

  it("the page's line says the rail's number when it has it (the server's, UX-08)", async () => {
    const props = { onOpenRecording: vi.fn(), onReview: vi.fn(), onFailed: vi.fn() };
    render(<div className="el-root"><ActivityV2 status={makeStatus()} needsYou={4} {...props} /></div>);
    await act(flushAsync);
    expect(screen.getByText(/^4 things need you\./)).toBeInTheDocument();
  });

  it("loading, a load error with Retry, and All caught up", async () => {
    fail("activity_list", "The server is unreachable.");
    render(<ActivityV2 status={makeStatus()} onOpenRecording={vi.fn()} onReview={vi.fn()} onFailed={vi.fn()} />);
    expect(screen.getByRole("status", { name: "" })).toHaveAttribute("aria-busy", "true");
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("Couldn't load the activity. The server is unreachable.");
    respond("activity_list", { data: [], unread_count: 0 });
    fireEvent.click(screen.getByRole("button", { name: "Retry" }));
    await act(flushAsync);
    expect(screen.getByText("All caught up")).toBeInTheDocument();
    expect(screen.getByText("Nothing needs you. Finished transcripts, problems, devices and shares show up here.")).toBeInTheDocument();
  });

  it("follows activity:updated from the core", async () => {
    await show();
    respond("activity_list", { data: [ITEMS[2]], unread_count: 0 });
    await act(() => emitEvent("activity:updated", null));
    await act(flushAsync);
    expect(screen.queryByText("Transcript ready")).toBeNull();
  });

  it("the sidebar's Activity shows how many things need you", () => {
    render(<Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} activity={3}><p /></Shell>);
    expect(screen.getByRole("button", { name: /^Activity/ })).toHaveTextContent("3");
  });
});
