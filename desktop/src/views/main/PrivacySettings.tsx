// Settings › App › Screen-share privacy (since v1.8.1): "Hide EchoLedger from screen sharing" and what this computer
// says about it, the last check with Check now, the meeting apps on a Mac, the one-window tip and the honest limits;
// and its sub-page Share Check ("See what others see"): the captured screen next to what you see, one plain line, and on a
// Mac the guided test per meeting app. The captured picture lives in this page's state only and goes when the page
// closes.
import { useEffect, useState } from "react";
import { api, errorMessage, type MeetingApp, type MeetingAppRow, type OldWindowsSharing, type PrivacyStatus, type Settings, type SettingsPatch, type ShareCheckView, type ShareHiding } from "../../lib/ipc";
import { checkedWhen, demoLeft, summary, usePrivacyStatus } from "../../lib/privacy";
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

/** The main window opened during a whole-screen share that keeps the floating windows off (since v1.8.1). */
export const SHARE_NOTICE = "You're sharing your whole screen, so this window may be visible in the screen share.";

/** The one plain line at the top of the main window while it is on screen during such a share. */
export function ShareNotice() {
  const status = usePrivacyStatus();
  if (!status?.mainInShare) return null;
  return (
    <StateBanner id="main-in-share" state="on" tone="warn" icon={<AlertIcon size={16} />} role="status" testId="main-in-share">
      <span>{SHARE_NOTICE}</span>
    </StateBanner>
  );
}

/** A status line's button (pure label). */
export const LINE_FIX_LABEL = { open_settings: "Open System Settings", hide_again: "Hide again" } as const;

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

function statusValue(s: PrivacyStatus): { text: string; tone?: "warning" } {
  if (s.hiding === "off") return { text: "Off", tone: "warning" };
  if (s.demoSecondsLeft) return { text: `Shown for ${demoLeft(s.demoSecondsLeft)?.replace(" left", "")}`, tone: "warning" };
  return s.badge === "visible" ? { text: s.badgeLabel, tone: "warning" } : { text: s.badgeLabel };
}

/** Settings › Screen-share privacy. */
export function SharePrivacyPage({ settings, patch, onCheck }: { settings: Settings; patch: Patch; onCheck: () => void }) {
  const status = usePrivacyStatus();
  const [copied, copy] = useCopied();
  const mac = status?.platform === "macos";
  const windows = status?.platform === "windows";
  const value = status ? statusValue(status) : null;
  const footer = [
    status?.hiding === "floating" ? "The main window shows in shares; captions, the pill and other floating windows stay hidden." : null,
    status?.hiding === "off" ? "Everything EchoLedger shows can be seen by people watching your shared screen." : null,
  ].filter(Boolean) as string[];
  return (
    <div data-testid="share-privacy">
      <p className="set-intro">
        Your notes stay on your screen. When you share your screen in Zoom, Meet or Teams, people see your other apps, not
        EchoLedger. EchoLedger checks this on your computer and tells you if it can't.
      </p>
      <Section footer={footer.length ? <>{footer.map((l) => <p key={l}>{l}</p>)}</> : undefined}>
        <SelectRow id="share_privacy.hiding" value={settings.hideFromSharing} options={options<ShareHiding>("share_privacy.hiding")} onChange={(v) => void patch({ hideFromSharing: v })} />
        <InfoRow id="share_privacy.status" value={value?.text} tone={value?.tone}>
          {status && <PrivacyBadgeView badge={status.badge} compact />}
        </InfoRow>
        {windows && status?.oldWindows && (
          <SelectRow id="share_privacy.old_windows" value={settings.oldWindowsSharing} options={options<OldWindowsSharing>("share_privacy.old_windows")} onChange={(v) => void patch({ oldWindowsSharing: v })} />
        )}
      </Section>
      {status && status.lines.length > 0 && (
        <div className="privacy-lines" role="status">
          {status.lines.map((l) => (
            <p key={l.text} className={l.warning ? "privacy-line warning" : "privacy-line"}>
              {l.text}
              {l.fix && <> <LineFix fix={l.fix} /></>}
            </p>
          ))}
        </div>
      )}
      <Section title="Check">
        <LinkRow id="share_privacy.check" value={status ? (status.lastCheck ? null : "Not checked yet") : null} onOpen={onCheck} chevron />
        <InfoRow id="share_privacy.last_check" value={lastCheckValue(status?.lastCheck?.result ?? null, status?.lastCheck?.at ?? null, new Date(), status?.lastCheck?.reason ?? null)}>
          <button type="button" className="btn compact" onClick={onCheck}>Check now</button>
        </InfoRow>
      </Section>
      {mac && status && (
        <Section title="Meeting apps" footer={<>
          <p>Each app captures your screen its own way. A 1-minute test with your phone tells you for sure.</p>
          {status.zoomInstalled && <p data-testid="zoom-steps">{ZOOM_SETTING}</p>}
        </>}>
          <div role="list" aria-label="Meeting apps">
            {status.apps.filter((a) => a.version || a.app === "meet").map((a) => <AppLine key={a.app} row={a} />)}
          </div>
          {status.zoomInstalled && (
            <InfoRow id="share_privacy.zoom" value="Advanced capture with window filtering" />
          )}
          <SwitchRow id="share_privacy.one_window" on={settings.shareOneWindow} onChange={(v) => void patch({ shareOneWindow: v })} />
        </Section>
      )}
      <Section title="Sharing" footer={<><p>{ONE_WINDOW_TIP}</p><p>{LIMITS}</p></>}>
        <ActionRow id="share_privacy.demo" value={status?.demoSecondsLeft ? demoLeft(status.demoSecondsLeft) : undefined}
          title={status?.demoSecondsLeft ? "Hide from shares again" : undefined}
          disabled={settings.hideFromSharing === "off"}
          onClick={() => void api.privacyShowInShares(status?.demoSecondsLeft ? 0 : 10).catch(() => undefined)} />
        <InfoRow id="share_privacy.hide_all" value={settings.shortcuts.hide_all ? <span className="kbd">{prettyShortcut(settings.shortcuts.hide_all, status?.platform ?? "windows")}</span> : "No shortcut"} />
        {status && status.displays > 1 && (
          <ActionRow id="share_privacy.displays" value="Then share the other display" onClick={() => void api.privacyMoveToDisplay(1).catch(() => undefined)} />
        )}
      </Section>
      <Section title="Diagnostics" footer={<p>{setting("share_privacy.diagnostics").description}</p>}>
        <SwitchRow id="share_privacy.diagnostics" on={settings.sendDiagnostics} onChange={(v) => void patch({ sendDiagnostics: v })} />
        <ActionRow id="share_privacy.copy" value={copied === "diag" ? "Copied" : undefined}
          onClick={() => void api.privacyDiagnostics().then((text) => copy("diag", text)).catch(() => undefined)} />
      </Section>
    </div>
  );
}

