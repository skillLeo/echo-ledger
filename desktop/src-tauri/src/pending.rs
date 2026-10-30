//! Keep / Discard after a recording ends (A2.1-A2.6). A finished recording that needs a decision stays on this
//! computer as a `PendingDecision` in `pending.json` (atomic writes, tolerant loading, restored at start) and is
//! not uploaded. Keep moves it to the upload queue; Discard deletes its files, uploads nothing, requests no
//! transcript and queues its server session (if captions opened one) for deletion. The functions here touch only
//! files, so the whole flow is unit-tested; `actions` adds locking, events and the review card.

use std::collections::HashMap;
use std::path::{Path, PathBuf};

use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::audio::{FinishedRecording, RecordingMode};
use crate::error::{AppError, AppResult};
use crate::net::models::{CallerDto, LanguageMarkDto, MarkerDto, PauseDto, SegmentDto};
use crate::net::sync::paths;
use crate::net::uploader::{DiscardQueue, PendingUpload, UploadQueue};
use crate::settings::{AskBeforeSaving, DecisionTimeout};

/// Every `pending_*` command answers this when the id is not (or no longer) waiting.
pub const NOT_WAITING: &str = "This recording is no longer waiting.";
pub const TITLE_TOO_LONG: &str = "Use 200 characters or fewer.";
/// "If I don't choose within 60 s".
pub const DECISION_WINDOW_SECS: i64 = 60;
const MAX_TITLE_CHARS: usize = 200;
const MAX_FIRST_LINE_CHARS: usize = 140;
/// The title of a recording recovered after a restart (since v1.3).
pub const RECOVERED_TITLE: &str = "Recovered recording";

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Decision {
    Keep,
    Discard,
}

/// Whether a finished recording waits for Keep / Discard (A2.6): Always → yes; Only automatic calls → only
/// recordings auto mode started; Never → no. Voice notes come here with `auto = false`; imports and dictation never.
pub fn should_ask(policy: AskBeforeSaving, auto: bool, mode: RecordingMode) -> bool {
    let _ = mode; // every recording mode follows the same rule
    match policy {
        AskBeforeSaving::Always => true,
        AskBeforeSaving::AutoOnly => auto,
        AskBeforeSaving::Never => false,
    }
}

/// What happens to an undecided recording when its time is up (A2.5); `None` = keep waiting.
pub fn timeout_action(policy: DecisionTimeout) -> Option<Decision> {
    match policy {
        DecisionTimeout::Keep => Some(Decision::Keep),
        DecisionTimeout::Discard => Some(Decision::Discard),
        DecisionTimeout::Wait => None,
    }
}

/// When the timeout applies: 60 s after the recording ended, or never for "Keep waiting".
pub fn deadline(created_at: DateTime<Utc>, policy: DecisionTimeout) -> Option<DateTime<Utc>> {
    timeout_action(policy).map(|_| created_at + chrono::Duration::seconds(DECISION_WINDOW_SECS))
}

/// The review card's preview: the first final caption turn as `Me: …` / `Client: …`, at most 140 characters.
pub fn first_line(segments: &[SegmentDto]) -> Option<String> {
    let first = segments.iter().filter(|s| !s.text.trim().is_empty()).min_by_key(|s| (s.start_ms, s.channel))?;
    let line = format!("{}: {}", first.speaker, first.text.trim());
    if line.chars().count() <= MAX_FIRST_LINE_CHARS {
        return Some(line);
    }
    let mut short: String = line.chars().take(MAX_FIRST_LINE_CHARS - 1).collect();
    short.push('…');
    Some(short)
}

/// A title as the user typed it: trimmed, empty means none, at most 200 characters.
pub fn normalize_title(title: &str) -> AppResult<Option<String>> {
    let title = title.trim();
    if title.chars().count() > MAX_TITLE_CHARS {
        return Err(AppError::msg(TITLE_TOO_LONG));
    }
    Ok((!title.is_empty()).then(|| title.to_string()))
}

