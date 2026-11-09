// The nested column of the Library and of Projects (v1.6, P4.2): the Library's places with their counts (All, Waiting,
// Pinned, Failed uploads, then the smart folders), and the project list with "+ New project". Recordings dragged from
// the list onto the Inbox or a project move there (lib/dragMove.ts, the same move as the Move dialog).
import type { ClientFolder, SmartFolder } from "../../lib/ipc";
import type { LibraryPlace, LibraryView } from "../../lib/folders";
import { folderApp } from "../../lib/folders";
import { DROP_ATTRIBUTE } from "../../lib/dragMove";
import { AlertIcon, DownloadIcon, HourglassIcon, InboxIcon, LibraryIcon, PinIcon, PlusIcon, SourceAppIcon, UsersIcon } from "../../components/Icons";
import { ProjectTree } from "./ProjectTree";

export function PlaceGlyph({ place }: { place: Pick<LibraryPlace, "folder" | "view"> }) {
  if (place.view === "waiting") return <HourglassIcon size={16} />;
  if (place.view === "pinned") return <PinIcon size={16} />;
  if (place.view === "failed") return <AlertIcon size={16} />;
  const folder = place.folder;
  if (!folder) return <LibraryIcon size={16} />;
  if (folder === "inbox") return <InboxIcon size={16} />;
  if (folder === "imported") return <DownloadIcon size={16} />;
  if (folder === "shared") return <UsersIcon size={16} />;
  return <SourceAppIcon app={folderApp(folder) ?? "other"} size={16} />;
}

/** Whether a place is the one the Library shows. */
export function isOpenPlace(place: Pick<LibraryPlace, "folder" | "view">, folder: SmartFolder | null, view: LibraryView | null): boolean {
  return place.folder === folder && place.view === view;
}

/** The places as rows: a glyph, the name and the count; a view that needs the person counts in the accent colour. */
export function PlaceList({ places, folder, view, onOpen, label, compact = false }: {
  places: readonly LibraryPlace[];
  folder: SmartFolder | null;
  view: LibraryView | null;
  onOpen: (place: LibraryPlace) => void;
  label: string;
  /** The sidebar's short form: no "All", and only the places that hold something (the Inbox always, it takes drops). */
  compact?: boolean;
}) {
  const rows = compact ? places.filter((p) => p.key !== "all" && (p.count > 0 || p.drop || p.folder === "shared" || isOpenPlace(p, folder, view))) : places;
  return (
    <ul className={`place-list ${compact ? "in-sidebar" : "in-column"}`} aria-label={label}>
      {rows.map((place) => {
        const open = isOpenPlace(place, folder, view);
        const attention = (place.view === "waiting" || place.view === "failed") && place.count > 0;
        return (
          <li key={place.key} className={`place-row ${open ? "selected" : ""} ${place.count === 0 && !open ? "empty" : ""}`} {...(place.drop ? { [DROP_ATTRIBUTE]: "" } : {})}>
            <button type="button" className="place-open" aria-current={open ? "true" : undefined} onClick={() => onOpen(place)}>
              <PlaceGlyph place={place} />
              <span className="place-name">{place.label}</span>
              {place.count > 0 && <span className={`place-count ${attention ? "attention" : ""}`} aria-hidden="true">{place.count}</span>}
            </button>
          </li>
        );
      })}
    </ul>
  );
}

/** The Library's nested column: its places, then the projects (recordings dragged onto one move into it). */
export function LibraryColumn({ places, folder, view, onOpen, projects = null, expanded, onToggle, onProject }: {
  places: readonly LibraryPlace[];
  folder: SmartFolder | null;
  view: LibraryView | null;
  onOpen: (place: LibraryPlace) => void;
  projects?: readonly ClientFolder[] | null;
  expanded?: ReadonlySet<string>;
  onToggle?: (id: string) => void;
  onProject?: (id: string) => void;
}) {
  return (
    <nav className="subnav" aria-label="Library folders">
      <PlaceList places={places} folder={folder} view={view} onOpen={onOpen} label="Smart folders" />
      {projects && projects.length > 0 && expanded && onToggle && onProject && (
        <>
          <div className="subnav-head">Projects</div>
          <ProjectTree projects={projects} label="Projects in the column" variant="sidebar" expanded={expanded} onToggle={onToggle} onOpen={onProject} dropTargets />
        </>
      )}
      <p className="subnav-hint small muted">Drag recordings onto the Inbox or a project to move them.</p>
    </nav>
  );
}

/** The Projects page's nested column: every project (subfolders fold), drop targets, and "+ New project". */
export function ProjectsColumn({ projects, selectedId, expanded, onToggle, onOpen, onNew }: {
  projects: readonly ClientFolder[] | null;
  selectedId: string | null;
  expanded: ReadonlySet<string>;
  onToggle: (id: string) => void;
  onOpen: (id: string | null) => void;
  onNew: () => void;
}) {
  return (
    <nav className="subnav" aria-label="Project list">
      <ul className="place-list in-column" aria-label="Every project">
        <li className={`place-row ${selectedId === null ? "selected" : ""}`}>
          <button type="button" className="place-open" aria-current={selectedId === null ? "true" : undefined} onClick={() => onOpen(null)}>
            <LibraryIcon size={16} /><span className="place-name">All projects</span>
            {projects && projects.length > 0 && <span className="place-count" aria-hidden="true">{projects.length}</span>}
          </button>
        </li>
      </ul>
      {projects && projects.length > 0 && (
        <ProjectTree projects={projects} label="Projects in the column" variant="sidebar" selectedId={selectedId} expanded={expanded} onToggle={onToggle}
          onOpen={(id) => onOpen(id)} dropTargets />
      )}
      <button type="button" className="btn text compact subnav-add" onClick={onNew}><PlusIcon size={16} />New project</button>
    </nav>
  );
}
