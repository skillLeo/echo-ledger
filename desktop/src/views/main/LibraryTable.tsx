// The Library as a table (v1.6, P4.4): one row per recording with the columns the person chose (Columns), the group
// headers as sticky rows, the row's actions on hover, and the recording opening in the drawer on the right.
import type { ReactNode } from "react";
import type { LibraryItem } from "../../lib/ipc";
import type { ItemGroup } from "../../lib/libraryGroups";
import { formatDuration, formatWhen, sourceAppLabel } from "../../lib/format";
import { itemState } from "../../lib/library";
import { stepOf } from "../../lib/progress";
import { AppTileIcon, appKind, PinIcon } from "../../components/Icons";
import { GroupHeader } from "./LibraryGrouping";
import { dragJustEnded } from "../../lib/dragMove";

export type TableColumn = "when" | "length" | "app" | "project" | "status" | "labels" | "device";
/** The optional columns in their order (the title is always there). */
export const TABLE_COLUMNS: readonly (readonly [TableColumn, string])[] = [
  ["when", "Date"], ["length", "Length"], ["app", "App"], ["project", "Project"], ["status", "Status"], ["labels", "Labels"], ["device", "Device"],
];
export const DEFAULT_COLUMNS: readonly TableColumn[] = ["when", "length", "app", "project", "status"];
const KEY = "echoledger-library-columns";

export function loadColumns(): Set<TableColumn> {
  try {
    const saved = JSON.parse(window.localStorage.getItem(KEY) ?? "null");
    if (Array.isArray(saved)) return new Set(saved.filter((c): c is TableColumn => TABLE_COLUMNS.some(([id]) => id === c)));
  } catch {
    // Not ours: the default columns.
  }
  return new Set(DEFAULT_COLUMNS);
}
export function saveColumns(columns: ReadonlySet<TableColumn>): void {
  try {
    window.localStorage.setItem(KEY, JSON.stringify(TABLE_COLUMNS.map(([id]) => id).filter((id) => columns.has(id))));
  } catch {
    // Storage can be unavailable; the choice then lasts until the app restarts.
  }
}

const STATE_TEXT = { ready: "Ready", processing: "Transcribing", failed: "Transcription failed", no_audio: "No audio" } as const;

function cell(item: LibraryItem, column: TableColumn): ReactNode {
  switch (column) {
    case "when":
      return formatWhen(item.started_at);
    case "length":
      return formatDuration(item.duration_ms);
    case "app":
      return item.source_app_label ?? (item.source_app ? sourceAppLabel(item.source_app) : "");
    case "project":
      return item.client?.name ?? "Inbox";
    case "status": {
      const state = itemState(item);
      return <span className={`pill ${state}`}>{state === "processing" ? stepOf(item).line ?? STATE_TEXT[state] : STATE_TEXT[state]}</span>;
    }
    case "labels":
      return (item.labels ?? []).map((l) => <span key={l} className="label-chip small">{l}</span>);
    case "device":
      return item.device?.name ?? item.source_device_label ?? "";
  }
}

export function LibraryTable({ groups, columns, collapsed, onToggleGroup, picked, selecting, selected, cursor, onOpen, onPick, onPickGroup, onPointerDown, menu }: {
  groups: readonly ItemGroup[];
  columns: ReadonlySet<TableColumn>;
  collapsed: ReadonlySet<string>;
  onToggleGroup: (key: string) => void;
  picked: ReadonlySet<string>;
  selecting: boolean;
  /** The recording in the drawer. */
  selected: string | null;
  /** The row the keys are on (J / K). */
  cursor: string | null;
  onOpen: (item: LibraryItem) => void;
  onPick: (id: string) => void;
  onPickGroup: (items: readonly LibraryItem[]) => void;
  onPointerDown: (e: React.PointerEvent, item: LibraryItem) => void;
  menu: (item: LibraryItem) => ReactNode;
}) {
  const shown = TABLE_COLUMNS.filter(([id]) => columns.has(id));
  const span = shown.length + 2;
  return (
    <table className="lib-table" aria-label="Recordings">
      <thead>
        <tr>
          <th scope="col" className="col-title">Recording</th>
          {shown.map(([id, name]) => <th key={id} scope="col" className={`col-${id}`}>{name}</th>)}
          <th scope="col" className="col-actions"><span className="sr-only">Actions</span></th>
        </tr>
      </thead>
      {groups.map((group) => (
        <tbody key={group.key}>
          {group.label && (
            <tr className="group-row">
              <th colSpan={span} scope="colgroup">
                <GroupHeader label={group.label} items={group.items} picked={picked} collapsed={collapsed.has(group.key)}
                  onToggle={() => onToggleGroup(group.key)} onPick={() => onPickGroup(group.items)} />
              </th>
            </tr>
          )}
          {!collapsed.has(group.key) && group.items.map((item) => (
            <tr key={item.id} data-row={item.id} className={`lib-row ${item.id === selected ? "selected" : ""} ${item.id === cursor ? "cursor" : ""} ${selecting && picked.has(item.id) ? "picked" : ""}`}
              aria-selected={selecting ? picked.has(item.id) : item.id === selected} onPointerDown={(e) => onPointerDown(e, item)}
              onClick={(e) => {
                if (dragJustEnded() || (e.target as HTMLElement).closest(".row-menu")) return;
                if (selecting) onPick(item.id);
                else onOpen(item);
              }}>
              <td className="col-title">
                <span className="lib-title-cell">
                  {selecting && <input type="checkbox" className="row-box" checked={picked.has(item.id)} readOnly tabIndex={-1} aria-label={`Select ${item.title ?? "recording"}`} />}
                  <span className={`avatar small app-${appKind(item.source_app)}`}><AppTileIcon app={item.source_app} /></span>
                  <button type="button" className="lib-title" dir="auto" onClick={(e) => { e.stopPropagation(); if (selecting) onPick(item.id); else onOpen(item); }}>{item.title ?? "Recording"}</button>
                  {item.pinned && <span className="pin-star" aria-label="Pinned"><PinIcon size={14} /></span>}
                </span>
              </td>
              {shown.map(([id]) => <td key={id} className={`col-${id}`}>{cell(item, id)}</td>)}
              <td className="col-actions">{!selecting && <div className="item-wrap with-menu table-menu">{menu(item)}</div>}</td>
            </tr>
          ))}
        </tbody>
      ))}
    </table>
  );
}
