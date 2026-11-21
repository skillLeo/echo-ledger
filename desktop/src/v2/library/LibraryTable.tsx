// The Library as a table (classic's "Show as: Table", parity.csv DT-LIB-045..056): the columns people choose (kept on
// this computer), sections as header rows, a row that opens or (selecting) ticks its recording, its own ⋮ and a
// right-click, the same as the list's rows.
import type { KeyboardEvent, MouseEvent, PointerEvent, ReactNode } from "react";
import type { LibraryItem, ViewsSummary } from "../../lib/ipc";
import { rowViewsLine } from "../../lib/progress";
import type { ItemGroup } from "../../lib/libraryGroups";
import { formatDuration, rowDate } from "../../lib/format";
import { itemState, recordingName } from "../../lib/library";
import { sourceLabel } from "../../lib/libraryGroups";
import { Badge, Icon, Icons } from "../components";
import { RECOVERED_CHIP } from "../../lib/recovered";
import type { MenuItem } from "../components/Menu";
import { SourceAvatar } from "./SourceAvatar";

export type Layout = "list" | "table";
export type Column = "date" | "length" | "app" | "project" | "status" | "labels" | "device";
export const COLUMN_NAMES: Record<Column, string> = {
  date: "Date", length: "Length", app: "App", project: "Project", status: "Status", labels: "Labels", device: "Device",
};
export const COLUMNS = Object.keys(COLUMN_NAMES) as Column[];
export const DEFAULT_COLUMNS: readonly Column[] = ["date", "length", "app", "project", "status"];

const LAYOUT_KEY = "echoledger-v2-library-layout";
const COLUMNS_KEY = "echoledger-v2-library-columns";
const read = (key: string): string | null => {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
};
const write = (key: string, value: string) => {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    // Storage can be unavailable: the choice lasts until the window closes.
  }
};
export const loadLayout = (): Layout => (read(LAYOUT_KEY) === "table" ? "table" : "list");
export const saveLayout = (l: Layout) => write(LAYOUT_KEY, l);
export function loadColumns(): Column[] {
  try {
    const saved = JSON.parse(read(COLUMNS_KEY) ?? "null");
    if (Array.isArray(saved)) return COLUMNS.filter((c) => saved.includes(c));
  } catch {
    // A bad value: the defaults.
  }
  return [...DEFAULT_COLUMNS];
}
export const saveColumns = (c: readonly Column[]) => write(COLUMNS_KEY, JSON.stringify(c));

const STATUS_WORDS = { ready: "Ready", processing: "Transcribing", failed: "Failed", no_audio: "No audio" } as const;
const DEVICE_WORDS: Record<string, string> = { phone: "Phone", desktop: "Computer", web: "Web" };

function cell(item: LibraryItem, column: Column, views: ViewsSummary | null): ReactNode {
  switch (column) {
    case "date": return rowDate(item.started_at);
    case "length": return <span className="el-num">{formatDuration(item.duration_ms)}</span>;
    case "app": return sourceLabel(item);
    case "project": return item.client?.name ?? "Inbox";
    case "status": {
      // A ready transcript whose default view is still written: "Converting to Roman Urdu… 60%" (U19).
      const converting = itemState(item) === "ready" ? rowViewsLine(views) : null;
      if (converting?.state === "making") return <span className="el-converting"><span className="el-spinner" aria-hidden="true" />{converting.text}</span>;
      if (converting?.state === "failed") return <span className="el-converting el-converting--danger" data-testid="table-convert-failed"><Icon icon={Icons.ErrorIcon} size="sm" />{converting.text}</span>;
      return STATUS_WORDS[itemState(item)];
    }
    case "labels": return (item.labels ?? []).join(", ");
    case "device": return item.device?.name ?? (item.source_device ? DEVICE_WORDS[item.source_device] : "");
  }
}

/** The Columns menu: one tick per column (Recording is always there). */
export function columnsMenu(columns: readonly Column[], onChange: (c: Column[]) => void): MenuItem[] {
  return COLUMNS.map((c) => ({
    label: COLUMN_NAMES[c], checked: columns.includes(c), keepOpen: true,
    onPick: () => onChange(columns.includes(c) ? columns.filter((x) => x !== c) : COLUMNS.filter((x) => x === c || columns.includes(x))),
  }));
}

