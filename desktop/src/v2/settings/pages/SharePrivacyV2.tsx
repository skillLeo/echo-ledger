// Settings › Screen-share privacy in the new look (the v1.8.1 headline), with today's data and rules
// (PrivacySettings.tsx): "Hide from screen sharing" and what this computer says about it now, the last check, the
// meeting apps and Zoom's one setting on a Mac, showing EchoLedger in a share for 10 minutes, "Hide EchoLedger windows",
// moving to the other display, the one-window tip and the honest limits, and diagnostics. Its sub-page "Check
// screen-share privacy" shows the captured screen next to what you see, in one plain line, and on a Mac the test with
// the phone per meeting app. The captured picture lives in the page's state only and goes when the page closes.
// Names: docs/DESIGN_SYSTEM.md "Screen-share privacy names" (never "overlay").
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, type MeetingApp, type MeetingAppRow, type OldWindowsSharing, type Settings, type ShareCheckView, type ShareHiding } from "../../../lib/ipc";
import { demoLeft, resultLine, summary, usePrivacyStatus } from "../../../lib/privacy";
import { setting } from "../../../lib/settingsRegistry";
import { prettyShortcut } from "../../../lib/format";
import { useCopied } from "../../../views/main/SettingsControls";
import { PrivacyBadgeView } from "../../../components/PrivacyBadge";
import {
  GUIDED_ALONE, HIDDEN_WHILE_SHARING, LIMITS, LINE_FIX_LABEL, ONE_WINDOW_TIP, OPEN_SYSTEM_SETTINGS, ZOOM_SETTING,
  displaysValue, hiddenReason, hiddenValue, lastCheckValue, statusValue, useCountdown, versionLine,
} from "../../../views/main/PrivacySettings";
import { Badge, Button, Card, Icons, List, ListRow } from "../../components";
import { Group, LinkSetting, SelectSetting, SwitchSetting, type Patch } from "./rows";

/** A status line's fix: the system's settings, or hiding the windows again. */
function LineFix({ fix }: { fix: "open_settings" | "hide_again" }) {
  const [problem, setProblem] = useState<string | null>(null);
  const press = () => {
    setProblem(null);
    const done = fix === "open_settings" ? api.openPrivacySettings("screen") : api.privacyHideAgain();
    void done.catch((e) => setProblem(errorMessage(e)));
  };
  return (
    <>
      <Button size="sm" onClick={press}>{LINE_FIX_LABEL[fix]}</Button>
      {problem && <span className="el-field__error" role="alert">{problem}</span>}
    </>
  );
}