/// A recording waiting for Keep / Discard, as the UI sees it (section 4.3). Times are RFC 3339 UTC;
/// `deadlineAt` is `null` for "Keep waiting".
#[derive(Clone, Debug, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct PendingItem {
    pub id: String,
    pub source_app: String,
    pub auto: bool,
    pub mode: RecordingMode,
    pub title: Option<String>,
    pub client_id: Option<String>,
    pub client_name: Option<String>,
    pub started_at: String,
    pub ended_at: String,
    pub duration_ms: u64,
    pub first_line: Option<String>,
    pub deadline_at: Option<String>,
    /// The language the final transcript will use (none = automatic: the client's or the console default).
    pub language: Option<String>,
}

/// What `from_recording` needs besides the finished files.
#[derive(Clone, Debug)]
pub struct DecisionInput {
    pub auto: bool,
    pub client_name: Option<String>,
    pub live_segments: Vec<SegmentDto>,
    pub live_provider: Option<String>,
    pub server_session_id: Option<String>,
    pub created_at: DateTime<Utc>,
    /// The transcription language chosen in Settings when the recording ended (none = automatic).
    pub language: Option<String>,
    /// The language chip changes during the recording (since v1.2).
    pub language_marks: Vec<LanguageMarkDto>,
    /// Who the recording was with (since v1.2: a meeting window's title).
    pub caller: Option<CallerDto>,
}

/// One entry of `pending.json`. The UI fields are public; the files, the live transcript and the server session
/// stay inside the core.
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct PendingDecision {
    pub id: String,
    pub client_ref: String,
    pub source_app: String,
    #[serde(default)]
    pub auto: bool,
    pub mode: RecordingMode,
    #[serde(default)]
    pub title: Option<String>,
    #[serde(default)]
    pub client_id: Option<String>,
    #[serde(default)]
    pub client_name: Option<String>,
    pub started_at: String,
    pub ended_at: String,
    pub duration_ms: u64,
    #[serde(default)]
    pub first_line: Option<String>,
    pub created_at: DateTime<Utc>,
    dir: PathBuf,
    primary: PathBuf,
    primary_channels: u16,
    #[serde(default)]
    mic_wav: Option<PathBuf>,
    #[serde(default)]
    system_wav: Option<PathBuf>,
    #[serde(default)]
    live_segments: Vec<SegmentDto>,
    #[serde(default)]
    live_provider: Option<String>,
    #[serde(default)]
    server_session_id: Option<String>,
    #[serde(default)]
    markers: Vec<MarkerDto>,
    #[serde(default)]
    pauses: Vec<PauseDto>,
    #[serde(default)]
    language: Option<String>,
    #[serde(default)]
    language_marks: Vec<LanguageMarkDto>,
    #[serde(default)]
    caller: Option<CallerDto>,
}

impl PendingDecision {
    /// The decision for a finished recording; its id is the recording folder's name (the upload item keeps it).
    pub fn from_recording(finished: &FinishedRecording, input: DecisionInput) -> Self {
        let output = &finished.output;
        let info = &finished.info;
        let id = output.dir.file_name().and_then(|n| n.to_str()).filter(|n| !n.is_empty()).map(str::to_string).unwrap_or_else(|| info.client_ref.clone());
        let call = info.mode == RecordingMode::Call;
        Self {
            id,
            client_ref: info.client_ref.clone(),
            source_app: info.source_app.clone(),
            auto: input.auto,
            mode: info.mode,
            title: None,
            client_id: info.client_id.clone(),
            client_name: input.client_name.filter(|_| info.client_id.is_some()),
            started_at: info.started_at.to_rfc3339(),
            ended_at: finished.ended_at.to_rfc3339(),
            duration_ms: output.duration_ms,
            first_line: first_line(&input.live_segments),
            created_at: input.created_at,
            dir: output.dir.clone(),
            primary: output.primary.clone(),
            primary_channels: output.channels,
            mic_wav: call.then(|| output.mic_wav.clone()),
            system_wav: call.then(|| output.system_wav.clone()),
            live_segments: input.live_segments,
            live_provider: input.live_provider,
            server_session_id: input.server_session_id,
            markers: finished.markers.clone(),
            pauses: finished.pauses.clone(),
            language: input.language,
            language_marks: input.language_marks,
            caller: input.caller,
        }
    }

