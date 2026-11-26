// The top of the v2 Library list, as today's Library has it (views/main/LibraryQueue.tsx, the same hook and commands):
// recordings waiting for Keep / Discard behind one line "2 calls waiting · Review", files being imported with Cancel,
// uploads that need the person with Retry / Discard, recordings waiting for another account, and uploads the server is
// still checking. Built from the v2 card, list rows, buttons and menus only. Every line is in the window's language
// (desktop Urdu, D1b): its own words through t() and tc(), lib's (the waiting line, the titles, the import's progress,
// the call choice's buttons) from their helpers in the same language, and the hook's (useLibraryQueue: its questions,
// its notices, the language question) in the window's language, as the v2 Library asks it.
import { Fragment, useEffect, useRef, useState, type ReactNode } from "react";
import type { PendingItem, UploadItem } from "../../lib/ipc";
import { MAIN_LANGUAGES, MORE_LANGUAGES, menuLabel } from "../../lib/languages";
import { formatDuration, formatWhen, sourceAppLabel } from "../../lib/format";
import { heldLine, importProgressText, pendingTitle, uploadTitle, waitingLine, waitingSummary } from "../../lib/pending";
import type { LibraryQueueState } from "../../views/main/LibraryQueue";
import { Banner, Button, Card, Dialog, Icons, List, ListRow, Progress, Status, TextField } from "../components";
import { Menu, type MenuItem } from "../components/Menu";
import { SourceAvatar } from "./SourceAvatar";
import { CALL_WORDS } from "../../lib/callChoice";
import { isRtl, lineDir, t, tc, useLanguage } from "../../i18n";

const MAX_TITLE_CHARS = 200;

/**
 * A row's line: its app, when, how long, then what else it has (a project, its first words, why it failed), joined by
 * dots. In Urdu the pieces read right to left from the first, each in its own isolate (a Latin app name and a number
 * would otherwise run together left to right); English as always.
 */
function meta(sourceApp: string, startedAt: string, durationMs: number, ...extra: (string | null | undefined)[]): ReactNode {
  const parts = [sourceAppLabel(sourceApp), formatWhen(startedAt), formatDuration(durationMs) || "0:00", ...extra].filter((p): p is string => !!p);
  if (!isRtl()) return parts.join(" · ");
  return <span dir="rtl">{parts.map((part, i) => <Fragment key={i}>{i > 0 && " · "}<bdi>{part}</bdi></Fragment>)}</span>;
}
/**
 * How many lines a row's line may take: in Urdu two, so a long one (its first words, why it failed) wraps before it is
 * cut and its start (the app and when) is never the part lost; one in English, as always.
 */
const metaLines = (): 1 | 2 => (isRtl() ? 2 : 1);

const avatar = (item: { sourceApp: string }) => <SourceAvatar size="sm" item={{ source_app: item.sourceApp, source_app_label: sourceAppLabel(item.sourceApp), source_device: undefined }} />;

/** Review requests already answered: a list that mounts again (after Trash, a selection, another page) keeps the
 * request it was given, and must not open again for it. */
const answered = new WeakSet<object>();

/**
 * What the queue shows with its own Review (the waiting calls, the uploads that need you), or null until both lists have
 * answered once: the Library's needs button leaves these to it, so there is one Review for each thing, and waits.
 */
export function queueCovers(q: LibraryQueueState): { waiting: boolean; failed: boolean } | null {
  return q.loaded ? { waiting: q.pending.length > 0, failed: q.failed.length > 0 } : null;
}

/**
 * The queue drawn from the Library's own copy of it (`queue`, useLibraryQueue in LibraryV2): it stays loaded and
 * follows the core's events while this list is away (Trash, a selection, a recording open), so what the header says
 * about it is right the moment the list is back. `openWaiting`: the Waiting place is open, so its calls are listed.
 */
