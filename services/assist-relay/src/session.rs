//! One assist session: a single task that owns the call's context, transcript, budget and suggestions, outlives a
//! dropped connection for `resume_grace_secs`, and talks to whichever connection is attached.
//!
//! Suggestions start on the other person's words only, never on mine:
//! - speculatively, when a partial looks like a question and the speech engine reports at least 128 ms of silence;
//!   more words within 700 ms cancel it, and the next pause starts it again;
//! - at the end of the turn, unless the speculative suggestion already answers the same words;
//! - when I ask (help now, regenerate, shorter, formal, translate).
//!
//! A hard question in Balanced also goes to the upgrade model; its answer replaces the draft only if I have not started
//! speaking. Every request is priced against the session's budget, which steps the mode down and, at the end, leaves
//! captions only, without ever closing the connection for it.

use std::collections::{HashMap, VecDeque};
use std::sync::Arc;
use std::time::Duration;

use tokio::sync::mpsc;
use tokio::task::JoinHandle;
use tokio::time::Instant;

use crate::auth::{AuthError, Claims, TokenVerifier};
use crate::budget::{Cause, allowed_modes, choose_mode, cost_micros, estimate_micros, micros_to_usd};
use crate::config::{RelayConfig, Shared, TranscriptScope};
use crate::context::{CallContext, Context, ContextBuilder, RewriteKind, Task, Transcript, TranscriptTurn};
use crate::domain::{Channel, EffectiveMode, LanguageHelp, Mode, ProviderKind};
use crate::internal::{Report, ReportSink, SuggestionText, UsageRecord};
use crate::pack::Pack;
use crate::protocol::{
    ClientMessage, ContextUpdate, EndStatus, ErrorMessage, Latency, ModeChanged, ModeReason, PROTOCOL_VERSION, Ready,
    ReadyLimits, ServerMessage, Skip, SuggestionEnd, SuggestionKind, SuggestionStart, TokenChunk, TokenRefreshed,
    TurnMessage, UsageReport,
};
use crate::provider::{CompletionRequest, ProviderError, Providers, Purpose, StreamEvent, Usage};
use crate::registry::{Ledger, Registry};
use crate::skip::{GateEnd, SkipGate, is_skip};
use crate::telemetry::{Outcome, SessionEvent, SuggestionRecord, TelemetrySink, Trigger};
use crate::text::{estimate_tokens, has_phrase, is_backchannel, looks_like_question, normalize, same_question, words};
use crate::time::{iso8601, ms, unix_now};

/// WebSocket close codes the relay uses.
pub mod close {
    pub const TOO_BIG: u16 = 1009;
    pub const SERVICE_RESTART: u16 = 1012;
    pub const TOKEN_EXPIRED: u16 = 4401;
    pub const WRONG_SESSION: u16 = 4403;
    pub const IDLE: u16 = 4408;
    pub const REPLACED: u16 = 4409;
}

/// A provider keeps a written prefix about this long after its last use.
const CACHE_TTL: Duration = Duration::from_secs(300);
/// After the first token, a stream that sends nothing for this long has failed.
const STALL_TIMEOUT: Duration = Duration::from_secs(10);
/// Suggestions kept for regenerate, shorter, formal and translate.
const HISTORY: usize = 20;
/// The question and task lines, for estimates before the request is built.
const QUESTION_TOKENS: u32 = 80;

/// A message from the app, with its `seq` and the time the relay read it from the socket (the "end of turn" of a
/// suggestion).
#[derive(Debug)]
pub struct Inbound {
    pub message: ClientMessage,
    pub seq: Option<u64>,
    pub received_at: Instant,
}

/// What the session sends to its connection's writer.
#[derive(Debug, Clone)]
pub enum Outbound {
    Message(ServerMessage),
    Close { code: u16, reason: &'static str },
}

/// A verified connection, handed to a session.
#[derive(Debug)]
pub struct Connection {
    pub id: u64,
    pub claims: Claims,
    pub out: mpsc::Sender<Outbound>,
    pub inbound: mpsc::Receiver<Inbound>,
}

#[derive(Debug)]
pub(crate) enum Control {
    // Boxed: a connection is large and the other variant carries nothing.
    Attach(Box<Connection>),
    Shutdown,
}

/// What every session needs, shared.
#[derive(Debug, Clone)]
pub struct SessionDeps {
    /// The settings in use (the server's catalog replaces the file's models and modes when it arrives).
    pub config: Arc<Shared<RelayConfig>>,
    pub pack: Arc<Shared<Pack>>,
    pub providers: Arc<Providers>,
    pub telemetry: Arc<dyn TelemetrySink>,
    /// Usage and suggestion texts for the server.
    pub reports: Arc<dyn ReportSink>,
    pub verifier: Arc<TokenVerifier>,
}

/// Where a suggestion came from, with the words it answers.
#[derive(Clone)]
enum Origin {
    Speculative { turn_id: String, text: String },
    Final { turn_id: String, text: String },
    HelpNow { turn_id: Option<String>, question: String },
    Rewrite { kind: RewriteKind, turn_id: Option<String>, question: String, previous: String },
}

impl Origin {
    fn trigger(&self) -> Trigger {
        match self {
            Origin::Speculative { .. } => Trigger::Speculative,
            Origin::Final { .. } => Trigger::Final,
            Origin::HelpNow { .. } => Trigger::HelpNow,
            Origin::Rewrite { kind, .. } => match kind {
                RewriteKind::Regenerate => Trigger::Regenerate,
                RewriteKind::Shorter => Trigger::Shorter,
                RewriteKind::Formal => Trigger::Formal,
                RewriteKind::Translate(_) => Trigger::Translate,
            },
        }
    }

    fn kind(&self) -> SuggestionKind {
        match self {
            Origin::Speculative { .. } | Origin::Final { .. } => SuggestionKind::Answer,
            Origin::HelpNow { .. } => SuggestionKind::HelpNow,
            Origin::Rewrite { kind, .. } => match kind {
                RewriteKind::Regenerate => SuggestionKind::Regenerate,
                RewriteKind::Shorter => SuggestionKind::Shorter,
                RewriteKind::Formal => SuggestionKind::Formal,
                RewriteKind::Translate(_) => SuggestionKind::Translate,
            },
        }
    }

    fn purpose(&self) -> Purpose {
        match self {
            Origin::Speculative { .. } | Origin::Final { .. } => Purpose::Answer,
            Origin::HelpNow { .. } => Purpose::HelpNow,
            Origin::Rewrite { kind, .. } => match kind {
                RewriteKind::Regenerate => Purpose::Regenerate,
                RewriteKind::Shorter => Purpose::Shorter,
                RewriteKind::Formal => Purpose::Formal,
                RewriteKind::Translate(_) => Purpose::Translate,
            },
        }
    }

    fn turn_id(&self) -> Option<&str> {
        match self {
            Origin::Speculative { turn_id, .. } | Origin::Final { turn_id, .. } => Some(turn_id),
            Origin::HelpNow { turn_id, .. } | Origin::Rewrite { turn_id, .. } => turn_id.as_deref(),
        }
    }

