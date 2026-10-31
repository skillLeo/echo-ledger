// The import sheet: files picked with Import or dropped on the window become one recording each, named after the
// file, with one client and language for the whole batch. The core copies them into the upload queue, where each
// file shows its own progress with Cancel and Retry (LibraryQueue).
import { useEffect, useId, useRef, useState } from "react";
import { api, errorMessage, type ClientFolder, type TranscriptionLanguage } from "../../lib/ipc";
import { MAIN_LANGUAGES, MORE_LANGUAGES, menuLabel } from "../../lib/languages";
import { fileName, importNotice, importTitle, isImportable } from "../../lib/importFiles";
import { folderBadge, sortFolders } from "../../lib/organise";

export function ImportSheet({ paths, notify, onClose }: {
  paths: string[];
  notify: (text: string, error?: boolean) => void;
  onClose: () => void;
}) {
  const [clients, setClients] = useState<ClientFolder[]>([]);
  const [clientId, setClientId] = useState("");
  const [language, setLanguage] = useState("");
  const [busy, setBusy] = useState(false);
  const titleId = useId();
  const clientField = useId();
  const languageField = useId();
  const importButton = useRef<HTMLButtonElement | null>(null);
  const accepted = paths.filter(isImportable);

  useEffect(() => {
    // Offline the sheet still works: the batch is simply imported without a client.
    api.clients().then((r) => setClients(r.data ?? [])).catch(() => undefined);
    importButton.current?.focus();
  }, []);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape" && !busy) onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [busy, onClose]);

  const start = async () => {
    setBusy(true);
    try {
      // Every path goes to the core, which decides what can be imported and counts the rest.
      const summary = await api.importVoiceNotes(paths, clientId || null, (language || null) as TranscriptionLanguage | null);
      notify(importNotice(summary), summary.queued === 0 && summary.duplicates === 0);
      onClose();
    } catch (e) {
      notify(errorMessage(e), true);
      setBusy(false);
    }
  };

  const count = accepted.length;
  return (
    <div className="sheet-backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget && !busy) onClose(); }}>
      <div className="sheet card" role="dialog" aria-modal="true" aria-labelledby={titleId}>
        <h2 id={titleId} className="sheet-title">{count === 0 ? "Nothing to import" : count === 1 ? "Import 1 file" : `Import ${count} files`}</h2>
        <p className="small muted">Each file becomes its own recording, named after the file. Videos are transcribed from their sound.</p>
        <ul className="import-files" aria-label="Files to import">
          {paths.map((path) => {
            const ok = isImportable(path);
            return (
              <li key={path} className={ok ? "" : "muted"} title={path}>
                <span className="import-title" dir="auto">{(ok && importTitle(path)) || fileName(path)}</span>
                <span className="small muted">{ok ? fileName(path) : "Can't be imported: not a voice note, audio or video file"}</span>
              </li>
            );
          })}
        </ul>
        <div className="row organise-field">
          <label htmlFor={clientField} className="small muted">Client</label>
          <select id={clientField} className="select" value={clientId} disabled={busy} onChange={(e) => setClientId(e.target.value)}>
            <option value="">No client</option>
            {sortFolders(clients).map((c) => <option key={c.id} value={c.id}>{`${folderBadge(c)} · ${c.name}`}</option>)}
          </select>
        </div>
        <div className="row organise-field">
          <label htmlFor={languageField} className="small muted">Language</label>
          <select id={languageField} className="select" value={language} disabled={busy} onChange={(e) => setLanguage(e.target.value)}>
            {MAIN_LANGUAGES.map((o) => <option key={o.chip} value={o.value ?? ""}>{menuLabel(o)}</option>)}
            <optgroup label="More languages">{MORE_LANGUAGES.map((o) => <option key={o.chip} value={o.value ?? ""}>{o.label}</option>)}</optgroup>
          </select>
        </div>
        <p className="small muted">The client and language apply to every file. Files already in your Library are skipped.</p>
        <div className="row sheet-actions">
          <span className="spacer" />
          <button type="button" className="btn ghost" disabled={busy} onClick={onClose}>Cancel</button>
          <button ref={importButton} type="button" className="btn primary" disabled={busy || count === 0} onClick={() => void start()}>
            {busy ? "Importing…" : count <= 1 ? "Import" : `Import ${count} files`}
          </button>
        </div>
      </div>
    </div>
  );
}
