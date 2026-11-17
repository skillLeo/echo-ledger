// Settings › App › Screen-share privacy (since v1.8.1): "Hide EchoLedger from screen sharing" and what this computer
// says about it, the last check, the meeting apps on a Mac, "Hide EchoLedger windows", the one-window tip and the honest
// limits; and its sub-page "Check screen-share privacy" ("See what others see"): the captured screen next to what you
// see, one plain line, and on a Mac the test with the phone per meeting app. The captured picture lives in this page's
// state only and goes when the page closes. Names: docs/DESIGN_SYSTEM.md "Screen-share privacy names".
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, type MeetingApp, type MeetingAppRow, type OldWindowsSharing, type PrivacyStatus, type Settings, type SettingsPatch, type ShareCheckView, type ShareHiding } from "../../lib/ipc";
import { checkedWhen, demoLeft, resultLine, summary, usePrivacyStatus } from "../../lib/privacy";
import { setting } from "../../lib/settingsRegistry";
import { prettyShortcut } from "../../lib/format";
import { ActionRow, InfoRow, LinkRow, Section, SelectRow, SwitchRow, Tag, useCopied } from "./SettingsControls";
import { PrivacyBadgeView } from "../../components/PrivacyBadge";
import { StateBanner } from "../../components/StateBanner";
import { AlertIcon } from "../../components/Icons";

type Patch = (p: SettingsPatch) => Promise<boolean>;
const options = <T extends string>(id: string) => (setting(id).options ?? []).map((o) => [o.value as T, o.label] as const);

/** The one-window tip and the limits, in the words the plan gives them. */
export const ONE_WINDOW_TIP = "Share one window or a tab for the strongest privacy.";
export const LIMITS = "Not hidden from cameras, projectors, Remote Desktop, or monitoring software on your computer.";
/** Zoom on a Mac: the one setting that keeps EchoLedger out of a whole-screen share (labels of Zoom 6 and 7). */
export const ZOOM_SETTING = "In Zoom, open Settings › Share Screen › Advanced and set Screen capture mode to Advanced capture with window filtering. EchoLedger can't read or change Zoom's settings, so test it once with your phone.";

/** The test with the phone shows a card on screen: who else is in the meeting sees it too. */
export const GUIDED_ALONE = "Use a meeting with nobody else in it, so only you see the test card.";
/** What the check puts on the screen for a moment (said before it does). */
export const PATTERN_FLASH = "While it checks, a checkered test window and a green box flash on your screen for a moment.";

/** The main window opened during a whole-screen share that keeps the floating windows off (since v1.8.1). */
export const SHARE_NOTICE = "You're sharing your whole screen, so this window may be visible in the screen share.";

/** While the sharing bar keeps the windows off, "Show EchoLedger windows" would last until the next look: what happens,
 * in the tray's words (R1, tray.rs HIDDEN_WHILE_SHARING). */
export const HIDDEN_WHILE_SHARING = "Hidden while you share your screen. They come back when the meeting ends.";

/** The label of every button that opens the system's settings on this page. */
export const OPEN_SYSTEM_SETTINGS = "Open System Settings";

/** The one plain line at the top of the main window while it is on screen during such a share. Closed, it stays closed
 * for that share only: the next share says it again. */
export function ShareNotice() {
  const status = usePrivacyStatus();
  if (!status?.mainInShare) return null;
  return (
    <StateBanner id="main-in-share" slot="main-in-share" state={`share-${status.shareNumber}`} tone="warn" icon={<AlertIcon size={16} />} role="status" testId="main-in-share">
      <span>{SHARE_NOTICE}</span>
    </StateBanner>
  );
}

/** A status line's button (pure label). */
export const LINE_FIX_LABEL = { open_settings: OPEN_SYSTEM_SETTINGS, hide_again: "Hide again" } as const;

function LineFix({ fix }: { fix: "open_settings" | "hide_again" }) {
  const [problem, setProblem] = useState<string | null>(null);
  const press = () => {
    setProblem(null);
    const done = fix === "open_settings" ? api.openPrivacySettings("screen") : api.privacyHideAgain();
    void done.catch((e) => setProblem(errorMessage(e)));
  };
  return (
    <>
      <button type="button" className="btn compact" onClick={press}>{LINE_FIX_LABEL[fix]}</button>
      {problem && <span className="privacy-line warning" role="alert">{problem}</span>}
    </>
  );
}

