// The Library's part of the sidebar (PLAN_LIBRARY_FILTERS §1): LIBRARY (All, Needs you, Unread, Important, Not
// transcribed, Audio only, and Shared, which opens onto Shared with me, My links and Request links), MY VIEWS (saved
// filter combinations: rename, drag to reorder or Move up / Move down, delete; "+ New view") and LABELS (lists.tsx).
// Counts are muted; only Needs you's and Unread's are bold. ⌘1…⌘9 (Ctrl on Windows) open the first nine views.
// At the sidebar's foot: Trash, then "Synced 2 min ago", which syncs again when pressed (LibraryFoot).
import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent, type ReactNode } from "react";
import { beginReorder, dragJustEnded, REORDER_ATTRIBUTE } from "../../lib/dragMove";
import { Button, ContextMenu, Dialog, Icon, Icons, TextField, menuPointFor } from "../components";
import type { IconType } from "../components/Button";
import { LIBRARY_VIEWS, LIBRARY_VIEW_NAMES, type LibraryViewId } from "./facets";
import { LabelsNav } from "./lists";
import { nameProblem, useMyViews, VIEW_NAME_MAX, ViewError, viewsStore, type MyView } from "./myViews";
import type { LibraryScope } from "./scope";
import { useSyncClock } from "./syncClock";
import { contextItems, LIBRARY_VIEW, MY_VIEW } from "../actions/contextMenus";
import { currentPlatform, popupNative } from "../actions/popup";
import { useActions } from "../actions/useActions";
import type { MenuItem } from "../components/Menu";
import { sayProblem, type Say } from "./organise";
import { stripIsolates, t, useLanguage } from "../../i18n";

