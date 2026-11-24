// UX-22's Mark as unread on the desktop (contract 2.0.0-t, DELETE /api/sessions/{id}/opened through the core's
// session_unopen): the menu says Mark as unread while a recording is read and Mark as read once every one picked is
// unread, as the web does; the row's dot and the Unread count follow at once, before any sync; the open recording
// answers ⇧⌘U / Ctrl+Shift+U too; and a refusal is said, with the dot put back as it was.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 24, 15, 0);
const done = (preview: string, id: string) => ({ final_status: "completed", final_transcript_id: id, live_available: false, preview });
const ITEMS: LibraryItem[] = [
  makeItem("bilal", { title: "Bilal Qureshi", source_app: "whatsapp", started_at: new Date(2026, 10, 24, 11).toISOString(), opened: true, transcript: done("Payment terms", "t1") }),
  makeItem("usman", { title: "Usman Chaudhry", source_app: "phone_call", started_at: new Date(2026, 10, 24, 9).toISOString(), opened: false, transcript: done("Steel", "t2") }),
];
const list = () => screen.getByRole("list", { name: "Recordings" });
const row = (title: string) => within(list()).getByRole("button", { name: title });
const dotOf = (title: string) => row(title).closest("li")!.querySelector(".el-rec__unread");
const menuWords = () => [...screen.getByRole("menu", { name: "Recording actions" }).querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent);
const unreadCount = (onItems: ReturnType<typeof vi.fn>) => {
  const [items, read] = onItems.mock.calls.at(-1) as [LibraryItem[], ReadonlySet<string>];
  return items.filter((i) => i.opened === false && !read.has(i.id)).length;
};
const setPlatform = (platform: string) => Object.defineProperty(navigator, "platform", { value: platform, configurable: true });

