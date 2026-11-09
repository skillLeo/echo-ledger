// What this person may use (v1.6, P1.2/P1.4, docs/API.md "Entitlements"): the server alone decides; the windows only
// show what its answer says. Read after signing in and whenever the window comes to the front. An older server sends
// none: then nothing is locked here and every feature stays as it is today (the v1.5 plan in the config still hides
// what its plan leaves out).
import { useSyncExternalStore } from "react";
import { api, type Entitlements, type OfferedPlan, type PaymentStage, type PromoCheck, type UpgradeRequest } from "./ipc";

/** undefined: not read yet; null: an older server (or signed out). */
let current: Entitlements | null | undefined = undefined;
const listeners = new Set<() => void>();
let reading: Promise<void> | null = null;

function set(next: Entitlements | null | undefined) {
  current = next;
  listeners.forEach((l) => l());
}

/** Reads the entitlements again; an error (offline) keeps the last answer. */
export function refreshEntitlements(): Promise<void> {
  if (reading) return reading;
  reading = api.entitlements().then((e) => set(e ? { ...e, upgrade_request: e.upgrade_request ? mergeRequest(current?.upgrade_request, e.upgrade_request) : null } : null), () => undefined).finally(() => {
    reading = null;
  });
  return reading;
}

/** Signed out: nothing is known about the next account. */
export function forgetEntitlements(): void {
  promoMissing = false;
  set(undefined);
}

// An older server answered the promo check with 404 (v1.6.2): the sheet stops offering the field until sign-out.
let promoMissing = false;
export function promoCodesMissing(): boolean {
  return promoMissing;
}
export function notePromoCodesMissing(): void {
  promoMissing = true;
}

/**
 * The plan's price with a promo code, from the server's own prices: "Rs 2,999 → Rs 1,500 / month with SAVE50", and
 * "Rs 2,999 / month → Free for 3 months with FREEPRO" at 100 %.
 */
export function promoPriceLine(check: PromoCheck): string {
  const before = check.price?.trim() || null;
  const after = check.final_price?.trim() || null;
  const months = check.months && check.months > 0 ? check.months : 1;
  if (check.free) return `${before ? `${before} → ` : ""}Free for ${months === 1 ? "1 month" : `${months} months`} with ${check.code}`;
  if (!before || !after) return `${check.percent ?? 0} % off with ${check.code}`;
  const per = after.match(/\s\/\s.+$/)?.[0] ?? "";
  const head = per && before.endsWith(per) ? before.slice(0, -per.length) : before;
  return `${head} → ${after} with ${check.code}`;
}

/** A request just made, changed or read again: shown at once, before the next read (merged with what is known). */
export function noteUpgradeRequest(request: UpgradeRequest): void {
  if (current) set({ ...current, upgrade_request: mergeRequest(current.upgrade_request, request) });
}

/** Reads the newest request again (`GET /api/upgrade-requests/latest`), which carries the payment details in full. */
export function refreshUpgradeRequest(): Promise<void> {
  return api.latestUpgradeRequest().then((r) => {
    if (r) noteUpgradeRequest(r);
  }, () => undefined);
}

/** Tests start from nothing. */
export function resetEntitlementsForTests(value: Entitlements | null | undefined = undefined): void {
  reading = null;
  promoMissing = false;
  set(value);
}

const subscribe = (l: () => void) => {
  listeners.add(l);
  return () => {
    listeners.delete(l);
  };
};

export function useEntitlements(): Entitlements | null | undefined {
  return useSyncExternalStore(subscribe, () => current, () => current);
}

/** The paid features the apps badge, in the order Plan & usage lists them, with the words used when the server sends none. */
export const PAID_FEATURES: readonly (readonly [string, string, string])[] = [
  ["platform_transcription", "Transcription on EchoLedger", "Your recordings are transcribed for you, without your own key."],
  ["views", "Roman Urdu, English and Urdu views", "Read any transcript in Roman Urdu, English or Urdu script."],
  ["double_check", "Double-check", "A second engine listens again where the first one was unsure."],
  ["screen_moments", "Screen moments", "What was on the meeting's screen, kept beside the transcript."],
  ["connector", "Claude and ChatGPT connector", "Ask Claude or ChatGPT about your recordings."],
  ["live_translate", "Live Translate", "Live captions translated while a game or video plays."],
  ["ai_notes", "Call notes, memory and Ask", "Notes after each call and answers about a project."],
  ["team", "Team workspace", "Invite people, with seats and roles."],
  ["premium_engines", "Premium engines", "ElevenLabs and Gemini for the hardest audio."],
];

