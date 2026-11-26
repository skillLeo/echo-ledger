// Home in the new look (ui_v2.home, IA 1): the status strip, the get-started checklist until everything is done, what
// needs action (failed uploads, calls waiting for a decision, recordings kept as audio only, missed calls), the last five
// recordings and the month's minutes. Everything comes from data the app already reads. In the window's language (D-261),
// the status strip too, beside its buttons.
import { RowMetaText } from "../library/RecordingRow";
import { useEffect, useState } from "react";
import { api, type AppStatus, type LibraryItem, type SkippedItem } from "../../lib/ipc";
import { useReadyReport } from "../../views/main/ReadyCheck";
import { accessOf, usePermissions } from "../../views/main/Permissions";
import { permissionKinds } from "../setup/SetupHealth";
import { useEntitlements } from "../../lib/entitlements";
import { Button, Card, EmptyState, Icon, Icons, List, ListRow, Loading, Progress, SkeletonRow, Status } from "../components";
import { shellStatus } from "../shell/status";
import { rowModel } from "../library/model";
import { statusOf } from "../library/facets";
import { minutesLine } from "../settings/AccountCard";
import { isViewer } from "../../lib/plan";
import { RecordSection, type Run } from "./RecordSection";
import { useClosedUntilChange } from "../../components/StateBanner";
import { lineDir, t, tc, useLanguage } from "../../i18n";

export type ChecklistItem = { id: "permissions" | "ready" | "first"; title: string; done: boolean; action: string };

/**
 * The get-started checklist (IA 1.1): permissions, the Ready check, a first recording, in the window's language (the
 * Library's Get started shows the same). Null once all are done.
 */
export function checklist(input: { permissionsOk: boolean | null; ready: boolean | null; recordings: number | null }): ChecklistItem[] | null {
  const items: ChecklistItem[] = [
    { id: "permissions", title: t("Allow the microphone and system audio"), done: input.permissionsOk === true, action: t("Set up") },
    { id: "ready", title: t("Pass the Ready check"), done: input.ready === true, action: t("Check") },
    // The verb (ریکارڈ کریں), where the rail's Record is the place.
    { id: "first", title: t("Make a test recording"), done: (input.recordings ?? 0) > 0, action: t("home_context.record") },
  ];
  return items.every((i) => i.done) ? null : items;
}

export type NeedsAction = { id: "failed" | "waiting" | "untranscribed" | "missed" | "notifications"; title: string; count: number; line?: string };

/** What needs action (IA 1.3), only the kinds with something in them, in the window's language. */
export function needsAction(input: { failed: number; waiting: number; untranscribed: number; missed: number; notifications?: number }): NeedsAction[] {
  const notifications = input.notifications ?? 0;
  const all: NeedsAction[] = [
    { id: "failed", count: input.failed, title: tc(":count recording didn't upload|:count recordings didn't upload", input.failed) },
    { id: "waiting", count: input.waiting, title: tc(":count call waits for your decision|:count calls wait for your decision", input.waiting) },
    { id: "untranscribed", count: input.untranscribed, title: tc(":count recording is audio only|:count recordings are audio only", input.untranscribed) },
    { id: "missed", count: input.missed, title: tc(":count short call was skipped in the last day|:count short calls were skipped in the last day", input.missed) },
    { id: "notifications", count: notifications, title: tc(":count notification needs you|:count notifications need you", notifications),
      line: t("A transcript that failed, the monthly limit, or an answer to your upgrade request.") },
  ];
  return all.filter((a) => a.count > 0);
}

export const NEEDS_ICONS: Record<NeedsAction["id"], typeof Icons.ErrorIcon> = {
  failed: Icons.ErrorIcon, waiting: Icons.ClockIcon, untranscribed: Icons.TranscriptIcon, missed: Icons.PhoneIcon, notifications: Icons.BellIcon,
};

/**
 * One thing that needs the person: a press opens it, × closes it until its count changes (classic's Home cards, the
 * same closed state: "home-waiting" closed in one design stays closed in the other for this run).
 */
