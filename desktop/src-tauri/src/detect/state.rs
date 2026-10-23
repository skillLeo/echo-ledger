//! Debounced "a call app is using the microphone" state machine (pure; time is passed in as milliseconds).
//! Idle → Candidate (mic held ≥ start_after) → Recording → Releasing (released ≥ stop_after) → Idle.

use super::classify::SourceApp;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum MicUseState {
    Idle,
    Candidate { app: SourceApp, since_ms: u64 },
    Recording { app: SourceApp },
    Releasing { app: SourceApp, since_ms: u64 },
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum MicUseEvent {
    Start(SourceApp),
    Stop(SourceApp),
}

#[derive(Clone, Debug)]
pub struct MicUseStateMachine {
    state: MicUseState,
    start_after_ms: u64,
    stop_after_ms: u64,
}

impl MicUseStateMachine {
    pub fn new(start_after_ms: u64, stop_after_ms: u64) -> Self {
        Self { state: MicUseState::Idle, start_after_ms, stop_after_ms }
    }

    pub fn state(&self) -> MicUseState {
        self.state
    }

    /// Feed the currently detected call app (None = nobody but us holds the mic).
    pub fn update(&mut self, now_ms: u64, active: Option<SourceApp>) -> Option<MicUseEvent> {
        let (next, event) = match (self.state, active) {
            (MicUseState::Idle, Some(app)) => (MicUseState::Candidate { app, since_ms: now_ms }, None),
            (MicUseState::Idle, None) => (MicUseState::Idle, None),
            (MicUseState::Candidate { app, since_ms }, Some(current)) if current == app => {
                if now_ms.saturating_sub(since_ms) >= self.start_after_ms {
                    (MicUseState::Recording { app }, Some(MicUseEvent::Start(app)))
                } else {
                    (self.state, None)
                }
            }
            (MicUseState::Candidate { .. }, Some(other)) => (MicUseState::Candidate { app: other, since_ms: now_ms }, None),
            (MicUseState::Candidate { .. }, None) => (MicUseState::Idle, None),
            (MicUseState::Recording { app }, Some(_)) => (MicUseState::Recording { app }, None),
            (MicUseState::Recording { app }, None) => (MicUseState::Releasing { app, since_ms: now_ms }, None),
            (MicUseState::Releasing { app, .. }, Some(_)) => (MicUseState::Recording { app }, None),
            (MicUseState::Releasing { app, since_ms }, None) => {
                if now_ms.saturating_sub(since_ms) >= self.stop_after_ms {
                    (MicUseState::Idle, Some(MicUseEvent::Stop(app)))
                } else {
                    (self.state, None)
                }
            }
        };
        self.state = next;
        event
    }

    /// Manual stop/start overrides reset the machine.
    pub fn reset(&mut self) {
        self.state = MicUseState::Idle;
    }
}
