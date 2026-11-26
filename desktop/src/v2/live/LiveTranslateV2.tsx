// Live Translate in the new look, inside the Live tab: today's panel (LiveTranslateCard, v1.3 plan B6) on the v2
// components, with the same core commands and feed. Pick an app that is playing sound, Game (voice chat, a box at the
// top) or Watch (a video, subtitles at the bottom), the language and the speed, and Start. While it runs: Move or Lock
// it on screen, and Stop. In the window's language (D-261); lib/translate's words (classic's panel's too) are said
// through t() here, and what the core says (an app's name, a notice) is shown as it comes.
import { useEffect, useState, type ReactNode } from "react";
import { listen } from "@tauri-apps/api/event";
import { errorMessage, type LiveTranslateAvailability } from "../../lib/ipc";
import {
  TRANSLATE_FEED, TRANSLATE_TARGETS, UNAVAILABLE_WORDS, appsPrompt, translateApi, translatePhase, type TranslateApp, type TranslateFeed, type TranslateMode, type TranslateSpeed,
} from "../../lib/translate";
import { isolate, isRtl, lineDir, t, useLanguage } from "../../i18n";
import { Button, Card, Icons, Select, Status } from "../components";

/** Game or Watch, in the window's language. */
export function modes(): { value: TranslateMode; label: string }[] {
  return [
    { value: "game", label: t("Game (box at the top)") },
    { value: "watch", label: t("Watch (subtitles)") },
  ];
}
/** The speeds, in the window's language. */
export function speeds(): { value: TranslateSpeed; label: string }[] {
  return [
    { value: "fastest", label: t("Fastest") },
    { value: "balanced", label: t("Balanced") },
    { value: "accurate", label: t("Accurate") },
  ];
}

export { UNAVAILABLE_WORDS, translateAvailability } from "../../lib/translate";

/** "Translating", or the feed's own word for where it is (a status this app doesn't know, as the core wrote it). */
function feedWords(feed: TranslateFeed): string {
  switch (feed.status) {
    case "live":
      return t("Translating");
    case "listening":
      return t("Listening");
    case "quiet":
      return t("Quiet");
    case "reconnecting":
      return t("Reconnecting");
    default:
      return feed.status.charAt(0).toUpperCase() + feed.status.slice(1);
  }
}

/** An app's name inside an Urdu line: in its own isolate. */
const named = (name: string) => (isRtl() ? isolate(name) : name);

/** Why an ended session ended (lib/translate's endedWords, in the window's language): the core's reason, else what usually ends one. */
function endedLine(feed: TranslateFeed): string {
  const reason = typeof feed.notice === "string" ? feed.notice.trim() : "";
  if (reason) return reason;
  const app = typeof feed.app === "string" ? feed.app.trim() : "";
  return app ? t("Live Translate ended. :app closed or stopped playing sound.", { app }) : t("Live Translate ended. The app closed or stopped playing sound.");
}

export function LiveTranslateV2({ run, unavailable = null, planBadge }: {
  run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
  /** Why it can't run here; null when it can. Nothing is asked of the core then, and the controls stay off. */
  unavailable?: LiveTranslateAvailability["reason"];
  /** The plan that has it, next to "Not in your plan". */
  planBadge?: ReactNode;
}) {
  useLanguage();
  if (unavailable) {
    return (
      <Card className="el-form el-translate" label={t("Live Translate")}>
        <div className="el-group__head"><h3 className="el-body-strong">{t("Live Translate")}</h3>{unavailable === "plan" && planBadge}</div>
        <p className="el-secondary" role="status">{t(UNAVAILABLE_WORDS[unavailable])}</p>
        <p className="el-caption el-secondary">{t("Translates one app's sound as it is heard: teammates in a game's voice chat, or a film.")}</p>
        <div className="el-page__actions"><Button variant="primary" icon={Icons.PlayIcon} disabled>{t("Start")}</Button></div>
      </Card>
    );
  }
  return <LiveTranslateForm run={run} />;
}

