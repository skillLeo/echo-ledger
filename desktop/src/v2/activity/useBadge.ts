// The rail's Activity number from the server (UX-08, BE-04: `GET /api/badge`), the one number the phone and the web
// console show too. Read when the window opens or comes back, whenever the core says Activity changed (its poll, an
// item marked read, Retry all), and when the calls waiting change (a decided call the server counts). A failed read
// (offline, locked, the server busy) keeps the last number; null only before the first read, from a server older than
// the badge, and from a new sign-in until its own number is read, when the window counts its own (lib/badge.ts
// `railBadge`): one account's number never shows for another.
import { useEffect, useState } from "react";
import { api, listenTo, type ActivityBadge } from "../../lib/ipc";
import { useWindowShown } from "../../lib/visibility";

const valid = (b: unknown): b is ActivityBadge =>
  !!b && typeof b === "object" && typeof (b as ActivityBadge).count === "number" && Number.isFinite((b as ActivityBadge).count);

/** `signIn`: the core's id for this sign-in (AppStatus.signIn); a new one starts again from nothing. */
export function useActivityBadge(signedIn: boolean, signIn: string | null = null): ActivityBadge | null {
  const [badge, setBadge] = useState<ActivityBadge | null>(null);
  const shown = useWindowShown();
  useEffect(() => {
    setBadge(null);
  }, [signIn]);
  useEffect(() => {
    if (!signedIn) {
      setBadge(null);
      return;
    }
    if (!shown) return;
    let alive = true;
    const read = () => api.badge().then((b) => alive && setBadge(valid(b) ? b : null), () => undefined);
    void read();
    const uns = [listenTo("activity:updated", () => void read()), listenTo("pending:changed", () => void read())];
    return () => {
      alive = false;
      for (const un of uns) un.then((f) => f()).catch(() => undefined);
    };
  }, [signedIn, shown, signIn]);
  return badge;
}
