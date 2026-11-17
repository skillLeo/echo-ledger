// How long a deleted recording can be restored: the server's setting (contract 2.0.0-b), read by the core
// (`trash_keep_hours`). Until the core answers (an older core has no such command), the sentences say 48 hours, the
// server's own default. Only the number comes from the server; every sentence keeps its words.
import { api } from "./ipc";
import { useEffect, useState } from "react";

/** The server's default (CaptureSession::BIN_HOURS) and the fallback while the core cannot say. */
export const TRASH_KEEP_HOURS_FALLBACK = 48;

let known: number | null = null;

const valid = (h: unknown): h is number => typeof h === "number" && Number.isInteger(h) && h > 0;

/** The hours from the core, or null when it cannot say (no command yet, or a bad answer). Read once per run. */
export async function trashKeepHours(): Promise<number | null> {
  if (known !== null) return known;
  try {
    const h: unknown = await api.trashKeepHours();
    if (valid(h)) known = h;
  } catch {
    // An older core: the fallback stands.
  }
  return known;
}

/** The hours in a view: null until (and unless) the core says. */
export function useTrashKeepHours(): number | null {
  const [hours, setHours] = useState<number | null>(known);
  useEffect(() => {
    let live = true;
    void trashKeepHours().then((h) => live && h !== null && setHours(h));
    return () => {
      live = false;
    };
  }, []);
  return hours;
}

/** "48 hours", "1 hour". */
export function hoursWords(hours: number | null | undefined): string {
  const h = hours ?? TRASH_KEEP_HOURS_FALLBACK;
  return h === 1 ? "1 hour" : `${h} hours`;
}

/** For tests: forget what the core said. */
export function resetTrashKeepHoursForTests(): void {
  known = null;
}