    /**
     * A recording found cut short after a restart (since v1.3, see `recovery`): its two WAVs were encoded again, it
     * waits for Keep or Discard like any other, titled so it is recognised. The app it came from is not known.
     */
    #[allow(clippy::too_many_arguments)]
    pub fn recovered(
        dir: PathBuf,
        primary: PathBuf,
        mic_wav: PathBuf,
        system_wav: PathBuf,
        duration_ms: u64,
        started_at: DateTime<Utc>,
        ended_at: DateTime<Utc>,
        created_at: DateTime<Utc>,
    ) -> Self {
        let id = dir.file_name().and_then(|n| n.to_str()).unwrap_or_default().to_string();
        Self {
            id,
            client_ref: uuid::Uuid::new_v4().to_string(),
            source_app: "other".into(),
            auto: false,
            mode: RecordingMode::Call,
            title: Some(RECOVERED_TITLE.into()),
            client_id: None,
            client_name: None,
            started_at: started_at.to_rfc3339(),
            ended_at: ended_at.to_rfc3339(),
            duration_ms,
            first_line: None,
            created_at,
            dir,
            primary,
            primary_channels: 2,
            mic_wav: Some(mic_wav),
            system_wav: Some(system_wav),
            live_segments: Vec::new(),
            live_provider: None,
            server_session_id: None,
            markers: Vec::new(),
            pauses: Vec::new(),
            language: None,
            language_marks: Vec::new(),
            caller: None,
        }
    }

    pub fn item(&self, policy: DecisionTimeout) -> PendingItem {
        PendingItem {
            id: self.id.clone(),
            source_app: self.source_app.clone(),
            auto: self.auto,
            mode: self.mode,
            title: self.title.clone(),
            client_id: self.client_id.clone(),
            client_name: self.client_name.clone(),
            started_at: self.started_at.clone(),
            ended_at: self.ended_at.clone(),
            duration_ms: self.duration_ms,
            first_line: self.first_line.clone(),
            deadline_at: deadline(self.created_at, policy).map(|d| d.to_rfc3339()),
            language: self.language.clone(),
        }
    }

    /// The open server session captions created for this recording, if any.
    pub fn server_session_id(&self) -> Option<&str> {
        self.server_session_id.as_deref()
    }

    /// The recording folder (deleted by Discard).
    pub fn dir(&self) -> &Path {
        &self.dir
    }

    /// The upload queue entry Keep creates: the open session (finished instead of creating a new one), the
    /// title and the client go with it.
    pub fn to_upload(&self) -> PendingUpload {
        PendingUpload {
            client_ref: self.client_ref.clone(),
            source_app: self.source_app.clone(),
            client_id: self.client_id.clone(),
            started_at: self.started_at.clone(),
            ended_at: self.ended_at.clone(),
            duration_ms: self.duration_ms,
            dir: self.dir.clone(),
            primary: self.primary.clone(),
            primary_channels: self.primary_channels,
            mic_wav: self.mic_wav.clone(),
            system_wav: self.system_wav.clone(),
            live_segments: self.live_segments.clone(),
            live_provider: self.live_provider.clone(),
            server_session_id: self.server_session_id.clone(),
            title: self.title.clone(),
            markers: self.markers.clone(),
            pauses: self.pauses.clone(),
            language: self.language.clone(),
            language_marks: self.language_marks.clone(),
            caller: self.caller.clone(),
            ..PendingUpload::default()
        }
    }
}

/// `pending.json`.
#[derive(Clone, Debug, Default, Serialize)]
pub struct PendingStore {
    pub items: Vec<PendingDecision>,
}

impl PendingStore {
    /// Tolerant like the other queues: a missing or broken file is empty, and one unreadable entry costs only
    /// that entry.
    pub fn load(path: &Path) -> Self {
        let Some(Value::Object(mut file)) = std::fs::read(path).ok().and_then(|b| serde_json::from_slice::<Value>(&b).ok()) else {
            return Self::default();
        };
        let Some(Value::Array(items)) = file.remove("items") else { return Self::default() };
        Self { items: items.into_iter().filter_map(|v| serde_json::from_value(v).ok()).collect() }
    }

