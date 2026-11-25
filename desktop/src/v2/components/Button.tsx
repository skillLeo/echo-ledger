import type { ComponentPropsWithRef, ComponentType, MouseEvent, ReactNode } from "react";
import { MIRRORED_IN_RTL } from "./icons";

export type IconType = ComponentType<{ className?: string }>;

/**
 * An icon from icons.ts at one of the three sizes; `directional` glyphs (chevrons, arrows) mirror right-to-left, and
 * so do the ones that point a way or read as text wherever they are drawn (icons.ts MIRRORED_IN_RTL: sort, sign out,
 * send, Ask, help, D-231).
 */
export function Icon({ icon: Glyph, size = "sm", directional = false }: { icon: IconType; size?: "sm" | "md" | "lg"; directional?: boolean }) {
  const mirrored = directional || MIRRORED_IN_RTL.has(Glyph);
  const cls = ["el-icon", size !== "sm" && `el-icon--${size}`, mirrored && "el-icon--directional"].filter(Boolean).join(" ");
  return <span className={cls} aria-hidden="true"><Glyph /></span>;
}

export type ButtonVariant = "primary" | "secondary" | "quiet" | "danger";
export type ButtonSize = "sm" | "md" | "lg";

export type ButtonProps = Omit<ComponentPropsWithRef<"button">, "children"> & {
  variant?: ButtonVariant;
  size?: ButtonSize;
  /** Busy: the button stays focusable, says so (aria-busy) and ignores clicks; no spinner, no looping animation. */
  loading?: boolean;
  /** What the button says while busy ("Saving…"); the label stays when not given. */
  loadingLabel?: string;
  icon?: IconType;
  iconEnd?: IconType;
  /** Mirror the icons in right-to-left (arrows and chevrons). */
  directional?: boolean;
  children?: ReactNode;
};

/** One button for every action. Icon-only buttons need an aria-label. */
export function Button({
  variant = "secondary", size = "md", loading = false, loadingLabel, icon, iconEnd, directional, children, className,
  onClick, type = "button", ...rest
}: ButtonProps) {
  const iconOnly = children === undefined || children === null || children === false;
  const cls = ["el-button", `el-button--${variant}`, size !== "md" && `el-button--${size}`, iconOnly && "el-button--icon", className]
    .filter(Boolean).join(" ");
  const handle = (e: MouseEvent<HTMLButtonElement>) => {
    if (loading) {
      e.preventDefault();
      return;
    }
    onClick?.(e);
  };
  return (
    <button type={type} className={cls} onClick={handle} aria-busy={loading || undefined} aria-disabled={loading || undefined} {...rest}>
      {icon && <Icon icon={icon} size={size === "lg" ? "md" : "sm"} directional={directional} />}
      {!iconOnly && <span>{loading && loadingLabel ? loadingLabel : children}</span>}
      {iconEnd && <Icon icon={iconEnd} directional={directional} />}
    </button>
  );
}
