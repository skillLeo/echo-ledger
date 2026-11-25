// Your account from the app (contract 2.0.0-l): the name, the picture, the email, the password, two-step sign-in,
// Google and passkeys. Each flow is written once here and drawn by both designs through a small kit (classic's Sheet and
// buttons, or the new design's Dialog, Button and TextField), so both say the same words and ask the same things.
// Every change that needs proof asks for it the way the account can give it: a two-step code when two-step is on
// (never the password alone), else the password, else a code sent to the email.
import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from "react";
import { api, type Account } from "../../lib/ipc";
import {
  ACCOUNT_WORDS, accountLang, call, cleanCode, DEFAULT_PASSWORD_RULES, failureText, fieldErrors, fieldText, looksLikeEmail, nameProblem, PASSKEY_NAME_MAX, passwordProblems,
  proofAfter, proofBody, proofFor, replyText, rulesLine, sentence, spacedSecret, type AccountReply, type PasswordRules, type ProofKind, type SecurityInfo,
  type TwoFactorStart,
} from "../../lib/account";
import { t } from "../../i18n";

// ---- The kit each design draws with ---------------------------------------------------------------------------------

export type KitDialogProps = { title: string; description?: string; onClose: () => void; busy?: boolean; alert?: boolean; children?: ReactNode; actions: ReactNode };
export type KitButtonProps = {
  kind?: "primary" | "secondary" | "danger" | "quiet";
  busy?: boolean;
  busyLabel?: string;
  disabled?: boolean;
  type?: "button" | "submit";
  form?: string;
  label?: string;
  onClick?: () => void;
  children: ReactNode;
};
export type KitFieldProps = {
  label: string;
  value: string;
  onChange: (value: string) => void;
  type?: "text" | "password" | "email";
  autoComplete?: string;
  inputMode?: "numeric" | "text" | "email";
  error?: string;
  hint?: string;
  autoFocus?: boolean;
  maxLength?: number;
};
export type AccountKit = {
  Dialog: (p: KitDialogProps) => ReactNode;
  Button: (p: KitButtonProps) => ReactNode;
  Field: (p: KitFieldProps) => ReactNode;
  Check: (p: { label: string; hint?: string; checked: boolean; onChange: (on: boolean) => void }) => ReactNode;
  /** A sentence said at once (a refusal): role="alert". */
  Problem: (p: { children: ReactNode }) => ReactNode;
};

/** The words of the rows on both designs' pages: the same names in each, so both read the same to a screen reader. */
export const ROWS = {
  get name() { return t("Change name"); },
  get picture() { return t("Change picture"); },
  get removePicture() { return t("Remove picture"); },
  get email() { return t("Change email"); },
  get pendingEmail() { return t("Check your new address"); },
  get password() { return t("Change password"); },
  get setPassword() { return t("Set a password"); },
  get twoStepOn() { return t("Turn on two-step sign-in"); },
  get twoStepOff() { return t("Turn off two-step sign-in"); },
  get recoveryCodes() { return t("New recovery codes"); },
  get google() { return t("Unlink Google"); },
  get addPasskey() { return t("Add a passkey on the website"); },
  renamePasskey: (name: string) => t("Rename passkey :name", { name }),
  removePasskey: (name: string) => t("Remove passkey :name", { name }),
};

export type Flow =
  | { kind: "name" | "email" | "password" | "two_step_on" | "two_step_off" | "recovery_codes" | "google" | "remove_picture" }
  | { kind: "rename_passkey" | "remove_passkey"; passkey: SecurityInfo["passkeys"][number] };

type Done = (message: string, account?: Partial<Account>) => void;

/** The words of the window's language (English unless the page is Urdu), the same as every refusal in the dialog. */
const words = () => ACCOUNT_WORDS[accountLang()];

