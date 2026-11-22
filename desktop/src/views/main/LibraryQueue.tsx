import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { api, errorMessage, listenTo, type PendingItem, type TranscriptionLanguage, type UploadItem } from "../../lib/ipc";
import { MAIN_LANGUAGES, MORE_LANGUAGES, learnPrompt, menuLabel, type LanguageChoice } from "../../lib/languages";
import { formatDuration, formatWhen, sourceAppLabel } from "../../lib/format";
import { fillWidth } from "../../lib/motion";
import {
  DELETE_WAITING_QUESTION,
  DISCARD_QUESTION,
  bulkNotice,
  deleteWaitingQuestion,
  deletedNotice,
  discardAllQuestion,
  failedUploads,
  heldLine,
  heldUploads,
  importProgressText,
  importingUploads,
  pendingTitle,
  uploadTitle,
  verifyingUploads,
  waitingItems,
  waitingLine,
  waitingSummary,
} from "../../lib/pending";
import { AlertIcon, AppTileIcon, HourglassIcon, ImportIcon, appKind } from "../../components/Icons";
import { NO_TARGET, type Notify } from "../../lib/notice";
import { confirmAction } from "../../lib/confirm";
import { CALL_WORDS } from "../../lib/callChoice";

/** A `main:navigate` request to bring one waiting recording into view, optionally with its title open for editing. */
export interface PendingFocus {
  id: string;
  rename: boolean;
  /** Bumped on every request, so asking for the same recording twice highlights it again. */
  seq: number;
}

type Client = { id: string; name: string };

const HIGHLIGHT_MS = 4000;
const asList = <T,>(value: unknown): T[] => (Array.isArray(value) ? (value as T[]) : []);

const meta = (sourceApp: string, startedAt: string, durationMs: number, extra?: string | null) =>
  [sourceAppLabel(sourceApp), formatWhen(startedAt), formatDuration(durationMs) || "0:00", extra].filter(Boolean).join(" · ");

/**
 * The top of the Library list: recordings waiting for Keep / Discard (A2.5), imported files on their way up (with
 * Cancel), and uploads that stopped and need the user (A5.1). The lists come from the core and follow its
 * `pending:changed` / `uploads:changed` events. Since v1.5 calls are kept (or asked about by the after-call chip) when
 * they end, so only recordings left waiting show, behind one slim line, "2 calls waiting · Review"; Review opens the
 * list. A recording whose chip still counts down is not listed. Since v1.5 recordings made while another account was
 * signed in here wait for that account, with Discard.
 */
/**
 * The waiting recordings and the uploads of the top of the Library, with every command they take: one source for the
 * classic list below and the v2 Library's (v2/library/QueueV2.tsx). The lists come from the core and follow its
 * `pending:changed` / `uploads:changed` events.
 */
