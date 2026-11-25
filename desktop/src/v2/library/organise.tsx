// What a recording, or several picked ones, can have done to it in the v2 Library: the shared ⋮ menu (one place for
// every action, grouped Organise · Export · Danger, Delete last and red), the Export ▸ formats, Copy for AI, and the
// dialogs behind Move, Label, Rename and Transcribe. Selection mode, the row ⋮, the right-click menu and the recording
// page all build from here, so an action is in the same place everywhere (PLAN_V2_PARITY 3, 4 and 7). The words are in
// the account's language (src/i18n); what goes to the clipboard for an assistant (Copy for AI's header) stays English,
// as the web's.
import { useEffect, useId, useRef, useState } from "react";
import { api, errorMessage, type BulkExportFormat, type ClientFolder, type ExportView, type LibraryItem, type ExportFormat, type MeetingPackFormat, type TranscribeEstimate, type TranscriptDetail, type TranscriptionLanguage, type TranscriptView, type WhatsAppVoiceOutcome } from "../../lib/ipc";
import { defaultViewOf, viewName } from "../../lib/progress";
export { shownExportView } from "../../lib/progress";
import { englishOnlyRisk, languageLabel, MAIN_LANGUAGES, menuLabel, MORE_LANGUAGES } from "../../lib/languages";
import { transcriptText, copiedTranscript, DEFAULT_UNSURE, formatDuration, formatTime, sentenceDate, sourceAppLabel } from "../../lib/format";
import { speakerName } from "../../lib/player";
import { projectLabel } from "../../lib/projects";
import { MAX_LABEL_CHARS } from "../../lib/organise";
import { MAX_TITLE_CHARS } from "../../lib/importFiles";
import { moveRecordings } from "../../views/main/ProjectDialogs";
import { fileName, linkMessage, WHATSAPP_LINK_SECONDS } from "../../views/main/WhatsAppSheet";
import { PACKS } from "../../views/main/TranscriptTools";
import { Button, Dialog, Icons, Select, TextField } from "../components";
import { openSettingsSection } from "../../lib/entitlements";
import type { MenuItem } from "../components/Menu";
import { t, tc, useT, withParts, wordsIn, type Lang } from "../../i18n";

/**
 * Every format, in one order and one set of words for every Export menu. `one` formats exist only for one recording
 * (PDF opens the print page); the zip of several recordings has the rest.
 */
export const EXPORT_FORMATS: readonly { format: ExportFormat; readonly label: string; one?: true }[] = [
  { format: "docx", get label() { return t("Word (.docx)"); } },
  { format: "html", get label() { return t("PDF (print)"); }, one: true },
  { format: "txt", get label() { return t("Text (.txt)"); } },
  { format: "md", get label() { return t("Markdown (.md)"); } },
  { format: "srt", get label() { return t("Subtitles (.srt)"); } },
  { format: "vtt", get label() { return t("Web subtitles (.vtt)"); } },
  { format: "json", get label() { return t("JSON (.json)"); } },
];
/** The formats a zip of several recordings takes (sessions_export). */
export const ZIP_FORMATS = EXPORT_FORMATS.filter((f): f is { format: BulkExportFormat; readonly label: string } => !f.one);

/** The Export ▸ sub-menu of the Library (a zip): one item per format. */
export function exportMenu(run: (format: BulkExportFormat) => void, group = t("Export")): MenuItem {
  return {
    label: t("Export"), icon: Icons.DownloadIcon, group,
    items: ZIP_FORMATS.map(({ format, label }) => ({ label, onPick: () => run(format) })),
  };
}

/** A meeting pack's name in the Export menu, by its id in classic's PACKS. */
function packLabel(id: string): string {
  if (id === "pack-pdf") return t("Meeting pack (PDF)");
  if (id === "pack-zip-audio") return t("Meeting pack with audio (.zip)");
  return t("Meeting pack (.zip)");
}

