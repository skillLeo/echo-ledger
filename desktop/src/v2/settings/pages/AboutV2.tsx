// Settings › About and Technical details in the new look, with today's data and rules (AppSettings.tsx,
// UpdateSettings.tsx): the version and updates (Check for updates; Restart once one is ready, never while recording),
// this computer's name, the server, export and import of settings (preferences only), and the policies.
import { useId, useState } from "react";
import { api, errorMessage, type AppStatus, type Settings } from "../../../lib/ipc";
import { formatWhen } from "../../../lib/format";
import { setting } from "../../../lib/settingsRegistry";
import { hostOf, useVersion } from "../../../views/main/AppSettings";
import { RECORDING_BLOCKS, updateLine, useUpdateStatus } from "../../../views/main/UpdateSettings";
import { Button, Icons, ListRow } from "../../components";
import { Group, LinkSetting, type Run } from "./rows";
import { t } from "../../../i18n";

/** Check for updates, and Restart once one is downloaded. */
function UpdatesRow({ appStatus }: { appStatus: AppStatus }) {
  const [status, setStatus] = useUpdateStatus();
  const [error, setError] = useState<string | null>(null);
  const title = setting("about.updates").title;
  if (!status) return <ListRow compact anchor="about.updates" title={title} />;
  const working = status.phase === "checking" || status.phase === "downloading";
  const ready = status.phase === "ready";
  const attempt = async (fn: () => Promise<unknown>) => {
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(errorMessage(e));
    }
  };
  const meta = (
    <span className="el-update">
      <span className={status.phase === "failed" ? "el-update__line el-update__line--failed" : "el-update__line"} role="status">{updateLine(status)}</span>
      {status.checkedAt && status.phase !== "checking" && <span className="el-update__line">{t("Last checked :when", { when: formatWhen(status.checkedAt) })}</span>}
      {ready && status.notes && <span className="el-update__line">{status.notes}</span>}
      {ready && appStatus.recording && <span className="el-update__line">{RECORDING_BLOCKS}</span>}
      {error && <span className="el-update__line el-row__error" role="alert">{error}</span>}
    </span>
  );
  return (
    <ListRow compact anchor="about.updates" title={title} meta={meta}
      control={ready
        ? <Button variant="primary" size="sm" disabled={!!appStatus.recording} onClick={() => void attempt(() => api.installUpdate())}>{t("Restart")}</Button>
        : <Button size="sm" icon={Icons.DownloadIcon} disabled={working} onClick={() => void attempt(async () => setStatus(await api.checkForUpdates()))}>{t("Check for updates")}</Button>} />
  );
}

export function AboutPageV2({ status, run, onImported, onDevices, onTechnical }: {
  status: AppStatus;
  run: Run;
  onImported: (s: Settings) => void;
  onDevices: () => void;
  onTechnical: () => void;
}) {
  const version = useVersion();
  const fileId = useId();
  const importFile = async (file: File | undefined) => {
    if (!file) return;
    const text = await file.text();
    const next = await run(() => api.importSettings(text), t("Settings imported"));
    if (next) onImported(next);
  };
  const web = (page: "privacy" | "terms") => () => void api.openServerPage(page).catch(() => undefined);
  return (
    <>
      <div className="el-rows-wrap">
      <Group>
        <ListRow compact anchor="about.version" title={setting("about.version").title} value={version ? <span dir="ltr">{version}</span> : undefined} />
        <UpdatesRow appStatus={status} />
        <LinkSetting id="about.device_name" value={status.deviceName ?? ""} onOpen={onDevices} />
        {status.serverUrl && <ListRow compact anchor="about.server" title={setting("about.server").title} value={<span dir="ltr">{hostOf(status.serverUrl)}</span>} />}
        <LinkSetting id="about.technical" onOpen={onTechnical} />
      </Group>
      </div>
      <Group footer={<p>{t("The file has your preferences only, never the sign-in, keys or PIN.")}</p>}>
        <ListRow compact anchor="about.export_settings" title={setting("about.export_settings").title}
          onClick={() => void run(api.exportSettings, t("Settings saved in Downloads"))} />
        <ListRow compact anchor="about.import_settings" title={setting("about.import_settings").title}
          onClick={() => document.getElementById(fileId)?.click()} />
      </Group>
      <input id={fileId} type="file" accept="application/json,.json" className="el-visually-hidden" tabIndex={-1} aria-label={t("Import settings")}
        onChange={(e) => { void importFile(e.target.files?.[0]); e.target.value = ""; }} />
      <Group>
        <ListRow compact anchor="about.privacy" title={setting("about.privacy").title} label={t("Privacy policy, opens in browser")} meta={t("Opens the website")} chevron onClick={web("privacy")} />
        <ListRow compact anchor="about.terms" title={setting("about.terms").title} label={t("Terms, opens in browser")} meta={t("Opens the website")} chevron onClick={web("terms")} />
      </Group>
    </>
  );
}

/** About › Technical details: the connection and this computer, for support. */
export function TechnicalPageV2({ status }: { status: AppStatus }) {
  return (
    <Group>
      <ListRow compact anchor="about.technical.secure" title={setting("about.technical.secure").title} value={status.pinned ? t("Verified") : t("Not verified")} />
      {status.serverUrl && <ListRow compact anchor="about.technical.address" title={setting("about.technical.address").title} value={<span dir="ltr">{status.serverUrl}</span>} />}
    </Group>
  );
}
