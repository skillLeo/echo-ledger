// The Projects page in the new design (classic's ProjectsView list, the same rules and words): every project as a tree
// with its recordings and last call, folders under their project (shown or hidden, kept on this computer); Request
// files and New project at the top; an error with Retry, still shapes while loading, and the empty state. The name
// dialog (New project, New folder inside, Rename) is the new design's too.
import { useMemo, useState } from "react";
import type { ClientFolder, LibraryItem } from "../../lib/ipc";
import { nestedCount, projectRecordings, projectTree, visibleRows } from "../../lib/projects";
import { clientSummary } from "../../views/main/ClientAvatar";
import { Avatar, Banner, Button, Dialog, EmptyState, Icon, Icons, List, Loading, SkeletonRow, TextField } from "../components";
import { initials } from "../settings/initials";

export type ProjectsListV2Props = {
  /** Every project; null while they load. */
  projects: readonly ClientFolder[] | null;
  error: string | null;
  onRetry: () => void;
  /** The saved library: each project's recordings and last call. */
  items: readonly LibraryItem[];
  expanded: ReadonlySet<string>;
  onToggle: (id: string) => void;
  onOpen: (id: string) => void;
  onNew: () => void;
  /** Request files (when the server has request links). */
  onRequest?: (() => void) | null;
  /**
   * The three levels (PLAN_NAV_3_LEVELS): the Projects panel has the tree, New project and Request files, so this page
   * is an overview of the top-level projects as cards (recordings, last call, folders, pinned) and repeats neither.
   */
  overview?: boolean;
};

/** "3 recordings · last call Today 09:12 · 2 folders" (classic's line under a project). */
export function projectLine(project: ClientFolder, projects: readonly ClientFolder[], items: readonly LibraryItem[], folders: number): string {
  const count = nestedCount(project, items, projects);
  const last = projectRecordings(items, projects, project.id, true)[0]?.started_at;
  return `${clientSummary(count, last)}${folders > 0 ? ` · ${folders} folder${folders === 1 ? "" : "s"}` : ""}`;
}

export function ProjectsListV2({ projects, error, onRetry, items, expanded, onToggle, onOpen, onNew, onRequest, overview = false }: ProjectsListV2Props) {
  const rows = useMemo(() => (projects ? visibleRows(projectTree(projects), expanded) : []), [projects, expanded]);
  const names = useMemo(() => new Map((projects ?? []).map((p) => [p.id, p.name])), [projects]);
  return (
    <div className="el-page el-page--narrow" data-v2="projects">
      <header className="el-page__head">
        <div className="el-page__titles">
          <h1 className="el-title-l">Projects</h1>
          <p className="el-secondary">Calls, files, a brief and notes together, for each client or piece of work. Recordings in no project are in All recordings, under Project › No project.</p>
        </div>
        {!overview && (
          <div className="el-page__actions">
            {onRequest && <Button icon={Icons.RequestFilesIcon} title="A link anyone can use to send you voice notes and files" onClick={onRequest}>Request files</Button>}
            <Button variant="primary" icon={Icons.PlusIcon} onClick={onNew}>New project</Button>
          </div>
        )}
      </header>
      {error && (
        <Banner tone="danger" icon={Icons.ErrorIcon} testId="projects-error" action={<Button size="sm" onClick={onRetry}>Retry</Button>}>
          {`Couldn't load the projects. ${error}`}
        </Banner>
      )}
      {projects === null && !error ? (
        <Loading label="Loading projects">{[0, 1, 2].map((n) => <SkeletonRow key={n} />)}</Loading>
      ) : projects && projects.length === 0 ? (
        <EmptyState icon={Icons.FolderIcon} title="No projects yet"
          body={overview ? "A project keeps its calls, files, a brief and notes together, and Ask answers from all of them. Make one with New project in the panel."
            : "A project keeps its calls, files, a brief and notes together, and Ask answers from all of them."}
          action={overview ? undefined : <Button variant="primary" icon={Icons.PlusIcon} onClick={onNew}>New project</Button>} />
      ) : projects && projects.length > 0 && overview ? (
        <ul className="el-project-cards" aria-label="Projects">
          {projectTree(projects).map(({ project, children }) => (
            <li key={project.id}>
              <button type="button" className="el-project-card" onClick={() => onOpen(project.id)}>
                <span className="el-project-card__head">
                  <Avatar initials={initials(project.name, "")} size="sm" />
                  <span className="el-body-strong el-project-card__name" dir="auto">{project.name}</span>
                  {project.pinned && <span className="el-project-card__pin" title="Pinned"><Icon icon={Icons.PinIcon} /><span className="el-visually-hidden">, pinned</span></span>}
                </span>
                <span className="el-caption el-secondary">{projectLine(project, projects, items, children.length)}</span>
              </button>
            </li>
          ))}
        </ul>
      ) : projects && projects.length > 0 ? (
        <List label="Projects" className="el-projects">
          {rows.map(({ project, depth, children }) => {
            const open = expanded.has(project.id);
            return (
              <li key={project.id} className="el-row el-projects__row" style={{ ["--el-tree-depth" as string]: String(depth - 1) }}>
                {children.length > 0 ? (
                  <button type="button" className="el-projects__fold" aria-expanded={open} aria-label={`${open ? "Hide" : "Show"} the folders in ${project.name}`}
                    onClick={() => onToggle(project.id)}>
                    <Icon icon={open ? Icons.ChevronDownIcon : Icons.ChevronEndIcon} directional />
                  </button>
                ) : <span className="el-projects__fold" aria-hidden="true" />}
                <button type="button" className="el-projects__open" onClick={() => onOpen(project.id)}>
                  <Avatar initials={initials(project.name, "")} size="sm" />
                  <span className="el-projects__text">
                    <span className="el-body-strong" dir="auto">{project.name}</span>
                    {/* Where it sits, for screen readers (the indent says it on screen): "folder in Acme Holdings, level 2". */}
                    {depth > 1 && <span className="el-visually-hidden">{`, folder in ${names.get(project.parent_id ?? "") ?? "a project"}, level ${depth}`}</span>}
                    <span className="el-caption el-secondary">{projectLine(project, projects, items, children.length)}</span>
                  </span>
                </button>
              </li>
            );
          })}
        </List>
      ) : null}
    </div>
  );
}

/** New project, New folder inside, Rename: one name, kept to 120 characters; the button waits until it changes. */
export function NameDialogV2({ title, initial = "", submitLabel, hint, onSubmit, onClose }: {
  title: string;
  initial?: string;
  submitLabel: string;
  hint?: string;
  onSubmit: (name: string) => Promise<boolean>;
  onClose: () => void;
}) {
  const [name, setName] = useState(initial);
  const [busy, setBusy] = useState(false);
  const text = name.trim();
  const can = !!text && text !== initial.trim();
  const submit = async () => {
    if (!can || busy) return;
    setBusy(true);
    if (!(await onSubmit(text))) setBusy(false);
  };
  return (
    <Dialog open title={title} description={hint} onClose={() => { if (!busy) onClose(); }}
      actions={<>
        <Button disabled={busy} onClick={onClose}>Cancel</Button>
        <Button variant="primary" disabled={!can} loading={busy} loadingLabel="Saving…" onClick={() => void submit()}>{submitLabel}</Button>
      </>}>
      <form onSubmit={(e) => { e.preventDefault(); void submit(); }}>
        <TextField label="Name" value={name} maxLength={120} dir="auto" autoFocus onFocus={(e) => e.currentTarget.select()} onChange={(e) => setName(e.target.value)} />
      </form>
    </Dialog>
  );
}
