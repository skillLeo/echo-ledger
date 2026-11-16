import type { ReactNode } from "react";

export type Tone = "neutral" | "accent" | "success" | "warning" | "danger";

/** A short label in a soft tone; at most one per row, never an emoji. */
export function Badge({ tone = "neutral", children }: { tone?: Tone; children: ReactNode }) {
  return <span className={`el-badge${tone !== "neutral" ? ` el-badge--${tone}` : ""}`}>{children}</span>;
}

export type StatusKind = "ready" | "setup" | "recording" | "idle";

/**
 * A status line with its dot: Ready, Needs setup, Recording 02:14. The words carry the meaning; the dot only repeats
 * it. Only the recording dot moves, and not under Reduce motion. `live` announces changes (the app's one status strip).
 */
export function Status({ kind, children, live }: { kind: StatusKind; children: ReactNode; live?: boolean }) {
  return (
    <span className={`el-status el-status--${kind}`} role={live ? "status" : undefined}>
      <span className="el-status__dot" aria-hidden="true" />
      <span>{children}</span>
    </span>
  );
}
