// The upgrade flow (v1.7, docs/API.md "Upgrade requests", "Payment receipts"): one sheet, 560 px, with a header, a body
// that scrolls and a footer that stays. Upgrade (choose a plan, a promo code, a note) → Pay for {plan} (the bank
// accounts with Copy, the exact amount, the reference) → Send receipt (up to 3 screenshots or PDFs per round: choose,
// drop or paste; the transaction ID and the payer) → the request's status (a timeline, the details, the receipts,
// Cancel request). While a request is open the sheet opens on it: there is never a second request or an "Upgrade".
import { createPortal } from "react-dom";
import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from "react";
import { api, errorMessage, listenForFileDrops, type Entitlements, type OfferedPlan, type PromoCheck, type Proof, type UpgradeRequest } from "../../lib/ipc";
import {
  closeUpgradeSheet, featureWords, isLocked, lowestPlan, noteUpgradeRequest, openRequest, planName, plansAbove, priceText, promoCodesMissing, notePromoCodesMissing,
  refreshEntitlements, refreshUpgradeRequest, requestStage, useEntitlements, useUpgradeSheet, type SheetStep,
} from "../../lib/entitlements";
import { claimFileDrops } from "../../lib/dropTarget";
import { fileSize, formatTime, rowDate, rowDateWithYear, rupees, sentenceDate } from "../../lib/format";
import { reducedMotion } from "../../lib/motion";
import { CheckIcon } from "../../components/ControlIcons";
import { LucideCopyIcon, LucideCheckIcon, LucideFileTextIcon, LucideXIcon, LucideUploadIcon } from "../../components/Icons";
import { ConfirmDialog, useCopied } from "./SettingsControls";
import { RequestCard } from "./Upgrade";

