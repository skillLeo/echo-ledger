// The top of the Library when Library is where the app starts (PLAN_V2_PARITY 2: Home is removed as a tab): the
// get-started checklist as a card that can be hidden, until setup is done. What needs action lives in one place, the
// quiet button in the Library's header (NeedsButton, before Trash), from the same rules as Home's (home/HomeV2.tsx
// needsAction): it shows whenever something needs action and goes when nothing does.
import { useEffect, useState } from "react";
import { api, type AppStatus, type LibraryItem, type SkippedItem } from "../../lib/ipc";
import { useReadyReport } from "../../views/main/ReadyCheck";
import { accessOf, usePermissions } from "../../views/main/Permissions";
import { permissionKinds } from "../setup/SetupHealth";
import { checklist, needsAction, NEEDS_ICONS, type ChecklistItem, type NeedsAction } from "../home/HomeV2";
import { Button, Card, Icon, Icons } from "../components";
import { Menu } from "../components/Menu";
import { isUntranscribed } from "./model";

const HIDDEN_KEY = "echoledger-library-setup-hidden";
const readHidden = () => {
  try {
    return window.localStorage.getItem(HIDDEN_KEY) === "1";
  } catch {
    return false;
  }
};

/**
 * Where the Library's needs-action banner, now gone, kept what its × dismissed: one key per account under this prefix.
 * Nothing reads it any more; it is cleared once so an old dismissal can never hide anything.
 */
export const DISMISSED_PREFIX = "echoledger-library-needs-action-dismissed";
export function forgetDismissed(): void {
  try {
    const store = window.localStorage;
    const stale: string[] = [];
    for (let i = 0; i < store.length; i++) {
      const key = store.key(i);
      if (key?.startsWith(DISMISSED_PREFIX)) stale.push(key);
    }
    stale.forEach((key) => store.removeItem(key));
  } catch {
    // Storage can be unavailable: then there is nothing stored to clear.
  }
}

/** What the queue under the header already shows with its own Review (waiting calls, uploads that need you). */
export type QueueCovers = { waiting: boolean; failed: boolean };

/** How long a change in the waiting calls that only one side has reported waits for the other (see useHeldWaiting). */
export const WAITING_HOLD_MS = 1000;

/**
 * The waiting calls the header counts: the status's, unless the queue shows them with its own Review. The core reports
 * a call that starts waiting, or is decided, twice, one after the other: in its status and in the queue's list
 * (`list`, a new list each time it says anything). A change only one of them has reported is held until the other
 * reports too, or WAITING_HOLD_MS, whichever is first, so the button never shows "1 waiting" for the moment between
 * them (and the header's actions don't fold and unfold for it).
 */
function useHeldWaiting(status: number, list: unknown, covered: boolean | null): number {
  const now = covered ? 0 : status;
  const ready = covered !== null;
  const [held, setHeld] = useState({ shown: now, status, list, ready });
  const statusMoved = status !== held.status;
  const listMoved = list !== held.list;
  // Before the queue has answered (and as it does), or when both have moved or neither: what they say now.
  const settled = !ready || !held.ready || statusMoved === listMoved;
  useEffect(() => {
    const next = { shown: now, status, list, ready };
    if (settled) {
      if (held.shown !== now || statusMoved || listMoved || held.ready !== ready) setHeld(next);
      return;
    }
    const timer = window.setTimeout(() => setHeld(next), WAITING_HOLD_MS);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settled, now, status, list, ready]);
  return settled ? now : held.shown;
}

/**
 * What needs action in the Library, for the header's button: null while the counts load (they read 0 until the
 * recordings, the skipped calls and the queue's lists are in, so the button never shows a count it then corrects), and
 * when nothing does. What the queue shows with its own Review (`covered`, null until its lists have answered) is left
 * out, so there is one Review for each thing; a waiting call only the status or only the queue's list (`waitingList`)
 * has reported yet is held for the other (useHeldWaiting). Without a status (the Library is not the start screen)
 * there is no button.
 */
export function useLibraryNeeds(status: AppStatus | null, items: readonly LibraryItem[] | null, covered: QueueCovers | null, waitingList?: unknown): readonly NeedsAction[] | null {
  const [skipped, setSkipped] = useState<SkippedItem[] | null>(null);
  const waiting = useHeldWaiting(status?.pendingDecisions ?? 0, waitingList, covered ? covered.waiting : null);
  const on = status !== null;
  useEffect(() => {
    if (!on) return;
    forgetDismissed();
    let live = true;
    api.skippedList().then((s) => live && setSkipped(Array.isArray(s) ? s : []), () => live && setSkipped([]));
    return () => {
      live = false;
    };
  }, [on]);
  if (!status || items === null || skipped === null || covered === null) return null;
  const actions = needsAction({
    failed: covered.failed ? 0 : status.failedUploads,
    waiting,
    untranscribed: items.filter((i) => !i.deleted && isUntranscribed(i)).length,
    missed: skipped.length,
  });
  return actions.length > 0 ? actions : null;
}

