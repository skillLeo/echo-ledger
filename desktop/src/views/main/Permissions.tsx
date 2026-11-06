// The permissions a recording needs (since v1.5, Phase 1B): one guided step per permission on a Mac (what it is for, Allow,
// a link to the right System Settings pane), the banner on the Record page that says what is missing and what a recording
// holds without it, and the one calm sentence when macOS has forgotten a permission (an unsigned app can lose them at an
// update).
import { useCallback, useEffect, useState } from "react";
import { api, type MissingPermission, type PermissionKind, type PermissionState, type PermissionsStatus } from "../../lib/ipc";
import { useWindowShown } from "../../lib/visibility";
import { AlertIcon, CloseIcon } from "../../components/Icons";
import { Sheet } from "./ProjectDialogs";

export interface PermissionStepText {
  title: string;
  forWhat: string;
  where: string;
  note?: string;
}

/** The guided steps, in the order onboarding walks them. */
export const PERMISSION_KINDS: readonly PermissionKind[] = ["microphone", "system_audio", "screen"];

export const PERMISSION_STEP: Record<PermissionKind, PermissionStepText> = {
  microphone: {
    title: "Microphone",
    forWhat: "So EchoLedger can record your side of a call, your voice notes and dictation. Without it a recording has only the other side.",
    where: "System Settings → Privacy & Security → Microphone",
  },
  system_audio: {
    title: "System audio",
    forWhat: "So EchoLedger can record the other side of a call: the sound this Mac plays. Without it a recording has only your voice.",
    where: "System Settings → Privacy & Security → Screen & System Audio Recording",
  },
  screen: {
    title: "Screen recording",
    forWhat: "Only for screen moments: what a meeting showed, kept beside the transcript. Recordings work without it.",
    where: "System Settings → Privacy & Security → Screen & System Audio Recording",
    note: "From macOS 15, macOS asks again about once a month whether EchoLedger may still see your screen. That is normal: choose Allow. If you miss it, screen moments pause and the recording goes on.",
  },
};

export function accessOf(permissions: PermissionsStatus | null, kind: PermissionKind): PermissionState {
  if (!permissions) return "unknown";
  if (kind === "microphone") return permissions.microphone;
  return (kind === "system_audio" ? permissions.systemAudio : permissions.screen) ?? "unknown";
}

const STATE_TEXT: Record<PermissionState, string> = {
  allowed: "Allowed",
  denied: "Off: turn on EchoLedger in System Settings",
  unknown: "Not asked yet",
};

/** The permissions as they stand: read when the window shows and again whenever it comes back into focus, which is what
 * coming back from System Settings looks like. */
export function usePermissions(): [PermissionsStatus | null, (next: PermissionsStatus) => void] {
  const [permissions, setPermissions] = useState<PermissionsStatus | null>(null);
  const shown = useWindowShown();
  useEffect(() => {
    if (!shown) return;
    let alive = true;
    const read = () => api.permissionsStatus().then((p) => alive && setPermissions(p)).catch(() => undefined);
    read();
    window.addEventListener("focus", read);
    return () => {
      alive = false;
      window.removeEventListener("focus", read);
    };
  }, [shown]);
  return [permissions, setPermissions];
}

