// The top of Settings (v1.7): who you are (avatar, name, email and the plan's neutral chip), the one button that fits
// your plan (filled Upgrade on Free, outlined on Pro, none on Ultra, for a team's members or while a request is open),
// and under it at most one state row: the thing that needs you now, in priority order.
import type { ReactNode } from "react";
import type { Account, AppStatus, Entitlements } from "../../lib/ipc";
import { initials } from "../../lib/shell";
import { count, rowDate, rowDateWithYear, sentenceDate } from "../../lib/format";
import { isLocked, isTopPlan, openRequest, openUpgradeSheet, planName, requestStage } from "../../lib/entitlements";
import { LucideCalendarIcon, LucideCircleAlertIcon, LucideCircleCheckIcon, LucideClockIcon, LucideInfoIcon, LucideKeyRoundIcon, LucideTriangleAlertIcon, LucideUserIcon, LucideXIcon } from "../../components/Icons";
import { canUpgrade, dismissRequest, nextPlan, requestShown } from "./Upgrade";

/** The plan's chip: its name, neutral, sentence case. */
export function PlanChip({ name }: { name: string }) {
  return <span className="plan-chip">{name}</span>;
}

/** The avatar: the account's initials (never the computer's), a person before the account is known. */
export function Avatar({ name, size = 56 }: { name: string | null; size?: number }) {
  return (
    <span className="profile-avatar" style={{ width: size, height: size, fontSize: Math.round(size * 0.36) }} aria-hidden="true">
      {name ? initials(name) : <LucideUserIcon size={Math.round(size * 0.45)} />}
    </span>
  );
}

/** The name the card shows: the account's name, else the email's local part. */
export function displayName(account: Account | null | undefined): string | null {
  if (!account) return null;
  return account.name?.trim() || account.email.split("@")[0] || null;
}

/** The plan's line beside the chip: "230 of 600 min this month" on Pro, "Paid until 9 Dec 2026" on Ultra, none on Free. */
export function planLine(e: Entitlements | null | undefined): string | null {
  if (!e?.plan || e.plan.key === "free") return null;
  if (isTopPlan(e)) return e.plan.expires_at ? `Paid until ${rowDateWithYear(e.plan.expires_at)}` : null;
  const m = e.limits.monthly_minutes;
  if (m && m.limit) return `${count(m.used ?? 0)} of ${count(m.limit)} min this month`;
  return e.plan.expires_at ? `Paid until ${rowDateWithYear(e.plan.expires_at)}` : null;
}

/** The card: one button for the body (opens Plan & usage), the Upgrade button beside it, never inside it. */
export function AccountHeader({ account, e, role, workspace, teamLine, onPlan }: {
  account: Account | null | undefined;
  e: Entitlements | null | undefined;
  role: string | null;
  /** The workspace's name. */
  workspace: string | null;
  /** "Acme, Admin" for a team workspace; none for one's own. */
  teamLine: string | null;
  onPlan: () => void;
}) {
  const name = displayName(account);
  const plan = e?.plan ? planName(e.plan) : null;
  const line = planLine(e);
  const member = !canUpgrade(role);
  const next = nextPlan(e);
  const button: "filled" | "outlined" | null = !e || member || openRequest(e) || !next || isTopPlan(e) ? null : e.plan?.key === "free" ? "filled" : "outlined";
  const until = e?.plan?.expires_at && isTopPlan(e) ? `, paid until ${sentenceDate(e.plan.expires_at)}` : "";
  const spoken = [name ?? "Your account", account?.email, plan ? `${plan} plan${until}` : null].filter(Boolean).join(", ");
  return (
    <div className="profile-card" data-testid="profile-card">
      <button type="button" className="profile-body" onClick={onPlan} disabled={!e} aria-label={`${spoken}. ${e ? "Opens plan and usage." : ""}`.trim()}>
        <Avatar name={name} />
        <span className="profile-text">
          <span className="profile-name">{name ?? "Your account"}</span>
          {account?.email && <span className="profile-email">{account.email}</span>}
          {plan && (
            <span className="profile-plan">
              <PlanChip name={plan} />
              {member && workspace ? <span className="profile-line">{workspace} is on {plan}. Its owner manages the plan.</span>
                : line && <span className="profile-line">{line}</span>}
            </span>
          )}
          {!member && teamLine && <span className="profile-line">{teamLine}</span>}
        </span>
      </button>
      {button && next && (
        <button type="button" className={`btn ${button === "filled" ? "primary" : "outline"} profile-upgrade`} onClick={() => openUpgradeSheet(null, { plan: next.key })}>Upgrade</button>
      )}
    </div>
  );
}

