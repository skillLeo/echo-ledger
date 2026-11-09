// Settings → Privacy & security (plan B16): the app lock with Windows Hello / Touch ID and a PIN, the devices paired
// with the server (revoke the others) and the recent audit log.
import { useEffect, useId, useState } from "react";
import { api, errorMessage, type LockAfterMinutes, type LockStatus, type NotificationCategory, type SecurityDevice, type SecurityEvent, type Settings, type SettingsPatch, type StorageInfo } from "../../lib/ipc";
import { fileSize, formatWhen } from "../../lib/format";
import { fillWidth } from "../../lib/motion";
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

/** Privacy & security → App lock. */
export function LockCard({ settings, patch, run }: { settings: Settings; patch: (p: SettingsPatch) => Promise<boolean>; run: Run }) {
  const [lock, setLock] = useState<LockStatus | null>(null);
  useEffect(() => {
    api.lockStatus().then(setLock).catch(() => undefined);
  }, []);
  return (
    <div className="card security-settings">
      <b>App lock</b>
      <Toggle label="Lock EchoLedger" hint={lock?.osMethod ? `${SYSTEM_LABEL[lock.osMethod].replace("Use ", "")} or your PIN unlocks it. Recording keeps running while it is locked.` : "Your PIN unlocks it. Recording keeps running while it is locked."}
        on={settings.appLock} onChange={(v) => void patch({ appLock: v }).then(async (ok) => { if (ok) setLock(await api.lockStatus()); })} />
      {!lock?.hasPin && <p className="small muted">Set a PIN first: it unlocks the app whenever Windows Hello or Touch ID is not available.</p>}
      {lock && <PinForm hasPin={lock.hasPin} run={run} onSaved={setLock} />}
      <SelectRow<string> label="Lock when not used" value={String(settings.lockAfterMinutes)} options={LOCK_AFTER}
        onChange={(v) => void patch({ lockAfterMinutes: Number(v) as LockAfterMinutes })} />
      <div className="row"><span className="spacer" /><button type="button" className="btn ghost" disabled={!settings.appLock} onClick={() => void run(() => api.lockNow())}>Lock now</button></div>
    </div>
  );
}

/** A device's platform in plain words (the server sends `windows`, `macos`, `android`…). */
export function platformLabel(platform: string): string {
  return ({ windows: "Windows", macos: "Mac", android: "Android phone", ios: "iPhone", linux: "Linux" } as Record<string, string>)[platform] ?? platform;
}

/** One device's name, renamed in place (v1.6, P3). */
function RenameDevice({ device, run, onDone }: { device: SecurityDevice; run: Run; onDone: (renamed: boolean) => void }) {
  const [name, setName] = useState(device.name);
  const id = useId();
  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    const saved = await run(() => api.renameDevice(device.id, name.trim()), "Name saved");
    onDone(saved !== undefined);
  };
  return (
    <form className="row device-rename" onSubmit={(e) => void save(e)} aria-label={`Rename ${device.name}`}>
      <label htmlFor={id} className="sr-only">New name for {device.name}</label>
      <input id={id} className="input" value={name} maxLength={64} autoFocus onChange={(e) => setName(e.target.value)}
        onKeyDown={(e) => { if (e.key === "Escape") onDone(false); }} />
      <button type="submit" className="btn small" disabled={!name.trim() || name.trim() === device.name}>Save</button>
      <button type="button" className="btn ghost small" onClick={() => onDone(false)}>Cancel</button>
    </form>
  );
}

