// Activity in the new design: the same page today's Activity is (views/main/ActivityCentre.tsx, the same commands,
// events and rules): what needs the person first (calls waiting for Keep or Discard, uploads that stopped, problems
// and answers the badge counts), what is in progress, the quiet "Skipped 3 short calls" line with Restore, then the
// notifications by day. A notification marks itself read and opens its recording. Built from v2 parts only.
// UX-08 (2.0.0-r): the server keeps one item per recording and kind ("3 times" on a repeated one), and uploads that didn't
// finish are one line, "3 recordings didn't finish uploading · Retry all", which opens to show each one.
import { PanelToggle } from "../shell/NavRail";
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, listenTo, type ActivityGroup, type ActivityItem, type AppStatus, type PendingItem, type RetryAllAnswer, type SkippedItem, type UploadItem } from "../../lib/ipc";
import { dayGroup } from "../../lib/library";
import { skippedLine, skippedMeta, waitingItems, waitingSummary } from "../../lib/pending";
import { actionCount, isProblem, needsAction as asksForAction, repeatWords, SERVER_PROBLEM, uploadGroupTitle } from "../../lib/badge";
import { RECOVERED_CHIP, useRecovered } from "../../lib/recovered";
import { activitySubtitle, shortWhen, useInProgress } from "../../views/main/ActivityCentre";
import { BackToTop, Badge, Banner, Button, Card, EmptyState, Icons, List, ListRow, Loading, SkeletonRow, type IconType } from "../components";
import { ActorAvatar } from "./ActorAvatar";
import { ContextMenu, menuPointFor, type MenuItem } from "../components/Menu";
import { contextItems } from "../actions/contextMenus";
import { currentPlatform, popupNative } from "../actions/popup";
import type { ActionContext } from "../actions/registry";
import { useActions } from "../actions/useActions";

/** An Activity item's menu (UX-22, 3b): Open · Mark as read | Retry (an upload that stopped). */
const ACTIVITY_ITEM = ["place.open", "activity.markRead", "-", "activity.retry"] as const;

/**
 * Retry only where it retries something: an upload that stopped, in failed uploads, until it has reached the server
 * (resolved: "Uploaded"). A transcription that failed is tried again on its recording's page (Open), and minutes used up
 * have nothing to retry.
 */
const retries = (i: Pick<ActivityItem, "kind" | "resolved">) => i.kind === "upload_failed" && !i.resolved;

/** The only group (2.0.0-r): uploads that didn't finish. */
const UPLOADS = "upload_failed";

/** What Retry all says it did: here and on the person's other devices; or only here, offline (a network error) or with
 * the server not answering as it should. */
export function retryAllWords(answer: Pick<RetryAllAnswer, "retry_requested_at" | "here" | "others">): string {
  if (!answer.retry_requested_at) {
    return answer.others === "offline" ? "Trying again on this computer. You're offline, so your other devices weren't asked."
      : "Trying again on this computer. Couldn't reach your other devices. Try again.";
  }
  return answer.here > 0 ? "Trying again. Your other devices try theirs too." : "Asked your other devices to try again.";
}

/** This computer's line for its own failed uploads (Open: where each can be retried or discarded). */
const localWords = (n: number) => (n === 1 ? "1 recording did not finish uploading" : `${n} recordings did not finish uploading`);

/** An item's icon: a problem that is over is done; the server's own (server_problem) is a server; other problems the error. */
const iconOf = (i: Pick<ActivityItem, "kind" | "resolved">): IconType =>
  i.resolved && isProblem(i.kind) ? Icons.ReadyIcon
    : i.kind === SERVER_PROBLEM ? Icons.ServerIcon
      : isProblem(i.kind) ? Icons.ErrorIcon : i.kind === "transcript_ready" ? Icons.CheckIcon : i.kind === "device_paired" ? Icons.LaptopIcon : Icons.BellIcon;

