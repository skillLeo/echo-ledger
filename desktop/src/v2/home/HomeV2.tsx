// Home in the new look (ui_v2.home, IA 1): the status strip, the get-started checklist until everything is done, what
// needs action (failed uploads, calls waiting for a decision, untranscribed recordings, missed calls), the last five
// recordings and the month's minutes. Everything comes from data the app already reads.
import { useEffect, useState } from "react";
import { api, type AppStatus, type LibraryItem, type SkippedItem } from "../../lib/ipc";
import { useReadyReport } from "../../views/main/ReadyCheck";
import { accessOf, usePermissions } from "../../views/main/Permissions";
import { permissionKinds } from "../setup/SetupHealth";
import { useEntitlements } from "../../lib/entitlements";
import { Badge, Button, Card, Icon, Icons, List, ListRow, Loading, Progress, SkeletonRow, Status } from "../components";
import { shellStatus } from "../shell/status";
import { isUntranscribed, rowModel } from "../library/model";
import { minutesLine } from "../settings/AccountCard";

export type ChecklistItem = { id: "permissions" | "ready" | "first"; title: string; done: boolean; action: string };

/** The get-started checklist (IA 1.1): permissions, the Ready check, a first recording. Null once all are done. */
export function checklist(input: { permissionsOk: boolean | null; ready: boolean | null; recordings: number | null }): ChecklistItem[] | null {
  const items: ChecklistItem[] = [
    { id: "permissions", title: "Allow the microphone and system audio", done: input.permissionsOk === true, action: "Set up" },
    { id: "ready", title: "Pass the Ready check", done: input.ready === true, action: "Check" },
    { id: "first", title: "Make a test recording", done: (input.recordings ?? 0) > 0, action: "Record" },
  ];
  return items.every((i) => i.done) ? null : items;
}

export type NeedsAction = { id: "failed" | "waiting" | "untranscribed" | "missed"; title: string; count: number };

/** What needs action (IA 1.3), only the kinds with something in them. */
export function needsAction(input: { failed: number; waiting: number; untranscribed: number; missed: number }): NeedsAction[] {
  const plural = (n: number, one: string, many: string) => (n === 1 ? one : many.replace("#", String(n)));
  const all: NeedsAction[] = [
    { id: "failed", count: input.failed, title: plural(input.failed, "1 recording didn't upload", "# recordings didn't upload") },
    { id: "waiting", count: input.waiting, title: plural(input.waiting, "1 call waits for your decision", "# calls wait for your decision") },
    { id: "untranscribed", count: input.untranscribed, title: plural(input.untranscribed, "1 recording isn't transcribed", "# recordings aren't transcribed") },
    { id: "missed", count: input.missed, title: plural(input.missed, "1 short call was skipped today", "# short calls were skipped today") },
  ];
  return all.filter((a) => a.count > 0);
}

export type HomeV2Props = {
  status: AppStatus;
  onRecord?: () => void;
  onStop?: () => void;
  onSetup: () => void;
  onReadyCheck: () => void;
  onOpenRecording: (id: string) => void;
  onLibrary: () => void;
  onAction: (id: NeedsAction["id"]) => void;
  onPlan: () => void;
};

