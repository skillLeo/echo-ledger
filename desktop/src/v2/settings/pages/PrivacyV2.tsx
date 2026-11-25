// Settings › Privacy & data in the new look, with today's data and rules (AccountSettings.tsx): the recording consent
// notice (read and acknowledged, never a switch), Export my data (made on the server, asked about every 5 seconds, its
// link opened once), and Delete my account (with the password or a two-step code, or on the website), or Cancel
// deletion while it is pending. A server without accounts keeps only the consent notice.
import { useEffect, useRef, useState, type FormEvent } from "react";
import { api, errorMessage, type Account, type AccountExport, type AppStatus, type Settings } from "../../../lib/ipc";
import { rowDateWithYear } from "../../../lib/format";
import { setting } from "../../../lib/settingsRegistry";
import { EXPORT_POLL_MS, longDate } from "../../../views/main/AccountSettings";
import { ConsentNotice } from "../../../views/main/ConsentNotice";
import { Button, Dialog, ListRow, TextField } from "../../components";
import { Group, LinkSetting, type Patch } from "./rows";
import { t } from "../../../i18n";

export function PrivacyPageV2({ account, settings, onAccount, onConsent }: {
  account: Account | null;
  settings: Settings;
  onAccount: (a: Account) => void;
  onConsent: () => void;
}) {
  const [exporting, setExporting] = useState<AccountExport | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState(false);
  const opened = useRef<string | null>(null);

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
  const exportLabel = exporting?.status === "making" ? t("Making your export…") : exporting?.status === "ready" ? t("Download your export")
    : exporting?.status === "failed" ? t("Couldn't make the export. Try again") : null;

  return (
    <>
      <Group>
        <LinkSetting id="privacy.consent" value={settings.consentAcknowledged ? t("Acknowledged") : t("Not yet")} onOpen={onConsent} />
      </Group>
      {account && (
        <Group footer={<>
          <p>{t("One ZIP with every recording's audio, transcripts, screens, project files and notes. The link works for 24 hours and is emailed to you too.")}</p>
          {exportError && <p className="el-field__error" role="alert">{exportError}</p>}
          {account.deletes_at
            ? <p>{t("Your account will be deleted on :date.", { date: longDate(account.deletes_at) })} {t("To keep it, sign in on the website and choose Keep my account.")}</p>
            : <p>{t("Your account and everything only you own are deleted after 7 days. Signing in again before then and choosing Keep my account cancels it.")}</p>}
        </>}>
          <ListRow compact anchor="privacy.export" title={setting("privacy.export").title} meta={exporting?.status === "failed" ? exportLabel ?? undefined : undefined}
            control={
              <Button size="sm" disabled={exporting?.status === "making"}
                onClick={() => (exporting?.status === "ready" && exporting.url ? void api.openExport(exporting.url).catch((e) => setExportError(errorMessage(e))) : void startExport())}>
                {exporting?.status === "ready" || exporting?.status === "making" ? exportLabel : t("Export my data")}
              </Button>
            } />
          {account.deletes_at ? (
            <ListRow compact anchor="privacy.cancel_deletion" title={setting("privacy.cancel_deletion").title} meta={t("Deletes on :date", { date: rowDateWithYear(account.deletes_at) })}
              control={<Button size="sm" onClick={() => void api.openServerPage("app/profile").catch(() => undefined)} aria-label={t("Cancel deletion, opens in browser")}>{t("Cancel deletion")}</Button>} />
          ) : (
            <ListRow compact anchor="privacy.delete_account" title={setting("privacy.delete_account").title}
              control={<Button size="sm" variant="danger" onClick={() => setDeleting(true)}>{t("Delete my account")}</Button>} />
          )}
        </Group>
      )}
      {deleting && account && <DeleteAccountDialogV2 account={account} onAccount={onAccount} onClose={() => setDeleting(false)} />}
    </>
  );
}

/** Delete my account: the password or a two-step code, or the web page instead. */
function DeleteAccountDialogV2({ account, onAccount, onClose }: { account: Account; onAccount: (a: Account) => void; onClose: () => void }) {
  const [useCode, setUseCode] = useState(false);
  const [secret, setSecret] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const confirmDelete = async (e: FormEvent) => {
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
    <Dialog open alert title={t("Delete your account?")} onClose={() => { if (!busy) onClose(); }}
      description={t("We delete your account and everything only you own after 7 days. You can cancel by signing in before then.")}>
      <form className="el-form el-form--flush" id="el-delete-account" onSubmit={(e) => void confirmDelete(e)} aria-label={t("Confirm deleting your account")}>
        <TextField label={useCode ? t("Two-step code") : t("Your password")} type={useCode ? "text" : "password"} inputMode={useCode ? "numeric" : undefined}
          autoComplete={useCode ? "one-time-code" : "current-password"} value={secret} onChange={(e) => setSecret(e.target.value)} required />
        {account.two_factor && (
          <Button variant="quiet" size="sm" onClick={() => { setUseCode((c) => !c); setSecret(""); }}>
            {useCode ? t("Use my password instead") : t("Use a two-step code instead")}
          </Button>
        )}
        {error && <p className="el-field__error" role="alert">{error}</p>}
        <Button variant="quiet" size="sm" onClick={() => void api.openServerPage("account/delete").catch(() => undefined)}>{t("Delete on the website")}</Button>
        <div className="el-dialog__actions el-dialog__actions--inline">
          <Button onClick={onClose} disabled={busy}>{t("Cancel")}</Button>
          <Button type="submit" variant="danger" disabled={!secret.trim()} loading={busy} loadingLabel={t("Deleting…")}>{t("Delete my account")}</Button>
        </div>
      </form>
    </Dialog>
  );
}

/** Privacy & data › Recording consent: the notice, then "I understand". It is never a switch. */
export function ConsentPageV2({ status, settings, patch }: { status: AppStatus; settings: Settings; patch: Patch }) {
  return (
    <div className="el-prose">
      <ConsentNotice legal={status.plan.known && status.paired} />
      {settings.consentAcknowledged
        ? <p className="el-secondary" data-testid="consent-done">{t("You've read this notice.")}</p>
        : <div><Button variant="primary" onClick={() => void patch({ consentAcknowledged: true })}>{t("I understand")}</Button></div>}
    </div>
  );
}
