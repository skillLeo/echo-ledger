// The top of the v2 Library list, as today's Library has it (views/main/LibraryQueue.tsx, the same hook and commands):
// recordings waiting for Keep / Discard behind one line "2 calls waiting · Review", files being imported with Cancel,
// uploads that need the person with Retry / Discard, recordings waiting for another account, and uploads the server is
// still checking. Built from the v2 card, list rows, buttons and menus only.
import { useRef, useState, type ReactNode } from "react";
import type { PendingItem, UploadItem } from "../../lib/ipc";
import { MAIN_LANGUAGES, MORE_LANGUAGES, menuLabel } from "../../lib/languages";
import { formatDuration, formatWhen, sourceAppLabel } from "../../lib/format";
import { heldLine, importProgressText, pendingTitle, uploadTitle, waitingLine, waitingSummary } from "../../lib/pending";
import { useLibraryQueue } from "../../views/main/LibraryQueue";
import type { Notify } from "../../lib/notice";
import { Banner, Button, Card, Dialog, Icons, List, ListRow, Progress, Status, TextField } from "../components";
import { Menu, type MenuItem } from "../components/Menu";
import { SourceAvatar } from "./SourceAvatar";

const MAX_TITLE_CHARS = 200;

const meta = (sourceApp: string, startedAt: string, durationMs: number, ...extra: (string | null | undefined)[]) =>
  [sourceAppLabel(sourceApp), formatWhen(startedAt), formatDuration(durationMs) || "0:00", ...extra].filter(Boolean).join(" · ");

const avatar = (item: { sourceApp: string }) => <SourceAvatar size="sm" item={{ source_app: item.sourceApp, source_app_label: sourceAppLabel(item.sourceApp), source_device: undefined }} />;

