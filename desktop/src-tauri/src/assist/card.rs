//! What the Assist card hears and what a window opened mid-session reads back (pure; the `assist` window's side of
//! docs/API_ASSIST.md 1.9.0-g). One event, `assist:message`: the relay's `ready`, `suggestion_start`, `token`,
//! `suggestion_end`, `skip`, `mode_changed` and `error` as the relay sends them without `seq`, and the app's own `turn`
//! (a caption line), `budget` and `ended`. The relay's `ready` does not carry the prep's `meeting_type`, so the app adds
//! it. The final turns also go to the server in batches ([`TurnBatch`]).

use std::collections::{HashSet, VecDeque};
use std::time::{Duration, Instant};

use chrono::{DateTime, Utc};
use serde::Serialize;
use serde_json::{Value, json};

use super::protocol::{Channel, ServerMessage};
use super::turns::CardTurn;

/// The event the card listens to.
pub const EVENT: &str = "assist:message";

/// At most this many messages are kept for a window opened mid-session (a finished suggestion's tokens are kept as one).
pub const MAX_KEPT: usize = 1_000;

/// The card's message for something the relay sent; none for what the card does not need (`token_refreshed`, unknown
/// types). `mode_changed` also says the new mode as `mode`, the field the card reads.
pub fn relay_message(message: &ServerMessage, meeting_type: Option<&str>) -> Option<Value> {
    fn tagged<T: Serialize>(kind: &str, body: &T) -> Value {
        let mut value = serde_json::to_value(body).unwrap_or_else(|_| json!({}));
        if let Some(object) = value.as_object_mut() {
            object.insert("type".into(), kind.into());
        }
        value
    }
    Some(match message {
        ServerMessage::Ready(ready) => {
            let mut value = tagged("ready", ready);
            if let (Some(object), Some(meeting_type)) = (value.as_object_mut(), meeting_type) {
                object.insert("meeting_type".into(), meeting_type.into());
            }
            value
        }
        ServerMessage::SuggestionStart(start) => tagged("suggestion_start", start),
        ServerMessage::Token(token) => tagged("token", token),
        ServerMessage::SuggestionEnd(end) => tagged("suggestion_end", end),
        ServerMessage::Skip(skip) => tagged("skip", skip),
        ServerMessage::Error(error) => tagged("error", error),
        ServerMessage::ModeChanged(change) => {
            let mut value = tagged("mode_changed", change);
            if let Some(object) = value.as_object_mut() {
                object.insert("mode".into(), change.to.clone().into());
            }
            value
        }
        ServerMessage::TokenRefreshed { .. } | ServerMessage::Unknown => return None,
    })
}

fn channel_word(channel: Channel) -> &'static str {
    match channel {
        Channel::Me => "me",
        Channel::Other => "other",
    }
}

/// A caption line on the card.
pub fn turn_message(turn: &CardTurn) -> Value {
    json!({"type": "turn", "turn_id": turn.turn_id, "channel": channel_word(turn.channel), "text": turn.text, "final": turn.is_final})
}

/// The session's assist time: what is left and what the month had (`null` when not known).
pub fn budget_message(seconds_left: Option<u64>, seconds_total: Option<u64>) -> Value {
    json!({"type": "budget", "seconds_left": seconds_left, "seconds_total": seconds_total})
}

/// The session is over; `reason` and `message` say why when it ended by itself (`message` is a plain sentence).
pub fn ended_message(reason: &str, message: Option<&str>) -> Value {
    json!({"type": "ended", "reason": reason, "message": message})
}

/// What `assist_state` gives a window opened mid-session.
#[derive(Clone, Debug, PartialEq, Serialize)]
pub struct Snapshot {
    pub messages: Vec<Value>,
    pub hint_level: String,
    pub paused: bool,
    pub muted_until: Option<String>,
    pub meeting_type: Option<String>,
}

/// The card's side of a running session: the messages so far (bounded), the hint level, pause and mute.
#[derive(Debug)]
pub struct Card {
    messages: VecDeque<Value>,
    pub hint_level: String,
    pub paused: bool,
    pub muted_until: Option<DateTime<Utc>>,
    pub meeting_type: Option<String>,
}

impl Card {
    pub fn new(meeting_type: Option<String>, hint_level: Option<String>) -> Self {
        Card { messages: VecDeque::new(), hint_level: hint_level.unwrap_or_else(|| "outline".into()), paused: false, muted_until: None, meeting_type }
    }

