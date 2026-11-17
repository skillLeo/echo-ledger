import { useEffect, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { TRANSLATE_FEED, type TranslateFeed, tailText, translateApi, translatePhase } from "../../lib/translate";
import { useConcealed } from "../../lib/privacy";
import { PrivacyBadge } from "../../components/PrivacyBadge";

/**
 * The Live Translate overlay window (v1.3): game voice chat in a dark box at the top, or video subtitles at the
 * bottom. Click-through while locked; Ctrl+Shift+T unlocks it to move it (drag anywhere), Ctrl+Shift+H hides it.
 */
export default function TranslateOverlayApp() {
  const [feed, setFeed] = useState<TranslateFeed | null>(null);
  const concealed = useConcealed();
  useEffect(() => {
    let stop: (() => void) | undefined;
    void translateApi.status().then((f) => f && setFeed(f)).catch(() => undefined);
    void listen<TranslateFeed>(TRANSLATE_FEED, (e) => setFeed(e.payload)).then((u) => (stop = u));
    return () => stop?.();
  }, []);
  // Only a running session is drawn: an ended one (or a status this app doesn't know) leaves nothing on screen, and the
  // main window's panel says why it ended, with Close. Hidden, a running one still shows the pill below.
  if (!feed || translatePhase(feed) !== "running" || concealed) return null;
  const subtitles = feed.mode === "watch";
  // Hidden, it still listens: a small pill says so, and how to show it again, so someone can always see it.
  if (feed.hidden) {
    return (
      <div className={`translate-hidden ${subtitles ? "subtitles" : "box"}`} role="status">
        Live Translate is listening · {feed.hide_keys || "Ctrl+Shift+H"} to show
      </div>
    );
  }
  const text = feed.notice ?? tailText(feed.text, subtitles ? 160 : 220);
  const waiting = !feed.notice && !feed.text && !feed.partial;
  return (
    <div
      className={`translate-overlay ${subtitles ? "subtitles" : "box"} ${feed.locked ? "" : "editing"}`}
      data-tauri-drag-region={feed.locked ? undefined : true}
      aria-live="polite"
      dir="auto"
    >
      <span className="translate-badge"><PrivacyBadge compact interactive={!feed.locked} /></span>
      {!subtitles && feed.original && <div className="translate-original" dir="auto">{tailText(feed.original, 90)}</div>}
      <div className="translate-text" dir="auto">
        {waiting ? <span className="translate-dots" aria-label="Listening">…</span> : text}
        {!feed.notice && feed.partial && <span className="translate-partial"> {feed.partial}</span>}
      </div>
      {!feed.locked && (
        <button type="button" className="translate-done" onClick={() => void translateApi.toggleLock()}>
          Done ({feed.lock_keys || "Ctrl+Shift+T"})
        </button>
      )}
    </div>
  );
}