/** The last check in a few words, for its row (pure). */
export function lastCheckValue(result: "pass" | "fail" | "error" | null, at: string | null, now: Date = new Date(), reason: string | null = null): string {
  if (!result || !at) return "Not checked yet";
  if (result === "pass" && reason === "old_windows") return `Black box, ${checkedWhen(at, now)}`;
  if (result === "pass") return `Hidden, ${checkedWhen(at, now)}`;
  if (result === "fail") return `Visible, ${checkedWhen(at, now)}`;
  return "Nothing measured";
}

export function statusValue(s: PrivacyStatus, demoSeconds: number | null): { text: string; tone?: "warning" } {
  if (s.hiding === "off") return { text: "Off", tone: "warning" };
  if (demoSeconds) return { text: `Shown for ${demoLeft(demoSeconds)?.replace(" left", "")}`, tone: "warning" };
  return s.badge === "visible" ? { text: s.badgeLabel, tone: "warning" } : { text: s.badgeLabel };
}

/** The demo's time left, counted down here between the core's updates. */
export function useCountdown(seconds: number | null): number | null {
  const [left, setLeft] = useState(seconds);
  useEffect(() => {
    setLeft(seconds);
    if (!seconds) return;
    const started = Date.now();
    const id = window.setInterval(() => setLeft(Math.max(0, seconds - Math.floor((Date.now() - started) / 1000))), 1000);
    return () => window.clearInterval(id);
  }, [seconds]);
  return left ? left : null;
}

/** Why EchoLedger's windows are off the screen, in the tray's order (pure; tray.rs `hidden_line`): the person's "Hide
 * EchoLedger windows" first (Show brings them back), then a whole-screen share whose sharing bar is on screen (Show would
 * last until the next look, so the line says when they come back), then a share whose bar went (Show works). */
export function hiddenReason(s: PrivacyStatus | null): "person" | "share_bar" | "share" | null {
  if (s?.hiddenByShortcut) return "person";
  if (s?.barOnScreen) return "share_bar";
  if (s?.hiddenForShare) return "share";
  return null;
}

/** What "Hide EchoLedger windows" says now (pure): hidden, and why; none while the windows are on screen. While the
 * sharing bar holds them, the section's line says why and when they come back, so the row only says Hidden. */
export function hiddenValue(s: PrivacyStatus | null): string | null {
  switch (hiddenReason(s)) {
    case "person":
    case "share_bar":
      return "Hidden";
    case "share":
      return "Hidden while you share your screen";
    default:
      return null;
  }
}

/** "Move to the other display": what it says before and after (pure). */
export function displaysValue(floatingOpen: boolean, moved: boolean): string {
  if (!floatingOpen) return "Nothing open to move";
  return moved ? "Moved: share your main display" : "Then share your main display";
}

