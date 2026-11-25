// Sharing a recording or a project (docs/API.md "Share links", "Sending to a teammate", since v1.5). Share link: who
// may open it (anyone with the link, with a password, or only the people named), downloads on or off, when it stops
// working; the link is shown once to copy, and every link is listed with its views and Revoke. Send to a teammate: to
// people with an account on this server, with a note. An older server gets the v1.1 link of a recording. The new
// design's recording page opens it too, so its words are in the account's language (useT), as v2/shared's are.
import { useCallback, useEffect, useId, useState } from "react";
import { api, errorMessage, type SentItem, type ShareAudience, type ShareLink, type TranscriptView } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { audienceText, downloadText, expiryChoices, expiryTime, linkState, MAX_PEOPLE, parseEmails, revokeLinkQuestion, viewsText, type ExpiryChoice } from "../../lib/sharing";
import { t, useT } from "../../i18n";
import { CopyIcon, SendIcon } from "../../components/Icons";
import { Sheet } from "./ProjectDialogs";
import { SharePanel } from "./TranscriptTools";
import { SuccessCheck } from "../../components/SuccessCheck";
import { NO_TARGET, type Notify } from "../../lib/notice";
import { confirmAction } from "../../lib/confirm";

export type ShareTarget =
  | { kind: "session"; id: string; title: string | null; hasAudio: boolean }
  | { kind: "project"; id: string; name: string };

/** Who may open a new link, each choice with its words. */
const audiences = (): readonly (readonly [ShareAudience, string])[] => [
  ["public", t("Anyone with the link")],
  ["password", t("Anyone with the link and a password")],
  ["people", t("Only people I choose")],
];
/** A transcript view's name. */
const viewName = (view: TranscriptView) => (view === "roman" ? t("Roman Urdu") : view === "english" ? t("English") : t("Urdu"));

export function ShareDialog({ target, notify, onClose, start = "link", view = null }: {
  target: ShareTarget;
  notify: Notify;
  onClose: () => void;
  /** The part that opens first. */
  start?: "link" | "send";
  /** The Show as view on screen: the shared page opens in it. */
  view?: TranscriptView | null;
}) {
  const { t } = useT();
  const [part, setPart] = useState<"link" | "send">(start);
  // The links so far; null until they are read, "legacy" on a server older than v1.5.
  const [links, setLinks] = useState<ShareLink[] | "legacy" | null>(null);
  const name = target.kind === "session" ? target.title || t("this recording") : target.name;
  const load = () =>
    api.sharesOf(target.kind === "session" ? target.id : null, target.kind === "project" ? target.id : null)
      .then((r) => setLinks(r ? r.data : "legacy"), (e) => {
        setLinks([]);
        notify(errorMessage(e), true);
      });
  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [target.kind, target.id]);

  if (links === "legacy") {
    return (
      <Sheet title={t("Share :name", { name })} onClose={onClose} className="share-sheet">
        {target.kind === "session" ? (
          <SharePanel sessionId={target.id} hasAudio={target.hasAudio} notify={notify} onClose={onClose} />
        ) : (
          <p>{t("Sharing a project needs a newer EchoLedger server. Ask the person who runs it to update.")}</p>
        )}
      </Sheet>
    );
  }
  return (
    <Sheet title={t("Share :name", { name })} onClose={onClose} className="share-sheet">
      <span className="segmented" role="group" aria-label={t("Share how")}>
        <button type="button" aria-pressed={part === "link"} onClick={() => setPart("link")}>{t("Share link")}</button>
        <button type="button" aria-pressed={part === "send"} onClick={() => setPart("send")}>{t("Send to a teammate")}</button>
      </span>
      {part === "link" ? (
        <LinkPart target={target} links={links} view={view} notify={notify} onMade={load} />
      ) : (
        <SendPart target={target} notify={notify} onSent={onClose} />
      )}
      <div className="row sheet-actions">
        <span className="spacer" />
        <button type="button" className="btn ghost" onClick={onClose}>{t("Close")}</button>
      </div>
    </Sheet>
  );
}