/** One permission: what it is for, how it stands, Allow while macOS has not asked, and the System Settings pane. */
export function PermissionStep({ kind, permissions, onChange, compact = false }: {
  kind: PermissionKind;
  permissions: PermissionsStatus | null;
  onChange: (next: PermissionsStatus) => void;
  compact?: boolean;
}) {
  const [asking, setAsking] = useState(false);
  const text = PERMISSION_STEP[kind];
  const access = accessOf(permissions, kind);
  const allow = async () => {
    setAsking(true);
    try {
      onChange(await api.permissionRequest(kind));
    } catch {
      // Nothing to add: the state line stays as it was and System Settings is one click away.
    } finally {
      setAsking(false);
    }
  };
  return (
    <div className="permission-step" data-testid={`permission-${kind}`}>
      {compact && <b>{text.title}</b>}
      <p className="small">{text.forWhat}</p>
      {text.note && <p className="small muted" data-testid="permission-note">{text.note}</p>}
      <div className="permission-state small" data-testid={`permission-state-${kind}`}>
        <span className={`status-dot ${access === "allowed" ? "ok" : access === "denied" ? "failed" : ""}`} aria-hidden="true" />
        {STATE_TEXT[access]}
      </div>
      <div className="row permission-actions">
        {access === "unknown" && <button type="button" className="btn" disabled={asking} onClick={() => void allow()}>{asking ? "Asking…" : "Allow"}</button>}
        {access !== "allowed" && <button type="button" className="btn ghost" onClick={() => void api.openPrivacySettings(kind).catch(() => undefined)}>Open System Settings</button>}
      </div>
      {access !== "allowed" && <p className="small muted">{text.where}</p>}
    </div>
  );
}

/** All three steps at once, for when macOS has forgotten one. */
export function PermissionGuide({ permissions, onChange, onClose }: { permissions: PermissionsStatus | null; onChange: (next: PermissionsStatus) => void; onClose: () => void }) {
  return (
    <Sheet title="Set up permissions" onClose={onClose}>
      {PERMISSION_KINDS.map((kind) => <PermissionStep key={kind} kind={kind} permissions={permissions} onChange={onChange} compact />)}
      <div className="sheet-actions row"><span className="spacer" /><button type="button" className="btn primary" onClick={onClose}>Done</button></div>
    </Sheet>
  );
}

const SETTINGS_LABEL: Record<PermissionsStatus["platform"], string> = { macos: "Open System Settings", windows: "Open Windows Settings", other: "" };

/** Record page: what is missing and what a recording holds without it. */
export function PermissionBanner({ recordingRef }: { recordingRef: string | null }) {
  const [permissions, setPermissions] = useState<PermissionsStatus | null>(null);
  const shown = useWindowShown();
  useEffect(() => {
    if (!shown) return;
    let alive = true;
    const read = () => api.permissionsStatus().then((p) => alive && setPermissions(p)).catch(() => undefined);
    read();
    window.addEventListener("focus", read);
    return () => {
      alive = false;
      window.removeEventListener("focus", read);
    };
  }, [shown, recordingRef]);
  const missing: MissingPermission[] = permissions?.missing ?? [];
  if (missing.length === 0 || !permissions) return null;
  return (
    <div className="permission-banner" data-testid="permission-banner">
      {missing.map((m) => (
        <div className="banner warn" role="status" key={m.kind}>
          <AlertIcon size={16} />
          <span>{m.sentence}</span>
          <button type="button" className="btn compact" onClick={() => void api.openPrivacySettings(m.kind).catch(() => undefined)}>{SETTINGS_LABEL[permissions.platform] || "Open settings"}</button>
        </div>
      ))}
    </div>
  );
}

/** The main window: macOS forgot a permission that was granted. Said once per loss: dismissing it (or setting it up) is
 * the end of it until another one goes. */
export function PermissionLossNotice() {
  const [permissions, setPermissions] = usePermissions();
  const [guide, setGuide] = useState(false);
  const [dismissed, setDismissed] = useState(false);
  const dismiss = useCallback(() => {
    setDismissed(true);
    void api.permissionNoticeDismiss().catch(() => undefined);
  }, []);
  const closeGuide = useCallback(() => {
    setGuide(false);
    dismiss();
  }, [dismiss]);
  const lost = permissions?.lost;
  return (
    <>
      {lost && !dismissed && (
        <div className="banner warn" role="status" data-testid="permission-loss">
          <AlertIcon size={16} />
          <span>{lost}</span>
          <button type="button" className="btn compact" onClick={() => setGuide(true)}>Set up</button>
          <button type="button" className="banner-close" aria-label="Dismiss" onClick={dismiss}><CloseIcon size={14} /></button>
        </div>
      )}
      {guide && <PermissionGuide permissions={permissions} onChange={setPermissions} onClose={closeGuide} />}
    </>
  );
}
