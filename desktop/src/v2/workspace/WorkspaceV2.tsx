// A project's page in the new look (Workspaces, IA 2.2; behind ui_v2.ask), with everything today's page has
// (views/main/ClientWorkspace.tsx; workspace.test.tsx keeps the two lists equal):
// - Recordings: search (titles and what was said), sort, its folders' recordings, its folders, drag onto a project,
//   filing rules;
// - Files: Add files, files dropped on the window, Paste instructions with a name, Remove after a confirmation; then the
//   screens kept in its meetings (once a tab of their own);
// - Memory & instructions: the AI notes switch and budget, the brief (Edit, Save brief, Write again), memory (pin,
//   remove, add), instructions; then the call notes (once a tab of their own);
// - Ask: Ask with Copy answer (when the plan has AI notes);
// - Members: who can open it (UX-09): you, and whoever its share links let in, with Share and Send a copy;
// and Share, Request files and the project menu, whose dialogs stay the Projects page's. The tabs' order is UX-09's:
// Recordings · Files · Memory & instructions · Ask · Members.
// Every word is the window's language (D-261); a project's, a folder's, a file's and a person's name is its own.
import { PanelToggle } from "../shell/NavRail";
import { Fragment, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { api, errorMessage, type BriefSections, type ClientFolder, type ClientWorkspace, type LibraryItem, type MemoryKind, type MemoryLine } from "../../lib/ipc";
import { formatDuration, formatWhen, rowDate, timestamp, whenParts } from "../../lib/format";
import { usePoll } from "../../lib/usePoll";
import { ancestors } from "../../lib/projects";
import { notifyWhenAway } from "../../lib/notify";
import { confirmAction } from "../../lib/confirm";
import { noAiNotes } from "../../lib/plan";
import { BRIEF_FIELDS, budgetLine, workspaceBusy } from "../../views/main/ClientWorkspace";
import { NotesSections } from "../../views/main/CallNotes";
import { LockedChip, PlanBadge, useLocked, useLockedTag } from "../../views/main/Upgrade";
import { ProjectScreens } from "../../views/main/ScreenMoments";
import type { Notify } from "../../lib/notice";
import {
  Breadcrumbs,
  Banner, Button, Card, EmptyState, Icons, List, ListRow, ListSection, Loading, Select, SkeletonRow, Tabs, TextArea, TextField, Toast, ToastRegion, Toggle,
} from "../components";
import { Menu, type MenuItem } from "../components/Menu";
import { MembersTab } from "./MembersTab";
import { AskV2 } from "../ask/AskV2";
import { RulesV2 } from "./Rules";
import { RecordingsTab } from "./RecordingsTab";
import { FilesTab, fileLine } from "./FilesTab";
import { rowDuration } from "../library/model";
import { language, lineDir, t, tc, useLanguage } from "../../i18n";

/** When the latest of these was recorded, by the instant itself (offsets differ from one device to another). */
export function latestStart(items: readonly Pick<LibraryItem, "started_at">[]): string | undefined {
  let best: string | undefined;
  for (const { started_at: at } of items) {
    if (at && !Number.isNaN(Date.parse(at)) && (best === undefined || Date.parse(at) > Date.parse(best))) best = at;
  }
  return best;
}

type Tab = "recordings" | "files" | "memory" | "ask" | "members";

/** The kinds of memory line, their names and headings in the window's language (read when shown). */
export const MEMORY_KINDS: readonly { value: MemoryKind; label: string; heading: string }[] = [
  { value: "decision", get label() { return t("Decision"); }, get heading() { return t("Decisions"); } },
  { value: "scope", get label() { return t("Scope"); }, get heading() { return t("Scope"); } },
  // A change that was made (تبدیلی), not the verb (ur.json's "Change" is بدلیں).
  { value: "change", get label() { return t("workspace_context.memory_change"); }, get heading() { return t("Changes"); } },
  { value: "question", get label() { return t("Open question"); }, get heading() { return t("Open questions"); } },
];

/** A brief field's name in the window's language (BRIEF_FIELDS keeps classic's English). */
function briefLabel(key: keyof BriefSections): string {
  switch (key) {
    case "goal":
      return t("Goal");
    case "scope":
      return t("Scope");
    case "constraints":
      return t("Constraints");
    case "deadlines":
      return t("Deadlines");
    case "budget":
      return t("Budget");
    case "people":
      return t("People");
    case "glossary":
      return t("Glossary");
  }
}

/**
 * The header's line: "2 recordings · Last call Today", "0 recordings" (classic's clientSummary's words), in the window's
 * language: "⁨2⁩ ریکارڈنگز · آخری کال ⁨آج⁩".
 */
export function summaryLine(count: number, lastCall: string | undefined): string {
  const day = count > 0 ? whenParts(lastCall)?.day : undefined;
  return `${tc(":count recording|:count recordings", count)}${day ? ` · ${t("Last call :date", { date: day })}` : ""}`;
}

/** A call's length: "18:24"; in Urdu in words, as a row says it ("18 منٹ 24 سیکنڈ"). */
const callLength = (ms: number) => (language() === "ur" ? rowDuration(ms) : formatDuration(ms));

/** A memory line's meta: "Pinned · 14 Nov · From a call at 0:31". */
function memoryMeta(line: MemoryLine): string {
  const from = line.source?.type === "call" ? (line.source.at_ms != null ? t("From a call at :time", { time: timestamp(line.source.at_ms) }) : t("From a call"))
    : line.source?.type === "file" ? t("From a file") : null;
  return [line.pinned ? t("Pinned") : null, line.happened_on ? rowDate(line.happened_on) : null, from].filter(Boolean).join(" · ");
}

/** A line of the app's own words around a name, read in its own words' direction (dir="auto" in English, as before). */
const Said = ({ children }: { children: string }) => <span dir={lineDir(children)}>{children}</span>;

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
  /** `screens`: whether the desktop keeps its meetings' screens (classic's "Keep screen moments" switch, on Files). */
  project: { id: string; name: string; ai_notes?: boolean; screens?: boolean };
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
  /** Send a copy to a teammate (Members). */
  onSend?: () => void;
  /** Request files into it: a button in its header (UX-09); none without request links. */
  onRequest?: () => void;
  /** Open on a tab (the project menu's Instructions & memory… and Members…); each request has a new seq. */
  startTab?: { tab: "memory" | "members"; seq: number } | null;
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
  /** The breadcrumbs' first step: the Projects page. */
  onProjects?: () => void;
  onOpenProject?: (id: string) => void;
  onOpenRecording: (id: string, ms?: number) => void;
  onLibrary: () => void;
};

