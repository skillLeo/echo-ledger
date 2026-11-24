// The new design's kit for the account flows (views/main/AccountFlows.tsx): its Dialog, Button, TextField and Checkbox.
import type { AccountKit, KitButtonProps, KitDialogProps, KitFieldProps } from "../../views/main/AccountFlows";
import { Button, Checkbox, Dialog, TextField } from "../components";

function KitDialog({ title, description, onClose, alert, children, actions }: KitDialogProps) {
  return <Dialog open alert={alert} title={title} description={description} onClose={onClose} actions={actions}>{children}</Dialog>;
}

function KitButton({ kind = "secondary", busy, busyLabel, disabled, type = "button", form, label, onClick, children }: KitButtonProps) {
  return (
    <Button variant={kind} type={type} form={form} disabled={disabled} loading={busy} loadingLabel={busyLabel} aria-label={label} onClick={onClick}>
      {children}
    </Button>
  );
}

function KitField({ label, value, onChange, type = "text", autoComplete, inputMode, error, hint, autoFocus, maxLength }: KitFieldProps) {
  return (
    <TextField label={label} value={value} onChange={(e) => onChange(e.target.value)} type={type} autoComplete={autoComplete} inputMode={inputMode}
      error={error} hint={hint} autoFocus={autoFocus} maxLength={maxLength} />
  );
}

export const V2_KIT: AccountKit = {
  Dialog: KitDialog,
  Button: KitButton,
  Field: KitField,
  Check: ({ label, hint, checked, onChange }) => <Checkbox label={label} hint={hint} checked={checked} onChange={(e) => onChange(e.target.checked)} />,
  Problem: ({ children }) => <p className="el-field__error" role="alert">{children}</p>,
};
