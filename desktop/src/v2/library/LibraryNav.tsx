// The Library's part of the sidebar (PLAN_LIBRARY_FILTERS §1): LIBRARY (All, Needs you, Unread, Important, Not
// transcribed, Audio only, and Shared, which opens onto Shared with me, My links and Request links), MY VIEWS (saved
// filter combinations: rename, drag to reorder or Move up / Move down, delete; "+ New view") and LABELS (lists.tsx).
// Counts are muted; only Needs you's and Unread's are bold. ⌘1…⌘9 (Ctrl on Windows) open the first nine views.
// At the sidebar's foot: Trash, then "Synced 2 min ago", which syncs again when pressed (LibraryFoot).
import { useEffect, useRef, useState, type DragEvent, type ReactNode } from "react";
import { Button, ContextMenu, Dialog, Icon, Icons, TextField, menuPointFor } from "../components";
import type { IconType } from "../components/Button";
import { LIBRARY_VIEWS, LIBRARY_VIEW_NAMES, type LibraryViewId } from "./facets";
import { LabelsNav } from "./lists";
import { nameProblem, useMyViews, VIEW_NAME_MAX, ViewError, viewsStore, type MyView } from "./myViews";
import type { LibraryScope } from "./scope";
import { useSyncClock } from "./syncClock";

const VIEW_ICONS: Record<LibraryViewId, IconType> = {
  all: Icons.LibraryIcon, needs: Icons.ErrorIcon, unread: Icons.MailIcon, important: Icons.StarIcon, not_transcribed: Icons.TranscriptIcon, audio_only: Icons.WaveIcon,
};
/** A group of the sidebar folded or open, kept on this computer (each group by its own key). */
export function useFolded(key: string, start = false): [boolean, () => void] {
  const storage = `echoledger-sidebar-${key}`;
  const [folded, setFolded] = useState(() => {
    try {
      const saved = window.localStorage.getItem(storage);
      return saved === null ? start : saved === "1";
    } catch {
      return start;
    }
  });
  const toggle = () => setFolded((f) => {
    try {
      window.localStorage.setItem(storage, f ? "0" : "1");
    } catch {
      // Kept for this session only.
    }
    return !f;
  });
  return [folded, toggle];
}

/** A group's header: its name (pressed: folds the group, the chevron says which way) and what is at its end. */
export function SectionHead({ title, folded, onFold, end }: { title: string; folded: boolean; onFold: () => void; end?: ReactNode }) {
  return (
    <div className="el-nav-section__head">
      <button type="button" className="el-nav-section__title el-caption" aria-expanded={!folded} onClick={onFold}>
        <Icon icon={folded ? Icons.ChevronEndIcon : Icons.ChevronDownIcon} directional />{title}
      </button>
      {end}
    </div>
  );
}

/** The counts that ask for attention are bold; the rest are quiet. */
const STRONG: readonly LibraryViewId[] = ["needs", "unread"];

const typing = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName) || !!target.closest('[role="dialog"], [role="alertdialog"], [role="menu"]'));

export type LibraryNavProps = {
  /** What the window shows: a scope's key (scope.ts), "shared:<tab>", or null (another page). */
  current: string | null;
  /** Each library view's count (Needs you with the calls and uploads waiting here); null until the list is read. */
  counts: Record<LibraryViewId, number> | null;
  /** How many recordings have each label (by key). */
  labelCounts: Readonly<Record<string, number>>;
  onScope: (scope: LibraryScope) => void;
  /** Shared's three places (Shared with me, My links, Request links) and which is open. */
  shared?: { tabs: readonly (readonly [string, string])[]; current: string | null; open: (tab: string) => void };
  /** "+ New view": the Library opens its filters, to choose some and Save as view. */
  onNewView: () => void;
  say: (text: string) => void;
  /** Labels changed (renamed, recoloured, deleted): the Library is synced once. */
  onLibraryChanged: () => void;
};

