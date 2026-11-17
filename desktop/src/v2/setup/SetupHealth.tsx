// Setup & health in the new look (ui_v2.setup_health): the permissions wizard, the Ready check and, on a Mac, the Mac
// check, from the data today's app already reads (permissions_status, ready_last / ready_check, mac_check_last).
import { useEffect, useState } from "react";
import { api, errorMessage, type CheckItem, type CheckReport, type PermissionKind, type PermissionsStatus } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { PERMISSION_STEP, accessOf, stepPlan, usePermissions, type StepAction } from "../../views/main/Permissions";
import { READY, readyHeadline, useReadyReport } from "../../views/main/ReadyCheck";
import {
  Button, Card, EmptyState, HealthCheckRow, Icons, Loading, SetupStep, Skeleton, Status, Steps, type HealthState,
} from "../components";

/** The permissions this computer asks for: all three on a Mac; the microphone on Windows. */
export function permissionKinds(platform: PermissionsStatus["platform"]): PermissionKind[] {
  return platform === "macos" ? ["microphone", "system_audio", "screen"] : ["microphone"];
}

/** The first step still to do (the wizard's current one); none when everything is allowed. */
export function currentStep(permissions: PermissionsStatus | null, kinds: PermissionKind[]): PermissionKind | null {
  return kinds.find((k) => accessOf(permissions, k) !== "allowed") ?? null;
}

const CHECK_STATE: Record<CheckItem["state"], HealthState> = { ok: "ok", limited: "limited", off: "problem", failed: "problem" };
const CHECK_WORDS: Record<CheckItem["state"], string> = { ok: "OK", limited: "Limited", off: "Off", failed: "Failed" };

