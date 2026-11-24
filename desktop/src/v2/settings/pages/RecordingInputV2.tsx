// Settings › Microphone & audio and Settings › Screen moments (with Never capture these apps) in the new look, with
// today's data and rules (CaptureSettings.tsx, ScreenSettings.tsx).
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, listenTo, type InputDevice, type PermissionsStatus, type ScreensStatus, type ScreenTarget, type Settings, type TestResult } from "../../../lib/ipc";
import { meterLevel } from "../../../lib/controls";
import { fillWidth } from "../../../lib/motion";
import { BLUETOOTH_NOTE, bluetoothMic } from "../../../lib/microphone";
import { setting } from "../../../lib/settingsRegistry";
import { PERMISSION_TEXT, testVerdict } from "../../../views/main/CaptureSettings";
import { MONTHLY_NOTE, TARGET_HINT, WINDOWS_WHOLE_SCREEN, comparableApp, screensBlocked } from "../../../views/main/ScreenSettings";
import { PlanBadge, useLocked, useLockedTag } from "../../../views/main/Upgrade";
import { Button, Card, EmptyState, Icon, Icons, List, ListRow, TextField } from "../../components";
import { Group, LinkSetting, ResetDefaults, SelectSetting, SwitchSetting, type Patch } from "./rows";

/** A level from 0 to 1 as a bar. */
function Meter({ label, level }: { label: string; level: number }) {
  return (
    <span className="el-meter" role="meter" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(level * 100)}>
      <span className="el-meter__fill" style={fillWidth(level)} />
    </span>
  );
}

/** Recording › Microphone & audio: which microphone, the levels and a 10-second test, the silent caller warning, and
 * what the system allows. `disabled` while a recording or dictation runs. */
