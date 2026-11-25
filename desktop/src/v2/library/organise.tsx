// What a recording, or several picked ones, can have done to it in the v2 Library: the shared ⋮ menu (one place for
// every action, grouped Organise · Export · Danger, Delete last and red), the Export ▸ formats, Copy for AI, and the
// dialogs behind Move, Label, Rename and Transcribe. Selection mode, the row ⋮, the right-click menu and the recording
// page all build from here, so an action is in the same place everywhere (PLAN_V2_PARITY 3, 4 and 7).
import { useEffect, useId, useRef, useState } from "react";
import { api, errorMessage, type BulkExportFormat, type ClientFolder, type ExportView, type LibraryItem, type ExportFormat, type MeetingPackFormat, type TranscribeEstimate, type TranscriptDetail, type TranscriptionLanguage, type TranscriptView, type WhatsAppVoiceOutcome } from "../../lib/ipc";
import { defaultViewOf } from "../../lib/progress";
export { shownExportView } from "../../lib/progress";
import { englishOnlyRisk, languageLabel, MAIN_LANGUAGES, menuLabel, MORE_LANGUAGES } from "../../lib/languages";
import { transcriptText, copiedTranscript, DEFAULT_UNSURE, formatDuration, formatTime, sentenceDate, sourceAppLabel } from "../../lib/format";
import { speakerName } from "../../lib/player";
import { projectLabel } from "../../lib/projects";
import { MAX_LABEL_CHARS } from "../../lib/organise";
import { MAX_TITLE_CHARS } from "../../lib/importFiles";
import { moveRecordings, movedText } from "../../views/main/ProjectDialogs";
import { ATTACH_NOTE, fileName, linkMessage, pasteNote, WHATSAPP_LINK_SECONDS } from "../../views/main/WhatsAppSheet";
import { PACKS } from "../../views/main/TranscriptTools";
import { Button, Dialog, Icons, Select, TextField } from "../components";
import { openSettingsSection } from "../../lib/entitlements";
import type { MenuItem } from "../components/Menu";

/**
 * Every format, in one order and one set of words for every Export menu. `one` formats exist only for one recording
 * (PDF opens the print page); the zip of several recordings has the rest.
 */
export const EXPORT_FORMATS: readonly { format: ExportFormat; label: string; one?: true }[] = [
  { format: "docx", label: "Word (.docx)" },
  { format: "html", label: "PDF (print)", one: true },
  { format: "txt", label: "Text (.txt)" },
  { format: "md", label: "Markdown (.md)" },
  { format: "srt", label: "Subtitles (.srt)" },
  { format: "vtt", label: "Web subtitles (.vtt)" },
  { format: "json", label: "JSON (.json)" },
];
/** The formats a zip of several recordings takes (sessions_export). */
export const ZIP_FORMATS = EXPORT_FORMATS.filter((f): f is { format: BulkExportFormat; label: string } => !f.one);
/** The menu's words for Change language, everywhere. */
export const CHANGE_LANGUAGE = "Change language and re-transcribe…";

/** The Export ▸ sub-menu of the Library (a zip): one item per format. */
export function exportMenu(run: (format: BulkExportFormat) => void, group = "Export"): MenuItem {
  return {
    label: "Export", icon: Icons.DownloadIcon, group,
    items: ZIP_FORMATS.map(({ format, label }) => ({ label, onPick: () => run(format) })),
  };
}

