// What this person may use (v1.6, P1.2/P1.4, docs/API.md "Entitlements"): the server alone decides; the windows only
// show what its answer says. Read after signing in and whenever the window comes to the front. An older server sends
// none: then nothing is locked here and every feature stays as it is today (the v1.5 plan in the config still hides
// what its plan leaves out).
import { useSyncExternalStore } from "react";
import { api, type Entitlements, type OfferedPlan, type UpgradeRequest } from "./ipc";

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
  reading = api.entitlements().then((e) => set(e ?? null), () => undefined).finally(() => {
    reading = null;
  });
  return reading;
}

/** Signed out: nothing is known about the next account. */
export function forgetEntitlements(): void {
  set(undefined);
}

/** A request just made (or found pending): shown at once, before the next read. */
export function noteUpgradeRequest(request: UpgradeRequest): void {
  if (current) set({ ...current, upgrade_request: request });
}

/** Tests start from nothing. */
export function resetEntitlementsForTests(value: Entitlements | null | undefined = undefined): void {
  reading = null;
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

/** "PRO ✦": the one badge text for a paid plan. */
export function badgeText(plan: Pick<OfferedPlan, "badge" | "name" | "key">): string {
  return `${(plan.badge || plan.name || plan.key).toUpperCase()} ✦`;
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

/** The pending upgrade request, if there is one. */
export function pendingRequest(e: Entitlements | null | undefined): UpgradeRequest | null {
  return e?.upgrade_request?.status === "pending" ? e.upgrade_request : null;
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

type SheetRequest = { feature: string | null; seq: number } | null;
let sheet: SheetRequest = null;
const sheetListeners = new Set<() => void>();
let seq = 0;

/** Opens the one upgrade sheet for `feature` (null: the plans without a feature). */
export function openUpgradeSheet(feature: string | null): void {
  sheet = { feature, seq: ++seq };
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
