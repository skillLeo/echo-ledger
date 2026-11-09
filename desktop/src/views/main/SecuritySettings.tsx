// Settings › App › App lock, Notifications and Storage, and Account › Devices and Sign-in activity (v1.7 rows): the app
// lock with Windows Hello / Touch ID and a PIN, which notifications show and when, what the account stores, and the
// devices signed in to it.
import { useEffect, useId, useState } from "react";
import { api, errorMessage, type LockAfterMinutes, type LockStatus, type NotificationCategory, type SecurityDevice, type SecurityEvent, type Settings, type SettingsPatch, type StorageInfo } from "../../lib/ipc";
import { fileSize, formatWhen, timeRange } from "../../lib/format";
import { fillWidth } from "../../lib/motion";
import { NOTIFICATION_KINDS, setting } from "../../lib/settingsRegistry";
import { LucideLaptopIcon, LucideSmartphoneIcon } from "../../components/Icons";
import { Sheet } from "./ProjectDialogs";
import { ActionRow, EntityRow, InfoRow, ResetRow, Section, SelectRow, SwitchRow, rowAttrs } from "./SettingsControls";
import { SYSTEM_LABEL } from "./LockScreen";

type Run = <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
type Patch = (p: SettingsPatch) => Promise<boolean>;

/** "Signed in · Pixel 9" style line for one audit entry. */
export function eventLine(e: Pick<SecurityEvent, "summary" | "actor" | "device">): string {
  const who = e.actor === "device" ? e.device ?? "A device" : e.actor === "web" ? "Console" : "EchoLedger";
  return `${e.summary}, ${who}`;
}

/** The PIN dialog: 4–12 digits, twice. */
function PinDialog({ hasPin, run, onSaved, onClose }: { hasPin: boolean; run: Run; onSaved: (s: LockStatus) => void; onClose: () => void }) {
  const [pin, setPin] = useState("");
  const [again, setAgain] = useState("");
  const pinId = useId();
  const againId = useId();
  const mismatch = again.length > 0 && pin !== again;
  const digits = (v: string) => v.replace(/\D/g, "").slice(0, 12);
  const save = async () => {
    const next = await run(() => api.setLockPin(pin), hasPin ? "PIN changed" : "PIN set");
    if (next) {
      onSaved(next);
      onClose();
    }
  };
  return (
    <Sheet title={hasPin ? "Change PIN" : "Set PIN"} onClose={onClose}>
      <form className="column" onSubmit={(e) => { e.preventDefault(); if (pin.length >= 4 && pin === again) void save(); }}>
        <label htmlFor={pinId} className="small muted">PIN (4–12 digits)</label>
        <input id={pinId} className="input" type="password" inputMode="numeric" autoComplete="new-password" autoFocus value={pin} onChange={(e) => setPin(digits(e.target.value))} />
        <label htmlFor={againId} className="small muted">Repeat the PIN</label>
        <input id={againId} className="input" type="password" inputMode="numeric" autoComplete="new-password" value={again} onChange={(e) => setAgain(digits(e.target.value))} />
        {mismatch && <p className="small error-text" role="alert">The PINs differ.</p>}
        <div className="row sheet-actions">
          <span className="spacer" />
          <button type="button" className="btn ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn primary" disabled={pin.length < 4 || pin !== again}>{hasPin ? "Change PIN" : "Set PIN"}</button>
        </div>
      </form>
    </Sheet>
  );
}

