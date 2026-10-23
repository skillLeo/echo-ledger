//! Library sync: `GET /api/library?since=` polling with tombstones, cached on disk for offline viewing.
//! Last-write-wins on `updated_at` (docs/API.md "Library & sync").

use std::path::Path;

use serde::{Deserialize, Serialize};

use super::api::ApiClient;
use super::models::LibraryItem;
use crate::error::AppResult;

#[derive(Default, Serialize, Deserialize)]
pub struct LibraryCache {
    pub since: Option<String>,
    pub items: Vec<LibraryItem>,
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
    pub fn load(path: &Path) -> Self {
        std::fs::read(path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    pub fn save(&self, path: &Path) -> AppResult<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec(self)?)?;
        std::fs::rename(tmp, path)?;
        Ok(())
    }

    /// Pulls all changes since the last sync (paging through cursors).
    pub async fn refresh(&mut self, api: &ApiClient) -> AppResult<usize> {
        let mut cursor: Option<String> = None;
        let mut changed = 0;
        let mut server_time = None;
        loop {
            let page = api.library(self.since.as_deref(), cursor.as_deref()).await?;
            changed += page.data.len();
            server_time.get_or_insert(page.server_time.clone());
            merge(&mut self.items, page.data);
            match page.next_cursor {
                Some(next) => cursor = Some(next),
                None => break,
            }
        }
        if let Some(t) = server_time {
            self.since = Some(t);
        }
        Ok(changed)
    }
}

pub mod paths {
    use std::path::PathBuf;

    pub fn library(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("library.json")
    }

    pub fn uploads(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("uploads.json")
    }

    pub fn recordings(data_dir: &std::path::Path) -> PathBuf {
        data_dir.join("recordings")
    }
}
