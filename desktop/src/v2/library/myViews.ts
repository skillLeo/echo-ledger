// My views (PLAN_LIBRARY_FILTERS, MY VIEWS; contract 2.0.0-p "Saved views"): saved filter sets, at most 30 per person,
// in the person's order, the same on every device. A view keeps the filters (a filter set, as facets.ts holds them),
// the search and how the list is shown (sort, group, density), never a recording or its words.
// Behind one interface (ViewsStore), kept local-first: a copy per account on this computer, so the views show and change
// offline at once; each change is marked and sent to `/api/views` when the server can be reached (a view made offline
// goes with its own client_ref, so sending it twice never makes it twice); whenever the library sync carries a
// `views_version` other than the copy's, the copy is read again from the server.
import { useEffect, useSyncExternalStore } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, type ServerView, type ViewRefusal } from "../../lib/ipc";
import type { SortKey } from "../../lib/library";
import type { GroupKey } from "../../lib/libraryGroups";
import type { Density } from "../../lib/shell";
import { cleanSpec, readSpec, type FilterSpec } from "./facets";

export const MAX_VIEWS = 30;
export const VIEW_NAME_MAX = 60;

export type ViewSpec = { filters: FilterSpec; search: string | null; sort: SortKey; group: GroupKey; density?: Density | null };
/**
 * A view: its server id once the server has it (a view made offline has a local id until then), the `client_ref` it
 * was made with, and `pending` while a change waits to be sent.
 */
export type MyView = {
  id: string; name: string; spec: ViewSpec; client_ref?: string; pending?: "create" | "update" | "delete";
  /** The server's last copy of its name and settings: what a refused rename or Save changes puts back. */
  saved?: { name: string; spec: ViewSpec };
};

/** Why a view can't be saved or renamed, in the words the dialog shows. */
export const VIEW_WORDS = {
  full: `You can keep up to ${MAX_VIEWS} views. Delete one to save another.`,
  empty: "Give the view a name.",
  long: `Use at most ${VIEW_NAME_MAX} characters.`,
  taken: (name: string) => `You already have a view called ${name}.`,
  gone: "That view is gone. It may have been deleted on another device.",
  notReady: "Your views are still loading. Try again in a moment.",
  notSaved: (name: string, why: string) => `${name} wasn't saved: ${why}`,
  notChanged: (name: string, why: string) => `Your changes to ${name} weren't saved: ${why}`,
  notDeleted: (name: string, why: string) => `${name} wasn't deleted: ${why}`,
} as const;

export class ViewError extends Error {}

export interface ViewsStore {
  list(): readonly MyView[];
  create(name: string, spec: ViewSpec): Promise<MyView>;
  rename(id: string, name: string): Promise<void>;
  /** Saves the view's filters again (Save changes when a view is open and changed). */
  update(id: string, spec: ViewSpec): Promise<void>;
  remove(id: string): Promise<void>;
  /** Moves a view to `index` in the order (drag, or Move up / Move down). */
  move(id: string, index: number): Promise<void>;
  subscribe(listener: () => void): () => void;
}

const spaces = (name: string) => name.trim().replace(/\s+/g, " ");

/** A name's problem, or null: empty, too long, or one of the person's other views already (whatever its case). */
export function nameProblem(name: string, views: readonly MyView[], except?: string): string | null {
  const n = spaces(name);
  if (!n) return VIEW_WORDS.empty;
  if (n.length > VIEW_NAME_MAX) return VIEW_WORDS.long;
  const twin = views.find((v) => v.id !== except && v.pending !== "delete" && v.name.trim().toLowerCase() === n.toLowerCase());
  return twin ? VIEW_WORDS.taken(twin.name) : null;
}

/** A view's settings as kept: the filter set cleaned, the search trimmed (none: null). */
export const cleanViewSpec = (spec: ViewSpec): ViewSpec =>
  ({ ...spec, filters: cleanSpec(spec.filters), search: spec.search?.trim() ? spec.search.trim().slice(0, 200) : null });

// The contract's words for sort and group, and this app's.
const SORT_OUT: Record<SortKey, string> = { newest: "newest", oldest: "oldest", longest: "longest", shortest: "shortest", title: "name", opened: "opened" };
const GROUP_OUT: Record<GroupKey, string> = { date: "date", app: "source", project: "project", language: "language", status: "status", device: "device", none: "none" };
const inverse = <K extends string>(map: Record<K, string>) => Object.fromEntries(Object.entries(map).map(([k, v]) => [v, k])) as Record<string, K>;
const SORT_IN = inverse(SORT_OUT);
const GROUP_IN = inverse(GROUP_OUT);

