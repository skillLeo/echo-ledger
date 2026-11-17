// What a recording, or several picked ones, can have done to it in the v2 Library: the shared ⋮ menu (one place for
// every action, grouped Organise · Export · Danger, Delete last and red), the Export ▸ formats, Copy for AI, and the
// dialogs behind Move, Label, Rename and Transcribe. Selection mode, the row ⋮, the right-click menu and the recording
// page all build from here, so an action is in the same place everywhere (PLAN_V2_PARITY 3, 4 and 7).
import { useEffect, useId, useRef, useState } from "react";
import { api, errorMessage, type BulkExportFormat, type ClientFolder, type LibraryItem, type TranscribeEstimate, type TranscriptionLanguage } from "../../lib/ipc";
import { englishOnlyRisk, languageLabel, MAIN_LANGUAGES, menuLabel, MORE_LANGUAGES } from "../../lib/languages";
import { transcriptText, copiedTranscript, DEFAULT_UNSURE, formatDuration, formatTime, sentenceDate, sourceAppLabel } from "../../lib/format";
import { speakerName } from "../../lib/player";
import { projectLabel } from "../../lib/projects";
import { MAX_LABEL_CHARS } from "../../lib/organise";
import { MAX_TITLE_CHARS } from "../../lib/importFiles";
import { moveRecordings, movedText } from "../../views/main/ProjectDialogs";
import { ATTACH_NOTE, fileName, linkMessage, WHATSAPP_LINK_SECONDS } from "../../views/main/WhatsAppSheet";
import { Button, Dialog, Icons, Select, TextField } from "../components";
import type { MenuItem } from "../components/Menu";

/** Every format the core exports, in classic's words and order (one zip for several recordings). */
export const EXPORT_FORMATS: readonly { format: BulkExportFormat; label: string }[] = [
  { format: "docx", label: "Word (.docx)" },
  { format: "txt", label: "Text (.txt)" },
  { format: "md", label: "Markdown (.md)" },
  { format: "srt", label: "Subtitles (.srt)" },
  { format: "vtt", label: "Web subtitles (.vtt)" },
  { format: "json", label: "JSON" },
];

/** The Export ▸ sub-menu: one item per format. */
export function exportMenu(run: (format: BulkExportFormat) => void, group = "Export"): MenuItem {
  return {
    label: "Export", icon: Icons.DownloadIcon, group,
    items: EXPORT_FORMATS.map(({ format, label }) => ({ label, onPick: () => run(format) })),
  };
}

export type Say = (text: string, action?: { label: string; run: () => void }) => void;

/** Saves the transcripts of `ids` as one zip in `format` where the person chooses; says where, or nothing on cancel. */
export async function exportRecordings(ids: readonly string[], format: BulkExportFormat, say: Say): Promise<void> {
  try {
    const path = await api.exportSessions([...ids], format);
    if (path) say(`Saved ${path}`);
  } catch (e) {
    say(errorMessage(e));
  }
}

/** A recording's transcript lines with the speakers' names, or null when it has none yet. */
export async function recordingLines(item: LibraryItem): Promise<{ speaker: string; start_ms: number; text: string; words?: { text: string; confidence?: number | null }[] }[] | null> {
  const id = item.transcript?.final_transcript_id ?? item.transcript?.live_transcript_id ?? null;
  if (!id) return null;
  const [detail, transcript] = await Promise.all([api.session(item.id).catch(() => ({} as Record<string, unknown>)), api.transcript(id)]);
  const raw = detail.speaker_names;
  const names = raw && typeof raw === "object" && !Array.isArray(raw) ? (raw as Record<string, string>) : {};
  return transcript.segments.map((s) => ({ ...s, speaker: speakerName(names, s.speaker) }));
}

