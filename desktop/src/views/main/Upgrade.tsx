// Plans and paid features (v1.6, rebuilt in v1.7, docs/API.md "Entitlements", "Upgrade requests", "Payment receipts"):
// the neutral tag a feature the plan leaves out wears ("Pro", no colour, sparkle or lock), Settings › Plan & usage (the
// current plan, the request, this month's meters, what the plan includes, the plans above it, payments) and Settings ›
// Transcription key (the Free plan's own Groq key). The upgrade flow itself is UpgradeFlow.tsx. An older server sends no
// entitlements: no tag, no flow, and Plan & usage is not offered.
import { useEffect, useId, useState, type ReactNode } from "react";
import { api, errorMessage, type Entitlements, type LimitEntitlement, type OfferedPlan, type UpgradeRequest } from "../../lib/ipc";
import {
  featureWords, isLocked, isTopPlan, lowestPlan, noteUpgradeRequest, openRequest, openUpgradeSheet, planName, plansAbove, priceText, refreshEntitlements, refreshUpgradeRequest,
  requestStage, useEntitlements,
} from "../../lib/entitlements";
import { count, fileSize, formatTime, rowDate, rowDateWithYear, rupees, sentenceDate } from "../../lib/format";
import { fillWidth } from "../../lib/motion";
import { CheckIcon } from "../../components/ControlIcons";
import { ActionRow, ConfirmDialog, InfoRow, LinkRow, Section, Tag, rowAttrs } from "./SettingsControls";

export { UpgradeSheet } from "./UpgradeFlow";

/** The neutral tag for a paid feature the plan leaves out ("Pro"); a click opens the upgrade flow (or the open request). */
export function PlanBadge({ feature, onOpen }: { feature: string; onOpen?: (feature: string) => void }) {
  const e = useEntitlements();
  if (!isLocked(e, feature)) return null;
  const plan = lowestPlan(e, feature);
  const { name } = featureWords(e, feature);
  const open = (ev: React.MouseEvent) => {
    ev.preventDefault();
    ev.stopPropagation();
    (onOpen ?? openForFeature)(feature);
  };
  return (
    <button type="button" className="plan-badge" onClick={open} aria-label={`${name}, in ${plan ? planName(plan) : "a paid plan"}`} title={`${name} is in ${plan ? planName(plan) : "a paid plan"}`}>
      {plan ? planName(plan) : "Paid"}
    </button>
  );
}

/** The flow for a locked feature: its plan preselected, or the open request when there is one. */
export function openForFeature(feature: string): void {
  openUpgradeSheet(feature);
}

/** The same tag as words only, inside something that is itself the button (a locked tab, a menu item, a palette row). */
export function BadgeMark({ feature }: { feature: string }) {
  const e = useEntitlements();
  if (!isLocked(e, feature)) return null;
  const plan = lowestPlan(e, feature);
  return <span className="plan-badge static mark" aria-hidden="true">{plan ? planName(plan) : "Paid"}</span>;
}

/** The tag a locked row shows instead of its switch, and what a click on it does; none when the plan has it. */
export function useLockedTag(feature: string): { tag: string; onOpen: () => void } | null {
  const e = useEntitlements();
  if (!isLocked(e, feature)) return null;
  const plan = lowestPlan(e, feature);
  return { tag: plan ? planName(plan) : "Paid", onOpen: () => openForFeature(feature) };
}

/** Whether the plan leaves `feature` out (an older server: never). */
export function useLocked(feature: string): boolean {
  return isLocked(useEntitlements(), feature);
}

/** A paid feature's place when the plan leaves it out: its name, what it does, and the tag. */
export function LockedFeatureCard({ feature }: { feature: string }) {
  const e = useEntitlements();
  if (!isLocked(e, feature)) return null;
  const words = featureWords(e, feature);
  return (
    <div className="card locked-feature" style={{ marginTop: 14 }} data-testid={`locked-${feature}`}>
      <div className="row"><b>{words.name}</b><span className="spacer" /><PlanBadge feature={feature} /></div>
      {words.description && <p className="small muted">{words.description}</p>}
    </div>
  );
}