    fn question(&self) -> &str {
        match self {
            Origin::Speculative { text, .. } | Origin::Final { text, .. } => text,
            Origin::HelpNow { question, .. } | Origin::Rewrite { question, .. } => question,
        }
    }

    /// Asked for by me, so I hear back even when no suggestion can be made.
    fn explicit(&self) -> bool {
        matches!(self, Origin::HelpNow { .. } | Origin::Rewrite { .. })
    }

    fn task(&self) -> Task<'_> {
        match self {
            Origin::Speculative { turn_id, text } => Task::Answer { turn_id: Some(turn_id.as_str()), text, partial: true },
            Origin::Final { turn_id, text } => Task::Answer { turn_id: Some(turn_id.as_str()), text, partial: false },
            Origin::HelpNow { question, .. } => Task::HelpNow { latest: Some(question.as_str()) },
            Origin::Rewrite { kind, question, previous, .. } => Task::Rewrite { kind: *kind, question, previous },
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Visibility {
    /// Streamed to the card.
    Shown,
    /// Balanced's upgrade, held until it is complete.
    Upgrade,
}

struct Active {
    id: String,
    visibility: Visibility,
    origin: Origin,
    mode: Mode,
    model: String,
    provider: ProviderKind,
    fallback: Option<String>,
    fallback_used: bool,
    attempt: u32,
    t0: Instant,
    requested_at: Instant,
    provider_first_token_at: Option<Instant>,
    first_sent_at: Option<Instant>,
    gate: SkipGate,
    text: String,
    usage: Option<Usage>,
    context: Arc<Context>,
    task: JoinHandle<()>,
    /// A shown draft: its upgrade. An upgrade: its draft.
    linked: Option<String>,
}

impl Active {
    fn usage(&self) -> (Usage, bool) {
        match self.usage {
            Some(usage) => (usage, false),
            // Cancelled before the provider counted: everything as uncached input, the words so far as output.
            None => (
                Usage {
                    input_tokens: self.context.tokens(),
                    cached_input_tokens: 0,
                    cache_write_tokens: 0,
                    output_tokens: estimate_tokens(&self.text),
                },
                true,
            ),
        }
    }

    fn latency(&self, now: Instant) -> Latency {
        let request = self.requested_at.saturating_duration_since(self.t0);
        let provider = self.provider_first_token_at.map(|at| at.saturating_duration_since(self.requested_at));
        let shown = self.first_sent_at.map(|at| at.saturating_duration_since(self.t0));
        let overhead = match (shown, provider, self.visibility) {
            (Some(shown), Some(provider), Visibility::Shown) => Some(shown.saturating_sub(provider)),
            _ => None,
        };
        Latency {
            end_of_turn_to_request_ms: ms(request),
            request_to_first_token_ms: provider.map(ms),
            end_of_turn_to_first_token_ms: shown.map(ms),
            relay_overhead_ms: overhead.map(ms),
            total_ms: ms(now.saturating_duration_since(self.t0)),
        }
    }
}

/// A finished suggestion, kept for rewrites and for `replaces`.
#[derive(Clone)]
struct Finished {
    id: String,
    turn_id: Option<String>,
    question: String,
    text: String,
}

/// A speculative start, until the end of the turn.
struct Speculation {
    turn_id: String,
    text: String,
    started: Instant,
    suggestion_id: String,
}

enum Feed {
    Stream { id: String, attempt: u32, at: Instant, item: Item },
    Warmed { model: String, result: Result<Usage, ProviderError> },
}

enum Item {
    Event(StreamEvent),
    Error(ProviderError),
    End,
}

/// How a shown suggestion ends.
enum Finish {
    Done,
    Skip,
    Cancelled,
    Superseded,
    Error(ProviderError),
}

/// Why the mode is being looked at again.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Why {
    Settings,
    Spend,
    Refresh,
    Request,
}

struct Attached {
    out: mpsc::Sender<Outbound>,
    inbound: mpsc::Receiver<Inbound>,
}

pub(crate) struct Engine {
    deps: SessionDeps,
    registry: Arc<Registry>,
    generation: u64,
    session_id: String,
    user: String,
    claims: Claims,
    nonce: String,
    seq: u64,
    conn: Option<Attached>,
    ever_attached: bool,
    broken: Option<&'static str>,
    token_deadline: Option<Instant>,
    grace_deadline: Option<Instant>,
    call: CallContext,
    transcript: Transcript,
    fixed_tokens: Option<u32>,
    requested: Mode,
    effective: EffectiveMode,
    paused: bool,
    spent: u64,
    spec: Option<Speculation>,
    active: HashMap<String, Active>,
    history: VecDeque<Finished>,
    last_me_speech: Option<Instant>,
    model_used_at: HashMap<String, Instant>,
    last_warm: Option<Instant>,
    /// What the session has spent and how long it was connected, and both when the current token arrived: the
    /// token's `cents_left` and `seconds_left` count down from there.
    ledger: Ledger,
    connected_since: Option<Instant>,
    assist_used_up: bool,
    /// The last mode change was the budget's: a refresh that raises the mode restores budget.
    budget_limited: bool,
    /// The `seq` of the app's message being handled (quoted in errors about it).
    replying_to: Option<u64>,
    feed_tx: mpsc::Sender<Feed>,
    feed_rx: mpsc::Receiver<Feed>,
    suggestions: u64,
}

async fn next_inbound(conn: &mut Option<Attached>) -> Option<Inbound> {
    match conn {
        Some(attached) => attached.inbound.recv().await,
        None => std::future::pending().await,
    }
}

async fn sleep_until(deadline: Option<Instant>) {
    match deadline {
        Some(deadline) => tokio::time::sleep_until(deadline).await,
        None => std::future::pending().await,
    }
}

impl Engine {
    pub(crate) fn new(registry: Arc<Registry>, generation: u64, claims: Claims, ledger: Ledger) -> Self {
        let deps = registry.deps().clone();
        let (feed_tx, feed_rx) = mpsc::channel(1024);
        let transcript = Transcript::new(&deps.config.get().context);
        let nonce = format!("{:x}", (unix_now().unsigned_abs() << 16) ^ generation);
        Engine {
            session_id: claims.sid.clone(),
            user: claims.sub.clone(),
            deps,
            registry,
            generation,
            claims,
            nonce,
            seq: 0,
            conn: None,
            ever_attached: false,
            broken: None,
            token_deadline: None,
            grace_deadline: None,
            call: CallContext::default(),
            transcript,
            fixed_tokens: None,
            requested: Mode::Balanced,
            effective: EffectiveMode::CaptionsOnly,
            paused: false,
            spent: ledger.spent,
            spec: None,
            active: HashMap::new(),
            history: VecDeque::new(),
            last_me_speech: None,
            model_used_at: HashMap::new(),
            last_warm: None,
            ledger,
            connected_since: None,
            assist_used_up: false,
            budget_limited: false,
            replying_to: None,
            feed_tx,
            feed_rx,
            suggestions: 0,
        }
    }

