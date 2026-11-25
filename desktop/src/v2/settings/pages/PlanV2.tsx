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
import { dateLocale, isRtl, language, lineDir, t, tc, withParts } from "../../../i18n";

/** The meters under Transcription: the limit, its name in the window's language, the row's id. */
const meters = (): readonly (readonly [string, string, string])[] => [
  ["storage_bytes", t("Storage"), "plan.usage.storage"],
  ["devices", t("Devices"), "plan.usage.devices"],
  ["seats", t("Team members"), "plan.usage.members"],
];

/**
 * "You've used 85% of your minutes.": what a meter at 80 % says, its words for each limit (minutes, storage, devices,
 * daily uploads; a team's seats never block, so never warn). `upgrade`: with the word Upgrade in its place (withParts).
 */
function usedLine(limitKey: string, percent: number, resets: string | null, upgrade: boolean): string {
  const date = resets ? rowDate(resets) : null;
  if (limitKey === "monthly_minutes") {
    if (upgrade) return date ? t("You've used :percent% of your minutes. :upgrade or wait until :date.", { percent, date }) : t("You've used :percent% of your minutes. :upgrade.", { percent });
    return date ? t("You've used :percent% of your minutes. They reset on :date.", { percent, date }) : t("You've used :percent% of your minutes.", { percent });
  }
  if (limitKey === "storage_bytes") return upgrade ? t("You've used :percent% of your storage. :upgrade.", { percent }) : t("You've used :percent% of your storage.", { percent });
  if (limitKey === "devices") return upgrade ? t("You've used :percent% of your devices. :upgrade.", { percent }) : t("You've used :percent% of your devices.", { percent });
  // D-230: the uploads a day are uploads (they were called team members).
  if (limitKey === "uploads_per_day") return upgrade ? t("You've used :percent% of your daily uploads. :upgrade.", { percent }) : t("You've used :percent% of your daily uploads.", { percent });
  return upgrade ? t("You've used :percent% of your team members. :upgrade.", { percent }) : t("You've used :percent% of your team members.", { percent });
}

/**
 * A plan's price as this page says it: the server's words in English; in another language the app's own, from the
 * amount when the server gives it (classic's upgrade sheet keeps priceText's English).
 */
function planPrice(plan: Parameters<typeof priceText>[0]): string | null {
  const pkr = typeof plan.price_pkr === "number" && plan.price_pkr > 0 ? Math.round(plan.price_pkr) : null;
  if (language() === "en" || pkr === null) return priceText(plan);
  return t("Rs :amount a month", { amount: pkr.toLocaleString("en-US") });
}

/**
 * The meter's value said in full for a screen reader: "230 of 600 minutes", "230 minutes" (the row shows "min"); the
 * others as the row shows them.
 */
function spokenUsage(limitKey: string, limit: LimitEntitlement, shown: string): string {
  if (limitKey !== "monthly_minutes") return shown;
  const used = limit.used ?? 0;
  return limit.limit
    ? tc(":used of :limit minutes", limit.limit, { used: count(used), limit: count(limit.limit) })
    : tc(":used minutes", used, { used: count(used) });
}

/** "1 December" for a sentence: English as it always was (the sentence date without its year); in Urdu its own. */
function dayAndMonth(iso: string): string {
  if (language() === "en") return sentenceDate(iso).replace(/ \d{4}$/, "");
  return new Date(iso.length === 10 ? `${iso}T00:00:00` : iso).toLocaleDateString(dateLocale(), { day: "numeric", month: "long" });
}

/** A word inside a sentence that acts ("upgrade"). */
function InlineAction({ children, onClick }: { children: ReactNode; onClick?: () => void }) {
  return <button type="button" className="el-inline-action" onClick={onClick}>{children}</button>;
}

/**
 * A line of the page's own words, in the direction of its words (lineDir): in Urdu "Pro پلان" starts with the plan's name
 * in its isolate, which the browsers' dir="auto" would read as left to right. English: dir="auto", as before.
 */
