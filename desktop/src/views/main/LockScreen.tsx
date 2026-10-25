// The app lock screen (plan B16): Windows Hello or Touch ID, or the PIN. Recording and uploads keep running behind it.
import { useEffect, useId, useRef, useState } from "react";
import { api, errorMessage, type LockStatus } from "../../lib/ipc";

export const SYSTEM_LABEL = { windows_hello: "Use Windows Hello", touch_id: "Use Touch ID" } as const;

/** Called when a user action happened; tells the core at most every 30 seconds. */
export function activityReporter(send: () => void, everyMs = 30_000, now: () => number = Date.now): () => void {
  let last = -Infinity;
  return () => {
    const t = now();
    if (t - last >= everyMs) {
      last = t;
      send();
    }
  };
}

export function LockScreen({ status, onUnlocked }: { status: LockStatus; onUnlocked: (s: LockStatus) => void }) {
  const [pin, setPin] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const pinId = useId();
  const asked = useRef(false);

  const done = (next: LockStatus) => {
    if (!next.locked) onUnlocked(next);
  };
  const useSystem = async () => {
    setBusy(true);
    setError(null);
    try {
      const next = await api.unlockWithSystem();
      if (next.locked) setError("Not verified. Try again or use your PIN.");
      done(next);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  const usePin = async () => {
    if (!pin) return;
    setBusy(true);
    setError(null);
    try {
      done(await api.unlockWithPin(pin));
    } catch (e) {
      setError(errorMessage(e));
      setPin("");
    } finally {
      setBusy(false);
    }
  };

  // Offer the system check straight away, once, as other apps do.
  useEffect(() => {
    if (status.osMethod && !asked.current) {
      asked.current = true;
      void useSystem();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status.osMethod]);

  return (
    <main className="gate lock-screen">
      <div className="card lock-card" role="dialog" aria-modal="true" aria-labelledby={`${pinId}-title`}>
        <h1 id={`${pinId}-title`} className="page-title">EchoLedger is locked</h1>
        <p className="small muted">Recording and uploads keep running while the app is locked.</p>
        {status.osMethod && (
          <button type="button" className="btn primary" disabled={busy} onClick={() => void useSystem()}>{SYSTEM_LABEL[status.osMethod]}</button>
        )}
        {status.hasPin && (
          <form className="row" onSubmit={(e) => { e.preventDefault(); void usePin(); }}>
            <label htmlFor={pinId} className="sr-only">PIN</label>
            <input id={pinId} className="input" type="password" inputMode="numeric" autoComplete="off" placeholder="PIN" maxLength={12}
              value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ""))} autoFocus={!status.osMethod} />
            <button type="submit" className="btn" disabled={busy || pin.length < 4}>Unlock</button>
          </form>
        )}
        {error && <p className="small error-text" role="alert">{error}</p>}
      </div>
    </main>
  );
}
