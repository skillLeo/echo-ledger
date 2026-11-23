// Help in the new look (v1.8 P6.4, docs/API.md 2.0.0-d), opened from Settings › Help: search over the user guide and
// its sections to read, What's new for this platform, and Contact support (an email to the server's support address;
// this computer's last device check goes with it only when the person ticks it).
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, type HelpGuide, type HelpGuideHit, type Settings, type WhatsNewRelease } from "../../lib/ipc";
import { prettyShortcut } from "../../lib/format";
import { CAPTURE_HELP, GLOBAL_SHORTCUTS, windowKeys } from "../../views/main/HelpDialog";
import { rowDate, sentenceDate } from "../../lib/format";
import { Button, Card, Checkbox, EmptyState, Icons, List, ListRow, Loading, SearchField, Skeleton, SkeletonRow, Tabs, TextArea, TextField } from "../components";
import { Markdown } from "./markdown";

export type HelpTab = "guides" | "shortcuts" | "whats_new" | "contact";
type Tab = HelpTab;
type Load<T> = { state: "loading" } | { state: "error"; message: string } | { state: "ready"; value: T };

/** An error in words: a core without the help commands yet says so plainly, never as a command name. */
export function helpError(e: unknown): string {
  const text = errorMessage(e);
  return /command .* not found|unknown command/i.test(text) ? "Help isn't available in this version of the app yet. Update EchoLedger to use it." : text;
}

export const SUBJECT_MAX = 120;
export const MESSAGE_MAX = 5000;

export type HelpV2Props = {
  platform: string;
  onBack: () => void;
  /** The Mac check (today's page), on a Mac. */
  onMacCheck?: () => void;
  /** Open on a tab ("?" opens Shortcuts); `seq` opens it again. */
  start?: { tab: HelpTab; seq: number } | null;
  /** A Settings page (Keyboard shortcuts, Screen-share privacy). */
  onSettings?: (page: string) => void;
  /** Where Back goes, in words ("Library" when "?" was pressed there); Settings by default. */
  backLabel?: string;
};

export function HelpV2({ platform, onBack, onMacCheck, start = null, onSettings, backLabel = "Settings", threeLevels = false }: HelpV2Props & {
  /** The rail · panel · page layout: its keys in place of the sidebar's "[". */
  threeLevels?: boolean;
}) {
  const [tab, setTab] = useState<Tab>(start?.tab ?? "guides");
  useEffect(() => {
    if (start) setTab(start.tab);
  }, [start?.seq]); // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className="el-page el-page--narrow" data-v2="help">
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>{backLabel}</Button>
      <header className="el-page__head"><h1 className="el-title-l">Help</h1></header>
      <Tabs label="Help" value={tab} onChange={setTab}
        tabs={[{ value: "guides", label: "Guides" }, { value: "shortcuts", label: "Shortcuts" }, { value: "whats_new", label: "What's new" }, { value: "contact", label: "Contact support" }]}>
        {tab === "guides" ? <Guides platform={platform} onMacCheck={onMacCheck} />
          : tab === "shortcuts" ? <Shortcuts platform={platform} onSettings={onSettings} threeLevels={threeLevels} />
          : tab === "whats_new" ? <WhatsNew /> : <ContactSupport platform={platform} onMacCheck={onMacCheck} />}
      </Tabs>
    </div>
  );
}

/**
 * Every key of the window (classic's "In this window"), the shortcuts that work anywhere on this computer as set in
 * Settings, and how to get a screenshot of EchoLedger and what to send for support.
 */
