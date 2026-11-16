//! Live sessions by id, and what each session has spent.
//!
//! One connection per session: a second connection for the same session takes over (the first is closed with 4409),
//! and a reconnect within the grace period resumes the session. The ledger (spend, connected time, and both when the
//! current token arrived) outlives the session for a day, so reconnecting with the same token never resets the count.

use std::collections::{BTreeMap, HashMap};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex, MutexGuard};
use std::time::{Duration, Instant};

use tokio::sync::mpsc;

use crate::auth::{AuthError, Claims};
use crate::session::{Connection, Control, Engine, Inbound, Outbound, SessionDeps};

struct Entry {
    user: String,
    control: mpsc::UnboundedSender<Control>,
    generation: u64,
}

/// A token as the ledger tells them apart: its issue time and `jti`.
pub type TokenKey = (i64, Option<String>);

/// Tokens remembered per session; a refresh every 13 minutes makes about 5 an hour.
const KEPT_TOKENS: usize = 64;

/// A session's spend and connected time, and both at the moment each of its tokens first arrived.
///
/// A token's `cents_left` and `seconds_left` count down from its first arrival, so a token seen before continues where
/// it was: going back and forth between two tokens never restores budget. A token issued before the newest one the
/// session has used is refused.
#[derive(Debug, Clone, Default)]
pub struct Ledger {
    pub spent: u64,
    pub connected: Duration,
    pub token_key: TokenKey,
    pub spend_base: u64,
    pub time_base: Duration,
    /// The newest `iat` this session has accepted.
    pub newest_iat: i64,
    /// Spend and connected time when each token first arrived.
    pub bases: BTreeMap<TokenKey, (u64, Duration)>,
}

impl Ledger {
    /// Whether a token with this `iat` may still be used: not older than the newest one accepted.
    pub fn accepts(&self, iat: i64) -> bool {
        iat >= self.newest_iat
    }

    /// Makes `key` the current token, counting from its first arrival (now, at `spent` and `connected`, if new).
    pub fn switch_to(&mut self, key: TokenKey, spent: u64, connected: Duration) {
        self.newest_iat = self.newest_iat.max(key.0);
        let (spend_base, time_base) = *self.bases.entry(key.clone()).or_insert((spent, connected));
        self.token_key = key;
        self.spend_base = spend_base;
        self.time_base = time_base;
        // Forget the oldest tokens: they can no longer be used (older than the newest).
        while self.bases.len() > KEPT_TOKENS {
            let Some(oldest) = self.bases.keys().next().cloned() else { break };
            self.bases.remove(&oldest);
        }
    }
}

struct Kept {
    ledger: Ledger,
    updated: Instant,
}

#[derive(Debug)]
pub struct Registry {
    deps: SessionDeps,
    sessions: Mutex<HashMap<String, Entry>>,
    ledgers: Mutex<HashMap<String, Kept>>,
    generations: AtomicU64,
    connections: AtomicU64,
}

impl std::fmt::Debug for Entry {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Entry").field("generation", &self.generation).finish()
    }
}

impl std::fmt::Debug for Kept {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Kept").field("spent", &self.ledger.spent).finish()
    }
}

fn lock<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    // A panic while holding the lock leaves plain data behind; carry on with it.
    mutex.lock().unwrap_or_else(std::sync::PoisonError::into_inner)
}

impl Registry {
    pub fn new(deps: SessionDeps) -> Arc<Self> {
        Arc::new(Registry {
            deps,
            sessions: Mutex::new(HashMap::new()),
            ledgers: Mutex::new(HashMap::new()),
            generations: AtomicU64::new(1),
            connections: AtomicU64::new(1),
        })
    }

    pub fn deps(&self) -> &SessionDeps {
        &self.deps
    }