/** Opens one flow's dialog; `onDone` says what changed (the page shows it and reads the account again). */
export function AccountFlow({ kit, flow, account, onClose, onDone }: { kit: AccountKit; flow: Flow; account: Account; onClose: () => void; onDone: Done }) {
  switch (flow.kind) {
    case "name": return <NameDialog kit={kit} account={account} onClose={onClose} onDone={onDone} />;
    case "email": return <EmailDialog kit={kit} account={account} onClose={onClose} onDone={onDone} />;
    case "password": return <PasswordDialog kit={kit} account={account} onClose={onClose} onDone={onDone} />;
    case "two_step_on": return <TwoStepDialog kit={kit} account={account} onClose={onClose} onDone={onDone} />;
    case "recovery_codes": return <RecoveryCodesDialog kit={kit} account={account} onClose={onClose} onDone={onDone} />;
    case "two_step_off": return <ProofDialog kit={kit} account={{ ...account, two_factor: true }} action="two_factor_off" title="Turn off two-step sign-in?"
      description="Signing in will need only your password again. Type a code from your authenticator app, or a recovery code." confirm="Turn off" danger
      onClose={onClose} onDone={() => onDone("Two-step sign-in is off", { two_factor: false })} />;
    case "google": return <ProofDialog kit={kit} account={account} action="google_unlink" title="Unlink Google?"
      description="You won't be able to sign in with Google any more. Your password and passkeys still work." confirm="Unlink" danger
      onClose={onClose} onDone={() => onDone("Google is unlinked", { google: false })} />;
    case "remove_picture": return <RemovePictureDialog kit={kit} onClose={onClose} onDone={onDone} />;
    case "rename_passkey": return <PasskeyNameDialog kit={kit} passkey={flow.passkey} onClose={onClose} onDone={onDone} />;
    case "remove_passkey": return <ProofDialog kit={kit} account={account} action="passkey_remove" id={flow.passkey.id} title={`Remove ${flow.passkey.name}?`}
      description="You won't be able to sign in with this passkey any more." confirm="Remove" danger onClose={onClose} onDone={() => onDone("Passkey removed")} />;
  }
}

/** One request at a time, with its refusal kept: the busy flag, the last problem and the field errors. */
function useSend() {
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const send = async <T,>(fn: () => Promise<AccountReply<T>>): Promise<AccountReply<T> | null> => {
    if (busy) return null;
    setBusy(true);
    setProblem(null);
    setFields({});
    try {
      const reply = await fn();
      if (!reply.ok) {
        const byField = fieldErrors(reply);
        setFields(byField);
        if (!Object.keys(byField).length || reply.code !== "validation_failed") setProblem(replyText(reply));
      }
      return reply;
    } catch (e) {
      setProblem(failureText(e));
      return null;
    } finally {
      setBusy(false);
    }
  };
  return { busy, problem, fields, send, setProblem, setFields };
}

/**
 * The proof field this account gives: a two-step code, the password, or a code sent by email (with Send me a code).
 * `kind` can change when the server asks for another (`proof_required`, `invalid_otp` with `accepts`).
 */
function ProofField({ kit, kind, value, onChange, error, email, onSent }: {
  kit: AccountKit; kind: ProofKind; value: string; onChange: (v: string) => void; error?: string; email: string; onSent: (problem: string | null) => void;
}) {
  const [sent, setSent] = useState(false);
  const [sending, setSending] = useState(false);
  if (kind === "password") {
    return <kit.Field label="Your password" type="password" autoComplete="current-password" value={value} onChange={onChange} error={error} autoFocus />;
  }
  if (kind === "otp") {
    return <kit.Field label="Two-step code" inputMode="numeric" autoComplete="one-time-code" value={value} onChange={onChange} error={error} autoFocus
      hint="The 6-digit code from your authenticator app, or a recovery code." />;
  }
  const sendCode = async () => {
    setSending(true);
    try {
      const reply = await call("proof_code");
      setSent(reply.ok);
      onSent(reply.ok ? null : replyText(reply));
    } catch (e) {
      onSent(failureText(e));
    } finally {
      setSending(false);
    }
  };
  return (
    <>
      <kit.Field label="Code from your email" inputMode="numeric" autoComplete="one-time-code" value={value} onChange={onChange} error={error}
        hint={sent ? `We sent a 6-digit code to ${email}. It works for 10 minutes.` : `We'll send a 6-digit code to ${email}.`} />
      <kit.Button kind="quiet" busy={sending} busyLabel="Sending…" onClick={() => void sendCode()}>{sent ? "Send another code" : "Send me a code"}</kit.Button>
    </>
  );
}

