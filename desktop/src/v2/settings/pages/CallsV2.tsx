// Settings › Calls & meetings in the new look, with today's data and rules (CaptureSettings.tsx): auto-record and
// each call app, unknown apps, browser tabs on a Mac; After each call (the call choice: four choices with their help
// lines, and When to ask under Ask me) and which short calls are skipped; the
// call sites (built-in rules, the person's own, never-record); and why a call wasn't recorded, read while on screen.
import { useEffect, useState } from "react";
import { api, type MicUser, type NotRecordedMic, type Settings, type SiteRule } from "../../../lib/ipc";
import { CALL_APPS, setting } from "../../../lib/settingsRegistry";
import { skipShorterThan } from "../../../lib/pending";
import { decisionText, lastNotRecordedLine, micTone, micUserName, sameMicApp } from "../../../lib/explainer";
import { useWindowShown } from "../../../lib/visibility";
import { sourceAppLabel } from "../../../lib/format";
import { BUILTIN_SITE_RULES, SITE_RULE_APPS, builtinEnabled, customRules, hasSameRule, isNeverRule, ruleFromInput, rulePattern, withBuiltinEnabled } from "../../../lib/sites";
import { AUTO_RECORD_APPS, appRecords } from "../../../views/main/CaptureSettings";
import { AFTER_EACH_CALL_OPTIONS, ASK_WHEN_OPTIONS, CALL_WORDS, afterEachCallOf, afterEachCallPatch } from "../../../lib/callChoice";
import { Button, Card, Icons, List, ListRow, Select, Status, TextField, Toggle } from "../../components";
import { ChoiceSetting, Group, LinkSetting, ResetDefaults, SectionTitle, SelectSetting, SwitchSetting, type Patch } from "./rows";
import { isRtl, lineDir, t, tc, withParts } from "../../../i18n";

const optionDefault = (id: string) => setting(id).options?.find((o) => o.default)?.value ?? "";

export function CallsPageV2({ settings, patch, platform, onSites, onConsent }: {
  settings: Settings;
  patch: Patch;
  platform: string;
  onSites: () => void;
  onConsent: () => void;
}) {
  const sites = settings.siteRules.filter((r) => !isNeverRule(r)).length;
  return (
    <>
      <Group title={CALL_WORDS.setting_title}>
        <ChoiceSetting id="calls.after" value={afterEachCallOf(settings)} options={AFTER_EACH_CALL_OPTIONS} onChange={(v) => void patch(afterEachCallPatch(v))} />
      </Group>
      {afterEachCallOf(settings) === "ask" && (
        <Group title={CALL_WORDS.when_title}>
          <ChoiceSetting id="calls.ask_when" value={settings.askWhen ?? "after"} options={ASK_WHEN_OPTIONS} onChange={(v) => void patch({ askWhen: v })} />
        </Group>
      )}
      {/* One setting: Don't record calls is the only switch for recording calls by themselves; the apps wait under it. */}
      <Group title={t("Record automatically")} footer={!settings.autoMode ? <p data-testid="apps-off-reason">{CALL_WORDS.setting_off_help}</p> : undefined}>
        {CALL_APPS.map(([app, name]) => {
          const on = appRecords(settings.autoRecordApps, app);
          return (
            <ListRow key={app} compact anchor={`calls.app.${app}`} icon={(p) => <Icons.SourceAppIcon app={app} {...p} />} iconStyle="plain" title={name}
              control={<Toggle label={t("Auto-record :name", { name })} hideLabel checked={on} disabled={!settings.autoMode}
                onChange={() => void patch({ autoRecordApps: { [app]: !on } })} />} />
          );
        })}
        <SwitchSetting id="calls.ask_unknown" on={settings.askUnknownApps} onChange={(v) => void patch({ askUnknownApps: v })} />
        {platform === "macos" && <SwitchSetting id="calls.browser_tabs" on={settings.browserAutomation} onChange={(v) => void patch({ browserAutomation: v })} />}
      </Group>
      <Group footer={<p>{t("Automatic calls this short, or where nobody spoke, are skipped quietly. Activity can bring them back for 24 hours.")}</p>}>
        <SelectSetting id="calls.skip_short" value={String(settings.skipCallsShorterThanS)} options={skipShorterThan().map(([s, label]) => ({ value: String(s), label }))}
          onChange={(v) => void patch({ skipCallsShorterThanS: Number(v) })} />
      </Group>
      <Group title={t("Websites")}>
        <LinkSetting id="calls.sites" value={tc(":count rule|:count rules", sites)} onOpen={onSites} />
      </Group>
      <WhyNotRecordedV2 />
      {!settings.consentAcknowledged && (
        <div className="el-settings-v2__reset"><Button variant="quiet" onClick={onConsent}>{t("Read the recording consent notice")}</Button></div>
      )}
      <ResetDefaults page={t("Calls & meetings")} onReset={() => void patch({
        autoMode: true, askUnknownApps: false, browserAutomation: false, afterCall: optionDefault("calls.after") as Settings["afterCall"], askWhen: optionDefault("calls.ask_when") as Settings["askWhen"],
        skipCallsShorterThanS: Number(optionDefault("calls.skip_short")), autoRecordApps: Object.fromEntries(AUTO_RECORD_APPS.map((a) => [a, true])),
      })} />
    </>
  );
}

const TONE_KIND = { live: "recording", ok: "ready", ask: "setup", idle: "idle" } as const;

/**
 * Each app using the microphone now and what EchoLedger does with it, and the last one that wasn't recorded (the core
 * keeps it since EchoLedger started). Read every 2 s, only while the window is on screen.
 */
