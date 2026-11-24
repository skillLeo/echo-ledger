// Activity in the new design: the same page today's Activity is (views/main/ActivityCentre.tsx, the same commands,
// events and rules): what needs the person first (calls waiting for Keep or Discard, uploads that stopped, problems
// and answers the badge counts), what is in progress, the quiet "Skipped 3 short calls" line with Restore, then the
// notifications by day. A notification marks itself read and opens its recording. Built from v2 parts only.
import { PanelToggle } from "../shell/NavRail";
import { useEffect, useState } from "react";
import { api, errorMessage, listenTo, type ActivityItem, type AppStatus, type PendingItem, type SkippedItem } from "../../lib/ipc";
import { dayGroup } from "../../lib/library";
import { skippedLine, skippedMeta, waitingItems, waitingSummary } from "../../lib/pending";
import { actionCount, isProblem, needsAction as asksForAction, SERVER_PROBLEM } from "../../lib/badge";
import { RECOVERED_CHIP, useRecovered } from "../../lib/recovered";
import { activitySubtitle, shortWhen, useInProgress } from "../../views/main/ActivityCentre";
import { BackToTop, Badge, Banner, Button, Card, EmptyState, Icons, List, ListRow, Loading, SkeletonRow, type IconType } from "../components";
import { ContextMenu, menuPointFor, type MenuItem } from "../components/Menu";
import { contextItems } from "../actions/contextMenus";
import { currentPlatform, popupNative } from "../actions/popup";
import type { ActionContext } from "../actions/registry";
import { useActions } from "../actions/useActions";

/** An Activity item's menu (UX-22, 3b): Open · Mark as read | Retry (an upload that stopped). */
const ACTIVITY_ITEM = ["place.open", "activity.markRead", "-", "activity.retry"] as const;

/**
 * Retry only where it retries something: an upload that stopped, in failed uploads. A transcription that failed is tried
 * again on its recording's page (Open), and minutes used up have nothing to retry.
 */
const retries = (i: Pick<ActivityItem, "kind">) => i.kind === "upload_failed";

/** An item's icon: a problem that is over is done; the server's own (server_problem) is a server; other problems the error. */
const iconOf = (i: Pick<ActivityItem, "kind" | "resolved">): IconType =>
  i.resolved && isProblem(i.kind) ? Icons.ReadyIcon
    : i.kind === SERVER_PROBLEM ? Icons.ServerIcon
      : isProblem(i.kind) ? Icons.ErrorIcon : i.kind === "transcript_ready" ? Icons.CheckIcon : i.kind === "device_paired" ? Icons.LaptopIcon : Icons.BellIcon;

export function ActivityV2({ status, show = "all", onOpenRecording, onReview, onFailed, onItems, say }: {
  status: AppStatus;
  /** What the panel chose: everything, only what needs the person, or only what is done. */
  show?: "all" | "needs" | "done";
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

  // An item's right-click menu (UX-22), from actions.json: only what fits it (Open a recording's; Mark as read while
  // unread; Retry an upload that stopped, where failed uploads are retried). Notification settings… and Remove wait for
  // their way in.
  const [menu, setMenu] = useState<{ at: { x: number; y: number }; label: string; items: MenuItem[] } | null>(null);
  const itemOf = (c: ActionContext) => (items ?? []).find((i) => i.id === c.targets?.[0]);
  useActions("activity", {
    "place.open": (c) => { const i = itemOf(c); if (i) void choose(i); },
    "activity.markRead": (c) => {
      const i = itemOf(c);
      if (!i || i.read) return;
      void api.markActivityRead([i.id]).catch(() => null);
      setItems((list) => list?.map((x) => (x.id === i.id ? { ...x, read: true } : x)) ?? null);
    },
    "activity.retry": () => onFailed(),
  });
  const itemMenu = (i: ActivityItem): MenuItem[] =>
    contextItems(ACTIVITY_ITEM, { platform: currentPlatform(), targets: [i.id], kind: "activity" })
      .filter((m) => (m.action !== "place.open" || !!i.session_id) && (m.action !== "activity.markRead" || !i.read) && (m.action !== "activity.retry" || retries(i)));
  const openMenu = (i: ActivityItem, at: { x: number; y: number }) => {
    const shown = itemMenu(i);
    if (!shown.length) return;
    if (currentPlatform() !== "macos") return setMenu({ at, label: i.title || "Activity", items: shown });
    void popupNative(shown, at).then((ok) => { if (!ok) setMenu({ at, label: i.title || "Activity", items: shown }); });
  };

  const row = (i: ActivityItem) => (
    <ListRow key={i.id} icon={iconOf(i)} title={i.title ?? ""} strong={!i.read}
      meta={[i.session_id && recovered.has(i.session_id) ? RECOVERED_CHIP : null, i.body].filter(Boolean).join(" · ")}
      badge={!i.read ? <Badge tone="accent">Unread</Badge> : undefined}
      endTop={i.at ? shortWhen(i.at) : undefined} onClick={() => void choose(i)}
      onContextMenu={(e) => openMenu(i, "clientX" in e && (e.clientX || e.clientY) ? { x: e.clientX, y: e.clientY } : menuPointFor(e.currentTarget))} />
  );

  return (
    <div className="el-page el-page--narrow el-back-top-room" data-v2="activity">
      <header className="el-page__head">
        <PanelToggle />
        <div className="el-page__titles">
          <h1 className="el-title-l">Activity</h1>
          <p className="el-secondary" aria-live="polite">{activitySubtitle(needsYou, unread)}</p>
        </div>
        {unread > 0 && <div className="el-page__actions"><Button variant="quiet" icon={Icons.CheckIcon} onClick={() => void readAll()}>Mark all read</Button></div>}
      </header>

      {show !== "done" && (waiting || status.failedUploads > 0 || needsAction.length > 0) && (
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

      {show !== "needs" && moving.length > 0 && (
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

      {show !== "needs" && skipped.length > 0 && (
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

      {show === "needs" && !(waiting || status.failedUploads > 0 || needsAction.length > 0) && items && (
        <EmptyState icon={Icons.ReadyIcon} title="Nothing needs you" body="Calls to decide, uploads that failed and anything else that needs you shows up here." />
      )}
      {show !== "needs" && groups.map((g) => (
        <section key={g.label} className="el-group" aria-label={g.label}>
          <h2 className="el-caption el-body-strong el-group__title">{g.label}</h2>
          <Card><List label={g.label} className="el-group__rows">{g.items.map(row)}</List></Card>
        </section>
      ))}
      <BackToTop first=".el-row__hit" />
      {menu && <ContextMenu label={menu.label} items={menu.items} at={menu.at} onClose={() => setMenu(null)} />}
    </div>
  );
}
