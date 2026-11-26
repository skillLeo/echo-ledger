// The Live tab in the new look (ui_v2.live, IA 3): everything during a call or session from one place. Record (now,
// or a voice note) with the Auto-record calls switch and how calls stand (UX-15, as the phone's Live tab: the same
// switch as Record's menu), Live Assist (upcoming sessions and a new one, only while live_assist is on), Live Translate,
// Dictation and Captions; each opens its feature or its one settings home. In the window's language (D-261), the status
// strip too, beside its buttons.
import { PanelToggle } from "../shell/NavRail";
import { useEffect, useState } from "react";
import { api, type AppStatus, type Settings } from "../../lib/ipc";
import { prettyShortcut } from "../../lib/format";
import { Button, Card, Icons, List, ListRow, Status, Toggle } from "../components";
import { shellStatus } from "../shell/status";
import type { Prep } from "../assist/adapter";
import { PrepScreen } from "../assist/PrepScreen";
import { LiveAssistSection } from "./LiveAssistSection";
import { PlanBadge, useLocked } from "../../views/main/Upgrade";
import { LiveTranslateV2 } from "./LiveTranslateV2";
import { UNAVAILABLE_WORDS, translateAvailability, useTranslateAvailability } from "../../lib/translate";
import { t, useLanguage } from "../../i18n";

export type LiveV2Props = {
  status: AppStatus;
  /** Live Assist is on for this person (live_assist). */
  assistOn: boolean;
  projects: { id: string; name: string }[];
  onRecord?: () => void;
  onVoiceNote?: () => void;
  onCaptions: () => void;
  /** Auto-record calls on or off (status.autoMode), as Record's menu does; none for a role that can't record. */
  onAutoRecord?: () => void;
  /** A Settings page ("voice", "captions", "calls"). */
  onSettings: (section: string) => void;
  /** The app's action runner (a notice on failure), for Live Translate's commands. */
  run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
};

/**
 * How calls stand, in the phone's words (UX-15): set up first while the Ready check found a problem, else on or ready;
 * in the window's language.
 */
export function callsLine(status: Pick<AppStatus, "autoMode" | "callsReady">): string {
  if (status.callsReady === false) return t("Calls: set up first");
  return status.autoMode ? t("Calls: auto-record on") : t("Calls: ready");
}

export function LiveV2({ status, assistOn, projects, onRecord, onVoiceNote, onCaptions, onAutoRecord, onSettings, run }: LiveV2Props) {
  useLanguage();
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

  const strip = shellStatus(status);
  const key = (action: "dictation" | "toggle_captions" | "voice_note") => {
    const k = settings?.shortcuts?.[action];
    return k ? prettyShortcut(k, status.platform) : null;
  };

  return (
    <div className="el-page el-page--narrow" data-v2="live">
      <header className="el-page__head"><PanelToggle /><h1 className="el-title-l">{t("Live")}</h1></header>

      <section className="el-group" aria-label={t("Record")}>
        <h2 className="el-caption el-body-strong el-group__title">{t("Record")}</h2>
        <Card className="el-home-strip">
          <Status kind={strip.kind} live>{strip.text}</Status>
          <span className="el-page__actions">
            {onVoiceNote && !status.recording && <Button onClick={onVoiceNote} icon={Icons.MicIcon}>{t("Voice note")}</Button>}
            {onRecord && <Button variant={status.recording ? "danger" : "primary"} icon={Icons.RecordIcon} onClick={onRecord}>{status.recording ? t("Stop and save") : t("home_context.record")}</Button>}
          </span>
        </Card>
        <Card>
          <List label={t("Recording")} className="el-group__rows">
            <ListRow compact icon={Icons.PhoneIcon} iconStyle="plain" title={t("Auto-record calls")} meta={callsLine(status)}
              control={<Toggle label={t("Auto-record calls")} hideLabel checked={status.autoMode} disabled={!onAutoRecord} onChange={() => onAutoRecord?.()} />} />
            <ListRow compact icon={Icons.SettingsIcon} iconStyle="plain" title={t("Calls & meetings")} chevron onClick={() => onSettings("calls")} />
          </List>
        </Card>
      </section>

      {assistOn && <LiveAssistSection onPrep={setPrep} />}

      <section className="el-group" aria-label={t("During a call")}>
        <h2 className="el-caption el-body-strong el-group__title">{t("During a call")}</h2>
        <Card>
          <List label={t("During a call")} className="el-group__rows">
            {translateShown && (
              <ListRow compact icon={Icons.LanguagesIcon} iconStyle="plain" title={t("Live Translate")}
                meta={translate.reason ? t(UNAVAILABLE_WORDS[translate.reason]) : t("Translate what a game or video says, as it is said")}
                badge={translate.reason === "plan" ? <PlanBadge feature="live_translate" /> : undefined} chevron onClick={() => setTranslateOpen(!translateOpen)} />
            )}
            <ListRow compact icon={Icons.KeyboardIcon} iconStyle="plain" title={t("Dictation")} meta={t("Speak, and the words are typed where you are")}
              value={key("dictation") ?? undefined} chevron onClick={() => onSettings("voice")} />
            <ListRow compact icon={Icons.CaptionsIcon} iconStyle="plain" title={t("Captions")}
              meta={status.recording ? (status.captionsLive ? t("On for this recording") : t("Off for this recording")) : t("Live text while you record")}
              value={key("toggle_captions") ?? undefined}
              control={status.recording ? <Button size="sm" onClick={onCaptions}>{status.captionsLive ? t("Turn off") : t("Turn on")}</Button> : undefined}
              chevron={!status.recording} onClick={status.recording ? undefined : () => onSettings("captions")} />
          </List>
        </Card>
        {translateOpen && translateShown && <LiveTranslateV2 run={run} unavailable={translate.reason} planBadge={<PlanBadge feature="live_translate" />} />}
      </section>
    </div>
  );
}