/** The proof's state for a dialog: its kind, its value, and the switch a refusal asks for. */
function useProof(account: Account) {
  const [kind, setKind] = useState<ProofKind>(() => proofFor(account));
  const [value, setValue] = useState("");
  const after = (reply: AccountReply<unknown>) => {
    const next = proofAfter(reply, kind);
    if (next !== kind) {
      setKind(next);
      setValue("");
    }
  };
  return { kind, value, setValue, body: () => proofBody(kind, value), ready: value.trim().length > 0, after };
}

/** A change that needs only proof: turn off two-step, unlink Google, remove a passkey. */
function ProofDialog({ kit, account, action, id, title, description, confirm, danger, onClose, onDone }: {
  kit: AccountKit; account: Account; action: "two_factor_off" | "google_unlink" | "passkey_remove"; id?: string; title: string; description: string;
  confirm: string; danger?: boolean; onClose: () => void; onDone: () => void;
}) {
  const proof = useProof(account);
  const { busy, problem, send, setProblem } = useSend();
  const formId = useId();
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const reply = await send(() => call(action, proof.body(), id));
    if (!reply) return;
    if (reply.ok) onDone();
    else proof.after(reply);
  };
  return (
    <kit.Dialog title={title} description={description} onClose={onClose} busy={busy} alert={danger}
      actions={<>
        <kit.Button onClick={onClose} disabled={busy}>Cancel</kit.Button>
        <kit.Button kind={danger ? "danger" : "primary"} type="submit" form={formId} busy={busy} busyLabel="Working…" disabled={!proof.ready}>{confirm}</kit.Button>
      </>}>
      <form id={formId} className="account-form" onSubmit={(e) => void submit(e)} noValidate>
        <ProofField kit={kit} kind={proof.kind} value={proof.value} onChange={proof.setValue} email={account.email} onSent={setProblem} />
        {problem && <kit.Problem>{problem}</kit.Problem>}
      </form>
    </kit.Dialog>
  );
}

/** Change name: checked here first (letters of any script, spaces and . ' - ( ) ,), then saved. */
function NameDialog({ kit, account, onClose, onDone }: { kit: AccountKit; account: Account; onClose: () => void; onDone: Done }) {
  const [name, setName] = useState(account.name);
  const [touched, setTouched] = useState(false);
  const { busy, problem, fields, send } = useSend();
  const formId = useId();
  const local = nameProblem(name);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setTouched(true);
    if (local) return;
    const reply = await send<Account>(() => call<Account>("name", { name: name.trim() }));
    if (reply?.ok) onDone("Name changed", { name: reply.data?.name ?? name.trim() });
  };
  const error = (touched && local ? fieldText(local) : null) || fields.name;
  return (
    <kit.Dialog title="Change name" onClose={onClose} busy={busy}
      actions={<>
        <kit.Button onClick={onClose} disabled={busy}>Cancel</kit.Button>
        <kit.Button kind="primary" type="submit" form={formId} busy={busy} busyLabel="Saving…" disabled={!name.trim() || name.trim() === account.name}>Save</kit.Button>
      </>}>
      <form id={formId} className="account-form" onSubmit={(e) => void submit(e)} noValidate>
        <kit.Field label="Name" value={name} onChange={(v) => { setName(v); setTouched(true); }} autoComplete="name" maxLength={160} error={error} autoFocus
          hint="This is the name your teammates see." />
        {problem && <kit.Problem>{problem}</kit.Problem>}
      </form>
    </kit.Dialog>
  );
}

/** Remove picture: asked first; the initials show again. */
function RemovePictureDialog({ kit, onClose, onDone }: { kit: AccountKit; onClose: () => void; onDone: Done }) {
  const { busy, problem, send } = useSend();
  const remove = async () => {
    const reply = await send(() => call("avatar_remove"));
    if (reply?.ok) onDone("Picture removed", { avatar_url: null, avatar_version: null });
  };
  return (
    <kit.Dialog title="Remove your picture?" description="Your initials show instead, everywhere." onClose={onClose} busy={busy} alert
      actions={<>
        <kit.Button onClick={onClose} disabled={busy}>Cancel</kit.Button>
        <kit.Button kind="danger" busy={busy} busyLabel="Removing…" onClick={() => void remove()}>Remove</kit.Button>
      </>}>
      {problem && <kit.Problem>{problem}</kit.Problem>}
    </kit.Dialog>
  );
}

