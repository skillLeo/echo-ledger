// A project's page (docs/API.md "Client workspaces" since v1.4, "Projects" since v1.5): breadcrumbs, its folders and
// menu, then Recordings (search, sort, its folders' recordings too), Files, Notes (the AI notes switch and budget, Ask,
// the brief and the memory), Screens (what the desktop kept of its meetings' screens, since v1.5; hidden on a server
// without them) and, while AI notes are on, the call notes Claude wrote. A plan without AI notes (since v1.5) hides that
// tab, the switch, Ask and Make again.
// Anything the server is still writing or reading is asked for again every few seconds until it is done.
import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { api, errorMessage, type AnswerCitation, type AnswerLanguage, type BriefSections, type ClientAnswer, type ClientFile, type ClientFolder, type ClientWorkspace as Workspace, type LibraryItem, type MemoryKind, type MemoryLine, type ProjectScreensPage, type SearchResult } from "../../lib/ipc";
import { formatDuration, formatWhen, timestamp } from "../../lib/format";
import { usePoll } from "../../lib/usePoll";
import { notifyWhenAway } from "../../lib/notify";
import { claimFileDrops } from "../../lib/dropTarget";
import { listenForFileDrops } from "../../lib/ipc";
import { searchItems, sortItems, type SortKey } from "../../lib/library";
import { ancestors, nestedCount, projectRecordings, projectTree } from "../../lib/projects";
import { beginRecordingDrag, dragJustEnded, dragLabel } from "../../lib/dragMove";
import { snippetParts } from "../../lib/player";
import { NO_AI_NOTES } from "../../lib/plan";
import { AlertIcon, AppTileIcon, appKind, ChevronRightIcon, CloseIcon, CopyIcon, FileIcon, FolderIcon, ImportIcon, PinIcon, PlusIcon, SearchIcon, SendIcon, SparkleIcon, TrashIcon } from "../../components/Icons";
import { PlayIcon } from "../../components/ControlIcons";
import { NotesSections } from "./CallNotes";
import { ClientAvatar, clientSummary } from "./ClientAvatar";
import { MenuButton, type MenuItem } from "./MenuButton";
import { PlanBadge } from "./Upgrade";
import { ProjectScreens } from "./ScreenMoments";

type Notify = (text: string, error?: boolean) => void;
type Tab = "recordings" | "files" | "notes" | "screens" | "ai_notes";
const TABS: readonly [Tab, string][] = [["recordings", "Recordings"], ["files", "Files"], ["notes", "Notes"], ["screens", "Screens"], ["ai_notes", "AI notes"]];

/** The sort orders of a project's recordings: newest, oldest, name, length. */
export const PROJECT_SORTS: readonly [SortKey, string][] = [["newest", "Newest first"], ["oldest", "Oldest first"], ["title", "Name A–Z"], ["longest", "Longest first"]];

/** The brief's sections in the order the page shows them; `goal` is one line, the rest are lists. */
export const BRIEF_FIELDS: readonly [keyof BriefSections, string][] = [
  ["goal", "Goal"], ["scope", "Scope"], ["constraints", "Constraints"], ["deadlines", "Deadlines"], ["budget", "Budget"], ["people", "People"], ["glossary", "Glossary"],
];
/** Memory grouped as on the phone: decisions, open questions, changes, scope. */
export const MEMORY_GROUPS: readonly [MemoryKind, string][] = [["decision", "Decisions"], ["question", "Open questions"], ["change", "Changes"], ["scope", "Scope"]];
/** File types a workspace takes (the server refuses the rest). */
export const CLIENT_FILE_TYPES = ["pdf", "docx", "txt", "md"] as const;

