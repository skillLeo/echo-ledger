// Shared and Activity in the whole app (MainApp with ui_v2.shell): a link's item opens once, the Request files sheet of
// the Record menu is the new one and leads to Shared › Request links, Restore's message goes to the Library, and
// Activity's own count reaches the sidebar.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeNavigate, makeSettings, makeSkippedItem, makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

beforeEach(() => {
  resetTauriMock();
  resetEntitlementsForTests(null);
  window.localStorage.clear();
  respond("app_status", makeStatus());
  respond("library_list", [makeItem("a", { title: "Bilal Qureshi · Qureshi Textiles" })]);
  respond("library_refresh", [makeItem("a", { title: "Bilal Qureshi · Qureshi Textiles" })]);
  respond("get_settings", makeSettings());
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("ui_flags", { ui_flags: { "ui_v2.shell": true }, ui_classic: false });
  respond("activity_list", { data: [], unread_count: 0 });
  respond("pending_list", []);
  respond("upload_list", []);
  respond("skipped_list", []);
  respond("shared_with_me", { data: [{ id: "01JDSHARE1", kind: "session", from: { name: "Imran" }, note: null, title: "Call with Omar", sent_at: null, saved_at: null, client: null,
    session: makeItem("other-1", { title: "Call with Omar" }) }] });
  respond("shares_of", { data: [] });
  respond("request_links", { data: [{ id: "r1", title: "Send me your voice notes", client_id: null, expires_at: null, max_files: 20, max_mb: 100, received: 2, revoked_at: null }] });
});
afterEach(async () => {
  await flushAsync();
});

async function app() {
  render(<MainApp />);
  await act(flushAsync);
  await act(flushAsync);
  return screen.findByRole("navigation", { name: "Main" });
}
const settle = async () => {
  for (let i = 0; i < 3; i++) await act(flushAsync);
};

describe("Shared in the app", () => {
  it("a link's item opens once: Shared → Activity → Shared shows the list, not the recording again", async () => {
    const nav = await app();
    respond("links_resolve", { kind: "shared", shared_id: "01JDSHARE1" });
    await act(async () => emitEvent("main:navigate", makeNavigate({ link: "https://ledger.example.test/s/AbC123" })));
    await settle();
    expect(document.querySelector("[data-v2=shared-reader]")).not.toBeNull();
    fireEvent.click(within(nav).getByRole("button", { name: /^Activity/ }));
    await settle();
    expect(document.querySelector("[data-v2=activity]")).not.toBeNull();
    fireEvent.click(within(nav).getByRole("button", { name: "Shared with me" }));
    await settle();
    expect(document.querySelector("[data-v2=shared]")).not.toBeNull();
    expect(document.querySelector("[data-v2=shared-reader]")).toBeNull();
    expect(screen.getByRole("list", { name: "Shared with me" })).toHaveTextContent("Call with Omar");
  });

  it("Record ▾ › Request link… opens the new Request files sheet; See your request links opens Shared › Request links", async () => {
    const nav = await app();
    fireEvent.click(within(nav).getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Request link…" }));
    await settle();
    const sheet = screen.getByRole("dialog", { name: "Request files" });
    expect(sheet.closest(".el-root")).not.toBeNull();
    expect(document.querySelector(".share-sheet")).toBeNull();
    fireEvent.click(within(sheet).getByRole("button", { name: "See your request links" }));
    await settle();
    expect(screen.queryByRole("dialog", { name: "Request files" })).toBeNull();
    expect(screen.getByRole("tab", { name: "Request links · 1" })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByRole("list", { name: "Request links" })).toHaveTextContent("2 files received");
  });
});

describe("Activity in the app", () => {
  it("Restore's message opens the Library, as classic's does", async () => {
    respond("skipped_list", [makeSkippedItem("k1", { callerName: "Omar Khan" })]);
    respond("skipped_restore", undefined);
    const nav = await app();
    fireEvent.click(within(nav).getByRole("button", { name: /^Activity/ }));
    await settle();
    const line = screen.getByTestId("skipped-calls");
    fireEvent.click(within(line).getByRole("button", { name: "Show" }));
    fireEvent.click(within(line).getByRole("button", { name: /^Restore Call with Omar Khan/ }));
    await settle();
    expect(callsOf("skipped_restore")).toEqual([{ id: "k1" }]);
    fireEvent.click(screen.getByRole("button", { name: "Restored. It uploads now and shows in the Library." }));
    await settle();
    expect(within(nav).getByRole("button", { name: "Library" })).toHaveAttribute("aria-current", "page");
  });

  it("the sidebar's Activity shows the page's real count of what needs you", async () => {
    respond("activity_list", { data: [
      { id: "n1", kind: "transcript_failed", title: "Transcription failed", body: "Call with Omar", session_id: "s3", read: false, at: new Date().toISOString() },
      { id: "n2", kind: "transcript_ready", title: "Transcript ready", body: "Acme", session_id: "s1", read: false, at: new Date().toISOString() },
    ], unread_count: 2 });
    respond("app_status", makeStatus({ pendingDecisions: 2 }));
    const nav = await app();
    fireEvent.click(within(nav).getByRole("button", { name: /^Activity/ }));
    await settle();
    expect(document.querySelector("[data-v2=activity]")).not.toBeNull();
    // Two calls wait and one problem is unread: 3 things need you (a ready transcript is not one).
    expect(within(nav).getByRole("button", { name: /^Activity/ })).toHaveTextContent("3");
  });
});
