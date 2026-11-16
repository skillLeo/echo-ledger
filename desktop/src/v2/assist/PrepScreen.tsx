// Live Assist prep (v1.9 L4): who the meeting is with and what kind (templates per type), a linked project and its
// documents, the brief (goals, done, not done, to discuss, instructions), mode, hint level and language help, the
// consent line with Copy, then Start now (recording and assist at once) or Schedule.
import { useEffect, useState } from "react";
import { api, errorMessage, type ClientFile } from "../../lib/ipc";
import { Badge, Button, Card, Checkbox, EmptyState, Icons, Segmented, Select, TextArea, TextField } from "../components";
import { assist, type AssistMode, type HintLevel, type LanguageHelp, type MeetingType, type Prep, type PrepDraft } from "./adapter";
import { CONSENT_LINE, INTERVIEW_NOTE } from "./AssistCard";

export const MEETING_TYPES: readonly { value: MeetingType; label: string }[] = [
  { value: "interview", label: "Interview" },
  { value: "existing_client", label: "Existing client" },
  { value: "new_client", label: "New client" },
  { value: "boss", label: "Boss" },
  { value: "general", label: "Other meeting" },
];

/** A starting brief per meeting type (the person edits it); interviews start with outline hints. */
export const TEMPLATES: Record<MeetingType, Pick<PrepDraft, "goals" | "to_discuss" | "hint_level">> = {
  interview: { goals: "Show the experience the role asks for, with one example each.", to_discuss: "Salary range, notice period, start date.", hint_level: "outline" },
  existing_client: { goals: "Agree the next order and its payment terms.", to_discuss: "Open invoices, delivery dates.", hint_level: "full" },
  new_client: { goals: "Understand what they need and agree a first step.", to_discuss: "Budget, timeline, who decides.", hint_level: "full" },
  boss: { goals: "Agree priorities for the next two weeks.", to_discuss: "Blockers and what I need from them.", hint_level: "full" },
  general: { goals: "", to_discuss: "", hint_level: "full" },
};

export function newDraft(type: MeetingType = "interview"): PrepDraft {
  return {
    meeting_type: type, title: "", counterpart: "", company: "", project_id: null, goals: TEMPLATES[type].goals, done: "", not_done: "",
    to_discuss: TEMPLATES[type].to_discuss, instructions: "", hint_level: TEMPLATES[type].hint_level, mode: "balanced", language_help: "off", scheduled_at: null,
  };
}

/** Changing the type brings that type's template in, but never over what the person wrote. */
export function withType(draft: PrepDraft, type: MeetingType): PrepDraft {
  const from = TEMPLATES[draft.meeting_type];
  const to = TEMPLATES[type];
  return {
    ...draft, meeting_type: type,
    goals: !draft.goals || draft.goals === from.goals ? to.goals : draft.goals,
    to_discuss: !draft.to_discuss || draft.to_discuss === from.to_discuss ? to.to_discuss : draft.to_discuss,
    hint_level: draft.hint_level === from.hint_level ? to.hint_level : draft.hint_level,
  };
}

const MODES: readonly { value: AssistMode; label: string }[] = [
  { value: "fast", label: "Fast" }, { value: "balanced", label: "Balanced" }, { value: "deep", label: "Deep (Ultra)" }, { value: "max", label: "Max (Ultra)" },
];
const HINTS: { value: HintLevel; label: string }[] = [{ value: "keywords", label: "Keywords" }, { value: "outline", label: "Outline" }, { value: "full", label: "Full answer" }];
const LANGUAGE_HELP: readonly { value: LanguageHelp; label: string }[] = [
  { value: "off", label: "Off" }, { value: "urdu", label: "Urdu" }, { value: "roman_urdu", label: "Roman Urdu" }, { value: "arabic", label: "Arabic" },
];
const MAX = 4000;