const money = (usd: number) => `$${(Number.isFinite(usd) ? usd : 0).toFixed(2)}`;
/** "AI this month: $0.42 of $5.00 · this project $0.12" */
export function budgetLine(ai: Workspace["ai"]): string {
  return `AI this month: ${money(ai.spent_usd)} of ${money(ai.budget_usd)} · this project ${money(ai.client_spent_usd)}`;
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

export function ClientWorkspace({ client, projects = [client], items, notify, onOpenRecording, onClientChanged, onOpenProject, menu = [], actions = null, onMoveRecordings, rules = null, aiNotesInPlan = true }: {
  client: ClientFolder;
  /** Every project, for the breadcrumbs, the folders and the folders' recordings. */
  projects?: readonly ClientFolder[];
  /** The saved library. */
  items: readonly LibraryItem[];
  notify: Notify;
  onOpenRecording: (id: string, ms?: number) => void;
  onClientChanged: (client: Partial<ClientFolder> & { id: string }) => void;
  /** Opens another project (a breadcrumb or a folder); null = the Projects page. */
  onOpenProject?: (id: string | null) => void;
  /** The project menu (New folder inside, Rename, Move, Share, Delete …). */
  menu?: readonly (MenuItem | null | false)[];
  /** Buttons beside the title (Share). */
  actions?: ReactNode;
  /** Recordings dragged from the list onto a project in the sidebar. */
  onMoveRecordings?: (ids: string[], projectId: string | null) => void;
  /** The filing rules section of the Recordings tab (since v1.5). */
  rules?: ReactNode;
  /** The plan has call notes, memory and Ask (`ai_notes`, since v1.5): without them the AI parts are hidden. */
  aiNotesInPlan?: boolean;
}) {
  const [tab, setTab] = useState<Tab>("recordings");
  const [ws, setWs] = useState<Workspace | null>(null);
  const [error, setError] = useState<string | null>(null);
  const previous = useRef<Workspace | null>(null);
  const crumbs = useMemo(() => ancestors(projects, client.id), [projects, client.id]);
  const subfolders = useMemo(() => projectTree(projects.filter((p) => p.parent_id === client.id)).map((n) => n.project), [projects, client.id]);
  const own = useMemo(() => projectRecordings(items, projects, client.id, false), [items, projects, client.id]);
  // AI notes are shown while they are on for the project; before the workspace has loaded, as the list says. A plan
  // without them hides them whatever the project's switch says.
  const aiNotes = aiNotesInPlan && (ws ? ws.client.ai_notes : client.ai_notes !== false);
  // Screen moments (since v1.5): the first page of the project's screens is read with the project; a server without
  // them (or a plan without them) answers none, and the tab goes.
  const [screens, setScreens] = useState<{ id: string; page: ProjectScreensPage | null; error: string | null } | null>(null);
  const [screensAttempt, setScreensAttempt] = useState(0);
  useEffect(() => {
    let live = true;
    api.clientScreens(client.id, true, null)
      .then((page) => { if (live) setScreens({ id: client.id, page, error: null }); })
      .catch((e) => { if (live) setScreens({ id: client.id, page: null, error: errorMessage(e) }); });
    return () => { live = false; };
  }, [client.id, screensAttempt]);
  const screensRead = screens?.id === client.id ? screens : null;
  const screensMissing = !!screensRead && !screensRead.page && !screensRead.error;
  const tabs = TABS.filter(([id]) => (id !== "ai_notes" || aiNotes) && (id !== "screens" || !screensMissing));
  useEffect(() => {
    if (tab === "ai_notes" && !aiNotes) setTab("notes");
    if (tab === "screens" && screensMissing) setTab("recordings");
  }, [tab, aiNotes, screensMissing]);

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
      notify(typeof e === "string" ? e : "Could not copy the project's context", true);
    }
  };

  const total = Math.max(nestedCount(client, items, projects), own.length);
  return (
    <>
      {onOpenProject && (
        <nav className="crumbs" aria-label="Where this project is">
          <button type="button" className="crumb" onClick={() => onOpenProject(null)}>Projects</button>
          {crumbs.map((c) => (
            <span key={c.id} className="crumb-step">
              <ChevronRightIcon size={12} aria-hidden="true" />
              <button type="button" className="crumb" onClick={() => onOpenProject(c.id)}>{c.name}</button>
            </span>
          ))}
          <span className="crumb-step"><ChevronRightIcon size={12} aria-hidden="true" /><span className="crumb current" aria-current="page">{client.name}</span></span>
        </nav>
      )}
      <div className="workspace-head">
        <ClientAvatar client={client} large />
        <div style={{ minWidth: 0, flex: 1 }}>
          <h1 className="page-title">{client.name}</h1>
          <div className="small muted">{clientSummary(total, projectRecordings(items, projects, client.id, true)[0]?.started_at)}</div>
        </div>
        {actions}
        <button type="button" className="btn" onClick={() => void copyForAi()} title="The brief, memory and recent call notes as one text, ready to paste into a chat">
          <SparkleIcon size={16} />Copy for AI
        </button>
        {menu.some(Boolean) && <MenuButton label="Project menu" items={menu} />}
      </div>
      {subfolders.length > 0 && onOpenProject && (
        <div className="subfolders" role="list" aria-label="Folders">
          {subfolders.map((f) => (
            <span role="listitem" key={f.id}>
              <button type="button" className="folder-chip" onClick={() => onOpenProject(f.id)}>
                <FolderIcon size={16} /><span>{f.name}</span><span className="muted">{nestedCount(f, items, projects) || ""}</span>
              </button>
            </span>
          ))}
        </div>
      )}
      <div className="tabs" role="tablist" aria-label={`${client.name} project`}>
        {tabs.map(([id, label]) => (
          <button key={id} type="button" role="tab" id={`ws-tab-${id}`} className="tab" aria-selected={tab === id} aria-controls={`ws-panel-${id}`} onClick={() => setTab(id)}>{label}</button>
        ))}
      </div>
      <div role="tabpanel" id={`ws-panel-${tab}`} aria-labelledby={`ws-tab-${tab}`}>
        {error && !ws && tab !== "recordings" && tab !== "screens" && (
          <div className="notice error sync-error" role="alert">
            <span>Couldn't load {client.name}'s workspace. {error}</span>
            <button type="button" className="btn compact" onClick={() => void load()}>Retry</button>
          </div>
        )}
        {!ws && !error && tab !== "recordings" && tab !== "screens" && (
          <div aria-busy="true">
            <span className="sr-only">Loading the workspace…</span>
            <span className="skeleton" style={{ width: "60%", height: 14, marginBottom: 10 }} />
            <span className="skeleton" style={{ width: "80%", height: 12, marginBottom: 10 }} />
            <span className="skeleton" style={{ width: "45%", height: 12 }} />
          </div>
        )}
        {tab === "recordings" && (
          <Recordings client={client} projects={projects} items={items} hasFolders={subfolders.length > 0} onOpen={(id, ms) => onOpenRecording(id, ms)} onMove={onMoveRecordings} rules={rules} />
        )}
        {tab === "files" && ws && <Files ws={ws} clientId={client.id} notify={notify} onChange={setWs} />}
        {tab === "notes" && ws && <Notes ws={ws} client={client} notify={notify} onChange={setWs} onReload={load} onOpenRecording={onOpenRecording} onClientChanged={onClientChanged} inPlan={aiNotesInPlan} />}
        {tab === "screens" && (screensRead?.page ? (
          <ProjectScreens client={client} first={screensRead.page} notify={notify} onOpenRecording={onOpenRecording} onClientChanged={onClientChanged} />
        ) : screensRead?.error ? (
          <div className="notice error sync-error" role="alert">
            <span>Couldn't load {client.name}'s screens. {screensRead.error}</span>
            <button type="button" className="btn compact" onClick={() => setScreensAttempt((n) => n + 1)}>Retry</button>
          </div>
        ) : (
          <div aria-busy="true">
            <span className="sr-only">Loading the screens…</span>
            <span className="skeleton" style={{ width: "40%", height: 14, marginBottom: 10 }} />
            <span className="skeleton" style={{ width: "70%", height: 90 }} />
          </div>
        ))}
        {tab === "ai_notes" && ws && <AiNotes ws={ws} clientName={client.name} onOpenRecording={onOpenRecording} />}
      </div>
    </>
  );
}

