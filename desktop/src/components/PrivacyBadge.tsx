// The privacy badge on every floating window (since v1.8.1): Private (a lock), Not checked, or Visible in shares (amber),
// from the last real check (Rust `privacy::status`). A click opens Share Check in the main window. In an overlay it is a
// hit region (`data-hit`), so it stays clickable while the rest of the window lets clicks through.
import type { PrivacyBadge as Badge } from "../lib/ipc";
import { api } from "../lib/ipc";
import { BADGE_HINT, BADGE_LABEL, usePrivacyStatus } from "../lib/privacy";
import { LucideCircleHelpIcon, LucideEyeIcon, LucideLockIcon, type IconProps } from "./Icons";

const ICON: Record<Badge, (p: IconProps) => React.ReactNode> = { private: LucideLockIcon, not_checked: LucideCircleHelpIcon, visible: LucideEyeIcon };

/** The badge for a known state (Settings shows it next to the status; the overlays through [PrivacyBadge]). */
export function PrivacyBadgeView({ badge, compact = false, onOpen }: { badge: Badge; compact?: boolean; onOpen?: () => void }) {
  const Icon = ICON[badge];
  const content = (
    <>
      <Icon size={compact ? 14 : 13} />
      {!compact && <span className="privacy-badge-label">{BADGE_LABEL[badge]}</span>}
    </>
  );
  if (!onOpen) {
    return <span className={`privacy-badge ${badge}`} data-testid="privacy-badge" title={BADGE_LABEL[badge]}>{content}</span>;
  }
  return (
    <button type="button" className={`privacy-badge ${badge}`} data-testid="privacy-badge" data-hit aria-label={BADGE_HINT[badge]} title={BADGE_HINT[badge]} onClick={onOpen}>
      {content}
    </button>
  );
}

/** The badge of a floating window: follows the status by itself, and opens Share Check when clicked. */
export function PrivacyBadge({ compact = false, interactive = true }: { compact?: boolean; interactive?: boolean }) {
  const status = usePrivacyStatus();
  if (!status) return null;
  return <PrivacyBadgeView badge={status.badge} compact={compact} onOpen={interactive ? () => void api.privacyOpenCheck().catch(() => undefined) : undefined} />;
}