    pub(crate) async fn run(mut self, mut control: mpsc::UnboundedReceiver<Control>) {
        loop {
            let token_deadline = self.token_deadline;
            let grace_deadline = self.grace_deadline;
            let assist_deadline = self.assist_deadline();
            tokio::select! {
                biased;
                message = control.recv() => match message {
                    Some(Control::Attach(connection)) => self.attach(*connection),
                    Some(Control::Shutdown) | None => {
                        self.shutdown();
                        break;
                    }
                },
                inbound = next_inbound(&mut self.conn) => match inbound {
                    Some(inbound) => self.on_message(inbound),
                    None => self.detach("closed"),
                },
                Some(feed) = self.feed_rx.recv() => self.on_feed(feed),
                () = sleep_until(token_deadline) => self.on_token_expired(),
                () = sleep_until(assist_deadline) => self.on_assist_time_up(),
                () = sleep_until(grace_deadline) => {
                    match self.registry.finish(&self.session_id, self.generation, &mut control) {
                        Some(Control::Attach(connection)) => self.attach(*connection),
                        Some(Control::Shutdown) => {
                            self.shutdown();
                            break;
                        }
                        None => {
                            self.end();
                            return;
                        }
                    }
                }
            }
            if let Some(reason) = self.broken.take() {
                self.detach(reason);
            }
        }
        self.registry.remove(&self.session_id, self.generation);
        self.end();
    }

    // -----------------------------------------------------------------------------------------------------------
    // Connections
    // -----------------------------------------------------------------------------------------------------------

    fn config(&self) -> Arc<RelayConfig> {
        self.deps.config.get()
    }

    fn attach(&mut self, connection: Connection) {
        if !self.ledger.accepts(connection.claims.iat) {
            // Checked before the upgrade too; this covers a token that raced a refresh. The current connection stays.
            let error = AuthError::Superseded;
            let refused = ServerMessage::Error(ErrorMessage::new(error.code(), error.to_string()).fatal());
            let _ = connection.out.try_send(Outbound::Message(refused));
            let _ = connection.out.try_send(Outbound::Close { code: close::TOKEN_EXPIRED, reason: "an older token" });
            self.deps.telemetry.event(&SessionEvent::AuthRejected { reason: error.reason() });
            return;
        }
        let resumed = self.ever_attached;
        if let Some(old) = self.conn.take() {
            let _ = old.out.try_send(Outbound::Close { code: close::REPLACED, reason: "replaced by a newer connection" });
        }
        self.cancel_all();
        self.stop_clock();
        self.take_token(connection.claims);
        self.grace_deadline = None;
        self.conn = Some(Attached { out: connection.out, inbound: connection.inbound });
        self.connected_since = Some(Instant::now());
        self.ever_attached = true;
        self.effective = self.compute_mode().0;
        let config = self.config();
        let ready = Ready {
            protocol: PROTOCOL_VERSION,
            session_id: self.session_id.clone(),
            mode: self.effective,
            requested_mode: self.requested,
            allowed_modes: allowed_modes(&config, &self.claims),
            expires_at: iso8601(self.claims.exp),
            resumed,
            practice: self.claims.practice,
            limits: ReadyLimits {
                max_message_bytes: config.limits.max_message_bytes,
                max_turn_chars: config.limits.max_turn_chars,
                messages_per_second: config.limits.messages_per_second,
            },
        };
        self.emit(ServerMessage::Ready(ready));
        self.deps.telemetry.event(&SessionEvent::Attached { session: self.session_id.clone(), resumed });
    }

    /// Counts from a token's first arrival: a new token from now, a token seen before (a reconnect, or one used
    /// earlier) from where it first arrived. The caller has checked that it is not older than the newest one.
    fn take_token(&mut self, claims: Claims) {
        if claims.token_key() != self.ledger.token_key {
            let (spent, connected) = (self.spent, self.connected_time());
            self.ledger.switch_to(claims.token_key(), spent, connected);
        }
        self.claims = claims;
        self.token_deadline = Some(deadline_for(self.claims.exp));
        self.assist_used_up = self.time_left().is_zero();
        self.save_ledger();
    }

    fn connected_time(&self) -> Duration {
        self.ledger.connected + self.connected_since.map_or(Duration::ZERO, |since| since.elapsed())
    }

    fn stop_clock(&mut self) {
        if let Some(since) = self.connected_since.take() {
            self.ledger.connected += since.elapsed();
        }
    }

    /// Assist time left on the current token.
    fn time_left(&self) -> Duration {
        let used = self.connected_time().saturating_sub(self.ledger.time_base);
        Duration::from_secs(self.claims.budget.seconds_left).saturating_sub(used)
    }

    fn save_ledger(&mut self) {
        self.ledger.spent = self.spent;
        let mut ledger = self.ledger.clone();
        ledger.connected = self.connected_time();
        self.registry.save_ledger(&self.session_id, ledger);
    }

    fn detach(&mut self, reason: &'static str) {
        if self.conn.take().is_none() {
            return;
        }
        self.cancel_all();
        self.spec = None;
        self.token_deadline = None;
        self.stop_clock();
        self.save_ledger();
        self.grace_deadline = Some(Instant::now() + self.config().session.resume_grace());
        self.deps.telemetry.event(&SessionEvent::Detached { session: self.session_id.clone(), reason });
    }

    fn shutdown(&mut self) {
        if let Some(conn) = self.conn.take() {
            let _ = conn.out.try_send(Outbound::Close { code: close::SERVICE_RESTART, reason: "relay restarting" });
        }
        self.cancel_all();
    }

    fn end(&mut self) {
        self.cancel_all();
        self.deps.telemetry.event(&SessionEvent::Ended {
            session: self.session_id.clone(),
            suggestions: self.suggestions,
            spent_micros: self.spent,
        });
    }

    fn emit(&mut self, message: ServerMessage) {
        let Some(conn) = &self.conn else {
            return;
        };
        match conn.out.try_send(Outbound::Message(message)) {
            Ok(()) => {}
            Err(mpsc::error::TrySendError::Full(_)) => self.broken = Some("too_slow"),
            Err(mpsc::error::TrySendError::Closed(_)) => self.broken = Some("closed"),
        }
    }

    /// An error, quoting the `seq` of the message being handled.
    fn error(&mut self, error: ErrorMessage) {
        let seq = self.replying_to;
        self.emit(ServerMessage::Error(error.in_reply_to(seq)));
    }

    fn on_token_expired(&mut self) {
        self.error(ErrorMessage::new("token_expired", "The session token has expired. Connect again with a new token.").retry().fatal());
        if let Some(conn) = &self.conn {
            let _ = conn.out.try_send(Outbound::Close { code: close::TOKEN_EXPIRED, reason: "token expired" });
        }
        self.detach("token_expired");
    }