function NeedsRow({ action: a, onOpen }: { action: NeedsAction; onOpen: () => void }) {
  const [closed, close] = useClosedUntilChange(`home-${a.id === "notifications" ? "problems" : a.id}`, String(a.count));
  if (closed) return null;
  return (
    <ListRow compact iconStyle="plain" icon={NEEDS_ICONS[a.id]} title={a.title} meta={a.line} onClick={onOpen} actionsShown="always"
      actions={<Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label={t("Dismiss: :title", { title: a.title })} title={t("Hide until it changes")} onClick={close} />} />
  );
}

/**
 * Why a viewer records nothing, and who can change it (lib/plan's viewerSentence, in the window's language): "You are a
 * viewer in Acme: you can open what is shared with you, not record. An owner or admin …".
 */
function viewerBody(workspace: string | null | undefined): string {
  const name = workspace?.trim();
  const sentence = name
    ? t("You are a viewer in :name: you can open what is shared with you, not record.", { name })
    : t("You are a viewer in this workspace: you can open what is shared with you, not record.");
  return `${sentence} ${t("An owner or admin of the workspace can change your role in the console.")}`;
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
  /** Runs a core command and says how it went (MainApp's run); without it the Record section isn't shown. */
  run?: Run;
  /** Unread notifications that need the person (a failed transcript, the limit, an upgrade answer): Activity has them. */
  notifications?: number;
  /** Upload: the file picker, then the import sheet. */
  onImport?: () => void;
};

