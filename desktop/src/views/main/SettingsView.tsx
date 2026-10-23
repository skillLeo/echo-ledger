import { useEffect, useState } from "react";
import { api, type AppStatus, type Corner, type Settings, type ShortcutAction } from "../../lib/ipc";
import { acceleratorFromEvent, prettyShortcut } from "../../lib/format";

const ACTIONS: [ShortcutAction, string][] = [
  ["toggle_captions", "Show / hide live captions"],
  ["toggle_saving", "Start / stop saving a recording"],
  ["copy_last2_min", "Copy the last 2 minutes of captions"],
  ["dictation", "Dictation box (press, speak, press again)"],
  ["voice_note", "Capture voice note playing now"],
];

function Toggle({ on, onChange, label, hint }: { on: boolean; onChange: (v: boolean) => void; label: string; hint?: string }) {
  return (
    <div className="toggle">
      <div><div>{label}</div>{hint && <div className="small muted">{hint}</div>}</div>
      <button className={`switch ${on ? "on" : ""}`} role="switch" aria-checked={on} aria-label={label} onClick={() => onChange(!on)} />
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
  const patch = async (p: Parameters<typeof api.updateSettings>[0]) => {
    const next = await run(() => api.updateSettings(p));
    if (next) setSettings(next);
  };
  const errorFor = (a: ShortcutAction) => status.shortcutErrors.find(([x]) => x === a)?.[1];

  return (
    <>
      <h1 className="page-title">Settings</h1>
      <p className="page-sub">Paired with {status.serverUrl} {status.pinned ? "· certificate pinned" : "· not pinned"}</p>
      <div className="card">
        <b>Recording</b>
        <Toggle label="Auto-record calls" hint="Starts when WhatsApp, Zoom or a browser (Meet) uses the mic; stops when it releases it." on={settings.autoMode} onChange={(v) => patch({ autoMode: v })} />
        <Toggle label="Show live captions while recording" on={settings.captionsOnRecord} onChange={(v) => patch({ captionsOnRecord: v })} />
        <Toggle label="Hide overlays from screen sharing" hint="Captions stay visible to you but not in Zoom/Meet screen shares." on={settings.contentProtection} onChange={(v) => patch({ contentProtection: v })} />
        <Toggle label="Start EchoLedger when I sign in" on={settings.autostart} onChange={(v) => patch({ autostart: v })} />
        <div className="toggle">
          <div>Caption position</div>
          <select className="input" style={{ width: 180 }} value={settings.overlayCorner} onChange={(e) => patch({ overlayCorner: e.target.value as Corner })}>
            <option value="bottom_right">Bottom right</option><option value="top_right">Top right</option>
            <option value="bottom_left">Bottom left</option><option value="top_left">Top left</option>
          </select>
        </div>
        <div className="toggle">
          <div><div>Client keyterms</div><div className="small muted">Names and jargon from this client boost accuracy.</div></div>
          <select className="input" style={{ width: 220 }} value={settings.clientId ?? ""} onChange={(e) => patch({ clientId: e.target.value || null })}>
            <option value="">No client</option>
            {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
        <div className="toggle">
          <div>Dictation output</div>
          <select className="input" style={{ width: 220 }} value={settings.dictationOutput} onChange={(e) => patch({ dictationOutput: e.target.value as "type" | "clipboard" })}>
            <option value="type">Type into the focused app</option><option value="clipboard">Copy to clipboard</option>
          </select>
        </div>
      </div>
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