/** A locked feature's name with its tag, where the feature would be (the transcript's Show as views, say). */
export function LockedChip({ feature, label }: { feature: string; label: string }) {
  const e = useEntitlements();
  if (!isLocked(e, feature)) return null;
  return <span className="locked-chip small muted" data-testid={`locked-${feature}`}><span>{label}</span><PlanBadge feature={feature} /></span>;
}

// ---- Words and numbers of Plan & usage -------------------------------------------------------------------------------

/** "3 hours", "30 days", "1 year", "Up to 50": a plan's limits as people read them. */
export function limitText(key: string, value: number | null | undefined): string {
  if (value === null) return "No limit";
  if (value === undefined) return "";
  switch (key) {
    case "storage_bytes":
      return fileSize(value);
    case "monthly_minutes":
      return `${count(value)} minutes`;
    case "max_file_minutes":
      return value >= 60 && value % 60 === 0 ? (value === 60 ? "1 hour" : `${value / 60} hours`) : `${count(value)} minutes`;
    case "retention_days":
      return value >= 365 && value % 365 === 0 ? (value === 365 ? "1 year" : `${value / 365} years`) : value === 1 ? "1 day" : `${count(value)} days`;
    case "uploads_per_day":
      return `Up to ${count(value)}`;
    case "seats":
      return value <= 1 ? "Just you" : `Up to ${count(value)} people`;
    default:
      return count(value);
  }
}

/** "230 of 600 min": used and limit in the same unit. */
export function usageText(key: string, limit: LimitEntitlement): string {
  const used = limit.used ?? 0;
  if (key === "storage_bytes") return limit.limit ? `${fileSize(used)} of ${fileSize(limit.limit)}` : fileSize(used);
  if (key === "monthly_minutes") return limit.limit ? `${count(used)} of ${count(limit.limit)} min` : `${count(used)} min`;
  return limit.limit ? `${count(used)} of ${count(limit.limit)}` : count(used);
}

/** How full a limit is (0–100), none without a limit. */
export function percentOf(limit: LimitEntitlement | undefined): number | null {
  if (!limit || !limit.limit || limit.limit <= 0 || limit.used === null) return null;
  return Math.min(100, Math.round((limit.used / limit.limit) * 100));
}

/** Whether the person may change the plan: the owner (or admin) of the workspace, or one's own workspace. */
export function canUpgrade(role: string | null | undefined): boolean {
  return role !== "member" && role !== "viewer";
}

/** The first plan above the current one ("Free → Pro", "Pro → Ultra"). */
export function nextPlan(e: Entitlements | null | undefined): OfferedPlan | null {
  return plansAbove(e)[0] ?? null;
}

const METERS: readonly (readonly [string, string, string])[] = [
  ["monthly_minutes", "Transcription", "plan.usage.minutes"],
  ["storage_bytes", "Storage", "plan.usage.storage"],
  ["devices", "Devices", "plan.usage.devices"],
  ["seats", "Team members", "plan.usage.members"],
];

