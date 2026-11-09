// Settings › Recording (v1.7): Calls & meetings (what records by itself, after a call, call sites), Microphone & audio
// (the device, a 10-second test with its meters, the silent caller warning, the permissions) and Captions with its
// Style sub-page. Every row comes from the settings registry.
import { useEffect, useId, useRef, useState } from "react";
import {
  api,
  errorMessage,
  listenTo,
  type CaptionStyle,
  type Corner,
  type InputDevice,
  type PermissionsStatus,
  type Settings,
  type SettingsPatch,
  type SiteRule,
  type TestResult,
} from "../../lib/ipc";
import { meterLevel } from "../../lib/controls";
import { fillWidth } from "../../lib/motion";
import { BLUETOOTH_NOTE, bluetoothMic } from "../../lib/microphone";
import { sourceAppLabel } from "../../lib/format";
import { BUILTIN_SITE_RULES, SITE_RULE_APPS, builtinEnabled, customRules, hasSameRule, isNeverRule, ruleFromInput, rulePattern, withBuiltinEnabled } from "../../lib/sites";
import { SKIP_SHORTER_THAN } from "../../lib/pending";
import { CALL_APPS, setting } from "../../lib/settingsRegistry";
import { SourceAppIcon } from "../../components/Icons";
import { EntityRow, InfoRow, LinkRow, ResetRow, Section, SegmentedRow, SelectRow, SliderRow, SwitchRow, rowAttrs } from "./SettingsControls";

type Patch = (p: SettingsPatch) => Promise<boolean>;

/** Call apps auto mode can record, in the order the switches show. */
export const AUTO_RECORD_APPS = CALL_APPS.map(([app]) => app);

/** Is auto-record on for this app? Apps without a stored switch are on. */
export function appRecords(apps: Record<string, boolean>, app: string): boolean {
  return apps[app] !== false;
}

/** "WhatsApp, Zoom +2", or "Off": the first two apps that record by themselves. */
export function callAppsValue(settings: Pick<Settings, "autoMode" | "autoRecordApps">): string {
  if (!settings.autoMode) return "Off";
  const on = CALL_APPS.filter(([app]) => appRecords(settings.autoRecordApps, app)).map(([, name]) => name);
  if (on.length === 0) return "Off";
  return on.length <= 2 ? on.join(", ") : `${on.slice(0, 2).join(", ")} +${on.length - 2}`;
}

const options = (id: string) => (setting(id).options ?? []).map((o) => [o.value, o.label] as const);
const optionDefault = (id: string) => setting(id).options?.find((o) => o.default)?.value ?? "";

const AFTER_CALL_HINT: Record<Settings["afterCall"], string> = {
  keep: "Each call is saved at once. A small “Saved, Undo” shows near the tray for a few seconds.",
  ask: "A small prompt near the tray asks Keep or Discard, and keeps the call by itself after 10 seconds.",
};
/** A Mac has no tray: the window is in the corner of the screen. */
export function afterCallHint(choice: Settings["afterCall"], platform: string): string {
  return platform === "macos" ? AFTER_CALL_HINT[choice].replace("near the tray", "in the corner of the screen") : AFTER_CALL_HINT[choice];
}

