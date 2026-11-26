// A project's Files in the new look, as today's: Add files (the native picker), files dropped on the window while this
// tab is open (PDF, Word, text, Markdown), what is uploading and being read, Paste instructions with a name, and Remove
// with today's "Remove … from this project?" confirmation. Its words are the window's language (D-261); a file's name is
// its own, and a format's (PDF, Word, Markdown) stays as it is.
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, listenForFileDrops, type ClientFile } from "../../lib/ipc";
import { claimFileDrops } from "../../lib/dropTarget";
import { confirmAction } from "../../lib/confirm";
import { fileSize as formatSize } from "../../lib/format";
import { CLIENT_FILE_TYPES, fileExtension } from "../../views/main/ClientWorkspace";
import { Button, Card, EmptyState, Icons, List, ListRow, TextArea, TextField } from "../components";
import { lineDir, t, tc, useLanguage } from "../../i18n";
import type { WorkspaceUpdate } from "./WorkspaceV2";

const baseName = (path: string): string => path.split(/[\\/]/).pop() ?? path;
const kindOf = (name: string) => {
  const ext = fileExtension(name);
  // The formats' names are as they are in every language; a plain text file is the window's word (ٹیکسٹ).
  return ext === "pdf" ? t("PDF") : ext === "docx" ? t("workspace_context.kind_word") : ext === "md" ? t("Markdown") : ext === "txt" ? t("workspace_context.kind_text")
    : ext ? ext.toUpperCase() : null;
};

/** "PDF · 3 pages · 120 KB", or what is happening to it, in the window's language. */
export function fileLine(file: ClientFile): string {
  if (file.status === "extracting") return t("Reading…");
  if (file.status === "failed") return file.error ? t("Couldn't read it: :error", { error: file.error }) : t("Couldn't read it");
  return [file.instructions ? t("Instructions") : kindOf(file.name), file.pages ? tc(":count page|:count pages", file.pages) : null, file.size_bytes ? formatSize(file.size_bytes) : null]
    .filter(Boolean).join(" · ");
}

export function FilesTab({ projectId, files, onChanged, say }: {
  projectId: string;
  files: ClientFile[];
  onChanged: WorkspaceUpdate;
  say: (text: string) => void;
}) {
  useLanguage();
  const [uploading, setUploading] = useState<string[]>([]);
  const [over, setOver] = useState(false);
  const [pasting, setPasting] = useState(false);
  const [pasteName, setPasteName] = useState("");
  const [pasteText, setPasteText] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const upload = async (paths: string[]) => {
    const wanted = paths.filter((p) => (CLIENT_FILE_TYPES as readonly string[]).includes(fileExtension(p)));
    if (wanted.length < paths.length) setError(t("Only PDF, Word (.docx), text and Markdown files can be added."));
    for (const path of wanted) {
      setUploading((list) => [...list, path]);
      try {
        const { data } = await api.uploadClientFile(projectId, path);
        onChanged((cur) => ({ ...cur, files: [data, ...cur.files.filter((f) => f.id !== data.id)] }));
      } catch (e) {
        setError(`${baseName(path)}: ${errorMessage(e)}`);
      } finally {
        setUploading((list) => list.filter((p) => p !== path));
      }
    }
  };
  const uploadRef = useRef(upload);
  uploadRef.current = upload;
  // While this tab is open, files dropped on the window are added here instead of being imported as recordings.
  useEffect(() => {
    const release = claimFileDrops((paths) => void uploadRef.current(paths));
    const un = listenForFileDrops({ onEnter: () => setOver(true), onLeave: () => setOver(false), onDrop: () => setOver(false) });
    return () => {
      release();
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  const pick = async () => {
    setError(null);
    try {
      const paths = await api.pickClientFiles();
      if (paths.length > 0) await upload(paths);
    } catch (e) {
      setError(errorMessage(e));
    }
  };
  const paste = async () => {
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.addClientInstructions(projectId, pasteText, pasteName.trim() || null);
      onChanged((cur) => ({ ...cur, files: [data, ...cur.files] }));
      setPasting(false);
      setPasteText("");
      setPasteName("");
      say(t("Instructions saved"));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  const remove = async (file: ClientFile) => {
    if (!(await confirmAction({ title: t("Remove :name from this project?", { name: file.name }), confirm: t("Remove"), tone: "danger" }))) return;
    try {
      await api.deleteClientFile(projectId, file.id);
      onChanged((cur) => ({ ...cur, files: cur.files.filter((f) => f.id !== file.id) }));
    } catch (e) {
      setError(errorMessage(e));
    }
  };

  return (
    <div className="el-workspace-tab">
      <Card className={`el-drop${over ? " el-drop--over" : ""}`} label={t("Add files")}>
        <p className="el-secondary" data-testid="file-drop">{t("Drop PDF, Word, text or Markdown files here. EchoLedger reads them for the brief and for Ask.")}</p>
        <div className="el-page__actions">
          <Button variant="primary" icon={Icons.ShareIcon} onClick={() => void pick()}>{t("Add files")}</Button>
          <Button aria-expanded={pasting} onClick={() => setPasting(!pasting)}>{t("Paste instructions")}</Button>
        </div>
      </Card>
      {pasting && (
        <Card>
          <form className="el-form" onSubmit={(e) => { e.preventDefault(); void paste(); }}>
            <TextField label={t("Name")} value={pasteName} maxLength={120} placeholder={t("House rules")} onChange={(e) => setPasteName(e.target.value)} />
            <TextArea label={t("Instructions")} value={pasteText} rows={6} maxLength={100_000} placeholder={t("Paste instructions, rules or notes for this project")} onChange={(e) => setPasteText(e.target.value)} />
            <div className="el-page__actions">
              <Button type="submit" variant="primary" disabled={!pasteText.trim()} loading={busy} loadingLabel={t("Saving…")}>{t("Save instructions")}</Button>
              <Button variant="quiet" onClick={() => setPasting(false)}>{t("Cancel")}</Button>
            </div>
          </form>
        </Card>
      )}
      {error && <p className="el-field__error" role="alert" dir={lineDir(error)}>{error}</p>}
      <Card>
        {files.length === 0 && uploading.length === 0 ? (
          <EmptyState icon={Icons.TranscriptIcon} title={t("No files yet")} body={t("A job description, a proposal, last month's invoices: Ask and the brief read them.")} />
        ) : (
          <List label={t("Files")} className="el-group__rows">
            {uploading.map((path) => <ListRow key={path} compact iconStyle="plain" icon={Icons.TranscriptIcon} title={baseName(path)} meta={t("Uploading…")} />)}
            {files.map((file) => (
              <ListRow key={file.id} compact iconStyle="plain" icon={file.status === "failed" ? Icons.ErrorIcon : Icons.TranscriptIcon} title={file.name} meta={fileLine(file)}
                control={<Button size="sm" variant="quiet" icon={Icons.TrashIcon} aria-label={t("Remove :name", { name: file.name })} onClick={() => void remove(file)} />} />
            ))}
          </List>
        )}
      </Card>
    </div>
  );
}
