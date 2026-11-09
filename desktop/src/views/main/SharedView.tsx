// Shared (v1.6, P4.1): what teammates sent you, the links you made, and your request links, each a tab with its count.
// Opening something sent to you goes to the Library's Shared with me (play, read, Save to my library); links can be
// revoked here; a new request link opens the Request files sheet.
import { useEffect, useMemo, useState } from "react";
import { api, errorMessage, type ClientFolder, type LibraryItem, type RequestLink, type ShareLink, type SharedItem } from "../../lib/ipc";
import { audienceText, linkState, limitsText, receivedText, requestState, viewsText } from "../../lib/sharing";
import { formatWhen } from "../../lib/format";
import { LinkIcon, PlusIcon, RequestFilesIcon, UsersIcon } from "../../components/Icons";
import { PageHeader, PageTabs } from "../../components/Page";
import { RequestLinksSheet } from "./RequestLinks";
import { SHARED_TABS, type SharedTab } from "./Sidebar";

/** What each tab lists, read when the page opens; null while loading, and on a server without it. */
interface SharedData {
  withMe: SharedItem[] | null;
  links: ShareLink[] | null;
  requests: RequestLink[] | null;
}

/** Who sent it: the name, else the email, else "A teammate". */
export function senderOf(item: Pick<SharedItem, "from">): string {
  return item.from?.name || item.from?.email || "A teammate";
}

