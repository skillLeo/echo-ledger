// Clients (since v1.4): every client folder as a list, like the phone's Clients tab, and the chosen client's workspace
// beside it (Overview · Recordings · Files · Ask).
import { useEffect, useMemo, useState } from "react";
import { api, errorMessage, type ClientFolder, type LibraryItem } from "../../lib/ipc";
import { sortFolders } from "../../lib/organise";
import { PlusIcon, UsersIcon } from "../../components/Icons";
import { ClientWorkspace } from "./ClientWorkspace";
import { ClientAvatar, clientSummary } from "./ClientAvatar";

type Notify = (text: string, error?: boolean) => void;

export function ClientsView({ notify, openRequest = null, onOpenRecording }: {
  notify: Notify;
  /** A client to open (from a recording's client chip). */
  openRequest?: { id: string; seq: number } | null;
  onOpenRecording: (id: string, ms?: number) => void;
}) {
  const [clients, setClients] = useState<ClientFolder[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [items, setItems] = useState<LibraryItem[]>([]);
  const [selected, setSelected] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [name, setName] = useState("");

  const load = () =>
    api.clients().then((r) => {
      setClients(r.data ?? []);
      setError(null);
    }, (e) => setError(errorMessage(e)));
  useEffect(() => {
    void load();
    // The saved library says how many recordings each client has and when the last call was, without a server trip.
    api.library().then(setItems).catch(() => undefined);
  }, []);
  useEffect(() => {
    if (openRequest) setSelected(openRequest.id);
  }, [openRequest?.seq]); // eslint-disable-line react-hooks/exhaustive-deps

  const byClient = useMemo(() => {
    const map = new Map<string, LibraryItem[]>();
    for (const item of items) {
      if (item.deleted || !item.client) continue;
      const list = map.get(item.client.id) ?? [];
      list.push(item);
      map.set(item.client.id, list);
    }
    for (const list of map.values()) list.sort((a, b) => (Date.parse(b.started_at ?? "") || 0) - (Date.parse(a.started_at ?? "") || 0));
    return map;
  }, [items]);

  const sorted = useMemo(() => sortFolders(clients ?? []), [clients]);
  // The first client opens by itself on a wide window, so the page never starts empty.
  useEffect(() => {
    if (selected === null && sorted.length > 0) setSelected(sorted[0].id);
  }, [sorted, selected]);
  const client = sorted.find((c) => c.id === selected) ?? null;

  const create = async () => {
    const text = name.trim();
    if (!text) return;
    try {
      const { data } = await api.createClient(text);
      setClients((list) => [...(list ?? []).filter((c) => c.id !== data.id), data]);
      setSelected(data.id);
      setCreating(false);
      setName("");
      notify(`${data.name} added`);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  return (
    <div className="clients-page">
      <div>
        <div className="page-head">
          <h1 className="page-title">Clients</h1>
          <span className="spacer" />
          <button type="button" className="btn ghost icon-only" aria-label="New client" title="New client" onClick={() => setCreating(true)}><PlusIcon size={20} /></button>
        </div>
        {creating && (
          <form className="client-new" onSubmit={(e) => { e.preventDefault(); void create(); }}>
            <input className="input" aria-label="Client name" placeholder="Client name" maxLength={120} autoFocus value={name}
              onChange={(e) => setName(e.target.value)} onKeyDown={(e) => e.key === "Escape" && setCreating(false)} />
            <button type="submit" className="btn primary" disabled={!name.trim()}>Add client</button>
            <button type="button" className="btn ghost" onClick={() => setCreating(false)}>Cancel</button>
          </form>
        )}
        {error && (
          <div className="notice error sync-error" role="alert">
            <span>Couldn't load the clients. {error}</span>
            <button type="button" className="btn compact" onClick={() => void load()}>Retry</button>
          </div>
        )}
        {clients === null && !error && (
          <div aria-busy="true">
            <span className="sr-only">Loading clients…</span>
            {[0, 1, 2].map((n) => (
              <div key={n} className="skeleton-row" aria-hidden="true">
                <span className="skeleton tile" style={{ borderRadius: "50%" }} />
                <span className="skeleton-lines"><span className="skeleton" style={{ width: "55%", height: 14 }} /><span className="skeleton" style={{ width: "35%", height: 10 }} /></span>
              </div>
            ))}
          </div>
        )}
        {clients && clients.length === 0 && !creating && (
          <div className="empty-state">
            <span className="empty-icon"><UsersIcon size={26} /></span>
            <span className="empty-title">No clients yet</span>
            <p>A client keeps its calls, files, a brief and notes together, and Ask answers from all of them.</p>
            <button type="button" className="btn primary" onClick={() => setCreating(true)}><PlusIcon size={16} />New client</button>
          </div>
        )}
        <div className="client-list" role="list" aria-label="Clients">
          {sorted.map((c) => {
            const list = byClient.get(c.id) ?? [];
            const count = Math.max(list.length, c.session_count ?? 0);
            return (
              <div role="listitem" key={c.id}>
                <button type="button" className={`client-row ${c.id === selected ? "selected" : ""}`} aria-current={c.id === selected ? "true" : undefined} onClick={() => setSelected(c.id)}>
                  <ClientAvatar client={c} />
                  <span className="client-main">
                    <span className="client-name">{c.name}</span>
                    <span className="client-sub">{clientSummary(count, list[0]?.started_at)}</span>
                  </span>
                </button>
              </div>
            );
          })}
        </div>
      </div>
      <div className="workspace">
        {client ? (
          <ClientWorkspace key={client.id} client={client} recordings={byClient.get(client.id) ?? []} notify={notify} onOpenRecording={onOpenRecording}
            onClientChanged={(next) => setClients((list) => (list ?? []).map((c) => (c.id === next.id ? { ...c, ...next } : c)))} />
        ) : clients && clients.length > 0 ? (
          <div className="empty-state"><span className="empty-icon"><UsersIcon size={26} /></span><p>Choose a client to see its brief, notes and files.</p></div>
        ) : null}
      </div>
    </div>
  );
}
