import { useEffect, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import {
  TRANSLATE_FEED,
  TRANSLATE_TARGETS,
  type TranslateApp,
  type TranslateFeed,
  type TranslateMode,
  type TranslateSpeed,
  UNAVAILABLE_WORDS,
  endedWords,
  translateApi,
  translatePhase,
} from "../../lib/translate";

/**
 * Live Translate on this computer (v1.3, plan B6): pick an app that is playing sound, Game (voice chat → a box at
 * the top) or Watch (a video → subtitles at the bottom), and Start. The overlay never takes the mouse until
 * Ctrl+Shift+T unlocks it.
 */
export function LiveTranslateCard({ run, unavailable = null }: {
  run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
  /** Why it can't run here (live_translate_status); the card then says so and asks nothing of the core. */
  unavailable?: keyof typeof UNAVAILABLE_WORDS | null;
}) {
  if (unavailable) {
    return (
      <div className="card" style={{ marginTop: 14 }} data-testid="translate-unavailable">
        <div className="row"><b>Live Translate</b></div>
        <p className="small" role="status">{UNAVAILABLE_WORDS[unavailable]}</p>
        <p className="small muted">Translates one app's sound as it is heard: teammates in a game's voice chat, or a film.</p>
        <div className="card-actions"><button type="button" className="btn primary" disabled>Start</button></div>
      </div>
    );
  }
  return <LiveTranslateForm run={run} />;
}

function LiveTranslateForm({ run }: { run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined> }) {
  const [apps, setApps] = useState<TranslateApp[]>([]);
  const [pid, setPid] = useState<number | null>(null);
  const [mode, setMode] = useState<TranslateMode>("game");
  const [target, setTarget] = useState("en");
  const [speed, setSpeed] = useState<TranslateSpeed>("fastest");
  const [feed, setFeed] = useState<TranslateFeed | null>(null);
  const [taken, setTaken] = useState<string[]>([]);
  const refresh = () => void translateApi.apps().then(setApps).catch(() => setApps([]));
  useEffect(() => {
    refresh();
    void translateApi.status().then(setFeed).catch(() => undefined);
    let stop: (() => void) | undefined;
    void listen<TranslateFeed>(TRANSLATE_FEED, (e) => setFeed(e.payload.status === "stopped" ? null : e.payload)).then((u) => (stop = u));
    return () => stop?.();
  }, []);
  const phase = translatePhase(feed);
  const running = phase === "running";
  const ended = phase === "ended";
  // An ended session is tidied away: the core lets go of anything left, and the form comes back.
  const close = () => {
    setFeed(null);
    void translateApi.stop().catch(() => undefined);
  };
  const chosen = apps.find((a) => a.pid === pid) ?? null;
  const start = async () => {
    if (!chosen) return;
    const hints = mode === "game" ? ["ar", "en"] : [];
    const busy = await run(() => translateApi.start(chosen, mode, target, hints, speed));
    setTaken(busy ?? []);
  };
  return (
    <div className="card" style={{ marginTop: 14 }}>
      <div className="row"><b>Live Translate</b><span className="spacer" />
        {running && <span className="small muted">{feed?.app} · {feed?.status === "live" ? "translating" : feed?.status}</span>}
        {ended && <span className="small muted" data-testid="translate-ended">{feed?.app ? `${feed.app} · ended` : "Ended"}</span>}
      </div>
      <p className="small muted">
        Translates one app's sound as it is heard: teammates in a game's voice chat, or a film. Works over borderless or
        windowed games, not exclusive full screen. A browser is heard with all its tabs. About $0.18 an hour.
      </p>
      <div className="translate-form">
        <label className="small">App
          <select aria-label="App to translate" value={pid ?? ""} onChange={(e) => setPid(e.target.value ? Number(e.target.value) : null)} disabled={running || ended}>
            <option value="">{apps.length ? "Choose an app playing sound" : "Nothing is playing sound"}</option>
            {apps.map((a) => <option key={a.pid} value={a.pid}>{a.name}</option>)}
          </select>
        </label>
        <button type="button" className="btn ghost compact" onClick={refresh} disabled={running || ended}>Refresh</button>
        <label className="small">Mode
          <select aria-label="Mode" value={mode} onChange={(e) => setMode(e.target.value as TranslateMode)} disabled={running || ended}>
            <option value="game">Game (box at the top)</option>
            <option value="watch">Watch (subtitles)</option>
          </select>
        </label>
        <label className="small">Translate to
          <select aria-label="Translate to" value={target} onChange={(e) => setTarget(e.target.value)} disabled={running || ended}>
            {TRANSLATE_TARGETS.map(([code, name]) => <option key={code} value={code}>{name}</option>)}
          </select>
        </label>
        <label className="small">Speed
          <select aria-label="Speed" value={speed} onChange={(e) => setSpeed(e.target.value as TranslateSpeed)} disabled={running || ended}>
            <option value="fastest">Fastest</option>
            <option value="balanced">Balanced</option>
            <option value="accurate">Accurate</option>
          </select>
        </label>
      </div>
      {running && <p className="small muted">Ctrl+Shift+T lets you move Live Translate (drag it, then Done); Ctrl+Shift+H hides it.</p>}
      {taken.length > 0 && <p className="small error-text">Another app already uses {taken.join(" and ")}: use the buttons here instead.</p>}
      {running && feed?.notice && <p className="small error-text">{feed.notice}</p>}
      {ended && feed && <p className="small" role="status">{endedWords(feed)}</p>}
      <div className="card-actions">
        {running ? (
          <>
            <button type="button" className="btn" onClick={() => run(translateApi.toggleLock)}>{feed?.locked ? "Move Live Translate" : "Lock Live Translate"}</button>
            <button type="button" className="btn primary" onClick={() => run(translateApi.stop)}>Stop</button>
          </>
        ) : ended ? (
          <button type="button" className="btn primary" onClick={close}>Close</button>
        ) : (
          <button type="button" className="btn primary" onClick={() => void start()} disabled={!chosen}>Start</button>
        )}
      </div>
    </div>
  );
}
