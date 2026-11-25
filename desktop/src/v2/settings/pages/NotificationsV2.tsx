// Settings › Notifications in the new look, with today's data and rules (SecuritySettings.tsx): one switch for them
// all, sounds, quiet hours with their times, a switch per kind (in the phone's channel order), and a test.
import { api, type Settings } from "../../../lib/ipc";
import { setting } from "../../../lib/settingsRegistry";
import { NOT_SHOWN, NOTIFICATION_CATEGORIES } from "../../../views/main/SecuritySettings";
import { ListRow, TextField } from "../../components";
import { Group, ResetDefaults, SwitchSetting, type Patch, type Run } from "./rows";
import { t } from "../../../i18n";

const isOn = (map: Partial<Record<string, boolean>> | undefined, c: string) => map?.[c] !== false;

export function NotificationsPageV2({ settings, patch, run }: { settings: Settings; patch: Patch; run: Run }) {
  const all = settings.activityNotifications;
  const soundsOn = NOTIFICATION_CATEGORIES.some(([c]) => isOn(settings.notificationSounds, c));
  const quiet = settings.quietHours && all;
  const test = () => void run(() => api.notificationTest("other").then((shown) => {
    if (!shown) throw t(NOT_SHOWN);
  }), t("Test notification sent"));
  const time = (id: "notifications.quiet_from" | "notifications.quiet_to", value: string, save: (v: string) => void) => (
    <ListRow compact anchor={id} title={setting(id).title}
      control={<TextField label={<span className="el-visually-hidden">{id === "notifications.quiet_from" ? t("Quiet hours from") : t("Quiet hours to")}</span>} type="time" value={value} disabled={!quiet} dir="ltr"
        className="el-setting__time" onChange={(e) => e.target.value && save(e.target.value)} />} />
  );
  return (
    <>
      <Group footer={<p>{t("No notifications in quiet hours, except Call capture & readiness. They still wait in the bell.")}</p>}>
        <SwitchSetting id="notifications.enabled" on={all} onChange={(v) => void patch({ activityNotifications: v })} />
        <SwitchSetting id="notifications.sounds" on={soundsOn} disabled={!all}
          onChange={(v) => void patch({ notificationSounds: Object.fromEntries(NOTIFICATION_CATEGORIES.map(([c]) => [c, v])) })} />
        <SwitchSetting id="notifications.quiet" on={settings.quietHours} disabled={!all} onChange={(v) => void patch({ quietHours: v })} />
        {time("notifications.quiet_from", settings.quietFrom, (v) => void patch({ quietFrom: v }))}
        {time("notifications.quiet_to", settings.quietTo, (v) => void patch({ quietTo: v }))}
      </Group>
      <Group title={t("Notify me about")}>
        {NOTIFICATION_CATEGORIES.map(([category]) => (
          <SwitchSetting key={category} id={`notifications.kind.${category}`} on={isOn(settings.notificationCategories, category)} disabled={!all}
            onChange={(v) => void patch({ notificationCategories: { [category]: v } })} />
        ))}
      </Group>
      <Group>
        <ListRow compact anchor="notifications.test" title={setting("notifications.test").title} onClick={test} />
      </Group>
      <ResetDefaults page={t("Notifications")} onReset={() => void patch({
        activityNotifications: true, quietHours: false,
        notificationCategories: Object.fromEntries(NOTIFICATION_CATEGORIES.map(([c]) => [c, true])),
        notificationSounds: Object.fromEntries(NOTIFICATION_CATEGORIES.map(([c]) => [c, true])),
      })} />
    </>
  );
}
