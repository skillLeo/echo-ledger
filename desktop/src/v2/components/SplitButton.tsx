import { useEffect, useId, useRef, useState, type KeyboardEvent } from "react";
import { Icon, type IconType } from "./Button";
import { MenuList, type MenuItem } from "./Menu";
import { ChevronDownIcon } from "./icons";

export type SplitButtonProps = {
  /** The main part's words ("Copy"). */
  label: string;
  icon?: IconType;
  /** The main part's press: the action most people want (copy the text). */
  onClick: () => void;
  /** The main part's name when the words alone aren't enough ("Copy text"). */
  mainLabel?: string;
  /** The chevron part's name ("More copy options"). */
  menuLabel: string;
  /** The other choices, in the shared Menu (copyMenuItems(...)). */
  items: readonly MenuItem[];
  variant?: "primary" | "secondary";
  disabled?: boolean;
};

/**
 * One button in two parts: the main action (icon and words) and a chevron that opens the other choices. Each part is
 * its own button with its own focus ring, at least 44 high; a hairline divides them inside one shape, rounded only at
 * the outer corners. The menu is the shared Menu's list, anchored to the whole button. In right to left the chevron is
 * on the left (logical properties only).
 */
export function SplitButton({ label, icon, onClick, mainLabel, menuLabel, items, variant = "secondary", disabled = false }: SplitButtonProps) {
  const id = useId();
  const [open, setOpen] = useState<false | { at: number }>(false);
  const root = useRef<HTMLDivElement>(null);
  const opener = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;
    const away = (e: PointerEvent) => {
      if (!root.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("pointerdown", away);
    return () => document.removeEventListener("pointerdown", away);
  }, [open]);

  const close = () => {
    setOpen(false);
    opener.current?.focus();
  };
  // ArrowDown or ArrowUp on the chevron opens the menu on its first or last item, as Menu's button does.
  const chevronKey = (e: KeyboardEvent<HTMLButtonElement>) => {
    if (e.key !== "ArrowDown" && e.key !== "ArrowUp") return;
    e.preventDefault();
    setOpen({ at: e.key === "ArrowDown" ? 0 : items.length - 1 });
  };

  return (
    <div className={`el-menu-anchor el-split el-split--${variant}`} ref={root}>
      <button type="button" className="el-split__main" aria-label={mainLabel} disabled={disabled} onClick={onClick}>
        {icon && <Icon icon={icon} />}
        <span>{label}</span>
      </button>
      <button type="button" ref={opener} className="el-split__more" aria-label={menuLabel} aria-haspopup="menu" aria-expanded={!!open}
        aria-controls={open ? `${id}-menu` : undefined} disabled={disabled} onClick={() => setOpen(open ? false : { at: 0 })} onKeyDown={chevronKey}>
        <Icon icon={ChevronDownIcon} />
      </button>
      {open && (
        <MenuList label={menuLabel} items={items} id={`${id}-menu`} className="el-menu el-menu--start" initial={open.at}
          onBack={close} onLeave={() => setOpen(false)} onPick={(item) => { close(); item.onPick?.(); }} />
      )}
    </div>
  );
}
