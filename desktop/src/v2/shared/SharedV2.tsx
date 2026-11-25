// Shared in the new design: the same page today's Shared is (views/main/SharedView.tsx, SharedWithMe.tsx and
// RequestLinks.tsx, the same commands and words): what teammates sent you, the links you made, and your request links,
// each a tab with its count. With me also holds what the Library's Shared with me folder holds: a recording opens
// read-only (SharedReader), Save to my library makes a copy here, Remove takes it off the list. A link can be revoked
// after asking; New request link opens the Request files sheet. Every row's ⋮ is always on screen (never on hover only)
// and its right-click is the same menu; a row press (or Enter on it) opens what it stands for. Times are number isolates
// so they read the right way round in Urdu. Built from v2 parts only. Its words are in the account's language (useT).
import { useEffect, useMemo, useRef, useState, type KeyboardEvent, type MouseEvent, type ReactNode } from "react";
import { api, errorMessage, type ClientFolder, type LibraryItem, type RequestLink, type ShareLink, type SharedItem } from "../../lib/ipc";
import { audienceText, limitsText, linkStatus, receivedText, requestStatus, revokeLinkQuestion, revokeRequestQuestion, statusText, viewsText, type LinkStatus } from "../../lib/sharing";
import { confirmAction } from "../../lib/confirm";
import { t, useT } from "../../i18n";
import { NO_TARGET, type Notify } from "../../lib/notice";
import { SHARED_TABS, type SharedTab } from "../../views/main/Sidebar";
import { BackToTop, Badge, Banner, Button, Card, EmptyState, Icons, List, ListRow, Loading, SkeletonRow, Tabs, type Tone } from "../components";
import { ContextMenu, Menu, menuPointFor, type MenuItem } from "../components/Menu";
import { SourceAvatar } from "../library/SourceAvatar";
import { SharedReader } from "./SharedReader";
import { quoted, senderOf, sharedTitle, SLOT, when, windowLine, withNode } from "./model";
import { RequestLinkSheet } from "./RequestLinkSheet";

/** What each tab lists, read when the page opens; null while loading (a server without it gives an empty list). */
interface SharedData {
  withMe: SharedItem[] | null;
  links: ShareLink[] | null;
  requests: RequestLink[] | null;
}

const STATE_TONE: Record<LinkStatus, Tone> = { active: "success", revoked: "neutral", expired: "neutral" };

type RowEvent = MouseEvent<HTMLElement> | KeyboardEvent<HTMLButtonElement>;

/** "Recording from Imran · Yesterday · 4:05 PM · “Please check the totals”", the time an isolate. */
export function sharedMeta(item: SharedItem): ReactNode {
  const name = senderOf(item);
  return windowLine(<>{item.kind === "project" ? t("Project from :name", { name }) : t("Recording from :name", { name })}{item.sent_at && <> · {when(item.sent_at)}</>}{item.note ? " · " + quoted(item.note) : ""}</>);
}
/** "3 views · last viewed Today · 9:00 AM", as lib/sharing's viewsText, the time an isolate. */
const viewsLine = (link: ShareLink): ReactNode =>
  link.view_count && link.last_viewed_at
    ? withNode(t(":views · last viewed :when", { views: viewsText({ ...link, last_viewed_at: null }), when: SLOT }), when(link.last_viewed_at))
    : viewsText(link);

