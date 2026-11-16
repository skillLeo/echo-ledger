//! Telemetry: one record per suggestion (latency stages, tokens, cost) and a few session events. Records hold ids,
//! numbers and fixed labels only: no words anyone said or a model wrote, no tokens, no secrets.

use std::sync::{Arc, Mutex};

use crate::domain::{EffectiveMode, Mode, ProviderKind};
use crate::protocol::{Latency, UsageReport};

/// What started a suggestion.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Trigger {
    /// A question-like partial and a pause, before the end of the turn.
    Speculative,
    /// The end of the other person's turn.
    Final,
    HelpNow,
    Regenerate,
    Shorter,
    Formal,
    Translate,
    /// The balanced mode's second model.
    Upgrade,
}

impl Trigger {
    pub fn as_str(self) -> &'static str {
        match self {
            Trigger::Speculative => "speculative",
            Trigger::Final => "final",
            Trigger::HelpNow => "help_now",
            Trigger::Regenerate => "regenerate",
            Trigger::Shorter => "shorter",
            Trigger::Formal => "formal",
            Trigger::Translate => "translate",
            Trigger::Upgrade => "upgrade",
        }
    }
}

/// How a suggestion ended.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Outcome {
    Done,
    Skip,
    Cancelled,
    Superseded,
    /// An upgrade that was not shown (I had started speaking, or the draft was gone).
    Discarded,
    Error,
}

impl Outcome {
    pub fn as_str(self) -> &'static str {
        match self {
            Outcome::Done => "done",
            Outcome::Skip => "skip",
            Outcome::Cancelled => "cancelled",
            Outcome::Superseded => "superseded",
            Outcome::Discarded => "discarded",
            Outcome::Error => "error",
        }
    }
}

#[derive(Debug, Clone)]
pub struct SuggestionRecord {
    pub session: String,
    pub suggestion: String,
    pub trigger: Trigger,
    pub outcome: Outcome,
    pub mode: Mode,
    /// A model id from the settings, never free text.
    pub model: String,
    pub provider: ProviderKind,
    pub fallback_used: bool,
    pub latency: Latency,
    pub usage: UsageReport,
    pub error: Option<&'static str>,
}

#[derive(Debug, Clone)]
pub enum SessionEvent {
    Attached { session: String, resumed: bool },
    Detached { session: String, reason: &'static str },
    Ended { session: String, suggestions: u64, spent_micros: u64 },
    ModeChanged { session: String, from: EffectiveMode, to: EffectiveMode, reason: &'static str },
    TokenRefreshed { session: String },
    AuthRejected { reason: &'static str },
    Warmed { session: String, model: String, cost_micros: u64, error: Option<&'static str> },
}

pub trait TelemetrySink: Send + Sync + std::fmt::Debug {
    fn suggestion(&self, record: &SuggestionRecord);
    fn event(&self, event: &SessionEvent);
}

/// Writes records to the log as structured fields (JSON lines in production).
#[derive(Debug, Default)]
pub struct LogSink;

impl TelemetrySink for LogSink {
    fn suggestion(&self, r: &SuggestionRecord) {
        tracing::info!(
            target: "assist_relay::telemetry",
            event = "suggestion",
            session = %r.session,
            suggestion = %r.suggestion,
            trigger = r.trigger.as_str(),
            outcome = r.outcome.as_str(),
            mode = r.mode.as_str(),
            model = %r.model,
            provider = r.provider.as_str(),
            fallback = r.fallback_used,
            end_of_turn_to_request_ms = r.latency.end_of_turn_to_request_ms,
            request_to_first_token_ms = r.latency.request_to_first_token_ms,
            end_of_turn_to_first_token_ms = r.latency.end_of_turn_to_first_token_ms,
            relay_overhead_ms = r.latency.relay_overhead_ms,
            total_ms = r.latency.total_ms,
            input_tokens = r.usage.input_tokens,
            cached_input_tokens = r.usage.cached_input_tokens,
            cache_write_tokens = r.usage.cache_write_tokens,
            output_tokens = r.usage.output_tokens,
            cost_usd = r.usage.cost_usd,
            usage_estimated = r.usage.estimated,
            error = r.error,
        );
    }

    fn event(&self, event: &SessionEvent) {
        match event {
            SessionEvent::Attached { session, resumed } => {
                tracing::info!(target: "assist_relay::telemetry", event = "attached", session = %session, resumed);
            }
            SessionEvent::Detached { session, reason } => {
                tracing::info!(target: "assist_relay::telemetry", event = "detached", session = %session, reason);
            }
            SessionEvent::Ended { session, suggestions, spent_micros } => {
                tracing::info!(target: "assist_relay::telemetry", event = "ended", session = %session, suggestions, spent_micros);
            }
            SessionEvent::ModeChanged { session, from, to, reason } => tracing::info!(
                target: "assist_relay::telemetry",
                event = "mode_changed",
                session = %session,
                from = from.as_str(),
                to = to.as_str(),
                reason
            ),
            SessionEvent::TokenRefreshed { session } => {
                tracing::info!(target: "assist_relay::telemetry", event = "token_refreshed", session = %session);
            }
            SessionEvent::AuthRejected { reason } => {
                tracing::warn!(target: "assist_relay::telemetry", event = "auth_rejected", reason);
            }
            SessionEvent::Warmed { session, model, cost_micros, error } => {
                tracing::debug!(target: "assist_relay::telemetry", event = "warmed", session = %session, model = %model, cost_micros, error);
            }
        }
    }
}

/// Keeps every record in memory, for the tests and the load test.
#[derive(Debug, Default, Clone)]
pub struct Recorder {
    suggestions: Arc<Mutex<Vec<SuggestionRecord>>>,
    events: Arc<Mutex<Vec<SessionEvent>>>,
}

impl Recorder {
    pub fn suggestions(&self) -> Vec<SuggestionRecord> {
        self.suggestions.lock().map(|r| r.clone()).unwrap_or_default()
    }

    pub fn events(&self) -> Vec<SessionEvent> {
        self.events.lock().map(|e| e.clone()).unwrap_or_default()
    }
}

impl TelemetrySink for Recorder {
    fn suggestion(&self, record: &SuggestionRecord) {
        if let Ok(mut records) = self.suggestions.lock() {
            records.push(record.clone());
        }
    }

    fn event(&self, event: &SessionEvent) {
        if let Ok(mut events) = self.events.lock() {
            events.push(event.clone());
        }
    }
}

/// Sends every record to several sinks (the log and a recorder, in the load test).
#[derive(Debug)]
pub struct Fanout(pub Vec<Arc<dyn TelemetrySink>>);

impl TelemetrySink for Fanout {
    fn suggestion(&self, record: &SuggestionRecord) {
        self.0.iter().for_each(|sink| sink.suggestion(record));
    }

    fn event(&self, event: &SessionEvent) {
        self.0.iter().for_each(|sink| sink.event(event));
    }
}
