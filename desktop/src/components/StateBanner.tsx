// A banner about something still true (v1.6.1, docs/DESIGN_SYSTEM.md "Notices" 3): calls not recorded, an update to
// install, maintenance, a limit, a failed sync. It has no timer, so a problem is never hidden by itself; × or a swipe
// closes it, and it stays closed until its state changes (a new problem, another version) or the app starts again.
import { useState, type ReactNode } from "react";
import { Notice } from "./Notice";

/** The banners closed in this run: their id and the state they were closed in. */
const closed = new Map<string, string>();

/** Whether the banner `id` was closed in its current `state`, and how to close it. */
export function useClosedUntilChange(id: string, state: string): [boolean, () => void] {
  const [, redraw] = useState(0);
  return [closed.get(id) === state, () => {
    closed.set(id, state);
    redraw((n) => n + 1);
  }];
}

/** Tests start from a fresh run. */
export function resetClosedBanners(): void {
  closed.clear();
}

const TONE_CLASS = { warn: "warn", danger: "danger", info: "info", quiet: "quiet", accent: "update-banner", error: "danger", neutral: "" } as const;

export function StateBanner({ id, state, tone = "warn", icon, children, onOpen, openLabel, actions, role, testId, className = "", onClose, bare = false }: {
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
  role?: "status" | "alert";
  testId?: string;
  className?: string;
  /** Also told when it is closed (to remember it elsewhere). */
  onClose?: () => void;
  /** Keeps its own look (a card): no banner colours. */
  bare?: boolean;
}) {
  const [isClosed, close] = useClosedUntilChange(id, state);
  if (isClosed) return null;
  return (
    <Notice kind="banner" tone={tone === "accent" ? "accent" : tone} className={bare ? className : `banner ${TONE_CLASS[tone]} ${className}`.trim()} icon={icon} onOpen={onOpen} openLabel={openLabel}
      actions={actions} role={role} testId={testId} timeoutMs={null} onDismiss={() => { close(); onClose?.(); }}>
      {children}
    </Notice>
  );
}