/** A view as the server sends it, as this app keeps it (a sort or group it lacks shows as Newest or Date). */
export function fromServer(v: ServerView): MyView {
  const spec: ViewSpec = {
    filters: readSpec(v.filters), search: v.search ?? null, sort: SORT_IN[v.sort] ?? "newest", group: GROUP_IN[v.group] ?? "date",
    density: v.density === "compact" ? "compact" : v.density === "comfortable" ? "comfortable" : null,
  };
  return { id: v.id, name: v.name, client_ref: v.client_ref ?? undefined, spec, saved: { name: v.name, spec } };
}
/** A view as the server takes it. */
export function toServer(v: MyView): Omit<ServerView, "id" | "position"> {
  const s = cleanViewSpec(v.spec);
  return {
    name: spaces(v.name), filters: s.filters as Record<string, unknown>, search: s.search, sort: SORT_OUT[s.sort] ?? "newest", group: GROUP_OUT[s.group] ?? "date",
    density: s.density ?? "comfortable", ...(v.client_ref ? { client_ref: v.client_ref } : {}),
  };
}

const KEY = "echoledger-my-views";
/** A view made here has this id until the server gives it one. */
const LOCAL = "local-";
/** Whether `id` names this view: its id, or the id it had here before the server gave it one. */
export const isView = (v: MyView, id: string) => v.id === id || (!!v.client_ref && id === `${LOCAL}${v.client_ref}`);
const newRef = () => (typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : `r-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`);

/** What is kept on this computer for one account: the views (with what waits to be sent), the version, the order. */
type Kept = { views: MyView[]; version: number | null; reorder: boolean };
const EMPTY: Kept = { views: [], version: null, reorder: false };

function read(key: string): Kept {
  try {
    const saved = JSON.parse(window.localStorage.getItem(key) ?? "null") as Partial<Kept> | MyView[] | null;
    const list = Array.isArray(saved) ? saved : saved?.views ?? [];
    const views = list.filter((v): v is MyView => !!v && typeof v.id === "string" && typeof v.name === "string" && !!v.spec)
      .map((v) => ({
        ...v, spec: { ...v.spec, filters: readSpec(v.spec.filters), search: v.spec.search ?? null },
        saved: v.saved?.spec ? { name: v.saved.name, spec: { ...v.saved.spec, filters: readSpec(v.saved.spec.filters), search: v.saved.spec.search ?? null } } : undefined,
      }));
    return { views, version: Array.isArray(saved) ? null : saved?.version ?? null, reorder: !Array.isArray(saved) && !!saved?.reorder };
  } catch {
    return { views: [], version: null, reorder: false };
  }
}

const refusalOf = (answer: unknown): ViewRefusal | null =>
  (answer && typeof answer === "object" && "refused" in answer ? (answer as { refused: ViewRefusal }).refused : null);

/**
 * The views kept on this computer, one copy per account and workspace (`account`: the server, the email and the
 * workspace), in step with the server's. None shows, and nothing is sent, until the account is known: one account's
 * views never show in another's. Each account switch starts a new generation; what a sync of an older one hears back
 * is dropped (a view it was sending stays waiting in that account's copy, and its client_ref makes it once). A change
 * the server refuses for good is dropped (a new view) or given back to the server's copy (a change), said through
 * `onProblem`, and the sync goes on with the next.
 */
export type SyncedViewsStore = ViewsStore & {
  /**
   * Whose views: `confirmed` once the server has said whose account this is; until then (the account remembered from
   * this sign-in, offline) they show and change on this computer only, and nothing is sent or read from the server.
   */
  use(account: string | null, confirmed?: boolean): void;
  /** Stops showing these accounts' views (their sign-in is over). */
  release(accounts: readonly string[]): void;
  sync(): Promise<void>;
  onProblem(f: (text: string) => void): () => void;
};

