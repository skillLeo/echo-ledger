// The Library's places since the redesign (PLAN_LIBRARY_FILTERS §6 and its lead decisions; docs/v2/audit/parity.csv
// DT-NAV-003, DT-LIB-115, DT-LIB-116, DT-LIB-117): the sidebar's LIBRARY views with their counts (Waiting and Failed
// uploads are counted in Needs you; Pinned is the Pinned section at the top of every list and Marked › Pinned), and
// today's smart folders as Source and Project choices (the Inbox is Project › No project, an app is its Source, Imported
// the server's word). Shell's places list stays for the new shell without the new Library (ui_v2.library off).
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import MainApp from "../../views/main/MainApp";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { Shell } from "../shell/Shell";
import { resetSyncClock } from "./syncClock";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const none = { final_status: null, final_transcript_id: null, live_available: false, preview: null };
const ITEMS: LibraryItem[] = [
  makeItem("wa", { title: "Bilal on WhatsApp", source_app: "whatsapp", started_at: "2026-11-17T06:00:00Z", client: { id: "p1", name: "Qureshi Textiles" } as LibraryItem["client"], pinned: true }),
  makeItem("zoom", { title: "Weekly Zoom", source_app: "zoom", started_at: "2026-11-17T05:00:00Z", opened: false }),
  makeItem("file", { title: "Imported memo", source_app: "voice_note", started_at: "2026-11-17T04:00:00Z", status: "audio_only", transcript: none }),
  makeItem("bad", { title: "Teams call", source_app: "microsoft_teams", started_at: "2026-11-16T04:00:00Z", status: "failed", transcript: { ...none, final_status: "failed" } }),
];
const rows = () => [...screen.getByRole("list", { name: "Recordings" }).querySelectorAll<HTMLButtonElement>("button[data-row-id]")].map((b) => b.dataset.rowId);
const pills = () => within(screen.getByRole("group", { name: "Filters on" }));

function inTheApp(items: LibraryItem[] = ITEMS, local = { failedUploads: 1, pendingDecisions: 2 }) {
  respond("app_status", makeStatus(local));
  respond("library_list", items);
  respond("library_refresh", items);
  respond("get_settings", makeSettings());
  respond("list_clients", { data: [{ id: "p1", name: "Qureshi Textiles" }] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("bin_list", { data: [] });
  respond("lists_get", []);
  respond("library_views", { views: { waiting: 2, pinned: 1, failed: 1 }, labels: [], folders: { inbox: 3, whatsapp: 1, zoom: 1, voice_notes: 1, imported: 1, shared: 0 } });
  respondWith("library_folder", ({ folder }) => (folder === "imported" ? [ITEMS[2]] : []));
  respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true }, ui_classic: false });
  render(<MainApp />);
}
const settle = async () => {
  await act(flushAsync);
  await act(flushAsync);
};

