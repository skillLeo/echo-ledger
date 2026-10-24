import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type LibraryItem, type TranscriptDetail } from "../../lib/ipc";
import { formatDuration, formatWhen, timestamp, transcriptText } from "../../lib/format";
import { SourceAppBadge, SourceDeviceBadge } from "../../components/SourceBadge";
import { CopyIcon, DesktopIcon, GlobeIcon, PhoneIcon, RefreshIcon, TrashIcon } from "../../components/Icons";

type SourceFilter = "all" | "phone" | "desktop" | "web";

export function filterItems(items: LibraryItem[], source: SourceFilter): LibraryItem[] {
  return items.filter((i) => !i.deleted && (source === "all" || i.source_device === source));
}

/** Unified library: every recording from every device (phone items show here too), labeled icon + text. */
export function LibraryView({ notify }: { notify: (t: string, e?: boolean) => void }) {
  const [items, setItems] = useState<LibraryItem[]>([]);
  const [source, setSource] = useState<SourceFilter>("all");
  const [selected, setSelected] = useState<string | null>(null);
  const [transcript, setTranscript] = useState<TranscriptDetail | null>(null);
  const [playbackUrl, setPlaybackUrl] = useState<string | null>(null);
  const [activeSegment, setActiveSegment] = useState(-1);
  const audioRef = useRef<HTMLAudioElement | null>(null);
  const [loading, setLoading] = useState(false);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      setItems(await api.refreshLibrary());
    } catch (e) {
      notify(errorMessage(e), true);
      setItems(await api.library().catch(() => []));
    } finally {
      setLoading(false);
    }
  }, [notify]);

  useEffect(() => {
    api.library().then(setItems).catch(() => undefined);
    refresh();
    const un = listen("library:updated", () => api.library().then(setItems).catch(() => undefined));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const visible = useMemo(() => filterItems(items, source), [items, source]);
  const current = visible.find((i) => i.id === selected) ?? null;

  useEffect(() => {
    setTranscript(null);
    setPlaybackUrl(null);
    setActiveSegment(-1);
    const id = current?.transcript?.final_transcript_id ?? current?.transcript?.live_transcript_id;
    if (id) api.transcript(id).then(setTranscript).catch(() => setTranscript(null));
    // Temporary signed URL to the decrypted recording; the server streams it with Range support.
    if (current) api.session(current.id).then((d) => setPlaybackUrl(typeof d.playback_url === "string" ? d.playback_url : null)).catch(() => setPlaybackUrl(null));
  }, [current?.id, current?.transcript?.final_transcript_id, current?.transcript?.live_transcript_id]);

  const copy = async (withTimestamps: boolean) => {
    if (!transcript) return;
    try {
      await navigator.clipboard.writeText(transcriptText(transcript.segments, withTimestamps));
      notify(withTimestamps ? "Copied with timestamps" : "Copied");
    } catch {
      notify("Could not access the clipboard", true);
    }
  };

  const seek = (ms: number) => {
    const audio = audioRef.current;
    if (!audio) return;
    audio.currentTime = ms / 1000;
    void audio.play().catch(() => undefined);
  };

  const onTimeUpdate = () => {
    const audio = audioRef.current;
    if (!audio || !transcript) return;
    const t = audio.currentTime * 1000;
    let index = -1;
    for (let i = 0; i < transcript.segments.length; i++) {
      if (transcript.segments[i].start_ms <= t) index = i;
      else break;
    }
    setActiveSegment(index);
  };

  const remove = async (id: string) => {
    if (!window.confirm("Delete this recording and transcript on every device? This cannot be undone.")) return;
    try {
      await api.deleteSession(id);
      setSelected(null);
      setItems(await api.library());
      notify("Deleted");
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  return (
    <>
      <div className="row">
        <div>
          <h1 className="page-title">Library</h1>
          <p className="page-sub">All recordings from your phone, this computer and the web.</p>
        </div>
        <div className="spacer" />
        <button className="btn" onClick={refresh} disabled={loading}><RefreshIcon size={16} />{loading ? "Syncing…" : "Sync"}</button>
      </div>
      <div className="chips" role="tablist">
        {([["all", "All", null], ["phone", "Phone", <PhoneIcon key="p" size={13} />], ["desktop", "Desktop", <DesktopIcon key="d" size={13} />], ["web", "Web", <GlobeIcon key="w" size={13} />]] as const).map(([key, label, icon]) => (
          <button key={key} className={`chip ${source === key ? "active" : ""}`} onClick={() => setSource(key)}>{icon}{label}</button>
        ))}
      </div>
      <div className="library">
        <div className="list" data-testid="library-list">
          {visible.length === 0 && <div className="empty">No recordings yet.</div>}
          {visible.map((item) => (
            <button key={item.id} className={`item ${item.id === selected ? "selected" : ""}`} onClick={() => setSelected(item.id)}>
              <div className="row">
                <SourceDeviceBadge source={item.source_device} label={item.source_device_label} />
                <SourceAppBadge app={item.source_app} label={item.source_app_label} />
                <span className="spacer" />
                <span className="small muted">{formatDuration(item.duration_ms)}</span>
              </div>
              <div className="item-title">{item.title}</div>
              <div className="small muted">{formatWhen(item.started_at)}{item.client ? ` · ${item.client.name}` : ""}{item.device ? ` · ${item.device.name}` : ""}</div>
              {item.transcript?.preview && <div className="item-preview">{item.transcript.preview}</div>}
              {!item.transcript?.preview && <div className="small muted">{item.status === "completed" ? "Transcript ready" : `Status: ${item.status}`}</div>}
            </button>
          ))}
        </div>
        <div className="detail card">
          {!current && <div className="empty">Select a recording to read its transcript.</div>}
          {current && (
            <>
              <div className="row">
                <SourceDeviceBadge source={current.source_device} label={current.source_device_label} />
                <SourceAppBadge app={current.source_app} label={current.source_app_label} />
                <span className="spacer" />
                <button className="btn danger ghost" onClick={() => remove(current.id)}><TrashIcon size={15} />Delete</button>
              </div>
              <h2 style={{ margin: "12px 0 2px" }}>{current.title}</h2>
              <div className="small muted">{formatWhen(current.started_at)} · {formatDuration(current.duration_ms)}{current.client ? ` · ${current.client.name}` : ""}</div>
              {playbackUrl && (
                <audio ref={audioRef} className="player" controls preload="metadata" src={playbackUrl} onTimeUpdate={onTimeUpdate} onSeeked={onTimeUpdate} data-testid="player" />
              )}
              {transcript ? (
                transcript.status === "failed" ? (
                  <div className="notice error" style={{ marginTop: 12 }}>
                    <strong>Transcription failed.</strong> {transcript.error ?? "The speech provider could not process this recording."}
                  </div>
                ) : (
                  <>
                    <div className="row" style={{ marginTop: 10 }}>
                      <span className="small muted">{transcript.kind === "final" ? `Final transcript · ${transcript.provider}` : "Live captions (final transcript pending)"}</span>
                      <span className="spacer" />
                      <button className="btn ghost" onClick={() => copy(false)} disabled={transcript.segments.length === 0}><CopyIcon size={14} />Copy text</button>
                      <button className="btn ghost" onClick={() => copy(true)} disabled={transcript.segments.length === 0}><CopyIcon size={14} />Copy with timestamps</button>
                    </div>
                    {transcript.segments.length === 0 && <div className="empty">The transcript is empty: no speech was detected.</div>}
                    <div className="segments">
                      {transcript.segments.map((s, i) => (
                        <div key={i} className={`segment ${i === activeSegment ? "active" : ""} ${playbackUrl ? "seekable" : ""}`} onClick={() => seek(s.start_ms)} role={playbackUrl ? "button" : undefined}>
                          <span className="seg-time">{timestamp(s.start_ms)}</span>
                          <span className={`seg-spk spk-${s.channel === 2 ? 2 : 1}`}>{s.speaker}</span>
                          <span>{s.text}</span>
                        </div>
                      ))}
                    </div>
                  </>
                )
              ) : (
                <div className="empty">{current.transcript?.final_status ? `Transcript ${current.transcript.final_status.replace("_", " ")}` : "Transcript not available yet."}</div>
              )}
            </>
          )}
        </div>
      </div>
    </>
  );
}
