// The Live tab in the new look (ui_v2.live, IA 3): everything during a call or session from one place. Record (now,
// or a voice note), Live Assist (upcoming sessions and a new one, only while live_assist is on), Live Translate,
// Dictation and Captions; each opens its feature or its one settings home.
import { useEffect, useState, type ReactNode } from "react";
import { api, type AppStatus, type Settings } from "../../lib/ipc";
import { prettyShortcut, formatWhen } from "../../lib/format";
import { Badge, Button, Card, Icons, List, ListRow, Status } from "../components";
import { shellStatus } from "../shell/status";
import { assist, type Prep } from "../assist/adapter";
import { MEETING_TYPES, PrepScreen } from "../assist/PrepScreen";

export type LiveV2Props = {
  status: AppStatus;
  /** Live Assist is on for this person (live_assist). */
  assistOn: boolean;
  projects: { id: string; name: string }[];
  onRecord?: () => void;
  onVoiceNote?: () => void;
  onCaptions: () => void;
  /** A Settings page ("voice", "captions", "calls"). */
  onSettings: (section: string) => void;
  /** Live Translate's own panel (today's), opened in place. */
  translate?: ReactNode;
};

export function LiveV2({ status, assistOn, projects, onRecord, onVoiceNote, onCaptions, onSettings, translate }: LiveV2Props) {
  const [prep, setPrep] = useState<Prep | "new" | null>(null);
  const [upcoming, setUpcoming] = useState<Prep[] | null>(null);
  const [translateOpen, setTranslateOpen] = useState(false);
  const [settings, setSettings] = useState<Settings | null>(null);

  useEffect(() => {
    api.settings().then(setSettings, () => undefined);
  }, []);
  useEffect(() => {
    if (!assistOn || prep) return;
    assist.preps("scheduled").then((p) => setUpcoming(Array.isArray(p) ? p : []), () => setUpcoming([]));
  }, [assistOn, prep]);

  if (prep) {
    return <PrepScreen prep={prep === "new" ? null : prep} projects={projects} onBack={() => setPrep(null)} onStarted={() => setPrep(null)} />;
  }

  const strip = shellStatus(status);
  const key = (action: "dictation" | "toggle_captions" | "voice_note") => {
    const k = settings?.shortcuts?.[action];
    return k ? prettyShortcut(k, status.platform) : null;
  };
  const typeLabel = (t: Prep["meeting_type"]) => MEETING_TYPES.find((m) => m.value === t)?.label ?? "Meeting";

  return (
    <div className="el-page el-page--narrow" data-v2="live">
      <header className="el-page__head"><h1 className="el-title-l">Live</h1></header>

      <section className="el-group" aria-label="Record">
        <h2 className="el-caption el-body-strong el-group__title">Record</h2>
        <Card className="el-home-strip">
          <Status kind={strip.kind} live>{strip.text}</Status>
          <span className="el-page__actions">
            {onVoiceNote && !status.recording && <Button onClick={onVoiceNote} icon={Icons.MicIcon}>Voice note</Button>}
            {onRecord && <Button variant={status.recording ? "danger" : "primary"} icon={Icons.RecordIcon} onClick={onRecord}>{status.recording ? "Stop and save" : "Record now"}</Button>}
          </span>
        </Card>
        <Card>
          <List label="Recording" className="el-group__rows">
            <ListRow compact icon={Icons.PhoneIcon} iconStyle="plain" title="Calls and meetings record by themselves"
              value={status.autoMode ? "On" : "Off"} chevron onClick={() => onSettings("calls")} />
          </List>
        </Card>
      </section>

      {assistOn && (
        <section className="el-group" aria-label="Live Assist">
          <div className="el-group__head">
            <h2 className="el-caption el-body-strong el-group__title">Live Assist</h2>
            <Button size="sm" variant="primary" onClick={() => setPrep("new")}>New session</Button>
          </div>
          <Card>
            <List label="Upcoming sessions" className="el-group__rows">
              {(upcoming ?? []).length === 0 ? (
                <ListRow compact icon={Icons.CalendarIcon} iconStyle="plain" title="No sessions scheduled"
                  meta="Prepare a call: who it's with, your goals and documents. Suggestions appear on a card only you can see." onClick={() => setPrep("new")} />
              ) : (upcoming ?? []).map((p) => (
                <ListRow key={p.id} compact icon={Icons.ClockIcon} iconStyle="plain" title={p.title || typeLabel(p.meeting_type)}
                  meta={[typeLabel(p.meeting_type), p.counterpart, p.scheduled_at ? formatWhen(p.scheduled_at) : null].filter(Boolean).join(" · ")}
                  badge={p.status === "armed" ? <Badge tone="accent">Ready</Badge> : undefined} chevron onClick={() => setPrep(p)} />
              ))}
            </List>
          </Card>
        </section>
      )}

      <section className="el-group" aria-label="During a call">
        <h2 className="el-caption el-body-strong el-group__title">During a call</h2>
        <Card>
          <List label="During a call" className="el-group__rows">
            <ListRow compact icon={Icons.LanguagesIcon} iconStyle="plain" title="Live Translate" meta="Translate what a game or video says, as it is said"
              chevron onClick={() => setTranslateOpen(!translateOpen)} />
            <ListRow compact icon={Icons.KeyboardIcon} iconStyle="plain" title="Dictation" meta="Speak, and the words are typed where you are"
              value={key("dictation") ?? undefined} chevron onClick={() => onSettings("voice")} />
            <ListRow compact icon={Icons.CaptionsIcon} iconStyle="plain" title="Captions"
              meta={status.recording ? (status.captionsLive ? "On for this recording" : "Off for this recording") : "Live text while you record"}
              value={key("toggle_captions") ?? undefined}
              control={status.recording ? <Button size="sm" onClick={onCaptions}>{status.captionsLive ? "Turn off" : "Turn on"}</Button> : undefined}
              chevron={!status.recording} onClick={status.recording ? undefined : () => onSettings("captions")} />
          </List>
        </Card>
        {translateOpen && translate && <div className="el-live-translate">{translate}</div>}
      </section>
    </div>
  );
}