export function SharedV2({ tab, onTab, notify, projects, focusId = null, onFocusHandled, onOpenRecording, onOpenProject, onSaved }: {
  tab: SharedTab;
  onTab: (tab: SharedTab) => void;
  notify: Notify;
  projects: readonly ClientFolder[] | null;
  /** An item of Shared with me to bring into view (an echoledger link to it was opened): a recording opens. */
  focusId?: string | null;
  /** The link's item was brought into view (or the person went Back from it): the window forgets the request, so a
   * later visit to Shared never opens it again. */
  onFocusHandled?: () => void;
  onOpenRecording: (id: string) => void;
  onOpenProject: (id: string) => void;
  /** Saved: a recording (`session_id`) or a project (`client_id`) is now in this workspace. */
  onSaved?: (saved: { session_id?: string; client_id?: string }, item: SharedItem) => void;
}) {
  const { t, tc, lang } = useT();
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

  // A link to one of them, handled once: a recording opens read-only, a project is shown selected in the list. A later
  // change of the list (a row removed, Save) never opens it again; the window is told, so a later visit doesn't either.
  const [focused, setFocused] = useState<string | null>(null);
  const handledFocus = useRef<string | null>(null);
  useEffect(() => {
    if (!focusId) {
      handledFocus.current = null;
      return;
    }
    if (!data.withMe || handledFocus.current === focusId) return;
    handledFocus.current = focusId;
    setFocused(focusId);
    const item = data.withMe.find((i) => i.id === focusId);
    if (item?.kind === "session" && item.session) setOpenId(item.id);
    else if (/^[\w-]+$/.test(focusId)) document.querySelector(`[data-row-id="${focusId}"]`)?.scrollIntoView?.({ block: "nearest" });
    onFocusHandled?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusId, data.withMe]);

  const titleOf = useMemo(() => {
    const recordings = new Map(library.map((i) => [i.id, i.title?.trim() || t("Recording")]));
    const names = new Map((projects ?? []).map((p) => [p.id, p.name]));
    return (link: ShareLink) => (link.session_id ? recordings.get(link.session_id) ?? t("A recording")
      : link.client_id ? t("Project: :name", { name: names.get(link.client_id) ?? t("a project") }) : t("A link"));
    // The words are read again when the language changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [library, projects, lang]);
  const projectName = (id: string | null) => (id ? (projects ?? []).find((p) => p.id === id)?.name ?? t("A project") : null);

  const save = async (item: SharedItem) => {
    if (busy) return;
    setBusy(item.id);
    try {
      const saved = await api.saveShared(item.id);
      setData((d) => ({ ...d, withMe: (d.withMe ?? []).map((i) => (i.id === item.id ? { ...i, saved_at: new Date().toISOString() } : i)) }));
      notify(item.kind === "project" ? (item.title !== null ? t("Saved :name to your projects", { name: item.title }) : t("Saved the project to your projects")) : t("Saved to your Library"), false,
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
      notify(t("Removed from Shared with me"), false, NO_TARGET);
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(null);
    }
  };
  const revokeLink = async (link: ShareLink) => {
    if (!(await confirmAction(revokeLinkQuestion()))) return;
    try {
      await api.revokeShare(link.id);
      notify(t("Link revoked: it stops working at once"), false, NO_TARGET);
      load();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  const revokeRequest = async (link: RequestLink) => {
    if (!(await confirmAction(revokeRequestQuestion()))) return;
    try {
      await api.revokeRequestLink(link.id);
      notify(t("Request link revoked"), false, NO_TARGET);
      void loadRequests();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  const withMeMenu = (item: SharedItem): MenuItem[] => [
    ...(item.session ? [{ label: t("Open"), icon: Icons.TranscriptIcon, onPick: () => setOpenId(item.id) }] : []),
    ...(item.saved_at ? [] : [{ label: t("Save to my library"), icon: Icons.DownloadIcon, disabled: busy === item.id, onPick: () => void save(item) }]),
    { label: t("Remove"), icon: Icons.TrashIcon, danger: true, disabled: busy === item.id, onPick: () => void remove(item) },
  ];
  const openLink = (link: ShareLink) => (link.session_id ? onOpenRecording(link.session_id) : link.client_id ? onOpenProject(link.client_id) : undefined);
  const linkMenu = (link: ShareLink): MenuItem[] => [
    ...(link.session_id || link.client_id ? [{ label: t("Open"), icon: link.session_id ? Icons.TranscriptIcon : Icons.FolderIcon, onPick: () => openLink(link) }] : []),
    ...(linkStatus(link) === "active" ? [{ label: t("Revoke"), icon: Icons.CloseIcon, danger: true, onPick: () => void revokeLink(link) }] : []),
  ];
  const requestMenu = (link: RequestLink): MenuItem[] =>
    requestStatus(link) === "active" ? [{ label: t("Revoke"), icon: Icons.CloseIcon, danger: true, onPick: () => void revokeRequest(link) }] : [];
  const rowMenu = (label: string, items: MenuItem[]) => (e: RowEvent) => {
    if (items.length === 0) return;
    const at = "clientX" in e && (e.clientX || e.clientY) ? { x: e.clientX, y: e.clientY } : menuPointFor(e.currentTarget);
    setContext({ at, label, items });
  };
  const more = (label: string, items: () => MenuItem[]) => <Menu label={label} items={items} button={{ variant: "quiet", size: "sm", icon: Icons.MoreIcon }} />;

  const counts: Record<SharedTab, number | null> = {
    "with-me": data.withMe?.length ?? null,
    links: data.links ? data.links.filter((l) => linkStatus(l) === "active").length : null,
    requests: data.requests ? data.requests.filter((l) => requestStatus(l) === "active").length : null,
  };
  const loading = (list: unknown[] | null, label: string) => list === null && !error && (
    <Loading label={label}><Card><div className="el-group__rows">{[0, 1, 2].map((n) => <SkeletonRow key={n} />)}</div></Card></Loading>
  );

  const open = openId ? data.withMe?.find((i) => i.id === openId && i.session) ?? null : null;
  if (open) {
    return (
      <SharedReader item={open} busy={busy === open.id} notify={notify} onBack={() => { setOpenId(null); setFocused(null); onFocusHandled?.(); }}
        onSave={() => void save(open)} onRemove={() => void remove(open)} />
    );
  }

  const withMePanel = (
    <>
      {loading(data.withMe, t("Loading what was shared with you…"))}
      {data.withMe?.length === 0 && <EmptyState icon={Icons.UsersIcon} title={t("Nothing sent to you yet")} body={t("When a teammate sends you a recording or a project, it shows here.")} />}
      {data.withMe && data.withMe.length > 0 && (
        <Card>
          <List label={t("Shared with me")} className="el-group__rows">
            {data.withMe.map((item) => {
              const title = sharedTitle(item);
              return (
                <ListRow key={item.id} rowId={item.id} title={title} meta={sharedMeta(item)} selected={item.id === focused} actionsShown="always" metaLines={2}
                  leading={item.session ? <SourceAvatar item={item.session} /> : undefined} icon={item.session ? undefined : Icons.FolderIcon}
                  endTop={item.saved_at ? t("Saved") : undefined}
                  // A recording opens read-only; a project sent to you has nothing to open until it is saved: its menu opens.
                  onClick={item.session ? () => setOpenId(item.id) : rowMenu(t("Actions for :name", { name: title }), withMeMenu(item))}
                  onContextMenu={rowMenu(t("Actions for :name", { name: title }), withMeMenu(item))}
                  actions={more(t("More for :name", { name: title }), () => withMeMenu(item))} />
              );
            })}
          </List>
        </Card>
      )}
    </>
  );

  const linksPanel = (
    <>
      {loading(data.links, t("Loading your links…"))}
      {data.links?.length === 0 && <EmptyState icon={Icons.LinkIcon} title={t("No links yet")} body={t("Share a recording or a project from its page; the link shows here with its views.")} />}
      {data.links && data.links.length > 0 && (
        <Card>
          <List label={t("My links")} className="el-group__rows">
            {data.links.map((link) => {
              const state = linkStatus(link);
              const title = titleOf(link);
              const items = linkMenu(link);
              return (
                <ListRow key={link.id} rowId={link.id} icon={link.client_id && !link.session_id ? Icons.FolderIcon : Icons.LinkIcon} title={title} actionsShown="always" metaLines={2}
                  meta={windowLine(<>{audienceText(link)} · {viewsLine(link)}{link.expires_at && <> · {withNode(t("until :date", { date: SLOT }), when(link.expires_at))}</>}</>)}
                  badge={<Badge tone={STATE_TONE[state]}>{statusText(state)}</Badge>}
                  onClick={link.session_id || link.client_id ? () => openLink(link) : undefined}
                  onContextMenu={rowMenu(t("Actions for :name", { name: title }), items)}
                  actions={items.length > 0 ? more(t("More for :name", { name: title }), () => linkMenu(link)) : undefined} />
              );
            })}
          </List>
        </Card>
      )}
    </>
  );

  /** The request links' rows: state, where files go, what came in, the limits, when it stops, and Revoke in the ⋮. */
  const requestRows = (list: RequestLink[]) => list.map((link) => {
    const state = requestStatus(link);
    const title = link.title || t("Request link");
    const items = requestMenu(link);
    const into = projectName(link.client_id);
    return (
      <ListRow key={link.id} rowId={link.id} icon={Icons.RequestFilesIcon} title={title} actionsShown="always" metaLines={2}
        meta={windowLine([into ? t("Into :project", { project: into }) : t("Into no project"), receivedText(link.received), limitsText(link) || null].filter(Boolean).join(" · "))}
        endTop={link.expires_at ? windowLine(withNode(state === "active" ? t("Stops :date", { date: SLOT }) : t("Stopped :date", { date: SLOT }), when(link.expires_at))) : t("Never stops")}
        badge={<Badge tone={STATE_TONE[state]}>{statusText(state)}</Badge>}
        onContextMenu={rowMenu(t("Actions for :name", { name: title }), items)}
        actions={items.length > 0 ? more(t("More for :name", { name: title }), () => requestMenu(link)) : undefined} />
    );
  });
  const requestsPanel = (
    <>
      {loading(data.requests, t("Loading your request links…"))}
      {data.requests?.length === 0 && <EmptyState icon={Icons.RequestFilesIcon} title={t("No request links yet")} body={t("A request link lets anyone send you voice notes and files, without an account.")} />}
      {data.requests && data.requests.length > 0 && <Card><List label={t("Request links")} className="el-group__rows">{requestRows(data.requests)}</List></Card>}
    </>
  );

  return (
    <div className="el-page el-back-top-room" data-v2="shared">
      <header className="el-page__head">
        <div className="el-page__titles">
          <h1 className="el-title-l">{t("Shared")}</h1>
          <p className="el-secondary" dir="auto">{t("What teammates sent you, the links you made, and links people use to send you files.")}</p>
        </div>
        <div className="el-page__actions">
          <Button variant="primary" icon={Icons.PlusIcon} onClick={() => setNewRequest(true)}>{t("New request link")}</Button>
        </div>
      </header>

      {error && (
        <Banner tone="danger" icon={Icons.ErrorIcon} action={<Button size="sm" onClick={load}>{t("Retry")}</Button>}>
          {t("Couldn't load everything. :error", { error })}
        </Banner>
      )}

      {/* The tabs' names are the sidebar's (SHARED_TABS, in English), put in the account's language here. */}
      <Tabs label={t("Shared")} value={tab} onChange={onTab}
        tabs={SHARED_TABS.map(([value, label]) => ({ value, label: counts[value] === null ? t(label) : tc(":tab · :count", counts[value] ?? 0, { tab: t(label) }) }))}>
        {tab === "with-me" ? withMePanel : tab === "links" ? linksPanel : requestsPanel}
      </Tabs>

      {context && <ContextMenu label={context.label} items={context.items} at={context.at} onClose={() => setContext(null)} />}
      {newRequest && (
        <RequestLinkSheet projects={projects ?? []} notify={notify} onShowLinks={() => onTab("requests")}
          onMade={() => void loadRequests()} onClose={() => { setNewRequest(false); void loadRequests(); }} />
      )}
      <BackToTop first=".el-row__hit" />
    </div>
  );
}