export function SetupHealth({ platform, onBack }: { platform: PermissionsStatus["platform"]; onBack?: () => void }) {
  const [permissions, setPermissions] = usePermissions();
  const [report, setReport] = useReadyReport();
  const [opened, setOpened] = useState<PermissionKind | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [mac, setMac] = useState<CheckReport | null | undefined>(platform === "macos" ? undefined : null);

  useEffect(() => {
    if (platform !== "macos") return;
    api.macCheckLast().then((r) => setMac(r ?? null), () => setMac(null));
  }, [platform]);

  const kinds = permissionKinds(platform);
  const current = currentStep(permissions, kinds);
  const doing = async (key: string, fn: () => Promise<void>) => {
    setBusy(key);
    setProblem(null);
    try {
      await fn();
    } catch (e) {
      setProblem(errorMessage(e));
    } finally {
      setBusy(null);
    }
  };

  // The words and buttons come from the one rule every permission step follows (Permissions.tsx stepPlan).
  const stepActions = (kind: PermissionKind) => {
    const step = stepPlan(permissions, kind);
    const button = (action: StepAction) => {
      const variant = step.main === action ? "primary" : action === "settings" ? "quiet" : "secondary";
      switch (action) {
        case "reopen":
          return <Button key={action} variant={variant} loading={busy === "reopen"} loadingLabel="Reopening…" onClick={() => void doing("reopen", () => api.quitAndReopen())}>Quit &amp; reopen</Button>;
        case "allow":
          return (
            <Button key={action} variant={variant} loading={busy === kind} loadingLabel="Asking…"
              onClick={() => void doing(kind, async () => setPermissions(await api.permissionRequest(kind)))}>Allow</Button>
          );
        case "settings":
          return <Button key={action} variant={variant} onClick={() => void api.openPrivacySettings(kind).catch(() => undefined)}>{step.settingsLabel}</Button>;
      }
    };
    return <div className="el-step__actions">{step.actions.map(button)}</div>;
  };

  return (
    <div className="el-page el-page--narrow" data-v2="setup-health">
      {onBack && <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>Settings</Button>}
      <header className="el-page__head">
        <div className="el-page__titles">
          <h1 className="el-title-l">Setup &amp; health</h1>
          {/* The dot carries the state: the words go without the classic headline's check mark. */}
          {report && <Status kind={report.ready ? "ready" : "setup"} live>{report.ready ? READY : readyHeadline(report)}</Status>}
        </div>
      </header>
      {problem && <p className="el-field__error" role="alert">{problem}</p>}

      <section className="el-group" aria-label="Permissions">
        <h2 className="el-caption el-body-strong el-group__title">Permissions</h2>
        <Card>
          {!permissions ? (
            <Loading label="Reading permissions"><div className="el-group__rows"><Skeleton width="medium" /><Skeleton width="long" /></div></Loading>
          ) : (
            <Steps label="Permissions">
              {kinds.map((kind, i) => {
                const step = stepPlan(permissions, kind);
                const text = PERMISSION_STEP[kind];
                return (
                  <SetupStep key={kind} number={i + 1} title={text.title} state={step.words}
                    done={step.access === "allowed"} current={kind === current} open={opened === kind}
                    onToggle={() => setOpened(opened === kind ? null : kind)}>
                    <p>{text.forWhat}</p>
                    {text.note && <p className="el-caption">{text.note}</p>}
                    {step.showWhere && <p className="el-caption">{text.where}</p>}
                    {step.actions.length > 0 && stepActions(kind)}
                  </SetupStep>
                );
              })}
            </Steps>
          )}
        </Card>
      </section>

      <section className="el-group" aria-label="Ready check">
        <div className="el-group__head">
          <h2 className="el-caption el-body-strong el-group__title">Ready check</h2>
          <Button size="sm" variant="quiet" loading={busy === "ready"} loadingLabel="Checking…"
            onClick={() => void doing("ready", async () => setReport(await api.readyCheck()))}>Check again</Button>
        </div>
        <Card>
          {!report ? (
            <Loading label="Checking"><div className="el-group__rows"><Skeleton width="medium" /><Skeleton width="long" /></div></Loading>
          ) : (
            <ul className="el-health-list" aria-label="Ready check">
              {report.checks.map((c) => (
                <HealthCheckRow key={c.id} state={c.ok ? "ok" : c.advisory ? "limited" : "problem"} stateLabel={c.ok ? "OK" : c.advisory ? "Worth a look" : "Needs a fix"} title={c.label} detail={c.detail ?? undefined}
                  action={c.fix ? (
                    <Button size="sm" variant={c.ok ? "secondary" : "primary"} loading={busy === c.id} loadingLabel="Fixing…"
                      onClick={() => void doing(c.id, async () => setReport(await api.readyFix(c.fix!.action)))}>{c.fix.label}</Button>
                  ) : undefined} />
              ))}
            </ul>
          )}
        </Card>
      </section>

      {platform === "macos" && (
        <section className="el-group" aria-label="Mac check">
          <div className="el-group__head">
            <h2 className="el-caption el-body-strong el-group__title">Mac check</h2>
            {mac && <span className="el-caption">{`Last run ${formatWhen(mac.finishedAt)}`}</span>}
          </div>
          <Card>
            {mac === undefined ? (
              <Loading label="Reading the last Mac check"><div className="el-group__rows"><Skeleton width="long" /></div></Loading>
            ) : busy === "mac" ? (
              <Loading label="Running the Mac check"><div className="el-group__rows"><Skeleton width="long" /><Skeleton width="medium" /><Skeleton width="long" /></div></Loading>
            ) : mac === null ? (
              <EmptyState icon={Icons.ReadyIcon} title="Run the Mac check"
                body="It checks the microphone, system audio, screen recording and call detection on this Mac in about a minute."
                action={<Button variant="primary" onClick={() => void doing("mac", async () => setMac(await api.macCheckRun()))}>Run Mac check</Button>} />
            ) : (
              <>
                <ul className="el-health-list" aria-label="Mac check">
                  {mac.items.map((item) => (
                    <HealthCheckRow key={item.key} state={CHECK_STATE[item.state]} stateLabel={CHECK_WORDS[item.state]} title={item.title} detail={item.detail}
                      action={item.fix ? <Button size="sm" onClick={() => void api.openPrivacySettings(item.fix!.settings).catch(() => undefined)}>{item.fix.label}</Button> : undefined} />
                  ))}
                </ul>
                {mac.stopped && <p className="el-caption el-group__note">{mac.stopped}</p>}
                <div className="el-step__actions el-group__note">
                  <Button onClick={() => void doing("mac", async () => setMac(await api.macCheckRun()))}>Run again</Button>
                  <Button variant="quiet" onClick={() => void doing("copy", () => api.macCheckCopy())}>Copy the report</Button>
                </div>
              </>
            )}
          </Card>
        </section>
      )}
    </div>
  );
}
