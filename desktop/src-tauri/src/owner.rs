//! Whose queued work this is (v1.5, docs/API.md "Workspaces, roles and privacy": a recording belongs to the person who
//! made it). `account.json` names the account this computer signs in as and is kept after signing out, until the next
//! sign-in. Everything queued while that account is the one here (recordings waiting for Keep, skipped calls, uploads,
//! screens) carries no owner of its own; a sign-in as someone else first stamps it with the account it was made under, so it
//! never uploads into another person's library or to another server, and waits for that account instead. The usage
//! reports and server deletions still to send are set aside for their account the same way (`held.json`) and come
//! back when it signs in again.

use std::path::Path;
use std::sync::atomic::Ordering;

use serde::{Deserialize, Serialize};

use crate::error::AppResult;
use crate::net::api::ApiClient;
use crate::net::models::UsageReport;
use crate::net::sync::paths;
use crate::net::uploader::{DiscardQueue, UploadQueue, UsageQueue};
use crate::pending::PendingStore;
use crate::screens::store::ScreenStore;
use crate::skipped::SkippedStore;

/// An account on a server. A server without accounts (older than v1.5) has no email: the server is all that is known.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct Owner {
    /// The server's base URL, without a trailing slash.
    pub server: String,
    /// The account's email in lower case.
    #[serde(default)]
    pub email: Option<String>,
}

impl Owner {
    pub fn new(server: &str, email: Option<&str>) -> Self {
        let email = email.map(|e| e.trim().to_lowercase()).filter(|e| !e.is_empty());
        Self { server: server.trim().trim_end_matches('/').to_string(), email }
    }

    /// Whether what was made under this account may upload while `current` is signed in: the same server, and the
    /// same account when this one's is known. A known account never uploads while the signed-in one is unknown.
    pub fn may_upload_as(&self, current: &Owner) -> bool {
        self.server.eq_ignore_ascii_case(&current.server)
            && match (&self.email, &current.email) {
                (None, _) => true,
                (Some(mine), Some(theirs)) => mine == theirs,
                (Some(_), None) => false,
            }
    }

    /// The account as the app names it: the email, else the server's address.
    pub fn label(&self) -> String {
        self.email.clone().unwrap_or_else(|| self.server.trim_start_matches("https://").trim_start_matches("http://").to_string())
    }

    /// The account in `account.json`; none before the first sign-in (or from an install older than v1.5).
    pub fn load(data_dir: &Path) -> Option<Self> {
        let bytes = std::fs::read(paths::account(data_dir)).ok()?;
        serde_json::from_slice::<Self>(&bytes).ok().filter(|o| !o.server.is_empty())
    }

    pub fn save(&self, data_dir: &Path) -> AppResult<()> {
        let path = paths::account(data_dir);
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)?;
        std::fs::rename(tmp, path)?;
        Ok(())
    }
}

/// Whose work without an owner is now: the account in `account.json`, else the server this computer was signed in to
/// (an install from before v1.5 that has not read its account yet).
pub fn current(data_dir: &Path, server_url: Option<&str>) -> Option<Owner> {
    Owner::load(data_dir).or_else(|| server_url.filter(|s| !s.trim().is_empty()).map(|s| Owner::new(s, None)))
}

/// Whether a queued item (its `owner`, none = the current account's) may upload while `current` is signed in.
pub fn may_upload(owner: Option<&Owner>, current: Option<&Owner>) -> bool {
    owner.is_none_or(|o| current.is_some_and(|c| o.may_upload_as(c)))
}

/// The account a queued item waits for, when it is not the one signed in now.
pub fn held_for(owner: Option<&Owner>, current: Option<&Owner>) -> Option<String> {
    if may_upload(owner, current) { None } else { owner.map(Owner::label) }
}

/// `held.json`: what each other account still has to send.
#[derive(Default, Serialize, Deserialize)]
struct Held {
    #[serde(default)]
    accounts: Vec<HeldWork>,
}

