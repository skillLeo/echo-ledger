// Live Assist prep (v1.9 L4): who the meeting is with and what kind (templates per type), a linked project and its
// documents, the brief (goals, done, not done, to discuss, instructions), mode, hint level and language help, the
// consent line with Copy, then Start now (recording and assist at once) or Schedule. In the window's language (D-264) on
// Live; in English on classic's Record page (LiveAssistClassic), which is English. The consent line is said to the other
// side, English in every language; names, projects' and files' names and what the core says are shown as they are.
import { useEffect, useRef, useState } from "react";
import { api, type ClientFile } from "../../lib/ipc";
import { isRtl, lineDir, useLanguage, wordsIn, type Lang } from "../../i18n";
import { Badge, Button, Card, Checkbox, EmptyState, Icons, Loading, Segmented, Select, Skeleton, TextArea, TextField } from "../components";
import { assist, failureText, type AssistMode, type HintLevel, type LanguageHelp, type MeetingType, type Prep, type PrepDraft } from "./adapter";
import { CONSENT_LINE, interviewNote } from "./AssistCard";
import { errorWords, isSessionActive, sessionActiveOf } from "./card";
import type { SessionActive } from "./adapter";

/** The kinds of meeting and their names in `lang` (the window's when left out, "en" on classic's English page). */
export function meetingTypes(lang?: Lang): { value: MeetingType; label: string }[] {
  const { t } = wordsIn(lang);
  return [
    { value: "interview", label: t("Interview") },
    { value: "existing_client", label: t("Existing client") },
    { value: "new_client", label: t("Prospective client") },
    { value: "boss", label: t("Boss") },
    { value: "general", label: t("Other meeting") },
  ];
}
/** Their English names: the keys Live's session rows say in the window's language (LiveAssistSection). */
export const MEETING_TYPES: readonly { value: MeetingType; label: string }[] = meetingTypes("en");

type Template = Pick<PrepDraft, "goals" | "to_discuss" | "hint_level">;

/**
 * A starting brief per meeting type (the person edits it), in `lang`: the window's when left out, "en" on classic's
 * English page. Interviews start with outline hints.
 */
export function templates(lang?: Lang): Record<MeetingType, Template> {
  const { t } = wordsIn(lang);
  return {
    interview: { goals: t("Show the experience the role asks for, with one example each."), to_discuss: t("Salary range, notice period, start date."), hint_level: "outline" },
    existing_client: { goals: t("Agree the next order and its payment terms."), to_discuss: t("Open invoices, delivery dates."), hint_level: "full" },
    new_client: { goals: t("Understand what they need and agree a first step."), to_discuss: t("Budget, timeline, who decides."), hint_level: "full" },
    boss: { goals: t("Agree priorities for the next two weeks."), to_discuss: t("Blockers and what I need from them."), hint_level: "full" },
    general: { goals: "", to_discuss: "", hint_level: "full" },
  };
}
/** The English briefs (classic's, and the ones a prep written in English still has untouched). */
export const TEMPLATES: Record<MeetingType, Template> = templates("en");

export function newDraft(type: MeetingType = "interview", lang?: Lang): PrepDraft {
  const brief = templates(lang)[type];
  return {
    meeting_type: type, title: "", counterpart: "", company: "", project_id: null, goals: brief.goals, done: "", not_done: "",
    to_discuss: brief.to_discuss, instructions: "", hint_level: brief.hint_level, mode: "balanced", language_help: "off", scheduled_at: null,
  };
}

/**
 * Changing the type brings that type's template in (in `lang`), but never over what the person wrote: a template in
 * the window's language or in English counts as untouched.
 */
export function withType(draft: PrepDraft, type: MeetingType, lang?: Lang): PrepDraft {
  const said = templates(lang);
  const from = [said[draft.meeting_type], TEMPLATES[draft.meeting_type]];
  const to = said[type];
  const untouched = (key: "goals" | "to_discuss") => !draft[key] || from.some((f) => f[key] === draft[key]);
  return {
    ...draft, meeting_type: type,
    goals: untouched("goals") ? to.goals : draft.goals,
    to_discuss: untouched("to_discuss") ? to.to_discuss : draft.to_discuss,
    hint_level: draft.hint_level === from[0].hint_level ? to.hint_level : draft.hint_level,
  };
}

