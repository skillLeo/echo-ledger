// Paid features and the plan (v1.6, P1.2/P1.4, docs/API.md "Entitlements", "Upgrade requests", "Your own
// transcription key"): ONE badge style ("PRO ✦", the plan's colour, a small lock) on a feature the plan leaves out;
// tapping it opens the one upgrade sheet (what the feature does, the plans side by side, Request upgrade with an
// optional message, then the payment instructions and "We'll activate it once confirmed"). Account → Plan & usage and
// Account → Transcription key (the Free plan's own Groq key, in three steps). An older server sends no entitlements:
// no badge, no sheet, and the v1.5 plan card stays.
import { useEffect, useId, useState, type CSSProperties } from "react";
import { api, errorMessage, type Entitlements, type LimitEntitlement, type OfferedPlan, type PromoCheck, type UpgradeRequest } from "../../lib/ipc";
// The sheet is opened through the store (lib/entitlements), so a badge anywhere opens the one sheet MainApp holds.
import {
  LIMIT_LABELS, PAID_FEATURES, allFeatures, badgeText, closeUpgradeSheet, featureWords, featuresSummary, isLocked, lockedBadge, lowestPlan, notePromoCodesMissing, noteUpgradeRequest, openSettingsSection, openUpgradeSheet as openSheet,
  pendingRequest, promoCodesMissing, promoPriceLine, refreshEntitlements, useEntitlements, useUpgradeSheet,
} from "../../lib/entitlements";
import { fileSize } from "../../lib/format";
import { fillWidth } from "../../lib/motion";
import { KeyIcon, LockIcon } from "../../components/Icons";
import { CheckIcon } from "../../components/ControlIcons";
import { Sheet } from "./ProjectDialogs";

export const ACTIVATE_ONCE_CONFIRMED = "We'll activate it once confirmed.";

/** The badge's colour as a style (only `#rrggbb` reaches the page; the core drops anything else). */
function badgeStyle(color: string | null | undefined): CSSProperties | undefined {
  return color && /^#[0-9a-f]{6}$/i.test(color) ? ({ "--badge": color } as CSSProperties) : undefined;
}

/** The one badge for a paid feature the plan leaves out ("PRO ✦"); nothing when the plan has it or the server is older. */
export function PlanBadge({ feature, onOpen }: { feature: string; onOpen?: (feature: string) => void }) {
  const e = useEntitlements();
  if (!isLocked(e, feature)) return null;
  const plan = lowestPlan(e, feature);
  const { name } = featureWords(e, feature);
  const open = (ev: React.MouseEvent) => {
    ev.preventDefault();
    ev.stopPropagation();
    (onOpen ?? openSheet)(feature);
  };
  return (
    <button type="button" className="plan-badge" style={badgeStyle(plan?.color)} onClick={open}
      aria-label={`${name} is in the ${plan?.name ?? "paid"} plan. See what it does`} title={`${name}: ${plan?.name ?? "a paid"} plan`}>
      <LockIcon size={11} /><span>{plan ? badgeText(plan) : "UPGRADE ✦"}</span>
    </button>
  );
}

/**
 * The same badge as words only, inside something that is itself the button (a project's locked tab, a menu item, a
 * palette row): that control opens the upgrade sheet.
 */
export function BadgeMark({ feature }: { feature: string }) {
  const badge = lockedBadge(useEntitlements(), feature);
  if (!badge) return null;
  return <span className="plan-badge static mark" style={badgeStyle(badge.color)} aria-hidden="true"><LockIcon size={11} /><span>{badge.text}</span></span>;
}

/**
 * A setting's switch for a feature the plan leaves out (v1.6.2): in view, off, with the badge; a click opens the
 * upgrade sheet and changes nothing.
 */
export function LockedToggle({ feature, label, hint }: { feature: string; label: string; hint?: string }) {
  const e = useEntitlements();
  const hintId = useId();
  if (!isLocked(e, feature)) return null;
  const plan = lowestPlan(e, feature)?.name ?? "paid";
  return (
    <div className="toggle locked-toggle" data-testid={`locked-toggle-${feature}`}>
      <div><div>{label}</div>{hint && <div id={hintId} className="small muted">{hint}</div>}</div>
      <span className="locked-toggle-end">
        <BadgeMark feature={feature} />
        <button type="button" className="switch" role="switch" aria-checked={false} aria-disabled="true" aria-label={`${label}, in the ${plan} plan`}
          aria-describedby={hint ? hintId : undefined} onClick={() => openSheet(feature)} />
      </span>
    </div>
  );
}