#[derive(Serialize, Deserialize)]
struct HeldWork {
    owner: Owner,
    #[serde(default)]
    usage: Vec<UsageReport>,
    #[serde(default)]
    discards: Vec<String>,
}

impl Held {
    /// The work set aside for `owner`, made empty when there is none yet.
    fn bucket(&mut self, owner: &Owner) -> &mut HeldWork {
        let at = match self.accounts.iter().position(|w| w.owner == *owner) {
            Some(at) => at,
            None => {
                self.accounts.push(HeldWork { owner: owner.clone(), usage: Vec::new(), discards: Vec::new() });
                self.accounts.len() - 1
            }
        };
        &mut self.accounts[at]
    }

    fn load(data_dir: &Path) -> Self {
        std::fs::read(paths::held(data_dir)).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    fn save(&self, data_dir: &Path) -> AppResult<()> {
        let path = paths::held(data_dir);
        if self.accounts.is_empty() {
            return match std::fs::remove_file(&path) {
                Err(e) if e.kind() != std::io::ErrorKind::NotFound => Err(e.into()),
                _ => Ok(()),
            };
        }
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)?;
        std::fs::rename(tmp, path)?;
        Ok(())
    }
}

/// Stamps every item without an owner as `owner`'s; true when one was.
fn stamp<'a>(owners: impl Iterator<Item = &'a mut Option<Owner>>, owner: &Owner) -> bool {
    let mut changed = false;
    for slot in owners.filter(|slot| slot.is_none()) {
        *slot = Some(owner.clone());
        changed = true;
    }
    changed
}

/// This computer is now signed in as `incoming` (the caller holds the queue lock). When that is someone else than
/// `outgoing`, the account here until now, everything without an owner is stamped as `outgoing`'s and its usage
/// reports and server deletions are set aside. Then `incoming`'s own set-aside work comes back, and `incoming` is
/// remembered. True when the account changed.
pub fn hand_over(data_dir: &Path, outgoing: Option<&Owner>, incoming: &Owner) -> AppResult<bool> {
    let usage_path = paths::usage(data_dir);
    let discards_path = paths::discards(data_dir);
    let mut usage = UsageQueue::load(&usage_path);
    let mut discards = DiscardQueue::load(&discards_path);
    let mut held = Held::load(data_dir);
    let someone_else = outgoing.filter(|o| !o.may_upload_as(incoming));
    if let Some(outgoing) = someone_else {
        let uploads_path = paths::uploads(data_dir);
        let mut uploads = UploadQueue::load(&uploads_path);
        if stamp(uploads.items.iter_mut().map(|i| &mut i.owner), outgoing) {
            uploads.save(&uploads_path)?;
        }
        let pending_path = paths::pending(data_dir);
        let mut pending = PendingStore::load(&pending_path);
        if stamp(pending.items.iter_mut().map(|d| &mut d.owner), outgoing) {
            pending.save(&pending_path)?;
        }
        let skipped_path = paths::skipped(data_dir);
        let mut skipped = SkippedStore::load(&skipped_path);
        if stamp(skipped.items.iter_mut().map(|c| &mut c.decision.owner), outgoing) {
            skipped.save(&skipped_path)?;
        }
        let screens = ScreenStore::in_dir(data_dir);
        screens.stamp(outgoing)?;
        screens.welcome_back(incoming, chrono::Utc::now())?;
        if !usage.items.is_empty() || !discards.items.is_empty() {
            let work = held.bucket(outgoing);
            work.usage.append(&mut usage.items);
            for id in discards.items.drain(..) {
                if !work.discards.contains(&id) {
                    work.discards.push(id);
                }
            }
        }
    }
    let (mine, others): (Vec<HeldWork>, Vec<HeldWork>) = std::mem::take(&mut held.accounts).into_iter().partition(|w| w.owner.may_upload_as(incoming));
    held.accounts = others;
    for work in mine {
        work.usage.into_iter().for_each(|r| usage.push(r));
        work.discards.into_iter().for_each(|id| discards.push(id));
    }
    usage.save(&usage_path)?;
    discards.save(&discards_path)?;
    held.save(data_dir)?;
    incoming.save(data_dir)?;
    Ok(someone_else.is_some())
}