/** The short header Copy for AI puts above a recording: title, date, people, language. */
export function aiHeader(item: LibraryItem, people: readonly string[]): string {
  const source = item.source_app_label ?? (item.source_app ? sourceAppLabel(item.source_app) : null);
  return [
    `# ${item.title?.trim() || "Recording"}`,
    [item.started_at ? `${sentenceDate(item.started_at)}, ${formatTime(new Date(item.started_at))}` : null, source, item.duration_ms ? formatDuration(item.duration_ms) : null].filter(Boolean).join(" · "),
    people.length ? `People: ${people.join(", ")}` : null,
    item.language_chip || item.language ? `Language: ${item.language_chip || item.language}` : null,
  ].filter(Boolean).join("\n");
}

/**
 * Copy for AI for one or several recordings: the note on unsure words once, then each recording's header and its
 * lines with timestamps. Recordings without a transcript are left out and counted.
 */
export async function copyForAi(items: readonly LibraryItem[]): Promise<{ text: string; copied: number; skipped: number }> {
  const parts: string[] = [];
  let skipped = 0;
  for (const item of items) {
    const lines = await recordingLines(item);
    if (!lines?.length) {
      skipped++;
      continue;
    }
    const people = [...new Set(lines.map((l) => l.speaker))];
    const body = copiedTranscript(lines, () => null, true, { ...DEFAULT_UNSURE, note: false }, false);
    parts.push(`${aiHeader(item, people)}\n\n${body}`);
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
    say(e instanceof Error && /clipboard/i.test(e.message) ? "Could not access the clipboard" : errorMessage(e));
  }
}

/** "1 recording", "3 recordings". */
export const recordingsWord = (n: number) => (n === 1 ? "1 recording" : `${n} recordings`);

/** Moves recordings to a project, the Inbox, or a new project made here. */
export function MoveDialog({ ids, title, current, onClose, say }: {
  ids: readonly string[];
  /** The recording's title when one is moved. */
  title?: string | null;
  /** The project they are in now (null: the Inbox; undefined: not all the same). */
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
    ...(current === null ? [] : [{ value: "inbox", label: "Inbox (no project)" }]),
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
      say(movedText(ids.length, title, list, projectId));
      onClose();
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  };
  const ready = target !== "" && (target !== "new" || name.trim() !== "");
  return (
    <Dialog open title={ids.length === 1 ? `Move “${title || "recording"}”` : `Move ${recordingsWord(ids.length)}`} onClose={onClose}
      description={error ?? undefined}
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
      say(errorMessage(e));
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
  const [title, setTitle] = useState(item.title ?? "");
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const save = async () => {
    const text = title.trim();
    if (text === (item.title ?? "").trim()) {
      onClose();
      return;
    }
    setBusy(true);
    try {
      const answer = await api.organiseSession(item.id, { title: text || null });
      if (answer?.data) onRenamed?.(answer.data);
      say(text ? "Renamed" : "The automatic title will be used");
      onClose();
    } catch (e) {
      say(errorMessage(e));
      setBusy(false);
    }
  };
  return (
    <Dialog open title="Rename" onClose={onClose} initialFocus={input}
      actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} loadingLabel="Saving…" onClick={() => void save()}>Save</Button></>}>
      <form onSubmit={(e) => { e.preventDefault(); void save(); }}>
        <TextField ref={input} label="Title" maxLength={MAX_TITLE_CHARS} value={title} onChange={(e) => setTitle(e.target.value)}
          onFocus={(e) => e.currentTarget.select()} hint="Leave it empty for an automatic title from what was said." />
      </form>
    </Dialog>
  );
}

