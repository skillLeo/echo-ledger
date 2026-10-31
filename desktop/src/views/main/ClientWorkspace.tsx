// A client's workspace (docs/API.md "Client workspaces", since v1.4): the AI notes switch and budget, the brief, the
// memory and the latest call notes (Overview), the client's recordings, its files and pasted instructions, and Ask.
// Anything the server is still writing or reading is asked for again every few seconds until it is done.
import { useEffect, useMemo, useRef, useState } from "react";
import { api, errorMessage, type AnswerCitation, type AnswerLanguage, type BriefSections, type ClientAnswer, type ClientFile, type ClientFolder, type ClientWorkspace as Workspace, type LibraryItem, type MemoryKind, type MemoryLine } from "../../lib/ipc";
import { formatDuration, formatWhen, timestamp } from "../../lib/format";
import { usePoll } from "../../lib/usePoll";
import { notifyWhenAway } from "../../lib/notify";
import { claimFileDrops } from "../../lib/dropTarget";
import { listenForFileDrops } from "../../lib/ipc";
import { AlertIcon, AppTileIcon, appKind, CloseIcon, CopyIcon, FileIcon, ImportIcon, PinIcon, PlusIcon, SendIcon, SparkleIcon, TrashIcon } from "../../components/Icons";
import { PlayIcon } from "../../components/ControlIcons";
import { NotesSections } from "./CallNotes";
import { ClientAvatar, clientSummary } from "./ClientAvatar";

type Notify = (text: string, error?: boolean) => void;
type Tab = "overview" | "recordings" | "files" | "ask";
const TABS: readonly [Tab, string][] = [["overview", "Overview"], ["recordings", "Recordings"], ["files", "Files"], ["ask", "Ask"]];

/** The brief's sections in the order the page shows them; `goal` is one line, the rest are lists. */
export const BRIEF_FIELDS: readonly [keyof BriefSections, string][] = [
  ["goal", "Goal"], ["scope", "Scope"], ["constraints", "Constraints"], ["deadlines", "Deadlines"], ["budget", "Budget"], ["people", "People"], ["glossary", "Glossary"],
];
/** Memory grouped as on the phone: decisions, open questions, changes, scope. */
export const MEMORY_GROUPS: readonly [MemoryKind, string][] = [["decision", "Decisions"], ["question", "Open questions"], ["change", "Changes"], ["scope", "Scope"]];
/** File types a workspace takes (the server refuses the rest). */
export const CLIENT_FILE_TYPES = ["pdf", "docx", "txt", "md"] as const;

const money = (usd: number) => `$${(Number.isFinite(usd) ? usd : 0).toFixed(2)}`;
/** "AI this month: $0.42 of $5.00 · this client $0.12" */
export function budgetLine(ai: Workspace["ai"]): string {
  return `AI this month: ${money(ai.spent_usd)} of ${money(ai.budget_usd)} · this client ${money(ai.client_spent_usd)}`;
}

/** Whether anything in the workspace is still being written or read on the server. */
export function workspaceBusy(ws: Workspace | null): boolean {
  if (!ws) return false;
  return ws.brief?.status === "working" || ws.notes.some((n) => n.status === "pending") || ws.files.some((f) => f.status === "extracting");
}