export function LibraryTable({ groups, columns, collapsed, onFold, selecting, picked, onRow, onRowMenu, onRowPointerDown, rowActions, recovered, viewsOf }: {
  /** A row's views as known last (the Library asks while they are written); its own otherwise. */
  viewsOf?: (item: LibraryItem) => ViewsSummary | null;
  /** Recordings recovered after a restart: their "Recovered" chip. */
  recovered?: ReadonlySet<string>;
  groups: readonly ItemGroup[];
  columns: readonly Column[];
  collapsed: ReadonlySet<string>;
  onFold: (key: string) => void;
  selecting: boolean;
  picked: ReadonlySet<string>;
  onRow: (item: LibraryItem, e: MouseEvent<HTMLButtonElement>) => void;
  onRowMenu: (item: LibraryItem, e: MouseEvent<HTMLElement> | KeyboardEvent<HTMLButtonElement>) => void;
  /** A press that may become a drag onto a project or the Inbox (lib/dragMove.ts), as a list row. */
  onRowPointerDown?: (item: LibraryItem, e: PointerEvent<HTMLButtonElement>) => void;
  rowActions: (item: LibraryItem) => ReactNode;
}) {
  const span = columns.length + 2;
  return (
    <div className="el-table-wrap">
      <table className="el-table" aria-label="Recordings">
        <thead>
          <tr>
            <th scope="col">Recording</th>
            {columns.map((c) => <th key={c} scope="col">{COLUMN_NAMES[c]}</th>)}
            <th scope="col"><span className="el-visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          {groups.map((g) => [
            g.label ? (
              <tr key={`g-${g.key}`} className="el-table__group">
                <th colSpan={span} scope="rowgroup">
                  <button type="button" className="el-list-section__button" aria-expanded={!collapsed.has(g.key)} onClick={() => onFold(g.key)}
                    aria-label={`${g.label} · ${g.items.length}`}>
                    <Icon icon={Icons.ChevronDownIcon} />{g.label}<span className="el-list-section__count"> · <span className="el-num">{g.items.length}</span></span>
                  </button>
                </th>
              </tr>
            ) : null,
            ...(collapsed.has(g.key) ? [] : g.items).map((item) => {
              const name = recordingName(item.title);
              const on = picked.has(item.id);
              return (
                <tr key={item.id} className={on ? "el-table__row el-table__row--picked" : "el-table__row"}
                  onContextMenu={(e) => { e.preventDefault(); onRowMenu(item, e); }}>
                  <td>
                    <button type="button" className="el-table__open" data-row-id={item.id} aria-pressed={selecting ? on : undefined}
                      aria-label={selecting ? `Select ${name}` : name} onClick={(e) => onRow(item, e)} onPointerDown={onRowPointerDown ? (e) => onRowPointerDown(item, e) : undefined}
                      onKeyDown={(e) => { if (e.key === "ContextMenu" || (e.shiftKey && e.key === "F10")) { e.preventDefault(); onRowMenu(item, e); } }}>
                      {selecting ? <span className={`el-row__tick${on ? " el-row__tick--on" : ""}`} aria-hidden="true">{on && <Icon icon={Icons.CheckIcon} />}</span>
                        : <SourceAvatar item={item} size="sm" />}
                      <span className="el-table__title" dir="auto">{name}</span>
                      {item.pinned && <span className="el-row__pin"><Icon icon={Icons.PinIcon} /><span className="el-visually-hidden">Pinned</span></span>}
                    </button>
                    {recovered?.has(item.id) && <Badge tone="accent">{RECOVERED_CHIP}</Badge>}
                  </td>
                  {columns.map((c) => <td key={c} dir="auto">{cell(item, c, viewsOf ? viewsOf(item) : item.transcript?.views ?? null)}</td>)}
                  <td className="el-table__actions">{selecting ? null : rowActions(item)}</td>
                </tr>
              );
            }),
          ])}
        </tbody>
      </table>
    </div>
  );
}