export function syncedViewsStore(): SyncedViewsStore {
  let key: string | null = null;
  let confirmed = false;
  let kept: Kept = EMPTY;
  let shown: MyView[] = [];
  let gen = 0;
  const listeners = new Set<() => void>();
  const problems = new Set<(text: string) => void>();
  let unlisten: (() => void) | null = null;
  let running: { gen: number; promise: Promise<void> } | null = null;
  let again = false;
  const show = (next: Kept) => {
    kept = next;
    shown = next.views.filter((v) => v.pending !== "delete");
    listeners.forEach((l) => l());
  };
  /** Keeps `next` as this account's copy; a write from another generation (another account's sync) is dropped. */
  const save = (next: Kept, g = gen): boolean => {
    if (g !== gen || key === null) return false;
    try {
      window.localStorage.setItem(key, JSON.stringify(next));
    } catch {
      // Storage can be unavailable: the views last until the window closes.
    }
    show(next);
    return true;
  };
  const say = (text: string) => problems.forEach((p) => p(text));
  const find = (id: string) => {
    const at = kept.views.findIndex((v) => v.id === id && v.pending !== "delete");
    if (at < 0) throw new ViewError(VIEW_WORDS.gone);
    return at;
  };
  const ready = () => {
    if (key === null) throw new ViewError(VIEW_WORDS.notReady);
  };
  const patch = (id: string, change: (v: MyView) => MyView) => save({ ...kept, views: kept.views.map((v) => (v.id === id ? change(v) : v)) });
  const drop = (id: string, g = gen) => save({ ...kept, views: kept.views.filter((v) => v.id !== id) }, g);
  /** A change refused: no longer waiting, and the server's copy is read (version forgotten) to put it back. */
  /** A change refused: the server's last copy of it is put back (its version forgotten, so the list is read again). */
  const restore = (id: string, g: number) => save({
    ...kept, version: null,
    views: kept.views.map((v) => (v.id === id ? { ...v, ...(v.saved ? { name: v.saved.name, spec: v.saved.spec } : {}), pending: undefined } : v)),
  }, g);

  /** Sends what waits, in order, then reads the server's copy when its version is not ours. Stops quietly when offline. */
  const work = async (g: number) => {
    // Anything sent: the server's copy is read after (another device may have changed the views meanwhile, and the
    // version an answer carries already counts that change).
    let sent = false;
    for (const view of [...kept.views]) {
      if (g !== gen) return;
      const now = kept.views.find((v) => v.id === view.id);
      if (!now?.pending) continue;
      try {
        if (now.pending === "create") {
          const answer = await api.createView(toServer(now));
          if (g !== gen) return;
          sent = true;
          const refused = refusalOf(answer);
          if (refused) {
            drop(now.id, g);
            say(VIEW_WORDS.notSaved(now.name, refused.code === "views_limit" ? VIEW_WORDS.full : refused.message));
            continue;
          }
          const made = fromServer((answer as { data: ServerView }).data);
          // Deleted here while it was on its way: it goes on the server too.
          if (!kept.views.some((v) => v.id === now.id)) {
            await api.deleteView(made.id).catch(() => undefined);
            continue;
          }
          // It keeps its place and what was changed since it was sent; the server's id from now on.
          save({
            ...kept, version: (answer as { views_version: number }).views_version,
            views: kept.views.map((v) => (v.id === now.id ? {
              ...v, id: made.id, saved: made.saved,
              pending: v.pending === "create" && v.spec === now.spec && v.name === now.name ? undefined : v.pending === "delete" ? "delete" : v.pending === "create" ? "update" : v.pending,
            } : v)),
          }, g);
        } else if (now.pending === "update") {
          const answer = await api.updateView(now.id, toServer({ ...now, client_ref: undefined }));
          if (g !== gen) return;
          sent = true;
          const refused = refusalOf(answer);
          if (refused) {
            // Deleted on another device: it goes quietly. Else the server's copy comes back, and why is said.
            if (refused.code === "view_not_found") drop(now.id, g);
            else {
              restore(now.id, g);
              say(VIEW_WORDS.notChanged(now.name, refused.message));
            }
            continue;
          }
          // Done, unless it was changed again or deleted while on its way.
          const saved = fromServer((answer as { data: ServerView }).data).saved;
          save({
            ...kept, version: (answer as { views_version: number }).views_version,
            views: kept.views.map((v) => (v.id !== now.id ? v : v.pending === "update" && v.spec === now.spec && v.name === now.name ? { ...v, saved, pending: undefined } : { ...v, saved })),
          }, g);
        } else {
          const answer = await api.deleteView(now.id);
          if (g !== gen) return;
          sent = true;
          const refused = refusalOf(answer);
          if (refused && refused.code !== "view_not_found") {
            restore(now.id, g);
            say(VIEW_WORDS.notDeleted(now.name, refused.message));
            continue;
          }
          save({ ...kept, version: refused ? kept.version : (answer as { views_version: number }).views_version, views: kept.views.filter((v) => v.id !== now.id) }, g);
        }
      } catch {
        // Offline, a busy or held server, or a server without saved views: kept here, sent next time.
        return;
      }
    }
    if (g !== gen) return;
    // What changed while the loop ran goes on the next round.
    if (kept.views.some((v) => v.pending)) {
      again = true;
      return;
    }
    if (kept.reorder) {
      try {
        const answer = await api.orderViews(kept.views.map((v) => v.id));
        if (g !== gen) return;
        sent = true;
        const refused = refusalOf(answer);
        // Refused (a view deleted elsewhere meanwhile, say): the server's order is read back.
        save({ ...kept, reorder: false, version: refused ? null : (answer as { views_version: number }).views_version }, g);
      } catch {
        return;
      }
    }
    try {
      const version = sent ? null : await api.viewsVersion();
      if (g !== gen) return;
      if (!sent && version !== null && version === kept.version) return;
      const answer = await api.views();
      if (g !== gen || !answer) return;
      // Nothing waits: the server's copy is the copy, in its order.
      if (!kept.views.some((v) => v.pending) && !kept.reorder) save({ views: answer.data.map(fromServer), version: answer.views_version, reorder: false }, g);
    } catch {
      // Offline: the copy stays.
    }
  };
  /** One sync at a time per account; a change made while one runs is sent by one more round. */
  const sync = (): Promise<void> => {
    if (key === null || !confirmed) return Promise.resolve();
    if (running && running.gen === gen) {
      again = true;
      return running.promise;
    }
    const g = gen;
    again = false;
    const promise: Promise<void> = work(g).finally(() => {
      if (running?.promise === promise) running = null;
      if (g !== gen) return;
      const more = again && kept.views.some((v) => v.pending);
      again = false;
      if (more) void sync();
    });
    running = { gen: g, promise };
    return promise;
  };

  return {
    use(account, sure = true) {
      const next = account ? `${KEY}:${account}` : null;
      if (next === key) {
        // The same account, now confirmed by the server: what waits here is sent from now on.
        if (sure && !confirmed && next) {
          confirmed = true;
          if (listeners.size) void sync();
        }
        return;
      }
      gen++;
      key = next;
      confirmed = sure && next !== null;
      again = false;
      running = null;
      show(next ? read(next) : EMPTY);
      if (next && listeners.size) void sync();
    },
    sync,
    release(accounts) {
      if (key && accounts.some((a) => `${KEY}:${a}` === key)) this.use(null);
    },
    onProblem(f) {
      problems.add(f);
      return () => void problems.delete(f);
    },
    list: () => shown,
    async create(name, spec) {
      ready();
      if (shown.length >= MAX_VIEWS) throw new ViewError(VIEW_WORDS.full);
      const problem = nameProblem(name, kept.views);
      if (problem) throw new ViewError(problem);
      const ref = newRef();
      const view: MyView = { id: `${LOCAL}${ref}`, name: spaces(name), spec: cleanViewSpec(spec), client_ref: ref, pending: "create" };
      save({ ...kept, views: [...kept.views, view] });
      void sync();
      return view;
    },
    async rename(id, name) {
      ready();
      find(id);
      const problem = nameProblem(name, kept.views, id);
      if (problem) throw new ViewError(problem);
      patch(id, (v) => ({ ...v, name: spaces(name), pending: v.pending ?? "update" }));
      void sync();
    },
    async update(id, spec) {
      ready();
      find(id);
      patch(id, (v) => ({ ...v, spec: cleanViewSpec(spec), pending: v.pending ?? "update" }));
      void sync();
    },
    async remove(id) {
      ready();
      const at = find(id);
      // Never sent: it just goes. Else it waits to be deleted on the server (and shows nowhere meanwhile).
      if (kept.views[at].pending === "create") drop(id);
      else patch(id, (v) => ({ ...v, pending: "delete" }));
      void sync();
    },
    async move(id, index) {
      ready();
      find(id);
      const live = kept.views.filter((v) => v.pending !== "delete");
      const from = live.findIndex((v) => v.id === id);
      const [view] = live.splice(from, 1);
      live.splice(Math.max(0, Math.min(live.length, index)), 0, view);
      save({ ...kept, reorder: true, views: [...live, ...kept.views.filter((v) => v.pending === "delete")] });
      void sync();
    },
    subscribe(listener) {
      // Read again when something starts showing them (another window, or the account, may have changed them), and
      // keep in step with the server while they show: now, and after every library sync.
      if (listeners.size === 0) {
        if (key) {
          show(read(key));
          void sync();
        }
        const un = listen("library:updated", () => void sync());
        unlisten = () => void un.then((f) => f());
      }
      listeners.add(listener);
      return () => {
        listeners.delete(listener);
        if (listeners.size === 0) {
          unlisten?.();
          unlisten = null;
        }
      };
    },
  };
}

