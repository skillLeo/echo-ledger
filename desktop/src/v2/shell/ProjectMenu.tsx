// The project's menu in the panel's tree (3b, UX-22's way): right-click a project, or Shift+F10 or the menu key on it.
// Open · Pin | Rename… · Instructions & Memory… · Request Files… · Members… | Archive | Delete Project…, every item an
// action of actions.json. What nothing here can do yet is greyed (it is the Projects page's), never hidden. On a Mac the
// system draws it (native_menu_popup); elsewhere it is the page's own menu.
import { useState, type ReactNode } from "react";
import { ContextMenu, menuPointFor } from "../components/Menu";
import { api } from "../../lib/ipc";
import { hasHandler, menuItems, nativeItems, type ActionContext } from "../actions/registry";

export const PROJECT_MENU = [
  "project.open", "project.pin", "-",
  "project.rename", "project.instructions", "project.requestFiles", "project.members", "-",
  "project.archive", "-",
  "project.delete",
] as const;

const mac = () => typeof document !== "undefined" && document.documentElement.dataset.platform === "macos";

/**
 * Its items for one project: no keys (a right-click menu on a Mac shows none), and greyed where nothing here can run
 * it yet (hasHandler: the web builder's canRun when the two meet).
 */
const projectItems = (ctx: ActionContext) => menuItems([...PROJECT_MENU], ctx)
  .map((i) => ({ ...i, shortcut: undefined, disabled: i.disabled || !hasHandler(i.action) || undefined }));

/** The menu for one project, and what opens it at a point (the pointer) or under an element (the keyboard). */
export function useProjectMenu(): { openAt: (project: { id: string; name: string; pinned?: boolean }, at: { x: number; y: number }) => void; view: ReactNode } {
  const [open, setOpen] = useState<{ name: string; ctx: ActionContext; at: { x: number; y: number } } | null>(null);
  const openAt = (project: { id: string; name: string; pinned?: boolean }, at: { x: number; y: number }) => {
    // Greyed, not left out, when nothing here can run it (menuItems outside a right-click's own rule): 3b's rule for
    // what fits what was clicked holds, every item is a project's.
    const ctx: ActionContext = { platform: mac() ? "macos" : "windows", targets: [project.id], state: { pinned: !!project.pinned } };
    if (mac()) {
      const items = nativeItems(projectItems(ctx))
        .map((i) => (i.action ? { ...i, target: project.id } : i));
      void api.nativeMenuPopup(items, at.x, at.y).catch(() => setOpen({ name: project.name, ctx, at }));
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
