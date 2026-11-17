// Live Translate in the new look, inside the Live tab: today's panel (LiveTranslateCard, v1.3 plan B6) on the v2
// components, with the same core commands and feed. Pick an app that is playing sound, Game (voice chat, a box at the
// top) or Watch (a video, subtitles at the bottom), the language and the speed, and Start. While it runs: Move or Lock
// it on screen, and Stop.
import { useEffect, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { TRANSLATE_FEED, TRANSLATE_TARGETS, translateApi, type TranslateApp, type TranslateFeed, type TranslateMode, type TranslateSpeed } from "../../lib/translate";
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

/** "translating", or the feed's own word for where it is. */
const feedWords = (feed: TranslateFeed) => (feed.status === "live" ? "Translating" : feed.status.charAt(0).toUpperCase() + feed.status.slice(1));

export function LiveTranslateV2({ run }: { run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined> }) {
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
  const running = !!feed && feed.status !== "stopped";
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
      </div>
      <p className="el-secondary">
        Translates one app's sound as it is heard: teammates in a game's voice chat, or a film. Works over borderless or
        windowed games, not exclusive full screen. A browser is heard with all its tabs. About $0.18 an hour.
      </p>
      <div className="el-translate__app">
        <Select label="App to translate" value={pid === null ? "" : String(pid)} disabled={running}
          onChange={(v) => setPid(v ? Number(v) : null)}
          options={[{ value: "", label: apps.length ? "Choose an app playing sound" : "Nothing is playing sound" }, ...apps.map((a) => ({ value: String(a.pid), label: a.name }))]} />
        <Button variant="quiet" icon={Icons.RefreshIcon} disabled={running} onClick={refresh}>Refresh</Button>
      </div>
      <div className="el-translate__choices">
        <Select label="Mode" value={mode} disabled={running} onChange={(v) => setMode(v as TranslateMode)} options={MODES} />
        <Select label="Translate to" value={target} disabled={running} onChange={setTarget} options={TRANSLATE_TARGETS.map(([value, label]) => ({ value, label }))} />
        <Select label="Speed" value={speed} disabled={running} onChange={(v) => setSpeed(v as TranslateSpeed)} options={SPEEDS} />
      </div>
      {running && <p className="el-caption el-secondary">Ctrl+Shift+T lets you move Live Translate (drag it, then Done); Ctrl+Shift+H hides it.</p>}
      {taken.length > 0 && <p className="el-field__error" role="alert">Another app already uses {taken.join(" and ")}: use the buttons here instead.</p>}
      {feed?.notice && <p className="el-field__error" role="alert">{feed.notice}</p>}
      <div className="el-page__actions">
        {running ? (
          <>
            <Button onClick={() => void run(translateApi.toggleLock)}>{feed?.locked ? "Move Live Translate" : "Lock Live Translate"}</Button>
            <Button variant="primary" icon={Icons.StopIcon} onClick={() => void run(translateApi.stop)}>Stop</Button>
          </>
        ) : (
          <Button variant="primary" icon={Icons.PlayIcon} disabled={!chosen} onClick={() => void start()}>Start</Button>
        )}
      </div>
    </Card>
  );
}