/** Settings › Screen-share privacy. */
export function SharePrivacyPage({ settings, patch, onCheck }: { settings: Settings; patch: Patch; onCheck: () => void }) {
  const status = usePrivacyStatus();
  const [copied, copy] = useCopied();
  const [moved, setMoved] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const demoSeconds = useCountdown(status?.demoSecondsLeft ?? null);
  const mac = status?.platform === "macos";
  const windows = status?.platform === "windows";
  const value = status ? statusValue(status, demoSeconds) : null;
  const hidden = hiddenValue(status);
  const barHolds = hiddenReason(status) === "share_bar";
  const shortcut = settings.shortcuts.hide_all ? <span className="kbd">{prettyShortcut(settings.shortcuts.hide_all, status?.platform ?? "windows")}</span> : "No shortcut";
  const footer = [
    status?.hiding === "floating" ? "The main window is visible in screen share; captions, the recording controls and the other floating windows stay hidden." : null,
    status?.hiding === "off" ? "Everything EchoLedger shows can be seen by people watching your screen share." : null,
  ].filter(Boolean) as string[];
  const lines = status?.lines ?? [];
  const act = (done: Promise<unknown>, after?: () => void) => {
    setProblem(null);
    void done.then(() => after?.()).catch((e) => setProblem(errorMessage(e)));
  };
  return (
    <div data-testid="share-privacy">
      <p className="set-intro">
        Your notes stay on your screen. When you share your screen in Zoom, Meet or Teams, people see your other apps, not
        EchoLedger. EchoLedger checks this on your computer and tells you if it can't.
      </p>
      <Section footer={footer.length || lines.length ? <>
        {footer.map((l) => <p key={l}>{l}</p>)}
        {lines.length > 0 && (
          <div className="privacy-lines" role="status">
            {lines.map((l) => (
              <p key={l.text} className={l.warning ? "privacy-line warning" : "privacy-line"}>
                {l.text}
                {l.fix && <> <LineFix fix={l.fix} /></>}
              </p>
            ))}
          </div>
        )}
      </> : undefined}>
        <SelectRow id="share_privacy.hiding" value={settings.hideFromSharing} options={options<ShareHiding>("share_privacy.hiding")} onChange={(v) => void patch({ hideFromSharing: v })} />
        <InfoRow id="share_privacy.status" value={value?.text} tone={value?.tone}>
          {status && <PrivacyBadgeView badge={status.badge} compact />}
        </InfoRow>
        {windows && status?.oldWindows && (
          <SelectRow id="share_privacy.old_windows" value={settings.oldWindowsSharing} options={options<OldWindowsSharing>("share_privacy.old_windows")} onChange={(v) => void patch({ oldWindowsSharing: v })} />
        )}
      </Section>
      <Section title="Check">
        <LinkRow id="share_privacy.check" value={lastCheckValue(status?.lastCheck?.result ?? null, status?.lastCheck?.at ?? null, new Date(), status?.lastCheck?.reason ?? null)} onOpen={onCheck} chevron />
      </Section>
      {mac && status && (
        <Section title="Meeting apps" footer={<>
          <p>Each app captures your screen its own way. A 1-minute test with your phone tells you for sure.</p>
          <p>{setting("share_privacy.one_window").description}</p>
        </>}>
          <div role="list" aria-label="Meeting apps">
            {status.apps.filter((a) => a.version || a.app === "meet").map((a) => <AppLine key={a.app} row={a} />)}
          </div>
          {status.zoomInstalled && <ZoomStep />}
          <SwitchRow id="share_privacy.one_window" on={settings.shareOneWindow} onChange={(v) => void patch({ shareOneWindow: v })} />
        </Section>
      )}
      <Section title="Sharing" footer={<>
        {barHolds && <p className="privacy-line" role="status" data-testid="back-after-share">{HIDDEN_WHILE_SHARING}</p>}
        <p>{ONE_WINDOW_TIP}</p><p>{LIMITS}</p>
        {problem && <p className="privacy-line warning" role="alert">{problem}</p>}
      </>}>
        <ActionRow id="share_privacy.demo" status={demoSeconds ? demoLeft(demoSeconds) : undefined}
          title={demoSeconds ? "Hide from screen share again" : undefined}
          disabled={settings.hideFromSharing === "off"}
          onClick={() => act(api.privacyShowInShares(demoSeconds ? 0 : 10))} />
        <InfoRow id="share_privacy.hide_all" value={hidden ?? shortcut}>
          {/* While the sharing bar alone holds them, Show would last until the next look: the footer says when they come
              back. After the person's own Hide, Show ends that, as in the tray. */}
          {!barHolds && (
            <button type="button" className="btn compact" onClick={() => act(api.privacySetHidden(!hidden))}>{hidden ? "Show EchoLedger windows" : "Hide now"}</button>
          )}
        </InfoRow>
        {status && status.displays > 1 && (
          <ActionRow id="share_privacy.displays" status={displaysValue(status.floatingOpen, moved)} disabled={!status.floatingOpen}
            onClick={() => act(api.privacyMoveToOtherDisplay(), () => setMoved(true))} />
        )}
      </Section>
      <Section title="Diagnostics" footer={<p>{setting("share_privacy.diagnostics").description}</p>}>
        <SwitchRow id="share_privacy.diagnostics" on={settings.sendDiagnostics} onChange={(v) => void patch({ sendDiagnostics: v })} />
        <ActionRow id="share_privacy.copy" status={copied === "diag" ? "Copied" : undefined}
          onClick={() => act(api.privacyDiagnostics().then((text) => copy("diag", text)))} />
      </Section>
    </div>
  );
}

/** Zoom on a Mac: one step, opened in place (EchoLedger can't read Zoom's setting, so it never claims it is set). */
function ZoomStep() {
  const [open, setOpen] = useState(false);
  return (
    <>
      <ActionRow id="share_privacy.zoom" value="1 setting" expanded={open} onClick={() => setOpen((o) => !o)} />
      {open && <div className="set-row set-block" data-testid="zoom-steps"><p>{ZOOM_SETTING}</p></div>}
    </>
  );
}

