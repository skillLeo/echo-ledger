//! Files in and out of the app: which files can be imported and the title each gets, the names of saved files, and
//! the native open and save dialogs. Every export asks where to save; the webview never chooses a path itself.

use std::path::{Path, PathBuf};

use serde::Serialize;
use tauri::{AppHandle, Manager};

/// What the Import button and a drop on the main window take: WhatsApp voice notes (.opus, .ogg), the common audio
/// files, and .mp4 / .webm videos, whose sound track the engines transcribe. Each one maps to a type in the server's
/// `accepted_mimes` (`uploader::mime_for`).
pub const IMPORT_EXTENSIONS: [&str; 11] = ["opus", "ogg", "oga", "mp3", "m4a", "aac", "wav", "flac", "amr", "mp4", "webm"];

/// The longest title the server keeps (docs/API.md `PATCH /api/sessions/{id}`).
pub const MAX_TITLE_CHARS: usize = 200;

pub fn is_importable(path: &Path) -> bool {
    path.extension().and_then(|e| e.to_str()).is_some_and(|e| IMPORT_EXTENSIONS.contains(&e.to_ascii_lowercase().as_str()))
}

/// The title of an imported file: its name without the extension, underscores as spaces, at most 200 characters.
pub fn title_from_file_name(path: &Path) -> Option<String> {
    let stem = path.file_stem()?.to_string_lossy().replace('_', " ");
    let title: String = stem.split_whitespace().collect::<Vec<_>>().join(" ").chars().take(MAX_TITLE_CHARS).collect();
    let title = title.trim_end();
    (!title.is_empty()).then(|| title.to_string())
}

/// What one import did, for the notice after the batch sheet.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ImportSummary {
    /// Files queued for upload, one recording each.
    pub queued: usize,
    /// Files skipped because the same file (by SHA-256) is already in the upload queue.
    pub duplicates: usize,
    /// Files of a type that cannot be imported.
    pub unsupported: usize,
    /// Files that could not be read or copied.
    pub failed: usize,
}

/// What a client's workspace takes (docs/API.md `POST /api/clients/{id}/files`): PDF, Word, text and Markdown.
pub const CLIENT_FILE_EXTENSIONS: [&str; 4] = ["pdf", "docx", "txt", "md"];

/// The largest file a client's workspace takes.
pub const MAX_CLIENT_FILE_BYTES: u64 = 20 * 1024 * 1024;

/// The type a client's workspace file is sent as, by its extension; `None` for a file the server would refuse.
pub fn client_file_mime(path: &Path) -> Option<&'static str> {
    match path.extension()?.to_str()?.to_ascii_lowercase().as_str() {
        "pdf" => Some("application/pdf"),
        "docx" => Some("application/vnd.openxmlformats-officedocument.wordprocessingml.document"),
        "txt" => Some("text/plain"),
        "md" => Some("text/markdown"),
        _ => None,
    }
}

/// The notice after the server answered `409 duplicate_recording` for imported files.
pub fn duplicates_notice(count: usize) -> String {
    if count == 1 {
        "1 file was already in your Library, so it was skipped.".into()
    } else {
        format!("{count} files were already in your Library, so they were skipped.")
    }
}

/// The extension a saved recording gets for its stored MIME type.
pub fn audio_extension(mime: &str) -> Option<&'static str> {
    match mime.split(';').next().unwrap_or_default().trim().to_ascii_lowercase().as_str() {
        "audio/flac" | "audio/x-flac" => Some("flac"),
        "audio/wav" | "audio/x-wav" | "audio/wave" => Some("wav"),
        "audio/ogg" | "audio/opus" => Some("ogg"),
        "audio/mpeg" | "audio/mp3" => Some("mp3"),
        "audio/mp4" | "audio/m4a" | "audio/x-m4a" => Some("m4a"),
        "audio/aac" => Some("aac"),
        "audio/webm" => Some("webm"),
        "audio/amr" => Some("amr"),
        _ => None,
    }
}

/// `text` without the characters file systems refuse, single-spaced; `fallback` when nothing is left.
pub fn safe_file_name(text: &str, fallback: &str) -> String {
    let name: String = text.chars().filter(|c| !matches!(c, '/' | '\\' | ':' | '*' | '?' | '"' | '<' | '>' | '|') && !c.is_control()).collect();
    let name = name.split_whitespace().collect::<Vec<_>>().join(" ");
    let name = name.trim_matches('.').trim();
    if name.is_empty() { fallback.to_string() } else { name.to_string() }
}