/** One recording's Export items: every format in the view on screen, then the meeting packs (given only for a recording with at least one screen). */
export function recordingExportItems({ exportAs, packAs, view }: {
  exportAs: (format: ExportFormat) => void;
  packAs?: (format: MeetingPackFormat, withAudio: boolean) => void;
  view: string | null;
}): MenuItem[] {
  return [
    ...EXPORT_FORMATS.map(({ format, label }) => ({ label, group: view ? `As ${view}` : "Transcript", onPick: () => exportAs(format) })),
    ...(packAs ? PACKS.map(([, format, withAudio, label]) => ({ label, group: "With the screens", onPick: () => packAs(format, withAudio) })) : []),
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
    if (path) say(`Saved ${path}`);
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

/** The language line's words for text in a view: what the server writes in its own Copy for AI. */
export const VIEW_LANGUAGE: Record<TranscriptView, string> = { roman: "Roman Urdu", urdu: "Urdu", english: "English" };

/**
 * The short header Copy for AI puts above a recording: title, date, people, language. The language is the view's
 * ("Roman Urdu") when the text below is in one, else the recording's chip ("UR+EN").
 */
export function aiHeader(item: LibraryItem, people: readonly string[], view: TranscriptView | null = null): string {
  const source = item.source_app_label ?? (item.source_app ? sourceAppLabel(item.source_app) : null);
  const language = view ? VIEW_LANGUAGE[view] : item.language_chip || item.language;
  return [
    `# ${item.title?.trim() || "Recording"}`,
    [item.started_at ? `${sentenceDate(item.started_at)}, ${formatTime(new Date(item.started_at))}` : null, source, item.duration_ms ? formatDuration(item.duration_ms) : null].filter(Boolean).join(" · "),
    people.length ? `People: ${people.join(", ")}` : null,
    language ? `Language: ${language}` : null,
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
      say(items.length === 1 ? "This recording has no transcript to copy yet" : "None of these has a transcript to copy yet");
      return;
    }
    await navigator.clipboard.writeText(text);
    say(copied === 1 && !skipped ? "Copied for AI" : `Copied ${copied} for AI${skipped ? ` · ${skipped} without a transcript left out` : ""}`);
  } catch (e) {
    sayProblem(say, e instanceof Error && /clipboard/i.test(e.message) ? "Could not access the clipboard" : errorMessage(e));
  }
}

/** "1 recording", "3 recordings". */
export const recordingsWord = (n: number) => (n === 1 ? "1 recording" : `${n} recordings`);

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
  const options = [
    { value: "", label: "Choose…" },
    ...(current === null ? [] : [{ value: "inbox", label: "No project" }]),
    ...(projects ?? []).filter((p) => p.id !== current).map((p) => ({ value: p.id, label: projectLabel(projects ?? [], p.id) })),
    { value: "new", label: "New project…" },
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
      say(projectId ? movedText(ids.length, title, list, projectId)
        : ids.length === 1 ? `Took “${title || "the recording"}” out of its project` : `Took ${ids.length} recordings out of their projects`);
      onClose();
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  };
  const ready = target !== "" && (target !== "new" || name.trim() !== "");
  return (
    <Dialog open title="Move to project" onClose={onClose}
      description={error ?? (ids.length === 1 ? `“${title || "Recording"}”` : recordingsWord(ids.length))}
      actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" disabled={!ready} loading={busy} loadingLabel="Moving…" onClick={() => void move()}>Move</Button></>}>
      <Select label="Move to" options={options} value={target} onChange={setTarget} disabled={!projects} />
      {target === "new" && <TextField label="New project name" maxLength={120} value={name} onChange={(e) => setName(e.target.value)} autoFocus />}
    </Dialog>
  );
}

/** Adds a label to recordings or takes one off; the labels already in use are offered as you type. */
export function LabelDialog({ ids, known, onClose, say }: { ids: readonly string[]; known: readonly string[]; onClose: () => void; say: Say }) {
  const [label, setLabel] = useState("");
  const [busy, setBusy] = useState(false);
  const listId = useId();
  const run = async (action: "label" | "unlabel") => {
    setBusy(true);
    try {
      const changed = await api.bulkSessions([...ids], action, null, label.trim());
      say(`${action === "label" ? "Labelled" : "Label taken off"} · ${recordingsWord(changed)}`);
      onClose();
    } catch (e) {
      sayProblem(say, errorMessage(e));
      setBusy(false);
    }
  };
  const empty = !label.trim();
  return (
    <Dialog open title={ids.length === 1 ? "Label" : `Label ${recordingsWord(ids.length)}`} onClose={onClose}
      actions={<>
        <Button onClick={onClose}>Cancel</Button>
        <Button disabled={empty || busy} onClick={() => void run("unlabel")}>Remove label</Button>
        <Button variant="primary" disabled={empty || busy} onClick={() => void run("label")}>Add label</Button>
      </>}>
      <TextField label="Label" list={listId} maxLength={MAX_LABEL_CHARS} value={label} onChange={(e) => setLabel(e.target.value)} autoFocus
        hint={known.length ? `In use: ${known.slice(0, 6).join(", ")}${known.length > 6 ? "…" : ""}` : undefined} />
      <datalist id={listId}>{known.map((k) => <option key={k} value={k} />)}</datalist>
    </Dialog>
  );
}

