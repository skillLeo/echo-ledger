// Settings › Sign-in & security in the new look, with today's data and rules (AccountSettings.tsx,
// SecuritySettings.tsx): the password and two-step sign-in (changed on the website), Google sign-in, the recent
// sign-in activity, and Sign out everywhere after asking.
import { useState } from "react";
import { api, type Account, type AppStatus } from "../../../lib/ipc";
import { formatWhen } from "../../../lib/format";
import { setting } from "../../../lib/settingsRegistry";
import { eventLine, useSecurityOverview } from "../../../views/main/SecuritySettings";
import { Button, Card, Dialog, EmptyState, Icons, List, ListRow } from "../../components";
import { Group, LinkSetting, type Run } from "./rows";

export function SecurityPageV2({ account, run, onSignedOut, onActivity }: { account: Account; run: Run; onSignedOut: (s: AppStatus) => void; onActivity: () => void }) {
  const [confirming, setConfirming] = useState(false);
  const web = () => void run(() => api.openServerPage("app/profile"));
  const signOutEverywhere = async () => {
    setConfirming(false);
    const signedOut = await run(api.signOutEverywhere, "Signed out everywhere");
    if (signedOut) onSignedOut(signedOut);
  };
  return (
    <>
      <Group footer={<p>Sign-in codes go to {account.email}. The password and two-step sign-in are changed on the website.</p>}>
        <ListRow compact anchor="security.password" title={setting("security.password").title} label="Password, opens in browser" onClick={web} />
        <ListRow compact anchor="security.two_step" title={setting("security.two_step").title} label="Two-step sign-in, opens in browser" value={account.two_factor ? "On" : "Off"} onClick={web} />
        <ListRow compact anchor="security.google" title={setting("security.google").title} value={account.google ? "Connected" : "Not connected"} />
        <LinkSetting id="security.activity" onOpen={onActivity} />
      </Group>
      <Group>
        <ListRow compact anchor="security.signout_all" title={setting("security.signout_all").title}
          control={<Button size="sm" variant="danger" icon={Icons.SignOutIcon} onClick={() => setConfirming(true)}>Sign out everywhere</Button>} />
      </Group>
      {confirming && (
        <Dialog open alert title="Sign out everywhere?" description="You'll be signed out on every phone, computer and browser, including this one." onClose={() => setConfirming(false)}
          actions={<><Button onClick={() => setConfirming(false)}>Cancel</Button><Button variant="danger" onClick={() => void signOutEverywhere()}>Sign out everywhere</Button></>} />
      )}
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
