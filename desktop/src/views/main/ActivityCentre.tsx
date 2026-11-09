// The activity centre (plan B18) as a page of its own, laid out like the phone's: what needs the person first (calls
// waiting for Keep or Discard, uploads that stopped), the quiet "Skipped 3 short calls" line (v1.5), then the
// notifications by day. A notification about a recording opens it; the unread count shows beside Activity in the sidebar.
import { useEffect, useState } from "react";
import { api, errorMessage, listenTo, type ActivityItem, type AppStatus, type LibraryItem, type PendingItem, type SkippedItem, type UploadItem } from "../../lib/ipc";
import { LIVE_STATUS_MS, needsLiveStatus } from "../../lib/library";
import { stepOf, uploadLine } from "../../lib/progress";
import { useWindowShown } from "../../lib/visibility";
import { formatWhen } from "../../lib/format";
import { dayGroup } from "../../lib/library";
import { skippedLine, skippedMeta, waitingItems, waitingSummary } from "../../lib/pending";
import { AlertIcon, BellIcon, DesktopIcon, HourglassIcon } from "../../components/Icons";
import { BADGE_READ_LIMIT, isProblem, needsAction as asksForAction } from "../../lib/badge";
import { CheckIcon } from "../../components/ControlIcons";

/** What is on its way (Phase 1C, C4): uploads with their %, and recordings being transcribed, the Library's words. */
export interface InProgressRow {
  id: string;
  title: string;
  line: string;
  /** Opens the recording (not for an upload still on this computer). */
  recordingId: string | null;
}

/** The rows of In progress (pure): this computer's uploads first, then the Library's recordings still moving. */
export function inProgressRows(uploads: UploadItem[], items: LibraryItem[]): InProgressRow[] {
  const sending = uploads.filter((u) => u.state !== "failed" && !u.heldFor).map((u) => ({ id: `upload-${u.id}`, title: u.title ?? "Recording", line: uploadLine(u), recordingId: null }));
  const moving = items
    .filter((i) => !i.deleted)
    .map((i) => ({ item: i, step: stepOf(i) }))
    .filter(({ step }) => step.step !== null)
    .map(({ item, step }) => ({ id: item.id, title: item.title ?? "Recording", line: step.line ?? "", recordingId: item.id }));
  return [...sending, ...moving];
}

function useInProgress(): InProgressRow[] {
  const [rows, setRows] = useState<InProgressRow[]>([]);
  const shown = useWindowShown();
  useEffect(() => {
    if (!shown) return;
    let alive = true;
    let timer = 0;
    const read = async () => {
      const [uploads, items] = await Promise.all([api.uploadList().catch(() => []), api.library().catch(() => [])]);
      if (!alive) return;
      const list = Array.isArray(items) ? items : [];
      const next = inProgressRows(Array.isArray(uploads) ? uploads : [], list.filter((i) => needsLiveStatus([i])));
      setRows(next);
      if (next.length > 0) timer = window.setTimeout(() => void read(), LIVE_STATUS_MS);
    };
    void read();
    const subs = [listenTo("uploads:changed", () => void read()), listenTo("library:updated", () => void read())];
    return () => {
      alive = false;
      window.clearTimeout(timer);
      subs.forEach((p) => p.then((f) => f()).catch(() => undefined));
    };
  }, [shown]);
  return rows;
}

/**
 * The notifications the Activity badge is counted from (v1.6, P6: lib/badge.ts), read when the window opens and again
 * whenever the core says the activity changed (its own poll, or something marked read).
 */
