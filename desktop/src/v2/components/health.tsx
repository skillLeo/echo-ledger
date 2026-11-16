import { useId, type ReactNode } from "react";
import { Icon } from "./Button";
import { CheckIcon, ErrorIcon, ReadyIcon, WarningIcon } from "./icons";

export type HealthState = "ok" | "limited" | "problem";

const MARK = { ok: ReadyIcon, limited: WarningIcon, problem: ErrorIcon } as const;

/**
 * One check of Setup & health: a mark and words that say how it stands (never colour alone), what it found, and the one
 * fix when it needs one.
 */
export function HealthCheckRow({ state, title, detail, stateLabel, action }: {
  state: HealthState;
  title: ReactNode;
  detail?: ReactNode;
  /** The state in words, for assistive tools ("OK", "Needs a fix"). */
  stateLabel: string;
  action?: ReactNode;
}) {
  const id = useId();
  return (
    <li className={`el-health el-health--${state}`} aria-labelledby={`${id}-title`} aria-describedby={`${id}-state${detail ? ` ${id}-detail` : ""}`}>
      <span className="el-health__mark"><Icon icon={MARK[state]} size="md" /></span>
      <span className="el-health__main">
        <span className="el-health__title" id={`${id}-title`}>{title}</span>
        <span className="el-visually-hidden" id={`${id}-state`}>{stateLabel}</span>
        {detail && <span className="el-caption" id={`${id}-detail`}>{detail}</span>}
      </span>
      {action && <span className="el-health__action">{action}</span>}
    </li>
  );
}

/** The steps of a setup wizard, in order. */
export function Steps({ label, children }: { label: string; children: ReactNode }) {
  return <ol className="el-steps" aria-label={label}>{children}</ol>;
}

/**
 * One step: its number (a check once done), its title and state line; the current step (or one opened) shows what it
 * is for and its actions.
 */
export function SetupStep({ number, title, state, done, current, open, onToggle, children }: {
  number: number;
  title: ReactNode;
  /** How it stands, in words ("Allowed", "Not asked yet"). */
  state: ReactNode;
  done: boolean;
  current: boolean;
  /** Shown expanded (the current step always is). */
  open?: boolean;
  onToggle?: () => void;
  children?: ReactNode;
}) {
  const id = useId();
  const expanded = current || !!open;
  return (
    <li className={`el-step${done ? " el-step--done" : ""}${current ? " el-step--current" : ""}`} aria-current={current ? "step" : undefined}>
      <span className="el-step__number" aria-hidden="true">{done ? <Icon icon={CheckIcon} /> : number}</span>
      <div className="el-step__main">
        {onToggle && !current ? (
          <button type="button" className="el-step__head" aria-expanded={expanded} aria-controls={`${id}-body`} onClick={onToggle}>
            <span className="el-step__title">{title}</span>
            <span className="el-caption">{state}</span>
          </button>
        ) : (
          <div className="el-step__head">
            <span className="el-step__title">{title}</span>
            <span className="el-caption">{state}</span>
          </div>
        )}
        {expanded && children && <div className="el-step__body" id={`${id}-body`}>{children}</div>}
      </div>
    </li>
  );
}