export function WorkspaceV2({
  project, projects = [], parent = null, folders = [], menu = [], onShare, onSend, onRequest, startTab = null, rules = false, aiNotesInPlan = true, onMoveRecordings, onProjectChanged,
  notify = () => undefined, onBack, onProjects, onOpenProject, onOpenRecording, onLibrary,
}: WorkspaceV2Props) {
  useLanguage();
  const [tab, setTab] = useState<Tab>(startTab?.tab ?? "recordings");
  useEffect(() => {
    if (startTab) setTab(startTab.tab);
  }, [startTab?.seq]); // eslint-disable-line react-hooks/exhaustive-deps
  const [items, setItems] = useState<LibraryItem[] | null>(null);
  const [itemsFailed, setItemsFailed] = useState(false);
  const [ws, setWs] = useState<ClientWorkspace | null>(null);
  const [wsFailed, setWsFailed] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const [message, setMessage] = useState<{ id: number; text: string; action?: { label: string; run: () => void }; error?: boolean } | null>(null);
  const say = useCallback((text: string, action?: { label: string; run: () => void }, error?: boolean) => setMessage({ id: Date.now(), text, action, error }), []);
  const update: WorkspaceUpdate = useCallback((change) => setWs((cur) => (cur ? change(cur) : cur)), []);
  const askLocked = useLocked("ai_notes");
  const screensLock = useLockedTag("screen_moments");
  // The brief, memory and recent call notes as one text for another AI chat, as today.
  const copyForAi = async () => {
    try {
      await navigator.clipboard.writeText(await api.clientBundle(project.id));
      say(t("Copied the brief, memory and recent call notes for AI"));
    } catch (e) {
      say(typeof e === "string" ? e : t("The project couldn't be copied. Try again."), undefined, true);
    }
  };

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
  // Work that finished while the person looked elsewhere: a native notification says what is ready (classic's).
  const previous = useRef<ClientWorkspace | null>(null);
  useEffect(() => {
    const before = previous.current;
    previous.current = ws;
    if (!before || !ws || before.client.id !== ws.client.id) return;
    if (before.brief?.status === "working" && ws.brief?.status === "ready") void notifyWhenAway(t("Brief ready"), t(":name's brief was written again.", { name: project.name }));
    for (const file of ws.files) {
      const was = before.files.find((f) => f.id === file.id);
      if (was?.status === "extracting" && file.status !== "extracting") {
        void notifyWhenAway(file.status === "ready" ? t("File read") : t("File not read"), `${project.name}: ${file.name}`);
      }
    }
  }, [ws, project.name]);

  const all = useMemo(() => (projects.length ? projects : [project as ClientFolder]), [projects, project]);
  const own = useMemo(() => (items ? items.filter((i) => !i.deleted && i.client?.id === project.id) : null), [items, project.id]);
  const ownCount = own ? own.length : null;
  // Classic's summary: how many, and when the last call was.
  const lastCall = useMemo(() => (own ? latestStart(own) : undefined), [own]);
  const summary = ownCount === null ? null : summaryLine(ownCount, lastCall);
  // AI notes while they are on for the project and in the plan; a plan without them keeps memory and the brief.
  const aiNotes = aiNotesInPlan && (ws ? ws.client.ai_notes : project.ai_notes !== false);
  // The tabs that list things say how many (classic's): its recordings, its files. UX-09's five, in its order.
  const tabs: { value: Tab; label: string; badge?: string | null; count?: number | null; countLabel?: string }[] = [
    { value: "recordings", label: t("Recordings"), count: ownCount, countLabel: ownCount === null ? undefined : tc(":count recording|:count recordings", ownCount) },
    { value: "files", label: t("Files"), count: ws ? ws.files.length : null, countLabel: ws ? tc(":count file|:count files", ws.files.length) : undefined },
    { value: "memory", label: t("Memory & instructions") },
    ...(aiNotesInPlan ? [{ value: "ask" as const, label: t("Ask") }] : []),
    { value: "members", label: t("Members") },
  ];
  const wsPart = (body: (ws: ClientWorkspace) => ReactNode) => (
    wsFailed && !ws ? (
      <EmptyState tone="error" icon={Icons.ErrorIcon} title={t("The project's workspace didn't load")} body={wsFailed}
        action={<Button onClick={() => setAttempt((n) => n + 1)}>{t("Try again")}</Button>} />
    ) : !ws ? (
      <Loading label={t("Loading the project's workspace")}><Card><div className="el-group__rows"><SkeletonRow end={false} /><SkeletonRow end={false} /></div></Card></Loading>
    ) : body(ws)
  );

  return (
    <div className="el-page el-page--narrow" data-v2="workspace">
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>{parent ? parent.name : t("Projects")}</Button>
      <header className="el-page__head el-page__head--top">
        <PanelToggle />
        <div className="el-page__titles">
          {/* Projects › the folders it sits in › this project: each step opens its page. */}
          {onProjects && <Breadcrumbs label={t("Where this project is")} crumbs={[
            { label: t("Projects"), onOpen: onProjects },
            ...ancestors(all, project.id).map((c) => ({ label: c.name, onOpen: onOpenProject ? () => onOpenProject(c.id) : undefined })),
            { label: project.name },
          ]} />}
          <h1 className="el-title-l" dir="auto">{project.name}</h1>
          {summary !== null && <p className="el-secondary" data-testid="project-summary" dir={lineDir(summary)}>{summary}</p>}
        </div>
        <div className="el-page__actions">
          {onRequest && <Button icon={Icons.RequestFilesIcon} title={t("A link anyone can use to send you voice notes and files, filed into this project")} onClick={onRequest}>{t("Request files")}</Button>}
          {onShare && <Button icon={Icons.ShareIcon} onClick={onShare}>{t("Share")}</Button>}
          <Button title={t("The brief, memory and recent call notes as one text, ready to paste into a chat")} onClick={() => void copyForAi()}>{t("Copy for AI")}</Button>
          {menu.length > 0 && <Menu label={t("Project menu")} items={menu} button={{ variant: "quiet", icon: Icons.MoreIcon }} />}
        </div>
      </header>
      <Tabs label={t("Project")} value={tabs.some((item) => item.value === tab) ? tab : "recordings"} onChange={setTab} tabs={tabs}>
        {tab === "recordings" && (
          <RecordingsTab project={project} projects={all} items={items} itemsFailed={itemsFailed} folders={folders} onRetry={() => setAttempt((n) => n + 1)}
            onOpenRecording={onOpenRecording} onOpenProject={onOpenProject} onMoveRecordings={onMoveRecordings} onLibrary={onLibrary}
            rules={rules ? <RulesV2 projectId={project.id} projectName={project.name} say={say} /> : undefined} />
        )}
        {tab === "files" && (
          <>
            {wsPart((w) => <FilesTab projectId={project.id} files={w.files} onChanged={update} say={say} />)}
            {/* The screens kept in its meetings (once their own tab); a plan without screen moments names the plan that has them. */}
            <section className="el-group el-workspace-part" aria-labelledby="el-project-screens">
              <h2 className="el-caption el-body-strong el-group__title" id="el-project-screens">
                {t("Screens")}{screensLock?.tag && <>{" "}<span className="plan-badge static mark" title={t(":feature is in :plan", { feature: t("Screens"), plan: screensLock.tag })}>{screensLock.tag}</span></>}
              </h2>
              <ScreensTab project={project as ClientFolder} notify={notify} onOpenRecording={onOpenRecording} onProjectChanged={onProjectChanged} />
            </section>
          </>
        )}
        {tab === "ask" && (askLocked ? (
          <LockedChip feature="ai_notes" label={t("Ask: answers about this project, from its recordings and files")} />
        ) : (
          <AskV2 embedded projects={[project]} initialProjectId={project.id} onOpenAt={(id, ms) => onOpenRecording(id, ms)}
            onOpenProject={() => setTab("files")} onLibrary={onLibrary} />
        ))}
        {tab === "memory" && wsPart((w) => (
          <>
            <Memory projectId={project.id} projectName={project.name} ws={w} onChanged={update} onOpenRecording={onOpenRecording} say={say}
              aiNotesInPlan={aiNotesInPlan && !askLocked} onProjectChanged={onProjectChanged} />
            {/* The notes written after its calls (once their own tab), while AI notes are on. */}
            {aiNotes && (
              <section className="el-group el-workspace-part" aria-labelledby="el-project-notes">
                <h2 className="el-caption el-body-strong el-group__title" id="el-project-notes">{t("Call notes")}</h2>
                <CallNotesTab ws={w} projectName={project.name} onOpenRecording={onOpenRecording} />
              </section>
            )}
          </>
        ))}
        {tab === "members" && <MembersTab projectId={project.id} projectName={project.name} within={ancestors(all, project.id)} onShare={onShare} onSend={onSend} />}
      </Tabs>
      <ToastRegion>
        {message && <Toast key={message.id} message={message.text} actionLabel={message.action?.label} onAction={message.action?.run} error={message.error} onClose={() => setMessage(null)} />}
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
  useLanguage();
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
    return <EmptyState tone="error" icon={Icons.ErrorIcon} title={t("The screens didn't load")} body={error} action={<Button onClick={() => setAttempt((n) => n + 1)}>{t("Try again")}</Button>} />;
  }
  if (page === undefined) return <Loading label={t("Loading the screens")}><SkeletonRow end={false} /></Loading>;
  if (!page) return <EmptyState icon={Icons.ScreenIcon} title={t("No screens here")} body={t("Screens kept during this project's meetings appear here when the server keeps them.")} />;
  return <ProjectScreens client={project} first={page} notify={notify} onOpenRecording={onOpenRecording} onClientChanged={onProjectChanged ?? (() => undefined)} />;
}