/** One recording's Export items: every format in the view on screen, then the meeting packs (given only for a recording with at least one screen). */
export function recordingExportItems({ exportAs, packAs, view }: {
  exportAs: (format: ExportFormat) => void;
  packAs?: (format: MeetingPackFormat, withAudio: boolean) => void;
  view: string | null;
}): MenuItem[] {
  return [
    ...EXPORT_FORMATS.map(({ format, label }) => ({ label, group: view ? t("As :view", { view }) : t("Transcript"), onPick: () => exportAs(format) })),
    ...(packAs ? PACKS.map(([id, format, withAudio]) => ({ label: packLabel(id), group: t("With the screens"), onPick: () => packAs(format, withAudio) })) : []),
  ];
}

/** Says something in the page's toast; `error`: a problem (a newer message waits for it instead of replacing it). */
export type Say = (text: string, action?: { label: string; run: () => void }, error?: boolean) => void;
/** Says a problem: flagged, so a newer message waits for it instead of pushing it off the screen (FIX-03). */
export function sayProblem(say: Say, text: string): void {
  say(text, undefined, true);
}

/**
 * Saves the transcripts of `ids` as one zip in `format` where the person chooses; says where, or nothing on cancel.
 * Since U19 in the view the list shows them in (`view`, from shownExportView), else the original words.
 */
export async function exportRecordings(ids: readonly string[], format: BulkExportFormat, say: Say, view?: ExportView): Promise<void> {
  try {
    const path = await api.exportSessions([...ids], format, view);
    if (path) say(t("Saved :path", { path }));
  } catch (e) {
    sayProblem(say, errorMessage(e));
  }
}

export type CopyLine = { speaker: string; start_ms: number; text: string; words?: { text: string; confidence?: number | null }[] };

/**
 * The view a copy reads when none is on screen (a row, several picked ones): the transcript's default view once it is
 * written (Roman Urdu for an Urdu call), else none, the original words. Its lines are aligned with the segments; a
 * missing line reads as that line's own words.
 */
export async function defaultViewLines(transcript: Pick<TranscriptDetail, "id" | "kind" | "views">): Promise<{ view: TranscriptView; lines: readonly (string | null)[] } | null> {
  const view = defaultViewOf(transcript.views);
  if (!view || transcript.kind !== "final" || transcript.views?.status !== "ready") return null;
  const state = await api.transcriptViews(transcript.id).catch(() => null);
  const lines = state?.status === "ready" ? state[view] : null;
  return lines ? { view, lines } : null;
}

/** A recording's lines with the speakers' names and the view its copies are in (`inView(i)`: the view's words of line i, null for its own). */
export async function recordingCopy(item: LibraryItem): Promise<{ lines: CopyLine[]; view: TranscriptView | null; inView: (i: number) => string | null } | null> {
  const id = item.transcript?.final_transcript_id ?? item.transcript?.live_transcript_id ?? null;
  if (!id) return null;
  const [detail, transcript] = await Promise.all([api.session(item.id).catch(() => ({} as Record<string, unknown>)), api.transcript(id)]);
  const raw = detail.speaker_names;
  const names = raw && typeof raw === "object" && !Array.isArray(raw) ? (raw as Record<string, string>) : {};
  const lines = transcript.segments.map((s) => ({ ...s, speaker: speakerName(names, s.speaker) }));
  const shown = lines.length ? await defaultViewLines(transcript) : null;
  return { lines, view: shown?.view ?? null, inView: (i) => shown?.lines[i] ?? null };
}

/**
 * The short header Copy for AI puts above a recording: title, date, people, language. The language is the view's
 * ("Roman Urdu", the words the server writes in its own Copy for AI) when the text below is in one, else the
 * recording's chip ("UR+EN"). In English in every language, as the web's: it is for the assistant, not the window.
 */
