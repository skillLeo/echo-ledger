// Projects (since v1.5; Clients in v1.4): every project as a tree with its subfolders, and a project's page. The apps
// call client folders projects; the API keeps the name clients. A project can be renamed, moved into another one or
// to the top, get a folder inside, or be deleted: its recordings then go to the Inbox and its folders up a level.
import { useEffect, useMemo, useState } from "react";
import { api, errorMessage, listenTo, type ClientFolder, type LibraryItem } from "../../lib/ipc";
import { canAddSubfolder, hasSubfolders, projectLabel } from "../../lib/projects";
import { FolderIcon, PlusIcon } from "../../components/Icons";
import { ClientWorkspace } from "./ClientWorkspace";
import { ProjectTree } from "./ProjectTree";
import { DeleteProjectDialog, MoveProjectDialog, moveRecordings, movedText, NameDialog } from "./ProjectDialogs";

type Notify = (text: string, error?: boolean) => void;
type Dialog =
  | { kind: "new"; parentId: string | null }
  | { kind: "rename"; project: ClientFolder }
  | { kind: "move"; project: ClientFolder }
  | { kind: "delete"; project: ClientFolder };

/** "Deleted Acme · 12 recordings moved to the Inbox · 2 folders moved up". */
export function deletedText(name: string, answer: { moved_sessions?: number; moved_projects?: number }): string {
  const parts = [`Deleted ${name}`];
  const sessions = answer.moved_sessions ?? 0;
  const folders = answer.moved_projects ?? 0;
  if (sessions > 0) parts.push(`${sessions} recording${sessions === 1 ? "" : "s"} moved to the Inbox`);
  if (folders > 0) parts.push(`${folders} folder${folders === 1 ? "" : "s"} moved up`);
  return parts.join(" · ");
}

export function ProjectsView({ notify, projects, error = null, onReload, onChanged, projectId, onOpenProject, onOpenRecording, expanded, onToggle }: {
  notify: Notify;
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
}) {
  const [items, setItems] = useState<LibraryItem[]>([]);
  const [dialog, setDialog] = useState<Dialog | null>(null);
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
      notify(parentId ? `${data.name} added to ${projectLabel(list, parentId)}` : `${data.name} added`);
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
      notify("Renamed");
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
      notify(parentId ? `Moved ${target.name} into ${projectLabel(list, parentId)}` : `Moved ${target.name} to the top level`);
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
      notify(movedText(ids.length, items.find((i) => i.id === ids[0])?.title, list, target));
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
      notify(deletedText(target.name, answer ?? {}));
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
        <NameDialog title={dialog.parentId ? `New folder in ${projectLabel(list, dialog.parentId)}` : "New project"} submitLabel={dialog.parentId ? "Add folder" : "Add project"}
          hint={dialog.parentId ? "A folder keeps some of the project's recordings and files apart. It has its own notes and Ask." : "A project keeps its calls, files, a brief and notes together, and Ask answers from all of them."}
          onSubmit={(name) => create(name, dialog.parentId)} onClose={close} />
      )}
      {dialog.kind === "rename" && <NameDialog title={`Rename ${dialog.project.name}`} initial={dialog.project.name} submitLabel="Rename" onSubmit={(name) => rename(dialog.project, name)} onClose={close} />}
      {dialog.kind === "move" && <MoveProjectDialog project={dialog.project} projects={list} onMove={(parentId) => move(dialog.project, parentId)} onClose={close} />}
      {dialog.kind === "delete" && (
        <DeleteProjectDialog project={dialog.project} recordings={Math.max(dialog.project.session_count ?? 0, items.filter((i) => !i.deleted && i.client?.id === dialog.project.id).length)}
          folders={list.filter((c) => c.parent_id === dialog.project.id).length} parentName={parentName(dialog.project)}
          onDelete={() => remove(dialog.project)} onClose={close} />
      )}
    </>
  );

  if (project) {
    const menu = [
      nested && canAddSubfolder(list, project.id) && { label: "New folder inside", onPick: () => setDialog({ kind: "new", parentId: project.id }) },
      { label: "Rename", onPick: () => setDialog({ kind: "rename", project }) },
      nested && { label: "Move…", onPick: () => setDialog({ kind: "move", project }) },
      nested && { label: "Delete project…", danger: true, onPick: () => setDialog({ kind: "delete", project }) },
    ];
    return (
      <div className="project-page">
        <ClientWorkspace key={project.id} client={project} projects={list} items={items} notify={notify} onOpenRecording={onOpenRecording}
          onClientChanged={(next) => onChanged((all) => all.map((c) => (c.id === next.id ? { ...c, ...next } : c)))}
          onOpenProject={onOpenProject} menu={menu} onMoveRecordings={(ids, target) => void dropMove(ids, target)} />
        {dialogs}
      </div>
    );
  }

  return (
    <div className="projects-home">
      <div className="page-head">
        <div>
          <h1 className="page-title">Projects</h1>
          <p className="page-sub">Calls, files, a brief and notes together, for each client or piece of work. Recordings in no project are in the Inbox.</p>
        </div>
        <span className="spacer" />
        <button type="button" className="btn primary" onClick={() => setDialog({ kind: "new", parentId: null })}><PlusIcon size={16} />New project</button>
      </div>
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
