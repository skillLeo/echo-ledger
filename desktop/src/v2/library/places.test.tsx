// The Library's places in the new design's sidebar, as classic's (docs/v2/audit/parity.csv DT-NAV-003, DT-LIB-115,
// DT-LIB-116, DT-LIB-117): Waiting, Pinned and Failed uploads with their counts, then the smart folders that hold
// something (the Inbox always: it takes dropped recordings); a place opens the Library on it.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import MainApp from "../../views/main/MainApp";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { Shell } from "../shell/Shell";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS: LibraryItem[] = [
  makeItem("wa", { title: "Bilal on WhatsApp", source_app: "whatsapp", started_at: "2026-11-17T06:00:00Z", client: { id: "p1", name: "Qureshi Textiles" } as LibraryItem["client"] }),
  makeItem("zoom", { title: "Weekly Zoom", source_app: "zoom", started_at: "2026-11-17T05:00:00Z" }),
  makeItem("file", { title: "Imported memo", source_app: "voice_note", started_at: "2026-11-17T04:00:00Z" }),
];
const rows = () => [...screen.getByRole("list", { name: "Recordings" }).querySelectorAll<HTMLButtonElement>("button[data-row-id]")].map((b) => b.dataset.rowId);

describe("the Library's places", () => {
  beforeEach(() => resetTauriMock());

  it("DT-NAV-003: the sidebar lists the places with their counts; the open one is marked; the Inbox takes drops", () => {
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

  it("DT-LIB-116 and DT-LIB-117: a folder shows only its recordings (the Inbox and an app here, Imported from the server); its chip's × shows all", async () => {
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("bin_list", { data: [] });
    respondWith("library_folder", ({ folder }) => (folder === "imported" ? [ITEMS[2]] : ITEMS.filter((i) => (folder === "inbox" ? !i.client : i.source_app === "zoom"))));
    const onFolder = vi.fn();
    const { rerender } = render(<LibraryV2 folder="inbox" onFolder={onFolder} />);
    await act(flushAsync);
    expect(rows()).toEqual(["zoom", "file"]);
    expect(screen.getByText("2 recordings in Inbox")).toBeInTheDocument();
    rerender(<LibraryV2 folder="zoom" onFolder={onFolder} />);
    await act(flushAsync);
    expect(rows()).toEqual(["zoom"]);
    rerender(<LibraryV2 folder="imported" onFolder={onFolder} />);
    await act(flushAsync);
    expect(callsOf("library_folder").at(-1)).toEqual({ folder: "imported" });
    expect(rows()).toEqual(["file"]);
    fireEvent.click(screen.getByRole("button", { name: "Show every recording, not only Imported" }));
    expect(onFolder).toHaveBeenCalledWith(null);
  });

  it("DT-LIB-115: in the app, the sidebar's places come from the server's counts and open the new Library there", async () => {
    respond("app_status", makeStatus());
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [{ id: "p1", name: "Qureshi Textiles" }] });
    respond("mic_users", []);
    respond("session_detail", {});
    respond("bin_list", { data: [] });
    respond("library_views", { views: { waiting: 0, pinned: 1, failed: 2 }, labels: [], folders: { inbox: 2, whatsapp: 1, zoom: 1, voice_notes: 1, imported: 0, shared: 0 } });
    respondWith("library_folder", ({ folder }) => ITEMS.filter((i) => (folder === "zoom" ? i.source_app === "zoom" : !i.client)));
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true }, ui_classic: false });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    const list = await screen.findByRole("list", { name: "Library places" });
    expect(within(list).getAllByRole("button").map((b) => b.getAttribute("aria-label"))).toEqual(["Pinned, 1", "Failed uploads, 2", "Inbox, 2", "WhatsApp, 1", "Zoom, 1", "Voice notes, 1"]);
    fireEvent.click(within(list).getByRole("button", { name: "Zoom, 1" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(within(list).getByRole("button", { name: "Zoom, 1" })).toHaveAttribute("aria-current", "page");
    expect(rows()).toEqual(["zoom"]);
    fireEvent.click(within(list).getByRole("button", { name: "Pinned, 1" }));
    await act(flushAsync);
    await act(flushAsync);
    // Pinned is one of the Library's own views: Views ▾ says so.
    expect(within(screen.getByRole("group", { name: "Filter recordings" })).getByRole("button", { name: /^Pinned/ })).toHaveAttribute("aria-haspopup", "menu");
  });
});
