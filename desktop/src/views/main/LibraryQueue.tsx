import { useCallback, useEffect, useRef, useState } from "react";
import { api, errorMessage, listenTo, type PendingItem, type TranscriptionLanguage, type UploadItem } from "../../lib/ipc";
import { MAIN_LANGUAGES, MORE_LANGUAGES, learnPrompt, menuLabel, type LanguageChoice } from "../../lib/languages";
import { formatDuration, formatWhen, sourceAppLabel } from "../../lib/format";
import {
  DISCARD_CONFIRM,
  bulkNotice,
  discardAllConfirm,
  failedUploads,
  importProgressText,
  importingUploads,
  pendingTitle,
  uploadTitle,
  verifyingUploads,
  waitingSummary,
} from "../../lib/pending";
import { AlertIcon, ClockIcon, ImportIcon, SourceAppIcon } from "../../components/Icons";

/** A `main:navigate` request to bring one waiting recording into view, optionally with its title open for editing. */
export interface PendingFocus {
  id: string;
  rename: boolean;
  /** Bumped on every request, so asking for the same recording twice highlights it again. */
  seq: number;
}

type Client = { id: string; name: string };
type Notify = (text: string, error?: boolean) => void;

const HIGHLIGHT_MS = 4000;
const asList = <T,>(value: unknown): T[] => (Array.isArray(value) ? (value as T[]) : []);

const meta = (sourceApp: string, startedAt: string, durationMs: number, extra?: string | null) =>
  [sourceAppLabel(sourceApp), formatWhen(startedAt), formatDuration(durationMs) || "0:00", extra].filter(Boolean).join(" · ");

/**
 * The top of the Library list: recordings waiting for Keep / Discard (A2.5), imported files on their way up (with
 * Cancel), and uploads that stopped and need the user (A5.1). The lists come from the core and follow its
 * `pending:changed` / `uploads:changed` events. The waiting recordings sit behind one summary card (as on the
 * phone); Review opens the list.
 */
