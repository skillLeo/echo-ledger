// Live Assist's place where things start: its upcoming sessions and New session, which open the prep screen
// (PrepScreen) and from there the Live Assist card's window. The new design's Live tab shows it, and so does classic's
// Record page (docs/PLAN_V2_PARITY.md: Live Assist is on for everyone and has no classic counterpart, so classic people
// reach the same window and card from where classic starts things). In the window's language on Live (D-261); in
// English on classic's Record page, which is English.
import { useEffect, useState } from "react";
import { formatWhen } from "../../lib/format";
import { isolate, isRtl, useLanguage, wordsIn, type Lang } from "../../i18n";
import { Badge, Button, Card, EmptyState, Icons, List, ListRow, Loading, SkeletonRow } from "../components";
import { assist, type Prep } from "../assist/adapter";
import { MEETING_TYPES, PrepScreen } from "../assist/PrepScreen";

/**
 * The section: its heading, New session, and the scheduled sessions (each opens its prep). In `lang`: the window's when
 * left out, "en" on classic's English page.
 */
export function LiveAssistSection({ onPrep, lang }: { onPrep: (prep: Prep | "new") => void; lang?: Lang }) {
  useLanguage();
  const { t } = wordsIn(lang);
  const [upcoming, setUpcoming] = useState<Prep[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let live = true;
    setFailed(false);
    assist.preps("scheduled").then((p) => live && setUpcoming(Array.isArray(p) ? p : []), () => live && setFailed(true));
    return () => {
      live = false;
    };
  }, [attempt]);
  // The prep screen's names for the kinds of meeting.
  const typeLabel = (type: Prep["meeting_type"]) => {
    const found = MEETING_TYPES.find((m) => m.value === type);
    return found ? t(found.label) : t("Meeting");
  };
  // Who it is with is their own name: in its own isolate inside an Urdu line.
  const named = (name: string) => (lang !== "en" && isRtl() ? isolate(name) : name);
  return (
    <section className="el-group" aria-label={t("Live Assist")}>
      <div className="el-group__head">
        <h2 className="el-caption el-body-strong el-group__title">{t("Live Assist")}</h2>
        <Button size="sm" variant="primary" onClick={() => onPrep("new")}>{t("New session")}</Button>
      </div>
      <Card>
        {failed && !upcoming ? (
          <EmptyState tone="error" icon={Icons.ErrorIcon} title={t("Sessions didn't load")}
            body={t("EchoLedger couldn't reach the server. You can still start a new session.")} action={<Button onClick={() => setAttempt((n) => n + 1)}>{t("Try again")}</Button>} />
        ) : !upcoming ? (
          <Loading label={t("Loading sessions")}><div className="el-group__rows"><SkeletonRow end={false} /><SkeletonRow end={false} /></div></Loading>
        ) : (
        <List label={t("Upcoming sessions")} className="el-group__rows">
          {upcoming.length === 0 ? (
            <ListRow compact icon={Icons.CalendarIcon} iconStyle="plain" title={t("No sessions scheduled")}
              meta={t("Prepare a call: who it's with, your goals and documents. Suggestions appear on a private card, hidden from screen share.")} onClick={() => onPrep("new")} />
          ) : upcoming.map((p) => (
            <ListRow key={p.id} compact icon={Icons.ClockIcon} iconStyle="plain" title={p.title || typeLabel(p.meeting_type)}
              meta={[typeLabel(p.meeting_type), p.counterpart ? named(p.counterpart) : null, p.scheduled_at ? formatWhen(p.scheduled_at) : null].filter(Boolean).join(" · ")}
              badge={p.status === "armed" ? <Badge tone="accent">{t("Ready")}</Badge> : undefined} chevron onClick={() => onPrep(p)} />
          ))}
        </List>
        )}
      </Card>
    </section>
  );
}

/** Classic's Record page: the section, or the prep screen once a session is opened, in the new look's frame. */
export function LiveAssistClassic({ projects }: { projects: { id: string; name: string }[] }) {
  const [prep, setPrep] = useState<Prep | "new" | null>(null);
  return (
    <div className="el-root el-embedded el-assist-classic" data-v2="live-assist">
      {prep
        ? <PrepScreen prep={prep === "new" ? null : prep} projects={projects} onBack={() => setPrep(null)} onStarted={() => setPrep(null)} lang="en" />
        : <LiveAssistSection onPrep={setPrep} lang="en" />}
    </div>
  );
}
