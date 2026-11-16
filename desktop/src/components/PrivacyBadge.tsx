// The privacy badge on every floating window (since v1.8.1): Private (a lock), Black box (older Windows), Not checked (a
// dashed circle) or Visible in screen share (amber), from the last real check (Rust `privacy::status`). A click opens
// "Check screen-share privacy" in the main window. In a floating window it is a hit region (`data-hit`), so it stays
// clickable while the rest of the window lets clicks through.
import type { PrivacyBadge as Badge } from "../lib/ipc";
import { api } from "../lib/ipc";
import { BADGE_HINT, BADGE_LABEL, usePrivacyStatus } from "../lib/privacy";
import { LucideCircleDashedIcon, LucideEyeIcon, LucideLockIcon, LucideSquareFilledIcon, type IconProps } from "./Icons";

const ICON: Record<Badge, (p: IconProps) => React.ReactNode> = { private: LucideLockIcon, black_box: LucideSquareFilledIcon, not_checked: LucideCircleDashedIcon, visible: LucideEyeIcon };

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
    return <span className={`privacy-badge ${badge}`} data-testid="privacy-badge" role="img" aria-label={BADGE_LABEL[badge]} title={BADGE_LABEL[badge]}>{content}</span>;
  }
  return (
    <button type="button" className={`privacy-badge ${badge}`} data-testid="privacy-badge" data-hit aria-label={BADGE_HINT[badge]} title={BADGE_HINT[badge]} onClick={onOpen}>
      {content}
    </button>
  );
}

/** The badge of a floating window: follows the status by itself, and opens "Check screen-share privacy" when clicked. */
export function PrivacyBadge({ compact = false, interactive = true }: { compact?: boolean; interactive?: boolean }) {
  const status = usePrivacyStatus();
  if (!status) return null;
  return <PrivacyBadgeView badge={status.badge} compact={compact} onOpen={interactive ? () => void api.privacyOpenCheck().catch(() => undefined) : undefined} />;
}
