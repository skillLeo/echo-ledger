import type { ReactNode } from "react";

export type SkeletonShape = "line" | "title" | "circle" | "block";
export type SkeletonWidth = "full" | "long" | "medium" | "short";

/** A still placeholder shape (no shimmer: the only looping animation is the recording dot). Hidden from assistive tools. */
export function Skeleton({ shape = "line", width = "full" }: { shape?: SkeletonShape; width?: SkeletonWidth }) {
  return <span className={`el-skeleton el-skeleton--${shape} el-skeleton--w-${width}`} aria-hidden="true" />;
}

/** Where content is on its way: one busy status with its words for assistive tools, and the shapes to come. */
export function Loading({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return (
    <div className={["el-loading", className].filter(Boolean).join(" ")} role="status" aria-busy="true" aria-live="polite">
      <span className="el-visually-hidden">{label}</span>
      {children}
    </div>
  );
}

/** A row still loading: the avatar circle, two lines and the time column. */
export function SkeletonRow({ end = true }: { end?: boolean }) {
  return (
    <div className="el-row el-skeleton-row" aria-hidden="true">
      <Skeleton shape="circle" />
      <span className="el-row__main el-skeleton-row__main">
        <Skeleton width="medium" />
        <Skeleton width="long" />
      </span>
      {end && <span className="el-row__end el-skeleton-row__end"><Skeleton width="full" /><Skeleton width="full" /></span>}
    </div>
  );
}

/** How far something has gone (an upload, a transcription in parts), with its words. Determinate only. */
export function Progress({ value, max = 100, label, valueText }: { value: number; max?: number; label: string; valueText?: string }) {
  const part = max > 0 ? Math.min(1, Math.max(0, value / max)) : 0;
  return (
    <div className="el-progress" role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={max} aria-valuenow={value}
      aria-valuetext={valueText}>
      <span className="el-progress__fill" style={{ inlineSize: `${Math.round(part * 1000) / 10}%` }} />
    </div>
  );
}