    fn on_token_refresh(&mut self, token: &str) {
        match self.deps.verifier.verify_for_session(token, &self.session_id, unix_now()) {
            Ok(claims) if claims.sub == self.user && !self.ledger.accepts(claims.iat) => {
                self.deps.telemetry.event(&SessionEvent::AuthRejected { reason: AuthError::Superseded.reason() });
                self.error(ErrorMessage::new("token_refresh_rejected", AuthError::Superseded.to_string()));
            }
            Ok(claims) if claims.sub == self.user => {
                self.take_token(claims);
                self.emit(ServerMessage::TokenRefreshed(TokenRefreshed { expires_at: iso8601(self.claims.exp) }));
                self.deps.telemetry.event(&SessionEvent::TokenRefreshed { session: self.session_id.clone() });
                self.evaluate_mode(Why::Refresh);
            }
            Ok(_) => self.error(ErrorMessage::new("token_refresh_rejected", "The new token is for another person.")),
            Err(error) => {
                self.deps.telemetry.event(&SessionEvent::AuthRejected { reason: error.reason() });
                self.error(ErrorMessage::new("token_refresh_rejected", error.to_string()));
            }
        }
    }

    // -----------------------------------------------------------------------------------------------------------
    // Messages from the app
    // -----------------------------------------------------------------------------------------------------------

    fn on_message(&mut self, inbound: Inbound) {
        let at = inbound.received_at;
        self.replying_to = inbound.seq;
        match inbound.message {
            ClientMessage::Auth(_) => {
                self.error(ErrorMessage::new("bad_message", "The connection is signed in already; use token_refresh for a new token."));
            }
            ClientMessage::ContextUpdate(update) => self.on_context(update),
            ClientMessage::TurnPartial(turn) => self.on_partial(turn, at),
            ClientMessage::TurnFinal(turn) => self.on_final(turn, at),
            ClientMessage::HelpNow(help) => self.on_help_now(help.turn_id, at),
            ClientMessage::Regenerate(rewrite) => self.on_rewrite(RewriteKind::Regenerate, rewrite.suggestion_id, at),
            ClientMessage::Shorter(rewrite) => self.on_rewrite(RewriteKind::Shorter, rewrite.suggestion_id, at),
            ClientMessage::Formal(rewrite) => self.on_rewrite(RewriteKind::Formal, rewrite.suggestion_id, at),
            ClientMessage::Translate(translate) => {
                self.on_rewrite(RewriteKind::Translate(translate.language), translate.suggestion_id, at);
            }
            ClientMessage::Cancel(cancel) => self.on_cancel(cancel.suggestion_id),
            ClientMessage::TokenRefresh(refresh) => self.on_token_refresh(&refresh.token),
        }
        self.replying_to = None;
    }

    fn on_context(&mut self, update: ContextUpdate) {
        let mut fixed_changed = false;
        if let Some(brief) = update.brief
            && brief != self.call.brief
        {
            self.call.brief = brief;
            fixed_changed = true;
        }
        if let Some(documents) = update.documents {
            let tokens: u32 = documents.iter().map(|d| estimate_tokens(&d.text)).sum();
            let most = self.config().context.max_document_tokens;
            if tokens > most {
                self.error(ErrorMessage::new(
                    "documents_too_large",
                    format!("The documents are too long for live suggestions (about {tokens} tokens; at most {most}). Send the parts that matter most."),
                ));
            } else if documents != self.call.documents {
                self.call.documents = documents;
                fixed_changed = true;
            }
        }
        if let Some(memory) = update.memory
            && memory.0 != self.call.memory
        {
            self.call.memory = memory.0;
            fixed_changed = true;
        }
        if let Some(facts) = update.facts {
            self.call.facts = facts;
        }
        if let Some(summary) = update.summary {
            self.call.summary = summary.0;
        }
        if let Some(settings) = update.settings {
            // The hint level and the language help sit near the end of the request ("Now"), so they change nothing
            // that is cached.
            if let Some(hint) = settings.hint_level {
                self.call.hint_level = Some(hint);
            }
            if let Some(language) = settings.language_help {
                self.call.language_help = language;
            }
            if let Some(assist) = settings.assist {
                self.set_paused(assist == crate::domain::AssistState::Paused);
            }
            if let Some(mode) = settings.mode {
                self.requested = mode;
                self.evaluate_mode(Why::Settings);
            }
        }
        if fixed_changed {
            self.fixed_tokens = None;
            self.warm();
        }
    }

    fn set_paused(&mut self, paused: bool) {
        if paused == self.paused {
            return;
        }
        self.paused = paused;
        if paused {
            self.spec = None;
            for id in self.shown_ids() {
                self.finish_shown(&id, Finish::Cancelled);
            }
        }
    }

    fn suggestions_on(&self) -> bool {
        self.conn.is_some() && !self.paused && self.effective != EffectiveMode::CaptionsOnly
    }

    fn on_partial(&mut self, turn: TurnMessage, at: Instant) {
        if turn.text.trim().is_empty() {
            return;
        }
        if turn.channel == Channel::Me {
            // Never a trigger. It only tells an upgrade that I have started answering.
            self.last_me_speech = Some(at);
            return;
        }
        let config = self.config();
        let window = Duration::from_millis(config.speculative.restart_window_ms);
        if let Some(spec) = &self.spec {
            if spec.turn_id != turn.turn_id {
                // A new turn: the earlier speculation stays as it is.
                self.spec = None;
            } else if normalize(&spec.text) == normalize(&turn.text) {
                // Only the silence grew.
                return;
            } else if at.saturating_duration_since(spec.started) <= window {
                // They kept talking: cancel, and start again at the next pause.
                let id = spec.suggestion_id.clone();
                self.spec = None;
                if self.active.contains_key(&id) {
                    self.finish_shown(&id, Finish::Cancelled);
                }
            } else {
                // Past the window the suggestion stands; the end of the turn decides.
                return;
            }
        }
        if !self.suggestions_on() {
            return;
        }
        let silence = turn.silence_ms.unwrap_or(0);
        if silence >= config.speculative.min_silence_ms && looks_like_question(&turn.text) && !is_backchannel(&turn.text) {
            self.start(Origin::Speculative { turn_id: turn.turn_id, text: turn.text }, at, None);
        }
    }

    fn on_final(&mut self, turn: TurnMessage, at: Instant) {
        let text = turn.text.trim().to_owned();
        if text.is_empty() {
            return;
        }
        self.transcript.push(TranscriptTurn {
            turn_id: turn.turn_id.clone(),
            channel: turn.channel,
            text: text.clone(),
            start_ms: turn.start_ms,
        });
        if turn.channel == Channel::Me {
            self.last_me_speech = Some(at);
            return;
        }
        let spec = self.spec.take().filter(|spec| spec.turn_id == turn.turn_id);
        let mut replaces = None;
        if let Some(spec) = spec {
            if same_question(&spec.text, &text) {
                // The speculative suggestion already answers these words.
                return;
            }
            if self.active.contains_key(&spec.suggestion_id) {
                self.finish_shown(&spec.suggestion_id, Finish::Superseded);
            } else if self.history.iter().any(|h| h.id == spec.suggestion_id) {
                replaces = Some(spec.suggestion_id);
            }
        } else if is_backchannel(&text) {
            return;
        }
        if self.suggestions_on() {
            self.start(Origin::Final { turn_id: turn.turn_id, text }, at, replaces);
        }
    }

    fn on_help_now(&mut self, turn_id: Option<String>, at: Instant) {
        let turn = match &turn_id {
            Some(id) => self.transcript.find(id),
            None => self.transcript.last_from(Channel::Other),
        };
        let question = turn.map(|t| t.text.clone()).unwrap_or_default();
        let turn_id = turn.map(|t| t.turn_id.clone());
        self.start(Origin::HelpNow { turn_id, question }, at, None);
    }