export function LibraryQueue({ notify, focus, onFocusHandled }: { notify: Notify; focus?: PendingFocus | null; onFocusHandled?: () => void }) {
  const [pending, setPending] = useState<PendingItem[]>([]);
  const [uploads, setUploads] = useState<UploadItem[]>([]);
  const [open, setOpen] = useState(false);
  const [renaming, setRenaming] = useState<{ id: string; text: string } | null>(null);
  const [assigning, setAssigning] = useState<string | null>(null);
  const [clients, setClients] = useState<Client[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [bulkBusy, setBulkBusy] = useState(false);
  const [highlight, setHighlight] = useState<{ id: string; seq: number } | null>(null);
  const [learn, setLearn] = useState<{ itemId: string; clientId: string; question: string; language: LanguageChoice } | null>(null);
  const rows = useRef(new Map<string, HTMLElement>());
  // The row a focus request asked for; scrolled to once the opened list has rendered it.
  const scrollTo = useRef<string | null>(null);
  const appliedFocus = useRef(0);
  // Mirrors `renaming` so Enter followed by the input's blur saves once.
  const renameRef = useRef<{ id: string; text: string } | null>(null);
  const setRename = (value: { id: string; text: string } | null) => {
    renameRef.current = value;
    setRenaming(value);
  };

  const pendingLoads = useRef(0);
  const reloadPending = useCallback(() => {
    pendingLoads.current++;
    api.pendingList().then(
      (items) => {
        pendingLoads.current--;
        setPending(asList(items));
      },
      () => {
        pendingLoads.current--;
      },
    );
  }, []);
  const reloadUploads = useCallback(() => {
    api.uploadList().then((items) => setUploads(asList(items))).catch(() => undefined);
  }, []);

  useEffect(() => {
    reloadPending();
    reloadUploads();
    const subs = [
      listenTo("pending:changed", (items) => setPending(asList(items))),
      listenTo("uploads:changed", (items) => setUploads(asList(items))),
    ];
    return () => subs.forEach((p) => p.then((f) => f()).catch(() => undefined));
  }, [reloadPending, reloadUploads]);

  // Applied once the requested item is in the list (the list may still be loading when the request arrives, or the
  // item may be newer than the last `pending:changed`, so an unknown id asks the core once).
  const refetchedFor = useRef(0);
  useEffect(() => {
    if (!focus || focus.seq === appliedFocus.current) return;
    const item = pending.find((p) => p.id === focus.id);
    if (!item) {
      if (refetchedFor.current !== focus.seq && pendingLoads.current === 0) {
        refetchedFor.current = focus.seq;
        reloadPending();
      }
      return;
    }
    appliedFocus.current = focus.seq;
    setOpen(true);
    scrollTo.current = item.id;
    setHighlight({ id: item.id, seq: focus.seq });
    if (focus.rename) setRename({ id: item.id, text: item.title ?? "" });
    // Handled: coming back to the Library later must not highlight or open the title again.
    onFocusHandled?.();
  }, [focus, pending]);

  useEffect(() => {
    const id = scrollTo.current;
    const el = id ? rows.current.get(id) : undefined;
    if (!el) return;
    scrollTo.current = null;
    el.scrollIntoView?.({ block: "center" });
  }, [open, highlight, pending]);

  useEffect(() => {
    if (!highlight) return;
    const timer = window.setTimeout(() => setHighlight(null), HIGHLIGHT_MS);
    return () => window.clearTimeout(timer);
  }, [highlight]);

  // Once nothing waits the card goes away; the next recording to arrive starts with the summary again.
  useEffect(() => {
    if (pending.length === 0) setOpen(false);
  }, [pending.length]);

  /** Runs one command for an item; on failure reports the core's message and re-reads both lists. */
  const perform = async (id: string, fn: () => Promise<unknown>, ok?: string): Promise<boolean> => {
    setBusy(id);
    try {
      await fn();
      if (ok) notify(ok);
      return true;
    } catch (e) {
      notify(errorMessage(e), true);
      reloadPending();
      reloadUploads();
      return false;
    } finally {
      setBusy(null);
    }
  };

  const drop = (id: string) => setPending((list) => list.filter((i) => i.id !== id));
  const replace = (updated: PendingItem | undefined) => {
    if (updated?.id) setPending((list) => list.map((i) => (i.id === updated.id ? updated : i)));
  };

  const keep = async (item: PendingItem) => {
    if (await perform(item.id, () => api.pendingKeep(item.id), "Kept")) drop(item.id);
  };

  const discard = async (item: PendingItem) => {
    if (!window.confirm(DISCARD_CONFIRM)) return;
    if (await perform(item.id, () => api.pendingDiscard(item.id), "Discarded")) drop(item.id);
  };

  /** Keep all / Discard all: one command per recording, in order; a failure is reported and the rest still run. */
  const decideAll = async (verb: "Kept" | "Discarded", command: (id: string) => Promise<unknown>) => {
    const items = pending;
    setBulkBusy(true);
    let done = 0;
    try {
      for (const item of items) {
        if (await perform(item.id, () => command(item.id))) {
          drop(item.id);
          done++;
        }
      }
    } finally {
      setBulkBusy(false);
    }
    if (done > 0) notify(bulkNotice(verb, done));
  };

  const keepAll = () => decideAll("Kept", (id) => api.pendingKeep(id));

  const discardAll = () => {
    if (!window.confirm(discardAllConfirm(pending.length))) return;
    return decideAll("Discarded", (id) => api.pendingDiscard(id));
  };

  const saveRename = async () => {
    const current = renameRef.current;
    if (!current) return;
    setRename(null);
    const item = pending.find((i) => i.id === current.id);
    const text = current.text.trim();
    if (!item || text === (item.title ?? "").trim()) return;
    let updated: PendingItem | undefined;
    await perform(item.id, async () => (updated = await api.pendingRename(item.id, text)));
    replace(updated);
  };

  const toggleAssign = (item: PendingItem) => {
    setAssigning((current) => (current === item.id ? null : item.id));
    if (clients) return;
    api
      .clients()
      .then((r) => setClients(r.data))
      .catch((e) => {
        setClients([]);
        notify(errorMessage(e), true);
      });
  };

  const assign = async (item: PendingItem, value: string) => {
    setAssigning(null);
    const clientId = value || null;
    if (clientId === item.clientId) return;
    let updated: PendingItem | undefined;
    await perform(item.id, async () => (updated = await api.pendingAssignClient(item.id, clientId)));
    replace(updated);
  };

  /** The language chip of a waiting recording: Keep transcribes in it; a client using another language is asked. */
  const setLanguage = async (item: PendingItem, value: string) => {
    const language = (value || null) as LanguageChoice;
    if (language === (item.language ?? null)) return;
    let updated: PendingItem | undefined;
    if (!(await perform(item.id, async () => (updated = await api.pendingSetLanguage(item.id, language))))) return;
    replace(updated);
    if (!item.clientId) return;
    const client = (await api.clients().catch(() => null))?.data.find((c) => c.id === item.clientId);
    const question = client ? learnPrompt(client.name, client.language, language) : null;
    setLearn(question && client ? { itemId: item.id, clientId: client.id, question, language } : null);
  };

  const answerLearn = async (yes: boolean) => {
    const asked = learn;
    setLearn(null);
    if (yes && asked) await perform(asked.itemId, () => api.updateClient(asked.clientId, { language: asked.language }), "Saved for this client");
  };

  const retry = async (item: UploadItem) => {
    if (await perform(item.id, () => api.uploadRetry(item.id))) {
      setUploads((list) => list.map((i) => (i.id === item.id ? { ...i, state: "queued", lastError: null } : i)));
    }
  };

  const discardUpload = async (item: UploadItem) => {
    // An imported file is still on the computer where it came from, so only a recording made here is asked about.
    if (!item.imported && !window.confirm(DISCARD_CONFIRM)) return;
    if (await perform(item.id, () => api.uploadDiscard(item.id), "Discarded")) setUploads((list) => list.filter((i) => i.id !== item.id));
  };

  const cancelImport = async (item: UploadItem) => {
    if (await perform(item.id, () => api.uploadDiscard(item.id), "Import cancelled")) setUploads((list) => list.filter((i) => i.id !== item.id));
  };

  const failed = failedUploads(uploads);
  const verifying = verifyingUploads(uploads);
  const importing = importingUploads(uploads);
  if (pending.length === 0 && failed.length === 0 && verifying.length === 0 && importing.length === 0) return null;

  return (
    <div className="queue">
      {pending.length > 0 && (
        <section className="queue-section" aria-labelledby="queue-pending-title">
          <div className="queue-card">
            <div className="queue-card-main">
              <div className="queue-head">
                <ClockIcon size={14} />
                <h2 id="queue-pending-title" className="queue-label">Waiting for your decision</h2>
                <span className="queue-count">{pending.length}</span>
              </div>
              <div className="small muted queue-meta" data-testid="waiting-summary">{waitingSummary(pending)}</div>
            </div>
            <button
              type="button"
              className={`btn compact ${open ? "ghost" : "primary"}`}
              aria-expanded={open}
              aria-controls={open ? "queue-pending-list" : undefined}
              onClick={() => setOpen((v) => !v)}
            >
              {open ? "Hide" : "Review"}
            </button>
          </div>
          {open && (
            <div id="queue-pending-list" className="queue-list">
              <div className="queue-actions queue-bulk">
                <button type="button" className="btn compact" disabled={bulkBusy} onClick={() => void keepAll()}>Keep all</button>
                <button type="button" className="btn compact danger" disabled={bulkBusy} onClick={() => void discardAll()}>Discard all</button>
              </div>
              {pending.map((item) => {
                const title = pendingTitle(item);
                const isBusy = bulkBusy || busy === item.id;
                const knownClient = !item.clientId || (clients ?? []).some((c) => c.id === item.clientId);
                return (
                  <div
                    key={item.id}
                    ref={(el) => {
                      if (el) rows.current.set(item.id, el);
                      else rows.current.delete(item.id);
                    }}
                    role="group"
                    aria-label={title}
                    className={`queue-item pending ${highlight?.id === item.id ? "highlight" : ""}`}
                    data-testid={`pending-${item.id}`}
                  >
                    <div className="row">
                      <span className="avatar desktop"><SourceAppIcon app={item.sourceApp} size={18} /></span>
                      <div className="item-main">
                        {renaming?.id === item.id ? (
                          <input
                            className="input queue-rename"
                            aria-label="Title"
                            autoFocus
                            maxLength={200}
                            value={renaming.text}
                            placeholder={pendingTitle({ ...item, title: null })}
                            onChange={(e) => setRename({ id: item.id, text: e.target.value })}
                            onKeyDown={(e) => {
                              if (e.key === "Enter") void saveRename();
                              if (e.key === "Escape") setRename(null);
                            }}
                            onBlur={() => void saveRename()}
                          />
                        ) : (
                          <div className="queue-title">{title}</div>
                        )}
                        <div className="small muted queue-meta">{meta(item.sourceApp, item.startedAt, item.durationMs, item.clientName)}</div>
                      </div>
                    </div>
                    {item.firstLine && <div className="item-preview">{item.firstLine}</div>}
                    {learn?.itemId === item.id && (
                      <div className="queue-learn" role="group" aria-label="Remember the language">
                        <span className="small">{learn.question}</span>
                        <button type="button" className="btn primary compact" onClick={() => void answerLearn(true)}>Yes</button>
                        <button type="button" className="btn ghost compact" onClick={() => void answerLearn(false)}>No</button>
                      </div>
                    )}
                    {assigning === item.id && (
                      <select
                        className="input queue-client"
                        aria-label="Client"
                        autoFocus
                        value={item.clientId ?? ""}
                        onChange={(e) => void assign(item, e.target.value)}
                        onBlur={() => setAssigning(null)}
                        onKeyDown={(e) => e.key === "Escape" && setAssigning(null)}
                      >
                        <option value="">No client</option>
                        {!knownClient && <option value={item.clientId ?? ""}>{item.clientName ?? item.clientId}</option>}
                        {(clients ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                      </select>
                    )}
                    <div className="queue-actions">
                      <button type="button" className="btn primary compact" disabled={isBusy} onClick={() => void keep(item)}>Keep</button>
                      <button type="button" className="btn compact" disabled={isBusy} onClick={() => void discard(item)}>Discard</button>
                      <button type="button" className="btn ghost compact" disabled={isBusy} onClick={() => setRename({ id: item.id, text: item.title ?? "" })}>Rename</button>
                      <button type="button" className="btn ghost compact" disabled={isBusy} aria-expanded={assigning === item.id} onClick={() => toggleAssign(item)}>Assign client</button>
                      <select className="input compact queue-language" aria-label="Language" title="Language of the transcript" disabled={isBusy}
                        value={item.language ?? ""} onChange={(e) => void setLanguage(item, e.target.value as TranscriptionLanguage | "")}>
                        {MAIN_LANGUAGES.map((o) => <option key={o.chip} value={o.value ?? ""}>{menuLabel(o)}</option>)}
                        <optgroup label="More languages">
                          {MORE_LANGUAGES.map((o) => <option key={o.chip} value={o.value ?? ""}>{o.label}</option>)}
                        </optgroup>
                      </select>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </section>
      )}
      {importing.length > 0 && (
        <section className="queue-section" aria-labelledby="queue-imports-title">
          <div className="queue-head">
            <ImportIcon size={14} />
            <h2 id="queue-imports-title" className="queue-label">Importing</h2>
            <span className="queue-count">{importing.length}</span>
          </div>
          {importing.map((item) => {
            const title = uploadTitle(item);
            const percent = item.state === "uploading" ? item.progress ?? 0 : 0;
            return (
              <div key={item.id} role="group" aria-label={title} className="queue-item importing" data-testid={`upload-${item.id}`}>
                <div className="row">
                  <span className="avatar desktop"><SourceAppIcon app={item.sourceApp} size={18} /></span>
                  <div className="item-main">
                    <div className="queue-title" dir="auto">{title}</div>
                    <div className="small muted queue-meta">{importProgressText(item)}</div>
                  </div>
                  <button type="button" className="btn ghost compact" disabled={busy === item.id} onClick={() => void cancelImport(item)}>Cancel</button>
                </div>
                <div className="meter import-meter" role="progressbar" aria-label={`Upload of ${title}`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
                  <div style={{ width: `${percent}%` }} />
                </div>
              </div>
            );
          })}
        </section>
      )}
      {failed.length > 0 && (
        <section className="queue-section" aria-labelledby="queue-uploads-title">
          <div className="queue-head">
            <AlertIcon size={14} />
            <h2 id="queue-uploads-title" className="queue-label">Uploads that need you</h2>
            <span className="queue-count">{failed.length}</span>
          </div>
          {failed.map((item) => {
            const title = uploadTitle(item);
            const isBusy = busy === item.id;
            return (
              <div key={item.id} role="group" aria-label={title} className="queue-item failed" data-testid={`upload-${item.id}`}>
                <div className="row">
                  <span className="avatar desktop"><SourceAppIcon app={item.sourceApp} size={18} /></span>
                  <div className="item-main">
                    <div className="queue-title">{title}</div>
                    <div className="small muted queue-meta">{meta(item.sourceApp, item.startedAt, item.durationMs)}</div>
                  </div>
                </div>
                {item.lastError && <div className="small error-text queue-error">{item.lastError}</div>}
                <div className="queue-actions">
                  <button type="button" className="btn primary compact" disabled={isBusy} onClick={() => void retry(item)}>Retry</button>
                  <button type="button" className="btn compact" disabled={isBusy} onClick={() => void discardUpload(item)}>Discard</button>
                </div>
              </div>
            );
          })}
        </section>
      )}
      {verifying.map((item) => (
        <div key={item.id} className="queue-item verifying" data-testid={`upload-${item.id}`}>
          <div className="row">
            <span className="avatar desktop"><SourceAppIcon app={item.sourceApp} size={18} /></span>
            <div className="item-main">
              <div className="queue-title">{uploadTitle(item)}</div>
              <div className="small muted queue-meta">{meta(item.sourceApp, item.startedAt, item.durationMs)}</div>
            </div>
            <span className="queue-status"><span className="spinner" aria-hidden="true" />Checking upload…</span>
          </div>
        </div>
      ))}
    </div>
  );
}
