// Settings in the new look (v1.8 P2.5/P2.6): the profile with the plan and Upgrade, the storage numbers (the
// account's from the plan, this computer's free space) and "Switch back to classic" while any redesigned screen is on
// (docs/API.md "Interface flags"). Today's Settings already shows the profile, so the app shows the storage numbers
// and the switch above it; the profile card waits for Settings' own flag (ui_v2.settings).
import { useEffect, useState } from "react";
import { api, errorMessage, type Account, type StorageInfo } from "../../lib/ipc";
import { fileSize } from "../../lib/format";
import { openUpgradeSheet, plansAbove, useEntitlements } from "../../lib/entitlements";
import { Avatar, Badge, Button, Card, EmptyState, Icons, List, ListRow, Loading, Progress, Skeleton, SkeletonRow } from "../components";
import { useDesignSwitch, type UiState } from "../flags";
import { initials } from "./initials";
import { useAvatar } from "../../views/main/AccountFlows";

export { initials };

/** "212 of 300 minutes used this month"; without a limit, just what was used. */
export function minutesLine(used: number | null | undefined, limit: number | null | undefined): string | null {
  if (used == null) return null;
  const u = Math.round(used);
  return limit != null ? `${u} of ${Math.round(limit)} minutes used this month` : `${u} minutes used this month`;
}

/** Who is signed in, the plan with Upgrade and the month's minutes (for Settings in the new look, ui_v2.settings). */
export function AccountCard() {
  const [account, setAccount] = useState<Account | null | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const entitlements = useEntitlements();
  // Since 2.0.0-l the person's picture, kept on this computer by its version; the initials without one.
  const picture = useAvatar(account);

  useEffect(() => {
    let live = true;
    setError(null);
    api.account().then((a) => live && setAccount(a)).catch((e) => {
      if (!live) return;
      setAccount(null);
      setError(errorMessage(e));
    });
    return () => {
      live = false;
    };
  }, [attempt]);

  if (account === undefined) {
    return (
      <Loading label="Loading your account">
        <Card className="el-profile"><Skeleton shape="circle" /><span className="el-profile__who"><Skeleton shape="title" width="medium" /><Skeleton width="long" /></span></Card>
      </Loading>
    );
  }
  if (!account) {
    return (
      <Card className="el-profile-error">
        <EmptyState tone="error" icon={Icons.ErrorIcon} title="Your account didn't load"
          body={error ?? "Settings on this computer still work. Your plan and profile show once the server answers."}
          action={<Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
      </Card>
    );
  }

  const plan = entitlements?.plan?.name ?? null;
  const minutes = entitlements?.limits.monthly_minutes;
  const usage = minutesLine(minutes?.used, minutes?.limit);
  const canUpgrade = !!entitlements && plansAbove(entitlements).length > 0;
  return (
    <>
      <Card className="el-profile" label={account.name || account.email}>
        <Avatar initials={initials(account.name, account.email)} photo={picture} color={account.avatar_color} seed={account.email} />
        <div className="el-profile__who">
          <span className="el-title-s" dir="auto">{account.name || account.email}</span>
          {account.name && <span className="el-caption el-profile__email">{account.email}</span>}
          {(plan || usage) && (
            <span className="el-profile__plan">
              {plan && <Badge>{plan}</Badge>}
              {usage && <span className="el-caption">{usage}</span>}
            </span>
          )}
        </div>
        {canUpgrade && <Button variant="primary" onClick={() => openUpgradeSheet(null, { plan: plansAbove(entitlements)[0]?.key ?? null })}>Upgrade</Button>}
      </Card>
    </>
  );
}

/** The storage numbers (v1.8 P2.5): the account's from the plan, this computer's free space, how long recordings stay. */
export function StorageCard() {
  const [storage, setStorage] = useState<StorageInfo | null | undefined>(undefined);
  const entitlements = useEntitlements();
  useEffect(() => {
    let live = true;
    api.storage().then((s) => live && setStorage(s ?? null)).catch(() => live && setStorage(null));
    return () => {
      live = false;
    };
  }, []);
  const stored = entitlements?.limits.storage_bytes;
  const usedBytes = stored?.used ?? storage?.recordings_bytes ?? null;
  if (storage === undefined) {
    return <Loading label="Loading storage"><Card className="el-group__rows"><SkeletonRow end={false} /><SkeletonRow end={false} /></Card></Loading>;
  }
  return (
    <>
      {(usedBytes != null || storage) && (
        <section className="el-group" aria-label="Storage">
          <h2 className="el-caption el-body-strong el-group__title">Storage</h2>
          <Card>
            <List label="Storage" className="el-group__rows">
              {usedBytes != null && (
                <ListRow compact icon={Icons.StorageIcon} iconStyle="plain" title="Your recordings"
                  meta={stored?.limit ? undefined : storage ? `${storage.recordings_count} recordings` : undefined}
                  value={stored?.limit ? `${fileSize(usedBytes)} of ${fileSize(stored.limit)}` : fileSize(usedBytes)} />
              )}
              {stored?.limit && usedBytes != null && (
                <li className="el-group__progress">
                  <Progress label="Storage used" value={usedBytes} max={stored.limit} valueText={`${fileSize(usedBytes)} of ${fileSize(stored.limit)}`} />
                </li>
              )}
              {storage?.disk_free_bytes != null && (
                <ListRow compact icon={Icons.ScreenIcon} iconStyle="plain" title="Free on this computer" value={fileSize(storage.disk_free_bytes)} />
              )}
              {storage && (
                <ListRow compact icon={Icons.ClockIcon} iconStyle="plain" title="Recordings are kept"
                  value={storage.retention_days > 0 ? `${storage.retention_days} days` : "Until you delete them"} />
              )}
            </List>
          </Card>
        </section>
      )}
    </>
  );
}

/** "Switch back to classic" while a redesigned screen is on; "Use the new look" once the person went back. */
export function ClassicSwitch(_props: { state?: UiState; onState?: (next: UiState) => void }) {
  // At the foot of the new design's Settings, as in its Appearance; classic offers the new design from its Appearance.
  const design = useDesignSwitch();
  if (!design.canSwitchBack) return null;
  return (
    <Card className="el-classic" label="Look">
      <List label="Look">
        <ListRow compact icon={Icons.AppearanceIcon} iconStyle="plain" title="You're using the new design"
          meta={design.error ?? "Classic stays available while the new design is finished."}
          control={<Button size="sm" loading={design.busy} loadingLabel="Switching…" onClick={() => void design.choose("classic")}>Switch back to classic</Button>} />
      </List>
    </Card>
  );
}
