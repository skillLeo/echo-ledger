// Settings → Help & diagnostics → Run Mac check (since v1.5, Phase 1B; shown on a Mac only). The core runs the checks one at
// a time (`mac_check.rs`) and says each step; every result is ✓ or ✗ with a plain sentence and, where System Settings has
// a pane for it, a button there. Nothing leaves the computer unless Copy report or Send to my admin is pressed.
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, listenTo, type AppStatus, type CheckItem, type CheckProgress, type CheckReport } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { CheckIcon } from "../../components/ControlIcons";
import { CloseIcon } from "../../components/Icons";

const STATE_WORD: Record<CheckItem["state"], string> = { ok: "OK", limited: "Limited", off: "Off", failed: "Failed" };

/** The reason Send is greyed out, or none. */
export function sendBlocked(status: Pick<AppStatus, "paired">): string | null {
  return status.paired ? null : "Sign in to send this to your admin.";
}

function CheckRow({ item }: { item: CheckItem }) {
  return (
    <li className={`check-row ${item.state}`} data-testid={`check-${item.key}`}>
      <span className="check-mark" role="img" aria-label={STATE_WORD[item.state]}>{item.state === "ok" ? <CheckIcon size={15} /> : <CloseIcon size={15} />}</span>
      <div className="check-main">
        <div className="check-title">{item.title}{item.state !== "ok" && <span className="small muted"> · {STATE_WORD[item.state]}</span>}</div>
        <div className="small muted">{item.detail}</div>
      </div>
      {item.fix && <button type="button" className="btn compact" onClick={() => void api.openPrivacySettings(item.fix?.settings as never).catch(() => undefined)}>{item.fix.label}</button>}
    </li>
  );
}

export function MacCheckCard({ status }: { status: AppStatus }) {
  const [report, setReport] = useState<CheckReport | null>(null);
  const [progress, setProgress] = useState<CheckProgress | null>(null);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [sending, setSending] = useState(false);
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    api.macCheckLast().then((last) => mounted.current && last && setReport(last)).catch(() => undefined);
    const un = listenTo("mac_check:progress", (p) => mounted.current && setProgress(p));
    return () => {
      mounted.current = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  const run = async () => {
    setRunning(true);
    setError(null);
    setCopied(false);
    setReport(null);
    setProgress(null);
    try {
      const done = await api.macCheckRun();
      if (mounted.current) setReport(done);
    } catch (e) {
      if (mounted.current) setError(errorMessage(e));
    } finally {
      if (mounted.current) {
        setRunning(false);
        setProgress(null);
      }
    }
  };
  const copy = async () => {
    setError(null);
    try {
      await api.macCheckCopy();
      if (mounted.current) setCopied(true);
    } catch (e) {
      if (mounted.current) setError(errorMessage(e));
    }
  };
  const send = async () => {
    setError(null);
    setSending(true);
    try {
      const sentAt = await api.macCheckSend();
      if (mounted.current) setReport((r) => (r ? { ...r, sentAt } : r));
    } catch (e) {
      if (mounted.current) setError(errorMessage(e));
    } finally {
      if (mounted.current) setSending(false);
    }
  };

  // Never during a recording: the check says so and offers nothing.
  if (!running && (status.recording || status.dictationActive)) {
    return (
      <div className="card mac-check" data-testid="mac-check">
        <b>Mac check</b>
        <p className="small" data-testid="mac-check-recording">Finish the current recording to run the Mac check.</p>
      </div>
    );
  }

  const blocked = sendBlocked(status);
  const items = report?.items ?? progress?.done ?? [];
  return (
    <div className="card mac-check" data-testid="mac-check">
      <b>Mac check</b>
      <p className="small muted">Checks macOS, the permissions, the microphone, the other side of a call, your meeting apps, the captions and screen moments, one at a time. It takes about 15 seconds, says something back, and plays one soft tone.</p>
      <div className="card-actions"><button type="button" className="btn primary" disabled={running} onClick={() => void run()}>{running ? "Checking…" : report ? "Run Mac check again" : "Run Mac check"}</button></div>
      {running && progress && <p className="small" role="status" data-testid="mac-check-progress">{progress.index + 1} of {progress.total}: {progress.line}</p>}
      {report?.stopped && <p className="small warning-text" role="status">{report.stopped}</p>}
      {items.length > 0 && <ul className="check-list">{items.map((item) => <CheckRow item={item} key={item.key} />)}</ul>}
      {report && (
        <>
          <div className="row organise-field">
            <button type="button" className="btn ghost" onClick={() => void copy()}>{copied ? "Copied" : "Copy report"}</button>
            <button type="button" className="btn ghost" disabled={!!blocked || sending} onClick={() => void send()}>{sending ? "Sending…" : "Send to my admin"}</button>
          </div>
          {blocked && <p className="small muted" data-testid="mac-check-send-blocked">{blocked}</p>}
          {report.sentAt && <p className="small" role="status" data-testid="mac-check-sent">Sent to your admin {formatWhen(report.sentAt)}.</p>}
          <p className="small muted">The report has no names, no recordings and no account. It is sent only when you press the button.</p>
        </>
      )}
      {error && <p className="small error-text">{error}</p>}
    </div>
  );
}