/// A server session of `owner`'s to delete while another account is signed in here (one of their recordings was
/// discarded): it waits with their other work instead of going with this account's token, which may not delete it.
/// The caller holds the queue lock.
pub fn hold_discard(data_dir: &Path, owner: &Owner, session_id: &str) -> AppResult<()> {
    let discards_path = paths::discards(data_dir);
    let mut discards = DiscardQueue::load(&discards_path);
    if discards.items.iter().any(|id| id == session_id) {
        discards.items.retain(|id| id != session_id);
        discards.save(&discards_path)?;
    }
    let mut held = Held::load(data_dir);
    let work = held.bucket(owner);
    if !session_id.is_empty() && !work.discards.iter().any(|id| id == session_id) {
        work.discards.push(session_id.to_string());
    }
    held.save(data_dir)
}

/// Who this computer is signed in as, read from the server when `account.json` does not say yet: an install from
/// before v1.5, or a sign-in whose account could not be read. Asked again only until the server has answered once
/// this run (an older server answers that it has no accounts).
pub async fn learn(state: &crate::AppState, api: &ApiClient) -> Option<Owner> {
    let server = api.base_url().to_string();
    let known = current(&state.data_dir, Some(&server));
    if known.as_ref().is_some_and(|o| o.email.is_some()) || state.owner_checked.load(Ordering::SeqCst) {
        return known;
    }
    let Ok(account) = api.account().await else { return known };
    state.owner_checked.store(true, Ordering::SeqCst);
    let learned = Owner::new(&server, account.as_ref().map(|a| a.email.as_str()));
    let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
    let before = current(&state.data_dir, Some(&server));
    if let Err(e) = hand_over(&state.data_dir, before.as_ref(), &learned) {
        eprintln!("[echoledger] remembering the account: {e}");
        return before;
    }
    Some(learned)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::net::uploader::PendingUpload;

    const SERVER: &str = "https://ledger.example.test";

    fn upload(dir: &Path, name: &str, owner: Option<Owner>) -> PendingUpload {
        PendingUpload { client_ref: name.into(), dir: dir.join(name), primary: dir.join(name).join("recording.flac"), owner, ..PendingUpload::default() }
    }

    fn report(id: &str) -> UsageReport {
        UsageReport { report_id: id.into(), use_case: "live_captions".into(), provider: "assemblyai".into(), streamed_ms: 60_000, channels: 2, ..UsageReport::default() }
    }

    #[test]
    fn a_recording_uploads_only_for_the_account_and_server_it_was_made_under() {
        let a = Owner::new("https://Ledger.example.test/", Some(" Hassam@Example.test "));
        assert_eq!(a, Owner { server: "https://Ledger.example.test".into(), email: Some("hassam@example.test".into()) });
        assert!(a.may_upload_as(&Owner::new(SERVER, Some("hassam@example.test"))), "the host's case and the email's case do not matter");
        assert!(!a.may_upload_as(&Owner::new(SERVER, Some("sara@example.test"))), "another account");
        assert!(!a.may_upload_as(&Owner::new("https://other.example.test", Some("hassam@example.test"))), "another server");
        assert!(!a.may_upload_as(&Owner::new(SERVER, None)), "an account not read yet is not taken for this one");
        // An older server has no accounts: only its address is known.
        let old = Owner::new(SERVER, None);
        assert!(old.may_upload_as(&Owner::new(SERVER, Some("sara@example.test"))));
        assert!(!old.may_upload_as(&Owner::new("https://other.example.test", None)));

        assert!(may_upload(None, None), "an item without an owner is the current account's");
        assert!(!may_upload(Some(&a), None));
        assert_eq!(held_for(Some(&a), Some(&Owner::new(SERVER, Some("sara@example.test")))).as_deref(), Some("hassam@example.test"));
        assert_eq!(held_for(Some(&old), Some(&Owner::new("https://other.example.test", None))).as_deref(), Some("ledger.example.test"));
        assert_eq!(held_for(None, Some(&a)), None);
    }

    #[test]
    fn the_account_is_remembered_and_an_older_install_falls_back_to_its_server() {
        let data = tempfile::tempdir().unwrap();
        assert_eq!(current(data.path(), None), None);
        assert_eq!(current(data.path(), Some(SERVER)), Some(Owner::new(SERVER, None)));
        let a = Owner::new(SERVER, Some("hassam@example.test"));
        a.save(data.path()).unwrap();
        assert_eq!(Owner::load(data.path()), Some(a.clone()));
        assert_eq!(current(data.path(), Some("https://other.example.test")), Some(a), "account.json wins");
    }

    #[test]
    fn signing_in_as_someone_else_keeps_the_queue_for_the_account_that_made_it() {
        let data = tempfile::tempdir().unwrap();
        let dir = data.path();
        let a = Owner::new(SERVER, Some("hassam@example.test"));
        let b = Owner::new(SERVER, Some("sara@example.test"));
        a.save(dir).unwrap();
        let earlier = Owner::new("https://other.example.test", Some("hassam@example.test"));
        UploadQueue { items: vec![upload(dir, "r1", None), upload(dir, "r2", Some(earlier.clone()))] }.save(&paths::uploads(dir)).unwrap();
        let mut usage = UsageQueue::default();
        usage.push(report("u1"));
        usage.save(&paths::usage(dir)).unwrap();
        let mut discards = DiscardQueue::default();
        discards.push("01JS".into());
        discards.save(&paths::discards(dir)).unwrap();

        let screens = ScreenStore::in_dir(dir);
        let key = crate::screens::store::ScreenKey::from_bytes(&[7; 32]);
        screens.add("r9", &key, b"RIFFa", 1000, 640, 360, "change", chrono::Utc::now()).unwrap();
        screens.link("r9", "01JSESSION").unwrap();

        assert!(hand_over(dir, Some(&a), &b).unwrap(), "another account");
        assert!(screens.due(chrono::Utc::now(), Some(&b)).is_empty(), "A's screens of a recording already sent wait for A too");
        let queue = UploadQueue::load(&paths::uploads(dir));
        assert_eq!(queue.items[0].owner.as_ref(), Some(&a), "made while A was signed in");
        assert_eq!(queue.items[1].owner.as_ref(), Some(&earlier), "an owner already stamped stays");
        assert!(queue.items.iter().all(|i| !may_upload(i.owner.as_ref(), Some(&b))), "nothing of A's goes to B");
        assert!(UsageQueue::load(&paths::usage(dir)).items.is_empty(), "A's usage is not reported as B's");
        assert!(DiscardQueue::load(&paths::discards(dir)).items.is_empty());
        assert_eq!(Owner::load(dir), Some(b.clone()));

        // B's own work stays B's; A signs in again and gets back what was set aside.
        let mut usage = UsageQueue::default();
        usage.push(report("u2"));
        usage.save(&paths::usage(dir)).unwrap();
        assert!(hand_over(dir, Some(&b), &a).unwrap());
        assert_eq!(screens.due(chrono::Utc::now(), Some(&a)).len(), 1);
        let usage: Vec<String> = UsageQueue::load(&paths::usage(dir)).items.into_iter().map(|r| r.report_id).collect();
        assert_eq!(usage, ["u1"]);
        assert_eq!(DiscardQueue::load(&paths::discards(dir)).items, ["01JS"]);
        assert!(may_upload(UploadQueue::load(&paths::uploads(dir)).items[0].owner.as_ref(), Some(&a)));
        let held = Held::load(dir);
        assert_eq!(held.accounts.len(), 1);
        assert_eq!(held.accounts[0].owner, b);
        assert_eq!(held.accounts[0].usage[0].report_id, "u2");
    }

    #[test]
    fn signing_in_again_as_the_same_account_changes_nothing() {
        let data = tempfile::tempdir().unwrap();
        let dir = data.path();
        let a = Owner::new(SERVER, Some("hassam@example.test"));
        UploadQueue { items: vec![upload(dir, "r1", None)] }.save(&paths::uploads(dir)).unwrap();
        let mut usage = UsageQueue::default();
        usage.push(report("u1"));
        usage.save(&paths::usage(dir)).unwrap();

        assert!(!hand_over(dir, Some(&a), &Owner::new("https://ledger.example.test/", Some("HASSAM@example.test"))).unwrap());
        assert_eq!(UploadQueue::load(&paths::uploads(dir)).items[0].owner, None);
        assert_eq!(UsageQueue::load(&paths::usage(dir)).items.len(), 1);
        // A first sign-in, and an install from before v1.5 whose account was not known, keep the queue as it is.
        assert!(!hand_over(dir, None, &a).unwrap());
        assert!(!hand_over(dir, Some(&Owner::new(SERVER, None)), &a).unwrap());
        assert_eq!(UploadQueue::load(&paths::uploads(dir)).items[0].owner, None);
        assert!(!paths::held(dir).exists(), "nothing set aside");
    }

    #[test]
    fn a_session_of_another_account_s_discarded_recording_waits_for_that_account() {
        let data = tempfile::tempdir().unwrap();
        let dir = data.path();
        let a = Owner::new(SERVER, Some("hassam@example.test"));
        let b = Owner::new(SERVER, Some("sara@example.test"));
        let mut discards = DiscardQueue::default();
        discards.push("A-OPEN".into());
        discards.push("B-OWN".into());
        discards.save(&paths::discards(dir)).unwrap();

        hold_discard(dir, &a, "A-OPEN").unwrap();
        assert_eq!(DiscardQueue::load(&paths::discards(dir)).items, ["B-OWN"], "not sent with Sara's token");
        b.save(dir).unwrap();
        hand_over(dir, Some(&b), &a).unwrap();
        let back = DiscardQueue::load(&paths::discards(dir)).items;
        assert!(back.contains(&"A-OPEN".to_string()), "deleted once Hassam is back: {back:?}");
        assert!(!back.contains(&"B-OWN".to_string()), "Sara's deletion waits for Sara");
    }

    #[test]
    fn waiting_recordings_and_skipped_calls_are_stamped_too() {
        use crate::pending::PendingDecision;
        use crate::skipped::{SkipReason, SkippedCall};
        let data = tempfile::tempdir().unwrap();
        let dir = data.path();
        let now = chrono::Utc::now();
        let decision = |name: &str| {
            let folder = dir.join(name);
            PendingDecision::recovered(folder.clone(), folder.join("recording.flac"), folder.join("mic.wav"), folder.join("system.wav"), 3_000, now, now, now)
        };
        PendingStore { items: vec![decision("p1")] }.save(&paths::pending(dir)).unwrap();
        SkippedStore { items: vec![SkippedCall { decision: decision("k1"), reason: SkipReason::TooShort, skipped_at: now }] }.save(&paths::skipped(dir)).unwrap();
        let a = Owner::new(SERVER, Some("hassam@example.test"));

        hand_over(dir, Some(&a), &Owner::new("https://other.example.test", None)).unwrap();
        let kept = PendingStore::load(&paths::pending(dir)).items[0].to_upload();
        assert_eq!(kept.owner.as_ref(), Some(&a), "Keep carries the owner into the upload queue");
        assert_eq!(SkippedStore::load(&paths::skipped(dir)).items[0].decision.owner.as_ref(), Some(&a));
    }
}
