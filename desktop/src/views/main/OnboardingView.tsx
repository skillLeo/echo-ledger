// First-run wizard (plan B19): permissions → sign in (or pairing, v1.5) → a 10-second test recording → done, with the
// recording-consent notice. On a Mac (since v1.5) each permission gets a guided step of its own after the welcome. Can be
// skipped; the same controls stay in Settings.
import { useEffect, useState, type ReactNode } from "react";
import { api, errorMessage, type AppStatus, type PermissionKind, type PermissionsStatus, type TestResult } from "../../lib/ipc";
import { SignInView } from "./SignInView";
import { testVerdict } from "./CaptureSettings";
import { ConsentNotice } from "./ConsentNotice";
import { Wordmark } from "../../components/BrandMark";
import { CheckIcon } from "../../components/ControlIcons";
import { CONSENT_TICK } from "../../lib/consent";
import { PERMISSION_STEP, PermissionStep } from "./Permissions";
import type { Notify } from "../../lib/notice";

export const STEPS = ["Permissions", "Sign in", "Test", "Done"] as const;
/** On a Mac the welcome comes first and each permission is a step of its own. */
export const MAC_STEPS = ["Welcome", "Microphone", "System audio", "Screen recording", "Sign in", "Test", "Done"] as const;
export type Step = (typeof STEPS)[number] | (typeof MAC_STEPS)[number];

const MAC_PERMISSION_STEP: Partial<Record<Step, PermissionKind>> = { Microphone: "microphone", "System audio": "system_audio", "Screen recording": "screen" };

export function stepsFor(platform: AppStatus["platform"]): readonly Step[] {
  return platform === "macos" ? MAC_STEPS : STEPS;
}

export function OnboardingView({ status, onStatus, onFinished, notify }: {
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

  // Read again whenever the window comes back, which is what returning from System Settings looks like.
  useEffect(() => {
    const read = () => api.permissionsStatus().then(setPermissions).catch(() => undefined);
    read();
    window.addEventListener("focus", read);
    return () => window.removeEventListener("focus", read);
  }, []);

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
  const permissionKind = MAC_PERMISSION_STEP[step];
  // Skip setup sits on the same row as the step's main action (left), so the two read as one choice.
  const actions = (primary: ReactNode) => (
    <div className="row onboarding-actions">
      <button type="button" className="btn ghost small" onClick={() => void finish()}>Skip setup</button>
      <span className="spacer" />
      {primary}
    </div>
  );

  return (
    <main className="gate onboarding">
      <div className="card onboarding-card">
        <Wordmark />
        <ol className="steps" aria-label="Setup steps">
          {steps.map((s, i) => (
            <li key={s} className={i === index ? "current" : i < index ? "done" : ""} aria-current={i === index ? "step" : undefined}>
              {i < index && <span className="step-check" aria-hidden="true"><CheckIcon size={13} /></span>}{s}
            </li>
          ))}
        </ol>

        {step === "Welcome" && (
          <section aria-labelledby="ob-welcome">
            <h1 id="ob-welcome" className="page-title">Welcome to EchoLedger</h1>
            <p className="small muted">EchoLedger records your calls, voice notes and dictation on this computer and keeps them in your own library. Next, macOS needs to allow three things.</p>
            {/* The consent notice is on the first page too, so skipping the setup does not skip it (v1.5). */}
            <div className="notice small"><ConsentNotice legal={false} /></div>
            {actions(<button type="button" className="btn primary" onClick={next}>Continue</button>)}
          </section>
        )}

        {permissionKind && (
          <section aria-labelledby="ob-permission">
            <h1 id="ob-permission" className="page-title">{PERMISSION_STEP[permissionKind].title}</h1>
            <PermissionStep kind={permissionKind} permissions={permissions} onChange={setPermissions} />
            {actions(<button type="button" className="btn primary" onClick={next}>Continue</button>)}
          </section>
        )}

        {step === "Permissions" && (
          <section aria-labelledby="ob-perm">
            <h1 id="ob-perm" className="page-title">Welcome to EchoLedger</h1>
            <p className="small muted">EchoLedger records your calls, voice notes and dictation on this computer and keeps them in your own library.</p>
            {/* The consent notice is on the first page too, so skipping the setup does not skip it (v1.5). */}
            <div className="notice small"><ConsentNotice legal={false} /></div>
            <div className="toggle">
              <div>Microphone</div>
              <div className="row">
                <span className="small">{permissions?.microphone === "allowed" ? "Allowed" : permissions?.microphone === "denied" ? "Blocked" : "Asked the first time you record"}</span>
                {permissions?.microphone === "denied" && <button type="button" className="btn ghost small" onClick={() => void api.openPrivacySettings("microphone")}>Open settings</button>}
              </div>
            </div>
            {actions(<button type="button" className="btn primary" onClick={next}>Continue</button>)}
          </section>
        )}

        {step === "Sign in" && (
          <section aria-labelledby="ob-pair">
            <h1 id="ob-pair" className="page-title">Connect to your server</h1>
            {status.paired ? (
              <>
                <p className="small">Signed in to {status.serverUrl} as {status.deviceName}.</p>
                {actions(<button type="button" className="btn primary" onClick={next}>Continue</button>)}
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
          <section aria-labelledby="ob-test">
            <h1 id="ob-test" className="page-title">Try a 10-second test</h1>
            <p className="small muted">Say a few words and play something on this computer, so both sides of a call are checked.</p>
            <button type="button" className="btn" disabled={testing} onClick={() => void test()}>{testing ? "Recording 10 seconds…" : result ? "Test again" : "Start the test"}</button>
            {result && <ul className="small">{testVerdict(result).map((line) => <li key={line}>{line}</li>)}</ul>}
            {actions(<button type="button" className="btn primary" onClick={next}>{result ? "Continue" : "Skip the test"}</button>)}
          </section>
        )}

        {step === "Done" && (
          <section aria-labelledby="ob-done">
            <h1 id="ob-done" className="page-title">You are set</h1>
            <h2 className="settings-group-title">Recording and consent</h2>
            <ConsentNotice legal={status.plan.known && status.paired} />
            <label className="row small"><input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} /> {CONSENT_TICK}</label>
            <div className="row onboarding-actions"><span className="spacer" /><button type="button" className="btn primary" onClick={() => void finish()}>Start using EchoLedger</button></div>
          </section>
        )}

      </div>
    </main>
  );
}
