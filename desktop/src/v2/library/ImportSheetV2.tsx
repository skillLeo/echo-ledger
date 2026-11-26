// The import sheet in the new design (classic's ImportSheet, the same rules and words): files picked with Upload or
// dropped on the window become one recording each, named after the file, with one project and language for the whole
// batch. The core copies them into the upload queue (import_voice_notes); what it couldn't take is said after. Its
// words are in the window's language (desktop Urdu, D1b), the notice after it too (lib/importFiles' importNotice).
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, type ClientFolder, type TranscriptionLanguage } from "../../lib/ipc";
import { MAIN_LANGUAGES, MORE_LANGUAGES, menuLabel } from "../../lib/languages";
import { fileName, importNotice, importTitle, isImportable } from "../../lib/importFiles";
import { projectLabel, projectTree, visibleRows } from "../../lib/projects";
import { NO_TARGET, type Notify } from "../../lib/notice";
import { Button, Icon, Icons, Select, Sheet } from "../components";
import { lineDir, t, tc, useLanguage } from "../../i18n";

/** "Import :count file|Import :count files": the heading's line, and the button's for more than one file. */
const importFiles = (count: number) => tc("Import :count file|Import :count files", count);

/** "Import 3 files", "Import 1 file", "Nothing to import", in the window's language. */
export const importHeading = (count: number) => (count === 0 ? t("Nothing to import") : importFiles(count));

export function ImportSheetV2({ paths, notify, onClose }: { paths: string[]; notify: Notify; onClose: () => void }) {
  // Drawn again in the new language when it changes.
  useLanguage();
  const [projects, setProjects] = useState<ClientFolder[]>([]);
  const [projectId, setProjectId] = useState("");
  const [language, setLanguage] = useState("");
  const [busy, setBusy] = useState(false);
  const importButton = useRef<HTMLButtonElement | null>(null);
  const accepted = paths.filter(isImportable);
  const count = accepted.length;

  useEffect(() => {
    // Offline the sheet still works: the batch is imported without a project.
    api.clients().then((r) => setProjects(r.data ?? [])).catch(() => undefined);
  }, []);

  const start = async () => {
    setBusy(true);
    try {
      // Every path goes to the core, which decides what can be imported and counts the rest.
      const summary = await api.importVoiceNotes(paths, projectId || null, (language || null) as TranscriptionLanguage | null);
      notify(importNotice(summary), summary.queued === 0 && summary.duplicates === 0, summary.queued > 0 ? { kind: "page", page: "activity" } : NO_TARGET);
      onClose();
    } catch (e) {
      notify(errorMessage(e), true);
      setBusy(false);
    }
  };

  const places = [{ value: "", label: t("No project") }, ...visibleRows(projectTree(projects), "all").map(({ project }) => ({ value: project.id, label: projectLabel(projects, project.id) }))];
  const languages = [...MAIN_LANGUAGES.map((o) => ({ value: o.value ?? "", label: menuLabel(o) })), ...MORE_LANGUAGES.map((o) => ({ value: o.value ?? "", label: o.label }))];
  return (
    <Sheet open title={importHeading(count)} description={t("Each file becomes its own recording, named after the file. Videos are transcribed from their sound.")}
      onClose={() => { if (!busy) onClose(); }} closeLabel={t("Close")} initialFocus={importButton}
      actions={<>
        <Button disabled={busy} onClick={onClose}>{t("Cancel")}</Button>
        <Button ref={importButton} variant="primary" icon={Icons.ShareIcon} disabled={count === 0} loading={busy} loadingLabel={t("Importing…")} onClick={() => void start()}>
          {count <= 1 ? t("Import") : importFiles(count)}
        </Button>
      </>}>
      <div className="el-form el-form--flush">
        <ul className="el-import__files" aria-label={t("Files to import")}>
          {paths.map((path, i) => {
            const ok = isImportable(path);
            const title = (ok && importTitle(path)) || fileName(path);
            const line = ok ? fileName(path) : t("Can't be imported: not a voice note, audio or video file");
            return (
              // The same file can be picked twice: each row has its own key.
              <li key={`${i}:${path}`} className={`el-import__file${ok ? "" : " el-import__file--no"}`} title={path}>
                <span className="el-body-strong" dir={lineDir(title)}>{title}</span>
                <span className="el-caption el-secondary" dir={lineDir(line)}>{line}</span>
              </li>
            );
          })}
        </ul>
        <Select label={t("Project")} options={places} value={projectId} onChange={setProjectId} />
        <Select label={t("Language")} options={languages} value={language} onChange={setLanguage} />
        <p className="el-caption el-secondary">{t("The project and language apply to every file. Files already in your Library are skipped.")}</p>
      </div>
    </Sheet>
  );
}

/** While files are dragged over the window: where they go when dropped (classic's drop hint, in the new look). */
export function DropHintV2() {
  useLanguage();
  return (
    <div className="el-drop-hint" aria-hidden="true" data-testid="drop-hint">
      <div className="el-drop-hint__card"><Icon icon={Icons.ShareIcon} size="lg" />{t("Drop voice notes, audio or video files to import them")}</div>
    </div>
  );
}