describe("Mark as unread (UX-22, contract 2.0.0-t)", () => {
  beforeEach(() => {
    resetTauriMock();
    window.localStorage.clear();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    setPlatform("Win32");
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("bin_list", { data: [] });
    respond("list_clients", { data: [] });
    respond("session_unopen", null);
    respond("session_opened", null);
    respondWith("sessions_bulk", (args) => (args as { ids: string[] }).ids.length);
  });
  afterEach(() => {
    vi.useRealTimers();
    setPlatform("");
  });

  it("a read recording: Mark as unread, and its dot and the Unread count come back at once, before any sync", async () => {
    const onItems = vi.fn();
    render(<LibraryV2 onItems={onItems} />);
    await act(flushAsync);
    expect(dotOf("Bilal Qureshi")).toBeNull();
    expect(dotOf("Usman Chaudhry")).not.toBeNull();
    expect(dotOf("Usman Chaudhry")).toHaveAccessibleName("Not opened yet");
    expect(unreadCount(onItems)).toBe(1);

    fireEvent.contextMenu(row("Bilal Qureshi"), { clientX: 40, clientY: 60 });
    expect(menuWords()).toContain("Mark as unread");
    // The key it answers is shown, as every key the Library answers.
    expect(screen.getByRole("menuitem", { name: /Mark as unread/ })).toHaveTextContent("Ctrl+Shift+U");
    fireEvent.click(screen.getByRole("menuitem", { name: /Mark as unread/ }));
    expect(dotOf("Bilal Qureshi")).not.toBeNull();
    expect(unreadCount(onItems)).toBe(2);
    await act(flushAsync);
    expect(callsOf("session_unopen")).toEqual([{ id: "bilal" }]);
    expect(callsOf("library_refresh").length).toBeLessThanOrEqual(1);
  });

  it("every one picked unread: Mark as read; the dots go", async () => {
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.contextMenu(row("Usman Chaudhry"), { clientX: 40, clientY: 60 });
    expect(menuWords()).toContain("Mark as read");
    expect(menuWords()).not.toContain("Mark as unread");
    fireEvent.click(screen.getByRole("menuitem", { name: /Mark as read/ }));
    expect(dotOf("Usman Chaudhry")).toBeNull();
    await act(flushAsync);
    expect(callsOf("session_opened")).toEqual([{ id: "usman" }]);
    expect(callsOf("session_unopen")).toEqual([]);
  });

  it("Ctrl+Shift+U on the focused row, and on the recording open now", async () => {
    respond("session_detail", {});
    render(<LibraryV2 />);
    await act(flushAsync);
    row("Bilal Qureshi").focus();
    fireEvent.keyDown(row("Bilal Qureshi"), { key: "U", ctrlKey: true, shiftKey: true });
    await act(flushAsync);
    expect(callsOf("session_unopen")).toEqual([{ id: "bilal" }]);
    expect(dotOf("Bilal Qureshi")).not.toBeNull();

    // Open Usman's (it reads it), then mark the open one unread.
    fireEvent.click(row("Usman Chaudhry"));
    await act(flushAsync);
    expect(callsOf("session_opened")).toEqual([{ id: "usman" }]);
    fireEvent.keyDown(document.body, { key: "U", ctrlKey: true, shiftKey: true });
    await act(flushAsync);
    expect(callsOf("session_unopen")).toEqual([{ id: "bilal" }, { id: "usman" }]);
  });

  it("a refusal (an older server) is said, and the dot is put back as it was", async () => {
    fail("session_unopen", "Mark as unread needs a newer EchoLedger server. Ask the person who runs it to update.");
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.contextMenu(row("Bilal Qureshi"), { clientX: 40, clientY: 60 });
    fireEvent.click(screen.getByRole("menuitem", { name: /Mark as unread/ }));
    await act(flushAsync);
    expect(dotOf("Bilal Qureshi")).toBeNull();
    expect(screen.getByText(/Mark as unread needs a newer EchoLedger server/)).toBeInTheDocument();
  });

  it("a sync that lands while the server is asked, with the copy from before, never takes the dot away again", async () => {
    let answer: () => void = () => undefined;
    respondWith("session_unopen", () => new Promise<null>((resolve) => { answer = () => resolve(null); }));
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.contextMenu(row("Bilal Qureshi"), { clientX: 40, clientY: 60 });
    fireEvent.click(screen.getByRole("menuitem", { name: /Mark as unread/ }));
    expect(dotOf("Bilal Qureshi")).not.toBeNull();
    // The saved Library read again before the mark is written: Bilal still opened in it.
    await emitEvent("library:updated", 0);
    await act(flushAsync);
    expect(dotOf("Bilal Qureshi")).toBeNull();
    await act(async () => { answer(); await flushAsync(); });
    expect(dotOf("Bilal Qureshi")).not.toBeNull();
    expect(screen.getByText("Marked 1 recording as unread")).toBeInTheDocument();
  });

  it("a failure partway: what the server took stays, the rest is put back, and it says how many", async () => {
    respond("library_list", ITEMS.map((i) => ({ ...i, opened: true })));
    respond("library_refresh", ITEMS.map((i) => ({ ...i, opened: true })));
    respondWith("session_unopen", (args) => ((args as { id: string }).id === "usman" ? Promise.reject("The server did not answer.") : Promise.resolve(null)));
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.click(row("Bilal Qureshi"), { ctrlKey: true });
    fireEvent.click(row("Usman Chaudhry"), { ctrlKey: true });
    fireEvent.contextMenu(row("Usman Chaudhry"), { clientX: 40, clientY: 60 });
    fireEvent.click(screen.getByRole("menuitem", { name: /Mark as unread/ }));
    await act(flushAsync);
    expect(callsOf("session_unopen").map((c) => c.id)).toEqual(["bilal", "usman"]);
    expect(dotOf("Bilal Qureshi")).not.toBeNull();
    expect(dotOf("Usman Chaudhry")).toBeNull();
    expect(screen.getByText(/1 of 2 marked as unread/)).toBeInTheDocument();
  });
});