/** The version a meeting app's result is kept for: Chrome's for Meet, which runs in it. */
export function versionLine(row: MeetingAppRow): string | null {
  if (!row.version) return null;
  return row.app === "meet" ? `Chrome ${row.version}` : `Version ${row.version}`;
}

function AppLine({ row }: { row: MeetingAppRow }) {
  const tone = row.state === "hidden" ? "success" : row.state === "visible" ? "warning" : undefined;
  const version = versionLine(row);
  return (
    <div className="set-row set-entity" role="listitem" data-testid={`app-${row.app}`}>
      <span className="set-main"><span className="set-title">{row.line}</span>{version && <span className="set-sub">{version}</span>}</span>
      {tone && <span className="set-end"><Tag tone={tone}>{row.state === "hidden" ? "Hidden" : "Visible"}</Tag></span>}
    </div>
  );
}

/** Screen-share privacy › Check screen-share privacy (Share Check). */
export function ShareCheckPage() {
  const status = usePrivacyStatus();
  const [view, setView] = useState<ShareCheckView | null>(null);
  const [running, setRunning] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [guided, setGuided] = useState<MeetingApp | null>(null);
  const question = useRef<HTMLParagraphElement>(null);
  // The picture goes with the page.
  useEffect(() => () => setView(null), []);
  useEffect(() => () => void api.shareCheckCard(false).catch(() => undefined), []);
  // The question takes the focus once the card is up, so a keyboard or a screen reader lands on it.
  useEffect(() => {
    if (guided) question.current?.focus();
  }, [guided]);
  const run = async () => {
    setRunning(true);
    setProblem(null);
    try {
      setView(await api.shareCheckRun("asked"));
    } catch (e) {
      setProblem(errorMessage(e));
    } finally {
      setRunning(false);
    }
  };
  const platform = status?.platform ?? "other";
  const line = view ? summary(view, platform, new Date(), status?.apps ?? []) : null;
  // An automatic run counts too: one check at a time.
  const busy = running || !!status?.checkRunning;
  // Before any run on a Mac without Screen Recording: what is missing, with its fix.
  const permissionFirst = platform === "macos" && status && !status.permission && !view
    ? resultLine("error", "permission_missing", status.system, new Date().toISOString(), platform) : null;
  const startGuided = async (app: MeetingApp) => {
    setProblem(null);
    try {
      await api.shareCheckCard(true);
      setGuided(app);
    } catch (e) {
      setProblem(errorMessage(e));
    }
  };
  const answer = async (visible: boolean) => {
    if (!guided) return;
    await api.shareCheckAnswer(guided, visible).catch((e) => setProblem(errorMessage(e)));
    setGuided(null);
  };
  const cancel = () => {
    setGuided(null);
    void api.shareCheckCard(false).catch(() => undefined);
  };
  return (
    <div data-testid="share-check">
      <p className="set-intro">
        EchoLedger opens a small test window and a green control for a moment, captures your screen the way meeting apps do,
        and shows you the result. The picture stays on this page and is gone when you close it.
      </p>
      <div className="check-run">
        <button type="button" className="btn primary" onClick={() => void run()} disabled={busy}>{busy ? "Checking…" : view ? "Check again" : "Check now"}</button>
        {problem && <p className="privacy-line warning" role="alert">{problem}</p>}
        {permissionFirst && (
          <p className="privacy-line warning" data-testid="permission-first">
            {permissionFirst.text} <button type="button" className="btn compact" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>{OPEN_SYSTEM_SETTINGS}</button>
          </p>
        )}
      </div>
      {view && line && (
        <div className={`check-result ${view.result}`} data-testid="check-result" role="status">
          <p className={`check-line ${line.tone}`}>{line.text}</p>
          {line.fix === "open_settings" && <button type="button" className="btn compact" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>{OPEN_SYSTEM_SETTINGS}</button>}
          {line.fix === "try_again" && <button type="button" className="btn compact" onClick={() => void run()} disabled={busy}>Try again</button>}
          {view.picture && <SidePictures view={view} />}
        </div>
      )}
      {platform === "macos" && status && (
        <Section title="Test with your phone" footer={<p>Join the same meeting on your phone, share your whole screen on this Mac, then say what your phone shows. The answer is kept for this app version and this macOS version.</p>}>
          {guided ? (
            <div className="set-row set-block guided" data-testid="guided">
              <p ref={question} tabIndex={-1}>Share your whole screen in {status.apps.find((a) => a.app === guided)?.name}. A test card is on your screen now. Can you see it on your phone?</p>
              <p className="small muted">{GUIDED_ALONE}</p>
              <div className="guided-answers">
                <button type="button" className="btn compact" onClick={() => void answer(true)}>I can see it</button>
                <button type="button" className="btn compact" onClick={() => void answer(false)}>I can't see it</button>
                <button type="button" className="btn compact ghost" onClick={cancel}>Cancel</button>
              </div>
            </div>
          ) : (
            <div role="list" aria-label="Meeting apps to test">
              {status.apps.filter((a) => a.version).map((a) => (
                <div key={a.app} className="set-row set-entity" role="listitem">
                  <span className="set-main"><span className="set-title">{a.line}</span><span className="set-sub">{versionLine(a)}</span></span>
                  <span className="set-end"><button type="button" className="btn compact" onClick={() => void startGuided(a.app)} disabled={busy}>{a.needsTest ? "Test" : "Test again"}</button></span>
                </div>
              ))}
              {status.apps.every((a) => !a.version) && <div className="set-row"><span className="set-title muted">No meeting apps found on this Mac.</span></div>}
            </div>
          )}
        </Section>
      )}
    </div>
  );
}

