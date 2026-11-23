// Settings › Appearance and Keyboard shortcuts in the new look, with today's data and rules (AppSettings.tsx). The
// theme, sidebar, density and clock are kept on this computer (lib/theme, lib/shell) and follow at once everywhere;
// a global shortcut is changed by pressing it, refused when another EchoLedger action has it, and reported when
// another app or the system has it.
import { useEffect, useState } from "react";
import { api, type AppStatus, type Settings, type ShortcutAction } from "../../../lib/ipc";
import { acceleratorFromEvent, prettyShortcut } from "../../../lib/format";
import { DEFAULT_PREFERENCES, loadPreferences, PREFERENCES_EVENT, savePreferences, type Preferences } from "../../../lib/shell";
import { applyTheme, getThemeChoice, saveThemeChoice, type ThemeChoice } from "../../../lib/theme";
import { conflictFor } from "../../../lib/shortcutConflicts";
import { SHORTCUT_ACTIONS, setting } from "../../../lib/settingsRegistry";
import { appKeys } from "../../../views/main/AppSettings";
import { Button, ListRow } from "../../components";
import { Group, ResetDefaults, SegmentedSetting, type Run } from "./rows";
import { useDesignSwitch } from "../../flags";

/** The theme: the app's own when it hands it down (the top bar's toggle follows), else this page's. */
export function useThemeChoice(theme?: ThemeChoice, onTheme?: (t: ThemeChoice) => void): [ThemeChoice, (t: ThemeChoice) => void] {
  const [own, setOwn] = useState<ThemeChoice>(getThemeChoice);
  const choose = (next: ThemeChoice) => {
    if (onTheme) return onTheme(next);
    saveThemeChoice(next);
    applyTheme(next);
    setOwn(next);
  };
  return [theme ?? own, choose];
}

export function AppearancePageV2({ theme, onTheme }: { theme: ThemeChoice; onTheme: (t: ThemeChoice) => void }) {
  const [prefs, setPrefs] = useState<Preferences>(loadPreferences);
  const setPref = (change: Partial<Preferences>) => {
    const next = { ...prefs, ...change };
    setPrefs(next);
    savePreferences(next);
    window.dispatchEvent(new CustomEvent(PREFERENCES_EVENT, { detail: next }));
  };
  return (
    <>
      <Group footer={<p>Kept on this computer. Times follow your choice everywhere in the app.</p>}>
        <SegmentedSetting id="appearance.theme" value={theme} onChange={onTheme} />
        <SegmentedSetting id="appearance.sidebar" value={prefs.sidebarStyle} onChange={(v) => setPref({ sidebarStyle: v })} />
        <SegmentedSetting id="appearance.density" value={prefs.density} onChange={(v) => setPref({ density: v })} />
        <SegmentedSetting id="appearance.clock" value={prefs.clock} onChange={(v) => setPref({ clock: v })} />
      </Group>
      <DesignRowV2 />
      <ResetDefaults page="Appearance" onReset={() => { onTheme("system"); setPref({ ...DEFAULT_PREFERENCES }); }} />
    </>
  );
}

/** The way back to classic (docs/PLAN_V2_PARITY.md 0), while the server says there is one (`can_switch_back`). */
function DesignRowV2() {
  const design = useDesignSwitch();
  if (!design.canSwitchBack) return null;
  return (
    <Group footer={<>{design.error && <p className="el-field__error" role="alert">{design.error}</p>}<p>Classic stays available while the new design is finished.</p></>}>
      <ListRow compact anchor="appearance.design" title={setting("appearance.design").title} value="New design"
        control={<Button size="sm" loading={design.busy} loadingLabel="Switching…" onClick={() => void design.choose("classic")}>Switch back to classic</Button>} />
    </Group>
  );
}

/** A key combination as keycaps. */
function Keys({ children }: { children: string }) {
  return <kbd className="el-kbd" dir="ltr">{children}</kbd>;
}

export function ShortcutsPageV2({ settings, status, run, onSettings, threeLevels = false }: {
  settings: Settings; status: AppStatus; run: Run; onSettings: (s: Settings) => void;
  /** The rail · panel · page layout: its keys in place of the sidebar's "[". */
  threeLevels?: boolean;
}) {
  const [listening, setListening] = useState<ShortcutAction | null>(null);
  const platform = status.platform;
  useEffect(() => {
    if (!listening) return;
    const onKey = async (e: KeyboardEvent) => {
      e.preventDefault();
      if (e.key === "Escape") return setListening(null);
      const accel = acceleratorFromEvent(e, platform);
      if (!accel) return;
      setListening(null);
      const next = await run(() => api.setShortcut(listening, accel), "Shortcut saved");
      if (next) onSettings(next);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [listening, run, platform, onSettings]);
  const clear = async (a: ShortcutAction) => {
    const next = await run(() => api.setShortcut(a, ""));
    if (next) onSettings(next);
  };
  const noteFor = (a: ShortcutAction): string | null => {
    const error = status.shortcutErrors.find(([x]) => x === a)?.[1];
    if (error) return error;
    const conflict = conflictFor(settings.shortcuts[a], platform);
    return conflict ? `This shortcut also ${conflict}; EchoLedger takes it over.` : null;
  };
  return (
    <>
      <div className="el-shortcuts">
      <Group title="Global shortcuts" footer={<p>They work while another app is in front. A shortcut another EchoLedger action uses is refused.</p>}>
        {SHORTCUT_ACTIONS.map(([action]) => {
          const a = action as ShortcutAction;
          const id = `shortcuts.global.${a}`;
          const title = setting(id).title;
          const keys = settings.shortcuts[a];
          const note = noteFor(a);
          return (
            <ListRow key={a} compact anchor={id} title={title}
              meta={note ? <span className="el-shortcut-note" role="status">{note}</span> : undefined}
              value={listening === a ? <span aria-live="polite">Press keys… (Esc cancels)</span> : keys ? <Keys>{prettyShortcut(keys, platform)}</Keys> : "None"}
              control={<span className="el-setting__actions">
                {keys && <Button variant="quiet" size="sm" aria-label={`Clear ${title}`} onClick={() => void clear(a)}>Clear</Button>}
                {/* Pressed while it listens; a second press or Esc stops listening. */}
                <Button variant="secondary" size="sm" aria-label={`Change ${title}`} aria-pressed={listening === a} onClick={() => setListening(listening === a ? null : a)}>
                  Change
                </Button>
              </span>} />
          );
        })}
      </Group>
      <Group title="In the app" footer={<p>These work while EchoLedger is in front.</p>}>
        {appKeys(platform, threeLevels).map(([keys, what], i) => <ListRow key={keys} compact anchor={i === 0 ? "shortcuts.app" : undefined} title={what} value={<Keys>{keys}</Keys>} />)}
      </Group>
      </div>
      <ResetDefaults page="Keyboard shortcuts" onReset={() => void run(api.resetShortcuts, "Shortcuts reset").then((s) => s && onSettings(s))} />
    </>
  );
}