/** Recording › Calls & meetings. */
export function CallsPage({ settings, patch, platform, onSites, consentRead, onConsent }: {
  settings: Settings;
  patch: Patch;
  platform: string;
  onSites: () => void;
  consentRead: boolean;
  onConsent: () => void;
}) {
  const sites = settings.siteRules.filter((r) => !isNeverRule(r)).length;
  return (
    <>
      <Section title="Record automatically" footer={<p>{setting("calls.ask_unknown").description}</p>}>
        <SwitchRow id="calls.auto" on={settings.autoMode} onChange={(v) => void patch({ autoMode: v })} />
        {CALL_APPS.map(([app, name]) => (
          <div key={app} className={`set-row ${settings.autoMode ? "" : "disabled"}`.trim()} {...rowAttrs(`calls.app.${app}`)}>
            <label className="set-hit">
              <span className="set-icon app" aria-hidden="true"><SourceAppIcon app={app} size={18} /></span>
              <span className="set-title">{name}</span>
              <button type="button" className={`switch ${appRecords(settings.autoRecordApps, app) ? "on" : ""}`} role="switch" aria-checked={appRecords(settings.autoRecordApps, app)}
                aria-label={`Auto-record ${name}`} disabled={!settings.autoMode} onClick={() => void patch({ autoRecordApps: { [app]: !appRecords(settings.autoRecordApps, app) } })} />
            </label>
          </div>
        ))}
        <SwitchRow id="calls.ask_unknown" on={settings.askUnknownApps} onChange={(v) => void patch({ askUnknownApps: v })} />
        {platform === "macos" && <SwitchRow id="calls.browser_tabs" on={settings.browserAutomation} onChange={(v) => void patch({ browserAutomation: v })} />}
      </Section>
      <Section title="After a call" footer={<><p>{afterCallHint(settings.afterCall, platform)}</p><p>Automatic calls this short, or where nobody spoke, are skipped quietly. Activity can bring them back for 24 hours.</p></>}>
        <SelectRow id="calls.after" value={settings.afterCall} options={options("calls.after") as readonly (readonly [Settings["afterCall"], string])[]} onChange={(v) => void patch({ afterCall: v })} />
        <SelectRow id="calls.skip_short" value={String(settings.skipCallsShorterThanS)} options={SKIP_SHORTER_THAN.map(([s, label]) => [String(s), label] as const)}
          onChange={(v) => void patch({ skipCallsShorterThanS: Number(v) })} />
      </Section>
      <Section title="Websites">
        <LinkRow id="calls.sites" value={sites === 1 ? "1 rule" : `${sites} rules`} onOpen={onSites} chevron />
      </Section>
      {!consentRead && (
        <div className="set-reset"><button type="button" className="btn text" onClick={onConsent}>Read the recording consent notice</button></div>
      )}
      <ResetRow page="Calls & meetings" onReset={() => void patch({
        autoMode: true, askUnknownApps: false, browserAutomation: false, afterCall: optionDefault("calls.after") as Settings["afterCall"],
        skipCallsShorterThanS: Number(optionDefault("calls.skip_short")), autoRecordApps: Object.fromEntries(AUTO_RECORD_APPS.map((a) => [a, true])),
      })} />
    </>
  );
}

/**
 * Calls & meetings › Call sites: the built-in rules with an on/off switch (off = a disabled user rule under the
 * built-in's id), the person's own rules, and the never-record list (rules with `app: "never"`).
 */
export function CallSitesPage({ rules, onSave }: { rules: SiteRule[]; onSave: (rules: SiteRule[]) => Promise<boolean> }) {
  const [app, setApp] = useState<string>(SITE_RULE_APPS[0][0]);
  const [text, setText] = useState("");
  const appId = useId();
  const textId = useId();
  const mine = customRules(rules);
  const add = async () => {
    const rule = ruleFromInput(app, text);
    if (!rule) return;
    if (hasSameRule(rules, rule) || (await onSave([...rules, rule]))) setText("");
  };
  const builtinRow = (rule: SiteRule) => {
    const on = builtinEnabled(rule, rules);
    const pattern = rulePattern(rule);
    const label = isNeverRule(rule) ? `Never record ${pattern}` : `Record calls on ${pattern}`;
    return (
      <EntityRow key={rule.id} leading={<SourceAppIcon app={isNeverRule(rule) ? undefined : rule.app} size={18} />} title={pattern} sub={isNeverRule(rule) ? undefined : sourceAppLabel(rule.app)}>
        <button type="button" className={`switch ${on ? "on" : ""}`} role="switch" aria-checked={on} aria-label={label} onClick={() => void onSave(withBuiltinEnabled(rules, rule, !on))} />
      </EntityRow>
    );
  };
  const userRow = (rule: SiteRule) => (
    <EntityRow key={rule.id} leading={<SourceAppIcon app={isNeverRule(rule) ? undefined : rule.app} size={18} />} title={rulePattern(rule)} sub={isNeverRule(rule) ? undefined : sourceAppLabel(rule.app)}>
      <button type="button" className="btn ghost compact" aria-label={`Remove ${rulePattern(rule)}`} onClick={() => void onSave(rules.filter((r) => r.id !== rule.id))}>Remove</button>
    </EntityRow>
  );
  return (
    <>
      <Section title="Record calls on">
        <div role="list" aria-label="Call sites">
          {BUILTIN_SITE_RULES.filter((r) => !isNeverRule(r)).map(builtinRow)}
          {mine.filter((r) => !isNeverRule(r)).map(userRow)}
        </div>
      </Section>
      <Section title="Never record">
        <div role="list" aria-label="Never record">
          {BUILTIN_SITE_RULES.filter(isNeverRule).map(builtinRow)}
          {mine.filter(isNeverRule).map(userRow)}
        </div>
      </Section>
      <Section title="Add a site">
        <form className="set-row set-form" onSubmit={(e) => { e.preventDefault(); void add(); }}>
          <label className="sr-only" htmlFor={appId}>App</label>
          <select id={appId} className="input set-select" value={app} onChange={(e) => setApp(e.target.value)}>
            {SITE_RULE_APPS.map(([value, name]) => <option key={value} value={value}>{name}</option>)}
          </select>
          <label className="sr-only" htmlFor={textId}>Address or title contains</label>
          <input id={textId} className="input" value={text} onChange={(e) => setText(e.target.value)} placeholder="Address or title contains" />
          <button type="submit" className="btn" disabled={!text.trim()}>Add</button>
        </form>
      </Section>
    </>
  );
}

