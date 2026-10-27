// The activity centre (plan B18): a bell with the unread count; the list opens the recording a notification is about.
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, listenTo, type ActivityItem } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { BellIcon, CloseIcon } from "../../components/Icons";

export function ActivityBell({ onOpenRecording }: { onOpenRecording: (id: string) => void }) {
  const [unread, setUnread] = useState(0);
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<ActivityItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const panel = useRef<HTMLDivElement>(null);
  const wrap = useRef<HTMLDivElement>(null);

  const load = () =>
    api.activity().then((r) => {
      setItems(r.data ?? []);
      setUnread(r.unread_count ?? 0);
      setError(null);
    }, (e) => setError(errorMessage(e)));

  useEffect(() => {
    void load();
    const un = listenTo("activity:updated", (n) => setUnread(typeof n === "number" ? n : 0));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  useEffect(() => {
    if (!open) return;
    void load();
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    // A click or touch anywhere outside the bell and its panel closes it.
    const onOutside = (e: MouseEvent) => {
      if (wrap.current && e.target instanceof Node && !wrap.current.contains(e.target)) setOpen(false);
    };
    window.addEventListener("keydown", onKey);
    window.addEventListener("mousedown", onOutside);
    return () => {
      window.removeEventListener("keydown", onKey);
      window.removeEventListener("mousedown", onOutside);
    };
  }, [open]);

  const choose = async (item: ActivityItem) => {
    if (!item.read) {
      const r = await api.markActivityRead([item.id]).catch(() => null);
      if (r) setUnread(r.unread_count);
      setItems((list) => list?.map((i) => (i.id === item.id ? { ...i, read: true } : i)) ?? null);
    }
    if (item.session_id) {
      setOpen(false);
      onOpenRecording(item.session_id);
    }
  };
  const readAll = async () => {
    const r = await api.markActivityRead().catch(() => null);
    if (r) setUnread(r.unread_count);
    setItems((list) => list?.map((i) => ({ ...i, read: true })) ?? null);
  };

  return (
    <div className="activity-bell" ref={wrap}>
      <button type="button" className="btn ghost" aria-expanded={open} aria-label={unread > 0 ? `Activity, ${unread} unread` : "Activity"} onClick={() => setOpen(!open)}>
        <BellIcon size={18} />{unread > 0 && <span className="badge">{unread > 99 ? "99+" : unread}</span>}
      </button>
      {open && (
        <div className="card activity-panel" role="dialog" aria-label="Activity" ref={panel}>
          <div className="row"><b>Activity</b><span className="spacer" />{unread > 0 && <button type="button" className="btn ghost small" onClick={() => void readAll()}>Mark all read</button>}<button type="button" className="btn ghost small" aria-label="Close activity" onClick={() => setOpen(false)}><CloseIcon size={14} /></button></div>
          {error && <p className="small muted">{error}</p>}
          {items && items.length === 0 && <p className="small muted">Nothing yet. Finished transcripts, failed uploads and new devices show up here.</p>}
          <ul className="activity-list">
            {(items ?? []).map((i) => (
              <li key={i.id}>
                <button type="button" className={`activity-item ${i.read ? "" : "unread"}`} onClick={() => void choose(i)}>
                  <span className="activity-title">{i.title}</span>
                  <span className="small muted">{i.body}</span>
                  {i.at && <span className="small muted">{formatWhen(i.at)}</span>}
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