export function QueueV2({ queue: q, reviewRequest, openWaiting = false, parts = "all" }: {
  queue: LibraryQueueState;
  reviewRequest?: { seq: number } | null;
  openWaiting?: boolean;
  /**
   * Which parts it shows (PLAN_LIBRARY_FILTERS): "needs", what needs the person (calls waiting for a decision, uploads
   * that failed, recordings waiting for another account: Needs you's own list); "progress", what is on its way
   * (imports with Cancel, uploads being checked: every other view); "all", both.
   */
  parts?: "all" | "needs" | "progress";
}) {
  const { pending, failed, verifying, importing, held, clients, busy, bulkBusy, learn } = q;
  // Drawn again in the new language when it changes.
  useLanguage();
  const [open, setOpen] = useState(false);
  const [renaming, setRenaming] = useState<{ item: PendingItem; text: string } | null>(null);
  const field = useRef<HTMLInputElement>(null);
  // Activity's Review: the list opens, the first waiting recording in view; once per request.
  useEffect(() => {
    if (!reviewRequest || answered.has(reviewRequest)) return;
    answered.add(reviewRequest);
    setOpen(true);
    q.loadClients();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reviewRequest?.seq]);
  useEffect(() => {
    if (open && reviewRequest) document.getElementById("queue-pending-list")?.scrollIntoView?.({ block: "nearest" });
  }, [open, reviewRequest]);
  // The Waiting place counts these calls: opening it lists them (Hide still folds them).
  useEffect(() => {
    if (!openWaiting) return;
    setOpen(true);
    q.loadClients();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openWaiting]);
  // Once nothing waits the list closes; the next recording to arrive starts with the summary again (classic's rule).
  // (Only once something has waited: the list reads its recordings after a Review request has opened it.)
  const hadWaiting = useRef(false);
  useEffect(() => {
    if (pending.length > 0) hadWaiting.current = true;
    else if (hadWaiting.current) {
      hadWaiting.current = false;
      setOpen(false);
    }
  }, [pending.length]);
  const needs = parts !== "progress";
  const progress = parts !== "needs";
  if ((!needs || (pending.length === 0 && failed.length === 0 && held.length === 0)) && (!progress || (verifying.length === 0 && importing.length === 0))) return null;
  const shown = open && pending.length > 0;

  const review = () => {
    setOpen((v) => !v);
    q.loadClients();
  };
  const saveRename = async () => {
    const current = renaming;
    setRenaming(null);
    if (current) await q.rename(current.item.id, current.text);
  };

  /** Rename…, Choose project ▸ and Language ▸ of one waiting recording; Keep and Discard stay on the row. */
  const more = (item: PendingItem): MenuItem[] => {
    const language = item.language ?? null;
    const known = !item.clientId || (clients ?? []).some((c) => c.id === item.clientId);
    return [
      { label: t("Rename…"), icon: Icons.RenameIcon, onPick: () => setRenaming({ item, text: item.title ?? "" }) },
      {
        label: t("Choose project"), icon: Icons.MoveIcon,
        items: [
          { label: t("No project"), radio: true, checked: !item.clientId, onPick: () => void q.assign(item, "") },
          ...(!known ? [{ label: item.clientName ?? item.clientId ?? "", radio: true, checked: true }] : []),
          ...(clients ?? []).map((c) => ({ label: c.name, radio: true, checked: c.id === item.clientId, onPick: () => void q.assign(item, c.id) })),
        ],
      },
      {
        label: t("Language"), icon: Icons.LanguagesIcon,
        items: [
          ...MAIN_LANGUAGES.map((o) => ({ label: menuLabel(o), radio: true, checked: (o.value ?? null) === language, onPick: () => void q.setLanguage(item, o.value ?? "") })),
          ...MORE_LANGUAGES.map((o) => ({ label: o.label, group: t("More languages"), radio: true, checked: (o.value ?? null) === language, onPick: () => void q.setLanguage(item, o.value ?? "") })),
        ],
      },
    ];
  };

  return (
    <div className="el-library-top" data-testid="library-queue">
      {needs && pending.length > 0 && (
        <Card className="el-library-setup" label={t("Waiting recordings")}>
          <div className="el-group__head" data-testid="waiting-line" title={waitingSummary(pending)}>
            <h2 className="el-title-s">{waitingLine(pending)}</h2>
            <Button size="sm" variant="quiet" aria-expanded={shown} aria-controls={shown ? "queue-pending-list" : undefined} onClick={review}>
              {shown ? t("Hide") : t("Review")}
            </Button>
          </div>
          {shown && (
            <div id="queue-pending-list" className="el-library-top">
              <div className="el-page__actions">
                <Button size="sm" variant="primary" disabled={bulkBusy} onClick={() => void q.keepAll()}>{t("Transcribe all")}</Button>
                <Button size="sm" disabled={bulkBusy} onClick={() => void q.keepAudioAll()}>{CALL_WORDS.save_audio_all}</Button>
                <Button size="sm" disabled={bulkBusy} onClick={() => void q.discardAll()}>{t("Delete all")}</Button>
              </div>
              {learn && (
                <div role="group" aria-label={t("Remember the language")}>
                  <Banner icon={Icons.LanguagesIcon}
                    action={<span className="el-page__actions">
                      <Button size="sm" variant="primary" onClick={() => void q.answerLearn(true)}>{t("Yes")}</Button>
                      <Button size="sm" variant="quiet" onClick={() => void q.answerLearn(false)}>{t("No")}</Button>
                    </span>}>
                    {learn.question}
                  </Banner>
                </div>
              )}
              <List label={t("Waiting recordings")}>
                {pending.map((item) => {
                  const title = pendingTitle(item);
                  const isBusy = bulkBusy || busy === item.id;
                  return (
                    <ListRow key={item.id} title={title} strong leading={avatar(item)} metaLines={metaLines()}
                      meta={item.restoredDelete ? <>
                        {meta(item.sourceApp, item.startedAt, item.durationMs, item.clientName, item.firstLine)}
                        <span className="el-queue-restored" data-testid="restored-delete" dir={lineDir(CALL_WORDS.restored_delete)}>{CALL_WORDS.restored_delete}</span>
                      </> : meta(item.sourceApp, item.startedAt, item.durationMs, item.clientName, item.firstLine)}
                      control={<span className="el-page__actions" role="group" aria-label={title} data-testid={`pending-${item.id}`}>
                        <Menu label={t("More for :title", { title })} items={() => more(item)} button={{ size: "sm", variant: "quiet", icon: Icons.MoreIcon, disabled: isBusy }} />
                        <Button size="sm" disabled={isBusy} onClick={() => void q.discard(item)}>{t("Delete")}</Button>
                        <Button size="sm" disabled={isBusy} onClick={() => void q.keepAudio(item)}>{CALL_WORDS.save_audio_only}</Button>
                        <Button size="sm" disabled={isBusy} onClick={() => void q.keep(item)}>{t("Transcribe")}</Button>
                      </span>} />
                  );
                })}
              </List>
            </div>
          )}
        </Card>
      )}
      {progress && importing.length > 0 && (
        <Section title={t("Importing")} heading={tc("Importing · :count", importing.length)}>
          {importing.map((item) => {
            const title = uploadTitle(item);
            const percent = item.state === "uploading" ? item.progress ?? 0 : 0;
            return (
              <UploadRow key={item.id} item={item} meta={importProgressText(item)}
                control={<Button size="sm" variant="quiet" disabled={busy === item.id} onClick={() => void q.cancelImport(item)}>{t("Cancel")}</Button>}
                after={<Progress label={t("Upload of :title", { title })} value={percent} />} />
            );
          })}
        </Section>
      )}
      {needs && failed.length > 0 && (
        <Section title={t("Uploads that need you")} heading={tc("Uploads that need you · :count", failed.length)}>
          {failed.map((item) => (
            <UploadRow key={item.id} item={item} meta={meta(item.sourceApp, item.startedAt, item.durationMs, item.lastError)}
              control={<span className="el-page__actions">
                <Button size="sm" variant="primary" disabled={busy === item.id} onClick={() => void q.retry(item)}>{t("Retry")}</Button>
                <Button size="sm" disabled={busy === item.id} onClick={() => void q.discardUpload(item)}>{t("Discard")}</Button>
              </span>} />
          ))}
        </Section>
      )}
      {needs && held.map(([account, items]) => (
        <Section key={account} title={t("Waiting for another account")} heading={tc("Waiting for another account · :count", items.length)}
          label={t("Waiting for :account", { account })} testId="held-uploads" note={heldLine(account, items.length)}
          end={items.length > 1 && <Button size="sm" disabled={bulkBusy} onClick={() => void q.discardHeld(items)}>{tc("Discard all :count", items.length)}</Button>}>
          {items.map((item) => (
            <UploadRow key={item.id} item={item} meta={meta(item.sourceApp, item.startedAt, item.durationMs)}
              control={<Button size="sm" variant="quiet" disabled={bulkBusy || busy === item.id} onClick={() => void q.discardUpload(item)}>{t("Discard")}</Button>} />
          ))}
        </Section>
      ))}
      {progress && verifying.length > 0 && (
        <List label={t("Checking uploads")}>
          {verifying.map((item) => (
            <UploadRow key={item.id} item={item} meta={meta(item.sourceApp, item.startedAt, item.durationMs)}
              control={<Status kind="setup">{t("Checking upload…")}</Status>} />
          ))}
        </List>
      )}
      {renaming && (
        <Dialog open title={t("Rename")} onClose={() => setRenaming(null)} closeLabel={t("Close")} initialFocus={field}
          actions={<><Button onClick={() => setRenaming(null)}>{t("Cancel")}</Button><Button variant="primary" onClick={() => void saveRename()}>{t("Save")}</Button></>}>
          <form onSubmit={(e) => { e.preventDefault(); void saveRename(); }}>
            <TextField ref={field} label={t("Title")} dir="auto" maxLength={MAX_TITLE_CHARS} value={renaming.text} placeholder={pendingTitle({ ...renaming.item, title: null })}
              onChange={(e) => setRenaming({ ...renaming, text: e.target.value })} />
          </form>
        </Dialog>
      )}
    </div>
  );
}

/** A card of uploads: `title` names it and its list, `heading` is the line it shows with its count ("Importing · 2"). */
function Section({ title, heading, label, note, end, testId, children }: {
  title: string; heading: string; label?: string; note?: string; end?: ReactNode; testId?: string; children: ReactNode;
}) {
  return (
    <Card className="el-library-setup" label={label ?? title}>
      <div className="el-group__head" data-testid={testId}>
        <h2 className="el-title-s">{heading}</h2>
        {end}
      </div>
      {note && <p className="el-caption el-secondary">{note}</p>}
      <List label={title}>{children}</List>
    </Card>
  );
}

function UploadRow({ item, meta: line, control, after }: { item: UploadItem; meta: ReactNode; control: ReactNode; after?: ReactNode }) {
  const title = uploadTitle(item);
  return (
    <>
      <ListRow title={title} strong leading={avatar(item)} meta={line} metaLines={metaLines()}
        control={<span className="el-page__actions" role="group" aria-label={title} data-testid={`upload-${item.id}`}>{control}</span>} />
      {after && <li className="el-group__progress">{after}</li>}
    </>
  );
}