/** Renames a recording; an empty title asks the server for its automatic one again. */
export function RenameDialog({ item, onClose, say, onRenamed }: { item: LibraryItem; onClose: () => void; say: Say; onRenamed?: (item: LibraryItem) => void }) {
  // Only a title the person chose is theirs to edit (2.0.0-k): "Call with …", an automatic title or the app's name start
  // the box empty, with that title shown as its placeholder. From an older server (no title_source) a title it wrote from
  // what was said (title_auto) is not the person's either; any other stays in the box.
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
      say(text ? "Renamed" : "Back to the title EchoLedger gives it");
      onClose();
    } catch (e) {
      sayProblem(say, errorMessage(e));
      setBusy(false);
    }
  };
  return (
    <Dialog open title="Rename" onClose={onClose} initialFocus={input}
      actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} loadingLabel="Saving…" onClick={() => void save()}>Save</Button></>}>
      <form onSubmit={(e) => { e.preventDefault(); void save(); }}>
        <TextField ref={input} label="Title" maxLength={MAX_TITLE_CHARS} value={title} onChange={(e) => setTitle(e.target.value)}
          placeholder={own ? undefined : item.title ?? undefined}
          onFocus={(e) => e.currentTarget.select()}
          hint={own ? "Leave it empty for the title EchoLedger gives it." : "Type a title of your own, or leave it empty to keep this one."} />
      </form>
    </Dialog>
  );
}

/** What the core says when the plan transcribes with the person's own key and none is added yet (`needs_transcription_key`);
 * the same words as desktop/src-tauri/src/error.rs CODE_TEXT, which a test holds them to. */
export const NEEDS_TRANSCRIPTION_KEY_TEXT = "This plan transcribes with your own Groq key. Add it in Settings › Transcription key.";