export function HomeV2({ status, onRecord, onStop, onSetup, onReadyCheck, onOpenRecording, onLibrary, onAction, onPlan, run, onImport, notifications = 0 }: HomeV2Props) {
  useLanguage();
  const [report] = useReadyReport();
  const [permissions] = usePermissions();
  const entitlements = useEntitlements();
  const [items, setItems] = useState<LibraryItem[] | null>(null);
  const [itemsFailed, setItemsFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [skipped, setSkipped] = useState<SkippedItem[]>([]);

  useEffect(() => {
    setItemsFailed(false);
    api.library().then((list) => setItems(Array.isArray(list) ? list : []), () => setItemsFailed(true));
    api.skippedList().then((s) => setSkipped(Array.isArray(s) ? s : []), () => undefined);
  }, [attempt]);

  const strip = shellStatus(status);
  const kinds = permissionKinds(status.platform);
  const permissionsOk = permissions ? kinds.every((k) => accessOf(permissions, k) === "allowed") : null;
  const live = (items ?? []).filter((i) => !i.deleted);
  // The checklist waits for what it is made of, so nothing shows as "To do" only because it is still being read.
  const known = permissions !== null && (report !== null || status.callsReady != null) && items !== null;
  const list = known ? checklist({ permissionsOk, ready: report?.ready ?? status.callsReady ?? null, recordings: live.length }) : null;
  const actions = needsAction({
    failed: status.failedUploads,
    waiting: status.pendingDecisions,
    // The Audio only view's recordings (Status not_transcribed), so the line and the view it opens agree.
    untranscribed: live.filter((i) => statusOf(i) === "not_transcribed").length,
    missed: skipped.length,
    notifications,
  });
  const recent = [...live].sort((a, b) => Date.parse(b.started_at ?? "") - Date.parse(a.started_at ?? "")).slice(0, 5);
  const minutes = entitlements?.limits.monthly_minutes;
  const usage = minutesLine(minutes?.used, minutes?.limit);
  const runChecklist = (id: ChecklistItem["id"]) => (id === "permissions" ? onSetup() : id === "ready" ? onReadyCheck() : onRecord?.());

  return (
    <div className="el-page el-page--narrow" data-v2="home">
      <header className="el-page__head">
        {/* The Record page, as the Record menu and the window's search name it in Urdu (its English heading is Home). */}
        <h1 className="el-title-l">{t("home_context.title")}</h1>
        {onImport && !isViewer(status) && <div className="el-page__actions"><Button icon={Icons.ShareIcon} onClick={onImport}>{t("Upload")}</Button></div>}
      </header>

      <Card className="el-home-strip" label={t("Status")}>
        <Status kind={strip.kind} live>{strip.text}</Status>
        {strip.kind === "recording" ? (onStop && <Button variant="danger" onClick={onStop}>{t("Stop and save")}</Button>)
          : strip.kind === "setup" ? <Button variant="primary" onClick={onSetup}>{t("Fix setup")}</Button>
            : onRecord && <Button variant="primary" icon={Icons.RecordIcon} onClick={onRecord}>{t("home_context.record")}</Button>}
      </Card>
      {/* A viewer records nothing: said instead of a Record button that isn't there (classic's notice). */}
      {isViewer(status) && strip.kind !== "recording" && (
        <div className="el-home-viewer" data-testid="home-viewer">
          <Card>
            <EmptyState icon={Icons.RecordIcon} title={t("Recording is off for viewers")} body={viewerBody(status.plan.workspace)} />
          </Card>
        </div>
      )}

      {run && (!isViewer(status) || status.recording) && <RecordSection status={status} run={run} onImport={onImport} />}

      {list && (
        <section className="el-group" aria-label={t("Get started")}>
          <h2 className="el-caption el-body-strong el-group__title">{t("Get started")}</h2>
          <Card>
            <ol className="el-steps" aria-label={t("Get started")}>
              {list.map((item, i) => (
                <li key={item.id} className={`el-step${item.done ? " el-step--done" : ""}`}>
                  <span className="el-step__number" aria-hidden="true">{item.done ? <Icon icon={Icons.CheckIcon} /> : i + 1}</span>
                  <div className="el-step__main el-home-check">
                    <span className="el-step__title">{item.title}</span>
                    <span className="el-caption">{item.done ? t("Done") : t("To do")}</span>
                  </div>
                  {!item.done && <Button size="sm" onClick={() => runChecklist(item.id)}>{item.action}</Button>}
                </li>
              ))}
            </ol>
          </Card>
        </section>
      )}

      {actions.length > 0 && (
        <section className="el-group" aria-label={t("Needs action")}>
          <h2 className="el-caption el-body-strong el-group__title">{t("Needs action")}</h2>
          <Card>
            <List label={t("Needs action")} className="el-group__rows">
              {actions.map((a) => <NeedsRow key={a.id} action={a} onOpen={() => onAction(a.id)} />)}
            </List>
          </Card>
        </section>
      )}

      <section className="el-group" aria-label={t("Recent recordings")}>
        <div className="el-group__head">
          <h2 className="el-caption el-body-strong el-group__title">{t("Recent recordings")}</h2>
          <Button size="sm" variant="quiet" onClick={onLibrary}>{t("Open Library")}</Button>
        </div>
        <Card>
          {itemsFailed && !items ? (
            <EmptyState tone="error" icon={Icons.ErrorIcon} title={t("Recent recordings didn't load")}
              body={t("EchoLedger couldn't read the library. Recordings on this computer are safe.")} action={<Button onClick={() => setAttempt((n) => n + 1)}>{t("Try again")}</Button>} />
          ) : !items ? (
            <Loading label={t("Loading recent recordings")}><div className="el-group__rows">{Array.from({ length: 3 }, (_, i) => <SkeletonRow key={i} />)}</div></Loading>
          ) : recent.length === 0 ? (
            <p className="el-group__note el-home-empty el-secondary">{t("Your recordings appear here once you record a call or import a file.")}</p>
          ) : (
            <List label={t("Recent recordings")} className="el-group__rows">
              {recent.map((item) => {
                const r = rowModel(item);
                return (
                  <ListRow key={item.id} icon={(p) => <Icons.SourceAppIcon app={r.app} {...p} />} title={r.title} meta={<RowMetaText r={r} />}
                    endTop={r.when} endBottom={r.duration ? <span className="el-ltr">{r.duration}</span> : undefined}
                    onClick={() => onOpenRecording(item.id)} />
                );
              })}
            </List>
          )}
        </Card>
      </section>

      {usage && (
        <section className="el-group" aria-label={t("This month")}>
          <h2 className="el-caption el-body-strong el-group__title">{t("This month")}</h2>
          <Card className="el-home-usage">
            <span dir={lineDir(usage)}>{usage}</span>
            {minutes?.limit != null && minutes.used != null && <Progress label={t("Minutes used")} value={minutes.used} max={minutes.limit} valueText={usage} />}
            <Button size="sm" variant="quiet" onClick={onPlan}>{t("Plan & usage")}</Button>
          </Card>
        </section>
      )}
    </div>
  );
}
