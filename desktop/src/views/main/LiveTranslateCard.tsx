import { useEffect, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import {
  TRANSLATE_FEED,
  TRANSLATE_TARGETS,
  type TranslateApp,
  type TranslateFeed,
  type TranslateMode,
  type TranslateSpeed,
  translateApi,
} from "../../lib/translate";

/**
 * Live Translate on this computer (v1.3, plan B6): pick an app that is playing sound, Game (voice chat → a box at
 * the top) or Watch (a video → subtitles at the bottom), and Start. The overlay never takes the mouse until
 * Ctrl+Shift+T unlocks it.
 */
export function LiveTranslateCard({ run }: { run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined> }) {
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
  const running = !!feed && feed.status !== "stopped";
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
      </div>
      <p className="small muted">
        Translates one app's sound as it is heard: teammates in a game's voice chat, or a film. Works over borderless or
        windowed games; exclusive full screen hides every overlay. A browser is heard with all its tabs. About $0.18 an hour.
      </p>
      <div className="translate-form">
        <label className="small">App
          <select aria-label="App to translate" value={pid ?? ""} onChange={(e) => setPid(e.target.value ? Number(e.target.value) : null)} disabled={running}>
            <option value="">{apps.length ? "Choose an app playing sound" : "Nothing is playing sound"}</option>
            {apps.map((a) => <option key={a.pid} value={a.pid}>{a.name}</option>)}
          </select>
        </label>
        <button type="button" className="btn ghost compact" onClick={refresh} disabled={running}>Refresh</button>
        <label className="small">Mode
          <select aria-label="Mode" value={mode} onChange={(e) => setMode(e.target.value as TranslateMode)} disabled={running}>
            <option value="game">Game (box at the top)</option>
            <option value="watch">Watch (subtitles)</option>
          </select>
        </label>
        <label className="small">Translate to
          <select aria-label="Translate to" value={target} onChange={(e) => setTarget(e.target.value)} disabled={running}>
            {TRANSLATE_TARGETS.map(([code, name]) => <option key={code} value={code}>{name}</option>)}
          </select>
        </label>
        <label className="small">Speed
          <select aria-label="Speed" value={speed} onChange={(e) => setSpeed(e.target.value as TranslateSpeed)} disabled={running}>
            <option value="fastest">Fastest</option>
            <option value="balanced">Balanced</option>
            <option value="accurate">Accurate</option>
          </select>
        </label>
      </div>
      {running && <p className="small muted">Ctrl+Shift+T moves the overlay (drag it, then Done); Ctrl+Shift+H hides it.</p>}
      {taken.length > 0 && <p className="small error-text">Another app already uses {taken.join(" and ")}: use the buttons here instead.</p>}
      {feed?.notice && <p className="small error-text">{feed.notice}</p>}
      <div className="card-actions">
        {running ? (
          <>
            <button type="button" className="btn" onClick={() => run(translateApi.toggleLock)}>{feed?.locked ? "Move overlay" : "Lock overlay"}</button>
            <button type="button" className="btn primary" onClick={() => run(translateApi.stop)}>Stop</button>
          </>
        ) : (
          <button type="button" className="btn primary" onClick={() => void start()} disabled={!chosen}>Start</button>
        )}
      </div>
    </div>
  );
}