// ---- Notes -----------------------------------------------------------------------------------------------------

/**
 * The AI notes switch and the month's AI budget, Ask, the brief and the memory. A plan without `ai_notes` keeps the
 * brief (edited by hand) and the memory, as the phone does, and says why the rest is not there.
 */
function Notes({ ws, client, notify, onChange, onReload, onOpenRecording, onClientChanged, inPlan }: {
  ws: Workspace;
  client: ClientFolder;
  notify: Notify;
  onChange: (ws: Workspace) => void;
  onReload: () => Promise<void>;
  onOpenRecording: (id: string, ms?: number) => void;
  onClientChanged: (client: Partial<ClientFolder> & { id: string }) => void;
  inPlan: boolean;
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
  if (!inPlan) {
    return (
      <>
        <section className="ws-section" aria-label="AI">
          <div className="card small muted row" data-testid="ai-not-in-plan"><span>{NO_AI_NOTES}</span><span className="spacer" /><PlanBadge feature="ai_notes" /></div>
        </section>
        <Brief ws={ws} clientId={client.id} notify={notify} onChange={onChange} onReload={onReload} canMake={false} />
        <Memory ws={ws} clientId={client.id} notify={notify} onChange={onChange} onOpenRecording={onOpenRecording} />
      </>
    );
  }
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
      <section className="ws-section" aria-labelledby="ws-ask">
        <h3 id="ws-ask">Ask</h3>
        <Ask ws={ws} clientId={client.id} onOpenRecording={onOpenRecording} />
      </section>
      <Brief ws={ws} clientId={client.id} notify={notify} onChange={onChange} onReload={onReload} />
      <Memory ws={ws} clientId={client.id} notify={notify} onChange={onChange} onOpenRecording={onOpenRecording} />
    </>
  );
}