function LiveTranslateForm({ run }: { run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined> }) {
  useLanguage();
  const [apps, setApps] = useState<TranslateApp[]>([]);
  const [appsError, setAppsError] = useState<string | null>(null);
  const [pid, setPid] = useState<number | null>(null);
  const [mode, setMode] = useState<TranslateMode>("game");
  const [target, setTarget] = useState("en");
  const [speed, setSpeed] = useState<TranslateSpeed>("fastest");
  const [feed, setFeed] = useState<TranslateFeed | null>(null);
  const [taken, setTaken] = useState<string[]>([]);
  const refresh = () =>
    void translateApi
      .apps()
      .then((list) => {
        setApps(list);
        setAppsError(null);
      })
      .catch((e: unknown) => {
        setApps([]);
        setAppsError(errorMessage(e));
      });
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
  const failure = appsError !== null ? t("The apps playing sound could not be listed: :message. Press Refresh to try again.", { message: appsError }) : null;
  // The keys another app holds, "Ctrl+Shift+T and Ctrl+Shift+H" (t() isolates each in an Urdu line).
  const takenKeys = taken.length > 0 ? taken.reduce((list, last) => t(":list and :last", { list, last })) : "";

  return (
    <Card className="el-form el-translate" label={t("Live Translate")}>
      <div className="el-group__head">
        <h3 className="el-body-strong">{t("Live Translate")}</h3>
        {running && feed && <Status kind="recording" live>{`${named(feed.app)} · ${feedWords(feed)}`}</Status>}
        {ended && feed && <Status kind="idle">{feed.app ? t(":app · Ended", { app: feed.app }) : t("Ended")}</Status>}
      </div>
      <p className="el-secondary">
        {t("Translates one app's sound as it is heard: teammates in a game's voice chat, or a film. Works over borderless or windowed games, not exclusive full screen. A browser is heard with all its tabs. About $0.18 an hour.")}
      </p>
      <div className="el-translate__app">
        <Select label={t("App to translate")} value={pid === null ? "" : String(pid)} disabled={busy}
          onChange={(v) => setPid(v ? Number(v) : null)}
          options={[{ value: "", label: t(appsPrompt(apps.length, appsError !== null)) }, ...apps.map((a) => ({ value: String(a.pid), label: a.name }))]} />
        <Button variant="quiet" icon={Icons.RefreshIcon} disabled={busy} onClick={refresh}>{t("Refresh")}</Button>
      </div>
      {failure !== null && <p dir={lineDir(failure)} className="el-field__error" role="alert">{failure}</p>}
      <div className="el-translate__choices">
        <Select label={t("Mode")} value={mode} disabled={busy} onChange={(v) => setMode(v as TranslateMode)} options={modes()} />
        <Select label={t("Translate to")} value={target} disabled={busy} onChange={setTarget} options={TRANSLATE_TARGETS.map(([value, label]) => ({ value, label: t(label) }))} />
        <Select label={t("Speed")} value={speed} disabled={busy} onChange={(v) => setSpeed(v as TranslateSpeed)} options={speeds()} />
      </div>
      {running && <p className="el-caption el-secondary">{t(":move lets you move Live Translate (drag it, then Done); :hide hides it.", { move: "Ctrl+Shift+T", hide: "Ctrl+Shift+H" })}</p>}
      {taken.length > 0 && <p className="el-field__error" role="alert">{t("Another app already uses :keys: use the buttons here instead.", { keys: takenKeys })}</p>}
      {running && feed?.notice && <p className="el-field__error" role="alert" dir="auto">{feed.notice}</p>}
      {ended && feed && <p className="el-translate__ended" role="status" data-testid="translate-ended" dir={lineDir(endedLine(feed))}>{endedLine(feed)}</p>}
      <div className="el-page__actions">
        {running ? (
          <>
            <Button onClick={() => void run(translateApi.toggleLock)}>{feed?.locked ? t("Move Live Translate") : t("Lock Live Translate")}</Button>
            <Button variant="primary" icon={Icons.StopIcon} onClick={() => void run(translateApi.stop)}>{t("Stop")}</Button>
          </>
        ) : ended ? (
          <Button variant="primary" onClick={close}>{t("Close")}</Button>
        ) : (
          <Button variant="primary" icon={Icons.PlayIcon} disabled={!chosen} onClick={() => void start()}>{t("Start")}</Button>
        )}
      </div>
    </Card>
  );
}