/** The most files one round sends (a round is the first send, then each new receipt staff ask for). */
export const ROUND_MAX = 3;
export const MAX_BYTES = 5 * 1024 * 1024;
const ACCEPTS = ["image/jpeg", "image/png", "image/webp", "application/pdf"];
const EXTENSIONS = /\.(jpe?g|png|webp|pdf)$/i;
export const TOO_LARGE = "This file is over 5 MB. Try a screenshot instead.";
export const WRONG_TYPE = "Send a screenshot or photo (JPEG, PNG or WebP) or a PDF.";
export const ROUND_FULL = "You can add up to 3 files at a time. Remove one to add another.";
export const NO_PROOF = "Add a screenshot, or type the transaction ID.";
const REFERENCE = /^[A-Za-z0-9 ._/#-]{4,64}$/;

/** The one upgrade sheet, held by MainApp and opened by a tag, a button or a row anywhere. */
export function UpgradeSheet() {
  const request = useUpgradeSheet();
  const e = useEntitlements();
  if (!request || !e) return null;
  return <UpgradeFlow key={request.seq} e={e} feature={request.feature} plan={request.plan} step={request.step} />;
}

/** The sheet's frame: a header with the title and ×, the body that scrolls, a footer that stays. */
function FlowSheet({ title, onClose, guard, footer, children }: { title: string; onClose: () => void; guard: () => boolean; footer?: ReactNode; children: ReactNode }) {
  const titleId = useId();
  const close = () => {
    if (guard()) onClose();
  };
  useEffect(() => {
    const onKey = (ev: KeyboardEvent) => {
      if (ev.key === "Escape") close();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  });
  return createPortal(
    // A click beside the sheet is ignored while a field has text (nothing typed is lost by a stray click).
    <div className="sheet-backdrop" onMouseDown={(ev) => { if (ev.target === ev.currentTarget && guard()) onClose(); }}>
      <div className="sheet card upgrade-sheet flow-sheet" role="dialog" aria-modal="true" aria-labelledby={titleId}>
        <div className="flow-head">
          <h2 id={titleId} className="sheet-title">{title}</h2>
          <button type="button" className="btn ghost icon-only" aria-label="Close" onClick={() => onClose()}><LucideXIcon size={18} /></button>
        </div>
        <div className="flow-body">{children}</div>
        {footer && <div className="flow-foot">{footer}</div>}
      </div>
    </div>,
    document.body,
  );
}

/** "PK00 XXXX 0000 0001 2345 6789": an IBAN in groups of four. */
export function ibanGroups(iban: string): string {
  return iban.replace(/\s+/g, "").replace(/(.{4})/g, "$1 ").trim();
}

/** The amount to pay in rupees: the request's, else the plan's price. */
function amountOf(request: UpgradeRequest | null, plan: OfferedPlan | null): number | null {
  if (request?.amount_due_pkr) return request.amount_due_pkr;
  const digits = request?.amount_due?.replace(/[^\d]/g, "");
  if (digits) return Number(digits);
  return typeof plan?.price_pkr === "number" ? plan.price_pkr : null;
}

function UpgradeFlow({ e, feature, plan, step }: { e: Entitlements; feature: string | null; plan: string | null; step: SheetStep | null }) {
  const open = openRequest(e);
  const known = e.upgrade_request;
  const [request, setRequest] = useState<UpgradeRequest | null>(open ?? (step && step !== "choose" ? known : null));
  const initial: SheetStep | "approved" = request
    ? (step && step !== "choose" ? step : "status")
    : "choose";
  const [at, setAt] = useState<SheetStep | "approved">(initial);
  const [dirty, setDirty] = useState(false);
  // The request as the server last said it (the store merges `latest`'s full copy in).
  useEffect(() => {
    if (request && known && known.id === request.id) setRequest((r) => (r ? { ...r, ...known, payment: known.payment ?? r.payment } : r));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [known]);
  useEffect(() => {
    if (request && requestStage(request) !== "approved") void refreshUpgradeRequest();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [request?.id]);
  const guard = () => !dirty || window.confirm("Close without sending? What you typed here is not kept.");
  const update = (r: UpgradeRequest) => {
    setRequest(r);
    noteUpgradeRequest(r);
  };
  const name = request ? planName(e.plans.find((p) => p.key === request.plan) ?? { key: request.plan, name: "" }) : "";
  if (at === "choose") {
    return <ChooseStep e={e} feature={feature} plan={plan} guard={guard} onDirty={setDirty} onMade={(r) => {
      update(r);
      setDirty(false);
      setAt(r.status === "approved" ? "approved" : requestStage(r) === "awaiting_payment" ? "pay" : "status");
    }} />;
  }
  if (!request) return null;
  if (at === "approved") return <ApprovedStep e={e} request={request} />;
  if (at === "pay") return <PayStep e={e} request={request} name={name} guard={guard} onReceipt={() => setAt("receipt")} onBack={() => setAt("status")} />;
  if (at === "receipt") {
    return <ReceiptStep request={request} guard={guard} onDirty={setDirty} onUpdate={update} onSent={(r) => { update(r); setDirty(false); setAt("status"); }} />;
  }
  return <StatusStep e={e} request={request} name={name} onPay={() => setAt("pay")} onReceipt={() => setAt("receipt")} onUpdate={update}
    onAgain={() => { setRequest(null); setAt("choose"); }} />;
}

// ---- Step 1: Upgrade ------------------------------------------------------------------------------------------------

function ChooseStep({ e, feature, plan, guard, onDirty, onMade }: {
  e: Entitlements;
  feature: string | null;
  plan: string | null;
  guard: () => boolean;
  onDirty: (dirty: boolean) => void;
  onMade: (r: UpgradeRequest) => void;
}) {
  const plans = plansAbove(e);
  const lowest = feature ? lowestPlan(e, feature) : null;
  const first = plans.find((p) => p.key === plan)?.key ?? plans.find((p) => p.key === lowest?.key)?.key ?? plans[0]?.key ?? null;
  const [chosen, setChosen] = useState<string | null>(first);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [promoOpen, setPromoOpen] = useState(false);
  const [promoText, setPromoText] = useState("");
  const [promo, setPromo] = useState<PromoCheck | null>(null);
  const [promoProblem, setPromoProblem] = useState<string | null>(null);
  const [checking, setChecking] = useState(false);
  const [promoGone, setPromoGone] = useState(promoCodesMissing());
  const noteId = useId();
  const promoId = useId();
  const current = e.plan ? planName(e.plan) : "your plan";
  const chosenPlan = plans.find((p) => p.key === chosen) ?? null;
  const chosenName = chosenPlan ? planName(chosenPlan) : "";
  const codeFits = !!promo && promo.plan === chosen;
  useEffect(() => onDirty(!!note.trim() || !!promoText.trim()), [note, promoText, onDirty]);

  const apply = async () => {
    const code = promoText.trim().toUpperCase();
    if (code.length < 6 || code.length > 24) return;
    setChecking(true);
    setPromoProblem(null);
    try {
      const answer = await api.checkPromoCode(code, chosen);
      if (answer.result === "valid") {
        setPromo(answer.check);
        setPromoOpen(false);
        if (answer.check.plan !== chosen && plans.some((p) => p.key === answer.check.plan)) setChosen(answer.check.plan);
      } else if (answer.result === "other_plan") {
        setPromoProblem(`This code is for ${planName(plans.find((p) => p.key === answer.plan) ?? { key: answer.plan, name: "" })}.`);
      } else if (answer.result === "invalid") {
        setPromoProblem("We don't know this code, or it can't be used any more. Check the spelling.");
      } else {
        notePromoCodesMissing();
        setPromoGone(true);
      }
    } catch (err) {
      setPromoProblem(errorMessage(err));
    } finally {
      setChecking(false);
    }
  };
  const send = async () => {
    if (!chosen) return;
    setBusy(true);
    setProblem(null);
    try {
      const made = await api.requestUpgrade(chosen, note.trim() || null, codeFits ? promo!.code : null);
      if (made.status === "approved") void refreshEntitlements();
      onMade(made);
    } catch (err) {
      setProblem(errorMessage(err));
      setBusy(false);
    }
  };
  const full = chosenPlan?.price_pkr ?? null;
  const discounted = codeFits && promo?.final_price ? Number(promo.final_price.replace(/[^\d]/g, "")) : null;
  const free = codeFits && promo?.free;
  const words = feature ? featureWords(e, feature) : null;
  const proToUltra = e.plan && e.plan.key !== "free" && e.plan.expires_at;
  const footer = (
    <div className="flow-bar">
      <div className="flow-pay">
        <span className="small muted">To pay</span>
        <span className="flow-amount tabular">
          {free ? "Rs 0" : discounted !== null && !Number.isNaN(discounted) ? <>{rupees(discounted)} {full ? <s className="muted">{rupees(full)}</s> : null}</> : full ? rupees(full) : priceText(chosenPlan ?? { price: null }) ?? ""}
        </span>
        {codeFits && !free && <span className="small muted">for the first month</span>}
      </div>
      <div className="flow-go">
        <button type="button" className="btn primary" disabled={busy || !chosen} onClick={() => void send()}>
          {busy ? "Sending…" : free ? `Switch on ${chosenName}` : `Request ${chosenName}`}
        </button>
      </div>
      {!free && <p className="small muted flow-under">You'll see our bank details on the next screen.</p>}
      {problem && <p className="small error-text flow-under" role="alert">{problem}</p>}
    </div>
  );
  return (
    <FlowSheet title="Upgrade" onClose={closeUpgradeSheet} guard={guard} footer={plans.length > 0 ? footer : undefined}>
      {words && lowest
        ? <p>{words.name} is in {planName(lowest)}. You're on {current}.</p>
        : <p>You're on {current}.</p>}
      {proToUltra && <p className="muted">{chosenName || "The new plan"} starts when we approve your payment. Days left on {current} aren't refunded.</p>}
      {plans.length === 0 && <p className="muted">There's no plan above yours to choose.</p>}
      <div className="plan-radios" role="radiogroup" aria-label="Plans">
        {plans.map((p, i) => {
          const selected = chosen === p.key;
          return (
            <button key={p.key} type="button" role="radio" aria-checked={selected} className={`plan-radio ${selected ? "chosen" : ""}`}
              aria-label={`${planName(p)}, ${priceText(p) ?? "price not set"}${selected ? ", selected" : ""}`}
              onClick={() => { setChosen(p.key); setPromoProblem(promo && promo.plan !== p.key ? `This code is for ${planName(plans.find((x) => x.key === promo.plan) ?? { key: promo.plan, name: "" })}.` : null); }}>
              <span className="radio-dot" aria-hidden="true" />
              <span className="plan-radio-main">
                <span className="plan-card-name">{planName(p)}</span>
                {priceText(p) && <span className="muted tabular">{priceText(p)}</span>}
                <span className="plan-lines">
                  {i > 0 && <span className="muted">Everything in {planName(plans[i - 1])}, plus:</span>}
                  {highlightsFor(e, p, i > 0 ? plans[i - 1] : null).map((l) => <span key={l}>{l}</span>)}
                </span>
              </span>
            </button>
          );
        })}
      </div>
      {!promoGone && plans.length > 0 && (
        <div className="set-group flow-group">
          {promoOpen ? (
            <form className="set-row set-form" onSubmit={(ev) => { ev.preventDefault(); void apply(); }} data-testid="promo-field">
              <label htmlFor={promoId} className="set-title">Code</label>
              <input id={promoId} className="input" value={promoText} maxLength={24} spellCheck={false} autoComplete="off" autoFocus
                onChange={(ev) => setPromoText(ev.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, ""))} />
              <button type="button" className="btn ghost compact" onClick={() => { setPromoOpen(false); setPromoProblem(null); }}>Cancel</button>
              <button type="submit" className="btn compact" disabled={checking || promoText.trim().length < 6}>{checking ? "Checking…" : "Apply"}</button>
            </form>
          ) : (
            <button type="button" className="set-row set-link" onClick={() => setPromoOpen(true)}>
              <span className="set-title">Promo code</span><span className="set-end"><span className="set-value">{promo ? promo.code : "Add"}</span></span>
            </button>
          )}
          {codeFits && <p className="set-sub success-text promo-applied" role="status">{promo!.code}: {promo!.percent ?? 0}% off {planName(plans.find((p) => p.key === promo!.plan) ?? { key: promo!.plan, name: promo!.plan_name ?? "" })} for {promo!.months && promo!.months > 1 ? `${promo!.months} months` : "1 month"}</p>}
          {promoProblem && <p className="set-sub error-text" role="alert">{promoProblem}</p>}
        </div>
      )}
      {plans.length > 0 && (
        <label className="field" htmlFor={noteId}>Note for our team (optional)
          <textarea id={noteId} className="input" rows={2} maxLength={500} value={note} onChange={(ev) => setNote(ev.target.value)} placeholder="For example, an invoice in my company's name" />
        </label>
      )}
    </FlowSheet>
  );
}

/** A plan card's lines, the same as on Plan & usage. */
function highlightsFor(e: Entitlements, p: OfferedPlan, below: OfferedPlan | null): string[] {
  const own = (p.highlights ?? []).filter((h) => h.trim());
  if (own.length > 0) return own.slice(0, 5);
  const lines: string[] = [];
  const minutes = p.limits.monthly_minutes;
  if (typeof minutes === "number" && minutes > 0) lines.push(`${minutes.toLocaleString("en-US")} transcription minutes a month`);
  const seats = p.limits.seats;
  if (typeof seats === "number" && seats > 1) lines.push(`A team of up to ${seats} people`);
  const before = new Set(below?.features ?? Object.keys(e.features).filter((k) => !isLocked(e, k)));
  for (const key of p.features) {
    if (lines.length >= 5) break;
    if (!before.has(key) && key !== "platform_transcription" && key !== "team") lines.push(featureWords(e, key).name);
  }
  return lines;
}

// ---- Step 2: Pay for {plan} -----------------------------------------------------------------------------------------

function CopyRow({ label, value, copyText, copied, onCopy, mono = false }: { label: string; value: string; copyText: string; copied: boolean; onCopy: () => void; mono?: boolean }) {
  return (
    <div className="set-row pay-row">
      <span className="set-main">
        <span className="set-sub">{label}</span>
        <span className={`pay-value ${mono ? "mono" : ""}`.trim()}>{value}</span>
      </span>
      <button type="button" className="btn ghost icon-only" aria-label={`Copy ${label.toLowerCase()}`} title={copied ? "Copied" : `Copy ${label.toLowerCase()}`} onClick={onCopy}
        data-copied={copied || undefined}>
        {copied ? <LucideCheckIcon size={18} /> : <LucideCopyIcon size={18} />}
      </button>
      {copyText && <span className="sr-only" aria-live="polite">{copied ? "Copied" : ""}</span>}
    </div>
  );
}

function PayStep({ e, request, name, guard, onReceipt, onBack }: { e: Entitlements; request: UpgradeRequest; name: string; guard: () => boolean; onReceipt: () => void; onBack: () => void }) {
  const [copied, copy] = useCopied();
  const amount = amountOf(request, e.plans.find((p) => p.key === request.plan) ?? null);
  const payment = request.payment;
  const accounts = payment?.accounts ?? [];
  const title = accounts.find((a) => a.title)?.title ?? null;
  const open = ["awaiting_payment", "needs_receipt"].includes(requestStage(request) ?? "");
  const footer = (
    <div className="flow-bar">
      {open && <p className="small muted flow-caption">Paid? Add a screenshot of the receipt so we can check it.</p>}
      <div className="flow-go">
        {open && <button type="button" className="btn text" onClick={() => closeUpgradeSheet()}>I'll pay later</button>}
        {!open && <button type="button" className="btn text" onClick={onBack}>Back</button>}
        {open && <button type="button" className="btn primary" onClick={onReceipt}>Add payment receipt</button>}
      </div>
    </div>
  );
  return (
    <FlowSheet title={`Pay for ${name}`} onClose={closeUpgradeSheet} guard={guard} footer={footer}>
      {amount !== null && (
        <div className="set-group flow-group pay-amount">
          <div className="set-row pay-row">
            <span className="set-main">
              <span className="set-sub">Amount</span>
              <span className="flow-amount tabular">{rupees(amount)}</span>
            </span>
            <button type="button" className="btn ghost icon-only" aria-label="Copy amount" onClick={() => void copy("amount", String(Math.round(amount)))}>
              {copied === "amount" ? <LucideCheckIcon size={18} /> : <LucideCopyIcon size={18} />}
            </button>
          </div>
          <p className="set-sub pay-line">Send exactly this amount. A different amount can slow down your upgrade.</p>
        </div>
      )}
      {accounts.length > 0 ? (
        accounts.map((a, i) => (
          <section key={i} className="set-section" aria-label={accounts.length > 1 ? `Pay to, account ${i + 1}` : "Pay to"}>
            <h3 className="set-head">{accounts.length > 1 ? `Pay to, account ${i + 1}` : "Pay to"}</h3>
            <div className="set-group">
              {a.title && <CopyRow label="Account title" value={a.title} copyText={a.title} copied={copied === `title-${i}`} onCopy={() => void copy(`title-${i}`, a.title!)} />}
              {a.account_number && <CopyRow label="Account number" value={a.account_number} copyText={a.account_number} mono copied={copied === `number-${i}`} onCopy={() => void copy(`number-${i}`, a.account_number!.replace(/\D/g, ""))} />}
              {a.iban && <CopyRow label="IBAN" value={ibanGroups(a.iban)} copyText={a.iban} mono copied={copied === `iban-${i}`} onCopy={() => void copy(`iban-${i}`, a.iban!.replace(/\s+/g, ""))} />}
              {a.bank && <CopyRow label="Bank" value={a.branch ? `${a.bank}, ${a.branch}` : a.bank} copyText={a.bank} copied={copied === `bank-${i}`} onCopy={() => void copy(`bank-${i}`, a.bank!)} />}
            </div>
          </section>
        ))
      ) : (
        <div className="set-group flow-group"><p className="pre-line set-block" data-testid="payment-note">{payment?.note || request.payment_instructions || "We'll email you our bank details."}</p></div>
      )}
      {request.reference_code && (
        <section className="set-section" aria-label="Reference">
          <h3 className="set-head">Reference</h3>
          <div className="set-group">
            <CopyRow label="Reference" value={request.reference_code} copyText={request.reference_code} mono copied={copied === "reference"} onCopy={() => void copy("reference", request.reference_code!)} />
          </div>
          <div className="set-foot"><p>If your bank app has a note or remarks box, write this. If you forget, that's fine. Your receipt is enough.</p></div>
        </section>
      )}
      <p>Pay from any bank app, JazzCash, Easypaisa, NayaPay or SadaPay.</p>
      {title && <p>Your app shows the account title before you confirm. It should say {title}. If it doesn't, stop and contact us.</p>}
      {accounts.length > 0 && payment?.note && <p className="pre-line muted" data-testid="payment-note">{payment.note}</p>}
    </FlowSheet>
  );
}

// ---- Step 3: Send receipt -------------------------------------------------------------------------------------------

/** A receipt of this round: being sent, sent to the server (not locked yet), or refused. */
interface LocalReceipt {
  key: string;
  name: string;
  /** What to send again on "Try again". */
  source: { path: string } | { file: File };
  state: "uploading" | "failed";
  error?: string;
  preview?: string;
}

/** Whether a picked or pasted file can be a receipt; the reason when it can't (the core checks the bytes again). */
export function receiptProblem(file: { name: string; type?: string; size?: number }): string | null {
  if (typeof file.size === "number" && file.size > MAX_BYTES) return TOO_LARGE;
  if (file.type ? !ACCEPTS.includes(file.type) : !EXTENSIONS.test(file.name)) return WRONG_TYPE;
  return null;
}

/** Base64 of a file's bytes, for the core. */
async function base64Of(file: File): Promise<string> {
  const bytes = new Uint8Array(await file.arrayBuffer());
  let text = "";
  for (let i = 0; i < bytes.length; i += 0x8000) text += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  return btoa(text);
}

/** A receipt's picture from the core as a `blob:` URL, let go when the tile goes. */
function useReceiptPicture(requestId: string, proof: Proof | null): string | null {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    if (!proof || proof.kind === "pdf") return;
    let made: string | null = null;
    let alive = true;
    api.receiptFile(requestId, proof.id, "thumb").then((f) => {
      if (!alive) return;
      const bytes = Uint8Array.from(atob(f.data), (c) => c.charCodeAt(0));
      made = URL.createObjectURL(new Blob([bytes], { type: f.mime }));
      setUrl(made);
    }, () => undefined);
    return () => {
      alive = false;
      if (made) URL.revokeObjectURL(made);
    };
  }, [requestId, proof]);
  return url;
}

function ReceiptTile({ requestId, proof, local, index, total, onRemove, onRetry }: {
  requestId: string;
  proof?: Proof;
  local?: LocalReceipt;
  index: number;
  total: number;
  onRemove?: () => void;
  onRetry?: () => void;
}) {
  const picture = useReceiptPicture(requestId, proof ?? null);
  const pdf = proof ? proof.kind === "pdf" : !!local && /\.pdf$/i.test(local.name);
  const name = proof?.name ?? local?.name ?? "Receipt";
  const sent = proof?.locked;
  const label = `Receipt ${index + 1} of ${total}, ${pdf ? "PDF" : "image"}${onRemove ? ", remove" : ""}`;
  return (
    <li className={`receipt-tile ${local?.state ?? ""}`.trim()} data-testid="receipt-tile" aria-label={label}>
      <span className="receipt-face">
        {local?.state === "failed" ? <span className="receipt-error small">Couldn't upload</span>
          : pdf ? <span className="receipt-pdf"><LucideFileTextIcon size={22} /><span className="small receipt-name">{name}</span>{proof?.bytes ? <span className="small muted">{fileSize(proof.bytes)}</span> : null}</span>
            : picture || local?.preview ? <img src={picture ?? local?.preview} alt="" /> : <span className="small muted receipt-name">{name}</span>}
        {local?.state === "uploading" && <span className="receipt-progress" aria-hidden="true" />}
        {onRemove && (
          <button type="button" className="receipt-remove" aria-label={`Remove receipt ${index + 1}`} onClick={onRemove}><LucideXIcon size={14} /></button>
        )}
      </span>
      {sent && proof?.created_at && <span className="small muted">Sent {rowDate(proof.created_at)}</span>}
      {local?.state === "uploading" && <span className="small muted">Sending…</span>}
      {local?.state === "failed" && (
        <span className="receipt-why small">
          <span className="error-text">{local.error}</span>
          {onRetry && <button type="button" className="btn text compact" onClick={onRetry}>Try again</button>}
        </span>
      )}
    </li>
  );
}

function ReceiptStep({ request, guard, onDirty, onUpdate, onSent }: {
  request: UpgradeRequest;
  guard: () => boolean;
  onDirty: (dirty: boolean) => void;
  onUpdate: (r: UpgradeRequest) => void;
  onSent: (r: UpgradeRequest) => void;
}) {
  const proofs = request.payment?.proofs ?? [];
  const sentProofs = proofs.filter((p) => p.locked);
  const [round, setRound] = useState<Proof[]>(proofs.filter((p) => !p.locked));
  const [local, setLocal] = useState<LocalReceipt[]>([]);
  const [reference, setReference] = useState("");
  const [payer, setPayer] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [over, setOver] = useState(false);
  const refId = useId();
  const payerId = useId();
  const seq = useRef(0);
  const stage = requestStage(request);
  const roomLeft = ROUND_MAX - round.length - local.filter((l) => l.state === "uploading").length;
  useEffect(() => onDirty(!!reference.trim() || !!payer.trim() || round.length > 0 || local.length > 0), [reference, payer, round.length, local.length, onDirty]);

  const merge = (proof: Proof) => {
    setRound((list) => (list.some((p) => p.id === proof.id) ? list : [...list, proof]));
    onUpdate({ ...request, payment: request.payment ? { ...request.payment, proofs: [...(request.payment.proofs ?? []).filter((p) => p.id !== proof.id), proof] } : request.payment });
  };
  const send = async (item: LocalReceipt) => {
    setLocal((list) => [...list.filter((l) => l.key !== item.key), { ...item, state: "uploading", error: undefined }]);
    try {
      const proof = "path" in item.source
        ? await api.sendReceiptFile(request.id, item.source.path)
        : await api.sendReceiptData(request.id, item.source.file.name || null, await base64Of(item.source.file));
      setLocal((list) => list.filter((l) => l.key !== item.key));
      if (item.preview) URL.revokeObjectURL(item.preview);
      merge(proof);
    } catch (err) {
      const text = errorMessage(err);
      setLocal((list) => list.map((l) => (l.key === item.key ? { ...l, state: "failed", error: text } : l)));
      if (text.startsWith("This request has already been decided")) void refreshUpgradeRequest();
    }
  };
  const add = (items: { name: string; type?: string; size?: number; source: LocalReceipt["source"] }[]) => {
    setProblem(null);
    let room = roomLeft;
    for (const it of items) {
      const key = `r${++seq.current}`;
      if (room <= 0) {
        setProblem(ROUND_FULL);
        break;
      }
      const why = receiptProblem(it);
      if (why) {
        setLocal((list) => [...list, { key, name: it.name, source: it.source, state: "failed", error: why }]);
        continue;
      }
      room--;
      const preview = "file" in it.source && it.source.file.type.startsWith("image/") && typeof URL.createObjectURL === "function" ? URL.createObjectURL(it.source.file) : undefined;
      void send({ key, name: it.name, source: it.source, state: "uploading", preview });
    }
  };
  const addRef = useRef(add);
  addRef.current = add;
  const baseName = (path: string) => path.split(/[\\/]/).pop() ?? path;
  // Files dropped on the window come here while this step is open (not to Import), and Ctrl/⌘+V pastes a screenshot.
  useEffect(() => {
    const release = claimFileDrops((paths) => addRef.current(paths.map((path) => ({ name: baseName(path), source: { path } }))));
    const un = listenForFileDrops({ onEnter: () => setOver(true), onLeave: () => setOver(false), onDrop: () => setOver(false) });
    const onPaste = (ev: ClipboardEvent) => {
      const files = [...(ev.clipboardData?.files ?? [])];
      if (files.length === 0) return;
      ev.preventDefault();
      addRef.current(files.map((file) => ({ name: file.name || "Pasted receipt.png", type: file.type, size: file.size, source: { file } })));
    };
    document.addEventListener("paste", onPaste);
    return () => {
      release();
      un.then((f) => f()).catch(() => undefined);
      document.removeEventListener("paste", onPaste);
    };
  }, []);
  const pick = async () => {
    try {
      const paths = await api.pickReceipts();
      add(paths.map((path) => ({ name: baseName(path), source: { path } })));
    } catch (err) {
      setProblem(errorMessage(err));
    }
  };
  const remove = async (proof: Proof) => {
    setProblem(null);
    try {
      await api.removeReceipt(request.id, proof.id);
      setRound((list) => list.filter((p) => p.id !== proof.id));
      onUpdate({ ...request, payment: request.payment ? { ...request.payment, proofs: (request.payment.proofs ?? []).filter((p) => p.id !== proof.id) } : request.payment });
    } catch (err) {
      setProblem(errorMessage(err));
    }
  };
  const ref = reference.trim();
  const refFine = ref === "" || REFERENCE.test(ref);
  const ready = (round.length > 0 || (ref !== "" && refFine)) && refFine && !local.some((l) => l.state === "uploading");
  const submit = async () => {
    if (!ready) {
      setProblem(refFine ? NO_PROOF : "A transaction ID is 4 to 64 letters or numbers. Spaces and . _ / # - are fine too.");
      return;
    }
    setSending(true);
    setProblem(null);
    try {
      const r = await api.markPaid(request.id, ref || null, payer.trim() || null);
      void refreshEntitlements();
      onSent(r);
    } catch (err) {
      setProblem(errorMessage(err));
      setSending(false);
      void refreshUpgradeRequest();
    }
  };
  type Tile = { proof?: Proof; local?: LocalReceipt; removable?: boolean };
  const tiles: Tile[] = [...sentProofs.map((p) => ({ proof: p })), ...round.map((p) => ({ proof: p, removable: true })), ...local.map((l) => ({ local: l }))];
  const footer = (
    <div className="flow-bar">
      {problem && <p className="small error-text flow-under" role="alert">{problem}</p>}
      <div className="flow-go"><button type="button" className="btn primary" disabled={sending || !ready} onClick={() => void submit()}>{sending ? "Sending…" : "Send receipt"}</button></div>
    </div>
  );
  return (
    <FlowSheet title="Send receipt" onClose={closeUpgradeSheet} guard={guard} footer={footer}>
      {stage === "needs_receipt" && request.payment?.proof_request?.note && (
        <div className="set-group flow-group"><p className="set-block pre-line">{request.payment.proof_request.note}</p></div>
      )}
      <p>A screenshot or PDF of your receipt. It should show the amount, date and time, and the transaction ID. You can cover your balance.</p>
      {tiles.length > 0 && (
        <ul className="receipt-tiles" aria-label="Receipts">
          {tiles.map((t, i) => (
            <ReceiptTile key={t.proof?.id ?? t.local?.key} requestId={request.id} proof={t.proof} local={t.local} index={i} total={tiles.length}
              onRemove={t.removable && t.proof ? () => void remove(t.proof!) : t.local?.state === "failed" ? () => setLocal((list) => list.filter((l) => l.key !== t.local!.key)) : undefined}
              onRetry={t.local?.state === "failed" && t.local.error !== TOO_LARGE && t.local.error !== WRONG_TYPE ? () => void send(t.local!) : undefined} />
          ))}
        </ul>
      )}
      <div className={`file-drop receipt-drop ${over ? "over" : ""}`.trim()} data-testid="receipt-drop">
        <LucideUploadIcon size={20} />
        <span>Drop a screenshot here or paste it</span>
        <button type="button" className="btn compact" disabled={roomLeft <= 0} onClick={() => void pick()}>Choose file…</button>
        <span className="small muted">JPEG, PNG, WebP or PDF, up to 5 MB. Up to 3 at a time.</span>
      </div>
      <label className="field" htmlFor={refId}>Transaction ID (optional)
        <input id={refId} className="input" value={reference} maxLength={64} spellCheck={false} autoComplete="off" onChange={(ev) => setReference(ev.target.value)}
          aria-invalid={!refFine || undefined} aria-describedby={`${refId}-help`} />
        <span id={`${refId}-help`} className="small muted">On your receipt as Transaction ID, TID or Reference no.</span>
      </label>
      <label className="field" htmlFor={payerId}>Paid from (optional)
        <input id={payerId} className="input" value={payer} maxLength={120} onChange={(ev) => setPayer(ev.target.value)} aria-describedby={`${payerId}-help`} />
        <span id={`${payerId}-help`} className="small muted">The name on the account you paid from, if it isn't yours.</span>
      </label>
      <p className="small muted">Only our payments team sees your receipt. We delete it 90 days after we decide.</p>
    </FlowSheet>
  );
}

// ---- The request's status -------------------------------------------------------------------------------------------

type StepState = "done" | "current" | "todo";

/** The timeline of a request: Requested · Receipt sent · Checked by our team · {Plan} on, as far as it has come. */
export function timelineSteps(request: UpgradeRequest, name: string): { label: string; state: StepState; at: string | null; note?: string | null }[] {
  const stage = requestStage(request);
  const at = (step: string) => [...(request.timeline ?? [])].reverse().find((t) => t.step === step)?.at ?? null;
  const requested = at("requested") ?? request.created_at;
  const sent = at("receipt_sent") ?? request.payment?.paid_at ?? null;
  const asked = at("receipt_requested") ?? request.payment?.proof_request?.at ?? null;
  const decided = at("decided") ?? request.decided_at;
  const steps: { label: string; state: StepState; at: string | null; note?: string | null }[] = [{ label: "Requested", state: "done", at: requested ?? null }];
  steps.push({ label: "Receipt sent", state: sent ? "done" : stage === "awaiting_payment" ? "current" : "todo", at: sent });
  if (stage === "needs_receipt") steps.push({ label: "New receipt asked for", state: "current", at: asked, note: request.payment?.proof_request?.note ?? null });
  if (stage === "declined" || stage === "cancelled") {
    steps.push({ label: stage === "declined" ? "Not approved" : "Cancelled by you", state: "done", at: at("cancelled") ?? decided ?? null });
    return steps;
  }
  steps.push({ label: "Checked by our team", state: stage === "approved" ? "done" : stage === "in_review" ? "current" : "todo", at: stage === "approved" ? decided ?? null : null });
  const until = request.approved_until ?? null;
  steps.push({ label: `${name} on`, state: stage === "approved" ? "done" : "todo", at: null, note: stage === "approved" && until ? `until ${rowDateWithYear(until)}` : null });
  return steps;
}

function when(iso: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "" : `${rowDate(iso)}, ${formatTime(d)}`;
}

function StatusStep({ e, request, name, onPay, onReceipt, onUpdate, onAgain }: {
  e: Entitlements;
  request: UpgradeRequest;
  name: string;
  onPay: () => void;
  onReceipt: () => void;
  onUpdate: (r: UpgradeRequest) => void;
  onAgain: () => void;
}) {
  const [cancelling, setCancelling] = useState<"ask" | "busy" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [copied, copy] = useCopied();
  const stage = requestStage(request);
  const open = stage === "awaiting_payment" || stage === "in_review" || stage === "needs_receipt";
  const steps = useMemo(() => timelineSteps(request, name), [request, name]);
  const amount = amountOf(request, e.plans.find((p) => p.key === request.plan) ?? null);
  const proofs = request.payment?.proofs ?? [];
  const cancel = async () => {
    setCancelling("busy");
    setError(null);
    try {
      onUpdate(await api.cancelUpgradeRequest(request.id));
      setCancelling(null);
      void refreshEntitlements();
    } catch (err) {
      setError(errorMessage(err));
      setCancelling("ask");
      void refreshUpgradeRequest();
    }
  };
  const reduced = reducedMotion();
  return (
    <FlowSheet title={`${name} request`} onClose={closeUpgradeSheet} guard={() => true}
      footer={<div className="flow-bar"><div className="flow-go">
        {open && <button type="button" className="btn text danger" onClick={() => setCancelling("ask")}>Cancel request</button>}
        <button type="button" className="btn ghost" onClick={() => closeUpgradeSheet()}>Done</button>
      </div></div>}>
      <div aria-live="polite" className="sr-only">{stage === "in_review" ? "Receipt sent. We'll check your payment." : ""}</div>
      <RequestCard e={e} request={request} inFlow onPay={open ? onPay : undefined} onReceipt={stage === "awaiting_payment" || stage === "needs_receipt" ? onReceipt : undefined}
        onAgain={stage === "declined" ? onAgain : undefined} />
      <ol className={`timeline ${reduced ? "still" : ""}`.trim()} aria-label="Progress">
        {steps.map((s) => (
          <li key={s.label} className={`timeline-step ${s.state}`} aria-label={`${s.label}, ${s.state === "done" ? "done" : s.state === "current" ? "current" : "not yet"}${s.at ? `, ${when(s.at)}` : ""}`}>
            <span className="timeline-mark" aria-hidden="true">{s.state === "done" && <CheckIcon size={13} />}</span>
            <span className="timeline-text">
              <span className="timeline-label">{s.label}</span>
              {(s.at || s.note) && <span className="small muted">{[when(s.at), s.note].filter(Boolean).join(" · ")}</span>}
            </span>
          </li>
        ))}
      </ol>
      <section className="set-section" aria-label="Details">
        <h3 className="set-head">Details</h3>
        <div className="set-group">
          <div className="set-row"><span className="set-title">Plan</span><span className="set-end"><span className="set-value">{name}, 1 month</span></span></div>
          {amount !== null && <div className="set-row"><span className="set-title">Amount</span><span className="set-end"><span className="set-value tabular">{rupees(amount)}</span></span></div>}
          {request.promo_code && <div className="set-row"><span className="set-title">Promo code</span><span className="set-end"><span className="set-value">{request.promo_code}</span></span></div>}
          {request.reference_code && (
            <div className="set-row"><span className="set-title">Reference</span><span className="set-end"><span className="set-value mono">{request.reference_code}</span>
              <button type="button" className="btn ghost icon-only" aria-label="Copy reference" onClick={() => void copy("ref", request.reference_code!)}>{copied === "ref" ? <LucideCheckIcon size={18} /> : <LucideCopyIcon size={18} />}</button>
            </span></div>
          )}
          {request.created_at && <div className="set-row"><span className="set-title">Requested</span><span className="set-end"><span className="set-value">{rowDateWithYear(request.created_at)}</span></span></div>}
          {request.payment?.reference && <div className="set-row"><span className="set-title">Transaction ID</span><span className="set-end"><span className="set-value mono">{request.payment.reference}</span></span></div>}
        </div>
      </section>
      {proofs.length > 0 && (
        <section className="set-section" aria-label="Receipts">
          <h3 className="set-head">Receipts</h3>
          <ul className="receipt-tiles" aria-label="Receipts">
            {proofs.map((p, i) => <ReceiptTile key={p.id} requestId={request.id} proof={{ ...p, locked: true }} index={i} total={proofs.length} />)}
          </ul>
        </section>
      )}
      {open && (
        <div className="flow-links">
          <button type="button" className="btn text" onClick={onPay}>Payment details</button>
          {(stage === "awaiting_payment" || stage === "needs_receipt") && <button type="button" className="btn text" onClick={onReceipt}>Add receipt</button>}
        </div>
      )}
      {cancelling && (
        <ConfirmDialog title="Cancel this request?" body="If you've already paid, add your receipt instead. Cancelling doesn't refund a payment."
          cancel="Keep request" confirm="Cancel request" busy={cancelling === "busy"} error={error} onConfirm={() => void cancel()}
          onClose={() => { setCancelling(null); setError(null); }} />
      )}
    </FlowSheet>
  );
}

function ApprovedStep({ e, request }: { e: Entitlements; request: UpgradeRequest }) {
  const on = planName(e.plans.find((p) => p.key === (request.approved_plan || request.plan)) ?? { key: request.approved_plan || request.plan, name: "" });
  const until = request.approved_until ?? null;
  return (
    <FlowSheet title={`You're on ${on}`} onClose={closeUpgradeSheet} guard={() => true}
      footer={<div className="flow-bar"><div className="flow-go"><button type="button" className="btn primary" onClick={() => closeUpgradeSheet()}>Done</button></div></div>}>
      <div className="approved" role="status" data-testid="upgrade-approved">
        <span className="approved-mark" aria-hidden="true"><CheckIcon size={22} /></span>
        <p>Thank you. {on} is on{until ? ` until ${sentenceDate(until)}` : ""}.</p>
      </div>
    </FlowSheet>
  );
}
