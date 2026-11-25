// Request files in the new design (classic's RequestLinksSheet, views/main/RequestLinks.tsx, the same rules and words):
// a link anyone can use, without an account, to send voice notes, audio and video (and, for a project, documents) into
// a project or the Inbox. Title people see, Files go to, Stops working, At most … files of … MB each, then Make link;
// the new link is shown once to copy. The links themselves, with what they received and Revoke, are in Shared › Request
// links: "See your request links" closes the sheet and opens that tab, from wherever the sheet was opened. Its words are
// in the account's language (useT); the title people see starts in it too.
import { useState } from "react";
import { api, errorMessage, type ClientFolder } from "../../lib/ipc";
import { projectLabel, projectTree, visibleRows } from "../../lib/projects";
import { expiryChoices, expiryTime, type ExpiryChoice } from "../../lib/sharing";
import type { Notify } from "../../lib/notice";
import { t, useT } from "../../i18n";
import { Banner, Button, Icons, Select, Sheet, TextField } from "../components";

/** A whole number from a field, or null when it is empty. */
const count = (text: string): number | null => (text.trim() === "" ? null : Number.parseInt(text, 10));

export const requestIntro = () => t("Anyone with a request link can send you voice notes, audio and video, without an account. They become recordings in the project you choose, or in no project; a project also takes PDF, Word, text and images into its Files.");

export function RequestLinkSheet({ projects, projectId = null, notify, onMade, onShowLinks, onClose }: {
  projects: readonly ClientFolder[];
  /** The project new links send into (null: none). */
  projectId?: string | null;
  notify: Notify;
  /** A link was made (the Request links tab reads its list again). */
  onMade?: () => void;
  /** Shared › Request links: every request link with what it received, and Revoke. The sheet closes first. */
  onShowLinks: () => void;
  onClose: () => void;
}) {
  const { t } = useT();
  const [title, setTitle] = useState(() => t("Send me your voice notes"));
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
      onMade?.();
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
      notify(t("Link copied"), false, { kind: "shared", tab: "requests" });
    } catch {
      notify(t("Could not access the clipboard"), true);
    }
  };
  const places = [{ value: "", label: t("No project") }, ...visibleRows(projectTree(projects), "all").map(({ project }) => ({ value: project.id, label: projectLabel(projects, project.id) }))];

  return (
    <Sheet open title={t("Request files")} description={requestIntro()} onClose={onClose}
      actions={<>
        <Button disabled={busy} onClick={onClose}>{t("Close")}</Button>
        <Button variant="primary" disabled={!valid} loading={busy} loadingLabel={t("Making the link…")} onClick={() => void make()}>{t("Make link")}</Button>
      </>}>
      <div className="el-form el-form--flush">
        <TextField label={t("Title people see")} maxLength={200} value={title} onChange={(e) => setTitle(e.target.value)} />
        <Select label={t("Files go to")} options={places} value={where} onChange={setWhere} />
        <div className="el-form__row">
          <Select label={t("Stops working")} options={expiryChoices().map(([value, label]) => ({ value, label }))} value={expiry} onChange={(v) => setExpiry(v as ExpiryChoice)} />
          {expiry === "date" && <TextField label={t("Day")} type="date" value={day} onChange={(e) => setDay(e.target.value)} />}
        </div>
        <div className="el-form__row">
          <TextField label={t("At most")} hint={t("files, 1 to 100")} type="number" min={1} max={100} value={maxFiles} onChange={(e) => setMaxFiles(e.target.value)}
            error={filesOk ? undefined : t("Choose from 1 to 100 files.")} />
          <TextField label={t("Largest file in MB")} hint={t("MB each, 1 to 2048")} type="number" min={1} max={2048} value={maxMb} onChange={(e) => setMaxMb(e.target.value)}
            error={mbOk ? undefined : t("Choose from 1 to 2048 MB.")} />
        </div>
        {error && <Banner tone="danger" icon={Icons.ErrorIcon}>{error}</Banner>}
        {made && (
          <div className="el-group" role="status">
            <span className="el-secondary">{t("Copy it now. It is shown only once.")}</span>
            <code className="el-code" dir="ltr">{made}</code>
            <Button icon={Icons.CopyIcon} onClick={() => void copy()}>{t("Copy link")}</Button>
          </div>
        )}
        <Button variant="quiet" size="sm" icon={Icons.RequestFilesIcon} onClick={() => { onClose(); onShowLinks(); }}>{t("See your request links")}</Button>
      </div>
    </Sheet>
  );
}
