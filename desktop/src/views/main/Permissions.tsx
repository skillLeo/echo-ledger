// The permissions a recording needs (since v1.5, Phase 1B): one guided step per permission on a Mac (what it is for, Allow,
// a link to the right System Settings pane), the banner on the Record page that says what is missing and what a recording
// holds without it, and the one calm sentence when macOS has forgotten a permission (an unsigned app can lose them at an
// update).
import { useCallback, useEffect, useState } from "react";
import { api, errorMessage, listenTo, type MissingPermission, type PermissionKind, type PermissionState, type PermissionsStatus } from "../../lib/ipc";
import { useWindowShown } from "../../lib/visibility";
import { AlertIcon } from "../../components/Icons";
import { Sheet } from "./ProjectDialogs";
import { Notice } from "../../components/Notice";
import { SETTINGS_LABEL, otherSideNotRecorded } from "../../lib/controls";
import { StateBanner } from "../../components/StateBanner";
import { useBannerTurn } from "../../components/BannerSlot";

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

/** Allowed before on this Mac, "not asked" now (D-120): maybe another copy of EchoLedger that System Settings still
 * lists, maybe a reset of the privacy settings. Said as a possibility, with what to do either way. */
export const ALLOWED_BEFORE = "Allowed before on this Mac. If System Settings already lists EchoLedger, remove it there, then click Allow.";

/** Screen Recording switched on in System Settings while EchoLedger ran: macOS applies it when the app opens again. */
export const REOPEN = "Allowed in System Settings. Quit and reopen EchoLedger to finish.";

/** System audio or Screen Recording when macOS does not say how it stands (D-136): only where to look. */
export const CANNOT_TELL = "macOS does not say whether EchoLedger may use this. Check it in System Settings.";

/** Whether macOS can say "not asked yet" for this permission (pure): the microphone always; system audio and Screen
 * Recording only through the privacy call (D-136). */
export function canTellNotAsked(permissions: PermissionsStatus | null, kind: PermissionKind): boolean {
  return kind === "microphone" || permissions?.platform !== "macos" || permissions.canTellNotAsked !== false;
}

/** The same after EchoLedger was reopened: reopening did not apply it, so it is not offered again (no endless loop). */
export const SWITCH_OFF_ON = "Allowed in System Settings, but macOS has not applied it, even after EchoLedger reopened. Switch EchoLedger off and on again there.";

const STATE_TEXT: Record<PermissionState, string> = {
  allowed: "Allowed",
  denied: "Off: turn on EchoLedger in System Settings",
  unknown: "Not asked yet",
  reopen: REOPEN,
};

/** Windows: the switch for the microphone is in Windows Settings, and Windows never says "not asked". */
export const WINDOWS_OFF = "Off: turn on EchoLedger in Windows Settings";
export const WINDOWS_CANNOT_TELL = "Windows doesn't say whether EchoLedger may use this. Check it in Windows Settings.";

export type StepAction = "reopen" | "settings" | "allow";

/** What one permission step says and offers, in order; the first action is the main one when `main` is set. */
export interface StepPlan {
  access: PermissionState;
  words: string;
  actions: StepAction[];
  /** The action drawn as the filled button (none while nothing is urgent). */
  main: StepAction | null;
  /** "Open System Settings" or "Open Windows Settings". */
  settingsLabel: string;
  /** Show where the switch is ("System Settings → Privacy & Security → …"). */
  showWhere: boolean;
}

/**
 * The one rule for a permission step, for every screen that shows one (the classic step and Setup & health): the
 * words (never a guess: D-120 allowed before, D-136 cannot tell) and the buttons. Allow only where asking works (a Mac
 * that can say "not asked", or allowed before); Quit & reopen once, then the switch off and on (no endless loop).
 */
