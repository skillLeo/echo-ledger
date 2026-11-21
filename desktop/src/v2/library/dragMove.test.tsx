// Moving recordings by dragging them, in the new design (docs/v2/audit/parity.csv DT-LIB-082, DT-LIB-083, DT-PRJ-014):
// a Library row (or every picked row) dropped on a pinned project or the Inbox in the sidebar moves there, as classic's;
// Escape or a drop elsewhere moves nothing, and the press that ended a drag never opens the row.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { Shell } from "../shell/Shell";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS: LibraryItem[] = [
  makeItem("a", { title: "Kick-off", started_at: "2026-11-17T06:00:00Z" }),
  makeItem("b", { title: "Follow-up", started_at: "2026-11-17T05:00:00Z" }),
  makeItem("c", { title: "Board call", started_at: "2026-11-17T04:00:00Z" }),
];

function app() {
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("bin_list", { data: [] });
  respond("list_clients", { data: [{ id: "p1", name: "Qureshi Textiles" }] });
  respondWith("sessions_bulk", (args) => (args as { ids: string[] }).ids.length);
  render(
    <Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} pinnedProjects={[{ id: "p1", name: "Qureshi Textiles" }]} onProject={() => undefined}>
      <LibraryV2 />
    </Shell>,
  );
}
const row = (title: string) => document.querySelector<HTMLButtonElement>(`button[data-row-id="${ITEMS.find((i) => i.title === title)!.id}"]`)!;
const project = () => within(screen.getByRole("list", { name: "Pinned projects" })).getByRole("button", { name: "Qureshi Textiles" }).closest("li")!;
const inbox = () => document.querySelector<HTMLElement>(".el-nav-drop")!;

/** Presses on `from`, moves past the threshold, and lets go over `to` (elementFromPoint answers `to`). */
async function drag(from: HTMLElement, to: HTMLElement | null, { escape = false, click = true } = {}) {
  document.elementFromPoint = vi.fn(() => to) as typeof document.elementFromPoint;
  fireEvent.pointerDown(from, { button: 0, clientX: 10, clientY: 10, buttons: 1 });
  fireEvent.pointerMove(window, { clientX: 40, clientY: 40, buttons: 1 });
  if (to) expect(to.closest("[data-drop-project]")).toHaveClass("drop-over");
  expect(document.body).toHaveClass("dragging-recordings");
  if (escape) fireEvent.keyDown(window, { key: "Escape" });
  fireEvent.pointerUp(window, { clientX: 40, clientY: 40 });
  if (click) fireEvent.click(from);
  await act(flushAsync);
}

describe("dragging recordings onto the sidebar", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => {
    document.body.classList.remove("dragging-recordings");
  });

  it("DT-LIB-082: a row dropped on a pinned project moves there, and the press does not open it", async () => {
    app();
    await act(flushAsync);
    await drag(row("Kick-off"), project());
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["a"], action: "move", clientId: "p1", label: null }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Moved to Qureshi Textiles");
    expect(screen.queryByRole("heading", { level: 1, name: "Kick-off" })).toBeNull();
    expect(document.body).not.toHaveClass("dragging-recordings");
  });

  it("DT-LIB-083: with rows picked, dragging one of them moves them all; the Inbox takes them out of their project", async () => {
    app();
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Select" }));
    fireEvent.click(row("Kick-off"));
    fireEvent.click(row("Board call"));
    await drag(row("Board call"), inbox());
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["a", "c"], action: "move", clientId: null, label: null }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("2 recordings moved to the Inbox");
  });

  it("Escape, or letting go away from a target, moves nothing", async () => {
    app();
    await act(flushAsync);
    await drag(row("Follow-up"), project(), { escape: true, click: false });
    await drag(row("Follow-up"), null, { click: false });
    expect(callsOf("sessions_bulk")).toEqual([]);
  });

  it("DT-PRJ-014: the sidebar's targets are the pinned projects and, while dragging, the Inbox", () => {
    render(<Shell tab="projects" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} pinnedProjects={[{ id: "p1", name: "Qureshi Textiles" }]} onProject={() => undefined}><p /></Shell>);
    expect(project()).toHaveAttribute("data-drop-project", "p1");
    expect(inbox()).toHaveAttribute("data-drop-project", "");
    expect(inbox()).toHaveAttribute("aria-hidden", "true");
  });
});