export function useLibraryQueue(notify: Notify) {
  const [all, setPending] = useState<PendingItem[]>([]);
  const pending = useMemo(() => waitingItems(all), [all]);
  const [uploads, setUploads] = useState<UploadItem[]>([]);
  const [clients, setClients] = useState<Client[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [bulkBusy, setBulkBusy] = useState(false);
  const [learn, setLearn] = useState<{ itemId: string; clientId: string; question: string; language: LanguageChoice } | null>(null);

  // Each list has answered once (or failed to): until both have, what the queue shows is not known yet.
  const [answered, setAnswered] = useState({ pending: false, uploads: false });
  const answer = useCallback((list: "pending" | "uploads") => setAnswered((a) => (a[list] ? a : { ...a, [list]: true })), []);
  const pendingLoads = useRef(0);
  const reloadPending = useCallback(() => {
    pendingLoads.current++;
    api.pendingList().then(
      (items) => {
        pendingLoads.current--;
        setPending(asList(items));
        answer("pending");
      },
      () => {
        pendingLoads.current--;
        answer("pending");
      },
    );
  }, [answer]);
  const reloadUploads = useCallback(() => {
    api.uploadList().then((items) => setUploads(asList(items))).catch(() => undefined).finally(() => answer("uploads"));
  }, [answer]);

  useEffect(() => {
    reloadPending();
    reloadUploads();
    const subs = [
      listenTo("pending:changed", (items) => {
        setPending(asList(items));
        answer("pending");
      }),
      listenTo("uploads:changed", (items) => {
        setUploads(asList(items));
        answer("uploads");
      }),
    ];
    return () => subs.forEach((p) => p.then((f) => f()).catch(() => undefined));
  }, [reloadPending, reloadUploads]);

  /** Runs one command for an item; on failure reports the core's message and re-reads both lists. */
  const perform = async (id: string, fn: () => Promise<unknown>, ok?: string): Promise<boolean> => {
    setBusy(id);
    try {
      await fn();
      // Calls being transcribed or saved as audio go up and show in the Library.
      if (ok) notify(ok, false, /^(Transcribing|Saved)/.test(ok) ? { kind: "library", view: null } : NO_TARGET);
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
    if (await perform(item.id, () => api.pendingKeep(item.id), "Transcribing…")) drop(item.id);
  };

  /** "Save audio only": kept and uploaded as audio, not transcribed; it can be transcribed later from the Library. */
  const keepAudio = async (item: PendingItem) => {
    if (await perform(item.id, () => api.pendingKeepAudio(item.id), "Saved as audio")) drop(item.id);
  };

  /** Delete and Delete all: gone from the list now, deleted only once "Deleted · Undo" has had its time; Undo puts them
   * back in the list. */
  const deleteLater = async (ids: string[]) => {
    setBulkBusy(true);
    try {
      const count = await api.pendingDelete(ids);
      setPending((list) => list.filter((i) => !ids.includes(i.id)));
      notify(deletedNotice(count), false, { kind: "action", run: () => {
        api.pendingDeleteUndo(ids).then(() => reloadPending(), (e) => notify(errorMessage(e), true));
      } });
    } catch (e) {
      notify(errorMessage(e), true);
      reloadPending();
    } finally {
      setBulkBusy(false);
    }
  };

  const discard = async (item: PendingItem) => {
    if (!(await confirmAction(DELETE_WAITING_QUESTION))) return;
    await deleteLater([item.id]);
  };

  /** Transcribe all / Save audio only for all: one command per recording, in order; a failure is reported and the rest
   * still run. */
  const decideAll = async (verb: "Transcribing" | "Saved as audio", command: (id: string) => Promise<unknown>) => {
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
    if (done > 0) notify(bulkNotice(verb, done), false, { kind: "library", view: null });
  };

  const keepAll = () => decideAll("Transcribing", (id) => api.pendingKeep(id));
  const keepAudioAll = () => decideAll("Saved as audio", (id) => api.pendingKeepAudio(id));

  const discardAll = async () => {
    const ids = pending.map((i) => i.id);
    if (!(await confirmAction(deleteWaitingQuestion(ids.length)))) return;
    await deleteLater(ids);
  };

  /** A new title for a waiting recording; an unchanged one sends nothing, an empty one clears it. */
  const rename = async (id: string, given: string) => {
    const item = pending.find((i) => i.id === id);
    const text = given.trim();
    if (!item || text === (item.title ?? "").trim()) return;
    let updated: PendingItem | undefined;
    await perform(item.id, async () => (updated = await api.pendingRename(item.id, text)));
    replace(updated);
  };

  /** The projects to choose from, loaded once. */
  const loadClients = () => {
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
    if (yes && asked) await perform(asked.itemId, () => api.updateClient(asked.clientId, { language: asked.language }), "Saved for this project");
  };

  const retry = async (item: UploadItem) => {
    if (await perform(item.id, () => api.uploadRetry(item.id))) {
      setUploads((list) => list.map((i) => (i.id === item.id ? { ...i, state: "queued", lastError: null } : i)));
    }
  };

  const discardUpload = async (item: UploadItem) => {
    // An imported file is still on the computer where it came from, so only a recording made here is asked about.
    if (!item.imported && !(await confirmAction(DISCARD_QUESTION))) return;
    if (await perform(item.id, () => api.uploadDiscard(item.id), "Discarded")) setUploads((list) => list.filter((i) => i.id !== item.id));
  };

  /** Every recording that waits for one account, after one confirmation. */
  const discardHeld = async (items: UploadItem[]) => {
    if (!(await confirmAction(discardAllQuestion(items.length)))) return;
    setBulkBusy(true);
    let done = 0;
    try {
      for (const item of items) {
        if (await perform(item.id, () => api.uploadDiscard(item.id))) {
          setUploads((list) => list.filter((i) => i.id !== item.id));
          done++;
        }
      }
    } finally {
      setBulkBusy(false);
    }
    if (done > 0) notify(bulkNotice("Discarded", done), false, NO_TARGET);
  };

  const cancelImport = async (item: UploadItem) => {
    if (await perform(item.id, () => api.uploadDiscard(item.id), "Import cancelled")) setUploads((list) => list.filter((i) => i.id !== item.id));
  };

  const failed = failedUploads(uploads);
  const verifying = verifyingUploads(uploads);
  const importing = importingUploads(uploads);
  const held = heldUploads(uploads);
  return {
    /** Both lists have answered once: what the queue shows is known. */
    loaded: answered.pending && answered.uploads,
    pending, uploads, failed, verifying, importing, held, clients, busy, bulkBusy, learn, pendingLoads, reloadPending,
    keep, keepAudio, discard, keepAll, keepAudioAll, discardAll, rename, loadClients, assign, setLanguage, answerLearn, retry, discardUpload, discardHeld, cancelImport,
  };
}

/** The queue's lists and commands (useLibraryQueue), for a list drawn apart from where it is kept (the v2 Library). */
export type LibraryQueueState = ReturnType<typeof useLibraryQueue>;

export function LibraryQueue({ notify, focus, onFocusHandled }: { notify: Notify; focus?: PendingFocus | null; onFocusHandled?: () => void }) {
  const q = useLibraryQueue(notify);
  const { pending, clients, busy, bulkBusy, learn, pendingLoads, reloadPending, failed, verifying, importing, held, keepAudio, keepAudioAll } = q;
  const [open, setOpen] = useState(false);
  const [renaming, setRenaming] = useState<{ id: string; text: string } | null>(null);
  const [assigning, setAssigning] = useState<string | null>(null);
  const [highlight, setHighlight] = useState<{ id: string; seq: number } | null>(null);
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

  const { keep, discard, keepAll, discardAll, answerLearn, retry, discardUpload, discardHeld, cancelImport } = q;
  const saveRename = async () => {
    const current = renameRef.current;
    if (!current) return;
    setRename(null);
    await q.rename(current.id, current.text);
  };

  const toggleAssign = (item: PendingItem) => {
    setAssigning((current) => (current === item.id ? null : item.id));
    q.loadClients();
  };

  const assign = (item: PendingItem, value: string) => {
    setAssigning(null);
    return q.assign(item, value);
  };
  const setLanguage = q.setLanguage;

  if (pending.length === 0 && failed.length === 0 && verifying.length === 0 && importing.length === 0 && held.length === 0) return null;

  return (
    <div className="queue">
      {pending.length > 0 && (
        <section className="queue-section" aria-labelledby="queue-pending-title">
          <div className="queue-slim" data-testid="waiting-line" title={waitingSummary(pending)}>
            <HourglassIcon size={15} />
            <h2 id="queue-pending-title" className="queue-slim-label">{waitingLine(pending)}</h2>
            <span className="queue-slim-sep" aria-hidden="true">·</span>
            <button
              type="button"
              className="btn text compact"
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
                <button type="button" className="btn primary compact" disabled={bulkBusy} onClick={() => void keepAll()}>Transcribe all</button>
                <button type="button" className="btn compact" disabled={bulkBusy} onClick={() => void keepAudioAll()}>{CALL_WORDS.save_audio_all}</button>
                <button type="button" className="btn compact" disabled={bulkBusy} onClick={() => void discardAll()}>Delete all</button>
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
                      <span className={`avatar desktop app-${appKind(item.sourceApp)}`}><AppTileIcon app={item.sourceApp} /></span>
                      <div className="item-main">
                        {renaming?.id === item.id ? (
                          <input
                            className="input queue-rename"
                            aria-label="Title"
                            dir="auto"
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
                          <div className="queue-title" dir="auto">{title}</div>
                        )}
                        <div className="small muted queue-meta">{meta(item.sourceApp, item.startedAt, item.durationMs, item.clientName)}</div>
                      </div>
                    </div>
                    {item.firstLine && <div className="item-preview">{item.firstLine}</div>}
                    {item.restoredDelete && <div className="item-preview queue-restored" data-testid="restored-delete" dir="auto">{CALL_WORDS.restored_delete}</div>}
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
                        aria-label="Project"
                        autoFocus
                        value={item.clientId ?? ""}
                        onChange={(e) => void assign(item, e.target.value)}
                        onBlur={() => setAssigning(null)}
                        onKeyDown={(e) => e.key === "Escape" && setAssigning(null)}
                      >
                        <option dir="auto" value="">No project</option>
                        {!knownClient && <option dir="auto" value={item.clientId ?? ""}>{item.clientName ?? item.clientId}</option>}
                        {(clients ?? []).map((c) => <option dir="auto" key={c.id} value={c.id}>{c.name}</option>)}
                      </select>
                    )}
                    <div className="queue-actions">
                      <select className={`chip queue-language ${item.language ? "active" : ""}`} aria-label="Language" title="Language of the transcript" disabled={isBusy}
                        value={item.language ?? ""} onChange={(e) => void setLanguage(item, e.target.value as TranscriptionLanguage | "")}>
                        {MAIN_LANGUAGES.map((o) => <option dir="auto" key={o.chip} value={o.value ?? ""}>{menuLabel(o)}</option>)}
                        <optgroup label="More languages">
                          {MORE_LANGUAGES.map((o) => <option dir="auto" key={o.chip} value={o.value ?? ""}>{o.label}</option>)}
                        </optgroup>
                      </select>
                      <button type="button" className="btn ghost compact" disabled={isBusy} aria-expanded={assigning === item.id} onClick={() => toggleAssign(item)}>Choose project</button>
                      <button type="button" className="btn ghost compact" disabled={isBusy} onClick={() => setRename({ id: item.id, text: item.title ?? "" })}>Rename</button>
                    </div>
                    {/* As on the after-call card: Delete on the left, Save audio only, Transcribe (the one primary action) on the right. */}
                    <div className="queue-actions decide">
                      <button type="button" className="btn" disabled={isBusy} onClick={() => void discard(item)}>Delete</button>
                      <button type="button" className="btn" disabled={isBusy} onClick={() => void keepAudio(item)}>{CALL_WORDS.save_audio_only}</button>
                      <button type="button" className="btn primary" disabled={isBusy} onClick={() => void keep(item)}>Transcribe</button>
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
                  <span className={`avatar desktop small app-${appKind(item.sourceApp)}`}><AppTileIcon app={item.sourceApp} size={18} /></span>
                  <div className="item-main">
                    <div className="queue-title" dir="auto">{title}</div>
                    <div className="small muted queue-meta">{importProgressText(item)}</div>
                  </div>
                  <button type="button" className="btn ghost compact" disabled={busy === item.id} onClick={() => void cancelImport(item)}>Cancel</button>
                </div>
                <div className="meter import-meter" role="progressbar" aria-label={`Upload of ${title}`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
                  <div style={fillWidth(percent / 100)} />
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
                  <span className={`avatar desktop small app-${appKind(item.sourceApp)}`}><AppTileIcon app={item.sourceApp} size={18} /></span>
                  <div className="item-main">
                    <div className="queue-title" dir="auto">{title}</div>
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
      {held.map(([account, items]) => (
        <section key={account} className="queue-section" aria-label={`Waiting for ${account}`} data-testid="held-uploads">
          <div className="queue-head">
            <HourglassIcon size={14} />
            <h2 className="queue-label">Waiting for another account</h2>
            <span className="queue-count">{items.length}</span>
          </div>
          <p className="small muted queue-note">{heldLine(account, items.length)}</p>
          {items.map((item) => {
            const title = uploadTitle(item);
            return (
              <div key={item.id} role="group" aria-label={title} className="queue-item" data-testid={`upload-${item.id}`}>
                <div className="row">
                  <span className={`avatar desktop small app-${appKind(item.sourceApp)}`}><AppTileIcon app={item.sourceApp} size={18} /></span>
                  <div className="item-main">
                    <div className="queue-title" dir="auto">{title}</div>
                    <div className="small muted queue-meta">{meta(item.sourceApp, item.startedAt, item.durationMs)}</div>
                  </div>
                  <button type="button" className="btn ghost compact" disabled={bulkBusy || busy === item.id} onClick={() => void discardUpload(item)}>Discard</button>
                </div>
              </div>
            );
          })}
          {items.length > 1 && (
            <div className="queue-actions">
              <button type="button" className="btn compact" disabled={bulkBusy} onClick={() => void discardHeld(items)}>Discard all {items.length}</button>
            </div>
          )}
        </section>
      ))}
      {verifying.map((item) => (
        <div key={item.id} className="queue-item verifying" data-testid={`upload-${item.id}`}>
          <div className="row">
            <span className={`avatar desktop small app-${appKind(item.sourceApp)}`}><AppTileIcon app={item.sourceApp} size={18} /></span>
            <div className="item-main">
              <div className="queue-title" dir="auto">{uploadTitle(item)}</div>
              <div className="small muted queue-meta">{meta(item.sourceApp, item.startedAt, item.durationMs)}</div>
            </div>
            <span className="queue-status"><span className="spinner" aria-hidden="true" />Checking upload…</span>
          </div>
        </div>
      ))}
    </div>
  );
}