function AppLine({ row }: { row: MeetingAppRow }) {
  const tone = row.state === "hidden" ? "success" : row.state === "visible" ? "warning" : undefined;
  return (
    <div className="set-row set-entity" role="listitem" data-testid={`app-${row.app}`}>
      <span className="set-main"><span className="set-title">{row.line}</span>{row.version && <span className="set-sub">Version {row.version}</span>}</span>
      {tone && <span className="set-end"><Tag tone={tone}>{row.state === "hidden" ? "Tested" : "Shows"}</Tag></span>}
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
  // The picture goes with the page.
  useEffect(() => () => setView(null), []);
  useEffect(() => () => void api.shareCheckCard(false).catch(() => undefined), []);
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
  const line = view ? summary(view, platform) : null;
  const startGuided = async (app: MeetingApp) => {
    setGuided(app);
    await api.shareCheckCard(true).catch((e) => setProblem(errorMessage(e)));
  };
  const answer = async (visible: boolean) => {
    if (!guided) return;
    await api.shareCheckAnswer(guided, visible).catch((e) => setProblem(errorMessage(e)));
    setGuided(null);
  };
  return (
    <div data-testid="share-check">
      <p className="set-intro">
        EchoLedger opens a small test window and a green control for a moment, captures your screen the way meeting apps do,
        and shows you the result. The picture stays on this page and is gone when you close it.
      </p>
      <div className="check-run">
        <button type="button" className="btn primary" onClick={() => void run()} disabled={running}>{running ? "Checking…" : view ? "Check again" : "Check now"}</button>
        {problem && <p className="privacy-line warning" role="alert">{problem}</p>}
      </div>
      {view && line && (
        <div className={`check-result ${view.result}`} data-testid="check-result" role="status">
          <p className={`check-line ${line.tone}`}>{line.text}</p>
          {line.fix === "open_settings" && <button type="button" className="btn compact" onClick={() => void api.openPrivacySettings("screen").catch(() => undefined)}>Open Settings</button>}
          {line.fix === "try_again" && <button type="button" className="btn compact" onClick={() => void run()} disabled={running}>Try again</button>}
          {view.picture && <SidePictures view={view} />}
        </div>
      )}
      {platform === "macos" && status && (
        <Section title="Test with your phone" footer={<p>Join the same meeting on your phone, share your whole screen on this Mac, then say what your phone shows. The answer is kept for this app version and this macOS version.</p>}>
          {guided ? (
            <div className="set-row set-block guided" data-testid="guided">
              <p>Share your whole screen in {status.apps.find((a) => a.app === guided)?.name}. A test card is on your screen now. Can you see it on your phone?</p>
              <div className="guided-answers">
                <button type="button" className="btn compact" onClick={() => void answer(true)}>I can see it</button>
                <button type="button" className="btn compact" onClick={() => void answer(false)}>I can't see it</button>
              </div>
            </div>
          ) : (
            <div role="list" aria-label="Meeting apps to test">
              {status.apps.filter((a) => a.version).map((a) => (
                <div key={a.app} className="set-row set-entity" role="listitem">
                  <span className="set-main"><span className="set-title">{a.line}</span><span className="set-sub">Version {a.version}</span></span>
                  <span className="set-end"><button type="button" className="btn compact" onClick={() => void startGuided(a.app)}>{a.needsTest ? "Test" : "Test again"}</button></span>
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
  const line = view ? summary(view, platform) : null;
  return (
    <div className="onboarding-privacy" data-testid="onboarding-share-check">
      <h2 className="settings-group-title">Private notes</h2>
      <p className="small muted">Your notes stay on your screen. When you share your screen in Zoom, Meet or Teams, people see your other apps, not EchoLedger. EchoLedger checks this on your computer and tells you if it can't.</p>
      <button type="button" className="btn" onClick={() => void run()} disabled={running}>{running ? "Checking…" : view ? "Check again" : "Check screen-share privacy"}</button>
      {line && <p className={`check-line small ${line.tone}`} role="status">{line.text}</p>}
      {problem && <p className="privacy-line warning" role="alert">{problem}</p>}
    </div>
  );
}
