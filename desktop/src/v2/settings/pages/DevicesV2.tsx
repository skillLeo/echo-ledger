// Settings › Devices in the new look, with today's data and rules (SecuritySettings.tsx): every phone and computer
// signed in to the account, this one first said as such; any can be renamed, the others signed out after asking.
import { useState } from "react";
import { api, type SecurityDevice } from "../../../lib/ipc";
import { confirmAction } from "../../../lib/confirm";
import { formatWhen } from "../../../lib/format";
import { platformLabel, useSecurityOverview } from "../../../views/main/SecuritySettings";
import { Button, Card, Dialog, Icons, List, ListRow, TextField } from "../../components";
import type { Run } from "./rows";

const isPhone = (d: SecurityDevice) => d.platform === "android" || d.platform === "ios";

/** "This computer"; "Android phone, active 2 hours ago". */
export function deviceLine(d: SecurityDevice): string {
  if (d.current) return "This computer";
  return `${platformLabel(d.platform)}${d.last_seen_at ? `, active ${formatWhen(d.last_seen_at).toLowerCase()}` : ""}`;
}

function RenameDialogV2({ device, run, onDone }: { device: SecurityDevice; run: Run; onDone: (renamed: boolean) => void }) {
  const [name, setName] = useState(device.name);
  const [busy, setBusy] = useState(false);
  const clean = name.trim();
  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!clean || clean === device.name) return;
    setBusy(true);
    const saved = await run(() => api.renameDevice(device.id, clean), "Name saved");
    setBusy(false);
    onDone(saved !== undefined);
  };
  return (
    <Dialog open title={`Rename ${device.name}`} onClose={() => { if (!busy) onDone(false); }}>
      <form className="el-form el-form--flush" onSubmit={(e) => void save(e)}>
        <TextField label="Name" value={name} maxLength={64} autoFocus onChange={(e) => setName(e.target.value)} />
        <div className="el-dialog__actions el-dialog__actions--inline">
          <Button onClick={() => onDone(false)} disabled={busy}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={!clean || clean === device.name} loading={busy}>Save</Button>
        </div>
      </form>
    </Dialog>
  );
}

export function DevicesPageV2({ run }: { run: Run }) {
  const { devices, error, reload } = useSecurityOverview();
  const [renaming, setRenaming] = useState<SecurityDevice | null>(null);
  const revoke = async (d: SecurityDevice) => {
    if (!(await confirmAction({ title: `Sign out ${d.name}?`, body: "It will need to sign in again.", confirm: "Sign out", tone: "danger" }))) return;
    const ok = await run(() => api.revokeDevice(d.id), `${d.name} signed out`);
    if (ok !== undefined) reload();
  };
  // This computer first, then the others as the server lists them.
  const ordered = [...devices.filter((d) => d.current), ...devices.filter((d) => !d.current)];
  return (
    <>
      <section className="el-group">
        <Card>
          <List label="Signed-in devices" className="el-group__rows">
              {ordered.map((d) => (
                <ListRow key={d.id} compact anchor={`devices.item.${d.id}`} icon={isPhone(d) ? Icons.DeviceIcon : Icons.LaptopIcon} iconStyle="plain"
                  title={d.name} meta={deviceLine(d)}
                  control={<span className="el-setting__actions">
                    <Button variant="quiet" size="sm" aria-label={`Rename ${d.name}`} onClick={() => setRenaming(d)}>Rename</Button>
                    {!d.current && <Button variant="quiet" size="sm" className="el-button--danger-text" aria-label={`Sign out ${d.name}`} onClick={() => void revoke(d)}>Sign out</Button>}
                  </span>} />
              ))}
          </List>
        </Card>
        {error && <div className="el-group__note el-caption"><p className="el-field__error" role="alert">{error}</p></div>}
      </section>
      {renaming && <RenameDialogV2 device={renaming} run={run} onDone={(renamed) => { setRenaming(null); if (renamed) reload(); }} />}
    </>
  );
}