export function aiHeader(item: LibraryItem, people: readonly string[], view: TranscriptView | null = null): string {
  const { t: english } = wordsIn("en");
  const source = item.source_app_label ?? (item.source_app ? sourceAppLabel(item.source_app, "en") : null);
  const language = view ? viewName(view, "en") : item.language_chip || item.language;
  return [
    `# ${item.title?.trim() || english("Recording")}`,
    [item.started_at ? `${sentenceDate(item.started_at)}, ${formatTime(new Date(item.started_at))}` : null, source, item.duration_ms ? formatDuration(item.duration_ms) : null].filter(Boolean).join(" · "),
    people.length ? english("People: :people", { people: people.join(", ") }) : null,
    language ? english("Language: :language", { language }) : null,
  ].filter(Boolean).join("\n");
}

/**
 * Copy for AI for one or several recordings: the note on unsure words once, then each recording's header and its
 * lines with timestamps, each in its default view once that is written (Roman Urdu for an Urdu call). Recordings
 * without a transcript are left out and counted.
 */
/** How many recordings Copy for AI reads at once: quicker for many, without a burst of requests. */
export const COPY_READS_AT_ONCE = 4;

/** `run` over `items`, at most `limit` at a time, the answers in the items' order. */
export async function mapLimited<T, R>(items: readonly T[], limit: number, run: (item: T) => Promise<R>): Promise<R[]> {
  const out = new Array<R>(items.length);
  let next = 0;
  const worker = async () => {
    while (next < items.length) {
      const at = next++;
      out[at] = await run(items[at]);
    }
  };
  await Promise.all(Array.from({ length: Math.min(Math.max(1, limit), items.length) }, worker));
  return out;
}

export async function copyForAi(items: readonly LibraryItem[]): Promise<{ text: string; copied: number; skipped: number }> {
  const parts: string[] = [];
  let skipped = 0;
  const copies = await mapLimited(items, COPY_READS_AT_ONCE, recordingCopy);
  for (const [n, item] of items.entries()) {
    const copy = copies[n];
    if (!copy?.lines.length) {
      skipped++;
      continue;
    }
    const people = [...new Set(copy.lines.map((l) => l.speaker))];
    const body = copiedTranscript(copy.lines, copy.inView, true, { ...DEFAULT_UNSURE, note: false }, false);
    parts.push(`${aiHeader(item, people, copy.view)}\n\n${body}`);
  }
  const text = parts.length ? `${DEFAULT_UNSURE.note_text}\n\n${parts.join("\n\n---\n\n")}` : "";
  return { text, copied: parts.length, skipped };
}

export async function copyForAiToClipboard(items: readonly LibraryItem[], say: Say): Promise<void> {
  try {
    const { text, copied, skipped } = await copyForAi(items);
    if (!copied) {
      say(items.length === 1 ? t("This recording has no transcript to copy yet") : t("None of these has a transcript to copy yet"));
      return;
    }
    await navigator.clipboard.writeText(text);
    if (copied === 1 && !skipped) say(t("Copied for AI"));
    else {
      const done = tc("Copied :count for AI", copied);
      say(skipped ? `${done} · ${tc(":count without a transcript left out", skipped)}` : done);
    }
  } catch (e) {
    sayProblem(say, e instanceof Error && /clipboard/i.test(e.message) ? t("Could not access the clipboard") : errorMessage(e));
  }
}

/** "1 recording", "3 recordings". */
export const recordingsWord = (n: number) => tc(":count recording|:count recordings", n);

/** The toast after a move to a project: "Moved “Call” to Acme › Invoices", "Moved 3 recordings to Acme". */
function movedLine(count: number, title: string | null | undefined, projects: readonly ClientFolder[], projectId: string): string {
  const project = projectLabel(projects, projectId);
  if (count === 1) {
    if (title) return project ? t("Moved “:title” to :project", { title, project }) : t("Moved “:title” to the project", { title });
    return project ? t("Moved “the recording” to :project", { project }) : t("Moved “the recording” to the project");
  }
  return project ? tc("Moved :count recordings to :project", count, { project }) : tc("Moved :count recordings to the project", count);
}

