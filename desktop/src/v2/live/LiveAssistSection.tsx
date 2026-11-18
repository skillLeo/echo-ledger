// Live Assist's place where things start: its upcoming sessions and New session, which open the prep screen
// (PrepScreen) and from there the Live Assist card's window. The new design's Live tab shows it, and so does classic's
// Record page (docs/PLAN_V2_PARITY.md: Live Assist is on for everyone and has no classic counterpart, so classic people
// reach the same window and card from where classic starts things).
import { useEffect, useState } from "react";
import { formatWhen } from "../../lib/format";
import { Badge, Button, Card, EmptyState, Icons, List, ListRow, Loading, SkeletonRow } from "../components";
import { assist, type Prep } from "../assist/adapter";
import { MEETING_TYPES, PrepScreen } from "../assist/PrepScreen";

/** The section: its heading, New session, and the scheduled sessions (each opens its prep). */
export function LiveAssistSection({ onPrep }: { onPrep: (prep: Prep | "new") => void }) {
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
  const typeLabel = (t: Prep["meeting_type"]) => MEETING_TYPES.find((m) => m.value === t)?.label ?? "Meeting";
  return (
    <section className="el-group" aria-label="Live Assist">
      <div className="el-group__head">
        <h2 className="el-caption el-body-strong el-group__title">Live Assist</h2>
        <Button size="sm" variant="primary" onClick={() => onPrep("new")}>New session</Button>
      </div>
      <Card>
        {failed && !upcoming ? (
          <EmptyState tone="error" icon={Icons.ErrorIcon} title="Sessions didn't load"
            body="EchoLedger couldn't reach the server. You can still start a new session." action={<Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
        ) : !upcoming ? (
          <Loading label="Loading sessions"><div className="el-group__rows"><SkeletonRow end={false} /><SkeletonRow end={false} /></div></Loading>
        ) : (
        <List label="Upcoming sessions" className="el-group__rows">
          {upcoming.length === 0 ? (
            <ListRow compact icon={Icons.CalendarIcon} iconStyle="plain" title="No sessions scheduled"
              meta="Prepare a call: who it's with, your goals and documents. Suggestions appear on a private card, hidden from screen share." onClick={() => onPrep("new")} />
          ) : upcoming.map((p) => (
            <ListRow key={p.id} compact icon={Icons.ClockIcon} iconStyle="plain" title={p.title || typeLabel(p.meeting_type)}
              meta={[typeLabel(p.meeting_type), p.counterpart, p.scheduled_at ? formatWhen(p.scheduled_at) : null].filter(Boolean).join(" · ")}
              badge={p.status === "armed" ? <Badge tone="accent">Ready</Badge> : undefined} chevron onClick={() => onPrep(p)} />
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
        ? <PrepScreen prep={prep === "new" ? null : prep} projects={projects} onBack={() => setPrep(null)} onStarted={() => setPrep(null)} />
        : <LiveAssistSection onPrep={setPrep} />}
    </div>
  );
}