function Shortcuts({ platform, onSettings, threeLevels = false }: { platform: string; onSettings?: (page: string) => void; threeLevels?: boolean }) {
  const [settings, setSettings] = useState<Settings | null>(null);
  const [unread, setUnread] = useState(false);
  useEffect(() => {
    api.settings().then(setSettings, () => setUnread(true));
  }, []);
  const keyList = (label: string, rows: readonly (readonly [string, string])[]) => (
    <dl className="el-keys" aria-label={label}>
      {rows.map(([keys, what]) => (
        <div key={`${keys}-${what}`} className="el-keys__row">
          <dt><kbd className="el-kbd" dir="ltr">{keys}</kbd></dt>
          <dd>{what}</dd>
        </div>
      ))}
    </dl>
  );
  return (
    <div className="el-help-keys">
      <h2 className="el-title-s">In this window</h2>
      <p className="el-caption el-secondary">They work while EchoLedger is in front, never while you type in a field.</p>
      {keyList("In this window", windowKeys(platform, threeLevels))}
      <h2 className="el-title-s">Anywhere on this computer</h2>
      {/* Shortcuts that couldn't be read are said to be so, never shown as "Not set". */}
      {unread && <p className="el-caption el-row__error" role="status" data-testid="shortcuts-unread">Your shortcuts couldn't be read. See them in Keyboard shortcuts.</p>}
      {keyList("Anywhere on this computer", GLOBAL_SHORTCUTS.map(([action, what]) => [
        unread || !settings ? "…" : settings.shortcuts[action] ? prettyShortcut(settings.shortcuts[action], platform) : "Not set", what,
      ] as const))}
      {onSettings && <Button size="sm" variant="quiet" onClick={() => onSettings("shortcuts")}>Change them in Keyboard shortcuts</Button>}
      <Card className="el-help-keys__tip">
        <h2 className="el-body-strong">Screenshots and support</h2>
        <p className="el-caption" data-testid="capture-help">{CAPTURE_HELP}</p>
        {onSettings && <Button size="sm" onClick={() => onSettings("share_privacy")}>Open Screen-share privacy</Button>}
      </Card>
    </div>
  );
}

/** The guide's sections, searched on the server as the person types; one opens to read. */
function Guides({ platform, onMacCheck }: { platform: string; onMacCheck?: () => void }) {
  const [query, setQuery] = useState("");
  const [hits, setHits] = useState<Load<HelpGuideHit[]>>({ state: "loading" });
  const [attempt, setAttempt] = useState(0);
  const [openId, setOpenId] = useState<string | null>(null);

  useEffect(() => {
    let live = true;
    const q = query.trim();
    const read = () => api.helpGuides(q.length >= 2 ? q : null)
      .then((r) => live && setHits({ state: "ready", value: r?.data ?? [] }))
      .catch((e) => live && setHits({ state: "error", message: helpError(e) }));
    // All of them at once; a search once the typing pauses.
    const timer = q ? window.setTimeout(() => void read(), 300) : (void read(), undefined);
    return () => {
      live = false;
      window.clearTimeout(timer);
    };
  }, [query, attempt]);

  if (openId) return <GuideReader id={openId} onBack={() => setOpenId(null)} />;
  return (
    <div className="el-help">
      <SearchField label="Search help" value={query} onChange={setQuery} />
      {hits.state === "loading" ? (
        <Loading label="Loading the guides">{Array.from({ length: 4 }, (_, i) => <SkeletonRow key={i} end={false} />)}</Loading>
      ) : hits.state === "error" ? (
        <EmptyState tone="error" icon={Icons.ErrorIcon} title="The guides didn't load" body={hits.message}
          action={<Button onClick={() => { setHits({ state: "loading" }); setAttempt((n) => n + 1); }}>Try again</Button>} />
      ) : hits.value.length === 0 ? (
        query.trim() ? (
          <EmptyState icon={Icons.SearchIcon} title="No guide matches" body="Try other words, or ask us in Contact support."
            action={<Button onClick={() => setQuery("")}>Clear search</Button>} />
        ) : (
          <EmptyState icon={Icons.HelpIcon} title="No guides yet" body="Your server has no user guide. Contact support can help." />
        )
      ) : (
        <Card>
          <List label={query.trim() ? "Matching guides" : "Guides"} className="el-group__rows">
            {hits.value.map((g) => <ListRow key={g.id} compact iconStyle="plain" icon={Icons.TranscriptIcon} title={g.title} meta={g.excerpt || undefined} chevron onClick={() => setOpenId(g.id)} />)}
          </List>
        </Card>
      )}
      {platform === "macos" && onMacCheck && (
        <Card>
          <List label="Checks" className="el-group__rows">
            <ListRow compact iconStyle="plain" icon={Icons.ReadyIcon} title="Mac check" meta="What macOS allows EchoLedger, and how to fix the rest" chevron onClick={onMacCheck} />
          </List>
        </Card>
      )}
    </div>
  );
}

/** One section of the guide. */
function GuideReader({ id, onBack }: { id: string; onBack: () => void }) {
  const [guide, setGuide] = useState<Load<HelpGuide>>({ state: "loading" });
  const [attempt, setAttempt] = useState(0);
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    let live = true;
    api.helpGuide(id).then((g) => live && setGuide({ state: "ready", value: g }), (e) => live && setGuide({ state: "error", message: helpError(e) }));
    return () => {
      live = false;
    };
  }, [id, attempt]);
  // The guide's title takes the focus when it opens, so a screen reader starts there.
  useEffect(() => {
    if (guide.state === "ready") heading.current?.focus();
  }, [guide.state]);
  return (
    <article className="el-help el-help__guide" aria-busy={guide.state === "loading" || undefined}>
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>All guides</Button>
      {guide.state === "loading" ? (
        <Loading label="Loading the guide"><Skeleton shape="title" /><Skeleton width="long" /><Skeleton width="medium" /></Loading>
      ) : guide.state === "error" ? (
        <EmptyState tone="error" icon={Icons.ErrorIcon} title="This guide didn't open" body={guide.message}
          action={<Button onClick={() => { setGuide({ state: "loading" }); setAttempt((n) => n + 1); }}>Try again</Button>} />
      ) : (
        <>
          <h2 className="el-title-m" ref={heading} tabIndex={-1} dir="auto">{guide.value.title}</h2>
          <Markdown text={stripTitle(guide.value.markdown, guide.value.title)} top={3} />
        </>
      )}
    </article>
  );
}

/** The section's own heading line is the page's title already. */
export function stripTitle(markdown: string, title: string): string {
  const [first, ...rest] = markdown.replace(/^\s+/, "").split("\n");
  const heading = /^#{1,6}\s+(.*)$/.exec(first ?? "");
  return heading && heading[1].trim() === title.trim() ? rest.join("\n") : markdown;
}