/** Transcribe: the minutes it will use and the minutes left this month first, then it starts. */
export function TranscribeDialog({ ids, onClose, say, of }: {
  ids: readonly string[]; onClose: () => void; say: Say;
  /** How many there are in all, when only the first ones go now (Transcribe all…, up to 50 at a time). */
  of?: number;
}) {
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
      const skipped = r.skipped.length ? ` · ${r.skipped.length} skipped (already transcribed or no audio)` : "";
      say(r.started.length ? `Transcribing ${recordingsWord(r.started.length)}${skipped}` : `Nothing to transcribe${skipped}`);
      onClose();
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  };
  const needsKey = error === NEEDS_TRANSCRIPTION_KEY_TEXT;
  const minutes = (n: number) => (n === 1 ? "1 minute" : `${n} minutes`);
  const first = of && of > ids.length ? `The first ${ids.length} of ${of} now. ` : "";
  const description = error
    ?? (!estimate ? "Working out the minutes…"
      : `${first}About ${minutes(estimate.minutes)}${estimate.minutes_left === null ? "." : ` of the ${minutes(estimate.minutes_left)} left this month.`}`
        + (over ? " That is more than is left, so nothing will start. Pick fewer recordings." : ""));
  return (
    <Dialog open title={ids.length === 1 ? "Transcribe this recording?" : `Transcribe ${recordingsWord(ids.length)}?`} description={description} onClose={onClose}
      actions={<><Button onClick={onClose}>Cancel</Button>{needsKey
        // Nothing can start until a key is added: the way to add one, as classic's "Add my Groq key".
        ? <Button variant="primary" icon={Icons.SettingsIcon} onClick={() => { onClose(); openSettingsSection("transcription-key"); }}>Add my Groq key</Button>
        : <Button variant="primary" disabled={!estimate || over} loading={busy} loadingLabel="Starting…" onClick={() => void start()}>Transcribe</Button>}</>} />
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
    if (cut) say(copied ? "WhatsApp opens with the start of the text. The whole text is copied: paste it there." : "WhatsApp opens with the start of the text.");
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
    if (!cut) say("Opening WhatsApp with the text");
    onClose();
  });
  /** `share` false: straight to WhatsApp with the text and the voice copied (after a share whose end is not known). */
  const sendVoice = (share = true) => run("voice", async () => {
    if (!text) return;
    const outcome = await api.whatsappShareVoice(item.id, text, share);
    if (outcome.kind === "shared") {
      say(outcome.toWhatsApp ? "Text and voice sent to WhatsApp" : "Text and voice shared");
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
    if (!link.url) throw new Error("The server made the link but did not send it. Try again.");
    await copy(link.url);
    await open(linkMessage(item.title ?? null, link.url), false);
    say("Link made (it works for 7 days) and copied");
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
      <Dialog open title="Send to WhatsApp" onClose={close}
        actions={<>
          {saved.textCut && saved.fileOnClipboard && <Button onClick={() => void copyWhole()}>{copiedWhole ? "Copied" : "Copy the whole text"}</Button>}
          <Button variant="primary" onClick={close}>Done</Button>
        </>}
        description={saved.fileOnClipboard ? pasteNote(saved.pasteKeys) : <>Saved in Downloads: <bdi>{fileName(saved.path)}</bdi></>}>
        {saved.fileOnClipboard
          ? <p>The text is in the message. The voice is saved in Downloads too: <bdi>{fileName(saved.path)}</bdi></p>
          : <p><b>{ATTACH_NOTE}</b>: in the chat, click <b>+</b> (or the paper clip), then <b>Document</b>, and choose this file. The text is in the message, and copied too.</p>}
        {saved.textCut && <p className="el-caption el-secondary">The message has the start of the text: it was too long to send whole. After attaching the voice, copy the whole text and paste it there.</p>}
      </Dialog>
    );
  }
  if (opened) {
    return (
      <Dialog open title="Send to WhatsApp" onClose={close} description="The share is open: finish sending there."
        actions={<>
          <Button loading={working === "voice"} loadingLabel="Opening WhatsApp…" onClick={() => void sendVoice(false)}>Open WhatsApp with the text instead</Button>
          <Button variant="primary" aria-disabled={busy} onClick={close}>Done</Button>
        </>}>
        <p>Closed it without sending, or WhatsApp was not offered? Open WhatsApp with the text, and paste the voice there.</p>
      </Dialog>
    );
  }
  return (
    <Dialog open title="Send to WhatsApp" onClose={close}
      description={text === null ? "There is no transcript yet: only the link can be sent." : "Opens WhatsApp, or WhatsApp Web, with the message ready to send to the person you choose."}
      actions={<Button aria-disabled={busy} onClick={close}>Cancel</Button>}>
      <div className="el-choices">
        <Button variant="primary" disabled={!text} aria-disabled={busy} loading={working === "text"} loadingLabel="Opening WhatsApp…" onClick={() => void sendText()}>Text only</Button>
        <p className="el-caption el-secondary">The text of the view you are reading.</p>
        {hasAudio && (
          <>
            <Button disabled={!text} aria-disabled={busy} loading={working === "voice"} loadingLabel="Sending…" onClick={() => void sendVoice()}>Text and voice</Button>
            <p className="el-caption el-secondary">The text and the voice, ready for WhatsApp.</p>
          </>
        )}
        <Button disabled={loading} aria-disabled={busy} loading={working === "link"} loadingLabel="Making the link…" onClick={() => void sendLink()}>Link (works for 7 days)</Button>
        <p className="el-caption el-secondary">Anyone with it can read{hasAudio ? " and listen" : ""} for 7 days.</p>
      </div>
    </Dialog>
  );
}

/**
 * Change language & re-transcribe: the language the recording is in, then a new transcript in it. The current one stays
 * until the new one is ready. "English only" on a recording with Urdu in it is said once (the engine would translate).
 */
export function LanguageDialog({ item, onClose, say }: { item: LibraryItem; onClose: () => void; say: Say }) {
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
      say(`Re-transcribing in ${languageLabel(language || null)}. The current transcript stays until the new one is ready.`);
      onClose();
    } catch (e) {
      sayProblem(say, errorMessage(e));
      setBusy(false);
    }
  };
  return (
    <Dialog open title={CHANGE_LANGUAGE.replace("…", "")} onClose={onClose}
      description={warned ? "English only would translate the Urdu in this recording into English. Choose Urdu (with English words) to keep what was said." : "The current transcript stays until the new one is ready."}
      actions={<><Button onClick={onClose}>Cancel</Button>
        <Button variant="primary" loading={busy} loadingLabel="Starting…" onClick={() => void run()}>{warned ? "Re-transcribe anyway" : "Re-transcribe"}</Button></>}>
      <Select label="Language" options={options} value={language} onChange={(v) => { setLanguage(v); setWarned(false); }} />
    </Dialog>
  );
}
