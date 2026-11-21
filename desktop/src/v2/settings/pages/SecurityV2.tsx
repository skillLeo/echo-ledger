// Settings › Sign-in & security in the new look, with today's data and rules (AccountSettings.tsx,
// SecuritySettings.tsx): the password and two-step sign-in, Google sign-in, passkeys, the recent sign-in activity, and
// Sign out everywhere after asking. Since 2.0.0-l the password, two-step, Google and passkeys are changed here
// (views/main/AccountFlows.tsx, the same flows classic draws); a passkey is added on the website.
import { useEffect, useState } from "react";
import { api, type Account, type AppStatus } from "../../../lib/ipc";
import { formatWhen } from "../../../lib/format";
import { setting } from "../../../lib/settingsRegistry";
import { eventLine, useSecurityOverview } from "../../../views/main/SecuritySettings";
import { accountNative } from "../../../views/main/AccountSettings";
import { AccountFlow, offers, ROWS, useFlows, useSecurityInfo } from "../../../views/main/AccountFlows";
import { Button, Card, Dialog, EmptyState, Icons, List, ListRow } from "../../components";
import { V2_KIT } from "../accountKit";
import { Group, LinkSetting, type Run } from "./rows";

export function SecurityPageV2({ account: given, run, onSignedOut, onActivity }: { account: Account; run: Run; onSignedOut: (s: AppStatus) => void; onActivity: () => void }) {
  const [account, setAccount] = useState(given);
  useEffect(() => setAccount(given), [given]);
  const [confirming, setConfirming] = useState(false);
  const native = accountNative(account);
  const { info, reload } = useSecurityInfo();
  const flows = useFlows((patch) => setAccount((a) => ({ ...a, ...patch })), reload);
  const done = (message: string, patch?: Partial<Account>) => {
    flows.done(message, patch);
    void run(() => Promise.resolve(), message);
  };
  const web = () => void run(() => api.openServerPage("app/profile"));
  const signOutEverywhere = async () => {
    setConfirming(false);
    const signedOut = await run(api.signOutEverywhere, "Signed out everywhere");
    if (signedOut) onSignedOut(signedOut);
  };
  const o = offers(account, native ? info : null);
  const title = (id: string) => setting(id).title;
  return (
    <>
      <Group footer={<p>Sign-in codes go to {account.email}.{native ? "" : " The password and two-step sign-in are changed on the website."}</p>}>
        {native ? (
          <>
            <ListRow compact anchor="security.password" title={title("security.password")} label={o.hasPassword ? ROWS.password : ROWS.setPassword}
              value={o.hasPassword ? "Change" : "Not set"} chevron onClick={() => flows.open({ kind: "password" })} />
            <ListRow compact anchor="security.two_step" title={title("security.two_step")} label={o.twoStep ? ROWS.twoStepOff : ROWS.twoStepOn}
              value={o.twoStep ? "On" : "Off"} chevron onClick={() => flows.open({ kind: o.twoStep ? "two_step_off" : "two_step_on" })} />
            {o.twoStep && (
              <ListRow compact title="Recovery codes" label={ROWS.recoveryCodes}
                value={o.recoveryLeft != null ? `${o.recoveryLeft} left` : "Make new"} chevron onClick={() => flows.open({ kind: "recovery_codes" })} />
            )}
            {o.google
              ? <ListRow compact anchor="security.google" title={title("security.google")} label={ROWS.google} value="Connected" chevron onClick={() => flows.open({ kind: "google" })} />
              : <ListRow compact anchor="security.google" title={title("security.google")} value="Not connected" />}
          </>
        ) : (
          <>
            <ListRow compact anchor="security.password" title={title("security.password")} label="Password, opens in browser" meta="Opens the website" chevron onClick={web} />
            <ListRow compact anchor="security.two_step" title={title("security.two_step")} label="Two-step sign-in, opens in browser" meta="Opens the website" value={account.two_factor ? "On" : "Off"} chevron onClick={web} />
            <ListRow compact anchor="security.google" title={title("security.google")} value={account.google ? "Connected" : "Not connected"} />
          </>
        )}
        <LinkSetting id="security.activity" onOpen={onActivity} />
      </Group>
      {native && (
        <Group title="Passkeys" footer={<p>Passkeys sign you in with your phone, fingerprint or face. Add one on the website.</p>}>
          {o.passkeys.map((p) => (
            <ListRow key={p.id} compact icon={Icons.LockIcon} iconStyle="plain" title={p.name}
              meta={p.last_used_at ? `Last used ${formatWhen(p.last_used_at)}` : "Not used yet"}
              control={<span className="el-row__buttons">
                <Button size="sm" variant="quiet" aria-label={ROWS.renamePasskey(p.name)} onClick={() => flows.open({ kind: "rename_passkey", passkey: p })}>Rename</Button>
                <Button size="sm" variant="quiet" aria-label={ROWS.removePasskey(p.name)} onClick={() => flows.open({ kind: "remove_passkey", passkey: p })}>Remove</Button>
              </span>} />
          ))}
          <ListRow compact anchor="security.passkeys" title={title("security.passkeys")} label={ROWS.addPasskey} meta="Opens the website" chevron
            onClick={() => void run(() => api.openServerPage("app/settings/security/passkeys"))} />
        </Group>
      )}
      <Group>
        <ListRow compact anchor="security.signout_all" title={title("security.signout_all")}
          control={<Button size="sm" variant="danger" icon={Icons.SignOutIcon} onClick={() => setConfirming(true)}>Sign out everywhere</Button>} />
      </Group>
      {confirming && (
        <Dialog open alert title="Sign out everywhere?" description="You'll be signed out on every phone, computer and browser, including this one." onClose={() => setConfirming(false)}
          actions={<><Button onClick={() => setConfirming(false)}>Cancel</Button><Button variant="danger" onClick={() => void signOutEverywhere()}>Sign out everywhere</Button></>} />
      )}
      {flows.flow && <AccountFlow kit={V2_KIT} flow={flows.flow} account={account} onClose={flows.close} onDone={done} />}
    </>
  );
}

/** The last 15 sign-in events. */
export function SignInActivityPageV2() {
  const { events, error } = useSecurityOverview();
  return (
    <>
      <Card>
        {events.length === 0 ? (
          <EmptyState icon={Icons.ClockIcon} title="Nothing recorded yet" body="Sign-ins and sign-outs on your devices appear here." />
        ) : (
          <List label="Recent activity" className="el-group__rows">
            {events.slice(0, 15).map((e) => <ListRow key={e.id} compact title={eventLine(e)} meta={formatWhen(e.at)} />)}
          </List>
        )}
      </Card>
      {error && <p className="el-field__error" role="alert">{error}</p>}
    </>
  );
}
