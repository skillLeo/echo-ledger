// Recordings recovered after a restart (v1.3) wait as "Recovered recording"; once kept, the transcript's title replaces
// that name. The core remembers which server sessions they became (recovered.rs), and the Library and Activity mark them
// with a "Recovered" chip so the person can still find them.
import { useEffect, useState } from "react";
import { api, listenTo } from "./ipc";

/** The chip's word. */
export const RECOVERED_CHIP = "Recovered";

/** The sessions recovered after a restart, read once and again whenever another one is remembered. */
export function useRecovered(): ReadonlySet<string> {
  const [ids, setIds] = useState<ReadonlySet<string>>(() => new Set());
  useEffect(() => {
    let alive = true;
    const read = () => void api.recoveredSessions().then((list) => alive && setIds(new Set(Array.isArray(list) ? list : []))).catch(() => undefined);
    read();
    const un = listenTo("recovered:changed", read);
    return () => {
      alive = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  return ids;
}
