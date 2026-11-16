//! Live sessions by id, and what each session has spent.
//!
//! One connection per session: a second connection for the same session takes over (the first is closed with 4409),
//! and a reconnect within the grace period resumes the session. The spend outlives the session for a day, so a new
//! connection never starts the budget from zero.

use std::collections::HashMap;
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

struct Spend {
    micros: u64,
    updated: Instant,
}

#[derive(Debug)]
pub struct Registry {
    deps: SessionDeps,
    sessions: Mutex<HashMap<String, Entry>>,
    spend: Mutex<HashMap<String, Spend>>,
    generations: AtomicU64,
    connections: AtomicU64,
}

impl std::fmt::Debug for Entry {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Entry").field("generation", &self.generation).finish()
    }
}

impl std::fmt::Debug for Spend {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Spend").field("micros", &self.micros).finish()
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
            spend: Mutex::new(HashMap::new()),
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
        let mut connection = Connection { id, claims, out, inbound };
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
        let spent = self.spent(&session_id);
        let user = connection.claims.sub.clone();
        let engine = Engine::new(self.clone(), generation, connection.claims.clone(), spent);
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

    /// What a session has spent so far, in micro-dollars.
    pub fn spent(&self, session_id: &str) -> u64 {
        lock(&self.spend).get(session_id).map_or(0, |spend| spend.micros)
    }

    pub(crate) fn record_spend(&self, session_id: &str, micros: u64) {
        let mut spend = lock(&self.spend);
        let entry = spend.entry(session_id.to_owned()).or_insert(Spend { micros: 0, updated: Instant::now() });
        entry.micros = entry.micros.max(micros);
        entry.updated = Instant::now();
    }

    /// Forgets the spend of sessions idle for longer than `older_than` (tokens live 15 minutes, sessions hours).
    pub fn sweep(&self, older_than: Duration) {
        lock(&self.spend).retain(|_, spend| spend.updated.elapsed() < older_than);
    }
}
