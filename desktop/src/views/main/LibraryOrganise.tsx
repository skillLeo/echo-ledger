// Library organisation on the desktop (plan B15): pin, labels and client folder of one recording, renaming it, the
// row menu, and bulk actions for the selected recordings. Every change goes to the server; the library refreshes
// when it answers.
import { useEffect, useId, useLayoutEffect, useRef, useState } from "react";
import { api, errorMessage, type BinItem, type BulkAction, type BulkExportFormat, type ClientFolder, type LibraryItem } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { MAX_LABEL_CHARS, withLabel } from "../../lib/organise";
import { projectLabel, projectTree, visibleRows } from "../../lib/projects";
import { MAX_TITLE_CHARS } from "../../lib/importFiles";
import { CloseIcon, PinIcon } from "../../components/Icons";
import { ChevronDownIcon, MoreIcon } from "../../components/ControlIcons";
import { MoveRecordingsDialog } from "./ProjectDialogs";
import { FiledByLine } from "./FilingRules";

type Notify = (message: string, error?: boolean) => void;

const NEW_CLIENT = "__new__";
const EXPORT_FORMATS: readonly (readonly [BulkExportFormat, string])[] = [
  ["docx", "Word (.docx)"],
  ["txt", "Text (.txt)"],
  ["md", "Markdown (.md)"],
  ["srt", "Subtitles (.srt)"],
  ["vtt", "Web subtitles (.vtt)"],
  ["json", "JSON"],
];

/**
 * A project picker with the Inbox (no project), every project by its place ("Acme › Invoices", since v1.5) and "New
 * project…", which asks for a name and creates the project.
 */