/** Moves recordings to a project, out of every project (No project), or to a new project made here. */
export function MoveDialog({ ids, title, current, onClose, say }: {
  ids: readonly string[];
  /** The recording's title when one is moved. */
  title?: string | null;
  /** The project they are in now (null: none; undefined: not all the same). */
  current?: string | null;
  onClose: () => void;
  say: Say;
}) {
  const [projects, setProjects] = useState<ClientFolder[] | null>(null);
  const [target, setTarget] = useState<string>("");
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api.clients().then((r) => setProjects(r.data)).catch((e) => setError(errorMessage(e)));
  }, []);
  const { t, tc } = useT();
  const options = [
    { value: "", label: t("Choose…") },
    ...(current === null ? [] : [{ value: "inbox", label: t("No project") }]),
    ...(projects ?? []).filter((p) => p.id !== current).map((p) => ({ value: p.id, label: projectLabel(projects ?? [], p.id) })),
    { value: "new", label: t("New project…") },
  ];
  const move = async () => {
    setBusy(true);
    try {
      let list = projects ?? [];
      let projectId: string | null = target === "inbox" ? null : target;
      if (target === "new") {
        const { data } = await api.createClient(name.trim());
        list = [...list, data];
        projectId = data.id;
      }
      await moveRecordings(ids, projectId);
      say(projectId ? movedLine(ids.length, title, list, projectId)
        : ids.length === 1 ? (title ? t("Took “:title” out of its project", { title }) : t("Took “the recording” out of its project"))
          : tc("Took :count recordings out of their projects", ids.length));
      onClose();
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  };
  const ready = target !== "" && (target !== "new" || name.trim() !== "");
  return (
    <Dialog open title={t("Move to project")} onClose={onClose}
      description={error ?? (ids.length === 1 ? `“${title || t("Recording")}”` : recordingsWord(ids.length))}
      actions={<><Button onClick={onClose}>{t("Cancel")}</Button><Button variant="primary" disabled={!ready} loading={busy} loadingLabel={t("Moving…")} onClick={() => void move()}>{t("Move")}</Button></>}>
      <Select label={t("Move to")} options={options} value={target} onChange={setTarget} disabled={!projects} />
      {target === "new" && <TextField label={t("New project name")} maxLength={120} value={name} onChange={(e) => setName(e.target.value)} autoFocus />}
    </Dialog>
  );
}

/** Adds a label to recordings or takes one off; the labels already in use are offered as you type. */
export function LabelDialog({ ids, known, onClose, say }: { ids: readonly string[]; known: readonly string[]; onClose: () => void; say: Say }) {
  const { t, tc } = useT();
  const [label, setLabel] = useState("");
  const [busy, setBusy] = useState(false);
  const listId = useId();
  const run = async (action: "label" | "unlabel") => {
    setBusy(true);
    try {
      const changed = await api.bulkSessions([...ids], action, null, label.trim());
      say(`${action === "label" ? t("Labelled") : t("Label taken off")} · ${recordingsWord(changed)}`);
      onClose();
    } catch (e) {
      sayProblem(say, errorMessage(e));
      setBusy(false);
    }
  };
  const empty = !label.trim();
  return (
    <Dialog open title={ids.length === 1 ? t("Label") : tc("Label :count recordings", ids.length)} onClose={onClose}
      actions={<>
        <Button onClick={onClose}>{t("Cancel")}</Button>
        <Button disabled={empty || busy} onClick={() => void run("unlabel")}>{t("Remove label")}</Button>
        <Button variant="primary" disabled={empty || busy} onClick={() => void run("label")}>{t("Add label")}</Button>
      </>}>
      <TextField label={t("Label")} list={listId} maxLength={MAX_LABEL_CHARS} value={label} onChange={(e) => setLabel(e.target.value)} autoFocus
        hint={known.length ? t("In use: :labels", { labels: `${known.slice(0, 6).join(", ")}${known.length > 6 ? "…" : ""}` }) : undefined} />
      <datalist id={listId}>{known.map((k) => <option key={k} value={k} />)}</datalist>
    </Dialog>
  );
}

