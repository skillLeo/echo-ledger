// Settings › Recording › Screen moments (since v1.5; rows of v1.7): on or off, the meeting window or the whole screen,
// the apps never captured (a sub-page), and what this computer and the server allow: the yellow border of Windows 10,
// the macOS Screen Recording permission (asked again about once a month from macOS 15), the plan and an older server.
import { useEffect, useId, useState } from "react";
import { api, type ScreensStatus, type ScreenTarget, type Settings, type SettingsPatch } from "../../lib/ipc";
import { setting } from "../../lib/settingsRegistry";
import { useLockedTag, useLocked } from "./Upgrade";
import { EntityRow, InfoRow, LinkRow, ResetRow, Section, SelectRow, SwitchRow } from "./SettingsControls";

const TARGET_HINT: Record<ScreenTarget, string> = {
  meeting_window: "Only the meeting app's window: nothing else on your screen is kept.",
  whole_screen: "The screen the meeting is on, without EchoLedger's own windows. Screens pause while an excluded app shows on it.",
};
/** macOS 15's monthly question, in plain words. */
export const MONTHLY_NOTE = "From macOS 15, macOS asks again about once a month whether EchoLedger may still see your screen. That is normal: choose Allow. If you miss it, screen moments pause and the recording goes on.";
/** Windows leaves EchoLedger out of a whole screen's picture only by hiding it from every screen capture meanwhile. */
const WINDOWS_WHOLE_SCREEN = " Meanwhile Windows hides EchoLedger's window from screen sharing too.";

/** A name as the excluded list compares it (the core cleans the list the same way): no capitals, spaces or `.exe`. */
export function comparableApp(name: string): string {
  return name.trim().toLowerCase().replace(/\.exe$/, "").replace(/[\s_-]/g, "");
}

/** What the page says about this computer, the plan and the server; null when nothing stops screen moments. */
export function screensBlocked(status: ScreensStatus): string | null {
  if (!status.onServer) return "Your server doesn't have screen moments yet. Nothing is kept until it's updated.";
  if (!status.inPlan) return "Screen moments aren't in your workspace's plan, so nothing is captured or kept.";
  if (!status.supported) return "This computer can't capture the screen: screen moments need Windows 10 (version 1903) or later, or macOS 14.4 or later.";
  return null;
}

