//! Keep / Discard after a recording ends (A2.1-A2.6, v1.5 "After a call"). A finished recording that is not kept at
//! once stays on this computer as a `PendingDecision` in `pending.json` (atomic writes, tolerant loading, restored at
//! start) and is not uploaded. With "Ask me" the after-call chip shows it, and at `keep_at`, 12 s later, it saves
//! itself as audio only (v1.8 P1); one without `keep_at` (the chip was never shown, it was opened in the main window, or it is left from before v1.5)
//! waits in the Library's waiting list. Keep moves it to the upload queue; Discard deletes its files, uploads nothing,
//! requests no transcript and queues its server session (if captions opened one) for deletion. The functions here
//! touch only files, so the whole flow is unit-tested; `actions` adds locking, events and the after-call window.

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
use crate::settings::AfterCall;

/// Every `pending_*` command answers this when the id is not (or no longer) waiting.
pub const NOT_WAITING: &str = "This recording is no longer waiting.";
pub const TITLE_TOO_LONG: &str = "Use 200 characters or fewer.";
/// "Ask me": with no answer this long after the call ended, the card saves the recording as audio only, not
/// transcribed (contract 1.8.0-e; the call choice made it 8 s, it was 12 s).
pub const CHIP_SECONDS: i64 = crate::after_call::CARD_SECONDS;
const MAX_TITLE_CHARS: usize = 200;
const MAX_FIRST_LINE_CHARS: usize = 140;
/// The title of a recording recovered after a restart (since v1.3).
pub const RECOVERED_TITLE: &str = "Recovered recording";

/// A discarded recording's files on this computer (the pill's Discard, a declined Live Assist start): its folder and
/// its screens. It is never added to the waiting list or the upload queue.
pub fn discard_recording_files(data_dir: &Path, dir: &Path, client_ref: &str) {
    crate::recovery::delete_recording_folder(dir);
    crate::screens::store::ScreenStore::in_dir(data_dir).forget(client_ref);
}

/// What "After a call" means for a finished recording: calls (automatic or by hand) follow the setting; voice notes
/// were stopped on purpose and are always kept and transcribed, with the same Undo.
pub fn after_call(setting: AfterCall, mode: RecordingMode) -> AfterCall {
    if mode == RecordingMode::Call { setting } else { AfterCall::AlwaysTranscribe }
}

/// The waiting list's preview: the first final caption turn as `Me: …` / `Client: …`, at most 140 characters.
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

/// A recording waiting for Keep / Discard, as the UI sees it (section 4.3). Times are RFC 3339 UTC; `deadlineAt` is
/// when the after-call chip keeps it by itself, `null` while it waits for the person.
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
    /// Who the call was with (a meeting window's title), for "Call with <name>" while it has no title.
    pub caller_name: Option<String>,
    /// Screens kept for this recording on this computer (the after-call card's "3 screens"); they upload with it.
    pub screens: usize,
}