/** Whether the plan leaves `feature` out. An older server (none) or a feature the answer does not name is not locked. */
export function isLocked(e: Entitlements | null | undefined, feature: string): boolean {
  return !!e && !!e.features[feature] && !e.features[feature].enabled;
}

function titleCase(key: string): string {
  return key ? key.charAt(0).toUpperCase() + key.slice(1).toLowerCase() : key;
}

/** The plan that brings `feature`: the answer's lowest plan, from the plans on offer (or made from its key). */
export function lowestPlan(e: Entitlements | null | undefined, feature: string): OfferedPlan | null {
  const key = e?.features[feature]?.lowest_plan ?? e?.plans.find((p) => p.features.includes(feature))?.key;
  if (!key) return null;
  return e?.plans.find((p) => p.key === key) ?? { key, name: titleCase(key), badge: null, color: null, price: null, description: null, features: [feature], limits: {} };
}

/** A plan's name as people read it ("Pro", "Ultra"): its display name, never its key or a badge in capitals. */
export function planName(plan: Pick<OfferedPlan, "name" | "key"> | null | undefined): string {
  if (!plan) return "";
  const name = plan.name?.trim();
  return name || titleCase(plan.key === "business" ? "ultra" : plan.key);
}

/** The one tag text for a paid plan: its name in sentence case ("Pro"), with no sparkle, lock or capitals. */
export function badgeText(plan: Pick<OfferedPlan, "badge" | "name" | "key">): string {
  return planName(plan);
}

/** The tag a feature the plan leaves out wears ("Pro"); none when the plan has it or the server is older. Plans have
 * no colours: the tag is neutral. */
export function lockedBadge(e: Entitlements | null | undefined, feature: string): { text: string; color: string | null; plan: string | null } | null {
  if (!isLocked(e, feature)) return null;
  const plan = lowestPlan(e, feature);
  return { text: plan ? badgeText(plan) : "Paid", color: null, plan: plan ? planName(plan) : null };
}

/** A feature's name and what it does: the server's words, else the app's. */
export function featureWords(e: Entitlements | null | undefined, feature: string): { name: string; description: string } {
  const known = PAID_FEATURES.find(([key]) => key === feature);
  const fromServer = e?.features[feature];
  return {
    name: fromServer?.name?.trim() || known?.[1] || titleCase(feature.replace(/_/g, " ")),
    description: fromServer?.description?.trim() || known?.[2] || "",
  };
}

/** Every feature the answer names, in the server's (the registry's) order, and how many of them the plan has. */
export function allFeatures(e: Entitlements): { keys: string[]; included: number } {
  const keys = Object.keys(e.features);
  return { keys, included: keys.filter((key) => e.features[key].enabled).length };
}

/** "Your plan has 6 of 15 features". */
export function featuresSummary(e: Entitlements): string {
  const { keys, included } = allFeatures(e);
  return `Your plan has ${included} of ${keys.length} ${keys.length === 1 ? "feature" : "features"}`;
}

/** The pending upgrade request, if there is one. */
export function pendingRequest(e: Entitlements | null | undefined): UpgradeRequest | null {
  return e?.upgrade_request?.status === "pending" ? e.upgrade_request : null;
}

/** Where a request stands: the server's `payment.stage` (v1.7), or what a v1.6 server's `status` means. */
export function requestStage(request: UpgradeRequest | null | undefined): PaymentStage | null {
  if (!request) return null;
  const stage = request.payment?.stage;
  if (stage && ["awaiting_payment", "in_review", "needs_receipt", "approved", "declined", "cancelled"].includes(stage)) return stage as PaymentStage;
  if (request.status === "approved") return "approved";
  if (request.status === "declined") return request.reply === "Cancelled by you" ? "cancelled" : "declined";
  return request.status === "pending" ? "awaiting_payment" : null;
}

/** The stages in which a request is still open (no Upgrade anywhere while one is). */
export const OPEN_STAGES: readonly PaymentStage[] = ["awaiting_payment", "in_review", "needs_receipt"];

/** The open request (waiting for payment, being checked, or needing a new receipt); none otherwise. */
export function openRequest(e: Entitlements | null | undefined): UpgradeRequest | null {
  const request = e?.upgrade_request ?? null;
  const stage = requestStage(request);
  return stage && OPEN_STAGES.includes(stage) ? request : null;
}

/** A newer answer about the same request merged into the one known: the payment part is never dropped by an answer that
 * has none (the entitlements' slim copy after `latest`'s full one). Another request replaces it. */
