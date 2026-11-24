// Every project as a tree in the new design's sidebar, as classic's column (docs/v2/audit/parity.csv DT-LIB-123,
// DT-PRJ-004, DT-NAV-016): pinned first, subfolders under their project, each a target for dropped recordings, and a
// project's subfolders folded or open as the person left them on this computer.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { LibraryV2 } from "../library/LibraryV2";
import { FOLDS_KEY, Shell, type SidebarProject } from "./Shell";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PROJECTS: SidebarProject[] = [
  { id: "m", name: "Mehran Foods" },
  { id: "q", name: "Qureshi Textiles", pinned: true },
  { id: "q1", name: "March order", parent_id: "q" },
  { id: "q2", name: "Audit", parent_id: "q" },
  { id: "q2a", name: "2026", parent_id: "q2" },
];
const show = (open = vi.fn(), current: string | null = null, children: React.ReactNode = <p />) => render(
  <Shell tab={current ? "projects" : "library"} onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} projectTree={PROJECTS} projectId={current} onProject={open}>{children}</Shell>,
);
const tree = () => screen.getByRole("list", { name: "Projects" });
const names = () => [...tree().querySelectorAll<HTMLButtonElement>(".el-nav-tree__name")].map((b) => b.textContent);

describe("the projects in the sidebar", () => {
  beforeEach(() => {
    resetTauriMock();
    localStorage.clear();
  });

  it("DT-LIB-123: pinned first, then by name, subfolders under their project; a project opens and is marked", () => {
    const open = vi.fn();
    show(open, "q1");
    expect(names()).toEqual(["Qureshi Textiles", "Audit", "2026", "March order", "Mehran Foods"]);
    expect(within(tree()).getByRole("button", { name: "March order" })).toHaveAttribute("aria-current", "page");
    fireEvent.click(within(tree()).getByRole("button", { name: "Mehran Foods" }));
    expect(open).toHaveBeenCalledWith("m");
    // Every project takes dropped recordings.
    expect(within(tree()).getByRole("button", { name: "2026" }).closest("[data-drop-project]")).toHaveAttribute("data-drop-project", "q2a");
    // The pinned list alone is not shown as well.
    expect(screen.queryByRole("list", { name: "Pinned projects" })).toBeNull();
  });

  it("DT-NAV-016: a project's folders fold and stay folded after a restart", () => {
    show();
    fireEvent.click(within(tree()).getByRole("button", { name: "Fold Qureshi Textiles's folders" }));
    expect(names()).toEqual(["Qureshi Textiles", "Mehran Foods"]);
    expect(within(tree()).getByRole("button", { name: "Show Qureshi Textiles's folders" })).toHaveAttribute("aria-expanded", "false");
    expect(JSON.parse(localStorage.getItem(FOLDS_KEY)!)).toEqual(["q"]);
    cleanup();
    show();
    expect(names()).toEqual(["Qureshi Textiles", "Mehran Foods"]);
    fireEvent.click(within(tree()).getByRole("button", { name: "Show Qureshi Textiles's folders" }));
    expect(names()).toContain("Audit");
    expect(JSON.parse(localStorage.getItem(FOLDS_KEY)!)).toEqual([]);
  });

  it("DT-PRJ-004: a Library row dropped on any project in the tree moves there", async () => {
    const items: LibraryItem[] = [makeItem("a", { title: "Kick-off", started_at: "2026-11-17T06:00:00Z" })];
    respond("library_list", items);
    respond("library_refresh", items);
    respond("bin_list", { data: [] });
    respond("list_clients", { data: [{ id: "q2a", name: "2026" }] });
    respondWith("sessions_bulk", (args) => (args as { ids: string[] }).ids.length);
    show(vi.fn(), null, <LibraryV2 />);
    await act(flushAsync);
    const row = document.querySelector<HTMLButtonElement>('button[data-row-id="a"]')!;
    const target = within(tree()).getByRole("button", { name: "2026" });
    document.elementFromPoint = vi.fn(() => target) as typeof document.elementFromPoint;
    fireEvent.pointerDown(row, { button: 0, clientX: 10, clientY: 10, buttons: 1 });
    fireEvent.pointerMove(window, { clientX: 40, clientY: 40, buttons: 1 });
    expect(target.closest("[data-drop-project]")).toHaveClass("drop-over");
    fireEvent.pointerUp(window, { clientX: 40, clientY: 40 });
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["a"], action: "move", clientId: "q2a", label: null }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Moved to 2026");
  });
});