export function ScreenMomentsPage({ settings, patch, onExcluded }: { settings: Settings; patch: (p: SettingsPatch) => Promise<boolean>; onExcluded: () => void }) {
  const [status, setStatus] = useState<ScreensStatus | null>(null);
  const [asked, setAsked] = useState(false);
  useEffect(() => {
    api.screensStatus().then(setStatus).catch(() => undefined);
  }, []);
  const locked = useLocked("screen_moments");
  const tag = useLockedTag("screen_moments");
  const blocked = status ? screensBlocked(status) : null;
  const apps = settings.screenExcludedApps;
  const on = settings.screenMoments && !locked && status?.inPlan !== false;
  const allow = async () => {
    const granted = await api.screensPermission().catch(() => false);
    setAsked(!granted);
    setStatus((s) => (s ? { ...s, permission: granted } : s));
  };
  const footer = [
    blocked,
    setting("screen.enabled").description,
    on ? TARGET_HINT[settings.screenTarget] + (settings.screenTarget === "whole_screen" && status?.platform === "windows" ? WINDOWS_WHOLE_SCREEN : "") : null,
    on && status?.platform === "windows" ? (status.borderless
      ? "Windows 11 captures without the yellow border. If it still shows, allow borderless capture for EchoLedger in Windows Settings."
      : "Windows 10 draws a yellow border around the meeting window while screens are on. Windows 11 captures without it.") : null,
    on && status?.platform === "macos" ? (status.permission ? MONTHLY_NOTE : asked
      ? "Turn on EchoLedger in System Settings › Privacy & Security › Screen & System Audio Recording, then open EchoLedger again."
      : `macOS asks once for the Screen Recording permission. ${MONTHLY_NOTE}`) : null,
  ].filter(Boolean) as string[];
  return (
    <div data-testid="screen-moments-card">
      <p className="set-intro">
        During a call recording EchoLedger keeps what the meeting shows each time it changes, or when someone says “look at
        this page”, so the transcript knows what was on screen. Screens are stored encrypted on this computer until they
        reach your server, and deleted here once they did.
      </p>
      <Section footer={<>{footer.map((line) => <p key={line} data-testid={line === blocked ? "screens-blocked" : undefined}>{line}</p>)}</>}>
        {/* A plan without screen moments keeps the row in view with its plan's tag (v1.6.2); nothing is captured. */}
        <SwitchRow id="screen.enabled" on={on} locked={locked ? tag : null} disabled={status?.inPlan === false}
          onChange={(v) => void patch({ screenMoments: v })} />
        {!locked && status?.inPlan !== false && (
          <>
            {on && <SelectRow id="screen.target" value={settings.screenTarget}
              options={(setting("screen.target").options ?? []).map((o) => [o.value as ScreenTarget, o.label] as const)} onChange={(v) => void patch({ screenTarget: v })} />}
            {on && status?.platform === "windows" && (
              <InfoRow id="screen.border" value={status.borderless ? "Off on Windows 11" : "Shown on Windows 10"}>
                {status.borderless && <button type="button" className="btn compact" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>Windows Settings</button>}
              </InfoRow>
            )}
            {on && status?.platform === "macos" && (
              <InfoRow id="screen.permission" value={status.permission ? "Allowed" : "Not allowed"}>
                {!status.permission && <button type="button" className="btn compact" onClick={() => void allow()}>Allow Screen Recording</button>}
                {!status.permission && asked && <button type="button" className="btn compact" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>Open System Settings</button>}
              </InfoRow>
            )}
            <LinkRow id="screen.excluded" value={apps.length === 1 ? "1 app" : `${apps.length} apps`} onOpen={onExcluded} chevron />
          </>
        )}
      </Section>
      <ResetRow page="Screen moments" onReset={() => void patch({ screenMoments: true, screenTarget: "meeting_window" })} />
    </div>
  );
}

/** Screen moments › Never capture these apps. */
export function ExcludedAppsPage({ settings, patch, platform }: { settings: Settings; patch: (p: SettingsPatch) => Promise<boolean>; platform: string }) {
  const [name, setName] = useState("");
  const inputId = useId();
  const apps = settings.screenExcludedApps;
  const add = async () => {
    const app = name.trim();
    if (!app) return;
    if (apps.some((a) => comparableApp(a) === comparableApp(app)) || (await patch({ screenExcludedApps: [...apps, app] }))) setName("");
  };
  const remove = (app: string) => void patch({ screenExcludedApps: apps.filter((a) => a !== app) });
  return (
    <>
      <Section footer={<p>By app or process name{platform === "windows" ? " (Signal.exe)" : platform === "macos" ? " (Signal)" : ""}. Screens pause while one shows. Password managers are on the list from the start.</p>}>
        <div role="list" aria-label="Never capture these apps">
          {apps.length === 0 && <div className="set-row"><span className="set-title muted">No apps: every window can be captured.</span></div>}
          {apps.map((app) => (
            <EntityRow key={app} title={app}>
              <button type="button" className="btn ghost compact" aria-label={`Remove ${app}`} onClick={() => remove(app)}>Remove</button>
            </EntityRow>
          ))}
        </div>
        <form className="set-row set-form" onSubmit={(e) => { e.preventDefault(); void add(); }}>
          <label htmlFor={inputId} className="sr-only">App or process name</label>
          <input id={inputId} className="input" placeholder="App or process name" value={name} maxLength={100} onChange={(e) => setName(e.target.value)} />
          <button type="submit" className="btn compact" disabled={!name.trim()}>Exclude</button>
        </form>
      </Section>
    </>
  );
}
