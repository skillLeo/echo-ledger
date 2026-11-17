// A project's page in the new look (Workspaces, IA 2.2; behind ui_v2.ask): Recordings · Ask · Memory & instructions,
// on the project APIs today's page uses (library_list for its recordings, client_workspace for the brief, memory and
// files, client_ask, client_memory_*, client_instructions_add, client_file_delete, client_brief_refresh).
import { Fragment, useCallback, useEffect, useMemo, useState } from "react";
import { api, errorMessage, type ClientFile, type ClientWorkspace, type LibraryItem, type MemoryKind, type MemoryLine } from "../../lib/ipc";
import { projectRecordings } from "../../lib/projects";
import { timestamp } from "../../lib/format";
import {
  Badge, Banner, Button, Card, Dialog, EmptyState, Icons, List, ListRow, ListSection, Loading, Select, SkeletonRow, Tabs, TextArea, Toast, ToastRegion,
} from "../components";
import { dayGroups, rowModel } from "../library/model";
import { AskV2 } from "../ask/AskV2";

type Tab = "recordings" | "ask" | "memory";

export const MEMORY_KINDS: readonly { value: MemoryKind; label: string; heading: string }[] = [
  { value: "decision", label: "Decision", heading: "Decisions" },
  { value: "scope", label: "Scope", heading: "Scope" },
  { value: "change", label: "Change", heading: "Changes" },
  { value: "question", label: "Open question", heading: "Open questions" },
];

const BRIEF_PARTS: readonly { key: "scope" | "constraints" | "deadlines" | "budget" | "people" | "glossary"; label: string }[] = [
  { key: "scope", label: "Scope" }, { key: "deadlines", label: "Deadlines" }, { key: "budget", label: "Budget" },
  { key: "constraints", label: "Constraints" }, { key: "people", label: "People" }, { key: "glossary", label: "Words" },
];

/** Memory lines under their kind's heading, pinned first, in the order MEMORY_KINDS gives. */
export function memoryGroups(lines: readonly MemoryLine[]): { kind: MemoryKind; heading: string; lines: MemoryLine[] }[] {
  return MEMORY_KINDS.map((k) => ({
    kind: k.value,
    heading: k.heading,
    lines: lines.filter((l) => l.kind === k.value).sort((a, b) => Number(b.pinned) - Number(a.pinned)),
  })).filter((g) => g.lines.length > 0);
}

export type WorkspaceV2Props = {
  project: { id: string; name: string };
  onBack: () => void;
  onOpenRecording: (id: string, ms?: number) => void;
  onLibrary: () => void;
};