export function QueueV2({ say }: { say: (text: string) => void }) {
  const notify: Notify = (text) => say(text);
  const q = useLibraryQueue(notify);
  const { pending, failed, verifying, importing, held, clients, busy, bulkBusy, learn } = q;
  const [open, setOpen] = useState(false);
  const [renaming, setRenaming] = useState<{ item: PendingItem; text: string } | null>(null);
  const field = useRef<HTMLInputElement>(null);
  if (pending.length === 0 && failed.length === 0 && verifying.length === 0 && importing.length === 0 && held.length === 0) return null;
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
      { label: "Rename…", icon: Icons.RenameIcon, onPick: () => setRenaming({ item, text: item.title ?? "" }) },
      {
        label: "Choose project", icon: Icons.MoveIcon,
        items: [
          { label: "No project", radio: true, checked: !item.clientId, onPick: () => void q.assign(item, "") },
          ...(!known ? [{ label: item.clientName ?? item.clientId ?? "", radio: true, checked: true }] : []),
          ...(clients ?? []).map((c) => ({ label: c.name, radio: true, checked: c.id === item.clientId, onPick: () => void q.assign(item, c.id) })),
        ],
      },
      {
        label: "Language", icon: Icons.LanguagesIcon,
        items: [
          ...MAIN_LANGUAGES.map((o) => ({ label: menuLabel(o), radio: true, checked: (o.value ?? null) === language, onPick: () => void q.setLanguage(item, o.value ?? "") })),
          ...MORE_LANGUAGES.map((o) => ({ label: o.label, group: "More languages", radio: true, checked: (o.value ?? null) === language, onPick: () => void q.setLanguage(item, o.value ?? "") })),
        ],
      },
    ];
  };

  return (
    <div className="el-library-top" data-testid="library-queue">
      {pending.length > 0 && (
        <Card className="el-library-setup" label="Waiting recordings">
          <div className="el-group__head" data-testid="waiting-line" title={waitingSummary(pending)}>
            <h2 className="el-body-strong">{waitingLine(pending)}</h2>
            <Button size="sm" variant="quiet" aria-expanded={shown} aria-controls={shown ? "queue-pending-list" : undefined} onClick={review}>
              {shown ? "Hide" : "Review"}
            </Button>
          </div>
          {shown && (
            <div id="queue-pending-list" className="el-library-top">
              <div className="el-page__actions">
                <Button size="sm" variant="primary" disabled={bulkBusy} onClick={() => void q.keepAll()}>Keep all</Button>
                <Button size="sm" disabled={bulkBusy} onClick={() => void q.discardAll()}>Discard all</Button>
              </div>
              {learn && (
                <div role="group" aria-label="Remember the language">
                  <Banner icon={Icons.LanguagesIcon}
                    action={<span className="el-page__actions">
                      <Button size="sm" variant="primary" onClick={() => void q.answerLearn(true)}>Yes</Button>
                      <Button size="sm" variant="quiet" onClick={() => void q.answerLearn(false)}>No</Button>
                    </span>}>
                    {learn.question}
                  </Banner>
                </div>
              )}
              <List label="Waiting recordings">
                {pending.map((item) => {
                  const title = pendingTitle(item);
                  const isBusy = bulkBusy || busy === item.id;
                  return (
                    <ListRow key={item.id} title={title} strong leading={avatar(item)}
                      meta={meta(item.sourceApp, item.startedAt, item.durationMs, item.clientName, item.firstLine)}
                      control={<span className="el-page__actions" role="group" aria-label={title} data-testid={`pending-${item.id}`}>
                        <Menu label={`More for ${title}`} items={() => more(item)} button={{ size: "sm", variant: "quiet", icon: Icons.MoreIcon, disabled: isBusy }} />
                        <Button size="sm" disabled={isBusy} onClick={() => void q.discard(item)}>Discard</Button>
                        <Button size="sm" variant="primary" disabled={isBusy} onClick={() => void q.keep(item)}>Keep</Button>
                      </span>} />
                  );
                })}
              </List>
            </div>
          )}
        </Card>
      )}
      {importing.length > 0 && (
        <Section title="Importing" count={importing.length}>
          {importing.map((item) => {
            const title = uploadTitle(item);
            const percent = item.state === "uploading" ? item.progress ?? 0 : 0;
            return (
              <UploadRow key={item.id} item={item} meta={importProgressText(item)}
                control={<Button size="sm" variant="quiet" disabled={busy === item.id} onClick={() => void q.cancelImport(item)}>Cancel</Button>}
                after={<Progress label={`Upload of ${title}`} value={percent} />} />
            );
          })}
        </Section>
      )}
      {failed.length > 0 && (
        <Section title="Uploads that need you" count={failed.length}>
          {failed.map((item) => (
            <UploadRow key={item.id} item={item} meta={meta(item.sourceApp, item.startedAt, item.durationMs, item.lastError)}
              control={<span className="el-page__actions">
                <Button size="sm" variant="primary" disabled={busy === item.id} onClick={() => void q.retry(item)}>Retry</Button>
                <Button size="sm" disabled={busy === item.id} onClick={() => void q.discardUpload(item)}>Discard</Button>
              </span>} />
          ))}
        </Section>
      )}
      {held.map(([account, items]) => (
        <Section key={account} title="Waiting for another account" count={items.length} label={`Waiting for ${account}`} testId="held-uploads"
          note={heldLine(account, items.length)}
          end={items.length > 1 && <Button size="sm" disabled={bulkBusy} onClick={() => void q.discardHeld(items)}>Discard all {items.length}</Button>}>
          {items.map((item) => (
            <UploadRow key={item.id} item={item} meta={meta(item.sourceApp, item.startedAt, item.durationMs)}
              control={<Button size="sm" variant="quiet" disabled={bulkBusy || busy === item.id} onClick={() => void q.discardUpload(item)}>Discard</Button>} />
          ))}
        </Section>
      ))}
      {verifying.length > 0 && (
        <List label="Checking uploads">
          {verifying.map((item) => (
            <UploadRow key={item.id} item={item} meta={meta(item.sourceApp, item.startedAt, item.durationMs)}
              control={<Status kind="setup">Checking upload…</Status>} />
          ))}
        </List>
      )}
      {renaming && (
        <Dialog open title="Rename" onClose={() => setRenaming(null)} initialFocus={field}
          actions={<><Button onClick={() => setRenaming(null)}>Cancel</Button><Button variant="primary" onClick={() => void saveRename()}>Save</Button></>}>
          <form onSubmit={(e) => { e.preventDefault(); void saveRename(); }}>
            <TextField ref={field} label="Title" dir="auto" maxLength={MAX_TITLE_CHARS} value={renaming.text} placeholder={pendingTitle({ ...renaming.item, title: null })}
              onChange={(e) => setRenaming({ ...renaming, text: e.target.value })} />
          </form>
        </Dialog>
      )}
    </div>
  );
}

function Section({ title, count, label, note, end, testId, children }: {
  title: string; count: number; label?: string; note?: string; end?: ReactNode; testId?: string; children: ReactNode;
}) {
  return (
    <Card className="el-library-setup" label={label ?? title}>
      <div className="el-group__head" data-testid={testId}>
        <h2 className="el-body-strong">{title} · {count}</h2>
        {end}
      </div>
      {note && <p className="el-caption el-secondary">{note}</p>}
      <List label={title}>{children}</List>
    </Card>
  );
}

function UploadRow({ item, meta: line, control, after }: { item: UploadItem; meta: string; control: ReactNode; after?: ReactNode }) {
  const title = uploadTitle(item);
  return (
    <>
      <ListRow title={title} strong leading={avatar(item)} meta={line}
        control={<span className="el-page__actions" role="group" aria-label={title} data-testid={`upload-${item.id}`}>{control}</span>} />
      {after && <li className="el-group__progress">{after}</li>}
    </>
  );
}
