// The rail's Activity number from the server (UX-08, BE-04: `GET /api/badge`), the one number the phone and the web
// console show too. Read when the window opens or comes back, and whenever the core says Activity changed (its poll, an
// item marked read, Retry all). A failed read (offline, locked, the server busy) keeps the last number; null only before
// the first read and from a server older than the badge, when the window counts its own (lib/badge.ts `railBadge`).
import { useEffect, useState } from "react";
import { api, listenTo, type ActivityBadge } from "../../lib/ipc";
import { useWindowShown } from "../../lib/visibility";

const valid = (b: unknown): b is ActivityBadge =>
  !!b && typeof b === "object" && typeof (b as ActivityBadge).count === "number" && Number.isFinite((b as ActivityBadge).count);

export function useActivityBadge(signedIn: boolean): ActivityBadge | null {
  const [badge, setBadge] = useState<ActivityBadge | null>(null);
  const shown = useWindowShown();
  useEffect(() => {
    if (!signedIn) {
      setBadge(null);
      return;
    }
    if (!shown) return;
    let alive = true;
    const read = () => api.badge().then((b) => alive && setBadge(valid(b) ? b : null), () => undefined);
    void read();
    const un = listenTo("activity:updated", () => void read());
    return () => {
      alive = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, [signedIn, shown]);
  return badge;
}
