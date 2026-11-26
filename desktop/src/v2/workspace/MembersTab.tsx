// A project's Members (UX-09): who can open it. A project is private to the person who made it (docs/API.md
// "Workspaces, roles and privacy"): nobody else in the workspace sees it until it is shared. So its members are you;
// the teammates it was sent to (docs/API.md "Sending to a teammate": they open it, read-only and kept current, until you
// stop sharing), and for a folder the teammates the projects it sits in were sent to, as a send holds its folders; and
// whoever its open share links let in. Its links are listed with how often they were viewed. Share opens the share
// sheet at Share link; Send to a teammate…, at Send to a teammate. Its words are the window's language (D-261); people's
// names and emails and the project's name are their own.
import { useEffect, useState } from "react";
import { api, errorMessage, type SentItem, type ShareLink } from "../../lib/ipc";
import { audienceText, viewsText } from "../../lib/sharing";
import { formatWhen } from "../../lib/format";
import { isolate, isRtl, lineDir, t, useLanguage } from "../../i18n";
import { useAccount } from "../../views/main/AccountSettings";
import { Avatar, Button, Card, EmptyState, Icons, List, ListRow, Loading, SkeletonRow } from "../components";
import { initials } from "../settings/initials";

/** A teammate it was sent to: the send's own project (null: this one) and the person. */
export type Sent = { item: SentItem; from: { id: string; name: string } | null };

/** Why each person can open it: sent (of this project, or of one it sits in) or a link made for them; one row each. */
export function memberRows(links: readonly ShareLink[], sent: readonly Sent[]): { key: string; who: string; email: string | null; how: string }[] {
  const people = new Map<string, { who: string; email: string | null; ways: string[] }>();
  const add = (email: string | null, name: string | null, way: string) => {
    const key = (email ?? name ?? "").toLowerCase();
    if (!key) return;
    const row = people.get(key) ?? { who: name || email || "", email, ways: [] };
    if (!row.ways.includes(way)) row.ways.push(way);
    if (name && row.who === email) row.who = name;
    people.set(key, row);
  };
  for (const { item, from } of sent) {
    add(item.to?.email ?? null, item.to?.name ?? null, from ? t("Sent with :name · can open it until you stop sharing", { name: from.name }) : t("Sent to them · can open it until you stop sharing"));
  }
  for (const link of links.filter((l) => l.active && l.audience === "people")) {
    for (const email of link.people ?? []) add(email, null, t("Through a share link"));
  }
  return [...people.entries()].map(([key, p]) => ({ key, who: p.who, email: p.who !== p.email ? p.email : null, how: p.ways.join(t("workspace_context.ways_separator")) }));
}

/** An email before the app's words: in its own isolate in a right-to-left line. */
const own = (value: string) => (isRtl() ? isolate(value) : value);

/** A line of the app's own words around a name, read in its own words' direction (dir="auto" in English, as before). */
const Said = ({ children }: { children: string }) => <span dir={lineDir(children)}>{children}</span>;

export function MembersTab({ projectId, projectName, within = [], onShare, onSend }: {
  projectId: string;
  projectName: string;
  /** The projects it sits in, nearest last (a send of one of them holds this folder too). */
  within?: readonly { id: string; name: string }[];
  onShare?: () => void;
  onSend?: () => void;
}) {
  useLanguage();
  const [links, setLinks] = useState<ShareLink[] | "legacy" | null>(null);
  const [sent, setSent] = useState<Sent[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const [account] = useAccount();
  const parents = within.map((p) => `${p.id}:${p.name}`).join("|");
  useEffect(() => {
    let live = true;
    setError(null);
    api.sharesOf(null, projectId).then((r) => live && setLinks(r ? r.data : "legacy"), (e) => live && setError(errorMessage(e)));
    // This project's sends, and those of the projects it sits in.
    const owners = [{ id: projectId, from: null }, ...within.map((p) => ({ id: p.id, from: p }))];
    Promise.all(owners.map(({ id, from }) => api.sentOf(null, id).then((r) => (r?.data ?? []).map((item) => ({ item, from })))))
      .then((all) => live && setSent(all.flat()), (e) => live && setError(errorMessage(e)));
    return () => {
      live = false;
    };
    // `parents` stands for `within`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId, parents, attempt]);
  if (error) {
    return <EmptyState tone="error" icon={Icons.ErrorIcon} title={t("Who can open it didn't load")} body={error} action={<Button onClick={() => setAttempt((n) => n + 1)}>{t("Try again")}</Button>} />;
  }
  if (links === null || sent === null) {
    return <Loading label={t("Loading who can open it")}><Card><div className="el-group__rows"><SkeletonRow end={false} /><SkeletonRow end={false} /></div></Card></Loading>;
  }
  const open = links === "legacy" ? [] : links.filter((l) => l.active);
  const people = memberRows(open, sent);
  const anyone = open.filter((l) => l.audience !== "people");
  const shared = people.length > 0 || open.length > 0;
  const me = account?.name || account?.email || "";
  const note = links === "legacy" && !sent.length ? t("Sharing a project needs a newer EchoLedger server.")
    : shared ? t("Only you and these can open :name. Change who can from Share.", { name: projectName })
      : t("Only you can open :name: a project is private until you share it, even in a team.", { name: projectName });
  return (
    <div className="el-workspace-tab" aria-label={t("Members")}>
      <section className="el-group" aria-labelledby="el-members-title">
        <h2 className="el-caption el-body-strong el-group__title" id="el-members-title">{t("Who can open it")}</h2>
        <Card>
          <List label={t("Members")} className="el-group__rows">
            <ListRow compact title={<Said>{me ? t(":name (you)", { name: me }) : t("You")}</Said>} meta={t("Created by you")}
              leading={<Avatar initials={initials(account?.name ?? "", account?.email ?? "")} size="sm" seed={account?.email ?? "you"} />} />
            {people.map((m) => (
              <ListRow key={m.key} compact title={<span dir="auto">{m.who}</span>} meta={<Said>{m.email ? `${own(m.email)} · ${m.how}` : m.how}</Said>}
                leading={<Avatar initials={initials(m.email ? m.who : "", m.email ?? m.who)} size="sm" seed={m.email ?? m.who} />} />
            ))}
            {anyone.length > 0 && <ListRow compact icon={Icons.LinkIcon} iconStyle="plain" title={audienceText(anyone[0])} meta={t("Through a share link")} />}
          </List>
        </Card>
        <p className="el-group__note el-caption el-secondary" dir={lineDir(note)}>{note}</p>
      </section>
      {open.length > 0 && (
        <section className="el-group" aria-labelledby="el-members-links">
          <h2 className="el-caption el-body-strong el-group__title" id="el-members-links">{t("Share links")}</h2>
          <Card>
            <List label={t("Share links")} className="el-group__rows">
              {open.map((link) => (
                <ListRow key={link.id} compact icon={Icons.LinkIcon} iconStyle="plain" title={<Said>{audienceText(link)}</Said>}
                  meta={[viewsText(link), link.expires_at ? t("until :date", { date: formatWhen(link.expires_at) }) : null].filter(Boolean).join(" · ")} />
              ))}
            </List>
          </Card>
        </section>
      )}
      {links !== "legacy" && (onShare || onSend) && (
        <div className="el-page__actions el-members__actions">
          {onShare && <Button icon={Icons.ShareIcon} onClick={onShare}>{t("Share")}</Button>}
          {onSend && <Button icon={Icons.SendIcon} title={t("They can open it, read-only and kept current, until you stop sharing")} onClick={onSend}>{t("Send to a teammate…")}</Button>}
        </div>
      )}
    </div>
  );
}
