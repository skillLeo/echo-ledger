// Settings › Account (since v1.5, rows of v1.7, docs/API.md "Your account"): Profile, Sign-in & security, and Privacy &
// data (recording consent, Export my data, Delete my account). A server without accounts answers none, and only what
// this computer holds (the consent notice) stays.
import { useEffect, useId, useRef, useState } from "react";
import { api, errorMessage, type Account, type AccountExport, type AppStatus, type Settings, type SettingsPatch, type WorkspaceRole } from "../../lib/ipc";
import { ROLE_LABEL } from "../../lib/plan";
import { rowDateWithYear, sentenceDate } from "../../lib/format";
import { ConsentNotice } from "./ConsentNotice";
import { Sheet } from "./ProjectDialogs";
import { ActionRow, DestructiveRow, InfoRow, LinkRow, Section } from "./SettingsControls";

/** How often a data export that is being made is asked about. */
export const EXPORT_POLL_MS = 5000;

type Run = <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;

/** "6 November 2026" for an ISO instant; the text itself when it is not one. */
export function longDate(iso: string | null | undefined): string {
  if (!iso) return "";
  return sentenceDate(iso) || iso;
}

/** The account as the server tells it, read when Settings opens: undefined while loading, null on an older server. */
export function useAccount(): [Account | null | undefined, (a: Account | null) => void, () => void] {
  const [account, setAccount] = useState<Account | null | undefined>(undefined);
  const [seq, setSeq] = useState(0);
  useEffect(() => {
    let alive = true;
    api.account().then((a) => alive && setAccount(a ?? null), () => alive && setAccount((known) => known ?? null));
    return () => {
      alive = false;
    };
  }, [seq]);
  return [account, setAccount, () => setSeq((n) => n + 1)];
}

/** The person's role in the workspace, from the account or the plan. */
export function roleOf(account: Account | null | undefined, status: Pick<AppStatus, "plan">): WorkspaceRole | null {
  const role = account?.workspace?.role ?? status.plan.role;
  return role && role in ROLE_LABEL ? (role as WorkspaceRole) : null;
}

/** "Acme, Admin" for a team workspace; none for one's own. */
export function workspaceLine(account: Account | null | undefined, status: Pick<AppStatus, "plan">): string | null {
  const workspace = account?.workspace?.name || status.plan.workspace;
  const role = roleOf(account, status);
  const team = (account?.workspaces?.length ?? 0) > 1 || (role !== null && role !== "owner") || (status.plan.seats ?? 1) > 1;
  if (!workspace || !team) return null;
  return role ? `${workspace}, ${ROLE_LABEL[role]}` : workspace;
}

/** Account › Profile: who is signed in. Name and email are changed on the website. */
export function ProfilePage({ account, status, run }: { account: Account; status: AppStatus; run: Run }) {
  const workspace = workspaceLine(account, status);
  return (
    <div className="account-card">
      <Section footer={<p>Your name and email are changed on the website.</p>}>
        <InfoRow id="profile.name" value={account.name || account.email.split("@")[0]} />
        <InfoRow id="profile.email" value={`${account.email}${account.email_verified ? "" : " (not verified)"}`} />
        {workspace && <InfoRow id="profile.workspace" value={workspace} />}
        {account.created_at && <InfoRow id="profile.since" value={rowDateWithYear(account.created_at)} />}
        <LinkRow id="profile.edit" external onOpen={() => void run(() => api.openServerPage("app/profile"))} />
      </Section>
    </div>
  );
}

/** Account › Sign-in & security. The password and two-step sign-in are changed on the website. */
export function SecurityPage({ account, run, onSignedOut, onActivity }: { account: Account; run: Run; onSignedOut: (s: AppStatus) => void; onActivity: () => void }) {
  const [confirming, setConfirming] = useState(false);
  const web = () => void run(() => api.openServerPage("app/profile"));
  const signOutEverywhere = async () => {
    setConfirming(false);
    const signedOut = await run(api.signOutEverywhere, "Signed out everywhere");
    if (signedOut) onSignedOut(signedOut);
  };
  return (
    <>
      <Section footer={<p>Sign-in codes go to {account.email}.</p>}>
        <LinkRow id="security.password" external onOpen={web} />
        <LinkRow id="security.two_step" value={account.two_factor ? "On" : "Off"} external onOpen={web} />
        <InfoRow id="security.google" value={account.google ? "Connected" : "Not connected"} />
        <LinkRow id="security.activity" onOpen={onActivity} chevron />
      </Section>
      <Section>
        <DestructiveRow id="security.signout_all" onClick={() => setConfirming(true)} />
      </Section>
      {confirming && (
        <Sheet title="Sign out everywhere?" onClose={() => setConfirming(false)}>
          <p>You'll be signed out on every phone, computer and browser, including this one.</p>
          <div className="row sheet-actions">
            <span className="spacer" />
            <button type="button" className="btn ghost" onClick={() => setConfirming(false)}>Cancel</button>
            <button type="button" className="btn danger solid" onClick={() => void signOutEverywhere()}>Sign out everywhere</button>
          </div>
        </Sheet>
      )}
    </>
  );
}

/** Privacy & data › Recording consent: the notice, then "I understand". It is never a switch. */
export function ConsentPage({ status, settings, patch }: { status: AppStatus; settings: Settings; patch: (p: SettingsPatch) => Promise<boolean> }) {
  return (
    <div className="set-prose">
      <ConsentNotice legal={status.plan.known && status.paired} />
      {settings.consentAcknowledged
        ? <p className="small muted" data-testid="consent-done">You've read this notice.</p>
        : <button type="button" className="btn primary" onClick={() => void patch({ consentAcknowledged: true })}>I understand</button>}
    </div>
  );
}

