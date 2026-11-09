// Shared with me (docs/API.md "Sending to a teammate", since v1.5): recordings and projects teammates sent, with who
// sent them and their note. A recording opens read-only beside the list; Save to my library makes an independent copy
// in this workspace, and Remove takes it off the list (the sender keeps theirs).
import { useEffect, useState } from "react";
import { api, errorMessage, type SharedItem } from "../../lib/ipc";
import { formatDuration, formatWhen } from "../../lib/format";
import { AppTileIcon, appKind, FolderIcon, UsersIcon } from "../../components/Icons";
import { SuccessCheck } from "../../components/SuccessCheck";
import { NO_TARGET, type Notify } from "../../lib/notice";

/** "From Hassam · Oct 30 · 4:05 pm" */
export function sentLine(item: Pick<SharedItem, "from" | "sent_at">): string {
  const who = item.from?.name || item.from?.email || "A teammate";
  return [`From ${who}`, item.sent_at ? formatWhen(item.sent_at) : null].filter(Boolean).join(" · ");
}

export function SharedWithMe({ notify, openId, onOpen, onSaved, focusId = null }: {
  notify: Notify;
  /** The shared recording open beside the list. */
  openId: string | null;
  onOpen: (item: SharedItem) => void;
  /** Saved: a recording (`session_id`) or a project (`client_id`) is now in this workspace. */
  onSaved: (saved: { session_id?: string; client_id?: string }, item: SharedItem) => void;
  /** One to bring into view (an echoledger link to it was opened). */
  focusId?: string | null;
}) {
  const [items, setItems] = useState<SharedItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const load = () =>
    api.sharedWithMe().then((r) => {
      setItems(r?.data ?? []);
      setError(r ? null : "This server does not have Shared with me yet.");
    }, (e) => {
      setItems([]);
      setError(errorMessage(e));
    });
  useEffect(() => {
    void load();
  }, []);
  // A link to one of them opens it.
  useEffect(() => {
    if (!focusId || !items) return;
    const item = items.find((i) => i.id === focusId);
    if (item?.kind === "session" && item.session) onOpen(item);
    if (/^[\w-]+$/.test(focusId)) document.querySelector(`[data-shared="${focusId}"]`)?.scrollIntoView?.({ block: "nearest" });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusId, items]);

  const save = async (item: SharedItem) => {
    setBusy(item.id);
    try {
      const saved = await api.saveShared(item.id);
      setItems((list) => (list ?? []).map((i) => (i.id === item.id ? { ...i, saved_at: new Date().toISOString() } : i)));
      notify(item.kind === "project" ? `Saved ${item.title ?? "the project"} to your projects` : "Saved to your Library", false,
        saved?.client_id ? { kind: "project", id: saved.client_id } : saved?.session_id ? { kind: "recording", id: saved.session_id } : NO_TARGET);
      onSaved(saved ?? {}, item);
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(null);
    }
  };
  const remove = async (item: SharedItem) => {
    setBusy(item.id);
    try {
      await api.removeShared(item.id);
      setItems((list) => (list ?? []).filter((i) => i.id !== item.id));
      notify("Removed from Shared with me", false, NO_TARGET);
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(null);
    }
  };

  if (items === null) {
    return (
      <div aria-busy="true">
        <span className="sr-only">Loading what was shared with you…</span>
        {[0, 1].map((n) => (
          <div key={n} className="skeleton-row" aria-hidden="true">
            <span className="skeleton tile" />
            <span className="skeleton-lines"><span className="skeleton" style={{ width: "60%", height: 14 }} /><span className="skeleton" style={{ width: "40%", height: 10 }} /></span>
          </div>
        ))}
      </div>
    );
  }
  if (error) return <div className="notice error" role="alert">{error}</div>;
  if (items.length === 0) {
    return (
      <div className="empty-state">
        <span className="empty-icon"><UsersIcon size={26} /></span>
        <span className="empty-title">Nothing shared with you yet</span>
        <p>Recordings and projects teammates send you show up here.</p>
      </div>
    );
  }
  return (
    <div className="group shared-list" role="list" aria-label="Shared with me">
      {items.map((item) => {
        const session = item.kind === "session" ? item.session : null;
        const title = item.title ?? session?.title ?? item.client?.name ?? "Untitled";
        return (
          <div key={item.id} role="listitem" data-shared={item.id} className={`shared-card ${openId === item.id ? "selected" : ""}`}>
            <div className="row">
              {session ? (
                <span className={`avatar app-${appKind(session.source_app)}`}><AppTileIcon app={session.source_app} /></span>
              ) : (
                <span className="avatar app-other"><FolderIcon size={20} /></span>
              )}
              <div className="item-main">
                <div className="item-line"><span className="item-title" dir="auto">{title}</span>{item.kind === "project" && <span className="label-chip small">Project</span>}</div>
                <div className="item-sub">{[sentLine(item), session ? formatDuration(session.duration_ms) || null : null].filter(Boolean).join(" · ")}</div>
              </div>
            </div>
            {item.note && <p className="shared-note" dir="auto">“{item.note}”</p>}
            <div className="row shared-actions">
              {session && <button type="button" className="btn compact" aria-pressed={openId === item.id} onClick={() => onOpen(item)}>Open</button>}
              {item.saved_at ? (
                <span className="small muted saved-line"><SuccessCheck size={14} />Saved to your {item.kind === "project" ? "projects" : "Library"}</span>
              ) : (
                <button type="button" className="btn primary compact" disabled={busy === item.id} onClick={() => void save(item)}>Save to my library</button>
              )}
              <span className="spacer" />
              <button type="button" className="btn ghost compact" disabled={busy === item.id} onClick={() => void remove(item)} aria-label={`Remove ${title} from Shared with me`}>Remove</button>
            </div>
          </div>
        );
      })}
    </div>
  );
}