const VIEW_ICONS: Record<LibraryViewId, IconType> = {
  all: Icons.LibraryIcon, needs: Icons.ErrorIcon, unread: Icons.MailIcon, important: Icons.StarIcon, audio_only: Icons.WaveIcon,
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

/**
 * A group's header: its name (pressed: folds the group, the chevron says which way) and what is at its end. With
 * `onTitle` the chevron alone folds, and the name is a link (Library: back to All recordings).
 */
export function SectionHead({ title, folded, onFold, end, onTitle, titleHint }: {
  title: string; folded: boolean; onFold: () => void; end?: ReactNode; onTitle?: () => void; titleHint?: string;
}) {
  return (
    <div className="el-nav-section__head">
      {onTitle ? (
        <>
          <button type="button" className="el-nav-section__fold" aria-expanded={!folded} aria-label={folded ? t("Show :title", { title }) : t("Fold :title", { title })} onClick={onFold}>
            <Icon icon={folded ? Icons.ChevronEndIcon : Icons.ChevronDownIcon} directional />
          </button>
          <button type="button" className="el-nav-section__link el-caption" title={titleHint} onClick={onTitle}>{title}</button>
        </>
      ) : (
        <button type="button" className="el-nav-section__title el-caption" aria-expanded={!folded} onClick={onFold}>
          <Icon icon={folded ? Icons.ChevronEndIcon : Icons.ChevronDownIcon} directional />{title}
        </button>
      )}
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
  say: Say;
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
      {count != null && count > 0 && <>{" "}<span className={`el-nav-item__count el-caption el-num${strong ? " el-nav-item__count--strong" : ""}`}>{count}</span></>}
    </button>
  );
}

export function LibraryNav({ current, counts, labelCounts, onScope, shared, onNewView, say, onLibraryChanged }: LibraryNavProps) {
  // Drawn again in the window's language when it changes (desktop Urdu, D1).
  useLanguage();
  const views = useMyViews();
  // A built-in view's menu (UX-22, 3b): Open; Unread's Mark all as read and Audio only's Transcribe all… (the Library
  // runs those two, for the place it lists).
  const [viewMenu, setViewMenu] = useState<{ view: LibraryViewId; at: { x: number; y: number } } | null>(null);
  useActions("place", { "place.open": (c) => { const view = c.targets?.[0] as LibraryViewId | undefined; if (view) onScope({ kind: "view", view }); } });
  const placeMenu = (view: LibraryViewId) => contextItems(LIBRARY_VIEW, { platform: currentPlatform(), targets: [view], kind: "place" })
    .filter((item) => item.action === "place.open" || (item.action === "view.markAllRead" && view === "unread") || (item.action === "view.transcribeAll" && view === "audio_only"));
  const openViewMenu = (view: LibraryViewId, at: { x: number; y: number }) => {
    if (currentPlatform() !== "macos") return setViewMenu({ view, at });
    void popupNative(placeMenu(view), at).then((shown) => { if (!shown) setViewMenu({ view, at }); });
  };
  const sharedOpen = !!current?.startsWith("shared:");
  const [libraryFolded, foldLibrary] = useFolded("library-folded");
  // Shared open or not is kept too; it opens by itself while one of its places is shown.
  const [sharedFolded, foldShared] = useFolded("shared-folded", true);
  const sharedShown = !sharedFolded || sharedOpen;

  // Alt+1…Alt+9 (Option on a Mac): the first nine views of the panel, the library views then My views (⌘1…⌘6 are the
  // rail's places). By the key's place, not its letter: Option+1 types "¡" on a Mac.
  const order = useRef<LibraryScope[]>([]);
  order.current = [...LIBRARY_VIEWS.map((view): LibraryScope => ({ kind: "view", view })), ...views.map((v): LibraryScope => ({ kind: "saved", id: v.id }))].slice(0, 9);
  const go = useRef(onScope);
  go.current = onScope;
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const digit = /^Digit([1-9])$/.exec(e.code)?.[1] ?? (/^[1-9]$/.test(e.key) ? e.key : null);
      if (!e.altKey || e.metaKey || e.ctrlKey || e.shiftKey || !digit || typing(e.target) || e.defaultPrevented) return;
      if (document.querySelector('[role="alertdialog"], [aria-modal="true"]')) return;
      const scope = order.current[Number(digit) - 1];
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
    return at >= 0 ? `Alt+${at + 1}` : undefined;
  };
  const currentLabel = current?.startsWith("label:") ? current.slice(6) : null;

  return (
    <div className="el-library-nav">
      <section className="el-nav-section" aria-label={t("Views")}>
        <SectionHead title={t("Views")} folded={libraryFolded} onFold={foldLibrary} />
        {viewMenu && <ContextMenu label={LIBRARY_VIEW_NAMES[viewMenu.view]} at={viewMenu.at} onClose={() => setViewMenu(null)} items={placeMenu(viewMenu.view)} />}
        {!libraryFolded && <ul className="el-nav-list" aria-label={t("Library views")}>
          {LIBRARY_VIEWS.map((view) => (
            <li key={view} onContextMenu={(e) => { e.preventDefault(); openViewMenu(view, { x: e.clientX, y: e.clientY }); }}>
              {/* facets.ts's English names, said in the window's language. */}
              <Row icon={VIEW_ICONS[view]} label={LIBRARY_VIEW_NAMES[view]} count={counts?.[view]} strong={STRONG.includes(view)}
                current={current === `view:${view}`} onClick={() => onScope({ kind: "view", view })} aria-keyshortcuts={shortcut({ kind: "view", view })} />
            </li>
          ))}
          {shared && shared.tabs.length > 0 && (
            <li>
              <Row icon={Icons.UsersIcon} label={t("Shared")} current={false} aria-expanded={sharedShown} onClick={() => {
                // Closed: it opens on Shared with me. Open: it folds (while one of its places is shown it stays open).
                if (!sharedShown) {
                  shared.open(shared.tabs[0][0]);
                  if (sharedFolded) foldShared();
                } else if (!sharedFolded) foldShared();
              }}>
                <span className="el-nav-item__fold" aria-hidden="true"><Icon icon={sharedShown ? Icons.ChevronDownIcon : Icons.ChevronEndIcon} directional /></span>
              </Row>
              {sharedShown && (
                <ul className="el-nav-list el-nav-list--sub" aria-label={t("Shared")}>
                  {shared.tabs.map(([tab, label]) => (
                    <li key={tab}>
                      <button type="button" className="el-nav-item el-nav-item--row el-nav-item--sub" aria-current={shared.current === tab ? "page" : undefined}
                        onClick={() => shared.open(tab)}>
                        {/* The tabs' English names (Sidebar.tsx SHARED_TABS), said in the window's language. */}
                        <span className="el-nav-item__label">{tab === "with-me" ? t("Shared with me") : t(label)}</span>
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
  say: Say; shortcut: (id: string) => string | undefined;
}) {
  useLanguage();
  const [menu, setMenu] = useState<{ view: MyView; at: { x: number; y: number } } | null>(null);
  const [folded, fold] = useFolded("views-folded");
  const [renaming, setRenaming] = useState<MyView | null>(null);
  const [deleting, setDeleting] = useState<MyView | null>(null);
  // Dragging a view (pointer events, as recordings are dragged: the page's own drag and drop is off on Windows): the
  // place it would go before is marked.
  const [over, setOver] = useState<number | null>(null);
  const move = (view: MyView, to: number) => void viewsStore.move(view.id, to).catch((e) => sayProblem(say, e instanceof Error ? e.message : String(e)));
  // A view's menu (UX-22, 3b), from actions.json: Open | Rename… · Duplicate · Move up · Move down | Delete view….
  const byId = (c: { targets?: readonly string[] }) => views.find((v) => v.id === c.targets?.[0]);
  const at = (c: { targets?: readonly string[] }) => views.findIndex((v) => v.id === c.targets?.[0]);
  useActions("view", {
    "place.open": (c) => { if (c.targets?.[0]) onOpen(c.targets[0]); },
    "file.rename": (c) => { const v = byId(c); if (v) setRenaming(v); },
    "view.duplicate": (c) => {
      const v = byId(c);
      if (!v) return;
      const taken = new Set(views.map((x) => x.name.toLocaleLowerCase()));
      // The new name goes to the server: in the window's language, without the isolates around its values.
      let name = stripIsolates(t(":name copy", { name: v.name }));
      for (let n = 2; taken.has(name.toLocaleLowerCase()); n++) name = stripIsolates(t(":name copy :n", { name: v.name, n }));
      void viewsStore.create(name, v.spec).then(() => say(t("Duplicated as :name", { name }))).catch((e) => sayProblem(say, e instanceof Error ? e.message : String(e)));
    },
    "view.moveUp": (c) => { const v = byId(c); if (v && at(c) > 0) move(v, at(c) - 1); },
    "view.moveDown": (c) => { const v = byId(c); if (v && at(c) < views.length - 1) move(v, at(c) + 1); },
    "view.delete": (c) => { const v = byId(c); if (v) setDeleting(v); },
  });
  const viewMenu = (view: MyView): MenuItem[] => {
    const i = views.findIndex((v) => v.id === view.id);
    return contextItems(MY_VIEW, { platform: currentPlatform(), targets: [view.id], kind: "view" })
      .filter((item) => !(item.action === "view.moveUp" && i === 0) && !(item.action === "view.moveDown" && i === views.length - 1));
  };
  /** The system's menu on a Mac (ours if it can't), ours elsewhere. */
  const openMenu = (view: MyView, where: { x: number; y: number }) => {
    if (currentPlatform() !== "macos") return setMenu({ view, at: where });
    void popupNative(viewMenu(view), where).then((shown) => { if (!shown) setMenu({ view, at: where }); });
  };
  const remove = async (view: MyView) => {
    setDeleting(null);
    try {
      await viewsStore.remove(view.id);
      if (current === view.id) onGone();
      say(t(":name deleted · its recordings are still in your Library", { name: view.name }));
    } catch (e) {
      sayProblem(say, e instanceof Error ? e.message : String(e));
    }
  };
  const press = (e: ReactPointerEvent, view: MyView) => {
    if ((e.target as HTMLElement).closest(".el-nav-row__more")) return;
    beginReorder(e, setOver, (index) => {
      const from = views.indexOf(view);
      const to = index > from ? index - 1 : index;
      if (to !== from) move(view, to);
    });
  };
  return (
    <section className="el-nav-section" aria-label={t("My views")}>
      <SectionHead title={t("My views")} folded={folded} onFold={fold}
        end={<Button size="sm" variant="quiet" icon={Icons.PlusIcon} aria-label={t("New view")} title={t("New view")} onClick={onNew} />} />
      {!folded && <ul className="el-nav-list" aria-label={t("My views")}>
        {views.map((v, i) => (
          <li key={v.id} className={`el-nav-row${over === i ? " el-nav-row--drop" : ""}`} {...{ [REORDER_ATTRIBUTE]: i }}
            onPointerDown={(e) => press(e, v)}
            onContextMenu={(e) => { e.preventDefault(); openMenu(v, { x: e.clientX, y: e.clientY }); }}>
            <Row icon={Icons.FilterIcon} label={v.name} current={current === v.id} onClick={() => { if (!dragJustEnded()) onOpen(v.id); }} aria-keyshortcuts={shortcut(v.id)} />
            <Button size="sm" variant="quiet" className="el-nav-row__more" icon={Icons.MoreIcon} aria-label={t("More for :name", { name: v.name })}
              onClick={(e) => openMenu(v, menuPointFor(e.currentTarget))} />
          </li>
        ))}
        <li className={over === views.length ? "el-nav-row--drop" : undefined} {...{ [REORDER_ATTRIBUTE]: views.length }}>
          <button type="button" className="el-nav-item el-nav-item--row el-nav-item--add" onClick={onNew}>
            <Icon icon={Icons.PlusIcon} /><span className="el-nav-item__label">{t("New view")}</span>
          </button>
        </li>
      </ul>}
      {menu && (() => {
        const i = views.findIndex((v) => v.id === menu.view.id);
        const view = views[i];
        if (!view) return null;
        return (
          <ContextMenu label={view.name} at={menu.at} onClose={() => setMenu(null)} items={viewMenu(view)} />
        );
      })()}
      {renaming && <ViewNameDialog title={t("Rename view")} save={t("Save")} start={renaming.name} except={renaming.id} onClose={() => setRenaming(null)}
        onSave={async (name) => {
          await viewsStore.rename(renaming.id, name);
          setRenaming(null);
          say(t("Renamed to :name", { name: name.trim() }));
        }} />}
      <Dialog open={!!deleting} alert title={deleting ? t("Delete :name?", { name: deleting.name }) : ""} description={t("Its recordings stay in your Library; only the saved filters go.")}
        onClose={() => setDeleting(null)}
        actions={<><Button onClick={() => setDeleting(null)}>{t("Cancel")}</Button><Button variant="danger" onClick={() => deleting && void remove(deleting)}>{t("Delete")}</Button></>} />
    </section>
  );
}

/** A view's name: Save as view's, or Rename view's. A problem (empty, too long, taken, 30 already) shows under it. */
export function ViewNameDialog({ title, save, start = "", except, onClose, onSave, note }: {
  title: string; save: string; start?: string; except?: string; onClose: () => void; onSave: (name: string) => Promise<void>; note?: string;
}) {
  useLanguage();
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
      actions={<><Button onClick={onClose}>{t("Cancel")}</Button><Button variant="primary" type="submit" form="el-view-name" loading={busy} loadingLabel={t("Saving…")} disabled={!name.trim()}>{save}</Button></>}>
      <form id="el-view-name" onSubmit={(e) => { e.preventDefault(); void submit(); }}>
        <TextField ref={field} label={t("View name")} value={name} maxLength={VIEW_NAME_MAX} autoComplete="off" spellCheck={false}
          onChange={(e) => { setName(e.target.value); setError(null); }} error={error ?? problem ?? undefined} />
      </form>
    </Dialog>
  );
}

/**
 * The sidebar's foot for the Library: Trash, then when it last synced (pressed: sync now). The line itself is quiet
 * (it changes every few minutes); how a sync went is said once, after a press, and whenever one fails.
 */
export function LibraryFoot({ trashCurrent, onTrash, onSync }: { trashCurrent: boolean; onTrash: () => void; onSync: () => void }) {
  const clock = useSyncClock();
  const pressed = useRef(false);
  const was = useRef(clock.syncing);
  const [said, setSaid] = useState("");
  useEffect(() => {
    if (was.current && !clock.syncing) {
      if (clock.error) setSaid(t("Couldn't sync: :error", { error: clock.error }));
      else if (pressed.current) setSaid(clock.words);
      pressed.current = false;
    }
    was.current = clock.syncing;
  }, [clock.syncing, clock.error, clock.words]);
  return (
    <div className="el-library-foot">
      <Row icon={Icons.TrashIcon} label={t("Trash")} current={trashCurrent} onClick={onTrash} />
      <button type="button" className="el-nav-item el-nav-item--row el-shell__synced el-caption" onClick={() => { pressed.current = true; setSaid(""); onSync(); }}
        aria-busy={clock.syncing || undefined} title={clock.error ? t("Couldn't sync: :error. Press to try again.", { error: clock.error }) : t("Sync now")} data-testid="sidebar-synced">
        <Icon icon={Icons.RefreshIcon} />
        <span className="el-nav-item__label">{clock.words}</span>
      </button>
      <span className="el-visually-hidden" role="status" data-testid="sidebar-synced-said">{said}</span>
    </div>
  );
}
