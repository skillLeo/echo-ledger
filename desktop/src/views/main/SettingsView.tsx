import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import { api, listenTo, type AfterCall, type AppStatus, type Corner, type Settings, type ShortcutAction, type SiteRule, type TranscriptionLanguage } from "../../lib/ipc";
import { acceleratorFromEvent, prettyShortcut, sourceAppLabel } from "../../lib/format";
import { BUILTIN_SITE_RULES, SITE_RULE_APPS, builtinEnabled, customRules, hasSameRule, isNeverRule, ruleFromInput, rulePattern, withBuiltinEnabled } from "../../lib/sites";
import { BellIcon, CallIcon, DesktopIcon, KeyIcon, LockIcon, MicIcon, PaletteIcon, SearchIcon, SettingsIcon, ShieldIcon, SourceAppIcon, SparkleIcon, UserIcon } from "../../components/Icons";
import { applyTheme, getThemeChoice, saveThemeChoice, type ThemeChoice } from "../../lib/theme";
import { SelectRow, Toggle } from "./SettingsControls";
import { AutoRecordApps, CaptionStyleCard, MicrophoneCard } from "./CaptureSettings";
import { DictationCard } from "./DictationSettings";
import { ScreenMomentsCard } from "./ScreenSettings";
import { DevicesCard, LockCard, NotificationsCard, StorageCard } from "./SecuritySettings";
import { AccountCard, PlanCard, SignInMethodsCard, YourDataCard, useAccount } from "./AccountSettings";
import { PageHeader, SavedIndicator } from "../../components/Page";
import { CLOCKS, DENSITIES, loadPreferences, PREFERENCES_EVENT, savePreferences, SIDEBAR_STYLES, type Preferences } from "../../lib/shell";
import { AllFeaturesCard, PlanUsageCard, TranscriptionKeyCard } from "./Upgrade";
import { featureWords, useEntitlements } from "../../lib/entitlements";
import { ConsentNotice } from "./ConsentNotice";
import { UpdatesPanel } from "./UpdateSettings";
import { MacCheckCard } from "./MacCheck";
import { CONSENT_SWITCH } from "../../lib/consent";
import { conflictFor } from "../../lib/shortcutConflicts";
import { getVersion } from "@tauri-apps/api/app";
import { LANGUAGE_OPTIONS, menuLabel } from "../../lib/languages";
import { SKIP_SHORTER_THAN } from "../../lib/pending";

const ACTIONS: [ShortcutAction, string][] = [
  ["record_now", "Record now (the meeting on screen, else a voice note)"],
  ["toggle_captions", "Show / hide live captions"],
  ["toggle_saving", "Start / stop saving a recording"],
  ["copy_last2_min", "Copy the last 2 minutes of captions"],
  ["dictation", "Dictation box"],
  ["voice_note", "Capture voice note playing now"],
  ["mark", "Bookmark this moment of a recording"],
];

/**
 * Account (v1.6, P4.5): the account's tabs (Profile · Password & sign-in methods · Devices · Notifications · Preferences ·
 * Plan & usage · Transcription key · Privacy & data), then this computer's (Calls & meetings · Voice notes & dictation ·
 * This computer). "All settings" lists every card on one page, and the search finds a setting in any of them.
 */