/// The name Save audio offers: the recording's title and day, with the extension of its primary file
/// (`GET /api/sessions/{id}`: `title`, `started_at`, `recordings[].mime`).
pub fn audio_file_name(detail: &serde_json::Value) -> String {
    let title = safe_file_name(detail.get("title").and_then(serde_json::Value::as_str).unwrap_or_default(), "Recording");
    let day = detail.get("started_at").and_then(serde_json::Value::as_str).and_then(|t| t.get(..10)).filter(|d| d.chars().all(|c| c.is_ascii_digit() || c == '-'));
    let mime = detail
        .get("recordings")
        .and_then(serde_json::Value::as_array)
        .and_then(|list| list.iter().find(|r| r.get("role").and_then(serde_json::Value::as_str) == Some("primary")).or(list.first()))
        .and_then(|r| r.get("mime"))
        .and_then(serde_json::Value::as_str)
        .unwrap_or_default();
    let stem = match day {
        Some(day) if !title.contains(day) => format!("{title} {day}"),
        _ => title,
    };
    match audio_extension(mime) {
        Some(ext) => format!("{stem}.{ext}"),
        None => stem,
    }
}

/// The kind of file the save dialog's filter names, for an export format.
pub fn format_label(format: &str) -> &'static str {
    match format {
        "docx" => "Word document",
        "txt" => "Text",
        "md" => "Markdown",
        "srt" | "vtt" => "Subtitles",
        "json" => "JSON",
        "zip" => "Zip archive",
        _ => "File",
    }
}

/// The chosen path, with `extension` added when the person typed a name without one.
pub fn with_extension(path: PathBuf, extension: Option<&str>) -> PathBuf {
    match extension {
        Some(ext) if path.extension().is_none() => path.with_extension(ext),
        _ => path,
    }
}