describe("the Library's places", () => {
  beforeEach(() => {
    resetTauriMock();
    resetSyncClock();
    window.localStorage.clear();
  });

  it("DT-NAV-003 (the new shell without the new Library): the sidebar lists the places with their counts; the open one is marked; the Inbox takes drops", () => {
    const open = vi.fn();
    render(
      <Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }}
        places={{ places: [{ key: "waiting", label: "Waiting", count: 2 }, { key: "failed", label: "Failed uploads", count: 1 }, { key: "inbox", label: "Inbox", count: 5, drop: true },
          { key: "zoom", label: "Zoom", count: 3 }], current: "zoom", open }}><p /></Shell>,
    );
    const list = screen.getByRole("list", { name: "Library places" });
    expect(within(list).getAllByRole("button").map((b) => b.getAttribute("aria-label"))).toEqual(["Waiting, 2", "Failed uploads, 1", "Inbox, 5", "Zoom, 3"]);
    expect(within(list).getByRole("button", { name: "Zoom, 3" })).toHaveAttribute("aria-current", "page");
    expect(within(list).getByRole("button", { name: "Inbox, 5" }).closest("li")).toHaveAttribute("data-drop-project", "");
    // With the Inbox listed, no second Inbox appears while dragging.
    expect(document.querySelector(".el-nav-drop")).toBeNull();
    fireEvent.click(within(list).getByRole("button", { name: "Waiting, 2" }));
    expect(open).toHaveBeenCalledWith("waiting");
  });

  it("DT-LIB-116 and DT-LIB-117: a smart folder asked for (the palette, a notice) opens All with its Source or Project chosen; its pill's × shows all", async () => {
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("bin_list", { data: [] });
    respondWith("library_folder", ({ folder }) => (folder === "imported" ? [ITEMS[2]] : []));
    const onFolder = vi.fn();
    const { rerender } = render(<LibraryV2 folder="inbox" onFolder={onFolder} />);
    await settle();
    // The Inbox: recordings in no project.
    expect(rows()).toEqual(["zoom", "file", "bad"]);
    expect(pills().getByRole("button", { name: "Remove Project: No project" })).toBeInTheDocument();
    // Taken: the same folder can be asked for again.
    expect(onFolder).toHaveBeenCalledWith(null);
    rerender(<LibraryV2 folder="zoom" onFolder={onFolder} />);
    await settle();
    expect(rows()).toEqual(["zoom"]);
    expect(pills().getByRole("button", { name: "Remove Source: Zoom" })).toBeInTheDocument();
    // Imported is the server's word (asked of a server that sends no facets yet).
    rerender(<LibraryV2 folder="imported" onFolder={onFolder} />);
    await settle();
    expect(callsOf("library_folder")).toContainEqual({ folder: "imported" });
    expect(rows()).toEqual(["file"]);
    fireEvent.click(pills().getByRole("button", { name: "Remove Source: Imported" }));
    await settle();
    expect(rows()).toEqual(["wa", "zoom", "file", "bad"]);
    expect(screen.queryByRole("group", { name: "Filters on" })).toBeNull();
  });

  it("the folders' recordings are each Source's and Project's options, with their counts, in the filter panel", async () => {
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("bin_list", { data: [] });
    respondWith("library_folder", ({ folder }) => (folder === "imported" ? [ITEMS[2]] : []));
    respond("list_clients", { data: [{ id: "p1", name: "Qureshi Textiles" }, { id: "p2", name: "Empty project" }] });
    render(<LibraryV2 />);
    await settle();
    fireEvent.click(screen.getByRole("button", { name: /^Filters/ }));
    const options = (section: string) => within(within(screen.getByTestId("filter-panel")).getByRole("region", { name: section }))
      .getAllByRole("checkbox").map((c) => c.closest("label")!.textContent);
    expect(options("Source")).toEqual(["Imported1", "Teams1", "WhatsApp1", "Zoom1"]);
    // Every project is offered, an empty one too (dimmed, still a choice); No project is the Inbox.
    expect(options("Project")).toEqual(["No project3", "Qureshi Textiles1", "Empty project0"]);
  });

  it("DT-LIB-115: in the app, the sidebar's LIBRARY views have their counts (Needs you with the calls and uploads waiting here) and open the Library there", async () => {
    inTheApp();
    await settle();
    const list = await screen.findByRole("list", { name: "Library views" });
    // Needs you: the failed one, the two calls waiting and the upload that failed here. Then Shared ▸, which folds.
    expect(within(list).getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent)).toEqual(
      ["All, 4", "Needs you, 4", "Unread, 1", "Important, 0", "Not transcribed, 1", "Audio only, 1", "Shared"]);
    // No Library places list any more: these are the LIBRARY section's.
    expect(screen.queryByRole("list", { name: "Library places" })).toBeNull();
    expect(within(list).getByRole("button", { name: "All, 4" })).toHaveAttribute("aria-current", "page");
    // Pinned comes first in its own section at the top of All.
    expect(screen.getAllByRole("button", { expanded: true }).find((b) => b.classList.contains("el-list-section__button"))).toHaveAccessibleName("Pinned · 1");
    fireEvent.click(within(list).getByRole("button", { name: "Audio only, 1" }));
    await settle();
    expect(within(list).getByRole("button", { name: "Audio only, 1" })).toHaveAttribute("aria-current", "page");
    expect(rows()).toEqual(["file"]);
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent(/^Audio only/);
  });

  it("in the app, Needs you counts and lists the server's waiting recordings (a call waiting for Keep or Discard); no other view has them", async () => {
    const call = makeItem("call", { title: "Phone call with Bilal", source_app: "phone_call", started_at: "2026-11-17T07:00:00Z", status: "recording", transcript: none });
    respond("pending_list", []);
    respond("upload_list", []);
    inTheApp([...ITEMS, call], { failedUploads: 0, pendingDecisions: 0 });
    await settle();
    const list = await screen.findByRole("list", { name: "Library views" });
    // The failed one and the waiting call; All counts what it lists (not the call).
    expect(within(list).getByRole("button", { name: /^Needs you, / })).toHaveAccessibleName("Needs you, 2");
    expect(within(list).getByRole("button", { name: /^All, / })).toHaveAccessibleName("All, 4");
    expect(rows()).not.toContain("call");
    fireEvent.click(within(list).getByRole("button", { name: "Needs you, 2" }));
    await settle();
    expect(rows()).toEqual(["call", "bad"]);
    expect(document.querySelector("button[data-row-id=call]")!.closest("li")).toHaveTextContent("Waiting for decision");
    // With a filter that the call doesn't have, it is still listed (as the server's Needs you keeps it).
    fireEvent.click(screen.getAllByRole("button", { name: "Show only Teams" })[0]);
    await settle();
    expect(rows()).toEqual(["call", "bad"]);
    fireEvent.change(screen.getByRole("searchbox", { name: "Search recordings" }), { target: { value: "nothing like it" } });
    await settle();
    expect(rows()).toEqual(["call"]);
  });

  it("in the app, All after a view shows every recording again", async () => {
    inTheApp();
    await settle();
    const list = await screen.findByRole("list", { name: "Library views" });
    fireEvent.click(within(list).getByRole("button", { name: "Not transcribed, 1" }));
    await settle();
    expect(rows()).toEqual(["file"]);
    fireEvent.click(within(list).getByRole("button", { name: "All, 4" }));
    await settle();
    expect(rows()).toEqual(["wa", "zoom", "file", "bad"]);
    expect(within(list).getByRole("button", { name: "Not transcribed, 1" })).not.toHaveAttribute("aria-current");
  });
});
