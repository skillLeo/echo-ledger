// The Live tab in the new look (ui_v2.live, IA 3; UX-10 "Live page = live tools"): the tools for a call or session in one
// place — Live Assist (upcoming sessions and a new one, only while live_assist is on), Live Translate, Dictation and
// Captions — with one status line for automatic recording. Record itself lives in the rail's Record button only.
import { PanelToggle } from "../shell/NavRail";
import { useEffect, useState } from "react";
import { api, type AppStatus, type Settings } from "../../lib/ipc";
import { prettyShortcut } from "../../lib/format";
import { Button, Card, Icons, List, ListRow } from "../components";
import type { Prep } from "../assist/adapter";
import { PrepScreen } from "../assist/PrepScreen";
import { LiveAssistSection } from "./LiveAssistSection";
import { PlanBadge, useLocked } from "../../views/main/Upgrade";
import { LiveTranslateV2 } from "./LiveTranslateV2";
import { UNAVAILABLE_WORDS, translateAvailability, useTranslateAvailability } from "../../lib/translate";

export type LiveV2Props = {
  status: AppStatus;
  /** Live Assist is on for this person (live_assist). */
  assistOn: boolean;
  projects: { id: string; name: string }[];
  /** Not shown here any more (UX-10: no second Record button); kept so the window can pass them unchanged. */
  onRecord?: () => void;
  onVoiceNote?: () => void;
  onCaptions: () => void;
  /** A Settings page ("voice", "captions", "calls"). */
  onSettings: (section: string) => void;
  /** The app's action runner (a notice on failure), for Live Translate's commands. */
  run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
};

export function LiveV2({ status, assistOn, projects, onCaptions, onSettings, run }: LiveV2Props) {
  const [prep, setPrep] = useState<Prep | "new" | null>(null);
  const [translateOpen, setTranslateOpen] = useState(false);
  const [settings, setSettings] = useState<Settings | null>(null);
  // As today's Record page: a plan without Live Translate shows it locked, with the plan that has it; a server without
  // it doesn't show it.
  const translateLocked = useLocked("live_translate");
  const translateAnswer = useTranslateAvailability();
  const translate = translateAvailability(translateAnswer, status.platform, status.plan.liveTranslate && !translateLocked);
  // Listed where it runs, and where it is held back by the system or the plan (saying which); a server without it at all
  // doesn't list it.
  const translateShown = translate.available || translate.reason === "platform" || translateLocked || (translateAnswer?.reason === "plan");

  useEffect(() => {
    api.settings().then(setSettings, () => undefined);
  }, []);

  if (prep) {
    return <PrepScreen prep={prep === "new" ? null : prep} projects={projects} onBack={() => setPrep(null)} onStarted={() => setPrep(null)} />;
  }

  const key = (action: "dictation" | "toggle_captions" | "voice_note") => {
    const k = settings?.shortcuts?.[action];
    return k ? prettyShortcut(k, status.platform) : null;
  };

  return (
    <div className="el-page el-page--narrow" data-v2="live">
      <header className="el-page__head"><PanelToggle /><h1 className="el-title-l">Live</h1></header>

      {/* One status line: whether calls and meetings are recorded without asking, and where to change it. */}
      <p className="el-secondary" data-testid="live-auto-record">
        Auto-record calls &amp; meetings: <span className="el-body-strong">{status.autoMode ? "On" : "Off"}</span>{" · "}
        <Button variant="quiet" size="sm" onClick={() => onSettings("calls")} aria-label="Change auto-record calls and meetings">Change</Button>
      </p>

      {assistOn && <LiveAssistSection onPrep={setPrep} />}

      <section className="el-group" aria-label="During a call">
        <h2 className="el-caption el-body-strong el-group__title">During a call</h2>
        <Card>
          <List label="During a call" className="el-group__rows">
            {translateShown && (
              <ListRow compact icon={Icons.LanguagesIcon} iconStyle="plain" title="Live Translate"
                meta={translate.reason ? UNAVAILABLE_WORDS[translate.reason] : "Translate what a game or video says, as it is said"}
                badge={translate.reason === "plan" ? <PlanBadge feature="live_translate" /> : undefined} chevron onClick={() => setTranslateOpen(!translateOpen)} />
            )}
            <ListRow compact icon={Icons.KeyboardIcon} iconStyle="plain" title="Dictation" meta="Speak, and the words are typed where you are"
              value={key("dictation") ?? undefined} chevron onClick={() => onSettings("voice")} />
            <ListRow compact icon={Icons.CaptionsIcon} iconStyle="plain" title="Captions"
              meta={status.recording ? (status.captionsLive ? "On for this recording" : "Off for this recording") : "Live text while you record"}
              value={key("toggle_captions") ?? undefined}
              control={status.recording ? <Button size="sm" onClick={onCaptions}>{status.captionsLive ? "Turn off" : "Turn on"}</Button> : undefined}
              chevron={!status.recording} onClick={status.recording ? undefined : () => onSettings("captions")} />
          </List>
        </Card>
        {translateOpen && translateShown && <LiveTranslateV2 run={run} unavailable={translate.reason} planBadge={<PlanBadge feature="live_translate" />} />}
      </section>
    </div>
  );
}