export function SharePrivacyPageV2({ settings, patch, onCheck }: { settings: Settings; patch: Patch; onCheck: () => void }) {
  const status = usePrivacyStatus();
  const [copied, copy] = useCopied();
  const [moved, setMoved] = useState(false);
  const [zoomOpen, setZoomOpen] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const demoSeconds = useCountdown(status?.demoSecondsLeft ?? null);
  const mac = status?.platform === "macos";
  const windows = status?.platform === "windows";
  const value = status ? statusValue(status, demoSeconds) : null;
  const hidden = hiddenValue(status);
  const barHolds = hiddenReason(status) === "share_bar";
  const shortcut = settings.shortcuts.hide_all ? prettyShortcut(settings.shortcuts.hide_all, status?.platform ?? "windows") : "No shortcut";
  const notes = [
    status?.hiding === "floating" ? "The main window is visible in screen share; captions, the recording controls and the other floating windows stay hidden." : null,
    status?.hiding === "off" ? "Everything EchoLedger shows can be seen by people watching your screen share." : null,
  ].filter(Boolean) as string[];
  const lines = status?.lines ?? [];
  const act = (done: Promise<unknown>, after?: () => void) => {
    setProblem(null);
    void done.then(() => after?.()).catch((e) => setProblem(errorMessage(e)));
  };

  return (
    <div className="el-settings-v2" data-testid="share-privacy">
      <p className="el-secondary">
        Your notes stay on your screen. When you share your screen in Zoom, Meet or Teams, people see your other apps, not
        EchoLedger. EchoLedger checks this on your computer and tells you if it can't.
      </p>
      <Group footer={notes.length || lines.length ? <>
        {notes.map((l) => <p key={l}>{l}</p>)}
        {lines.length > 0 && (
          <div className="el-privacy-lines" role="status">
            {lines.map((l) => (
              <p key={l.text} className={l.warning ? "el-privacy-line el-privacy-line--warning" : "el-privacy-line"}>
                {l.warning && <Icons.WarningIcon />}<span>{l.text}</span>{l.fix && <LineFix fix={l.fix} />}
              </p>
            ))}
          </div>
        )}
      </> : undefined}>
        <SelectSetting<ShareHiding> id="share_privacy.hiding" value={settings.hideFromSharing} onChange={(v) => void patch({ hideFromSharing: v })} />
        <ListRow compact anchor="share_privacy.status" title={setting("share_privacy.status").title}
          value={value ? <span className={value.tone ? "el-privacy-value el-privacy-value--warning" : "el-privacy-value"}>{value.text}</span> : undefined}
          control={status ? <PrivacyBadgeView badge={status.badge} compact /> : undefined} />
        {windows && status?.oldWindows && (
          <SelectSetting<OldWindowsSharing> id="share_privacy.old_windows" value={settings.oldWindowsSharing} onChange={(v) => void patch({ oldWindowsSharing: v })} />
        )}
      </Group>

      <Group title="Check">
        <LinkSetting id="share_privacy.check" value={lastCheckValue(status?.lastCheck?.result ?? null, status?.lastCheck?.at ?? null, new Date(), status?.lastCheck?.reason ?? null)} onOpen={onCheck} />
      </Group>

      {mac && status && (
        <Group title="Meeting apps" footer={<>
          <p>Each app captures your screen its own way. A 1-minute test with your phone tells you for sure.</p>
          <p>{setting("share_privacy.one_window").description}</p>
        </>}>
          {status.apps.filter((a) => a.version || a.app === "meet").map((a) => <AppRow key={a.app} row={a} />)}
          {status.zoomInstalled && (
            <>
              <ListRow compact anchor="share_privacy.zoom" title={setting("share_privacy.zoom").title} value="1 setting" expanded={zoomOpen} chevron onClick={() => setZoomOpen((o) => !o)} />
              {zoomOpen && <li className="el-row el-row--compact el-row--note" data-testid="zoom-steps"><p className="el-secondary">{ZOOM_SETTING}</p></li>}
            </>
          )}
          <SwitchSetting id="share_privacy.one_window" on={settings.shareOneWindow} onChange={(v) => void patch({ shareOneWindow: v })} hint={null} />
        </Group>
      )}

      <Group title="Sharing" footer={<>
        {barHolds && <p role="status" data-testid="back-after-share">{HIDDEN_WHILE_SHARING}</p>}
        <p>{ONE_WINDOW_TIP}</p><p>{LIMITS}</p>
        {problem && <p className="el-field__error" role="alert">{problem}</p>}
      </>}>
        <ListRow compact anchor="share_privacy.demo" title={demoSeconds ? "Hide from screen share again" : setting("share_privacy.demo").title}
          value={demoSeconds ? demoLeft(demoSeconds) ?? undefined : undefined} disabled={settings.hideFromSharing === "off"}
          onClick={() => act(api.privacyShowInShares(demoSeconds ? 0 : 10))} />
        <ListRow compact anchor="share_privacy.hide_all" title={setting("share_privacy.hide_all").title} value={hidden ?? shortcut}
          control={!barHolds ? <Button size="sm" onClick={() => act(api.privacySetHidden(!hidden))}>{hidden ? "Show EchoLedger windows" : "Hide now"}</Button> : undefined} />
        {status && status.displays > 1 && (
          <ListRow compact anchor="share_privacy.displays" title={setting("share_privacy.displays").title} value={displaysValue(status.floatingOpen, moved)}
            disabled={!status.floatingOpen} onClick={() => act(api.privacyMoveToOtherDisplay(), () => setMoved(true))} />
        )}
      </Group>

      <Group title="Diagnostics">
        <SwitchSetting id="share_privacy.diagnostics" on={settings.sendDiagnostics} onChange={(v) => void patch({ sendDiagnostics: v })} />
        <ListRow compact anchor="share_privacy.copy" title={setting("share_privacy.copy").title} value={copied === "diag" ? "Copied" : undefined}
          onClick={() => act(api.privacyDiagnostics().then((text) => copy("diag", text)))} />
      </Group>
    </div>
  );
}

/** A meeting app on a Mac: what is known about it, the version that result is kept for, and Hidden or Visible. */
function AppRow({ row }: { row: MeetingAppRow }) {
  const version = versionLine(row);
  return (
    <ListRow compact title={row.line} meta={version ?? undefined}
      badge={row.state === "hidden" ? <Badge tone="success">Hidden</Badge> : row.state === "visible" ? <Badge tone="warning">Visible</Badge> : undefined} />
  );
}

