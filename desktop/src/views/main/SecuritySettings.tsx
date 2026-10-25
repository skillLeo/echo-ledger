// Settings → Privacy & security (plan B16): the app lock with Windows Hello / Touch ID and a PIN, the devices paired
// with the server (revoke the others) and the recent audit log.
import { useEffect, useId, useState } from "react";
import { api, errorMessage, type LockAfterMinutes, type LockStatus, type SecurityDevice, type SecurityEvent, type Settings, type SettingsPatch } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { SelectRow, Toggle } from "./SettingsControls";
import { SYSTEM_LABEL } from "./LockScreen";

type Run = <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;

const LOCK_AFTER: readonly (readonly [string, string])[] = [["1", "After 1 minute"], ["5", "After 5 minutes"], ["15", "After 15 minutes"], ["60", "After 1 hour"], ["0", "Only when the app starts"]];

/** "Signed in · Pixel 9" style line for one audit entry. */
export function eventLine(e: Pick<SecurityEvent, "summary" | "actor" | "device">): string {
  const who = e.actor === "device" ? e.device ?? "A device" : e.actor === "web" ? "Console" : "EchoLedger";
  return `${e.summary} · ${who}`;
}

function PinForm({ hasPin, run, onSaved }: { hasPin: boolean; run: Run; onSaved: (s: LockStatus) => void }) {
  const [pin, setPin] = useState("");
  const [again, setAgain] = useState("");
  const pinId = useId();
  const againId = useId();
  const mismatch = again.length > 0 && pin !== again;
  const save = async () => {
    const next = await run(() => api.setLockPin(pin), hasPin ? "PIN changed" : "PIN set");
    if (next) {
      onSaved(next);
      setPin("");
      setAgain("");
    }
  };
  const digits = (v: string) => v.replace(/\D/g, "").slice(0, 12);
  return (
    <div className="row organise-field" style={{ marginTop: 8 }}>
      <label htmlFor={pinId} className="sr-only">{hasPin ? "New PIN" : "PIN"}</label>
      <input id={pinId} className="input" type="password" inputMode="numeric" autoComplete="new-password" placeholder={hasPin ? "New PIN" : "PIN (4-12 digits)"} value={pin} onChange={(e) => setPin(digits(e.target.value))} />
      <label htmlFor={againId} className="sr-only">Repeat the PIN</label>
      <input id={againId} className="input" type="password" inputMode="numeric" autoComplete="new-password" placeholder="Repeat the PIN" value={again} onChange={(e) => setAgain(digits(e.target.value))} />
      <button type="button" className="btn" disabled={pin.length < 4 || pin !== again} onClick={() => void save()}>{hasPin ? "Change PIN" : "Set PIN"}</button>
      {mismatch && <span className="small error-text">The PINs differ.</span>}
    </div>
  );
}

export function SecurityCard({ settings, patch, run }: { settings: Settings; patch: (p: SettingsPatch) => Promise<boolean>; run: Run }) {
  const [lock, setLock] = useState<LockStatus | null>(null);
  const [devices, setDevices] = useState<SecurityDevice[]>([]);
  const [events, setEvents] = useState<SecurityEvent[]>([]);
  const [overviewError, setOverviewError] = useState<string | null>(null);

  const loadOverview = () =>
    api.securityOverview().then((o) => {
      setDevices(o.devices ?? []);
      setEvents(o.events ?? []);
      setOverviewError(null);
    }, (e) => setOverviewError(errorMessage(e)));

  useEffect(() => {
    api.lockStatus().then(setLock).catch(() => undefined);
    void loadOverview();
  }, []);

  const revoke = async (d: SecurityDevice) => {
    if (!window.confirm(`Revoke ${d.name}? It stops syncing at once; its recordings stay.`)) return;
    const ok = await run(() => api.revokeDevice(d.id), `${d.name} revoked`);
    if (ok !== undefined) void loadOverview();
  };

  return (
    <div className="card security-settings">
      <b>Privacy & security</b>
      <Toggle label="Lock EchoLedger" hint={lock?.osMethod ? `${SYSTEM_LABEL[lock.osMethod].replace("Use ", "")} or your PIN unlocks it. Recording keeps running while it is locked.` : "Your PIN unlocks it. Recording keeps running while it is locked."}
        on={settings.appLock} onChange={(v) => void patch({ appLock: v }).then(async (ok) => { if (ok) setLock(await api.lockStatus()); })} />
      {!lock?.hasPin && <p className="small muted">Set a PIN first: it unlocks the app whenever Windows Hello or Touch ID is not available.</p>}
      {lock && <PinForm hasPin={lock.hasPin} run={run} onSaved={setLock} />}
      <SelectRow<string> label="Lock when not used" value={String(settings.lockAfterMinutes)} options={LOCK_AFTER}
        onChange={(v) => void patch({ lockAfterMinutes: Number(v) as LockAfterMinutes })} />
      <div className="row"><span className="spacer" /><button type="button" className="btn ghost" disabled={!settings.appLock} onClick={() => void run(() => api.lockNow())}>Lock now</button></div>

      <b style={{ display: "block", marginTop: 12 }}>Devices</b>
      {overviewError && <p className="small muted">{overviewError}</p>}
      <div className="site-list" role="list" aria-label="Paired devices">
        {devices.map((d) => (
          <div className="site-rule" role="listitem" key={d.id}>
            <div className="site-main">
              <div className="site-pattern">{d.name}{d.current && <span className="small muted"> · this computer</span>}</div>
              <div className="small muted">{d.platform}{d.last_seen_at ? ` · seen ${formatWhen(d.last_seen_at)}` : ""}</div>
            </div>
            {!d.current && <button type="button" className="btn danger ghost small" onClick={() => void revoke(d)}>Revoke</button>}
          </div>
        ))}
      </div>

      <b style={{ display: "block", marginTop: 12 }}>Recent activity</b>
      {events.length === 0 ? <p className="small muted">Nothing recorded yet.</p> : (
        <ul className="activity" aria-label="Recent activity">
          {events.slice(0, 15).map((e) => <li key={e.id} className="small"><span className="muted">{formatWhen(e.at)}</span> {eventLine(e)}</li>)}
        </ul>
      )}
    </div>
  );
}
