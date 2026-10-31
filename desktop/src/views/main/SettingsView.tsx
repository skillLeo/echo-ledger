import { useEffect, useId, useState, type ReactNode } from "react";
import { api, listenTo, type AppStatus, type AskBeforeSaving, type Corner, type DecisionTimeout, type Settings, type ShortcutAction, type SiteRule, type TranscriptionLanguage } from "../../lib/ipc";
import { acceleratorFromEvent, prettyShortcut, sourceAppLabel } from "../../lib/format";
import { BUILTIN_SITE_RULES, SITE_RULE_APPS, builtinEnabled, customRules, hasSameRule, isNeverRule, ruleFromInput, rulePattern, withBuiltinEnabled } from "../../lib/sites";
import { CallIcon, ChevronRightIcon, KeyboardIcon, MicIcon, SearchIcon, SettingsIcon, SourceAppIcon, TuneIcon } from "../../components/Icons";
import { applyTheme, getThemeChoice, saveThemeChoice, type ThemeChoice } from "../../lib/theme";
import { SelectRow, Toggle } from "./SettingsControls";
import { AutoRecordApps, CaptionStyleCard, MicrophoneCard } from "./CaptureSettings";
import { DictationCard } from "./DictationSettings";
import { DevicesCard, LockCard, NotificationsCard, StorageCard } from "./SecuritySettings";
import { conflictFor } from "../../lib/shortcutConflicts";
import { getVersion } from "@tauri-apps/api/app";
import { LANGUAGE_OPTIONS, menuLabel } from "../../lib/languages";

const ACTIONS: [ShortcutAction, string][] = [
  ["toggle_captions", "Show / hide live captions"],
  ["toggle_saving", "Start / stop saving a recording"],
  ["copy_last2_min", "Copy the last 2 minutes of captions"],
  ["dictation", "Dictation box"],
  ["voice_note", "Capture voice note playing now"],
  ["mark", "Bookmark this moment of a recording"],
];

type Group = "general" | "calls" | "voice" | "shortcuts";
/** General, then one panel per way of using the app, as on the phone's settings (B19). */
export const GROUPS: readonly Group[] = ["general", "calls", "voice", "shortcuts"];
export const GROUP_LABEL: Record<Group, string> = {
  general: "General", calls: "Calls & meetings", voice: "Voice notes & dictation", shortcuts: "Shortcuts",
};
const GROUP_SUB: Record<Group, string> = {
  general: "This computer, look, lock, privacy, storage",
  calls: "Call apps, after a call, captions, microphone",
  voice: "The dictation box, its words and history",
  shortcuts: "Keys for captions, saving, bookmarks, the app",
};
const GROUP_ICON: Record<Group | "all", typeof SettingsIcon> = { all: SettingsIcon, general: TuneIcon, calls: CallIcon, voice: MicIcon, shortcuts: KeyboardIcon };
const THEMES: readonly [ThemeChoice, string][] = [["system", "System"], ["light", "Light"], ["dark", "Dark"]];
/** Keys of the main window itself (not global; they work while EchoLedger is in front). */
const APP_KEYS: readonly [string, string][] = [
  ["Ctrl+K", "Search recordings and run commands"],
  ["N", "Start or stop a voice note"],
  ["Space", "Play or pause the open recording"],
  ["J / L", "Back or forward 10 seconds"],
  ["Ctrl+F", "Find in the open transcript"],
];
interface SettingsSection { id: string; group: Group; title: string; keywords: string; node: ReactNode }

const ASK_BEFORE_SAVING: readonly (readonly [AskBeforeSaving, string])[] = [["always", "Always"], ["auto_only", "Only automatic calls"], ["never", "Never"]];
const DECISION_TIMEOUT: readonly (readonly [DecisionTimeout, string])[] = [["keep", "Keep the recording"], ["discard", "Discard it"], ["wait", "Keep waiting"]];
const CAPTION_LINES = [["2", "2"], ["3", "3"], ["5", "5"]] as const;
/** Automatic uses the client's language or the console default, else detects it. */
// Auto, English, Urdu, Arabic, then the More languages (lib/languages); Urdu and Arabic allow English words inside.
const LANGUAGES: readonly (readonly ["" | TranscriptionLanguage, string])[] = LANGUAGE_OPTIONS.map((o) => [o.value ?? "", o.more ? `${o.label} (more languages)` : menuLabel(o)] as const);

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