/** The call notes written after the project's calls, newest first, as today's AI notes tab. */
function CallNotesTab({ ws, projectName, onOpenRecording }: { ws: ClientWorkspace; projectName: string; onOpenRecording: (id: string, ms?: number) => void }) {
  useLanguage();
  if (ws.notes.length === 0) {
    return <EmptyState icon={Icons.TranscriptIcon} title={t("No call notes yet")} body={<Said>{t("Notes appear here after a call filed under :name is kept and transcribed.", { name: projectName })}</Said>} />;
  }
  return (
    <div className="el-workspace-tab" aria-label={t("Call notes")}>
      {ws.notes.map((note) => (
        <Card key={note.id} className="el-form" label={note.session?.title ?? t("Call")}>
          <div className="el-group__head">
            <span className="el-body-strong" dir="auto">{note.session?.title ?? t("Call")}</span>
            <span className="el-caption">{formatWhen(note.session?.started_at ?? note.created_at ?? undefined)}{note.session?.duration_ms ? ` · ${callLength(note.session.duration_ms)}` : ""}</span>
            <Button size="sm" variant="quiet" aria-label={note.session?.title ? t("Open :name", { name: note.session.title }) : t("Open the call")} onClick={() => onOpenRecording(note.session_id)}>{t("Open")}</Button>
          </div>
          {note.status === "pending" && <p className="el-caption" role="status">{t("Writing the notes…")}</p>}
          {(note.status === "failed" || note.status === "skipped") && <p className="el-caption">{note.error ?? t("No notes were written for this call.")}</p>}
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
  useLanguage();
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
      const typed = editing?.[key] ?? "";
      if (key === "goal") next.goal = typed.trim() || null;
      else (next as Record<string, string[]>)[key] = typed.split("\n").map((l) => l.trim()).filter(Boolean);
    }
    const brief = (await api.saveClientBrief(projectId, next)).data;
    onChanged((cur) => ({ ...cur, brief }));
    setEditing(null);
    say(t("Brief saved"));
  });
  const writeAgain = async () => {
    if (ws.brief?.edited && !(await confirmAction({ title: t("Write the brief again?"), body: t("It's written from the project's files and call notes. Your edits are replaced."), confirm: t("Write again"), tone: "danger" }))) return;
    await run("brief", async () => {
      const brief = (await api.refreshClientBrief(projectId)).data;
      onChanged((cur) => ({ ...cur, brief }));
    });
  };
  const removeLine = async (line: MemoryLine) => {
    if (!(await confirmAction({ title: t("Remove this line from memory?"), body: `“${line.text}”`, confirm: t("Remove"), tone: "danger" }))) return;
    await run(`remove-${line.id}`, async () => {
      await api.deleteClientMemory(projectId, line.id);
      onChanged((cur) => ({ ...cur, memory: cur.memory.filter((l) => l.id !== line.id) }));
    });
  };
  const removeInstructions = async (id: string, name: string) => {
    if (!(await confirmAction({ title: t("Remove :name from this project?", { name }), confirm: t("Remove"), tone: "danger" }))) return;
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
        <section className="el-group" aria-label={t("AI")}>
          <Card className="el-form">
            <Toggle label={t("AI notes")} hint={t("EchoLedger writes call notes after each kept recording of :name.", { name: projectName })} checked={ws.client.ai_notes}
              disabled={busy("ai-notes")} onChange={(on) => void toggleNotes(on)} />
            <p className="el-caption" data-testid="ai-budget">
              {ws.ai.configured ? budgetLine(ws.ai) : t("AI isn't set up on the server yet: notes, the brief and Ask wait for it. Ask the person who runs the console.")}
            </p>
          </Card>
        </section>
      ) : (
        <section className="el-group" aria-label={t("AI")}>
          <Card className="el-form el-ai-off" ><span data-testid="ai-not-in-plan">{noAiNotes()}</span><PlanBadge feature="ai_notes" /></Card>
        </section>
      )}

      <section className="el-group" aria-label={t("Brief")}>
        <div className="el-group__head">
          <h2 className="el-caption el-body-strong el-group__title">{ws.brief?.edited ? t("Brief · edited by you") : t("Brief")}</h2>
          {!editing && (
            <span className="el-row__buttons">
              <Button size="sm" variant="quiet" disabled={busy("brief") || ws.brief?.status === "working"} onClick={startEdit}>{t("Edit")}</Button>
              {aiNotesInPlan && ws.ai.configured && (
                <Button size="sm" variant="quiet" loading={busy("brief") || ws.brief?.status === "working"} loadingLabel={t("Writing…")} onClick={() => void writeAgain()}>{t("Write again")}</Button>
              )}
            </span>
          )}
        </div>
        <Card className="el-form">
          {ws.brief?.status === "failed" && <p className="el-field__error" role="alert">{ws.brief.error ?? t("The brief could not be written.")}</p>}
          {editing ? (
            <form className="el-form el-brief-edit" onSubmit={(e) => { e.preventDefault(); void saveBrief(); }}>
              {BRIEF_FIELDS.map(([key]) => key === "goal" ? (
                <TextField key={key} label={briefLabel(key)} value={editing[key] ?? ""} maxLength={300} onChange={(e) => setEditing({ ...editing, [key]: e.target.value })} />
              ) : (
                <TextArea key={key} label={briefLabel(key)} hint={t("One per line")} value={editing[key] ?? ""} rows={3} onChange={(e) => setEditing({ ...editing, [key]: e.target.value })} />
              ))}
              <div className="el-page__actions">
                <Button type="submit" variant="primary" loading={busy("brief-save")} loadingLabel={t("Saving…")}>{t("Save brief")}</Button>
                <Button variant="quiet" onClick={() => setEditing(null)}>{t("Cancel")}</Button>
              </div>
            </form>
          ) : filled.length === 0 ? (
            <p className="el-secondary">
              {ws.brief?.status === "working" ? t("The brief is being written from the project's calls and files.")
                : aiNotesInPlan ? t("No brief yet. Add files, keep a call, or press Write again.") : t("No brief yet. Press Edit to write one.")}
            </p>
          ) : (
            <dl className="el-details">
              {filled.map(([key]) => {
                const value = sections[key];
                return <Fragment key={key}><dt>{briefLabel(key)}</dt><dd dir="auto">{Array.isArray(value) ? value.join(" · ") : value}</dd></Fragment>;
              })}
            </dl>
          )}
        </Card>
      </section>

      <section className="el-group" aria-label={t("Memory")}>
        <h2 className="el-caption el-body-strong el-group__title">{t("Memory")}</h2>
        <Card>
          {groups.length === 0 ? (
            <p className="el-group__note el-home-empty el-secondary">{t("What this project decided, its scope, changes and open questions appear here as calls are transcribed. You can add a line yourself.")}</p>
          ) : (
            <List label={t("Memory")} className="el-group__rows">
              {groups.map((g) => [
                <ListSection key={g.kind} title={g.heading} />,
                ...g.lines.map((line) => (
                  <ListRow key={line.id} compact iconStyle="plain" icon={Icons.TranscriptIcon} title={line.text}
                    meta={memoryMeta(line) || undefined}
                    control={
                      <span className="el-row__buttons">
                        {line.source?.type === "call" && line.source.id && (
                          <Button size="sm" variant="quiet" aria-label={line.source.at_ms != null ? t("Open the call at :time", { time: timestamp(line.source.at_ms) }) : t("Open the call")}
                            onClick={() => onOpenRecording(line.source!.id!, line.source!.at_ms ?? 0)}>{t("Open")}</Button>
                        )}
                        <Button size="sm" variant="quiet" aria-label={line.pinned ? t("Unpin “:text”", { text: line.text }) : t("Pin “:text”", { text: line.text })} disabled={busy(line.id)}
                          onClick={() => void run(line.id, async () => {
                            const next = (await api.updateClientMemory(projectId, line.id, { pinned: !line.pinned })).data;
                            onChanged((cur) => ({ ...cur, memory: cur.memory.map((l) => (l.id === line.id ? next : l)) }));
                          })}>{line.pinned ? t("Unpin") : t("Pin")}</Button>
                        <Button size="sm" variant="quiet" icon={Icons.TrashIcon} aria-label={t("Remove “:text”", { text: line.text })} disabled={busy(`remove-${line.id}`)} onClick={() => void removeLine(line)} />
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
              <Select label={t("Kind")} value={kind} onChange={(v) => setKind(v as MemoryKind)} options={MEMORY_KINDS.map((k) => ({ value: k.value, label: k.label }))} />
              <TextArea label={t("Add a line")} value={text} rows={2} placeholder={t("Payment: 40% in advance, the rest against the bill of lading.")} onChange={(e) => setText(e.target.value)} />
            </div>
            <span><Button type="submit" disabled={!text.trim()} loading={busy("add")} loadingLabel={t("Adding…")}>{t("Add to memory")}</Button></span>
          </form>
        </Card>
      </section>

      <section className="el-group" aria-label={t("Instructions")}>
        <h2 className="el-caption el-body-strong el-group__title">{t("Instructions")}</h2>
        <Card>
          {instructionFiles.length === 0 ? (
            <p className="el-group__note el-home-empty el-secondary">{t("How this project's answers and call notes should be written. Add them under Files with Paste instructions.")}</p>
          ) : (
            <List label={t("Instructions")} className="el-group__rows">
              {instructionFiles.map((f) => (
                <ListRow key={f.id} compact iconStyle="plain" icon={Icons.TranscriptIcon} title={f.name} meta={fileLine(f)}
                  control={<Button size="sm" variant="quiet" icon={Icons.TrashIcon} aria-label={t("Remove :name", { name: f.name })} disabled={busy(`remove-${f.id}`)} onClick={() => void removeInstructions(f.id, f.name)} />} />
              ))}
            </List>
          )}
        </Card>
      </section>
    </div>
  );
}