/** What the test result says to the user, one sentence per side. */
export function testVerdict(result: Pick<TestResult, "micHeard" | "systemHeard">): string[] {
  return [
    result.micHeard ? "Your microphone is working." : "We didn't hear your microphone. Check the microphone above and its permission.",
    result.systemHeard
      ? "Sound from this computer was captured."
      : "No sound from this computer was captured. Play something during the test to check the other side of calls.",
  ];
}

const PERMISSION_TEXT: Record<PermissionsStatus["microphone"], string> = {
  allowed: "Allowed",
  denied: "Not allowed",
  unknown: "Asked on first use",
};

/** Recording › Microphone & audio. */
export function MicrophonePage({ settings, patch, disabled, systemAudioNote }: { settings: Settings; patch: Patch; disabled: boolean; systemAudioNote?: string | null }) {
  const [devices, setDevices] = useState<InputDevice[]>([]);
  const [permissions, setPermissions] = useState<PermissionsStatus | null>(null);
  const [testing, setTesting] = useState(false);
  const [levels, setLevels] = useState<[number, number]>([0, 0]);
  const [result, setResult] = useState<TestResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    api.inputDevices().then((d) => mounted.current && setDevices(d)).catch(() => undefined);
    api.permissionsStatus().then((p) => mounted.current && setPermissions(p)).catch(() => undefined);
    const un = listenTo("capture:test_level", (l) => mounted.current && setLevels(l));
    return () => {
      mounted.current = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  const chosenMissing = !!settings.inputDevice && devices.length > 0 && !devices.some((d) => d.id === settings.inputDevice);
  const bluetooth = bluetoothMic(devices, settings.inputDevice);
  const test = async () => {
    setTesting(true);
    setResult(null);
    setError(null);
    try {
      const r = await api.testCapture();
      if (mounted.current) setResult(r);
    } catch (e) {
      if (mounted.current) setError(errorMessage(e));
    } finally {
      if (mounted.current) {
        setTesting(false);
        setLevels([0, 0]);
      }
    }
  };
  const me = meterLevel(testing ? levels[0] : result?.micPeak ?? 0);
  const them = meterLevel(testing ? levels[1] : result?.systemPeak ?? 0);
  const footer = [
    chosenMissing && "The microphone you chose isn't connected, so the system default is used.",
    bluetooth && `${BLUETOOTH_NOTE}. A wired or built-in microphone hears calls better.`,
    disabled && "Finish the current recording to run a test.",
  ].filter(Boolean) as string[];
  return (
    <>
      <Section footer={footer.length > 0 || systemAudioNote ? <>
        {footer.map((line) => line.startsWith(BLUETOOTH_NOTE)
          ? <p key={line} data-testid="bluetooth-note" title={bluetooth?.name}>{line}</p> : <p key={line}>{line}</p>)}
        {systemAudioNote && <p className="warning-text" data-testid="whole-system-note">{systemAudioNote}.</p>}
      </> : undefined}>
        <SelectRow id="mic.device" value={chosenMissing ? "" : settings.inputDevice ?? ""}
          options={[["", "Automatic"], ...devices.map((d) => [d.id, `${d.name}${d.isDefault ? " (default)" : ""}`] as const)]} width={260}
          onChange={(v) => void patch({ inputDevice: v || null })} />
        <div className="set-row set-meters" {...rowAttrs("mic.levels")} aria-live="polite">
          <span className="set-title">Microphone level</span>
          <span className="set-end set-meter-pair">
            <span className="meter" role="meter" aria-label="Microphone level" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(me * 100)}><span style={fillWidth(me)} /></span>
            <span className="small muted">Computer</span>
            <span className="meter" role="meter" aria-label="Computer sound level" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(them * 100)}><span style={fillWidth(them)} /></span>
          </span>
        </div>
        <InfoRow id="mic.test">
          <button type="button" className="btn compact" disabled={testing || disabled} onClick={() => void test()}>{testing ? "Recording…" : result ? "Test again" : "Test 10 s"}</button>
        </InfoRow>
        {result && (
          <div className="set-row set-block">
            {testVerdict(result).map((line) => <p className="small" key={line}>{line}</p>)}
            <audio controls src={`data:audio/wav;base64,${result.wavBase64}`} aria-label="Play back the test recording" />
          </div>
        )}
        {error && <div className="set-row set-block"><p className="small error-text">{error}</p></div>}
        <SwitchRow id="mic.far_side_warning" on={settings.farSideWarning} onChange={(farSideWarning) => void patch({ farSideWarning })} />
      </Section>
      <Section title="Permissions" footer={<p>{setting("mic.far_side_warning").description}</p>}>
        <InfoRow id="mic.perm.microphone" value={permissions ? PERMISSION_TEXT[permissions.microphone] : "Checking…"}>
          {permissions && permissions.microphone !== "allowed" && permissions.platform !== "other" && (
            <button type="button" className="btn compact" onClick={() => void api.openPrivacySettings("microphone").catch(() => undefined)}>Fix</button>
          )}
        </InfoRow>
        {permissions?.platform === "macos" && (
          <InfoRow id="mic.perm.system_audio" value={permissions.systemAudio === "allowed" ? "Allowed" : permissions.systemAudio === "denied" ? "Not allowed" : "Asked on first use"}>
            {permissions.systemAudio !== "allowed" && (
              <button type="button" className="btn compact" onClick={() => void api.openPrivacySettings("system_audio").catch(() => undefined)}>Fix</button>
            )}
          </InfoRow>
        )}
      </Section>
      <ResetRow page="Microphone & audio" onReset={() => void patch({ inputDevice: null, farSideWarning: true })} />
    </>
  );
}

