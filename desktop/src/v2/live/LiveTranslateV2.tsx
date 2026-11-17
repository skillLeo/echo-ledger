// Live Translate in the new look, inside the Live tab: today's panel (LiveTranslateCard, v1.3 plan B6) on the v2
// components, with the same core commands and feed. Pick an app that is playing sound, Game (voice chat, a box at the
// top) or Watch (a video, subtitles at the bottom), the language and the speed, and Start. While it runs: Move or Lock
// it on screen, and Stop.
import { useEffect, useState, type ReactNode } from "react";
import { listen } from "@tauri-apps/api/event";
import type { LiveTranslateAvailability } from "../../lib/ipc";
import {
  TRANSLATE_FEED, TRANSLATE_TARGETS, UNAVAILABLE_WORDS, endedWords, translateApi, translatePhase, type TranslateApp, type TranslateFeed, type TranslateMode, type TranslateSpeed,
} from "../../lib/translate";
import { Button, Card, Icons, Select, Status } from "../components";

export const MODES: { value: TranslateMode; label: string }[] = [
  { value: "game", label: "Game (box at the top)" },
  { value: "watch", label: "Watch (subtitles)" },
];
export const SPEEDS: { value: TranslateSpeed; label: string }[] = [
  { value: "fastest", label: "Fastest" },
  { value: "balanced", label: "Balanced" },
  { value: "accurate", label: "Accurate" },
];

export { UNAVAILABLE_WORDS, translateAvailability } from "../../lib/translate";

/** "translating", or the feed's own word for where it is. */
const feedWords = (feed: TranslateFeed) => (feed.status === "live" ? "Translating" : feed.status.charAt(0).toUpperCase() + feed.status.slice(1));

export function LiveTranslateV2({ run, unavailable = null, planBadge }: {
  run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
  /** Why it can't run here; null when it can. Nothing is asked of the core then, and the controls stay off. */
  unavailable?: LiveTranslateAvailability["reason"];
  /** The plan that has it, next to "Not in your plan". */
  planBadge?: ReactNode;
}) {
  if (unavailable) {
    return (
      <Card className="el-form el-translate" label="Live Translate">
        <div className="el-group__head"><h3 className="el-body-strong">Live Translate</h3>{unavailable === "plan" && planBadge}</div>
        <p className="el-secondary" role="status">{UNAVAILABLE_WORDS[unavailable]}</p>
        <p className="el-caption el-secondary">Translates one app's sound as it is heard: teammates in a game's voice chat, or a film.</p>
        <div className="el-page__actions"><Button variant="primary" icon={Icons.PlayIcon} disabled>Start</Button></div>
      </Card>
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
    let live = true;
    void listen<TranslateFeed>(TRANSLATE_FEED, (e) => setFeed(e.payload.status === "stopped" ? null : e.payload)).then((u) => {
      if (live) stop = u;
      else u();
    });
    return () => {
      live = false;
      stop?.();
    };
  }, []);
  const phase = translatePhase(feed);
  const running = phase === "running";
  const ended = phase === "ended";
  const busy = running || ended;
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
    <Card className="el-form el-translate" label="Live Translate">
      <div className="el-group__head">
        <h3 className="el-body-strong">Live Translate</h3>
        {running && feed && <Status kind="recording" live>{`${feed.app} · ${feedWords(feed)}`}</Status>}
        {ended && feed && <Status kind="idle">{feed.app ? `${feed.app} · Ended` : "Ended"}</Status>}
      </div>
      <p className="el-secondary">
        Translates one app's sound as it is heard: teammates in a game's voice chat, or a film. Works over borderless or
        windowed games, not exclusive full screen. A browser is heard with all its tabs. About $0.18 an hour.
      </p>
      <div className="el-translate__app">
        <Select label="App to translate" value={pid === null ? "" : String(pid)} disabled={busy}
          onChange={(v) => setPid(v ? Number(v) : null)}
          options={[{ value: "", label: apps.length ? "Choose an app playing sound" : "Nothing is playing sound" }, ...apps.map((a) => ({ value: String(a.pid), label: a.name }))]} />
        <Button variant="quiet" icon={Icons.RefreshIcon} disabled={busy} onClick={refresh}>Refresh</Button>
      </div>
      <div className="el-translate__choices">
        <Select label="Mode" value={mode} disabled={busy} onChange={(v) => setMode(v as TranslateMode)} options={MODES} />
        <Select label="Translate to" value={target} disabled={busy} onChange={setTarget} options={TRANSLATE_TARGETS.map(([value, label]) => ({ value, label }))} />
        <Select label="Speed" value={speed} disabled={busy} onChange={(v) => setSpeed(v as TranslateSpeed)} options={SPEEDS} />
      </div>
      {running && <p className="el-caption el-secondary">Ctrl+Shift+T lets you move Live Translate (drag it, then Done); Ctrl+Shift+H hides it.</p>}
      {taken.length > 0 && <p className="el-field__error" role="alert">Another app already uses {taken.join(" and ")}: use the buttons here instead.</p>}
      {running && feed?.notice && <p className="el-field__error" role="alert">{feed.notice}</p>}
      {ended && feed && <p className="el-translate__ended" role="status" data-testid="translate-ended">{endedWords(feed)}</p>}
      <div className="el-page__actions">
        {running ? (
          <>
            <Button onClick={() => void run(translateApi.toggleLock)}>{feed?.locked ? "Move Live Translate" : "Lock Live Translate"}</Button>
            <Button variant="primary" icon={Icons.StopIcon} onClick={() => void run(translateApi.stop)}>Stop</Button>
          </>
        ) : ended ? (
          <Button variant="primary" onClick={close}>Close</Button>
        ) : (
          <Button variant="primary" icon={Icons.PlayIcon} disabled={!chosen} onClick={() => void start()}>Start</Button>
        )}
      </div>
    </Card>
  );
}
