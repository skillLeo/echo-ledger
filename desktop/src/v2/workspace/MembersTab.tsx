// A project's Members (UX-09): who can open it. A project is private to the person who made it (docs/API.md
// "Workspaces, roles and privacy"): nobody else in the workspace sees it until it is shared. So its members are you, and
// whoever its open share links let in: the people a link was made for, or anyone with the link (and its password).
// Share opens the share sheet at Share link; Send a copy, at Send to a teammate (a copy in their library, not a member).
import { useEffect, useState } from "react";
import { api, errorMessage, type ShareLink } from "../../lib/ipc";
import { audienceText, viewsText } from "../../lib/sharing";
import { formatWhen } from "../../lib/format";
import { useAccount } from "../../views/main/AccountSettings";
import { Avatar, Button, Card, EmptyState, Icons, List, ListRow, Loading, SkeletonRow } from "../components";
import { initials } from "../settings/initials";

/** Who an open link lets in: one row per person it was made for, else one for anyone with it. */
export function memberRows(links: readonly ShareLink[]): { key: string; who: string; how: string; person: boolean }[] {
  const rows: { key: string; who: string; how: string; person: boolean }[] = [];
  for (const link of links.filter((l) => l.active)) {
    const how = [viewsText(link), link.expires_at ? `until ${formatWhen(link.expires_at)}` : null].filter(Boolean).join(" · ");
    if (link.audience === "people" && link.people?.length) {
      for (const email of link.people) rows.push({ key: `${link.id}:${email}`, who: email, how: `Through a share link · ${how}`, person: true });
    } else {
      rows.push({ key: link.id, who: audienceText(link), how, person: false });
    }
  }
  return rows;
}

export function MembersTab({ projectId, projectName, onShare, onSend }: {
  projectId: string;
  projectName: string;
  onShare?: () => void;
  onSend?: () => void;
}) {
  const [links, setLinks] = useState<ShareLink[] | "legacy" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const [account] = useAccount();
  useEffect(() => {
    let live = true;
    setError(null);
    api.sharesOf(null, projectId).then((r) => live && setLinks(r ? r.data : "legacy"), (e) => live && setError(errorMessage(e)));
    return () => {
      live = false;
    };
  }, [projectId, attempt]);
  const actions = (onShare || onSend) && (
    <div className="el-page__actions el-members__actions">
      {onShare && <Button icon={Icons.ShareIcon} onClick={onShare}>Share…</Button>}
      {onSend && <Button icon={Icons.SendIcon} title="A copy in their library; they don't join the project" onClick={onSend}>Send a copy to a teammate…</Button>}
    </div>
  );
  if (error) {
    return <EmptyState tone="error" icon={Icons.ErrorIcon} title="Who can open it didn't load" body={error} action={<Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />;
  }
  if (links === null) {
    return <Loading label="Loading who can open it"><Card><div className="el-group__rows"><SkeletonRow end={false} /><SkeletonRow end={false} /></div></Card></Loading>;
  }
  const others = links === "legacy" ? [] : memberRows(links);
  const me = account?.name || account?.email || "You";
  return (
    <div className="el-workspace-tab" aria-label="Members">
      <section className="el-group" aria-labelledby="el-members-title">
        <h2 className="el-caption el-body-strong el-group__title" id="el-members-title">Who can open it</h2>
        <Card>
          <List label="Members" className="el-group__rows">
            <ListRow compact title={<span dir="auto">{account ? `${me} (you)` : "You"}</span>} meta="Made it · owner"
              leading={<Avatar initials={initials(account?.name ?? "", account?.email ?? "")} size="sm" seed={account?.email ?? "you"} />} />
            {others.map((m) => (
              <ListRow key={m.key} compact title={<span dir="auto">{m.who}</span>} meta={m.how}
                leading={m.person ? <Avatar initials={initials("", m.who)} size="sm" seed={m.who} /> : undefined}
                icon={m.person ? undefined : Icons.LinkIcon} iconStyle={m.person ? undefined : "plain"} />
            ))}
          </List>
        </Card>
        <p className="el-group__note el-caption el-secondary" dir="auto">
          {links === "legacy" ? "Sharing a project needs a newer EchoLedger server."
            : others.length ? `Only you and these can open ${projectName}. Revoke a link from Share.`
              : `Only you can open ${projectName}: a project is private until you share it, even in a team.`}
        </p>
      </section>
      {links !== "legacy" && actions}
    </div>
  );
}