export function SettingsView({ status, run, onUnpaired, theme, onTheme }: {
  status: AppStatus;
  run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
  onUnpaired: (s: AppStatus) => void;
  /** The theme choice and how to change it (the sidebar's toggle does the same). */
  theme?: ThemeChoice;
  onTheme?: (theme: ThemeChoice) => void;
}) {
  const [ownTheme, setOwnTheme] = useState<ThemeChoice>(getThemeChoice);
  const themeChoice = theme ?? ownTheme;
  const chooseTheme = onTheme ?? ((next: ThemeChoice) => {
    saveThemeChoice(next);
    applyTheme(next);
    setOwnTheme(next);
  });
  const [settings, setSettings] = useState<Settings | null>(null);
  const [recording, setRecording] = useState<ShortcutAction | null>(null);
  const [clients, setClients] = useState<{ id: string; name: string }[]>([]);
  const [group, setGroup] = useState<Group | "all">("all");
  const [query, setQuery] = useState("");

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

  const shortcutConflict = (action: ShortcutAction) => conflictFor(settings.shortcuts[action], status.platform);
  const sections: SettingsSection[] = [
    { id: "general", group: "general", title: "General", keywords: "start sign in login autostart language transcription client keyterms", node: (
      <div className="card">
        <b>General</b>
        <Toggle label="Start EchoLedger when I sign in" on={settings.autostart} onChange={(v) => patch({ autostart: v })} />
        <SelectRow label="Transcription language" value={(settings.transcriptionLanguage ?? "") as "" | TranscriptionLanguage} options={LANGUAGES} width={220}
          onChange={(v) => patch({ transcriptionLanguage: v === "" ? null : v })} />
        <div className="toggle">
          <div><label htmlFor="settings-client">Client keyterms</label><div id="settings-client-hint" className="small muted">Names and jargon from this client boost accuracy.</div></div>
          {/* "No client" sends clientId: null (clears it); a missing key would keep the stored client (A6.DU4). */}
          <select id="settings-client" aria-describedby="settings-client-hint" className="input" style={{ width: 220 }} value={settings.clientId ?? ""} onChange={(e) => patch({ clientId: e.target.value || null })}>
            <option value="">No client</option>
            {clients.map((cl) => <option key={cl.id} value={cl.id}>{cl.name}</option>)}
          </select>
        </div>
      </div>
    ) },
    { id: "appearance", group: "general", title: "Appearance", keywords: "theme dark light system look", node: (
      <div className="card">
        <b>Appearance</b>
        <div className="toggle">
          <span id="settings-theme">Theme</span>
          <span className="segmented" role="group" aria-labelledby="settings-theme">
            {THEMES.map(([value, text]) => <button key={value} type="button" aria-pressed={themeChoice === value} onClick={() => chooseTheme(value)}>{text}</button>)}
          </span>
        </div>
      </div>
    ) },
    { id: "notifications", group: "general", title: "Notifications", keywords: "notifications quiet hours bell activity", node: <NotificationsCard settings={settings} patch={patch} /> },
    { id: "consent", group: "general", title: "Privacy & consent", keywords: "consent law participants", node: (
      <div className="card">
        <b>Privacy &amp; consent</b>
        <p className="small muted">Call-recording laws differ by country. Tell participants you are recording where required. EchoLedger shows a red indicator whenever it records.</p>
        <Toggle label="I understand and will obtain consent where required" on={settings.consentAcknowledged} onChange={(v) => patch({ consentAcknowledged: v })} />
      </div>
    ) },
    { id: "recording", group: "calls", title: "Recording", keywords: "auto record calls unknown apps ask before saving decision timeout browser tab", node: (
      <div className="card">
        <b>Recording</b>
        <Toggle label="Auto-record calls" hint="Records a call when an app both uses your microphone and plays audio. Dictation and voice typing are never recorded." on={settings.autoMode} onChange={(v) => patch({ autoMode: v })} />
        <Toggle label="Ask me when an unrecognised app uses the microphone" hint="A small prompt appears for 8 seconds." on={settings.askUnknownApps} onChange={(v) => patch({ askUnknownApps: v })} />
        {status.platform === "macos" && (
          <Toggle label="Read browser tab addresses" hint="Lets EchoLedger tell a Google Meet call from dictation. macOS asks for permission once." on={settings.browserAutomation} onChange={(v) => patch({ browserAutomation: v })} />
        )}
        <SelectRow label="Ask before saving" value={settings.askBeforeSaving} options={ASK_BEFORE_SAVING} onChange={(v) => patch({ askBeforeSaving: v })} />
        <SelectRow label="If I don't choose within 60 s" value={settings.decisionTimeout} options={DECISION_TIMEOUT} onChange={(v) => patch({ decisionTimeout: v })} />
      </div>
    ) },
    { id: "auto-apps", group: "calls", title: "Auto-record apps", keywords: "whatsapp zoom teams meet apps", node: <AutoRecordApps settings={settings} patch={patch} /> },
    { id: "call-sites", group: "calls", title: "Call sites", keywords: "sites browser never record rules", node: <CallSites rules={settings.siteRules} onSave={(siteRules) => patch({ siteRules: siteRules })} /> },
    { id: "microphone", group: "calls", title: "Microphone", keywords: "microphone device test permission silence far side", node: <MicrophoneCard settings={settings} patch={patch} disabled={!!status.recording || status.dictationActive} /> },
    { id: "captions", group: "calls", title: "Captions", keywords: "captions lines overlay screen sharing position corner", node: (
      <div className="card">
        <b>Captions</b>
        <Toggle label="Show live captions while recording" on={settings.captionsOnRecord} onChange={(v) => patch({ captionsOnRecord: v })} />
        <SelectRow label="Caption lines" value={String(settings.captionLines) as "2" | "3" | "5"} options={CAPTION_LINES} width={180} onChange={(v) => patch({ captionLines: Number(v) })} />
        <Toggle label="Hide overlays from screen sharing" hint="Captions stay visible to you but not in Zoom/Meet screen shares." on={settings.contentProtection} onChange={(v) => patch({ contentProtection: v })} />
        <div className="toggle">
          <label htmlFor="settings-overlay-corner">Caption position</label>
          <select id="settings-overlay-corner" className="input" style={{ width: 180 }} value={settings.overlayCorner} onChange={(e) => patch({ overlayCorner: e.target.value as Corner })}>
            <option value="bottom_right">Bottom right</option><option value="top_right">Top right</option>
            <option value="bottom_left">Bottom left</option><option value="top_left">Top left</option>
          </select>
        </div>
      </div>
    ) },
    { id: "caption-style", group: "calls", title: "Caption style", keywords: "size width opacity colour compact", node: <CaptionStyleCard settings={settings} patch={patch} /> },
    { id: "dictation", group: "voice", title: "Dictation", keywords: "dictation hold talk clean up sounds replacements snippets history", node: <DictationCard settings={settings} patch={patch} /> },
    { id: "shortcuts", group: "shortcuts", title: "Global shortcuts", keywords: "shortcuts keys hotkeys keyboard", node: (
      <div className="card">
        <div className="row"><b>Global shortcuts</b><span className="spacer" /><button className="btn ghost small" onClick={async () => { const s = await run(api.resetShortcuts, "Shortcuts reset"); if (s) setSettings(s); }}>Reset defaults</button></div>
        {ACTIONS.map(([action, label]) => (
          <div className="toggle" key={action}>
            <div>
              <div>{label}</div>
              {errorFor(action) && <div className="small error-text">{errorFor(action)}</div>}
              {!errorFor(action) && shortcutConflict(action) && <div className="small warning-text">{`This shortcut also ${shortcutConflict(action)}; EchoLedger takes it over.`}</div>}
            </div>
            <div className="row">
              <span className="kbd">{recording === action ? "Press keys…" : prettyShortcut(settings.shortcuts[action], status.platform)}</span>
              <button className="btn" onClick={() => setRecording(action)}>Change</button>
              {settings.shortcuts[action] && <button className="btn ghost" onClick={async () => { const s = await run(() => api.setShortcut(action, "")); if (s) setSettings(s); }}>Clear</button>}
            </div>
          </div>
        ))}
        <p className="small muted">A shortcut used by another EchoLedger action is refused; one used by another app is reported here.</p>
      </div>
    ) },
    { id: "app-keys", group: "shortcuts", title: "In the app", keywords: "keys search voice note play pause find", node: (
      <div className="card">
        <b>In the app</b>
        {APP_KEYS.map(([keys, what]) => (
          <div className="toggle" key={keys}><span>{what}</span><span className="kbd">{status.platform === "macos" ? keys.replace("Ctrl+", "⌘") : keys}</span></div>
        ))}
      </div>
    ) },
    { id: "lock", group: "general", title: "App lock", keywords: "lock pin windows hello touch id privacy security", node: <LockCard settings={settings} patch={patch} run={run} /> },
    { id: "devices", group: "general", title: "Devices", keywords: "devices revoke paired activity audit", node: <DevicesCard run={run} /> },
    { id: "this-device", group: "general", title: "This computer", keywords: "unpair device name", node: (
      <div className="card">
        <div className="row"><div><b>Device</b><div className="small muted">{status.deviceName}</div></div><span className="spacer" />
          <button className="btn danger" onClick={async () => { if (window.confirm("Unpair this computer? Pending uploads stay queued until you pair again.")) { const s = await run(api.unpair); if (s) onUnpaired(s); } }}>Unpair</button>
        </div>
      </div>
    ) },
    { id: "storage", group: "general", title: "Storage and retention", keywords: "storage disk retention deleted bin", node: <StorageCard /> },
    { id: "about", group: "general", title: "About", keywords: "version server export import backup settings file", node: <AboutCard status={status} run={run} onImported={setSettings} /> },
  ];
  const q = query.trim().toLowerCase();
  // Panels in their order (General first), the cards of a panel in the order they are listed.
  const visible = sections
    .filter((s) => (q ? `${s.title} ${s.keywords} ${GROUP_LABEL[s.group]}`.toLowerCase().includes(q) : group === "all" || s.group === group))
    .sort((a, b) => GROUPS.indexOf(a.group) - GROUPS.indexOf(b.group));

  return (
    <>
      <h1 className="page-title">Settings</h1>
      <p className="page-sub">Connected to {hostOf(status.serverUrl)} {status.pinned ? "· secure connection" : "· connection not verified"}</p>
      <div className="settings-layout">
        <nav className="settings-nav" aria-label="Settings groups">
          <label className="searchbox compact"><SearchIcon size={16} /><input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search settings" aria-label="Search settings" /></label>
          {(["all", ...GROUPS] as const).map((g) => {
            const Icon = GROUP_ICON[g];
            const active = !q && group === g;
            return (
              <button key={g} type="button" className={`settings-row ${active ? "active" : ""}`} aria-current={active ? "page" : undefined}
                aria-labelledby={`settings-row-${g}`} aria-describedby={`settings-sub-${g}`} onClick={() => { setGroup(g); setQuery(""); }}>
                <span className="settings-row-icon"><Icon size={20} /></span>
                <span className="settings-row-main">
                  <span className="settings-row-title" id={`settings-row-${g}`}>{g === "all" ? "All settings" : GROUP_LABEL[g]}</span>
                  <span className="settings-row-sub" id={`settings-sub-${g}`}>{g === "all" ? "Every setting on one page" : GROUP_SUB[g]}</span>
                </span>
                <ChevronRightIcon size={16} />
              </button>
            );
          })}
        </nav>
        <div className="settings-content">
          {visible.length === 0 && <div className="empty">No setting matches “{query}”.</div>}
          {visible.map((s, i) => (
            <section key={s.id} aria-label={s.title}>
              {(i === 0 || visible[i - 1].group !== s.group) && <h2 className="settings-group-title">{GROUP_LABEL[s.group]}</h2>}
              {s.node}
            </section>
          ))}
        </div>
      </div>
    </>
  );
}

