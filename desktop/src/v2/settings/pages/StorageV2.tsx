// Settings › Storage in the new look, with today's data (SecuritySettings.tsx): what the account stores, against the
// plan's limit, how many recordings, how long the plan keeps them, and how long Trash keeps a deleted one (the
// server's setting, from the core; 48 hours from an older one).
import { useEffect, useState } from "react";
import { api, errorMessage, type StorageInfo } from "../../../lib/ipc";
import { fillWidth } from "../../../lib/motion";
import { setting, titleV2 } from "../../../lib/settingsRegistry";
import { useTrashKeepHours } from "../../../lib/trash";
import { count } from "../../../lib/format";
import { trashTime } from "../../library/model";
import { storageValue } from "../../../views/main/SecuritySettings";
import { ListRow } from "../../components";
import { Group } from "./rows";
import { t, tc } from "../../../i18n";

/** "1 year", "2 years", "30 days". */
export function retentionWords(days: number): string {
  if (days >= 365 && days % 365 === 0) return tc(":count year|:count years", days / 365);
  return tc(":count day|:count days", days);
}

export function StoragePageV2({ limitBytes = null }: { limitBytes?: number | null }) {
  const [info, setInfo] = useState<StorageInfo | null>(null);
  const [error, setError] = useState<string | null>(null);
  const trashHours = useTrashKeepHours();
  useEffect(() => {
    api.storage().then(setInfo, (e) => setError(errorMessage(e)));
  }, []);
  const percent = info && limitBytes ? Math.min(100, Math.round((info.recordings_bytes / limitBytes) * 100)) : null;
  const tone = percent === null ? "" : percent >= 100 ? "full" : percent >= 80 ? "warn" : "";
  const used = info ? storageValue(info.recordings_bytes, limitBytes) : t("Checking…");
  return (
    <Group footer={<>
      <p>{t("Recordings are deleted after the time your plan keeps them, unless you keep them or their project does. Deleted recordings stay in Trash for :time.", { time: trashTime(trashHours) })}</p>
      {error && <p className="el-field__error" role="alert">{error}</p>}
    </>}>
      <ListRow compact anchor="storage.used" title={setting("storage.used").title}
        value={<span className={tone ? `el-storage-value el-storage-value--${tone}` : "el-storage-value"}>{used}</span>}
        meta={percent !== null ? (
          <span className={`el-meter el-meter--wide${tone ? ` el-meter--${tone}` : ""}`} role="meter" aria-label={t("Storage, :value used", { value: used })} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
            <span className="el-meter__fill" style={fillWidth(percent / 100)} />
          </span>
        ) : undefined} />
      <ListRow compact anchor="storage.count" title={setting("storage.count").title}
        value={info ? tc(":count recording|:count recordings", info.recordings_count, { count: count(info.recordings_count) }) : undefined} />
      <ListRow compact anchor="storage.retention" title={setting("storage.retention").title} value={info ? retentionWords(info.retention_days) : undefined} />
      <ListRow compact anchor="storage.bin" title={titleV2(setting("storage.bin"))} value={trashTime(trashHours)} />
    </Group>
  );
}