/** How often "Check your new address" asks whether the change was confirmed in a browser. */
export const EMAIL_POLL_MS = 10_000;

/**
 * Change email: the new address and proof, then the 6-digit code sent to it. A change confirmed by the link in a browser
 * is noticed by reading the account every 10 seconds while this is open.
 */
function EmailDialog({ kit, account, onClose, onDone }: { kit: AccountKit; account: Account; onClose: () => void; onDone: Done }) {
  const [step, setStep] = useState<"address" | "code">(account.pending_email ? "code" : "address");
  const [pending, setPending] = useState(account.pending_email ?? "");
  const [email, setEmail] = useState("");
  const [code, setCode] = useState("");
  const [touched, setTouched] = useState(false);
  const proof = useProof(account);
  const { busy, problem, fields, send, setProblem } = useSend();
  const formId = useId();

  useEffect(() => {
    if (step !== "code") return;
    const timer = window.setInterval(() => {
      api.account().then((a) => {
        if (!a) return;
        if (!a.pending_email && a.email.toLowerCase() === pending.toLowerCase()) onDone("Email changed", { email: a.email, pending_email: null });
      }).catch(() => undefined);
    }, EMAIL_POLL_MS);
    return () => window.clearInterval(timer);
  }, [step, pending, onDone]);

  const start = async (e: FormEvent) => {
    e.preventDefault();
    setTouched(true);
    if (!looksLikeEmail(email)) return;
    const reply = await send<{ pending_email: string }>(() => call("email", { email: email.trim(), ...proof.body() }));
    if (!reply) return;
    if (reply.ok) {
      setPending(reply.data?.pending_email ?? email.trim());
      setStep("code");
    } else proof.after(reply);
  };
  const confirmCode = async (e: FormEvent) => {
    e.preventDefault();
    const reply = await send<Account>(() => call<Account>("email_confirm", { code: cleanCode(code) }));
    if (!reply) return;
    if (reply.ok) onDone("Email changed", { email: reply.data?.email ?? pending, pending_email: null });
    else if (reply.code === "expired") {
      setStep("address");
      setCode("");
    }
  };
  const cancelChange = async () => {
    const reply = await send(() => call("email_cancel"));
    if (reply?.ok) onDone("Email change cancelled", { pending_email: null });
  };

  if (step === "code") {
    return (
      <kit.Dialog title="Check your new address" description={`We sent a 6-digit code to ${pending}. Type it here, or open the link in that email.`} onClose={onClose} busy={busy}
        actions={<>
          <kit.Button kind="quiet" onClick={() => void cancelChange()} disabled={busy}>Cancel the change</kit.Button>
          <kit.Button onClick={onClose} disabled={busy}>Later</kit.Button>
          <kit.Button kind="primary" type="submit" form={formId} busy={busy} busyLabel="Checking…" disabled={cleanCode(code).length < 6}>Confirm</kit.Button>
        </>}>
        <form id={formId} className="account-form" onSubmit={(e) => void confirmCode(e)} noValidate>
          <kit.Field label="Code from the email" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={setCode} maxLength={12} error={fields.code} autoFocus
            hint="The code works for 60 minutes. Your email changes once it's confirmed." />
          {problem && <kit.Problem>{problem}</kit.Problem>}
        </form>
      </kit.Dialog>
    );
  }
  const emailError = (touched && email.trim() && !looksLikeEmail(email) ? words().field_invalid_email : null) || fields.email;
  return (
    <kit.Dialog title="Change email" description={`Your email is ${account.email} now. We'll send a code to the new address, and tell the old one.`} onClose={onClose} busy={busy}
      actions={<>
        <kit.Button onClick={onClose} disabled={busy}>Cancel</kit.Button>
        <kit.Button kind="primary" type="submit" form={formId} busy={busy} busyLabel="Sending…" disabled={!email.trim() || !proof.ready}>Send code</kit.Button>
      </>}>
      <form id={formId} className="account-form" onSubmit={(e) => void start(e)} noValidate>
        <kit.Field label="New email" type="email" inputMode="email" autoComplete="email" value={email} onChange={(v) => { setEmail(v); setTouched(false); }} error={emailError} autoFocus />
        <ProofField kit={kit} kind={proof.kind} value={proof.value} onChange={proof.setValue} email={account.email} onSent={setProblem}
          error={fields.password ?? fields.otp ?? fields.email_code} />
        {problem && <kit.Problem>{problem}</kit.Problem>}
      </form>
    </kit.Dialog>
  );
}

