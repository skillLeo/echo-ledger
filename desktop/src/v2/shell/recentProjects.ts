// The Projects panel's Recent (PLAN_NAV_3_LEVELS §3): the projects opened on this computer, kept here; no components,
// so the window can note one without loading the new design.
/** The projects opened on this computer, newest first (the Projects panel's Recent), kept here. */
const RECENT_KEY = "echoledger-recent-projects";
export function recentProjects(): string[] {
  try {
    const saved: unknown = JSON.parse(localStorage.getItem(RECENT_KEY) ?? "[]");
    return Array.isArray(saved) ? saved.filter((v): v is string => typeof v === "string") : [];
  } catch {
    return [];
  }
}
export function noteRecentProject(id: string) {
  try {
    localStorage.setItem(RECENT_KEY, JSON.stringify([id, ...recentProjects().filter((x) => x !== id)].slice(0, 8)));
  } catch {
    // Kept for this session only.
  }
}