export const fileExtension = (path: string): string => (/\.([^.\\/]+)$/.exec(path)?.[1] ?? "").toLowerCase();
const baseName = (path: string): string => path.split(/[\\/]/).pop() ?? path;
function fileSize(bytes: number | null): string {
  if (!bytes) return "";
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
function fileKind(file: ClientFile): string {
  if (file.instructions) return "Pasted instructions";
  const ext = fileExtension(file.name);
  return ext === "pdf" ? "PDF" : ext === "docx" ? "Word" : ext === "md" ? "Markdown" : ext === "txt" ? "Text" : ext.toUpperCase();
}

export function ClientWorkspace({ client, recordings, notify, onOpenRecording, onClientChanged }: {
  client: ClientFolder;
  /** The client's recordings in the saved library, newest first. */
  recordings: LibraryItem[];
  notify: Notify;
  onOpenRecording: (id: string, ms?: number) => void;
  onClientChanged: (client: Partial<ClientFolder> & { id: string }) => void;
}) {
  const [tab, setTab] = useState<Tab>("overview");
  const [ws, setWs] = useState<Workspace | null>(null);
  const [error, setError] = useState<string | null>(null);
  const previous = useRef<Workspace | null>(null);

  const load = async () => {
    try {
      const { data } = await api.clientWorkspace(client.id);
      setWs(data);
      setError(null);
    } catch (e) {
      setError(errorMessage(e));
    }
  };
  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [client.id]);
  usePoll(workspaceBusy(ws), load);

  // Work that finished while the person looked elsewhere: a native notification says what is ready.
  useEffect(() => {
    const before = previous.current;
    previous.current = ws;
    if (!before || !ws) return;
    if (before.brief?.status === "working" && ws.brief?.status === "ready") void notifyWhenAway("Brief ready", `${client.name}'s brief was written again.`);
    for (const file of ws.files) {
      const was = before.files.find((f) => f.id === file.id);
      if (was?.status === "extracting" && file.status !== "extracting") {
        void notifyWhenAway(file.status === "ready" ? "File read" : "File not read", `${client.name}: ${file.name}`);
      }
    }
  }, [ws, client.name]);

  const copyForAi = async () => {
    try {
      const text = await api.clientBundle(client.id);
      await navigator.clipboard.writeText(text);
      notify("Copied the brief, memory and recent call notes for AI");
    } catch (e) {
      notify(typeof e === "string" ? e : "Could not copy the client's context", true);
    }
  };

  return (
    <>
      <div className="workspace-head">
        <ClientAvatar client={client} large />
        <div style={{ minWidth: 0, flex: 1 }}>
          <h1 className="page-title">{client.name}</h1>
          <div className="small muted">{clientSummary(Math.max(recordings.length, client.session_count ?? 0), recordings[0]?.started_at)}</div>
        </div>
        <button type="button" className="btn" onClick={() => void copyForAi()} title="The brief, memory and recent call notes as one text, ready to paste into a chat">
          <SparkleIcon size={16} />Copy for AI
        </button>
      </div>
      <div className="tabs" role="tablist" aria-label={`${client.name} workspace`}>
        {TABS.map(([id, label]) => (
          <button key={id} type="button" role="tab" id={`ws-tab-${id}`} className="tab" aria-selected={tab === id} aria-controls={`ws-panel-${id}`} onClick={() => setTab(id)}>{label}</button>
        ))}
      </div>
      <div role="tabpanel" id={`ws-panel-${tab}`} aria-labelledby={`ws-tab-${tab}`}>
        {error && !ws && (
          <div className="notice error sync-error" role="alert">
            <span>Couldn't load {client.name}'s workspace. {error}</span>
            <button type="button" className="btn compact" onClick={() => void load()}>Retry</button>
          </div>
        )}
        {!ws && !error && tab !== "recordings" && (
          <div aria-busy="true">
            <span className="sr-only">Loading the workspace…</span>
            <span className="skeleton" style={{ width: "60%", height: 14, marginBottom: 10 }} />
            <span className="skeleton" style={{ width: "80%", height: 12, marginBottom: 10 }} />
            <span className="skeleton" style={{ width: "45%", height: 12 }} />
          </div>
        )}
        {tab === "overview" && ws && <Overview ws={ws} client={client} notify={notify} onChange={setWs} onReload={load} onOpenRecording={onOpenRecording} onClientChanged={onClientChanged} />}
        {tab === "recordings" && <Recordings recordings={recordings} clientName={client.name} onOpen={(id) => onOpenRecording(id)} />}
        {tab === "files" && ws && <Files ws={ws} clientId={client.id} notify={notify} onChange={setWs} />}
        {tab === "ask" && ws && <Ask ws={ws} clientId={client.id} onOpenRecording={onOpenRecording} />}
      </div>
    </>
  );
}

// ---- Overview --------------------------------------------------------------------------------------------------

