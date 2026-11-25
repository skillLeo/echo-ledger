// Settings › App lock in the new look, with today's data and rules (SecuritySettings.tsx): the lock, when it locks,
// the PIN (set or change, typed twice) and Lock now. Recording keeps running while the app is locked.
import { useEffect, useState } from "react";
import { api, type LockAfterMinutes, type LockStatus, type Settings } from "../../../lib/ipc";
import { setting } from "../../../lib/settingsRegistry";
import { SYSTEM_LABEL } from "../../../views/main/LockScreen";
import { Button, Dialog, ListRow, TextField } from "../../components";
import { Group, ResetDefaults, SelectSetting, SwitchSetting, type Patch, type Run } from "./rows";
import { t } from "../../../i18n";

export function LockPageV2({ settings, patch, run }: { settings: Settings; patch: Patch; run: Run }) {
  const [lock, setLock] = useState<LockStatus | null>(null);
  const [pinOpen, setPinOpen] = useState(false);
  useEffect(() => {
    api.lockStatus().then(setLock).catch(() => undefined);
  }, []);
  const method = lock?.osMethod ? SYSTEM_LABEL[lock.osMethod].replace("Use ", "") : null;
  const footer = [
    method ? `${method} or your PIN unlocks it. Recording keeps running while it's locked.` : t("Your PIN unlocks it. Recording keeps running while it's locked."),
    lock && !lock.hasPin ? t("Set a PIN first. It unlocks the app whenever Windows Hello or Touch ID isn't available.") : null,
  ].filter(Boolean) as string[];
  return (
    <>
      <Group footer={<>{footer.map((l) => <p key={l}>{l}</p>)}</>}>
        <SwitchSetting id="lock.enabled" on={settings.appLock} onChange={(v) => void patch({ appLock: v }).then(async (ok) => { if (ok) setLock(await api.lockStatus()); })} />
        <SelectSetting id="lock.after" value={String(settings.lockAfterMinutes)} disabled={!settings.appLock}
          onChange={(v) => void patch({ lockAfterMinutes: Number(v) as LockAfterMinutes })} />
        <ListRow compact anchor="lock.pin" title={setting("lock.pin").title} value={lock?.hasPin ? t("Set") : t("Not set")} chevron onClick={() => setPinOpen(true)} />
        <ListRow compact anchor="lock.now" title={setting("lock.now").title} disabled={!settings.appLock} onClick={() => void run(() => api.lockNow())} />
      </Group>
      <ResetDefaults page={t("App lock")} onReset={() => void patch({ lockAfterMinutes: 5 as LockAfterMinutes })} />
      {pinOpen && lock && <PinDialogV2 hasPin={lock.hasPin} run={run} onSaved={setLock} onClose={() => setPinOpen(false)} />}
    </>
  );
}

/** Set or change the PIN: 4 to 12 digits, typed twice. */
function PinDialogV2({ hasPin, run, onSaved, onClose }: { hasPin: boolean; run: Run; onSaved: (s: LockStatus) => void; onClose: () => void }) {
  const [pin, setPin] = useState("");
  const [again, setAgain] = useState("");
  const mismatch = again.length > 0 && pin !== again;
  const digits = (v: string) => v.replace(/\D/g, "").slice(0, 12);
  const save = async () => {
    const next = await run(() => api.setLockPin(pin), hasPin ? t("PIN changed") : t("PIN set"));
    if (next) {
      onSaved(next);
      onClose();
    }
  };
  const title = hasPin ? t("Change PIN") : t("Set PIN");
  return (
    <Dialog open title={title} onClose={onClose}>
      <form className="el-form el-form--flush" onSubmit={(e) => { e.preventDefault(); if (pin.length >= 4 && pin === again) void save(); }}>
        <TextField label={t("PIN (4–12 digits)")} type="password" inputMode="numeric" autoComplete="new-password" value={pin} onChange={(e) => setPin(digits(e.target.value))} />
        <TextField label={t("Repeat the PIN")} type="password" inputMode="numeric" autoComplete="new-password" value={again} onChange={(e) => setAgain(digits(e.target.value))}
          error={mismatch ? t("The PINs differ.") : undefined} />
        <div className="el-dialog__actions el-dialog__actions--inline">
          <Button onClick={onClose}>{t("Cancel")}</Button>
          <Button type="submit" variant="primary" disabled={pin.length < 4 || pin !== again}>{title}</Button>
        </div>
      </form>
    </Dialog>
  );
}
