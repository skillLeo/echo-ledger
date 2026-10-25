import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type LibraryItem, type TranscriptDetail } from "../../lib/ipc";
import { formatDuration, formatWhen, timestamp, transcriptText } from "../../lib/format";
import { groupItems, itemState, searchItems, SORT_LABELS, sortItems, type SortKey } from "../../lib/library";
import { SourceAppBadge, SourceDeviceBadge } from "../../components/SourceBadge";
import { CopyIcon, DesktopIcon, GlobeIcon, PhoneIcon, RefreshIcon, SearchIcon, SourceAppIcon, TrashIcon } from "../../components/Icons";
import { NO_AUDIO_HINT } from "../../lib/pending";
import { LibraryQueue, type PendingFocus } from "./LibraryQueue";

type SourceFilter = "all" | "phone" | "desktop" | "web";

export function filterItems(items: LibraryItem[], source: SourceFilter): LibraryItem[] {
  return items.filter((i) => !i.deleted && (source === "all" || i.source_device === source));
}

const STATE_LABEL = { ready: "Ready", processing: "Processing", failed: "Failed" } as const;

/** Transcript states in which the provider is still working: never shown as an empty transcript (A6.DU3). */
const IN_FLIGHT = new Set(["pending", "submitted", "processing"]);
const isTranscribing = (status?: string | null): boolean => !!status && IN_FLIGHT.has(status);

const PLAYBACK_EXPIRED = "Playback link expired. Open the recording again.";
const playbackUrlOf = (detail: Record<string, unknown>): string | null => (typeof detail.playback_url === "string" ? detail.playback_url : null);

/**
 * Unified library: every recording from every device, searchable by title and transcript text. Recordings waiting
 * for a decision and uploads that need the user sit at the top of the list; `focus` brings one of them into view.
 */