/** Devices: the devices signed in to the account (rename any, revoke the others) and the recent audit log. */
export function DevicesCard({ run }: { run: Run }) {
  const [devices, setDevices] = useState<SecurityDevice[]>([]);
  const [events, setEvents] = useState<SecurityEvent[]>([]);
  const [overviewError, setOverviewError] = useState<string | null>(null);
  const [renaming, setRenaming] = useState<string | null>(null);

  const loadOverview = () =>
    api.securityOverview().then((o) => {
      setDevices(o.devices ?? []);
      setEvents(o.events ?? []);
      setOverviewError(null);
    }, (e) => setOverviewError(errorMessage(e)));

  useEffect(() => {
    void loadOverview();
  }, []);

  const revoke = async (d: SecurityDevice) => {
    if (!window.confirm(`Revoke ${d.name}? It stops syncing at once; its recordings stay.`)) return;
    const ok = await run(() => api.revokeDevice(d.id), `${d.name} revoked`);
    if (ok !== undefined) void loadOverview();
  };

  return (
    <div className="card devices-settings">
      <b>Devices</b>
      {overviewError && <p className="small muted">{overviewError}</p>}
      <div className="site-list" role="list" aria-label="Signed-in devices">
        {devices.map((d) => (
          <div className="site-rule" role="listitem" key={d.id}>
            {renaming === d.id ? (
              <RenameDevice device={d} run={run} onDone={(renamed) => { setRenaming(null); if (renamed) void loadOverview(); }} />
            ) : (
              <>
                <div className="site-main">
                  <div className="site-pattern">{d.name}{d.current && <span className="small muted"> · this computer</span>}</div>
                  <div className="small muted">{platformLabel(d.platform)}{d.last_seen_at ? ` · active ${formatWhen(d.last_seen_at)}` : ""}</div>
                </div>
                <button type="button" className="btn ghost small" aria-label={`Rename ${d.name}`} onClick={() => setRenaming(d.id)}>Rename</button>
                {!d.current && <button type="button" className="btn danger ghost small" onClick={() => void revoke(d)}>Revoke</button>}
              </>
            )}
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

/** The app lock and the devices together. */
export function SecurityCard(props: { settings: Settings; patch: (p: SettingsPatch) => Promise<boolean>; run: Run }) {
  return (
    <>
      <LockCard {...props} />
      <DevicesCard run={props.run} />
    </>
  );
}
export { fileSize };

/** Settings → Storage and retention (B17): the storage meter and how long recordings are kept. */
export function StorageCard({ limitBytes = null }: { limitBytes?: number | null } = {}) {
  const [info, setInfo] = useState<StorageInfo | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api.storage().then(setInfo, (e) => setError(errorMessage(e)));
  }, []);
  const used = info && info.disk_total_bytes && info.disk_free_bytes !== null ? 1 - info.disk_free_bytes / info.disk_total_bytes : null;
  return (
    <div className="card storage-settings">
      <b>Storage and retention</b>
      {error && <p className="small muted">{error}</p>}
      {info && (
        <>
          <p className="small">
            Recordings on the server: {fileSize(info.recordings_bytes)} in {info.recordings_count} file{info.recordings_count === 1 ? "" : "s"}
            {limitBytes !== null ? ` of the ${fileSize(limitBytes)} your plan allows` : ""}.
          </p>
          {used !== null && (
            <div className="flow-bar" role="meter" aria-label="Server disk used" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(used * 100)}>
              <span style={fillWidth(used)} />
            </div>
          )}
          {used !== null && <p className="small muted">Server disk {Math.round(used * 100)}% used · {fileSize(info.disk_free_bytes ?? 0)} free.</p>}
          <p className="small muted">Recordings are deleted after {info.retention_days} days unless kept or their project keeps them longer. Deleted recordings can be restored for 48 hours.</p>
        </>
      )}
    </div>
  );
}

/** The notification categories (Phase 1C), in the phone's channel order; Call capture & readiness is Critical. */
export const NOTIFICATION_CATEGORIES: readonly (readonly [NotificationCategory, string, string])[] = [
  ["recording", "Recording", "A recording that could not start or was not saved."],
  ["transcription", "Transcription", "A transcript is ready, or could not be made."],
  ["enhancement", "Enhancement", "Roman Urdu and other views, briefs, call notes and answers that are ready."],
  ["uploads", "Uploads", "An upload that needs you."],
  ["call_capture", "Call capture & readiness", "Calls will not be recorded fully, a backup start, only one side heard. Critical: quiet hours never hold these back."],
  ["sharing", "Sharing", "Something shared with you."],
  ["account", "Account & security", "A new device signed in to your account."],
  ["usage", "Usage & limits", "The monthly transcription limit."],
  ["live_translate", "Live Translate", "Live Translate news."],
  ["app_updates", "App updates", "A new version is ready to install."],
  ["other", "Other", "Anything else."],
];

export const NOT_SHOWN = "The system did not show it. Allow notifications for EchoLedger in your computer's settings.";
const isOn = (map: Partial<Record<NotificationCategory, boolean>> | undefined, c: NotificationCategory) => map?.[c] !== false;

/** Settings → Notifications (B18, Phase 1C): every kind of notification on or off with its sound and a Test button, and
 * the quiet hours, which never hold back Call capture & readiness. */
export function NotificationsCard({ settings, patch, run }: {
  settings: Settings;
  patch: (p: SettingsPatch) => Promise<boolean>;
  /** Runs the Test button and says how it went. */
  run?: Run;
}) {
  const fromId = useId();
  const toId = useId();
  const all = settings.activityNotifications;
  const test = (category: NotificationCategory, label: string) => {
    const send = () => api.notificationTest(category).then((shown) => {
      if (!shown) throw NOT_SHOWN;
    });
    void (run ? run(send, `Test sent: ${label}`) : send().catch(() => undefined));
  };
  return (
    <div className="card notifications-settings">
      <b>Notifications</b>
      <Toggle label="Show notifications" hint="The switch for all of them. Clicking one opens what it is about."
        on={all} onChange={(v) => void patch({ activityNotifications: v })} />
      <ul className="notification-categories" aria-label="Kinds of notification">
        {NOTIFICATION_CATEGORIES.map(([category, label, hint]) => {
          const on = isOn(settings.notificationCategories, category);
          const sound = isOn(settings.notificationSounds, category);
          return (
            <li key={category} className={`notification-category ${category === "call_capture" ? "critical" : ""}`} data-testid={`notify-${category}`}>
              <div className="notification-category-text">
                <div>{label}{category === "call_capture" && <span className="critical-chip">Critical</span>}</div>
                <div className="small muted">{hint}</div>
              </div>
              <button type="button" className={`btn compact ghost sound-toggle ${sound ? "on" : ""}`} aria-pressed={sound} disabled={!all || !on}
                aria-label={`Sound for ${label}`} title={sound ? "Plays a sound" : "Silent"}
                onClick={() => void patch({ notificationSounds: { [category]: !sound } })}>{sound ? "Sound" : "Silent"}</button>
              <button type="button" className="btn compact" onClick={() => test(category, label)} aria-label={`Test ${label}`}>Test</button>
              <button type="button" className={`switch ${on ? "on" : ""}`} role="switch" aria-checked={on} aria-label={label} disabled={!all}
                onClick={() => void patch({ notificationCategories: { [category]: !on } })} />
            </li>
          );
        })}
      </ul>
      <Toggle label="Quiet hours" hint="No notifications in this time, except Call capture & readiness. They still wait in the bell." on={settings.quietHours} onChange={(v) => void patch({ quietHours: v })} />
      <div className="row organise-field">
        <label htmlFor={fromId} className="small muted">From</label>
        <input id={fromId} className="input" type="time" value={settings.quietFrom} disabled={!settings.quietHours} onChange={(e) => e.target.value && void patch({ quietFrom: e.target.value })} />
        <label htmlFor={toId} className="small muted">to</label>
        <input id={toId} className="input" type="time" value={settings.quietTo} disabled={!settings.quietHours} onChange={(e) => e.target.value && void patch({ quietTo: e.target.value })} />
      </div>
    </div>
  );
}
