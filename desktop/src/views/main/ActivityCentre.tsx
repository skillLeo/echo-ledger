// The activity centre (plan B18) as a page of its own, laid out like the phone's: what needs the person first (calls
// waiting for Keep or Discard, uploads that stopped), then the notifications by day. A notification about a recording
// opens it; the unread count shows beside Activity in the sidebar.
import { useEffect, useState } from "react";
import { api, errorMessage, listenTo, type ActivityItem, type AppStatus, type PendingItem } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { dayGroup } from "../../lib/library";
import { waitingSummary } from "../../lib/pending";
import { AlertIcon, BellIcon, DesktopIcon, HourglassIcon } from "../../components/Icons";
import { CheckIcon } from "../../components/ControlIcons";

/** The unread count of the activity centre, kept current by the core's `activity:updated`. */
export function useActivityUnread(): [number, (n: number) => void] {
  const [unread, setUnread] = useState(0);
  useEffect(() => {
    api.activity().then((r) => setUnread(r.unread_count ?? 0)).catch(() => undefined);
    const un = listenTo("activity:updated", (n) => setUnread(typeof n === "number" ? n : 0));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  return [unread, setUnread];
}

const FAILED_KINDS = new Set(["transcript_failed", "upload_failed", "budget_reached"]);

function ActivityTile({ kind }: { kind: string | null }) {
  if (kind && FAILED_KINDS.has(kind)) return <span className="activity-tile failed"><AlertIcon size={20} /></span>;
  if (kind === "transcript_ready") return <span className="activity-tile"><CheckIcon size={20} /></span>;
  if (kind === "device_paired") return <span className="activity-tile neutral"><DesktopIcon size={20} /></span>;
  return <span className="activity-tile neutral"><BellIcon size={20} /></span>;
}

/** Today's notifications show the time, older ones the day. */
function shortWhen(iso: string | null): string {
  if (!iso) return "";
  const text = formatWhen(iso);
  return text.startsWith("Today · ") ? text.slice("Today · ".length) : text.split(" · ")[0];
}

export function ActivityView({ status, onOpenRecording, onReview, onUnread }: {
  status: AppStatus;
  onOpenRecording: (id: string) => void;
  /** Opens the waiting recordings in the Library (the first one in view). */
  onReview: (pendingId: string | null) => void;
  onUnread: (n: number) => void;
}) {
  const [items, setItems] = useState<ActivityItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState<PendingItem[]>([]);

  const load = () =>
    api.activity().then((r) => {
      setItems(r.data ?? []);
      onUnread(r.unread_count ?? 0);
      setError(null);
    }, (e) => setError(errorMessage(e)));

  useEffect(() => {
    void load();
    api.pendingList().then((p) => setPending(Array.isArray(p) ? p : [])).catch(() => undefined);
    const subs = [
      listenTo("activity:updated", () => void load()),
      listenTo("pending:changed", (p) => setPending(Array.isArray(p) ? p : [])),
    ];
    return () => subs.forEach((s) => s.then((f) => f()).catch(() => undefined));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const choose = async (item: ActivityItem) => {
    if (!item.read) {
      const r = await api.markActivityRead([item.id]).catch(() => null);
      if (r) onUnread(r.unread_count);
      setItems((list) => list?.map((i) => (i.id === item.id ? { ...i, read: true } : i)) ?? null);
    }
    if (item.session_id) onOpenRecording(item.session_id);
  };
  const readAll = async () => {
    const r = await api.markActivityRead().catch(() => null);
    if (r) onUnread(r.unread_count);
    setItems((list) => list?.map((i) => ({ ...i, read: true })) ?? null);
  };

  const unread = (items ?? []).filter((i) => !i.read).length;
  const needsAction = (items ?? []).filter((i) => !i.read && i.kind !== null && FAILED_KINDS.has(i.kind));
  const rest = (items ?? []).filter((i) => !needsAction.includes(i));
  const groups: { label: string; items: ActivityItem[] }[] = [];
  for (const item of rest) {
    const label = dayGroup(item.at ?? undefined) === "Today" ? "Today" : "Earlier";
    const last = groups[groups.length - 1];
    if (last?.label === label) last.items.push(item);
    else groups.push({ label, items: [item] });
  }
  const waiting = pending.length > 0 || status.pendingDecisions > 0;

  const row = (i: ActivityItem) => (
    <li key={i.id}>
      <button type="button" className={`activity-item ${i.read ? "" : "unread"}`} onClick={() => void choose(i)}>
        <ActivityTile kind={i.kind} />
        <span className="activity-main">
          <span className="activity-title">{!i.read && <span className="unread-dot" aria-label="Unread" />}<span>{i.title}</span></span>
          <span className="activity-body">{i.body}</span>
        </span>
        {i.at && <span className="item-time">{shortWhen(i.at)}</span>}
      </button>
    </li>
  );

  return (
    <div className="activity-page">
      <div className="page-head">
        <h1 className="page-title">Activity</h1>
        <span className="spacer" />
        {unread > 0 && <button type="button" className="btn text" onClick={() => void readAll()}>Mark all read</button>}
      </div>
      {(waiting || status.failedUploads > 0 || needsAction.length > 0) && (
        <section aria-labelledby="activity-needs">
          <h2 id="activity-needs" className="section-title">Needs action</h2>
          {waiting && (
            <div className="action-card">
              <HourglassIcon size={22} />
              <div className="action-card-main">
                <span className="action-card-title">Keep or discard?</span>
                <span className="small muted">{pending.length > 0 ? waitingSummary(pending) : `${status.pendingDecisions} waiting for your decision`}</span>
              </div>
              <button type="button" className="btn text" onClick={() => onReview(pending[0]?.id ?? null)}>Review</button>
            </div>
          )}
          {status.failedUploads > 0 && (
            <div className="action-card">
              <AlertIcon size={22} />
              <div className="action-card-main">
                <span className="action-card-title">Uploads that need you</span>
                <span className="small muted">{status.failedUploads === 1 ? "1 recording did not finish uploading" : `${status.failedUploads} recordings did not finish uploading`}</span>
              </div>
              <button type="button" className="btn text" onClick={() => onReview(null)}>Open</button>
            </div>
          )}
          {needsAction.length > 0 && <ul className="activity-list">{needsAction.map(row)}</ul>}
        </section>
      )}
      {error && (
        <div className="notice error sync-error" role="alert">
          <span>Couldn't load the activity. {error}</span>
          <button type="button" className="btn compact" onClick={() => void load()}>Retry</button>
        </div>
      )}
      {items === null && !error && (
        <div aria-busy="true">
          <span className="sr-only">Loading activity…</span>
          {[0, 1, 2].map((n) => (
            <div key={n} className="skeleton-row" aria-hidden="true">
              <span className="skeleton tile" />
              <span className="skeleton-lines"><span className="skeleton" style={{ width: "48%", height: 14 }} /><span className="skeleton" style={{ width: "30%", height: 10 }} /></span>
            </div>
          ))}
        </div>
      )}
      {items && items.length === 0 && !waiting && (
        <div className="empty-state">
          <span className="empty-icon"><BellIcon size={26} /></span>
          <span className="empty-title">All caught up</span>
          <p>Nothing yet. Finished transcripts, failed uploads and new devices show up here.</p>
        </div>
      )}
      {groups.map((g) => (
        <section key={g.label} aria-label={g.label}>
          <h2 className="section-title">{g.label}</h2>
          <ul className="activity-list">{g.items.map(row)}</ul>
        </section>
      ))}
    </div>
  );
}
