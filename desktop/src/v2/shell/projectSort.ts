// How the projects are listed (UX-09): by name, or the ones opened lately first ("Recent", once a row of its own in the
// Projects panel, now a sort option). One choice for the panel and the All projects page, each account's own on this
// computer (kept.ts), read again when another account signs in.
import { useSyncExternalStore } from "react";
import { recentProjects } from "./recentProjects";
import { onProjectsAccount, projectsAccountNow, readKept, writeKept } from "./kept";

export type ProjectSort = "name" | "recent";
export const PROJECT_SORT_KEY = "echoledger-projects-sort";
const EVENT = "echoledger:projects-sort";
export const PROJECT_SORTS: readonly { value: ProjectSort; label: string }[] = [{ value: "name", label: "Name" }, { value: "recent", label: "Recent" }];

/** The account's choice (Name until it chose; Name while the account isn't known). */
export function loadProjectSort(account: string | null = projectsAccountNow()): ProjectSort {
  return readKept(PROJECT_SORT_KEY, account) === "recent" ? "recent" : "name";
}

/** Chooses for the account now (in memory only until it is known), for the panel and the page at once. */
export function setProjectSort(sort: ProjectSort) {
  writeKept(PROJECT_SORT_KEY, projectsAccountNow(), sort);
  current = sort;
  window.dispatchEvent(new Event(EVENT));
}

let current: ProjectSort | null = null;
const now = () => (current ??= loadProjectSort());
// Another account: its own choice is read again.
if (typeof window !== "undefined") onProjectsAccount(() => {
  current = null;
  window.dispatchEvent(new Event(EVENT));
});

/** The choice now, followed as it changes (the panel's and the page's agree), each account its own. */
export function useProjectSort(): [ProjectSort, (sort: ProjectSort) => void] {
  const sort = useSyncExternalStore(
    (changed) => {
      window.addEventListener(EVENT, changed);
      return () => window.removeEventListener(EVENT, changed);
    },
    now,
    () => "name" as const,
  );
  return [sort, setProjectSort];
}

/** For tests: forget the choice read. */
export function resetProjectSortForTests() {
  current = null;
}

/**
 * The order of a list of projects (pure but for `recent`): pinned first, then by name, or the ones opened lately first
 * (newest first) and the rest by name.
 */
export function projectOrder(sort: ProjectSort, recent: readonly string[] = sort === "recent" ? recentProjects() : []) {
  const rank = new Map(recent.map((id, i) => [id, i]));
  return (a: { id: string; name: string; pinned?: boolean }, b: { id: string; name: string; pinned?: boolean }) => {
    const pinned = Number(!!b.pinned) - Number(!!a.pinned);
    if (pinned) return pinned;
    if (sort === "recent") {
      const ra = rank.get(a.id) ?? Infinity;
      const rb = rank.get(b.id) ?? Infinity;
      if (ra !== rb) return ra - rb;
    }
    return a.name.localeCompare(b.name);
  };
}
