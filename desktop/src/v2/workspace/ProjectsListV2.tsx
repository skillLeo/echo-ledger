// The Projects page in the new design (classic's ProjectsView list, the same rules and words): every project as a tree
// with its recordings and last call, folders under their project (shown or hidden, kept on this computer); Request
// files and New project at the top; an error with Retry, still shapes while loading, and the empty state. The name
// dialog (New project, New folder inside, Rename) is the new design's too.
// With the three levels (UX-09) it is All projects, the page's title in the title row: the top-level projects as
// cards, pinned first, sorted as the panel is (Name or Recent); with no project, one empty state that says how to start.
// Every word is the window's language (D-259); a project's name is its own.
import { PanelToggle } from "../shell/NavRail";
import { useMemo, useState } from "react";
import { usePageTitle } from "../pageTitle";
import { PROJECT_SORTS, projectOrder, useProjectSort } from "../shell/projectSort";
import type { ClientFolder, LibraryItem } from "../../lib/ipc";
import { nestedCount, projectRecordings, projectTree, visibleRows } from "../../lib/projects";
import { Avatar, Banner, Button, Dialog, EmptyState, Icon, Icons, List, Loading, SkeletonRow, TextField } from "../components";
import { Menu } from "../components/Menu";
import { initials } from "../settings/initials";
import { whenParts } from "../../lib/format";
import { lineDir, t, tc, useLanguage, wordsIn, type Lang } from "../../i18n";

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
   * The three levels (PLAN_NAV_3_LEVELS, UX-09): the Projects panel has the project list, so this page is All projects,
   * an overview of the top-level projects as cards (recordings, last call, folders, pinned), with Sort, Request files
   * and New project.
   */
  overview?: boolean;
};

/** With no project yet (UX-09): what a project is for, and the two ways to start; in the window's language, or `lang`. */
export function noProjects(lang?: Lang): string {
  return wordsIn(lang).t("Keep each client's calls, files and notes together");
}
/** Its English (the empty state's name). */
export const NO_PROJECTS = noProjects("en");

/**
 * "3 recordings · Last call Today · 2 folders", or "No recordings yet" (classic's line under a project, clientSummary's
 * words), in the window's language: "3 ریکارڈنگز · آخری کال آج · 2 فولڈرز", each value in its own isolate.
 */
export function projectLine(project: ClientFolder, projects: readonly ClientFolder[], items: readonly LibraryItem[], folders: number): string {
  const count = nestedCount(project, items, projects);
  const day = whenParts(projectRecordings(items, projects, project.id, true)[0]?.started_at)?.day;
  const summary = count === 0 ? t("No recordings yet")
    : `${tc(":count recording|:count recordings", count)}${day ? ` · ${t("Last call :date", { date: day })}` : ""}`;
  return `${summary}${folders > 0 ? ` · ${tc(":count folder|:count folders", folders)}` : ""}`;
}

/** A project's line under its name, its direction from its own words (in English dir="auto", as it always was). */
const Line = ({ className, children }: { className: string; children: string }) => <span className={className} dir={lineDir(children)}>{children}</span>;

