// Sign in to EchoLedger (since v1.5, docs/API.md "Signing in with an account"): the server, the account's email and
// password (and the two-factor code when the account has one), or the browser for Google and passkeys. The pairing
// code / QR stays for servers older than accounts.
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, listenTo, type AppStatus } from "../../lib/ipc";
import { PairingView } from "./PairingView";
import { ShieldIcon } from "../../components/Icons";
import { Wordmark } from "../../components/BrandMark";

const PAIRING_ONLY = "This server does not sign in with accounts yet. Pair this computer with a code from the console instead.";
const OTP_ASKED = "Your account has two-factor sign-in. Type the code your authenticator app shows, or a recovery code.";
const BROWSER_WAIT = "Finish signing in in your browser. EchoLedger continues by itself when you are done.";
const SIGNED_IN = "Signed in. Your library will sync now.";

/** A server address worth sending: more than the "https://" the field starts with. */
function usableServer(url: string): boolean {
  return /^https?:\/\/[^/\s]+/.test(url.trim());
}

export function SignInView({ onSignedIn, notify, serverUrl }: {
  onSignedIn: (s: AppStatus) => void;
  notify: (text: string, error?: boolean) => void;
  /** The server this computer used before (signed out), to start the field with. */
  serverUrl?: string | null;
}) {
  const [server, setServer] = useState(serverUrl || "https://");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [otp, setOtp] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);
  const [waiting, setWaiting] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [pairing, setPairing] = useState<"chosen" | "only" | null>(null);
  const parent = useRef({ onSignedIn, notify });
  parent.current = { onSignedIn, notify };

  // The browser sign-in comes back through the core (the echoledger://signed-in link), as these two events.
  useEffect(() => {
    const subs = [
      listenTo("signin:done", (status) => {
        setWaiting(false);
        parent.current.notify(SIGNED_IN);
        parent.current.onSignedIn(status);
      }),
      listenTo("signin:failed", (text) => {
        setWaiting(false);
        setProblem(text);
      }),
      // A link of an earlier browser sign-in: said, while this one keeps waiting.
      listenTo("signin:other", (text) => setProblem(text)),
    ];
    return () => subs.forEach((p) => p.then((f) => f()).catch(() => undefined));
  }, []);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setProblem(null);
    try {
      const outcome = await api.signInPassword(server.trim(), email.trim(), password, otp === null ? null : otp.trim(), name.trim());
      if (outcome.result === "otp_required") {
        setOtp((code) => code ?? "");
        setProblem(otp === null ? null : OTP_ASKED);
      } else if (outcome.result === "pairing_only") {
        setPairing("only");
      } else if (outcome.status) {
        notify(SIGNED_IN);
        onSignedIn(outcome.status);
      }
    } catch (err) {
      setProblem(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const browser = async () => {
    setProblem(null);
    setBusy(true);
    try {
      if (await api.signInBrowser(server.trim(), name.trim())) setWaiting(true);
      else setPairing("only");
    } catch (err) {
      setProblem(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const cancel = () => {
    setWaiting(false);
    setProblem(null);
    void api.signInBrowserCancel().catch(() => undefined);
  };

  if (pairing) {
    return (
      <PairingView onPaired={onSignedIn} notify={notify} notice={pairing === "only" ? PAIRING_ONLY : null}
        onUseAccount={pairing === "chosen" ? () => setPairing(null) : undefined} />
    );
  }

  return (
    <div style={{ display: "grid", placeItems: "center", height: "100%", padding: 24 }}>
      <form className="card sign-in" style={{ width: 440 }} onSubmit={submit} aria-labelledby="sign-in-title">
        <Wordmark />
        <h1 id="sign-in-title" className="page-title">Sign in to EchoLedger</h1>
        <p className="page-sub">Sign in with your EchoLedger account. Your recordings and projects are there on every computer and phone you sign in on.</p>
        <label className="field">Server address
          <input className="input" value={server} onChange={(e) => setServer(e.target.value)} placeholder="https://ledger.example.com" required disabled={waiting} />
        </label>
        {waiting ? (
          <div className="sign-in-wait" role="status">
            <p className="small">{BROWSER_WAIT}</p>
            {problem && <div className="notice error small" role="alert">{problem}</div>}
            <div className="row"><span className="spacer" /><button type="button" className="btn ghost" onClick={cancel}>Cancel</button></div>
          </div>
        ) : (
          <>
            <label className="field">Email
              <input className="input" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
            </label>
            <label className="field">Password
              <input className="input" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
            </label>
            {otp !== null && (
              <label className="field">Two-factor code
                <input className="input" inputMode="numeric" autoComplete="one-time-code" value={otp} onChange={(e) => setOtp(e.target.value)}
                  placeholder="123456" aria-describedby="sign-in-otp-hint" required autoFocus />
                <span id="sign-in-otp-hint" className="small muted">{OTP_ASKED}</span>
              </label>
            )}
            <label className="field">Name for this computer (optional)
              <input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="Work laptop" />
            </label>
            {problem && <div className="notice error small" role="alert">{problem}</div>}
            <button className="btn primary" type="submit" disabled={busy} style={{ width: "100%", justifyContent: "center", marginTop: 12 }}>
              {busy ? "Signing in…" : "Sign in"}
            </button>
            <div className="sign-in-or" aria-hidden="true"><span>or</span></div>
            <button className="btn" type="button" disabled={busy || !usableServer(server)} onClick={() => void browser()} style={{ width: "100%", justifyContent: "center" }}>
              Sign in in the browser (Google, passkeys)
            </button>
            <button type="button" className="btn ghost small sign-in-pair" onClick={() => setPairing("chosen")}>Use a pairing code or QR instead</button>
          </>
        )}
        <p className="small muted row" style={{ marginTop: 14 }}><ShieldIcon size={14} />The access token is stored in your OS keychain. Your password is sent only to your server.</p>
      </form>
    </div>
  );
}
