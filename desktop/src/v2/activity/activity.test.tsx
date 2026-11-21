// Activity in the new design (ActivityV2): the same page, rules and commands as today's Activity.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makePendingItem, makeSkippedItem, makeStatus } from "../../test/fixtures";
import { Shell } from "../shell/Shell";
import { ActivityV2 } from "./ActivityV2";

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

  it("Needs action: Keep or discard? › Review opens the first waiting recording", async () => {
    respond("pending_list", [makePendingItem("p1"), makePendingItem("p2")]);
    const { onReview } = await show();
    const needs = screen.getByRole("list", { name: "Needs action" });
    expect(within(needs).getByText("Keep or discard?")).toBeInTheDocument();
    fireEvent.click(within(row("Keep or discard?")).getByRole("button", { name: "Review" }));
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