function WhyNotRecordedV2() {
  const [users, setUsers] = useState<MicUser[]>([]);
  const [last, setLast] = useState<NotRecordedMic | null>(null);
  const shown = useWindowShown();
  useEffect(() => {
    if (!shown) return;
    let alive = true;
    const tick = () => {
      api.micUsers().then((list) => alive && setUsers(Array.isArray(list) ? list : [])).catch(() => undefined);
      api.micLastNotRecorded().then((kept) => alive && setLast(kept?.user ? kept : null)).catch(() => undefined);
    };
    tick();
    const timer = window.setInterval(tick, 2000);
    return () => {
      alive = false;
      window.clearInterval(timer);
    };
  }, [shown]);
  return (
    <section className="el-group" aria-labelledby="el-why-not">
      <SectionTitle id="el-why-not">{t("Why a call wasn't recorded")}</SectionTitle>
      <Card>
        <div className="el-why" data-testid="why-not-recorded" aria-live="polite">
          {users.length === 0 ? <p className="el-secondary">{t("No app is using the microphone now.")}</p> : (
            <ul className="el-why__list">
              {users.map((u, i) => (
                // D-232: one line, the app's name in it as a value; it reads in the direction of what it says (lineDir of
                // the decision, the line's own words: the name and the decision are values).
                <li key={`${u.name}-${i}`} dir={isRtl() ? lineDir(decisionText(u)) : undefined}>
                  <Status kind={TONE_KIND[micTone(u)]}>{withParts(t(":app: :decision", { decision: decisionText(u) }), { app: <span className="el-body-strong" dir="auto">{micUserName(u)}</span> })}</Status>
                </li>
              ))}
            </ul>
          )}
          {last && !users.some((u) => sameMicApp(u, last.user)) && <p className="el-secondary" data-testid="last-not-recorded" dir={lineDir(lastNotRecordedLine(last))}>{lastNotRecordedLine(last)}</p>}
        </div>
      </Card>
      <p className="el-group__note el-caption el-secondary">{t("EchoLedger records a call when a call app uses your microphone and plays sound.")}</p>
    </section>
  );
}

/** Calls & meetings › Call sites: built-in rules with a switch, the person's own with Remove, and Add a site. */
export function CallSitesPageV2({ rules, onSave }: { rules: SiteRule[]; onSave: (rules: SiteRule[]) => Promise<boolean> }) {
  const [app, setApp] = useState<string>(SITE_RULE_APPS[0][0]);
  const [text, setText] = useState("");
  const mine = customRules(rules);
  const add = async () => {
    const rule = ruleFromInput(app, text);
    if (!rule) return;
    if (hasSameRule(rules, rule) || (await onSave([...rules, rule]))) setText("");
  };
  const icon = (rule: SiteRule) => (p: { className?: string }) => <Icons.SourceAppIcon app={isNeverRule(rule) ? undefined : rule.app} {...p} />;
  const builtinRow = (rule: SiteRule) => {
    const on = builtinEnabled(rule, rules);
    const pattern = rulePattern(rule);
    return (
      <ListRow key={rule.id} compact iconStyle="plain" icon={icon(rule)} title={<span dir="ltr">{pattern}</span>} meta={isNeverRule(rule) ? undefined : sourceAppLabel(rule.app)}
        control={<Toggle label={isNeverRule(rule) ? t("Never record :pattern", { pattern }) : t("Record calls on :pattern", { pattern })} hideLabel checked={on} onChange={() => void onSave(withBuiltinEnabled(rules, rule, !on))} />} />
    );
  };
  const userRow = (rule: SiteRule) => (
    <ListRow key={rule.id} compact iconStyle="plain" icon={icon(rule)} title={<span dir="ltr">{rulePattern(rule)}</span>} meta={isNeverRule(rule) ? undefined : sourceAppLabel(rule.app)}
      control={<Button size="sm" variant="quiet" aria-label={t("Remove :name", { name: rulePattern(rule) })} onClick={() => void onSave(rules.filter((r) => r.id !== rule.id))}>{t("Remove")}</Button>} />
  );
  return (
    <>
      <section className="el-group" aria-label={t("Record calls on")}>
        <SectionTitle>{t("Record calls on")}</SectionTitle>
        <Card><List label={t("Call sites")} className="el-group__rows">{BUILTIN_SITE_RULES.filter((r) => !isNeverRule(r)).map(builtinRow)}{mine.filter((r) => !isNeverRule(r)).map(userRow)}</List></Card>
      </section>
      <section className="el-group" aria-label={t("Never record")}>
        <SectionTitle>{t("Never record")}</SectionTitle>
        <Card><List label={t("Never record")} className="el-group__rows">{BUILTIN_SITE_RULES.filter(isNeverRule).map(builtinRow)}{mine.filter(isNeverRule).map(userRow)}</List></Card>
      </section>
      <Card label={t("Add a site")}>
        <form className="el-form" onSubmit={(e) => { e.preventDefault(); void add(); }}>
          <SectionTitle className="el-body-strong">{t("Add a site")}</SectionTitle>
          <div className="el-settings-v2__pair">
            <Select label={t("App")} value={app} onChange={setApp} options={SITE_RULE_APPS.map(([value, label]) => ({ value, label }))} />
            <TextField label={t("Address or title contains")} value={text} dir="ltr" onChange={(e) => setText(e.target.value)} />
          </div>
          <div className="el-page__actions"><Button type="submit" disabled={!text.trim()}>{t("Add")}</Button></div>
        </form>
      </Card>
    </>
  );
}