export function stepPlan(permissions: PermissionsStatus | null, kind: PermissionKind): StepPlan {
  const access = accessOf(permissions, kind);
  if (permissions?.platform === "windows") {
    const words = access === "allowed" ? STATE_TEXT.allowed : access === "denied" ? WINDOWS_OFF : WINDOWS_CANNOT_TELL;
    const actions: StepAction[] = access === "allowed" ? [] : ["settings"];
    return { access, words, actions, main: actions[0] ?? null, settingsLabel: "Open Windows Settings", showWhere: false };
  }
  const before = access === "unknown" && !!permissions?.allowedBefore?.includes(kind);
  const stuck = access === "reopen" && !!permissions?.reopenDidNotApply;
  const untold = access === "unknown" && !canTellNotAsked(permissions, kind);
  const plan = (words: string, actions: StepAction[], main: StepAction | null): StepPlan => ({
    access, words, actions, main, settingsLabel: "Open System Settings", showWhere: access !== "allowed" && (access !== "reopen" || stuck),
  });
  if (access === "allowed") return plan(STATE_TEXT.allowed, [], null);
  if (stuck) return plan(SWITCH_OFF_ON, ["settings"], "settings");
  if (access === "reopen") return plan(REOPEN, ["reopen"], "reopen");
  if (access === "denied") return plan(STATE_TEXT.denied, ["settings"], "settings");
  if (before) return plan(ALLOWED_BEFORE, ["settings", "allow"], "settings");
  if (untold) return plan(CANNOT_TELL, ["settings", "allow"], "settings");
  return plan(STATE_TEXT.unknown, ["allow", "settings"], "allow");
}

/** The permissions as they stand: read when the window shows and again whenever it comes back into focus, which is what
 * coming back from System Settings looks like. `readAgain` asks for one more read when it changes. */
export function usePermissions(readAgain?: string | null): [PermissionsStatus | null, (next: PermissionsStatus) => void] {
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
  }, [shown, readAgain]);
  return [permissions, setPermissions];
}

/** One permission: what it is for, how it stands, Allow while macOS has not asked, the System Settings pane, and Quit &
 * reopen when Screen Recording was switched on while EchoLedger ran. */
