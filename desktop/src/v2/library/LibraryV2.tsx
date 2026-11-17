// The v2 Library (ui_v2.library): the same recordings today's Library shows (library_list, refreshed from the server,
// reloaded on library:updated), as WhatsApp-style rows with the saved views All, Today, Untranscribed and Trash. One
// feedback system: the Toast, one at a time, with Undo after a delete. Selection mode (Select, a Cmd/Ctrl- or Shift-click,
// Cmd/Ctrl+A or X) picks several; the row ⋮, a right-click and the selection bar's ⋮ are one menu (organise.tsx).
import { useCallback, useEffect, useRef, useState, type KeyboardEvent as ReactKeyboardEvent, type MouseEvent as ReactMouseEvent } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type BinItem, type BulkAction, type LibraryItem } from "../../lib/ipc";
import { formatDuration, rowDate } from "../../lib/format";
import { eraseIn } from "../../views/main/LibraryOrganise";
import { useTrashKeepHours } from "../../lib/trash";
import {
  Badge, Button, Chip, Dialog, EmptyState, FilterBar, Icons, List, ListRow, ListSection, Loading, SearchField, SkeletonRow,
  Toast, ToastRegion,
} from "../components";
import { ScreensMark } from "./screens";
import { binHours, binSentence, dayGroups, isUntranscribed, rowModel, viewCounts, viewItems, type SavedView } from "./model";
import { RecordingPage } from "./RecordingPage";
import { ContextMenu, Menu, menuPointFor, type MenuItem } from "../components/Menu";
import {
  copyForAiToClipboard, exportMenu, exportRecordings, knownLabels, LabelDialog, MoveDialog, recordingLines, recordingsWord, RenameDialog,
  TranscribeDialog,
} from "./organise";
import { transcriptText } from "../../lib/format";

type Organise =
  | { kind: "move" | "label" | "transcribe"; ids: string[]; title?: string | null; current?: string | null }
  | { kind: "rename"; item: LibraryItem };

