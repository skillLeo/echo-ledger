// Settings → Account (since v1.5, docs/API.md "Your account", "Plans and features"): who is signed in, the workspace
// and role, two-factor and Google; the plan with its limits next to the usage; Export my data, Sign out everywhere and
// Delete my account. A server without accounts answers none and the group is not shown.
import { useEffect, useId, useRef, useState } from "react";
import { api, errorMessage, type Account, type AccountExport, type AppStatus, type WorkspaceRole } from "../../lib/ipc";
import { fillWidth } from "../../lib/motion";
import { minutesLine, percentUsed, planFeatures, ROLE_LABEL, storageLine, viewerSentence } from "../../lib/plan";

/** How often a data export that is being made is asked about. */
export const EXPORT_POLL_MS = 5000;
const SIGN_OUT_EVERYWHERE_CONFIRM =
  "Sign out everywhere? Every computer, phone and browser signed in to your account is signed out, this computer too. Recordings waiting here upload once you sign in again with this account.";

type Run = <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;

/** "6 November 2026" for an ISO instant; the text itself when it is not one. */
export function longDate(iso: string | null | undefined): string {
  if (!iso) return "";
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? iso : at.toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" });
}

/** The account as the server tells it, read when Settings opens: undefined while loading, null on an older server. */
export function useAccount(): [Account | null | undefined, (a: Account | null) => void] {
  const [account, setAccount] = useState<Account | null | undefined>(undefined);
  useEffect(() => {
    let alive = true;
    api.account().then((a) => alive && setAccount(a ?? null), () => alive && setAccount(null));
    return () => {
      alive = false;
    };
  }, []);
  return [account, setAccount];
}

function roleOf(account: Account, status: AppStatus): WorkspaceRole | null {
  const role = account.workspace?.role ?? status.plan.role;
  return role && role in ROLE_LABEL ? (role as WorkspaceRole) : null;
}

/** Profile (v1.6 Account tab): who is signed in, the workspace and role, and since when. */
export function AccountCard({ account, status }: { account: Account; status: AppStatus }) {
  const role = roleOf(account, status);
  const workspace = account.workspace?.name || status.plan.workspace;
  return (
    <div className="card account-card">
      <b>Your account</b>
      {account.deletes_at && (
        <div className="notice error small" role="status">
          Your account will be deleted on {longDate(account.deletes_at)}. To keep it, sign in again in the browser and choose Keep my account.
        </div>
      )}
      <dl className="account-facts">
        <div><dt>Name</dt><dd>{account.name || "—"}</dd></div>
        <div><dt>Email</dt><dd>{account.email}{account.email_verified ? "" : " (not verified yet)"}</dd></div>
        <div><dt>Workspace</dt><dd>{workspace || "—"}{role ? ` · ${ROLE_LABEL[role]}` : ""}</dd></div>
        {account.created_at && <div><dt>Member since</dt><dd>{longDate(account.created_at)}</dd></div>}
      </dl>
      {role === "viewer" && <div className="notice small" role="note">{viewerSentence(workspace)}</div>}
      <p className="small muted">Your name and email are changed in the console, under Profile.</p>
    </div>
  );
}

/** Password & sign-in methods (v1.6 Account tab): the password, two-factor and Google, and Sign out everywhere. */
export function SignInMethodsCard({ account, run, onSignedOut }: { account: Account; run: Run; onSignedOut: (s: AppStatus) => void }) {
  const signOutEverywhere = async () => {
    if (!window.confirm(SIGN_OUT_EVERYWHERE_CONFIRM)) return;
    const signedOut = await run(api.signOutEverywhere, "Signed out everywhere");
    if (signedOut) onSignedOut(signedOut);
  };
  const security = () => void run(() => api.openServerPage("admin/profile"));
  return (
    <div className="card account-card">
      <b>Password &amp; sign-in methods</b>
      <dl className="account-facts">
        <div><dt>Email codes</dt><dd>On: a 6-digit code to {account.email}</dd></div>
        <div><dt>Two-factor sign-in</dt><dd>{account.two_factor ? "On" : "Off"}</dd></div>
        <div><dt>Google</dt><dd>{account.google ? "Connected" : "Not connected"}</dd></div>
      </dl>
      <div className="row organise-field">
        <span className="small muted">Change your password or turn two-factor sign-in on in the browser.</span>
        <span className="spacer" />
        <button type="button" className="btn ghost" onClick={security}>Open Profile in the browser</button>
      </div>
      <div className="row organise-field">
        <span className="small muted">Signs out every computer, phone and browser, this one too.</span>
        <span className="spacer" />
        <button type="button" className="btn" onClick={() => void signOutEverywhere()}>Sign out everywhere</button>
      </div>
    </div>
  );
}