/** Screen-share privacy › Check screen-share privacy. */
export function ShareCheckPageV2() {
  const status = usePrivacyStatus();
  const [view, setView] = useState<ShareCheckView | null>(null);
  const [running, setRunning] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [guided, setGuided] = useState<MeetingApp | null>(null);
  const question = useRef<HTMLParagraphElement>(null);
  // The picture goes with the page, and the test card with it.
  useEffect(() => () => setView(null), []);
  useEffect(() => () => void api.shareCheckCard(false).catch(() => undefined), []);
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
  const busy = running || !!status?.checkRunning;
  const permissionFirst = platform === "macos" && status && !status.permission && !view
    ? resultLine("error", "permission_missing", status.system, new Date().toISOString(), platform) : null;
  const openSettings = () => void api.openPrivacySettings("screen").catch(() => undefined);
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
    <div className="el-settings-v2" data-testid="share-check">
      <p className="el-secondary">
        EchoLedger opens a small test window and a green control for a moment, captures your screen the way meeting apps do,
        and shows you the result. The picture stays on this page and is gone when you close it.
      </p>
      <div className="el-page__actions el-check-run">
        <Button variant="primary" onClick={() => void run()} disabled={busy}>{busy ? "Checking…" : view ? "Check again" : "Check now"}</Button>
      </div>
      {problem && <p className="el-field__error" role="alert">{problem}</p>}
      {permissionFirst && (
        <p className="el-privacy-line el-privacy-line--warning" data-testid="permission-first">
          <Icons.WarningIcon /><span>{permissionFirst.text}</span><Button size="sm" onClick={openSettings}>{OPEN_SYSTEM_SETTINGS}</Button>
        </p>
      )}
      {view && line && (
        <Card className={`el-check-result el-check-result--${view.result}`}>
          <div className="el-form" data-testid="check-result" role="status">
            <p className={`el-check-line el-check-line--${line.tone}`}>{line.text}</p>
            {line.fix === "open_settings" && <div><Button size="sm" onClick={openSettings}>{OPEN_SYSTEM_SETTINGS}</Button></div>}
            {line.fix === "try_again" && <div><Button size="sm" onClick={() => void run()} disabled={busy}>Try again</Button></div>}
            {view.picture && <SidePicturesV2 view={view} />}
          </div>
        </Card>
      )}
      {platform === "macos" && status && (
        <section className="el-group" aria-labelledby="el-phone-test">
          <h2 className="el-caption el-body-strong el-group__title" id="el-phone-test">Test with your phone</h2>
          <Card>
            {guided ? (
              <div className="el-form" data-testid="guided">
                <p ref={question} tabIndex={-1} className="el-body-strong">Share your whole screen in {status.apps.find((a) => a.app === guided)?.name}. A test card is on your screen now. Can you see it on your phone?</p>
                <p className="el-secondary">{GUIDED_ALONE}</p>
                <div className="el-page__actions">
                  <Button onClick={() => void answer(true)}>I can see it</Button>
                  <Button onClick={() => void answer(false)}>I can't see it</Button>
                  <Button variant="quiet" onClick={cancel}>Cancel</Button>
                </div>
              </div>
            ) : status.apps.some((a) => a.version) ? (
              <List label="Meeting apps to test" className="el-group__rows">
                {status.apps.filter((a) => a.version).map((a) => (
                  <ListRow key={a.app} compact title={a.line} meta={versionLine(a) ?? undefined}
                    control={<Button size="sm" onClick={() => void startGuided(a.app)} disabled={busy} aria-label={`${a.needsTest ? "Test" : "Test again"} ${a.name}`}>{a.needsTest ? "Test" : "Test again"}</Button>} />
                ))}
              </List>
            ) : <p className="el-secondary el-check-none">No meeting apps found on this Mac.</p>}
          </Card>
          <p className="el-group__note el-caption el-secondary">Join the same meeting on your phone, share your whole screen on this Mac, then say what your phone shows. The answer is kept for this app version and this macOS version.</p>
        </section>
      )}
    </div>
  );
}

/** "What you see" next to "What others see": the same captured screen, the test window drawn where you see it, and as
 * the capture got it, the test area outlined. The patch and outline keep the test window's own pattern (the check's
 * `check-patch` and `check-outline`), so the picture matches what flashed on screen. */
function SidePicturesV2({ view }: { view: ShareCheckView }) {
  const area = (a: [number, number, number, number] | null) => (a ? { insetInlineStart: `${a[0] * 100}%`, insetBlockStart: `${a[1] * 100}%`, inlineSize: `${a[2] * 100}%`, blockSize: `${a[3] * 100}%` } : undefined);
  return (
    <div className="el-check-pictures" dir="ltr">
      <figure>
        <div className="el-check-frame">
          <img src={view.picture!} alt="" />
          <span className="el-check-mark check-patch" style={area(view.testArea)} />
        </div>
        <figcaption className="el-caption el-secondary">What you see</figcaption>
      </figure>
      <figure>
        <div className="el-check-frame">
          <img src={view.picture!} alt="Your screen as a screen share captured it" />
          <span className="el-check-mark check-outline" style={area(view.testArea)} />
        </div>
        <figcaption className="el-caption el-secondary">What others see</figcaption>
      </figure>
    </div>
  );
}