/** Renames a recording; an empty title asks the server for its automatic one again. */
export function RenameDialog({ item, onClose, say, onRenamed }: { item: LibraryItem; onClose: () => void; say: Say; onRenamed?: (item: LibraryItem) => void }) {
  // Only a title the person chose is theirs to edit (2.0.0-k): "Call with …", an automatic title or the app's name start
  // the box empty, with that title shown as its placeholder. From an older server (no title_source) a title it wrote from
  // what was said (title_auto) is not the person's either; any other stays in the box.
  const { t } = useT();
  const own = item.title_source === "own" || (item.title_source === undefined && !item.title_auto);
  const [title, setTitle] = useState(own ? item.title ?? "" : "");
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const save = async () => {
    const text = title.trim();
    // Unchanged, or left empty over a title the person didn't choose: nothing to save.
    if (text === (own ? item.title ?? "" : "").trim()) {
      onClose();
      return;
    }
    setBusy(true);
    try {
      const answer = await api.organiseSession(item.id, { title: text || null });
      if (answer?.data) onRenamed?.(answer.data);
      say(text ? t("Renamed") : t("Back to the title EchoLedger gives it"));
      onClose();
    } catch (e) {
      sayProblem(say, errorMessage(e));
      setBusy(false);
    }
  };
  return (
    <Dialog open title={t("Rename")} onClose={onClose} initialFocus={input}
      actions={<><Button onClick={onClose}>{t("Cancel")}</Button><Button variant="primary" loading={busy} loadingLabel={t("Saving…")} onClick={() => void save()}>{t("Save")}</Button></>}>
      <form onSubmit={(e) => { e.preventDefault(); void save(); }}>
        <TextField ref={input} label={t("Title")} maxLength={MAX_TITLE_CHARS} value={title} onChange={(e) => setTitle(e.target.value)}
          placeholder={own ? undefined : item.title ?? undefined}
          onFocus={(e) => e.currentTarget.select()}
          hint={own ? t("Leave it empty for the title EchoLedger gives it.") : t("Type a title of your own, or leave it empty to keep this one.")} />
      </form>
    </Dialog>
  );
}

/** What the plan that transcribes with the person's own key says when none is added yet (`needs_transcription_key`). */
export function needsTranscriptionKeyText(lang?: Lang): string {
  return wordsIn(lang).t("This plan transcribes with your own Groq key. Add it in Settings › Transcription key.");
}
/** What the core says then, in English: the same words as desktop/src-tauri/src/error.rs CODE_TEXT, which a test holds
 * them to; the dialog knows the error by them and shows needsTranscriptionKeyText() instead. */
export const NEEDS_TRANSCRIPTION_KEY_TEXT = needsTranscriptionKeyText("en");