/** One meter of "This month": title, "230 of 600 min", the bar (neutral, then amber from 80 %, red when full) and its line. */
export function Meter({ id, label, limitKey, limit, quiet, onUpgrade }: {
  id: string;
  label: string;
  limitKey: string;
  limit: LimitEntitlement;
  /** No "Upgrade" in the line (a member, or while a request is open). */
  quiet: boolean;
  onUpgrade?: () => void;
}) {
  const percent = percentOf(limit) ?? 0;
  // A team of one member out of one is never red (it blocks nothing here).
  const blocks = limitKey !== "seats";
  const tone = percent >= 100 && blocks ? "full" : percent >= 80 ? "warn" : "";
  const text = usageText(limitKey, limit);
  const resets = limitKey === "monthly_minutes" && limit.resets_at ? limit.resets_at : null;
  const words = limitKey === "monthly_minutes" ? "minutes" : limitKey === "storage_bytes" ? "storage" : limitKey === "devices" ? "devices" : "team members";
  let line: ReactNode = resets ? `Resets ${rowDate(resets)}` : null;
  if (percent >= 100 && blocks) {
    line = limitKey === "monthly_minutes" ? `Transcription is paused${resets ? ` until ${rowDate(resets)}` : ""}.`
      : limitKey === "storage_bytes" ? (quiet ? "New recordings can't upload until you free up space." : <>New recordings can't upload until you free up space or <button type="button" className="btn inline-link" onClick={onUpgrade}>upgrade</button>.</>)
        : limitKey === "devices" ? "Sign out a device to add another." : null;
  } else if (percent >= 80 && blocks) {
    line = quiet || !onUpgrade
      ? `You've used ${percent}% of your ${words}.${resets ? ` They reset on ${rowDate(resets)}.` : ""}`
      : <>You've used {percent}% of your {words}. <button type="button" className="btn inline-link" onClick={onUpgrade}>Upgrade</button>{resets ? ` or wait until ${rowDate(resets)}.` : "."}</>;
  }
  const spoken = `${label}, ${text.replace(" min", " minutes")} used${resets ? `, resets ${sentenceDate(resets).replace(/ \d{4}$/, "")}` : ""}.`;
  return (
    <div className="set-row set-meter-row" {...rowAttrs(id)} data-testid={`meter-${limitKey}`}>
      <span className="set-line"><span className="set-title">{label}</span><span className={`set-value ${tone}`}>{text}</span></span>
      <span className={`set-meter ${tone}`} role="meter" aria-label={spoken} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}><span style={fillWidth(percent / 100)} /></span>
      {line && <span className={`set-sub ${tone}`}>{line}</span>}
    </div>
  );
}

