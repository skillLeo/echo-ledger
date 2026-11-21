// The first-run wizard in the new design (classic's OnboardingView, the same steps, rules, words and commands):
// permissions (on a Mac a welcome, then each permission as its own step), sign in, a 10-second test, done with the
// recording-consent notice and the screen-share privacy check. Skip setup on every step; the same controls stay in
// Settings. Finishing (or skipping) saves onboardingDone, with the consent tick when it was ticked.
import { useEffect, useRef, useState, type ReactNode } from "react";
import { api, errorMessage, type AppStatus, type PermissionKind, type PermissionsStatus, type ShareCheckView, type TestResult } from "../../lib/ipc";
import { CONSENT_NOTICE, CONSENT_TICK, LEGAL_HINT, RECORDING_SHOWN } from "../../lib/consent";
import { summary, usePrivacyStatus } from "../../lib/privacy";
import type { Notify } from "../../lib/notice";
import { PERMISSION_STEP, stepPlan, type StepAction } from "../../views/main/Permissions";
import { OPEN_SYSTEM_SETTINGS, PATTERN_FLASH, sharePrivacyTop } from "../../views/main/PrivacySettings";
import { testVerdict } from "../../views/main/CaptureSettings";
import { SignInView } from "../../views/main/SignInView";
import { stepsFor, type Step } from "../../views/main/OnboardingView";
import { Button, Card, Checkbox, Icon, Icons } from "../components";

const PERMISSION_OF: Partial<Record<Step, PermissionKind>> = { Microphone: "microphone", "System audio": "system_audio", "Screen recording": "screen" };

/** The consent notice: the sentence for everyone, and the server's Terms and Privacy once it is known. */
function Consent({ legal }: { legal: boolean }) {
  return (
    <div className="el-wizard__consent" data-testid="consent-notice">
      <p>{CONSENT_NOTICE} {RECORDING_SHOWN}</p>
      {legal && (
        <p className="el-caption el-secondary el-wizard__legal">
          <span>{LEGAL_HINT}</span>
          <Button size="sm" variant="quiet" onClick={() => void api.openServerPage("terms").catch(() => undefined)}>Terms</Button>
          <Button size="sm" variant="quiet" onClick={() => void api.openServerPage("privacy").catch(() => undefined)}>Privacy</Button>
        </p>
      )}
    </div>
  );
}

/** Screen-share privacy, checked once before the first call (classic's OnboardingShareCheck). */
function ShareCheck({ platform }: { platform: string }) {
  const status = usePrivacyStatus();
  const [view, setView] = useState<ShareCheckView | null>(null);
  const [running, setRunning] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const run = async () => {
    setRunning(true);
    setProblem(null);
    try {
      setView(await api.shareCheckRun("onboarding"));
    } catch (e) {
      setProblem(errorMessage(e));
    } finally {
      setRunning(false);
    }
  };
  const line = view ? summary(view, platform, new Date(), status?.apps ?? [], "onboarding") : null;
  const busy = running || !!status?.checkRunning;
  return (
    <section className="el-wizard__group" aria-label="Screen-share privacy" data-testid="onboarding-share-check">
      <h2 className="el-body-strong">Screen-share privacy</h2>
      {sharePrivacyTop(status?.hiding, status?.badge).map((l) => <p key={l} className="el-caption el-secondary">{l}</p>)}
      <p className="el-caption el-secondary">{PATTERN_FLASH}</p>
      <Button loading={busy} loadingLabel="Checking…" onClick={() => void run()}>{view ? "Check again" : "Check screen-share privacy"}</Button>
      {line && (
        <p className={`el-caption${line.tone === "warning" ? " el-row__error" : ""}`} role="status">
          {line.text}
          {line.fix === "open_settings" && <> <Button size="sm" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>{OPEN_SYSTEM_SETTINGS}</Button></>}
          {line.fix === "try_again" && <> <Button size="sm" disabled={busy} onClick={() => void run()}>Try again</Button></>}
        </p>
      )}
      {problem && <p className="el-field__error" role="alert">{problem}</p>}
    </section>
  );
}