function Line({ text, className }: { text: string; className?: string }) {
  return <p dir={lineDir(text)} className={className}>{text}</p>;
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
  // The line's words (its direction is theirs: lineDir), and the line, with the word Upgrade as a button in it.
  let words: string | null = share === null ? (limitKey === "monthly_minutes" ? t("No monthly limit") : null) : resets ? t("Resets :date", { date: rowDate(resets) }) : null;
  let action: ReactNode = null;
  if (percent >= 100 && blocks) {
    words = limitKey === "monthly_minutes" ? (resets ? t("Transcription is paused until :date.", { date: rowDate(resets) }) : t("Transcription is paused."))
      : limitKey === "storage_bytes" ? (quiet ? t("New recordings can't upload until you free up space.") : t("New recordings can't upload until you free up space or :upgrade."))
        : limitKey === "devices" ? t("Sign out a device to add another.") : null;
    if (limitKey === "storage_bytes" && !quiet) action = <InlineAction onClick={onUpgrade}>{t("upgrade")}</InlineAction>;
  } else if (percent >= 80 && blocks) {
    words = usedLine(limitKey, percent, resets, !quiet && !!onUpgrade);
    if (!quiet && onUpgrade) action = <InlineAction onClick={onUpgrade}>{t("plan_context.upgrade")}</InlineAction>;
  }
  const line: ReactNode = words !== null && action ? withParts(words, { upgrade: action }) : words;
  const value = spokenUsage(limitKey, limit, text);
  const spoken = resets && share !== null
    ? t(":label, :value used, resets :date.", { label, value, date: dayAndMonth(resets) })
    : t(":label, :value used.", { label, value });
  return (
    <li className="el-row el-row--compact el-meter-row" id={`setting-${id}`} data-setting={id} tabIndex={-1} data-testid={`meter-${limitKey}`}>
      <span className="el-meter-row__line">
        <span className="el-row__title">{label}</span>
        {/* "230 of 600 min" left to right in English; in Urdu its own words' direction (":limit میں سے :used"). */}
        <span className={`el-meter-row__value${tone ? ` el-storage-value--${tone}` : ""}`} dir={isRtl() ? lineDir(text) : "ltr"}>{text}</span>
      </span>
      {share !== null
        ? <span className={`el-meter el-meter--wide${tone ? ` el-meter--${tone}` : ""}`} role="meter" aria-label={spoken} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
          <span className="el-meter__fill" style={fillWidth(percent / 100)} />
        </span>
        : <span className="el-visually-hidden">{spoken}</span>}
      {line && <span className={`el-caption ${tone ? `el-storage-value--${tone}` : "el-secondary"}`} dir={lineDir(words)}>{line}</span>}
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
  const paidAt = request.payment?.paid_at ?? request.timeline?.find((step) => step.step === "receipt_sent")?.at ?? null;
  const send = request.reference_code
    ? t("Send :amount to our bank account, then add a screenshot of the receipt. Reference :reference.", { amount: amount ?? t("the amount") })
    : t("Send :amount to our bank account, then add a screenshot of the receipt.", { amount: amount ?? t("the amount") });
  const received = paidAt ? `${t("We got your receipt on :date at :time.", { date: rowDate(paidAt), time: formatTime(new Date(paidAt)) })} ` : "";
  const thanks = until ? t("Thank you. :plan is on until :date.", { plan: name, date: sentenceDate(until) }) : t("Thank you. :plan is on.", { plan: name });
  type Shape = { title: string; tag: string; tone?: "warning" | "success"; body: ReactNode; primary?: [string, () => void]; secondary?: [string, () => void, boolean?] };
  const cards: Record<string, Shape> = {
    awaiting_payment: {
      title: t(":plan requested", { plan: name }), tag: t("Waiting for payment"),
      body: <p dir={lineDir(send)}>{request.reference_code ? withParts(send, { reference: <span className="el-mono" dir="ltr">{request.reference_code}</span> }) : send}</p>,
      primary: [t("Payment details"), onPay], secondary: [t("Cancel request"), onCancel, true],
    },
    in_review: {
      title: t("Checking your payment"), tag: t("In review"),
      body: <p dir={lineDir(`${received}${request.payment?.review_hours ?? t("We check receipts every day.")}`)}>{received}{request.payment?.review_hours ?? t("We check receipts every day.")}</p>,
      primary: [t("View request"), onOpen],
    },
    needs_receipt: {
      title: t("We need another receipt"), tag: t("Action needed"), tone: "warning",
      body: <>{request.payment?.proof_request?.note && <p dir="auto" className="el-pre-line">{request.payment.proof_request.note}</p>}<p dir={lineDir(t("Your request is still open. You don't need to pay again."))}>{t("Your request is still open. You don't need to pay again.")}</p></>,
      primary: [t("Add receipt"), onReceipt], secondary: [t("Payment details"), onPay],
    },
    declined: {
      title: t("We couldn't confirm your payment"), tag: t("Not approved"),
      body: <>{request.reply && <p dir="auto" className="el-pre-line">{request.reply}</p>}<p dir={lineDir(t("If you've paid, reply to our email with your transaction ID and we'll look again."))}>{t("If you've paid, reply to our email with your transaction ID and we'll look again.")}</p></>,
      primary: onAgain ? [t("Request again"), onAgain] : undefined, secondary: [t("Dismiss"), onDismiss],
    },
    approved: {
      title: t("You're on :plan", { plan: name }), tag: t("Approved"), tone: "success",
      body: <p dir={lineDir(thanks)}>{thanks}</p>,
      secondary: [t("Dismiss"), onDismiss],
    },
  };
  const card = stage ? cards[stage] : undefined;
  if (!card) return null;
  return (
    <section className="el-group" data-testid="upgrade-request" data-stage={stage} id="setting-plan.request" data-setting="plan.request" tabIndex={-1}>
      <Card>
        <div className="el-plan-card" role="status">
          {/* Named by what it shows (the title, then the stage), so the spoken name starts with the visible words. */}
          <button type="button" className="el-plan-card__head el-plan-card__head--link" onClick={onOpen}
            aria-label={t(":title, :status. Opens the request", { title: card.title, status: card.tag })}>
            <span className="el-title-s">{card.title}</span>
            <Badge tone={card.tone}>{card.tag}</Badge>
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
  // Plan names are names (LEAD 23): Free as written, in every language.
  const freeName = planName(e.plan?.key === "free" ? e.plan : { key: "free", name: "" });
  const columns: { key: string; name: string; has: (f: string) => boolean; limits: Record<string, number | null | undefined> }[] = [
    { key: "free", name: freeName, has: (f) => e.features[f]?.cost !== "paid", limits: e.plan?.key === "free" ? Object.fromEntries(Object.entries(e.limits).map(([k, v]) => [k, v.limit])) : {} },
    ...e.plans.filter((p) => p.key !== "free").map((p) => ({ key: p.key, name: planName(p), has: (f: string) => p.features.includes(f) || e.features[f]?.cost !== "paid", limits: p.limits })),
  ];
  const limits = ["storage_bytes", "retention_days", "devices", "max_file_minutes", "uploads_per_day"].filter((k) => columns.some((c) => c.limits[k] !== undefined));
  const limitName: Record<string, string> = { storage_bytes: t("Storage"), retention_days: t("Recordings kept"), devices: t("Devices"), max_file_minutes: t("Longest recording"), uploads_per_day: t("Uploads a day") };
  return (
    <section className="el-group">
      <Card>
        <div className="el-table-wrap">
          <table className="el-table" aria-label={t("Every feature")} data-testid="all-features">
            <thead><tr><th scope="col"><span className="el-visually-hidden">{t("Feature")}</span></th>{columns.map((c) => <th key={c.key} scope="col">{c.name}{c.key === e.plan?.key && <span className="el-secondary"> {t("(yours)")}</span>}</th>)}</tr></thead>
            <tbody>
              {Object.keys(e.features).map((f) => (
                <tr key={f} data-testid={`feature-${f}`}>
                  <th scope="row">{featureWords(e, f).name}</th>
                  {columns.map((c) => <td key={c.key}>{c.has(f) ? <span role="img" aria-label={t("Included")} className="el-table__tick"><Icons.CheckIcon /></span> : <span className="el-visually-hidden">{t("Not included")}</span>}</td>)}
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
    case "approved": return decided ? t("Approved on :date", { date: decided }) : t("Approved");
    case "declined": return decided ? t("Not approved on :date", { date: decided }) : t("Not approved");
    case "cancelled": return t("Cancelled by you");
    case "in_review": return since ? t("In review since :date", { date: since }) : t("In review");
    case "needs_receipt": return t("New receipt asked for");
    default: return since ? t("Waiting for payment since :date", { date: since }) : t("Waiting for payment");
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
  const name = plan ? planName(plan) : null;
  const isFree = !plan || plan.key === "free";
  const top = isTopPlan(e);
  const above = plansAbove(e);
  const allowed = canUpgrade(role);
  const quiet = !!open || !allowed;
  const current = e.plans.find((p) => p.key === plan?.key) ?? null;
  const price = current ? planPrice(current) : null;
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
  const title = t(":plan plan", { plan: isFree ? planName({ key: "free", name: "" }) : name });
  const included: [string, string][] = [["retention_days", t("Recordings kept")], ["max_file_minutes", t("Longest recording")], ["uploads_per_day", t("Uploads a day")], ["seats", t("Team members")]];
  return (
    <div className="el-settings-v2" data-testid="plan-usage">
      <section className="el-group" id="setting-plan.current" data-setting="plan.current" tabIndex={-1}>
        <Card>
          <div className="el-plan-card">
            <div className="el-plan-card__head"><span className="el-title-m" dir={lineDir(title)}>{title}</span></div>
            <div className="el-plan-card__body el-secondary">
              {!allowed && workspace && <Line text={name !== null ? t(":workspace is on :plan. Its owner manages the plan.", { workspace, plan: name })
                // No plan known (D-230): who manages it.
                : t(":workspace's plan is managed by its owner.", { workspace })} />}
              {isFree && allowed && <Line text={t("Transcripts use your own free Groq key.")} />}
              {!isFree && price && <Line className="el-tabular" text={price} />}
              {!isFree && <Line text={plan?.expires_at ? t("Paid until :date", { date: rowDateWithYear(plan.expires_at) }) : t("No end date")} />}
              {plan?.trial_minutes_left ? <Line text={tc(":minutes trial minutes left", plan.trial_minutes_left, { minutes: count(plan.trial_minutes_left) })} /> : null}
              {allowed && !open && top && <Line text={t("You're on our top plan.")} />}
            </div>
            {allowed && !open && next && (
              <div className="el-plan-card__actions" id="setting-plan.upgrade" data-setting="plan.upgrade" tabIndex={-1}>
                <Button variant="primary" onClick={() => flow(next.key)}>{isFree ? t("Upgrade") : t("Upgrade to :plan", { plan: planName(next) })}</Button>
                {isFree && above.length > 0 && <Button variant="quiet" onClick={scrollToPlans}>{t("Compare plans")}</Button>}
              </div>
            )}
          </div>
        </Card>
        {!isFree && plan?.expires_at && <div className="el-group__note el-caption el-secondary"><Line text={t("Your plan stops on :date. There is nothing to cancel.", { date: sentenceDate(plan.expires_at) })} /></div>}
      </section>
      {request && requestShown(request) && (
        <RequestCardV2 e={e} request={request} onOpen={() => status("status")} onPay={() => status("pay")} onReceipt={() => status("receipt")} onCancel={() => setCancelling("ask")}
          onAgain={allowed && above.length > 0 ? () => flow(request.plan) : undefined}
          onDismiss={() => { dismissRequest(request.id); rerender((n) => n + 1); }} />
      )}
      <Group title={t("This month")} footer={!ownKey ? <Line text={t("Minutes reset on the 1st of each month. Unused minutes don't carry over.")} /> : undefined}>
        {ownKey ? (
          <ListRow compact anchor="plan.usage.minutes" title={t("Transcription")} value={t("Your Groq key")}
            meta={e.transcription_key?.set ? t("Key added") : t("Not added")}
            control={e.transcription_key?.set ? undefined : <Button size="sm" onClick={onKey}>{t("Add key")}</Button>} />
        ) : <MeterRow id="plan.usage.minutes" label={t("Transcription")} limitKey="monthly_minutes" limit={minutes} quiet={quiet} onUpgrade={onUpgrade} />}
        {meters().map(([key, label, id]) => {
          const limit = e.limits[key];
          if (!limit || limit.limit === null || limit.limit === undefined) return null;
          if (key === "seats" && (limit.limit ?? 0) <= 1) return null;
          return <MeterRow key={key} id={id} label={label} limitKey={key} limit={limit} quiet={quiet} onUpgrade={onUpgrade} />;
        })}
        {uploads && (percentOf(uploads) ?? 0) >= 80 && <MeterRow id="plan.usage.uploads" label={t("Uploads in the last 24 hours")} limitKey="uploads_per_day" limit={uploads} quiet={quiet} onUpgrade={onUpgrade} />}
      </Group>
      <Group title={t("Included in your plan")} footer={isFree ? <Line text={t("Every plan can record, organise, search, share links and export.")} /> : undefined}>
        {included.map(([key, label]) => {
          const value = e.limits[key]?.limit;
          if (value === undefined) return null;
          return <ListRow key={key} compact anchor={`plan.included.${key}`} title={label} value={limitText(key, value)} />;
        })}
        <ListRow compact anchor="plan.compare" title={setting("plan.compare").title} value={matrix ? t("Shown below") : undefined} expanded={matrix} chevron onClick={() => setMatrix((m) => !m)} />
      </Group>
      {matrix && <FeatureTable e={e} />}
      {allowed && above.length > 0 && (
        <section className="el-group" id="plans-above" aria-labelledby="plans-head">
          <SectionTitle id="plans-head">{t("Plans")}</SectionTitle>
          {above.map((p, i) => {
            const below = i > 0 ? above[i - 1] : current;
            return (
              <Card key={p.key}>
                <div className="el-plan-card" data-testid={`plan-${p.key}`}>
                  <div className="el-plan-card__head"><span className="el-title-s">{planName(p)}</span>{planPrice(p) && <span className="el-secondary el-tabular">{planPrice(p)}</span>}</div>
                  <div className="el-plan-card__body">
                    {i > 0 && <Line className="el-secondary" text={t("Everything in :plan, plus:", { plan: planName(above[i - 1]) })} />}
                    {/* The server's highlights stay its English (the lead's call): each line in its own words' direction. */}
                    <ul className="el-plan-card__lines">{planHighlights(e, p, below).map((l) => <li key={l} dir={isRtl() ? lineDir(l) : undefined}>{l}</li>)}</ul>
                  </div>
                  {!open && <div className="el-plan-card__actions"><Button onClick={() => flow(p.key)}>{t("Choose :plan", { plan: planName(p) })}</Button></div>}
                </div>
              </Card>
            );
          })}
        </section>
      )}
      {cancelling && (
        <Dialog open alert title={t("Cancel this request?")} description={t("If you've already paid, add your receipt instead. Cancelling doesn't refund a payment.")}
          onClose={() => { setCancelling(null); setCancelError(null); }}
          actions={<>
            {cancelError && <p dir={lineDir(cancelError)} className="el-field__error" role="alert">{cancelError}</p>}
            <Button onClick={() => { setCancelling(null); setCancelError(null); }}>{t("Keep request")}</Button>
            <Button variant="danger" loading={cancelling === "busy"} onClick={() => void cancel()}>{t("Cancel request")}</Button>
          </>} />
      )}
      {allowed && (request || above.length > 0) && (
        <Group title={t("Payments")} footer={<Line text={t("Payments are by bank transfer. We switch your plan on after we see the money in our account.")} />}>
          {request && stage !== null && (
            <ListRow compact anchor="plan.history" title={t(":plan, 1 month", { plan: planName(e.plans.find((p) => p.key === request.plan) ?? { key: request.plan, name: "" }) })}
              meta={historyLine(request)} value={request.amount_due_pkr ? rupees(request.amount_due_pkr) : request.amount_due ?? undefined} chevron onClick={() => status("status")} />
          )}
          {!open && above.length > 0 && (
            <ListRow compact anchor="plan.promo" title={setting("plan.promo").title} value={t("Add")} chevron onClick={() => openUpgradeSheet(null, { plan: next?.key ?? null, step: "choose" })} />
          )}
        </Group>
      )}
    </div>
  );
}