/** The password rules this server says (`GET /api/auth/options`), or the usual ones. */
function usePasswordRules(): PasswordRules {
  const [rules, setRules] = useState<PasswordRules>(DEFAULT_PASSWORD_RULES);
  useEffect(() => {
    let live = true;
    api.settings().then((s) => (s.serverUrl ? api.authOptions(s.serverUrl) : null)).then((o) => {
      if (live && o?.passwordRules?.min) setRules(o.passwordRules);
    }).catch(() => undefined);
    return () => {
      live = false;
    };
  }, []);
  return rules;
}

/**
 * Change password (or set a first one, for an account made with Google): the current password with a two-step code
 * when two-step is on, the new one checked against the server's rules first, and "Sign out other devices" on.
 */
function PasswordDialog({ kit, account, onClose, onDone }: { kit: AccountKit; account: Account; onClose: () => void; onDone: Done }) {
  const rules = usePasswordRules();
  const hasPassword = account.has_password !== false;
  const [current, setCurrent] = useState("");
  const [otp, setOtp] = useState("");
  const [next, setNext] = useState("");
  const [signOutOthers, setSignOutOthers] = useState(true);
  const [touched, setTouched] = useState(false);
  // Without a password the proof is a two-step code or a code by email.
  const proof = useProof({ ...account, has_password: false });
  const { busy, problem, fields, send, setProblem } = useSend();
  const formId = useId();
  const needsOtp = account.two_factor;
  const local = passwordProblems(next, rules);
  const ready = next.length > 0 && (hasPassword ? current.length > 0 && (!needsOtp || cleanCode(otp).length > 0) : proof.ready);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setTouched(true);
    if (local.length) return;
    const body: Record<string, unknown> = { new: next, sign_out_others: signOutOthers };
    if (hasPassword) {
      body.current = current;
      if (needsOtp) body.otp = cleanCode(otp);
    } else Object.assign(body, proof.body());
    const reply = await send(() => call("password", body));
    if (!reply) return;
    if (reply.ok) onDone(hasPassword ? "Password changed" : "Password set", { has_password: true });
    else if (!hasPassword) proof.after(reply);
  };
  const newError = (touched && next && local.length ? fieldText(local[0]) : null) || fields.new;
  return (
    <kit.Dialog title={hasPassword ? "Change password" : "Set a password"} onClose={onClose} busy={busy}
      actions={<>
        <kit.Button onClick={onClose} disabled={busy}>Cancel</kit.Button>
        <kit.Button kind="primary" type="submit" form={formId} busy={busy} busyLabel="Saving…" disabled={!ready}>{hasPassword ? "Change password" : "Set password"}</kit.Button>
      </>}>
      <form id={formId} className="account-form" onSubmit={(e) => void submit(e)} noValidate>
        {hasPassword ? (
          <>
            <kit.Field label="Current password" type="password" autoComplete="current-password" value={current} onChange={setCurrent} error={fields.current} autoFocus />
            {needsOtp && <kit.Field label="Two-step code" inputMode="numeric" autoComplete="one-time-code" value={otp} onChange={setOtp} error={fields.otp}
              hint="The 6-digit code from your authenticator app, or a recovery code." />}
          </>
        ) : (
          <ProofField kit={kit} kind={proof.kind} value={proof.value} onChange={proof.setValue} email={account.email} onSent={setProblem} />
        )}
        <kit.Field label="New password" type="password" autoComplete="new-password" value={next} onChange={(v) => { setNext(v); setTouched(false); }} error={newError}
          hint={rulesLine(rules)} />
        <kit.Check label="Sign out other devices and browsers" hint="This computer stays signed in." checked={signOutOthers} onChange={setSignOutOthers} />
        {problem && <kit.Problem>{problem}</kit.Problem>}
      </form>
    </kit.Dialog>
  );
}