/** "What you see" next to "What others see": the same captured screen, with the test window drawn where you see it, and
 * as the capture got it, the test area outlined. */
function SidePictures({ view }: { view: ShareCheckView }) {
  const area = (a: [number, number, number, number] | null) => (a ? { left: `${a[0] * 100}%`, top: `${a[1] * 100}%`, width: `${a[2] * 100}%`, height: `${a[3] * 100}%` } : undefined);
  return (
    <div className="check-pictures">
      <figure>
        <div className="check-frame">
          <img src={view.picture!} alt="" />
          <span className="check-patch" style={area(view.testArea)} />
        </div>
        <figcaption>What you see</figcaption>
      </figure>
      <figure>
        <div className="check-frame">
          <img src={view.picture!} alt="Your screen as a screen share captured it" />
          <span className="check-outline" style={area(view.testArea)} />
        </div>
        <figcaption>What others see</figcaption>
      </figure>
    </div>
  );
}

/** What the Settings root shows next to "Screen-share privacy". */
export function sharePrivacyValue(settings: Settings | null): string {
  if (!settings) return "";
  return settings.hideFromSharing === "off" ? "Off" : settings.hideFromSharing === "floating" ? "Floating windows" : "All windows";
}

/** Onboarding's Done step: what private notes means, and the check run once from here. */
export function OnboardingShareCheck({ platform }: { platform: string }) {
  const status = usePrivacyStatus();
  const [view, setView] = useState<ShareCheckView | null>(null);
  const [running, setRunning] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const run = async () => {
    setRunning(true);
    setProblem(null);
    try {
      setView(await api.shareCheckRun("onboarding"));
    } catch (e) {
      setProblem(errorMessage(e));
    } finally {
      setRunning(false);
    }
  };
  const line = view ? summary(view, platform, new Date(), status?.apps ?? [], "onboarding") : null;
  const busy = running || !!status?.checkRunning;
  return (
    <div className="onboarding-privacy" data-testid="onboarding-share-check">
      <h2 className="settings-group-title">Private notes</h2>
      <p className="small muted">Your notes stay on your screen. When you share your screen in Zoom, Meet or Teams, people see your other apps, not EchoLedger. EchoLedger checks this on your computer and tells you if it can't.</p>
      <p className="small muted">{PATTERN_FLASH}</p>
      <button type="button" className="btn" onClick={() => void run()} disabled={busy}>{busy ? "Checking…" : view ? "Check again" : "Check screen-share privacy"}</button>
      {line && (
        <p className={`check-line small ${line.tone}`} role="status">
          {line.text}
          {line.fix === "open_settings" && <> <button type="button" className="btn compact" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>{OPEN_SYSTEM_SETTINGS}</button></>}
          {line.fix === "try_again" && <> <button type="button" className="btn compact" onClick={() => void run()} disabled={busy}>Try again</button></>}
        </p>
      )}
      {problem && <p className="privacy-line warning" role="alert">{problem}</p>}
    </div>
  );
}
