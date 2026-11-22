// The top of the Library when Library is where the app starts (PLAN_V2_PARITY 2: Home is removed as a tab): the
// get-started checklist as a card that can be hidden, until setup is done, and what needs action as one quiet banner.
// The same rules as Home's (home/HomeV2.tsx checklist and needsAction), from the same data. The banner's × hides it
// until the situation changes: a count goes up or a new kind of notice appears; fewer things to fix keeps it hidden.
// While it is hidden and something still needs action, NeedsButton keeps it in the Library's header, before Trash.
import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from "react";
import { api, type AppStatus, type LibraryItem, type SkippedItem } from "../../lib/ipc";
import { useReadyReport } from "../../views/main/ReadyCheck";
import { accessOf, usePermissions } from "../../views/main/Permissions";
import { useAccount } from "../../views/main/AccountSettings";
import { permissionKinds } from "../setup/SetupHealth";
import { checklist, needsAction, NEEDS_ICONS, type ChecklistItem, type NeedsAction } from "../home/HomeV2";
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
  /** What still needs action while the banner is dismissed (null otherwise, and once this is gone): the header's button. */
  onQuiet?: (actions: readonly NeedsAction[] | null) => void;
};

export function LibraryTop({ status, items, onSetup, onReadyCheck, onRecord, onAction, covered, onQuiet }: LibraryTopProps) {
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
  // Dismissed with something left: the header's button, from settled counts only. Reported before the paint, so the
  // banner and the button are never on screen together.
  const kept = settled && actions.length > 0 && quiet ? actions : null;
  const keptKey = JSON.stringify(kept);
  useLayoutEffect(() => {
    onQuiet?.(kept);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [keptKey]);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useLayoutEffect(() => () => onQuiet?.(null), []);
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

/**
 * Whether the button's words would give the header (the titles, then the actions beside them or on rows of their own)
 * a line it doesn't have without the button: then it shows its count alone.
 */
function wordsAddALine(button: HTMLElement, words: HTMLElement): boolean {
  const own = button.closest(".el-page__actions > *");
  const actions = own?.parentElement;
  const head = actions?.parentElement;
  const titles = head?.querySelector(":scope > .el-page__titles");
  if (!own || !actions || !head || !titles) return false;
  const px = (value: string) => parseFloat(value) || 0;
  const gap = px(getComputedStyle(actions).columnGap);
  const others = [...actions.children].filter((c) => c !== own).map((c) => c.getBoundingClientRect().width);
  const othersWidth = others.reduce((sum, w) => sum + w + gap, 0);
  // Shown, the words are as wide as they are drawn; hidden, they keep their full scroll width.
  const hidden = words.classList.contains("el-visually-hidden");
  const wordsWidth = hidden ? words.scrollWidth : words.getBoundingClientRect().width;
  const now = own.getBoundingClientRect().width;
  const full = hidden ? now + wordsWidth : now;
  const room = head.clientWidth + 0.5;
  const beside = px(getComputedStyle(titles).flexBasis) + px(getComputedStyle(head).columnGap);
  // The row of actions with the button (its width and a gap), or without it (0).
  const lines = (width: number) => {
    const row = width > 0 ? othersWidth + width : othersWidth - gap;
    return beside + row <= room ? 1 : row <= room ? 2 : 3;
  };
  return lines(full) > lines(0);
}

/**
 * What still needs action once the banner is dismissed, as one quiet button in the Library's header: one kind opens
 * what Review opens ("5 not transcribed"); several open a menu of them ("7 need you"). Where its words would put the
 * header on another line it shows its icon and count alone; its name and tooltip keep the words.
 */
export function NeedsButton({ actions, onAction }: { actions: readonly NeedsAction[]; onAction: (id: NeedsAction["id"]) => void }) {
  const id = useId();
  const words = useRef<HTMLSpanElement>(null);
  const [compact, setCompact] = useState(false);
  const { count, words: text } = needsLabel(actions);
  useLayoutEffect(() => {
    const button = document.getElementById(id);
    if (!button || !words.current) return;
    const fit = () => words.current && setCompact(wordsAddALine(button, words.current));
    fit();
    const head = button.closest(".el-page__head");
    if (!head) return;
    if (typeof ResizeObserver === "function") {
      // Only a new width counts: the header's height changes with the words, and that alone must not measure again.
      let width = head.clientWidth;
      const ro = new ResizeObserver(() => {
        if (head.clientWidth === width) return;
        width = head.clientWidth;
        fit();
      });
      ro.observe(head);
      return () => ro.disconnect();
    }
    window.addEventListener("resize", fit);
    return () => window.removeEventListener("resize", fit);
  }, [id, count, text]);
  const name = `${count} ${text}`;
  const label = (
    <span dir="auto">{count}<span ref={words} className={compact ? "el-visually-hidden" : undefined}> {text}</span></span>
  );
  if (actions.length === 1) {
    const [a] = actions;
    return (
      <Button id={id} variant="quiet" icon={NEEDS_ICONS[a.id]} aria-label={name} title={a.title} onClick={() => onAction(a.id)}>{label}</Button>
    );
  }
  return (
    <Menu label="Needs action" button={{ id, variant: "quiet", icon: Icons.InfoIcon, iconEnd: Icons.ChevronDownIcon, text: label,
      "aria-label": name, title: actions.map((a) => a.title).join(" · ") }}
      items={actions.map((a) => ({ label: a.title, icon: NEEDS_ICONS[a.id], onPick: () => onAction(a.id) }))} />
  );
}