/** Whether the plan leaves `feature` out (an older server: never). */
export function useLocked(feature: string): boolean {
  return isLocked(useEntitlements(), feature);
}

/** A paid feature's place when the plan leaves it out: its name, what it does, and the badge. */
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

/** A locked feature's name with its badge, where the feature would be (the transcript's Show as views, say). */
export function LockedChip({ feature, label }: { feature: string; label: string }) {
  const e = useEntitlements();
  if (!isLocked(e, feature)) return null;
  return <span className="locked-chip small muted" data-testid={`locked-${feature}`}><span>{label}</span><PlanBadge feature={feature} /></span>;
}

/** A plan's limit for the comparison ("600 minutes", "No limit"). */
export function limitText(key: string, value: number | null | undefined): string {
  if (value === null) return "No limit";
  if (value === undefined) return "—";
  switch (LIMIT_LABELS.find(([k]) => k === key)?.[2]) {
    case "bytes":
      return fileSize(value);
    case "minutes":
      return value >= 120 && value % 60 === 0 ? `${value / 60} hours` : `${Math.round(value).toLocaleString("en-US")} minutes`;
    case "days":
      return `${value} days`;
    case "usd":
      return `$${value.toFixed(2)}`;
    default:
      return Math.round(value).toLocaleString("en-US");
  }
}

/** The plans side by side: price, what each has of the paid features, the main limits. */
function PlanComparison({ e, plans, feature, chosen, onChoose, promo = null }: {
  e: Entitlements;
  plans: OfferedPlan[];
  feature: string | null;
  chosen: string | null;
  onChoose: (key: string) => void;
  /** An applied promo code (v1.6.2): its plan's price line shows the price after it. */
  promo?: PromoCheck | null;
}) {
  const rows = feature ? [feature, ...PAID_FEATURES.map(([k]) => k).filter((k) => k !== feature)] : PAID_FEATURES.map(([k]) => k);
  const limits = ["monthly_minutes", "devices", "storage_bytes", "retention_days"].filter((k) => plans.some((p) => k in p.limits));
  return (
    <div className="plan-compare" role="radiogroup" aria-label="Plans">
      {plans.map((p) => (
        <button key={p.key} type="button" role="radio" aria-checked={chosen === p.key} className={`plan-option ${chosen === p.key ? "chosen" : ""}`}
          style={badgeStyle(p.color)} onClick={() => onChoose(p.key)}>
          <span className="plan-option-head"><span className="plan-badge static">{badgeText(p)}</span>
            {promo?.plan === p.key ? <span className="small promo-price" data-testid={`promo-price-${p.key}`}>{promoPriceLine(promo)}</span> : p.price && <span className="small">{p.price}</span>}
          </span>
          {p.description && <span className="small muted">{p.description}</span>}
          <span className="plan-option-list">
            {rows.filter((k) => p.features.includes(k) || k === feature).slice(0, 6).map((k) => (
              <span key={k} className={`small ${p.features.includes(k) ? "" : "muted"}`}>
                {p.features.includes(k) ? <CheckIcon size={12} /> : <LockIcon size={12} />}{featureWords(e, k).name}
              </span>
            ))}
            {limits.map((k) => (
              <span key={k} className="small muted">{LIMIT_LABELS.find(([l]) => l === k)?.[1]}: {limitText(k, p.limits[k])}</span>
            ))}
          </span>
        </button>
      ))}
    </div>
  );
}

/** What a sent (or pending) request says: the plan, the payment instructions, and that it is activated once confirmed. */
function RequestSent({ request, plans }: { request: UpgradeRequest; plans: OfferedPlan[] }) {
  const name = plans.find((p) => p.key === request.plan)?.name ?? request.plan;
  // A 100 % promo code (v1.6.2): approved at once, nothing to pay.
  if (request.status === "approved") {
    const on = plans.find((p) => p.key === (request.approved_plan || request.plan))?.name ?? request.approved_plan ?? name;
    return (
      <div className="upgrade-sent approved" role="status" data-testid="upgrade-approved">
        <b>Your {on} plan is on{request.approved_until ? ` until ${dateOf(request.approved_until)}` : ""}</b>
        {request.promo_code && <p className="small">With {request.promo_code}: nothing to pay.</p>}
      </div>
    );
  }
  return (
    <div className="upgrade-sent" role="status">
      <b>{request.status === "pending" ? `Your request for ${name} is pending` : `Request for ${name} sent`}</b>
      {request.amount_due && <p className="small" data-testid="amount-due">To pay: {request.amount_due}{request.promo_code ? ` with ${request.promo_code}` : ""}</p>}
      {request.payment_instructions && <p className="small upgrade-payment">{request.payment_instructions}</p>}
      <p className="small">{ACTIVATE_ONCE_CONFIRMED}</p>
    </div>
  );
}

