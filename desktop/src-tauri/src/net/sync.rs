//! Library sync: `GET /api/library?since=` polling with tombstones, cached on disk for offline viewing.
//! Last-write-wins on `updated_at` (docs/API.md "Library & sync").

use std::collections::HashSet;
use std::path::Path;

use serde::{Deserialize, Serialize};

use super::api::ApiClient;
use super::models::{LibraryItem, OpenedMany};
use crate::error::AppResult;

#[derive(Default, Serialize, Deserialize)]
pub struct LibraryCache {
    pub since: Option<String>,
    pub items: Vec<LibraryItem>,
    /// The saved views' version the last sync carried (contract 2.0.0-p "Saved views"); None before one did.
    #[serde(default)]
    pub views_version: Option<u64>,
}

/// Applies a page of changes: tombstones remove, newer items replace, unknown items insert (pure).
pub fn merge(items: &mut Vec<LibraryItem>, incoming: Vec<LibraryItem>) {
    for item in incoming {
        let pos = items.iter().position(|i| i.id == item.id);
        if item.deleted {
            if let Some(p) = pos {
                items.remove(p);
            }
            continue;
        }
        match pos {
            Some(p) if items[p].updated_at <= item.updated_at => items[p] = item,
            Some(_) => {}
            None => items.push(item),
        }
    }
    items.sort_by(|a, b| {
        let key = |i: &LibraryItem| i.rest.get("started_at").and_then(|v| v.as_str()).unwrap_or("").to_string();
        key(b).cmp(&key(a))
    });
}

impl LibraryCache {
    /// The recording was opened (it is no longer unread): true when the saved copy changed.
    pub fn mark_opened(&mut self, id: &str) -> bool {
        match self.items.iter_mut().find(|i| i.id == id) {
            Some(item) if item.rest.get("opened") != Some(&serde_json::Value::Bool(true)) => {
                item.rest.insert("opened".into(), serde_json::Value::Bool(true));
                true
            }
            _ => false,
        }
    }

    /// The recording is unread again (Mark as unread, 2.0.0-t): true when the saved copy changed.
    pub fn mark_unopened(&mut self, id: &str) -> bool {
        match self.items.iter_mut().find(|i| i.id == id) {
            Some(item) if item.rest.get("opened") != Some(&serde_json::Value::Bool(false)) => {
                item.rest.insert("opened".into(), serde_json::Value::Bool(false));
                true
            }
            _ => false,
        }
    }

    /// Mark as read or unread for many (contract 2.0.0-y): the saved copy follows the server's `done` only, never the
    /// ids it did not find. True when anything changed.
    pub fn mark_opened_many(&mut self, answer: &OpenedMany, opened: bool) -> bool {
        let mut changed = false;
        for id in &answer.done {
            changed |= if opened { self.mark_opened(id) } else { self.mark_unopened(id) };
        }
        changed
    }

    pub fn load(path: &Path) -> Self {
        std::fs::read(path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    pub fn save(&self, path: &Path) -> AppResult<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec(self)?)?;
        std::fs::rename(tmp, path)?;
        Ok(())
    }

    /// Pulls all changes since the last sync (paging through cursors). Paging stops when the server repeats a
    /// `next_cursor` or after [`MAX_PAGES`] pages (A6.DR15); a cursor the server calls invalid restarts paging once
    /// without a cursor (docs/API.md "Library & sync").
    pub async fn refresh(&mut self, api: &ApiClient) -> AppResult<usize> {
        let mut cursor: Option<String> = None;
        let mut seen: HashSet<String> = HashSet::new();
        let mut changed = 0;
        let mut pages = 0usize;
        let mut restarted = false;
        let mut server_time = None;
        loop {
            let page = match api.library(self.since.as_deref(), cursor.as_deref()).await {
                Ok(page) => page,
                Err(e) if e.code() == Some("invalid_cursor") && cursor.is_some() && !restarted => {
                    restarted = true;
                    cursor = None;
                    seen.clear();
                    server_time = None;
                    continue;
                }
                Err(e) => return Err(e),
            };
            pages += 1;
            changed += page.data.len();
            server_time.get_or_insert(page.server_time.clone());
            if page.views_version.is_some() {
                self.views_version = page.views_version;
            }
            merge(&mut self.items, page.data);
            match page.next_cursor {
                Some(next) if pages < MAX_PAGES && seen.insert(next.clone()) => cursor = Some(next),
                _ => break,
            }
        }
        if let Some(t) = server_time {
            self.since = Some(next_since(self.since.as_deref(), t));
        }
        Ok(changed)
    }
}

/// Upper bound of pages one refresh may request.
pub const MAX_PAGES: usize = 1_000;

/// The sync position after a complete sync: the first page's `server_time`, unless the server clock stepped back
/// behind the stored position, which is then kept (pure).
pub fn next_since(stored: Option<&str>, server_time: String) -> String {
    let parse = |v: &str| chrono::DateTime::parse_from_rfc3339(v).ok();
    match (stored.and_then(parse), parse(&server_time)) {
        (Some(old), Some(new)) if new < old => stored.unwrap_or_default().to_string(),
        _ => server_time,
    }
}

pub mod paths {
    use std::path::PathBuf;

    pub fn library(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("library.json")
    }

    /// Server sessions to erase from the Trash once deleted (a call rule's "Don't record", contract 2.0.0-w).
    pub fn purges(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("purges.json")
    }

    /// Activity notifications already shown as OS notifications (B18).
    pub fn activity_seen(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("activity_seen.json")
    }

    /// When this computer last ran a Retry all (`retry_requested_at`, BE-03), so each one runs here once.
    pub fn retry_all_ran(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("retry_all_ran.json")
    }

    /// The app lock's PIN hash (B16); kept out of the settings the UI reads.
    pub fn app_lock(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("app_lock.json")
    }

    pub fn uploads(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("uploads.json")
    }

    /// Recordings waiting for Keep / Discard (A2.1).
    pub fn pending(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("pending.json")
    }

    /// Short or silent automatic calls, restorable for 24 hours (v1.5).
    pub fn skipped(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("skipped.json")
    }

    pub fn recordings(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("recordings")
    }

    /// Streaming usage reports waiting for `POST /api/usage`.
    pub fn usage(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("usage.json")
    }

    /// Server sessions waiting for `DELETE /api/sessions/{id}`.
    pub fn discards(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("discards.json")
    }

    /// Ends of calls waiting for `PATCH /api/sessions/{id}` (contract 2.0.0-z).
    pub fn ends(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("ends.json")
    }

    /// The account this computer signs in as (v1.5), kept after signing out until the next sign-in.
    pub fn account(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("account.json")
    }

    /// Usage reports and server deletions set aside for an account while another one is signed in (v1.5).
    pub fn held(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("held.json")
    }
}
