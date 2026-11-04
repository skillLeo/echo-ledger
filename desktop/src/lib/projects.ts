// Projects (docs/API.md "Projects", since v1.5): client folders with subfolders, at most 4 levels deep, as a tree, and
// where a project may move. Pure; unit-tested.
import type { ClientFolder, LibraryItem } from "./ipc";
import { sortFolders } from "./organise";

/** Projects nest at most this many levels (docs/API.md `too_deep`). */
export const MAX_DEPTH = 4;

export interface ProjectNode {
  project: ClientFolder;
  /** 1 = the top level. */
  depth: number;
  children: ProjectNode[];
}

/** Whether the server speaks v1.5 projects: it sends `parent_id` (null at the top) with every project. */
export function hasSubfolders(projects: readonly ClientFolder[]): boolean {
  return projects.some((p) => p.parent_id !== undefined);
}

/** The parent a project is shown under: its `parent_id` when that project is known, else the top level. */
function parentOf(project: ClientFolder, ids: ReadonlySet<string>): string | null {
  return project.parent_id && project.parent_id !== project.id && ids.has(project.parent_id) ? project.parent_id : null;
}

/**
 * The projects as a tree, each level pinned first and then by name. A project whose parent is unknown (or that sits in
 * a loop the server should never send) is shown at the top level, so nothing is ever left out.
 */
export function projectTree(projects: readonly ClientFolder[]): ProjectNode[] {
  const ids = new Set(projects.map((p) => p.id));
  const children = new Map<string | null, ClientFolder[]>();
  for (const p of projects) {
    const parent = parentOf(p, ids);
    children.set(parent, [...(children.get(parent) ?? []), p]);
  }
  const placed = new Set<string>();
  const build = (parent: string, depth: number): ProjectNode[] =>
    sortFolders(children.get(parent) ?? [])
      .filter((p) => !placed.has(p.id) && placed.add(p.id))
      .map((project) => ({ project, depth, children: build(project.id, depth + 1) }));
  const roots: ProjectNode[] = [];
  for (const project of sortFolders(children.get(null) ?? [])) {
    placed.add(project.id);
    roots.push({ project, depth: 1, children: build(project.id, 2) });
  }
  for (const project of sortFolders([...projects])) {
    if (placed.has(project.id)) continue;
    placed.add(project.id);
    roots.push({ project, depth: 1, children: build(project.id, 2) });
  }
  return roots;
}

/** The rows of a tree as shown, depth first: a project's subfolders only while it is expanded (all with `"all"`). */
export function visibleRows(tree: readonly ProjectNode[], expanded: ReadonlySet<string> | "all"): ProjectNode[] {
  const rows: ProjectNode[] = [];
  const walk = (nodes: readonly ProjectNode[]) => {
    for (const node of nodes) {
      rows.push(node);
      if (expanded === "all" || expanded.has(node.project.id)) walk(node.children);
    }
  };
  walk(tree);
  return rows;
}

/** A project's ancestors from the top (its breadcrumbs, without itself); the server's `path` when its parents are not known here. */
export function ancestors(projects: readonly ClientFolder[], id: string): { id: string; name: string }[] {
  const byId = new Map(projects.map((p) => [p.id, p]));
  const project = byId.get(id);
  if (!project) return [];
  const chain: { id: string; name: string }[] = [];
  const seen = new Set([id]);
  let parent = project.parent_id ?? null;
  while (parent && byId.has(parent) && !seen.has(parent)) {
    seen.add(parent);
    const p = byId.get(parent)!;
    chain.unshift({ id: p.id, name: p.name });
    parent = p.parent_id ?? null;
  }
  if (parent && !seen.has(parent) && project.path && project.path.length > chain.length) return project.path;
  return chain;
}

/** The level a project is on (1 = the top level). */
export function depthOf(projects: readonly ClientFolder[], id: string): number {
  return ancestors(projects, id).length + 1;
}

/** The project and every project under it. */
export function subtreeIds(projects: readonly ClientFolder[], id: string): Set<string> {
  const ids = new Set(projects.map((p) => p.id));
  const out = new Set([id]);
  let grew = true;
  while (grew) {
    grew = false;
    for (const p of projects) {
      const parent = parentOf(p, ids);
      if (parent && out.has(parent) && !out.has(p.id)) {
        out.add(p.id);
        grew = true;
      }
    }
  }
  return out;
}

/** How many levels a project and its subfolders take (1 = it has none). */
export function subtreeHeight(projects: readonly ClientFolder[], id: string): number {
  const ids = new Set(projects.map((p) => p.id));
  const height = (at: string, seen: Set<string>): number => {
    const kids = projects.filter((p) => parentOf(p, ids) === at && !seen.has(p.id));
    kids.forEach((k) => seen.add(k.id));
    return 1 + Math.max(0, ...kids.map((k) => height(k.id, seen)));
  };
  return height(id, new Set([id]));
}

/**
 * Whether a project may move under `target` (null = the top level, always allowed): never into itself or one of its
 * own subfolders (`invalid_parent`), and never so deep that a subfolder would pass 4 levels (`too_deep`).
 */
export function canMoveTo(projects: readonly ClientFolder[], id: string, target: string | null): boolean {
  if (target === null) return true;
  if (subtreeIds(projects, id).has(target)) return false;
  return depthOf(projects, target) + subtreeHeight(projects, id) <= MAX_DEPTH;
}

/** Whether a subfolder can be made inside a project (it is not on the deepest level already). */
export function canAddSubfolder(projects: readonly ClientFolder[], id: string): boolean {
  return depthOf(projects, id) < MAX_DEPTH;
}

/** "Acme › Invoices": a project's place, for pickers and messages. */
export function projectLabel(projects: readonly ClientFolder[], id: string): string {
  const project = projects.find((p) => p.id === id);
  if (!project) return "";
  return [...ancestors(projects, id).map((a) => a.name), project.name].join(" › ");
}

/** A project's recordings in the saved library, with its subfolders' when asked, newest first. */
export function projectRecordings(items: readonly LibraryItem[], projects: readonly ClientFolder[], id: string, withSubfolders: boolean): LibraryItem[] {
  const ids = withSubfolders ? subtreeIds(projects, id) : new Set([id]);
  return items
    .filter((i) => !i.deleted && !!i.client && ids.has(i.client.id))
    .sort((a, b) => (Date.parse(b.started_at ?? "") || 0) - (Date.parse(a.started_at ?? "") || 0));
}

/** Recordings in a project and its subfolders: the server's count when it sent one, else the saved library's. */
export function nestedCount(project: ClientFolder, items: readonly LibraryItem[], projects: readonly ClientFolder[]): number {
  if (typeof project.nested_session_count === "number") return project.nested_session_count;
  return Math.max(projectRecordings(items, projects, project.id, true).length, project.session_count ?? 0);
}