/** App › App lock. */
export function LockPage({ settings, patch, run }: { settings: Settings; patch: Patch; run: Run }) {
  const [lock, setLock] = useState<LockStatus | null>(null);
  const [pinOpen, setPinOpen] = useState(false);
  useEffect(() => {
    api.lockStatus().then(setLock).catch(() => undefined);
  }, []);
  const method = lock?.osMethod ? SYSTEM_LABEL[lock.osMethod].replace("Use ", "") : null;
  const footer = [
    method ? `${method} or your PIN unlocks it. Recording keeps running while it's locked.` : "Your PIN unlocks it. Recording keeps running while it's locked.",
    lock && !lock.hasPin ? "Set a PIN first. It unlocks the app whenever Windows Hello or Touch ID isn't available." : null,
  ].filter(Boolean) as string[];
  return (
    <>
      <Section footer={<>{footer.map((l) => <p key={l}>{l}</p>)}</>}>
        <SwitchRow id="lock.enabled" on={settings.appLock} onChange={(v) => void patch({ appLock: v }).then(async (ok) => { if (ok) setLock(await api.lockStatus()); })} />
        <SelectRow id="lock.after" value={String(settings.lockAfterMinutes)} disabled={!settings.appLock}
          options={(setting("lock.after").options ?? []).map((o) => [o.value, o.label] as const)} onChange={(v) => void patch({ lockAfterMinutes: Number(v) as LockAfterMinutes })} />
        <ActionRow id="lock.pin" value={lock?.hasPin ? "Set" : "Not set"} onClick={() => setPinOpen(true)} />
        <ActionRow id="lock.now" disabled={!settings.appLock} onClick={() => void run(() => api.lockNow())} />
      </Section>
      <ResetRow page="App lock" onReset={() => void patch({ lockAfterMinutes: 5 as LockAfterMinutes })} />
      {pinOpen && lock && <PinDialog hasPin={lock.hasPin} run={run} onSaved={setLock} onClose={() => setPinOpen(false)} />}
    </>
  );
}

/** The notification kinds, in the phone's channel order (the label and what each is about). */
export const NOTIFICATION_CATEGORIES = NOTIFICATION_KINDS as readonly (readonly [NotificationCategory, string, string])[];

export const NOT_SHOWN = "The system didn't show it. Allow notifications for EchoLedger in your computer's settings.";
const isOn = (map: Partial<Record<NotificationCategory, boolean>> | undefined, c: NotificationCategory) => map?.[c] !== false;

/** The value Settings shows for Notifications: "On", "Off" or "Quiet 10 pm–7 am". */
export function notificationsValue(settings: Pick<Settings, "activityNotifications" | "quietHours" | "quietFrom" | "quietTo">): string {
  if (!settings.activityNotifications) return "Off";
  return settings.quietHours ? `Quiet ${timeRange(settings.quietFrom, settings.quietTo)}` : "On";
}

/** App › Notifications: one switch for them all, quiet hours, a switch per kind, sounds and one test. */
export function NotificationsPage({ settings, patch, run }: { settings: Settings; patch: Patch; run?: Run }) {
  const fromId = useId();
  const toId = useId();
  const all = settings.activityNotifications;
  const soundsOn = NOTIFICATION_CATEGORIES.some(([c]) => isOn(settings.notificationSounds, c));
  const test = () => {
    const send = () => api.notificationTest("other").then((shown) => {
      if (!shown) throw NOT_SHOWN;
    });
    void (run ? run(send, "Test notification sent") : send().catch(() => undefined));
  };
  return (
    <>
      <Section footer={<p>No notifications in quiet hours, except Call capture & readiness. They still wait in the bell.</p>}>
        <SwitchRow id="notifications.enabled" on={all} onChange={(v) => void patch({ activityNotifications: v })} />
        <SwitchRow id="notifications.sounds" on={soundsOn} disabled={!all}
          onChange={(v) => void patch({ notificationSounds: Object.fromEntries(NOTIFICATION_CATEGORIES.map(([c]) => [c, v])) })} />
        <SwitchRow id="notifications.quiet" on={settings.quietHours} disabled={!all} onChange={(v) => void patch({ quietHours: v })} />
        <div className={`set-row ${settings.quietHours && all ? "" : "disabled"}`.trim()} {...rowAttrs("notifications.quiet_from")}>
          <label htmlFor={fromId} className="set-title">From</label>
          <input id={fromId} className="input set-time" type="time" value={settings.quietFrom} disabled={!settings.quietHours || !all} onChange={(e) => e.target.value && void patch({ quietFrom: e.target.value })} />
        </div>
        <div className={`set-row ${settings.quietHours && all ? "" : "disabled"}`.trim()} {...rowAttrs("notifications.quiet_to")}>
          <label htmlFor={toId} className="set-title">To</label>
          <input id={toId} className="input set-time" type="time" value={settings.quietTo} disabled={!settings.quietHours || !all} onChange={(e) => e.target.value && void patch({ quietTo: e.target.value })} />
        </div>
      </Section>
      <Section title="Notify me about">
        <ul className="set-list" aria-label="Kinds of notification">
          {NOTIFICATION_CATEGORIES.map(([category]) => (
            <li key={category} data-testid={`notify-${category}`}>
              <SwitchRow id={`notifications.kind.${category}`} on={isOn(settings.notificationCategories, category)} disabled={!all}
                onChange={(v) => void patch({ notificationCategories: { [category]: v } })} />
            </li>
          ))}
        </ul>
      </Section>
      <Section>
        <ActionRow id="notifications.test" onClick={test} />
      </Section>
      <ResetRow page="Notifications" onReset={() => void patch({
        activityNotifications: true, quietHours: false,
        notificationCategories: Object.fromEntries(NOTIFICATION_CATEGORIES.map(([c]) => [c, true])),
        notificationSounds: Object.fromEntries(NOTIFICATION_CATEGORIES.map(([c]) => [c, true])),
      })} />
    </>
  );
}