interface StateRowData {
  key: string;
  icon: ReactNode;
  tone?: "warning" | "success";
  text: string;
  action?: [string, () => void];
  dismiss?: () => void;
  onOpen?: () => void;
}

/** The one state row under the card (none when nothing is true), the first true of: deletion, a failed load, a
 * request that needs something or was decided, a plan that ends soon, and the free key to set up. */
export function stateRow({ account, accountFailed, e, status, onPrivacy, onKey, onRetry, onPlan, onDismissed }: {
  account: Account | null | undefined;
  accountFailed: boolean;
  e: Entitlements | null | undefined;
  status: Pick<AppStatus, "platform">;
  onPrivacy: () => void;
  onKey: () => void;
  onRetry: () => void;
  onPlan: () => void;
  onDismissed: () => void;
}, now: number = Date.now()): StateRowData | null {
  if (account?.deletes_at) {
    return { key: "deleting", icon: <LucideTriangleAlertIcon size={20} />, tone: "warning", text: `Your account will be deleted on ${rowDate(account.deletes_at)}.`, action: ["Cancel deletion", onPrivacy] };
  }
  if (accountFailed && !account) return { key: "load", icon: <LucideCircleAlertIcon size={20} />, text: "Couldn't load your account.", action: ["Try again", onRetry] };
  const request = e?.upgrade_request ?? null;
  const stage = requestStage(request);
  const name = request && e ? planName(e.plans.find((p) => p.key === request.plan) ?? { key: request.plan, name: "" }) : "";
  const status_ = (step: "status" | "pay") => () => openUpgradeSheet(null, { step });
  if (stage === "needs_receipt") return { key: "needs", icon: <LucideCircleAlertIcon size={20} />, tone: "warning", text: `We need another receipt for ${name}`, onOpen: status_("status") };
  if (stage === "declined" && requestShown(request, now)) {
    return { key: "declined", icon: <LucideInfoIcon size={20} />, text: `Your ${name} request wasn't approved`, onOpen: status_("status"), dismiss: () => { dismissRequest(request!.id); onDismissed(); } };
  }
  if (stage === "awaiting_payment") return { key: "awaiting", icon: <LucideClockIcon size={20} />, text: `${name} requested. Waiting for your payment`, onOpen: status_("pay") };
  if (stage === "in_review") return { key: "review", icon: <LucideClockIcon size={20} />, text: `Checking your payment for ${name}`, onOpen: status_("status") };
  const ends = e?.plan && e.plan.key !== "free" && e.plan.expires_at ? Date.parse(e.plan.expires_at) : NaN;
  if (!Number.isNaN(ends) && ends > now && ends - now <= 7 * 86_400_000) {
    return { key: "ending", icon: <LucideCalendarIcon size={20} />, tone: "warning", text: `${planName(e!.plan!)} ends on ${rowDate(e!.plan!.expires_at)}.`, onOpen: onPlan };
  }
  if (stage === "approved" && requestShown(request, now)) {
    const until = request!.approved_until ?? e?.plan?.expires_at ?? null;
    return { key: "approved", icon: <LucideCircleCheckIcon size={20} />, tone: "success", text: `You're on ${name}${until ? ` until ${rowDateWithYear(until)}` : ""}`, onOpen: onPlan,
      dismiss: () => { dismissRequest(request!.id); onDismissed(); } };
  }
  // The desktop has no transcription of its own: a Free account without a key gets no transcripts until it adds one.
  if (e && isLocked(e, "platform_transcription") && !e.transcription_key?.set) {
    void status;
    return { key: "key", icon: <LucideKeyRoundIcon size={20} />, text: "Set up free transcription (2 minutes)", onOpen: onKey };
  }
  return null;
}

export function StateRow({ row }: { row: StateRowData | null }) {
  if (!row) return null;
  const body = (
    <>
      <span className={`state-icon ${row.tone ?? ""}`.trim()} aria-hidden="true">{row.icon}</span>
      <span className="state-text">{row.text}</span>
    </>
  );
  return (
    <div className="set-section state-row-wrap" data-testid="state-row" data-state={row.key}>
      <div className="set-group state-row" role="status">
        {row.onOpen ? <button type="button" className="state-main" onClick={row.onOpen}>{body}</button> : <span className="state-main">{body}</span>}
        {row.action && <button type="button" className="btn text" onClick={row.action[1]}>{row.action[0]}</button>}
        {row.dismiss && <button type="button" className="btn ghost icon-only" aria-label="Dismiss" onClick={row.dismiss}><LucideXIcon size={16} /></button>}
      </div>
    </div>
  );
}
