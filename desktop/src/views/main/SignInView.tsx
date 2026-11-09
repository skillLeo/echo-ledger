// Sign in to EchoLedger (v1.6, P1, docs/API.md "Sign-up and sign-in with an email code"): one flow in plain words. The
// email first. A new email gets a 6-digit code, then the name and "I accept the Terms and Privacy", and is signed in on
// the Free plan; an existing one types its password or asks for a code by email, then the two-factor code when the
// account has one. Under "or", "Continue with Google" and "Passkey" side by side (both the browser sign-in), and "Sign in with QR / code"
// as a small link (pairing codes, for older servers and the console's "Sign in a device").
//
// The server is built in (lib/server.ts); "Use a different server" sits under Advanced. A build without one asks for
// the server address as before. An older server (no /api/auth/start) gets the v1.5 sign-in: password and browser.
import { useEffect, useId, useRef, useState } from "react";
import { api, errorMessage, listenTo, type AppStatus, type AuthOptions } from "../../lib/ipc";
import { DEFAULT_SERVER, cleanServer, serverHost } from "../../lib/server";
import { PairingView } from "./PairingView";
import { KeyIcon, ShieldIcon } from "../../components/Icons";
import { GoogleMark, Wordmark } from "../../components/BrandMark";
import { NO_TARGET, type Notify } from "../../lib/notice";

const PAIRING_ONLY = "This server does not sign in with accounts yet. Sign in with a code from the console instead.";
const OTP_ASKED = "Your account has two-factor sign-in. Type the code your authenticator app shows, or a recovery code.";
const BROWSER_WAIT = "Finish signing in in your browser. EchoLedger continues by itself when you are done.";
const SIGNED_IN = "Signed in. Your library will sync now.";
const WELCOME = "Welcome to EchoLedger. Your account is ready.";
const GOOGLE_ONLY = "This account signs in with Google. Choose Continue with Google.";
/** The plain promise under every sign-in card (no "token" or "keychain" for the person). */
export const ENCRYPTED = "Your data is encrypted on this computer.";

/** A server address worth asking: more than the "https://" the field starts with. */
function usableServer(url: string): boolean {
  return /^https?:\/\/[^/\s]+/.test(url.trim());
}

/** How long the server address rests before its sign-in options are asked for. */
export const OPTIONS_AFTER_MS = 500;

/**
 * What the server's sign-in offers (Phase 1C, `GET /api/auth/options`), asked once the address rests; null while not
 * known, from an older server or offline: the screen then shows what it always did.
 */
function useAuthOptions(server: string): AuthOptions | null {
  const [options, setOptions] = useState<{ server: string; options: AuthOptions | null } | null>(null);
  useEffect(() => {
    const url = server.trim();
    if (!usableServer(url)) return;
    let alive = true;
    const timer = window.setTimeout(() => {
      api.authOptions(url).then((o) => alive && setOptions({ server: url, options: o ?? null }), () => alive && setOptions({ server: url, options: null }));
    }, OPTIONS_AFTER_MS);
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [server]);
  return options && options.server === server.trim() ? options.options : null;
}

/** Seconds until a new code may be asked for, counting down once a second. */
function useCountdown(until: number | null): number {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (until === null) return;
    setNow(Date.now());
    // Ticks until the wait is over, then stops.
    const timer = window.setInterval(() => {
      const at = Date.now();
      setNow(at);
      if (at >= until) window.clearInterval(timer);
    }, 1000);
    return () => window.clearInterval(timer);
  }, [until]);
  // The clock as of this render too, so a fresh countdown never shows a second more than it was given.
  return until === null ? 0 : Math.max(0, Math.ceil((until - Math.max(now, Date.now())) / 1000));
}

type Step =
  | { kind: "email" }
  /** `older`: a server without email codes (the v1.5 screen: password, browser, its own Create account page). */
  | { kind: "password"; methods: string[]; older: boolean }
  | { kind: "code"; purpose: "sign_in" | "sign_up"; methods: string[] }
  | { kind: "otp"; via: "password" | "code" }
  | { kind: "profile" };