    /// Atomic replace: a temporary file renamed over the old one.
    pub fn save(&self, path: &Path) -> AppResult<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)?;
        std::fs::rename(tmp, path)?;
        Ok(())
    }

    pub fn find(&self, id: &str) -> Option<&PendingDecision> {
        self.items.iter().find(|d| d.id == id)
    }

    /// The UI list, newest first.
    pub fn ui_items(&self, policy: DecisionTimeout) -> Vec<PendingItem> {
        let mut items: Vec<&PendingDecision> = self.items.iter().collect();
        items.sort_by_key(|d| std::cmp::Reverse(d.created_at));
        items.into_iter().map(|d| d.item(policy)).collect()
    }

    /// The earliest moment a timeout applies under `policy`.
    pub fn next_deadline(&self, policy: DecisionTimeout) -> Option<DateTime<Utc>> {
        self.items.iter().filter_map(|d| deadline(d.created_at, policy)).min()
    }
}

/// The queue files a decision touches, all in the app data dir. The caller holds the queue lock.
#[derive(Clone, Debug)]
pub struct DecisionFiles {
    pub pending: PathBuf,
    pub uploads: PathBuf,
    pub discards: PathBuf,
}

impl DecisionFiles {
    pub fn in_dir(data_dir: &Path) -> Self {
        Self { pending: paths::pending(data_dir), uploads: paths::uploads(data_dir), discards: paths::discards(data_dir) }
    }

    pub fn load(&self) -> PendingStore {
        PendingStore::load(&self.pending)
    }

    pub fn add(&self, decision: PendingDecision) -> AppResult<()> {
        let mut store = self.load();
        store.items.retain(|d| d.id != decision.id);
        store.items.push(decision);
        store.save(&self.pending)
    }

    /// Keep: the recording joins the upload queue (once), then leaves `pending.json`, so a crash in between can
    /// never lose it.
    pub fn keep(&self, id: &str) -> AppResult<PendingUpload> {
        let mut store = self.load();
        let at = store.items.iter().position(|d| d.id == id).ok_or_else(|| AppError::msg(NOT_WAITING))?;
        let upload = store.items[at].to_upload();
        let mut queue = UploadQueue::load(&self.uploads);
        if !queue.items.iter().any(|i| i.id() == upload.id()) {
            queue.items.push(upload.clone());
            queue.save(&self.uploads)?;
        }
        store.items.remove(at);
        store.save(&self.pending)?;
        Ok(upload)
    }

    /// Discard: the server session (if captions opened one) is queued for deletion, the entry goes and the
    /// recording folder is deleted. Nothing is uploaded and no transcript is requested.
    pub fn discard(&self, id: &str) -> AppResult<PendingDecision> {
        let mut store = self.load();
        let at = store.items.iter().position(|d| d.id == id).ok_or_else(|| AppError::msg(NOT_WAITING))?;
        let decision = store.items.remove(at);
        if let Some(session) = decision.server_session_id.clone() {
            let mut discards = DiscardQueue::load(&self.discards);
            discards.push(session);
            discards.save(&self.discards)?;
        }
        store.save(&self.pending)?;
        if !decision.dir.as_os_str().is_empty() {
            let _ = std::fs::remove_dir_all(&decision.dir);
        }
        Ok(decision)
    }

    /// Changes one entry in place.
    pub fn update(&self, id: &str, change: impl FnOnce(&mut PendingDecision)) -> AppResult<PendingDecision> {
        let mut store = self.load();
        let decision = store.items.iter_mut().find(|d| d.id == id).ok_or_else(|| AppError::msg(NOT_WAITING))?;
        change(decision);
        let updated = decision.clone();
        store.save(&self.pending)?;
        Ok(updated)
    }

    /// Rename: trimmed, 1-200 characters, empty clears the title.
    pub fn rename(&self, id: &str, title: &str) -> AppResult<PendingDecision> {
        let title = normalize_title(title)?;
        self.update(id, |d| d.title = title)
    }

    /// The language chip on the review card or in the waiting list: the final transcript uses it at Keep.
    pub fn set_language(&self, id: &str, language: Option<String>) -> AppResult<PendingDecision> {
        self.update(id, |d| d.language = language)
    }