    /// Keeps a message for later windows. A finished suggestion's tokens become one; past [`MAX_KEPT`] the oldest go
    /// (the first `ready` stays).
    pub fn record(&mut self, message: &Value) {
        if message["type"] == "suggestion_end"
            && let Some(id) = message["suggestion_id"].as_str()
        {
            self.join_tokens(id);
        }
        self.messages.push_back(message.clone());
        while self.messages.len() > MAX_KEPT {
            let first_is_ready = self.messages.front().is_some_and(|m| m["type"] == "ready");
            let at = usize::from(first_is_ready);
            self.messages.remove(at);
        }
    }

    fn join_tokens(&mut self, id: &str) {
        let is_token = |m: &Value| m["type"] == "token" && m["suggestion_id"] == id;
        let Some(first) = self.messages.iter().position(is_token) else { return };
        let text: String = self.messages.iter().filter(|m| is_token(m)).filter_map(|m| m["text"].as_str()).collect();
        let mut index = 0;
        self.messages.retain(|m| {
            let keep = index == first || !is_token(m);
            index += 1;
            keep
        });
        self.messages[first] = json!({"type": "token", "suggestion_id": id, "text": text});
    }

    /// Muted until `now` + `seconds`.
    pub fn mute(&mut self, seconds: u64, now: DateTime<Utc>) {
        self.muted_until = Some(now + chrono::Duration::seconds(seconds.min(24 * 3600) as i64));
    }

    /// No automatic suggestions now: paused, or muted until a time still ahead.
    pub fn quiet(&self, now: DateTime<Utc>) -> bool {
        self.paused || self.muted_until.is_some_and(|until| until > now)
    }

    pub fn snapshot(&self, now: DateTime<Utc>) -> Snapshot {
        Snapshot {
            messages: self.messages.iter().cloned().collect(),
            hint_level: self.hint_level.clone(),
            paused: self.paused,
            muted_until: self.muted_until.filter(|until| *until > now).map(|until| until.to_rfc3339()),
            meeting_type: self.meeting_type.clone(),
        }
    }
}

/// One final turn for `POST /api/assist/sessions/{id}/turns`.
#[derive(Clone, PartialEq, Eq, Serialize)]
pub struct TurnRow {
    pub client_ref: String,
    pub channel: &'static str,
    pub start_ms: u64,
    pub end_ms: u64,
    pub text: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub language: Option<String>,
}

/// The words stay out of logs.
impl std::fmt::Debug for TurnRow {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "TurnRow({}, <{} chars>)", self.client_ref, self.text.chars().count())
    }
}

impl TurnRow {
    /// A final caption line of session `session_id`; its `client_ref` is the same however often it is sent.
    pub fn of(session_id: &str, turn: &CardTurn) -> Option<Self> {
        turn.is_final.then(|| TurnRow {
            client_ref: format!("{session_id}:{}", turn.turn_id),
            channel: channel_word(turn.channel),
            start_ms: turn.start_ms.unwrap_or(0),
            end_ms: turn.end_ms.unwrap_or(0),
            text: turn.text.clone(),
            language: None,
        })
    }
}

/// Final turns wait here and go to the server every 5 s or 10 at a time; a `client_ref` already accepted is never sent
/// again, and a batch that failed goes back first in line.
#[derive(Debug)]
pub struct TurnBatch {
    waiting: Vec<TurnRow>,
    accepted: HashSet<String>,
    last_sent: Instant,
}

/// A batch goes at the latest this long after the last one.
pub const BATCH_EVERY: Duration = Duration::from_secs(5);
/// A batch goes at once when this many turns wait.
pub const BATCH_SIZE: usize = 10;

impl TurnBatch {
    pub fn new(now: Instant) -> Self {
        TurnBatch { waiting: Vec::new(), accepted: HashSet::new(), last_sent: now }
    }

    /// Adds a turn (one already accepted or waiting is ignored); true when a batch should go now.
    pub fn push(&mut self, row: TurnRow) -> bool {
        if !self.accepted.contains(&row.client_ref) && !self.waiting.iter().any(|w| w.client_ref == row.client_ref) {
            self.waiting.push(row);
        }
        self.waiting.len() >= BATCH_SIZE
    }

    /// Turns wait and the last batch went [`BATCH_EVERY`] ago (or they are [`BATCH_SIZE`]).
    pub fn due(&self, now: Instant) -> bool {
        !self.waiting.is_empty() && (self.waiting.len() >= BATCH_SIZE || now.duration_since(self.last_sent) >= BATCH_EVERY)
    }

