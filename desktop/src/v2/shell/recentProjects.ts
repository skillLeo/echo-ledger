// The projects opened lately (the Projects sort "Recent", UX-09): each account's own on this computer, newest first;
// while the account isn't known, kept in memory only. No components, so the window can note one without loading the
// new design.
import { onProjectsAccount, projectsAccountNow, readKept, writeKept } from "./kept";

const RECENT_KEY = "echoledger-recent-projects";
/** Before the account is known. */
let memory: string[] = [];

/** The projects opened on this computer by the account now, newest first. */
export function recentProjects(): string[] {
  const account = projectsAccountNow();
  if (!account) return memory;
  try {
    const saved: unknown = JSON.parse(readKept(RECENT_KEY, account) ?? "[]");
    return Array.isArray(saved) ? saved.filter((v): v is string => typeof v === "string") : [];
  } catch {
    return [];
  }
}

export function noteRecentProject(id: string) {
  const next = [id, ...recentProjects().filter((x) => x !== id)].slice(0, 8);
  const account = projectsAccountNow();
  if (account) writeKept(RECENT_KEY, account, JSON.stringify(next));
  else memory = next;
}

// Another account: what was noted before one was known isn't carried over to it.
if (typeof window !== "undefined") onProjectsAccount(() => {
  memory = [];
});
