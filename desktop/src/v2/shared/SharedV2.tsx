// Shared in the new design: the same page today's Shared is (views/main/SharedView.tsx, SharedWithMe.tsx and
// RequestLinks.tsx, the same commands and words): what teammates sent you, the links you made, and your request links,
// each a tab with its count. With me also holds what the Library's Shared with me folder holds: a recording opens
// read-only (SharedReader), Save to my library makes a copy here, Remove takes it off the list. A link can be revoked
// after asking; New request link opens the Request files sheet. Every row's ⋮ is always on screen (never on hover only)
// and its right-click is the same menu; a row press (or Enter on it) opens what it stands for. Times are number isolates
// so they read the right way round in Urdu. Built from v2 parts only.
import { useEffect, useMemo, useState, type KeyboardEvent, type MouseEvent, type ReactNode } from "react";
import { api, errorMessage, type ClientFolder, type LibraryItem, type RequestLink, type ShareLink, type SharedItem } from "../../lib/ipc";
import { audienceText, limitsText, linkState, receivedText, requestState, REVOKE_LINK_QUESTION, REVOKE_REQUEST_QUESTION, viewsText } from "../../lib/sharing";
import { formatWhen } from "../../lib/format";
import { recordingName } from "../../lib/library";
import { confirmAction } from "../../lib/confirm";
import { NO_TARGET, type Notify } from "../../lib/notice";
import { SHARED_TABS, type SharedTab } from "../../views/main/Sidebar";
import { Badge, Banner, Button, Card, EmptyState, Icons, List, ListRow, Loading, SkeletonRow, Tabs, type Tone } from "../components";
import { ContextMenu, Menu, menuPointFor, type MenuItem } from "../components/Menu";
import { SourceAvatar } from "../library/SourceAvatar";
import { SharedReader } from "./SharedReader";
import { sharedTitle } from "./model";
import { senderOf } from "../../views/main/SharedView";
import "./shared.css";
import { RequestLinkSheet } from "./RequestLinkSheet";

/** What each tab lists, read when the page opens; null while loading (a server without it gives an empty list). */
interface SharedData {
  withMe: SharedItem[] | null;
  links: ShareLink[] | null;
  requests: RequestLink[] | null;
}

const STATE_TONE: Record<"Active" | "Revoked" | "Expired", Tone> = { Active: "success", Revoked: "neutral", Expired: "neutral" };

type RowEvent = MouseEvent<HTMLElement> | KeyboardEvent<HTMLButtonElement>;

/** A time in a row's line, kept left-to-right as one piece inside right-to-left text. */
const when = (iso: string) => <span className="el-num">{formatWhen(iso)}</span>;

/** "Recording from Imran · Yesterday · 4:05 PM · “Please check the totals”", the time an isolate. */
export function sharedMeta(item: SharedItem): ReactNode {
  return <>{item.kind === "project" ? "Project" : "Recording"} from {senderOf(item)}{item.sent_at && <> · {when(item.sent_at)}</>}{item.note ? ` · “${item.note}”` : ""}</>;
}
/** "3 views · last viewed Today · 9:00 AM", as lib/sharing's viewsText, the time an isolate. */
const viewsLine = (link: ShareLink): ReactNode =>
  link.view_count && link.last_viewed_at ? <>{viewsText({ ...link, last_viewed_at: null })} · last viewed {when(link.last_viewed_at)}</> : viewsText(link);