/** One limit with its meter: what is used next to what the plan allows. */
function LimitRow({ label, text, percent }: { label: string; text: string; percent: number | null }) {
  return (
    <div className="plan-limit">
      <div className="row"><span>{label}</span><span className="spacer" /><span className="small muted">{text}</span></div>
      {percent !== null && (
        <div className={`flow-bar ${percent >= 100 ? "full" : percent >= 80 ? "warn" : ""}`} role="meter" aria-label={`${label} used`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
          <span style={fillWidth(percent / 100)} />
        </div>
      )}
    </div>
  );
}

/** A plan that sets no retention leaves it to the server (its own "Recordings are deleted after" setting): asked only then. */
function useServerRetention(needed: boolean): number | null {
  const [days, setDays] = useState<number | null>(null);
  useEffect(() => {
    if (!needed) return;
    let alive = true;
    api.storage().then((info) => alive && setDays(info.retention_days), () => undefined);
    return () => {
      alive = false;
    };
  }, [needed]);
  return days;
}

/** The plan: its name, the limits next to the usage, and what it includes. */
export function PlanCard({ status }: { status: AppStatus }) {
  const plan = status.plan;
  const storage = storageLine(plan);
  const serverDays = useServerRetention(plan.known && plan.retentionDays === null);
  return (
    <div className="card plan-card" data-testid="plan-card">
      <div className="row"><b>Plan</b><span className="spacer" />{plan.known && plan.name && <span className="chip small">{plan.name}</span>}</div>
      {!plan.known && <p className="small muted">This server has no plans yet: every feature is on, within the server's own monthly limit.</p>}
      <LimitRow label="Transcription" text={minutesLine(plan)} percent={percentUsed(plan.usedMinutes, plan.monthlyMinutes)} />
      {storage && <LimitRow label="Storage" text={storage} percent={percentUsed(plan.usedStorageBytes, plan.storageBytes)} />}
      {plan.known && (
        <>
          <div className="toggle"><span>Recordings kept</span><span className="small muted">{plan.retentionDays === null ? (serverDays === null ? "As the server is set" : `${serverDays} days`) : `${plan.retentionDays} days`}</span></div>
          <div className="toggle"><span>People in the workspace</span><span className="small muted">{plan.seats === null ? "No limit" : `Up to ${plan.seats}`}</span></div>
          <ul className="plan-features" aria-label="In your plan">
            {planFeatures(plan).map(([name, on]) => (
              <li key={name} className={on ? "" : "off"}>{name}: {on ? "included" : "not in your plan"}</li>
            ))}
          </ul>
          <p className="small muted">Roman Urdu and the other transcript views, suggested fixes and screen descriptions are in every plan. The workspace owner changes the plan in the console.</p>
        </>
      )}
    </div>
  );
}

/** Export my data (a ZIP of everything, made on the server) and Delete my account. */
export function YourDataCard({ account, onAccount }: { account: Account; onAccount: (a: Account) => void }) {
  const [exporting, setExporting] = useState<AccountExport | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [useCode, setUseCode] = useState(false);
  const [secret, setSecret] = useState("");
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const secretId = useId();
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

  const confirmDelete = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setDeleteError(null);
    try {
      const deletesAt = await api.deleteAccount(useCode ? null : secret, useCode ? secret.trim() : null);
      const fresh = deletesAt ? null : await api.account().catch(() => null);
      onAccount({ ...account, deletes_at: deletesAt ?? fresh?.deletes_at ?? account.deletes_at });
      setDeleting(false);
      setSecret("");
    } catch (err) {
      setDeleteError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="card your-data">
      <b>Your data</b>
      <div className="toggle">
        <div>
          <div>Export my data</div>
          <div className="small muted">One ZIP with every recording's audio, transcripts, screens, project files and notes. The link works for 24 hours and is emailed to you too.</div>
        </div>
        <button type="button" className="btn" disabled={exporting?.status === "making"} onClick={() => void startExport()}>
          {exporting?.status === "making" ? "Making your export…" : "Export my data"}
        </button>
      </div>
      {exporting?.status === "ready" && exporting.url && (
        <div className="notice small" role="status">
          Your export is ready{exporting.expires_at ? ` until ${longDate(exporting.expires_at)}` : ""}.{" "}
          <button type="button" className="btn ghost compact" onClick={() => void api.openExport(exporting.url!).catch((e) => setExportError(errorMessage(e)))}>Open the download</button>
        </div>
      )}
      {exporting?.status === "failed" && <div className="notice error small" role="alert">The export could not be made. Try again in a while.</div>}
      {exportError && <div className="notice error small" role="alert">{exportError}</div>}

      <div className="toggle">
        <div>
          <div>Delete my account</div>
          <div className="small muted">
            {account.deletes_at
              ? `Your account will be deleted on ${longDate(account.deletes_at)}.`
              : "Your account and everything only you own (recordings, transcripts, screens, files, your own workspace's projects, devices) are deleted after 7 days. Signing in again before then and choosing Keep my account cancels it."}
          </div>
        </div>
        {!deleting && !account.deletes_at && <button type="button" className="btn danger" onClick={() => setDeleting(true)}>Delete my account…</button>}
      </div>
      {deleting && (
        <form className="account-delete" onSubmit={(e) => void confirmDelete(e)} aria-label="Confirm deleting your account">
          <label htmlFor={secretId} className="small">{useCode ? "Two-factor code" : "Your password"}, to confirm it is you</label>
          <input id={secretId} className="input" type={useCode ? "text" : "password"} inputMode={useCode ? "numeric" : undefined}
            autoComplete={useCode ? "one-time-code" : "current-password"} value={secret} onChange={(e) => setSecret(e.target.value)} required autoFocus />
          {account.two_factor && (
            <button type="button" className="btn ghost compact" onClick={() => { setUseCode((c) => !c); setSecret(""); }}>
              {useCode ? "Use my password instead" : "Use a two-factor code instead"}
            </button>
          )}
          {deleteError && <div className="notice error small" role="alert">{deleteError}</div>}
          <p className="small muted">{account.google ? "Signed in with Google and no password? Delete the account on the web page: sign in there with Google and confirm." : "No password or code to hand? Delete the account on the web page instead."}</p>
          <div className="row">
            <button type="button" className="btn ghost compact" onClick={() => void api.openServerPage("account/delete").catch(() => undefined)}>Delete on the web page</button>
            <span className="spacer" />
            <button type="button" className="btn ghost" onClick={() => { setDeleting(false); setSecret(""); setDeleteError(null); }}>Cancel</button>
            <button type="submit" className="btn danger" disabled={busy || !secret.trim()}>{busy ? "Deleting…" : "Delete my account"}</button>
          </div>
        </form>
      )}
    </div>
  );
}