export function useActivityItems(): [ActivityItem[], (items: ActivityItem[]) => void] {
  const [items, setItems] = useState<ActivityItem[]>([]);
  useEffect(() => {
    let alive = true;
    const read = () => api.activity(BADGE_READ_LIMIT).then((r) => alive && setItems(Array.isArray(r?.data) ? r.data : [])).catch(() => undefined);
    void read();
    const un = listenTo("activity:updated", () => void read());
    return () => {
      alive = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  return [items, setItems];
}

function ActivityTile({ kind }: { kind: string | null }) {
  if (isProblem(kind)) return <span className="activity-tile failed"><AlertIcon size={20} /></span>;
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

export function ActivityView({ status, notify, onOpenRecording, onReview, onItems }: {
  status: AppStatus;
  /** The main window's toast (Restore says what happened). */
  notify?: (text: string, error?: boolean) => void;
  onOpenRecording: (id: string) => void;
  /** Opens the waiting recordings in the Library (the first one in view). */
  onReview: (pendingId: string | null) => void;
  /** The list as it now stands (the badge is counted from it). */
  onItems?: (items: ActivityItem[]) => void;
}) {
  const [items, setItems] = useState<ActivityItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [all, setPending] = useState<PendingItem[]>([]);
  // A recording whose after-call chip still counts down keeps itself: only the ones left waiting need the person.
  const pending = waitingItems(all);
  const [skipped, setSkipped] = useState<SkippedItem[]>([]);
  const [showSkipped, setShowSkipped] = useState(false);
  const [restoring, setRestoring] = useState<string | null>(null);
  const moving = useInProgress();

  const load = () =>
    api.activity().then((r) => {
      setItems(r.data ?? []);
      setError(null);
    }, (e) => setError(errorMessage(e)));

  useEffect(() => {
    void load();
    api.pendingList().then((p) => setPending(Array.isArray(p) ? p : [])).catch(() => undefined);
    api.skippedList().then((s) => setSkipped(Array.isArray(s) ? s : [])).catch(() => undefined);
    const subs = [
      listenTo("activity:updated", () => void load()),
      listenTo("pending:changed", (p) => setPending(Array.isArray(p) ? p : [])),
      listenTo("skipped:changed", (s) => setSkipped(Array.isArray(s) ? s : [])),
    ];
    return () => subs.forEach((s) => s.then((f) => f()).catch(() => undefined));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Once nothing is skipped the line goes; the next skipped call starts collapsed again.
  useEffect(() => {
    if (skipped.length === 0) setShowSkipped(false);
  }, [skipped.length]);

  /** Restore on the skipped line: the call is kept like any other; the core says what is left. */
  const restore = async (item: SkippedItem) => {
    if (restoring) return;
    setRestoring(item.id);
    try {
      await api.skippedRestore(item.id);
      setSkipped((list) => list.filter((s) => s.id !== item.id));
      notify?.("Restored. It uploads now and shows in the Library.");
    } catch (e) {
      notify?.(errorMessage(e), true);
      api.skippedList().then((s) => setSkipped(Array.isArray(s) ? s : [])).catch(() => undefined);
    } finally {
      setRestoring(null);
    }
  };

  // The badge follows what this page shows (marking read here takes it off at once).
  useEffect(() => {
    if (items) onItems?.(items);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [items]);

  const choose = async (item: ActivityItem) => {
    if (!item.read) {
      await api.markActivityRead([item.id]).catch(() => null);
      setItems((list) => list?.map((i) => (i.id === item.id ? { ...i, read: true } : i)) ?? null);
    }
    if (item.session_id) onOpenRecording(item.session_id);
  };
  const readAll = async () => {
    await api.markActivityRead().catch(() => null);
    setItems((list) => list?.map((i) => ({ ...i, read: true })) ?? null);
  };

  const unread = (items ?? []).filter((i) => !i.read).length;
  // The same rule as the badge (lib/badge.ts): unread problems and answers to an upgrade request.
  const needsAction = (items ?? []).filter(asksForAction);
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
      {moving.length > 0 && (
        <section aria-labelledby="activity-moving" data-testid="in-progress">
          <h2 id="activity-moving" className="section-title">In progress</h2>
          <ul className="activity-list">
            {moving.map((r) => (
              <li key={r.id} className="activity-row progress-row">
                <span className="spinner" aria-hidden="true" />
                <div className="activity-main">
                  <span className="activity-title" dir="auto">{r.title}</span>
                  <span className="small muted">{r.line}</span>
                </div>
                {r.recordingId && <button type="button" className="btn text compact" onClick={() => onOpenRecording(r.recordingId!)}>Open</button>}
              </li>
            ))}
          </ul>
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
      {skipped.length > 0 && (
        <section className="skipped-calls" aria-label="Skipped calls" data-testid="skipped-calls">
          <div className="skipped-head">
            <span className="skipped-line">{skippedLine(skipped.length)}</span>
            <button type="button" className="btn text compact" aria-expanded={showSkipped} aria-controls={showSkipped ? "skipped-list" : undefined}
              onClick={() => setShowSkipped((v) => !v)}>
              {showSkipped ? "Hide" : "Show"}
            </button>
          </div>
          {showSkipped && (
            <ul id="skipped-list" className="skipped-list">
              {skipped.map((item) => {
                const meta = skippedMeta(item);
                return (
                  <li key={item.id} className="skipped-row">
                    <span className="skipped-meta" dir="auto" title={meta}>{meta}</span>
                    <button type="button" className="btn text compact" disabled={restoring !== null} aria-label={`Restore ${meta}`}
                      onClick={() => void restore(item)}>
                      Restore
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </section>
      )}
      {items && items.length === 0 && !waiting && skipped.length === 0 && (
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