/** Transcribe: the minutes it will use and the minutes left this month first, then it starts. */
export function TranscribeDialog({ ids, onClose, say }: { ids: readonly string[]; onClose: () => void; say: Say }) {
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
  const minutes = (n: number) => (n === 1 ? "1 minute" : `${n} minutes`);
  const description = error
    ?? (!estimate ? "Working out the minutes…"
      : `About ${minutes(estimate.minutes)}${estimate.minutes_left === null ? "." : ` of the ${minutes(estimate.minutes_left)} left this month.`}`
        + (over ? " That is more than is left, so nothing will start. Pick fewer recordings." : ""));
  return (
    <Dialog open title={ids.length === 1 ? "Transcribe this recording?" : `Transcribe ${recordingsWord(ids.length)}?`} description={description} onClose={onClose}
      actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" disabled={!estimate || over} loading={busy} loadingLabel="Starting…" onClick={() => void start()}>Transcribe</Button></>} />
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
 * the text with the voice (saved in Downloads to attach), or a link that works for 7 days. The text always goes on the
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
  const [saved, setSaved] = useState<string | null>(null);
  useEffect(() => {
    if (given === undefined) recordingLines(item).then((l) => setText(l?.length ? transcriptText(l, false) : null)).catch(() => setText(null));
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
  const run = async (kind: "text" | "voice" | "link", fn: () => Promise<void>) => {
    if (working) return;
    setWorking(kind);
    try {
      await fn();
    } catch (e) {
      say(errorMessage(e));
    } finally {
      setWorking(null);
    }
  };
  const sendText = () => run("text", async () => {
    if (!text) return;
    const cut = await open(text, await copy(text));
    if (!cut) say("Opening WhatsApp with the text");
    onClose();
  });
  const sendVoice = () => run("voice", async () => {
    if (!text) return;
    const path = await api.saveAudioToDownloads(item.id);
    await open(text, await copy(text));
    setSaved(path);
  });
  const sendLink = () => run("link", async () => {
    const link = await api.createShare(item.id, WHATSAPP_LINK_SECONDS, null, !!hasAudio);
    if (!link.url) throw new Error("The server made the link but did not send it. Try again.");
    await copy(link.url);
    await open(linkMessage(item.title ?? null, link.url), false);
    say("Link made (it works for 7 days) and copied");
    onClose();
  });
  const loading = text === undefined || hasAudio === undefined;
  if (saved) {
    return (
      <Dialog open title="Send to WhatsApp" onClose={onClose} actions={<Button variant="primary" onClick={onClose}>Done</Button>}
        description={`Saved in Downloads: ${fileName(saved)}`}>
        <p><b>{ATTACH_NOTE}</b>: in the chat, click <b>+</b> (or the paper clip), then <b>Document</b>, and choose this file. The text is in the message, and copied too.</p>
      </Dialog>
    );
  }
  return (
    <Dialog open title="Send to WhatsApp" onClose={onClose}
      description={text === null ? "There is no transcript yet: only the link can be sent." : "Opens WhatsApp, or WhatsApp Web, with the message ready to send to the person you choose."}
      actions={<Button onClick={onClose}>Cancel</Button>}>
      <div className="el-choices">
        <Button variant="primary" disabled={!text || !!working} loading={working === "text"} loadingLabel="Opening WhatsApp…" onClick={() => void sendText()}>Text only</Button>
        <p className="el-caption el-secondary">The text of the view you are reading.</p>
        {hasAudio && (
          <>
            <Button disabled={!text || !!working} loading={working === "voice"} loadingLabel="Saving the voice…" onClick={() => void sendVoice()}>Text and voice</Button>
            <p className="el-caption el-secondary">The text in the message; the voice saved in Downloads to attach.</p>
          </>
        )}
        <Button disabled={loading || !!working} loading={working === "link"} loadingLabel="Making the link…" onClick={() => void sendLink()}>Link (works for 7 days)</Button>
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
      say(errorMessage(e));
      setBusy(false);
    }
  };
  return (
    <Dialog open title="Change language and re-transcribe" onClose={onClose}
      description={warned ? "English only would translate the Urdu in this recording into English. Choose Urdu (with English words) to keep what was said." : "The current transcript stays until the new one is ready."}
      actions={<><Button onClick={onClose}>Cancel</Button>
        <Button variant="primary" loading={busy} loadingLabel="Starting…" onClick={() => void run()}>{warned ? "Re-transcribe anyway" : "Re-transcribe"}</Button></>}>
      <Select label="Language" options={options} value={language} onChange={(v) => { setLanguage(v); setWarned(false); }} />
    </Dialog>
  );
}
