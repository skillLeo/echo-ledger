// A project's page in the new look (Workspaces, IA 2.2; behind ui_v2.ask), with everything today's page has
// (views/main/ClientWorkspace.tsx; workspace.test.tsx keeps the two lists equal):
// - Recordings: search (titles and what was said), sort, its folders' recordings, its folders, drag onto a project,
//   filing rules;
// - Files: Add files, files dropped on the window, Paste instructions with a name, Remove after a confirmation;
// - Ask: Ask with Copy answer (when the plan has AI notes);
// - Memory & instructions: the AI notes switch and budget, the brief (Edit, Save brief, Write again), memory (pin,
//   remove, add), instructions;
// - Call notes and Screens, as today;
// and Share and the project menu, whose dialogs stay the Projects page's.
import { Fragment, useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import { api, errorMessage, type BriefSections, type ClientFolder, type ClientWorkspace, type LibraryItem, type MemoryKind, type MemoryLine } from "../../lib/ipc";
import { formatDuration, formatWhen, rowDate, timestamp } from "../../lib/format";
import { usePoll } from "../../lib/usePoll";
import { confirmAction } from "../../lib/confirm";
import { NO_AI_NOTES } from "../../lib/plan";
import { BRIEF_FIELDS, budgetLine, workspaceBusy } from "../../views/main/ClientWorkspace";
import { NotesSections } from "../../views/main/CallNotes";
import { LockedChip, PlanBadge, useLocked } from "../../views/main/Upgrade";
import { ProjectScreens } from "../../views/main/ScreenMoments";
import type { Notify } from "../../lib/notice";
import {
  Banner, Button, Card, EmptyState, Icons, List, ListRow, ListSection, Loading, Select, SkeletonRow, Tabs, TextArea, TextField, Toast, ToastRegion, Toggle,
} from "../components";
import { Menu, type MenuItem } from "../components/Menu";
import { AskV2 } from "../ask/AskV2";
import { RulesV2 } from "./Rules";
import { RecordingsTab } from "./RecordingsTab";
import { FilesTab, fileLine } from "./FilesTab";

type Tab = "recordings" | "files" | "ask" | "memory" | "notes" | "screens";

export const MEMORY_KINDS: readonly { value: MemoryKind; label: string; heading: string }[] = [
  { value: "decision", label: "Decision", heading: "Decisions" },
  { value: "scope", label: "Scope", heading: "Scope" },
  { value: "change", label: "Change", heading: "Changes" },
  { value: "question", label: "Open question", heading: "Open questions" },
];

/** Memory lines under their kind's heading, pinned first, in the order MEMORY_KINDS gives. */
export function memoryGroups(lines: readonly MemoryLine[]): { kind: MemoryKind; heading: string; lines: MemoryLine[] }[] {
  return MEMORY_KINDS.map((k) => ({
    kind: k.value,
    heading: k.heading,
    lines: lines.filter((l) => l.kind === k.value).sort((a, b) => Number(b.pinned) - Number(a.pinned)),
  })).filter((g) => g.lines.length > 0);
}

/** A change to the loaded workspace, made on the latest one, so two changes in flight never undo each other. */
export type WorkspaceUpdate = (update: (current: ClientWorkspace) => ClientWorkspace) => void;

export type WorkspaceV2Props = {
  project: { id: string; name: string; ai_notes?: boolean };
  /** Every project (its folders' recordings, the drag targets). */
  projects?: readonly ClientFolder[];
  /** The project it sits in; Back goes there (null: the Projects page). */
  parent?: { id: string; name: string } | null;
  /** Its folders, with how many recordings each holds. */
  folders?: { id: string; name: string; count: number }[];
  /** The project menu (Projects page's actions and dialogs). */
  menu?: MenuItem[];
  /** Share: a link to the project's recordings, or send it to a teammate. */
  onShare?: () => void;
  /** Filing rules are shown (a server with projects in folders). */
  rules?: boolean;
  /** The plan has call notes, memory writing and Ask (`ai_notes`); without it they wait for an upgrade, as today. */
  aiNotesInPlan?: boolean;
  /** Recordings dragged from here onto a project (or the Inbox) in the sidebar. */
  onMoveRecordings?: (ids: string[], projectId: string | null) => void;
  /** A project's AI notes switch changed (the Projects page's list follows). */
  onProjectChanged?: (change: Partial<ClientFolder> & { id: string }) => void;
  /** Today's screens list reports through the app's notices. */
  notify?: Notify;
  onBack: () => void;
  onOpenProject?: (id: string) => void;
  onOpenRecording: (id: string, ms?: number) => void;
  onLibrary: () => void;
};

export function WorkspaceV2({
  project, projects = [], parent = null, folders = [], menu = [], onShare, rules = false, aiNotesInPlan = true, onMoveRecordings, onProjectChanged,
  notify = () => undefined, onBack, onOpenProject, onOpenRecording, onLibrary,
}: WorkspaceV2Props) {
  const [tab, setTab] = useState<Tab>("recordings");
  const [items, setItems] = useState<LibraryItem[] | null>(null);
  const [itemsFailed, setItemsFailed] = useState(false);
  const [ws, setWs] = useState<ClientWorkspace | null>(null);
  const [wsFailed, setWsFailed] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const [message, setMessage] = useState<{ id: number; text: string; action?: { label: string; run: () => void } } | null>(null);
  const say = useCallback((text: string, action?: { label: string; run: () => void }) => setMessage({ id: Date.now(), text, action }), []);
  const update: WorkspaceUpdate = useCallback((change) => setWs((cur) => (cur ? change(cur) : cur)), []);
  const askLocked = useLocked("ai_notes");

  useEffect(() => {
    setItemsFailed(false);
    api.library().then((l) => setItems(Array.isArray(l) ? l : []), () => setItemsFailed(true));
  }, [attempt]);
  const load = useCallback(async () => {
    setWsFailed(null);
    try {
      setWs((await api.clientWorkspace(project.id)).data);
    } catch (e) {
      setWsFailed(errorMessage(e));
    }
  }, [project.id]);
  useEffect(() => void load(), [load, attempt]);
  // Anything the server is still writing or reading is asked for again until it is done, as today.
  usePoll(workspaceBusy(ws), load);

  const all = useMemo(() => (projects.length ? projects : [project as ClientFolder]), [projects, project]);
  const ownCount = useMemo(() => (items ? items.filter((i) => !i.deleted && i.client?.id === project.id).length : null), [items, project.id]);
  // AI notes while they are on for the project and in the plan; a plan without them keeps memory and the brief.
  const aiNotes = aiNotesInPlan && (ws ? ws.client.ai_notes : project.ai_notes !== false);
  const tabs: { value: Tab; label: string }[] = [
    { value: "recordings", label: "Recordings" },
    { value: "files", label: "Files" },
    ...(aiNotesInPlan ? [{ value: "ask" as const, label: "Ask" }] : []),
    { value: "memory", label: "Memory & instructions" },
    ...(aiNotes ? [{ value: "notes" as const, label: "Call notes" }] : []),
    { value: "screens", label: "Screens" },
  ];
  const wsPart = (body: (ws: ClientWorkspace) => ReactNode) => (
    wsFailed && !ws ? (
      <EmptyState tone="error" icon={Icons.ErrorIcon} title="The project's workspace didn't load" body={wsFailed}
        action={<Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
    ) : !ws ? (
      <Loading label="Loading the project's workspace"><Card><div className="el-group__rows"><SkeletonRow end={false} /><SkeletonRow end={false} /></div></Card></Loading>
    ) : body(ws)
  );

  return (
    <div className="el-page el-page--narrow" data-v2="workspace">
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>{parent ? parent.name : "Projects"}</Button>
      <header className="el-page__head el-page__head--top">
        <div className="el-page__titles">
          <h1 className="el-title-l" dir="auto">{project.name}</h1>
          {ownCount !== null && <p className="el-secondary">{ownCount === 1 ? "1 recording" : `${ownCount} recordings`}</p>}
        </div>
        <div className="el-page__actions">
          {onShare && <Button icon={Icons.ShareIcon} onClick={onShare}>Share</Button>}
          {menu.length > 0 && <Menu label="Project menu" items={menu} button={{ variant: "quiet", icon: Icons.MoreIcon }} />}
        </div>
      </header>
      <Tabs label="Project" value={tabs.some((t) => t.value === tab) ? tab : "recordings"} onChange={setTab} tabs={tabs}>
        {tab === "recordings" && (
          <RecordingsTab project={project} projects={all} items={items} itemsFailed={itemsFailed} folders={folders} onRetry={() => setAttempt((n) => n + 1)}
            onOpenRecording={onOpenRecording} onOpenProject={onOpenProject} onMoveRecordings={onMoveRecordings} onLibrary={onLibrary}
            rules={rules ? <RulesV2 projectId={project.id} projectName={project.name} say={say} /> : undefined} />
        )}
        {tab === "files" && wsPart((w) => <FilesTab projectId={project.id} files={w.files} onChanged={update} say={say} />)}
        {tab === "ask" && (askLocked ? (
          <LockedChip feature="ai_notes" label="Ask: answers about this project, from its recordings and files" />
        ) : (
          <AskV2 embedded projects={[project]} initialProjectId={project.id} onOpenAt={(id, ms) => onOpenRecording(id, ms)}
            onOpenProject={() => setTab("files")} onLibrary={onLibrary} />
        ))}
        {tab === "memory" && wsPart((w) => (
          <Memory projectId={project.id} projectName={project.name} ws={w} onChanged={update} onOpenRecording={onOpenRecording} say={say}
            aiNotesInPlan={aiNotesInPlan && !askLocked} onProjectChanged={onProjectChanged} />
        ))}
        {tab === "notes" && wsPart((w) => <CallNotesTab ws={w} projectName={project.name} onOpenRecording={onOpenRecording} />)}
        {tab === "screens" && <ScreensTab project={project as ClientFolder} notify={notify} onOpenRecording={onOpenRecording} onProjectChanged={onProjectChanged} />}
      </Tabs>
      <ToastRegion>
        {message && <Toast key={message.id} message={message.text} actionLabel={message.action?.label} onAction={message.action?.run} onClose={() => setMessage(null)} />}
      </ToastRegion>
    </div>
  );
}

/** Today's screens list for the project (what the desktop kept of its meetings' screens). */
function ScreensTab({ project, notify, onOpenRecording, onProjectChanged }: {
  project: ClientFolder;
  notify: Notify;
  onOpenRecording: (id: string, ms?: number) => void;
  onProjectChanged?: (change: Partial<ClientFolder> & { id: string }) => void;
}) {
  const [page, setPage] = useState<Awaited<ReturnType<typeof api.clientScreens>> | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let live = true;
    setError(null);
    api.clientScreens(project.id, true, null).then((p) => live && setPage(p), (e) => live && setError(errorMessage(e)));
    return () => {
      live = false;
    };
  }, [project.id, attempt]);
  if (error) {
    return <EmptyState tone="error" icon={Icons.ErrorIcon} title="The screens didn't load" body={error} action={<Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />;
  }
  if (page === undefined) return <Loading label="Loading the screens"><SkeletonRow end={false} /></Loading>;
  if (!page) return <EmptyState icon={Icons.ScreenIcon} title="No screens here" body="Screens kept during this project's meetings appear here when the server keeps them." />;
  return <ProjectScreens client={project} first={page} notify={notify} onOpenRecording={onOpenRecording} onClientChanged={onProjectChanged ?? (() => undefined)} />;
}

/** The call notes written after the project's calls, newest first, as today's AI notes tab. */
function CallNotesTab({ ws, projectName, onOpenRecording }: { ws: ClientWorkspace; projectName: string; onOpenRecording: (id: string, ms?: number) => void }) {
  if (ws.notes.length === 0) {
    return <EmptyState icon={Icons.TranscriptIcon} title="No call notes yet" body={`Notes appear here after a call filed under ${projectName} is kept and transcribed.`} />;
  }
  return (
    <div className="el-workspace-tab" aria-label="Call notes">
      {ws.notes.map((note) => (
        <Card key={note.id} className="el-form" label={note.session?.title ?? "Call"}>
          <div className="el-group__head">
            <span className="el-body-strong" dir="auto">{note.session?.title ?? "Call"}</span>
            <span className="el-caption">{formatWhen(note.session?.started_at ?? note.created_at ?? undefined)}{note.session?.duration_ms ? ` · ${formatDuration(note.session.duration_ms)}` : ""}</span>
            <Button size="sm" variant="quiet" onClick={() => onOpenRecording(note.session_id)}>Open</Button>
          </div>
          {note.status === "pending" && <p className="el-caption" role="status">Writing the notes…</p>}
          {(note.status === "failed" || note.status === "skipped") && <p className="el-caption">{note.error ?? "No notes were written for this call."}</p>}
          {note.status === "ready" && <NotesSections notes={note} onOpen={(ms) => onOpenRecording(note.session_id, ms)} />}
        </Card>
      ))}
    </div>
  );
}

function Memory({ projectId, projectName, ws, onChanged, onOpenRecording, say, aiNotesInPlan, onProjectChanged }: {
  projectId: string;
  projectName: string;
  ws: ClientWorkspace;
  onChanged: WorkspaceUpdate;
  onOpenRecording: (id: string, ms?: number) => void;
  say: (text: string) => void;
  aiNotesInPlan: boolean;
  onProjectChanged?: (change: Partial<ClientFolder> & { id: string }) => void;
}) {
  const [kind, setKind] = useState<MemoryKind>("decision");
  const [text, setText] = useState("");
  const [editing, setEditing] = useState<Record<string, string> | null>(null);
  // What is under way, by key: several changes can be in flight at once.
  const [busySet, setBusySet] = useState<ReadonlySet<string>>(new Set());
  const busy = (key: string) => busySet.has(key);
  const [error, setError] = useState<string | null>(null);
  const run = async (key: string, fn: () => Promise<void>) => {
    setBusySet((cur) => new Set(cur).add(key));
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusySet((cur) => {
        const next = new Set(cur);
        next.delete(key);
        return next;
      });
    }
  };
  const sections = ws.brief?.sections ?? {};
  const filled = BRIEF_FIELDS.filter(([key]) => {
    const value = sections[key];
    return Array.isArray(value) ? value.length > 0 : !!value;
  });
  const instructionFiles = ws.files.filter((f) => f.instructions);
  const groups = memoryGroups(ws.memory);

  const startEdit = () => setEditing(Object.fromEntries(BRIEF_FIELDS.map(([key]) => {
    const value = sections[key];
    return [key, Array.isArray(value) ? value.join("\n") : (value ?? "")];
  })));
  const saveBrief = () => run("brief-save", async () => {
    const next: BriefSections = {};
    for (const [key] of BRIEF_FIELDS) {
      const t = editing?.[key] ?? "";
      if (key === "goal") next.goal = t.trim() || null;
      else (next as Record<string, string[]>)[key] = t.split("\n").map((l) => l.trim()).filter(Boolean);
    }
    const brief = (await api.saveClientBrief(projectId, next)).data;
    onChanged((cur) => ({ ...cur, brief }));
    setEditing(null);
    say("Brief saved");
  });
  const writeAgain = async () => {
    if (ws.brief?.edited && !(await confirmAction({ title: "Write the brief again?", body: "It's written from the project's files and call notes. Your edits are replaced.", confirm: "Write again", tone: "danger" }))) return;
    await run("brief", async () => {
      const brief = (await api.refreshClientBrief(projectId)).data;
      onChanged((cur) => ({ ...cur, brief }));
    });
  };
  const removeLine = async (line: MemoryLine) => {
    if (!(await confirmAction({ title: "Remove this line from memory?", body: `“${line.text}”`, confirm: "Remove", tone: "danger" }))) return;
    await run(`remove-${line.id}`, async () => {
      await api.deleteClientMemory(projectId, line.id);
      onChanged((cur) => ({ ...cur, memory: cur.memory.filter((l) => l.id !== line.id) }));
    });
  };
  const removeInstructions = async (id: string, name: string) => {
    if (!(await confirmAction({ title: `Remove ${name} from this project?`, confirm: "Remove", tone: "danger" }))) return;
    await run(`remove-${id}`, async () => {
      await api.deleteClientFile(projectId, id);
      onChanged((cur) => ({ ...cur, files: cur.files.filter((f) => f.id !== id) }));
    });
  };
  const toggleNotes = (on: boolean) => run("ai-notes", async () => {
    await api.updateClient(projectId, { ai_notes: on });
    onChanged((cur) => ({ ...cur, client: { ...cur.client, ai_notes: on } }));
    onProjectChanged?.({ id: projectId, ai_notes: on });
  });

  return (
    <div className="el-workspace-memory">
      {error && <Banner tone="danger" icon={Icons.ErrorIcon} onClose={() => setError(null)}>{error}</Banner>}

      {aiNotesInPlan ? (
        <section className="el-group" aria-label="AI">
          <Card className="el-form">
            <Toggle label="AI notes" hint={`EchoLedger writes call notes after each kept recording of ${projectName}.`} checked={ws.client.ai_notes}
              disabled={busy("ai-notes")} onChange={(on) => void toggleNotes(on)} />
            <p className="el-caption" data-testid="ai-budget">
              {ws.ai.configured ? budgetLine(ws.ai) : "AI isn't set up on the server yet: notes, the brief and Ask wait for it. Ask the person who runs the console."}
            </p>
          </Card>
        </section>
      ) : (
        <section className="el-group" aria-label="AI">
          <Card className="el-form el-ai-off" ><span data-testid="ai-not-in-plan">{NO_AI_NOTES}</span><PlanBadge feature="ai_notes" /></Card>
        </section>
      )}

      <section className="el-group" aria-label="Brief">
        <div className="el-group__head">
          <h2 className="el-caption el-body-strong el-group__title">Brief{ws.brief?.edited ? " · edited by you" : ""}</h2>
          {!editing && (
            <span className="el-row__buttons">
              <Button size="sm" variant="quiet" disabled={busy("brief") || ws.brief?.status === "working"} onClick={startEdit}>Edit</Button>
              {aiNotesInPlan && ws.ai.configured && (
                <Button size="sm" variant="quiet" loading={busy("brief") || ws.brief?.status === "working"} loadingLabel="Writing…" onClick={() => void writeAgain()}>Write again</Button>
              )}
            </span>
          )}
        </div>
        <Card className="el-form">
          {ws.brief?.status === "failed" && <p className="el-field__error" role="alert">{ws.brief.error ?? "The brief could not be written."}</p>}
          {editing ? (
            <form className="el-form el-brief-edit" onSubmit={(e) => { e.preventDefault(); void saveBrief(); }}>
              {BRIEF_FIELDS.map(([key, label]) => key === "goal" ? (
                <TextField key={key} label={label} value={editing[key] ?? ""} maxLength={300} onChange={(e) => setEditing({ ...editing, [key]: e.target.value })} />
              ) : (
                <TextArea key={key} label={label} hint="One per line" value={editing[key] ?? ""} rows={3} onChange={(e) => setEditing({ ...editing, [key]: e.target.value })} />
              ))}
              <div className="el-page__actions">
                <Button type="submit" variant="primary" loading={busy("brief-save")} loadingLabel="Saving…">Save brief</Button>
                <Button variant="quiet" onClick={() => setEditing(null)}>Cancel</Button>
              </div>
            </form>
          ) : filled.length === 0 ? (
            <p className="el-secondary">
              {ws.brief?.status === "working" ? "The brief is being written from the project's calls and files."
                : aiNotesInPlan ? "No brief yet. Add files, keep a call, or press Write again." : "No brief yet. Press Edit to write one."}
            </p>
          ) : (
            <dl className="el-details">
              {filled.map(([key, label]) => {
                const value = sections[key];
                return <Fragment key={key}><dt>{label}</dt><dd dir="auto">{Array.isArray(value) ? value.join(" · ") : value}</dd></Fragment>;
              })}
            </dl>
          )}
        </Card>
      </section>

      <section className="el-group" aria-label="Memory">
        <h2 className="el-caption el-body-strong el-group__title">Memory</h2>
        <Card>
          {groups.length === 0 ? (
            <p className="el-group__note el-home-empty el-secondary">What this project decided, its scope, changes and open questions appear here as calls are transcribed. You can add a line yourself.</p>
          ) : (
            <List label="Memory" className="el-group__rows">
              {groups.map((g) => [
                <ListSection key={g.kind} title={g.heading} />,
                ...g.lines.map((line) => (
                  <ListRow key={line.id} compact iconStyle="plain" icon={Icons.TranscriptIcon} title={line.text}
                    meta={[line.pinned ? "Pinned" : null, line.happened_on ? rowDate(line.happened_on) : null, line.source?.type === "call" ? `From a call${line.source.at_ms != null ? ` at ${timestamp(line.source.at_ms)}` : ""}` : line.source?.type === "file" ? "From a file" : null].filter(Boolean).join(" · ") || undefined}
                    control={
                      <span className="el-row__buttons">
                        {line.source?.type === "call" && line.source.id && (
                          <Button size="sm" variant="quiet" onClick={() => onOpenRecording(line.source!.id!, line.source!.at_ms ?? 0)}>Open</Button>
                        )}
                        <Button size="sm" variant="quiet" aria-pressed={line.pinned} disabled={busy(line.id)}
                          onClick={() => void run(line.id, async () => {
                            const next = (await api.updateClientMemory(projectId, line.id, { pinned: !line.pinned })).data;
                            onChanged((cur) => ({ ...cur, memory: cur.memory.map((l) => (l.id === line.id ? next : l)) }));
                          })}>{line.pinned ? "Unpin" : "Pin"}</Button>
                        <Button size="sm" variant="quiet" icon={Icons.TrashIcon} aria-label={`Remove “${line.text}”`} disabled={busy(`remove-${line.id}`)} onClick={() => void removeLine(line)} />
                      </span>
                    } />
                )),
              ])}
            </List>
          )}
          <form className="el-form el-workspace-add" onSubmit={(e) => {
            e.preventDefault();
            if (!text.trim()) return;
            void run("add", async () => {
              const line = (await api.addClientMemory(projectId, kind, text.trim())).data;
              onChanged((cur) => ({ ...cur, memory: [...cur.memory, line] }));
              setText("");
            });
          }}>
            <div className="el-form__row">
              <Select label="Kind" value={kind} onChange={(v) => setKind(v as MemoryKind)} options={MEMORY_KINDS.map((k) => ({ value: k.value, label: k.label }))} />
              <TextArea label="Add a line" value={text} rows={2} placeholder="Payment: 40% in advance, the rest against the bill of lading." onChange={(e) => setText(e.target.value)} />
            </div>
            <span><Button type="submit" disabled={!text.trim()} loading={busy("add")} loadingLabel="Adding…">Add to memory</Button></span>
          </form>
        </Card>
      </section>

      <section className="el-group" aria-label="Instructions">
        <h2 className="el-caption el-body-strong el-group__title">Instructions</h2>
        <Card>
          {instructionFiles.length === 0 ? (
            <p className="el-group__note el-home-empty el-secondary">How this project's answers and call notes should be written. Add them under Files with Paste instructions.</p>
          ) : (
            <List label="Instructions" className="el-group__rows">
              {instructionFiles.map((f) => (
                <ListRow key={f.id} compact iconStyle="plain" icon={Icons.TranscriptIcon} title={f.name} meta={fileLine(f)}
                  control={<Button size="sm" variant="quiet" icon={Icons.TrashIcon} aria-label={`Remove ${f.name}`} disabled={busy(`remove-${f.id}`)} onClick={() => void removeInstructions(f.id, f.name)} />} />
              ))}
            </List>
          )}
        </Card>
      </section>
    </div>
  );
}
