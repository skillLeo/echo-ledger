// The project tree (since v1.5): projects and their subfolders, each project expanded or collapsed. The sidebar shows
// it under Projects, where recordings can be dropped on a project; the Projects page shows it with each project's
// recordings and last call.
import { useMemo } from "react";
import type { ClientFolder, LibraryItem } from "../../lib/ipc";
import { nestedCount, projectRecordings, projectTree, visibleRows } from "../../lib/projects";
import { ChevronRightIcon, FolderIcon } from "../../components/Icons";
import { DROP_ATTRIBUTE } from "../../lib/dragMove";
import { ClientAvatar, clientSummary } from "./ClientAvatar";

export function ProjectTree({ projects, label, variant, selectedId = null, expanded, onToggle, onOpen, items = [], dropTargets = false }: {
  projects: readonly ClientFolder[];
  /** The list's name for screen readers. */
  label: string;
  variant: "sidebar" | "page";
  selectedId?: string | null;
  expanded: ReadonlySet<string>;
  onToggle: (id: string) => void;
  onOpen: (id: string) => void;
  /** The saved library: counts and last calls when the server sent none. */
  items?: readonly LibraryItem[];
  /** Rows take recordings dragged onto them (the sidebar). */
  dropTargets?: boolean;
}) {
  const tree = useMemo(() => projectTree(projects), [projects]);
  const rows = visibleRows(tree, expanded);
  return (
    <ul className={`project-tree ${variant}`} aria-label={label}>
      {rows.map(({ project, depth, children }) => {
        const open = expanded.has(project.id);
        const count = nestedCount(project, items, projects);
        const drop = dropTargets ? { [DROP_ATTRIBUTE]: project.id } : {};
        return (
          <li key={project.id} className={`tree-row ${project.id === selectedId ? "selected" : ""}`} style={{ ["--depth" as string]: depth - 1 }} {...drop}>
            {children.length > 0 ? (
              <button type="button" className={`tree-toggle ${open ? "open" : ""}`} aria-expanded={open}
                aria-label={`${open ? "Hide" : "Show"} the folders in ${project.name}`} onClick={() => onToggle(project.id)}>
                <ChevronRightIcon size={14} />
              </button>
            ) : <span className="tree-toggle" aria-hidden="true" />}
            <button type="button" className="tree-open" aria-current={project.id === selectedId ? "true" : undefined} title={variant === "sidebar" ? project.name : undefined}
              onClick={() => onOpen(project.id)}>
              {variant === "page" ? <ClientAvatar client={project} /> : <FolderIcon size={16} />}
              {variant === "page" ? (
                <span className="client-main">
                  <span className="client-name">{project.name}</span>
                  <span className="client-sub">{clientSummary(count, projectRecordings(items, projects, project.id, true)[0]?.started_at)}{children.length > 0 ? ` · ${children.length} folder${children.length === 1 ? "" : "s"}` : ""}</span>
                </span>
              ) : (
                <>
                  <span className="tree-name">{project.name}</span>
                  {count > 0 && <span className="tree-count" aria-hidden="true">{count}</span>}
                </>
              )}
            </button>
          </li>
        );
      })}
    </ul>
  );
}
