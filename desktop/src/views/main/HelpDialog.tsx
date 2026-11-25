// Help & shortcuts (v1.6, P4.1): every key of the window in one place ("?" opens it), the global shortcuts as set in
// Settings › Keyboard shortcuts, and where to read more (answers to common questions in Settings › Help); how to get a
// screenshot of EchoLedger and what to send for support. It fits the window: the lists scroll, the title and buttons stay.
import { useEffect, useState } from "react";
import { api, type Settings, type ShortcutAction } from "../../lib/ipc";
import { prettyShortcut } from "../../lib/format";
import { paletteKeys } from "../../lib/shell";
import { PLAYER_KEYS } from "../../lib/player";
import { Sheet } from "./ProjectDialogs";
import { t } from "../../i18n";

/**
 * The keys of the main window (they work while EchoLedger is in front, never while typing in a field). `threeLevels`:
 * the rail · panel · page layout (ui_v2.library), whose keys replace the sidebar's "[".
 */
export function windowKeys(platform: string, threeLevels = false, zoom = false): readonly (readonly [string, string])[] {
  const mod = platform === "macos" ? "⌘" : "Ctrl+";
  const keys: [string, string][] = [
    [paletteKeys(platform), "Search recordings, transcripts and pages, or run a command"],
    ["/", "Search this page's list"],
    ["J / K", "Next / previous recording in the list"],
    ["Enter", "Open the recording"],
    ["X", "Select the recording (then Move, Label, Export or Delete)"],
    ["Esc", "Close the recording, a menu or a dialog"],
    ["N", "Start or stop a voice note"],
    [PLAYER_KEYS.toggle, "Play or pause the open recording"],
    [`${PLAYER_KEYS.back5} / ${PLAYER_KEYS.forward5}`, "Back or forward 5 seconds"],
    [`${PLAYER_KEYS.back10} / ${PLAYER_KEYS.forward10}`, "Back or forward 10 seconds"],
    [`${mod}F`, "Find in the open transcript (in the new design, search when no recording is open)"],
    ["[", "Collapse or expand the sidebar"],
    ["?", "This list"],
  ];
  // UX-07: zoom, like Chrome (Ctrl + the mouse wheel too, off a Mac).
  if (zoom) keys.push([`${mod}+ / ${mod}− / ${mod}0`, platform === "macos" ? "Zoom in, zoom out, or back to 100%" : "Zoom in, zoom out, or back to 100% (Ctrl + the mouse wheel too)"]);
  return threeLevels ? keys.flatMap((row) => (row[0] === "[" ? navKeys(platform) : [row])) : keys;
}

/** The three levels' own keys (PLAN_NAV_3_LEVELS §6), in place of the sidebar's "[" (one list for Help and Settings). */
export function navKeys(platform: string): readonly [string, string][] {
  const mod = platform === "macos" ? "⌘" : "Ctrl+";
  const alt = platform === "macos" ? "⌥" : "Alt+";
  return [
    [`${mod}1 … ${mod}6`, t("Library, Projects, Live, Ask, Activity, Settings")],
    [`${alt}1 … ${alt}9`, t("The panel's views: the Library's, then My views")],
    [`${mod}\\`, t("Hide or show the panel")],
    [`${mod}[ / ${mod}]`, t("Back or forward through the Library's views and filters")],
  ];
}


export const GLOBAL_SHORTCUTS: readonly (readonly [ShortcutAction, string])[] = [
  ["record_now", "Record now"],
  ["toggle_captions", "Show / hide live captions"],
  ["toggle_saving", "Start / stop saving a recording"],
  ["dictation", "Dictation box"],
  ["mark", "Bookmark this moment"],
];

/** How to get a screenshot of EchoLedger and what to send for support (D-125). */
export const CAPTURE_HELP = "EchoLedger's windows show in your screenshots; only the live words (Live Assist, captions, translation) are hidden from them. For support, send Copy diagnostics (Settings › Screen-share privacy).";

export function HelpDialog({ platform, onClose, onSettings, threeLevels = false, zoom = false }: {
  platform: string; onClose: () => void; onSettings: (page: string) => void;
  /** The rail · panel · page layout: its keys in place of the sidebar's "[". */
  threeLevels?: boolean;
  /** Zoom is on (UX-07): its keys too. */
  zoom?: boolean;
}) {
  const [settings, setSettings] = useState<Settings | null>(null);
  useEffect(() => {
    api.settings().then(setSettings).catch(() => undefined);
  }, []);
  const go = (page: string) => {
    onClose();
    onSettings(page);
  };
  return (
    <Sheet title="Help & shortcuts" onClose={onClose} className="help-sheet" closeButton>
      <div className="help-body">
        <h3 className="help-head">In this window</h3>
        <dl className="key-list">
          {windowKeys(platform, threeLevels, zoom).map(([keys, what]) => <div key={keys}><dt><span className="kbd">{keys}</span></dt><dd>{what}</dd></div>)}
        </dl>
        <h3 className="help-head">Anywhere on this computer</h3>
        <dl className="key-list">
          {GLOBAL_SHORTCUTS.map(([action, what]) => (
            <div key={action}><dt><span className="kbd">{settings?.shortcuts[action] ? prettyShortcut(settings.shortcuts[action], platform) : "Not set"}</span></dt><dd>{what}</dd></div>
          ))}
        </dl>
        <p className="small muted">Change the shortcuts in Settings › Keyboard shortcuts. Answers to common questions are in Settings › Help.</p>
        <p className="small muted" data-testid="capture-help">{CAPTURE_HELP}</p>
      </div>
      <div className="row sheet-actions help-actions">
        <button type="button" className="btn ghost" onClick={() => go("shortcuts")}>Keyboard shortcuts</button>
        <button type="button" className="btn ghost" onClick={() => go("help")}>Help articles</button>
        <button type="button" className="btn ghost" onClick={() => go("share_privacy")}>Screen-share privacy</button>
        <span className="spacer" />
        <button type="button" className="btn primary" onClick={onClose}>Close</button>
      </div>
    </Sheet>
  );
}