/** Transcribe: the minutes it will use and the minutes left this month first, then it starts. */
export function TranscribeDialog({ ids, onClose, say, of }: {
  ids: readonly string[]; onClose: () => void; say: Say;
  /** How many there are in all, when only the first ones go now (Transcribe all…, up to 50 at a time). */
  of?: number;
}) {
  const { t, tc } = useT();
  const [estimate, setEstimate] = useState<TranscribeEstimate | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    api.sessionsTranscribeEstimate([...ids]).then(setEstimate).catch((e) => setError(errorMessage(e)));
  }, [ids]);
  const over = !!estimate && estimate.minutes_left !== null && estimate.minutes > estimate.minutes_left;
  const start = async () => {
    setBusy(true);
    try {
      const r = await api.sessionsTranscribe([...ids]);
      const started = r.started.length ? tc("Transcribing :count recording|Transcribing :count recordings", r.started.length) : t("Nothing to transcribe");
      say(r.skipped.length ? `${started} · ${tc(":count skipped (already transcribed or no audio)|:count skipped (already transcribed or no audio)", r.skipped.length)}` : started);
      onClose();
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  };
  // The core's English words for "no key yet" name the case; the dialog says it in the window's language.
  const needsKey = error === NEEDS_TRANSCRIPTION_KEY_TEXT;
  const minutes = (n: number) => tc("1 minute|:count minutes", n);
  /** The estimate in sentences: the first ones going now, the minutes it uses (and of those left), too many. */
  const estimateText = (e: TranscribeEstimate) => [
    of && of > ids.length ? tc("The first :count of :total now.", ids.length, { total: of }) : null,
    e.minutes_left === null ? t("About :minutes.", { minutes: minutes(e.minutes) })
      : t("About :minutes of the :left left this month.", { minutes: minutes(e.minutes), left: minutes(e.minutes_left) }),
    over ? t("That is more than is left, so nothing will start. Pick fewer recordings.") : null,
  ].filter(Boolean).join(" ");
  const description = needsKey ? needsTranscriptionKeyText() : error ?? (!estimate ? t("Working out the minutes…") : estimateText(estimate));
  return (
    <Dialog open title={ids.length === 1 ? t("Transcribe this recording?") : tc("Transcribe :count recordings?", ids.length)} description={description} onClose={onClose}
      actions={<><Button onClick={onClose}>{t("Cancel")}</Button>{needsKey
        // Nothing can start until a key is added: the way to add one, as classic's "Add my Groq key".
        ? <Button variant="primary" icon={Icons.SettingsIcon} onClick={() => { onClose(); openSettingsSection("transcription-key"); }}>{t("Add my Groq key")}</Button>
        : <Button variant="primary" disabled={!estimate || over} loading={busy} loadingLabel={t("Starting…")} onClick={() => void start()}>{t("Transcribe")}</Button>}</>} />
  );
}

/** The labels in use across the library, most used first. */
export function knownLabels(items: readonly LibraryItem[]): string[] {
  const counts = new Map<string, number>();
  for (const i of items) for (const l of i.labels ?? []) counts.set(l, (counts.get(l) ?? 0) + 1);
  return [...counts.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])).map(([l]) => l);
}

/**
 * Send to WhatsApp, as classic's sheet (views/main/WhatsAppSheet.tsx) and the phone: the text of the view being read,
 * the text with the voice (through the system's share, F6; without it WhatsApp with the text and the voice copied or
 * saved in Downloads to attach), or a link that works for 7 days. The text always goes on the
 * clipboard too: a long one is cut in the message and the core says so. Without a transcript only the link is offered.
 * `text` and `hasAudio` come from the recording page; from a row they are read here.
 */