/** About: the version and server, and settings export / import without the pairing (B19). */
function AboutCard({ status, run, onImported }: { status: AppStatus; run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>; onImported: (s: Settings) => void }) {
  const [version, setVersion] = useState<string | null>(null);
  const fileId = useId();
  useEffect(() => {
    getVersion().then(setVersion).catch(() => undefined);
  }, []);
  const importFile = async (file: File | undefined) => {
    if (!file) return;
    const text = await file.text();
    const next = await run(() => api.importSettings(text), "Settings imported");
    if (next) onImported(next);
  };
  return (
    <div className="card about-settings">
      <b>EchoLedger{version ? ` ${version}` : ""}</b>
      <p className="small muted">Server: {status.serverUrl ?? "not paired"}{status.pinned ? " (secure connection)" : " (connection not verified)"}</p>
      <div className="row organise-field">
        <button type="button" className="btn ghost" onClick={() => void run(api.exportSettings, "Settings saved in Downloads")}>Export settings</button>
        <label htmlFor={fileId} className="btn ghost">Import settings</label>
        <input id={fileId} type="file" accept="application/json,.json" className="sr-only" aria-label="Import settings" onChange={(e) => void importFile(e.target.files?.[0])} />
      </div>
      <p className="small muted">The file has your preferences only, never the pairing, keys or PIN.</p>
    </div>
  );
}

/** The server's host name for the page subtitle (the full address is under About). */
function hostOf(url: string | null): string {
  if (!url) return "the server";
  try {
    return new URL(url).host;
  } catch {
    return url;
  }
}
