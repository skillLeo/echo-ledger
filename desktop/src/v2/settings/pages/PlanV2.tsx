// Settings › Plan & usage in the new look, with today's data and rules (Upgrade.tsx): the plan with its one main
// button, an open or recent upgrade request stage by stage, this month's meters, what the plan includes and every
// feature, the plans above it, and payments. Buying goes through today's upgrade flow (openUpgradeSheet).
import { useEffect, useState, type ReactNode } from "react";
import { api, errorMessage, type Entitlements, type LimitEntitlement, type UpgradeRequest } from "../../../lib/ipc";
import {
  featureWords, isLocked, isTopPlan, noteUpgradeRequest, openRequest, openUpgradeSheet, planName, plansAbove, priceText, refreshEntitlements, refreshUpgradeRequest,
  requestStage,
} from "../../../lib/entitlements";
import { count, formatTime, rowDate, rowDateWithYear, rupees, sentenceDate } from "../../../lib/format";
import { fillWidth } from "../../../lib/motion";
import { setting } from "../../../lib/settingsRegistry";
import { canUpgrade, dismissRequest, limitText, percentOf, planHighlights, requestShown, usageText } from "../../../views/main/Upgrade";
import { Badge, Button, Card, Dialog, Icons, ListRow } from "../../components";
import { Group, SectionTitle } from "./rows";

const METERS: readonly (readonly [string, string, string])[] = [
  ["storage_bytes", "Storage", "plan.usage.storage"],
  ["devices", "Devices", "plan.usage.devices"],
  ["seats", "Team members", "plan.usage.members"],
];

/** A word inside a sentence that acts ("upgrade"). */
function InlineAction({ children, onClick }: { children: ReactNode; onClick?: () => void }) {
  return <button type="button" className="el-inline-action" onClick={onClick}>{children}</button>;
}

