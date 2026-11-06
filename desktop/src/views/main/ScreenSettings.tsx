// Settings → Screen moments (since v1.5): on or off, the meeting window or the whole screen, the apps never captured,
// and what this computer and the server allow: the yellow border of Windows 10, the macOS Screen Recording permission
// (asked again about once a month from macOS 15), the plan and an older server.
import { useEffect, useId, useState } from "react";
import { api, type ScreensStatus, type ScreenTarget, type Settings, type SettingsPatch } from "../../lib/ipc";
import { CloseIcon } from "../../components/Icons";
import { SelectRow, Toggle } from "./SettingsControls";

const TARGETS: readonly (readonly [ScreenTarget, string])[] = [["meeting_window", "The meeting window"], ["whole_screen", "The whole screen"]];
const TARGET_HINT: Record<ScreenTarget, string> = {
  meeting_window: "Only the meeting app's window: nothing else on your screen is kept.",
  whole_screen: "The screen the meeting is on, without EchoLedger's own windows. Screens pause while an excluded app shows on it.",
};
/** Windows leaves EchoLedger out of a whole screen's picture only by hiding it from every screen capture meanwhile. */
const WINDOWS_WHOLE_SCREEN = " Meanwhile Windows hides EchoLedger's window from screen sharing too.";

/** A name as the excluded list compares it (the core cleans the list the same way): no capitals, spaces or `.exe`. */
export function comparableApp(name: string): string {
  return name.trim().toLowerCase().replace(/\.exe$/, "").replace(/[\s_-]/g, "");
}

/** What the card says about this computer, the plan and the server; null when nothing stops screen moments. */
export function screensBlocked(status: ScreensStatus): string | null {
  if (!status.onServer) return "Your server does not have screen moments yet. Nothing is kept until it is updated.";
  if (!status.inPlan) return "Screen moments are not in your workspace's plan, so nothing is kept.";
  if (!status.supported) return "This computer cannot capture the screen: screen moments need Windows 10 (version 1903) or later, or macOS 14.4 or later.";
  return null;
}

export function ScreenMomentsCard({ settings, patch }: { settings: Settings; patch: (p: SettingsPatch) => Promise<boolean> }) {
  const [status, setStatus] = useState<ScreensStatus | null>(null);
  const [name, setName] = useState("");
  const [asked, setAsked] = useState(false);
  const inputId = useId();
  const listId = useId();
  useEffect(() => {
    api.screensStatus().then(setStatus).catch(() => undefined);
  }, []);
  const apps = settings.screenExcludedApps;
  const blocked = status ? screensBlocked(status) : null;

  const add = async () => {
    const app = name.trim();
    if (!app) return;
    if (apps.some((a) => comparableApp(a) === comparableApp(app)) || (await patch({ screenExcludedApps: [...apps, app] }))) setName("");
  };
  const remove = (app: string) => void patch({ screenExcludedApps: apps.filter((a) => a !== app) });
  const allow = async () => {
    const granted = await api.screensPermission().catch(() => false);
    setAsked(!granted);
    setStatus((s) => (s ? { ...s, permission: granted } : s));
  };

  return (
    <div className="card" data-testid="screen-moments-card">
      <b>Screen moments</b>
      <p className="small muted">
        During a call recording EchoLedger keeps what the meeting shows each time it changes, or when someone says
        “look at this page”, so the transcript and an AI reading it know what was on screen. Screens are stored
        encrypted on this computer until they reach your server, and deleted here once they did.
      </p>
      {blocked && <div className="notice" role="status">{blocked}</div>}
      <Toggle label="Keep screen moments" hint="The recording pill shows when screens are on; one click there pauses them." on={settings.screenMoments} onChange={(v) => void patch({ screenMoments: v })} />
      {settings.screenMoments && (
        <>
          <SelectRow label="Capture" value={settings.screenTarget} options={TARGETS} width={200} onChange={(v) => void patch({ screenTarget: v })}
            hint={TARGET_HINT[settings.screenTarget] + (settings.screenTarget === "whole_screen" && status?.platform === "windows" ? WINDOWS_WHOLE_SCREEN : "")} />
          {status?.platform === "windows" && (
            <div className="toggle">
              <div>
                <div>Capture border</div>
                <div className="small muted" data-testid="screens-border-note">
                  {status.borderless
                    ? "Windows 11 captures without the yellow border. If it still shows, allow borderless capture for EchoLedger in Windows Settings."
                    : "Windows 10 draws a yellow border around the meeting window while screens are on. Windows 11 captures without it."}
                </div>
              </div>
              {status.borderless && <button type="button" className="btn compact" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>Windows Settings</button>}
            </div>
          )}
          {status?.platform === "macos" && (
            <div className="toggle">
              <div>
                <div>Screen Recording</div>
                <div className="small muted" data-testid="screens-permission-note">
                  {status.permission
                    ? "Allowed. macOS 15 and later ask again about once a month: choose Allow to keep screen moments."
                    : asked
                      ? "Turn on EchoLedger in System Settings → Privacy & Security → Screen & System Audio Recording, then open EchoLedger again."
                      : "macOS asks once for the Screen Recording permission, and from macOS 15 again about once a month."}
                </div>
              </div>
              {!status.permission && <button type="button" className="btn compact" onClick={() => void allow()}>Allow Screen Recording</button>}
            </div>
          )}
          <div className="excluded-apps">
            <div>
              <div id={listId}>Never capture these apps</div>
              <div className="small muted">
                By app or process name{status?.platform === "windows" ? " (Signal.exe)" : status?.platform === "macos" ? " (Signal)" : ""}. Screens pause
                while one shows; password managers are on the list from the start.
              </div>
            </div>
            <div className="excluded-list" role="list" aria-labelledby={listId}>
              {apps.length === 0 && <span className="small muted">No apps: every window can be captured.</span>}
              {apps.map((app) => (
                <span role="listitem" className="chip small excluded-app" key={app}>
                  {app}
                  <button type="button" className="chip-remove" aria-label={`Remove ${app}`} title={`Remove ${app}`} onClick={() => remove(app)}><CloseIcon size={12} /></button>
                </span>
              ))}
            </div>
            <div className="row">
              <label htmlFor={inputId} className="sr-only">App to exclude</label>
              <input id={inputId} className="input" placeholder="App or process name" value={name} maxLength={100} style={{ width: 220 }}
                onChange={(e) => setName(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") void add(); }} />
              <button type="button" className="btn compact" disabled={!name.trim()} onClick={() => void add()}>Exclude</button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