/** The one upgrade sheet, held by MainApp and opened by any badge (or Plan & usage → See plans). */
export function UpgradeSheet() {
  const request = useUpgradeSheet();
  const e = useEntitlements();
  if (!request || !e) return null;
  return <UpgradeSheetBody key={request.seq} e={e} feature={request.feature} />;
}

function UpgradeSheetBody({ e, feature }: { e: Entitlements; feature: string | null }) {
  const plans = e.plans.filter((p) => p.key !== e.plan?.key);
  const lowest = feature ? lowestPlan(e, feature) : null;
  const [chosen, setChosen] = useState<string | null>(lowest?.key ?? plans[0]?.key ?? null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [sent, setSent] = useState<UpgradeRequest | null>(pendingRequest(e));
  const messageId = useId();
  const promoId = useId();
  const words = feature ? featureWords(e, feature) : null;
  // A promo code (v1.6.2): typed, checked for the plan shown, then sent with the request.
  const [promoOpen, setPromoOpen] = useState(false);
  const [promoText, setPromoText] = useState("");
  const [promo, setPromo] = useState<PromoCheck | null>(null);
  const [promoProblem, setPromoProblem] = useState<string | null>(null);
  const [otherPlan, setOtherPlan] = useState<{ plan: string; message: string } | null>(null);
  const [checking, setChecking] = useState(false);
  const [promoGone, setPromoGone] = useState(promoCodesMissing());

  const choose = (key: string) => {
    setChosen(key);
    setOtherPlan(null);
    if (promo && promo.plan !== key) setPromo(null);
  };
  const apply = async (plan: string | null = chosen) => {
    const code = promoText.trim();
    if (!code) return;
    setChecking(true);
    setPromoProblem(null);
    setOtherPlan(null);
    try {
      const answer = await api.checkPromoCode(code, plan);
      setPromo(answer.result === "valid" ? answer.check : null);
      if (answer.result === "valid" && answer.check.plan !== plan && plans.some((p) => p.key === answer.check.plan)) setChosen(answer.check.plan);
      if (answer.result === "invalid") setPromoProblem(answer.message);
      if (answer.result === "other_plan") setOtherPlan({ plan: answer.plan, message: answer.message });
      if (answer.result === "unsupported") {
        notePromoCodesMissing();
        setPromoGone(true);
      }
    } catch (err) {
      setPromo(null);
      setPromoProblem(errorMessage(err));
    } finally {
      setChecking(false);
    }
  };
  const switchTo = (plan: string) => {
    setChosen(plan);
    void apply(plan);
  };
  const otherPlanName = otherPlan ? plans.find((p) => p.key === otherPlan.plan)?.name ?? null : null;

  const send = async () => {
    if (!chosen) return;
    setBusy(true);
    setProblem(null);
    try {
      const made = await api.requestUpgrade(chosen, message.trim() || null, promo?.plan === chosen ? promo.code : null);
      setSent(made);
      noteUpgradeRequest(made);
      // A 100 % code switched the plan on: the badges go as soon as the plan is read again.
      if (made.status === "approved") void refreshEntitlements();
    } catch (err) {
      setProblem(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Sheet title={words ? words.name : "Plans"} onClose={closeUpgradeSheet} busy={busy} className="upgrade-sheet">
      {words?.description && <p className="small">{words.description}</p>}
      {words && lowest && <p className="small muted">In the {lowest.name} plan{plans.length > 1 ? " and above" : ""}. You are on {e.plan?.name ?? "your plan"}.</p>}
      {!words && e.plan && <p className="small muted">You are on {e.plan.name}.</p>}
      {plans.length > 0 && <PlanComparison e={e} plans={plans} feature={feature} chosen={chosen} onChoose={choose} promo={sent ? null : promo} />}
      {sent ? <RequestSent request={sent} plans={e.plans} /> : (
        <>
          {!promoGone && (promoOpen ? (
            <div className="promo-field" data-testid="promo-field">
              <label className="field" htmlFor={promoId}>Promo code</label>
              <div className="row promo-row">
                <input id={promoId} className="input" value={promoText} maxLength={24} spellCheck={false} autoComplete="off" autoCapitalize="characters"
                  onChange={(ev) => setPromoText(ev.target.value.toUpperCase())}
                  onKeyDown={(ev) => { if (ev.key === "Enter") { ev.preventDefault(); void apply(); } }} />
                <button type="button" className="btn" disabled={checking || !promoText.trim()} onClick={() => void apply()}>{checking ? "Checking…" : "Apply"}</button>
              </div>
              {promo && <p className="small promo-applied" role="status">{promo.code} applied{promo.percent ? `: ${promo.percent} % off` : ""}{promo.plan_name ? ` ${promo.plan_name}` : ""}.</p>}
              {promoProblem && <div className="notice error small" role="alert">{promoProblem}</div>}
              {otherPlan && (
                <div className="notice small row promo-other" role="status">
                  <span>{otherPlan.message}</span><span className="spacer" />
                  {otherPlanName && <button type="button" className="btn compact" onClick={() => switchTo(otherPlan.plan)}>Switch to {otherPlanName}</button>}
                </div>
              )}
            </div>
          ) : (
            <button type="button" className="btn text compact promo-link" onClick={() => setPromoOpen(true)}>Have a promo code?</button>
          ))}
          <label className="field" htmlFor={messageId}>Message for the owner (optional)
            <textarea id={messageId} className="input" rows={2} maxLength={1000} value={message} onChange={(ev) => setMessage(ev.target.value)}
              placeholder="Anything they should know, like who it is for" />
          </label>
          {problem && <div className="notice error small" role="alert">{problem}</div>}
        </>
      )}
      <div className="sheet-actions row">
        <span className="spacer" />
        <button type="button" className="btn ghost" onClick={closeUpgradeSheet} disabled={busy}>{sent ? "Done" : "Not now"}</button>
        {!sent && (
          <button type="button" className="btn primary" disabled={busy || !chosen} onClick={() => void send()}>
            {busy ? "Sending…" : "Request upgrade"}
          </button>
        )}
      </div>
    </Sheet>
  );
}

/** One limit with its meter: used next to what the plan allows. */
function LimitLine({ id, label, limit }: { id: string; label: string; limit: LimitEntitlement }) {
  const used = limit.used;
  const percent = limit.limit && limit.limit > 0 && used !== null ? Math.min(100, Math.round((used / limit.limit) * 100)) : null;
  const text = limit.limit === 0 ? "Not in your plan"
    : used === null ? limitText(id, limit.limit) : limit.limit === null ? `${limitText(id, used)} (no limit)` : `${limitText(id, used)} of ${limitText(id, limit.limit)}`;
  return (
    <div className="plan-limit">
      <div className="row"><span>{label}</span><span className="spacer" /><span className="small muted">{text}</span></div>
      {percent !== null && (
        <div className={`flow-bar ${percent >= 100 ? "full" : percent >= 80 ? "warn" : ""}`} role="meter" aria-label={`${label} used`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
          <span style={fillWidth(percent / 100)} />
        </div>
      )}
    </div>
  );
}

function dateOf(iso: string | null | undefined): string {
  if (!iso) return "";
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? iso : at.toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" });
}

/** Account → Plan & usage (v1.6): the plan with its badge, the limits used, the paid features and the latest request. */
export function PlanUsageCard({ e }: { e: Entitlements }) {
  const plan = e.plan;
  const request = e.upgrade_request;
  const requestName = request ? e.plans.find((p) => p.key === request.plan)?.name ?? request.plan : null;
  const limits = LIMIT_LABELS.filter(([key]) => e.limits[key]);
  return (
    <div className="card plan-card" data-testid="plan-usage">
      <div className="row">
        <b>Plan &amp; usage</b><span className="spacer" />
        {plan && <span className="plan-badge static" style={badgeStyle(plan.color)}>{plan.badge || plan.name}</span>}
      </div>
      {plan && (
        <p className="small muted">
          You are on {plan.name}{plan.expires_at ? ` until ${dateOf(plan.expires_at)}` : ""}.
          {plan.trial_minutes_left ? ` ${Math.round(plan.trial_minutes_left)} trial minutes left.` : ""}
        </p>
      )}
      {limits.map(([key, label]) => <LimitLine key={key} id={key} label={label} limit={e.limits[key]} />)}
      {Object.keys(e.features).length > 0 && (
        <div className="row plan-features-summary" data-testid="plan-features-summary">
          <span className="small">{featuresSummary(e)}.</span><span className="spacer" />
          <button type="button" className="btn compact ghost" onClick={() => openSettingsSection("all-features")}>See all features</button>
        </div>
      )}
      {request && (
        <div className={`notice small upgrade-request ${request.status}`} role="status" data-testid="upgrade-request">
          {request.status === "pending" && <>Your request for {requestName} is pending. {ACTIVATE_ONCE_CONFIRMED}</>}
          {request.status === "approved" && <>Your request for {requestName} was approved{request.decided_at ? ` on ${dateOf(request.decided_at)}` : ""}.</>}
          {request.status === "declined" && <>Your request for {requestName} was declined{request.reply ? `: ${request.reply}` : "."}</>}
          {request.status === "pending" && request.payment_instructions && <p className="upgrade-payment">{request.payment_instructions}</p>}
        </div>
      )}
      {e.plans.some((p) => p.key !== plan?.key) && (
        <div className="row organise-field"><span className="spacer" /><button type="button" className="btn" onClick={() => openSheet(null)}>See plans</button></div>
      )}
    </div>
  );
}

/**
 * Account → Plan & usage → All features (v1.6.2): every feature the server names, in its registry's order, with what
 * it does; a tick when the plan has it, otherwise the one badge, which opens the upgrade sheet for that feature (never
 * the feature: the server still refuses it with `feature_not_in_plan`).
 */
export function AllFeaturesCard({ e }: { e: Entitlements }) {
  const { keys } = allFeatures(e);
  return (
    <div className="card all-features" data-testid="all-features">
      <div className="row"><b>All features</b></div>
      <p className="small muted" data-testid="all-features-summary">{featuresSummary(e)}</p>
      <ul className="feature-list" aria-label="All features">
        {keys.map((key) => {
          const included = e.features[key].enabled;
          const words = featureWords(e, key);
          return (
            <li key={key} className={included ? "included" : "locked"} data-testid={`feature-${key}`}>
              <span className="feature-words">
                <span className="feature-name">{words.name}</span>
                {words.description && <span className="small muted">{words.description}</span>}
              </span>
              {included
                ? <span className="feature-included" role="img" aria-label={`${words.name} is in your plan`} title="In your plan"><CheckIcon size={15} /></span>
                : <PlanBadge feature={key} />}
            </li>
          );
        })}
      </ul>
    </div>
  );
}

const GROQ_KEYS = "console.groq.com";

/** Account → Transcription key (v1.6, the Free plan): the person's own free Groq key in three steps. */
export function TranscriptionKeyCard({ e }: { e: Entitlements }) {
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
    if (!window.confirm("Remove your Groq key? New recordings are not transcribed until you add a key again.")) return;
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

  return (
    <div className="card transcription-key" data-testid="transcription-key">
      <div className="row"><KeyIcon size={18} /><b>Transcription key</b><span className="spacer" />
        {key?.set && <span className="chip small">Key added · ending {key.last4 ?? "…"}</span>}
      </div>
      <p className="small muted">Your plan transcribes with your own free Groq key. It is kept encrypted on the server, only for your recordings, and never shown again.</p>
      <ol className="key-steps">
        <li>
          <span>Open <b>{GROQ_KEYS}</b> and sign in (it is free).</span>
          <button type="button" className="btn compact" onClick={() => void api.openGroqConsole().catch((err) => setProblem(errorMessage(err)))}>Open {GROQ_KEYS}</button>
        </li>
        <li><span>Choose <b>API Keys</b>, then <b>Create API key</b>, and copy it.</span></li>
        <li>
          <span>Paste it here and press <b>Save</b>.</span>
          <form className="row key-paste" onSubmit={(ev) => void save(ev)}>
            <label htmlFor={fieldId} className="sr-only">Paste your Groq key</label>
            <input id={fieldId} className="input" type="password" autoComplete="off" spellCheck={false} placeholder={key?.set ? "Paste a new key to replace it" : "Paste your key (gsk_…)"}
              value={value} onChange={(ev) => setValue(ev.target.value)} />
            <button type="submit" className="btn primary" disabled={busy || !value.trim()}>{busy ? "Checking…" : "Save"}</button>
          </form>
        </li>
      </ol>
      {saved && <div className="notice small" role="status">Key saved. New recordings are transcribed with it.</div>}
      {problem && <div className="notice error small" role="alert">{problem}</div>}
      {key?.set && <div className="row"><span className="spacer" /><button type="button" className="btn ghost danger small" disabled={busy} onClick={() => void remove()}>Remove key</button></div>}
    </div>
  );
}

/** A recording whose transcript failed because there is no key yet (`needs_transcription_key`). */
export const NEEDS_KEY_TEXT = "This recording was not transcribed: your plan uses your own free Groq key, and none is added yet.";