/** Keys the Library answers anywhere but in a field, a dialog or a menu. */
const typing = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName) || !!target.closest('[role="dialog"], [role="alertdialog"], [role="menu"]'));
const MOD = typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform) ? "⌘" : "Ctrl+";

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
  const [selecting, setSelecting] = useState(false);
  const [picked, setPicked] = useState<ReadonlySet<string>>(new Set());
  const anchor = useRef<string | null>(null);
  const [context, setContext] = useState<{ at: { x: number; y: number }; ids: string[] } | null>(null);
  const [organise, setOrganise] = useState<Organise | null>(null);
  const endSelection = useCallback(() => {
    setSelecting(false);
    setPicked(new Set());
    anchor.current = null;
  }, []);

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
    endSelection();
  }, [view, loadBin, endSelection]);
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
  /** Several to Trash at once, with one Undo that brings them all back. */
  const removeMany = async (ids: readonly string[]) => {
    if (ids.length === 1) {
      const one = items?.find((i) => i.id === ids[0]);
      if (one) await remove(one);
      endSelection();
      return;
    }
    try {
      const gone = await api.bulkSessions([...ids], "delete");
      const order = items ? viewItems(items, view === "trash" ? "all" : view, query) : [];
      const next = order.find((i) => !ids.includes(i.id)) ?? null;
      setItems((list) => list?.filter((i) => !ids.includes(i.id)) ?? list);
      endSelection();
      setFocusAfter({ rowId: next?.id ?? null, seq: ++seq.current });
      say(`${recordingsWord(gone || ids.length)} moved to Trash`, {
        label: "Undo",
        run: () => void Promise.all(ids.map((id) => api.restoreSession(id))).then(() => load()).catch((e) => say(errorMessage(e))),
      });
    } catch (e) {
      say(errorMessage(e));
    }
  };
  const bulk = async (ids: readonly string[], action: BulkAction, done: string) => {
    try {
      const changed = await api.bulkSessions([...ids], action);
      say(ids.length === 1 ? done : `${done} · ${recordingsWord(changed)}`);
      await load();
    } catch (e) {
      say(errorMessage(e));
    }
  };
  const sendToWhatsApp = async (item: LibraryItem) => {
    try {
      const lines = await recordingLines(item);
      if (!lines?.length) {
        say("This recording has no transcript to send yet");
        return;
      }
      await api.whatsappOpen(transcriptText(lines, false));
    } catch (e) {
      say(errorMessage(e));
    }
  };

  /**
   * The one menu for a recording or for the picked ones: Organise, Export, then Delete, last and red. `without` leaves
   * out what the selection bar already shows as buttons.
   */
  const menuFor = (ids: readonly string[], without: ReadonlySet<string> = new Set()): MenuItem[] => {
    const chosen = (items ?? []).filter((i) => ids.includes(i.id));
    const one = chosen.length === 1 ? chosen[0] : null;
    const all = (test: (i: LibraryItem) => boolean) => chosen.length > 0 && chosen.every(test);
    const none = (test: (i: LibraryItem) => boolean) => !chosen.some(test);
    const list: (MenuItem & { key: string })[] = [];
    const add = (key: string, item: MenuItem) => { if (!without.has(key)) list.push({ key, ...item }); };
    if (one) add("open", { label: "Open", onPick: () => setOpenId(one.id) });
    if (one) add("rename", { label: "Rename…", icon: Icons.RenameIcon, group: "Organise", onPick: () => setOrganise({ kind: "rename", item: one }) });
    add("move", { label: "Move to project…", icon: Icons.MoveIcon, group: "Organise",
      onPick: () => setOrganise({ kind: "move", ids: [...ids], title: one?.title, current: one ? one.client?.id ?? null : undefined }) });
    add("label", { label: "Label…", icon: Icons.LabelIcon, group: "Organise", onPick: () => setOrganise({ kind: "label", ids: [...ids] }) });
    if (!all((i) => !!i.pinned)) add("pin", { label: "Pin", icon: Icons.PinIcon, group: "Organise", onPick: () => void bulk(ids, "pin", "Pinned") });
    if (!none((i) => !!i.pinned)) add("unpin", { label: "Unpin", icon: Icons.PinIcon, group: "Organise", onPick: () => void bulk(ids, "unpin", "Unpinned") });
    if (!all((i) => !!i.keep)) add("keep", { label: "Keep forever", icon: Icons.KeepIcon, group: "Organise", onPick: () => void bulk(ids, "keep", "Kept forever") });
    if (!none((i) => !!i.keep)) add("unkeep", { label: "Allow auto clean-up", icon: Icons.KeepIcon, group: "Organise", onPick: () => void bulk(ids, "unkeep", "Auto clean-up allowed") });
    if (!one || isUntranscribed(one)) add("transcribe", { label: "Transcribe…", icon: Icons.TranscriptIcon, group: "Organise", onPick: () => setOrganise({ kind: "transcribe", ids: [...ids] }) });
    add("export", exportMenu((format) => void exportRecordings(ids, format, say)));
    add("ai", { label: "Copy for AI", icon: Icons.SparkleIcon, group: "Export", onPick: () => void copyForAiToClipboard(chosen, say) });
    if (one) add("whatsapp", { label: "Send to WhatsApp…", icon: Icons.SendIcon, group: "Export", onPick: () => void sendToWhatsApp(one) });
    add("delete", { label: ids.length === 1 ? "Delete" : `Delete ${ids.length}`, icon: Icons.TrashIcon, group: "Danger", danger: true, shortcut: "Del", onPick: () => void removeMany(ids) });
    return list.map(({ key: _key, ...item }) => item);
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
  const counts = items ? viewCounts(items) : null;
  const shown = items && view !== "trash" ? viewItems(items, view, query) : [];
  const shownIds = shown.map((i) => i.id);
  const pickedIds = shownIds.filter((id) => picked.has(id));
  const allPicked = shownIds.length > 0 && pickedIds.length === shownIds.length;
  const toggle = (id: string) => {
    setSelecting(true);
    anchor.current = id;
    setPicked((now) => {
      const next = new Set(now);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };
  const pickAll = () => {
    setSelecting(true);
    setPicked(new Set(shownIds));
  };
  const rowClick = (item: LibraryItem, e: ReactMouseEvent<HTMLButtonElement>) => {
    if (e.shiftKey && anchor.current && shownIds.includes(anchor.current)) {
      // A range from the last ticked row to this one, added to what is picked.
      const [a, b] = [shownIds.indexOf(anchor.current), shownIds.indexOf(item.id)].sort((x, y) => x - y);
      setSelecting(true);
      setPicked((now) => new Set([...now, ...shownIds.slice(a, b + 1)]));
      anchor.current = item.id;
    } else if (selecting || e.shiftKey || e.metaKey || e.ctrlKey) {
      toggle(item.id);
    } else {
      setOpenId(item.id);
    }
  };
  const rowMenu = (item: LibraryItem, e: ReactMouseEvent<HTMLElement> | ReactKeyboardEvent<HTMLButtonElement>) => {
    const at = "clientX" in e && (e.clientX || e.clientY) ? { x: e.clientX, y: e.clientY } : menuPointFor(e.currentTarget);
    setContext({ at, ids: selecting && picked.has(item.id) ? pickedIds : [item.id] });
  };

  // Keys for the list: Cmd/Ctrl+A picks every row shown, Delete sends the picked (or the focused) ones to Trash with
  // Undo, Esc ends selection, J and K move between rows, X ticks the focused row.
  const keys = useRef<(e: KeyboardEvent) => void>(() => undefined);
  keys.current = (e: KeyboardEvent) => {
    if (open || view === "trash" || !items || typing(e.target) || organise || context) return;
    const focused = (document.activeElement as HTMLElement | null)?.closest<HTMLElement>("[data-row-id]")?.dataset.rowId
      ?? (document.activeElement as HTMLElement | null)?.dataset?.rowId;
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "a") {
      e.preventDefault();
      pickAll();
    } else if ((e.key === "Delete" || e.key === "Backspace") && !e.metaKey && !e.ctrlKey) {
      const ids = selecting && pickedIds.length ? pickedIds : focused ? [focused] : [];
      if (!ids.length) return;
      e.preventDefault();
      void removeMany(ids);
    } else if (e.key === "Escape" && selecting) {
      e.preventDefault();
      endSelection();
    } else if ((e.key === "j" || e.key === "k") && !e.metaKey && !e.ctrlKey && !e.altKey) {
      const rows = [...(root.current?.querySelectorAll<HTMLElement>("[data-row-id]") ?? [])];
      if (!rows.length) return;
      e.preventDefault();
      const at = rows.findIndex((r) => r.dataset.rowId === focused);
      const to = at < 0 ? 0 : Math.max(0, Math.min(rows.length - 1, at + (e.key === "j" ? 1 : -1)));
      rows[to].focus();
    } else if (e.key === "x" && focused && !e.metaKey && !e.ctrlKey) {
      e.preventDefault();
      toggle(focused);
    }
  };
  useEffect(() => {
    const on = (e: KeyboardEvent) => keys.current(e);
    document.addEventListener("keydown", on);
    return () => document.removeEventListener("keydown", on);
  }, []);
  useEffect(() => {
    if (openId) endSelection();
  }, [openId, endSelection]);

  const closeOrganise = () => {
    setOrganise(null);
    void load();
  };
  const dialogs = (
    <>
      {context && <ContextMenu label={context.ids.length === 1 ? "Recording actions" : `Actions for ${context.ids.length} recordings`}
        items={menuFor(context.ids)} at={context.at} onClose={() => setContext(null)} />}
      {organise?.kind === "move" && <MoveDialog ids={organise.ids} title={organise.title} current={organise.current} onClose={closeOrganise} say={say} />}
      {organise?.kind === "label" && <LabelDialog ids={organise.ids} known={knownLabels(items ?? [])} onClose={closeOrganise} say={say} />}
      {organise?.kind === "transcribe" && <TranscribeDialog ids={organise.ids} onClose={closeOrganise} say={say} />}
      {organise?.kind === "rename" && <RenameDialog item={organise.item} onClose={closeOrganise} say={say}
        onRenamed={(r) => setItems((list) => list?.map((i) => (i.id === r.id ? { ...i, ...r } : i)) ?? list)} />}
    </>
  );
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
        {dialogs}
        {toasts}
      </div>
    );
  }

  const chip = (v: SavedView, label: string) => (
    <Chip label={label} selected={view === v} count={v !== "all" && v !== "trash" && counts ? counts[v] : undefined}
      onToggle={() => setView(view === v && v !== "all" ? "all" : v)} />
  );

  return (
    <div className="el-root el-embedded" data-v2="library" ref={root}>
      <div className="el-page el-page--wide">
        {selecting ? (
          <header className="el-page__head el-selectbar" role="toolbar" aria-label="Selected recordings">
            <div className="el-selectbar__start">
              <Button variant="quiet" icon={Icons.CloseIcon} aria-label="Cancel selection" onClick={endSelection} />
              <h1 className="el-title-m" aria-live="polite">{pickedIds.length ? `${pickedIds.length} selected` : "Select recordings"}</h1>
              <Button variant="quiet" size="sm" aria-keyshortcuts="Meta+A Control+A" onClick={() => (allPicked ? setPicked(new Set()) : pickAll())}>
                {allPicked ? "Unselect all" : "Select all"}
              </Button>
            </div>
            <div className="el-page__actions">
              <Button variant="quiet" icon={Icons.MoveIcon} aria-label="Move to project" title="Move to project" disabled={!pickedIds.length}
                onClick={() => setOrganise({ kind: "move", ids: pickedIds })} />
              <Button variant="quiet" icon={Icons.LabelIcon} aria-label="Label" title="Label" disabled={!pickedIds.length}
                onClick={() => setOrganise({ kind: "label", ids: pickedIds })} />
              {pickedIds.length > 0 && (
                <Menu label="Share or export" button={{ variant: "quiet", icon: Icons.ShareIcon, title: "Share or export" }}
                  items={[...exportMenu((format) => void exportRecordings(pickedIds, format, say)).items!.map((i) => ({ ...i, group: "Export transcripts" })),
                    { label: "Copy for AI", icon: Icons.SparkleIcon, group: "Copy", onPick: () => void copyForAiToClipboard((items ?? []).filter((i) => picked.has(i.id)), say) }]} />
              )}
              <Button variant="quiet" icon={Icons.TrashIcon} aria-label="Delete" title={`Delete (Del)`} aria-keyshortcuts="Delete" disabled={!pickedIds.length}
                onClick={() => void removeMany(pickedIds)} />
              {pickedIds.length > 0 && (
                <Menu label="More actions" button={{ variant: "quiet", icon: Icons.MoreIcon }}
                  items={menuFor(pickedIds, new Set(["open", "move", "label", "ai", "delete"]))} />
              )}
            </div>
          </header>
        ) : (
          <header className="el-page__head">
            <h1 className="el-title-l">{view === "trash" ? "Trash" : "Library"}</h1>
            <div className="el-page__actions">
              {view !== "trash" && (counts?.all ?? 0) > 0 && (
                <Button variant="quiet" icon={Icons.SelectIcon} title={`Select (${MOD}A selects all)`} onClick={() => setSelecting(true)}>Select</Button>
              )}
              {onImport && <Button onClick={onImport}>Import</Button>}
              {onRecord && <Button variant="primary" icon={Icons.RecordIcon} onClick={onRecord}>New recording</Button>}
            </div>
          </header>
        )}
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
                    badge={r.badge ? <Badge tone={r.badge.tone}>{r.badge.text}</Badge> : undefined} mark={r.screens ? <ScreensMark count={r.screens} /> : undefined}
                    endTop={r.when} endBottom={r.duration ? <span className="el-num">{r.duration}</span> : undefined}
                    rowId={item.id} onClick={(e) => rowClick(item, e)} onContextMenu={(e) => rowMenu(item, e)}
                    checked={selecting ? picked.has(item.id) : undefined}
                    actions={selecting ? undefined : <Menu label={`More for ${r.title}`} items={menuFor([item.id])} button={{ variant: "quiet", size: "sm", icon: Icons.MoreIcon }} />} />
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
      {dialogs}
      {toasts}
    </div>
  );
}