export function ProjectsListV2({ projects, error, onRetry, items, expanded, onToggle, onOpen, onNew, onRequest, overview = false }: ProjectsListV2Props) {
  useLanguage();
  const rows = useMemo(() => (projects ? visibleRows(projectTree(projects), expanded) : []), [projects, expanded]);
  const names = useMemo(() => new Map((projects ?? []).map((p) => [p.id, p.name])), [projects]);
  const [sort, setSort] = useProjectSort();
  const none = !!projects && projects.length === 0;
  const sortLabel = t(PROJECT_SORTS.find((o) => o.value === sort)!.label);
  const cards = useMemo(() => {
    if (!projects) return [];
    // The comparator is made once (it reads the Recent list), not for every comparison.
    const order = projectOrder(sort);
    return projectTree(projects).sort((a, b) => order(a.project, b.project));
  }, [projects, sort]);
  // All projects is the title row's title (UX-09), with how many cards it shows (folders are inside theirs); the page
  // then has no title of its own.
  const inTitleRow = usePageTitle(overview ? { title: t("All projects"), count: cards.length > 0 ? cards.length : null } : null);
  const requestButton = onRequest && (
    <Button icon={Icons.RequestFilesIcon} title={t("A link anyone can use to send you voice notes and files")} onClick={onRequest}>{t("Request files")}</Button>
  );
  return (
    <div className="el-page el-page--narrow" data-v2="projects">
      {overview ? (
        <header className={`el-page__head${inTitleRow ? " el-page__head--bar" : ""}`}>
          {!inTitleRow && <><PanelToggle /><h1 className="el-title-l">{t("All projects")}</h1></>}
          {!none && projects && (
            <>
              <Menu label={t("Sort projects")} align="start"
                items={PROJECT_SORTS.map((o) => ({ label: t(o.label), radio: true, checked: o.value === sort, onPick: () => setSort(o.value) }))}
                // Named by its words ("Sort: Name"), so what it shows is its name (FIX-06, label in name).
                button={{ variant: "quiet", icon: Icons.SortIcon, iconEnd: Icons.ChevronDownIcon, text: t("Sort: :sort", { sort: sortLabel }) }} />
              <div className="el-page__actions">
                {requestButton}
                <Button variant="primary" icon={Icons.PlusIcon} onClick={onNew}>{t("New project")}</Button>
              </div>
            </>
          )}
        </header>
      ) : (
        <header className="el-page__head">
          <PanelToggle />
          <div className="el-page__titles">
            <h1 className="el-title-l">{t("Projects")}</h1>
            <p className="el-secondary">{t("Calls, files, a brief and notes together, for each client or piece of work. Recordings in no project are in All recordings, under Project › No project.")}</p>
          </div>
          {!none && (
            <div className="el-page__actions">
              {requestButton}
              <Button variant="primary" icon={Icons.PlusIcon} onClick={onNew}>{t("New project")}</Button>
            </div>
          )}
        </header>
      )}
      {error && (
        <Banner tone="danger" icon={Icons.ErrorIcon} testId="projects-error" action={<Button size="sm" onClick={onRetry}>{t("Retry")}</Button>}>
          {`${t("Couldn't load the projects.")} ${error}`}
        </Banner>
      )}
      {projects === null && !error ? (
        <Loading label={t("Loading projects")}>{[0, 1, 2].map((n) => <SkeletonRow key={n} />)}</Loading>
      ) : none ? (
        // UX-09: one empty state, its two ways to start (there is no panel to point to).
        <EmptyState icon={Icons.FolderIcon} title={noProjects()}
          action={<>
            <Button variant="primary" icon={Icons.PlusIcon} onClick={onNew}>{t("New project")}</Button>
            {onRequest && <Button icon={Icons.RequestFilesIcon} title={t("A link anyone can use to send you voice notes and files")} onClick={onRequest}>{t("Request files from a client")}</Button>}
          </>} />
      ) : projects && projects.length > 0 && overview ? (
        <ul className="el-project-cards" aria-label={t("Projects")}>
          {cards.map(({ project, children }) => (
            <li key={project.id}>
              <button type="button" className="el-project-card" onClick={() => onOpen(project.id)}>
                <span className="el-project-card__head">
                  <Avatar initials={initials(project.name, "")} size="sm" />
                  <span className="el-body-strong el-project-card__name" dir="auto">{project.name}</span>
                  {project.pinned && <span className="el-project-card__pin" title={t("Pinned")}><Icon icon={Icons.PinIcon} /><span className="el-visually-hidden">{t(", pinned")}</span></span>}
                </span>
                <Line className="el-caption el-secondary">{projectLine(project, projects, items, children.length)}</Line>
              </button>
            </li>
          ))}
        </ul>
      ) : projects && projects.length > 0 ? (
        <List label={t("Projects")} className="el-projects">
          {rows.map(({ project, depth, children }) => {
            const open = expanded.has(project.id);
            return (
              <li key={project.id} className="el-row el-projects__row" style={{ ["--el-tree-depth" as string]: String(depth - 1) }}>
                {children.length > 0 ? (
                  <button type="button" className="el-projects__fold" aria-expanded={open} aria-label={open ? t("Hide the folders in :name", { name: project.name }) : t("Show the folders in :name", { name: project.name })}
                    onClick={() => onToggle(project.id)}>
                    <Icon icon={open ? Icons.ChevronDownIcon : Icons.ChevronEndIcon} directional />
                  </button>
                ) : <span className="el-projects__fold" aria-hidden="true" />}
                <button type="button" className="el-projects__open" onClick={() => onOpen(project.id)}>
                  <Avatar initials={initials(project.name, "")} size="sm" />
                  <span className="el-projects__text">
                    <span className="el-body-strong" dir="auto">{project.name}</span>
                    {/* Where it sits, for screen readers (the indent says it on screen): "folder in Acme Holdings, level 2". */}
                    {depth > 1 && <span className="el-visually-hidden">{t(", folder in :name, level :level", { name: names.get(project.parent_id ?? "") ?? t("a project"), level: depth })}</span>}
                    <Line className="el-caption el-secondary">{projectLine(project, projects, items, children.length)}</Line>
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
  useLanguage();
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
        <Button disabled={busy} onClick={onClose}>{t("Cancel")}</Button>
        <Button variant="primary" disabled={!can} loading={busy} loadingLabel={t("Saving…")} onClick={() => void submit()}>{submitLabel}</Button>
      </>}>
      <form onSubmit={(e) => { e.preventDefault(); void submit(); }}>
        <TextField label={t("Name")} value={name} maxLength={120} dir="auto" autoFocus onFocus={(e) => e.currentTarget.select()} onChange={(e) => setName(e.target.value)} />
      </form>
    </Dialog>
  );
}
