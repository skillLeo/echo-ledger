// First-run wizard (plan B19): permissions → pairing → a 10-second test recording → done. Can be skipped; the same
// controls stay in Settings.
import { useEffect, useState, type ReactNode } from "react";
import { api, errorMessage, type AppStatus, type PermissionsStatus, type TestResult } from "../../lib/ipc";
import { PairingView } from "./PairingView";
import { testVerdict } from "./CaptureSettings";

export const STEPS = ["Permissions", "Pair", "Test", "Done"] as const;
export type Step = (typeof STEPS)[number];

export function OnboardingView({ status, onStatus, onFinished, notify }: {
  status: AppStatus;
  onStatus: (s: AppStatus) => void;
  onFinished: () => void;
  notify: (text: string, error?: boolean) => void;
}) {
  const [step, setStep] = useState<Step>("Permissions");
  const [permissions, setPermissions] = useState<PermissionsStatus | null>(null);
  const [testing, setTesting] = useState(false);
  const [result, setResult] = useState<TestResult | null>(null);
  const [consent, setConsent] = useState(false);

  useEffect(() => {
    api.permissionsStatus().then(setPermissions).catch(() => undefined);
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
  const index = STEPS.indexOf(step);
  const next = () => setStep(STEPS[Math.min(index + 1, STEPS.length - 1)]);
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
        <ol className="steps" aria-label="Setup steps">
          {STEPS.map((s, i) => <li key={s} className={i === index ? "current" : i < index ? "done" : ""} aria-current={i === index ? "step" : undefined}>{s}</li>)}
        </ol>

        {step === "Permissions" && (
          <section aria-labelledby="ob-perm">
            <h1 id="ob-perm" className="page-title">Welcome to EchoLedger</h1>
            <p className="small muted">EchoLedger records your calls, voice notes and dictation on this computer and keeps them in your own library.</p>
            <div className="toggle">
              <div>Microphone</div>
              <div className="row">
                <span className="small">{permissions?.microphone === "allowed" ? "Allowed" : permissions?.microphone === "denied" ? "Blocked" : "Asked the first time you record"}</span>
                {permissions?.microphone === "denied" && <button type="button" className="btn ghost small" onClick={() => void api.openPrivacySettings("microphone")}>Open settings</button>}
              </div>
            </div>
            {permissions?.platform === "macos" && (
              <div className="toggle">
                <div>Sound from other apps</div>
                <button type="button" className="btn ghost small" onClick={() => void api.openPrivacySettings("system_audio")}>Open settings</button>
              </div>
            )}
            {actions(<button type="button" className="btn primary" onClick={next}>Continue</button>)}
          </section>
        )}

        {step === "Pair" && (
          <section aria-labelledby="ob-pair">
            <h1 id="ob-pair" className="page-title">Connect to your server</h1>
            {status.paired ? (
              <>
                <p className="small">Paired with {status.serverUrl} as {status.deviceName}.</p>
                {actions(<button type="button" className="btn primary" onClick={next}>Continue</button>)}
              </>
            ) : (
              <>
                <PairingView onPaired={(s) => { onStatus(s); if (s.paired) next(); }} notify={notify} />
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
            <p className="small muted">Call-recording laws differ by country. Tell participants you are recording where required. A red indicator shows whenever EchoLedger records.</p>
            <label className="row small"><input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} /> I understand and will obtain consent where required</label>
            <div className="row onboarding-actions"><span className="spacer" /><button type="button" className="btn primary" onClick={() => void finish()}>Start using EchoLedger</button></div>
          </section>
        )}

      </div>
    </main>
  );
}