/// Shows a native file dialog over the main window and hands back its answer. The dialog is built on the main thread
/// (macOS requires it) and awaited on a thread of its own, so nothing waits on the event loop.
#[cfg(any(windows, target_os = "macos"))]
async fn file_dialog<T, F>(app: &AppHandle, build: impl FnOnce(rfd::AsyncFileDialog) -> F + Send + 'static) -> Option<T>
where
    T: Send + 'static,
    F: std::future::Future<Output = T> + Send + 'static,
{
    let window = app.get_webview_window(crate::lifecycle::MAIN);
    let (tx, rx) = tokio::sync::oneshot::channel();
    app.run_on_main_thread(move || {
        let mut dialog = rfd::AsyncFileDialog::new();
        if let Some(window) = &window {
            dialog = dialog.set_parent(window);
        }
        let answer = build(dialog);
        std::thread::spawn(move || {
            let _ = tx.send(tauri::async_runtime::block_on(answer));
        });
    })
    .ok()?;
    rx.await.ok()
}

/// Asks where to save `file_name` (in Downloads unless the person goes elsewhere); `None` when they cancel.
#[cfg(any(windows, target_os = "macos"))]
pub async fn ask_save_path(app: &AppHandle, file_name: &str, kind: &str) -> Option<PathBuf> {
    let extension = Path::new(file_name).extension().and_then(|e| e.to_str()).map(str::to_string);
    let (name, kind, ext) = (file_name.to_string(), kind.to_string(), extension.clone());
    let downloads = app.path().download_dir().ok();
    let chosen = file_dialog(app, move |dialog| {
        let mut dialog = dialog.set_title("Save as").set_file_name(name);
        if let Some(dir) = downloads {
            dialog = dialog.set_directory(dir);
        }
        if let Some(ext) = ext {
            dialog = dialog.add_filter(kind, &[ext]);
        }
        dialog.save_file()
    })
    .await
    .flatten()?;
    Some(with_extension(chosen.path().to_path_buf(), extension.as_deref()))
}

/// The Import button's picker: several files at once, of the types `IMPORT_EXTENSIONS` lists. Empty when cancelled.
#[cfg(any(windows, target_os = "macos"))]
pub async fn pick_import_files(app: &AppHandle) -> Vec<PathBuf> {
    let chosen = file_dialog(app, |dialog| dialog.set_title("Import voice notes, audio or video").add_filter("Voice notes, audio and video", &IMPORT_EXTENSIONS).pick_files()).await;
    chosen.flatten().unwrap_or_default().iter().map(|f| f.path().to_path_buf()).collect()
}

/// The client workspace's picker: several files at once, of the types `CLIENT_FILE_EXTENSIONS` lists. Empty when
/// cancelled.
#[cfg(any(windows, target_os = "macos"))]
pub async fn pick_client_files(app: &AppHandle) -> Vec<PathBuf> {
    let chosen = file_dialog(app, |dialog| dialog.set_title("Add files for this client").add_filter("PDF, Word, text and Markdown", &CLIENT_FILE_EXTENSIONS).pick_files()).await;
    chosen.flatten().unwrap_or_default().iter().map(|f| f.path().to_path_buf()).collect()
}

/// Other systems have no native dialog here: nothing is chosen.
#[cfg(not(any(windows, target_os = "macos")))]
pub async fn ask_save_path(_app: &AppHandle, _file_name: &str, _kind: &str) -> Option<PathBuf> {
    None
}

#[cfg(not(any(windows, target_os = "macos")))]
pub async fn pick_import_files(_app: &AppHandle) -> Vec<PathBuf> {
    Vec::new()
}

#[cfg(not(any(windows, target_os = "macos")))]
pub async fn pick_client_files(_app: &AppHandle) -> Vec<PathBuf> {
    Vec::new()
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn whatsapp_notes_audio_and_videos_can_be_imported() {
        for name in ["PTT-20261030-WA0003.opus", "a.OGG", "a.oga", "a.mp3", "a.m4a", "a.aac", "a.wav", "a.flac", "a.amr", "clip.mp4", "clip.WEBM"] {
            assert!(is_importable(Path::new(name)), "{name}");
        }
        for name in ["notes.txt", "photo.jpg", "movie.mkv", "no_extension", ".opus"] {
            assert!(!is_importable(Path::new(name)), "{name}");
        }
    }

    #[test]
    fn every_importable_type_is_uploaded_as_an_accepted_audio_type() {
        // Videos go up as their container type; the engines read the sound track.
        assert_eq!(crate::net::uploader::mime_for(Path::new("clip.mp4")).0, "audio/mp4");
        assert_eq!(crate::net::uploader::mime_for(Path::new("clip.webm")).0, "audio/webm");
        assert_eq!(crate::net::uploader::mime_for(Path::new("PTT-1.opus")).0, "audio/ogg");
        for ext in IMPORT_EXTENSIONS {
            let mime = crate::net::uploader::mime_for(Path::new(&format!("a.{ext}"))).0;
            assert!(mime.starts_with("audio/"), "{ext} -> {mime}");
        }
    }

    #[test]
    fn the_title_is_the_file_name_without_extension_and_underscores() {
        assert_eq!(title_from_file_name(Path::new("C:/Users/me/Call_with_Acme_Oct.m4a")).as_deref(), Some("Call with Acme Oct"));
        assert_eq!(title_from_file_name(Path::new("/tmp/PTT-20261030-WA0003.opus")).as_deref(), Some("PTT-20261030-WA0003"));
        assert_eq!(title_from_file_name(Path::new("__budget__  review_.mp3")).as_deref(), Some("budget review"));
        assert_eq!(title_from_file_name(Path::new("archive.v2.wav")).as_deref(), Some("archive.v2"));
        assert_eq!(title_from_file_name(Path::new("___.wav")), None);
    }

    #[test]
    fn a_long_file_name_gives_a_title_of_200_characters() {
        let long = format!("{}.mp3", "ب".repeat(250));
        let title = title_from_file_name(Path::new(&long)).unwrap();
        assert_eq!(title.chars().count(), MAX_TITLE_CHARS);
    }

    #[test]
    fn client_files_are_sent_with_the_type_of_their_extension() {
        assert_eq!(client_file_mime(Path::new("C:/Acme/Scope.PDF")), Some("application/pdf"));
        assert_eq!(client_file_mime(Path::new("brief.docx")), Some("application/vnd.openxmlformats-officedocument.wordprocessingml.document"));
        assert_eq!(client_file_mime(Path::new("rules.txt")), Some("text/plain"));
        assert_eq!(client_file_mime(Path::new("notes.Md")), Some("text/markdown"));
        for name in ["old.doc", "sheet.xlsx", "photo.png", "call.mp3", "no_extension", ".pdf"] {
            assert_eq!(client_file_mime(Path::new(name)), None, "{name}");
        }
        for ext in CLIENT_FILE_EXTENSIONS {
            assert!(client_file_mime(Path::new(&format!("a.{ext}"))).is_some(), "the picker offers {ext}");
        }
    }

    #[test]
    fn the_duplicate_notice_counts_the_files() {
        assert_eq!(duplicates_notice(1), "1 file was already in your Library, so it was skipped.");
        assert_eq!(duplicates_notice(3), "3 files were already in your Library, so they were skipped.");
    }

    #[test]
    fn saved_audio_is_named_after_the_title_and_day_with_its_type() {
        let detail = json!({"title": "Zoom: Acme / budget", "started_at": "2026-10-24T09:00:00+00:00",
                            "recordings": [{"role": "channel_1", "mime": "audio/wav"}, {"role": "primary", "mime": "audio/flac"}]});
        assert_eq!(audio_file_name(&detail), "Zoom Acme budget 2026-10-24.flac");
        assert_eq!(audio_file_name(&json!({"title": null, "recordings": [{"role": "primary", "mime": "audio/mp4"}]})), "Recording.m4a");
        assert_eq!(audio_file_name(&json!({"title": "Note", "recordings": []})), "Note");
        assert_eq!(audio_extension("audio/ogg; codecs=opus"), Some("ogg"));
        assert_eq!(audio_extension("application/octet-stream"), None);
    }

    #[test]
    fn a_typed_name_without_extension_gets_one() {
        assert_eq!(with_extension(PathBuf::from("C:/out/call"), Some("docx")), PathBuf::from("C:/out/call.docx"));
        assert_eq!(with_extension(PathBuf::from("C:/out/call.txt"), Some("docx")), PathBuf::from("C:/out/call.txt"));
        assert_eq!(with_extension(PathBuf::from("C:/out/call"), None), PathBuf::from("C:/out/call"));
    }
}