export function WhatsAppDialog({ item, text: given, hasAudio: givenAudio, onClose, say }: {
  item: LibraryItem;
  text?: string | null;
  hasAudio?: boolean;
  onClose: () => void;
  say: Say;
}) {
  const { t } = useT();
  const [text, setText] = useState<string | null | undefined>(given);
  const [hasAudio, setHasAudio] = useState<boolean | undefined>(givenAudio);
  const [working, setWorking] = useState<"text" | "voice" | "link" | null>(null);
  const [saved, setSaved] = useState<Extract<WhatsAppVoiceOutcome, { kind: "fallback" }> | null>(null);
  // The share was shown and how it ended is not known (Windows 10, or no answer in time): nothing is claimed.
  const [opened, setOpened] = useState(false);
  // After a cut text with the voice on the clipboard: the whole text was copied again by the button.
  const [copiedWhole, setCopiedWhole] = useState(false);
  useEffect(() => {
    // From a row: the text in the recording's default view once it is written, as the page would show it.
    if (given === undefined) {
      recordingCopy(item).then((c) => setText(c?.lines.length ? transcriptText(c.lines.map((l, i) => ({ ...l, text: c.inView(i) ?? l.text })), false) : null))
        .catch(() => setText(null));
    }
    if (givenAudio === undefined) api.session(item.id).then((d) => setHasAudio(typeof d.playback_url === "string")).catch(() => setHasAudio(false));
  }, [item, given, givenAudio]);
  const copy = async (value: string) => {
    try {
      await navigator.clipboard.writeText(value);
      return true;
    } catch {
      return false;
    }
  };
  const open = async (message: string, copied: boolean) => {
    const cut = await api.whatsappOpen(message);
    if (cut) say(copied ? t("WhatsApp opens with the start of the text. The whole text is copied: paste it there.") : t("WhatsApp opens with the start of the text."));
    return cut;
  };
  // One way at a time: a press while another runs is ignored (a ref, so a quick second press is too).
  const running = useRef(false);
  const run = async (kind: "text" | "voice" | "link", fn: () => Promise<void>) => {
    if (running.current) return;
    running.current = true;
    setWorking(kind);
    setCopiedWhole(false);
    try {
      await fn();
    } catch (e) {
      sayProblem(say, errorMessage(e));
    } finally {
      running.current = false;
      setWorking(null);
    }
  };
  const sendText = () => run("text", async () => {
    if (!text) return;
    const cut = await open(text, await copy(text));
    if (!cut) say(t("Opening WhatsApp with the text"));
    onClose();
  });
  /** `share` false: straight to WhatsApp with the text and the voice copied (after a share whose end is not known). */
  const sendVoice = (share = true) => run("voice", async () => {
    if (!text) return;
    const outcome = await api.whatsappShareVoice(item.id, text, share);
    if (outcome.kind === "shared") {
      say(outcome.toWhatsApp ? t("Text and voice sent to WhatsApp") : t("Text and voice shared"));
      onClose();
    } else if (outcome.kind === "opened") {
      setOpened(true);
    } else if (outcome.kind === "fallback") {
      // The clipboard holds the voice when it could be put there; otherwise the text, as before.
      if (!outcome.fileOnClipboard) await copy(text);
      setSaved(outcome);
    }
    // Cancelled: the person closed the share; the dialog stays as it was.
  });
  const sendLink = () => run("link", async () => {
    const link = await api.createShare(item.id, WHATSAPP_LINK_SECONDS, null, !!hasAudio);
    if (!link.url) throw new Error(t("The server made the link but did not send it. Try again."));
    await copy(link.url);
    await open(linkMessage(item.title ?? null, link.url), false);
    say(t("Link made (it works for 7 days) and copied"));
    onClose();
  });
  const copyWhole = async () => {
    if (text && (await copy(text))) setCopiedWhole(true);
  };
  // Done, Cancel, Esc and × wait while a way runs: its answer (a fallback to follow, say) must still be shown.
  const close = () => {
    if (!running.current) onClose();
  };
  const loading = text === undefined || hasAudio === undefined;
  // While one way runs only its button shows it; the others look as they were but take no press (run ignores them).
  const busy = working !== null ? true : undefined;
  if (saved) {
    return (
      <Dialog open title={t("Send to WhatsApp")} onClose={close}
        actions={<>
          {saved.textCut && saved.fileOnClipboard && <Button onClick={() => void copyWhole()}>{copiedWhole ? t("Copied") : t("Copy the whole text")}</Button>}
          <Button variant="primary" onClick={close}>{t("Done")}</Button>
        </>}
        description={saved.fileOnClipboard ? t("The voice file is copied: in the chat, press :keys to attach it.", { keys: saved.pasteKeys })
          : <>{withParts(t("Saved in Downloads: :file"), { file: <bdi>{fileName(saved.path)}</bdi> })}</>}>
        {saved.fileOnClipboard
          ? <p>{withParts(t("The text is in the message. The voice is saved in Downloads too: :file"), { file: <bdi>{fileName(saved.path)}</bdi> })}</p>
          : <p>{withParts(t(":note: in the chat, click :plus (or the paper clip), then :document, and choose this file. The text is in the message, and copied too."), {
            note: <b>{t("Attach the saved voice file in WhatsApp")}</b>, plus: <b>+</b>, document: <b>{t("Document")}</b>,
          })}</p>}
        {saved.textCut && <p className="el-caption el-secondary">{t("The message has the start of the text: it was too long to send whole. After attaching the voice, copy the whole text and paste it there.")}</p>}
      </Dialog>
    );
  }
  if (opened) {
    return (
      <Dialog open title={t("Send to WhatsApp")} onClose={close} description={t("The share is open: finish sending there.")}
        actions={<>
          <Button loading={working === "voice"} loadingLabel={t("Opening WhatsApp…")} onClick={() => void sendVoice(false)}>{t("Open WhatsApp with the text instead")}</Button>
          <Button variant="primary" aria-disabled={busy} onClick={close}>{t("Done")}</Button>
        </>}>
        <p>{t("Closed it without sending, or WhatsApp was not offered? Open WhatsApp with the text, and paste the voice there.")}</p>
      </Dialog>
    );
  }
  return (
    <Dialog open title={t("Send to WhatsApp")} onClose={close}
      description={text === null ? t("There is no transcript yet: only the link can be sent.") : t("Opens WhatsApp, or WhatsApp Web, with the message ready to send to the person you choose.")}
      actions={<Button aria-disabled={busy} onClick={close}>{t("Cancel")}</Button>}>
      <div className="el-choices">
        <Button variant="primary" disabled={!text} aria-disabled={busy} loading={working === "text"} loadingLabel={t("Opening WhatsApp…")} onClick={() => void sendText()}>{t("Text only")}</Button>
        <p className="el-caption el-secondary">{t("The text of the view you are reading.")}</p>
        {hasAudio && (
          <>
            <Button disabled={!text} aria-disabled={busy} loading={working === "voice"} loadingLabel={t("Sending…")} onClick={() => void sendVoice()}>{t("Text and voice")}</Button>
            <p className="el-caption el-secondary">{t("The text and the voice, ready for WhatsApp.")}</p>
          </>
        )}
        <Button disabled={loading} aria-disabled={busy} loading={working === "link"} loadingLabel={t("Making the link…")} onClick={() => void sendLink()}>{t("Link (works for 7 days)")}</Button>
        <p className="el-caption el-secondary">{hasAudio ? t("Anyone with it can read and listen for 7 days.") : t("Anyone with it can read for 7 days.")}</p>
      </div>
    </Dialog>
  );
}