export function WorkspaceV2({ project, onBack, onOpenRecording, onLibrary }: WorkspaceV2Props) {
  const [tab, setTab] = useState<Tab>("recordings");
  const [items, setItems] = useState<LibraryItem[] | null>(null);
  const [itemsFailed, setItemsFailed] = useState(false);
  const [ws, setWs] = useState<ClientWorkspace | null>(null);
  const [wsFailed, setWsFailed] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const [message, setMessage] = useState<{ id: number; text: string } | null>(null);
  const say = (text: string) => setMessage({ id: Date.now(), text });

  useEffect(() => {
    setItemsFailed(false);
    api.library().then((l) => setItems(Array.isArray(l) ? l : []), () => setItemsFailed(true));
  }, [attempt]);
  const loadWorkspace = useCallback(() => {
    setWsFailed(null);
    api.clientWorkspace(project.id).then((r) => setWs(r.data), (e) => setWsFailed(errorMessage(e)));
  }, [project.id]);
  useEffect(() => loadWorkspace(), [loadWorkspace, attempt]);

  const own = useMemo(() => (items ? projectRecordings(items, [{ id: project.id, name: project.name }], project.id, false) : null), [items, project]);

  return (
    <div className="el-page el-page--narrow" data-v2="workspace">
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>Projects</Button>
      <header className="el-page__head">
        <div className="el-page__titles">
          <h1 className="el-title-l" dir="auto">{project.name}</h1>
          {own && <p className="el-secondary">{own.length === 1 ? "1 recording" : `${own.length} recordings`}</p>}
        </div>
      </header>
      <Tabs label="Project" value={tab} onChange={setTab}
        tabs={[{ value: "recordings", label: "Recordings" }, { value: "ask", label: "Ask" }, { value: "memory", label: "Memory & instructions" }]}>
        {tab === "recordings" && (
          itemsFailed && !items ? (
            <EmptyState tone="error" icon={Icons.ErrorIcon} title="The recordings didn't load" body="EchoLedger couldn't read the library. Recordings on this computer are safe."
              action={<Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
          ) : !own ? (
            <Loading label="Loading recordings">{Array.from({ length: 4 }, (_, i) => <SkeletonRow key={i} />)}</Loading>
          ) : own.length === 0 ? (
            <EmptyState icon={Icons.FolderIcon} title="No recordings in this project yet"
              body="Move recordings here from Library, or let a filing rule put calls with this project here." action={<Button onClick={onLibrary}>Open Library</Button>} />
          ) : (
            <List label="Recordings">
              {dayGroups(own).map((g) => [
                <ListSection key={g.label} title={g.label} />,
                ...g.items.map((item) => {
                  const r = rowModel(item);
                  return (
                    <ListRow key={item.id} icon={(p) => <Icons.SourceAppIcon app={r.app} {...p} />} title={r.title} meta={r.meta}
                      badge={r.badge ? <Badge tone={r.badge.tone}>{r.badge.text}</Badge> : undefined}
                      endTop={r.when} endBottom={r.duration ? <span className="el-num">{r.duration}</span> : undefined} onClick={() => onOpenRecording(item.id)} />
                  );
                }),
              ])}
            </List>
          )
        )}
        {tab === "ask" && (
          <AskV2 embedded projects={[project]} initialProjectId={project.id} onOpenAt={(id, ms) => onOpenRecording(id, ms)}
            onOpenProject={() => setTab("memory")} onLibrary={onLibrary} />
        )}
        {tab === "memory" && (
          wsFailed && !ws ? (
            <EmptyState tone="error" icon={Icons.ErrorIcon} title="The project's memory didn't load" body={wsFailed}
              action={<Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
          ) : !ws ? (
            <Loading label="Loading the project's memory"><Card><div className="el-group__rows"><SkeletonRow end={false} /><SkeletonRow end={false} /></div></Card></Loading>
          ) : (
            <Memory projectId={project.id} ws={ws} onChanged={setWs} onOpenRecording={onOpenRecording} say={say} />
          )
        )}
      </Tabs>
      <ToastRegion>
        {message && <Toast key={message.id} message={message.text} onClose={() => setMessage(null)} />}
      </ToastRegion>
    </div>
  );
}

function Memory({ projectId, ws, onChanged, onOpenRecording, say }: {
  projectId: string;
  ws: ClientWorkspace;
  onChanged: (ws: ClientWorkspace) => void;
  onOpenRecording: (id: string, ms?: number) => void;
  say: (text: string) => void;
}) {
  const [kind, setKind] = useState<MemoryKind>("decision");
  const [text, setText] = useState("");
  const [instructions, setInstructions] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [removing, setRemoving] = useState<{ kind: "memory"; line: MemoryLine } | { kind: "file"; file: ClientFile } | null>(null);
  const run = async (key: string, fn: () => Promise<void>) => {
    setBusy(key);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(null);
    }
  };
  const brief = ws.brief?.sections ?? null;
  const instructionFiles = ws.files.filter((f) => f.instructions);
  const groups = memoryGroups(ws.memory);

  return (
    <div className="el-workspace-memory">
      {error && <Banner tone="danger" icon={Icons.ErrorIcon} onClose={() => setError(null)}>{error}</Banner>}

      <section className="el-group" aria-label="Brief">
        <div className="el-group__head">
          <h2 className="el-caption el-body-strong el-group__title">Brief</h2>
          {ws.ai.configured && !ws.brief?.edited && (
            <Button size="sm" variant="quiet" loading={busy === "brief" || ws.brief?.status === "working"} loadingLabel="Writing…"
              onClick={() => void run("brief", async () => onChanged({ ...ws, brief: (await api.refreshClientBrief(projectId)).data }))}>Write again</Button>
          )}
        </div>
        <Card className="el-form">
          {!brief || (!brief.goal && BRIEF_PARTS.every((p) => !(brief[p.key] ?? []).length)) ? (
            <p className="el-secondary">{ws.brief?.status === "working" ? "The brief is being written from the project's calls and files." : "No brief yet. It is written from the project's calls and files once there are some."}</p>
          ) : (
            <dl className="el-details">
              {brief.goal && <><dt>Goal</dt><dd dir="auto">{brief.goal}</dd></>}
              {BRIEF_PARTS.filter((p) => (brief[p.key] ?? []).length > 0).map((p) => (
                <Fragment key={p.key}><dt>{p.label}</dt><dd dir="auto">{(brief[p.key] ?? []).join(" · ")}</dd></Fragment>
              ))}
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
                  <ListRow key={line.id} compact iconStyle="plain" icon={line.pinned ? Icons.CheckIcon : Icons.TranscriptIcon}
                    title={line.text}
                    meta={[line.pinned ? "Pinned" : null, line.happened_on, line.source?.type === "call" ? `From a call${line.source.at_ms != null ? ` at ${timestamp(line.source.at_ms)}` : ""}` : line.source?.type === "file" ? "From a file" : null].filter(Boolean).join(" · ") || undefined}
                    control={
                      <span className="el-row__buttons">
                        {line.source?.type === "call" && line.source.id && (
                          <Button size="sm" variant="quiet" onClick={() => onOpenRecording(line.source!.id!, line.source!.at_ms ?? 0)}>Open</Button>
                        )}
                        <Button size="sm" variant="quiet" aria-pressed={line.pinned} disabled={busy === line.id}
                          onClick={() => void run(line.id, async () => {
                            const next = (await api.updateClientMemory(projectId, line.id, { pinned: !line.pinned })).data;
                            onChanged({ ...ws, memory: ws.memory.map((l) => (l.id === line.id ? next : l)) });
                          })}>{line.pinned ? "Unpin" : "Pin"}</Button>
                        <Button size="sm" variant="quiet" icon={Icons.TrashIcon} aria-label={`Delete "${line.text}"`} onClick={() => setRemoving({ kind: "memory", line })} />
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
              onChanged({ ...ws, memory: [...ws.memory, line] });
              setText("");
            });
          }}>
            <div className="el-form__row">
              <Select label="Kind" value={kind} onChange={(v) => setKind(v as MemoryKind)} options={MEMORY_KINDS.map((k) => ({ value: k.value, label: k.label }))} />
              <TextArea label="Add a line" value={text} rows={2} placeholder="Payment: 40% in advance, the rest against the bill of lading." onChange={(e) => setText(e.target.value)} />
            </div>
            <span><Button type="submit" disabled={!text.trim()} loading={busy === "add"} loadingLabel="Adding…">Add to memory</Button></span>
          </form>
        </Card>
      </section>

      <section className="el-group" aria-label="Instructions">
        <h2 className="el-caption el-body-strong el-group__title">Instructions</h2>
        <Card>
          {instructionFiles.length > 0 && (
            <List label="Instructions" className="el-group__rows">
              {instructionFiles.map((f) => (
                <ListRow key={f.id} compact iconStyle="plain" icon={Icons.TranscriptIcon} title={f.name}
                  meta={f.status === "extracting" ? "Reading…" : f.status === "failed" ? (f.error ?? "Couldn't be read") : undefined}
                  control={<Button size="sm" variant="quiet" icon={Icons.TrashIcon} aria-label={`Delete "${f.name}"`} onClick={() => setRemoving({ kind: "file", file: f })} />} />
              ))}
            </List>
          )}
          <form className="el-form" onSubmit={(e) => {
            e.preventDefault();
            if (!instructions.trim()) return;
            void run("instructions", async () => {
              const file = (await api.addClientInstructions(projectId, instructions.trim())).data;
              onChanged({ ...ws, files: [...ws.files, file] });
              setInstructions("");
              say("Instructions saved");
            });
          }}>
            <TextArea label="Instructions for answers and notes" value={instructions} rows={3}
              hint="How this project's answers and call notes should be written: names to use, what to leave out."
              placeholder="Call the client Qureshi Sahib. Amounts in rupees." onChange={(e) => setInstructions(e.target.value)} />
            <span><Button type="submit" disabled={!instructions.trim()} loading={busy === "instructions"} loadingLabel="Saving…">Save instructions</Button></span>
          </form>
        </Card>
      </section>

      <Dialog open={!!removing} alert title={removing?.kind === "file" ? "Delete these instructions?" : "Delete this line from memory?"}
        description={removing?.kind === "file" ? `"${removing.file.name}" is removed from the project.` : removing ? `"${removing.line.text}"` : undefined}
        onClose={() => setRemoving(null)}
        actions={<>
          <Button onClick={() => setRemoving(null)}>Cancel</Button>
          <Button variant="danger" onClick={() => {
            const r = removing;
            setRemoving(null);
            if (!r) return;
            void run("remove", async () => {
              if (r.kind === "memory") {
                await api.deleteClientMemory(projectId, r.line.id);
                onChanged({ ...ws, memory: ws.memory.filter((l) => l.id !== r.line.id) });
              } else {
                await api.deleteClientFile(projectId, r.file.id);
                onChanged({ ...ws, files: ws.files.filter((f) => f.id !== r.file.id) });
              }
            });
          }}>Delete</Button>
        </>} />
    </div>
  );
}