// ---- AI notes --------------------------------------------------------------------------------------------------

/** The call notes Claude wrote after the project's calls, newest first (the last 10 the server keeps here). */
function AiNotes({ ws, clientName, onOpenRecording }: { ws: Workspace; clientName: string; onOpenRecording: (id: string, ms?: number) => void }) {
  return (
    <section className="ws-section" aria-label="Call notes">
      {ws.notes.length === 0 && (
        <div className="empty-state">
          <span className="empty-icon"><SparkleIcon size={26} /></span>
          <span className="empty-title">No call notes yet</span>
          <p>Notes appear here after a call filed under {clientName} is kept and transcribed.</p>
        </div>
      )}
      {ws.notes.map((note) => (
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
  );
}

/** The brief; `canMake` false (a plan without `ai_notes`) leaves out Make again: the brief is then written by hand. */
function Brief({ ws, clientId, notify, onChange, onReload, canMake = true }: { ws: Workspace; clientId: string; notify: Notify; onChange: (ws: Workspace) => void; onReload: () => Promise<void>; canMake?: boolean }) {
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
    if (brief?.edited && !window.confirm("Write the brief again from the project's files and call notes? Your edits are replaced.")) return;
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
        {!editing && canMake && <button type="button" className="btn ghost compact" disabled={busy || brief?.status === "working"} onClick={() => void makeAgain()}><SparkleIcon size={14} />Make again</button>}
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
          brief?.status !== "working" && <p className="small muted" style={{ margin: 0 }}>{canMake ? "No brief yet. Add files, keep a call, or press Make again." : "No brief yet. Press Edit to write one."}</p>
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

/**
 * The project's recordings (its folders' too when asked), found by the words of their titles and previews and, from
 * two letters on, by what was said in them; sorted newest, oldest, by name or by length. A row can be dragged onto
 * another project in the sidebar.
 */
function Recordings({ client, projects, items, hasFolders, onOpen, onMove, rules }: {
  client: ClientFolder;
  projects: readonly ClientFolder[];
  items: readonly LibraryItem[];
  hasFolders: boolean;
  onOpen: (id: string, ms?: number) => void;
  onMove?: (ids: string[], projectId: string | null) => void;
  rules?: ReactNode;
}) {
  const [query, setQuery] = useState("");
  const [sort, setSort] = useState<SortKey>("newest");
  const [nested, setNested] = useState(false);
  const [found, setFound] = useState<SearchResult["data"]>([]);
  const withFolders = nested && hasFolders;
  const all = useMemo(() => projectRecordings(items, projects, client.id, withFolders), [items, projects, client.id, withFolders]);
  const shown = useMemo(() => sortItems(searchItems(all, query), sort), [all, query, sort]);
  const folderName = (item: LibraryItem) => (item.client && item.client.id !== client.id ? item.client.name : null);
  // What was said: the server's transcript search, for this project.
  useEffect(() => {
    const q = query.trim();
    if (q.length < 2) {
      setFound([]);
      return;
    }
    let alive = true;
    const timer = window.setTimeout(() => {
      api.searchLibrary({ q, clientId: client.id }).then((r) => alive && setFound(r.data.filter((d) => d.hits.length > 0))).catch(() => alive && setFound([]));
    }, 350);
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [query, client.id]);
  const startDrag = (e: React.PointerEvent, item: LibraryItem) => {
    if (!onMove) return;
    beginRecordingDrag(e, () => ({ ids: [item.id], label: dragLabel([item.title]) }), onMove);
  };
  return (
    <>
      <div className="toolbar project-tools">
        <label className="searchbox">
          <SearchIcon size={18} />
          <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder={`Search ${client.name}`} aria-label="Search in this project" />
          {query && <button className="clear" onClick={() => setQuery("")} aria-label="Clear search"><CloseIcon size={16} /></button>}
        </label>
        <select className="chip" value={sort} onChange={(e) => setSort(e.target.value as SortKey)} aria-label="Sort recordings">
          {PROJECT_SORTS.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select>
        {hasFolders && <label className="check"><input type="checkbox" checked={nested} onChange={(e) => setNested(e.target.checked)} />Include its folders</label>}
      </div>
      {rules}
      {found.length > 0 && (
        <div className="group search-hits" role="region" aria-label="Matches in transcripts">
          <div className="group-label">In transcripts</div>
          {found.map((r) => (
            <div key={r.session.id} className="hit-card">
              <div className="item-title">{r.session.title}</div>
              {r.hits.map((h) => (
                <button key={`${h.transcript_id}-${h.segment_index}`} type="button" className="hit" onClick={() => onOpen(r.session.id, h.start_ms)}
                  aria-label={`Play ${r.session.title ?? "recording"} from ${timestamp(h.start_ms)}`}>
                  <span className="seg-time">{timestamp(h.start_ms)}</span>
                  <span className="hit-snippet" dir="auto"><b>{h.speaker}:</b> {snippetParts(h.snippet, h.highlights).map((p, i) => (p.hit ? <mark key={i}>{p.text}</mark> : <span key={i}>{p.text}</span>))}</span>
                </button>
              ))}
            </div>
          ))}
        </div>
      )}
      {all.length === 0 ? (
        <div className="empty-state">
          <span className="empty-icon"><FileIcon size={26} /></span>
          <span className="empty-title">No recordings yet</span>
          <p>Recordings filed under {client.name} show up here. Drag recordings from the Library onto it in the sidebar, or use Move.</p>
        </div>
      ) : shown.length === 0 ? (
        <div className="empty">Nothing matches. Try other words.</div>
      ) : (
        <div className="ws-recordings group" role="list" aria-label={`${client.name} recordings`}>
          {shown.map((item) => (
            <div role="listitem" key={item.id}>
              <button type="button" className="item" onPointerDown={(e) => startDrag(e, item)} onClick={() => { if (!dragJustEnded()) onOpen(item.id); }}>
                <div className="row">
                  <span className={`avatar app-${appKind(item.source_app)}`}><AppTileIcon app={item.source_app} /></span>
                  <div className="item-main">
                    <div className="item-line"><span className="item-title">{item.title}</span><span className="item-time">{formatWhen(item.started_at).split(" · ")[0]}</span></div>
                    <div className="item-sub">{[formatDuration(item.duration_ms), item.language_chip && item.language_chip !== "Auto" ? item.language_chip : null, folderName(item) ? `in ${folderName(item)}` : null].filter(Boolean).join(" · ")}</div>
                  </div>
                </div>
              </button>
            </div>
          ))}
        </div>
      )}
    </>
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
    if (!window.confirm(`Remove ${file.name} from this project?`)) return;
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
          <textarea className="input" aria-label="Instructions" rows={6} maxLength={100_000} placeholder="Paste instructions, rules or notes for this project" value={pasteText} onChange={(e) => setPasteText(e.target.value)} />
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
        <textarea className="input" aria-label="Question" dir="auto" placeholder={`Ask about ${ws.client.name}: “When do we launch?”`} maxLength={500} value={question}
          onChange={(e) => setQuestion(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) { e.preventDefault(); void ask(); } }} />
        <div className="row">
          <span className="segmented" role="group" aria-label="Answer in">
            <button type="button" aria-pressed={language === "en"} onClick={() => setLanguage("en")}>English</button>
            <button type="button" aria-pressed={language === "roman_urdu"} onClick={() => setLanguage("roman_urdu")}>Roman Urdu</button>
          </span>
          <span className="spacer" />
          <span className="small muted">Answers come from this project's calls and files.</span>
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