const MAX = 4000;

/**
 * The prep for a new session, or for an upcoming one (`prep`). In `lang`: the window's when left out, "en" on classic's
 * English page.
 */
export function PrepScreen({ prep = null, projects, onBack, onStarted, lang }: {
  prep?: Prep | null;
  projects: { id: string; name: string }[];
  onBack: () => void;
  onStarted: () => void;
  lang?: Lang;
}) {
  const windowLang = useLanguage();
  const shown = lang ?? windowLang;
  const rtl = isRtl(shown);
  const { t, tc } = wordsIn(lang);
  const [draft, setDraft] = useState<PrepDraft>(() => (prep ? { ...prep } : newDraft("interview", lang)));
  const [saved, setSaved] = useState<Prep | null>(prep);
  const [files, setFiles] = useState<ClientFile[] | null>(null);
  const [filesFailed, setFilesFailed] = useState(false);
  const [filesAttempt, setFilesAttempt] = useState(0);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [scheduleAt, setScheduleAt] = useState(prep?.scheduled_at?.slice(0, 16) ?? "");
  /** 409 session_active on Start now: the prep to start once the running session has ended. */
  const [activeFor, setActiveFor] = useState<{ prepId: string; running: SessionActive } | null>(null);
  // Leaving the prep while it asks about a running session keeps that session, as Keep running does.
  const asking = useRef(false);
  asking.current = activeFor !== null;
  useEffect(() => () => {
    if (asking.current) void assist.keepRunning().catch(() => undefined);
  }, []);
  /** documents_too_large (1.9.0-n): from attaching, or from the relay when the session sends them; the old ones stay. */
  const [docsTooLarge, setDocsTooLarge] = useState(false);

  useEffect(() => {
    let live = true;
    const un = assist.listen((m) => {
      if (live && m.type === "error" && m.code === "documents_too_large") setDocsTooLarge(true);
    });
    return () => {
      live = false;
      void un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  const set = <K extends keyof PrepDraft>(key: K, value: PrepDraft[K]) => setDraft((d) => ({ ...d, [key]: value }));

  useEffect(() => {
    setFiles(null);
    setFilesFailed(false);
    if (!draft.project_id) return;
    let live = true;
    api.clientWorkspace(draft.project_id).then((w) => live && setFiles((w?.data?.files ?? []).filter((f) => f.status === "ready")), () => live && setFilesFailed(true));
    return () => {
      live = false;
    };
  }, [draft.project_id, filesAttempt]);

  const run = async (key: string, fn: () => Promise<void>) => {
    setBusy(key);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(failureText(e, lang));
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
    try {
      setSaved(on ? await assist.addDocuments(p.id, [file.id]) : await assist.removeDocument(p.id, file.id));
      if (!on) setDocsTooLarge(false);
    } catch (e) {
      if ((e as { code?: unknown } | null)?.code !== "documents_too_large") throw e;
      setDocsTooLarge(true);
    }
  });
  const startNow = (prepId: string) => run("start", async () => {
    try {
      await assist.start(prepId, draft.mode);
      onStarted();
    } catch (e) {
      if (!isSessionActive(e)) throw e;
      setActiveFor({ prepId, running: sessionActiveOf(e, lang) });
    }
  });
  const tooLong = (["goals", "done", "not_done", "to_discuss", "instructions"] as const).find((k) => draft[k].length > MAX);
  const modes: { value: AssistMode; label: string }[] = [
    { value: "fast", label: t("Fast") }, { value: "balanced", label: t("Balanced") }, { value: "deep", label: t("Deep (Ultra)") }, { value: "max", label: t("Max (Ultra)") },
  ];
  const hints: { value: HintLevel; label: string }[] = [{ value: "keywords", label: t("Keywords") }, { value: "outline", label: t("Outline") }, { value: "full", label: t("Full answer") }];
  const languageHelp: { value: LanguageHelp; label: string }[] = [
    { value: "off", label: t("Off") }, { value: "urdu", label: t("Urdu") }, { value: "roman_urdu", label: t("Roman Urdu") }, { value: "arabic", label: t("Arabic") },
  ];
  // In a right-to-left window the consent line is still English: in the English face, left to right.
  const english = rtl ? { lang: "en", dir: "ltr" as const } : {};
  const dirOf = (line: string) => lineDir(line, shown);

  return (
    <div className="el-page el-page--narrow" data-v2="assist-prep">
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>{t("Live")}</Button>
      <header className="el-page__head"><h1 className="el-title-l">{prep ? t("Edit prep") : t("New Live Assist session")}</h1></header>

      <section className="el-group" aria-label={t("The meeting")}>
        <h2 className="el-caption el-body-strong el-group__title">{t("The meeting")}</h2>
        <Card className="el-form">
          <Select label={t("Meeting type")} options={meetingTypes(lang)} value={draft.meeting_type}
            onChange={(v) => setDraft((d) => withType(d, v as MeetingType, lang))} />
          <TextField label={t("Title")} value={draft.title} placeholder={t("Interview at Mehran Foods")} onChange={(e) => set("title", e.target.value)} />
          <div className="el-form__row">
            <TextField label={t("prep_context.with")} value={draft.counterpart} placeholder={t("Ayesha Siddiqui")} onChange={(e) => set("counterpart", e.target.value)} />
            <TextField label={t("Company")} value={draft.company} placeholder={t("Mehran Foods")} onChange={(e) => set("company", e.target.value)} />
          </div>
          <Select label={t("Project")} value={draft.project_id ?? ""} onChange={(v) => set("project_id", v || null)}
            options={[{ value: "", label: t("No project") }, ...projects.map((p) => ({ value: p.id, label: p.name }))]} />
        </Card>
      </section>

      <section className="el-group" aria-label={t("Documents")}>
        <h2 className="el-caption el-body-strong el-group__title">{t("Documents")}</h2>
        <Card className="el-form">
          {!draft.project_id ? (
            <p className="el-secondary">{t("Link a project to use its files: a job description, a proposal, last month's invoices.")}</p>
          ) : filesFailed ? (
            <EmptyState tone="error" icon={Icons.ErrorIcon} title={t("The project's files didn't load")}
              body={t("The session works without them; you can attach them later.")} action={<Button onClick={() => setFilesAttempt((n) => n + 1)}>{t("Try again")}</Button>} />
          ) : files === null ? (
            <Loading label={t("Reading the project's files")} key="loading"><Skeleton width="medium" /><Skeleton width="long" /></Loading>
          ) : files.length === 0 ? (
            <EmptyState icon={Icons.TranscriptIcon} title={t("No files in this project")} body={t("Add files to the project, then attach them here.")} />
          ) : (
            <fieldset className="el-radio-group">
              <legend>{t("Use in this session")}</legend>
              {docsTooLarge && <p className="el-field__error" role="alert">{errorWords("documents_too_large", false, "", lang)}</p>}
              {files.map((f) => (
                // A file's name is as it is: in its own isolate inside a right-to-left window.
                <Checkbox key={f.id} label={rtl ? <bdi>{f.name}</bdi> : f.name} checked={attached.has(f.id)} disabled={busy === `file-${f.id}`}
                  hint={f.pages ? tc(":count page|:count pages", f.pages) : undefined} onChange={(e) => void toggleFile(f, e.target.checked)} />
              ))}
            </fieldset>
          )}
        </Card>
      </section>

      <section className="el-group" aria-label={t("Brief")}>
        <h2 className="el-caption el-body-strong el-group__title">{t("Brief")}</h2>
        <Card className="el-form">
          <TextArea label={t("Goals")} value={draft.goals} maxLength={MAX} onChange={(e) => set("goals", e.target.value)} />
          <div className="el-form__row">
            <TextArea label={t("Done so far")} value={draft.done} maxLength={MAX} onChange={(e) => set("done", e.target.value)} />
            <TextArea label={t("Not done yet")} value={draft.not_done} maxLength={MAX} onChange={(e) => set("not_done", e.target.value)} />
          </div>
          <TextArea label={t("To discuss")} value={draft.to_discuss} maxLength={MAX} onChange={(e) => set("to_discuss", e.target.value)} />
          <TextArea label={t("Instructions for the assistant")} value={draft.instructions} maxLength={MAX}
            hint={t("It never invents experience, numbers or promises that aren't in this brief.")} onChange={(e) => set("instructions", e.target.value)} />
        </Card>
      </section>

      <section className="el-group" aria-label={t("How it helps")}>
        <h2 className="el-caption el-body-strong el-group__title">{t("How it helps")}</h2>
        <Card className="el-form">
          <div className="el-form__row">
            <Select label={t("Mode")} options={modes} value={draft.mode} onChange={(v) => set("mode", v as AssistMode)} />
            <Select label={t("Language help")} options={languageHelp} value={draft.language_help} onChange={(v) => set("language_help", v as LanguageHelp)} />
          </div>
          <div className="el-field">
            <span className="el-field__label">{t("Hints")}</span>
            <Segmented label={t("Hints")} options={hints} value={draft.hint_level} onChange={(v) => set("hint_level", v)} />
          </div>
          {draft.meeting_type === "interview" ? (
            <p className="el-secondary" role="note">{interviewNote(lang)}</p>
          ) : (
            <div className="el-consent" role="note">
              <span className="el-caption el-body-strong">{t("Say this at the start")}</span>
              <p {...english}>{CONSENT_LINE}</p>
              <Button size="sm" variant="quiet" onClick={() => void run("copy", () => navigator.clipboard.writeText(CONSENT_LINE))}>{t("Copy")}</Button>
            </div>
          )}
        </Card>
      </section>

      {error && <p className="el-field__error" role="alert" dir={dirOf(error)}>{error}</p>}
      {activeFor && (
        <div className="el-consent" role="alert">
          <span dir={dirOf(activeFor.running.text)}>{activeFor.running.text}</span>
          <span className="el-page__actions">
            <Button size="sm" variant="primary" loading={busy === "start"} loadingLabel={t("Starting…")}
              onClick={() => {
                const { prepId, running } = activeFor;
                setActiveFor(null);
                void run("start", async () => {
                  await assist.startReplacing(running.session_id, prepId, draft.mode);
                  onStarted();
                });
              }}>{activeFor.running.action}</Button>
            <Button size="sm" variant="quiet" onClick={() => { setActiveFor(null); void run("keep", () => assist.keepRunning()); }}>{t("Keep running")}</Button>
          </span>
        </div>
      )}
      {tooLong && <p className="el-field__error" role="alert">{t("Each part of the brief can have up to 4,000 characters.")}</p>}
      <div className="el-prep__actions">
        <Button variant="primary" icon={Icons.RecordIcon} loading={busy === "start"} loadingLabel={t("Starting…")} disabled={!!tooLong}
          onClick={() => void run("start", async () => {
            const p = await save();
            await startNow(p.id);
          })}>{t("Start now")}</Button>
        <TextField label={t("Schedule for")} type="datetime-local" value={scheduleAt} onChange={(e) => setScheduleAt(e.target.value)} className="el-prep__when" />
        <Button disabled={!scheduleAt || !!tooLong} loading={busy === "schedule"} loadingLabel={t("Scheduling…")}
          onClick={() => void run("schedule", async () => {
            const p = await save();
            setSaved(await assist.schedule(p.id, new Date(scheduleAt).toISOString()));
          })}>{t("Schedule")}</Button>
        {saved?.status === "scheduled" && <Badge tone="accent">{t("Scheduled")}</Badge>}
        <Button variant="quiet" loading={busy === "save"} loadingLabel={t("Saving…")} disabled={!!tooLong} onClick={() => void run("save", async () => void (await save()))}>{t("Save draft")}</Button>
      </div>
      <p className="el-caption">{t("Start now records the call and starts Live Assist together; it is saved without the after-call card. A scheduled session gets ready 2 minutes early and waits 10 minutes for the meeting.")}</p>
    </div>
  );
}