/** "Storage" in rows: "120 MB of 1 GB", or what is used when there is no limit. */
export function storageValue(used: number | null | undefined, limit: number | null | undefined): string {
  if (used === null || used === undefined) return "";
  return limit ? `${fileSize(used)} of ${fileSize(limit)}` : fileSize(used);
}

/** App › Storage: what the account stores here, how long recordings are kept, and the Bin. */
export function StoragePage({ limitBytes = null }: { limitBytes?: number | null }) {
  const [info, setInfo] = useState<StorageInfo | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api.storage().then(setInfo, (e) => setError(errorMessage(e)));
  }, []);
  const percent = info && limitBytes ? Math.min(100, Math.round((info.recordings_bytes / limitBytes) * 100)) : null;
  const tone = percent === null ? "" : percent >= 100 ? "full" : percent >= 80 ? "warn" : "";
  return (
    <Section footer={<><p>Recordings are deleted after the time your plan keeps them, unless you keep them or their project does. Deleted recordings stay in the Bin for 2 days.</p>{error && <p>{error}</p>}</>}>
      <div className="set-row set-meter-row" {...rowAttrs("storage.used")}>
        <span className="set-line"><span className="set-title">Your storage</span><span className={`set-value ${tone}`}>{info ? storageValue(info.recordings_bytes, limitBytes) : "Checking…"}</span></span>
        {percent !== null && (
          <span className={`set-meter ${tone}`} role="meter" aria-label={`Storage, ${storageValue(info?.recordings_bytes, limitBytes)} used`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
            <span style={fillWidth(percent / 100)} />
          </span>
        )}
      </div>
      <InfoRow id="storage.count" value={info ? (info.recordings_count === 1 ? "1 recording" : `${info.recordings_count.toLocaleString("en-US")} recordings`) : ""} />
      <InfoRow id="storage.retention" value={info ? (info.retention_days >= 365 && info.retention_days % 365 === 0 ? (info.retention_days === 365 ? "1 year" : `${info.retention_days / 365} years`) : `${info.retention_days} days`) : ""} />
      <InfoRow id="storage.bin" value="2 days" />
    </Section>
  );
}

/** A device's platform in plain words (the server sends `windows`, `macos`, `android`…). */
export function platformLabel(platform: string): string {
  return ({ windows: "Windows", macos: "Mac", android: "Android phone", ios: "iPhone", linux: "Linux" } as Record<string, string>)[platform] ?? platform;
}