function LinkPart({ target, links, view, notify, onMade }: {
  target: ShareTarget;
  links: ShareLink[] | null;
  view: TranscriptView | null;
  notify: Notify;
  onMade: () => Promise<void>;
}) {
  const { t, tc } = useT();
  const [audience, setAudience] = useState<ShareAudience>("public");
  const [password, setPassword] = useState("");
  const [people, setPeople] = useState("");
  const [download, setDownload] = useState(false);
  const [expiry, setExpiry] = useState<ExpiryChoice>("week");
  const [day, setDay] = useState("");
  const [made, setMade] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const group = useId();
  const passwordId = useId();
  const peopleId = useId();
  const expiryId = useId();
  const dayId = useId();
  const parsed = parseEmails(people);
  const ready = audience === "password" ? password.length >= 6 && password.length <= 100
    : audience === "people" ? parsed.emails.length > 0 && parsed.invalid.length === 0 && parsed.emails.length <= MAX_PEOPLE
      : true;
  const datedOk = expiry !== "date" || !!day;

  const make = async () => {
    setBusy(true);
    setError(null);
    try {
      const link = await api.createShareLink(target.kind, target.id, {
        audience,
        password: audience === "password" ? password : null,
        people: audience === "people" ? parsed.emails : [],
        allowDownload: download,
        expiresAt: expiryTime(expiry, new Date(), day),
        view,
      });
      setMade(link.url ?? null);
      setPassword("");
      // The new link is on the clipboard at once (v1.6, P6: one click fewer); Copy link stays for later.
      if (link.url) {
        try {
          await navigator.clipboard.writeText(link.url);
          notify(t("Link made and copied"), false, { kind: "shared", tab: "links" });
        } catch {
          // No clipboard here: the link is shown to copy.
        }
      }
      await onMade();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  const copy = async () => {
    if (!made) return;
    try {
      await navigator.clipboard.writeText(made);
      notify(t("Link copied"), false, { kind: "shared", tab: "links" });
    } catch {
      notify(t("Could not access the clipboard"), true);
    }
  };
  const revoke = async (link: ShareLink) => {
    if (!(await confirmAction(revokeLinkQuestion()))) return;
    try {
      await api.revokeShare(link.id);
      await onMade();
      notify(t("Link revoked: it stops working at once"), false, NO_TARGET);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  return (
    <>
      <fieldset className="share-field">
        <legend className="small muted">{t("Who can open it")}</legend>
        {audiences().map(([value, label]) => (
          <label key={value} className="radio-row">
            <input type="radio" name={group} checked={audience === value} onChange={() => setAudience(value)} />{label}
          </label>
        ))}
        {audience === "password" && (
          <>
            <label htmlFor={passwordId} className="sr-only">{t("Password")}</label>
            <input id={passwordId} className="input" type="password" autoComplete="new-password" minLength={6} maxLength={100} placeholder={t("Password, 6 or more characters")}
              value={password} onChange={(e) => setPassword(e.target.value)} />
          </>
        )}
        {audience === "people" && (
          <>
            <label htmlFor={peopleId} className="sr-only">{t("Their emails")}</label>
            <textarea id={peopleId} className="input" rows={2} placeholder={t("Their emails, separated by commas")} value={people} onChange={(e) => setPeople(e.target.value)} />
            <span className="small muted">
              {parsed.invalid.length > 0 ? t("Not an email: :emails", { emails: parsed.invalid.join(", ") })
                : parsed.emails.length > MAX_PEOPLE ? tc("Name :count person at most.|Name :count people at most.", MAX_PEOPLE)
                  : t("They open it signed in to the console with one of these emails.")}
            </span>
          </>
        )}
      </fieldset>
      <div className="toggle-row">
        <span>
          {t("Allow downloads")}
          <span className="small muted" style={{ display: "block" }}>{download ? t("The audio and the transcript can be downloaded.") : t("People can read and listen, not download.")}</span>
        </span>
        <button type="button" role="switch" aria-checked={download} aria-label={t("Allow downloads")} className={`switch ${download ? "on" : ""}`} onClick={() => setDownload(!download)} />
      </div>
      <div className="row organise-field">
        <label htmlFor={expiryId} className="small muted">{t("Stops working")}</label>
        <select id={expiryId} className="select" value={expiry} onChange={(e) => setExpiry(e.target.value as ExpiryChoice)}>
          {expiryChoices().map(([value, label]) => <option dir="auto" key={value} value={value}>{label}</option>)}
        </select>
        {expiry === "date" && (
          <>
            <label htmlFor={dayId} className="sr-only">{t("Day")}</label>
            <input id={dayId} className="input" type="date" value={day} onChange={(e) => setDay(e.target.value)} style={{ width: "auto" }} />
          </>
        )}
      </div>
      {view && <p className="small muted">{t("The page opens in the :view view.", { view: viewName(view) })}</p>}
      <div className="row">
        <button type="button" className="btn primary" disabled={busy || !ready || !datedOk} onClick={() => void make()}>{busy ? t("Making the link…") : t("Make link")}</button>
      </div>
      {error && <div className="notice error small" role="alert">{error}</div>}
      {made && (
        <div className="share-made" role="status">
          <div className="share-made-head"><SuccessCheck size={16} /><b className="small">{t("Link made")}</b></div>
          <span className="small muted">{t("Copy it now. It is shown only once.")}</span>
          <code className="share-url">{made}</code>
          <button type="button" className="btn" onClick={() => void copy()}><CopyIcon size={14} />{t("Copy link")}</button>
        </div>
      )}
      <div className="share-links">
        <b className="small">{t("Links")}</b>
        {links === null ? <p className="small muted">{t("Loading…")}</p> : links.length === 0 ? <p className="small muted">{t("No links yet.")}</p> : (
          <ul className="links-list" aria-label={t("Share links")}>
            {links.map((link) => (
              <li key={link.id} className="link-row">
                <div className="link-main">
                  <span className="link-who">{audienceText(link)}</span>
                  <span className="small muted">
                    {[linkState(link), t("made :date", { date: formatWhen(link.created_at) }),
                      link.expires_at ? (link.active ? t("stops :date", { date: formatWhen(link.expires_at) }) : t("stopped :date", { date: formatWhen(link.expires_at) })) : t("never stops"),
                      downloadText(link)].join(" · ")}
                  </span>
                  <span className="small muted">{viewsText(link)}</span>
                </div>
                {link.active && <button type="button" className="btn ghost danger compact" onClick={() => void revoke(link)}>{t("Revoke")}</button>}
              </li>
            ))}
          </ul>
        )}
      </div>
    </>
  );
}

function SendPart({ target, notify, onSent }: { target: ShareTarget; notify: Notify; onSent: () => void }) {
  const { t, tc } = useT();
  const [sentItems, setSentItems] = useState<SentItem[]>([]);
  const loadSent = useCallback(async () => {
    try {
      const answer = await api.sentOf(target.kind === "session" ? target.id : null, target.kind === "project" ? target.id : null);
      setSentItems(answer?.data ?? []);
    } catch {
      setSentItems([]); // the list is a help; sending still works without it
    }
  }, [target.id, target.kind]);
  useEffect(() => { void loadSent(); }, [loadSent]);
  const stop = async (item: SentItem) => {
    try {
      await api.stopSharing(item.id);
      notify(t("Stopped sharing"), false, NO_TARGET);
      await loadSent();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  const [to, setTo] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const toId = useId();
  const noteId = useId();
  const parsed = parseEmails(to);
  const ready = parsed.emails.length > 0 && parsed.invalid.length === 0 && parsed.emails.length <= MAX_PEOPLE && note.length <= 500;
  const send = async () => {
    setBusy(true);
    setError(null);
    try {
      const sent = await api.sendToTeammate(target.kind, target.id, parsed.emails, note.trim() || null);
      notify(sent === 1 ? t("Sent to :email", { email: parsed.emails[0] }) : tc("Sent to :count person|Sent to :count people", sent), false, NO_TARGET);
      onSent();
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  };
  return (
    <>
      <p className="small muted">
        {t("They find it in Shared with me, can play and read it, and save a copy of their own. People without an account here will not receive it.")}
      </p>
      <label htmlFor={toId} className="small muted">{t("Their emails")}</label>
      <textarea id={toId} className="input" rows={2} placeholder={t("ana@example.com, omar@example.com")} value={to} onChange={(e) => setTo(e.target.value)} />
      {parsed.invalid.length > 0 && <span className="small error-text">{t("Not an email: :emails", { emails: parsed.invalid.join(", ") })}</span>}
      <label htmlFor={noteId} className="small muted">{t("Note (optional)")}</label>
      <textarea id={noteId} className="input" rows={2} maxLength={500} placeholder={t("For tomorrow's meeting")} value={note} onChange={(e) => setNote(e.target.value)} dir="auto" />
      {error && <div className="notice error small" role="alert">{error}</div>}
      <div className="row">
        <button type="button" className="btn primary" disabled={busy || !ready} onClick={() => void send()}><SendIcon size={16} />{busy ? t("Sending…") : t("Send")}</button>
      </div>
      {sentItems.length > 0 && (
        <div className="share-links">
          <b className="small">{t("Sent to")}</b>
          <ul className="links-list" aria-label={t("Sent to")}>
            {sentItems.map((item) => (
              <li key={item.id} className="link-row">
                <div className="link-main">
                  <span className="link-who">{item.to?.name ?? item.to?.email}</span>
                  <span className="small muted">
                    {[item.to?.name ? item.to?.email : null, item.sent_at ? t("sent :date", { date: formatWhen(item.sent_at) }) : null, item.saved_at ? t("they saved a copy") : null].filter(Boolean).join(" · ")}
                  </span>
                </div>
                <button type="button" className="btn ghost danger compact" onClick={() => void stop(item)}>{t("Stop sharing")}</button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </>
  );
}
