// The top of the Library when Library is where the app starts (PLAN_V2_PARITY 2: Home is removed as a tab): the
// get-started checklist as a card that can be hidden, until setup is done, and what needs action as one quiet banner.
// The same rules as Home's (home/HomeV2.tsx checklist and needsAction), from the same data. The banner's × hides it
// until the situation changes: a count goes up or a new kind of notice appears; fewer things to fix keeps it hidden.
import { useEffect, useMemo, useState } from "react";
import { api, type AppStatus, type LibraryItem, type SkippedItem } from "../../lib/ipc";
import { useReadyReport } from "../../views/main/ReadyCheck";
import { accessOf, usePermissions } from "../../views/main/Permissions";
import { useAccount } from "../../views/main/AccountSettings";
import { permissionKinds } from "../setup/SetupHealth";
import { checklist, needsAction, type ChecklistItem, type NeedsAction } from "../home/HomeV2";
import { Banner, Button, Card, Icon, Icons } from "../components";
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

/** What the needs-action banner said when it was dismissed: each kind and its count. */
export type DismissedSignature = Partial<Record<NeedsAction["id"], number>>;
const DISMISSED_KEY = "echoledger-library-needs-action-dismissed";
/** One key per account (server and email: the core gives no account id), so another account starts with the banner. */
export const dismissedKey = (serverUrl: string | null | undefined, email: string) =>
  `${DISMISSED_KEY}:${serverUrl ?? ""}:${email.trim().toLowerCase()}`;
const readDismissed = (key: string): DismissedSignature | null => {
  try {
    const raw = window.localStorage.getItem(key);
    const value: unknown = raw ? JSON.parse(raw) : null;
    return value && typeof value === "object" && !Array.isArray(value) ? (value as DismissedSignature) : null;
  } catch {
    return null;
  }
};
const writeDismissed = (key: string | null, signature: DismissedSignature | null) => {
  if (!key) return;
  try {
    if (signature) window.localStorage.setItem(key, JSON.stringify(signature));
    else window.localStorage.removeItem(key);
  } catch {
    // Storage can be unavailable: dismissed until the window closes.
  }
};
const signatureOf = (actions: readonly NeedsAction[]): DismissedSignature =>
  Object.fromEntries(actions.map((a) => [a.id, a.count]));
/** Still dismissed while every kind shown was in the dismissed banner and has not gone up since. */
export function stillDismissed(dismissed: DismissedSignature | null, actions: readonly NeedsAction[]): boolean {
  return dismissed !== null && actions.every((a) => {
    const then = dismissed[a.id];
    return typeof then === "number" && a.count <= then;
  });
}

export type LibraryTopProps = {
  status: AppStatus;
  items: readonly LibraryItem[] | null;
  onSetup: () => void;
  onReadyCheck: () => void;
  onRecord?: () => void;
  onAction: (id: NeedsAction["id"]) => void;
  /** What the queue under it already shows with its own Review (waiting calls, uploads that need you): left out here. */
  covered?: { waiting: boolean; failed: boolean };
};

export function LibraryTop({ status, items, onSetup, onReadyCheck, onRecord, onAction, covered }: LibraryTopProps) {
  const [report] = useReadyReport();
  const [permissions] = usePermissions();
  const [skipped, setSkipped] = useState<SkippedItem[] | null>(null);
  const [hidden, setHidden] = useState(readHidden);
  // The account is needed for the key; without one (an older core) a dismissal lasts until the window closes.
  const [account] = useAccount();
  const storeKey = account?.email ? dismissedKey(status.serverUrl, account.email) : null;
  const stored = useMemo(() => (storeKey ? readDismissed(storeKey) : null), [storeKey]);
  const [memory, setMemory] = useState<{ key: string | null; value: DismissedSignature | null } | null>(null);
  const dismissed = memory && memory.key === storeKey ? memory.value : stored;
  const setDismissed = (value: DismissedSignature | null) => {
    setMemory({ key: storeKey, value });
    writeDismissed(storeKey, value);
  };
  useEffect(() => {
    api.skippedList().then((s) => setSkipped(Array.isArray(s) ? s : []), () => setSkipped([]));
  }, []);
  const live = (items ?? []).filter((i) => !i.deleted);
  const kinds = permissionKinds(status.platform);
  const permissionsOk = permissions ? kinds.every((k) => accessOf(permissions, k) === "allowed") : null;
  const known = permissions !== null && (report !== null || status.callsReady != null) && items !== null;
  const steps = known && !hidden ? checklist({ permissionsOk, ready: report?.ready ?? status.callsReady ?? null, recordings: live.length }) : null;
  const counts = {
    failed: status.failedUploads,
    waiting: status.pendingDecisions,
    untranscribed: live.filter(isUntranscribed).length,
    missed: skipped?.length ?? 0,
  };
  const actions = needsAction({
    ...counts,
    failed: covered?.failed ? 0 : counts.failed,
    waiting: covered?.waiting ? 0 : counts.waiting,
  });
  // What a dismissal remembers: every kind and count, also the ones the queue shows for now (it reports that only once
  // it has loaded, so they can be in the banner for a moment after a restart).
  const all = signatureOf(needsAction(counts));
  // Counts read 0 until the recordings and skipped calls load: only settled counts move what was dismissed.
  const settled = items !== null && skipped !== null && account !== undefined;
  const quiet = stillDismissed(dismissed, actions);
  const key = JSON.stringify([all, actions.map((a) => a.id)]);
  useEffect(() => {
    if (!settled || dismissed === null) return;
    // Went up or a new kind: show again. Went down: stay dismissed, from the lower counts, so a later rise shows.
    const lower = Object.fromEntries(Object.entries(all).flatMap(([id, n]) => {
      const then = dismissed[id as NeedsAction["id"]];
      return typeof then === "number" ? [[id, Math.min(then, n ?? 0)]] : [];
    })) as DismissedSignature;
    const next = quiet && Object.keys(lower).length > 0 ? lower : null;
    if (JSON.stringify(next) === JSON.stringify(dismissed)) return;
    setDismissed(next);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settled, quiet, key, storeKey]);
  const dismiss = () => {
    setDismissed(all);
  };
  const run = (id: ChecklistItem["id"]) => (id === "permissions" ? onSetup() : id === "ready" ? onReadyCheck() : onRecord?.());
  const hide = () => {
    setHidden(true);
    try {
      window.localStorage.setItem(HIDDEN_KEY, "1");
    } catch {
      // Storage can be unavailable: hidden until the window closes.
    }
  };
  // Until the account is known the banner waits, so a dismissed one never flashes.
  const banner = actions.length > 0 && !quiet && account !== undefined;
  if (!steps && !banner) return null;
  return (
    <div className="el-library-top">
      {steps && (
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
      )}
      {banner && (
        <Banner icon={Icons.InfoIcon} testId="needs-action" onClose={dismiss} closeLabel="Dismiss"
          action={actions.length === 1 ? <Button size="sm" onClick={() => onAction(actions[0].id)}>Review</Button>
            : <Menu label="Needs action" button={{ size: "sm", text: "Review", iconEnd: Icons.ChevronDownIcon }}
              items={actions.map((a) => ({ label: a.title, onPick: () => onAction(a.id) }))} />}>
          {actions.length === 1 ? actions[0].title : `${actions[0].title} · ${actions.length - 1} more need${actions.length === 2 ? "s" : ""} you`}
        </Banner>
      )}
    </div>
  );
}