    pub fn assign_client(&self, id: &str, client_id: Option<String>, client_name: Option<String>) -> AppResult<PendingDecision> {
        self.update(id, |d| {
            d.client_name = client_name.filter(|_| client_id.is_some());
            d.client_id = client_id;
        })
    }

    /// A client deleted on the server is cleared from every waiting recording; true when something changed.
    pub fn forget_client(&self, client_id: &str) -> AppResult<bool> {
        let mut store = self.load();
        let mut changed = false;
        for d in store.items.iter_mut().filter(|d| d.client_id.as_deref() == Some(client_id)) {
            d.client_id = None;
            d.client_name = None;
            changed = true;
        }
        if changed {
            store.save(&self.pending)?;
        }
        Ok(changed)
    }

    /// Links a server session that was opened after its recording stopped; false when no entry has that id.
    pub fn set_server_session(&self, id: &str, session_id: &str) -> AppResult<bool> {
        match self.update(id, |d| d.server_session_id = Some(session_id.to_string())) {
            Ok(_) => Ok(true),
            Err(AppError::Message(m)) if m == NOT_WAITING => Ok(false),
            Err(e) => Err(e),
        }
    }

    /// Applies `policy` to every entry whose deadline has passed at `now` (the policy in force now, A2.5) and
    /// returns what was done to which id. "Keep waiting" does nothing.
    pub fn apply_timeouts(&self, now: DateTime<Utc>, policy: DecisionTimeout) -> AppResult<Vec<(String, Decision)>> {
        let Some(action) = timeout_action(policy) else { return Ok(Vec::new()) };
        let due: Vec<String> = self.load().items.iter().filter(|d| deadline(d.created_at, policy).is_some_and(|at| at <= now)).map(|d| d.id.clone()).collect();
        let mut done = Vec::new();
        for id in due {
            match action {
                Decision::Keep => self.keep(&id).map(|_| ())?,
                Decision::Discard => self.discard(&id).map(|_| ())?,
            }
            done.push((id, action));
        }
        Ok(done)
    }
}

/// Where a server session opened for live captions belongs. Opening happens while the recording runs, but the
/// answer can arrive after the user pressed Stop; such a session is handed to the stop flow, or (once the
/// recording was decided) to its outcome, so it is finished or deleted and never left open.
#[derive(Debug, Default)]
pub struct SessionLink {
    recording: Option<String>,
    session: Option<String>,
    stopping: Option<String>,
    late: HashMap<String, String>,
}

/// Where `SessionLink::attach` put a session.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Attach {
    /// The recording still runs: the session is its open session.
    Current,
    /// The recording is being stopped: `end_stop` hands the session over.
    Deferred,
    /// The recording was already decided: the caller links the session to the outcome (or deletes it).
    Orphan,
}

impl SessionLink {
    /// A new recording starts; it has no session yet.
    pub fn start(&mut self, client_ref: &str) {
        self.recording = Some(client_ref.to_string());
        self.session = None;
    }

    /// The open session of the running recording `client_ref`.
    pub fn session_for(&self, client_ref: &str) -> Option<String> {
        self.session.clone().filter(|_| self.recording.as_deref() == Some(client_ref))
    }

    pub fn attach(&mut self, client_ref: &str, session_id: &str) -> Attach {
        if self.recording.as_deref() == Some(client_ref) {
            self.session = Some(session_id.to_string());
            Attach::Current
        } else if self.stopping.as_deref() == Some(client_ref) {
            self.late.insert(client_ref.to_string(), session_id.to_string());
            Attach::Deferred
        } else {
            Attach::Orphan
        }
    }

    /// Stop begins: returns the recording's session; a session that arrives from now on is deferred.
    pub fn begin_stop(&mut self) -> Option<String> {
        self.stopping = self.recording.take();
        self.session.take()
    }

    /// Stop decided the outcome of `client_ref`: returns a session that arrived meanwhile.
    pub fn end_stop(&mut self, client_ref: &str) -> Option<String> {
        if self.stopping.as_deref() == Some(client_ref) {
            self.stopping = None;
        }
        self.late.remove(client_ref)
    }
}