export function PermissionStep({ kind, permissions, onChange, compact = false }: {
  kind: PermissionKind;
  permissions: PermissionsStatus | null;
  onChange: (next: PermissionsStatus) => void;
  compact?: boolean;
}) {
  const [asking, setAsking] = useState(false);
  const [reopenRefused, setReopenRefused] = useState<string | null>(null);
  const text = PERMISSION_STEP[kind];
  const step = stepPlan(permissions, kind);
  const access = step.access;
  const reopen = async () => {
    setReopenRefused(null);
    try {
      await api.quitAndReopen();
    } catch (e) {
      setReopenRefused(errorMessage(e));
    }
  };
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
  const button = (action: StepAction) => {
    // The classic look stays as it was (only the new screens fill the main button): Quit & reopen filled; System
    // Settings filled where it comes first (allowed before, cannot tell, stuck), ghost beside Allow, plain when off.
    const settingsFirst = step.main === "settings" && (access === "reopen" || (access === "unknown" && permissions?.platform !== "windows"));
    const cls = action === "reopen" ? "btn primary" : action === "allow" ? "btn"
      : settingsFirst ? "btn primary" : access === "unknown" ? "btn ghost" : "btn";
    switch (action) {
      case "reopen": return <button key={action} type="button" className={cls} onClick={() => void reopen()}>Quit &amp; reopen</button>;
      case "allow": return <button key={action} type="button" className={cls} disabled={asking} onClick={() => void allow()}>{asking ? "Asking…" : "Allow"}</button>;
      case "settings": return <button key={action} type="button" className={cls} onClick={() => void api.openPrivacySettings(kind).catch(() => undefined)}>{step.settingsLabel}</button>;
    }
  };
  return (
    <div className="permission-step" data-testid={`permission-${kind}`}>
      {compact && <b>{text.title}</b>}
      <p className="small">{text.forWhat}</p>
      {text.note && <p className="small muted" data-testid="permission-note">{text.note}</p>}
      <div className="permission-state small" data-testid={`permission-state-${kind}`}>
        <span className={`status-dot ${access === "allowed" ? "ok" : access === "denied" ? "failed" : access === "reopen" ? "ask" : ""}`} aria-hidden="true" />
        {step.words}
      </div>
      <div className="row permission-actions">{step.actions.map(button)}</div>
      {reopenRefused && <p className="small error-text" role="alert">{reopenRefused}</p>}
      {step.showWhere && <p className="small muted">{text.where}</p>}
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

export { SETTINGS_LABEL };

/** Record page: what is missing and what a recording holds without it. */
export function PermissionBanner({ recordingRef }: { recordingRef: string | null }) {
  // A prompt answered after the start, or a switch turned off meanwhile, comes with the recording's state.
  const [recordingMissing, setRecordingMissing] = useState("");
  useEffect(() => {
    const un = listenTo("recording:state", (state) => setRecordingMissing(state.active ? (state.missing ?? []).map((m) => m.kind).join() : ""));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  const [permissions] = usePermissions(`${recordingRef ?? ""}:${recordingMissing}`);
  const missing: MissingPermission[] = permissions?.missing ?? [];
  if (missing.length === 0 || !permissions) return null;
  return (
    <div className="permission-banner" data-testid="permission-banner">
      {/* A state: no timer; closed until it changes; a click opens the fix (v1.6.1 Notices). */}
      {missing.map((m) => {
        const fix = () => void api.openPrivacySettings(m.kind).catch(() => undefined);
        return (
          <StateBanner key={m.kind} id={`permission-${m.kind}`} state={m.sentence} role="status" icon={<AlertIcon size={16} />} onOpen={fix}
            actions={<button type="button" className="btn compact" onClick={fix}>{SETTINGS_LABEL[permissions.platform] || "Open settings"}</button>}>
            <span>{m.sentence}</span>
          </StateBanner>
        );
      })}
    </div>
  );
}

/**
 * The main window, while a call records and the recording itself found its other side not recorded (2.0.6: a tap
 * held back, System Audio Recording). It stays until the other side is heard, with Open System Settings (System Audio
 * Recording's own pane) and Try again; after a try that did not help its words say to quit and reopen.
 */
export function OtherSideBanner() {
  const [warning, setWarning] = useState<MissingPermission | null>(null);
  useEffect(() => {
    const un = listenTo("recording:state", (state) => setWarning(state.active ? otherSideNotRecorded(state.missing) : null));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  // Closed by hand it stays closed until its words change (a try that did not help says to quit and reopen).
  const [closed, setClosed] = useState<string | null>(null);
  const shown = useBannerTurn("other-side", !!warning && closed !== warning.sentence);
  if (!shown || !warning) return null;
  const open = () => void api.openPrivacySettings("system_audio").catch(() => undefined);
  return (
    <Notice kind="banner" tone="danger" className="banner danger" role="alert" testId="other-side-not-recorded" icon={<AlertIcon size={16} />} timeoutMs={null}
      onOpen={open} openLabel={`${warning.sentence} Open System Settings`} onDismiss={() => setClosed(warning.sentence)}
      actions={<>
        <button type="button" className="btn compact" onClick={open}>Open System Settings</button>
        <button type="button" className="btn compact" onClick={() => void api.captureRetrySystem().catch(() => undefined)}>Try again</button>
      </>}>
      <span>{warning.sentence}</span>
    </Notice>
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
  const shown = useBannerTurn("permission-loss", !!lost && !dismissed);
  return (
    <>
      {shown && (
        <Notice kind="banner" tone="warn" className="banner warn" role="status" testId="permission-loss" icon={<AlertIcon size={16} />} timeoutMs={null}
          onOpen={() => setGuide(true)} onDismiss={dismiss}
          actions={<button type="button" className="btn compact" onClick={() => setGuide(true)}>Set up</button>}>
          <span>{lost}</span>
        </Notice>
      )}
      {guide && <PermissionGuide permissions={permissions} onChange={setPermissions} onClose={closeGuide} />}
    </>
  );
}