/** One meter: "230 of 600 min", the bar (amber from 80 %, red when full) and its line, as today's Meter says them. */
function MeterRow({ id, label, limitKey, limit, quiet, onUpgrade }: {
  id: string; label: string; limitKey: string; limit: LimitEntitlement; quiet: boolean; onUpgrade?: () => void;
}) {
  const share = percentOf(limit);
  const percent = share ?? 0;
  const blocks = limitKey !== "seats";
  const tone = percent >= 100 && blocks ? "full" : percent >= 80 ? "warn" : "";
  const text = usageText(limitKey, limit);
  const resets = limitKey === "monthly_minutes" && limit.resets_at ? limit.resets_at : null;
  // D-230: what the 80 % line calls each limit (the uploads were "team members"; a team's seats never block, so never warn).
  const words = limitKey === "monthly_minutes" ? "minutes" : limitKey === "storage_bytes" ? "storage" : limitKey === "devices" ? "devices"
    : limitKey === "uploads_per_day" ? "daily uploads" : "team members";
  let line: ReactNode = share === null ? (limitKey === "monthly_minutes" ? "No monthly limit" : null) : resets ? `Resets ${rowDate(resets)}` : null;
  if (percent >= 100 && blocks) {
    line = limitKey === "monthly_minutes" ? `Transcription is paused${resets ? ` until ${rowDate(resets)}` : ""}.`
      : limitKey === "storage_bytes" ? (quiet ? "New recordings can't upload until you free up space." : <>New recordings can't upload until you free up space or <InlineAction onClick={onUpgrade}>upgrade</InlineAction>.</>)
        : limitKey === "devices" ? "Sign out a device to add another." : null;
  } else if (percent >= 80 && blocks) {
    line = quiet || !onUpgrade
      ? `You've used ${percent}% of your ${words}.${resets ? ` They reset on ${rowDate(resets)}.` : ""}`
      : <>You've used {percent}% of your {words}. <InlineAction onClick={onUpgrade}>Upgrade</InlineAction>{resets ? ` or wait until ${rowDate(resets)}.` : "."}</>;
  }
  const spoken = `${label}, ${text.replace(" min", " minutes")} used${resets && share !== null ? `, resets ${sentenceDate(resets).replace(/ \d{4}$/, "")}` : ""}.`;
  return (
    <li className="el-row el-row--compact el-meter-row" id={`setting-${id}`} data-setting={id} tabIndex={-1} data-testid={`meter-${limitKey}`}>
      <span className="el-meter-row__line">
        <span className="el-row__title">{label}</span>
        <span className={`el-meter-row__value${tone ? ` el-storage-value--${tone}` : ""}`} dir="ltr">{text}</span>
      </span>
      {share !== null
        ? <span className={`el-meter el-meter--wide${tone ? ` el-meter--${tone}` : ""}`} role="meter" aria-label={spoken} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
          <span className="el-meter__fill" style={fillWidth(percent / 100)} />
        </span>
        : <span className="el-visually-hidden">{spoken}</span>}
      {line && <span className={`el-caption ${tone ? `el-storage-value--${tone}` : "el-secondary"}`} dir="auto">{line}</span>}
    </li>
  );
}

/** The request's card, stage by stage, with today's words and buttons. */
function RequestCardV2({ e, request, onOpen, onPay, onReceipt, onCancel, onAgain, onDismiss }: {
  e: Entitlements; request: UpgradeRequest; onOpen: () => void; onPay: () => void; onReceipt: () => void; onCancel: () => void; onAgain?: () => void; onDismiss: () => void;
}) {
  const stage = requestStage(request);
  const name = planName(e.plans.find((p) => p.key === request.plan) ?? { key: request.plan, name: "" });
  const amount = request.amount_due_pkr ? rupees(request.amount_due_pkr) : request.amount_due ?? null;
  const until = request.approved_until ?? e.plan?.expires_at ?? null;
  const paidAt = request.payment?.paid_at ?? request.timeline?.find((t) => t.step === "receipt_sent")?.at ?? null;
  type Shape = { title: string; tag: string; tone?: "warning" | "success"; body: ReactNode; primary?: [string, () => void]; secondary?: [string, () => void, boolean?] };
  const cards: Record<string, Shape> = {
    awaiting_payment: {
      title: `${name} requested`, tag: "Waiting for payment",
      body: <p dir="auto">Send {amount ?? "the amount"} to our bank account, then add a screenshot of the receipt.{request.reference_code ? <> Reference <span className="el-mono" dir="ltr">{request.reference_code}</span>.</> : ""}</p>,
      primary: ["Payment details", onPay], secondary: ["Cancel request", onCancel, true],
    },
    in_review: {
      title: "Checking your payment", tag: "In review",
      body: <p dir="auto">{paidAt ? `We got your receipt on ${rowDate(paidAt)} at ${formatTime(new Date(paidAt))}. ` : ""}{request.payment?.review_hours ?? "We check receipts every day."}</p>,
      primary: ["View request", onOpen],
    },
    needs_receipt: {
      title: "We need another receipt", tag: "Action needed", tone: "warning",
      body: <>{request.payment?.proof_request?.note && <p dir="auto" className="el-pre-line">{request.payment.proof_request.note}</p>}<p dir="auto">Your request is still open. You don't need to pay again.</p></>,
      primary: ["Add receipt", onReceipt], secondary: ["Payment details", onPay],
    },
    declined: {
      title: "We couldn't confirm your payment", tag: "Not approved",
      body: <>{request.reply && <p dir="auto" className="el-pre-line">{request.reply}</p>}<p dir="auto">If you've paid, reply to our email with your transaction ID and we'll look again.</p></>,
      primary: onAgain ? ["Request again", onAgain] : undefined, secondary: ["Dismiss", onDismiss],
    },
    approved: {
      title: `You're on ${name}`, tag: "Approved", tone: "success",
      body: <p dir="auto">Thank you. {name} is on{until ? ` until ${sentenceDate(until)}` : ""}.</p>,
      secondary: ["Dismiss", onDismiss],
    },
  };
  const card = stage ? cards[stage] : undefined;
  if (!card) return null;
  return (
    <section className="el-group" data-testid="upgrade-request" data-stage={stage} id="setting-plan.request" data-setting="plan.request" tabIndex={-1}>
      <Card>
        <div className="el-plan-card" role="status">
          {/* Named by what it shows, so the spoken name starts with the visible words. */}
          <button type="button" className="el-plan-card__head el-plan-card__head--link" onClick={onOpen}>
            <span className="el-title-s">{card.title}</span><span className="el-visually-hidden">,</span>{" "}
            <Badge tone={card.tone}>{card.tag}</Badge><span className="el-visually-hidden">. Opens the request</span>
          </button>
          <div className="el-plan-card__body el-secondary">{card.body}</div>
          {(card.primary || card.secondary) && (
            <div className="el-plan-card__actions">
              {card.primary && <Button variant="primary" onClick={card.primary[1]}>{card.primary[0]}</Button>}
              {card.secondary && <Button variant={card.secondary[2] ? "danger" : "quiet"} onClick={card.secondary[1]}>{card.secondary[0]}</Button>}
            </div>
          )}
        </div>
      </Card>
    </section>
  );
}

/** Every feature: Free | Pro | Ultra, a tick where a plan has one, limits as values. */
function FeatureTable({ e }: { e: Entitlements }) {
  const freeName = e.plan?.key === "free" ? planName(e.plan) : "Free";
  const columns: { key: string; name: string; has: (f: string) => boolean; limits: Record<string, number | null | undefined> }[] = [
    { key: "free", name: freeName, has: (f) => e.features[f]?.cost !== "paid", limits: e.plan?.key === "free" ? Object.fromEntries(Object.entries(e.limits).map(([k, v]) => [k, v.limit])) : {} },
    ...e.plans.filter((p) => p.key !== "free").map((p) => ({ key: p.key, name: planName(p), has: (f: string) => p.features.includes(f) || e.features[f]?.cost !== "paid", limits: p.limits })),
  ];
  const limits = ["storage_bytes", "retention_days", "devices", "max_file_minutes", "uploads_per_day"].filter((k) => columns.some((c) => c.limits[k] !== undefined));
  const limitName: Record<string, string> = { storage_bytes: "Storage", retention_days: "Recordings kept", devices: "Devices", max_file_minutes: "Longest recording", uploads_per_day: "Uploads a day" };
  return (
    <section className="el-group">
      <Card>
        <div className="el-table-wrap">
          <table className="el-table" aria-label="Every feature" data-testid="all-features">
            <thead><tr><th scope="col"><span className="el-visually-hidden">Feature</span></th>{columns.map((c) => <th key={c.key} scope="col">{c.name}{c.key === e.plan?.key && <span className="el-secondary"> (yours)</span>}</th>)}</tr></thead>
            <tbody>
              {Object.keys(e.features).map((f) => (
                <tr key={f} data-testid={`feature-${f}`}>
                  <th scope="row">{featureWords(e, f).name}</th>
                  {columns.map((c) => <td key={c.key}>{c.has(f) ? <span role="img" aria-label="Included" className="el-table__tick"><Icons.CheckIcon /></span> : <span className="el-visually-hidden">Not included</span>}</td>)}
                </tr>
              ))}
              {limits.map((k) => (
                <tr key={k}>
                  <th scope="row">{limitName[k]}</th>
                  {columns.map((c) => <td key={c.key} className="el-tabular">{c.limits[k] === undefined ? "" : limitText(k, c.limits[k] ?? null)}</td>)}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </section>
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

export function PlanPageV2({ e, role, workspace, onKey }: { e: Entitlements; role: string | null; workspace: string | null; onKey: () => void }) {
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
  const minutes = e.limits.monthly_minutes;
  const ownKey = isLocked(e, "platform_transcription") || !minutes || minutes.limit === 0;
  const uploads = e.limits.uploads_per_day;
  const onUpgrade = next ? () => flow(next.key) : undefined;
  const included: [string, string][] = [["retention_days", "Recordings kept"], ["max_file_minutes", "Longest recording"], ["uploads_per_day", "Uploads a day"], ["seats", "Team members"]];
  return (
    <div className="el-settings-v2" data-testid="plan-usage">
      <section className="el-group" id="setting-plan.current" data-setting="plan.current" tabIndex={-1}>
        <Card>
          <div className="el-plan-card">
            <div className="el-plan-card__head"><span className="el-title-m" dir="auto">{isFree ? "Free plan" : `${name} plan`}</span></div>
            <div className="el-plan-card__body el-secondary">
              {!allowed && workspace && <p dir="auto">{plan ? `${workspace} is on ${name}. Its owner manages the plan.` : `${workspace}'s plan is managed by its owner.`}</p>}
              {isFree && allowed && <p dir="auto">Transcripts use your own free Groq key.</p>}
              {!isFree && price && <p dir="auto" className="el-tabular">{price}</p>}
              {!isFree && <p dir="auto">{plan?.expires_at ? `Paid until ${rowDateWithYear(plan.expires_at)}` : "No end date"}</p>}
              {plan?.trial_minutes_left ? <p dir="auto">{count(plan.trial_minutes_left)} trial minutes left</p> : null}
              {allowed && !open && top && <p dir="auto">You're on our top plan.</p>}
            </div>
            {allowed && !open && next && (
              <div className="el-plan-card__actions" id="setting-plan.upgrade" data-setting="plan.upgrade" tabIndex={-1}>
                <Button variant="primary" onClick={() => flow(next.key)}>{isFree ? "Upgrade" : `Upgrade to ${planName(next)}`}</Button>
                {isFree && above.length > 0 && <Button variant="quiet" onClick={scrollToPlans}>Compare plans</Button>}
              </div>
            )}
          </div>
        </Card>
        {!isFree && plan?.expires_at && <div className="el-group__note el-caption el-secondary"><p dir="auto">Your plan stops on {sentenceDate(plan.expires_at)}. There is nothing to cancel.</p></div>}
      </section>
      {request && requestShown(request) && (
        <RequestCardV2 e={e} request={request} onOpen={() => status("status")} onPay={() => status("pay")} onReceipt={() => status("receipt")} onCancel={() => setCancelling("ask")}
          onAgain={allowed && above.length > 0 ? () => flow(request.plan) : undefined}
          onDismiss={() => { dismissRequest(request.id); rerender((n) => n + 1); }} />
      )}
      <Group title="This month" footer={!ownKey ? <p dir="auto">Minutes reset on the 1st of each month. Unused minutes don't carry over.</p> : undefined}>
        {ownKey ? (
          <ListRow compact anchor="plan.usage.minutes" title="Transcription" value="Your Groq key"
            meta={e.transcription_key?.set ? "Key added" : "Not added"}
            control={e.transcription_key?.set ? undefined : <Button size="sm" onClick={onKey}>Add key</Button>} />
        ) : <MeterRow id="plan.usage.minutes" label="Transcription" limitKey="monthly_minutes" limit={minutes} quiet={quiet} onUpgrade={onUpgrade} />}
        {METERS.map(([key, label, id]) => {
          const limit = e.limits[key];
          if (!limit || limit.limit === null || limit.limit === undefined) return null;
          if (key === "seats" && (limit.limit ?? 0) <= 1) return null;
          return <MeterRow key={key} id={id} label={label} limitKey={key} limit={limit} quiet={quiet} onUpgrade={onUpgrade} />;
        })}
        {uploads && (percentOf(uploads) ?? 0) >= 80 && <MeterRow id="plan.usage.uploads" label="Uploads in the last 24 hours" limitKey="uploads_per_day" limit={uploads} quiet={quiet} onUpgrade={onUpgrade} />}
      </Group>
      <Group title="Included in your plan" footer={isFree ? <p dir="auto">Every plan can record, organise, search, share links and export.</p> : undefined}>
        {included.map(([key, label]) => {
          const value = e.limits[key]?.limit;
          if (value === undefined) return null;
          return <ListRow key={key} compact anchor={`plan.included.${key}`} title={label} value={limitText(key, value)} />;
        })}
        <ListRow compact anchor="plan.compare" title={setting("plan.compare").title} value={matrix ? "Shown below" : undefined} expanded={matrix} chevron onClick={() => setMatrix((m) => !m)} />
      </Group>
      {matrix && <FeatureTable e={e} />}
      {allowed && above.length > 0 && (
        <section className="el-group" id="plans-above" aria-labelledby="plans-head">
          <SectionTitle id="plans-head">Plans</SectionTitle>
          {above.map((p, i) => {
            const below = i > 0 ? above[i - 1] : current;
            return (
              <Card key={p.key}>
                <div className="el-plan-card" data-testid={`plan-${p.key}`}>
                  <div className="el-plan-card__head"><span className="el-title-s">{planName(p)}</span>{priceText(p) && <span className="el-secondary el-tabular">{priceText(p)}</span>}</div>
                  <div className="el-plan-card__body">
                    {i > 0 && <p dir="auto" className="el-secondary">Everything in {planName(above[i - 1])}, plus:</p>}
                    <ul className="el-plan-card__lines">{planHighlights(e, p, below).map((l) => <li key={l}>{l}</li>)}</ul>
                  </div>
                  {!open && <div className="el-plan-card__actions"><Button onClick={() => flow(p.key)}>Choose {planName(p)}</Button></div>}
                </div>
              </Card>
            );
          })}
        </section>
      )}
      {cancelling && (
        <Dialog open alert title="Cancel this request?" description="If you've already paid, add your receipt instead. Cancelling doesn't refund a payment."
          onClose={() => { setCancelling(null); setCancelError(null); }}
          actions={<>
            {cancelError && <p dir="auto" className="el-field__error" role="alert">{cancelError}</p>}
            <Button onClick={() => { setCancelling(null); setCancelError(null); }}>Keep request</Button>
            <Button variant="danger" loading={cancelling === "busy"} onClick={() => void cancel()}>Cancel request</Button>
          </>} />
      )}
      {allowed && (request || above.length > 0) && (
        <Group title="Payments" footer={<p dir="auto">Payments are by bank transfer. We switch your plan on after we see the money in our account.</p>}>
          {request && stage !== null && (
            <ListRow compact anchor="plan.history" title={`${planName(e.plans.find((p) => p.key === request.plan) ?? { key: request.plan, name: "" })}, 1 month`}
              meta={historyLine(request)} value={request.amount_due_pkr ? rupees(request.amount_due_pkr) : request.amount_due ?? undefined} chevron onClick={() => status("status")} />
          )}
          {!open && above.length > 0 && (
            <ListRow compact anchor="plan.promo" title={setting("plan.promo").title} value="Add" chevron onClick={() => openUpgradeSheet(null, { plan: next?.key ?? null, step: "choose" })} />
          )}
        </Group>
      )}
    </div>
  );
}