/** One device's new name, in a dialog. */
function RenameDialog({ device, run, onDone }: { device: SecurityDevice; run: Run; onDone: (renamed: boolean) => void }) {
  const [name, setName] = useState(device.name);
  const id = useId();
  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    const saved = await run(() => api.renameDevice(device.id, name.trim()), "Name saved");
    onDone(saved !== undefined);
  };
  return (
    <Sheet title={`Rename ${device.name}`} onClose={() => onDone(false)}>
      <form className="column" onSubmit={(e) => void save(e)}>
        <label htmlFor={id} className="small muted">Name</label>
        <input id={id} className="input" value={name} maxLength={64} autoFocus onChange={(e) => setName(e.target.value)} />
        <div className="row sheet-actions">
          <span className="spacer" />
          <button type="button" className="btn ghost" onClick={() => onDone(false)}>Cancel</button>
          <button type="submit" className="btn primary" disabled={!name.trim() || name.trim() === device.name}>Save</button>
        </div>
      </form>
    </Sheet>
  );
}

/** The devices and the recent sign-in activity, read once for both pages. */
export function useSecurityOverview(): { devices: SecurityDevice[]; events: SecurityEvent[]; error: string | null; reload: () => void } {
  const [devices, setDevices] = useState<SecurityDevice[]>([]);
  const [events, setEvents] = useState<SecurityEvent[]>([]);
  const [error, setError] = useState<string | null>(null);
  const reload = () =>
    void api.securityOverview().then((o) => {
      setDevices(o.devices ?? []);
      setEvents(o.events ?? []);
      setError(null);
    }, (e) => setError(errorMessage(e)));
  useEffect(reload, []);
  return { devices, events, error, reload };
}

/** Account › Devices: every device signed in to the account (rename any, sign the others out). */
export function DevicesPage({ run }: { run: Run }) {
  const { devices, error, reload } = useSecurityOverview();
  const [renaming, setRenaming] = useState<SecurityDevice | null>(null);
  const revoke = async (d: SecurityDevice) => {
    if (!window.confirm(`Sign out ${d.name}? It will need to sign in again.`)) return;
    const ok = await run(() => api.revokeDevice(d.id), `${d.name} signed out`);
    if (ok !== undefined) reload();
  };
  return (
    <>
      <Section footer={error ? <p>{error}</p> : undefined}>
        <div role="list" aria-label="Signed-in devices">
          {devices.map((d) => (
            <EntityRow key={d.id} id={`devices.item.${d.id}`} leading={d.platform === "android" || d.platform === "ios" ? <LucideSmartphoneIcon size={20} /> : <LucideLaptopIcon size={20} />}
              title={d.name} sub={d.current ? "This computer" : `${platformLabel(d.platform)}${d.last_seen_at ? `, active ${formatWhen(d.last_seen_at).toLowerCase()}` : ""}`}>
              <button type="button" className="btn ghost compact" aria-label={`Rename ${d.name}`} onClick={() => setRenaming(d)}>Rename</button>
              {!d.current && <button type="button" className="btn ghost compact danger" aria-label={`Sign out ${d.name}`} onClick={() => void revoke(d)}>Sign out</button>}
            </EntityRow>
          ))}
        </div>
      </Section>
      {renaming && <RenameDialog device={renaming} run={run} onDone={(renamed) => { setRenaming(null); if (renamed) reload(); }} />}
    </>
  );
}

/** Sign-in & security › Sign-in activity: the last 15 events. */
export function SignInActivityPage() {
  const { events, error } = useSecurityOverview();
  return (
    <Section footer={error ? <p>{error}</p> : undefined}>
      {events.length === 0 ? <div className="set-row"><span className="set-title muted">Nothing recorded yet.</span></div> : (
        <div role="list" aria-label="Recent activity">
          {events.slice(0, 15).map((e) => <EntityRow key={e.id} title={eventLine(e)} sub={formatWhen(e.at)} />)}
        </div>
      )}
    </Section>
  );
}

