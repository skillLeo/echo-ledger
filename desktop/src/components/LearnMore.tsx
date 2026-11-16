// A banner says one short sentence; what it means and how to fix it in full waits behind "Learn more" (Notices).
import { useId, useState, type ReactNode } from "react";

export function LearnMore({ children, label = "Learn more" }: { children: ReactNode; label?: string }) {
  const [open, setOpen] = useState(false);
  const id = useId();
  return (
    <>
      <button type="button" className="btn inline-link learn-more" aria-expanded={open} aria-controls={id} onClick={(e) => { e.stopPropagation(); setOpen((o) => !o); }}>
        {open ? "Show less" : label}
      </button>
      <span id={id} className="banner-details small" hidden={!open} dir="auto">{children}</span>
    </>
  );
}
