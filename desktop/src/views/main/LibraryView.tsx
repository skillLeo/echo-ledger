import { useCallback, useEffect, useMemo, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type LibraryItem, type TranscriptDetail } from "../../lib/ipc";
import { formatDuration, formatWhen, timestamp } from "../../lib/format";
import { SourceAppBadge, SourceDeviceBadge } from "../../components/SourceBadge";
import { DesktopIcon, GlobeIcon, PhoneIcon, RefreshIcon, TrashIcon } from "../../components/Icons";

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
    const id = current?.transcript?.final_transcript_id ?? current?.transcript?.live_transcript_id;
    if (id) api.transcript(id).then(setTranscript).catch(() => setTranscript(null));
  }, [current?.id, current?.transcript?.final_transcript_id, current?.transcript?.live_transcript_id]);

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
              {transcript ? (
                <>
                  <div className="small muted" style={{ marginTop: 10 }}>{transcript.kind === "final" ? `Final transcript · ${transcript.provider}` : "Live captions (final transcript pending)"}</div>
                  <div className="segments">
                    {transcript.segments.map((s, i) => (
                      <div key={i} className="segment">
                        <span className="seg-time">{timestamp(s.start_ms)}</span>
                        <span className={`seg-spk spk-${s.channel === 2 ? 2 : 1}`}>{s.speaker}</span>
                        <span>{s.text}</span>
                      </div>
                    ))}
                  </div>
                </>
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