/** "This month": the minutes (or, on Free, the own key), storage, devices and a team's members. */
export function ThisMonth({ e, quiet, onKey, onUpgrade, footer = true }: { e: Entitlements; quiet: boolean; onKey: () => void; onUpgrade?: () => void; footer?: boolean }) {
  const minutes = e.limits.monthly_minutes;
  const ownKey = isLocked(e, "platform_transcription") || !minutes || minutes.limit === 0;
  const uploads = e.limits.uploads_per_day;
  return (
    <Section title="This month" footer={footer && !ownKey ? <p>Minutes reset on the 1st of each month. Unused minutes don't carry over.</p> : undefined}>
      {ownKey ? (
        <div className="set-row set-meter-row" {...rowAttrs("plan.usage.minutes")} data-testid="meter-own-key">
          <span className="set-line"><span className="set-title">Transcription</span><span className="set-value">Your Groq key</span></span>
          <span className="set-sub">{e.transcription_key?.set ? "Key added" : <>Not added. <button type="button" className="btn inline-link" onClick={onKey}>Add key</button></>}</span>
        </div>
      ) : <Meter id="plan.usage.minutes" label="Transcription" limitKey="monthly_minutes" limit={minutes} quiet={quiet} onUpgrade={onUpgrade} />}
      {METERS.slice(1).map(([key, label, id]) => {
        const limit = e.limits[key];
        if (!limit || limit.limit === null || limit.limit === undefined) return null;
        if (key === "seats" && (limit.limit ?? 0) <= 1) return null;
        return <Meter key={key} id={id} label={label} limitKey={key} limit={limit} quiet={quiet} onUpgrade={onUpgrade} />;
      })}
      {uploads && (percentOf(uploads) ?? 0) >= 80 && <Meter id="plan.usage.uploads" label="Uploads in the last 24 hours" limitKey="uploads_per_day" limit={uploads} quiet={quiet} onUpgrade={onUpgrade} />}
    </Section>
  );
}

/** The lines of a plan card: the server's highlights, else what the plan adds (its minutes, its team, its features). */
export function planHighlights(e: Entitlements, plan: OfferedPlan, below: OfferedPlan | null): string[] {
  const own = (plan.highlights ?? []).map((h) => h.trim()).filter(Boolean);
  if (own.length > 0) return own.slice(0, 5);
  const lines: string[] = [];
  const minutes = plan.limits.monthly_minutes;
  if (typeof minutes === "number" && minutes > 0) lines.push(`${count(minutes)} transcription minutes a month${below ? "" : ", no key needed"}`);
  const seats = plan.limits.seats;
  if (typeof seats === "number" && seats > 1) lines.push(`A team of up to ${count(seats)} people`);
  const before = new Set(below?.features ?? Object.keys(e.features).filter((k) => e.features[k].cost !== "paid" || e.features[k].enabled));
  for (const key of plan.features) {
    if (lines.length >= 5) break;
    if (before.has(key) || key === "platform_transcription" || key === "team") continue;
    lines.push(featureWords(e, key).name);
  }
  return lines.slice(0, 5);
}

/** The request's card (stage by stage): what happened, what to do next, its one main button. */
export function RequestCard({ e, request, onOpen, onPay, onReceipt, onCancel, onAgain, onDismiss, inFlow = false }: {
  e: Entitlements;
  request: UpgradeRequest;
  onOpen?: () => void;
  onPay?: () => void;
  onReceipt?: () => void;
  onCancel?: () => void;
  onAgain?: () => void;
  onDismiss?: () => void;
  /** Inside the request's own status: no "View request". */
  inFlow?: boolean;
}) {
  const stage = requestStage(request);
  const name = planName(e.plans.find((p) => p.key === request.plan) ?? { key: request.plan, name: "" });
  const amount = request.amount_due_pkr ? rupees(request.amount_due_pkr) : request.amount_due ?? null;
  const until = request.approved_until ?? e.plan?.expires_at ?? null;
  const paidAt = request.payment?.paid_at ?? request.timeline?.find((t) => t.step === "receipt_sent")?.at ?? null;
  type Card = { title: string; tag: string; tone?: "warning" | "success"; body: ReactNode; primary?: [string, (() => void) | undefined]; secondary?: [string, (() => void) | undefined, boolean?] };
  const cards: Record<string, Card> = {
    awaiting_payment: {
      title: `${name} requested`, tag: "Waiting for payment",
      body: <p>Send {amount ?? "the amount"} to our bank account, then add a screenshot of the receipt.{request.reference_code ? <> Reference <span className="mono">{request.reference_code}</span>.</> : ""}</p>,
      primary: ["Payment details", onPay], secondary: ["Cancel request", onCancel, true],
    },
    in_review: {
      title: "Checking your payment", tag: "In review",
      body: <p>{paidAt ? `We got your receipt on ${rowDate(paidAt)} at ${formatTime(new Date(paidAt))}. ` : ""}{request.payment?.review_hours ?? "We check receipts every day."}</p>,
      primary: inFlow ? undefined : ["View request", onOpen],
    },
    needs_receipt: {
      title: "We need another receipt", tag: "Action needed", tone: "warning",
      body: <>{request.payment?.proof_request?.note && <p className="pre-line">{request.payment.proof_request.note}</p>}<p>Your request is still open. You don't need to pay again.</p></>,
      primary: ["Add receipt", onReceipt], secondary: ["Payment details", onPay],
    },
    declined: {
      title: "We couldn't confirm your payment", tag: "Not approved",
      body: <>{request.reply && <p className="pre-line">{request.reply}</p>}<p>If you've paid, reply to our email with your transaction ID and we'll look again.</p></>,
      primary: onAgain ? ["Request again", onAgain] : undefined, secondary: onDismiss ? ["Dismiss", onDismiss] : undefined,
    },
    approved: {
      title: `You're on ${name}`, tag: "Approved", tone: "success",
      body: <p>Thank you. {name} is on{until ? ` until ${sentenceDate(until)}` : ""}.</p>,
      secondary: onDismiss ? ["Dismiss", onDismiss] : undefined,
    },
  };
  const card = stage ? cards[stage] : undefined;
  if (!card) return null;
  return (
    <div className="set-section request-card" data-testid="upgrade-request" data-stage={stage}>
      <div className="set-group set-card" role="status">
        {onOpen && !inFlow ? (
          <button type="button" className="request-head as-link" onClick={onOpen} aria-label={`${card.title}, ${card.tag}. Opens the request`}>
            <span className="request-title">{card.title}</span><Tag tone={card.tone}>{card.tag}</Tag>
          </button>
        ) : (
          <div className="request-head"><span className="request-title">{card.title}</span><Tag tone={card.tone}>{card.tag}</Tag></div>
        )}
        <div className="request-body">{card.body}</div>
        {(card.primary || card.secondary) && (
          <div className="request-actions">
            {card.primary?.[1] && <button type="button" className="btn primary" onClick={card.primary[1]}>{card.primary[0]}</button>}
            {card.secondary?.[1] && <button type="button" className={`btn text ${card.secondary[2] ? "danger" : ""}`.trim()} onClick={card.secondary[1]}>{card.secondary[0]}</button>}
          </div>
        )}
      </div>
    </div>
  );
}

const DISMISSED_KEY = "echoledger-request-dismissed";
function dismissedIds(): string[] {
  try {
    const parsed = JSON.parse(localStorage.getItem(DISMISSED_KEY) ?? "[]");
    return Array.isArray(parsed) ? parsed.filter((x): x is string => typeof x === "string") : [];
  } catch {
    return [];
  }
}
/** Hides a decided request's card and state row (stored per request id). */
export function dismissRequest(id: string): void {
  try {
    localStorage.setItem(DISMISSED_KEY, JSON.stringify([...dismissedIds().filter((x) => x !== id), id].slice(-20)));
  } catch {
    // Storage is off: it shows again next time.
  }
}

/** Whether a request's card is shown: open, or declined in the last 14 days, or approved in the last 7, not dismissed. */
export function requestShown(request: UpgradeRequest | null | undefined, now: number = Date.now()): boolean {
  const stage = requestStage(request);
  if (!request || !stage || stage === "cancelled") return false;
  if (stage === "awaiting_payment" || stage === "in_review" || stage === "needs_receipt") return true;
  if (dismissedIds().includes(request.id)) return false;
  const at = Date.parse(request.decided_at ?? request.created_at ?? "");
  const days = Number.isNaN(at) ? 0 : (now - at) / 86_400_000;
  return stage === "declined" ? days <= 14 : days <= 7;
}

/** The full table of features: Free | Pro | Ultra, a tick where a plan has one, limits as values. */
function FeatureMatrix({ e }: { e: Entitlements }) {
  const freeName = e.plan?.key === "free" ? planName(e.plan) : "Free";
  const columns: { key: string; name: string; has: (f: string) => boolean; limits: Record<string, number | null | undefined> }[] = [
    { key: "free", name: freeName, has: (f) => e.features[f]?.cost !== "paid", limits: e.plan?.key === "free" ? Object.fromEntries(Object.entries(e.limits).map(([k, v]) => [k, v.limit])) : {} },
    ...e.plans.filter((p) => p.key !== "free").map((p) => ({ key: p.key, name: planName(p), has: (f: string) => p.features.includes(f) || e.features[f]?.cost !== "paid", limits: p.limits })),
  ];
  const features = Object.keys(e.features);
  const limits = ["storage_bytes", "retention_days", "devices", "max_file_minutes", "uploads_per_day"].filter((k) => columns.some((c) => c.limits[k] !== undefined));
  const head = (c: (typeof columns)[number]) => (c.key === e.plan?.key ? <>{c.name} <span className="small muted">(yours)</span></> : c.name);
  return (
    <div className="set-section">
      <div className="set-group feature-matrix-wrap">
        <table className="feature-matrix" aria-label="Every feature" data-testid="all-features">
          <thead><tr><th scope="col"><span className="sr-only">Feature</span></th>{columns.map((c) => <th key={c.key} scope="col">{head(c)}</th>)}</tr></thead>
          <tbody>
            {features.map((f) => (
              <tr key={f} data-testid={`feature-${f}`}>
                <th scope="row">{featureWords(e, f).name}</th>
                {columns.map((c) => <td key={c.key}>{c.has(f) ? <span role="img" aria-label="Included"><CheckIcon size={15} /></span> : <span className="sr-only">Not included</span>}</td>)}
              </tr>
            ))}
            {limits.map((k) => (
              <tr key={k}>
                <th scope="row">{k === "storage_bytes" ? "Storage" : k === "retention_days" ? "Recordings kept" : k === "devices" ? "Devices" : k === "max_file_minutes" ? "Longest recording" : "Uploads a day"}</th>
                {columns.map((c) => <td key={c.key} className="tabular">{c.limits[k] === undefined ? "" : limitText(k, c.limits[k] ?? null)}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/** Account › Plan & usage (v1.7): see the settings spec, section 4. */
export function PlanUsagePage({ e, role, workspace, onKey }: { e: Entitlements; role: string | null; workspace: string | null; onKey: () => void }) {
  const [matrix, setMatrix] = useState(false);
  const [, rerender] = useState(0);
  const request = e.upgrade_request;
  const open = openRequest(e);
  const stage = requestStage(request);
  // Each time the page opens while a request is open, the request is read in full (the bank details, the receipts).
  useEffect(() => {
    if (open) void refreshUpgradeRequest();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open?.id, stage]);
  const plan = e.plan;
  const name = plan ? planName(plan) : "Your";
  const isFree = !plan || plan.key === "free";
  const top = isTopPlan(e);
  const above = plansAbove(e);
  const allowed = canUpgrade(role);
  const quiet = !!open || !allowed;
  const current = e.plans.find((p) => p.key === plan?.key) ?? null;
  const price = current ? priceText(current) : null;
  const next = above[0] ?? null;
  const flow = (planKey: string | null) => openUpgradeSheet(null, { plan: planKey });
  const status = (step: "status" | "pay" | "receipt") => openUpgradeSheet(null, { step });
  const scrollToPlans = () => document.getElementById("plans-above")?.scrollIntoView?.({ block: "start", behavior: "smooth" });
  const included: [string, string][] = [
    ["retention_days", "Recordings kept"], ["max_file_minutes", "Longest recording"], ["uploads_per_day", "Uploads a day"], ["seats", "Team members"],
  ];
  const [cancelling, setCancelling] = useState<"ask" | "busy" | null>(null);
  const [cancelError, setCancelError] = useState<string | null>(null);
  const cancel = async () => {
    if (!open) return;
    setCancelling("busy");
    setCancelError(null);
    try {
      noteUpgradeRequest(await api.cancelUpgradeRequest(open.id));
      setCancelling(null);
      void refreshEntitlements();
    } catch (err) {
      setCancelError(errorMessage(err));
      setCancelling("ask");
      void refreshUpgradeRequest();
    }
  };
  return (
    <div data-testid="plan-usage">
      <div className="set-section" {...rowAttrs("plan.current")}>
        <div className="set-group set-card plan-current">
          <div className="plan-name">{isFree ? "Free plan" : `${name} plan`}</div>
          {!allowed && workspace && <p className="muted">{workspace} is on {name}. Its owner manages the plan.</p>}
          {isFree && allowed && <p className="muted">Transcripts use your own free Groq key.</p>}
          {!isFree && price && <p className="muted tabular">{price}</p>}
          {!isFree && <p className="muted">{plan?.expires_at ? `Paid until ${rowDateWithYear(plan.expires_at)}` : "No end date"}</p>}
          {plan?.trial_minutes_left ? <p className="muted">{count(plan.trial_minutes_left)} trial minutes left</p> : null}
          {allowed && !open && next && (
            <div className="plan-actions" {...rowAttrs("plan.upgrade")}>
              <button type="button" className="btn primary wide" onClick={() => flow(next.key)}>{isFree ? "Upgrade" : `Upgrade to ${planName(next)}`}</button>
              {isFree && above.length > 0 && <button type="button" className="btn text" onClick={scrollToPlans}>Compare plans</button>}
            </div>
          )}
          {allowed && !open && top && <p className="muted">You're on our top plan.</p>}
        </div>
        {!isFree && plan?.expires_at && <div className="set-foot"><p>Your plan stops on {sentenceDate(plan.expires_at)}. There is nothing to cancel.</p></div>}
      </div>
      {request && requestShown(request) && (
        <div {...rowAttrs("plan.request")}>
          <RequestCard e={e} request={request} onOpen={() => status("status")} onPay={() => status("pay")} onReceipt={() => status("receipt")} onCancel={() => setCancelling("ask")}
            onAgain={allowed && above.length > 0 ? () => flow(request.plan) : undefined}
            onDismiss={() => { dismissRequest(request.id); rerender((n) => n + 1); }} />
        </div>
      )}
      <ThisMonth e={e} quiet={quiet} onKey={onKey} onUpgrade={next ? () => flow(next.key) : undefined} />
      <Section title="Included in your plan" footer={isFree ? <p>Every plan can record, organise, search, share links and export.</p> : undefined}>
        {included.map(([key, label]) => {
          const value = e.limits[key]?.limit;
          if (value === undefined) return null;
          return <InfoRow key={key} id={`plan.included.${key}`} title={label} value={limitText(key, value)} />;
        })}
        <LinkRow id="plan.compare" value={matrix ? "Shown below" : undefined} onOpen={() => setMatrix((m) => !m)} chevron />
      </Section>
      {matrix && <FeatureMatrix e={e} />}
      {allowed && above.length > 0 && (
        <section className="set-section" id="plans-above" aria-labelledby="plans-head">
          <h3 id="plans-head" className="set-head">Plans</h3>
          {above.map((p, i) => {
            const below = i > 0 ? above[i - 1] : current;
            const lines = planHighlights(e, p, below);
            return (
              <div key={p.key} className="set-group set-card plan-card-above" data-testid={`plan-${p.key}`}>
                <div className="plan-card-line"><span className="plan-card-name">{planName(p)}</span>{priceText(p) && <span className="muted tabular">{priceText(p)}</span>}</div>
                <div className="plan-lines">
                  {i > 0 && <p className="muted">Everything in {planName(above[i - 1])}, plus:</p>}
                  {lines.map((l) => <p key={l}>{l}</p>)}
                </div>
                {!open && <button type="button" className="btn outline wide" onClick={() => flow(p.key)}>Choose {planName(p)}</button>}
              </div>
            );
          })}
        </section>
      )}
      {cancelling && (
        <ConfirmDialog title="Cancel this request?" body="If you've already paid, add your receipt instead. Cancelling doesn't refund a payment."
          cancel="Keep request" confirm="Cancel request" busy={cancelling === "busy"} error={cancelError}
          onConfirm={() => void cancel()} onClose={() => { setCancelling(null); setCancelError(null); }} />
      )}
      {allowed && (request || above.length > 0) && (
        <Section title="Payments" footer={<p>Payments are by bank transfer. We switch your plan on after we see the money in our account.</p>}>
          {request && stage !== null && (
            <button type="button" className="set-row set-link set-two" {...rowAttrs("plan.history")} onClick={() => status("status")}>
              <span className="set-main">
                <span className="set-title">{planName(e.plans.find((p) => p.key === request.plan) ?? { key: request.plan, name: "" })}, 1 month</span>
                <span className="set-sub">{historyLine(request)}</span>
              </span>
              {(request.amount_due_pkr || request.amount_due) && <span className="set-end"><span className="set-value tabular">{request.amount_due_pkr ? rupees(request.amount_due_pkr) : request.amount_due}</span></span>}
            </button>
          )}
          {!open && above.length > 0 && <ActionRow id="plan.promo" value="Add" onClick={() => openUpgradeSheet(null, { plan: next?.key ?? null, step: "choose" })} />}
        </Section>
      )}
    </div>
  );
}

/** "Approved on 9 Nov 2026", "In review since 9 Nov". */
function historyLine(request: UpgradeRequest): string {
  const stage = requestStage(request);
  const decided = rowDateWithYear(request.decided_at);
  const since = rowDate(request.payment?.paid_at ?? request.created_at);
  switch (stage) {
    case "approved": return decided ? `Approved on ${decided}` : "Approved";
    case "declined": return decided ? `Not approved on ${decided}` : "Not approved";
    case "cancelled": return "Cancelled by you";
    case "in_review": return since ? `In review since ${since}` : "In review";
    case "needs_receipt": return "New receipt asked for";
    default: return since ? `Waiting for payment since ${since}` : "Waiting for payment";
  }
}

const GROQ_KEYS = "console.groq.com";

/** Transcription › Transcription key (the Free plan): the person's own free Groq key in three steps. */
export function TranscriptionKeyPage({ e }: { e: Entitlements }) {
  const key = e.transcription_key;
  const [value, setValue] = useState("");
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const fieldId = useId();
  useEffect(() => {
    if (!saved) return;
    const timer = window.setTimeout(() => setSaved(false), 4000);
    return () => window.clearTimeout(timer);
  }, [saved]);
  const save = async (ev: React.FormEvent) => {
    ev.preventDefault();
    setBusy(true);
    setProblem(null);
    try {
      await api.setTranscriptionKey(value);
      setValue("");
      setSaved(true);
      await refreshEntitlements();
    } catch (err) {
      setProblem(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };
  const remove = async () => {
    if (!window.confirm("Remove your Groq key? New recordings won't be transcribed until you add a key or upgrade.")) return;
    setBusy(true);
    setProblem(null);
    try {
      await api.deleteTranscriptionKey();
      await refreshEntitlements();
    } catch (err) {
      setProblem(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };
  const paid = !isLocked(e, "platform_transcription");
  return (
    <div className="transcription-key" data-testid="transcription-key">
      {key?.set ? (
        <Section footer={paid ? <p>Your plan transcribes for you. Your own key is used only if you choose it on a recording.</p> : undefined}>
          <InfoRow id="key.saved" value={`Ending in ${key.last4 ?? "…"}`} />
          <button type="button" className="set-row set-link set-danger" disabled={busy} onClick={() => void remove()}><span className="set-title">Remove key</span></button>
        </Section>
      ) : <p className="set-intro">Transcripts on Free use your own free Groq key. It takes about 2 minutes.</p>}
      <Section title={key?.set ? "Replace the key" : "Add your key"}>
        <ol className="key-steps set-block">
          <li>
            <span>Open <b>{GROQ_KEYS}</b> and sign in. It's free.</span>
            <button type="button" className="btn compact" onClick={() => void api.openGroqConsole().catch((err) => setProblem(errorMessage(err)))}>Open {GROQ_KEYS}</button>
          </li>
          <li><span>Choose <b>API Keys</b>, then <b>Create API key</b>, and copy it.</span></li>
          <li>
            <span>Paste it here and choose <b>Save</b>.</span>
            <form className="row key-paste" onSubmit={(ev) => void save(ev)}>
              <label htmlFor={fieldId} className="sr-only">Groq key</label>
              <input id={fieldId} className="input" type="password" autoComplete="off" spellCheck={false} placeholder="Groq key (gsk_…)" value={value} onChange={(ev) => setValue(ev.target.value)} />
              <button type="submit" className="btn primary" disabled={busy || value.trim().length < 20}>{busy ? "Checking…" : "Save"}</button>
            </form>
            {problem && <p className="small error-text" role="alert">{problem}</p>}
            {saved && <p className="small" role="status">Key saved. New recordings are transcribed with it.</p>}
          </li>
        </ol>
      </Section>
    </div>
  );
}

/** A recording whose transcript failed because there is no key yet (`needs_transcription_key`). */
export const NEEDS_KEY_TEXT = "This recording wasn't transcribed. Add your Groq key in Settings › Transcription key.";