export function SharedView({ tab, onTab, notify, projects, onOpenShared, onOpenRecording, onOpenProject }: {
  tab: SharedTab;
  onTab: (tab: SharedTab) => void;
  notify: (text: string, error?: boolean) => void;
  projects: readonly ClientFolder[] | null;
  /** Opens an item of Shared with me in the Library. */
  onOpenShared: (id: string) => void;
  onOpenRecording: (id: string) => void;
  onOpenProject: (id: string) => void;
}) {
  const [data, setData] = useState<SharedData>({ withMe: null, links: null, requests: null });
  const [library, setLibrary] = useState<LibraryItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [newRequest, setNewRequest] = useState(false);
  const load = () => {
    setError(null);
    api.sharedWithMe().then((r) => setData((d) => ({ ...d, withMe: r?.data ?? [] })), (e) => setError(errorMessage(e)));
    api.sharesOf(null, null).then((r) => setData((d) => ({ ...d, links: r?.data ?? [] })), (e) => setError(errorMessage(e)));
    api.requestLinks().then((r) => setData((d) => ({ ...d, requests: r?.data ?? [] })), (e) => setError(errorMessage(e)));
    api.library().then((l) => setLibrary(Array.isArray(l) ? l : [])).catch(() => undefined);
  };
  useEffect(load, []);
  const titleOf = useMemo(() => {
    const recordings = new Map(library.map((i) => [i.id, i.title ?? "Recording"]));
    const names = new Map((projects ?? []).map((p) => [p.id, p.name]));
    return (link: ShareLink) => (link.session_id ? recordings.get(link.session_id) ?? "A recording" : link.client_id ? `Project: ${names.get(link.client_id) ?? "a project"}` : "A link");
  }, [library, projects]);
  const projectName = (id: string | null) => (id ? (projects ?? []).find((p) => p.id === id)?.name ?? "A project" : "The Inbox");

  const revokeLink = async (link: ShareLink) => {
    try {
      await api.revokeShare(link.id);
      notify("Link revoked: it stops working at once");
      load();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  const revokeRequest = async (link: RequestLink) => {
    try {
      await api.revokeRequestLink(link.id);
      notify("Request link revoked");
      load();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  const counts: Record<SharedTab, number | null> = {
    "with-me": data.withMe?.length ?? null,
    links: data.links ? data.links.filter((l) => linkState(l) === "Active").length : null,
    requests: data.requests ? data.requests.filter((l) => requestState(l) === "Active").length : null,
  };
  const loading = (list: unknown[] | null) => list === null && !error && <div className="empty" aria-busy="true">Loading…</div>;
  return (
    <div className="shared-page">
      <PageHeader crumbs={[{ label: "Shared" }]} title="Shared" subtitle="What teammates sent you, the links you made, and links people use to send you files."
        actions={<button type="button" className="btn primary" onClick={() => setNewRequest(true)}><PlusIcon size={16} />New request link</button>}>
        <PageTabs label="Shared" value={tab} onChange={onTab} tabs={SHARED_TABS.map(([id, label]) => ({ id, label, count: counts[id] }))} />
      </PageHeader>
      {error && (
        <div className="notice error sync-error" role="alert">
          <span>Couldn't load everything. {error}</span>
          <button type="button" className="btn compact" onClick={load}>Retry</button>
        </div>
      )}
      <div role="tabpanel" aria-label={SHARED_TABS.find(([id]) => id === tab)?.[1]}>
        {tab === "with-me" && (
          <>
            {loading(data.withMe)}
            {data.withMe?.length === 0 && <Empty icon={<UsersIcon size={26} />} title="Nothing sent to you yet" text="When a teammate sends you a recording or a project, it shows here." />}
            {data.withMe && data.withMe.length > 0 && (
              <ul className="data-list card">
                {data.withMe.map((item) => (
                  <li key={item.id} className="data-row">
                    <div className="data-main">
                      <span className="data-title" dir="auto">{item.title ?? item.session?.title ?? item.client?.name ?? "Shared item"}</span>
                      <span className="small muted">{item.kind === "project" ? "Project" : "Recording"} from {senderOf(item)}{item.sent_at ? ` · ${formatWhen(item.sent_at)}` : ""}{item.note ? ` · “${item.note}”` : ""}</span>
                    </div>
                    {item.saved_at && <span className="badge">Saved to my library</span>}
                    <div className="row-actions">
                      <button type="button" className="btn compact" onClick={() => onOpenShared(item.id)}>Open</button>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
        {tab === "links" && (
          <>
            {loading(data.links)}
            {data.links?.length === 0 && <Empty icon={<LinkIcon size={26} />} title="No links yet" text="Share a recording or a project from its page; the link shows here with its views." />}
            {data.links && data.links.length > 0 && (
              <ul className="data-list card">
                {data.links.map((link) => {
                  const state = linkState(link);
                  return (
                    <li key={link.id} className={`data-row ${state === "Active" ? "" : "off"}`}>
                      <div className="data-main">
                        <span className="data-title" dir="auto">{titleOf(link)}</span>
                        <span className="small muted">{audienceText(link)} · {viewsText(link)}{link.expires_at ? ` · until ${formatWhen(link.expires_at)}` : ""}</span>
                      </div>
                      <span className={`badge state-${state.toLowerCase()}`}>{state}</span>
                      <div className="row-actions">
                        {link.session_id && <button type="button" className="btn ghost compact" onClick={() => onOpenRecording(link.session_id!)}>Open</button>}
                        {link.client_id && <button type="button" className="btn ghost compact" onClick={() => onOpenProject(link.client_id!)}>Open</button>}
                        {state === "Active" && <button type="button" className="btn ghost compact danger" onClick={() => void revokeLink(link)}>Revoke</button>}
                      </div>
                    </li>
                  );
                })}
              </ul>
            )}
          </>
        )}
        {tab === "requests" && (
          <>
            {loading(data.requests)}
            {data.requests?.length === 0 && <Empty icon={<RequestFilesIcon size={26} />} title="No request links yet" text="A request link lets anyone send you voice notes and files, without an account." />}
            {data.requests && data.requests.length > 0 && (
              <ul className="data-list card">
                {data.requests.map((link) => {
                  const state = requestState(link);
                  return (
                    <li key={link.id} className={`data-row ${state === "Active" ? "" : "off"}`}>
                      <div className="data-main">
                        <span className="data-title" dir="auto">{link.title ?? "Request link"}</span>
                        <span className="small muted">Into {projectName(link.client_id)} · {receivedText(link.received)} · {limitsText(link)}</span>
                      </div>
                      <span className={`badge state-${state.toLowerCase()}`}>{state}</span>
                      <div className="row-actions">
                        {state === "Active" && <button type="button" className="btn ghost compact danger" onClick={() => void revokeRequest(link)}>Revoke</button>}
                      </div>
                    </li>
                  );
                })}
              </ul>
            )}
          </>
        )}
      </div>
      {newRequest && <RequestLinksSheet projects={projects ?? []} notify={notify} onClose={() => { setNewRequest(false); load(); }} />}
    </div>
  );
}

function Empty({ icon, title, text }: { icon: React.ReactNode; title: string; text: string }) {
  return (
    <div className="empty-state">
      <span className="empty-icon">{icon}</span>
      <span className="empty-title">{title}</span>
      <p>{text}</p>
    </div>
  );
}
