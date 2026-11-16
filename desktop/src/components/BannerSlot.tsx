// One banner at a time above the main window's pages (docs/DESIGN_SYSTEM.md "Notices"): every banner that wants to show
// asks for its turn, and only the most important of them shows; the next one shows once it is dismissed or its state
// is gone. Outside a slot (a page's own banners, tests of one banner) every banner shows as before.
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";

/** The main window's banners, most important first. */
export const BANNER_ORDER = [
  // The person's own window is in a whole-screen share right now.
  "main-in-share",
  // This version is refused or must update: nothing uploads until it does.
  "app-update",
  // The call that just ended lost one side.
  "one-sided",
  // macOS forgot a permission: calls are not fully recorded.
  "permission-loss",
  "maintenance",
  "budget",
  "update-ready",
  "announcement",
] as const;

export type BannerName = (typeof BANNER_ORDER)[number];

interface Slot {
  want: (name: BannerName, wants: boolean) => void;
  top: BannerName | null;
}

const SlotContext = createContext<Slot | null>(null);

/** Which banner shows now (pure): the first of the order that wants to. */
export function bannerOnTop(wanting: ReadonlySet<BannerName>): BannerName | null {
  return BANNER_ORDER.find((name) => wanting.has(name)) ?? null;
}

export function BannerSlot({ children }: { children: ReactNode }) {
  const [wanting, setWanting] = useState<ReadonlySet<BannerName>>(() => new Set());
  const want = useCallback((name: BannerName, wants: boolean) => setWanting((current) => {
    if (current.has(name) === wants) return current;
    const next = new Set(current);
    if (wants) next.add(name);
    else next.delete(name);
    return next;
  }), []);
  const top = bannerOnTop(wanting);
  const slot = useMemo(() => ({ want, top }), [want, top]);
  return <SlotContext.Provider value={slot}>{children}</SlotContext.Provider>;
}

/** Whether banner `name`, which `wants` to show, is the one shown now (always, outside a slot or without a name). */
export function useBannerTurn(name: BannerName | undefined, wants: boolean): boolean {
  const slot = useContext(SlotContext);
  const want = slot?.want;
  useEffect(() => {
    if (!want || !name) return;
    want(name, wants);
    return () => want(name, false);
  }, [want, name, wants]);
  if (!slot || !name) return wants;
  return wants && slot.top === name;
}
