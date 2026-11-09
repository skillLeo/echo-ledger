// Request files (docs/API.md "Request links", since v1.5): a link anyone can use, without an account, to send voice
// notes, audio and video (and, for a project, documents) into a project or the Inbox. A new link is shown once to copy;
// every link is listed with what it received, and can be revoked.
import { useEffect, useId, useState } from "react";
import { api, errorMessage, type ClientFolder, type RequestLink } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { projectLabel, projectTree, visibleRows } from "../../lib/projects";
import { EXPIRY_CHOICES, expiryTime, limitsText, receivedText, requestState, type ExpiryChoice } from "../../lib/sharing";
import { CopyIcon } from "../../components/Icons";
import { Sheet } from "./ProjectDialogs";
import { NO_TARGET, type Notify } from "../../lib/notice";

/** A whole number from a field, or null when it is empty. */
const count = (text: string): number | null => (text.trim() === "" ? null : Number.parseInt(text, 10));

export function RequestLinksSheet({ projects, projectId = null, focusId = null, notify, onClose }: {
  projects: readonly ClientFolder[];
  /** The project new links send into (null: the Inbox). */
  projectId?: string | null;
  /** A link to bring into view (an echoledger link to it was opened). */
  focusId?: string | null;
  notify: Notify;
  onClose: () => void;
}) {
  const [links, setLinks] = useState<RequestLink[] | null>(null);
  const [title, setTitle] = useState("Send me your voice notes");
  const [where, setWhere] = useState(projectId ?? "");
  const [expiry, setExpiry] = useState<ExpiryChoice>("week");
  const [day, setDay] = useState("");
  const [maxFiles, setMaxFiles] = useState("20");
  const [maxMb, setMaxMb] = useState("100");
  const [made, setMade] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const ids = { title: useId(), where: useId(), expiry: useId(), day: useId(), files: useId(), mb: useId() };
  const load = () =>
    api.requestLinks().then((r) => setLinks(r?.data ?? []), (e) => {
      setLinks([]);
      notify(errorMessage(e), true);
    });
  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  useEffect(() => {
    if (focusId && links && /^[\w-]+$/.test(focusId)) document.querySelector(`[data-request="${focusId}"]`)?.scrollIntoView?.({ block: "nearest" });
  }, [focusId, links]);

  const files = count(maxFiles);
  const mb = count(maxMb);
  const valid = title.trim().length > 0 && (files === null || (files >= 1 && files <= 100)) && (mb === null || (mb >= 1 && mb <= 2048)) && (expiry !== "date" || !!day);
  const make = async () => {
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.createRequestLink({ title: title.trim(), clientId: where || null, expiresAt: expiryTime(expiry, new Date(), day), maxFiles: files, maxMb: mb });
      setMade(data.url ?? null);
      await load();
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
      notify("Link copied", false, { kind: "shared", tab: "requests" });
    } catch {
      notify("Could not access the clipboard", true);
    }
  };
  const revoke = async (link: RequestLink) => {
    try {
      await api.revokeRequestLink(link.id);
      await load();
      notify("Request link revoked: it stops taking files at once", false, NO_TARGET);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  const placeName = (id: string | null) => (id ? projectLabel(projects, id) || "A project" : "Inbox");

  return (
    <Sheet title="Request files" onClose={onClose} busy={busy} className="share-sheet">
      <p className="small muted">
        Anyone with a request link can send you voice notes, audio and video, without an account. They become recordings in the project you choose or the Inbox; a project also takes PDF, Word, text and images into its Files.
      </p>
      <div className="column">
        <label htmlFor={ids.title} className="small muted">Title people see</label>
        <input id={ids.title} className="input" maxLength={200} value={title} onChange={(e) => setTitle(e.target.value)} dir="auto" />
        <div className="row organise-field">
          <label htmlFor={ids.where} className="small muted">Files go to</label>
          <select id={ids.where} className="select" value={where} onChange={(e) => setWhere(e.target.value)}>
            <option value="">Inbox</option>
            {visibleRows(projectTree(projects), "all").map(({ project }) => <option key={project.id} value={project.id}>{projectLabel(projects, project.id)}</option>)}
          </select>
        </div>
        <div className="row organise-field">
          <label htmlFor={ids.expiry} className="small muted">Stops working</label>
          <select id={ids.expiry} className="select" value={expiry} onChange={(e) => setExpiry(e.target.value as ExpiryChoice)}>
            {EXPIRY_CHOICES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
          {expiry === "date" && (
            <>
              <label htmlFor={ids.day} className="sr-only">Day</label>
              <input id={ids.day} className="input" type="date" value={day} onChange={(e) => setDay(e.target.value)} style={{ width: "auto" }} />
            </>
          )}
        </div>
        <div className="row organise-field">
          <label htmlFor={ids.files} className="small muted">At most</label>
          <input id={ids.files} className="input number" type="number" min={1} max={100} value={maxFiles} onChange={(e) => setMaxFiles(e.target.value)} aria-describedby={`${ids.files}-unit`} />
          <span id={`${ids.files}-unit`} className="small muted">files of</span>
          <label htmlFor={ids.mb} className="sr-only">Largest file in MB</label>
          <input id={ids.mb} className="input number" type="number" min={1} max={2048} value={maxMb} onChange={(e) => setMaxMb(e.target.value)} />
          <span className="small muted">MB each</span>
        </div>
      </div>
      <div className="row">
        <button type="button" className="btn primary" disabled={busy || !valid} onClick={() => void make()}>{busy ? "Making the link…" : "Make link"}</button>
      </div>
      {error && <div className="notice error small" role="alert">{error}</div>}
      {made && (
        <div className="share-made" role="status">
          <span className="small muted">Copy it now. It is shown only once.</span>
          <code className="share-url">{made}</code>
          <button type="button" className="btn" onClick={() => void copy()}><CopyIcon size={14} />Copy link</button>
        </div>
      )}
      <div className="share-links">
        <b className="small">Request links</b>
        {links === null ? <p className="small muted">Loading…</p> : links.length === 0 ? <p className="small muted">No request links yet.</p> : (
          <ul className="links-list" aria-label="Request links">
            {links.map((link) => {
              const state = requestState(link);
              return (
                <li key={link.id} className={`link-row ${link.id === focusId ? "focused" : ""}`} data-request={link.id}>
                  <div className="link-main">
                    <span className="link-who" dir="auto">{link.title || "Request link"}</span>
                    <span className="small muted">{[placeName(link.client_id), receivedText(link.received), limitsText(link) || null].filter(Boolean).join(" · ")}</span>
                    <span className="small muted">{[state, link.expires_at ? `${state === "Active" ? "stops" : "stopped"} ${formatWhen(link.expires_at)}` : "never stops"].join(" · ")}</span>
                  </div>
                  {state === "Active" && <button type="button" className="btn ghost danger compact" onClick={() => void revoke(link)}>Revoke</button>}
                </li>
              );
            })}
          </ul>
        )}
      </div>
      <div className="row sheet-actions">
        <span className="spacer" />
        <button type="button" className="btn ghost" disabled={busy} onClick={onClose}>Close</button>
      </div>
    </Sheet>
  );
}