    fn on_rewrite(&mut self, kind: RewriteKind, suggestion_id: Option<String>, at: Instant) {
        let target = match &suggestion_id {
            Some(id) => self.history.iter().rev().find(|h| &h.id == id),
            None => self.history.back(),
        };
        let Some(target) = target.cloned() else {
            self.error(ErrorMessage::new("nothing_to_rewrite", "There is no finished suggestion to change yet."));
            return;
        };
        if let RewriteKind::Translate(LanguageHelp::Off) = kind {
            return;
        }
        let origin = Origin::Rewrite { kind, turn_id: target.turn_id, question: target.question, previous: target.text };
        self.start(origin, at, Some(target.id));
    }

    fn on_cancel(&mut self, suggestion_id: Option<String>) {
        let ids = match suggestion_id {
            Some(id) => vec![id],
            None => self.shown_ids(),
        };
        for id in ids {
            if self.active.get(&id).is_some_and(|a| a.visibility == Visibility::Shown) {
                if self.spec.as_ref().is_some_and(|spec| spec.suggestion_id == id) {
                    self.spec = None;
                }
                self.finish_shown(&id, Finish::Cancelled);
            }
        }
    }

    // -----------------------------------------------------------------------------------------------------------
    // Suggestions
    // -----------------------------------------------------------------------------------------------------------

    fn next_id(&mut self) -> String {
        self.seq += 1;
        format!("{}-{}", self.nonce, self.seq)
    }

    fn shown_ids(&self) -> Vec<String> {
        self.active.values().filter(|a| a.visibility == Visibility::Shown).map(|a| a.id.clone()).collect()
    }

    fn is_hard(&self, question: &str) -> bool {
        let config = self.config();
        let normalized = normalize(question);
        words(&normalized).len() >= config.upgrade.min_words || has_phrase(question, &config.upgrade.phrases)
    }

    fn model_allowed(&self, model: &str) -> bool {
        self.claims.models.iter().any(|m| m == model) && self.config().model(model).is_some_and(|spec| spec.enabled)
    }

    fn start(&mut self, origin: Origin, t0: Instant, replaces: Option<String>) {
        self.evaluate_mode(Why::Request);
        let Some(mode) = self.effective.mode() else {
            if origin.explicit() {
                let message = if self.assist_used_up {
                    "Suggestions are off: this plan's assist time is used up. Captions continue."
                } else {
                    "Suggestions are off: this session's budget is used up. Captions continue."
                };
                self.error(ErrorMessage::new("budget_exhausted", message));
            }
            return;
        };
        // One answer on the card at a time.
        for id in self.shown_ids() {
            self.finish_shown(&id, Finish::Superseded);
        }
        let config = self.config();
        let pack = self.deps.pack.get();
        let plan = config.modes.get(mode);
        let builder = ContextBuilder { pack: &pack, config: &config.context };
        let context = Arc::new(builder.build(&self.call, &self.transcript, origin.task(), plan.transcript));
        let upgrade = plan
            .upgrade
            .clone()
            .filter(|model| {
                matches!(origin, Origin::Speculative { .. } | Origin::Final { .. } | Origin::HelpNow { .. })
                    && self.model_allowed(model)
                    && self.is_hard(origin.question())
            });
        let fallback = plan.fallback.clone().filter(|model| self.model_allowed(model));
        let id = self.next_id();
        self.suggestions += 1;
        self.emit(ServerMessage::SuggestionStart(SuggestionStart {
            suggestion_id: id.clone(),
            turn_id: origin.turn_id().map(str::to_owned),
            kind: origin.kind(),
            mode,
            model: plan.primary.clone(),
            speculative: matches!(origin, Origin::Speculative { .. }),
            draft: upgrade.is_some(),
            replaces,
        }));
        if let Origin::Speculative { turn_id, text } = &origin {
            self.spec = Some(Speculation { turn_id: turn_id.clone(), text: text.clone(), started: t0, suggestion_id: id.clone() });
        }
        let upgrade_id = upgrade.as_ref().map(|_| self.next_id());
        self.launch(id.clone(), Visibility::Shown, origin.clone(), mode, plan.primary.clone(), fallback, context.clone(), t0, upgrade_id.clone());
        if let (Some(model), Some(upgrade_id)) = (upgrade, upgrade_id) {
            self.suggestions += 1;
            self.launch(upgrade_id, Visibility::Upgrade, origin, mode, model, None, context, t0, Some(id));
        }
    }

    #[allow(clippy::too_many_arguments)]
    fn launch(
        &mut self,
        id: String,
        visibility: Visibility,
        origin: Origin,
        mode: Mode,
        model: String,
        fallback: Option<String>,
        context: Arc<Context>,
        t0: Instant,
        linked: Option<String>,
    ) {
        let config = self.config();
        let provider = config.model(&model).map_or(ProviderKind::Anthropic, |spec| spec.provider);
        let max_output_tokens = config.modes.get(mode).max_output_tokens;
        let task = self.spawn_attempt(&id, 1, &model, &context, max_output_tokens, &origin);
        let active = Active {
            id: id.clone(),
            visibility,
            origin,
            mode,
            model,
            provider,
            fallback,
            fallback_used: false,
            attempt: 1,
            t0,
            requested_at: Instant::now(),
            provider_first_token_at: None,
            first_sent_at: None,
            gate: SkipGate::default(),
            text: String::new(),
            usage: None,
            context,
            task,
            linked,
        };
        self.active.insert(id, active);
    }

    /// Streams one attempt in its own task. Aborting the task drops the provider stream, which cancels the request.
    fn spawn_attempt(
        &self,
        id: &str,
        attempt: u32,
        model: &str,
        context: &Arc<Context>,
        max_output_tokens: u32,
        origin: &Origin,
    ) -> JoinHandle<()> {
        let config = self.config();
        let spec = config.model(model);
        let provider = self.deps.providers.get(spec.map_or(ProviderKind::Anthropic, |s| s.provider));
        let first_token_timeout = spec.map_or(Duration::from_secs(5), |s| s.first_token_timeout());
        let request = CompletionRequest {
            model: model.to_owned(),
            context: context.clone(),
            max_output_tokens,
            options: spec.map(|s| s.options.clone()).unwrap_or_default(),
            purpose: origin.purpose(),
            question: origin.question().to_owned(),
        };
        let feed = self.feed_tx.clone();
        let id = id.to_owned();
        tokio::spawn(async move {
            use futures_util::StreamExt;
            let mut stream = provider.stream(request);
            let mut waiting_for_text = true;
            loop {
                let wait = if waiting_for_text { first_token_timeout } else { STALL_TIMEOUT };
                let next = tokio::time::timeout(wait, stream.next()).await;
                let at = Instant::now();
                let item = match next {
                    Err(_) => Item::Error(ProviderError::Timeout),
                    Ok(None) => Item::End,
                    Ok(Some(Ok(event))) => {
                        if matches!(event, StreamEvent::Text(_)) {
                            waiting_for_text = false;
                        }
                        Item::Event(event)
                    }
                    Ok(Some(Err(error))) => Item::Error(error),
                };
                let last = matches!(item, Item::End | Item::Error(_));
                if feed.send(Feed::Stream { id: id.clone(), attempt, at, item }).await.is_err() || last {
                    return;
                }
            }
        })
    }