type Group = "profile" | "sign-in" | "devices" | "notifications" | "preferences" | "plan" | "key" | "privacy" | "calls" | "voice" | "computer";
export const GROUPS: readonly Group[] = ["profile", "sign-in", "devices", "notifications", "preferences", "plan", "key", "privacy", "calls", "voice", "computer"];
export const GROUP_LABEL: Record<Group, string> = {
  profile: "Profile", "sign-in": "Password & sign-in methods", devices: "Devices", notifications: "Notifications", preferences: "Preferences",
  plan: "Plan & usage", key: "Transcription key", privacy: "Privacy & data", calls: "Calls & meetings", voice: "Voice notes & dictation", computer: "This computer",
};
/** The tabs that belong to the account (the rest are this computer's). */
const ACCOUNT_GROUPS: ReadonlySet<Group> = new Set(["profile", "sign-in", "devices", "notifications", "preferences", "plan", "key", "privacy"]);
/** The tabs "Find a page" and the palette open (`section` ids). */
/** Sections other parts of the app open by id, and the tab each is in. */
const SECTION_GROUP: Record<string, Group> = {
  "transcription-key": "key", plan: "plan", "all-features": "plan", devices: "devices", shortcuts: "preferences", "your-data": "privacy", general: "computer", about: "computer",
};
export const ACCOUNT_TABS: readonly (readonly [Group, string])[] = GROUPS.map((g) => [g, GROUP_LABEL[g]] as const);
const GROUP_ICON: Record<Group | "all", typeof SettingsIcon> = {
  all: SettingsIcon, profile: UserIcon, "sign-in": LockIcon, devices: DesktopIcon, notifications: BellIcon, preferences: PaletteIcon, plan: SparkleIcon,
  key: KeyIcon, privacy: ShieldIcon, calls: CallIcon, voice: MicIcon, computer: DesktopIcon,
};
const THEMES: readonly [ThemeChoice, string][] = [["system", "System"], ["light", "Light"], ["dark", "Dark"]];
/** Keys of the main window itself (not global; they work while EchoLedger is in front). */
const APP_KEYS: readonly [string, string][] = [
  ["Ctrl+K", "Search recordings, pages and commands"],
  ["/", "Search this page's list"],
  ["J / K", "Next or previous recording; Enter opens it, X selects it"],
  ["N", "Start or stop a voice note"],
  ["Space", "Play or pause the open recording"],
  ["Shift+← / →", "Back or forward 10 seconds (← / → 5 seconds)"],
  ["Ctrl+F", "Find in the open transcript"],
  ["[", "Collapse or expand the sidebar"],
  ["?", "Help & shortcuts"],
];
/** A Mac's own keys (since v1.5): the app menu answers them, and they read as a Mac person expects. */
const MAC_KEYS: readonly [string, string][] = [
  ["⌘,", "Open Settings"],
  ["⌘W", "Close the window; EchoLedger stays in the menu bar"],
  ["⌘Q", "Quit EchoLedger; a running recording is saved first"],
];

/** The keys Settings lists on this platform, with their names as this platform writes them. */
export function appKeys(platform: string): readonly [string, string][] {
  if (platform !== "macos") return APP_KEYS;
  return [...APP_KEYS.map(([keys, what]) => [keys.replace("Ctrl+", "⌘"), what] as [string, string]), ...MAC_KEYS];
}
interface SettingsSection { id: string; group: Group; title: string; keywords: string; node: ReactNode }
/** Signing this computer out (v1.5; "Unpair" before accounts): the token goes, the recordings waiting here stay. */
export const SIGN_OUT_CONFIRM = "Sign out of this computer? Recordings waiting to upload stay here and upload when you sign in again with this account, never into another one.";

/** "Signed in to ledger.example.com" (the server's host, as on the phone), or "Not signed in". */
export function signedInLine(serverUrl: string | null | undefined): string {
  return serverUrl ? `Signed in to ${hostOf(serverUrl)}` : "Not signed in";
}

const AFTER_CALL: readonly (readonly [AfterCall, string])[] = [["keep", "Keep automatically"], ["ask", "Ask me"]];
const AFTER_CALL_HINT: Record<AfterCall, string> = {
  keep: "Each call is saved at once. A small “Saved · Undo” shows near the tray for a few seconds.",
  ask: "A small prompt near the tray asks Keep or Discard, and keeps the call by itself after 10 seconds.",
};
/** A Mac has no tray: the window is in the corner of the screen. */
function afterCallHint(choice: AfterCall, platform: string): string {
  return platform === "macos" ? AFTER_CALL_HINT[choice].replace("near the tray", "in the corner of the screen") : AFTER_CALL_HINT[choice];
}
const CAPTION_LINES = [["2", "2"], ["3", "3"], ["5", "5"]] as const;
const SKIP_OPTIONS = SKIP_SHORTER_THAN.map(([seconds, label]) => [String(seconds), label] as const);
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

