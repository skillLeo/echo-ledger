// The v2 Library (ui_v2.library): the same recordings today's Library shows (library_list, refreshed from the server,
// reloaded on library:updated), as WhatsApp-style rows with the saved views All, Today, Untranscribed and Trash. One
// feedback system: the Toast, one at a time, with Undo after a delete.
import { useCallback, useEffect, useRef, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type BinItem, type LibraryItem } from "../../lib/ipc";
import { formatDuration, rowDate } from "../../lib/format";
import { eraseIn } from "../../views/main/LibraryOrganise";
import { useTrashKeepHours } from "../../lib/trash";
import {
  Badge, Button, Chip, Dialog, EmptyState, FilterBar, Icons, List, ListRow, ListSection, Loading, SearchField, SkeletonRow,
  Toast, ToastRegion,
} from "../components";
import { binHours, binSentence, dayGroups, rowModel, viewCounts, viewItems, type SavedView } from "./model";
import { RecordingPage } from "./RecordingPage";

type Message = { id: number; text: string; action?: { label: string; run: () => void } };

export type LibraryV2Props = {
  onImport?: () => void;
  onRecord?: () => void;
  /** Open this recording (a notice, the palette, a link); `seq` makes the same one open again. */
  openRequest?: { id: string; seq: number } | null;
  /** Open a saved view (Home's "isn't transcribed"); `seq` makes the same one open again. */
  viewRequest?: { view: SavedView; seq: number } | null;
};

