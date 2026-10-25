//! The activity centre on the desktop (plan B18): new server notifications become OS notifications, except in the
//! quiet hours; clicking one brings EchoLedger forward on that recording. Pure parts are unit-tested.

use std::collections::VecDeque;
use std::path::Path;

use serde::{Deserialize, Serialize};

/// How many notification ids are remembered as already shown.
const SEEN_LIMIT: usize = 200;
/// More new items than this in one poll become one summary notification.
pub const MAX_SINGLE: usize = 3;

/// "22:30" → minutes after midnight.
pub fn parse_hhmm(text: &str) -> Option<u32> {
    let (h, m) = text.split_once(':')?;
    if h.len() != 2 || m.len() != 2 {
        return None;
    }
    let (h, m) = (h.parse::<u32>().ok()?, m.parse::<u32>().ok()?);
    (h < 24 && m < 60).then_some(h * 60 + m)
}

/// Is [now] (minutes after midnight, local) inside the quiet hours? Overnight ranges (22:00 to 07:00) wrap; equal
/// ends mean all day.
pub fn in_quiet_hours(enabled: bool, from: &str, to: &str, now: u32) -> bool {
    let (Some(from), Some(to)) = (parse_hhmm(from), parse_hhmm(to)) else { return false };
    if !enabled {
        return false;
    }
    match from.cmp(&to) {
        std::cmp::Ordering::Equal => true,
        std::cmp::Ordering::Less => now >= from && now < to,
        std::cmp::Ordering::Greater => now >= from || now < to,
    }
}

/// One item of `GET /api/activity`.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct ActivityItem {
    pub id: String,
    #[serde(default)]
    pub kind: Option<String>,
    #[serde(default)]
    pub title: String,
    #[serde(default)]
    pub body: String,
    #[serde(default)]
    pub session_id: Option<String>,
    #[serde(default)]
    pub read: bool,
    #[serde(default)]
    pub at: Option<String>,
}

/// `activity_seen.json`: the ids already shown, so a restart never repeats them.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct Seen {
    /// False until the first poll, which only learns what exists (no flood of old notifications).
    #[serde(default)]
    pub initialised: bool,
    #[serde(default)]
    pub ids: VecDeque<String>,
}

impl Seen {
    pub fn load(path: &Path) -> Self {
        std::fs::read(path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    pub fn save(&self, path: &Path) -> std::io::Result<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec(self)?)?;
        std::fs::rename(tmp, path)
    }

    /// The unread items not shown before, oldest first; remembers them all.
    pub fn fresh(&mut self, items: &[ActivityItem]) -> Vec<ActivityItem> {
        let first = !self.initialised;
        self.initialised = true;
        let mut out = Vec::new();
        for item in items.iter().rev() {
            if self.ids.contains(&item.id) {
                continue;
            }
            self.ids.push_back(item.id.clone());
            if !first && !item.read {
                out.push(item.clone());
            }
        }
        while self.ids.len() > SEEN_LIMIT {
            self.ids.pop_front();
        }
        out
    }
}

/// What the OS notification says. While the app is locked it says nothing about the recording.
pub fn notification_text(items: &[ActivityItem], locked: bool) -> Vec<(String, String)> {
    if locked {
        return vec![("EchoLedger".into(), "New activity. Unlock EchoLedger to see it.".into())];
    }
    if items.len() > MAX_SINGLE {
        return vec![("EchoLedger".into(), format!("{} new notifications", items.len()))];
    }
    items.iter().map(|i| (i.title.clone(), i.body.clone())).collect()
}