    fn on_feed(&mut self, feed: Feed) {
        match feed {
            Feed::Warmed { model, result } => self.on_warmed(model, result),
            Feed::Stream { id, attempt, at, item } => {
                if self.active.get(&id).is_none_or(|active| active.attempt != attempt) {
                    return;
                }
                match item {
                    Item::Event(StreamEvent::Text(text)) => self.on_text(&id, at, &text),
                    Item::Event(StreamEvent::Usage(usage)) => {
                        if let Some(active) = self.active.get_mut(&id) {
                            active.usage = Some(usage);
                        }
                    }
                    Item::Error(error) => self.on_attempt_error(&id, error),
                    Item::End => self.on_attempt_end(&id),
                }
            }
        }
    }

    fn on_text(&mut self, id: &str, at: Instant, text: &str) {
        let Some(active) = self.active.get_mut(id) else {
            return;
        };
        active.provider_first_token_at.get_or_insert(at);
        active.text.push_str(text);
        if active.visibility == Visibility::Upgrade {
            return;
        }
        let Some(release) = active.gate.feed(text) else {
            return;
        };
        self.send_text(id, release);
    }

    fn send_text(&mut self, id: &str, text: String) {
        if text.is_empty() {
            return;
        }
        self.emit(ServerMessage::Token(TokenChunk { suggestion_id: id.to_owned(), text }));
        if let Some(active) = self.active.get_mut(id) {
            active.first_sent_at.get_or_insert_with(Instant::now);
        }
    }

    fn on_attempt_error(&mut self, id: &str, error: ProviderError) {
        let Some(active) = self.active.get(id) else {
            return;
        };
        if active.visibility == Visibility::Upgrade {
            self.finish_upgrade(id, Some(error));
            return;
        }
        if !active.gate.is_open()
            && let Some(fallback) = active.fallback.clone()
        {
            // Nothing reached the card yet: the same suggestion on the fallback model.
            let attempt = active.attempt + 1;
            let config = self.config();
            let max_output_tokens = config.modes.get(active.mode).max_output_tokens;
            let task = self.spawn_attempt(id, attempt, &fallback, &active.context, max_output_tokens, &active.origin);
            let provider = config.model(&fallback).map_or(active.provider, |spec| spec.provider);
            if let Some(active) = self.active.get_mut(id) {
                active.task.abort();
                active.task = task;
                active.attempt = attempt;
                active.model = fallback;
                active.provider = provider;
                active.fallback = None;
                active.fallback_used = true;
                active.text.clear();
                active.gate = SkipGate::default();
                active.usage = None;
                active.provider_first_token_at = None;
            }
            return;
        }
        if active.visibility == Visibility::Shown
            && let Some(fallback) = active.fallback.clone()
        {
            // Words already reached the card: the fallback model answers again as a new suggestion in its place.
            self.replace_on_fallback(id, fallback);
            return;
        }
        self.finish_shown(id, Finish::Error(error));
    }

    /// A shown suggestion broke after its first words (the connection dropped, or the model declined mid-answer). It
    /// ends `superseded`, and the fallback model answers the same request as a new suggestion that `replaces` it, timed
    /// from the same end of turn. The fallback gets no fallback of its own. A Balanced draft's upgrade carries over:
    /// the fallback's answer becomes the draft it may replace, with the same rule as before.
    fn replace_on_fallback(&mut self, id: &str, fallback: String) {
        let Some(active) = self.active.get_mut(id) else {
            return;
        };
        // Taken off the broken draft so that finishing it does not discard the upgrade.
        let upgrade = active.linked.take();
        let (origin, mode, context, t0) = (active.origin.clone(), active.mode, active.context.clone(), active.t0);
        self.finish_shown(id, Finish::Superseded);
        let new_id = self.next_id();
        self.suggestions += 1;
        self.emit(ServerMessage::SuggestionStart(SuggestionStart {
            suggestion_id: new_id.clone(),
            turn_id: origin.turn_id().map(str::to_owned),
            kind: origin.kind(),
            mode,
            model: fallback.clone(),
            speculative: false,
            draft: upgrade.is_some(),
            replaces: Some(id.to_owned()),
        }));
        if let Some(spec) = self.spec.as_mut()
            && spec.suggestion_id == id
        {
            spec.suggestion_id = new_id.clone();
        }
        self.launch(new_id.clone(), Visibility::Shown, origin, mode, fallback, None, context, t0, upgrade.clone());
        if let Some(upgrade) = upgrade.and_then(|upgrade| self.active.get_mut(&upgrade)) {
            upgrade.linked = Some(new_id.clone());
        }
        if let Some(active) = self.active.get_mut(&new_id) {
            active.fallback_used = true;
        }
    }

    fn on_attempt_end(&mut self, id: &str) {
        let Some(active) = self.active.get_mut(id) else {
            return;
        };
        if active.visibility == Visibility::Upgrade {
            self.finish_upgrade(id, None);
            return;
        }
        match active.gate.finish() {
            GateEnd::Skip => self.finish_shown(id, Finish::Skip),
            GateEnd::Flush(rest) => {
                self.send_text(id, rest);
                self.finish_shown(id, Finish::Done);
            }
        }
    }

    /// Ends a shown suggestion: tells the app, records it, charges it.
    fn finish_shown(&mut self, id: &str, finish: Finish) {
        let Some(active) = self.active.remove(id) else {
            return;
        };
        active.task.abort();
        let now = Instant::now();
        let latency = active.latency(now);
        let report = self.charge(&active);
        let (outcome, error) = match finish {
            Finish::Done => {
                self.emit(ServerMessage::SuggestionEnd(SuggestionEnd {
                    suggestion_id: active.id.clone(),
                    status: EndStatus::Done,
                    model: active.model.clone(),
                    latency,
                    usage: report,
                }));
                self.remember(&active);
                (Outcome::Done, None)
            }
            Finish::Skip => {
                self.emit(ServerMessage::Skip(Skip {
                    suggestion_id: active.id.clone(),
                    turn_id: active.origin.turn_id().map(str::to_owned),
                    latency,
                    usage: report,
                }));
                (Outcome::Skip, None)
            }
            Finish::Cancelled | Finish::Superseded => {
                let superseded = matches!(finish, Finish::Superseded);
                self.emit(ServerMessage::SuggestionEnd(SuggestionEnd {
                    suggestion_id: active.id.clone(),
                    status: if superseded { EndStatus::Superseded } else { EndStatus::Cancelled },
                    model: active.model.clone(),
                    latency,
                    usage: report,
                }));
                (if superseded { Outcome::Superseded } else { Outcome::Cancelled }, None)
            }
            Finish::Error(error) => {
                let message = ErrorMessage::new(error.code(), plain_error(error)).for_suggestion(&active.id);
                self.emit(ServerMessage::Error(if retryable(error) { message.retry() } else { message }));
                (Outcome::Error, Some(error.code()))
            }
        };
        if outcome == Outcome::Done {
            self.report_text(&active, None);
        }
        // A draft that will not stay on the card needs no upgrade.
        if outcome != Outcome::Done
            && let Some(upgrade) = &active.linked
        {
            self.discard_upgrade(upgrade);
        }
        self.record(&active, outcome, latency, report, error);
        self.evaluate_mode(Why::Spend);
    }

