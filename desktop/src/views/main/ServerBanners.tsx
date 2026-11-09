// What the server says to every app (v1.6, P2.7, docs/API.md "Updates, announcements and maintenance"), at the top of
// the main window: the update this version needs (Update tries the app's own updater first, then the server's
// Downloads page), the admin's announcement (closed per text, remembered on this computer) and maintenance, a quiet
// line: recordings and uploads wait, nothing failed.
import { useState } from "react";
import { api, errorMessage, MAINTENANCE_TEXT, UPDATE_REQUIRED_TEXT, type AnnouncementNotice, type ServerNotices } from "../../lib/ipc";
import { clockTime } from "../../lib/format";
import { AlertIcon, BellIcon, CloseIcon, RefreshIcon } from "../../components/Icons";

const CLOSED_KEY = "echoledger-announcements-closed";
/** How many closed announcements are remembered (the oldest are forgotten first). */
const CLOSED_KEEP = 20;

export function closedAnnouncements(): string[] {
  try {
    const saved = JSON.parse(window.localStorage.getItem(CLOSED_KEY) ?? "[]");
    return Array.isArray(saved) ? saved.filter((t): t is string => typeof t === "string") : [];
  } catch {
    return [];
  }
}
function rememberClosed(text: string): void {
  try {
    window.localStorage.setItem(CLOSED_KEY, JSON.stringify([...closedAnnouncements().filter((t) => t !== text), text].slice(-CLOSED_KEEP)));
  } catch {
    // Storage can be unavailable; it is closed until the app restarts.
  }
}

/** The announcement still shows: it has words, its time has not passed, and it was not closed (per text). */
export function showsAnnouncement(a: AnnouncementNotice | null | undefined, closed: readonly string[], now: number = Date.now()): a is AnnouncementNotice {
  if (!a || !a.text.trim()) return false;
  const until = a.until ? Date.parse(a.until) : NaN;
  if (Number.isFinite(until) && now >= until) return false;
  return !closed.includes(a.text);
}

/** "Back about 11:00." for maintenance that says when it ends (today's time, else the day too). */
export function backAbout(until: string | null | undefined, now: Date = new Date()): string {
  if (!until) return "";
  const at = new Date(until);
  if (Number.isNaN(at.getTime()) || at.getTime() <= now.getTime()) return "";
  const sameDay = at.toDateString() === now.toDateString();
  return `Back about ${sameDay ? "" : `${at.toLocaleDateString([], { month: "short", day: "numeric" })}, `}${clockTime(at)}.`;
}

/** A message the core says while the server holds the work: shown as news, never as a failure. */
export function isHoldMessage(text: string): boolean {
  return text === MAINTENANCE_TEXT || text === UPDATE_REQUIRED_TEXT;
}

export function ServerBanners({ server, notify }: { server: ServerNotices | null | undefined; notify: (text: string, error?: boolean) => void }) {
  const [closed, setClosed] = useState<string[]>(closedAnnouncements);
  const [checking, setChecking] = useState(false);
  if (!server) return null;
  const update = server.appUpdate;
  const announcement = server.announcement;

  // Update: the app's own updater first; without a newer build there (or without an updater), the Downloads page.
  const runUpdate = async () => {
    if (checking) return;
    setChecking(true);
    try {
      const status = await api.checkForUpdates().catch(() => null);
      if (status && (status.phase === "downloading" || status.phase === "ready")) {
        notify(status.phase === "ready" ? "The update is ready: choose Restart to install it." : "Downloading the update. EchoLedger says when it is ready to restart.");
        return;
      }
      await api.openServerPage("downloads");
      notify("The Downloads page opened in your browser");
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setChecking(false);
    }
  };

  return (
    <>
      {update && (
        <div className={`banner ${update.refused ? "danger" : "warn"}`} data-testid="app-update">
          <AlertIcon size={16} />
          <span className="banner-text">
            <b>{update.message || (update.minimum ? `Please update EchoLedger to ${update.minimum} or later.` : "Please update EchoLedger.")}</b>
            {update.refused && <span className="small"> The server no longer accepts this version: your recordings wait on this computer and upload after the update.</span>}
          </span>
          <button type="button" className="btn compact" disabled={checking} onClick={() => void runUpdate()}><RefreshIcon size={14} />{checking ? "Looking…" : "Update"}</button>
        </div>
      )}
      {server.maintenance && (
        <div className="banner quiet" data-testid="maintenance">
          <RefreshIcon size={16} />
          <span className="banner-text">
            <b>{server.maintenance.message && server.maintenance.message !== MAINTENANCE_TEXT ? server.maintenance.message : "The server is in maintenance."}</b>
            <span className="small"> {backAbout(server.maintenance.until)} Recordings and uploads wait on this computer and go when it is back.</span>
          </span>
        </div>
      )}
      {showsAnnouncement(announcement, closed) && (
        <div className={`banner ${announcement.level === "warning" ? "warn" : "info"}`} data-testid="announcement">
          <BellIcon size={16} />
          <span className="banner-text" dir="auto">{announcement.text}</span>
          <button type="button" className="banner-close" aria-label="Close this announcement" onClick={() => { rememberClosed(announcement.text); setClosed(closedAnnouncements()); }}>
            <CloseIcon size={14} />
          </button>
        </div>
      )}
    </>
  );
}
