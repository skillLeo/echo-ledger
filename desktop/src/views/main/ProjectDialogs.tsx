// Dialogs of the Projects page (since v1.5): naming a project or a subfolder, moving a project into another one (or to
// the top level), deleting one, moving recordings into a project or the Inbox, and the project picker they share.
import { useEffect, useId, useMemo, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { api, errorMessage, type ClientFolder } from "../../lib/ipc";
import { canMoveTo, projectLabel, projectTree, subtreeIds, visibleRows } from "../../lib/projects";
import { FolderIcon, InboxIcon, PlusIcon, SearchIcon } from "../../components/Icons";

/** Moves recordings into a project (null: the Inbox): one through its own change, several in one bulk action. */
export async function moveRecordings(ids: readonly string[], projectId: string | null): Promise<void> {
  if (ids.length === 1) await api.organiseSession(ids[0], { client_id: projectId });
  else await api.bulkSessions([...ids], "move", projectId);
}

/** "Moved “Kick-off” to Acme › Invoices", "Moved 3 recordings to the Inbox". */
export function movedText(count: number, title: string | null | undefined, projects: readonly ClientFolder[], projectId: string | null): string {
  const where = projectId ? projectLabel(projects, projectId) || "the project" : "the Inbox";
  return count === 1 ? `Moved “${title || "the recording"}” to ${where}` : `Moved ${count} recordings to ${where}`;
}

/** A dialog sheet: Escape or a click beside it closes it, except while it is busy. */
export function Sheet({ title, onClose, busy = false, className = "", children }: {
  title: string;
  onClose: () => void;
  busy?: boolean;
  className?: string;
  children: ReactNode;
}) {
  const titleId = useId();
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape" && !busy) onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [busy, onClose]);
  // On the page itself, not inside the row or bar that opened it (a sticky bar would hold it under the rest).
  return createPortal(
    <div className="sheet-backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget && !busy) onClose(); }}>
      <div className={`sheet card ${className}`.trim()} role="dialog" aria-modal="true" aria-labelledby={titleId}>
        <h2 id={titleId} className="sheet-title">{title}</h2>
        {children}
      </div>
    </div>,
    document.body,
  );
}

/** A new project, a new subfolder or a new name: `onSubmit` resolves to true once it is done (the caller closes it). */
export function NameDialog({ title, initial = "", submitLabel, hint, onSubmit, onClose }: {
  title: string;
  initial?: string;
  submitLabel: string;
  hint?: string;
  onSubmit: (name: string) => Promise<boolean>;
  onClose: () => void;
}) {
  const [name, setName] = useState(initial);
  const [busy, setBusy] = useState(false);
  const fieldId = useId();
  const text = name.trim();
  const submit = async () => {
    if (!text || busy || text === initial.trim()) return;
    setBusy(true);
    if (!(await onSubmit(text))) setBusy(false);
  };
  return (
    <Sheet title={title} onClose={onClose} busy={busy}>
      {hint && <p className="small muted">{hint}</p>}
      <form className="column" onSubmit={(e) => { e.preventDefault(); void submit(); }}>
        <label htmlFor={fieldId} className="small muted">Name</label>
        <input id={fieldId} className="input" autoFocus maxLength={120} value={name} dir="auto" onFocus={(e) => e.currentTarget.select()} onChange={(e) => setName(e.target.value)} />
        <div className="row sheet-actions">
          <span className="spacer" />
          <button type="button" className="btn ghost" disabled={busy} onClick={onClose}>Cancel</button>
          <button type="submit" className="btn primary" disabled={busy || !text || text === initial.trim()}>{submitLabel}</button>
        </div>
      </form>
    </Sheet>
  );
}

/**
 * The projects to pick from, indented by level, under one row for no project (`top`: the Inbox for recordings, the top
 * level for a project). `blocked` says why a row cannot be picked (null when it can). A search narrows a long list.
 */
export function ProjectPicker({ projects, top, value, onChange, blocked, label }: {
  projects: readonly ClientFolder[];
  top: { label: string; inbox: boolean };
  /** The chosen row: a project's id, null for the top row, undefined while nothing is chosen. */
  value: string | null | undefined;
  onChange: (id: string | null) => void;
  blocked: (id: string | null) => string | null;
  label: string;
}) {
  const [query, setQuery] = useState("");
  const group = useId();
  const rows = useMemo(() => visibleRows(projectTree(projects), "all"), [projects]);
  const q = query.trim().toLowerCase();
  const shown = q ? rows.filter((r) => projectLabel(projects, r.project.id).toLowerCase().includes(q)) : rows;
  const row = (id: string | null, name: string, depth: number, icon: ReactNode) => {
    const reason = blocked(id);
    return (
      <label key={id ?? "top"} className={`pick-row ${reason ? "blocked" : ""} ${value === id ? "chosen" : ""}`} style={{ ["--depth" as string]: depth }} title={reason ?? undefined}>
        <input type="radio" name={group} checked={value === id} disabled={!!reason} onChange={() => onChange(id)} />
        {icon}
        <span className="pick-name" dir="auto">{name}</span>
        {reason && <span className="pick-note">{reason}</span>}
      </label>
    );
  };
  return (
    <div className="project-picker">
      {rows.length > 6 && (
        <label className="searchbox compact">
          <SearchIcon size={16} />
          <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Find a project" aria-label="Find a project" />
        </label>
      )}
      <div className="pick-list" role="radiogroup" aria-label={label}>
        {!q && row(null, top.label, 0, top.inbox ? <InboxIcon size={16} /> : <FolderIcon size={16} />)}
        {shown.map((r) => row(r.project.id, q ? projectLabel(projects, r.project.id) : r.project.name, q ? 0 : r.depth, <FolderIcon size={16} />))}
        {q && shown.length === 0 && <p className="small muted">No project is called that.</p>}
      </div>
    </div>
  );
}

/** Why a project cannot move under a target (null when it can), in the words the picker shows. */
export function moveBlock(projects: readonly ClientFolder[], project: ClientFolder, target: string | null): string | null {
  if (target === (project.parent_id ?? null)) return "It is here now";
  if (target === project.id) return "This project";
  if (target !== null && subtreeIds(projects, project.id).has(target)) return "One of its own folders";
  if (!canMoveTo(projects, project.id, target)) return "Too deep: 4 levels at most";
  return null;
}

/** Moves a project into another one or to the top level. */
export function MoveProjectDialog({ project, projects, onMove, onClose }: {
  project: ClientFolder;
  projects: readonly ClientFolder[];
  onMove: (parentId: string | null) => Promise<boolean>;
  onClose: () => void;
}) {
  const [target, setTarget] = useState<string | null | undefined>(undefined);
  const [busy, setBusy] = useState(false);
  const move = async () => {
    if (target === undefined || busy) return;
    setBusy(true);
    if (!(await onMove(target))) setBusy(false);
  };
  return (
    <Sheet title={`Move ${project.name}`} onClose={onClose} busy={busy}>
      <p className="small muted">Its recordings, files and folders move with it.</p>
      <ProjectPicker projects={projects} top={{ label: "Top level", inbox: false }} value={target} onChange={setTarget} label="Move to" blocked={(id) => moveBlock(projects, project, id)} />
      <div className="row sheet-actions">
        <span className="spacer" />
        <button type="button" className="btn ghost" disabled={busy} onClick={onClose}>Cancel</button>
        <button type="button" className="btn primary" disabled={busy || target === undefined} onClick={() => void move()}>Move</button>
      </div>
    </Sheet>
  );
}

/**
 * Moves recordings into a project or the Inbox (one from its row menu, several from the selection bar), or into a new
 * project made here.
 */
export function MoveRecordingsDialog({ ids, title = null, current, projects, notify, onClose, onMoved, onProjectCreated }: {
  ids: readonly string[];
  /** The recording's title when one is moved. */
  title?: string | null;
  /** The project they are all in now (null: the Inbox; undefined: not all the same). */
  current?: string | null;
  projects: readonly ClientFolder[];
  notify: (text: string, error?: boolean) => void;
  onClose: () => void;
  onMoved?: (projectId: string | null) => void;
  onProjectCreated?: (project: ClientFolder) => void;
}) {
  const [target, setTarget] = useState<string | null | undefined>(undefined);
  const [busy, setBusy] = useState(false);
  const [creating, setCreating] = useState(false);
  const [name, setName] = useState("");
  const nameId = useId();
  const count = ids.length;
  const move = async (projectId: string | null, list: readonly ClientFolder[] = projects) => {
    setBusy(true);
    try {
      await moveRecordings(ids, projectId);
      notify(movedText(count, title, list, projectId));
      onMoved?.(projectId);
      onClose();
    } catch (e) {
      notify(errorMessage(e), true);
      setBusy(false);
    }
  };
  const createAndMove = async () => {
    const text = name.trim();
    if (!text || busy) return;
    setBusy(true);
    try {
      const { data } = await api.createClient(text);
      onProjectCreated?.(data);
      await move(data.id, [...projects, data]);
    } catch (e) {
      notify(errorMessage(e), true);
      setBusy(false);
    }
  };
  return (
    <Sheet title={count === 1 ? `Move “${title || "recording"}”` : `Move ${count} recordings`} onClose={onClose} busy={busy}>
      <ProjectPicker projects={projects} top={{ label: "Inbox", inbox: true }} value={target} onChange={setTarget} label="Move to"
        blocked={(id) => (current !== undefined && id === current ? (count === 1 ? "It is here now" : "They are here now") : null)} />
      {creating ? (
        <form className="row" onSubmit={(e) => { e.preventDefault(); void createAndMove(); }}>
          <label htmlFor={nameId} className="sr-only">New project name</label>
          <input id={nameId} className="input" placeholder="Project name" maxLength={120} autoFocus value={name} onChange={(e) => setName(e.target.value)} />
          <button type="submit" className="btn" disabled={busy || !name.trim()}>Create and move</button>
          <button type="button" className="btn ghost" disabled={busy} onClick={() => setCreating(false)}>Cancel</button>
        </form>
      ) : (
        <button type="button" className="btn text compact" style={{ justifySelf: "start" }} onClick={() => setCreating(true)}><PlusIcon size={14} />New project</button>
      )}
      <div className="row sheet-actions">
        <span className="spacer" />
        <button type="button" className="btn ghost" disabled={busy} onClick={onClose}>Cancel</button>
        <button type="button" className="btn primary" disabled={busy || target === undefined} onClick={() => target !== undefined && void move(target)}>Move</button>
      </div>
    </Sheet>
  );
}

/** Deletes a project after saying what happens to what is in it: nothing in it is lost. */
export function DeleteProjectDialog({ project, recordings, folders, parentName, onDelete, onClose }: {
  project: ClientFolder;
  /** Recordings directly in it (they go to the Inbox). */
  recordings: number;
  /** Its subfolders (they move up a level with their recordings). */
  folders: number;
  /** Where the subfolders go: the parent's name, null for the top level. */
  parentName: string | null;
  onDelete: () => Promise<boolean>;
  onClose: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const remove = async () => {
    setBusy(true);
    if (!(await onDelete())) setBusy(false);
  };
  return (
    <Sheet title={`Delete ${project.name}?`} onClose={onClose} busy={busy}>
      <p>{recordings > 0 ? `Its ${recordings === 1 ? "recording goes" : `${recordings} recordings go`} to the Inbox, so nothing is lost.` : "Its recordings go to the Inbox, so nothing is lost."}</p>
      {folders > 0 && <p>{`${folders === 1 ? "Its folder moves" : `Its ${folders} folders move`} up to ${parentName ?? "the top level"}, with ${folders === 1 ? "its" : "their"} recordings.`}</p>}
      <p className="small muted">Its files, brief, notes, memory and filing rules are deleted with it.</p>
      <div className="row sheet-actions">
        <span className="spacer" />
        <button type="button" className="btn ghost" disabled={busy} onClick={onClose}>Cancel</button>
        <button type="button" className="btn danger solid" disabled={busy} onClick={() => void remove()}>{busy ? "Deleting…" : "Delete project"}</button>
      </div>
    </Sheet>
  );
}
