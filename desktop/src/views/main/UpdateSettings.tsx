// Updates (since v1.5): Settings → About's "Check for updates" and the prompt once an update is downloaded. The core
// checks the signed-in server soon after EchoLedger starts and every 6 hours (v1.6), verifies the package's signature,
// and installs it the next time EchoLedger starts, or at once on "Restart" (never while recording).
import { useEffect, useState } from "react";
import { api, errorMessage, listenTo, type AppStatus, type UpdateStatus } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { DownloadIcon } from "../../components/Icons";
import { NO_TARGET, type Notify } from "../../lib/notice";

/** Where updates stand, kept current by `update:status`; null until the core answered. */
export function useUpdateStatus(): [UpdateStatus | null, (s: UpdateStatus) => void] {
  const [status, setStatus] = useState<UpdateStatus | null>(null);
  useEffect(() => {
    let alive = true;
    api.updateStatus().then((s) => alive && setStatus(s), () => undefined);
    const un = listenTo("update:status", (s) => alive && setStatus(s));
    return () => {
      alive = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  return [status, setStatus];
}

/** What the About card says about updates. */
export function updateLine(status: UpdateStatus): string {
  switch (status.phase) {
    case "checking":
      return "Checking for updates…";
    case "downloading":
      return `Downloading EchoLedger ${status.version ?? ""}${status.progress !== null ? ` · ${status.progress}%` : "…"}`;
    case "ready":
      return `Update ready — EchoLedger ${status.version}. Restart to install it, or it installs the next time EchoLedger starts.`;
    case "up_to_date":
    case "failed":
    case "unavailable":
      return status.message ?? "";
    default:
      return "EchoLedger looks for updates when it starts and every 6 hours.";
  }
}

const RECORDING_BLOCKS = "Stop the recording first: EchoLedger restarts to install the update.";

/** Settings → About: the version, Check for updates, and Restart once an update is ready. */
export function UpdatesPanel({ appStatus }: { appStatus: AppStatus }) {
  const [status, setStatus] = useUpdateStatus();
  const [error, setError] = useState<string | null>(null);
  if (!status) return null;
  const working = status.phase === "checking" || status.phase === "downloading";
  const check = async () => {
    setError(null);
    try {
      setStatus(await api.checkForUpdates());
    } catch (e) {
      setError(errorMessage(e));
    }
  };
  const install = async () => {
    setError(null);
    try {
      await api.installUpdate();
    } catch (e) {
      setError(errorMessage(e));
    }
  };
  return (
    <div className="updates-panel" data-testid="updates">
      <div className="row organise-field">
        <div>
          <div className={`small ${status.phase === "failed" ? "error-text" : ""}`} role="status">{updateLine(status)}</div>
          {status.checkedAt && status.phase !== "checking" && <div className="small muted">Last checked {formatWhen(status.checkedAt)}</div>}
        </div>
        <span className="spacer" />
        {status.phase === "ready" ? (
          <button type="button" className="btn primary" disabled={!!appStatus.recording} title={appStatus.recording ? RECORDING_BLOCKS : undefined} onClick={() => void install()}>
            Restart
          </button>
        ) : (
          <button type="button" className="btn ghost" disabled={working} onClick={() => void check()}><DownloadIcon size={15} />Check for updates</button>
        )}
      </div>
      {status.phase === "ready" && status.notes && <p className="small muted update-notes">{status.notes}</p>}
      {status.phase === "ready" && appStatus.recording && <p className="small muted">{RECORDING_BLOCKS}</p>}
      {error && <div className="notice error small" role="alert">{error}</div>}
    </div>
  );
}

/** "Update ready — Restart" across the top of the window once an update is downloaded, or Later (the next start). */
export function UpdateBanner({ appStatus, notify }: { appStatus: AppStatus; notify: Notify }) {
  const [status] = useUpdateStatus();
  const [later, setLater] = useState<string | null>(null);
  if (status?.phase !== "ready" || !status.version || later === status.version) return null;
  const install = async () => {
    try {
      await api.installUpdate();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  return (
    <div className="banner update-banner" data-testid="update-banner" role="status">
      <DownloadIcon size={16} />
      <span><b>Update ready</b> — EchoLedger {status.version}</span>
      <button type="button" className="btn compact primary" disabled={!!appStatus.recording} title={appStatus.recording ? RECORDING_BLOCKS : undefined} onClick={() => void install()}>Restart</button>
      <button type="button" className="btn compact ghost" onClick={() => { setLater(status.version); notify("The update installs the next time EchoLedger starts.", false, NO_TARGET); }}>Later</button>
    </div>
  );
}