/**
 * Change language & re-transcribe: the language the recording is in, then a new transcript in it. The current one stays
 * until the new one is ready. "English only" on a recording with Urdu in it is said once (the engine would translate).
 */
export function LanguageDialog({ item, onClose, say }: { item: LibraryItem; onClose: () => void; say: Say }) {
  const { t } = useT();
  const [language, setLanguage] = useState<string>(item.language ?? "");
  const [warned, setWarned] = useState(false);
  const [busy, setBusy] = useState(false);
  const risk = language === "en" && englishOnlyRisk(item.transcript?.final_language, item.transcript?.preview);
  const options = [
    ...MAIN_LANGUAGES.map((o) => ({ value: o.value ?? "", label: menuLabel(o) })),
    ...MORE_LANGUAGES.map((o) => ({ value: o.value ?? "", label: menuLabel(o) })),
  ];
  const run = async () => {
    if (risk && !warned) {
      setWarned(true);
      return;
    }
    setBusy(true);
    try {
      await api.retranscribe(item.id, (language || null) as TranscriptionLanguage | null);
      say(t("Re-transcribing in :language. The current transcript stays until the new one is ready.", { language: languageLabel(language || null) }));
      onClose();
    } catch (e) {
      sayProblem(say, errorMessage(e));
      setBusy(false);
    }
  };
  return (
    <Dialog open title={t("Change language and re-transcribe")} onClose={onClose}
      description={warned ? t("English only would translate the Urdu in this recording into English. Choose Urdu (with English words) to keep what was said.") : t("The current transcript stays until the new one is ready.")}
      actions={<><Button onClick={onClose}>{t("Cancel")}</Button>
        <Button variant="primary" loading={busy} loadingLabel={t("Starting…")} onClick={() => void run()}>{warned ? t("Re-transcribe anyway") : t("Re-transcribe")}</Button></>}>
      <Select label={t("Language")} options={options} value={language} onChange={(v) => { setLanguage(v); setWarned(false); }} />
    </Dialog>
  );
}
