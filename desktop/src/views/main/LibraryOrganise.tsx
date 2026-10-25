// Library organisation on the desktop (plan B15): pin, labels and client folder of one recording, and bulk actions
// for the selected recordings. Every change goes to the server; the library refreshes when it answers.
import { useId, useState } from "react";
import { api, errorMessage, type BulkAction, type BulkExportFormat, type ClientFolder, type LibraryItem } from "../../lib/ipc";
import { folderBadge, MAX_LABEL_CHARS, sortFolders, withLabel } from "../../lib/organise";

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

/** A client folder picker with "No client" and "New client…", which asks for a name and creates the folder. */
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
        <option value="">No client</option>
        {sortFolders(clients).map((c) => <option key={c.id} value={c.id}>{`${folderBadge(c)} · ${c.name}`}</option>)}
        <option value={NEW_CLIENT}>New client…</option>
      </select>
      {creating && (
        <>
          <label htmlFor={nameId} className="sr-only">New client name</label>
          <input id={nameId} className="input" placeholder="Client name" maxLength={120} value={name} autoFocus
            onChange={(e) => setName(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter" && name.trim()) void create(); if (e.key === "Escape") setCreating(false); }} />
          <button type="button" className="btn" disabled={!name.trim()} onClick={() => void create()}>Create</button>
          <button type="button" className="btn ghost" onClick={() => setCreating(false)}>Cancel</button>
        </>
      )}
    </div>
  );
}

/** Pin, client folder and labels of the open recording. */
export function OrganisePanel({ item, clients, knownLabels, notify, onClientCreated }: {
  item: LibraryItem;
  clients: ClientFolder[];
  knownLabels: string[];
  notify: Notify;
  onClientCreated: (client: ClientFolder) => void;
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
  return (
    <div className="organise" role="group" aria-label="Organise">
      <button type="button" className={`btn ghost ${item.pinned ? "pinned" : ""}`} aria-pressed={!!item.pinned} disabled={busy}
        onClick={() => void save({ pinned: !item.pinned }, item.pinned ? "Unpinned" : "Pinned")}>
        <span aria-hidden="true">{item.pinned ? "★" : "☆"}</span>{item.pinned ? "Pinned" : "Pin"}
      </button>
      <FolderPicker label="Client" value={item.client?.id ?? ""} clients={clients} disabled={busy} notify={notify} onCreated={onClientCreated}
        onPick={(clientId) => void save({ client_id: clientId }, "Moved")} />
      <div className="chips labels" role="list" aria-label="Labels">
        {labels.map((l) => (
          <span role="listitem" key={l} className="chip label-chip">
            {l}
            <button type="button" className="chip-remove" aria-label={`Remove label ${l}`} disabled={busy} onClick={() => void save({ labels: labels.filter((x) => x !== l) })}>×</button>
          </span>
        ))}
        <span role="listitem" className="label-add">
          <label htmlFor={labelId} className="sr-only">Add a label</label>
          <input id={labelId} className="input small" placeholder="Add label" list={listId} maxLength={MAX_LABEL_CHARS} value={label} disabled={busy}
            onChange={(e) => setLabel(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") void addLabel(); }} />
          <datalist id={listId}>{knownLabels.filter((k) => !labels.includes(k)).map((k) => <option key={k} value={k} />)}</datalist>
        </span>
      </div>
    </div>
  );
}

/** Actions for the selected recordings: move, label, pin, export as one zip, delete. */
export function BulkBar({ ids, clients, knownLabels, notify, onDone, onClientCreated }: {
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
      const path = await api.exportSessions(ids, format);
      notify(`Saved ${path}`);
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="bulk-bar card" role="toolbar" aria-label="Selected recordings">
      <div className="row">
        <b>{count === 0 ? "Select recordings" : `${count} selected`}</b>
        <span className="spacer" />
        <button type="button" className="btn ghost" onClick={onDone}>Done</button>
      </div>
      {count > 0 && (
        <div className="bulk-actions">
          <FolderPicker label="Move to" value="" clients={clients} disabled={busy} notify={notify} onCreated={onClientCreated} onPick={(clientId) => void run("move", clientId)} />
          <div className="row organise-field">
            <label htmlFor={labelId} className="sr-only">Label</label>
            <input id={labelId} className="input small" placeholder="Label" list={listId} maxLength={MAX_LABEL_CHARS} value={label} disabled={busy} onChange={(e) => setLabel(e.target.value)} />
            <datalist id={listId}>{knownLabels.map((k) => <option key={k} value={k} />)}</datalist>
            <button type="button" className="btn ghost" disabled={busy || !label.trim()} onClick={() => void run("label", null, label)}>Add label</button>
            <button type="button" className="btn ghost" disabled={busy || !label.trim()} onClick={() => void run("unlabel", null, label)}>Remove label</button>
          </div>
          <div className="row organise-field">
            <button type="button" className="btn ghost" disabled={busy} onClick={() => void run("pin")}>Pin</button>
            <button type="button" className="btn ghost" disabled={busy} onClick={() => void run("unpin")}>Unpin</button>
            <label htmlFor={formatId} className="sr-only">Export format</label>
            <select id={formatId} className="select" value={format} disabled={busy} onChange={(e) => setFormat(e.target.value as BulkExportFormat)}>
              {EXPORT_FORMATS.map(([v, text]) => <option key={v} value={v}>{text}</option>)}
            </select>
            <button type="button" className="btn ghost" disabled={busy} onClick={() => void exportZip()}>Export transcripts</button>
            {confirmDelete ? (
              <button type="button" className="btn danger" disabled={busy} onClick={() => void run("delete")}>{`Delete ${count} on every device`}</button>
            ) : (
              <button type="button" className="btn danger ghost" disabled={busy} onClick={() => setConfirmDelete(true)}>Delete</button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
