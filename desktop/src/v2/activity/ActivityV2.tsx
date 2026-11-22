// Activity in the new design: the same page today's Activity is (views/main/ActivityCentre.tsx, the same commands,
// events and rules): what needs the person first (calls waiting for Keep or Discard, uploads that stopped, problems
// and answers the badge counts), what is in progress, the quiet "Skipped 3 short calls" line with Restore, then the
// notifications by day. A notification marks itself read and opens its recording. Built from v2 parts only.
import { useEffect, useState } from "react";
import { api, errorMessage, listenTo, type ActivityItem, type AppStatus, type PendingItem, type SkippedItem } from "../../lib/ipc";
import { dayGroup } from "../../lib/library";
import { skippedLine, skippedMeta, waitingItems, waitingSummary } from "../../lib/pending";
import { actionCount, isProblem, needsAction as asksForAction } from "../../lib/badge";
import { RECOVERED_CHIP, useRecovered } from "../../lib/recovered";
import { activitySubtitle, shortWhen, useInProgress } from "../../views/main/ActivityCentre";
import { Badge, Banner, Button, Card, EmptyState, Icons, List, ListRow, Loading, SkeletonRow, type IconType } from "../components";

const iconOf = (kind: string | null): IconType =>
  isProblem(kind) ? Icons.ErrorIcon : kind === "transcript_ready" ? Icons.CheckIcon : kind === "device_paired" ? Icons.LaptopIcon : Icons.BellIcon;

