// Settings cards for capture and control (plan B1, B4, B5): which call apps record automatically, how captions look,
// and the microphone: device picker, 10-second test recording with meters, permissions and the silence warning.
import { useEffect, useId, useRef, useState } from "react";
import {
  api,
  errorMessage,
  listenTo,
  type CaptionSize,
  type CaptionStyle,
  type CaptionWidth,
  type InputDevice,
  type PermissionsStatus,
  type Settings,
  type SettingsPatch,
  type TestResult,
} from "../../lib/ipc";
import { meterLevel } from "../../lib/controls";
import { sourceAppLabel } from "../../lib/format";
import { SourceAppIcon } from "../../components/Icons";
import { SelectRow, Toggle } from "./SettingsControls";

type Patch = (p: SettingsPatch) => Promise<boolean>;

/** Call apps auto mode can record, in the order the switches show. */
export const AUTO_RECORD_APPS = ["whatsapp", "whatsapp_business", "whatsapp_web", "google_meet", "zoom", "microsoft_teams"] as const;

/** Is auto-record on for this app? Apps without a stored switch are on. */
export function appRecords(apps: Record<string, boolean>, app: string): boolean {
  return apps[app] !== false;
}

/** Settings → Auto-record: one switch per call app (B1). Off = auto mode ignores it; recording by hand still works. */
export function AutoRecordApps({ settings, patch }: { settings: Settings; patch: Patch }) {
  return (
    <div className="card">
      <b>Auto-record apps</b>
      <p className="small muted">Calls in these apps are recorded automatically while auto-record is on. You can always start a recording yourself.</p>
      <div className="site-list" role="list" aria-label="Auto-record apps">
        {AUTO_RECORD_APPS.map((app) => {
          const on = appRecords(settings.autoRecordApps, app);
          const label = sourceAppLabel(app);
          return (
            <div className={`site-rule ${on ? "" : "off"}`} role="listitem" key={app}>
              <span className="site-icon"><SourceAppIcon app={app} size={15} /></span>
              <div className="site-main"><div className="site-pattern">{label}</div></div>
              <button type="button" className={`switch ${on ? "on" : ""}`} role="switch" aria-checked={on} aria-label={`Auto-record ${label}`} disabled={!settings.autoMode} onClick={() => void patch({ autoRecordApps: { [app]: !on } })} />
            </div>
          );
        })}
      </div>
      {!settings.autoMode && <p className="small muted">Auto-record is off, so these switches have no effect.</p>}
    </div>
  );
}

const SIZES: readonly (readonly [CaptionSize, string])[] = [["small", "Small"], ["medium", "Medium"], ["large", "Large"]];
const WIDTHS: readonly (readonly [CaptionWidth, string])[] = [["narrow", "Narrow"], ["normal", "Normal"], ["wide", "Wide"]];
const AUTO_HIDE = [["0", "Never"], ["5", "After 5 seconds"], ["10", "After 10 seconds"], ["30", "After 30 seconds"]] as const;
/** The speaker colours of the dark caption pill (the pill meters use the same). */
export const DEFAULT_ME_COLOR = "#9eb1ff";
export const DEFAULT_CLIENT_COLOR = "#0bd8b6";

/** Settings → Caption style (B4). Every change saves the whole style; the overlay restyles at once. */
export function CaptionStyleCard({ settings, patch }: { settings: Settings; patch: Patch }) {
  const style = settings.captionStyle;
  const opacityId = useId();
  const meId = useId();
  const clientId = useId();
  const save = (next: Partial<CaptionStyle>) => void patch({ captionStyle: { ...style, ...next } });
  return (
    <div className="card">
      <b>Caption style</b>
      <SelectRow label="Text size" value={style.size} options={SIZES} width={180} onChange={(size) => save({ size })} />
      <SelectRow label="Width" value={style.width} options={WIDTHS} width={180} onChange={(width) => save({ width })} />
      <div className="toggle">
        <label htmlFor={opacityId}>Background opacity</label>
        <div className="row">
          <input id={opacityId} type="range" min={40} max={100} step={5} value={style.opacity} onChange={(e) => save({ opacity: Number(e.target.value) })} />
          <span className="small muted" style={{ width: 40, textAlign: "right" }}>{style.opacity}%</span>
        </div>
      </div>
      <Toggle label="Compact captions" hint="Speakers are shown by colour instead of the Me / Client labels." on={style.compact} onChange={(compact) => save({ compact })} />
      <SelectRow label="Hide captions when nobody speaks" value={String(style.autoHideSeconds) as (typeof AUTO_HIDE)[number][0]} options={AUTO_HIDE} width={180} onChange={(v) => save({ autoHideSeconds: Number(v) })} />
      <div className="toggle">
        <label htmlFor={meId}>Colour for Me</label>
        <div className="row">
          <input id={meId} type="color" value={style.meColor ?? DEFAULT_ME_COLOR} onChange={(e) => save({ meColor: e.target.value })} />
          {style.meColor && <button type="button" className="btn ghost compact" onClick={() => save({ meColor: null })}>Default</button>}
        </div>
      </div>
      <div className="toggle">
        <label htmlFor={clientId}>Colour for Client</label>
        <div className="row">
          <input id={clientId} type="color" value={style.clientColor ?? DEFAULT_CLIENT_COLOR} onChange={(e) => save({ clientColor: e.target.value })} />
          {style.clientColor && <button type="button" className="btn ghost compact" onClick={() => save({ clientColor: null })}>Default</button>}
        </div>
      </div>
    </div>
  );
}