export function SharedV2({ tab, onTab, notify, projects, focusId = null, onOpenRecording, onOpenProject, onSaved }: {
  tab: SharedTab;
  onTab: (tab: SharedTab) => void;
  notify: Notify;
  projects: readonly ClientFolder[] | null;
  /** An item of Shared with me to bring into view (an echoledger link to it was opened): a recording opens. */
  focusId?: string | null;
  onOpenRecording: (id: string) => void;
  onOpenProject: (id: string) => void;
  /** Saved: a recording (`session_id`) or a project (`client_id`) is now in this workspace. */
  onSaved?: (saved: { session_id?: string; client_id?: string }, item: SharedItem) => void;
}) {
  const [data, setData] = useState<SharedData>({ withMe: null, links: null, requests: null });
  const [library, setLibrary] = useState<LibraryItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [openId, setOpenId] = useState<string | null>(null);
  const [newRequest, setNewRequest] = useState(false);
  const [context, setContext] = useState<{ at: { x: number; y: number }; label: string; items: MenuItem[] } | null>(null);

  const load = () => {
    setError(null);
    api.sharedWithMe().then((r) => setData((d) => ({ ...d, withMe: r?.data ?? [] })), (e) => setError(errorMessage(e)));
    api.sharesOf(null, null).then((r) => setData((d) => ({ ...d, links: r?.data ?? [] })), (e) => setError(errorMessage(e)));
    loadRequests();
    api.library().then((l) => setLibrary(Array.isArray(l) ? l : [])).catch(() => undefined);
  };
  const loadRequests = () =>
    api.requestLinks().then((r) => setData((d) => ({ ...d, requests: r?.data ?? [] })), (e) => setError(errorMessage(e)));
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(load, []);

  // A link to one of them: a recording opens read-only, a project is shown selected in the list.
  useEffect(() => {
    if (!focusId || !data.withMe) return;
    const item = data.withMe.find((i) => i.id === focusId);
    if (item?.kind === "session" && item.session) setOpenId(item.id);
    else if (/^[\w-]+$/.test(focusId)) document.querySelector(`[data-row-id="${focusId}"]`)?.scrollIntoView?.({ block: "nearest" });
  }, [focusId, data.withMe]);

  const titleOf = useMemo(() => {
    const recordings = new Map(library.map((i) => [i.id, recordingName(i.title)]));
    const names = new Map((projects ?? []).map((p) => [p.id, p.name]));
    return (link: ShareLink) => (link.session_id ? recordings.get(link.session_id) ?? "A recording" : link.client_id ? `Project: ${names.get(link.client_id) ?? "a project"}` : "A link");
  }, [library, projects]);
  const projectName = (id: string | null) => (id ? (projects ?? []).find((p) => p.id === id)?.name ?? "A project" : "The Inbox");

  const save = async (item: SharedItem) => {
    if (busy) return;
    setBusy(item.id);
    try {
      const saved = await api.saveShared(item.id);
      setData((d) => ({ ...d, withMe: (d.withMe ?? []).map((i) => (i.id === item.id ? { ...i, saved_at: new Date().toISOString() } : i)) }));
      notify(item.kind === "project" ? `Saved ${item.title ?? "the project"} to your projects` : "Saved to your Library", false,
        saved?.client_id ? { kind: "project", id: saved.client_id } : saved?.session_id ? { kind: "recording", id: saved.session_id } : NO_TARGET);
      onSaved?.(saved ?? {}, item);
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(null);
    }
  };
  const remove = async (item: SharedItem) => {
    if (busy) return;
    setBusy(item.id);
    try {
      await api.removeShared(item.id);
      setData((d) => ({ ...d, withMe: (d.withMe ?? []).filter((i) => i.id !== item.id) }));
      if (openId === item.id) setOpenId(null);
      notify("Removed from Shared with me", false, NO_TARGET);
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(null);
    }
  };
  const revokeLink = async (link: ShareLink) => {
    if (!(await confirmAction(REVOKE_LINK_QUESTION))) return;
    try {
      await api.revokeShare(link.id);
      notify("Link revoked: it stops working at once", false, NO_TARGET);
      load();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  const revokeRequest = async (link: RequestLink) => {
    if (!(await confirmAction(REVOKE_REQUEST_QUESTION))) return;
    try {
      await api.revokeRequestLink(link.id);
      notify("Request link revoked", false, NO_TARGET);
      void loadRequests();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  const withMeMenu = (item: SharedItem): MenuItem[] => [
    ...(item.session ? [{ label: "Open", icon: Icons.TranscriptIcon, onPick: () => setOpenId(item.id) }] : []),
    ...(item.saved_at ? [] : [{ label: "Save to my library", icon: Icons.DownloadIcon, disabled: busy === item.id, onPick: () => void save(item) }]),
    { label: "Remove", icon: Icons.TrashIcon, danger: true, disabled: busy === item.id, onPick: () => void remove(item) },
  ];
  const openLink = (link: ShareLink) => (link.session_id ? onOpenRecording(link.session_id) : link.client_id ? onOpenProject(link.client_id) : undefined);
  const linkMenu = (link: ShareLink): MenuItem[] => [
    ...(link.session_id || link.client_id ? [{ label: "Open", icon: link.session_id ? Icons.TranscriptIcon : Icons.FolderIcon, onPick: () => openLink(link) }] : []),
    ...(linkState(link) === "Active" ? [{ label: "Revoke", icon: Icons.CloseIcon, danger: true, onPick: () => void revokeLink(link) }] : []),
  ];
  const requestMenu = (link: RequestLink): MenuItem[] =>
    requestState(link) === "Active" ? [{ label: "Revoke", icon: Icons.CloseIcon, danger: true, onPick: () => void revokeRequest(link) }] : [];
  const rowMenu = (label: string, items: MenuItem[]) => (e: RowEvent) => {
    if (items.length === 0) return;
    const at = "clientX" in e && (e.clientX || e.clientY) ? { x: e.clientX, y: e.clientY } : menuPointFor(e.currentTarget);
    setContext({ at, label, items });
  };
  const more = (label: string, items: () => MenuItem[]) => <Menu label={label} items={items} button={{ variant: "quiet", size: "sm", icon: Icons.MoreIcon }} />;

  const counts: Record<SharedTab, number | null> = {
    "with-me": data.withMe?.length ?? null,
    links: data.links ? data.links.filter((l) => linkState(l) === "Active").length : null,
    requests: data.requests ? data.requests.filter((l) => requestState(l) === "Active").length : null,
  };
  const loading = (list: unknown[] | null, label: string) => list === null && !error && (
    <Loading label={label}><Card><div className="el-group__rows">{[0, 1, 2].map((n) => <SkeletonRow key={n} />)}</div></Card></Loading>
  );

  const open = openId ? data.withMe?.find((i) => i.id === openId && i.session) ?? null : null;
  if (open) {
    return (
      <SharedReader item={open} busy={busy === open.id} notify={notify} onBack={() => setOpenId(null)}
        onSave={() => void save(open)} onRemove={() => void remove(open)} />
    );
  }

  const withMePanel = (
    <>
      {loading(data.withMe, "Loading what was shared with you…")}
      {data.withMe?.length === 0 && <EmptyState icon={Icons.UserIcon} title="Nothing sent to you yet" body="When a teammate sends you a recording or a project, it shows here." />}
      {data.withMe && data.withMe.length > 0 && (
        <Card>
          <List label="Shared with me" className="el-group__rows">
            {data.withMe.map((item) => {
              const title = sharedTitle(item);
              return (
                <ListRow key={item.id} rowId={item.id} title={title} meta={sharedMeta(item)} selected={item.id === focusId}
                  leading={item.session ? <SourceAvatar item={item.session} /> : undefined} icon={item.session ? undefined : Icons.FolderIcon}
                  endTop={item.saved_at ? "Saved" : undefined}
                  // A recording opens read-only; a project sent to you has nothing to open until it is saved: its menu opens.
                  onClick={item.session ? () => setOpenId(item.id) : rowMenu(`Actions for ${title}`, withMeMenu(item))}
                  onContextMenu={rowMenu(`Actions for ${title}`, withMeMenu(item))}
                  actions={more(`More for ${title}`, () => withMeMenu(item))} />
              );
            })}
          </List>
        </Card>
      )}
    </>
  );

  const linksPanel = (
    <>
      {loading(data.links, "Loading your links…")}
      {data.links?.length === 0 && <EmptyState icon={Icons.LinkIcon} title="No links yet" body="Share a recording or a project from its page; the link shows here with its views." />}
      {data.links && data.links.length > 0 && (
        <Card>
          <List label="My links" className="el-group__rows">
            {data.links.map((link) => {
              const state = linkState(link);
              const title = titleOf(link);
              const items = linkMenu(link);
              return (
                <ListRow key={link.id} rowId={link.id} icon={link.client_id && !link.session_id ? Icons.FolderIcon : Icons.LinkIcon} title={title}
                  meta={<>{audienceText(link)} · {viewsLine(link)}{link.expires_at && <> · until {when(link.expires_at)}</>}</>}
                  badge={<Badge tone={STATE_TONE[state]}>{state}</Badge>}
                  onClick={link.session_id || link.client_id ? () => openLink(link) : undefined}
                  onContextMenu={rowMenu(`Actions for ${title}`, items)}
                  actions={items.length > 0 ? more(`More for ${title}`, () => linkMenu(link)) : undefined} />
              );
            })}
          </List>
        </Card>
      )}
    </>
  );

  /** The request links' rows; in the sheet without the right-click menu (its ⋮ stays inside the sheet). */
  const requestRows = (list: RequestLink[], inSheet = false) => list.map((link) => {
    const state = requestState(link);
    const title = link.title || "Request link";
    const items = requestMenu(link);
    return (
      <ListRow key={link.id} rowId={link.id} icon={Icons.InboxIcon} title={title}
        meta={[`Into ${projectName(link.client_id)}`, receivedText(link.received), limitsText(link) || null].filter(Boolean).join(" · ")}
        endTop={link.expires_at ? <>{state === "Active" ? "Stops" : "Stopped"} {when(link.expires_at)}</> : "Never stops"}
        badge={<Badge tone={STATE_TONE[state]}>{state}</Badge>}
        onContextMenu={inSheet ? undefined : rowMenu(`Actions for ${title}`, items)}
        actions={items.length > 0 ? more(`More for ${title}`, () => requestMenu(link)) : undefined} />
    );
  });
  const requestsPanel = (
    <>
      {loading(data.requests, "Loading your request links…")}
      {data.requests?.length === 0 && <EmptyState icon={Icons.InboxIcon} title="No request links yet" body="A request link lets anyone send you voice notes and files, without an account." />}
      {data.requests && data.requests.length > 0 && <Card><List label="Request links" className="el-group__rows">{requestRows(data.requests)}</List></Card>}
    </>
  );

  return (
    <div className="el-page" data-v2="shared">
      <header className="el-page__head">
        <div className="el-page__titles">
          <h1 className="el-title-l">Shared</h1>
          <p className="el-secondary" dir="auto">What teammates sent you, the links you made, and links people use to send you files.</p>
        </div>
        <div className="el-page__actions">
          <Button variant="primary" icon={Icons.InboxIcon} onClick={() => setNewRequest(true)}>New request link</Button>
        </div>
      </header>

      {error && (
        <Banner tone="danger" icon={Icons.ErrorIcon} action={<Button size="sm" onClick={load}>Retry</Button>}>
          Couldn't load everything. {error}
        </Banner>
      )}

      <Tabs label="Shared" value={tab} onChange={onTab}
        tabs={SHARED_TABS.map(([value, label]) => ({ value, label: counts[value] === null ? label : `${label} · ${counts[value]}` }))}>
        {tab === "with-me" ? withMePanel : tab === "links" ? linksPanel : requestsPanel}
      </Tabs>

      {context && <ContextMenu label={context.label} items={context.items} at={context.at} onClose={() => setContext(null)} />}
      {newRequest && (
        <RequestLinkSheet projects={projects ?? []} notify={notify} requests={data.requests} renderRows={(list) => requestRows(list, true)}
          onMade={() => void loadRequests()} onClose={() => { setNewRequest(false); void loadRequests(); }} />
      )}
    </div>
  );
}