export function PrepScreen({ prep = null, projects, onBack, onStarted }: {
  prep?: Prep | null;
  projects: { id: string; name: string }[];
  onBack: () => void;
  onStarted: () => void;
}) {
  const [draft, setDraft] = useState<PrepDraft>(() => (prep ? { ...prep } : newDraft()));
  const [saved, setSaved] = useState<Prep | null>(prep);
  const [files, setFiles] = useState<ClientFile[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [scheduleAt, setScheduleAt] = useState(prep?.scheduled_at?.slice(0, 16) ?? "");
  const set = <K extends keyof PrepDraft>(key: K, value: PrepDraft[K]) => setDraft((d) => ({ ...d, [key]: value }));

  useEffect(() => {
    if (!draft.project_id) return setFiles(null);
    let live = true;
    api.clientWorkspace(draft.project_id).then((w) => live && setFiles(w.data.files.filter((f) => f.status === "ready")), () => live && setFiles([]));
    return () => {
      live = false;
    };
  }, [draft.project_id]);

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
  const save = async (): Promise<Prep> => {
    const next = await assist.savePrep({ ...draft, id: saved?.id });
    setSaved(next);
    return next;
  };
  const attached = new Set((saved?.documents ?? []).map((d) => d.file_id));
  const toggleFile = (file: ClientFile, on: boolean) => run(`file-${file.id}`, async () => {
    const p = saved ?? (await save());
    setSaved(on ? await assist.addDocuments(p.id, [file.id]) : await assist.removeDocument(p.id, file.id));
  });
  const tooLong = (["goals", "done", "not_done", "to_discuss", "instructions"] as const).find((k) => draft[k].length > MAX);

  return (
    <div className="el-page el-page--narrow" data-v2="assist-prep">
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>Live</Button>
      <header className="el-page__head"><h1 className="el-title-l">{prep ? "Edit prep" : "New Live Assist session"}</h1></header>

      <section className="el-group" aria-label="The meeting">
        <h2 className="el-caption el-body-strong el-group__title">The meeting</h2>
        <Card className="el-form">
          <Select label="Meeting type" options={MEETING_TYPES.map((t) => ({ value: t.value, label: t.label }))} value={draft.meeting_type}
            onChange={(v) => setDraft((d) => withType(d, v as MeetingType))} />
          <TextField label="Title" value={draft.title} placeholder="Interview at Mehran Foods" onChange={(e) => set("title", e.target.value)} />
          <div className="el-form__row">
            <TextField label="With" value={draft.counterpart} placeholder="Ayesha Siddiqui" onChange={(e) => set("counterpart", e.target.value)} />
            <TextField label="Company" value={draft.company} placeholder="Mehran Foods" onChange={(e) => set("company", e.target.value)} />
          </div>
          <Select label="Project" value={draft.project_id ?? ""} onChange={(v) => set("project_id", v || null)}
            options={[{ value: "", label: "No project" }, ...projects.map((p) => ({ value: p.id, label: p.name }))]} />
        </Card>
      </section>

      <section className="el-group" aria-label="Documents">
        <h2 className="el-caption el-body-strong el-group__title">Documents</h2>
        <Card className="el-form">
          {!draft.project_id ? (
            <p className="el-secondary">Link a project to use its files: a job description, a proposal, last month's invoices.</p>
          ) : files === null ? (
            <p className="el-secondary">Reading the project's files</p>
          ) : files.length === 0 ? (
            <EmptyState icon={Icons.TranscriptIcon} title="No files in this project" body="Add files to the project, then attach them here." />
          ) : (
            <fieldset className="el-radio-group">
              <legend>Use in this session</legend>
              {files.map((f) => (
                <Checkbox key={f.id} label={f.name} checked={attached.has(f.id)} disabled={busy === `file-${f.id}`}
                  hint={f.pages ? `${f.pages} pages` : undefined} onChange={(e) => void toggleFile(f, e.target.checked)} />
              ))}
            </fieldset>
          )}
        </Card>
      </section>

      <section className="el-group" aria-label="Brief">
        <h2 className="el-caption el-body-strong el-group__title">Brief</h2>
        <Card className="el-form">
          <TextArea label="Goals" value={draft.goals} maxLength={MAX} onChange={(e) => set("goals", e.target.value)} />
          <div className="el-form__row">
            <TextArea label="Done so far" value={draft.done} maxLength={MAX} onChange={(e) => set("done", e.target.value)} />
            <TextArea label="Not done yet" value={draft.not_done} maxLength={MAX} onChange={(e) => set("not_done", e.target.value)} />
          </div>
          <TextArea label="To discuss" value={draft.to_discuss} maxLength={MAX} onChange={(e) => set("to_discuss", e.target.value)} />
          <TextArea label="Instructions for the assistant" value={draft.instructions} maxLength={MAX}
            hint="It never invents experience, numbers or promises that aren't in this brief." onChange={(e) => set("instructions", e.target.value)} />
        </Card>
      </section>

      <section className="el-group" aria-label="How it helps">
        <h2 className="el-caption el-body-strong el-group__title">How it helps</h2>
        <Card className="el-form">
          <div className="el-form__row">
            <Select label="Mode" options={MODES.map((m) => ({ value: m.value, label: m.label }))} value={draft.mode} onChange={(v) => set("mode", v as AssistMode)} />
            <Select label="Language help" options={LANGUAGE_HELP.map((l) => ({ value: l.value, label: l.label }))} value={draft.language_help} onChange={(v) => set("language_help", v as LanguageHelp)} />
          </div>
          <div className="el-field">
            <span className="el-field__label">Hints</span>
            <Segmented label="Hints" options={HINTS} value={draft.hint_level} onChange={(v) => set("hint_level", v)} />
          </div>
          {draft.meeting_type === "interview" ? (
            <p className="el-secondary" role="note">{INTERVIEW_NOTE}</p>
          ) : (
            <div className="el-consent" role="note">
              <span className="el-caption el-body-strong">Say this at the start</span>
              <p>{CONSENT_LINE}</p>
              <Button size="sm" variant="quiet" onClick={() => void run("copy", () => navigator.clipboard.writeText(CONSENT_LINE))}>Copy</Button>
            </div>
          )}
        </Card>
      </section>

      {error && <p className="el-field__error" role="alert">{error}</p>}
      {tooLong && <p className="el-field__error" role="alert">Each part of the brief can have up to 4,000 characters.</p>}
      <div className="el-prep__actions">
        <Button variant="primary" icon={Icons.RecordIcon} loading={busy === "start"} loadingLabel="Starting…" disabled={!!tooLong}
          onClick={() => void run("start", async () => {
            const p = await save();
            await assist.start(p.id, draft.mode);
            onStarted();
          })}>Start now</Button>
        <TextField label="Schedule for" type="datetime-local" value={scheduleAt} onChange={(e) => setScheduleAt(e.target.value)} className="el-prep__when" />
        <Button disabled={!scheduleAt || !!tooLong} loading={busy === "schedule"} loadingLabel="Scheduling…"
          onClick={() => void run("schedule", async () => {
            const p = await save();
            setSaved(await assist.schedule(p.id, new Date(scheduleAt).toISOString()));
          })}>Schedule</Button>
        {saved?.status === "scheduled" && <Badge tone="accent">Scheduled</Badge>}
        <Button variant="quiet" loading={busy === "save"} loadingLabel="Saving…" disabled={!!tooLong} onClick={() => void run("save", async () => void (await save()))}>Save draft</Button>
      </div>
      <p className="el-caption">Start now records the call and starts Live Assist together; it is saved without the after-call card. A scheduled session gets ready 2 minutes early and waits 10 minutes for the meeting.</p>
    </div>
  );
}
