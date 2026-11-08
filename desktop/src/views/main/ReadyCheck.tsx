// The Ready check (Phase 1C, A1–A2): whether this computer will record the next call, both sides, by itself. The core
// runs it at start, every few seconds and after every change of settings (readiness.rs) and says each new answer as
// `ready:changed`; this card shows it with a one-click fix per problem, and the chip in the sidebar says it in two words.
import { useCallback, useEffect, useState } from "react";
import { api, errorMessage, listenTo, type AppStatus, type ReadyCheck, type ReadyReport } from "../../lib/ipc";
import { CheckIcon } from "../../components/ControlIcons";
import { CloseIcon } from "../../components/Icons";

export const READY = "Ready to record calls";

/** The headline of a report: "✓ Ready to record calls" or how many things need fixing. */
export function readyHeadline(report: Pick<ReadyReport, "ready" | "checks">): string {
  if (report.ready) return `✓ ${READY}`;
  const problems = report.checks.filter((c) => !c.ok).length;
  return problems === 1 ? "1 thing to fix before calls are recorded" : `${problems} things to fix before calls are recorded`;
}

/** The chip's words (A2): "Calls: Ready" or "Not ready — Fix"; none before the first check or while signed out. */
export function chipText(callsReady: boolean | null | undefined): string | null {
  if (callsReady === true) return "Calls: Ready";
  if (callsReady === false) return "Not ready — Fix";
  return null;
}

/** The last report, kept fresh by `ready:changed`; runs a check when none was made yet. */
export function useReadyReport(): [ReadyReport | null, (r: ReadyReport) => void] {
  const [report, setReport] = useState<ReadyReport | null>(null);
  useEffect(() => {
    let alive = true;
    api.readyLast()
      .then((last) => (last ? last : api.readyCheck()))
      .then((r) => alive && r && setReport(r))
      .catch(() => undefined);
    const un = listenTo("ready:changed", (r) => alive && setReport(r));
    return () => {
      alive = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  return [report, setReport];
}

function ReadyRow({ check, busy, onFix }: { check: ReadyCheck; busy: boolean; onFix: (c: ReadyCheck) => void }) {
  return (
    <li className={`check-row ${check.ok ? "ok" : "failed"}`} data-testid={`ready-${check.id}`}>
      <span className="check-mark" role="img" aria-label={check.ok ? "OK" : "Problem"}>{check.ok ? <CheckIcon size={15} /> : <CloseIcon size={15} />}</span>
      <div className="check-main">
        <div className="check-title">{check.label}</div>
        {check.detail && <div className="small muted">{check.detail}</div>}
      </div>
      {check.fix && <button type="button" className={`btn compact ${check.ok ? "" : "primary"}`} disabled={busy} onClick={() => onFix(check)}>{check.fix.label}</button>}
    </li>
  );
}

/** The Ready check card: on the Record page and after signing in. Problems first, each with its fix. */
export function ReadyCheckCard({ notify }: { notify?: (text: string, error?: boolean) => void }) {
  const [report, setReport] = useReadyReport();
  const [busy, setBusy] = useState(false);
  const run = useCallback(async (job: () => Promise<ReadyReport>) => {
    setBusy(true);
    try {
      setReport(await job());
    } catch (e) {
      notify?.(errorMessage(e), true);
    } finally {
      setBusy(false);
    }
  }, [notify, setReport]);
  const checks = report ? [...report.checks].sort((a, b) => Number(a.ok) - Number(b.ok)) : [];
  return (
    <section className={`card ready-card ${report?.ready ? "ready" : report ? "not-ready" : ""}`} aria-labelledby="ready-title" data-testid="ready-card">
      <div className="row">
        <b id="ready-title" aria-live="polite">{report ? readyHeadline(report) : "Checking whether calls will be recorded…"}</b>
        <span className="spacer" />
        <button type="button" className="btn compact ghost" disabled={busy} onClick={() => void run(api.readyCheck)}>{busy ? "Checking…" : "Check again"}</button>
      </div>
      {report && !report.ready && <p className="small muted">Each problem has its fix. The check runs again by itself.</p>}
      {report && (
        <ul className="check-list">
          {checks.map((c) => <ReadyRow key={c.id} check={c} busy={busy} onFix={(check) => check.fix && void run(() => api.readyFix(check.fix!.action))} />)}
        </ul>
      )}
    </section>
  );
}

/** The sidebar chip (A2): "Calls: Ready" or "Not ready — Fix", which opens the Ready check. */
export function ReadyChip({ status, onOpen }: { status: Pick<AppStatus, "callsReady" | "readyProblems">; onOpen: () => void }) {
  const text = chipText(status.callsReady);
  if (!text) return null;
  const ready = status.callsReady === true;
  return (
    <button type="button" className={`ready-chip ${ready ? "ready" : "not-ready"}`} data-testid="ready-chip" onClick={onOpen}
      title={ready ? READY : `${status.readyProblems ?? 1} to fix: open the Ready check`}>
      <span className={`status-dot ${ready ? "ok" : "failed"}`} aria-hidden="true" />
      <span className="ready-chip-text">{text}</span>
    </button>
  );
}
