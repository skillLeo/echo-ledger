// Settings › Microphone & audio and Settings › Screen moments (with Never capture these apps) in the new look, with
// today's data and rules (CaptureSettings.tsx, ScreenSettings.tsx).
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, listenTo, type InputDevice, type PermissionsStatus, type ScreensStatus, type ScreenTarget, type Settings, type TestResult } from "../../../lib/ipc";
import { meterLevel } from "../../../lib/controls";
import { fillWidth } from "../../../lib/motion";
import { bluetoothMic } from "../../../lib/microphone";
import { setting } from "../../../lib/settingsRegistry";
import { PERMISSION_TEXT, testVerdict } from "../../../views/main/CaptureSettings";
import { MONTHLY_NOTE, TARGET_HINT, WINDOWS_WHOLE_SCREEN, comparableApp, screensBlocked } from "../../../views/main/ScreenSettings";
import { PlanBadge, useLocked, useLockedTag } from "../../../views/main/Upgrade";
import { Button, Card, EmptyState, Icon, Icons, List, ListRow, TextField } from "../../components";
import { Group, LinkSetting, ResetDefaults, SelectSetting, SwitchSetting, type Patch } from "./rows";
import { t, tc } from "../../../i18n";

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
  // The lines under the group, each with its own test id (the Bluetooth one names the microphone on hover).
  const notes = [
    chosenMissing && { line: t("The microphone you chose isn't connected, so the system default is used.") },
    bluetooth && { line: t("Accuracy is lower with Bluetooth mics. A wired or built-in microphone hears calls better."), bluetooth: true },
    disabled && { line: t("Finish the current recording to run a test.") },
  ].filter(Boolean) as { line: string; bluetooth?: boolean }[];
  const fix = (kind: "microphone" | "system_audio") => <Button size="sm" onClick={() => void api.openPrivacySettings(kind).catch(() => undefined)}>{t("Fix")}</Button>;
  return (
    <>
      <Group footer={notes.length > 0 || systemAudioNote ? <>
        {notes.map((n) => (n.bluetooth ? <p key={n.line} data-testid="bluetooth-note" title={bluetooth?.name}>{n.line}</p> : <p key={n.line}>{n.line}</p>))}
        {systemAudioNote && <p className="el-privacy-line--warning" data-testid="whole-system-note">{systemAudioNote}.</p>}
      </> : undefined}>
        <SelectSetting id="mic.device" value={chosenMissing ? "" : settings.inputDevice ?? ""}
          options={[{ value: "", label: t("Automatic") }, ...devices.map((d) => ({ value: d.id, label: d.isDefault ? t(":name (default)", { name: d.name }) : d.name }))]}
          onChange={(v) => void patch({ inputDevice: v || null })} />
        <ListRow compact anchor="mic.levels" title={t("Microphone level")}
          control={<span className="el-meters" aria-live="polite"><Meter label={t("Microphone level")} level={me} /><span className="el-caption el-secondary">{t("Computer")}</span><Meter label={t("Computer sound level")} level={them} /></span>} />
        <ListRow compact anchor="mic.test" title={setting("mic.test").title}
          control={<Button size="sm" disabled={testing || disabled} onClick={() => void test()}>{testing ? t("Recording…") : result ? t("Test again") : t("Test 10 s")}</Button>} />
        {result && (
          <li className="el-row el-row--compact el-row--note">
            <div className="el-test-result">
              {testVerdict(result).map((line) => <p key={line}>{line}</p>)}
              <audio controls src={`data:audio/wav;base64,${result.wavBase64}`} aria-label={t("Play back the test recording")} />
            </div>
          </li>
        )}
        {error && <li className="el-row el-row--compact el-row--note"><p className="el-field__error" role="alert">{error}</p></li>}
        <SwitchSetting id="mic.far_side_warning" on={settings.farSideWarning} onChange={(farSideWarning) => void patch({ farSideWarning })} />
      </Group>
      <Group title={t("Permissions")}>
        <ListRow compact anchor="mic.perm.microphone" title={setting("mic.perm.microphone").title} value={permissions ? PERMISSION_TEXT[permissions.microphone] : t("Checking…")}
          control={permissions && permissions.microphone !== "allowed" && permissions.platform !== "other" ? fix("microphone") : undefined} />
        {permissions?.platform === "macos" && (
          <ListRow compact anchor="mic.perm.system_audio" title={setting("mic.perm.system_audio").title}
            value={permissions.systemAudio === "allowed" ? t("Allowed") : permissions.systemAudio === "denied" ? t("Not allowed")
              : permissions.canTellNotAsked === false ? t("Check in System Settings") : t("Asked on first use")}
            control={permissions.systemAudio !== "allowed" ? fix("system_audio") : undefined} />
        )}
      </Group>
      <ResetDefaults page={t("Microphone & audio")} onReset={() => void patch({ inputDevice: null, farSideWarning: true })} />
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
  // UX-10: each row keeps its one line (the setting's own); the longer explanation and each platform's notes are under "Learn more" (on the page,
  // closed). What needs the person (the server, the plan or this computer holding them back, and macOS's permission
  // after a refusal) stays in view.
  const more = [
    t("During a call recording EchoLedger keeps what the meeting shows each time it changes, or when someone says “look at this page”, so the transcript knows what was on screen. Screens are stored encrypted on this computer until they reach your server, and deleted here once they did."),
    on ? TARGET_HINT[settings.screenTarget] + (settings.screenTarget === "whole_screen" && status?.platform === "windows" ? ` ${t(WINDOWS_WHOLE_SCREEN.trim())}` : "") : null,
    on && status?.platform === "windows" ? (status.borderless
      ? t("Windows 11 captures without the yellow border. If it still shows, allow borderless capture for EchoLedger in Windows Settings.")
      : t("Windows 10 draws a yellow border around the meeting window while screens are on. Windows 11 captures without it.")) : null,
    // Allowed: the monthly question; refused: only what to do (in view, below the rows); not asked yet: both.
    on && status?.platform === "macos" ? (status.permission ? t(MONTHLY_NOTE) : asked ? null
      : `${t("macOS asks once for the Screen Recording permission.")} ${t(MONTHLY_NOTE)}`) : null,
  ].filter(Boolean) as string[];
  const notes = [
    blocked,
    // The switch row says what screen moments are; the plan's row in its place doesn't, so the line goes here.
    locked ? setting("screen.enabled").description : null,
    on && status?.platform === "macos" && !status.permission && asked
      ? t("Turn on EchoLedger in System Settings › Privacy & Security › Screen & System Audio Recording, then open EchoLedger again.") : null,
  ].filter(Boolean) as string[];
  return (
    <div className="el-settings-v2" data-testid="screen-moments-card">
      <p className="el-secondary">{t("Keeps what a meeting shows on screen, so the transcript knows what was there.")}</p>
      <Group footer={notes.length ? <>{notes.map((line) => <p key={line} data-testid={line === blocked ? "screens-blocked" : undefined}>{line}</p>)}</> : undefined}>
        {locked ? (
          // A plan without screen moments keeps the row in view with its plan's badge; nothing is captured.
          <ListRow compact anchor="screen.enabled" title={setting("screen.enabled").title} meta={t("Not in your plan")} badge={<PlanBadge feature="screen_moments" />}
            label={tag ? t(":title, in :plan", { title: setting("screen.enabled").title, plan: tag.tag }) : undefined} chevron onClick={tag?.onOpen} />
        ) : (
          <SwitchSetting id="screen.enabled" on={on} disabled={status?.inPlan === false} onChange={(v) => void patch({ screenMoments: v })} />
        )}
        {!locked && status?.inPlan !== false && (
          <>
            {on && <SelectSetting<ScreenTarget> id="screen.target" value={settings.screenTarget} onChange={(v) => void patch({ screenTarget: v })} />}
            {on && status?.platform === "windows" && (
              <ListRow compact anchor="screen.border" title={setting("screen.border").title} value={status.borderless ? t("Off on Windows 11") : t("Shown on Windows 10")}
                control={status.borderless ? <Button size="sm" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>{t("Windows Settings")}</Button> : undefined} />
            )}
            {on && status?.platform === "macos" && (
              <ListRow compact anchor="screen.permission" title={setting("screen.permission").title} value={status.permission ? t("Allowed") : t("Not allowed")}
                meta={status.permission ? t("macOS asks again about once a month: choose Allow.") : undefined}
                control={!status.permission ? (
                  <span className="el-row__buttons">
                    <Button size="sm" onClick={() => void allow()}>{t("Allow Screen Recording")}</Button>
                    {asked && <Button size="sm" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>{t("Open System Settings")}</Button>}
                  </span>
                ) : undefined} />
            )}
            <LinkSetting id="screen.excluded" value={tc(":count app|:count apps", apps.length)} onOpen={onExcluded} />
          </>
        )}
      </Group>
      {/* Learn more: what screen moments are, and this platform's notes, in full. */}
      <details className="el-more el-group__note el-caption el-secondary" data-testid="screen-learn-more">
        <summary><Icon icon={Icons.ChevronEndIcon} directional />{t("Learn more")}</summary>
        {more.map((line) => <p key={line}>{line}</p>)}
      </details>
      <ResetDefaults page={t("Screen moments")} onReset={() => void patch({ screenMoments: true, screenTarget: "meeting_window" })} />
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
          <EmptyState icon={Icons.ScreenIcon} title={t("No apps")} body={t("Every window can be captured.")} />
        ) : (
          <List label={t("Never capture these apps")} className="el-group__rows">
            {apps.map((app) => (
              <ListRow key={app} compact title={app}
                control={<Button size="sm" variant="quiet" aria-label={t("Remove :name", { name: app })} onClick={() => void patch({ screenExcludedApps: apps.filter((a) => a !== app) })}>{t("Remove")}</Button>} />
            ))}
          </List>
        )}
      </Card>
      <Card>
        <form className="el-form" onSubmit={(e) => { e.preventDefault(); void add(); }}>
          <TextField label={t("App or process name")} value={name} maxLength={100} dir="ltr" onChange={(e) => setName(e.target.value)}
            hint={platform === "windows" ? t("By app or process name (Signal.exe). Screens pause while one shows. Password managers are on the list from the start.")
              : platform === "macos" ? t("By app or process name (Signal). Screens pause while one shows. Password managers are on the list from the start.")
                : t("By app or process name. Screens pause while one shows. Password managers are on the list from the start.")} />
          <div className="el-page__actions"><Button type="submit" disabled={!name.trim()}>{t("Exclude")}</Button></div>
        </form>
      </Card>
    </>
  );
}
