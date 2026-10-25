import { useEffect, useId, useState, type ReactNode } from "react";
import { api, listenTo, type AppStatus, type AskBeforeSaving, type Corner, type DecisionTimeout, type Settings, type ShortcutAction, type SiteRule, type TranscriptionLanguage } from "../../lib/ipc";
import { acceleratorFromEvent, prettyShortcut, sourceAppLabel } from "../../lib/format";
import { BUILTIN_SITE_RULES, SITE_RULE_APPS, builtinEnabled, customRules, hasSameRule, isNeverRule, ruleFromInput, rulePattern, withBuiltinEnabled } from "../../lib/sites";
import { SourceAppIcon } from "../../components/Icons";
import { SelectRow, Toggle } from "./SettingsControls";
import { AutoRecordApps, CaptionStyleCard, MicrophoneCard } from "./CaptureSettings";

const ACTIONS: [ShortcutAction, string][] = [
  ["toggle_captions", "Show / hide live captions"],
  ["toggle_saving", "Start / stop saving a recording"],
  ["copy_last2_min", "Copy the last 2 minutes of captions"],
  ["dictation", "Dictation box (press, speak, press again)"],
  ["voice_note", "Capture voice note playing now"],
  ["mark", "Bookmark this moment of a recording"],
];

const ASK_BEFORE_SAVING: readonly (readonly [AskBeforeSaving, string])[] = [["always", "Always"], ["auto_only", "Only automatic calls"], ["never", "Never"]];
const DECISION_TIMEOUT: readonly (readonly [DecisionTimeout, string])[] = [["keep", "Keep the recording"], ["discard", "Discard it"], ["wait", "Keep waiting"]];
const CAPTION_LINES = [["2", "2"], ["3", "3"], ["5", "5"]] as const;
/** Automatic uses the client's language or the console default, else detects it. */
const LANGUAGES: readonly (readonly ["" | TranscriptionLanguage, string])[] = [["", "Automatic"], ["en", "English"], ["ur", "Urdu"], ["hi", "Hindi"], ["en+ur", "English + Urdu"]];

/**
 * Settings → Call sites (A1.4): the built-in rules with an on/off switch (off = a disabled user rule under the
 * built-in's id), the user's own rules, and the never-record list (rules with `app: "never"`).
 */
function CallSites({ rules, onSave }: { rules: SiteRule[]; onSave: (rules: SiteRule[]) => Promise<boolean> }) {
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

  /** One rule: what it matches, the app it records as (never-record rules name none), and its control. */
  const ruleRow = (rule: SiteRule, control: ReactNode) => (
    <div className={`site-rule ${rule.enabled ? "" : "off"}`} role="listitem" key={rule.id}>
      <span className="site-icon"><SourceAppIcon app={isNeverRule(rule) ? undefined : rule.app} size={15} /></span>
      <div className="site-main">
        <div className="site-pattern">{rulePattern(rule)}</div>
        {!isNeverRule(rule) && <div className="small muted">{sourceAppLabel(rule.app)}</div>}
      </div>
      {control}
    </div>
  );

  const builtinRow = (rule: SiteRule) => {
    const on = builtinEnabled(rule, rules);
    const pattern = rulePattern(rule);
    const label = isNeverRule(rule) ? `Never record ${pattern}` : `Record calls on ${pattern}`;
    return ruleRow(
      { ...rule, enabled: on },
      <button type="button" className={`switch ${on ? "on" : ""}`} role="switch" aria-checked={on} aria-label={label} onClick={() => void onSave(withBuiltinEnabled(rules, rule, !on))} />,
    );
  };

  const userRow = (rule: SiteRule) =>
    ruleRow(
      rule,
      <button type="button" className="btn ghost compact" aria-label={`Remove ${rulePattern(rule)}`} onClick={() => void onSave(rules.filter((r) => r.id !== rule.id))}>Remove</button>,
    );

  return (
    <div className="card">
      <b>Call sites</b>
      <div className="site-list" role="list" aria-label="Call sites">
        {BUILTIN_SITE_RULES.filter((r) => !isNeverRule(r)).map(builtinRow)}
        {mine.filter((r) => !isNeverRule(r)).map(userRow)}
      </div>
      <div className="site-subhead">Never record</div>
      <div className="site-list" role="list" aria-label="Never record">
        {BUILTIN_SITE_RULES.filter(isNeverRule).map(builtinRow)}
        {mine.filter(isNeverRule).map(userRow)}
      </div>
      <form className="site-add" onSubmit={(e) => { e.preventDefault(); void add(); }}>
        <label className="sr-only" htmlFor={appId}>App</label>
        <select id={appId} className="input" value={app} onChange={(e) => setApp(e.target.value)}>
          {SITE_RULE_APPS.map(([value, name]) => <option key={value} value={value}>{name}</option>)}
        </select>
        <label className="sr-only" htmlFor={textId}>Address or title contains</label>
        <input id={textId} className="input" value={text} onChange={(e) => setText(e.target.value)} placeholder="Address or title contains" />
        <button type="submit" className="btn" disabled={!text.trim()}>Add</button>
      </form>
    </div>
  );
}

