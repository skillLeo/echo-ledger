// Help & shortcuts (v1.6, P4.1): every key of the window in one place ("?" opens it), the global shortcuts as set in
// Account → Preferences, and where to read more.
import { useEffect, useState } from "react";
import { api, type Settings, type ShortcutAction } from "../../lib/ipc";
import { prettyShortcut } from "../../lib/format";
import { paletteKeys } from "../../lib/shell";
import { Sheet } from "./ProjectDialogs";

/** The keys of the main window (they work while EchoLedger is in front, never while typing in a field). */
export function windowKeys(platform: string): readonly (readonly [string, string])[] {
  const mod = platform === "macos" ? "⌘" : "Ctrl+";
  return [
    [paletteKeys(platform), "Search recordings, transcripts and pages, or run a command"],
    ["/", "Search this page's list"],
    ["J / K", "Next / previous recording in the list"],
    ["Enter", "Open the recording"],
    ["X", "Select the recording (then Move, Label, Export or Delete)"],
    ["Esc", "Close the recording, a menu or a dialog"],
    ["N", "Start or stop a voice note"],
    ["Space", "Play or pause the open recording"],
    ["← / →", "Back or forward 5 seconds"],
    ["Shift+← / Shift+→", "Back or forward 10 seconds"],
    [`${mod}F`, "Find in the open transcript"],
    ["[", "Collapse or expand the sidebar"],
    ["?", "This list"],
  ];
}

const GLOBAL: readonly (readonly [ShortcutAction, string])[] = [
  ["record_now", "Record now"],
  ["toggle_captions", "Show / hide live captions"],
  ["toggle_saving", "Start / stop saving a recording"],
  ["dictation", "Dictation box"],
  ["mark", "Bookmark this moment"],
];

export function HelpDialog({ platform, onClose, onShortcuts }: { platform: string; onClose: () => void; onShortcuts: () => void }) {
  const [settings, setSettings] = useState<Settings | null>(null);
  useEffect(() => {
    api.settings().then(setSettings).catch(() => undefined);
  }, []);
  return (
    <Sheet title="Help & shortcuts" onClose={onClose} className="help-sheet">
      <h3 className="help-head">In this window</h3>
      <dl className="key-list">
        {windowKeys(platform).map(([keys, what]) => <div key={keys}><dt><span className="kbd">{keys}</span></dt><dd>{what}</dd></div>)}
      </dl>
      <h3 className="help-head">Anywhere on this computer</h3>
      <dl className="key-list">
        {GLOBAL.map(([action, what]) => (
          <div key={action}><dt><span className="kbd">{settings?.shortcuts[action] ? prettyShortcut(settings.shortcuts[action], platform) : "Not set"}</span></dt><dd>{what}</dd></div>
        ))}
      </dl>
      <p className="small muted">Change the global shortcuts in Account → Preferences. The user guide is in the console under Help.</p>
      <div className="sheet-actions">
        <button type="button" className="btn ghost" onClick={() => { onClose(); onShortcuts(); }}>Change shortcuts</button>
        <span className="spacer" />
        <button type="button" className="btn primary" onClick={onClose}>Close</button>
      </div>
    </Sheet>
  );
}