/** This platform's last releases, newest first. */
function WhatsNew() {
  const [releases, setReleases] = useState<Load<WhatsNewRelease[]>>({ state: "loading" });
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let live = true;
    api.helpWhatsNew().then((r) => live && setReleases({ state: "ready", value: r?.data ?? [] }), (e) => live && setReleases({ state: "error", message: helpError(e) }));
    return () => {
      live = false;
    };
  }, [attempt]);
  if (releases.state === "loading") return <Loading label="Loading what's new">{Array.from({ length: 3 }, (_, i) => <SkeletonRow key={i} end={false} />)}</Loading>;
  if (releases.state === "error") {
    return (
      <EmptyState tone="error" icon={Icons.ErrorIcon} title="What's new didn't load" body={releases.message}
        action={<Button onClick={() => { setReleases({ state: "loading" }); setAttempt((n) => n + 1); }}>Try again</Button>} />
    );
  }
  if (releases.value.length === 0) return <EmptyState icon={Icons.HelpIcon} title="No release notes yet" body="Notes for each new version of the app appear here." />;
  return (
    <div className="el-help">
      {releases.value.map((r) => (
        <Card key={`${r.platform}-${r.version}`}>
          <section className="el-help__release" aria-labelledby={`release-${r.version}`}>
            <h2 className="el-body-strong" id={`release-${r.version}`}>Version <span dir="ltr">{r.version}</span></h2>
            {r.released_at && <p className="el-caption el-secondary">{rowDate(r.released_at)}</p>}
            <Markdown text={r.notes} top={3} />
          </section>
        </Card>
      ))}
    </div>
  );
}

/** "Mac check" on a Mac, where the report comes from; "device check" elsewhere. */
const checkName = (platform: string) => (platform === "macos" ? "Mac check" : "device check");

/** Exactly what "Include a report from this computer" attaches (undefined: still asking the core). */
export function reportLine(platform: string, sentAt: string | null | undefined): string {
  if (sentAt === undefined) return "Looking for this computer's last report…";
  if (!sentAt) return "No report from this computer yet";
  return `Your last ${checkName(platform)} report, from ${sentenceDate(sentAt) || sentAt}`;
}

/** A message to support, with this computer's last device check only when ticked. */
function ContactSupport({ platform, onMacCheck }: { platform: string; onMacCheck?: () => void }) {
  // The last report this computer sent; null when there is none, or when the core can't say yet (an older core).
  const [sentAt, setSentAt] = useState<string | null | undefined>(undefined);
  useEffect(() => {
    let live = true;
    api.lastDeviceReport().then((r) => live && setSentAt(r?.sentAt ?? null), () => live && setSentAt(null));
    return () => {
      live = false;
    };
  }, []);
  const [subject, setSubject] = useState("");
  const [message, setMessage] = useState("");
  const [attach, setAttach] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const done = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    if (sent) done.current?.focus();
  }, [sent]);

  if (sent) {
    return (
      <Card>
        <div className="el-help__sent" role="status">
          <h2 className="el-body-strong" ref={done} tabIndex={-1}>Message sent</h2>
          <p className="el-secondary">Support replies to your account's email address, usually within a working day.</p>
          <Button onClick={() => { setSent(false); setSubject(""); setMessage(""); setAttach(false); }}>Write another</Button>
        </div>
      </Card>
    );
  }
  const send = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.supportSend(subject.trim(), message.trim(), attach && !!sentAt);
      setSent(true);
    } catch (e) {
      setError(helpError(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Card>
      <form className="el-form" onSubmit={(e) => { e.preventDefault(); void send(); }}>
        <p className="el-secondary">Tell us what happened and what you expected. We reply by email.</p>
        <TextField label="Subject" value={subject} maxLength={SUBJECT_MAX} required onChange={(e) => setSubject(e.target.value)} />
        <TextArea label="Message" value={message} rows={8} maxLength={MESSAGE_MAX} required onChange={(e) => setMessage(e.target.value)}
          hint={`${message.length.toLocaleString("en")} of ${MESSAGE_MAX.toLocaleString("en")} characters`} />
        <div className="el-help__report">
          <Checkbox label="Include a report from this computer" checked={attach && !!sentAt} disabled={!sentAt} onChange={(e) => setAttach(e.target.checked)}
            hint={sentAt ? `${reportLine(platform, sentAt)}: the app version, the system and what it allows EchoLedger. Never your recordings or transcripts.` : `${reportLine(platform, sentAt)}.`} />
          {platform === "macos" && onMacCheck && <Button variant="quiet" size="sm" onClick={onMacCheck}>Run the Mac check first</Button>}
        </div>
        {error && <p className="el-field__error" role="alert">{error}</p>}
        <div className="el-page__actions">
          <Button type="submit" variant="primary" disabled={!subject.trim() || !message.trim()} loading={busy} loadingLabel="Sending…">Send</Button>
        </div>
      </form>
    </Card>
  );
}
