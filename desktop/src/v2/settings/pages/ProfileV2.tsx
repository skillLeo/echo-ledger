// Settings › Profile in the new look, with today's data (AccountSettings.tsx): who is signed in, the email (and
// whether it is verified), the workspace and role on a team, and since when. Since 2.0.0-l the picture, name and email
// are changed here (views/main/AccountFlows.tsx, the same flows classic draws); an older server changes them on the web.
// UX-11: the profile is edited in the app, the website only for billing; so with the app's own flows there is no "Edit
// profile · Opens the website" (the language waits for BE-06's profile).
import { useEffect, useState } from "react";
import { api, type Account, type AppStatus } from "../../../lib/ipc";
import { rowDateWithYear } from "../../../lib/format";
import { setting } from "../../../lib/settingsRegistry";
import { accountNative, workspaceLine } from "../../../views/main/AccountSettings";
import { AccountFlow, choosePicture, ROWS, useAvatar, useFlows } from "../../../views/main/AccountFlows";
import { Avatar, Badge, ListRow } from "../../components";
import { initials } from "../initials";
import { V2_KIT } from "../accountKit";
import { Group, type Run } from "./rows";
import { t } from "../../../i18n";

export function ProfilePageV2({ account: given, status, run }: { account: Account; status: AppStatus; run: Run }) {
  const [account, setAccount] = useState(given);
  useEffect(() => setAccount(given), [given]);
  const workspace = workspaceLine(account, status);
  const avatar = useAvatar(account);
  const flows = useFlows((patch) => setAccount((a) => ({ ...a, ...patch })), () => undefined);
  const done = (message: string, patch?: Partial<Account>) => {
    flows.done(message, patch);
    void run(() => Promise.resolve(), message);
  };
  const picture = async () => {
    const answer = await choosePicture();
    if (!answer) return;
    if (answer.ok) {
      setAccount((a) => ({ ...a, ...answer.account }));
      void run(() => Promise.resolve(), answer.message);
    } else void run(() => Promise.reject(new Error(answer.message)));
  };
  const row = (id: string, value: string) => <ListRow compact anchor={id} title={setting(id).title} value={value} />;
  const name = account.name || account.email.split("@")[0];
  const native = accountNative(account);
  const badge = account.email_verified ? undefined : <Badge tone="warning">{t("Not verified")}</Badge>;
  return (
    <>
      <Group footer={native ? <p>{account.pending_email ? `Waiting for the code sent to ${account.pending_email}.` : t("Your teammates see your name and picture.")}</p>
        : <p>{t("Your name and email are changed on the website.")}</p>}>
        {native && (
          <ListRow compact anchor="profile.picture" title={setting("profile.picture").title} label={ROWS.picture} chevron onClick={() => void picture()}
            leading={<Avatar initials={initials(account.name, account.email)} size="list" photo={avatar} color={account.avatar_color} seed={account.email} />} />
        )}
        {native && account.avatar_version != null && (
          <ListRow compact title={ROWS.removePicture} label={ROWS.removePicture} onClick={() => flows.open({ kind: "remove_picture" })} />
        )}
        {native
          ? <ListRow compact anchor="profile.name" title={setting("profile.name").title} label={ROWS.name} value={name} chevron onClick={() => flows.open({ kind: "name" })} />
          : row("profile.name", name)}
        {native
          ? <ListRow compact anchor="profile.email" title={setting("profile.email").title} label={account.pending_email ? ROWS.pendingEmail : ROWS.email} value={account.email}
            badge={badge} chevron onClick={() => flows.open({ kind: "email" })} />
          : <ListRow compact anchor="profile.email" title={setting("profile.email").title} value={account.email} badge={badge} />}
        {workspace && row("profile.workspace", workspace)}
        {account.created_at && row("profile.since", rowDateWithYear(account.created_at))}
        {!native && (
          <ListRow compact anchor="profile.edit" title={setting("profile.edit").title} label={t("Edit profile, opens in browser")} meta={t("Opens the website")} chevron
            onClick={() => void run(() => api.openServerPage("app/profile"))} />
        )}
      </Group>
      {flows.flow && <AccountFlow kit={V2_KIT} flow={flows.flow} account={account} onClose={flows.close} onDone={done} />}
    </>
  );
}