export function ActivityV2({ status, onOpenRecording, onReview, onFailed, onItems, say }: {
  status: AppStatus;
  onOpenRecording: (id: string) => void;
  /** Keep or discard? › Review: the waiting recordings in the Library, the first one in view. */
  onReview: (pendingId: string | null) => void;
  /** Uploads that need you › Open: where they can be retried. */
  onFailed: () => void;
  /** The list as it now stands (the badge is counted from it). */
  onItems?: (items: ActivityItem[]) => void;
  /** The window's message (Restore says what happened). */
  say?: (text: string, error?: boolean) => void;
}) {
  const [items, setItems] = useState<ActivityItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [all, setPending] = useState<PendingItem[]>([]);
  const pending = waitingItems(all);
  const [skipped, setSkipped] = useState<SkippedItem[]>([]);
  const [showSkipped, setShowSkipped] = useState(false);
  const [restoring, setRestoring] = useState<string | null>(null);
  const moving = useInProgress();
  const recovered = useRecovered();

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

  useEffect(() => {
    if (skipped.length === 0) setShowSkipped(false);
  }, [skipped.length]);

  useEffect(() => {
    if (items) onItems?.(items);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [items]);

  const restore = async (item: SkippedItem) => {
    if (restoring) return;
    setRestoring(item.id);
    try {
      await api.skippedRestore(item.id);
      setSkipped((list) => list.filter((s) => s.id !== item.id));
      say?.("Restored. It uploads now and shows in the Library.");
    } catch (e) {
      say?.(errorMessage(e), true);
      api.skippedList().then((s) => setSkipped(Array.isArray(s) ? s : [])).catch(() => undefined);
    } finally {
      setRestoring(null);
    }
  };

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
  const needsYou = actionCount(items, Math.max(pending.length, status.pendingDecisions));

  const row = (i: ActivityItem) => (
    <ListRow key={i.id} icon={iconOf(i.kind)} title={i.title ?? ""} strong={!i.read}
      meta={[i.session_id && recovered.has(i.session_id) ? RECOVERED_CHIP : null, i.body].filter(Boolean).join(" · ")}
      badge={!i.read ? <Badge tone="accent">Unread</Badge> : undefined}
      endTop={i.at ? shortWhen(i.at) : undefined} onClick={() => void choose(i)} />
  );

  return (
    <div className="el-page el-page--narrow" data-v2="activity">
      <header className="el-page__head">
        <div className="el-page__titles">
          <h1 className="el-title-l">Activity</h1>
          <p className="el-secondary" aria-live="polite">{activitySubtitle(needsYou, unread)}</p>
        </div>
        {unread > 0 && <div className="el-page__actions"><Button variant="quiet" icon={Icons.CheckIcon} onClick={() => void readAll()}>Mark all read</Button></div>}
      </header>

      {(waiting || status.failedUploads > 0 || needsAction.length > 0) && (
        <section className="el-group" aria-labelledby="activity-needs">
          <h2 id="activity-needs" className="el-caption el-body-strong el-group__title">Needs action</h2>
          <Card>
            <List label="Needs action" className="el-group__rows">
              {waiting && (
                <ListRow icon={Icons.ClockIcon} title="Transcribe or delete?"
                  meta={pending.length > 0 ? waitingSummary(pending) : `${status.pendingDecisions} waiting for your decision`}
                  control={<Button size="sm" onClick={() => onReview(pending[0]?.id ?? null)}>Review</Button>} />
              )}
              {status.failedUploads > 0 && (
                <ListRow icon={Icons.ErrorIcon} title="Uploads that need you"
                  meta={status.failedUploads === 1 ? "1 recording did not finish uploading" : `${status.failedUploads} recordings did not finish uploading`}
                  control={<Button size="sm" onClick={onFailed}>Open</Button>} />
              )}
              {needsAction.map(row)}
            </List>
          </Card>
        </section>
      )}

      {moving.length > 0 && (
        <section className="el-group" aria-labelledby="activity-moving" data-testid="in-progress">
          <h2 id="activity-moving" className="el-caption el-body-strong el-group__title">In progress</h2>
          <Card>
            <List label="In progress" className="el-group__rows">
              {moving.map((r) => (
                <ListRow key={r.id} icon={Icons.PendingIcon} title={r.title} meta={r.line}
                  control={r.recordingId ? <Button size="sm" variant="quiet" onClick={() => onOpenRecording(r.recordingId!)}>Open</Button> : undefined} />
              ))}
            </List>
          </Card>
        </section>
      )}

      {error && (
        <Banner tone="danger" icon={Icons.ErrorIcon} action={<Button size="sm" onClick={() => void load()}>Retry</Button>}>
          Couldn't load the activity. {error}
        </Banner>
      )}
      {items === null && !error && (
        <Loading label="Loading activity…"><Card><div className="el-group__rows">{[0, 1, 2].map((n) => <SkeletonRow key={n} />)}</div></Card></Loading>
      )}

      {skipped.length > 0 && (
        <section className="el-group" aria-label="Skipped calls" data-testid="skipped-calls">
          <div className="el-group__head">
            <span className="el-secondary">{skippedLine(skipped.length)}</span>
            <Button size="sm" variant="quiet" aria-expanded={showSkipped} aria-controls={showSkipped ? "skipped-list" : undefined}
              onClick={() => setShowSkipped((v) => !v)}>{showSkipped ? "Hide" : "Show"}</Button>
          </div>
          {showSkipped && (
            <Card>
              <div id="skipped-list">
                <List label="Skipped calls" className="el-group__rows">
                  {skipped.map((item) => {
                    const meta = skippedMeta(item);
                    return (
                      <ListRow key={item.id} compact title={meta}
                        control={<Button size="sm" variant="quiet" disabled={restoring !== null} aria-label={`Restore ${meta}`} onClick={() => void restore(item)}>Restore</Button>} />
                    );
                  })}
                </List>
              </div>
            </Card>
          )}
        </section>
      )}

      {items && items.length === 0 && !waiting && skipped.length === 0 && (
        <EmptyState icon={Icons.BellIcon} title="All caught up" body="Nothing yet. Finished transcripts, failed uploads and new devices show up here." />
      )}

      {groups.map((g) => (
        <section key={g.label} className="el-group" aria-label={g.label}>
          <h2 className="el-caption el-body-strong el-group__title">{g.label}</h2>
          <Card><List label={g.label} className="el-group__rows">{g.items.map(row)}</List></Card>
        </section>
      ))}
    </div>
  );
}