/** The speaker colours of the dark caption pill (the pill meters use the same). */
export const DEFAULT_ME_COLOR = "#9eb1ff";
export const DEFAULT_CLIENT_COLOR = "#0bd8b6";
/** The swatches after Automatic, the phone's six. */
export const COLOUR_PRESETS = ["#a78bfa", "#22d3ee", "#34d399", "#fbbf24", "#fb7185", "#ffffff"] as const;

/** The caption as the overlay draws it, with the chosen colours and size. */
function CaptionPreview({ style }: { style: CaptionStyle }) {
  const size = style.size === "small" ? 13 : style.size === "large" ? 19 : 15;
  return (
    <div className="set-preview" aria-hidden="true">
      <div className="caption-sample" style={{ fontSize: size, background: `rgba(24, 25, 27, ${style.opacity / 100})` }}>
        <div><span style={{ color: style.clientColor ?? DEFAULT_CLIENT_COLOR }}>{style.compact ? "" : "Client  "}</span>Can you send the estimate by Monday?</div>
        <div><span style={{ color: style.meColor ?? DEFAULT_ME_COLOR }}>{style.compact ? "" : "Me  "}</span>Yes, with the timeline.</div>
      </div>
    </div>
  );
}

/** Recording › Captions. */
export function CaptionsPage({ settings, patch, onStyle }: { settings: Settings; patch: Patch; onStyle: () => void }) {
  return (
    <>
      <CaptionPreview style={settings.captionStyle} />
      <Section footer={<><p>{setting("captions.screen_share").description}</p><p>You can also right-click the captions while recording to change their style.</p></>}>
        <SwitchRow id="captions.enabled" on={settings.captionsOnRecord} onChange={(v) => void patch({ captionsOnRecord: v })} />
        <SwitchRow id="captions.screen_share" on={!settings.contentProtection} onChange={(v) => void patch({ contentProtection: !v })} />
        <LinkRow id="captions.style" onOpen={onStyle} chevron />
      </Section>
      <ResetRow page="Captions" onReset={() => void patch({ captionsOnRecord: true, contentProtection: true })} />
    </>
  );
}