export type LibraryTopProps = {
  status: AppStatus;
  items: readonly LibraryItem[] | null;
  onSetup: () => void;
  onReadyCheck: () => void;
  onRecord?: () => void;
};

export function LibraryTop({ status, items, onSetup, onReadyCheck, onRecord }: LibraryTopProps) {
  const [report] = useReadyReport();
  const [permissions] = usePermissions();
  const [hidden, setHidden] = useState(readHidden);
  const live = (items ?? []).filter((i) => !i.deleted);
  const kinds = permissionKinds(status.platform);
  const permissionsOk = permissions ? kinds.every((k) => accessOf(permissions, k) === "allowed") : null;
  const known = permissions !== null && (report !== null || status.callsReady != null) && items !== null;
  const steps = known && !hidden ? checklist({ permissionsOk, ready: report?.ready ?? status.callsReady ?? null, recordings: live.length }) : null;
  const run = (id: ChecklistItem["id"]) => (id === "permissions" ? onSetup() : id === "ready" ? onReadyCheck() : onRecord?.());
  const hide = () => {
    setHidden(true);
    try {
      window.localStorage.setItem(HIDDEN_KEY, "1");
    } catch {
      // Storage can be unavailable: hidden until the window closes.
    }
  };
  if (!steps) return null;
  return (
    <div className="el-library-top">
      <Card className="el-library-setup" label="Get started">
        <div className="el-group__head">
          <h2 className="el-body-strong">Get started</h2>
          <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label="Hide Get started" onClick={hide} />
        </div>
        <ol className="el-steps" aria-label="Get started">
          {steps.map((item, i) => (
            <li key={item.id} className={`el-step${item.done ? " el-step--done" : ""}`}>
              <span className="el-step__number" aria-hidden="true">{item.done ? <Icon icon={Icons.CheckIcon} /> : i + 1}</span>
              <div className="el-step__main el-home-check">
                <span className="el-step__title">{item.title}</span>
                <span className="el-caption">{item.done ? "Done" : "To do"}</span>
              </div>
              {!item.done && <Button size="sm" onClick={() => run(item.id)}>{item.action}</Button>}
            </li>
          ))}
        </ol>
      </Card>
    </div>
  );
}

/** The header button's words for one kind: "5 not transcribed". */
const SHORT: Record<NeedsAction["id"], (n: number) => string> = {
  failed: () => "didn't upload",
  waiting: () => "waiting",
  untranscribed: () => "not transcribed",
  missed: () => "skipped",
  notifications: (n) => (n === 1 ? "notification" : "notifications"),
};

/** The count and the words beside it: "5" and "not transcribed", or "7" and "need you" for several kinds. */
export function needsLabel(actions: readonly NeedsAction[]): { count: number; words: string } {
  if (actions.length === 1) return { count: actions[0].count, words: SHORT[actions[0].id](actions[0].count) };
  const count = actions.reduce((n, a) => n + a.count, 0);
  return { count, words: count === 1 ? "needs you" : "need you" };
}

/** The small attention dot (the accent, as the sidebar's counts that need you) where the button's icon would be. */
function AttentionDot({ className }: { className?: string }) {
  return <span className={["el-attention-dot", className].filter(Boolean).join(" ")} />;
}

/**
 * What needs action in the Library, as one quiet button in its header, the only place the Library says it: one kind
 * opens what Review opens ("5 not transcribed"); several open a menu of them ("7 need you"). A small attention dot
 * leads it. It always keeps its words: where the header runs out of room, its least-used actions fold into More
 * instead (headerFold.ts).
 */
export function NeedsButton({ actions, onAction }: { actions: readonly NeedsAction[]; onAction: (id: NeedsAction["id"]) => void }) {
  const { count, words } = needsLabel(actions);
  // The count and its words read as one left-to-right piece in a right-to-left window too.
  const label = <span dir="auto">{count} {words}</span>;
  if (actions.length === 1) {
    const [a] = actions;
    return <Button variant="quiet" className="el-needs" icon={AttentionDot} title={a.title} onClick={() => onAction(a.id)}>{label}</Button>;
  }
  return (
    <Menu label="Needs action" button={{ variant: "quiet", className: "el-needs", icon: AttentionDot, iconEnd: Icons.ChevronDownIcon, text: label, title: actions.map((a) => a.title).join(" · ") }}
      items={actions.map((a) => ({ label: a.title, icon: NEEDS_ICONS[a.id], onPick: () => onAction(a.id) }))} />
  );
}
