// Projects (since v1.5; Clients in v1.4): every project as a tree with its subfolders, and a project's page. The apps
// call client folders projects; the API keeps the name clients. A project can be renamed, moved into another one or
// to the top, get a folder inside, or be deleted: its recordings then go to the Inbox and its folders up a level.
import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { PageHeader } from "../../components/Page";
import { api, errorMessage, listenTo, type ClientFolder, type LibraryItem } from "../../lib/ipc";
import { canAddSubfolder, hasSubfolders, nestedCount, projectLabel } from "../../lib/projects";
import { FolderIcon, LinkIcon, PlusIcon, RequestFilesIcon } from "../../components/Icons";
import { ClientWorkspace } from "./ClientWorkspace";
import { ProjectTree } from "./ProjectTree";
import { DeleteProjectDialog, MoveProjectDialog, moveRecordings, movedText, NameDialog } from "./ProjectDialogs";
import { RulesSection } from "./FilingRules";
import { ShareDialog } from "./ShareDialog";
import { RequestLinksSheet } from "./RequestLinks";
import { NO_TARGET, type Notify } from "../../lib/notice";
import { useLanguage, wordsIn, type Lang } from "../../i18n";

/** What a project's page in the new look needs from the Projects page: the project, where it sits, its folders, the
 * project menu (whose dialogs this page keeps showing) and Share. */
export interface ProjectPageContext {
  project: ClientFolder;
  parent: { id: string; name: string } | null;
  folders: { id: string; name: string; count: number }[];
  /** Its menu, without Request files: the page has it as a button in its header (UX-09). */
  menu: { label: string; onPick: () => void; danger?: boolean }[];
  onShare: (() => void) | null;
  /** Send a copy to a teammate (Members). */
  onSend: (() => void) | null;
  /** Request files into it (a button in its header, UX-09); null without request links. */
  onRequest: (() => void) | null;
  rules: boolean;
  /** Every project, for its folders' recordings and the drag targets. */
  projects: ClientFolder[];
  aiNotesInPlan: boolean;
  /** Recordings dragged onto a project (or the Inbox) in the sidebar. */
  onMoveRecordings: (ids: string[], projectId: string | null) => void;
  /** The project's AI notes switch changed. */
  onProjectChanged: (change: Partial<ClientFolder> & { id: string }) => void;
}

type Dialog =
  | { kind: "new"; parentId: string | null }
  | { kind: "rename"; project: ClientFolder }
  | { kind: "move"; project: ClientFolder }
  | { kind: "delete"; project: ClientFolder }
  | { kind: "share"; project: ClientFolder; start: "link" | "send" }
  | { kind: "requests"; projectId: string | null; focusId: string | null };

/**
 * "Deleted Acme · 12 recordings moved to the Inbox · 2 folders moved up": in `lang` (the window's language when left
 * out; the classic page passes "en"), the project's name as it is.
 */
export function deletedText(name: string, answer: { moved_sessions?: number; moved_projects?: number }, lang?: Lang): string {
  const { t, tc } = wordsIn(lang);
  const parts = [t("Deleted :name", { name })];
  const sessions = answer.moved_sessions ?? 0;
  const folders = answer.moved_projects ?? 0;
  if (sessions > 0) parts.push(tc(":count recording moved to the Inbox|:count recordings moved to the Inbox", sessions));
  if (folders > 0) parts.push(tc(":count folder moved up|:count folders moved up", folders));
  return parts.join(" · ");
}

/** This page's name dialog, or the one the new design renders (one component type, so typing never remounts it). */
function NameDialogSwitch({ render, ...props }: Parameters<typeof NameDialog>[0] & { render: ((props: Parameters<typeof NameDialog>[0]) => ReactNode) | null }) {
  return render ? <>{render(props)}</> : <NameDialog {...props} />;
}