    /// Hands a verified connection to its session, starting the session when it is not running.
    pub fn connect(
        self: &Arc<Self>,
        claims: Claims,
        out: mpsc::Sender<Outbound>,
        inbound: mpsc::Receiver<Inbound>,
    ) -> Result<u64, AuthError> {
        let id = self.connections.fetch_add(1, Ordering::Relaxed);
        let session_id = claims.sid.clone();
        let mut connection = Box::new(Connection { id, claims, out, inbound });
        let mut sessions = lock(&self.sessions);
        if let Some(entry) = sessions.get(&session_id) {
            if entry.user != connection.claims.sub {
                return Err(AuthError::WrongSession);
            }
            match entry.control.send(Control::Attach(connection)) {
                Ok(()) => return Ok(id),
                // The session task has just ended: start a new one.
                Err(mpsc::error::SendError(Control::Attach(returned))) => connection = returned,
                Err(_) => return Err(AuthError::WrongSession),
            }
        }
        let generation = self.generations.fetch_add(1, Ordering::Relaxed);
        let (control, control_rx) = mpsc::unbounded_channel();
        let ledger = self.ledger(&session_id);
        let user = connection.claims.sub.clone();
        let engine = Engine::new(self.clone(), generation, connection.claims.clone(), ledger);
        let _ = control.send(Control::Attach(connection));
        sessions.insert(session_id, Entry { user, control, generation });
        drop(sessions);
        tokio::spawn(engine.run(control_rx));
        Ok(id)
    }

    /// The user a running session belongs to.
    pub fn owner(&self, session_id: &str) -> Option<String> {
        lock(&self.sessions).get(session_id).map(|entry| entry.user.clone())
    }

    pub fn len(&self) -> usize {
        lock(&self.sessions).len()
    }

    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }

    /// Asks every session to close its connection (1012) and end.
    pub fn shutdown(&self) {
        for entry in lock(&self.sessions).values() {
            let _ = entry.control.send(Control::Shutdown);
        }
    }

    /// A session's grace period ran out. Under the lock: a connection that arrived meanwhile is handed back, so the
    /// session carries on; otherwise the session leaves the map and ends.
    pub(crate) fn finish(
        &self,
        session_id: &str,
        generation: u64,
        control: &mut mpsc::UnboundedReceiver<Control>,
    ) -> Option<Control> {
        let mut sessions = lock(&self.sessions);
        if let Ok(waiting) = control.try_recv() {
            return Some(waiting);
        }
        if sessions.get(session_id).is_some_and(|entry| entry.generation == generation) {
            sessions.remove(session_id);
        }
        None
    }

    pub(crate) fn remove(&self, session_id: &str, generation: u64) {
        let mut sessions = lock(&self.sessions);
        if sessions.get(session_id).is_some_and(|entry| entry.generation == generation) {
            sessions.remove(session_id);
        }
    }

    /// Whether a session would take a token issued at `iat` (an older one than it uses is refused).
    pub fn accepts(&self, session_id: &str, iat: i64) -> bool {
        lock(&self.ledgers).get(session_id).is_none_or(|kept| kept.ledger.accepts(iat))
    }

    /// What a session has spent so far, in micro-dollars.
    pub fn spent(&self, session_id: &str) -> u64 {
        lock(&self.ledgers).get(session_id).map_or(0, |kept| kept.ledger.spent)
    }

    fn ledger(&self, session_id: &str) -> Ledger {
        lock(&self.ledgers).get(session_id).map(|kept| kept.ledger.clone()).unwrap_or_default()
    }

    pub(crate) fn save_ledger(&self, session_id: &str, ledger: Ledger) {
        lock(&self.ledgers).insert(session_id.to_owned(), Kept { ledger, updated: Instant::now() });
    }

    /// Forgets the ledgers of sessions idle for longer than `older_than` (tokens live 15 minutes, sessions hours).
    pub fn sweep(&self, older_than: Duration) {
        lock(&self.ledgers).retain(|_, kept| kept.updated.elapsed() < older_than);
    }
}
