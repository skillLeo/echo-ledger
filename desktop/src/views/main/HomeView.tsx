// Home (v1.6, P4.1): where the app starts its day. What needs you (calls waiting, uploads that stopped, problems), the
// latest recordings, then recording itself (the Ready check, Record now, automatic calls, captions, voice notes).
import { useEffect, useState } from "react";
import { api, listenTo, type ActivityItem, type AppStatus, type LibraryItem } from "../../lib/ipc";
import { needsAction } from "../../lib/badge";
import { formatDuration } from "../../lib/format";
import { isViewer } from "../../lib/plan";
import { AlertIcon, AppTileIcon, appKind, BellIcon, HourglassIcon, ImportIcon } from "../../components/Icons";
import { PageHeader } from "../../components/Page";
import { StateBanner } from "../../components/StateBanner";
import { RecordView } from "./RecordView";
import { rowTime } from "./LibraryView";

const RECENT = 5;

/** The latest recordings saved here (newest first), kept fresh by the core's `library:updated`. */
function useRecent(): LibraryItem[] | null {
  const [items, setItems] = useState<LibraryItem[] | null>(null);
  useEffect(() => {
    let alive = true;
    const read = () => api.library().then((list) => {
      if (!alive) return;
      const kept = (Array.isArray(list) ? list : []).filter((i) => !i.deleted && i.status !== "recording");
      kept.sort((a, b) => (Date.parse(b.started_at ?? "") || 0) - (Date.parse(a.started_at ?? "") || 0));
      setItems(kept.slice(0, RECENT));
    }).catch(() => alive && setItems([]));
    void read();
    const un = listenTo("library:updated", () => void read());
    return () => {
      alive = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  return items;
}

export function HomeView({ status, run, onImport, needsYou, onReview, onFailed, onActivity, onOpenRecording, onLibrary }: {
  status: AppStatus;
  run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
  onImport?: () => void;
  /** The notifications the badge counts from. */
  needsYou: readonly ActivityItem[];
  onReview: () => void;
  onFailed: () => void;
  onActivity: () => void;
  onOpenRecording: (id: string) => void;
  onLibrary: () => void;
}) {
  const recent = useRecent();
  const problems = needsYou.filter(needsAction).length;
  const viewer = isViewer(status);
  // What needs you (states, v1.6.1 Notices): a click goes there, × or a swipe closes a card until its count changes.
  const card = (id: string, count: number, icon: React.ReactNode, title: string, line: string, action: string, onOpen: () => void) => (
    <StateBanner key={id} id={`home-${id}`} state={String(count)} bare className="action-card" icon={icon} onOpen={onOpen}
      actions={<button type="button" className="btn text" onClick={onOpen}>{action}</button>}>
      <span className="action-card-main">
        <span className="action-card-title">{title}</span>
        <span className="small muted">{line}</span>
      </span>
    </StateBanner>
  );
  const cards = [
    status.pendingDecisions > 0 && card("waiting", status.pendingDecisions, <HourglassIcon size={22} />, "Keep or discard?",
      status.pendingDecisions === 1 ? "1 call is waiting for your decision" : `${status.pendingDecisions} calls are waiting for your decision`, "Review", onReview),
    status.failedUploads > 0 && card("failed", status.failedUploads, <AlertIcon size={22} />, "Uploads that need you",
      status.failedUploads === 1 ? "1 recording did not finish uploading" : `${status.failedUploads} recordings did not finish uploading`, "Open", onFailed),
    problems > 0 && card("problems", problems, <BellIcon size={22} />, problems === 1 ? "1 notification needs you" : `${problems} notifications need you`,
      "A transcript that failed, the monthly limit, or an answer to your upgrade request.", "Open", onActivity),
  ].filter(Boolean);
  return (
    <div className="home-page">
      <PageHeader crumbs={[{ label: "Home" }]} title="Home" subtitle={viewer ? "What is shared with you is under Shared." : "Record calls and voice notes, and pick up where you left off."}
        actions={!viewer && onImport && <button type="button" className="btn" onClick={onImport}><ImportIcon size={16} />Upload</button>} />
      {cards.length > 0 && (
        <section aria-labelledby="home-needs" className="home-section">
          <h2 id="home-needs" className="section-title">Needs you</h2>
          <div className="home-cards">{cards}</div>
        </section>
      )}
      {recent && recent.length > 0 && (
        <section aria-labelledby="home-recent" className="home-section">
          <div className="section-row">
            <h2 id="home-recent" className="section-title">Recent recordings</h2>
            <span className="spacer" />
            <button type="button" className="btn text compact" onClick={onLibrary}>Open the Library</button>
          </div>
          <ul className="recent-list card">
            {recent.map((item) => (
              <li key={item.id}>
                <button type="button" className="recent-row" onClick={() => onOpenRecording(item.id)}>
                  <span className={`avatar small app-${appKind(item.source_app)}`}><AppTileIcon app={item.source_app} /></span>
                  <span className="recent-title" dir="auto">{item.title ?? "Recording"}</span>
                  <span className="recent-meta small muted">{[formatDuration(item.duration_ms), item.client?.name].filter(Boolean).join(" · ")}</span>
                  <span className="item-time">{rowTime(item.started_at)}</span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}
      <section aria-label="Record" className="home-section home-record">
        <RecordView status={status} run={run} onImport={onImport} />
      </section>
    </div>
  );
}
