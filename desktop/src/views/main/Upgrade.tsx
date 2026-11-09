// Paid features and the plan (v1.6, P1.2/P1.4, docs/API.md "Entitlements", "Upgrade requests", "Your own
// transcription key"): ONE badge style ("PRO ✦", the plan's colour, a small lock) on a feature the plan leaves out;
// tapping it opens the one upgrade sheet (what the feature does, the plans side by side, Request upgrade with an
// optional message, then the payment instructions and "We'll activate it once confirmed"). Account → Plan & usage and
// Account → Transcription key (the Free plan's own Groq key, in three steps). An older server sends no entitlements:
// no badge, no sheet, and the v1.5 plan card stays.
import { useEffect, useId, useState, type CSSProperties } from "react";
import { api, errorMessage, type Entitlements, type LimitEntitlement, type OfferedPlan, type UpgradeRequest } from "../../lib/ipc";
// The sheet is opened through the store (lib/entitlements), so a badge anywhere opens the one sheet MainApp holds.
import {
  LIMIT_LABELS, PAID_FEATURES, badgeText, closeUpgradeSheet, featureWords, isLocked, lowestPlan, noteUpgradeRequest, openUpgradeSheet as openSheet,
  pendingRequest, refreshEntitlements, useEntitlements, useUpgradeSheet,
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
function PlanComparison({ e, plans, feature, chosen, onChoose }: {
  e: Entitlements;
  plans: OfferedPlan[];
  feature: string | null;
  chosen: string | null;
  onChoose: (key: string) => void;
}) {
  const rows = feature ? [feature, ...PAID_FEATURES.map(([k]) => k).filter((k) => k !== feature)] : PAID_FEATURES.map(([k]) => k);
  const limits = ["monthly_minutes", "devices", "storage_bytes", "retention_days"].filter((k) => plans.some((p) => k in p.limits));
  return (
    <div className="plan-compare" role="radiogroup" aria-label="Plans">
      {plans.map((p) => (
        <button key={p.key} type="button" role="radio" aria-checked={chosen === p.key} className={`plan-option ${chosen === p.key ? "chosen" : ""}`}
          style={badgeStyle(p.color)} onClick={() => onChoose(p.key)}>
          <span className="plan-option-head"><span className="plan-badge static">{badgeText(p)}</span>{p.price && <span className="small">{p.price}</span>}</span>
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
  return (
    <div className="upgrade-sent" role="status">
      <b>{request.status === "pending" ? `Your request for ${name} is pending` : `Request for ${name} sent`}</b>
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
  const words = feature ? featureWords(e, feature) : null;

  const send = async () => {
    if (!chosen) return;
    setBusy(true);
    setProblem(null);
    try {
      const made = await api.requestUpgrade(chosen, message.trim() || null);
      setSent(made);
      noteUpgradeRequest(made);
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
      {plans.length > 0 && <PlanComparison e={e} plans={plans} feature={feature} chosen={chosen} onChoose={setChosen} />}
      {sent ? <RequestSent request={sent} plans={e.plans} /> : (
        <>
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
      <ul className="plan-features" aria-label="Paid features">
        {PAID_FEATURES.filter(([key]) => e.features[key]).map(([key]) => (
          <li key={key} className={e.features[key].enabled ? "" : "off"}>
            {e.features[key].enabled ? <CheckIcon size={13} /> : <LockIcon size={13} />}
            <span>{featureWords(e, key).name}</span>
            <PlanBadge feature={key} />
          </li>
        ))}
      </ul>
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
          <button type="button" className="btn ghost compact" onClick={() => void api.openGroqConsole().catch((err) => setProblem(errorMessage(err)))}>Open {GROQ_KEYS}</button>
        </li>
        <li><span>Choose <b>API Keys</b>, then <b>Create API key</b>, and copy it.</span></li>
        <li>
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