export function ActivityV2({ status, show = "all", needsYou: badge, onOpenRecording, onReview, onFailed, onItems, say }: {
  status: AppStatus;
  /** The rail's number (UX-08: the server's, the same on the phone and the web console); counted here without it. */
  needsYou?: number;
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

  // The groups (2.0.0-r); null while they can't be read (offline, an older server): failed uploads are then this
  // computer's own line, as before.
  const [serverGroups, setServerGroups] = useState<ActivityGroup[] | null>(null);
  const [groupOpen, setGroupOpen] = useState(false);
  const [groupItems, setGroupItems] = useState<ActivityItem[] | null>(null);
  const [retrying, setRetrying] = useState(false);
  const groupOpenRef = useRef(false);
  groupOpenRef.current = groupOpen;
  const readGroup = () =>
    api.activityGroupItems(UPLOADS).then((r) => setGroupItems(Array.isArray(r?.data) ? r.data : []), () => setGroupItems(null));

  // This computer's failed uploads (its own, not another account's): Open leads to where each is retried or discarded.
  const [localFailed, setLocalFailed] = useState<UploadItem[]>([]);
  const readUploads = () =>
    api.uploadList().then((u) => setLocalFailed((Array.isArray(u) ? u : []).filter((i) => i.state === "failed" && !i.heldFor)), () => undefined);
  const needsHeading = useRef<HTMLHeadingElement>(null);
  const pageTitle = useRef<HTMLHeadingElement>(null);
  const retried = useRef(false);

  const load = () => {
    api.activityGroups().then((r) => setServerGroups(Array.isArray(r?.data) ? r.data : []), () => setServerGroups(null));
    void readUploads();
    if (groupOpenRef.current) void readGroup();
    return api.activity().then((r) => {
      setItems(r.data ?? []);
      setError(null);
    }, (e) => setError(errorMessage(e)));
  };

  useEffect(() => {
    void load();
    api.pendingList().then((p) => setPending(Array.isArray(p) ? p : [])).catch(() => undefined);
    api.skippedList().then((s) => setSkipped(Array.isArray(s) ? s : [])).catch(() => undefined);
    const subs = [
      listenTo("activity:updated", () => void load()),
      listenTo("uploads:changed", () => void readUploads()),
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
  /** Marks one read (its Mark read, its menu's Mark as read): never by being shown, so a problem stays until it is seen to. */
  const markRead = (item: ActivityItem) => {
    if (item.read) return;
    void api.markActivityRead([item.id]).catch(() => null);
    setItems((list) => list?.map((x) => (x.id === item.id ? { ...x, read: true } : x)) ?? null);
  };
  const readAll = async () => {
    await api.markActivityRead().catch(() => null);
    setItems((list) => list?.map((i) => ({ ...i, read: true })) ?? null);
  };

  // Uploads that didn't finish, as one line (with at least one unresolved): its items are inside it, not on their own.
  const uploads = serverGroups?.find((g) => g.group === UPLOADS && g.count > 0) ?? null;
  const inGroup = (i: ActivityItem) => !!uploads && i.group === UPLOADS && !i.resolved;
  const toggleGroup = () => {
    if (!groupOpen) void readGroup();
    setGroupOpen(!groupOpen);
  };
  useEffect(() => {
    const afterRetry = retried.current;
    retried.current = false;
    if (uploads) return;
    setGroupOpen(false);
    // Retry all emptied the group: its line (and the button that had focus) went, so focus goes to what heads the
    // section, or the page's title when the whole section went with it.
    const lost = !document.activeElement || document.activeElement === document.body;
    if (afterRetry && lost) (needsHeading.current ?? pageTitle.current)?.focus();
  }, [uploads]);
  // This computer's own failed uploads: in the group when the server lists their recordings there (the line inside it
  // leads to them), else on their own line as before.
  const localInGroup = uploads ? localFailed.filter((u) => !!u.sessionId && uploads.session_ids.includes(u.sessionId)).length : 0;
  const localOutside = uploads ? localFailed.length - localInGroup : status.failedUploads;
  const retryAll = async () => {
    if (retrying) return;
    setRetrying(true);
    try {
      say?.(retryAllWords(await api.activityRetryAll(UPLOADS)));
      // The groups read next are the ones after it (see the effect above).
      retried.current = true;
      void load();
    } catch (e) {
      say?.(errorMessage(e), true);
    } finally {
      setRetrying(false);
    }
  };
  // Opened: the group's items as the server lists them, else those of the list read here.
  const shownItems = groupItems ?? (items ?? []).filter(inGroup);

  const unread = (items ?? []).filter((i) => !i.read).length;
  const needsAction = (items ?? []).filter((i) => asksForAction(i) && !inGroup(i));
  const rest = (items ?? []).filter((i) => !needsAction.includes(i) && !inGroup(i));
  const groups: { label: string; items: ActivityItem[] }[] = [];
  for (const item of rest) {
    const label = dayGroup(item.at ?? undefined) === "Today" ? "Today" : "Earlier";
    const last = groups[groups.length - 1];
    if (last?.label === label) last.items.push(item);
    else groups.push({ label, items: [item] });
  }
  const waiting = pending.length > 0 || status.pendingDecisions > 0;
  const needsYou = badge ?? actionCount(items, Math.max(pending.length, status.pendingDecisions));

  // An item's right-click menu (UX-22), from actions.json: only what fits it (Open a recording's; Mark as read while
  // unread; Retry an upload that stopped, where failed uploads are retried). Notification settings… and Remove wait for
  // their way in.
  const [menu, setMenu] = useState<{ at: { x: number; y: number }; label: string; items: MenuItem[] } | null>(null);
  const itemOf = (c: ActionContext) => (items ?? []).find((i) => i.id === c.targets?.[0]);
  useActions("activity", {
    "place.open": (c) => { const i = itemOf(c); if (i) void choose(i); },
    "activity.markRead": (c) => {
      const i = itemOf(c);
      if (i) markRead(i);
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

  // D-233 (classic's D-066): only a notification about a recording opens something, so only it is a button. One with
  // nowhere to go (a new device, the server's own problem, a failure with no recording) is a plain row, out of the Tab
  // order; while unread it ends with a quiet Mark read, so a problem can be cleared on its own, never just by being seen.
  // An item about a person (contract 2.0.0-aa) shows them in the icon's place.
  const row = (i: ActivityItem) => (
    <ListRow key={i.id} icon={iconOf(i)} leading={i.actor ? <ActorAvatar actor={i.actor} /> : undefined} title={i.title ?? ""} strong={!i.read}
      meta={[i.session_id && recovered.has(i.session_id) ? RECOVERED_CHIP : null, i.body, repeatWords(i.repeat_count)].filter(Boolean).join(" · ")}
      badge={!i.read ? <Badge tone="accent">Unread</Badge> : undefined}
      endTop={i.at ? shortWhen(i.at) : undefined} onClick={i.session_id ? () => void choose(i) : undefined}
      control={!i.session_id && !i.read
        ? <Button size="sm" variant="quiet" aria-label={`Mark read: ${i.title ?? "Activity"}`} onClick={() => markRead(i)}>Mark read</Button> : undefined}
      onContextMenu={(e) => openMenu(i, "clientX" in e && (e.clientX || e.clientY) ? { x: e.clientX, y: e.clientY } : menuPointFor(e.currentTarget))} />
  );

  return (
    <div className="el-page el-page--narrow el-back-top-room" data-v2="activity">
      <header className="el-page__head">
        <PanelToggle />
        <div className="el-page__titles">
          <h1 className="el-title-l" ref={pageTitle} tabIndex={-1}>Activity</h1>
          <p className="el-secondary" aria-live="polite" dir="auto">{activitySubtitle(needsYou, unread)}</p>
        </div>
        {unread > 0 && <div className="el-page__actions"><Button variant="quiet" icon={Icons.CheckIcon} onClick={() => void readAll()}>Mark all read</Button></div>}
      </header>

      {show !== "done" && (waiting || !!uploads || localOutside > 0 || needsAction.length > 0) && (
        <section className="el-group" aria-labelledby="activity-needs">
          <h2 id="activity-needs" className="el-caption el-body-strong el-group__title" ref={needsHeading} tabIndex={-1}>Needs action</h2>
          <Card>
            <List label="Needs action" className="el-group__rows">
              {waiting && (
                <ListRow icon={Icons.ClockIcon} title="Transcribe or delete?"
                  meta={pending.length > 0 ? waitingSummary(pending) : `${status.pendingDecisions} waiting for your decision`}
                  control={<Button size="sm" onClick={() => onReview(pending[0]?.id ?? null)}>Review</Button>} />
              )}
              {uploads ? (
                <>
                  <ListRow icon={Icons.ErrorIcon} title={uploadGroupTitle(uploads.count)} strong={uploads.unread > 0}
                    endTop={uploads.at ? shortWhen(uploads.at) : undefined} expanded={groupOpen} chevron onClick={toggleGroup}
                    actionsShown="always" actions={(
                      <Button size="sm" loading={retrying} loadingLabel="Retrying…" onClick={() => void retryAll()}>{uploads.count === 1 ? "Retry" : "Retry all"}</Button>
                    )} />
                  {groupOpen && (
                    <li className="el-activity__group" data-testid="upload-group">
                      <List label={uploadGroupTitle(uploads.count)}>
                        {shownItems.map(row)}
                        {localInGroup > 0 && (
                          <ListRow icon={Icons.LaptopIcon} title={localInGroup === 1 ? "1 is on this computer" : `${localInGroup} are on this computer`}
                            meta="Open them to retry or discard each one" control={<Button size="sm" onClick={onFailed}>Open</Button>} />
                        )}
                      </List>
                    </li>
                  )}
                </>
              ) : null}
              {localOutside > 0 && (
                <ListRow icon={Icons.ErrorIcon} title="Uploads that need you" meta={localWords(localOutside)}
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

      {show === "needs" && !(waiting || !!uploads || localOutside > 0 || needsAction.length > 0) && items && (
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
