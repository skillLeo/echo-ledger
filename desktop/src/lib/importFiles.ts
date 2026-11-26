// What the import sheet shows before the core imports (files.rs is the authority and uses the same rules): which files
// can be imported and the title each recording gets. The notice after it is in the window's language, or in English
// when the caller says `lang` "en" (classic's import sheet keeps exactly the English it always had).
import type { ImportSummary } from "./ipc";
import { wordsIn, type Lang } from "../i18n";

/** WhatsApp voice notes (.opus, .ogg), audio files, and .mp4 / .webm videos (their sound is transcribed). */
export const IMPORT_EXTENSIONS = ["opus", "ogg", "oga", "mp3", "m4a", "aac", "wav", "flac", "amr", "mp4", "webm"] as const;

/** The longest title the server keeps. */
export const MAX_TITLE_CHARS = 200;

/** The last part of a Windows or macOS path. */
export function fileName(path: string): string {
  return path.split(/[\\/]/).filter(Boolean).pop() ?? path;
}

const extensionOf = (name: string): string | null => {
  const dot = name.lastIndexOf(".");
  return dot > 0 ? name.slice(dot + 1).toLowerCase() : null;
};

export function isImportable(path: string): boolean {
  const ext = extensionOf(fileName(path));
  return ext !== null && (IMPORT_EXTENSIONS as readonly string[]).includes(ext);
}

/** The file name without its extension, underscores as spaces, at most 200 characters; null when nothing is left. */
export function importTitle(path: string): string | null {
  const name = fileName(path);
  const dot = name.lastIndexOf(".");
  const stem = dot > 0 ? name.slice(0, dot) : name;
  const title = [...stem.replace(/_/g, " ").split(/\s+/).filter(Boolean).join(" ")].slice(0, MAX_TITLE_CHARS).join("").trimEnd();
  return title || null;
}

/** The notice after an import: what was queued and what was skipped, and why, each a whole sentence with its count. */
export function importNotice(summary: ImportSummary, lang?: Lang): string {
  const { t, tc } = wordsIn(lang);
  const parts: string[] = [];
  if (summary.queued > 0) parts.push(tc("Importing :count file. It uploads in the background.|Importing :count files. They upload in the background.", summary.queued));
  if (summary.duplicates > 0) parts.push(tc("Skipped :count file already waiting to upload.|Skipped :count files already waiting to upload.", summary.duplicates));
  if (summary.unsupported > 0) {
    parts.push(tc("Skipped :count file that is not a voice note, audio or video file.|Skipped :count files that are not a voice note, audio or video file.", summary.unsupported));
  }
  if (summary.failed > 0) parts.push(tc("Couldn't read :count file.|Couldn't read :count files.", summary.failed));
  return parts.join(" ") || t("Nothing to import.");
}
