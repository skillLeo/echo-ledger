// My views when the account can't be read at the start (offline): the last account's views on this computer still
// show and a view can be saved; once a library sync works the account is read again and the view is sent. Signing out
// forgets whose views they were (review LF-desktop recheck item 2).
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem, ServerView } from "../../lib/ipc";
import MainApp from "../../views/main/MainApp";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { resetSyncClock } from "./syncClock";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS: LibraryItem[] = [
  makeItem("wa", { title: "Bilal on WhatsApp", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: "2026-11-17T06:00:00Z" }),
  makeItem("zoom", { title: "Weekly Zoom", source_app: "zoom", source_app_label: "Zoom", started_at: "2026-11-17T05:00:00Z" }),
];
const ACCOUNT = "https://ledger.example.test|me@example.test|1";
const CLIENTS: ServerView = { id: "v1", name: "Clients", filters: { source: { op: "is", values: ["zoom"] } }, search: null, sort: "newest", group: "date", density: "comfortable", position: 0 };
const settle = async () => {
  await act(flushAsync);
  await act(flushAsync);
};
const myViews = () => within(screen.getByRole("list", { name: "My views" }));

function app() {
  respond("app_status", makeStatus());
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("get_settings", makeSettings());
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("bin_list", { data: [] });
  respond("lists_get", []);
  respond("library_folder", []);
  respond("library_views", { views: { waiting: 0, pinned: 0, failed: 0 }, labels: [], folders: {} });
  respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true }, ui_classic: false });
  render(<MainApp />);
}

describe("My views while the account can't be read", () => {
  beforeEach(() => {
    resetTauriMock();
    resetSyncClock();
    window.localStorage.clear();
    Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
  });

  it("an offline start shows the last account's views and saves a new one; once a sync works the account is read and the view sent", async () => {
    window.localStorage.setItem("echoledger-my-views-account", JSON.stringify({ server: "https://ledger.example.test", account: ACCOUNT }));
    window.localStorage.setItem(`echoledger-my-views:${ACCOUNT}`, JSON.stringify({ views: [{ id: "v1", name: "Clients", spec: { filters: CLIENTS.filters, search: null, sort: "newest", group: "date" } }], version: 3, reorder: false }));
    fail("account_get", "The server didn't answer.");
    for (const command of ["views_get", "library_views_version", "view_create"]) fail(command, "The server didn't answer.");
    app();
    await settle();
    await screen.findByRole("list", { name: "Library views" });
    await settle();
    expect(myViews().getByRole("button", { name: "Clients" })).toBeInTheDocument();
    // Saved offline: it shows at once.
    fireEvent.click(screen.getAllByRole("button", { name: "Show only WhatsApp" })[0]);
    fireEvent.click(within(screen.getByRole("group", { name: "Filters on" })).getByRole("button", { name: "Save as view" }));
    fireEvent.change(screen.getByRole("textbox", { name: "View name" }), { target: { value: "WhatsApp calls" } });
    fireEvent.click(screen.getByRole("button", { name: "Save view" }));
    await settle();
    expect(myViews().getByRole("button", { name: "WhatsApp calls" })).toHaveAttribute("aria-current", "page");
    // The network is back: a library sync works, the account is read, and the view goes to the server once.
    const server: ServerView[] = [CLIENTS];
    respond("account_get", { name: "Me", email: "me@example.test", workspace: { id: 1, name: "Personal", role: "owner" } });
    respondWith("views_get", () => ({ data: server, views_version: 4 + server.length }));
    respondWith("library_views_version", () => 3);
    respondWith("view_create", ({ view }) => {
      const made = { ...(view as ServerView), id: "v2", position: 1 };
      server.push(made);
      return { data: made, views_version: 5 };
    });
    await emitEvent("library:updated", 0);
    await settle();
    expect(callsOf("account_get").length).toBeGreaterThanOrEqual(2);
    expect(callsOf("view_create").at(-1)).toMatchObject({ view: { name: "WhatsApp calls", filters: { source: { op: "is", values: ["whatsapp"] } } } });
    expect(server.map((v) => v.name)).toEqual(["Clients", "WhatsApp calls"]);
    expect(myViews().getAllByRole("button").filter((b) => !b.className.includes("el-nav-row__more")).map((b) => b.textContent)).toEqual(["Clients", "WhatsApp calls", "New view"]);
    expect(JSON.parse(window.localStorage.getItem("echoledger-my-views-account")!)).toEqual({ server: "https://ledger.example.test", account: ACCOUNT });
  });

  it("signed out: whose views they were is forgotten, and none show", async () => {
    window.localStorage.setItem("echoledger-my-views-account", JSON.stringify({ server: "https://ledger.example.test", account: ACCOUNT }));
    respond("app_status", makeStatus({ paired: false }));
    render(<MainApp />);
    await settle();
    expect(window.localStorage.getItem("echoledger-my-views-account")).toBeNull();
  });
});