/// The name of who the running recording `client_ref` is with, from the caller noted for it (none for a caller noted
/// for another recording, or a blank name).
pub fn running_caller_name(noted: Option<&(String, CallerDto)>, client_ref: &str) -> Option<String> {
    noted.filter(|(r, _)| r == client_ref).and_then(|(_, c)| c.name.as_deref()).map(str::trim).filter(|n| !n.is_empty()).map(str::to_string)
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
    /// When the after-call chip keeps it by itself ("Ask me", v1.5); none = it waits for Keep or Discard.
    #[serde(default)]
    keep_at: Option<DateTime<Utc>>,
    /// The chip promised to save the audio, not transcribed, at `keep_at` (since v1.8 P1). An entry written by an
    /// older version has no such mark: its chip promised Keep, so it is kept and transcribed when due.
    #[serde(default)]
    saves_audio_when_due: bool,
    /// The account it was made under, once another account signed in here (since v1.5); none = the account this
    /// computer signs in as. Keep passes it on to the upload.
    #[serde(default)]
    pub owner: Option<crate::owner::Owner>,
    /// Recovered after a restart (since v1.3): it keeps a "Recovered" mark once its transcript renames it (`recovered`).
    #[serde(default)]
    recovered: bool,
    /// Discarded from the after-call chip: its files go only at this time, so its Undo can still bring it back
    /// (`discard_later`, `undo_discard`, `discard_due`); none otherwise.
    #[serde(default)]
    discard_at: Option<DateTime<Utc>>,
    /// The call choice made (the card, the pill, the setting): applied at `keep_at`, once its confirmation's Undo time
    /// is over (`choose`); none while the card asks or the recording waits.
    #[serde(default)]
    chosen: Option<crate::after_call::CallChoice>,
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
            keep_at: None,
            saves_audio_when_due: false,
            owner: None,
            recovered: false,
            discard_at: None,
            chosen: None,
        }
    }

    /// The same decision, kept by itself at `at` unless the person answers the chip first.
    pub fn keeping_at(mut self, at: DateTime<Utc>) -> Self {
        self.keep_at = Some(at);
        self.saves_audio_when_due = true;
        self
    }

    /// The same decision with its choice made: transcribed or saved as audio at `at`, deleted at `at` (Delete), unless
    /// Undo comes first.
    pub fn chosen_at(mut self, choice: crate::after_call::CallChoice, at: DateTime<Utc>) -> Self {
        apply_choice(&mut self, choice, at);
        self
    }

    /// The call choice made for it, waiting for its Undo time to be over.
    pub fn chosen(&self) -> Option<crate::after_call::CallChoice> {
        self.chosen
    }

    /// When it keeps itself; none while it waits for the person.
    pub fn keep_at(&self) -> Option<DateTime<Utc>> {
        self.keep_at
    }

    /// Waits for Keep or Discard (no chip counts down for it, and it is not on its way out).
    pub fn waits(&self) -> bool {
        self.keep_at.is_none() && self.discard_at.is_none()
    }

    /// Its chip counts down (it keeps itself at `keep_at`).
    pub fn counting(&self) -> bool {
        self.keep_at.is_some()
    }

    /// Discarded from the chip, its files kept until `discard_at` for Undo: no list or count shows it.
    pub fn discarding(&self) -> bool {
        self.discard_at.is_some()
    }

    /// The caller's name from the meeting app, when there was one.
    pub fn caller_name(&self) -> Option<&str> {
        self.caller.as_ref().and_then(|c| c.name.as_deref()).map(str::trim).filter(|n| !n.is_empty())
    }

    /// Takes the open server session captions created (a skipped recording deletes it at once).
    pub fn take_server_session(&mut self) -> Option<String> {
        self.server_session_id.take()
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
            keep_at: None,
            saves_audio_when_due: false,
            owner: None,
            recovered: true,
            discard_at: None,
            chosen: None,
        }
    }

    /// Recovered after a restart; an entry saved before the mark existed is known by its title.
    pub fn is_recovered(&self) -> bool {
        self.recovered || self.title.as_deref() == Some(RECOVERED_TITLE)
    }

    pub fn item(&self) -> PendingItem {
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
            deadline_at: self.keep_at.map(|d| d.to_rfc3339()),
            language: self.language.clone(),
            caller_name: self.caller_name().map(str::to_string),
            screens: 0,
        }
    }

    /// [`Self::item`] with the number of screens `screens` holds for this recording.
    pub fn item_with_screens(&self, screens: &crate::screens::store::ScreenStore) -> PendingItem {
        PendingItem { screens: screens.count(&self.client_ref), ..self.item() }
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
            owner: self.owner.clone(),
            recovered: self.is_recovered(),
            ..PendingUpload::default()
        }
    }
}

