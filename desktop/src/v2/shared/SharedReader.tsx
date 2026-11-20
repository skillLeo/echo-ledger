// A recording a teammate sent, open read-only (classic opens it in the Library's Shared with me, LibraryView's
// readOnly): who sent it, when and their note, the one player and the transcript with speakers. Nothing here changes
// it; Save to my library makes a copy that can be changed, and Remove takes it off Shared with me. Same commands as
// classic: session_detail for the audio and speaker names, transcript_detail for the text.
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, type SharedItem, type TranscriptDetail } from "../../lib/ipc";
import { formatDuration } from "../../lib/format";
import { activeIndex, speakerName } from "../../lib/player";
import type { Notify } from "../../lib/notice";
import { sentLine } from "../../views/main/SharedWithMe";
import { Badge, Banner, Button, Card, EmptyState, Icons, Loading, Player, Skeleton, Transcript, type Segment } from "../components";
import { Menu } from "../components/Menu";
import { SourceAvatar } from "../library/SourceAvatar";
import { sharedTitle } from "./model";

type Load<T> = { state: "loading" } | { state: "error"; message: string } | { state: "ready"; value: T };

/** The words under the sender's line: what this view lets you do. */
export const READ_ONLY_LINE = "You can play and read it. Save to my library makes a copy you can change.";

export function SharedReader({ item, busy, notify, onBack, onSave, onRemove }: {
  item: SharedItem;
  busy: boolean;
  notify: Notify;
  onBack: () => void;
  onSave: () => void;
  onRemove: () => void;
}) {
  const session = item.session!;
  const title = sharedTitle(item);
  const transcriptId = session.transcript?.final_transcript_id ?? session.transcript?.live_transcript_id ?? null;
  const [detail, setDetail] = useState<Load<{ url: string | null; names: Record<string, string> }>>({ state: "loading" });
  const [transcript, setTranscript] = useState<Load<TranscriptDetail | null>>({ state: "loading" });
  const [attempt, setAttempt] = useState(0);
  const [playing, setPlaying] = useState(false);
  const [position, setPosition] = useState(0);
  const [mediaSeconds, setMediaSeconds] = useState<number | null>(null);
  const audio = useRef<HTMLAudioElement>(null);

  useEffect(() => {
    let live = true;
    setDetail({ state: "loading" });
    setTranscript(transcriptId ? { state: "loading" } : { state: "ready", value: null });
    api.session(session.id).then((d) => {
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
  }, [session.id, transcriptId, attempt]);

  const names = detail.state === "ready" ? detail.value.names : {};
  const segments: Segment[] = transcript.state === "ready" && transcript.value
    ? transcript.value.segments.map((s) => ({ speaker: speakerName(names, s.speaker), start: s.start_ms / 1000, text: s.text }))
    : [];
  const durationS = Math.max(1, Math.round(mediaSeconds && Number.isFinite(mediaSeconds) ? mediaSeconds : (session.duration_ms ?? 0) / 1000));
  const active = transcript.state === "ready" && transcript.value && (playing || position > 0) ? activeIndex(transcript.value.segments, position * 1000) : -1;
  const url = detail.state === "ready" ? detail.value.url : null;

  const toggle = () => {
    const a = audio.current;
    if (!a) return;
    if (a.paused) void a.play().catch(() => notify("The audio didn't play. Try again in a moment.", true));
    else a.pause();
  };
  const seek = (s: number) => {
    const to = Math.max(0, Math.min(durationS, s));
    setPosition(to);
    if (audio.current) audio.current.currentTime = to;
  };
  const playFrom = (i: number) => {
    seek(segments[i]?.start ?? 0);
    void audio.current?.play().catch(() => undefined);
  };

  if (detail.state === "error" && transcript.state !== "ready") {
    return (
      <div className="el-page" data-v2="shared-reader">
        <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>Shared</Button>
        <EmptyState tone="error" icon={Icons.ErrorIcon} title="This recording didn't open" body={detail.message}
          action={<Button variant="primary" onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
      </div>
    );
  }

  return (
    <div className="el-page" data-v2="shared-reader">
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>Shared</Button>
      <header className="el-page__head el-page__head--top">
        <div className="el-page__titles">
          <h1 className="el-title-l" dir="auto">{title}</h1>
          <p className="el-page__meta el-secondary">
            <SourceAvatar item={session} size="sm" />
            <span dir="auto">{[sentLine(item), formatDuration(session.duration_ms) || null].filter(Boolean).join(" · ")}</span>
            <Badge>Read-only</Badge>
            {item.saved_at && <Badge tone="success">Saved to my library</Badge>}
          </p>
        </div>
        <div className="el-page__actions">
          {!item.saved_at && <Button variant="primary" icon={Icons.DownloadIcon} loading={busy} onClick={onSave}>Save to my library</Button>}
          <Menu label="More for this recording" button={{ variant: "quiet", icon: Icons.MoreIcon, title: "More" }}
            items={[{ label: "Remove", icon: Icons.TrashIcon, danger: true, disabled: busy, onPick: onRemove }]} />
        </div>
      </header>

      <Banner icon={Icons.InfoIcon}>
        {item.note && <><span dir="auto">“{item.note}”</span>{" "}</>}{READ_ONLY_LINE}
      </Banner>

      <Card className="el-page__player">
        {detail.state === "loading" ? (
          <Loading label="Loading the audio"><Skeleton shape="block" /></Loading>
        ) : (
          <>
            {url && (
              <audio ref={audio} src={url} preload="metadata" onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)}
                onLoadedMetadata={(e) => setMediaSeconds(e.currentTarget.duration)} onEnded={() => setPlaying(false)}
                onTimeUpdate={(e) => setPosition(e.currentTarget.currentTime)} />
            )}
            <Player playing={playing} position={Math.round(position)} duration={durationS} onToggle={toggle} onSeek={seek} onSkip={(s) => seek(position + s)} />
          </>
        )}
      </Card>

      <section className="el-group" aria-label="Transcript">
        {transcript.state === "loading" ? (
          <Loading label="Loading the transcript"><Card><Skeleton /><Skeleton width="long" /><Skeleton width="medium" /></Card></Loading>
        ) : transcript.state === "error" ? (
          <EmptyState tone="error" icon={Icons.ErrorIcon} title="The transcript didn't load" body={transcript.message}
            action={<Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
        ) : segments.length > 0 ? (
          <Card><Transcript label="Transcript" segments={segments} activeIndex={active >= 0 ? active : undefined} onPlayLine={url ? playFrom : undefined} /></Card>
        ) : (
          <EmptyState icon={Icons.TranscriptIcon} title="No transcript yet" body="It shows here once the sender's copy is transcribed." />
        )}
      </section>
    </div>
  );
}