export function mergeRequest(known: UpgradeRequest | null | undefined, next: UpgradeRequest | null | undefined): UpgradeRequest | null {
  if (!next) return known ?? null;
  if (!known || known.id !== next.id) return next;
  const merged: UpgradeRequest = { ...known, ...next };
  for (const key of ["payment", "reference_code", "amount_due", "amount_due_pkr", "currency", "payment_instructions"] as const) {
    if (next[key] == null && known[key] != null) (merged as unknown as Record<string, unknown>)[key] = known[key];
  }
  if ((!next.timeline || next.timeline.length === 0) && known.timeline?.length) merged.timeline = known.timeline;
  return merged;
}

/** The plans above the current one, cheapest first (Free sees Pro and Ultra, Pro sees Ultra, Ultra none). */
export function plansAbove(e: Entitlements | null | undefined): OfferedPlan[] {
  if (!e) return [];
  const paid = e.plans.filter((p) => p.key !== "free" && p.price_pkr !== 0);
  const here = paid.findIndex((p) => p.key === e.plan?.key);
  if (here >= 0) return paid.slice(here + 1);
  return e.plan && e.plan.key !== "free" && isTopPlan(e) ? [] : paid;
}

/** Whether this person is on the top plan (Ultra): every feature the server names is on. */
export function isTopPlan(e: Entitlements | null | undefined): boolean {
  if (!e?.plan || e.plan.key === "free") return false;
  const paid = e.plans.filter((p) => p.key !== "free");
  if (paid.length > 0 && paid[paid.length - 1].key === e.plan.key) return true;
  const keys = Object.keys(e.features);
  return keys.length > 0 && keys.every((k) => e.features[k].enabled);
}

/** The price of a plan as written: the server's text ("Rs 2,999 a month"), else from its rupees, else its old text. */
export function priceText(plan: Pick<OfferedPlan, "price" | "price_pkr" | "price_text">): string | null {
  if (plan.price_text?.trim()) return plan.price_text.trim();
  if (typeof plan.price_pkr === "number" && plan.price_pkr > 0) return `Rs ${Math.round(plan.price_pkr).toLocaleString("en-US")} a month`;
  const old = plan.price?.trim();
  return old && /\d/.test(old) ? old.replace(/\s*\/\s*month$/i, " a month") : null;
}

/** Whether this person transcribes with their own key: the plan has no platform transcription. */
export function needsOwnKey(e: Entitlements | null | undefined): boolean {
  return isLocked(e, "platform_transcription");
}

/** The limit names Plan & usage shows, in order, and how each reads. */
export const LIMIT_LABELS: readonly (readonly [string, string, "minutes" | "bytes" | "count" | "days" | "usd"])[] = [
  ["monthly_minutes", "Transcription on EchoLedger", "minutes"],
  ["storage_bytes", "Storage", "bytes"],
  ["devices", "Devices", "count"],
  ["uploads_per_day", "Uploads today", "count"],
  ["max_file_minutes", "Longest recording", "minutes"],
  ["retention_days", "Recordings kept", "days"],
  ["seats", "People in the workspace", "count"],
  ["ai_usd_month", "AI this month", "usd"],
];

// ---- Opening the upgrade sheet and Account sections from anywhere ------------------------------------------------

/** Where the upgrade sheet opens: plan and promo, the bank details, the receipts, or the request's status. */
export type SheetStep = "choose" | "pay" | "receipt" | "status";
type SheetRequest = { feature: string | null; plan: string | null; step: SheetStep | null; seq: number } | null;
let sheet: SheetRequest = null;
const sheetListeners = new Set<() => void>();
let seq = 0;

/** Opens the one upgrade sheet for `feature` (null: the plans without a feature), on `plan` or at `step`. While a
 * request is open it opens on that request instead (no Upgrade while one is open). */
export function openUpgradeSheet(feature: string | null, options: { plan?: string | null; step?: SheetStep | null } = {}): void {
  sheet = { feature, plan: options.plan ?? null, step: options.step ?? null, seq: ++seq };
  sheetListeners.forEach((l) => l());
}

export function closeUpgradeSheet(): void {
  sheet = null;
  sheetListeners.forEach((l) => l());
}

export function useUpgradeSheet(): SheetRequest {
  return useSyncExternalStore((l) => {
    sheetListeners.add(l);
    return () => {
      sheetListeners.delete(l);
    };
  }, () => sheet, () => sheet);
}

/** Settings → Account → a section ("transcription-key", "plan"): the main window opens it (MainApp listens). */
export const OPEN_SECTION_EVENT = "echoledger:open-settings-section";

export function openSettingsSection(id: string): void {
  window.dispatchEvent(new CustomEvent(OPEN_SECTION_EVENT, { detail: id }));
}

/** Settings at `page`, scrolled to the setting `id` and highlighted for a moment ("lock#lock.after"). */
export function openSettings(page: string, id: string | null = null): void {
  openSettingsSection(id ? `${page}#${id}` : page);
}