export function FolderPicker({ label, value, clients, onPick, onCreated, notify, disabled = false }: {
  label: string;
  value: string;
  clients: ClientFolder[];
  onPick: (clientId: string | null) => void;
  onCreated: (client: ClientFolder) => void;
  notify: Notify;
  disabled?: boolean;
}) {
  const id = useId();
  const nameId = useId();
  const [creating, setCreating] = useState(false);
  const [name, setName] = useState("");
  const create = async () => {
    try {
      const { data } = await api.createClient(name.trim());
      onCreated(data);
      setCreating(false);
      setName("");
      onPick(data.id);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  return (
    <div className="row organise-field">
      <label htmlFor={id} className="small muted">{label}</label>
      <select id={id} className="select" value={value} disabled={disabled}
        onChange={(e) => (e.target.value === NEW_CLIENT ? setCreating(true) : onPick(e.target.value || null))}>
        <option value="">Inbox</option>
        {visibleRows(projectTree(clients), "all").map(({ project }) => <option key={project.id} value={project.id}>{projectLabel(clients, project.id)}</option>)}
        <option value={NEW_CLIENT}>New project…</option>
      </select>
      {creating && (
        <>
          <label htmlFor={nameId} className="sr-only">New project name</label>
          <input id={nameId} className="input" placeholder="Project name" maxLength={120} value={name} autoFocus
            onChange={(e) => setName(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter" && name.trim()) void create(); if (e.key === "Escape") setCreating(false); }} />
          <button type="button" className="btn" disabled={!name.trim()} onClick={() => void create()}>Create</button>
          <button type="button" className="btn ghost" onClick={() => setCreating(false)}>Cancel</button>
        </>
      )}
    </div>
  );
}

/** A title renamed in place: Enter or leaving the field saves (once), Escape cancels; empty clears the title. */
export function TitleEditor({ initial, className = "", onSave, onCancel }: {
  initial: string;
  className?: string;
  onSave: (title: string) => void;
  onCancel: () => void;
}) {
  const [text, setText] = useState(initial);
  const done = useRef(false);
  const finish = (save: boolean) => {
    if (done.current) return;
    done.current = true;
    if (save) onSave(text.trim());
    else onCancel();
  };
  return (
    <input className={`input ${className}`.trim()} aria-label="Title" autoFocus maxLength={MAX_TITLE_CHARS} value={text} dir="auto"
      placeholder="Empty: an automatic title from what was said"
      onFocus={(e) => e.currentTarget.select()}
      onChange={(e) => setText(e.target.value)}
      onKeyDown={(e) => {
        if (e.key === "Enter") finish(true);
        if (e.key === "Escape") finish(false);
      }}
      onBlur={() => finish(true)} />
  );
}

/** The ⋯ menu on a Library row: Rename, Move to (since v1.5), Pin or Unpin, Delete. It opens upwards when it would end
 * below the visible part of the list (the last rows), where the list's scroll box cut it off. */
export function RowMenu({ title, pinned, onRename, onMove, onPin, onDelete, onWhatsApp }: {
  title: string;
  pinned: boolean;
  onRename: () => void;
  /** Opens the Move dialog. */
  onMove?: () => void;
  onPin: () => void;
  onDelete: () => void;
  /** Send to WhatsApp (v1.6, P5.5): opens the recording and its sheet. */
  onWhatsApp?: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [up, setUp] = useState(false);
  const box = useRef<HTMLDivElement | null>(null);
  const pop = useRef<HTMLDivElement | null>(null);
  useLayoutEffect(() => {
    if (!open) {
      setUp(false);
      return;
    }
    const scroller = box.current?.closest(".list");
    if (!pop.current || !scroller) return;
    const room = scroller.getBoundingClientRect();
    const menu = pop.current.getBoundingClientRect();
    // 36 px: the button the menu hangs from, which it then sits above.
    if (menu.bottom > room.bottom && menu.top - 36 - menu.height >= room.top) setUp(true);
  }, [open]);
  useEffect(() => {
    if (!open) return;
    const outside = (e: MouseEvent) => {
      if (!box.current?.contains(e.target as Node)) setOpen(false);
    };
    const escape = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", outside);
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("mousedown", outside);
      document.removeEventListener("keydown", escape);
    };
  }, [open]);
  const pick = (action: () => void) => {
    setOpen(false);
    action();
  };
  return (
    <div className="row-menu" ref={box}>
      <button type="button" className="row-menu-btn" aria-label={`More for ${title}`} aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen(!open)}>
        <MoreIcon size={16} />
      </button>
      {open && (
        <div ref={pop} className={`row-menu-pop card${up ? " up" : ""}`} role="menu" aria-label={`Actions for ${title}`}>
          <button type="button" role="menuitem" className="btn ghost small" onClick={() => pick(onRename)}>Rename</button>
          {onMove && <button type="button" role="menuitem" className="btn ghost small" onClick={() => pick(onMove)}>Move to…</button>}
          {onWhatsApp && <button type="button" role="menuitem" className="btn ghost small" onClick={() => pick(onWhatsApp)}>Send to WhatsApp…</button>}
          <button type="button" role="menuitem" className="btn ghost small" onClick={() => pick(onPin)}>{pinned ? "Unpin" : "Pin"}</button>
          <button type="button" role="menuitem" className="btn ghost small danger" onClick={() => pick(onDelete)}>Delete</button>
        </div>
      )}
    </div>
  );
}

/** Pin, client folder and labels of the open recording. */
export function OrganisePanel({ item, clients, knownLabels, notify, onClientCreated, children }: {
  item: LibraryItem;
  clients: ClientFolder[];
  knownLabels: string[];
  notify: Notify;
  onClientCreated: (client: ClientFolder) => void;
  /** More header chips after Client (the recording page puts Language there). */
  children?: React.ReactNode;
}) {
  const [label, setLabel] = useState("");
  const [busy, setBusy] = useState(false);
  const labelId = useId();
  const listId = useId();
  const labels = item.labels ?? [];
  const save = async (patch: Parameters<typeof api.organiseSession>[1], done?: string) => {
    setBusy(true);
    try {
      await api.organiseSession(item.id, patch);
      if (done) notify(done);
      return true;
    } catch (e) {
      notify(errorMessage(e), true);
      return false;
    } finally {
      setBusy(false);
    }
  };
  const addLabel = async () => {
    const next = withLabel(labels, label);
    if (next === null) {
      setLabel("");
      return;
    }
    if (await save({ labels: next })) setLabel("");
  };
  // The recording page's header chips: Project, Language (from the page), Labels; then Pin and Keep forever. Under them,
  // how it got into its project when a filing rule or a contact filed it (since v1.5).
  return (
    <>
    <div className="organise head-chips" role="group" aria-label="Organise">
      <span className="head-chip">
        <FolderPicker label="Project" value={item.client?.id ?? ""} clients={clients} disabled={busy} notify={notify} onCreated={onClientCreated}
          onPick={(clientId) => void save({ client_id: clientId }, clientId ? `Moved to ${projectLabel(clients, clientId) || "the project"}` : "Moved to the Inbox")} />
      </span>
      {children}
      <span className="head-chip labels-chip">
        <span className="chip-key" aria-hidden="true">Labels</span>
        <span className="chips labels" role="list" aria-label="Labels">
          {labels.map((l) => (
            <span role="listitem" key={l} className="chip label-chip">
              {l}
              <button type="button" className="chip-remove" aria-label={`Remove label ${l}`} disabled={busy} onClick={() => void save({ labels: labels.filter((x) => x !== l) })}><CloseIcon size={12} /></button>
            </span>
          ))}
          <span role="listitem" className="label-add">
            <label htmlFor={labelId} className="sr-only">Add a label</label>
            <input id={labelId} placeholder="Add label" list={listId} maxLength={MAX_LABEL_CHARS} value={label} disabled={busy}
              onChange={(e) => setLabel(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") void addLabel(); }} />
            <datalist id={listId}>{knownLabels.filter((k) => !labels.includes(k)).map((k) => <option key={k} value={k} />)}</datalist>
          </span>
        </span>
      </span>
      <button type="button" className={`btn ghost compact ${item.pinned ? "pinned" : ""}`} aria-pressed={!!item.pinned} disabled={busy}
        onClick={() => void save({ pinned: !item.pinned }, item.pinned ? "Unpinned" : "Pinned")}>
        <PinIcon size={15} />{item.pinned ? "Pinned" : "Pin"}
      </button>
      <button type="button" className="btn ghost compact" aria-pressed={!!item.keep} disabled={busy} title="Kept recordings are never deleted automatically"
        onClick={() => void save({ keep: !item.keep }, item.keep ? "No longer kept" : "Kept forever")}>
        {item.keep ? "Kept forever" : "Keep forever"}
      </button>
    </div>
    <FiledByLine item={item} />
    </>
  );
}

/** Actions for the selected recordings: move, label, pin, export as one zip, delete. */
export function BulkBar({ ids, clients, knownLabels, notify, onDone, onClientCreated, onSelectAll }: {
  /** Ticks every recording the list shows. */
  onSelectAll?: () => void;
  ids: string[];
  clients: ClientFolder[];
  knownLabels: string[];
  notify: Notify;
  onDone: () => void;
  onClientCreated: (client: ClientFolder) => void;
}) {
  const [label, setLabel] = useState("");
  const [format, setFormat] = useState<BulkExportFormat>("docx");
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [busy, setBusy] = useState(false);
  const [moving, setMoving] = useState(false);
  const labelId = useId();
  const formatId = useId();
  const listId = useId();
  const count = ids.length;
  const run = async (action: BulkAction, clientId: string | null = null, value: string | null = null) => {
    setBusy(true);
    try {
      const changed = await api.bulkSessions(ids, action, clientId, value);
      notify(action === "delete" ? `${changed} deleted` : changed === 1 ? "1 recording updated" : `${changed} recordings updated`);
      if (action === "delete") onDone();
      setLabel("");
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(false);
      setConfirmDelete(false);
    }
  };
  const exportZip = async () => {
    setBusy(true);
    try {
      // The core asks where to save the zip; null means the person cancelled there.
      const path = await api.exportSessions(ids, format);
      if (path) notify(`Saved ${path}`);
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(false);
    }
  };
  // One compact line that stays at the top of the list; the rarer actions sit under "Label" and "More".
  return (
    <div className="bulk-bar card" role="toolbar" aria-label="Selected recordings">
      <b className="bulk-count">{count === 0 ? "Select recordings" : `${count} selected`}</b>
      {onSelectAll && <button type="button" className="btn ghost small" onClick={onSelectAll}>Select all</button>}
      {count > 0 && (
        <>
          <button type="button" className="btn ghost small" disabled={busy} onClick={() => setMoving(true)}>Move…</button>
          {moving && <MoveRecordingsDialog ids={ids} projects={clients} notify={notify} onClose={() => setMoving(false)} onProjectCreated={onClientCreated} />}
          <details className="bulk-menu">
            <summary className="btn ghost small">Label<ChevronDownIcon size={14} /></summary>
            <div className="bulk-pop card">
              <label htmlFor={labelId} className="sr-only">Label</label>
              <input id={labelId} className="input small" placeholder="Label" list={listId} maxLength={MAX_LABEL_CHARS} value={label} disabled={busy} onChange={(e) => setLabel(e.target.value)} />
              <datalist id={listId}>{knownLabels.map((k) => <option key={k} value={k} />)}</datalist>
              <div className="row">
                <button type="button" className="btn ghost small" disabled={busy || !label.trim()} onClick={() => void run("label", null, label)}>Add label</button>
                <button type="button" className="btn ghost small" disabled={busy || !label.trim()} onClick={() => void run("unlabel", null, label)}>Remove label</button>
              </div>
            </div>
          </details>
          <button type="button" className="btn ghost small" disabled={busy} onClick={() => void run("pin")}>Pin</button>
          <details className="bulk-menu">
            <summary className="btn ghost small">More<ChevronDownIcon size={14} /></summary>
            <div className="bulk-pop card">
              <button type="button" className="btn ghost small" disabled={busy} onClick={() => void run("unpin")}>Unpin</button>
              <button type="button" className="btn ghost small" disabled={busy} onClick={() => void run("keep")}>Keep forever</button>
              <button type="button" className="btn ghost small" disabled={busy} onClick={() => void run("unkeep")}>Stop keeping</button>
              <div className="row">
                <label htmlFor={formatId} className="sr-only">Export format</label>
                <select id={formatId} className="select" value={format} disabled={busy} onChange={(e) => setFormat(e.target.value as BulkExportFormat)}>
                  {EXPORT_FORMATS.map(([v, text]) => <option key={v} value={v}>{text}</option>)}
                </select>
                <button type="button" className="btn ghost small" disabled={busy} onClick={() => void exportZip()}>Export transcripts</button>
              </div>
            </div>
          </details>
          {confirmDelete ? (
            <button type="button" className="btn danger small" disabled={busy} onClick={() => void run("delete")}>{`Delete ${count} (restorable for 48 hours)`}</button>
          ) : (
            <button type="button" className="btn danger ghost small" disabled={busy} onClick={() => setConfirmDelete(true)}>Delete</button>
          )}
        </>
      )}
      <span className="spacer" />
      <button type="button" className="btn ghost small" onClick={onDone}>Done</button>
    </div>
  );
}
/** "erased in 5 hours" from the erase time; "erased soon" in the last minutes. */
export function eraseIn(eraseAt: string, now: number = Date.now()): string {
  const hours = Math.floor((Date.parse(eraseAt) - now) / 3_600_000);
  return hours >= 1 ? `erased in ${hours} hour${hours === 1 ? "" : "s"}` : "erased soon";
}

/** Recently deleted (B17): the 48-hour undo bin with Restore and Delete now. */
export function BinPanel({ notify, onClose }: { notify: Notify; onClose: () => void }) {
  const [items, setItems] = useState<BinItem[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const load = () => api.bin().then((r) => setItems(r.data ?? []), (e) => { setItems([]); notify(errorMessage(e), true); });
  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  const act = async (id: string, fn: () => Promise<unknown>, done: string) => {
    setBusy(id);
    try {
      await fn();
      notify(done);
      await load();
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(null);
    }
  };
  return (
    <div className="card bin-panel" role="region" aria-label="Recently deleted">
      <div className="row"><b>Recently deleted</b><span className="spacer" /><button type="button" className="btn ghost small" onClick={onClose}>Close</button></div>
      <p className="small muted">Deleted recordings stay here for 48 hours, then their audio and transcripts are erased.</p>
      {items === null ? <p className="small muted">Loading…</p> : items.length === 0 ? <p className="small muted">Nothing deleted in the last 48 hours.</p> : (
        <div className="site-list" role="list">
          {items.map((b) => (
            <div className="site-rule" role="listitem" key={b.id}>
              <div className="site-main">
                <div className="site-pattern">{b.title}</div>
                <div className="small muted">{formatWhen(b.started_at)}{b.client ? ` · ${b.client.name}` : ""} · {eraseIn(b.erase_at)}</div>
              </div>
              <button type="button" className="btn small" disabled={busy === b.id} onClick={() => void act(b.id, () => api.restoreSession(b.id), "Restored")}>Restore</button>
              <button type="button" className="btn danger ghost small" disabled={busy === b.id}
                onClick={() => { if (window.confirm("Erase this recording now? This cannot be undone.")) void act(b.id, () => api.eraseFromBin(b.id), "Erased"); }}>Delete now</button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
