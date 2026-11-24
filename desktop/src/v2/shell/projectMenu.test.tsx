// The project's menu in the panel's tree (3b): right-click, Shift+F10 or the menu key; every item from actions.json;
// what nothing here can run yet greyed, never hidden; on a Mac the system's own menu, carrying the project it is for.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { callsOf, emitEvent, resetTauriMock, respond } from "../../test/tauri";
import { Shell, type SidebarProject } from "./Shell";
import { registerAction } from "../actions/registry";
import { useMenuPicks } from "../actions/menuState";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PROJECTS: SidebarProject[] = [{ id: "m", name: "Mehran Foods" }, { id: "q", name: "Qureshi Textiles", pinned: true }];
function Picks() {
  useMenuPicks("macos");
  return null;
}
const show = () => render(
  <><Picks /><Shell tab="library" onGo={() => undefined} status={{ kind: "ready", text: "Ready" }} projectTree={PROJECTS} projectId={null} onProject={() => undefined}><p /></Shell></>,
);
const project = (name: string) => within(screen.getByRole("list", { name: "Projects" })).getByRole("button", { name });
const offs: (() => void)[] = [];

describe("a project's menu in the panel (3b)", () => {
  beforeEach(() => {
    resetTauriMock();
    localStorage.clear();
  });
  afterEach(() => {
    offs.splice(0).forEach((off) => off());
    delete document.documentElement.dataset.platform;
  });

  it("Open · Pin | Rename… · Instructions & memory… · Request files… · Members… | Archive | Delete project…; greyed where nothing can run it", () => {
    const open = vi.fn();
    offs.push(registerAction("project.open", open));
    show();
    fireEvent.contextMenu(project("Mehran Foods"), { clientX: 40, clientY: 100 });
    const menu = screen.getByRole("menu", { name: "Mehran Foods" });
    expect([...menu.querySelectorAll('[role^="menuitem"]')].map((m) => m.textContent)).toEqual([
      "Open", "Pin", "Rename…", "Instructions & memory…", "Request files…", "Members…", "Archive", "Delete project…",
    ]);
    expect(menu.querySelectorAll('[role="separator"]')).toHaveLength(3);
    expect(within(menu).getByRole("menuitem", { name: "Rename…" })).toHaveAttribute("aria-disabled", "true");
    expect(within(menu).getByRole("menuitem", { name: "Open" })).not.toHaveAttribute("aria-disabled");
    fireEvent.click(within(menu).getByRole("menuitem", { name: "Open" }));
    expect(open).toHaveBeenCalledWith(expect.objectContaining({ targets: ["m"] }));
  });

  it("a pinned project reads Unpin; the keyboard opens it too (Shift+F10, the menu key)", () => {
    show();
    project("Qureshi Textiles").focus();
    fireEvent.keyDown(project("Qureshi Textiles"), { key: "F10", shiftKey: true });
    expect(within(screen.getByRole("menu", { name: "Qureshi Textiles" })).getByRole("menuitem", { name: "Unpin" })).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole("menu", { name: "Qureshi Textiles" }), { key: "Escape" });
    fireEvent.keyDown(project("Mehran Foods"), { key: "ContextMenu" });
    expect(screen.getByRole("menu", { name: "Mehran Foods" })).toBeInTheDocument();
  });

  it("on a Mac the system draws it, in Title Case, each item carrying its project; a pick runs for that project", async () => {
    document.documentElement.dataset.platform = "macos";
    respond("native_menu_popup", null);
    const requestFiles = vi.fn();
    offs.push(registerAction("project.requestFiles", requestFiles));
    show();
    fireEvent.contextMenu(project("Mehran Foods"), { clientX: 40, clientY: 100 });
    await act(async () => undefined);
    expect(screen.queryByRole("menu")).toBeNull();
    const [call] = callsOf("native_menu_popup");
    const items = call.items as { action: string | null; label?: string; enabled?: boolean; target?: string }[];
    expect(items.filter((i) => i.action).map((i) => i.label)).toEqual([
      "Open", "Pin", "Rename…", "Instructions & Memory…", "Request Files…", "Members…", "Archive", "Delete Project…",
    ]);
    expect(items.filter((i) => i.action).every((i) => i.target === "m")).toBe(true);
    expect(items.find((i) => i.action === "project.requestFiles")).toMatchObject({ enabled: true });
    expect(items.find((i) => i.action === "project.members")).toMatchObject({ enabled: false });
    await act(() => emitEvent("menu:action", "project.requestFiles@m"));
    expect(requestFiles).toHaveBeenCalledWith(expect.objectContaining({ targets: ["m"] }));
  });
});
