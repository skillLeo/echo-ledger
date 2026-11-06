// The Library's smart folders in the sidebar (since v1.5): the Inbox, the call apps, voice notes, imported files and
// Shared with me, with how many recordings each holds. Each opens the Library on that folder; recordings dragged onto
// the Inbox leave their project.
import type { LibraryViews, SmartFolder } from "../../lib/ipc";
import { folderApp, shownFolders } from "../../lib/folders";
import { DROP_ATTRIBUTE } from "../../lib/dragMove";
import { DownloadIcon, InboxIcon, SourceAppIcon, UsersIcon } from "../../components/Icons";

function FolderGlyph({ folder }: { folder: SmartFolder }) {
  if (folder === "inbox") return <InboxIcon size={16} />;
  if (folder === "imported") return <DownloadIcon size={16} />;
  if (folder === "shared") return <UsersIcon size={16} />;
  return <SourceAppIcon app={folderApp(folder) ?? "other"} size={16} />;
}

export function SmartFolderList({ counts, open, onOpen }: {
  counts: LibraryViews["folders"];
  /** The folder the Library shows now (null: everything). */
  open: SmartFolder | null;
  onOpen: (folder: SmartFolder) => void;
}) {
  const folders = shownFolders(counts, open);
  if (folders.length === 0) return null;
  return (
    <ul className="project-tree in-sidebar smart-folders" aria-label="Smart folders">
      {folders.map(({ folder, label, count }) => (
        <li key={folder} className={`tree-row nav-sub ${open === folder ? "selected" : ""}`} {...(folder === "inbox" ? { [DROP_ATTRIBUTE]: "" } : {})}>
          <button type="button" className="tree-open" aria-current={open === folder ? "true" : undefined} onClick={() => onOpen(folder)}>
            <FolderGlyph folder={folder} />
            <span className="tree-name">{label}</span>
            {count > 0 && <span className="tree-count" aria-hidden="true">{count}</span>}
          </button>
        </li>
      ))}
    </ul>
  );
}
