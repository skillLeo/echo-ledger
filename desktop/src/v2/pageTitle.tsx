// The page's part of the title row (PLAN_V2_1_POLISH UX-20): a page publishes its title, its count and its search; the
// shell's title row shows them. Shared by the shell (fix/D-rail-v21) and the Library (fix/D-library-v21): keep this file
// identical in both. With no shell providing the slot (classic, or a shell without the title row) a page shows its own
// header instead (`usePageTitle` returns false).
import { createContext, useContext, useEffect, useId, useMemo, useRef, useState } from "react";

/** What a page shows in the title row: "All recordings 219", and the search the row's field runs for this view. */
export type PageTitle = {
  title: string;
  count: number | null;
  /** ⌘F and the row's field search this view: the placeholder ("Search recordings"), the scope chip's words ("All
   * recordings"), and the view's own query, which the list filters itself by. */
  search?: { placeholder: string; scope: string; query: string; setQuery(q: string): void };
};

type Slot = { publish(id: string, title: PageTitle | null): void; withdraw(id: string): void };

/** Provided by the shell; a page publishes into it. */
export const PageTitleSlot = createContext<Slot | null>(null);

/** What a published title looks like, without its function (a new `setQuery` each render is the same title). */
const keyOf = (t: PageTitle | null) => (t ? JSON.stringify([t.title, t.count, t.search?.placeholder, t.search?.scope, t.search?.query]) : "");

/**
 * The shell's side: the title of the page mounted last (the latest mount wins), and the slot to provide. Changing a
 * title in place keeps its turn; the newest `setQuery` is always the one called.
 */
export function usePageTitleSlot(): [PageTitle | null, Slot] {
  const latest = useRef(new Map<string, PageTitle | null>());
  const [order, setOrder] = useState<{ id: string; key: string }[]>([]);
  const slot = useMemo<Slot>(() => ({
    publish(id, title) {
      latest.current.set(id, title);
      const key = keyOf(title);
      setOrder((now) => {
        const at = now.findIndex((e) => e.id === id);
        if (at >= 0 && now[at].key === key) return now;
        return at >= 0 ? now.map((e, i) => (i === at ? { id, key } : e)) : [...now, { id, key }];
      });
    },
    withdraw(id) {
      latest.current.delete(id);
      setOrder((now) => (now.some((e) => e.id === id) ? now.filter((e) => e.id !== id) : now));
    },
  }), []);
  const last = order.length ? order[order.length - 1] : undefined;
  const shown = last ? latest.current.get(last.id) ?? null : null;
  // The row keeps what it rendered, but a page's newer render may bring a newer setQuery with the same words: call that.
  const title = useMemo(() => {
    if (!shown?.search || !last) return shown;
    const id = last.id;
    return { ...shown, search: { ...shown.search, setQuery: (q: string) => latest.current.get(id)?.search?.setQuery(q) } };
    // `last.key` stands for everything shown but setQuery, which is looked up when called.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [last?.id, last?.key]);
  return [title, slot];
}

/** A page's side: publishes its title while mounted. True when a shell shows it (the page then has no header of its own). */
export function usePageTitle(t: PageTitle | null): boolean {
  const slot = useContext(PageTitleSlot);
  const id = useId();
  // Published after every render: an unchanged title (same words, count and query) changes nothing in the shell, and
  // the newest setQuery is kept.
  useEffect(() => {
    slot?.publish(id, t);
  });
  useEffect(() => () => slot?.withdraw(id), [slot, id]);
  return !!slot;
}