/** What the new design's list of projects is given. */
export type ProjectsListContext = {
  projects: ClientFolder[] | null;
  error: string | null;
  onReload: () => void;
  items: readonly LibraryItem[];
  expanded: ReadonlySet<string>;
  onToggle: (id: string) => void;
  onOpenProject: (id: string) => void;
  onNew: () => void;
  onRequest: (() => void) | null;
};

export function ProjectsView({ notify, projects, error = null, onReload, onChanged, projectId, onOpenProject, onOpenRecording, expanded, onToggle, requestFocus = null, onRequestFocusHandled, aiNotesInPlan = true, request = null, aside = null, renderPage = null, onRequestLink, renderList = null, renderNameDialog = null }: {
  notify: Notify;
  /** The new design's Request files sheet (into a project, or the Inbox); without it this page's own sheet opens. */
  onRequestLink?: (projectId: string | null) => void;
  /** Every project; null while they load. */
  projects: ClientFolder[] | null;
  /** Why they could not be loaded. */
  error?: string | null;
  onReload: () => Promise<unknown> | void;
  /** A change made here, shown at once (the next load confirms it). */
  onChanged: (change: (list: ClientFolder[]) => ClientFolder[]) => void;
  /** The open project; null = the list of projects. */
  projectId: string | null;
  onOpenProject: (id: string | null) => void;
  onOpenRecording: (id: string, ms?: number) => void;
  /** Which projects show their folders (shared with the sidebar). */
  expanded: ReadonlySet<string>;
  onToggle: (id: string) => void;
  /** A request link to show (an echoledger link to it was opened). */
  requestFocus?: { id: string; seq: number } | null;
  /** The request link is shown: coming back to Projects later does not show it again. */
  onRequestFocusHandled?: () => void;
  /** The plan has call notes, memory and Ask (`ai_notes`, since v1.5). */
  aiNotesInPlan?: boolean;
  /** "+ New project" from the top bar or the column (v1.6): the dialog opens once per `seq`. */
  request?: { kind: "new"; seq: number } | null;
  /** The nested column (v1.6, P4.2): every project and "+ New project". */
  aside?: ReactNode;
  /** A project's page in the new look (ui_v2.ask) in place of today's; the dialogs stay this page's. */
  renderPage?: ((ctx: ProjectPageContext) => ReactNode) | null;
  /** The new design's list of projects; this page's dialogs stay with it. */
  renderList?: ((ctx: ProjectsListContext) => ReactNode) | null;
  /** The new design's name dialog (New project, New folder inside, Rename) in place of this page's. */
  renderNameDialog?: ((props: Parameters<typeof NameDialog>[0]) => ReactNode) | null;
}) {
  // The new design (it renders this page's list, a project's page or the name dialog) shows these dialogs' words, the
  // project menu and the notices in the window's language; the classic page keeps its English in every window.
  useLanguage();
  const words: Lang | undefined = renderNameDialog || renderPage || renderList ? undefined : "en";
  const { t } = wordsIn(words);
  const [items, setItems] = useState<LibraryItem[]>([]);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const handledRequest = useRef(0);
  useEffect(() => {
    if (!request || handledRequest.current === request.seq) return;
    handledRequest.current = request.seq;
    setDialog({ kind: "new", parentId: null });
  }, [request]);
  // Request links exist on this server (since v1.5): asked once; Request files is hidden until it says so.
  const [requests, setRequests] = useState(false);
  useEffect(() => {
    let alive = true;
    api.requestLinks().then((r) => alive && setRequests(r !== null), () => undefined);
    return () => {
      alive = false;
    };
  }, []);
  useEffect(() => {
    if (!requestFocus) return;
    setDialog({ kind: "requests", projectId: null, focusId: requestFocus.id });
    onRequestFocusHandled?.();
  }, [requestFocus?.seq]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    // The saved library says how many recordings each project has and when the last call was, without a server trip.
    api.library().then(setItems).catch(() => undefined);
  }, [projectId]);
  useEffect(() => {
    const un = listenTo("library:updated", () => api.library().then(setItems).catch(() => undefined));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  const list = projects ?? [];
  const nested = hasSubfolders(list);
  const project = list.find((p) => p.id === projectId) ?? null;
  const parentName = (p: ClientFolder) => (p.parent_id ? list.find((x) => x.id === p.parent_id)?.name ?? null : null);
  const close = () => setDialog(null);

  const create = async (name: string, parentId: string | null): Promise<boolean> => {
    try {
      const { data } = await api.createClient(name, null, null, parentId);
      onChanged((all) => [...all.filter((c) => c.id !== data.id), { parent_id: parentId, ...data }]);
      if (parentId && !expanded.has(parentId)) onToggle(parentId);
      close();
      onOpenProject(data.id);
      notify(parentId ? t(":name added to :project", { name: data.name, project: projectLabel(list, parentId) }) : t(":name added", { name: data.name }), false, { kind: "project", id: data.id });
      void onReload();
      return true;
    } catch (e) {
      notify(errorMessage(e), true);
      return false;
    }
  };
  const rename = async (target: ClientFolder, name: string): Promise<boolean> => {
    try {
      const { data } = await api.updateClient(target.id, { name });
      onChanged((all) => all.map((c) => (c.id === target.id ? { ...c, ...data, name: data?.name ?? name } : c)));
      close();
      notify(t("Renamed"), false, { kind: "project", id: target.id });
      void onReload();
      return true;
    } catch (e) {
      notify(errorMessage(e), true);
      return false;
    }
  };
  const move = async (target: ClientFolder, parentId: string | null): Promise<boolean> => {
    try {
      await api.updateClient(target.id, { parent_id: parentId });
      onChanged((all) => all.map((c) => (c.id === target.id ? { ...c, parent_id: parentId } : c)));
      close();
      notify(parentId ? t("Moved :name into :project", { name: target.name, project: projectLabel(list, parentId) }) : t("Moved :name to the top level", { name: target.name }), false, { kind: "project", id: target.id });
      if (parentId && !expanded.has(parentId)) onToggle(parentId);
      void onReload();
      return true;
    } catch (e) {
      notify(errorMessage(e), true);
      return false;
    }
  };
  /** Recordings dragged from a project's list onto another project or the Inbox in the sidebar. */
  const dropMove = async (ids: string[], target: string | null) => {
    try {
      await moveRecordings(ids, target);
      notify(movedText(ids.length, items.find((i) => i.id === ids[0])?.title, list, target, words), false, target ? { kind: "project", id: target } : { kind: "library", folder: "inbox" });
      void onReload();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  const remove = async (target: ClientFolder): Promise<boolean> => {
    try {
      const answer = await api.deleteClient(target.id);
      onChanged((all) => all.filter((c) => c.id !== target.id).map((c) => (c.parent_id === target.id ? { ...c, parent_id: target.parent_id ?? null } : c)));
      close();
      onOpenProject(target.parent_id ?? null);
      notify(deletedText(target.name, answer ?? {}, words), false, (answer?.moved_sessions ?? 0) > 0 ? { kind: "library", folder: "inbox" } : NO_TARGET);
      void onReload();
      return true;
    } catch (e) {
      notify(errorMessage(e), true);
      return false;
    }
  };

  const dialogs = dialog && (
    <>
      {dialog.kind === "new" && (
        <NameDialogSwitch render={renderNameDialog} title={dialog.parentId ? t("New folder in :project", { project: projectLabel(list, dialog.parentId) }) : t("New project")}
          submitLabel={dialog.parentId ? t("Add folder") : t("Add project")}
          hint={dialog.parentId ? t("A folder keeps some of the project's recordings and files apart. It has its own notes and Ask.") : t("A project keeps its calls, files, a brief and notes together, and Ask answers from all of them.")}
          onSubmit={(name) => create(name, dialog.parentId)} onClose={close} />
      )}
      {dialog.kind === "rename" && <NameDialogSwitch render={renderNameDialog} title={t("Rename :name", { name: dialog.project.name })} initial={dialog.project.name} submitLabel={t("Rename")} onSubmit={(name) => rename(dialog.project, name)} onClose={close} />}
      {dialog.kind === "move" && <MoveProjectDialog project={dialog.project} projects={list} onMove={(parentId) => move(dialog.project, parentId)} onClose={close} />}
      {dialog.kind === "delete" && (
        <DeleteProjectDialog project={dialog.project} recordings={Math.max(dialog.project.session_count ?? 0, items.filter((i) => !i.deleted && i.client?.id === dialog.project.id).length)}
          folders={list.filter((c) => c.parent_id === dialog.project.id).length} parentName={parentName(dialog.project)}
          onDelete={() => remove(dialog.project)} onClose={close} />
      )}
      {dialog.kind === "share" && <ShareDialog target={{ kind: "project", id: dialog.project.id, name: dialog.project.name }} start={dialog.start} notify={notify} onClose={close} />}
      {dialog.kind === "requests" && <RequestLinksSheet projects={list} projectId={dialog.projectId} focusId={dialog.focusId} notify={notify} onClose={close} />}
    </>
  );

  if (project) {
    const requestItem = { label: "Request files into it…", onPick: () => (onRequestLink ? onRequestLink(project.id) : setDialog({ kind: "requests", projectId: project.id, focusId: null })) };
    const menu = [
      nested && canAddSubfolder(list, project.id) && { label: t("New folder inside"), onPick: () => setDialog({ kind: "new", parentId: project.id }) },
      { label: t("Rename"), onPick: () => setDialog({ kind: "rename", project }) },
      nested && { label: t("Move…"), onPick: () => setDialog({ kind: "move", project }) },
      nested && { label: t("Share link…"), onPick: () => setDialog({ kind: "share", project, start: "link" }) },
      nested && { label: t("Send to a teammate…"), onPick: () => setDialog({ kind: "share", project, start: "send" }) },
      requests && requestItem,
      nested && { label: t("Delete project…"), danger: true, onPick: () => setDialog({ kind: "delete", project }) },
    ];
    const share = nested && (
      <button type="button" className="btn" onClick={() => setDialog({ kind: "share", project, start: "link" })} title="A link to the project's recordings, or send it to a teammate">
        <LinkIcon size={16} />Share
      </button>
    );
    if (renderPage) {
      const parent = project.parent_id ? list.find((p) => p.id === project.parent_id) ?? null : null;
      return (
        <>
          {renderPage({
            project,
            parent: parent ? { id: parent.id, name: parent.name } : null,
            folders: list.filter((p) => p.parent_id === project.id).map((p) => ({ id: p.id, name: p.name, count: nestedCount(p, items, list) })),
            // Request files is a button in the page's header (UX-09), not in its menu.
            menu: menu.filter((m): m is { label: string; onPick: () => void; danger?: boolean } => !!m && m !== requestItem),
            onShare: nested ? () => setDialog({ kind: "share", project, start: "link" }) : null,
            onSend: nested ? () => setDialog({ kind: "share", project, start: "send" }) : null,
            onRequest: requests ? requestItem.onPick : null,
            rules: nested,
            projects: list,
            aiNotesInPlan,
            onMoveRecordings: (ids, target) => void dropMove(ids, target),
            onProjectChanged: (next) => onChanged((all) => all.map((c) => (c.id === next.id ? { ...c, ...next } : c))),
          })}
          {dialogs}
        </>
      );
    }
    return (
      <div className="page-split">
      {aside}
      <div className="project-page page-main">
        <ClientWorkspace key={project.id} client={project} projects={list} items={items} notify={notify} onOpenRecording={onOpenRecording} aiNotesInPlan={aiNotesInPlan}
          onClientChanged={(next) => onChanged((all) => all.map((c) => (c.id === next.id ? { ...c, ...next } : c)))}
          onOpenProject={onOpenProject} menu={menu} actions={share || null} onMoveRecordings={(ids, target) => void dropMove(ids, target)}
          rules={nested ? <RulesSection project={project} notify={notify} /> : null} />
        {dialogs}
      </div>
      </div>
    );
  }

  if (renderList) {
    return (
      <>
        {renderList({
          projects, error, onReload: () => void onReload(), items, expanded, onToggle, onOpenProject: (id) => onOpenProject(id),
          onNew: () => setDialog({ kind: "new", parentId: null }),
          onRequest: requests ? () => (onRequestLink ? onRequestLink(null) : setDialog({ kind: "requests", projectId: null, focusId: null })) : null,
        })}
        {dialogs}
      </>
    );
  }
  return (
    <div className="projects-home">
      <PageHeader crumbs={[{ label: "Projects" }]} title="Projects"
        subtitle="Calls, files, a brief and notes together, for each client or piece of work. Recordings in no project are in the Inbox."
        actions={<>
          {requests && (
            <button type="button" className="btn" onClick={() => (onRequestLink ? onRequestLink(null) : setDialog({ kind: "requests", projectId: null, focusId: null }))} title="A link anyone can use to send you voice notes and files">
              <RequestFilesIcon size={16} />Request files
            </button>
          )}
          <button type="button" className="btn primary" onClick={() => setDialog({ kind: "new", parentId: null })}><PlusIcon size={16} />New project</button>
        </>} />
      <div className="page-split">
      {aside}
      <div className="page-main">
      {error && (
        <div className="notice error sync-error" role="alert">
          <span>Couldn't load the projects. {error}</span>
          <button type="button" className="btn compact" onClick={() => void onReload()}>Retry</button>
        </div>
      )}
      {projects === null && !error && (
        <div aria-busy="true">
          <span className="sr-only">Loading projects…</span>
          {[0, 1, 2].map((n) => (
            <div key={n} className="skeleton-row" aria-hidden="true">
              <span className="skeleton tile" style={{ borderRadius: "50%" }} />
              <span className="skeleton-lines"><span className="skeleton" style={{ width: "55%", height: 14 }} /><span className="skeleton" style={{ width: "35%", height: 10 }} /></span>
            </div>
          ))}
        </div>
      )}
      {projects && projects.length === 0 && (
        <div className="empty-state">
          <span className="empty-icon"><FolderIcon size={26} /></span>
          <span className="empty-title">No projects yet</span>
          <p>A project keeps its calls, files, a brief and notes together, and Ask answers from all of them.</p>
          <button type="button" className="btn primary" onClick={() => setDialog({ kind: "new", parentId: null })}><PlusIcon size={16} />New project</button>
        </div>
      )}
      {projects && projects.length > 0 && (
        <ProjectTree projects={projects} label="Projects" variant="page" expanded={expanded} onToggle={onToggle} onOpen={onOpenProject} items={items} />
      )}
      </div>
      </div>
      {dialogs}
    </div>
  );
}

/** The expanded projects of the tree, kept on this computer like the sidebar's state. */
export function useExpandedProjects(key = "echoledger-projects-open"): [ReadonlySet<string>, (id: string) => void] {
  const [open, setOpen] = useState<Set<string>>(() => {
    try {
      const saved = JSON.parse(window.localStorage.getItem(key) ?? "[]");
      return new Set(Array.isArray(saved) ? saved.filter((v): v is string => typeof v === "string") : []);
    } catch {
      return new Set();
    }
  });
  const toggle = useMemo(() => (id: string) => {
    setOpen((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      try {
        window.localStorage.setItem(key, JSON.stringify([...next]));
      } catch {
        // Storage can be unavailable; the tree then opens as it was until the app restarts.
      }
      return next;
    });
  }, [key]);
  return [open, toggle];
}
