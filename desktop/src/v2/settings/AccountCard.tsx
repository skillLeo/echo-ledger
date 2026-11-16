// The top of Settings in the new look: who is signed in, the plan with Upgrade, the month's minutes and the storage
// numbers (v1.8 P2.5/P2.6): the account's from the plan, and this computer's free space. "Switch back to classic"
// sits under it while any redesigned screen is on (docs/API.md "Interface flags").
import { useEffect, useState } from "react";
import { api, errorMessage, type Account, type StorageInfo } from "../../lib/ipc";
import { fileSize } from "../../lib/format";
import { openUpgradeSheet, plansAbove, useEntitlements } from "../../lib/entitlements";
import { Avatar, Badge, Button, Card, EmptyState, Icons, List, ListRow, Loading, Progress, Skeleton } from "../components";
import { anyV2, setClassic, type UiState } from "../flags";

/** "AS" for Ayesha Siddiqui; the email's first letter without a name. */
export function initials(name: string | null | undefined, email: string | null | undefined): string {
  const words = (name ?? "").trim().split(/\s+/).filter(Boolean);
  if (words.length > 0) return words.slice(0, 2).map((w) => [...w][0]!.toLocaleUpperCase()).join("");
  return ([...(email ?? "").trim()][0] ?? "?").toLocaleUpperCase();
}

/** "212 of 300 minutes used this month"; without a limit, just what was used. */
export function minutesLine(used: number | null | undefined, limit: number | null | undefined): string | null {
  if (used == null) return null;
  const u = Math.round(used);
  return limit != null ? `${u} of ${Math.round(limit)} minutes used this month` : `${u} minutes used this month`;
}

export function AccountCard() {
  const [account, setAccount] = useState<Account | null | undefined>(undefined);
  const [storage, setStorage] = useState<StorageInfo | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const entitlements = useEntitlements();

  useEffect(() => {
    let live = true;
    setError(null);
    api.account().then((a) => live && setAccount(a)).catch((e) => {
      if (!live) return;
      setAccount(null);
      setError(errorMessage(e));
    });
    api.storage().then((s) => live && setStorage(s)).catch(() => undefined);
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
  const stored = entitlements?.limits.storage_bytes;
  const usedBytes = stored?.used ?? storage?.recordings_bytes ?? null;
  return (
    <>
      <Card className="el-profile" label={account.name || account.email}>
        <Avatar initials={initials(account.name, account.email)} />
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
export function ClassicSwitch({ state, onState }: { state: UiState; onState: (next: UiState) => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!anyV2(state) && !state.classic) return null;
  const toClassic = !state.classic;
  const flip = async () => {
    setBusy(true);
    setError(null);
    try {
      onState(await setClassic(toClassic));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Card className="el-classic" label="Look">
      <List label="Look">
        <ListRow compact icon={Icons.AppearanceIcon} iconStyle="plain"
          title={toClassic ? "You're using the new look" : "You're using the classic look"}
          meta={error ?? (toClassic ? "The classic screens stay available while the new ones roll out." : "The new screens are ready for you.")}
          control={<Button size="sm" loading={busy} loadingLabel="Switching…" onClick={() => void flip()}>{toClassic ? "Switch back to classic" : "Use the new look"}</Button>} />
      </List>
    </Card>
  );
}