export function HomeV2({ status, onRecord, onStop, onSetup, onReadyCheck, onOpenRecording, onLibrary, onAction, onPlan }: HomeV2Props) {
  const [report] = useReadyReport();
  const [permissions] = usePermissions();
  const entitlements = useEntitlements();
  const [items, setItems] = useState<LibraryItem[] | null>(null);
  const [skipped, setSkipped] = useState<SkippedItem[]>([]);

  useEffect(() => {
    api.library().then(setItems, () => setItems([]));
    api.skippedList().then((s) => setSkipped(Array.isArray(s) ? s : []), () => undefined);
  }, []);

  const strip = shellStatus(status);
  const kinds = permissionKinds(status.platform);
  const permissionsOk = permissions ? kinds.every((k) => accessOf(permissions, k) === "allowed") : null;
  const live = (items ?? []).filter((i) => !i.deleted);
  const list = checklist({ permissionsOk, ready: report?.ready ?? status.callsReady ?? null, recordings: items ? live.length : null });
  const actions = needsAction({
    failed: status.failedUploads,
    waiting: status.pendingDecisions,
    untranscribed: live.filter(isUntranscribed).length,
    missed: skipped.length,
  });
  const recent = [...live].sort((a, b) => Date.parse(b.started_at ?? "") - Date.parse(a.started_at ?? "")).slice(0, 5);
  const minutes = entitlements?.limits.monthly_minutes;
  const usage = minutesLine(minutes?.used, minutes?.limit);
  const runChecklist = (id: ChecklistItem["id"]) => (id === "permissions" ? onSetup() : id === "ready" ? onReadyCheck() : onRecord?.());

  return (
    <div className="el-page el-page--narrow" data-v2="home">
      <header className="el-page__head"><h1 className="el-title-l">Home</h1></header>

      <Card className="el-home-strip" label="Status">
        <Status kind={strip.kind} live>{strip.text}</Status>
        {strip.kind === "recording" ? (onStop && <Button variant="danger" onClick={onStop}>Stop and save</Button>)
          : strip.kind === "setup" ? <Button variant="primary" onClick={onSetup}>Fix setup</Button>
            : onRecord && <Button variant="primary" icon={Icons.RecordIcon} onClick={onRecord}>Record now</Button>}
      </Card>

      {list && (
        <section className="el-group" aria-label="Get started">
          <h2 className="el-caption el-body-strong el-group__title">Get started</h2>
          <Card>
            <ol className="el-steps" aria-label="Get started">
              {list.map((item, i) => (
                <li key={item.id} className={`el-step${item.done ? " el-step--done" : ""}`}>
                  <span className="el-step__number" aria-hidden="true">{item.done ? <Icon icon={Icons.CheckIcon} /> : i + 1}</span>
                  <div className="el-step__main el-home-check">
                    <span className="el-step__title">{item.title}</span>
                    <span className="el-caption">{item.done ? "Done" : "To do"}</span>
                  </div>
                  {!item.done && <Button size="sm" onClick={() => runChecklist(item.id)}>{item.action}</Button>}
                </li>
              ))}
            </ol>
          </Card>
        </section>
      )}

      {actions.length > 0 && (
        <section className="el-group" aria-label="Needs action">
          <h2 className="el-caption el-body-strong el-group__title">Needs action</h2>
          <Card>
            <List label="Needs action" className="el-group__rows">
              {actions.map((a) => (
                <ListRow key={a.id} compact iconStyle="plain"
                  icon={a.id === "failed" ? Icons.ErrorIcon : a.id === "waiting" ? Icons.ClockIcon : a.id === "untranscribed" ? Icons.TranscriptIcon : Icons.PhoneIcon}
                  title={a.title} chevron onClick={() => onAction(a.id)} />
              ))}
            </List>
          </Card>
        </section>
      )}

      <section className="el-group" aria-label="Recent recordings">
        <div className="el-group__head">
          <h2 className="el-caption el-body-strong el-group__title">Recent recordings</h2>
          <Button size="sm" variant="quiet" onClick={onLibrary}>Open Library</Button>
        </div>
        <Card>
          {!items ? (
            <Loading label="Loading recent recordings"><div className="el-group__rows">{Array.from({ length: 3 }, (_, i) => <SkeletonRow key={i} />)}</div></Loading>
          ) : recent.length === 0 ? (
            <p className="el-group__note el-home-empty el-secondary">Your recordings appear here once you record a call or import a file.</p>
          ) : (
            <List label="Recent recordings" className="el-group__rows">
              {recent.map((item) => {
                const r = rowModel(item);
                return (
                  <ListRow key={item.id} icon={(p) => <Icons.SourceAppIcon app={r.app} {...p} />} title={r.title} meta={r.meta}
                    badge={r.badge ? <Badge tone={r.badge.tone}>{r.badge.text}</Badge> : undefined}
                    endTop={r.when} endBottom={r.duration ? <span className="el-num">{r.duration}</span> : undefined}
                    onClick={() => onOpenRecording(item.id)} />
                );
              })}
            </List>
          )}
        </Card>
      </section>

      {usage && (
        <section className="el-group" aria-label="This month">
          <h2 className="el-caption el-body-strong el-group__title">This month</h2>
          <Card className="el-home-usage">
            <span>{usage}</span>
            {minutes?.limit != null && minutes.used != null && <Progress label="Minutes used" value={minutes.used} max={minutes.limit} valueText={usage} />}
            <Button size="sm" variant="quiet" onClick={onPlan}>Plan &amp; usage</Button>
          </Card>
        </section>
      )}
    </div>
  );
}