export function MicrophonePageV2({ settings, patch, disabled, systemAudioNote }: { settings: Settings; patch: Patch; disabled: boolean; systemAudioNote?: string | null }) {
  const [devices, setDevices] = useState<InputDevice[]>([]);
  const [permissions, setPermissions] = useState<PermissionsStatus | null>(null);
  const [testing, setTesting] = useState(false);
  const [levels, setLevels] = useState<[number, number]>([0, 0]);
  const [result, setResult] = useState<TestResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    api.inputDevices().then((d) => mounted.current && setDevices(d)).catch(() => undefined);
    api.permissionsStatus().then((p) => mounted.current && setPermissions(p)).catch(() => undefined);
    const un = listenTo("capture:test_level", (l) => mounted.current && setLevels(l));
    return () => {
      mounted.current = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  const chosenMissing = !!settings.inputDevice && devices.length > 0 && !devices.some((d) => d.id === settings.inputDevice);
  const bluetooth = bluetoothMic(devices, settings.inputDevice);
  const test = async () => {
    setTesting(true);
    setResult(null);
    setError(null);
    try {
      const r = await api.testCapture();
      if (mounted.current) setResult(r);
    } catch (e) {
      if (mounted.current) setError(errorMessage(e));
    } finally {
      if (mounted.current) {
        setTesting(false);
        setLevels([0, 0]);
      }
    }
  };
  const me = meterLevel(testing ? levels[0] : result?.micPeak ?? 0);
  const them = meterLevel(testing ? levels[1] : result?.systemPeak ?? 0);
  const notes = [
    chosenMissing && "The microphone you chose isn't connected, so the system default is used.",
    bluetooth && `${BLUETOOTH_NOTE}. A wired or built-in microphone hears calls better.`,
    disabled && "Finish the current recording to run a test.",
  ].filter(Boolean) as string[];
  const fix = (kind: "microphone" | "system_audio") => <Button size="sm" onClick={() => void api.openPrivacySettings(kind).catch(() => undefined)}>Fix</Button>;
  return (
    <>
      <Group footer={notes.length > 0 || systemAudioNote ? <>
        {notes.map((line) => (line.startsWith(BLUETOOTH_NOTE) ? <p key={line} data-testid="bluetooth-note" title={bluetooth?.name}>{line}</p> : <p key={line}>{line}</p>))}
        {systemAudioNote && <p className="el-privacy-line--warning" data-testid="whole-system-note">{systemAudioNote}.</p>}
      </> : undefined}>
        <SelectSetting id="mic.device" value={chosenMissing ? "" : settings.inputDevice ?? ""}
          options={[{ value: "", label: "Automatic" }, ...devices.map((d) => ({ value: d.id, label: `${d.name}${d.isDefault ? " (default)" : ""}` }))]}
          onChange={(v) => void patch({ inputDevice: v || null })} />
        <ListRow compact anchor="mic.levels" title="Microphone level"
          control={<span className="el-meters" aria-live="polite"><Meter label="Microphone level" level={me} /><span className="el-caption el-secondary">Computer</span><Meter label="Computer sound level" level={them} /></span>} />
        <ListRow compact anchor="mic.test" title={setting("mic.test").title}
          control={<Button size="sm" disabled={testing || disabled} onClick={() => void test()}>{testing ? "Recording…" : result ? "Test again" : "Test 10 s"}</Button>} />
        {result && (
          <li className="el-row el-row--compact el-row--note">
            <div className="el-test-result">
              {testVerdict(result).map((line) => <p key={line}>{line}</p>)}
              <audio controls src={`data:audio/wav;base64,${result.wavBase64}`} aria-label="Play back the test recording" />
            </div>
          </li>
        )}
        {error && <li className="el-row el-row--compact el-row--note"><p className="el-field__error" role="alert">{error}</p></li>}
        <SwitchSetting id="mic.far_side_warning" on={settings.farSideWarning} onChange={(farSideWarning) => void patch({ farSideWarning })} />
      </Group>
      <Group title="Permissions">
        <ListRow compact anchor="mic.perm.microphone" title={setting("mic.perm.microphone").title} value={permissions ? PERMISSION_TEXT[permissions.microphone] : "Checking…"}
          control={permissions && permissions.microphone !== "allowed" && permissions.platform !== "other" ? fix("microphone") : undefined} />
        {permissions?.platform === "macos" && (
          <ListRow compact anchor="mic.perm.system_audio" title={setting("mic.perm.system_audio").title}
            value={permissions.systemAudio === "allowed" ? "Allowed" : permissions.systemAudio === "denied" ? "Not allowed"
              : permissions.canTellNotAsked === false ? "Check in System Settings" : "Asked on first use"}
            control={permissions.systemAudio !== "allowed" ? fix("system_audio") : undefined} />
        )}
      </Group>
      <ResetDefaults page="Microphone & audio" onReset={() => void patch({ inputDevice: null, farSideWarning: true })} />
    </>
  );
}

/** Recording › Screen moments: on or off, what is captured, what this computer, the plan and the server allow. */
export function ScreenMomentsPageV2({ settings, patch, onExcluded }: { settings: Settings; patch: Patch; onExcluded: () => void }) {
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
  // UX-10: one line per setting; the longer explanation and each platform's note are under "Learn more" (still on the
  // page, closed). What needs the person — the server, the plan or this computer holding them back, and macOS's
  // permission after a refusal — stays in view.
  const more = [
    setting("screen.enabled").description,
    on ? TARGET_HINT[settings.screenTarget] + (settings.screenTarget === "whole_screen" && status?.platform === "windows" ? WINDOWS_WHOLE_SCREEN : "") : null,
    on && status?.platform === "windows" ? (status.borderless
      ? "Windows 11 captures without the yellow border. If it still shows, allow borderless capture for EchoLedger in Windows Settings."
      : "Windows 10 draws a yellow border around the meeting window while screens are on. Windows 11 captures without it.") : null,
    // As classic: allowed → the monthly question; refused → only what to do (in view, below the rows); not asked yet → both.
    on && status?.platform === "macos" ? (status.permission ? MONTHLY_NOTE : asked ? null : `macOS asks once for the Screen Recording permission. ${MONTHLY_NOTE}`) : null,
  ].filter(Boolean) as string[];
  const askedNote = "Turn on EchoLedger in System Settings › Privacy & Security › Screen & System Audio Recording, then open EchoLedger again.";
  const footer = [blocked, on && status?.platform === "macos" && !status.permission && asked ? askedNote : null].filter(Boolean) as string[];
  return (
    <div className="el-settings-v2" data-testid="screen-moments-card">
      <p className="el-secondary">Keeps what a meeting shows on screen, so the transcript knows what was there.</p>
      <Group footer={footer.length ? <>{footer.map((line) => <p key={line} data-testid={line === blocked ? "screens-blocked" : undefined}>{line}</p>)}</> : undefined}>
        {locked ? (
          // A plan without screen moments keeps the row in view with its plan's badge; nothing is captured.
          <ListRow compact anchor="screen.enabled" title={setting("screen.enabled").title} meta="Not in your plan" badge={<PlanBadge feature="screen_moments" />}
            label={tag ? `${setting("screen.enabled").title}, in ${tag.tag}` : undefined} chevron onClick={tag?.onOpen} />
        ) : (
          <SwitchSetting id="screen.enabled" on={on} disabled={status?.inPlan === false} onChange={(v) => void patch({ screenMoments: v })}
            hint="Pause it any time from the recording controls." />
        )}
        {!locked && status?.inPlan !== false && (
          <>
            {on && <SelectSetting<ScreenTarget> id="screen.target" value={settings.screenTarget} onChange={(v) => void patch({ screenTarget: v })}
              meta={settings.screenTarget === "whole_screen" ? "Without EchoLedger's own windows." : "Nothing else on your screen is kept."} />}
            {on && status?.platform === "windows" && (
              <ListRow compact anchor="screen.border" title={setting("screen.border").title} value={status.borderless ? "Off on Windows 11" : "Shown on Windows 10"}
                control={status.borderless ? <Button size="sm" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>Windows Settings</Button> : undefined} />
            )}
            {on && status?.platform === "macos" && (
              <ListRow compact anchor="screen.permission" title={setting("screen.permission").title} value={status.permission ? "Allowed" : "Not allowed"}
                meta={status.permission ? "macOS asks again about once a month: choose Allow." : undefined}
                control={!status.permission ? (
                  <span className="el-row__buttons">
                    <Button size="sm" onClick={() => void allow()}>Allow Screen Recording</Button>
                    {asked && <Button size="sm" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>Open System Settings</Button>}
                  </span>
                ) : undefined} />
            )}
            <LinkSetting id="screen.excluded" value={apps.length === 1 ? "1 app" : `${apps.length} apps`} onOpen={onExcluded} meta="Never kept, even when they're on screen." />
          </>
        )}
      </Group>
      {/* Learn more: what screen moments are, and this platform's notes, in full. */}
      <details className="el-group__note el-caption el-secondary" data-testid="screen-learn-more">
        <summary><Icon icon={Icons.ChevronEndIcon} directional />Learn more</summary>
        <p>
          During a call recording EchoLedger keeps what the meeting shows each time it changes, or when someone says “look at
          this page”, so the transcript knows what was on screen. Screens are stored encrypted on this computer until they
          reach your server, and deleted here once they did.
        </p>
        {more.map((line) => <p key={line}>{line}</p>)}
      </details>
      <ResetDefaults page="Screen moments" onReset={() => void patch({ screenMoments: true, screenTarget: "meeting_window" })} />
    </div>
  );
}

/** Screen moments › Never capture these apps. */
export function ExcludedAppsPageV2({ settings, patch, platform }: { settings: Settings; patch: Patch; platform: string }) {
  const [name, setName] = useState("");
  const apps = settings.screenExcludedApps;
  const add = async () => {
    const app = name.trim();
    if (!app) return;
    if (apps.some((a) => comparableApp(a) === comparableApp(app)) || (await patch({ screenExcludedApps: [...apps, app] }))) setName("");
  };
  return (
    <>
      <Card>
        {apps.length === 0 ? (
          <EmptyState icon={Icons.ScreenIcon} title="No apps" body="Every window can be captured." />
        ) : (
          <List label="Never capture these apps" className="el-group__rows">
            {apps.map((app) => (
              <ListRow key={app} compact title={app}
                control={<Button size="sm" variant="quiet" aria-label={`Remove ${app}`} onClick={() => void patch({ screenExcludedApps: apps.filter((a) => a !== app) })}>Remove</Button>} />
            ))}
          </List>
        )}
      </Card>
      <Card>
        <form className="el-form" onSubmit={(e) => { e.preventDefault(); void add(); }}>
          <TextField label="App or process name" value={name} maxLength={100} dir="ltr" onChange={(e) => setName(e.target.value)}
            hint={`By app or process name${platform === "windows" ? " (Signal.exe)" : platform === "macos" ? " (Signal)" : ""}. Screens pause while one shows. Password managers are on the list from the start.`} />
          <div className="el-page__actions"><Button type="submit" disabled={!name.trim()}>Exclude</Button></div>
        </form>
      </Card>
    </>
  );
}
