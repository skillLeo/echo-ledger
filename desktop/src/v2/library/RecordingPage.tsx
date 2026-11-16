// The v2 recording page (ui_v2.library): title, the one player, the transcript with speakers, and the actions. The
// data are today's: session_detail for the audio and speaker names, transcript_detail for the text.
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, type LibraryItem, type TranscriptDetail } from "../../lib/ipc";
import { itemState } from "../../lib/library";
import { formatDuration, formatWhen, sourceAppLabel, transcriptText } from "../../lib/format";
import { activeIndex, speakerName } from "../../lib/player";
import { Badge, Button, Card, EmptyState, Icons, Loading, Player, Select, Skeleton, Tabs, Transcript, type Segment } from "../components";

type Tab = "transcript" | "details";
type Load<T> = { state: "loading" } | { state: "error"; message: string } | { state: "ready"; value: T };

export type RecordingPageProps = {
  item: LibraryItem;
  onBack: () => void;
  /** Delete: the Library moves it to Trash and offers Undo. */
  onDelete: (item: LibraryItem) => void;
  /** One message at a time, with at most one action (the Library's Toast). */
  onMessage: (message: string) => void;
};

const SPEEDS = ["1", "1.25", "1.5", "2"];

export function RecordingPage({ item, onBack, onDelete, onMessage }: RecordingPageProps) {
  const [tab, setTab] = useState<Tab>("transcript");
  const [detail, setDetail] = useState<Load<{ url: string | null; names: Record<string, string> }>>({ state: "loading" });
  const [transcript, setTranscript] = useState<Load<TranscriptDetail | null>>({ state: "loading" });
  const [attempt, setAttempt] = useState(0);
  const [playing, setPlaying] = useState(false);
  const [position, setPosition] = useState(0);
  const [speed, setSpeed] = useState("1");
  const [busy, setBusy] = useState(false);
  const audio = useRef<HTMLAudioElement>(null);
  const state = itemState(item);
  const transcriptId = item.transcript?.final_transcript_id ?? item.transcript?.live_transcript_id ?? null;

  useEffect(() => {
    let live = true;
    setDetail({ state: "loading" });
    setTranscript(transcriptId ? { state: "loading" } : { state: "ready", value: null });
    api.session(item.id).then((d) => {
      if (!live) return;
      const names = d.speaker_names;
      setDetail({
        state: "ready",
        value: {
          url: typeof d.playback_url === "string" ? d.playback_url : null,
          names: names && typeof names === "object" && !Array.isArray(names) ? (names as Record<string, string>) : {},
        },
      });
    }).catch((e) => live && setDetail({ state: "error", message: errorMessage(e) }));
    if (transcriptId) {
      api.transcript(transcriptId).then((t) => live && setTranscript({ state: "ready", value: t }))
        .catch((e) => live && setTranscript({ state: "error", message: errorMessage(e) }));
    }
    return () => {
      live = false;
    };
  }, [item.id, transcriptId, attempt]);

  useEffect(() => {
    if (audio.current) audio.current.playbackRate = Number(speed);
  }, [speed]);

  const durationS = Math.max(1, Math.round((item.duration_ms ?? 0) / 1000));
  const names = detail.state === "ready" ? detail.value.names : {};
  const segments: Segment[] = transcript.state === "ready" && transcript.value
    ? transcript.value.segments.map((s) => ({ speaker: speakerName(names, s.speaker), start: s.start_ms / 1000, text: s.text }))
    : [];
  const active = transcript.state === "ready" && transcript.value ? activeIndex(transcript.value.segments, position * 1000) : -1;
  const source = item.source_app_label ?? (item.source_app ? sourceAppLabel(item.source_app) : null);
  const meta = [source, item.client?.name, formatWhen(item.started_at)].filter(Boolean).join(" · ");

  const toggle = () => {
    const a = audio.current;
    if (!a) return;
    if (a.paused) void a.play().catch(() => onMessage("The audio didn't play. Try again in a moment."));
    else a.pause();
  };
  const seek = (s: number) => {
    setPosition(s);
    if (audio.current) audio.current.currentTime = s;
  };
  const act = async (fn: () => Promise<unknown>, done?: string) => {
    setBusy(true);
    try {
      await fn();
      if (done) onMessage(done);
    } catch (e) {
      onMessage(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  if (detail.state === "error" && transcript.state !== "ready") {
    return (
      <div className="el-page">
        <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>Library</Button>
        <EmptyState tone="error" icon={Icons.ErrorIcon} title="This recording didn't open" body={detail.message}
          action={<Button variant="primary" onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
      </div>
    );
  }

  return (
    <div className="el-page">
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>Library</Button>
      <header className="el-page__head el-page__head--top">
        <div className="el-page__titles">
          <h1 className="el-title-l" dir="auto">{item.title?.trim() || "Recording"}</h1>
          <p className="el-page__meta el-secondary">
            <span dir="auto">{meta}</span>
            {state === "ready" ? <Badge tone="success">Transcribed</Badge>
              : state === "failed" ? <Badge tone="danger">Transcription failed</Badge>
                : state === "no_audio" ? <Badge>Nothing was heard</Badge>
                  : item.transcript?.final_status ? <Badge tone="accent">Transcribing…</Badge> : <Badge tone="warning">Untranscribed</Badge>}
          </p>
        </div>
        <div className="el-page__actions">
          {segments.length > 0 && (
            <Button icon={Icons.ShareIcon} disabled={busy} onClick={() => void act(() => api.whatsappOpen(transcriptText(segments.map((s) => ({ speaker: s.speaker, start_ms: s.start * 1000, text: s.text })), false)))}>
              Send to WhatsApp
            </Button>
          )}
          <Button icon={Icons.StorageIcon} disabled={busy} onClick={() => void act(async () => { if (await api.saveAudio(item.id)) onMessage("Audio saved"); })}>Save audio</Button>
          <Button variant="danger" icon={Icons.TrashIcon} disabled={busy} onClick={() => onDelete(item)}>Delete</Button>
        </div>
      </header>
      <Card className="el-page__player">
        {detail.state === "loading" ? (
          <Loading label="Loading the audio"><Skeleton shape="block" /></Loading>
        ) : (
          <>
            {detail.state === "ready" && detail.value.url && (
              <audio ref={audio} src={detail.value.url} preload="metadata" onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)}
                onEnded={() => setPlaying(false)} onTimeUpdate={(e) => setPosition(e.currentTarget.currentTime)} />
            )}
            <Player playing={playing} position={Math.round(position)} duration={durationS} onToggle={toggle} onSeek={seek}
              end={<Select label="Playback speed" hideLabel value={speed} onChange={setSpeed} className="el-page__speed"
                options={SPEEDS.map((v) => ({ value: v, label: `${v}×`, dir: "ltr" as const }))} />} />
          </>
        )}
      </Card>
      <Tabs label="Recording" value={tab} onChange={setTab} tabs={[{ value: "transcript", label: "Transcript" }, { value: "details", label: "Details" }]}>
        {tab === "transcript" ? (
          transcript.state === "loading" ? (
            <Loading label="Loading the transcript">
              {Array.from({ length: 4 }, (_, i) => <Skeleton key={i} width={i % 2 ? "medium" : "long"} />)}
            </Loading>
          ) : transcript.state === "error" ? (
            <EmptyState tone="error" icon={Icons.ErrorIcon} title="The transcript didn't load" body={transcript.message}
              action={<Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
          ) : segments.length > 0 ? (
            <Transcript label="Transcript" segments={segments} activeIndex={active >= 0 ? active : undefined} />
          ) : state === "processing" && item.transcript?.final_status ? (
            <EmptyState icon={Icons.TranscriptIcon} title="Transcribing" body="The transcript appears here when it is ready. You can keep working meanwhile." />
          ) : state === "no_audio" ? (
            <EmptyState icon={Icons.MicIcon} title="Nothing was heard" body="The recording has no sound to transcribe. Check the microphone and system audio before the next call." />
          ) : (
            <EmptyState icon={Icons.TranscriptIcon} title={state === "failed" ? "The transcription failed" : "Not transcribed yet"}
              body={`Transcribe this recording to read, search and share what was said.${item.duration_ms ? ` It is ${formatDuration(item.duration_ms)} long.` : ""}`}
              action={<Button variant="primary" loading={busy} loadingLabel="Starting…"
                onClick={() => void act(() => api.retranscribe(item.id, null), "Transcription started")}>Transcribe</Button>} />
          )
        ) : (
          <dl className="el-details">
            <dt>Recorded</dt><dd>{formatWhen(item.started_at) || "Unknown"}</dd>
            <dt>Length</dt><dd className="el-num">{formatDuration(item.duration_ms) || "Unknown"}</dd>
            {source && <><dt>Source</dt><dd>{source}</dd></>}
            {item.device?.name && <><dt>Device</dt><dd>{item.device.name}</dd></>}
            {item.client?.name && <><dt>Project</dt><dd>{item.client.name}</dd></>}
            {item.language_chip && <><dt>Language</dt><dd>{item.language_chip}</dd></>}
          </dl>
        )}
      </Tabs>
    </div>
  );
}
