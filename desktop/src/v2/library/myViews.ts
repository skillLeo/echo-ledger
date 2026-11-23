// My views (PLAN_LIBRARY_FILTERS, MY VIEWS; contract 2.0.0-p "Saved views"): saved filter sets, at most 30 per person,
// in the person's order, the same on every device. A view keeps the filters (a filter set, as facets.ts holds them),
// the search and how the list is shown (sort, group, density), never a recording or its words.
// Behind one interface (ViewsStore), kept local-first: a copy per account on this computer, so the views show and change
// offline at once; each change is marked and sent to `/api/views` when the server can be reached (a view made offline
// goes with its own client_ref, so sending it twice never makes it twice); whenever the library sync carries a
// `views_version` other than the copy's, the copy is read again from the server.
import { useEffect, useSyncExternalStore } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type ServerView } from "../../lib/ipc";
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
export type MyView = { id: string; name: string; spec: ViewSpec; client_ref?: string; pending?: "create" | "update" | "delete" };

/** Why a view can't be saved or renamed, in the words the dialog shows. */
export const VIEW_WORDS = {
  full: `You can keep up to ${MAX_VIEWS} views. Delete one to save another.`,
  empty: "Give the view a name.",
  long: `Use at most ${VIEW_NAME_MAX} characters.`,
  taken: (name: string) => `You already have a view called ${name}.`,
  gone: "That view is gone. It may have been deleted on another device.",
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
  return {
    id: v.id, name: v.name, client_ref: v.client_ref ?? undefined,
    spec: {
      filters: readSpec(v.filters), search: v.search ?? null, sort: SORT_IN[v.sort] ?? "newest", group: GROUP_IN[v.group] ?? "date",
      density: v.density === "compact" ? "compact" : v.density === "comfortable" ? "comfortable" : null,
    },
  };
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

function read(key: string): Kept {
  try {
    const saved = JSON.parse(window.localStorage.getItem(key) ?? "null") as Partial<Kept> | MyView[] | null;
    const list = Array.isArray(saved) ? saved : saved?.views ?? [];
    const views = list.filter((v): v is MyView => !!v && typeof v.id === "string" && typeof v.name === "string" && !!v.spec)
      .map((v) => ({ ...v, spec: { ...v.spec, filters: readSpec(v.spec.filters), search: v.spec.search ?? null } }));
    return { views, version: Array.isArray(saved) ? null : saved?.version ?? null, reorder: !Array.isArray(saved) && !!saved?.reorder };
  } catch {
    return { views: [], version: null, reorder: false };
  }
}

const isGone = (e: unknown) => /view_not_found|not found/i.test(errorMessage(e));
const isFull = (e: unknown) => /views_limit|up to 30/i.test(errorMessage(e));

/**
 * The views kept on this computer, one copy per account (`account`: the server and the email), in step with the
 * server's. `onProblem` hears what the sync couldn't do (a view the server refused: 30 already).
 */
export function syncedViewsStore(): ViewsStore & { use(account: string | null): void; sync(): Promise<void>; onProblem(f: (text: string) => void): () => void } {
  let key = KEY;
  let kept: Kept = read(key);
  let shown: MyView[] = kept.views.filter((v) => v.pending !== "delete");
  const listeners = new Set<() => void>();
  const problems = new Set<(text: string) => void>();
  let unlisten: (() => void) | null = null;
  let running: Promise<void> | null = null;
  let again = false;
  const save = (next: Kept) => {
    kept = next;
    shown = next.views.filter((v) => v.pending !== "delete");
    try {
      window.localStorage.setItem(key, JSON.stringify(next));
    } catch {
      // Storage can be unavailable: the views last until the window closes.
    }
    listeners.forEach((l) => l());
  };
  const find = (id: string) => {
    const at = kept.views.findIndex((v) => v.id === id && v.pending !== "delete");
    if (at < 0) throw new ViewError(VIEW_WORDS.gone);
    return at;
  };
  const patch = (id: string, change: (v: MyView) => MyView) => save({ ...kept, views: kept.views.map((v) => (v.id === id ? change(v) : v)) });
  const drop = (id: string) => save({ ...kept, views: kept.views.filter((v) => v.id !== id) });

  /** Sends what waits, in order, then reads the server's copy when its version is not ours. Stops quietly when offline. */
  const work = async () => {
    // Anything sent: the server's copy is read after (another device may have changed the views meanwhile, and the
    // version an answer carries already counts that change).
    let sent = false;
    for (const view of [...kept.views]) {
      const now = kept.views.find((v) => v.id === view.id);
      if (!now?.pending) continue;
      try {
        if (now.pending === "create") {
          const answer = await api.createView(toServer(now));
          const made = fromServer(answer.data);
          sent = true;
          // Deleted here while it was on its way: it goes on the server too.
          if (!kept.views.some((v) => v.id === now.id)) {
            await api.deleteView(made.id).catch(() => undefined);
            continue;
          }
          // It keeps its place and what was changed since it was sent; the server's id from now on.
          save({
            ...kept, version: answer.views_version,
            views: kept.views.map((v) => (v.id === now.id ? { ...v, id: made.id, pending: v.pending === "create" && v.spec === now.spec && v.name === now.name ? undefined : v.pending === "delete" ? "delete" : v.pending === "create" ? "update" : v.pending } : v)),
          });
        } else if (now.pending === "update") {
          const answer = await api.updateView(now.id, toServer({ ...now, client_ref: undefined }));
          sent = true;
          // Done, unless it was changed again or deleted while on its way.
          save({ ...kept, version: answer.views_version, views: kept.views.map((v) => (v.id === now.id && v.pending === "update" && v.spec === now.spec && v.name === now.name ? { ...v, pending: undefined } : v)) });
        } else {
          const answer = await api.deleteView(now.id);
          sent = true;
          save({ ...kept, version: answer.views_version, views: kept.views.filter((v) => v.id !== now.id) });
        }
      } catch (e) {
        if (now.pending === "create" && isFull(e)) {
          drop(now.id);
          problems.forEach((p) => p(`${now.name} wasn't saved: ${VIEW_WORDS.full}`));
          continue;
        }
        if (now.pending !== "create" && isGone(e)) {
          drop(now.id);
          continue;
        }
        // Offline, or a server without saved views: kept here, sent next time.
        return;
      }
    }
    // What changed while the loop ran goes on the next round.
    if (kept.views.some((v) => v.pending)) {
      again = true;
      return;
    }
    if (kept.reorder) {
      try {
        const answer = await api.orderViews(kept.views.map((v) => v.id));
        sent = true;
        save({ ...kept, reorder: false, version: answer.views_version });
      } catch (e) {
        if (!isGone(e) && !/validation/i.test(errorMessage(e))) return;
        save({ ...kept, reorder: false });
      }
    }
    try {
      const version = sent ? null : await api.viewsVersion();
      if (!sent && version !== null && version === kept.version) return;
      const answer = await api.views();
      if (!answer) return;
      // Nothing waits: the server's copy is the copy, in its order.
      if (!kept.views.some((v) => v.pending) && !kept.reorder) save({ views: answer.data.map(fromServer), version: answer.views_version, reorder: false });
    } catch {
      // Offline: the copy stays.
    }
  };
  /** One sync at a time; a change made while one runs is sent by one more round. */
  const sync = (): Promise<void> => {
    if (running) {
      again = true;
      return running;
    }
    again = false;
    running = work().finally(() => {
      running = null;
      if (again && kept.views.some((v) => v.pending)) void sync();
      again = false;
    });
    return running;
  };

  return {
    use(account) {
      const next = account ? `${KEY}:${account}` : KEY;
      if (next === key) return;
      key = next;
      save(read(key));
      if (listeners.size) void sync();
    },
    sync,
    onProblem(f) {
      problems.add(f);
      return () => problems.delete(f);
    },
    list: () => shown,
    async create(name, spec) {
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
      find(id);
      const problem = nameProblem(name, kept.views, id);
      if (problem) throw new ViewError(problem);
      patch(id, (v) => ({ ...v, name: spaces(name), pending: v.pending ?? "update" }));
      void sync();
    },
    async update(id, spec) {
      find(id);
      patch(id, (v) => ({ ...v, spec: cleanViewSpec(spec), pending: v.pending ?? "update" }));
      void sync();
    },
    async remove(id) {
      const at = find(id);
      // Never sent: it just goes. Else it waits to be deleted on the server (and shows nowhere meanwhile).
      if (kept.views[at].pending === "create") drop(id);
      else patch(id, (v) => ({ ...v, pending: "delete" }));
      void sync();
    },
    async move(id, index) {
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
        save(read(key));
        void sync();
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
export const viewsStore = syncedViewsStore();

/** The person's views, kept up to date. */
export function useMyViews(store: ViewsStore = viewsStore): readonly MyView[] {
  return useSyncExternalStore(store.subscribe, store.list, store.list);
}

/** Uses the signed-in account's own copy (the server and the email; none: this computer's). */
export function useViewsAccount(account: string | null) {
  useEffect(() => {
    viewsStore.use(account);
  }, [account]);
}