/** Two-step sign-in's QR code, drawn on a canvas from the core's rows of modules (a quiet zone of 4 around it). */
export function QrCode({ rows, label }: { rows: string[]; label: string }) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const size = rows.length + 8;
  useEffect(() => {
    const ctx = canvas.current?.getContext?.("2d");
    if (!ctx) return;
    ctx.fillStyle = "white";
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = "black";
    rows.forEach((row, y) => [...row].forEach((c, x) => {
      if (c === "1") ctx.fillRect(x + 4, y + 4, 1, 1);
    }));
  }, [rows, size]);
  return <canvas ref={canvas} className="account-qr" role="img" aria-label={label} width={size} height={size} />;
}

/** The recovery codes, shown once: Copy and Save, then "I've saved them". */
function RecoveryCodes({ kit, codes, email, title, onDone }: { kit: AccountKit; codes: string[]; email: string; title: string; onDone: () => void }) {
  const [said, setSaid] = useState<string | null>(null);
  // Through the core: marked so clipboard history and managers keep no copy, and taken off the clipboard after a minute.
  const copy = async () => {
    try {
      await api.copyRecoveryCodes(codes);
      setSaid(sentence("codes_copied"));
    } catch {
      setSaid(sentence("codes_copy_failed"));
    }
  };
  const save = async () => {
    try {
      const path = await api.saveRecoveryCodes(codes, email);
      if (path) setSaid(sentence("codes_saved", { path }));
    } catch (e) {
      setSaid(failureText(e));
    }
  };
  return (
    <kit.Dialog title={title} description="Each code works once if you lose your phone. Keep them somewhere safe: they won't be shown again." onClose={onDone}
      actions={<>
        <kit.Button onClick={() => void copy()}>Copy codes</kit.Button>
        <kit.Button onClick={() => void save()}>Save as a file…</kit.Button>
        <kit.Button kind="primary" onClick={onDone}>I've saved them</kit.Button>
      </>}>
      <ol className="account-codes" aria-label="Recovery codes">
        {codes.map((c) => <li key={c} dir="ltr"><code>{c}</code></li>)}
      </ol>
      {said && <p className="account-said" role="status">{said}</p>}
    </kit.Dialog>
  );
}

/**
 * Turn on two-step sign-in: proof (the password, or a code by email), then the QR code and the key to type by hand, then
 * a code from the app, then the 8 recovery codes once.
 */