    /// An upgrade finished (or failed): it replaces its draft if I have not started speaking.
    fn finish_upgrade(&mut self, id: &str, error: Option<ProviderError>) {
        let Some(upgrade) = self.active.remove(id) else {
            return;
        };
        upgrade.task.abort();
        let now = Instant::now();
        let report = self.charge(&upgrade);
        let draft = upgrade.linked.clone().unwrap_or_default();
        let draft_on_card = self.active.contains_key(&draft) || self.history.iter().any(|h| h.id == draft);
        let i_spoke = self.last_me_speech.is_some_and(|at| at > upgrade.t0);
        if error.is_some() || is_skip(&upgrade.text) || upgrade.text.trim().is_empty() || i_spoke || !draft_on_card {
            let outcome = if error.is_some() { Outcome::Error } else { Outcome::Discarded };
            self.record(&upgrade, outcome, upgrade.latency(now), report, error.map(ProviderError::code));
            self.evaluate_mode(Why::Spend);
            return;
        }
        if self.active.contains_key(&draft) {
            self.finish_shown(&draft, Finish::Superseded);
        }
        self.emit(ServerMessage::SuggestionStart(SuggestionStart {
            suggestion_id: upgrade.id.clone(),
            turn_id: upgrade.origin.turn_id().map(str::to_owned),
            kind: SuggestionKind::Upgrade,
            mode: upgrade.mode,
            model: upgrade.model.clone(),
            speculative: false,
            draft: false,
            replaces: Some(draft),
        }));
        self.emit(ServerMessage::Token(TokenChunk { suggestion_id: upgrade.id.clone(), text: upgrade.text.clone() }));
        let mut shown = upgrade;
        shown.first_sent_at = Some(Instant::now());
        let latency = shown.latency(now);
        self.emit(ServerMessage::SuggestionEnd(SuggestionEnd {
            suggestion_id: shown.id.clone(),
            status: EndStatus::Done,
            model: shown.model.clone(),
            latency,
            usage: report,
        }));
        self.remember(&shown);
        self.report_text(&shown, shown.linked.clone());
        self.record(&shown, Outcome::Done, latency, report, None);
        self.evaluate_mode(Why::Spend);
    }

    fn discard_upgrade(&mut self, id: &str) {
        let Some(upgrade) = self.active.remove(id) else {
            return;
        };
        upgrade.task.abort();
        let report = self.charge(&upgrade);
        let latency = upgrade.latency(Instant::now());
        self.record(&upgrade, Outcome::Discarded, latency, report, None);
    }

    /// Stops every suggestion (the connection went, or a new one took over).
    fn cancel_all(&mut self) {
        for id in self.shown_ids() {
            self.finish_shown(&id, Finish::Cancelled);
        }
        let upgrades: Vec<String> = self.active.keys().cloned().collect();
        for id in upgrades {
            self.discard_upgrade(&id);
        }
    }

    /// The words of a suggestion that was shown, for the call's report on the server.
    fn report_text(&self, active: &Active, replaces: Option<String>) {
        let kind = if active.visibility == Visibility::Upgrade { SuggestionKind::Upgrade } else { active.origin.kind() };
        self.deps.reports.report(Report::Suggestion(SuggestionText {
            suggestion_id: active.id.clone(),
            session_id: self.session_id.clone(),
            turn_id: active.origin.turn_id().map(str::to_owned),
            kind,
            mode: active.mode,
            model: active.model.clone(),
            replaces,
            text: active.text.clone(),
            at: iso8601(unix_now()),
        }));
    }

    fn remember(&mut self, active: &Active) {
        self.history.push_back(Finished {
            id: active.id.clone(),
            turn_id: active.origin.turn_id().map(str::to_owned),
            question: active.origin.question().to_owned(),
            text: active.text.clone(),
        });
        while self.history.len() > HISTORY {
            self.history.pop_front();
        }
    }

    /// Telemetry for the log, and the usage record for the server.
    fn record(&self, active: &Active, outcome: Outcome, latency: Latency, usage: UsageReport, error: Option<&'static str>) {
        let trigger = if active.visibility == Visibility::Upgrade { Trigger::Upgrade } else { active.origin.trigger() };
        let kind = if active.visibility == Visibility::Upgrade { SuggestionKind::Upgrade } else { active.origin.kind() };
        self.deps.reports.report(Report::Usage(UsageRecord {
            suggestion_id: active.id.clone(),
            session_id: self.session_id.clone(),
            model: active.model.clone(),
            provider: active.provider,
            mode: active.mode,
            kind,
            trigger: trigger.as_str(),
            outcome: outcome.as_str(),
            fallback: active.fallback_used,
            tokens: UsageRecord::tokens_of(&usage),
            cost_usd: usage.cost_usd,
            estimated: usage.estimated,
            latency,
            at: iso8601(unix_now()),
        }));
        self.deps.telemetry.suggestion(&SuggestionRecord {
            session: self.session_id.clone(),
            suggestion: active.id.clone(),
            trigger,
            outcome,
            mode: active.mode,
            model: active.model.clone(),
            provider: active.provider,
            fallback_used: active.fallback_used,
            latency,
            usage,
            error,
        });
    }

    // -----------------------------------------------------------------------------------------------------------
    // Budget and mode
    // -----------------------------------------------------------------------------------------------------------

    /// Adds what a request cost to the session's spend (kept by the registry across reconnects).
    fn charge(&mut self, active: &Active) -> UsageReport {
        let (usage, estimated) = active.usage();
        let cost = self.config().model(&active.model).map_or(0, |spec| cost_micros(spec, &usage));
        self.add_spend(cost, &active.model);
        UsageReport {
            input_tokens: usage.input_tokens,
            cached_input_tokens: usage.cached_input_tokens,
            cache_write_tokens: usage.cache_write_tokens,
            output_tokens: usage.output_tokens,
            cost_usd: micros_to_usd(cost),
            estimated,
        }
    }

    fn add_spend(&mut self, micros: u64, model: &str) {
        self.spent = self.spent.saturating_add(micros);
        self.model_used_at.insert(model.to_owned(), Instant::now());
        self.save_ledger();
    }

    /// Model budget left on the current token: its `cents_left`, less what was spent since it arrived.
    fn remaining(&self) -> u64 {
        self.claims.budget_micros().saturating_sub(self.spent.saturating_sub(self.ledger.spend_base))
    }

