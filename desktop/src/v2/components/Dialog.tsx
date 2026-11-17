import { useEffect, useId, useRef, type KeyboardEvent, type ReactNode, type RefObject } from "react";
import { Button } from "./Button";
import { CloseIcon } from "./icons";

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function focusables(root: HTMLElement): HTMLElement[] {
  return [...root.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((el) => !el.closest("[hidden]"));
}

/**
 * Keeps keyboard focus inside `root` while mounted: focus moves in on open (to `initial`, else the first control after
 * the close button, else the panel), Tab and Shift+Tab wrap, and focus returns to the opener on close.
 */
export function useFocusTrap(root: RefObject<HTMLElement | null>, initial?: RefObject<HTMLElement | null>) {
  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null;
    const panel = root.current;
    if (panel) {
      const items = focusables(panel);
      const target = initial?.current ?? items.find((el) => !el.dataset.close) ?? items[0] ?? panel;
      target.focus();
    }
    return () => {
      if (opener && document.contains(opener)) opener.focus();
    };
  }, [root, initial]);

  return (e: KeyboardEvent<HTMLElement>) => {
    if (e.key !== "Tab" || !root.current) return;
    const items = focusables(root.current);
    if (items.length === 0) {
      e.preventDefault();
      return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    const at = document.activeElement;
    if (e.shiftKey && (at === first || at === root.current)) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && at === last) {
      e.preventDefault();
      first.focus();
    }
  };
}

export type DialogProps = {
  open: boolean;
  /** The visible title, which also names the dialog. */
  title: ReactNode;
  /** A sentence under the title, read with it. */
  description?: ReactNode;
  onClose: () => void;
  closeLabel?: string;
  /** The buttons at the bottom, the main one last. */
  actions?: ReactNode;
  children?: ReactNode;
  /** The control to focus first; otherwise the first control in the body. */
  initialFocus?: RefObject<HTMLElement | null>;
  /** A role="alertdialog" for a destructive confirmation. */
  alert?: boolean;
  /** As wide as the window allows (a picture viewer). */
  wide?: boolean;
};

function Overlay({ kind, open, title, description, onClose, closeLabel = "Close", actions, children, initialFocus, alert, wide }: DialogProps & { kind: "dialog" | "sheet" }) {
  if (!open) return null;
  return (
    <Panel kind={kind} title={title} description={description} onClose={onClose} closeLabel={closeLabel} actions={actions}
      initialFocus={initialFocus} alert={alert} wide={wide} open>{children}</Panel>
  );
}

function Panel({ kind, title, description, onClose, closeLabel, actions, children, initialFocus, alert, wide }: DialogProps & { kind: "dialog" | "sheet" }) {
  const id = useId();
  const panel = useRef<HTMLDivElement>(null);
  const trap = useFocusTrap(panel, initialFocus);
  const keyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key === "Escape") {
      e.stopPropagation();
      onClose();
      return;
    }
    trap(e);
  };
  return (
    <div className={`el-scrim${kind === "sheet" ? " el-scrim--sheet" : ""}`} onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div ref={panel} className={kind === "sheet" ? "el-sheet" : `el-dialog${wide ? " el-dialog--wide" : ""}`} role={alert ? "alertdialog" : "dialog"} aria-modal="true"
        aria-labelledby={`${id}-title`} aria-describedby={description ? `${id}-desc` : undefined} tabIndex={-1} onKeyDown={keyDown}>
        <div className="el-dialog__head">
          <div className="el-dialog__titles">
            <h2 className="el-title-s" id={`${id}-title`}>{title}</h2>
            {description && <p className="el-secondary" id={`${id}-desc`}>{description}</p>}
          </div>
          <Button variant="quiet" icon={CloseIcon} aria-label={closeLabel} data-close="true" onClick={onClose} />
        </div>
        {children && <div className="el-dialog__body">{children}</div>}
        {actions && <div className="el-dialog__actions">{actions}</div>}
      </div>
    </div>
  );
}

/** A centred modal dialog: focus trapped, Esc and the scrim close it, named by its title. */
export function Dialog(props: DialogProps) {
  return <Overlay kind="dialog" {...props} />;
}

/** The same modal as a panel from the end edge (filters, details). */
export function Sheet(props: DialogProps) {
  return <Overlay kind="sheet" {...props} />;
}