/** A row of the sidebar: icon, name, count; the current one has a quiet fill. */
function Row({ icon, label, count, strong, current, onClick, children, ...rest }: {
  icon?: IconType; label: string; count?: number | null; strong?: boolean; current?: boolean; onClick: () => void; children?: ReactNode;
} & Record<`data-${string}` | `aria-${string}`, string | number | boolean | undefined>) {
  return (
    <button type="button" className="el-nav-item el-nav-item--row" aria-current={current ? "page" : undefined}
      aria-label={count != null ? `${label}, ${count}` : undefined} onClick={onClick} {...rest}>
      {icon && <Icon icon={icon} />}
      <span className="el-nav-item__label" dir="auto">{label}</span>
      {children}
      {count != null && count > 0 && <span className={`el-nav-item__count el-caption el-num${strong ? " el-nav-item__count--strong" : ""}`}>{count}</span>}
    </button>
  );
}

export function LibraryNav({ current, counts, labelCounts, onScope, shared, onNewView, say, onLibraryChanged }: LibraryNavProps) {
  const views = useMyViews();
  const sharedOpen = !!current?.startsWith("shared:");
  const [libraryFolded, foldLibrary] = useFolded("library-folded");
  // Shared open or not is kept too; it opens by itself while one of its places is shown.
  const [sharedFolded, foldShared] = useFolded("shared-folded", true);
  const sharedShown = !sharedFolded || sharedOpen;

  // ⌘1…⌘9 (Ctrl+1…9 on Windows): the first nine views of the sidebar, the library views then My views.
  const order = useRef<LibraryScope[]>([]);
  order.current = [...LIBRARY_VIEWS.map((view): LibraryScope => ({ kind: "view", view })), ...views.map((v): LibraryScope => ({ kind: "saved", id: v.id }))].slice(0, 9);
  const go = useRef(onScope);
  go.current = onScope;
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (!(e.metaKey || e.ctrlKey) || e.altKey || e.shiftKey || !/^[1-9]$/.test(e.key) || typing(e.target) || e.defaultPrevented) return;
      if (document.querySelector('[role="alertdialog"], [aria-modal="true"]')) return;
      const scope = order.current[Number(e.key) - 1];
      if (!scope) return;
      e.preventDefault();
      go.current(scope);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);
  const key = (scope: LibraryScope) => order.current.findIndex((s) => JSON.stringify(s) === JSON.stringify(scope));
  const shortcut = (scope: LibraryScope) => {
    const at = key(scope);
    return at >= 0 ? `Meta+${at + 1} Control+${at + 1}` : undefined;
  };
  const currentLabel = current?.startsWith("label:") ? current.slice(6) : null;

  return (
    <div className="el-library-nav">
      <section className="el-nav-section" aria-label="Library">
        <SectionHead title="Library" folded={libraryFolded} onFold={foldLibrary} />
        {!libraryFolded && <ul className="el-nav-list" aria-label="Library views">
          {LIBRARY_VIEWS.map((view) => (
            <li key={view}>
              <Row icon={VIEW_ICONS[view]} label={LIBRARY_VIEW_NAMES[view]} count={counts?.[view]} strong={STRONG.includes(view)}
                current={current === `view:${view}`} onClick={() => onScope({ kind: "view", view })} aria-keyshortcuts={shortcut({ kind: "view", view })} />
            </li>
          ))}
          {shared && shared.tabs.length > 0 && (
            <li>
              <Row icon={Icons.UsersIcon} label="Shared" current={false} aria-expanded={sharedShown} onClick={() => {
                // Closed: it opens on Shared with me. Open: it folds (while one of its places is shown it stays open).
                if (!sharedShown) {
                  shared.open(shared.tabs[0][0]);
                  if (sharedFolded) foldShared();
                } else if (!sharedFolded) foldShared();
              }}>
                <span className="el-nav-item__fold" aria-hidden="true"><Icon icon={sharedShown ? Icons.ChevronDownIcon : Icons.ChevronEndIcon} directional /></span>
              </Row>
              {sharedShown && (
                <ul className="el-nav-list el-nav-list--sub" aria-label="Shared">
                  {shared.tabs.map(([tab, label]) => (
                    <li key={tab}>
                      <button type="button" className="el-nav-item el-nav-item--row el-nav-item--sub" aria-current={shared.current === tab ? "page" : undefined}
                        onClick={() => shared.open(tab)}>
                        <span className="el-nav-item__label">{label === "With me" ? "Shared with me" : label}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </li>
          )}
        </ul>}
      </section>
      <MyViewsNav views={views} current={current?.startsWith("saved:") ? current.slice(6) : null} onOpen={(id) => onScope({ kind: "saved", id })}
        onGone={() => onScope({ kind: "view", view: "all" })} onNew={onNewView} say={say} shortcut={(id) => shortcut({ kind: "saved", id })} />
      <LabelsNav counts={labelCounts} current={currentLabel} onOpen={(k) => onScope({ kind: "label", key: k })} onGone={() => onScope({ kind: "view", view: "all" })}
        say={say} onLibraryChanged={onLibraryChanged} />
    </div>
  );
}

/**
 * MY VIEWS: the person's saved views in their order, "+ New view" at the end. Each one's menu (⋮ or a right-click):
 * Rename…, Move up, Move down, Delete view…; dragging one moves it.
 */
function MyViewsNav({ views, current, onOpen, onGone, onNew, say, shortcut }: {
  views: readonly MyView[]; current: string | null; onOpen: (id: string) => void; onGone: () => void; onNew: () => void;
  say: (text: string) => void; shortcut: (id: string) => string | undefined;
}) {
  const [menu, setMenu] = useState<{ view: MyView; at: { x: number; y: number } } | null>(null);
  const [folded, fold] = useFolded("views-folded");
  const [renaming, setRenaming] = useState<MyView | null>(null);
  const [deleting, setDeleting] = useState<MyView | null>(null);
  const [dragging, setDragging] = useState<string | null>(null);
  const [over, setOver] = useState<number | null>(null);
  const move = (view: MyView, to: number) => void viewsStore.move(view.id, to).catch((e) => say(e instanceof Error ? e.message : String(e)));
  const remove = async (view: MyView) => {
    setDeleting(null);
    try {
      await viewsStore.remove(view.id);
      if (current === view.id) onGone();
      say(`${view.name} deleted · its recordings are still in your Library`);
    } catch (e) {
      say(e instanceof Error ? e.message : String(e));
    }
  };
  const drop = (e: DragEvent, index: number) => {
    e.preventDefault();
    const view = views.find((v) => v.id === dragging);
    setDragging(null);
    setOver(null);
    if (!view) return;
    const from = views.indexOf(view);
    move(view, index > from ? index - 1 : index);
  };
  return (
    <section className="el-nav-section" aria-label="My views">
      <SectionHead title="My views" folded={folded} onFold={fold}
        end={<Button size="sm" variant="quiet" icon={Icons.PlusIcon} aria-label="New view" title="New view" onClick={onNew} />} />
      {!folded && <ul className="el-nav-list" aria-label="My views">
        {views.map((v, i) => (
          <li key={v.id} className={`el-nav-row${over === i ? " el-nav-row--drop" : ""}`} draggable onDragStart={(e) => { setDragging(v.id); e.dataTransfer?.setData("text/plain", v.id); }}
            onDragOver={(e) => { if (dragging) { e.preventDefault(); setOver(i); } }} onDragLeave={() => setOver((o) => (o === i ? null : o))} onDrop={(e) => drop(e, i)}
            onDragEnd={() => { setDragging(null); setOver(null); }}
            onContextMenu={(e) => { e.preventDefault(); setMenu({ view: v, at: { x: e.clientX, y: e.clientY } }); }}>
            <Row icon={Icons.FilterIcon} label={v.name} current={current === v.id} onClick={() => onOpen(v.id)} aria-keyshortcuts={shortcut(v.id)} />
            <Button size="sm" variant="quiet" className="el-nav-row__more" icon={Icons.MoreIcon} aria-label={`More for ${v.name}`}
              onClick={(e) => setMenu({ view: v, at: menuPointFor(e.currentTarget) })} />
          </li>
        ))}
        <li className={over === views.length ? "el-nav-row--drop" : undefined} onDragOver={(e) => { if (dragging) { e.preventDefault(); setOver(views.length); } }}
          onDrop={(e) => drop(e, views.length)}>
          <button type="button" className="el-nav-item el-nav-item--row el-nav-item--add" onClick={onNew}>
            <Icon icon={Icons.PlusIcon} /><span className="el-nav-item__label">New view</span>
          </button>
        </li>
      </ul>}
      {menu && (() => {
        const i = views.findIndex((v) => v.id === menu.view.id);
        const view = views[i];
        if (!view) return null;
        return (
          <ContextMenu label={view.name} at={menu.at} onClose={() => setMenu(null)} items={[
            { label: "Rename…", icon: Icons.RenameIcon, onPick: () => setRenaming(view) },
            ...(i > 0 ? [{ label: "Move up", onPick: () => move(view, i - 1) }] : []),
            ...(i < views.length - 1 ? [{ label: "Move down", onPick: () => move(view, i + 1) }] : []),
            { label: "Delete view…", icon: Icons.TrashIcon, danger: true, separator: true, onPick: () => setDeleting(view) },
          ]} />
        );
      })()}
      {renaming && <ViewNameDialog title="Rename view" save="Save" start={renaming.name} except={renaming.id} onClose={() => setRenaming(null)}
        onSave={async (name) => {
          await viewsStore.rename(renaming.id, name);
          setRenaming(null);
          say(`Renamed to ${name.trim()}`);
        }} />}
      <Dialog open={!!deleting} alert title={deleting ? `Delete ${deleting.name}?` : ""} description="Its recordings stay in your Library; only the saved filters go."
        onClose={() => setDeleting(null)}
        actions={<><Button onClick={() => setDeleting(null)}>Cancel</Button><Button variant="danger" onClick={() => deleting && void remove(deleting)}>Delete</Button></>} />
    </section>
  );
}

/** A view's name: Save as view's, or Rename view's. A problem (empty, too long, taken, 30 already) shows under it. */
export function ViewNameDialog({ title, save, start = "", except, onClose, onSave, note }: {
  title: string; save: string; start?: string; except?: string; onClose: () => void; onSave: (name: string) => Promise<void>; note?: string;
}) {
  const views = useMyViews();
  const [name, setName] = useState(start);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const field = useRef<HTMLInputElement>(null);
  const problem = name.trim() ? nameProblem(name, views, except) : null;
  const submit = async () => {
    const p = nameProblem(name, views, except);
    if (p) return setError(p);
    setBusy(true);
    try {
      await onSave(name);
    } catch (e) {
      setError(e instanceof ViewError || e instanceof Error ? e.message : String(e));
      setBusy(false);
    }
  };
  return (
    <Dialog open title={title} description={note} onClose={onClose} initialFocus={field}
      actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" type="submit" form="el-view-name" loading={busy} loadingLabel="Saving…" disabled={!name.trim()}>{save}</Button></>}>
      <form id="el-view-name" onSubmit={(e) => { e.preventDefault(); void submit(); }}>
        <TextField ref={field} label="View name" value={name} maxLength={VIEW_NAME_MAX} autoComplete="off" spellCheck={false}
          onChange={(e) => { setName(e.target.value); setError(null); }} error={error ?? problem ?? undefined} />
      </form>
    </Dialog>
  );
}

/** The sidebar's foot for the Library: Trash, then when it last synced (pressed: sync now). */
export function LibraryFoot({ trashCurrent, onTrash, onSync }: { trashCurrent: boolean; onTrash: () => void; onSync: () => void }) {
  const clock = useSyncClock();
  return (
    <div className="el-library-foot">
      <Row icon={Icons.TrashIcon} label="Trash" current={trashCurrent} onClick={onTrash} />
      <button type="button" className="el-nav-item el-nav-item--row el-shell__synced el-caption" onClick={onSync} aria-busy={clock.syncing || undefined}
        title={clock.error ? `Couldn't sync: ${clock.error}. Press to try again.` : "Sync now"} aria-live="polite" data-testid="sidebar-synced">
        <Icon icon={Icons.RefreshIcon} />
        <span className="el-nav-item__label">{clock.words}</span>
      </button>
    </div>
  );
}