/** What the test result says to the user, one sentence per side. */
export function testVerdict(result: Pick<TestResult, "micHeard" | "systemHeard">): string[] {
  return [
    result.micHeard ? "Your microphone is working." : "We did not hear your microphone. Check the device above and the microphone permission.",
    result.systemHeard
      ? "Sound from this computer was captured."
      : "No sound from this computer was captured. Play something during the test to check the other side of calls.",
  ];
}

const PERMISSION_TEXT: Record<PermissionsStatus["microphone"], string> = {
  allowed: "Allowed",
  denied: "Blocked: EchoLedger cannot hear your microphone",
  unknown: "Asked the first time you record",
};

/** Settings → Microphone and capture health (B5). */
export function MicrophoneCard({ settings, patch, disabled }: { settings: Settings; patch: Patch; disabled: boolean }) {
  const [devices, setDevices] = useState<InputDevice[]>([]);
  const [permissions, setPermissions] = useState<PermissionsStatus | null>(null);
  const [testing, setTesting] = useState(false);
  const [levels, setLevels] = useState<[number, number]>([0, 0]);
  const [result, setResult] = useState<TestResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const deviceId = useId();
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

  return (
    <div className="card">
      <b>Microphone</b>
      <div className="toggle">
        <label htmlFor={deviceId}>Record from</label>
        <select id={deviceId} className="input" style={{ width: 260 }} value={chosenMissing ? "" : settings.inputDevice ?? ""} onChange={(e) => void patch({ inputDevice: e.target.value || null })}>
          <option value="">System default</option>
          {devices.map((d) => <option key={d.id} value={d.id}>{d.name}{d.isDefault ? " (default)" : ""}</option>)}
        </select>
      </div>
      {chosenMissing && <p className="small muted">The microphone you chose is not connected, so the system default is used.</p>}
      <div className="toggle">
        <div><div>Test recording</div><div className="small muted">Records 10 seconds of your microphone and this computer's sound, then plays it back.</div></div>
        <button type="button" className="btn" disabled={testing || disabled} onClick={() => void test()}>{testing ? "Recording…" : "Test 10 seconds"}</button>
      </div>
      {(testing || result) && (
        <div aria-live="polite">
          <div className="row small"><span style={{ width: 52 }}>Me</span><div className="meter" role="meter" aria-label="Microphone level" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(meterLevel(testing ? levels[0] : result?.micPeak ?? 0) * 100)}><div style={{ width: `${meterLevel(testing ? levels[0] : result?.micPeak ?? 0) * 100}%` }} /></div></div>
          <div className="row small"><span style={{ width: 52 }}>Client</span><div className="meter" role="meter" aria-label="Computer sound level" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(meterLevel(testing ? levels[1] : result?.systemPeak ?? 0) * 100)}><div style={{ width: `${meterLevel(testing ? levels[1] : result?.systemPeak ?? 0) * 100}%` }} /></div></div>
          {result && (
            <>
              {testVerdict(result).map((line) => <p className="small" key={line}>{line}</p>)}
              <audio controls src={`data:audio/wav;base64,${result.wavBase64}`} aria-label="Test recording" />
            </>
          )}
        </div>
      )}
      {disabled && <p className="small muted">Finish the current recording to run a test.</p>}
      {error && <p className="small error-text">{error}</p>}
      <div className="toggle">
        <div><div>Microphone permission</div><div className="small muted">{permissions ? PERMISSION_TEXT[permissions.microphone] : "Checking…"}</div></div>
        {permissions && permissions.platform !== "other" && <button type="button" className="btn ghost" onClick={() => void api.openPrivacySettings("microphone").catch(() => undefined)}>Open settings</button>}
      </div>
      {permissions?.platform === "macos" && (
        <div className="toggle">
          <div><div>Screen and system audio recording</div><div className="small muted">Needed to hear the other side of calls.</div></div>
          <button type="button" className="btn ghost" onClick={() => void api.openPrivacySettings("system_audio").catch(() => undefined)}>Open settings</button>
        </div>
      )}
      <Toggle label="Warn me when the other side is silent" hint="Shows a warning on the recording pill if a call has not heard the other side for 30 seconds." on={settings.farSideWarning} onChange={(farSideWarning) => void patch({ farSideWarning })} />
    </div>
  );
}
