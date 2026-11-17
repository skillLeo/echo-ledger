// Settings › Profile in the new look, with today's data (AccountSettings.tsx): who is signed in, the email (and
// whether it is verified), the workspace and role on a team, and since when. Name and email are changed on the website.
import { api, type Account, type AppStatus } from "../../../lib/ipc";
import { rowDateWithYear } from "../../../lib/format";
import { setting } from "../../../lib/settingsRegistry";
import { workspaceLine } from "../../../views/main/AccountSettings";
import { Badge, ListRow } from "../../components";
import { Group, type Run } from "./rows";

export function ProfilePageV2({ account, status, run }: { account: Account; status: AppStatus; run: Run }) {
  const workspace = workspaceLine(account, status);
  const row = (id: string, value: string) => <ListRow compact anchor={id} title={setting(id).title} value={value} />;
  return (
    <Group footer={<p>Your name and email are changed on the website.</p>}>
      {row("profile.name", account.name || account.email.split("@")[0])}
      <ListRow compact anchor="profile.email" title={setting("profile.email").title} value={account.email}
        badge={account.email_verified ? undefined : <Badge tone="warning">Not verified</Badge>} />
      {workspace && row("profile.workspace", workspace)}
      {account.created_at && row("profile.since", rowDateWithYear(account.created_at))}
      <ListRow compact anchor="profile.edit" title={setting("profile.edit").title} label="Edit profile, opens in browser" meta="Opens the website" chevron
        onClick={() => void run(() => api.openServerPage("app/profile"))} />
    </Group>
  );
}
