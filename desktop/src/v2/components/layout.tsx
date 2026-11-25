import { useState, type ReactNode } from "react";
import { Icon, type IconType } from "./Button";

/** A flat panel with a hairline border (mixed items only, never a coloured edge). */
export function Card({ children, className, label }: { children: ReactNode; className?: string; label?: string }) {
  return <section className={["el-card", className].filter(Boolean).join(" ")} aria-label={label}>{children}</section>;
}

/** The avatars' colours (tokens role.avatar.0…7, the server's avatar_color). */
export const AVATAR_COLOURS = 8;

/**
 * The colour of someone's circle: the server's avatar_color (0–7, BE-06), else one worked out from who it is (their
 * email, a speaker's name), the same every time.
 */
export function avatarColour(color: number | null | undefined, seed: string): number {
  if (typeof color === "number" && Number.isInteger(color) && color >= 0 && color < AVATAR_COLOURS) return color;
  let hash = 0;
  for (const ch of seed.trim().toLowerCase()) hash = (hash * 31 + ch.codePointAt(0)!) >>> 0;
  return hash % AVATAR_COLOURS;
}

/**
 * A person's avatar (DES-08), the same everywhere: their photo filling the circle, else their initials on a solid
 * colour (never grey or white), with a 1 px inner ring so neither melts into the background. A photo that fails to load
 * shows the initials. The name is said by what holds it, so the avatar itself is hidden from assistive tools.
 * Sizes: rail 28, list 32 (sm), account menu 40, md 48, profile 96.
 */
export function Avatar({ initials, size = "md", color, seed, photo, drawn = false }: {
  initials: string;
  /**
   * The initials drawn, not written (FIX-06): where what holds the avatar is named otherwise ("Account, Ayesha Khan"),
   * so its visible words stay part of its name (label in name).
   */
  drawn?: boolean;
  size?: "sm" | "md" | "rail" | "list" | "menu" | "profile";
  /** The server's avatar_color (0–7); left out, one from `seed` (else the initials). */
  color?: number | null;
  seed?: string;
  photo?: string | null;
}) {
  const [broken, setBroken] = useState<string | null>(null);
  const showPhoto = !!photo && broken !== photo;
  return (
    <span className={`el-avatar el-avatar--${size}`} data-colour={avatarColour(color, seed ?? initials)} aria-hidden="true"
      data-initials={drawn && !showPhoto ? initials : undefined}>
      {showPhoto ? <img src={photo!} alt="" onError={() => setBroken(photo!)} /> : drawn ? null : initials}
    </span>
  );
}

/** The app's primary navigation; below the rail breakpoint (780 px window) it keeps its icons only. */
export function Sidebar({ label, brand, brandEnd, children, footer, rail, width }: {
  label: string;
  /** Icons only (true) or full (false) whatever the window's width; left out, the window's width decides. */
  rail?: boolean;
  /** Its width in CSS pixels while not a rail (the person's, 200 to 320); left out, the design's. */
  width?: number;
  brand?: ReactNode;
  /** A control at the brand's end (Search): it stays at the top of the rail when the name hides. */
  brandEnd?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <nav className="el-sidebar" aria-label={label} data-rail={rail === undefined ? undefined : rail ? "on" : "off"}
      style={width !== undefined && !rail ? { ["--el-sidebar-width" as string]: `${width}px` } : undefined}>
      {(brand || brandEnd) && (
        <div className="el-sidebar__brand">
          {brand && <span className="el-sidebar__brand-name">{brand}</span>}
          {brandEnd && <span className="el-sidebar__brand-end">{brandEnd}</span>}
        </div>
      )}
      {children}
      {footer}
    </nav>
  );
}

/**
 * One place in the sidebar; the current page is marked for assistive tools too. Its count needs attention (Activity's
 * "needs you"): the accent pill, and in the icon rail, where the count is only for assistive tools, a small accent dot
 * on the icon. Its name says the count as the Library's places do: "Activity, 6".
 */
export function NavItem({ icon, label, current, count, onClick }: { icon: IconType; label: string; current?: boolean; count?: number; onClick?: () => void }) {
  return (
    <button type="button" className="el-nav-item" aria-current={current ? "page" : undefined} onClick={onClick} title={label}
      aria-label={count ? `${label}, ${count}` : undefined}>
      <Icon icon={icon} size="md" />
      <span className="el-nav-item__label">{label}</span>
      {count !== undefined && <>{" "}<span className="el-nav-item__count el-caption el-num">{count}</span></>}
      {count !== undefined && count > 0 && <span className="el-attention-dot el-nav-item__dot" aria-hidden="true" />}
    </button>
  );
}
