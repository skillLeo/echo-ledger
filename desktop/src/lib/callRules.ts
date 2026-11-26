// Call rules (contract 2.0.0-w, docs/PLAN_CALL_RULES.md; lead decisions 25 and 28) as the window shows them: the
// account's After each call, When to ask and Short calls, and its rules for a contact (by name: the desktop knows no
// numbers) or a Source, while the person's `call_rules` flag is on. The core keeps the account's answer for this
// sign-in and says when it changes (`call_rules:changed`); null means the flag is off and this computer's own
// settings apply, as before.
import { useEffect, useState } from "react";
import { api, errorMessage, listenTo, type AfterEachCall, type CallAction, type CallRule, type CallRules, type Settings } from "./ipc";
import { CALL_WORDS } from "./callChoice";
import { confirmAction } from "./confirm";
import { sourceAppLabel } from "./format";
import { t } from "../i18n";

/**
 * The account's answer while the flag is on (null: off, or not read yet), kept up to date. `failed`: the flag is on
 * but its answer couldn't be read (offline before any was kept), told apart from "off"; `reload` tries again.
 */
export function useCallRules(): { rules: CallRules | null; set: (rules: CallRules) => void; failed: boolean; reload: () => void } {
  const [rules, setRules] = useState<CallRules | null>(null);
  const [failed, setFailed] = useState(false);
  const [round, setRound] = useState(0);
  useEffect(() => {
    let live = true;
    api.callRules().then((r) => { if (live) { setRules(r ?? null); setFailed(false); } }).catch(() => { if (live) setFailed(true); });
    const off = listenTo("call_rules:changed", (r) => { if (live) { setRules(r ?? null); setFailed(false); } });
    return () => {
      live = false;
      void off.then((f) => f()).catch(() => undefined);
    };
  }, [round]);
  return { rules, set: setRules, failed, reload: () => setRound((n) => n + 1) };
}

/** The account's After each call, When to ask and Short calls as Reset sets them: the settings' own defaults (Ask me,
 * after the call, short calls not kept). */
export const ACCOUNT_DEFAULTS = { after_call: "ask", ask_when: "after_call", short_calls: "dont_keep" } as const;

/**
 * Asks before a contact's rule becomes Don't record (decision 25's words), wherever it is set: the Library's "Always
 * for <name>" and the Call rules page.
 */
export function confirmContactDontRecord(name: string): Promise<boolean> {
  return confirmAction({
    title: t("Don't record calls with :name?", { name }),
    body: t("On this computer the recording stops and is deleted as soon as :name is known, on every call from now on.", { name }),
    confirm: actionWords("dont_record"), tone: "danger",
  });
}

/** The account's After each call as Settings shows it: Don't record is "Don't record calls"; never chosen is Ask me. */
export function accountAfterEachCall(rules: Pick<CallRules, "after_call">): AfterEachCall {
  return rules.after_call === "dont_record" ? "off" : rules.after_call ?? "ask";
}

/** The account's value for a choice of "After each call". */
export const accountAfterCall = (value: AfterEachCall): CallAction => (value === "off" ? "dont_record" : value);

/** The account's When to ask as Settings shows it. */
export const accountAskWhen = (rules: Pick<CallRules, "ask_when">): NonNullable<Settings["askWhen"]> => (rules.ask_when === "at_start" ? "start" : "after");

/** The four choices for a rule, in the contract's words (the same as After each call's). */
export const CALL_ACTIONS: readonly CallAction[] = ["ask", "always_transcribe", "always_audio", "dont_record"];

export function actionWords(action: CallAction): string {
  switch (action) {
    case "ask": return CALL_WORDS.setting_ask;
    case "always_transcribe": return CALL_WORDS.setting_transcribe;
    case "always_audio": return CALL_WORDS.setting_audio;
    case "dont_record": return CALL_WORDS.dont_record;
  }
}

/** The Sources a rule can be made for here (the desktop's call apps); a phone's own Sources show as they come. */
export const RULE_SOURCES: readonly string[] = ["zoom", "google_meet", "microsoft_teams", "slack", "whatsapp"];

/** What a rule is for, as its row says it: the contact's name (or number), a group set on the phone, or the app. */
export function ruleSubject(rule: CallRule): string {
  if (rule.kind === "contact") return rule.name?.trim() || rule.number || t("A contact");
  if (rule.kind === "group") {
    switch (rule.group) {
      case "favourites": return t("Favourites");
      case "saved_contacts": return t("Saved contacts");
      default: return t("Unknown numbers");
    }
  }
  return rule.source_app ? sourceAppLabel(rule.source_app) : t("An app");
}

/**
 * What a rule does, in honest words (decision 25): a contact's Don't record on this computer stops and deletes the
 * recording as soon as the contact is known, never "nothing is recorded".
 */
export function ruleLine(rule: CallRule): string {
  if (rule.kind === "contact" && rule.action === "dont_record") return t("Stops and deletes the recording as soon as :name is known", { name: ruleSubject(rule) });
  return actionWords(rule.action);
}

/**
 * On this computer a Source's or the default's Don't record is decided when the call starts, before its caller is
 * known (docs/API.md, 2.0.0-w: "the desktop and the Play build stop and delete as soon as the contact is known"): with
 * a rule for a contact as well, the page says so, once per such app.
 */
export function startNotes(rules: CallRules): string[] {
  if (!rules.rules.some((r) => r.kind === "contact" && r.action !== "dont_record")) return [];
  const notes = rules.rules.filter((r) => r.kind === "source" && r.action === "dont_record" && r.source_app)
    .map((r) => t("On this computer, :app's Don't record applies before the caller is known.", { app: sourceAppLabel(r.source_app!) }));
  if (rules.after_call === "dont_record") notes.push(t("On this computer, Don't record calls applies before the caller is known."));
  return notes;
}

/** A contact's name as it is sent and kept: trimmed, each run of spaces one, the first 120 characters (the server's
 * cleanName; it checks the 120 before cleaning, so a longer name is cut here). */
export function cleanName(name: string): string {
  return Array.from(name.trim().split(/\s+/u).join(" ")).slice(0, 120).join("");
}

/** The account's rule for a contact by `name`, matched as the server and the core key names (cleanName, lower case). */
export function contactRuleOf(rules: CallRules, name: string): CallRule | undefined {
  const key = (n: string) => cleanName(n).toLowerCase();
  const wanted = key(name);
  return rules.rules.find((r) => r.kind === "contact" && !r.number && r.name != null && key(r.name) === wanted);
}

/** A change's failure in the core's words (offline, 500 rules, too many changes), for the page's line. */
export const ruleProblem = (e: unknown) => errorMessage(e);