export function LibraryView({ notify, focus = null, onFocusHandled }: {
  notify: (t: string, e?: boolean) => void;
  focus?: PendingFocus | null;
  onFocusHandled?: () => void;
}) {
  const [items, setItems] = useState<LibraryItem[]>([]);
  const [source, setSource] = useState<SourceFilter>("all");
  const [query, setQuery] = useState("");
  const [sort, setSort] = useState<SortKey>("newest");
  const [selected, setSelected] = useState<string | null>(null);
  const [transcript, setTranscript] = useState<TranscriptDetail | null>(null);
  const [playbackUrl, setPlaybackUrl] = useState<string | null>(null);
  const [activeSegment, setActiveSegment] = useState(-1);
  const [playbackError, setPlaybackError] = useState<string | null>(null);
  const audioRef = useRef<HTMLAudioElement | null>(null);
  /** Bumped on every selection change; an answer that belongs to an older selection is dropped (A6.DU2). */
  const selection = useRef(0);
  const playbackRefreshed = useRef(false);
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

  const filtered = useMemo(() => sortItems(searchItems(filterItems(items, source), query), sort), [items, source, query, sort]);
  const groups = useMemo(() => groupItems(filtered, sort), [filtered, sort]);
  const current = filtered.find((i) => i.id === selected) ?? null;

  const finalStatus = current?.transcript?.final_status ?? null;
  const finalId = current?.transcript?.final_transcript_id ?? null;
  const liveId = current?.transcript?.live_transcript_id ?? null;
  useEffect(() => {
    const token = ++selection.current;
    const latest = () => token === selection.current;
    setTranscript(null);
    setPlaybackUrl(null);
    setPlaybackError(null);
    setActiveSegment(-1);
    playbackRefreshed.current = false;
    // While the final transcript is still being made, the live captions (when there are any) are worth reading.
    const id = (isTranscribing(finalStatus) && liveId) || finalId || liveId;
    if (id) api.transcript(id).then((t) => latest() && setTranscript(t)).catch(() => latest() && setTranscript(null));
    // Temporary signed URL to the decrypted recording; the server streams it with Range support.
    if (current) api.session(current.id).then((d) => latest() && setPlaybackUrl(playbackUrlOf(d))).catch(() => latest() && setPlaybackUrl(null));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [current?.id, finalId, liveId, finalStatus]);

  /** The signed playback URL lives 30 minutes (docs/API.md); a failed load asks for a fresh one once (A6.DU8). */
  const onPlaybackError = () => {
    if (!current) return;
    const token = selection.current;
    const failedUrl = playbackUrl;
    const expired = () => {
      if (token !== selection.current) return;
      setPlaybackUrl(null);
      setPlaybackError(PLAYBACK_EXPIRED);
    };
    if (playbackRefreshed.current) return expired();
    playbackRefreshed.current = true;
    api
      .session(current.id)
      .then((d) => {
        if (token !== selection.current) return;
        const next = playbackUrlOf(d);
        if (next && next !== failedUrl) setPlaybackUrl(next);
        else expired();
      })
      .catch(expired);
  };

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

  const total = items.filter((i) => !i.deleted).length;
  return (
    <>
      <div className="row">
        <div>
          <h1 className="page-title">Library</h1>
          <p className="page-sub">{total === 0 ? "Recordings from your phone, this computer and the web will appear here." : `${total} recording${total === 1 ? "" : "s"} from your phone, this computer and the web.`}</p>
        </div>
        <div className="spacer" />
        <button className="btn" onClick={refresh} disabled={loading}><RefreshIcon size={16} />{loading ? "Syncing…" : "Sync"}</button>
      </div>
      <div className="toolbar">
        <label className="searchbox">
          <SearchIcon size={16} />
          <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search titles and transcripts" aria-label="Search recordings" />
          {query && <button className="clear" onClick={() => setQuery("")} aria-label="Clear search">×</button>}
        </label>
        <select className="select" value={sort} onChange={(e) => setSort(e.target.value as SortKey)} aria-label="Sort recordings">
          {(Object.keys(SORT_LABELS) as SortKey[]).map((k) => <option key={k} value={k}>{SORT_LABELS[k]}</option>)}
        </select>
      </div>
      {/* Filter toggles, not tabs: a group of pressed / not pressed buttons (A6.DU7). */}
      <div className="chips" role="group" aria-label="Filter by device">
        {([["all", "All", null], ["phone", "Phone", <PhoneIcon key="p" size={13} />], ["desktop", "Desktop", <DesktopIcon key="d" size={13} />], ["web", "Web", <GlobeIcon key="w" size={13} />]] as const).map(([key, label, icon]) => (
          <button key={key} type="button" className={`chip ${source === key ? "active" : ""}`} aria-pressed={source === key} onClick={() => setSource(key)}>{icon}{label}</button>
        ))}
      </div>
      <div className="library">
        <div className="list" data-testid="library-list">
          <LibraryQueue notify={notify} focus={focus} onFocusHandled={onFocusHandled} />
          {filtered.length === 0 && (
            <div className="empty">{query || source !== "all" ? "Nothing matches. Try a different search or filter." : "No recordings yet."}</div>
          )}
          {groups.map((group) => (
            <div key={group.label ?? "all"} className="group">
              {group.label && <div className="group-label">{group.label}</div>}
              {group.items.map((item) => {
                const state = itemState(item);
                return (
                  <button key={item.id} type="button" className={`item ${item.id === selected ? "selected" : ""}`} aria-current={item.id === selected ? "true" : undefined} onClick={() => setSelected(item.id)}>
                    <div className="row">
                      <span className={`avatar ${item.source_device ?? "web"}`}><SourceAppIcon app={item.source_app} size={18} /></span>
                      <div className="item-main">
                        <div className="item-title">{item.title}</div>
                        <div className="small muted">{formatWhen(item.started_at)}{item.duration_ms ? ` · ${formatDuration(item.duration_ms)}` : ""}{item.client ? ` · ${item.client.name}` : ""}</div>
                      </div>
                      <span className={`pill ${state}`}>{STATE_LABEL[state]}</span>
                    </div>
                    {item.transcript?.preview && <div className="item-preview">{item.transcript.preview}</div>}
                  </button>
                );
              })}
            </div>
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
              <h2 className="detail-title">{current.title}</h2>
              <div className="small muted">{formatWhen(current.started_at)} · {formatDuration(current.duration_ms)}{current.client ? ` · ${current.client.name}` : ""}</div>
              {playbackUrl && (
                <audio ref={audioRef} className="player" controls preload="metadata" src={playbackUrl} onTimeUpdate={onTimeUpdate} onSeeked={onTimeUpdate} onError={onPlaybackError} data-testid="player" />
              )}
              {playbackError && <div className="notice" style={{ marginTop: 12 }}>{playbackError}</div>}
              {transcript ? (
                transcript.status === "failed" ? (
                  <div className="notice error" style={{ marginTop: 12 }}>
                    <strong>Transcription failed.</strong> {transcript.error ?? "The speech provider could not process this recording."}
                    {/* The server says what happened; the desktop knows what to check (docs/API.md `error_code`). */}
                    {transcript.error_code === "no_audio" && <div className="notice-hint">{NO_AUDIO_HINT}</div>}
                  </div>
                ) : isTranscribing(transcript.status) ? (
                  <div className="empty">Transcribing…</div>
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
                      {transcript.segments.map((s, i) => {
                        const cells = (
                          <>
                            <span className="seg-time">{timestamp(s.start_ms)}</span>
                            <span className={`seg-spk spk-${s.channel === 2 ? 2 : 1}`}>{s.speaker}</span>
                            <span className="seg-text">{s.text}</span>
                          </>
                        );
                        const className = `segment ${i === activeSegment ? "active" : ""}`;
                        const playing = i === activeSegment ? "true" : undefined;
                        // A row that seeks is a real button: it takes focus and Enter / Space activate it (A6.DU7).
                        return playbackUrl ? (
                          <button key={i} type="button" className={`${className} seekable`} aria-current={playing} onClick={() => seek(s.start_ms)}>{cells}</button>
                        ) : (
                          <div key={i} className={className}>{cells}</div>
                        );
                      })}
                    </div>
                  </>
                )
              ) : (
                <div className="empty">{isTranscribing(finalStatus) ? "Transcribing…" : finalStatus ? `Transcript ${finalStatus.replace("_", " ")}` : "Transcript not available yet."}</div>
              )}
            </>
          )}
        </div>
      </div>
    </>
  );
}