export function LibraryV2({ onImport, onRecord, openRequest, viewRequest }: LibraryV2Props) {
  const [items, setItems] = useState<LibraryItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [view, setView] = useState<SavedView>("all");
  const [query, setQuery] = useState("");
  const [openId, setOpenId] = useState<string | null>(null);
  const [bin, setBin] = useState<BinItem[] | null>(null);
  const [binError, setBinError] = useState<string | null>(null);
  const [erase, setErase] = useState<BinItem | null>(null);
  const [message, setMessage] = useState<Message | null>(null);
  const seq = useRef(0);
  // Keyboard focus after a change that takes away the focused control: the next row after a delete, the recording
  // again after Undo, else the search field. Applied once, when the list has been drawn, and only if focus was lost
  // (on nothing or the page): never taken from where the person has moved it since.
  const [focusAfter, setFocusAfter] = useState<{ rowId: string | null; seq: number } | null>(null);
  const root = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!focusAfter) return;
    setFocusAfter(null);
    const active = document.activeElement;
    if (active && active !== document.body) return;
    const el = root.current;
    const row = focusAfter.rowId ? el?.querySelector<HTMLElement>(`[data-row-id="${CSS.escape(focusAfter.rowId)}"]`) : null;
    (row ?? el?.querySelector<HTMLElement>('input[type="search"]'))?.focus();
  }, [focusAfter]);
  const keepHours = useTrashKeepHours();

  const say = useCallback((text: string, action?: Message["action"]) => setMessage({ id: ++seq.current, text, action }), []);

  const load = useCallback(async () => {
    try {
      setItems(await api.library());
      setError(null);
    } catch (e) {
      setError(errorMessage(e));
    }
  }, []);
  const loadBin = useCallback(async () => {
    try {
      setBin((await api.bin())?.data ?? []);
      setBinError(null);
    } catch (e) {
      setBinError(errorMessage(e));
    }
  }, []);

  useEffect(() => {
    void load().then(() => api.refreshLibrary().then(setItems).catch(() => undefined));
    const un = listen("library:updated", () => void load());
    return () => {
      void un.then((f) => f());
    };
  }, [load]);
  useEffect(() => {
    if (view === "trash") void loadBin();
  }, [view, loadBin]);
  useEffect(() => {
    if (viewRequest) {
      setOpenId(null);
      setView(viewRequest.view);
    }
  }, [viewRequest]);
  useEffect(() => {
    if (openRequest) {
      setView("all");
      setOpenId(openRequest.id);
    }
  }, [openRequest]);

  const remove = async (item: LibraryItem) => {
    const title = item.title?.trim() || "Recording";
    try {
      await api.deleteSession(item.id);
      const order = items ? viewItems(items, view === "trash" ? "all" : view, query) : [];
      const at = order.findIndex((i) => i.id === item.id);
      const next = order[at + 1] ?? order[at - 1] ?? null;
      setItems((list) => list?.filter((i) => i.id !== item.id) ?? list);
      setOpenId(null);
      setFocusAfter({ rowId: next?.id ?? null, seq: ++seq.current });
      say(`"${title}" moved to Trash`, {
        label: "Undo",
        run: () => void api.restoreSession(item.id).then(async () => {
          await load();
          setFocusAfter({ rowId: item.id, seq: ++seq.current });
        }).catch((e) => say(errorMessage(e))),
      });
    } catch (e) {
      say(errorMessage(e));
    }
  };
  const restore = async (b: BinItem) => {
    try {
      await api.restoreSession(b.id);
      setBin((list) => list?.filter((x) => x.id !== b.id) ?? list);
      await load();
      say(`"${b.title || "Recording"}" restored`, { label: "Open", run: () => { setView("all"); setOpenId(b.id); } });
    } catch (e) {
      say(errorMessage(e));
    }
  };
  const eraseNow = async (b: BinItem) => {
    setErase(null);
    try {
      await api.eraseFromBin(b.id);
      setBin((list) => list?.filter((x) => x.id !== b.id) ?? list);
      say(`"${b.title || "Recording"}" deleted forever`);
    } catch (e) {
      say(errorMessage(e));
    }
  };

  const open = openId ? items?.find((i) => i.id === openId) ?? null : null;
  const toasts = (
    <ToastRegion>
      {message && (
        <Toast key={message.id} message={message.text} actionLabel={message.action?.label} onAction={message.action?.run}
          onClose={() => setMessage((m) => (m?.id === message.id ? null : m))} />
      )}
    </ToastRegion>
  );

  if (open) {
    return (
      <div className="el-root el-embedded" data-v2="library" ref={root}>
        <RecordingPage item={open} onBack={() => setOpenId(null)} onDelete={(i) => void remove(i)} onMessage={(t) => say(t)} />
        {toasts}
      </div>
    );
  }

  const counts = items ? viewCounts(items) : null;
  const shown = items && view !== "trash" ? viewItems(items, view, query) : [];
  const chip = (v: SavedView, label: string) => (
    <Chip label={label} selected={view === v} count={v !== "all" && v !== "trash" && counts ? counts[v] : undefined}
      onToggle={() => setView(view === v && v !== "all" ? "all" : v)} />
  );

  return (
    <div className="el-root el-embedded" data-v2="library" ref={root}>
      <div className="el-page el-page--wide">
        <header className="el-page__head">
          <h1 className="el-title-l">{view === "trash" ? "Trash" : "Library"}</h1>
          <div className="el-page__actions">
            {onImport && <Button onClick={onImport}>Import</Button>}
            {onRecord && <Button variant="primary" icon={Icons.RecordIcon} onClick={onRecord}>New recording</Button>}
          </div>
        </header>
        <div className="el-page__tools">
          {view !== "trash" && <SearchField label="Search recordings" value={query} onChange={setQuery} disabled={!items} />}
          <FilterBar label="Saved views">
            {chip("all", "All")}
            {chip("today", "Today")}
            {chip("untranscribed", "Untranscribed")}
            {chip("trash", "Trash")}
          </FilterBar>
        </div>
        {view === "trash" ? (
          binError ? (
            <EmptyState tone="error" icon={Icons.ErrorIcon} title="Trash didn't load" body={binError} action={<Button variant="primary" onClick={() => void loadBin()}>Try again</Button>} />
          ) : !bin ? (
            <Loading label="Loading Trash">{Array.from({ length: 4 }, (_, i) => <SkeletonRow key={i} />)}</Loading>
          ) : bin.length === 0 ? (
            <EmptyState icon={Icons.TrashIcon} title="Trash is empty" body={binSentence(binHours(bin, keepHours))} />
          ) : (
            <>
            <p className="el-secondary">{binSentence(binHours(bin, keepHours))}</p>
            <List label="Deleted recordings">
              {bin.map((b) => (
                <ListRow key={b.id} icon={Icons.TrashIcon} title={b.title || "Recording"}
                  meta={`Deleted ${rowDate(b.deleted_at)} · ${eraseIn(b.erase_at)}`}
                  control={
                    <span className="el-row__buttons">
                      <Button size="sm" onClick={() => void restore(b)}>Restore</Button>
                      <Button size="sm" variant="quiet" icon={Icons.TrashIcon} aria-label={`Delete "${b.title || "Recording"}" forever`} onClick={() => setErase(b)} />
                    </span>
                  }
                  endTop={b.duration_ms ? <span className="el-num">{formatDuration(b.duration_ms)}</span> : undefined} />
              ))}
            </List>
            </>
          )
        ) : error && !items ? (
          <EmptyState tone="error" icon={Icons.ErrorIcon} title="The library didn't load" body={error}
            action={<Button variant="primary" onClick={() => void load()}>Try again</Button>} />
        ) : !items ? (
          <Loading label="Loading recordings">{Array.from({ length: 7 }, (_, i) => <SkeletonRow key={i} />)}</Loading>
        ) : counts?.all === 0 ? (
          <EmptyState icon={Icons.LibraryIcon} title="No recordings yet"
            body="Calls and meetings you record on this computer or your phone, and files you import, appear here."
            action={onImport ? <Button onClick={onImport}>Import</Button> : undefined} />
        ) : shown.length === 0 ? (
          <EmptyState icon={Icons.SearchIcon} title={query ? "No recordings match" : view === "today" ? "Nothing recorded today" : "Everything is transcribed"}
            body={query ? "Try another word, or clear the search to see every recording." : view === "today" ? "Today's calls and meetings appear here as they are recorded." : "Recordings without a transcript appear here."}
            action={<Button onClick={() => { setQuery(""); setView("all"); }}>{query ? "Clear search" : "Show all"}</Button>} />
        ) : (
          <List label="Recordings">
            {dayGroups(shown).map((g) => [
              <ListSection key={g.label} title={g.label} />,
              ...g.items.map((item) => {
                const r = rowModel(item);
                return (
                  <ListRow key={item.id} icon={(p) => <Icons.SourceAppIcon app={r.app} {...p} />} title={r.title} meta={r.meta}
                    badge={r.badge ? <Badge tone={r.badge.tone}>{r.badge.text}</Badge> : undefined}
                    endTop={r.when} endBottom={r.duration ? <span className="el-num">{r.duration}</span> : undefined}
                    rowId={item.id} onClick={() => setOpenId(item.id)} />
                );
              }),
            ])}
          </List>
        )}
      </div>
      <Dialog open={!!erase} alert title="Delete forever?"
        description={erase ? `"${erase.title || "Recording"}" and its transcript are erased from the server. This can't be undone.` : undefined}
        onClose={() => setErase(null)}
        actions={<><Button onClick={() => setErase(null)}>Cancel</Button><Button variant="danger" onClick={() => erase && void eraseNow(erase)}>Delete forever</Button></>} />
      {toasts}
    </div>
  );
}