/** The app's views store (one for the window: the sidebar and the Library share it). */
export const viewsStore: SyncedViewsStore = syncedViewsStore();

/** The person's views, kept up to date. */
export function useMyViews(store: ViewsStore = viewsStore): readonly MyView[] {
  return useSyncExternalStore(store.subscribe, store.list, store.list);
}

/**
 * Whose views these are: the server, the email and the workspace together; null until all three are known (then no
 * view shows, and none is sent).
 */
export function viewsAccount(server: string | null | undefined, email: string | null | undefined, workspace: unknown): string | null {
  const host = hostOf(server);
  const who = (email ?? "").trim().toLowerCase();
  const where = workspace === null || workspace === undefined ? "" : String(workspace).trim();
  return host && who && where ? `${host}|${who}|${where}` : null;
}

const LAST_ACCOUNT_KEY = "echoledger-my-views-account";
const hostOf = (server: string | null | undefined) => (server ?? "").trim().replace(/\/+$/, "").toLowerCase();

/**
 * What is kept for one sign-in (the core's id for it, AppStatus.signIn): whose views were shown last, and every
 * account whose views were kept on this computer during it (to clear them at sign-out).
 */
type Remembered = { server: string; signIn: string; account: string; accounts: string[] };
function remembered(): Remembered | null {
  try {
    const kept = JSON.parse(window.localStorage.getItem(LAST_ACCOUNT_KEY) ?? "null") as Partial<Remembered> | null;
    return kept && typeof kept.signIn === "string" && typeof kept.account === "string" && typeof kept.server === "string"
      ? { server: kept.server, signIn: kept.signIn, account: kept.account, accounts: Array.isArray(kept.accounts) ? kept.accounts.filter((a): a is string => typeof a === "string") : [kept.account] }
      : null;
  } catch {
    return null;
  }
}