    /// Tokens of the fixed part (rules to memory), built once per context change.
    fn fixed_tokens(&mut self) -> u32 {
        if let Some(tokens) = self.fixed_tokens {
            return tokens;
        }
        let config = self.config();
        let pack = self.deps.pack.get();
        let empty = Transcript::new(&config.context);
        let builder = ContextBuilder { pack: &pack, config: &config.context };
        let context = builder.build(&self.call, &empty, Task::HelpNow { latest: None }, TranscriptScope::Recent);
        let tokens = context.cacheable_tokens();
        self.fixed_tokens = Some(tokens);
        tokens
    }

    fn estimate(&self, mode: Mode, fixed: u32) -> u64 {
        let config = self.config();
        let plan = config.modes.get(mode);
        let ledger: u32 = self.call.facts.iter().map(|f| estimate_tokens(&f.text) + 2).sum::<u32>() + estimate_tokens(&self.call.summary);
        let tokens = fixed + ledger + self.transcript.tokens(plan.transcript) + QUESTION_TOKENS;
        let cacheable = match plan.transcript {
            TranscriptScope::Recent => fixed,
            TranscriptScope::Full => fixed + ledger + self.transcript.full_block_tokens(),
        };
        let output = config.budget.expected_output_tokens;
        let one = |model: &str| -> u64 {
            let Some(spec) = config.model(model) else {
                return u64::MAX / 16;
            };
            let warm = self.model_used_at.get(model).is_some_and(|at| at.elapsed() < CACHE_TTL);
            estimate_micros(spec, tokens, cacheable, warm, output)
        };
        let mut micros = one(&plan.primary);
        if let Some(upgrade) = &plan.upgrade
            && self.model_allowed(upgrade)
        {
            // Only hard questions go to the upgrade model.
            micros = micros.saturating_add((one(upgrade) as f64 * config.budget.upgrade_share).ceil() as u64);
        }
        micros
    }

    fn compute_mode(&mut self) -> (EffectiveMode, Option<Cause>) {
        if self.assist_used_up {
            return (EffectiveMode::CaptionsOnly, None);
        }
        let fixed = self.fixed_tokens();
        let config = self.config();
        let allowed = allowed_modes(&config, &self.claims);
        let reserve = config.budget.reserve_suggestions;
        let (mode, cause) = choose_mode(self.requested, &allowed, self.remaining(), reserve, |mode| self.estimate(mode, fixed));
        (mode, Some(cause))
    }

    fn evaluate_mode(&mut self, why: Why) {
        let (next, cause) = self.compute_mode();
        let previous = self.effective;
        if next == previous {
            return;
        }
        let rank = |mode: EffectiveMode| mode.mode().map_or(0, |m| m as u8 + 1);
        let rising = rank(next) > rank(previous);
        let reason = match cause {
            // The budget had held the mode down and a new token raised it: that is the change, even when the plan
            // now caps how far it goes.
            _ if rising && why == Why::Refresh && self.budget_limited => ModeReason::BudgetRestored,
            None => ModeReason::AssistTime,
            Some(Cause::Budget) => ModeReason::Budget,
            Some(Cause::Plan) => ModeReason::Plan,
            Some(Cause::Requested) if why == Why::Settings => ModeReason::Requested,
            Some(Cause::Requested) if rising => ModeReason::BudgetRestored,
            Some(Cause::Requested) => ModeReason::Requested,
        };
        self.effective = next;
        self.budget_limited = matches!(reason, ModeReason::Budget);
        self.emit(ServerMessage::ModeChanged(ModeChanged { from: previous, to: next, reason }));
        self.deps.telemetry.event(&SessionEvent::ModeChanged {
            session: self.session_id.clone(),
            from: previous,
            to: next,
            reason: reason.as_str(),
        });
    }

    /// When the token's assist time runs out (assist time is wall-clock time with the relay connected).
    fn assist_deadline(&self) -> Option<Instant> {
        if self.assist_used_up || self.connected_since.is_none() {
            return None;
        }
        Some(Instant::now() + self.time_left())
    }

    fn on_assist_time_up(&mut self) {
        self.assist_used_up = true;
        self.evaluate_mode(Why::Spend);
    }

    // -----------------------------------------------------------------------------------------------------------
    // Warm-up
    // -----------------------------------------------------------------------------------------------------------

    /// After the context changes, writes the provider cache for the new fixed part (at most once a minute).
    fn warm(&mut self) {
        let Some(mode) = self.effective.mode() else {
            return;
        };
        if self.conn.is_none() {
            return;
        }
        let config = self.config();
        let interval = Duration::from_secs(config.session.warm_min_interval_secs);
        if self.last_warm.is_some_and(|at| at.elapsed() < interval) {
            return;
        }
        let pack = self.deps.pack.get();
        let plan = config.modes.get(mode);
        let model = plan.primary.clone();
        let Some(spec) = config.model(&model).filter(|_| self.model_allowed(&model)) else {
            return;
        };
        let builder = ContextBuilder { pack: &pack, config: &config.context };
        let context = builder.build(&self.call, &self.transcript, Task::HelpNow { latest: None }, plan.transcript);
        let request = CompletionRequest {
            model: model.clone(),
            context: Arc::new(context),
            max_output_tokens: 0,
            options: spec.options.clone(),
            purpose: Purpose::Warm,
            question: String::new(),
        };
        let provider = self.deps.providers.get(spec.provider);
        let feed = self.feed_tx.clone();
        self.last_warm = Some(Instant::now());
        tokio::spawn(async move {
            let result = provider.warm(request).await;
            let _ = feed.send(Feed::Warmed { model, result }).await;
        });
    }

    fn on_warmed(&mut self, model: String, result: Result<Usage, ProviderError>) {
        let (cost, error) = match result {
            Ok(usage) => (self.config().model(&model).map_or(0, |spec| cost_micros(spec, &usage)), None),
            Err(error) => (0, Some(error.code())),
        };
        if error.is_none() {
            self.add_spend(cost, &model);
        }
        self.deps.telemetry.event(&SessionEvent::Warmed { session: self.session_id.clone(), model, cost_micros: cost, error });
        self.evaluate_mode(Why::Spend);
    }
}

/// The tokio instant at which the token expiring at unix time `exp` stops being valid (with the clock leeway).
fn deadline_for(exp: i64) -> Instant {
    let left = exp.saturating_add(crate::auth::LEEWAY_SECS).saturating_sub(unix_now()).max(0);
    Instant::now() + Duration::from_secs(left.unsigned_abs())
}

/// Whether the same request may work a little later.
fn retryable(error: ProviderError) -> bool {
    matches!(error, ProviderError::Unavailable | ProviderError::RateLimited | ProviderError::Timeout | ProviderError::Protocol)
}

/// The sentence the app may show for a failed suggestion.
fn plain_error(error: ProviderError) -> String {
    match error {
        // No vendor name for the app: the provider is in the log (the suggestion record's `provider`) only.
        ProviderError::NotConfigured(_) => "Live Assist is not set up on the server yet. Ask your admin.".to_owned(),
        ProviderError::Timeout => "The model did not answer in time. Try again.".to_owned(),
        ProviderError::Declined => "The model declined to answer this one.".to_owned(),
        ProviderError::Unavailable | ProviderError::RateLimited | ProviderError::Protocol => {
            "The model is not available right now. Try again in a moment.".to_owned()
        }
    }
}