function Overview({ ws, client, notify, onChange, onReload, onOpenRecording, onClientChanged }: {
  ws: Workspace;
  client: ClientFolder;
  notify: Notify;
  onChange: (ws: Workspace) => void;
  onReload: () => Promise<void>;
  onOpenRecording: (id: string, ms?: number) => void;
  onClientChanged: (client: Partial<ClientFolder> & { id: string }) => void;
}) {
  const [busy, setBusy] = useState(false);
  const toggleNotes = async () => {
    const on = !ws.client.ai_notes;
    setBusy(true);
    try {
      await api.updateClient(client.id, { ai_notes: on });
      onChange({ ...ws, client: { ...ws.client, ai_notes: on } });
      onClientChanged({ id: client.id, ai_notes: on });
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(false);
    }
  };
  return (
    <>
      <section className="ws-section" aria-label="AI">
        <div className="card">
          <div className="toggle">
            <div>
              <label htmlFor={`ai-notes-${client.id}`}>AI notes</label>
              <div className="small muted" id={`ai-notes-hint-${client.id}`}>Claude writes call notes after each kept recording of {client.name}.</div>
            </div>
            <button id={`ai-notes-${client.id}`} type="button" role="switch" aria-checked={ws.client.ai_notes} aria-describedby={`ai-notes-hint-${client.id}`}
              className={`switch ${ws.client.ai_notes ? "on" : ""}`} disabled={busy} onClick={() => void toggleNotes()} />
          </div>
          <div className="budget-line" data-testid="ai-budget" style={{ paddingTop: 10 }}>
            {ws.ai.configured ? budgetLine(ws.ai) : "AI is not set up on the server yet: notes, the brief and Ask need its Anthropic key."}
          </div>
        </div>
      </section>
      <Brief ws={ws} clientId={client.id} notify={notify} onChange={onChange} onReload={onReload} />
      <Memory ws={ws} clientId={client.id} notify={notify} onChange={onChange} onOpenRecording={onOpenRecording} />
      <section className="ws-section" aria-labelledby="ws-notes">
        <h3 id="ws-notes">Latest call notes</h3>
        {ws.notes.length === 0 && <p className="small muted">Notes appear here after a call filed under {client.name} is kept and transcribed.</p>}
        {ws.notes.slice(0, 3).map((note) => (
          <div key={note.id} className="card" style={{ marginBottom: 10 }}>
            <div className="row">
              <b style={{ minWidth: 0, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{note.session?.title ?? "Call"}</b>
              <span className="small muted">{formatWhen(note.session?.started_at ?? note.created_at ?? undefined)}{note.session?.duration_ms ? ` · ${formatDuration(note.session.duration_ms)}` : ""}</span>
              <span className="spacer" />
              <button type="button" className="btn text compact" onClick={() => onOpenRecording(note.session_id)}>Open</button>
            </div>
            {note.status === "pending" && <div className="row small muted" role="status"><span className="spinner" aria-hidden="true" />Writing the notes…</div>}
            {(note.status === "failed" || note.status === "skipped") && <p className="small muted">{note.error ?? "No notes were written for this call."}</p>}
            {note.status === "ready" && <NotesSections notes={note} onOpen={(ms) => onOpenRecording(note.session_id, ms)} />}
          </div>
        ))}
      </section>
    </>
  );
}

function Brief({ ws, clientId, notify, onChange, onReload }: { ws: Workspace; clientId: string; notify: Notify; onChange: (ws: Workspace) => void; onReload: () => Promise<void> }) {
  const brief = ws.brief;
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const sections = brief?.sections ?? {};
  const startEdit = () => {
    setDraft(Object.fromEntries(BRIEF_FIELDS.map(([key]) => {
      const value = sections[key];
      return [key, Array.isArray(value) ? value.join("\n") : (value ?? "")];
    })));
    setEditing(true);
  };
  const save = async () => {
    const next: BriefSections = {};
    for (const [key] of BRIEF_FIELDS) {
      const text = draft[key] ?? "";
      if (key === "goal") next.goal = text.trim() || null;
      else (next as Record<string, string[]>)[key] = text.split("\n").map((l) => l.trim()).filter(Boolean);
    }
    setBusy(true);
    try {
      const { data } = await api.saveClientBrief(clientId, next);
      onChange({ ...ws, brief: data });
      setEditing(false);
      notify("Brief saved");
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(false);
    }
  };
  const makeAgain = async () => {
    if (brief?.edited && !window.confirm("Write the brief again from the client's files and call notes? Your edits are replaced.")) return;
    setBusy(true);
    try {
      const { data } = await api.refreshClientBrief(clientId);
      onChange({ ...ws, brief: data });
    } catch (e) {
      notify(errorMessage(e), true);
      await onReload();
    } finally {
      setBusy(false);
    }
  };
  const filled = BRIEF_FIELDS.filter(([key]) => {
    const value = sections[key];
    return Array.isArray(value) ? value.length > 0 : !!value;
  });
  return (
    <section className="ws-section" aria-labelledby="ws-brief">
      <h3 id="ws-brief">
        Brief{brief?.edited && <span className="label-chip">Edited by you</span>}
        <span className="spacer" />
        {!editing && <button type="button" className="btn ghost compact" disabled={busy || brief?.status === "working"} onClick={startEdit}>Edit</button>}
        {!editing && <button type="button" className="btn ghost compact" disabled={busy || brief?.status === "working"} onClick={() => void makeAgain()}><SparkleIcon size={14} />Make again</button>}
      </h3>
      <div className="card">
        {brief?.status === "working" && <div className="row small muted" role="status"><span className="spinner" aria-hidden="true" />Writing the brief… this takes a minute.</div>}
        {brief?.status === "failed" && <div className="notice error" role="alert">{brief.error ?? "The brief could not be written."}</div>}
        {editing ? (
          <form className="brief-edit" onSubmit={(e) => { e.preventDefault(); void save(); }}>
            {BRIEF_FIELDS.map(([key, label]) => (
              <label key={key}>
                {label}{key !== "goal" && <span className="small muted" style={{ fontWeight: 400 }}> · one per line</span>}
                {key === "goal"
                  ? <input className="input" maxLength={300} value={draft[key] ?? ""} onChange={(e) => setDraft({ ...draft, [key]: e.target.value })} />
                  : <textarea className="input" rows={3} value={draft[key] ?? ""} onChange={(e) => setDraft({ ...draft, [key]: e.target.value })} />}
              </label>
            ))}
            <div className="row">
              <button type="submit" className="btn primary" disabled={busy}>Save brief</button>
              <button type="button" className="btn ghost" onClick={() => setEditing(false)}>Cancel</button>
            </div>
          </form>
        ) : filled.length === 0 ? (
          brief?.status !== "working" && <p className="small muted" style={{ margin: 0 }}>No brief yet. Add files, keep a call, or press Make again.</p>
        ) : (
          <div className="brief-grid">
            {filled.map(([key, label]) => {
              const value = sections[key];
              return (
                <div key={key} className="brief-field">
                  <h4>{label}</h4>
                  {Array.isArray(value) ? <ul>{value.map((line, i) => <li key={i} dir="auto">{line}</li>)}</ul> : <p dir="auto">{value}</p>}
                </div>
              );
            })}
          </div>
        )}
      </div>
    </section>
  );
}

function Memory({ ws, clientId, notify, onChange, onOpenRecording }: {
  ws: Workspace;
  clientId: string;
  notify: Notify;
  onChange: (ws: Workspace) => void;
  onOpenRecording: (id: string, ms?: number) => void;
}) {
  const [kind, setKind] = useState<MemoryKind>("decision");
  const [text, setText] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const replace = (line: MemoryLine) => onChange({ ...ws, memory: ws.memory.map((m) => (m.id === line.id ? line : m)) });
  const pin = async (line: MemoryLine) => {
    setBusy(line.id);
    try {
      const { data } = await api.updateClientMemory(clientId, line.id, { pinned: !line.pinned });
      replace(data);
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(null);
    }
  };
  const remove = async (line: MemoryLine) => {
    setBusy(line.id);
    try {
      await api.deleteClientMemory(clientId, line.id);
      onChange({ ...ws, memory: ws.memory.filter((m) => m.id !== line.id) });
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(null);
    }
  };
  const add = async () => {
    const line = text.trim();
    if (!line) return;
    setBusy("add");
    try {
      const { data } = await api.addClientMemory(clientId, kind, line);
      onChange({ ...ws, memory: [data, ...ws.memory] });
      setText("");
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(null);
    }
  };
  return (
    <section className="ws-section" aria-labelledby="ws-memory">
      <h3 id="ws-memory">Memory</h3>
      <div className="card">
        {ws.memory.length === 0 && <p className="small muted" style={{ margin: 0 }}>Decisions, open questions and changes from calls collect here. Add your own below.</p>}
        {MEMORY_GROUPS.map(([group, heading]) => {
          // Pinned lines first, then the newest (the server's order inside a group).
          const lines = ws.memory.filter((m) => m.kind === group);
          if (lines.length === 0) return null;
          return (
            <div key={group} className="memory-group" role="group" aria-label={heading}>
              <h4>{heading}</h4>
              {lines.map((line) => (
                <div key={line.id} className="memory-line">
                  <span className="memory-text" dir="auto">{line.text}</span>
                  {line.source?.type === "call" && line.source.id && (
                    <button type="button" className="btn text compact" title="Play the moment in the call"
                      onClick={() => line.source?.id && onOpenRecording(line.source.id, line.source.at_ms ?? 0)}>
                      <PlayIcon size={12} />{line.source.at_ms !== null && line.source.at_ms !== undefined ? timestamp(line.source.at_ms) : "Call"}
                    </button>
                  )}
                  {line.happened_on && <span className="memory-date">{formatWhen(`${line.happened_on}T12:00:00`).split(" · ")[0]}</span>}
                  <button type="button" className="btn ghost compact icon-only" aria-pressed={line.pinned} aria-label={`${line.pinned ? "Unpin" : "Pin"} “${line.text}”`}
                    disabled={busy === line.id} onClick={() => void pin(line)}><PinIcon size={15} /></button>
                  <button type="button" className="btn ghost compact icon-only" aria-label={`Remove “${line.text}”`} disabled={busy === line.id} onClick={() => void remove(line)}><TrashIcon size={15} /></button>
                </div>
              ))}
            </div>
          );
        })}
        <form className="memory-add" onSubmit={(e) => { e.preventDefault(); void add(); }}>
          <select className="input" aria-label="Kind" value={kind} onChange={(e) => setKind(e.target.value as MemoryKind)}>
            {MEMORY_GROUPS.map(([value, heading]) => <option key={value} value={value}>{heading}</option>)}
          </select>
          <input className="input" aria-label="Add to memory" placeholder="Add a decision, question, change or scope line" maxLength={500} value={text} onChange={(e) => setText(e.target.value)} />
          <button type="submit" className="btn" disabled={!text.trim() || busy === "add"}><PlusIcon size={16} />Add</button>
        </form>
      </div>
    </section>
  );
}

// ---- Recordings ------------------------------------------------------------------------------------------------

function Recordings({ recordings, clientName, onOpen }: { recordings: LibraryItem[]; clientName: string; onOpen: (id: string) => void }) {
  if (recordings.length === 0) {
    return (
      <div className="empty-state">
        <span className="empty-icon"><FileIcon size={26} /></span>
        <span className="empty-title">No recordings yet</span>
        <p>Recordings filed under {clientName} show up here.</p>
      </div>
    );
  }
  return (
    <div className="ws-recordings group">
      {recordings.map((item) => (
        <button key={item.id} type="button" className="item" onClick={() => onOpen(item.id)}>
          <div className="row">
            <span className={`avatar app-${appKind(item.source_app)}`}><AppTileIcon app={item.source_app} /></span>
            <div className="item-main">
              <div className="item-line"><span className="item-title">{item.title}</span><span className="item-time">{formatWhen(item.started_at).split(" · ")[0]}</span></div>
              <div className="item-sub">{[formatDuration(item.duration_ms), item.language_chip && item.language_chip !== "Auto" ? item.language_chip : null].filter(Boolean).join(" · ")}</div>
            </div>
          </div>
        </button>
      ))}
    </div>
  );
}

// ---- Files -----------------------------------------------------------------------------------------------------

function Files({ ws, clientId, notify, onChange }: { ws: Workspace; clientId: string; notify: Notify; onChange: (ws: Workspace) => void }) {
  const [uploading, setUploading] = useState<string[]>([]);
  const [over, setOver] = useState(false);
  const [pasting, setPasting] = useState(false);
  const [pasteName, setPasteName] = useState("");
  const [pasteText, setPasteText] = useState("");
  const [busy, setBusy] = useState(false);
  const wsRef = useRef(ws);
  wsRef.current = ws;

  const upload = async (paths: string[]) => {
    const wanted = paths.filter((p) => (CLIENT_FILE_TYPES as readonly string[]).includes(fileExtension(p)));
    if (wanted.length < paths.length) notify("Only PDF, Word (.docx), text and Markdown files can be added.", wanted.length === 0);
    for (const path of wanted) {
      setUploading((list) => [...list, path]);
      try {
        const { data } = await api.uploadClientFile(clientId, path);
        onChange({ ...wsRef.current, files: [data, ...wsRef.current.files.filter((f) => f.id !== data.id)] });
      } catch (e) {
        notify(`${baseName(path)}: ${errorMessage(e)}`, true);
      } finally {
        setUploading((list) => list.filter((p) => p !== path));
      }
    }
  };
  const uploadRef = useRef(upload);
  uploadRef.current = upload;

  // While this tab is open, files dropped on the window are added here instead of being imported as recordings.
  useEffect(() => {
    const release = claimFileDrops((paths) => void uploadRef.current(paths));
    const un = listenForFileDrops({ onEnter: () => setOver(true), onLeave: () => setOver(false), onDrop: () => setOver(false) });
    return () => {
      release();
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  const pick = async () => {
    try {
      const paths = await api.pickClientFiles();
      if (paths.length > 0) await upload(paths);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  const paste = async () => {
    setBusy(true);
    try {
      const { data } = await api.addClientInstructions(clientId, pasteText, pasteName.trim() || null);
      onChange({ ...ws, files: [data, ...ws.files] });
      setPasting(false);
      setPasteText("");
      setPasteName("");
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(false);
    }
  };
  const remove = async (file: ClientFile) => {
    if (!window.confirm(`Remove ${file.name} from this client?`)) return;
    try {
      await api.deleteClientFile(clientId, file.id);
      onChange({ ...ws, files: ws.files.filter((f) => f.id !== file.id) });
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  return (
    <>
      <div className={`file-drop ${over ? "over" : ""}`} data-testid="file-drop">
        <ImportIcon size={26} />
        <span>Drop PDF, Word, text or Markdown files here. Claude reads them for the brief and for Ask.</span>
        <div className="row">
          <button type="button" className="btn primary" onClick={() => void pick()}><PlusIcon size={16} />Add files</button>
          <button type="button" className="btn" aria-expanded={pasting} onClick={() => setPasting(!pasting)}>Paste instructions</button>
        </div>
      </div>
      {pasting && (
        <form className="card" style={{ marginTop: 12, display: "grid", gap: 10 }} onSubmit={(e) => { e.preventDefault(); void paste(); }}>
          <input className="input" aria-label="Name" placeholder="Name (for example: House rules)" maxLength={120} value={pasteName} onChange={(e) => setPasteName(e.target.value)} />
          <textarea className="input" aria-label="Instructions" rows={6} maxLength={100_000} placeholder="Paste the client's instructions, rules or notes" value={pasteText} onChange={(e) => setPasteText(e.target.value)} />
          <div className="row">
            <button type="submit" className="btn primary" disabled={busy || !pasteText.trim()}>Save instructions</button>
            <button type="button" className="btn ghost" onClick={() => setPasting(false)}>Cancel</button>
          </div>
        </form>
      )}
      <div className="card" style={{ marginTop: 12, padding: "4px 12px" }} role="list" aria-label="Files">
        {uploading.map((path) => (
          <div key={path} className="file-row" role="listitem">
            <span className="file-tile"><FileIcon size={20} /></span>
            <span className="file-main"><span className="file-name">{baseName(path)}</span><span className="file-sub"><span className="spinner" aria-hidden="true" />Uploading…</span></span>
          </div>
        ))}
        {ws.files.length === 0 && uploading.length === 0 && <p className="small muted" style={{ margin: "10px 0" }}>No files yet.</p>}
        {ws.files.map((file) => (
          <div key={file.id} className="file-row" role="listitem">
            <span className="file-tile"><FileIcon size={20} /></span>
            <span className="file-main">
              <span className="file-name" title={file.name}>{file.name}</span>
              <span className="file-sub">
                {file.status === "extracting" ? <><span className="spinner" aria-hidden="true" />Reading…</>
                  : file.status === "failed" ? <span className="error-text"><AlertIcon size={13} /> Couldn't read it{file.error ? `: ${file.error}` : ""}</span>
                    : [fileKind(file), file.pages ? `${file.pages} page${file.pages === 1 ? "" : "s"}` : null, fileSize(file.size_bytes)].filter(Boolean).join(" · ")}
              </span>
            </span>
            <button type="button" className="btn ghost compact icon-only" aria-label={`Remove ${file.name}`} onClick={() => void remove(file)}><TrashIcon size={15} /></button>
          </div>
        ))}
      </div>
    </>
  );
}

// ---- Ask -------------------------------------------------------------------------------------------------------

/** A source chip's words: "Kick-off · Oct 30 · 2:05" for a call, "scope.pdf · page 2" for a file. */
export function citationLabel(c: AnswerCitation): string {
  if (c.type === "file") return `${c.title}${c.page ? ` · page ${c.page}` : ""}`;
  return `${c.title}${c.at_ms !== null && c.at_ms !== undefined ? ` · ${timestamp(c.at_ms)}` : ""}`;
}

function Ask({ ws, clientId, onOpenRecording }: { ws: Workspace; clientId: string; onOpenRecording: (id: string, ms?: number) => void }) {
  const [question, setQuestion] = useState("");
  const [language, setLanguage] = useState<AnswerLanguage>("en");
  const [asking, setAsking] = useState(false);
  const [answer, setAnswer] = useState<{ question: string; data: ClientAnswer } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const ready = useMemo(() => question.trim().length >= 3, [question]);
  const ask = async () => {
    const q = question.trim();
    if (q.length < 3) return;
    setAsking(true);
    setError(null);
    try {
      const { data } = await api.askClient(clientId, q, language);
      setAnswer({ question: q, data });
      void notifyWhenAway("Answer ready", q);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setAsking(false);
    }
  };
  return (
    <>
      {!ws.ai.configured && <div className="notice" role="status" style={{ marginBottom: 12 }}>Ask needs the server's Anthropic key. Ask the person who runs the console to add it.</div>}
      <form className="ask-box" onSubmit={(e) => { e.preventDefault(); void ask(); }}>
        <textarea className="input" aria-label="Question" placeholder={`Ask about ${ws.client.name}: “When do we launch?”`} maxLength={500} value={question}
          onChange={(e) => setQuestion(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) { e.preventDefault(); void ask(); } }} />
        <div className="row">
          <span className="segmented" role="group" aria-label="Answer in">
            <button type="button" aria-pressed={language === "en"} onClick={() => setLanguage("en")}>English</button>
            <button type="button" aria-pressed={language === "roman_urdu"} onClick={() => setLanguage("roman_urdu")}>Roman Urdu</button>
          </span>
          <span className="spacer" />
          <span className="small muted">Answers come from this client's calls and files.</span>
          <button type="submit" className="btn primary" disabled={!ready || asking}>{asking ? <span className="spinner" aria-hidden="true" /> : <SendIcon size={16} />}Ask</button>
        </div>
      </form>
      {error && <div className="notice error" role="alert" style={{ marginTop: 12 }}>{error}</div>}
      {asking && <div className="row small muted" role="status" style={{ marginTop: 12 }}><span className="spinner" aria-hidden="true" />Reading the calls and files…</div>}
      {answer && !asking && (
        <div className="ask-answer" aria-live="polite">
          <div className="small muted" style={{ marginBottom: 6 }}>{answer.question}</div>
          <p dir="auto">{answer.data.answer}</p>
          {answer.data.citations.length > 0 && (
            <div className="source-chips" role="list" aria-label="Sources">
              {answer.data.citations.map((c, i) => (
                <span role="listitem" key={i}>
                  {c.type === "call" && c.session_id ? (
                    <button type="button" className="source-chip" title={c.quote ?? undefined} onClick={() => onOpenRecording(c.session_id!, c.at_ms ?? 0)}>
                      <PlayIcon size={12} /><span>{citationLabel(c)}</span>
                    </button>
                  ) : (
                    <span className="source-chip" title={c.quote ?? undefined}><FileIcon size={14} /><span>{citationLabel(c)}</span></span>
                  )}
                </span>
              ))}
            </div>
          )}
          <button type="button" className="btn ghost compact" style={{ marginTop: 10 }} onClick={() => void navigator.clipboard.writeText(answer.data.answer).catch(() => undefined)}><CopyIcon size={14} />Copy answer</button>
          <button type="button" className="btn ghost compact" style={{ marginTop: 10 }} aria-label="Clear the answer" onClick={() => setAnswer(null)}><CloseIcon size={14} /></button>
        </div>
      )}
    </>
  );
}