/** Account › Privacy & data: recording consent, Export my data and Delete my account. */
export function PrivacyPage({ account, settings, onAccount, onConsent }: {
  account: Account | null;
  settings: Settings;
  onAccount: (a: Account) => void;
  onConsent: () => void;
}) {
  const [exporting, setExporting] = useState<AccountExport | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState(false);
  const opened = useRef<string | null>(null);

  // A making export is asked about every 5 seconds; a ready one opens its link once. An error stops the asking.
  const making = exporting?.status === "making" ? exporting.id : null;
  useEffect(() => {
    if (!making) return;
    const timer = window.setInterval(() => {
      api.exportStatus(making).then(setExporting, (e) => {
        setExporting(null);
        setExportError(errorMessage(e));
      });
    }, EXPORT_POLL_MS);
    return () => window.clearInterval(timer);
  }, [making]);
  useEffect(() => {
    if (exporting?.status !== "ready" || !exporting.url || opened.current === exporting.id) return;
    opened.current = exporting.id;
    api.openExport(exporting.url).catch((e) => setExportError(errorMessage(e)));
  }, [exporting]);

  const startExport = async () => {
    setExportError(null);
    try {
      setExporting(await api.startExport());
    } catch (e) {
      setExportError(errorMessage(e));
    }
  };
  const exportValue = exporting?.status === "making" ? "Making your export…" : exporting?.status === "ready" ? "Download your export" : exporting?.status === "failed" ? "Couldn't make the export. Try again" : undefined;

  return (
    <>
      <Section>
        <LinkRow id="privacy.consent" value={settings.consentAcknowledged ? "Acknowledged" : "Not yet"} onOpen={onConsent} chevron />
      </Section>
      {account && (
        <Section footer={<>
          <p>One ZIP with every recording's audio, transcripts, screens, project files and notes. The link works for 24 hours and is emailed to you too.</p>
          {exportError && <p className="error-text" role="alert">{exportError}</p>}
          {account.deletes_at
            ? <p>Your account will be deleted on {longDate(account.deletes_at)}. To keep it, sign in on the website and choose Keep my account.</p>
            : <p>Your account and everything only you own are deleted after 7 days. Signing in again before then and choosing Keep my account cancels it.</p>}
        </>}>
          <ActionRow id="privacy.export" value={exportValue} disabled={exporting?.status === "making"}
            onClick={() => (exporting?.status === "ready" && exporting.url ? void api.openExport(exporting.url).catch((e) => setExportError(errorMessage(e))) : void startExport())} />
          {account.deletes_at
            ? <LinkRow id="privacy.cancel_deletion" value={`Deletes on ${rowDateWithYear(account.deletes_at)}`} external onOpen={() => void api.openServerPage("app/profile").catch(() => undefined)} />
            : <DestructiveRow id="privacy.delete_account" onClick={() => setDeleting(true)} />}
        </Section>
      )}
      {deleting && account && <DeleteAccountDialog account={account} onAccount={onAccount} onClose={() => setDeleting(false)} />}
    </>
  );
}

/** Delete my account: the password or a two-step code, or the web page instead. */
function DeleteAccountDialog({ account, onAccount, onClose }: { account: Account; onAccount: (a: Account) => void; onClose: () => void }) {
  const [useCode, setUseCode] = useState(false);
  const [secret, setSecret] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const secretId = useId();
  const confirm = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const deletesAt = await api.deleteAccount(useCode ? null : secret, useCode ? secret.trim() : null);
      const fresh = deletesAt ? null : await api.account().catch(() => null);
      onAccount({ ...account, deletes_at: deletesAt ?? fresh?.deletes_at ?? account.deletes_at });
      onClose();
    } catch (err) {
      setError(errorMessage(err));
      setBusy(false);
    }
  };
  return (
    <Sheet title="Delete your account?" onClose={onClose} busy={busy}>
      <form className="column account-delete" onSubmit={(e) => void confirm(e)} aria-label="Confirm deleting your account">
        <p>We delete your account and everything only you own after 7 days. You can cancel by signing in before then.</p>
        <label htmlFor={secretId} className="small muted">{useCode ? "Two-step code" : "Your password"}</label>
        <input id={secretId} className="input" type={useCode ? "text" : "password"} inputMode={useCode ? "numeric" : undefined}
          autoComplete={useCode ? "one-time-code" : "current-password"} value={secret} onChange={(e) => setSecret(e.target.value)} required autoFocus />
        {account.two_factor && (
          <button type="button" className="btn text compact" onClick={() => { setUseCode((c) => !c); setSecret(""); }}>
            {useCode ? "Use my password instead" : "Use a two-step code instead"}
          </button>
        )}
        {error && <p className="small error-text" role="alert">{error}</p>}
        <button type="button" className="btn text compact" onClick={() => void api.openServerPage("account/delete").catch(() => undefined)}>Delete on the website</button>
        <div className="row sheet-actions">
          <span className="spacer" />
          <button type="button" className="btn ghost" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="btn danger solid" disabled={busy || !secret.trim()}>{busy ? "Deleting…" : "Delete my account"}</button>
        </div>
      </form>
    </Sheet>
  );
}
