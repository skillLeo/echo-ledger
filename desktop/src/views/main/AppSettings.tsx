// Settings › Transcription › Language and Custom words, App › Appearance and Keyboard shortcuts, and Support › Help,
// About and its Technical details (v1.7 rows).
import { useEffect, useId, useState } from "react";
import { api, type AppStatus, type Settings, type SettingsPatch, type ShortcutAction, type TranscriptionLanguage } from "../../lib/ipc";
import { acceleratorFromEvent, prettyShortcut } from "../../lib/format";
import { LANGUAGE_OPTIONS, menuLabel } from "../../lib/languages";
import type { Preferences } from "../../lib/shell";
import type { ThemeChoice } from "../../lib/theme";
import { conflictFor } from "../../lib/shortcutConflicts";
import { HELP_ARTICLES, SHORTCUT_ACTIONS, setting, type SettingEntry } from "../../lib/settingsRegistry";
import { firstSentence } from "../../lib/settingsIndex";
import { getVersion } from "@tauri-apps/api/app";
import { UpdatesPanel } from "./UpdateSettings";
import { ActionRow, InfoRow, LinkRow, ResetRow, Section, SegmentedRow, SelectRow, rowAttrs } from "./SettingsControls";

type Run = <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
type Patch = (p: SettingsPatch) => Promise<boolean>;
const opts = <T extends string>(id: string) => (setting(id).options ?? []).map((o) => [o.value as T, o.label] as const);

/** Automatic, English, Urdu, Arabic, then the more languages (lib/languages). */
export const LANGUAGES: readonly (readonly ["" | TranscriptionLanguage, string])[] = LANGUAGE_OPTIONS.map((o) => [o.value ?? "", o.more ? o.label : menuLabel(o)] as const);

/** "Automatic", "English": the language row's value. */
export function languageValue(language: string | null | undefined): string {
  const found = LANGUAGES.find(([v]) => v === (language ?? ""));
  return found ? found[1] : "Automatic";
}

/** Transcription › Language. */
export function LanguagePage({ settings, patch }: { settings: Settings; patch: Patch }) {
  return (
    <>
      <Section footer={<p>The language your recordings are transcribed in. Roman Urdu and English views are chosen on each recording.</p>}>
        <SelectRow id="transcription.language" value={(settings.transcriptionLanguage ?? "") as "" | TranscriptionLanguage} options={LANGUAGES} width={260}
          onChange={(v) => void patch({ transcriptionLanguage: v === "" ? null : v })} />
      </Section>
      <ResetRow page="Language" onReset={() => void patch({ transcriptionLanguage: null })} />
    </>
  );
}

