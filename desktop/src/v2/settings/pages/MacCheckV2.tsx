// Help › Mac check in the new look, with today's data and rules (MacCheck.tsx): the core runs the checks one at a time
// and says each step; every result has a mark and its state in words, a plain sentence and, where System Settings has
// a pane for it, the button there (or Quit & reopen). Never during a recording or dictation. Nothing leaves the
// computer unless Copy report or Send to my admin is pressed.
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, listenTo, type AppStatus, type CheckItem, type CheckProgress, type CheckReport } from "../../../lib/ipc";
import { formatWhen } from "../../../lib/format";
import { sendBlocked } from "../../../views/main/MacCheck";
import { Button, Card, HealthCheckRow, type HealthState } from "../../components";
import { t } from "../../../i18n";

/** Each state in words, in the window's language (its keys stay the states). */
const STATE_WORD: Record<CheckItem["state"], string> = {
  get ok() { return t("OK"); },
  get limited() { return t("Limited"); },
  get off() { return t("Off"); },
  get failed() { return t("Failed"); },
};
const HEALTH: Record<CheckItem["state"], HealthState> = { ok: "ok", limited: "limited", off: "problem", failed: "problem" };

function CheckRowV2({ item }: { item: CheckItem }) {
  const { fix } = item;
  const [refused, setRefused] = useState<string | null>(null);
  // Quit & reopen (Screen Recording allowed while EchoLedger ran), refused in the middle of a recording; else the pane.
  const act = () => {
    if (!fix) return;
    if (!fix.reopen) {
      void api.openPrivacySettings(fix.settings).catch(() => undefined);
      return;
    }
    setRefused(null);
    api.quitAndReopen().catch((e) => setRefused(errorMessage(e)));
  };
  return (
    <HealthCheckRow state={HEALTH[item.state]} stateLabel={STATE_WORD[item.state]}
      title={<span data-testid={`check-${item.key}`}>{item.title}{item.state !== "ok" && <span className="el-secondary"> · {STATE_WORD[item.state]}</span>}</span>}
      detail={<>{item.detail}{refused && <span className="el-row__error" role="alert"> {refused}</span>}</>}
      action={fix ? <Button size="sm" variant={fix.reopen ? "primary" : "secondary"} onClick={act}>{fix.label}</Button> : undefined} />
  );
}

export function MacCheckPageV2({ status }: { status: AppStatus }) {
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

  const attempt = async (fn: () => Promise<void>) => {
    setError(null);
    try {
      await fn();
    } catch (e) {
      if (mounted.current) setError(errorMessage(e));
    }
  };
  const run = async () => {
    setRunning(true);
    setCopied(false);
    setReport(null);
    setProgress(null);
    await attempt(async () => {
      const done = await api.macCheckRun();
      if (mounted.current) setReport(done);
    });
    if (mounted.current) {
      setRunning(false);
      setProgress(null);
    }
  };
  const copy = () => attempt(async () => {
    await api.macCheckCopy();
    if (mounted.current) setCopied(true);
  });
  const send = async () => {
    setSending(true);
    await attempt(async () => {
      const sentAt = await api.macCheckSend();
      if (mounted.current) setReport((r) => (r ? { ...r, sentAt } : r));
    });
    if (mounted.current) setSending(false);
  };

  // Never during a recording: the check says so and offers nothing.
  if (!running && (status.recording || status.dictationActive)) {
    return (
      <Card>
        <p className="el-mac-check__line" data-testid="mac-check-recording">{t("Finish the current recording to run the Mac check.")}</p>
      </Card>
    );
  }

  const blocked = sendBlocked(status);
  const items = report?.items ?? progress?.done ?? [];
  return (
    <div className="el-settings-v2" data-testid="mac-check">
      <p className="el-secondary">
        {t("The Mac check looks at macOS, the permissions, the microphone, the other side of a call, your meeting apps, the captions and screen moments, one at a time. It takes about 15 seconds: say something when it asks, and it plays one soft tone.")}
      </p>
      <div className="el-page__actions">
        <Button variant="primary" disabled={running} loading={running} loadingLabel={t("Checking…")} onClick={() => void run()}>
          {report ? t("Run Mac check again") : t("Run Mac check")}
        </Button>
      </div>
      {running && progress && <p role="status" data-testid="mac-check-progress">{t(":step of :total: :line", { step: progress.index + 1, total: progress.total, line: progress.line })}</p>}
      {report?.stopped && <p className="el-mac-check__stopped" role="status">{report.stopped}</p>}
      {items.length > 0 && <Card><ul className="el-health-list" aria-label={t("Mac check results")}>{items.map((item) => <CheckRowV2 item={item} key={item.key} />)}</ul></Card>}
      {report && (
        <section className="el-group">
          <div className="el-page__actions">
            <Button onClick={() => void copy()}>{copied ? t("Copied") : t("Copy report")}</Button>
            <Button disabled={!!blocked || sending} loading={sending} loadingLabel={t("Sending…")} onClick={() => void send()}>{t("Send to my admin")}</Button>
          </div>
          <div className="el-group__note el-caption el-secondary">
            {blocked && <p data-testid="mac-check-send-blocked">{blocked}</p>}
            {report.sentAt && <p role="status" data-testid="mac-check-sent">{t("Sent to your admin :when.", { when: formatWhen(report.sentAt) })}</p>}
            <p>{t("The report has no names, no recordings and no account. It is sent only when you press the button.")}</p>
          </div>
        </section>
      )}
      {error && <p className="el-field__error" role="alert">{error}</p>}
    </div>
  );
}