function TwoStepDialog({ kit, account, onClose, onDone }: { kit: AccountKit; account: Account; onClose: () => void; onDone: Done }) {
  const proof = useProof({ ...account, two_factor: false });
  const [start, setStart] = useState<(TwoFactorStart & { qr: string[] | null }) | null>(null);
  const [code, setCode] = useState("");
  const [codes, setCodes] = useState<string[] | null>(null);
  const { busy, problem, fields, send, setProblem } = useSend();
  const formId = useId();
  const begin = async (e: FormEvent) => {
    e.preventDefault();
    const reply = await send<TwoFactorStart>(() => call<TwoFactorStart>("two_factor_begin", proof.body()));
    if (!reply) return;
    if (reply.ok && reply.data) setStart({ ...reply.data, qr: reply.qr });
    else proof.after(reply);
  };
  const confirmCode = async (e: FormEvent) => {
    e.preventDefault();
    if (!start) return;
    const reply = await send<{ recovery_codes: string[] }>(() => call("two_factor_confirm", { ceremony: start.ceremony, code: cleanCode(code) }));
    if (reply?.ok) setCodes(reply.data?.recovery_codes ?? []);
  };
  if (codes) {
    return <RecoveryCodes kit={kit} codes={codes} email={account.email} title="Two-step sign-in is on" onDone={() => onDone("Two-step sign-in is on", { two_factor: true })} />;
  }
  if (start) {
    return (
      <kit.Dialog title="Scan this code" description="Open your authenticator app (Google Authenticator, Microsoft Authenticator, 1Password…) and add an account with this QR code." onClose={onClose} busy={busy}
        actions={<>
          <kit.Button onClick={onClose} disabled={busy}>Cancel</kit.Button>
          <kit.Button kind="primary" type="submit" form={formId} busy={busy} busyLabel="Checking…" disabled={cleanCode(code).length < 6}>Turn on</kit.Button>
        </>}>
        <form id={formId} className="account-form" onSubmit={(e) => void confirmCode(e)} noValidate>
          {start.qr && <QrCode rows={start.qr} label="QR code for your authenticator app" />}
          <p className="account-key">Can't scan it? Type this key in the app: <code dir="ltr" data-testid="two-step-key">{spacedSecret(start.secret)}</code></p>
          <kit.Field label="Code from the app" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={setCode} maxLength={8} error={fields.code} autoFocus
            hint="Type the 6-digit code the app shows for EchoLedger." />
          {problem && <kit.Problem>{problem}</kit.Problem>}
        </form>
      </kit.Dialog>
    );
  }
  return (
    <kit.Dialog title="Turn on two-step sign-in" description="Signing in will also need a code from an authenticator app on your phone. First, confirm it's you." onClose={onClose} busy={busy}
      actions={<>
        <kit.Button onClick={onClose} disabled={busy}>Cancel</kit.Button>
        <kit.Button kind="primary" type="submit" form={formId} busy={busy} busyLabel="Working…" disabled={!proof.ready}>Continue</kit.Button>
      </>}>
      <form id={formId} className="account-form" onSubmit={(e) => void begin(e)} noValidate>
        <ProofField kit={kit} kind={proof.kind} value={proof.value} onChange={proof.setValue} email={account.email} onSent={setProblem} />
        {problem && <kit.Problem>{problem}</kit.Problem>}
      </form>
    </kit.Dialog>
  );
}

/** New recovery codes: a two-step code (never the password alone); the old codes stop working. */
function RecoveryCodesDialog({ kit, account, onClose, onDone }: { kit: AccountKit; account: Account; onClose: () => void; onDone: Done }) {
  const [otp, setOtp] = useState("");
  const [codes, setCodes] = useState<string[] | null>(null);
  const { busy, problem, send } = useSend();
  const formId = useId();
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const reply = await send<{ recovery_codes: string[] }>(() => call("recovery_codes", { otp: cleanCode(otp) }));
    if (reply?.ok) setCodes(reply.data?.recovery_codes ?? []);
  };
  if (codes) return <RecoveryCodes kit={kit} codes={codes} email={account.email} title="Your new recovery codes" onDone={() => onDone("New recovery codes made")} />;
  return (
    <kit.Dialog title="Make new recovery codes?" description="Your old recovery codes stop working. Type a code from your authenticator app, or one of your recovery codes." onClose={onClose} busy={busy}
      actions={<>
        <kit.Button onClick={onClose} disabled={busy}>Cancel</kit.Button>
        <kit.Button kind="primary" type="submit" form={formId} busy={busy} busyLabel="Working…" disabled={!otp.trim()}>Make new codes</kit.Button>
      </>}>
      <form id={formId} className="account-form" onSubmit={(e) => void submit(e)} noValidate>
        <kit.Field label="Two-step code" inputMode="numeric" autoComplete="one-time-code" value={otp} onChange={setOtp} autoFocus />
        {problem && <kit.Problem>{problem}</kit.Problem>}
      </form>
    </kit.Dialog>
  );
}