export function SettingsView({ status, run, onUnpaired, theme, onTheme, section = null }: {
  status: AppStatus;
  /** A section to open and show (`transcription-key` from a recording that needs the key, say), with a fresh `seq`. */
  section?: { id: string; seq: number } | null;
  run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
  onUnpaired: (s: AppStatus) => void;
  /** The theme choice and how to change it (the sidebar's toggle does the same). */
  theme?: ThemeChoice;
  onTheme?: (theme: ThemeChoice) => void;
}) {
  const [ownTheme, setOwnTheme] = useState<ThemeChoice>(getThemeChoice);
  const themeChoice = theme ?? ownTheme;
  const setTheme = onTheme ?? ((next: ThemeChoice) => {
    saveThemeChoice(next);
    applyTheme(next);
    setOwnTheme(next);
  });
  const chooseTheme = (next: ThemeChoice) => {
    setTheme(next);
    setSavedAt(Date.now());
  };
  const [settings, setSettings] = useState<Settings | null>(null);
  const [recording, setRecording] = useState<ShortcutAction | null>(null);
  const [clients, setClients] = useState<{ id: string; name: string }[]>([]);
  const [group, setGroup] = useState<Group | "all">("all");
  // Account → Preferences kept on this computer (v1.6): the sidebar's style, density, the clock.
  const [prefs, setPrefs] = useState<Preferences>(loadPreferences);
  // "Saved · just now" (v1.6, P4.4): when a setting here was last saved.
  const [savedAt, setSavedAt] = useState<number | null>(null);
  const setPref = (change: Partial<Preferences>) => {
    const next = { ...prefs, ...change };
    setPrefs(next);
    savePreferences(next);
    window.dispatchEvent(new CustomEvent(PREFERENCES_EVENT, { detail: next }));
    setSavedAt(Date.now());
  };
  const [query, setQuery] = useState("");
  // The account (v1.5): none from a server without accounts, and then no Account group.
  const [account, setAccount] = useAccount();
  // What this person may use (v1.6): Plan & usage and the Free plan's own key; none from an older server.
  const entitlements = useEntitlements();
  const showKey = !!entitlements && (entitlements.features.platform_transcription?.enabled === false || !!entitlements.transcription_key?.set
    || entitlements.engines.includes("own_groq"));
  // A tab or a section asked for from elsewhere (the avatar menu, Find a page, a recording that needs the key): its
  // tab, then the section itself in view once it is there.
  useEffect(() => {
    if (!section) return;
    setQuery("");
    setGroup((GROUPS as readonly string[]).includes(section.id) ? (section.id as Group) : SECTION_GROUP[section.id] ?? "all");
  }, [section]);
  const shownSection = useRef(0);
  useEffect(() => {
    if (!section || shownSection.current === section.seq) return;
    if ((GROUPS as readonly string[]).includes(section.id)) {
      shownSection.current = section.seq;
      return;
    }
    const element = document.getElementById(`settings-${section.id}`);
    if (!element) return; // not there yet (the settings or the entitlements are still being read): the next render tries
    shownSection.current = section.seq;
    element.scrollIntoView?.({ block: "start" });
  });

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
    if (next) {
      setSettings(next);
      setSavedAt(Date.now());
    }
    return !!next;
  };
  const errorFor = (a: ShortcutAction) => status.shortcutErrors.find(([x]) => x === a)?.[1];

  const shortcutConflict = (action: ShortcutAction) => conflictFor(settings.shortcuts[action], status.platform);
  const sections: SettingsSection[] = [
    ...(account ? [
      { id: "account", group: "profile", title: "Your account", keywords: "account profile email name workspace role member",
        node: <AccountCard account={account} status={status} /> },
      { id: "sign-in-methods", group: "sign-in", title: "Password & sign-in methods", keywords: "password two-factor 2fa google sign in methods sign out everywhere security",
        node: <SignInMethodsCard account={account} run={run} onSignedOut={onUnpaired} /> },
      entitlements
        ? { id: "plan", group: "plan", title: "Plan & usage", keywords: "plan usage limits minutes storage devices uploads upgrade request pro business badge features",
          node: <PlanUsageCard e={entitlements} /> }
        : { id: "plan", group: "plan", title: "Plan", keywords: "plan limits minutes storage usage retention seats features live translate screen moments",
          node: <PlanCard status={status} /> },
      ...(entitlements && Object.keys(entitlements.features).length > 0 ? [{ id: "all-features", group: "plan", title: "All features",
        keywords: `all features plan pro included locked upgrade ${Object.keys(entitlements.features).map((k) => featureWords(entitlements, k).name).join(" ")}`,
        node: <AllFeaturesCard e={entitlements} /> }] satisfies SettingsSection[] : []),
      ...(entitlements && showKey ? [{ id: "transcription-key", group: "key", title: "Transcription key", keywords: "transcription key groq free own api key",
        node: <TranscriptionKeyCard e={entitlements} /> }] satisfies SettingsSection[] : []),
      { id: "your-data", group: "privacy", title: "Your data", keywords: "export my data download zip delete my account",
        node: <YourDataCard account={account} onAccount={setAccount} /> },
    ] satisfies SettingsSection[] : []),
    { id: "general", group: "computer", title: "General", keywords: "start sign in login autostart project client keyterms", node: (
      <div className="card">
        <b>General</b>
        <Toggle label="Start EchoLedger when I sign in" on={settings.autostart} onChange={(v) => patch({ autostart: v })} />
        <div className="toggle">
          <div><label htmlFor="settings-client">Project keyterms</label><div id="settings-client-hint" className="small muted">Names and jargon of the selected project improve accuracy.</div></div>
          {/* "No project" sends clientId: null (clears it); a missing key would keep the stored client (A6.DU4). */}
          <select id="settings-client" aria-describedby="settings-client-hint" className="input" style={{ width: 260 }} value={settings.clientId ?? ""} onChange={(e) => patch({ clientId: e.target.value || null })}>
            <option value="">No project</option>
            {clients.map((cl) => <option key={cl.id} value={cl.id}>{cl.name}</option>)}
          </select>
        </div>
      </div>
    ) },
    { id: "appearance", group: "preferences", title: "Look and language", keywords: "theme dark light system look sidebar style soft grey density compact comfortable clock 12-hour 24-hour time language transcription", node: (
      <div className="card">
        <b>Look and language</b>
        <div className="toggle">
          <span id="settings-theme">Theme</span>
          <span className="segmented" role="group" aria-labelledby="settings-theme">
            {THEMES.map(([value, text]) => <button key={value} type="button" aria-pressed={themeChoice === value} onClick={() => chooseTheme(value)}>{text}</button>)}
          </span>
        </div>
        <div className="toggle">
          <span id="settings-sidebar-style">Sidebar style</span>
          <span className="segmented" role="group" aria-labelledby="settings-sidebar-style">
            {SIDEBAR_STYLES.map(([value, text]) => <button key={value} type="button" aria-pressed={prefs.sidebarStyle === value} onClick={() => setPref({ sidebarStyle: value })}>{text}</button>)}
          </span>
        </div>
        <div className="toggle">
          <span id="settings-density">Density</span>
          <span className="segmented" role="group" aria-labelledby="settings-density">
            {DENSITIES.map(([value, text]) => <button key={value} type="button" aria-pressed={prefs.density === value} onClick={() => setPref({ density: value })}>{text}</button>)}
          </span>
        </div>
        <SelectRow label="Times" value={prefs.clock} options={CLOCKS} width={220} onChange={(v) => setPref({ clock: v })} />
        <SelectRow label="Transcription language" value={(settings.transcriptionLanguage ?? "") as "" | TranscriptionLanguage} options={LANGUAGES} width={260}
          hint="The language recordings are transcribed in. The app itself is in English."
          onChange={(v) => patch({ transcriptionLanguage: v === "" ? null : v })} />
      </div>
    ) },
    { id: "notifications", group: "notifications", title: "Notifications", keywords: "notifications quiet hours bell activity sound test critical recording transcription uploads sharing updates", node: <NotificationsCard settings={settings} patch={patch} run={run} /> },
    { id: "consent", group: "calls", title: "Privacy & consent", keywords: "consent law participants terms privacy policy", node: (
      <div className="card">
        <b>Privacy &amp; consent</b>
        <ConsentNotice legal={status.plan.known && status.paired} />
        <Toggle label={CONSENT_SWITCH} on={settings.consentAcknowledged} onChange={(v) => patch({ consentAcknowledged: v })} />
      </div>
    ) },
    { id: "recording", group: "calls", title: "Recording", keywords: "auto record calls unknown apps after a call keep ask undo saved skip short silent calls browser tab", node: (
      <div className="card">
        <b>Recording</b>
        <Toggle label="Auto-record calls" hint="Records a call when an app both uses your microphone and plays audio. Dictation and voice typing are never recorded." on={settings.autoMode} onChange={(v) => patch({ autoMode: v })} />
        <Toggle label="Ask me when an unrecognised app uses the microphone" hint="A small prompt appears for 8 seconds." on={settings.askUnknownApps} onChange={(v) => patch({ askUnknownApps: v })} />
        {status.platform === "macos" && (
          <Toggle label="Read browser tab addresses" hint="Lets EchoLedger tell a Google Meet call from dictation. macOS asks for permission once." on={settings.browserAutomation} onChange={(v) => patch({ browserAutomation: v })} />
        )}
        <SelectRow label="After a call" value={settings.afterCall} options={AFTER_CALL} hint={afterCallHint(settings.afterCall, status.platform)} onChange={(v) => patch({ afterCall: v })} />
        <SelectRow label="Skip calls shorter than" value={String(settings.skipCallsShorterThanS)} options={SKIP_OPTIONS} width={180}
          hint="Automatic calls that are this short, or where nobody spoke, are skipped quietly. Activity can restore them for 24 hours."
          onChange={(v) => patch({ skipCallsShorterThanS: Number(v) })} />
      </div>
    ) },
    { id: "auto-apps", group: "calls", title: "Auto-record apps", keywords: "whatsapp zoom teams meet apps", node: <AutoRecordApps settings={settings} patch={patch} /> },
    { id: "call-sites", group: "calls", title: "Call sites", keywords: "sites browser never record rules", node: <CallSites rules={settings.siteRules} onSave={(siteRules) => patch({ siteRules: siteRules })} /> },
    { id: "microphone", group: "calls", title: "Microphone", keywords: "microphone device test permission silence far side", node: <MicrophoneCard settings={settings} patch={patch} disabled={!!status.recording || status.dictationActive} systemAudioNote={status.systemAudioNote} /> },
    { id: "screen-moments", group: "calls", title: "Screen moments", keywords: "screen moments screenshots shared screen capture meeting window whole screen excluded apps password managers border screen recording permission",
      node: <ScreenMomentsCard settings={settings} patch={patch} /> },
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
    { id: "shortcuts", group: "preferences", title: "Global shortcuts", keywords: "shortcuts keys hotkeys keyboard", node: (
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
    { id: "app-keys", group: "preferences", title: "In the app", keywords: "keys search voice note play pause find", node: (
      <div className="card">
        <b>In the app</b>
        {appKeys(status.platform).map(([keys, what]) => (
          <div className="toggle" key={keys}><span>{what}</span><span className="kbd">{keys}</span></div>
        ))}
      </div>
    ) },
    { id: "lock", group: "computer", title: "App lock", keywords: "lock pin windows hello touch id privacy security", node: <LockCard settings={settings} patch={patch} run={run} /> },
    // Account → Devices (v1.6, P3) when the server has accounts: rename any, revoke the others.
    { id: "devices", group: "devices", title: "Devices", keywords: "devices rename revoke signed in paired activity audit", node: <DevicesCard run={run} /> },
    { id: "this-device", group: "computer", title: "This computer", keywords: "sign out unpair device name", node: (
      <div className="card">
        <div className="row"><div><b>{status.deviceName}</b><div className="small muted" data-testid="signed-in-to">{signedInLine(status.serverUrl)}</div></div><span className="spacer" />
          <button className="btn danger" onClick={async () => { if (window.confirm(SIGN_OUT_CONFIRM)) { const s = await run(api.unpair); if (s) onUnpaired(s); } }}>Sign out of this computer…</button>
        </div>
      </div>
    ) },
    { id: "storage", group: "computer", title: "Storage and retention", keywords: "storage disk retention deleted bin", node: <StorageCard limitBytes={status.plan.storageBytes} /> },
    // The Mac's own check (since v1.5): on any other computer this section does not exist.
    ...(status.platform === "macos" ? [
      { id: "help", group: "computer", title: "Help & diagnostics", keywords: "help diagnostics run mac check permissions microphone system audio screen recording report admin",
        node: <MacCheckCard status={status} /> },
    ] satisfies SettingsSection[] : []),
    { id: "about", group: "computer", title: "About", keywords: "version server updates check for updates restart export import backup settings file", node: <AboutCard status={status} run={run} onImported={setSettings} /> },
  ];
  const q = query.trim().toLowerCase();
  // The tabs that have something on this computer and server (an older server has no account tabs).
  const present = new Set(sections.map((sec) => sec.group));
  const shown = GROUPS.filter((g) => present.has(g));
  const current: Group | "all" = group !== "all" && !present.has(group) ? "all" : group;
  // Tabs in their order, the cards of a tab in the order they are listed.
  const visible = sections
    .filter((sec) => (q ? `${sec.title} ${sec.keywords} ${GROUP_LABEL[sec.group]}`.toLowerCase().includes(q) : current === "all" || sec.group === current))
    .sort((a, b) => GROUPS.indexOf(a.group) - GROUPS.indexOf(b.group));
  const tabRow = (g: Group | "all") => {
    const Icon = GROUP_ICON[g];
    const active = !q && current === g;
    return (
      <button key={g} type="button" className={`settings-row ${active ? "active" : ""}`} aria-current={active ? "page" : undefined}
        onClick={() => { setGroup(g); setQuery(""); }}>
        <span className="settings-row-icon"><Icon size={18} /></span>
        <span className="settings-row-title">{g === "all" ? "All settings" : GROUP_LABEL[g]}</span>
      </button>
    );
  };
  const title = q || current === "all" ? "Account" : GROUP_LABEL[current];

  return (
    <>
      <PageHeader crumbs={current !== "all" && !q ? [{ label: "Account", onOpen: () => { setGroup("all"); setQuery(""); } }, { label: GROUP_LABEL[current] }] : [{ label: "Account" }]}
        title={title} subtitle={`${signedInLine(status.serverUrl)} ${status.pinned ? "· secure connection" : "· connection not verified"}`}
        actions={<SavedIndicator at={savedAt} />} />
      <div className="settings-layout">
        <nav className="settings-nav" aria-label="Settings groups">
          <label className="searchbox compact"><SearchIcon size={16} /><input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search settings" aria-label="Search settings" /></label>
          {tabRow("all")}
          {shown.some((g) => ACCOUNT_GROUPS.has(g)) && <div className="settings-nav-head">Account</div>}
          {shown.filter((g) => ACCOUNT_GROUPS.has(g)).map(tabRow)}
          <div className="settings-nav-head">This computer</div>
          {shown.filter((g) => !ACCOUNT_GROUPS.has(g)).map(tabRow)}
        </nav>
        <div className="settings-content">
          {visible.length === 0 && <div className="empty">No setting matches “{query}”.</div>}
          {visible.map((sec, i) => (
            <section key={sec.id} id={`settings-${sec.id}`} aria-label={sec.title}>
              {(current === "all" || q) && (i === 0 || visible[i - 1].group !== sec.group) && <h2 className="settings-group-title">{GROUP_LABEL[sec.group]}</h2>}
              {sec.node}
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
      <p className="small muted">{signedInLine(status.serverUrl)}{status.serverUrl ? (status.pinned ? " (secure connection)" : " (connection not verified)") : ""}</p>
      <UpdatesPanel appStatus={status} />
      <div className="row organise-field">
        <button type="button" className="btn ghost" onClick={() => void run(api.exportSettings, "Settings saved in Downloads")}>Export settings</button>
        <label htmlFor={fileId} className="btn ghost">Import settings</label>
        <input id={fileId} type="file" accept="application/json,.json" className="sr-only" aria-label="Import settings" onChange={(e) => void importFile(e.target.files?.[0])} />
      </div>
      <p className="small muted">The file has your preferences only, never the sign-in, keys or PIN.</p>
    </div>
  );
}

/** The server's host name for the page subtitle (the full address is under About). */
function hostOf(url: string | null): string {
  if (!url) return "the server";
  try {
    return new URL(url).host || url;
  } catch {
    // Not a URL: shown as stored.
    return url;
  }
}