export function OnboardingV2({ status, onStatus, onFinished, notify }: {
  status: AppStatus;
  onStatus: (s: AppStatus) => void;
  onFinished: () => void;
  notify: Notify;
}) {
  const steps = stepsFor(status.platform);
  const [step, setStep] = useState<Step>(steps[0]);
  const [permissions, setPermissions] = useState<PermissionsStatus | null>(null);
  const [testing, setTesting] = useState(false);
  const [result, setResult] = useState<TestResult | null>(null);
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  // A permission request the system refused to show: said on the step, never silent.
  const [askFailed, setAskFailed] = useState<{ kind: PermissionKind; words: string } | null>(null);
  const heading = useRef<HTMLHeadingElement>(null);

  // Read again whenever the window comes back, which is what returning from System Settings looks like.
  useEffect(() => {
    const read = () => api.permissionsStatus().then(setPermissions).catch(() => undefined);
    read();
    window.addEventListener("focus", read);
    return () => window.removeEventListener("focus", read);
  }, []);
  // Each step starts with its title for the keyboard and screen readers.
  const first = useRef(true);
  useEffect(() => {
    if (first.current) {
      first.current = false;
      return;
    }
    heading.current?.focus();
  }, [step]);

  const finish = async () => {
    try {
      await api.updateSettings({ onboardingDone: true, ...(consent ? { consentAcknowledged: true } : {}) });
      onFinished();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  const test = async () => {
    setTesting(true);
    try {
      setResult(await api.testCapture(10));
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setTesting(false);
    }
  };
  const index = steps.indexOf(step);
  const next = () => setStep(steps[Math.min(index + 1, steps.length - 1)]);
  const kind = PERMISSION_OF[step];
  const actions = (primary: ReactNode) => (
    <div className="el-wizard__actions">
      <Button variant="quiet" onClick={() => void finish()}>Skip setup</Button>
      {primary}
    </div>
  );
  const title = (text: string) => <h1 className="el-title-l" ref={heading} tabIndex={-1}>{text}</h1>;

  // A permission step's buttons: the same rule every permission step follows (Permissions.tsx stepPlan).
  const permissionButtons = (k: PermissionKind) => {
    const plan = stepPlan(permissions, k);
    const button = (action: StepAction) => {
      const variant = plan.main === action ? "primary" : action === "settings" ? "quiet" : "secondary";
      switch (action) {
        case "reopen":
          return <Button key={action} variant={variant} onClick={() => void api.quitAndReopen().catch((e) => notify(errorMessage(e), true))}>Quit &amp; reopen</Button>;
        case "allow":
          return (
            <Button key={action} variant={variant} loading={busy === k} loadingLabel="Asking…" onClick={() => {
              setBusy(k);
              setAskFailed(null);
              void api.permissionRequest(k).then(setPermissions, (e) => setAskFailed({ kind: k, words: errorMessage(e) })).finally(() => setBusy(null));
            }}>Allow</Button>
          );
        case "settings":
          return <Button key={action} variant={variant} onClick={() => void api.openPrivacySettings(k).catch(() => undefined)}>{plan.settingsLabel}</Button>;
      }
    };
    return { plan, buttons: <div className="el-wizard__row">{plan.actions.map(button)}</div> };
  };

  return (
    <main className="el-wizard" data-v2="onboarding">
      <Card className="el-wizard__card">
        <p className="el-wizard__brand" lang="en" dir="ltr">EchoLedger</p>
        <ol className="el-wizard__steps" aria-label="Setup steps">
          {steps.map((s, i) => (
            <li key={s} className={`el-wizard__step${i === index ? " el-wizard__step--current" : i < index ? " el-wizard__step--done" : ""}`}
              aria-current={i === index ? "step" : undefined}>
              {i < index ? <Icon icon={Icons.CheckIcon} /> : <span className="el-wizard__dot" aria-hidden="true" />}
              <span>{s}</span>
              {i < index && <span className="el-visually-hidden"> (done)</span>}
            </li>
          ))}
        </ol>

        {(step === "Welcome" || step === "Permissions") && (
          <section className="el-wizard__body" aria-label={step}>
            {title("Welcome to EchoLedger")}
            <p className="el-secondary">
              EchoLedger records your calls, voice notes and dictation on this computer and keeps them in your own library.
              {step === "Welcome" ? " Next, macOS needs to allow three things." : ""}
            </p>
            {/* The consent notice is on the first page too, so skipping the setup does not skip it. */}
            <Consent legal={false} />
            {step === "Permissions" && (
              <div className="el-wizard__row" data-testid="microphone-line">
                <span className="el-body-strong">Microphone</span>
                <span>{permissions?.microphone === "allowed" ? "Allowed" : permissions?.microphone === "denied" ? "Blocked" : "Asked the first time you record"}</span>
                {permissions?.microphone === "denied" && <Button size="sm" onClick={() => void api.openPrivacySettings("microphone")}>Open settings</Button>}
              </div>
            )}
            {actions(<Button variant="primary" onClick={next}>Continue</Button>)}
          </section>
        )}

        {kind && (() => {
          const { plan, buttons } = permissionButtons(kind);
          return (
            <section className="el-wizard__body" aria-label={PERMISSION_STEP[kind].title}>
              {title(PERMISSION_STEP[kind].title)}
              <p className="el-secondary">{PERMISSION_STEP[kind].forWhat}</p>
              <p className="el-caption" role="status">{plan.words}</p>
              {buttons}
              {askFailed?.kind === kind && (
                <p className="el-field__error" role="alert" data-testid="allow-failed">
                  {`macOS didn't ask: ${askFailed.words} Use ${plan.settingsLabel} instead.`}
                </p>
              )}
              {plan.showWhere && <p className="el-caption el-secondary">{PERMISSION_STEP[kind].where}</p>}
              {actions(<Button variant="primary" onClick={next}>Continue</Button>)}
            </section>
          );
        })()}

        {step === "Sign in" && (
          <section className="el-wizard__body" aria-label="Sign in">
            {title("Connect to your server")}
            {status.paired ? (
              <>
                <p dir="auto">Signed in to {status.serverUrl} as {status.deviceName}.</p>
                {actions(<Button variant="primary" onClick={next}>Continue</Button>)}
              </>
            ) : (
              <>
                <SignInView onSignedIn={(s) => { onStatus(s); if (s.paired) next(); }} notify={notify} serverUrl={status.serverUrl} />
                {actions(null)}
              </>
            )}
          </section>
        )}

        {step === "Test" && (
          <section className="el-wizard__body" aria-label="Test">
            {title("Try a 10-second test")}
            <p className="el-secondary">Say a few words and play something on this computer, so both sides of a call are checked.</p>
            <Button icon={Icons.MicIcon} loading={testing} loadingLabel="Recording 10 seconds…" onClick={() => void test()}>{result ? "Test again" : "Start the test"}</Button>
            {result && <ul className="el-wizard__verdict" aria-label="Test result">{testVerdict(result).map((line) => <li key={line}>{line}</li>)}</ul>}
            {actions(<Button variant="primary" onClick={next}>{result ? "Continue" : "Skip the test"}</Button>)}
          </section>
        )}

        {step === "Done" && (
          <section className="el-wizard__body" aria-label="Done">
            {title("You are set")}
            <h2 className="el-body-strong">Recording and consent</h2>
            <Consent legal={status.plan.known && status.paired} />
            <Checkbox label={CONSENT_TICK} checked={consent} onChange={(e) => setConsent(e.target.checked)} />
            <ShareCheck platform={status.platform} />
            <div className="el-wizard__actions el-wizard__actions--end">
              <Button variant="primary" onClick={() => void finish()}>Start using EchoLedger</Button>
            </div>
          </section>
        )}
      </Card>
    </main>
  );
}