/** A colour row: Automatic plus six swatches (a colour picked earlier that is none of them shows as a seventh). */
function SwatchRow({ id, value, onChange }: { id: string; value: string | null; onChange: (c: string | null) => void }) {
  const labelId = useId();
  const presets: string[] = [...COLOUR_PRESETS];
  if (value && !presets.includes(value.toLowerCase())) presets.push(value.toLowerCase());
  const title = setting(id).title;
  return (
    <div className="set-row" {...rowAttrs(id)}>
      <span id={labelId} className="set-title">{title}</span>
      <span className="set-swatches" role="radiogroup" aria-labelledby={labelId}>
        <button type="button" role="radio" aria-checked={value === null} className="swatch auto" onClick={() => onChange(null)}>Automatic</button>
        {presets.map((c) => (
          <button key={c} type="button" role="radio" aria-checked={value?.toLowerCase() === c} aria-label={c} className="swatch" style={{ background: c }} onClick={() => onChange(c)} />
        ))}
      </span>
    </div>
  );
}

/** Recording › Captions › Style. Every change saves the whole style; the overlay restyles at once. */
export function CaptionStylePage({ settings, patch }: { settings: Settings; patch: Patch }) {
  const style = settings.captionStyle;
  const save = (next: Partial<CaptionStyle>) => void patch({ captionStyle: { ...style, ...next } });
  return (
    <>
      <CaptionPreview style={style} />
      <Section footer={<p>{setting("captions.compact").description}</p>}>
        <SegmentedRow id="captions.size" value={style.size} options={options("captions.size") as readonly (readonly [CaptionStyle["size"], string])[]} onChange={(size) => save({ size })} />
        <SegmentedRow id="captions.lines" value={String(settings.captionLines)} options={options("captions.lines")} onChange={(v) => void patch({ captionLines: Number(v) })} />
        <SelectRow id="captions.position" value={settings.overlayCorner} options={options("captions.position") as readonly (readonly [Corner, string])[]} onChange={(v) => void patch({ overlayCorner: v })} />
        <SegmentedRow id="captions.width" value={style.width} options={options("captions.width") as readonly (readonly [CaptionStyle["width"], string])[]} onChange={(width) => save({ width })} />
        <SelectRow id="captions.autohide" value={String(style.autoHideSeconds)} options={options("captions.autohide")} onChange={(v) => save({ autoHideSeconds: Number(v) })} />
        <SliderRow id="captions.opacity" value={style.opacity} min={40} max={100} step={5} format={(v) => `${v}%`} onChange={(opacity) => save({ opacity })} />
        <SwitchRow id="captions.compact" on={style.compact} onChange={(compact) => save({ compact })} />
        <SwatchRow id="captions.me_colour" value={style.meColor} onChange={(meColor) => save({ meColor })} />
        <SwatchRow id="captions.client_colour" value={style.clientColor} onChange={(clientColor) => save({ clientColor })} />
      </Section>
      <ResetRow page="Style" onReset={() => void patch({
        captionLines: 3, overlayCorner: "bottom_right",
        captionStyle: { ...style, size: "medium", width: "normal", opacity: 85, autoHideSeconds: 0, compact: false, meColor: null, clientColor: null },
      })} />
    </>
  );
}

