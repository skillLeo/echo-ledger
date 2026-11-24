// The project's menu in the panel's tree (3b, UX-22's way): right-click a project, or Shift+F10 or the menu key on it.
// Open · Pin | Rename… · Instructions & Memory… · Request Files… · Members… | Archive | Delete Project…, every item an
// action of actions.json. A right-click menu holds only what fits (3b, as contextMenus.ts): what nothing here can do is
// left out, not greyed (only the menu bar greys), and a group left empty takes its line with it. On a Mac the system
// draws it (native_menu_popup); elsewhere it is the page's own menu.
import { useState, type ReactNode } from "react";
import { ContextMenu, menuPointFor } from "../components/Menu";
import { api } from "../../lib/ipc";
import { canRun, menuItems, nativeItems, type ActionContext } from "../actions/registry";

export const PROJECT_MENU = [
  "project.open", "project.pin", "-",
  "project.rename", "project.instructions", "project.requestFiles", "project.members", "-",
  "project.archive", "-",
  "project.delete",
] as const;

const mac = () => typeof document !== "undefined" && document.documentElement.dataset.platform === "macos";

/**
 * Its items for one project: only what something here can run and what fits (a right-click menu, `context`), with no
 * keys (a right-click menu on a Mac shows none).
 */
export const projectItems = (ctx: ActionContext) =>
  menuItems(PROJECT_MENU.filter((id) => id === "-" || canRun(id, ctx.kind)), { ...ctx, context: true })
    .map((i) => ({ ...i, shortcut: undefined }));

/** The menu for one project, and what opens it at a point (the pointer) or under an element (the keyboard). */
export function useProjectMenu(): { openAt: (project: { id: string; name: string; pinned?: boolean }, at: { x: number; y: number }) => void; view: ReactNode } {
  const [open, setOpen] = useState<{ name: string; ctx: ActionContext; at: { x: number; y: number } } | null>(null);
  const openAt = (project: { id: string; name: string; pinned?: boolean }, at: { x: number; y: number }) => {
    const ctx: ActionContext = { platform: mac() ? "macos" : "windows", targets: [project.id], state: { pinned: !!project.pinned } };
    const items = projectItems(ctx);
    // Nothing here can do anything for a project: no menu.
    if (!items.length) return;
    if (mac()) {
      const lines = nativeItems(items).map((i) => (i.action ? { ...i, target: project.id } : i));
      void api.nativeMenuPopup(lines, at.x, at.y).catch(() => setOpen({ name: project.name, ctx, at }));
      return;
    }
    setOpen({ name: project.name, ctx, at });
  };
  const view = open && (
    <ContextMenu label={open.name} items={projectItems(open.ctx)} at={open.at}
      onClose={() => setOpen(null)} />
  );
  return { openAt, view };
}

/** Where a keyboard opens a row's menu: under the start of the focused element. */
export const keyboardPoint = (el: Element) => menuPointFor(el);