/** Rename a passkey: a person's name rules, up to 80 characters. */
function PasskeyNameDialog({ kit, passkey, onClose, onDone }: { kit: AccountKit; passkey: SecurityInfo["passkeys"][number]; onClose: () => void; onDone: Done }) {
  const [name, setName] = useState(passkey.name);
  const { busy, problem, fields, send } = useSend();
  const formId = useId();
  const local = nameProblem(name, PASSKEY_NAME_MAX);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (local) return;
    const reply = await send(() => call("passkey_rename", { name: name.trim() }, passkey.id));
    if (reply?.ok) onDone("Passkey renamed");
  };
  return (
    <kit.Dialog title="Rename passkey" onClose={onClose} busy={busy}
      actions={<>
        <kit.Button onClick={onClose} disabled={busy}>Cancel</kit.Button>
        <kit.Button kind="primary" type="submit" form={formId} busy={busy} busyLabel="Saving…" disabled={!name.trim()}>Save</kit.Button>
      </>}>
      <form id={formId} className="account-form" onSubmit={(e) => void submit(e)} noValidate>
        <kit.Field label="Passkey name" value={name} onChange={setName} maxLength={100} error={(name.trim() && local ? fieldText(local) : null) || fields.name} autoFocus />
        {problem && <kit.Problem>{problem}</kit.Problem>}
      </form>
    </kit.Dialog>
  );
}

// ---- What the pages share ------------------------------------------------------------------------------------------

/** The account's sign-in methods and passkeys (`GET /api/account/security`); null on a server before 2.0.0-l. */
export function useSecurityInfo(): { info: SecurityInfo | null | undefined; reload: () => void } {
  const [info, setInfo] = useState<SecurityInfo | null | undefined>(undefined);
  const [seq, setSeq] = useState(0);
  useEffect(() => {
    let live = true;
    call<SecurityInfo>("security").then((r) => live && setInfo(r.ok ? r.data : null)).catch(() => live && setInfo(null));
    return () => {
      live = false;
    };
  }, [seq]);
  return { info, reload: () => setSeq((n) => n + 1) };
}

/** The person's picture as the window may show it, or null for the initials (kept on this computer by version). */
export function useAvatar(account: Pick<Account, "avatar_url" | "avatar_version"> | null | undefined): string | null {
  const [src, setSrc] = useState<string | null>(null);
  const version = account?.avatar_version ?? null;
  const url = account?.avatar_url ?? null;
  useEffect(() => {
    let live = true;
    if (version == null) {
      setSrc(null);
      return;
    }
    api.avatarImage(url, version).then((s) => live && setSrc(s ?? null)).catch(() => live && setSrc(null));
    return () => {
      live = false;
    };
  }, [url, version]);
  return src;
}

/** Change picture…: the core's picker and upload; what to say afterwards (null: the person cancelled). */
export async function choosePicture(): Promise<{ ok: boolean; message: string; account?: Partial<Account> } | null> {
  try {
    const reply = (await api.chooseAvatar()) as AccountReply<{ avatar_url: string; avatar_version: number }> | null;
    if (!reply) return null;
    if (reply.ok && reply.data) return { ok: true, message: "Picture changed", account: { avatar_url: reply.data.avatar_url, avatar_version: reply.data.avatar_version } };
    return { ok: false, message: replyText(reply) };
  } catch (e) {
    return { ok: false, message: failureText(e) };
  }
}

/** Which flows the page offers, from the account and its sign-in methods. */
export function offers(account: Account, info: SecurityInfo | null | undefined) {
  const twoStep = info?.two_factor.enabled ?? account.two_factor;
  return {
    twoStep,
    recoveryLeft: info?.two_factor.recovery_codes_left ?? null,
    google: info?.google.linked ?? account.google,
    passkeys: info?.passkeys ?? [],
    hasPassword: (info?.password ?? account.has_password) !== false,
    /** The server serves the 2.0.0-l routes: the changes are made here, not on the website. */
    native: info !== null && info !== undefined,
  };
}

/** One flow open at a time for a page, with the message after it and the account it changed. */
export function useFlows(onAccount: (patch: Partial<Account>) => void, onChanged: () => void) {
  const [flow, setFlow] = useState<Flow | null>(null);
  const [said, setSaid] = useState<string | null>(null);
  const live = useRef(true);
  useEffect(() => () => {
    live.current = false;
  }, []);
  const done: Done = (message, patch) => {
    setFlow(null);
    if (patch) onAccount(patch);
    if (live.current) setSaid(message);
    onChanged();
  };
  return { flow, open: setFlow, close: () => setFlow(null), done, said, say: setSaid };
}