/** Transcription › Custom words: a project's names and terms help the transcript. */
export function WordsPage({ settings, patch }: { settings: Settings; patch: Patch }) {
  const [clients, setClients] = useState<{ id: string; name: string }[]>([]);
  useEffect(() => {
    api.clients().then((r) => setClients(r.data)).catch(() => undefined);
  }, []);
  return (
    <>
      <Section footer={<p>A project's names and terms help the transcript of recordings in that project.</p>}>
        {/* "None" sends clientId: null (clears it); a missing key would keep the stored client (A6.DU4). */}
        <SelectRow id="words.project" value={settings.clientId ?? ""} options={[["", "None"], ...clients.map((c) => [c.id, c.name] as const)]} width={260}
          onChange={(v) => void patch({ clientId: v || null })} />
      </Section>
      <ResetRow page="Custom words" onReset={() => void patch({ clientId: null })} />
    </>
  );
}

/** App › Appearance: the theme, the sidebar, the density and how times are written. */
export function AppearancePage({ theme, onTheme, prefs, setPref }: { theme: ThemeChoice; onTheme: (t: ThemeChoice) => void; prefs: Preferences; setPref: (p: Partial<Preferences>) => void }) {
  return (
    <>
      <Section>
        <SegmentedRow id="appearance.theme" value={theme} options={opts<ThemeChoice>("appearance.theme")} onChange={onTheme} />
        <SegmentedRow id="appearance.sidebar" value={prefs.sidebarStyle} options={opts<Preferences["sidebarStyle"]>("appearance.sidebar")} onChange={(v) => setPref({ sidebarStyle: v })} />
        <SegmentedRow id="appearance.density" value={prefs.density} options={opts<Preferences["density"]>("appearance.density")} onChange={(v) => setPref({ density: v })} />
        <SegmentedRow id="appearance.clock" value={prefs.clock} options={opts<Preferences["clock"]>("appearance.clock")} onChange={(v) => setPref({ clock: v })} />
      </Section>
      <ResetRow page="Appearance" onReset={() => { onTheme("system"); setPref({ sidebarStyle: "light", density: "comfortable", clock: "12" }); }} />
    </>
  );
}

/** Keys of the main window itself (not global; they work while EchoLedger is in front). */
const APP_KEYS: readonly [string, string][] = [
  ["Ctrl+K", "Search recordings, pages, settings and commands"],
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

/** App › Keyboard shortcuts: the global shortcuts (change or clear each) and the keys inside the app. */
export function ShortcutsPage({ settings, status, run, onSettings }: { settings: Settings; status: AppStatus; run: Run; onSettings: (s: Settings) => void }) {
  const [recording, setRecording] = useState<ShortcutAction | null>(null);
  useEffect(() => {
    if (!recording) return;
    const onKey = async (e: KeyboardEvent) => {
      e.preventDefault();
      if (e.key === "Escape") return setRecording(null);
      const accel = acceleratorFromEvent(e, status.platform);
      if (!accel) return;
      setRecording(null);
      const next = await run(() => api.setShortcut(recording, accel), "Shortcut saved");
      if (next) onSettings(next);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [recording, run, status.platform, onSettings]);
  const errorFor = (a: ShortcutAction) => status.shortcutErrors.find(([x]) => x === a)?.[1];
  const notes = SHORTCUT_ACTIONS.map(([action, title]) => {
    const error = errorFor(action as ShortcutAction);
    const conflict = conflictFor(settings.shortcuts[action as ShortcutAction], status.platform);
    return error ? `${title}: ${error}` : conflict ? `${title}: this shortcut also ${conflict}; EchoLedger takes it over.` : null;
  }).filter(Boolean) as string[];
  return (
    <>
      <Section title="Global shortcuts" footer={<>{notes.map((n) => <p key={n} className="warning-text">{n}</p>)}<p>A shortcut another EchoLedger action uses is refused; one another app uses is reported here.</p></>}>
        {SHORTCUT_ACTIONS.map(([action]) => {
          const a = action as ShortcutAction;
          const keys = settings.shortcuts[a];
          return (
            <InfoRow key={a} id={`shortcuts.global.${a}`} value={<span className="kbd">{recording === a ? "Press keys…" : keys ? prettyShortcut(keys, status.platform) : "None"}</span>}>
              <button type="button" className="btn compact" onClick={() => setRecording(a)} aria-label={`Change ${setting(`shortcuts.global.${a}`).title}`}>Change</button>
              {keys && <button type="button" className="btn ghost compact" aria-label={`Clear ${setting(`shortcuts.global.${a}`).title}`}
                onClick={async () => { const s = await run(() => api.setShortcut(a, "")); if (s) onSettings(s); }}>Clear</button>}
            </InfoRow>
          );
        })}
      </Section>
      <section className="set-section" aria-labelledby="in-app-head" {...rowAttrs("shortcuts.app")}>
        <h3 id="in-app-head" className="set-head">In the app</h3>
        <div className="set-group">
          {appKeys(status.platform).map(([keys, what]) => (
            <div className="set-row" key={keys}><span className="set-title">{what}</span><span className="set-end"><span className="kbd">{keys}</span></span></div>
          ))}
        </div>
      </section>
      <ResetRow page="Keyboard shortcuts" onReset={async () => { const s = await run(api.resetShortcuts, "Shortcuts reset"); if (s) onSettings(s); }} />
    </>
  );
}

/** Support › Help: the Mac check on a Mac, and the answers to the questions people ask most. */
export function HelpPage({ platform, available, article, onArticle, onTarget, onMacCheck }: {
  platform: string;
  available: (e: SettingEntry) => boolean;
  article: string | null;
  onArticle: (id: string | null) => void;
  onTarget: (target: string) => void;
  onMacCheck: () => void;
}) {
  const articles = HELP_ARTICLES.filter(available);
  return (
    <>
      {platform === "macos" && (
        <Section>
          <LinkRow id="help.mac_check" onOpen={onMacCheck} chevron />
        </Section>
      )}
      <Section title="Questions">
        {articles.map((a) => (
          <div key={a.id} className={`help-article ${article === a.id ? "open" : ""}`.trim()} {...rowAttrs(a.id)}>
            <button type="button" className="set-row set-link set-two" aria-expanded={article === a.id} onClick={() => onArticle(article === a.id ? null : a.id)}>
              <span className="set-main"><span className="set-title">{a.title}</span>{article !== a.id && <span className="set-sub">{firstSentence(a.answer)}</span>}</span>
            </button>
            {article === a.id && (
              <div className="help-answer">
                <p>{a.answer}</p>
                {a.target && <button type="button" className="btn outline compact" onClick={() => onTarget(a.target!)}>Open {setting(a.target).title}</button>}
              </div>
            )}
          </div>
        ))}
      </Section>
    </>
  );
}

/** The server's host name ("app.echoledger.pk"), never the whole address. */
export function hostOf(url: string | null): string {
  if (!url) return "";
  try {
    return new URL(url).host || url;
  } catch {
    // Not a URL: shown as stored.
    return url;
  }
}

/** The app's version (from the build), read once. */
export function useVersion(): string | null {
  const [version, setVersion] = useState<string | null>(null);
  useEffect(() => {
    getVersion().then(setVersion).catch(() => undefined);
  }, []);
  return version;
}

/** Support › About: the version and updates, this computer's name, the server, and settings export / import. */
export function AboutPage({ status, run, onImported, onDevices, onTechnical }: {
  status: AppStatus;
  run: Run;
  onImported: (s: Settings) => void;
  onDevices: () => void;
  onTechnical: () => void;
}) {
  const version = useVersion();
  const fileId = useId();
  const importFile = async (file: File | undefined) => {
    if (!file) return;
    const text = await file.text();
    const next = await run(() => api.importSettings(text), "Settings imported");
    if (next) onImported(next);
  };
  return (
    <>
      <Section>
        <InfoRow id="about.version" value={version ?? ""} />
        <div className="set-row set-block" {...rowAttrs("about.updates")}>
          <span className="set-title">Check for updates</span>
          <UpdatesPanel appStatus={status} />
        </div>
        <LinkRow id="about.device_name" value={status.deviceName ?? ""} onOpen={onDevices} chevron />
        {status.serverUrl && <InfoRow id="about.server" value={hostOf(status.serverUrl)} />}
        <LinkRow id="about.technical" onOpen={onTechnical} chevron />
      </Section>
      <Section footer={<p>The file has your preferences only, never the sign-in, keys or PIN.</p>}>
        <ActionRow id="about.export_settings" onClick={() => void run(api.exportSettings, "Settings saved in Downloads")} />
        <label htmlFor={fileId} className="set-row set-link" {...rowAttrs("about.import_settings")}><span className="set-title">Import settings</span></label>
        <input id={fileId} type="file" accept="application/json,.json" className="sr-only" aria-label="Import settings" onChange={(e) => void importFile(e.target.files?.[0])} />
      </Section>
      <Section>
        <LinkRow id="about.privacy" external onOpen={() => void api.openServerPage("privacy").catch(() => undefined)} />
        <LinkRow id="about.terms" external onOpen={() => void api.openServerPage("terms").catch(() => undefined)} />
      </Section>
    </>
  );
}

/** About › Technical details: the connection and this computer, for support. */
export function TechnicalPage({ status }: { status: AppStatus }) {
  return (
    <Section>
      <InfoRow id="about.technical.secure" title="Secure connection" value={status.pinned ? "Verified" : "Not verified"} />
      {status.serverUrl && <InfoRow id="about.technical.address" title="Server address" value={status.serverUrl} />}
    </Section>
  );
}