export function SignInView({ onSignedIn, notify, serverUrl, defaultServer = DEFAULT_SERVER }: {
  onSignedIn: (s: AppStatus) => void;
  notify: Notify;
  /** The server this computer used before (signed out). */
  serverUrl?: string | null;
  /** The server built into the app (lib/server.ts); empty asks for the address. Tests pass their own. */
  defaultServer?: string;
}) {
  const builtIn = cleanServer(defaultServer);
  const before = cleanServer(serverUrl);
  const [server, setServer] = useState(before || builtIn || "https://");
  // A server other than the built-in one is shown, so the person sees where they sign in.
  const [advanced, setAdvanced] = useState(!!builtIn && !!before && before !== builtIn);
  const [step, setStep] = useState<Step>({ kind: "email" });
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [otp, setOtp] = useState("");
  const [name, setName] = useState("");
  const [accepted, setAccepted] = useState(false);
  const [deviceName, setDeviceName] = useState("");
  const [busy, setBusy] = useState(false);
  const [waiting, setWaiting] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [resendAt, setResendAt] = useState<number | null>(null);
  const [pairing, setPairing] = useState<"chosen" | "only" | null>(null);
  const wait = useCountdown(resendAt);
  const options = useAuthOptions(server);
  const ids = { server: useId(), device: useId(), accept: useId(), advanced: useId() };
  const parent = useRef({ onSignedIn, notify });
  parent.current = { onSignedIn, notify };
  const target = server.trim();

  // Under "or" (v1.6.2): "Continue with Google" when the server offers it, and Passkey when it signs in in the browser;
  // both finish in the browser, where Google and passkeys work. An older server (no options) offers both there.
  const withGoogle = !options || options.google;
  const withPasskey = !options || options.browserSignIn;
  const openPage = (url: string) => void api.openAuthPage(target, url).catch((e) => setProblem(errorMessage(e)));
  const openServerPage = (page: "terms" | "privacy") => void api.openServerPage(page, target).catch((e) => setProblem(errorMessage(e)));

  // The browser sign-in comes back through the core (the echoledger://signed-in link), as these events.
  useEffect(() => {
    const subs = [
      listenTo("signin:done", (status) => {
        setWaiting(false);
        parent.current.notify(SIGNED_IN, false, NO_TARGET);
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

  /** Runs one step's request with the busy state and the problem beside the form. */
  const attempt = async (work: () => Promise<void>) => {
    setBusy(true);
    setProblem(null);
    try {
      await work();
    } catch (err) {
      setProblem(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const signedIn = (status: AppStatus, text = SIGNED_IN) => {
    notify(text, false, NO_TARGET);
    onSignedIn(status);
  };

  const sendCode = async (purpose: "sign_in" | "sign_up", methods: string[]) => {
    const sent = await api.sendCode(target, email.trim(), purpose);
    setResendAt(Date.now() + Math.max(1, sent.resendAfter) * 1000);
    setCode("");
    setStep({ kind: "code", purpose, methods });
    if (!sent.sent) setProblem(`A code was sent a moment ago. Check your email, or ask for a new one in ${sent.resendAfter} s.`);
  };

  const continueWithEmail = () => attempt(async () => {
    const start = await api.authStart(target, email.trim());
    if (start.account === "older") return setStep({ kind: "password", methods: ["password"], older: true });
    if (start.account === "new") return sendCode("sign_up", start.methods);
    if (start.methods.includes("password")) return setStep({ kind: "password", methods: start.methods, older: false });
    if (start.methods.includes("code")) return sendCode("sign_in", start.methods);
    setProblem(GOOGLE_ONLY);
  });

  const signInWithPassword = (withOtp: string | null) => attempt(async () => {
    const outcome = await api.signInPassword(target, email.trim(), password, withOtp === null ? null : withOtp.trim(), deviceName.trim());
    if (outcome.result === "otp_required") {
      if (step.kind === "otp") setProblem(OTP_ASKED);
      setStep({ kind: "otp", via: "password" });
    } else if (outcome.result === "pairing_only") {
      setPairing("only");
    } else if (outcome.status) {
      signedIn(outcome.status);
    }
  });

  const verify = (withOtp: string | null) => attempt(async () => {
    const outcome = await api.verifyCode(target, email.trim(), code.trim(), withOtp === null ? null : withOtp.trim(), deviceName.trim());
    if (outcome.result === "otp_required") {
      if (step.kind === "otp") setProblem(OTP_ASKED);
      setStep({ kind: "otp", via: "code" });
    } else if (outcome.result === "profile") {
      setStep({ kind: "profile" });
    } else if (outcome.status) {
      signedIn(outcome.status);
    }
  });

  const createAccount = () => attempt(async () => {
    try {
      signedIn(await api.signUp(name.trim(), accepted), WELCOME);
    } catch (err) {
      // The sign-up waited too long: back to the email, where a new code is one click away.
      const text = errorMessage(err);
      if (text.startsWith("This sign-up took too long")) setStep({ kind: "email" });
      throw err;
    }
  });

  const browser = () => attempt(async () => {
    if (await api.signInBrowser(target, deviceName.trim())) setWaiting(true);
    else setPairing("only");
  });

  const cancel = () => {
    setWaiting(false);
    setProblem(null);
    void api.signInBrowserCancel().catch(() => undefined);
  };

  const backToEmail = () => {
    setStep({ kind: "email" });
    setPassword("");
    setCode("");
    setOtp("");
    setProblem(null);
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (busy) return;
    switch (step.kind) {
      case "email":
        return void continueWithEmail();
      case "password":
        return void signInWithPassword(null);
      case "code":
        return void verify(null);
      case "otp":
        return void (step.via === "password" ? signInWithPassword(otp) : verify(otp));
      case "profile":
        return void createAccount();
    }
  };

  if (pairing) {
    return (
      <PairingView onPaired={onSignedIn} notify={notify} notice={pairing === "only" ? PAIRING_ONLY : null} serverUrl={target} defaultServer={builtIn}
        onUseAccount={pairing === "chosen" ? () => setPairing(null) : undefined} />
    );
  }

  const serverField = (label: string) => (
    <label className="field" htmlFor={ids.server}>{label}
      <input id={ids.server} className="input" value={server} onChange={(e) => setServer(e.target.value)} placeholder="https://ledger.example.com" required disabled={waiting || step.kind !== "email"} />
    </label>
  );
  // The browser (Google) beside the email, and beside a sign-in whose account can use it; never while signing up.
  const google = (withGoogle || withPasskey) && (step.kind === "email"
    || (step.kind === "password" && (step.older || step.methods.includes("google")))
    || (step.kind === "code" && step.purpose === "sign_in" && step.methods.includes("google")));
  const emailLine = (
    <p className="small sign-in-email">
      <span className="muted">{step.kind === "profile" ? "New account for" : "Signing in as"}</span> <b>{email.trim()}</b>
      <button type="button" className="btn text compact" onClick={backToEmail}>Use another email</button>
    </p>
  );
  const primary = (text: string, busyText: string, disabled = false) => (
    <button className="btn primary sign-in-primary" type="submit" disabled={busy || disabled}>{busy ? busyText : text}</button>
  );

  const title = { email: "Sign in to EchoLedger", password: "Welcome back", code: "Check your email", otp: "Two-factor sign-in", profile: "Create your account" }[step.kind];

  return (
    <div style={{ display: "grid", placeItems: "center", height: "100%", padding: 24 }}>
      <form className="card sign-in" style={{ width: 440, maxWidth: "100%" }} onSubmit={submit} aria-labelledby="sign-in-title">
        <Wordmark />
        <h1 id="sign-in-title" className="page-title">{title}</h1>
        {waiting ? (
          <div className="sign-in-wait" role="status">
            <p className="small">{BROWSER_WAIT}</p>
            {problem && <div className="notice error small" role="alert">{problem}</div>}
            <div className="row"><span className="spacer" /><button type="button" className="btn ghost" onClick={cancel}>Cancel</button></div>
          </div>
        ) : (
          <>
            {step.kind === "email" && (
              <>
                <p className="page-sub">Type your email to sign in, or to make a free account. Your recordings and projects are on every computer and phone you sign in on.</p>
                {!builtIn && serverField("Server address")}
                <label className="field">Email
                  <input className="input" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
                </label>
              </>
            )}
            {step.kind === "password" && (
              <>
                {emailLine}
                <label className="field">Password
                  <input className="input" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required autoFocus />
                </label>
                {options?.resetUrl && (
                  <button type="button" className="btn text compact sign-in-forgot" onClick={() => openPage(options.resetUrl!)}>Forgot password?</button>
                )}
              </>
            )}
            {step.kind === "code" && (
              <>
                <p className="page-sub">We sent a 6-digit code to <b>{email.trim()}</b>. It works for 10 minutes.</p>
                <label className="field">Code from the email
                  <input className="input sign-in-code" inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={code}
                    onChange={(e) => setCode(e.target.value.replace(/[^\d -]/g, ""))} placeholder="123456" required autoFocus />
                </label>
              </>
            )}
            {step.kind === "otp" && (
              <label className="field">Two-factor code
                <input className="input" inputMode="numeric" autoComplete="one-time-code" value={otp} onChange={(e) => setOtp(e.target.value)}
                  placeholder="123456" aria-describedby="sign-in-otp-hint" required autoFocus />
                <span id="sign-in-otp-hint" className="small muted">{OTP_ASKED}</span>
              </label>
            )}
            {step.kind === "profile" && (
              <>
                {emailLine}
                <label className="field">Your name
                  <input className="input" autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} maxLength={100} required autoFocus />
                </label>
                <div className="sign-in-accept">
                  <input id={ids.accept} type="checkbox" checked={accepted} onChange={(e) => setAccepted(e.target.checked)} aria-label="I accept the Terms and Privacy" />
                  <label htmlFor={ids.accept} className="small">I accept the</label>
                  <button type="button" className="btn text compact" onClick={() => openServerPage("terms")}>Terms</button>
                  <span className="small">and</span>
                  <button type="button" className="btn text compact" onClick={() => openServerPage("privacy")}>Privacy</button>
                </div>
                <p className="small muted">Your account starts on the Free plan. You can ask for more any time in Settings → Account.</p>
              </>
            )}

            {problem && <div className="notice error small" role="alert">{problem}</div>}

            {step.kind === "email" && primary("Continue", "Checking…", !email.trim() || !usableServer(server))}
            {step.kind === "password" && primary("Sign in", "Signing in…", !password)}
            {step.kind === "code" && primary("Continue", "Checking…", code.replace(/\D/g, "").length !== 6)}
            {step.kind === "otp" && primary("Sign in", "Signing in…", !otp.trim())}
            {step.kind === "profile" && primary("Create account", "Creating your account…", !name.trim() || !accepted)}

            {step.kind === "password" && step.methods.includes("code") && (
              <button type="button" className="btn ghost sign-in-secondary" disabled={busy} onClick={() => void attempt(() => sendCode("sign_in", step.methods))}>Email me a code instead</button>
            )}
            {step.kind === "code" && (
              <div className="row sign-in-resend">
                <button type="button" className="btn ghost compact" disabled={busy || wait > 0} onClick={() => void attempt(() => sendCode(step.purpose, step.methods))}>
                  {wait > 0 ? `Send a new code in ${wait} s` : "Send a new code"}
                </button>
                <span className="spacer" />
                <button type="button" className="btn text compact" onClick={backToEmail}>Use another email</button>
              </div>
            )}
            {step.kind === "otp" && <button type="button" className="btn text compact sign-in-secondary" onClick={backToEmail}>Start again</button>}

            {google && (
              <>
                <div className="sign-in-or" aria-hidden="true"><span>or</span></div>
                <div className="sign-in-alt" data-testid="sign-in-alt">
                  {withGoogle && (
                    <button className="btn sign-in-google" type="button" disabled={busy || !usableServer(server)} onClick={() => void browser()}>
                      <GoogleMark />Continue with Google
                    </button>
                  )}
                  {withPasskey && (
                    <button className="btn sign-in-passkey" type="button" aria-label="Sign in with a passkey" title="Opens the browser, where your passkey works"
                      disabled={busy || !usableServer(server)} onClick={() => void browser()}>
                      <KeyIcon size={18} />Passkey
                    </button>
                  )}
                </div>
              </>
            )}
            {step.kind === "password" && step.older && options?.signUp && options.signUpUrl && (
              <p className="small sign-in-create">New to EchoLedger? <button type="button" className="btn text compact" onClick={() => openPage(options.signUpUrl!)}>Create account</button></p>
            )}
            {step.kind === "email" && (
              <button type="button" className="btn text small sign-in-pair" onClick={() => setPairing("chosen")}>Sign in with QR / code</button>
            )}
            {step.kind === "email" && (
              <div className="sign-in-advanced">
                <button type="button" className="btn text compact" aria-expanded={advanced} aria-controls={ids.advanced} onClick={() => setAdvanced((a) => !a)}>Advanced</button>
                {advanced && (
                  <div id={ids.advanced} className="sign-in-advanced-body">
                    {builtIn && serverField("Use a different server")}
                    {builtIn && target !== builtIn && (
                      <button type="button" className="btn text compact" onClick={() => setServer(builtIn)}>Use {serverHost(builtIn)} again</button>
                    )}
                    <label className="field" htmlFor={ids.device}>Name for this computer (optional)
                      <input id={ids.device} className="input" value={deviceName} onChange={(e) => setDeviceName(e.target.value)} placeholder="Work laptop" maxLength={64} />
                    </label>
                  </div>
                )}
              </div>
            )}
          </>
        )}
        <p className="small muted row sign-in-safe"><ShieldIcon size={14} />{ENCRYPTED}</p>
      </form>
    </div>
  );
}