export function SettingsView({ status, run, onUnpaired }: {
  status: AppStatus;
  run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
  onUnpaired: (s: AppStatus) => void;
}) {
  const [settings, setSettings] = useState<Settings | null>(null);
  const [recording, setRecording] = useState<ShortcutAction | null>(null);
  const [clients, setClients] = useState<{ id: string; name: string }[]>([]);

  useEffect(() => {
    api.settings().then(setSettings).catch(() => undefined);
    api.clients().then((r) => setClients(r.data)).catch(() => undefined);
    // Changes made elsewhere (tray, "Always ignore" on the ask chip, a removed client) show up while this is open.
    const un = listenTo("settings:changed", (next) => next && setSettings(next));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  useEffect(() => {
    if (!recording || !settings) return;
    const onKey = async (e: KeyboardEvent) => {
      e.preventDefault();
      if (e.key === "Escape") return setRecording(null);
      const accel = acceleratorFromEvent(e, status.platform);
      if (!accel) return;
      setRecording(null);
      const next = await run(() => api.setShortcut(recording, accel), "Shortcut saved");
      if (next) setSettings(next);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [recording, settings, run, status.platform]);

  if (!settings) return null;
  /** Saves a change; resolves to true when the core accepted it. */
  const patch = async (p: Parameters<typeof api.updateSettings>[0]): Promise<boolean> => {
    const next = await run(() => api.updateSettings(p));
    if (next) setSettings(next);
    return !!next;
  };
  const errorFor = (a: ShortcutAction) => status.shortcutErrors.find(([x]) => x === a)?.[1];

  return (
    <>
      <h1 className="page-title">Settings</h1>
      <p className="page-sub">Paired with {status.serverUrl} {status.pinned ? "· certificate pinned" : "· not pinned"}</p>
      <div className="card">
        <b>Recording</b>
        <Toggle label="Auto-record calls" hint="Records a call when an app both uses your microphone and plays audio. Dictation and voice typing are never recorded." on={settings.autoMode} onChange={(v) => patch({ autoMode: v })} />
        <Toggle label="Ask me when an unrecognised app uses the microphone" hint="A small prompt appears for 8 seconds." on={settings.askUnknownApps} onChange={(v) => patch({ askUnknownApps: v })} />
        {status.platform === "macos" && (
          <Toggle label="Read browser tab addresses" hint="Lets EchoLedger tell a Google Meet call from dictation. macOS asks for permission once." on={settings.browserAutomation} onChange={(v) => patch({ browserAutomation: v })} />
        )}
        <SelectRow label="Ask before saving" value={settings.askBeforeSaving} options={ASK_BEFORE_SAVING} onChange={(v) => patch({ askBeforeSaving: v })} />
        <SelectRow label="If I don't choose within 60 s" value={settings.decisionTimeout} options={DECISION_TIMEOUT} onChange={(v) => patch({ decisionTimeout: v })} />
        <SelectRow label="Transcription language" value={(settings.transcriptionLanguage ?? "") as "" | TranscriptionLanguage} options={LANGUAGES} width={220}
          onChange={(v) => patch({ transcriptionLanguage: v === "" ? null : v })} />
        <Toggle label="Show live captions while recording" on={settings.captionsOnRecord} onChange={(v) => patch({ captionsOnRecord: v })} />
        <SelectRow label="Caption lines" value={String(settings.captionLines) as "2" | "3" | "5"} options={CAPTION_LINES} width={180} onChange={(v) => patch({ captionLines: Number(v) })} />
        <Toggle label="Hide overlays from screen sharing" hint="Captions stay visible to you but not in Zoom/Meet screen shares." on={settings.contentProtection} onChange={(v) => patch({ contentProtection: v })} />
        <Toggle label="Start EchoLedger when I sign in" on={settings.autostart} onChange={(v) => patch({ autostart: v })} />
        <div className="toggle">
          <label htmlFor="settings-overlay-corner">Caption position</label>
          <select id="settings-overlay-corner" className="input" style={{ width: 180 }} value={settings.overlayCorner} onChange={(e) => patch({ overlayCorner: e.target.value as Corner })}>
            <option value="bottom_right">Bottom right</option><option value="top_right">Top right</option>
            <option value="bottom_left">Bottom left</option><option value="top_left">Top left</option>
          </select>
        </div>
        <div className="toggle">
          <div><label htmlFor="settings-client">Client keyterms</label><div id="settings-client-hint" className="small muted">Names and jargon from this client boost accuracy.</div></div>
          {/* "No client" sends clientId: null (clears it); a missing key would keep the stored client (A6.DU4). */}
          <select id="settings-client" aria-describedby="settings-client-hint" className="input" style={{ width: 220 }} value={settings.clientId ?? ""} onChange={(e) => patch({ clientId: e.target.value || null })}>
            <option value="">No client</option>
            {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
        <div className="toggle">
          <label htmlFor="settings-dictation-output">Dictation output</label>
          <select id="settings-dictation-output" className="input" style={{ width: 220 }} value={settings.dictationOutput} onChange={(e) => patch({ dictationOutput: e.target.value as "type" | "clipboard" })}>
            <option value="type">Type into the focused app</option><option value="clipboard">Copy to clipboard</option>
          </select>
        </div>
      </div>
      <AutoRecordApps settings={settings} patch={patch} />
      <CallSites rules={settings.siteRules} onSave={(siteRules) => patch({ siteRules: siteRules })} />
      <CaptionStyleCard settings={settings} patch={patch} />
      <MicrophoneCard settings={settings} patch={patch} disabled={!!status.recording || status.dictationActive} />
      <div className="card">
        <div className="row"><b>Global shortcuts</b><span className="spacer" /><button className="btn ghost small" onClick={async () => { const s = await run(api.resetShortcuts, "Shortcuts reset"); if (s) setSettings(s); }}>Reset defaults</button></div>
        {ACTIONS.map(([action, label]) => (
          <div className="toggle" key={action}>
            <div><div>{label}</div>{errorFor(action) && <div className="small error-text">{errorFor(action)}</div>}</div>
            <div className="row">
              <span className="kbd">{recording === action ? "Press keys…" : prettyShortcut(settings.shortcuts[action], status.platform)}</span>
              <button className="btn" onClick={() => setRecording(action)}>Change</button>
              {settings.shortcuts[action] && <button className="btn ghost" onClick={async () => { const s = await run(() => api.setShortcut(action, "")); if (s) setSettings(s); }}>Clear</button>}
            </div>
          </div>
        ))}
        <p className="small muted">Tip: Ctrl+Shift+C and Ctrl+Shift+R also open DevTools / hard-reload in browsers; change them if that bothers you.</p>
      </div>
      <div className="card">
        <b>Privacy &amp; consent</b>
        <p className="small muted">Call-recording laws differ by country. Tell participants you are recording where required. EchoLedger shows a red indicator whenever it records.</p>
        <Toggle label="I understand and will obtain consent where required" on={settings.consentAcknowledged} onChange={(v) => patch({ consentAcknowledged: v })} />
      </div>
      <div className="card">
        <div className="row"><div><b>Device</b><div className="small muted">{status.deviceName}</div></div><span className="spacer" />
          <button className="btn danger" onClick={async () => { if (window.confirm("Unpair this computer? Pending uploads stay queued until you pair again.")) { const s = await run(api.unpair); if (s) onUnpaired(s); } }}>Unpair</button>
        </div>
      </div>
    </>
  );
}