    /// The next batch (at most [`BATCH_SIZE`] turns), taken out of the queue.
    pub fn take(&mut self, now: Instant) -> Vec<TurnRow> {
        self.last_sent = now;
        let n = self.waiting.len().min(BATCH_SIZE);
        self.waiting.drain(..n).collect()
    }

    /// The server accepted the batch.
    pub fn accepted(&mut self, rows: &[TurnRow]) {
        self.accepted.extend(rows.iter().map(|r| r.client_ref.clone()));
    }

    /// The batch did not reach the server: it goes back first in line.
    pub fn failed(&mut self, rows: Vec<TurnRow>) {
        let mut back = rows;
        back.retain(|r| !self.accepted.contains(&r.client_ref));
        back.append(&mut self.waiting);
        self.waiting = back;
    }

    pub fn is_empty(&self) -> bool {
        self.waiting.is_empty()
    }
}

/// The assist time of the session: what the server said was left at the start, counted down by the session's wall-clock
/// time (an assist-hour is wall-clock time with the relay connected; the relay is connected for nearly all of it).
#[derive(Clone, Copy, Debug)]
pub struct Budget {
    pub left_at_start: Option<u64>,
    pub total: Option<u64>,
    pub started: Instant,
}

impl Budget {
    pub fn message(&self, now: Instant) -> Value {
        let spent = now.duration_since(self.started).as_secs();
        budget_message(self.left_at_start.map(|left| left.saturating_sub(spent)), self.total)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::assist::protocol::{EndStatus, ModeChanged, ModeReason, Ready, RelayError, SuggestionEnd, Token};

    fn turn(id: &str, channel: Channel, text: &str, is_final: bool) -> CardTurn {
        CardTurn { turn_id: id.into(), channel, text: text.into(), is_final, start_ms: is_final.then_some(1_000), end_ms: is_final.then_some(2_500) }
    }

    #[test]
    fn the_relay_s_messages_reach_the_card_as_sent_without_seq() {
        let ready = Ready { protocol: 1, session_id: "as-1".into(), mode: "balanced".into(), requested_mode: "balanced".into(), allowed_modes: vec!["balanced".into(), "fast".into()], expires_at: None, resumed: false, practice: false, limits: None };
        let sent = relay_message(&ServerMessage::Ready(ready.clone()), Some("interview")).unwrap();
        assert_eq!((sent["type"].as_str(), sent["meeting_type"].as_str(), sent["requested_mode"].as_str()), (Some("ready"), Some("interview"), Some("balanced")));
        assert!(sent.get("seq").is_none());
        assert!(relay_message(&ServerMessage::Ready(ready), None).unwrap().get("meeting_type").is_none());
        let token = relay_message(&ServerMessage::Token(Token { suggestion_id: "s1".into(), text: "Yes".into() }), None).unwrap();
        assert_eq!(token, json!({"type": "token", "suggestion_id": "s1", "text": "Yes"}));
        let end = relay_message(&ServerMessage::SuggestionEnd(SuggestionEnd { suggestion_id: "s1".into(), status: EndStatus::Superseded, model: "m".into(), latency: json!({}), usage: json!({}) }), None).unwrap();
        assert_eq!(end["status"], "superseded");
        let changed = relay_message(&ServerMessage::ModeChanged(ModeChanged { from: "deep".into(), to: "fast".into(), reason: ModeReason::Requested, requested: Some("fast".into()) }), None).unwrap();
        assert_eq!(changed, json!({"type": "mode_changed", "from": "deep", "to": "fast", "mode": "fast", "reason": "requested", "requested": "fast"}));
        let error = relay_message(&ServerMessage::Error(RelayError { code: "provider_error".into(), message: "The suggestion failed.".into(), retry: true, suggestion_id: Some("s2".into()), in_reply_to: None, fatal: false }), None).unwrap();
        assert_eq!(error, json!({"type": "error", "code": "provider_error", "message": "The suggestion failed.", "retry": true, "suggestion_id": "s2", "in_reply_to": null, "fatal": false}));
        assert_eq!(relay_message(&ServerMessage::TokenRefreshed { expires_at: "x".into() }, None), None);
        assert_eq!(relay_message(&ServerMessage::Unknown, None), None);
    }

    #[test]
    fn the_app_s_own_messages_have_the_card_s_shapes() {
        assert_eq!(turn_message(&turn("o-3", Channel::Other, "When can you start?", true)), json!({"type": "turn", "turn_id": "o-3", "channel": "other", "text": "When can you start?", "final": true}));
        assert_eq!(budget_message(Some(600), None), json!({"type": "budget", "seconds_left": 600, "seconds_total": null}));
        assert_eq!(ended_message("closed", None), json!({"type": "ended", "reason": "closed", "message": null}));
        let started = Instant::now();
        let budget = Budget { left_at_start: Some(100), total: Some(36_000), started };
        assert_eq!(budget.message(started + Duration::from_secs(30))["seconds_left"], 70);
        assert_eq!(budget.message(started + Duration::from_secs(300))["seconds_left"], 0, "never below zero");
    }

    #[test]
    fn a_window_opened_mid_session_reads_the_session_back_compactly() {
        let mut card = Card::new(Some("interview".into()), Some("full".into()));
        card.record(&json!({"type": "ready", "session_id": "as-1"}));
        for text in ["Use ", "the ", "dispute."] {
            card.record(&json!({"type": "token", "suggestion_id": "s1", "text": text}));
        }
        card.record(&json!({"type": "turn", "turn_id": "o-1", "channel": "other", "text": "Hi", "final": false}));
        card.record(&json!({"type": "suggestion_end", "suggestion_id": "s1", "status": "done"}));
        let now = Utc::now();
        card.mute(60, now);
        let snap = card.snapshot(now);
        let kinds: Vec<&str> = snap.messages.iter().filter_map(|m| m["type"].as_str()).collect();
        assert_eq!(kinds, ["ready", "token", "turn", "suggestion_end"], "a finished suggestion's tokens are one");
        assert_eq!(snap.messages[1]["text"], "Use the dispute.");
        assert_eq!((snap.hint_level.as_str(), snap.meeting_type.as_deref()), ("full", Some("interview")));
        assert!(snap.muted_until.is_some() && card.quiet(now));
        assert!(!card.quiet(now + chrono::Duration::seconds(61)), "the mute ends");
        assert_eq!(card.snapshot(now + chrono::Duration::seconds(61)).muted_until, None);
        for i in 0..(MAX_KEPT + 5) {
            card.record(&json!({"type": "turn", "turn_id": format!("o-{i}"), "channel": "other", "text": "x", "final": true}));
        }
        let snap = card.snapshot(now);
        assert_eq!(snap.messages.len(), MAX_KEPT);
        assert_eq!(snap.messages[0]["type"], "ready", "the first ready stays");
    }

    #[test]
    fn final_turns_go_every_5_s_or_10_at_a_time_never_twice() {
        let start = Instant::now();
        let mut batch = TurnBatch::new(start);
        assert!(TurnRow::of("as-1", &turn("o-1", Channel::Other, "Hi", false)).is_none(), "partials stay on the card");
        let row = |n: usize| TurnRow::of("as-1", &turn(&format!("o-{n}"), Channel::Other, "Hi", true)).unwrap();
        assert_eq!(row(1).client_ref, "as-1:o-1");
        assert!(!batch.push(row(1)));
        assert!(!batch.push(row(1)), "the same turn waits once");
        assert!(!batch.due(start + Duration::from_secs(4)));
        assert!(batch.due(start + BATCH_EVERY));
        let sent = batch.take(start + BATCH_EVERY);
        assert_eq!(sent.len(), 1);
        batch.accepted(&sent);
        assert!(!batch.push(row(1)), "an accepted turn is never sent again");
        assert!(batch.is_empty());
        let full = (2..=11).map(|n| batch.push(row(n))).collect::<Vec<_>>();
        assert_eq!(full.last(), Some(&true), "ten wait: a batch goes now");
        let later = start + Duration::from_secs(6);
        assert!(batch.due(later));
        let ten = batch.take(later);
        assert_eq!(ten.len(), BATCH_SIZE);
        batch.push(row(12));
        batch.failed(ten);
        let again = batch.take(later + BATCH_EVERY);
        assert_eq!(again.first().map(|r| r.client_ref.as_str()), Some("as-1:o-2"), "a failed batch goes first");
        assert_eq!(serde_json::to_value(&again[0]).unwrap(), json!({"client_ref": "as-1:o-2", "channel": "other", "start_ms": 1000, "end_ms": 2500, "text": "Hi"}));
        assert!(!format!("{:?}", again[0]).contains("Hi"), "the words stay out of logs");
    }
}