/** Whose views this computer showed last, whatever the sign-in (null: none kept): the one an earlier version's
 * computer-wide layout belongs to (the shell's panels). */
export function lastAccountHere(): string | null {
  return remembered()?.account ?? null;
}

/** Keeps whose views were shown last in this sign-in, to show them (on this computer only) while the account can't be read. */
export function rememberViewsAccount(server: string | null | undefined, account: string, signIn: string | null | undefined) {
  if (!signIn) return;
  const now = remembered();
  const accounts = now?.signIn === signIn ? [...new Set([...now.accounts, account])] : [account];
  try {
    window.localStorage.setItem(LAST_ACCOUNT_KEY, JSON.stringify({ server: hostOf(server), signIn, account, accounts }));
  } catch {
    // Storage unavailable: offline, no views until the account is read.
  }
}
/** Whose views were shown last in this very sign-in with this server, or null. */
export function lastViewsAccount(server: string | null | undefined, signIn: string | null | undefined): string | null {
  const kept = remembered();
  return kept && signIn && kept.signIn === signIn && hostOf(server) && kept.server === hostOf(server) ? kept.account : null;
}

/**
 * The sign-in now (null: signed out). What was kept for another sign-in goes: whose views they were, and each of its
 * accounts' copies, unless something in a copy still waits to be sent; that copy stays under its own account (no one
 * else's views read it) and is sent when that account signs in here again.
 */
export function settleViewsAccount(signIn: string | null | undefined) {
  const kept = remembered();
  if (!kept || (signIn && kept.signIn === signIn)) return;
  viewsStore.release(kept.accounts);
  for (const account of kept.accounts) {
    const copy = `${KEY}:${account}`;
    const waiting = read(copy);
    if (waiting.views.some((v) => v.pending) || waiting.reorder) continue;
    try {
      window.localStorage.removeItem(copy);
    } catch {
      // Nothing to clear.
    }
  }
  try {
    window.localStorage.removeItem(LAST_ACCOUNT_KEY);
  } catch {
    // Nothing kept.
  }
}

/**
 * Uses an account's own copy in its workspace (viewsAccount); none while it isn't known. `confirmed`: the server said
 * so (else it is the one remembered from this sign-in: shown, kept here, never sent until confirmed).
 */
export function useViewsAccount(account: string | null, confirmed = true) {
  useEffect(() => {
    viewsStore.use(account, confirmed);
  }, [account, confirmed]);
}
