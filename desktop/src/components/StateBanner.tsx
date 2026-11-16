// A banner about something still true (v1.6.1, docs/DESIGN_SYSTEM.md "Notices" 3): calls not recorded, an update to
// install, maintenance, a limit, a failed sync. It has no timer, so a problem is never hidden by itself; × or a swipe
// closes it, and it stays closed until its state changes (a new problem, another version) or the app starts again.
// A banner whose state names one occurrence (a version, a maintenance window with its end, a month) is `remembered`:
// closed, it stays closed after a restart too. One whose state repeats from run to run (a share counted from 1, a
// refused version, a permission, a count) never is, or it would never come back. Above the pages only one shows at a
// time (BannerSlot).
import { useState, type ReactNode } from "react";
import { Notice } from "./Notice";
import { useBannerTurn, type BannerName } from "./BannerSlot";

const CLOSED_KEY = "echoledger-closed-banners";

/** The banners closed in this run: their id and the state they were closed in. */
const closed = new Map<string, string>();
/** The remembered ones closed in any run (kept on this computer). */
const remembered = new Map<string, string>(readClosed());

function readClosed(): [string, string][] {
  try {
    const saved = JSON.parse(window.localStorage.getItem(CLOSED_KEY) ?? "{}") as unknown;
    return saved && typeof saved === "object" ? Object.entries(saved as Record<string, unknown>).filter((e): e is [string, string] => typeof e[1] === "string") : [];
  } catch {
    return [];
  }
}

function saveClosed(): void {
  try {
    window.localStorage.setItem(CLOSED_KEY, JSON.stringify(Object.fromEntries(remembered)));
  } catch {
    // Not kept: it stays closed for this run.
  }
}

/** Whether the banner `id` was closed in its current `state`, and how to close it; `remember`: also after a restart. */
export function useClosedUntilChange(id: string, state: string, remember = false): [boolean, () => void] {
  const [, redraw] = useState(0);
  return [closed.get(id) === state || (remember && remembered.get(id) === state), () => {
    closed.set(id, state);
    if (remember) {
      remembered.set(id, state);
      saveClosed();
    }
    redraw((n) => n + 1);
  }];
}

/** A new run in tests: what was closed in this run is forgotten, what is remembered is read again. */
export function restartForTests(): void {
  closed.clear();
  remembered.clear();
  for (const [id, state] of readClosed()) remembered.set(id, state);
}

/** Tests start from a fresh run. */
export function resetClosedBanners(): void {
  closed.clear();
  remembered.clear();
  try {
    window.localStorage.removeItem(CLOSED_KEY);
  } catch {
    // Nothing kept.
  }
}

const TONE_CLASS = { warn: "warn", danger: "danger", info: "info", quiet: "quiet", accent: "update-banner", error: "danger", neutral: "" } as const;

export function StateBanner({ id, state, tone = "warn", icon, children, onOpen, openLabel, actions, details, role, testId, className = "", onClose, bare = false, slot, remember = false }: {
  /** Which banner it is (one per kind of state). */
  id: string;
  /** The state it is about: closing it lasts until this changes. */
  state: string;
  tone?: keyof typeof TONE_CLASS;
  icon?: ReactNode;
  children: ReactNode;
  /** Where it goes when clicked: the fix, the page. */
  onOpen?: () => void;
  openLabel?: string;
  actions?: ReactNode;
  /** What the sentence leaves out, behind "Learn more" (beside the sentence, never inside its button). */
  details?: ReactNode;
  role?: "status" | "alert";
  testId?: string;
  className?: string;
  /** Also told when it is closed (to remember it elsewhere). */
  onClose?: () => void;
  /** Keeps its own look (a card): no banner colours. */
  bare?: boolean;
  /** One of the main window's banners: it shows only when it is the most important one (BannerSlot). */
  slot?: BannerName;
  /** The state names one occurrence (a version, a dated window, a month): closed, it stays closed after a restart. */
  remember?: boolean;
}) {
  const [isClosed, close] = useClosedUntilChange(id, state, remember);
  const shown = useBannerTurn(slot, !isClosed);
  if (!shown) return null;
  return (
    <Notice kind="banner" tone={tone === "accent" ? "accent" : tone} className={bare ? className : `banner ${TONE_CLASS[tone]} ${className}`.trim()} icon={icon} onOpen={onOpen} openLabel={openLabel}
      actions={actions} details={details} role={role} testId={testId} timeoutMs={null} onDismiss={() => { close(); onClose?.(); }}>
      {children}
    </Notice>
  );
}
