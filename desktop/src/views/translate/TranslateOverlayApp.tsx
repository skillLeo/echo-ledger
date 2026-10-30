import { useEffect, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { TRANSLATE_FEED, type TranslateFeed, tailText, translateApi } from "../../lib/translate";

/**
 * The Live Translate overlay window (v1.3): game voice chat in a dark box at the top, or video subtitles at the
 * bottom. Click-through while locked; Ctrl+Shift+T unlocks it to move it (drag anywhere), Ctrl+Shift+H hides it.
 */
export default function TranslateOverlayApp() {
  const [feed, setFeed] = useState<TranslateFeed | null>(null);
  useEffect(() => {
    let stop: (() => void) | undefined;
    void translateApi.status().then((f) => f && setFeed(f)).catch(() => undefined);
    void listen<TranslateFeed>(TRANSLATE_FEED, (e) => setFeed(e.payload)).then((u) => (stop = u));
    return () => stop?.();
  }, []);
  if (!feed || feed.hidden || feed.status === "stopped") return null;
  const subtitles = feed.mode === "watch";
  const text = feed.notice ?? tailText(feed.text, subtitles ? 160 : 220);
  const waiting = !feed.notice && !feed.text && !feed.partial;
  return (
    <div
      className={`translate-overlay ${subtitles ? "subtitles" : "box"} ${feed.locked ? "" : "editing"}`}
      data-tauri-drag-region={feed.locked ? undefined : true}
      aria-live="polite"
      dir="auto"
    >
      {!subtitles && feed.original && <div className="translate-original" dir="auto">{tailText(feed.original, 90)}</div>}
      <div className="translate-text" dir="auto">
        {waiting ? <span className="translate-dots" aria-label="Listening">…</span> : text}
        {!feed.notice && feed.partial && <span className="translate-partial"> {feed.partial}</span>}
      </div>
      {!feed.locked && (
        <button type="button" className="translate-done" onClick={() => void translateApi.toggleLock()}>
          Done (Ctrl+Shift+T)
        </button>
      )}
    </div>
  );
}