/// Marks `choice` on a decision, to be applied at `at`: Delete is a discard whose files wait for its Undo time; the others
/// keep it then, transcribed or as audio only.
fn apply_choice(d: &mut PendingDecision, choice: crate::after_call::CallChoice, at: DateTime<Utc>) {
    use crate::after_call::CallChoice;
    d.chosen = Some(choice);
    if choice == CallChoice::Delete {
        d.keep_at = None;
        d.discard_at = Some(at);
    } else {
        d.keep_at = Some(at);
        d.saves_audio_when_due = choice == CallChoice::Audio;
        d.discard_at = None;
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

    /// The UI list, newest first (a recording on its way out is not on it).
    pub fn ui_items(&self) -> Vec<PendingItem> {
        self.listed().into_iter().map(PendingDecision::item).collect()
    }

    /// [`Self::ui_items`] with each recording's screens counted in `screens`.
    pub fn ui_items_with_screens(&self, screens: &crate::screens::store::ScreenStore) -> Vec<PendingItem> {
        self.listed().into_iter().map(|d| d.item_with_screens(screens)).collect()
    }

    fn listed(&self) -> Vec<&PendingDecision> {
        let mut items: Vec<&PendingDecision> = self.items.iter().filter(|d| !d.discarding()).collect();
        items.sort_by_key(|d| std::cmp::Reverse(d.created_at));
        items
    }

    /// Recordings that wait for the person (the tray, the sidebar and the Library's waiting line count these).
    pub fn waiting(&self) -> usize {
        self.items.iter().filter(|d| d.waits()).count()
    }

    /// The earliest moment a chip keeps its recording.
    pub fn next_keep_at(&self) -> Option<DateTime<Utc>> {
        self.items.iter().filter_map(|d| d.keep_at).min()
    }

    /// When the chip timer wakes next, after keeping what was due at `now`: at the earliest chip's time; a time that
    /// has already passed (its recording could not be kept just now) is tried again in a minute, not straight away.
    pub fn next_wake(&self, now: DateTime<Utc>) -> Option<DateTime<Utc>> {
        self.next_keep_at().map(|at| if at <= now { now + chrono::Duration::minutes(1) } else { at })
    }

    /// When the next discarded recording's Undo time is over (the same rule: one already past is tried again later).
    pub fn next_discard(&self, now: DateTime<Utc>) -> Option<DateTime<Utc>> {
        self.items.iter().filter_map(|d| d.discard_at).min().map(|at| if at <= now { now + chrono::Duration::minutes(1) } else { at })
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
        self.keep_as(id, true)
    }

    /// "Save audio" (contract 1.8.0-e): kept and uploaded as audio only, not transcribed; it lands in "Not transcribed".
    pub fn keep_audio(&self, id: &str) -> AppResult<PendingUpload> {
        self.keep_as(id, false)
    }

    fn keep_as(&self, id: &str, transcribe: bool) -> AppResult<PendingUpload> {
        let mut store = self.load();
        let at = store.items.iter().position(|d| d.id == id).ok_or_else(|| AppError::msg(NOT_WAITING))?;
        let upload = PendingUpload { transcribe, ..store.items[at].to_upload() };
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
        // The server session queued for deletion by this discard (none when it was queued already).
        let mut queued = None;
        if let Some(session) = decision.server_session_id.clone() {
            let mut discards = DiscardQueue::load(&self.discards);
            if !discards.items.contains(&session) {
                queued = Some(session.clone());
            }
            discards.push(session);
            discards.save(&self.discards)?;
        }
        // The recording stays when the discard can't finish: its server session is taken off the queue again (best
        // effort), like its folder's mark.
        let undo_queue = |queued: &Option<String>| {
            if let Some(session) = queued {
                let mut discards = DiscardQueue::load(&self.discards);
                discards.items.retain(|s| s != session);
                let _ = discards.save(&self.discards);
            }
        };
        // Marked before its entry goes: a crash in between deletes the folder at the next start, never offers it back
        // as "Recovered recording".
        if let Err(e) = crate::recovery::mark_leaving(&decision.dir) {
            undo_queue(&queued);
            return Err(e.into());
        }
        if let Err(e) = store.save(&self.pending) {
            crate::recovery::unmark_leaving(&decision.dir);
            undo_queue(&queued);
            return Err(e);
        }
        crate::recovery::delete_recording_folder(&decision.dir);
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

    /// The language chip in the waiting list: the final transcript uses it at Keep.
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

    /// Saves as audio only every recording whose chip time is over at `now` (also one whose time ran out while the app
    /// was closed) and returns their ids: no answer is "Save audio", never a transcript (v1.8 P1). A chip shown by a
    /// version before that promised Keep, so its recording is kept and transcribed. Recordings that wait for the
    /// person are left alone.
    pub fn keep_due(&self, now: DateTime<Utc>) -> AppResult<Vec<String>> {
        let due: Vec<(String, bool)> = self.load().items.iter().filter(|d| d.keep_at.is_some_and(|at| at <= now)).map(|d| (d.id.clone(), !d.saves_audio_when_due)).collect();
        for (id, transcribe) in &due {
            self.keep_as(id, *transcribe)?;
        }
        Ok(due.into_iter().map(|(id, _)| id).collect())
    }

    /// The call choice for `id` (the card's answer, Transcribe on "Saved as audio"): applied at `at`, when its Undo time
    /// is over; until then nothing is uploaded or deleted. An error when it is no longer here (its time was over).
    pub fn choose(&self, id: &str, choice: crate::after_call::CallChoice, at: DateTime<Utc>) -> AppResult<PendingDecision> {
        self.update(id, |d| apply_choice(d, choice, at))
    }

    /// Undo on the waiting list's Delete: the recording waits in the list again, as before. An error when its files
    /// already went.
    pub fn restore_waiting(&self, id: &str) -> AppResult<PendingDecision> {
        if !self.load().find(id).is_some_and(PendingDecision::discarding) {
            return Err(AppError::msg(NOT_WAITING));
        }
        self.update(id, |d| {
            d.discard_at = None;
            d.chosen = None;
            d.keep_at = None;
        })
    }

    /// Undo on a confirmation: no choice now, the card asks again until `keep_at` (then saves the audio only). An error
    /// when the choice was already applied.
    pub fn ask_again(&self, id: &str, keep_at: DateTime<Utc>) -> AppResult<PendingDecision> {
        self.update(id, |d| {
            d.chosen = None;
            d.discard_at = None;
            d.keep_at = Some(keep_at);
            d.saves_audio_when_due = true;
        })
    }

    /// The card of `id` ran out of time with no choice while on screen: it is saved as audio only at `at`, after the
    /// "Saved as audio · Transcribe · Undo" confirmation. False when it is not a card whose time is over (answered,
    /// gone, or still counting).
    pub fn time_out_card(&self, id: &str, now: DateTime<Utc>, at: DateTime<Utc>) -> AppResult<bool> {
        let due = self.load().find(id).is_some_and(|d| d.chosen.is_none() && d.saves_audio_when_due && d.keep_at.is_some_and(|k| k <= now));
        if due {
            self.choose(id, crate::after_call::CallChoice::Audio, at)?;
        }
        Ok(due)
    }

    /// Discard from the after-call chip: the recording leaves every list and count now, its chip stops counting, and
    /// its files stay until `at` so Undo can bring it back (`undo_discard`); `discard_due` finishes it then.
    pub fn discard_later(&self, id: &str, at: DateTime<Utc>) -> AppResult<PendingDecision> {
        self.update(id, |d| apply_choice(d, crate::after_call::CallChoice::Delete, at))
    }

    /// Undo after a chip's Discard: the recording is back as it was, its chip counting again until `keep_at`. An error
    /// when it is no longer on its way out (its time was over, or it was already brought back).
    pub fn undo_discard(&self, id: &str, keep_at: DateTime<Utc>) -> AppResult<PendingDecision> {
        if !self.load().find(id).is_some_and(PendingDecision::discarding) {
            return Err(AppError::msg(NOT_WAITING));
        }
        self.ask_again(id, keep_at)
    }

    /// Discards every recording whose Undo time is over at `now` (files, server session), one by one: the ones that went
    /// are returned (their follow-ups must run), the ones that could not go are returned apart and stay for the next
    /// try. One whose time is not over yet is left alone, also across a restart.
    pub fn discard_due(&self, now: DateTime<Utc>) -> (Vec<PendingDecision>, Vec<(String, AppError)>) {
        let due: Vec<String> = self.load().items.iter().filter(|d| d.discard_at.is_some_and(|at| at <= now)).map(|d| d.id.clone()).collect();
        let (mut done, mut failed) = (Vec::new(), Vec::new());
        for id in due {
            match self.discard(&id) {
                Ok(decision) => done.push(decision),
                Err(e) => failed.push((id, e)),
            }
        }
        (done, failed)
    }

    /// The person opened the recording in the main window: no chip keeps it now, it waits for Keep or Discard.
    pub fn stop_countdown(&self, id: &str) -> AppResult<PendingDecision> {
        self.update(id, |d| {
            d.keep_at = None;
            d.chosen = None;
        })
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
