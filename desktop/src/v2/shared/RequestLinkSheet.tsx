// Request files in the new design (classic's RequestLinksSheet, views/main/RequestLinks.tsx, the same rules and words):
// a link anyone can use, without an account, to send voice notes, audio and video (and, for a project, documents) into
// a project or the Inbox. Title people see, Files go to, Stops working, At most … files of … MB each, then Make link;
// the new link is shown once to copy. Below, every request link with what it received, and Revoke (after asking).
import { useState, type ReactNode } from "react";
import { api, errorMessage, type ClientFolder, type RequestLink } from "../../lib/ipc";
import { projectLabel, projectTree, visibleRows } from "../../lib/projects";
import { EXPIRY_CHOICES, expiryTime, type ExpiryChoice } from "../../lib/sharing";
import type { Notify } from "../../lib/notice";
import { Banner, Button, Card, Icons, List, Select, Sheet, TextField } from "../components";

/** A whole number from a field, or null when it is empty. */
const count = (text: string): number | null => (text.trim() === "" ? null : Number.parseInt(text, 10));

export const REQUEST_INTRO = "Anyone with a request link can send you voice notes, audio and video, without an account. They become recordings in the project you choose or the Inbox; a project also takes PDF, Word, text and images into its Files.";

export function RequestLinkSheet({ projects, projectId = null, requests, renderRows, notify, onMade, onClose }: {
  projects: readonly ClientFolder[];
  /** The project new links send into (null: the Inbox). */
  projectId?: string | null;
  /** Every request link (null while loading), drawn by the page's own rows (state, received, limits, Revoke). */
  requests: RequestLink[] | null;
  renderRows: (list: RequestLink[]) => ReactNode;
  notify: Notify;
  /** A link was made: the list is read again. */
  onMade: () => void;
  onClose: () => void;
}) {
  const [title, setTitle] = useState("Send me your voice notes");
  const [where, setWhere] = useState(projectId ?? "");
  const [expiry, setExpiry] = useState<ExpiryChoice>("week");
  const [day, setDay] = useState("");
  const [maxFiles, setMaxFiles] = useState("20");
  const [maxMb, setMaxMb] = useState("100");
  const [made, setMade] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const files = count(maxFiles);
  const mb = count(maxMb);
  const filesOk = files === null || (files >= 1 && files <= 100);
  const mbOk = mb === null || (mb >= 1 && mb <= 2048);
  const valid = title.trim().length > 0 && filesOk && mbOk && (expiry !== "date" || !!day);
  const make = async () => {
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.createRequestLink({ title: title.trim(), clientId: where || null, expiresAt: expiryTime(expiry, new Date(), day), maxFiles: files, maxMb: mb });
      setMade(data.url ?? null);
      onMade();
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
  const places = [{ value: "", label: "Inbox" }, ...visibleRows(projectTree(projects), "all").map(({ project }) => ({ value: project.id, label: projectLabel(projects, project.id) }))];

  return (
    <Sheet open title="Request files" description={REQUEST_INTRO} onClose={onClose}
      actions={<>
        <Button disabled={busy} onClick={onClose}>Close</Button>
        <Button variant="primary" disabled={!valid} loading={busy} loadingLabel="Making the link…" onClick={() => void make()}>Make link</Button>
      </>}>
      <div className="el-form el-form--flush">
        <TextField label="Title people see" maxLength={200} value={title} onChange={(e) => setTitle(e.target.value)} />
        <Select label="Files go to" options={places} value={where} onChange={setWhere} />
        <div className="el-form__row">
          <Select label="Stops working" options={EXPIRY_CHOICES.map(([value, label]) => ({ value, label }))} value={expiry} onChange={(v) => setExpiry(v as ExpiryChoice)} />
          {expiry === "date" && <TextField label="Day" type="date" value={day} onChange={(e) => setDay(e.target.value)} />}
        </div>
        <div className="el-form__row">
          <TextField label="At most" hint="files, 1 to 100" type="number" min={1} max={100} value={maxFiles} onChange={(e) => setMaxFiles(e.target.value)}
            error={filesOk ? undefined : "Choose from 1 to 100 files."} />
          <TextField label="Largest file in MB" hint="MB each, 1 to 2048" type="number" min={1} max={2048} value={maxMb} onChange={(e) => setMaxMb(e.target.value)}
            error={mbOk ? undefined : "Choose from 1 to 2048 MB."} />
        </div>
        {error && <Banner tone="danger" icon={Icons.ErrorIcon}>{error}</Banner>}
        {made && (
          <div className="el-group" role="status">
            <span className="el-secondary">Copy it now. It is shown only once.</span>
            <code className="el-code" dir="ltr">{made}</code>
            <Button icon={Icons.CopyIcon} onClick={() => void copy()}>Copy link</Button>
          </div>
        )}
        <section className="el-group" aria-labelledby="request-links-title">
          <h3 id="request-links-title" className="el-caption el-body-strong el-group__title">Request links</h3>
          {requests === null ? <p className="el-secondary">Loading…</p> : requests.length === 0 ? <p className="el-secondary">No request links yet.</p> : (
            <Card><List label="Request links" className="el-group__rows">{renderRows(requests)}</List></Card>
          )}
        </section>
      </div>
    </Sheet>
  );
}
