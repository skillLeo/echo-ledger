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

/// The app's sentence for every relay `error` code (the D-135 rule: the app's own words, never the relay's text;
/// contract 1.9.0-n "What the apps must handle"). `budget_exhausted` says two things: with `retry` the budget gate
/// held it for now, without it suggestions are off.
pub fn error_sentence(code: &str, retry: bool) -> &'static str {
    match code {
        "budget_exhausted" if retry => "Live Assist is busy. Try again in a moment.",
        "budget_exhausted" => "Suggestions are off: this month's Live Assist time is used up. Captions go on.",
        "rate_limited" => "Live Assist is busy. Try again in a moment.",
        "documents_too_large" => "The prep's documents are too long for Live Assist, so it kept the earlier ones. Remove some, then try again.",
        "nothing_to_rewrite" => "There's no suggestion to change yet.",
        "provider_not_configured" => "Live Assist is not set up on the server yet. Ask your admin.",
        "provider_unavailable" | "provider_timeout" => "Live Assist couldn't make this suggestion. Try again, or press Help now.",
        "model_declined" => "Live Assist couldn't help with this one.",
        "bad_message" => "Live Assist couldn't read one request. Try that again.",
        "token_refresh_rejected" | "token_expired" | "token_missing" | "token_invalid" => "Live Assist is renewing its access.",
        "wrong_session" => "This Live Assist session belongs to another account.",
        "app_update_required" => "Live Assist needs the latest version of the app.",
        "relay_unavailable" | "unavailable" => "Live Assist can't connect right now. Try again in a few minutes.",
        _ => "Live Assist couldn't make this suggestion. Try again.",
    }
}

/// Errors the app handles itself, which the card never shows: new tokens and reconnects (the session ends in plain
/// words if they fail for good).
pub fn handled_by_app(code: &str) -> bool {
    matches!(code, "token_refresh_rejected" | "token_expired" | "token_missing" | "token_invalid" | "wrong_session")
}

/// The card's message for something the relay sent, its fields as the relay sends them (without `seq`); none for what
/// the card does not need (`token_refreshed`, unknown types, errors the app handles). An `error`'s `message` is the
/// app's sentence for its code ([`error_sentence`]), never the relay's text.
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
        ServerMessage::Error(error) if handled_by_app(&error.code) => return None,
        ServerMessage::Error(error) => tagged("error", &super::protocol::RelayError { message: error_sentence(&error.code, error.retry).to_string(), ..error.clone() }),
        ServerMessage::ModeChanged(change) => tagged("mode_changed", change),
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
        // A resumed connection: a suggestion running when it dropped gets no end (1.9.0-n), so it leaves the card.
        if message["type"] == "ready" && message["resumed"] == true {
            self.drop_unfinished();
        }
        self.messages.push_back(message.clone());
        while self.messages.len() > MAX_KEPT {
            let first_is_ready = self.messages.front().is_some_and(|m| m["type"] == "ready");
            let at = usize::from(first_is_ready);
            self.messages.remove(at);
        }
    }

    /// The suggestions that started and never ended (`suggestion_end`, `skip` or an `error` naming them) leave.
    fn drop_unfinished(&mut self) {
        let ended: HashSet<String> = self
            .messages
            .iter()
            .filter(|m| matches!(m["type"].as_str(), Some("suggestion_end" | "skip" | "error")))
            .filter_map(|m| m["suggestion_id"].as_str().map(str::to_string))
            .collect();
        self.messages.retain(|m| {
            let streaming = matches!(m["type"].as_str(), Some("suggestion_start" | "token"));
            !streaming || m["suggestion_id"].as_str().is_some_and(|id| ended.contains(id))
        });
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
    /// A final caption line of session `session_id`; its `client_ref` is the same however often it is sent. The other
    /// person's turn is named by the relay's `turn_id` itself (1.9.0-j: the report pairs each hint with its question);
    /// the microphone's by the app's own ref. Turn ids never restart inside a session: the turns feed lives as long as
    /// the session, across relay reconnects.
    pub fn of(session_id: &str, turn: &CardTurn) -> Option<Self> {
        turn.is_final.then(|| TurnRow {
            client_ref: match turn.channel {
                Channel::Other => turn.turn_id.clone(),
                Channel::Me => format!("{session_id}:{}", turn.turn_id),
            },
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
    /// What a token refresh says is left now: counted from `now` on (the total stays unless it says one).
    pub fn renewed(&self, plan: &crate::net::models::AssistPlanDto, now: Instant) -> Budget {
        match plan.seconds_left {
            Some(left) => Budget { left_at_start: Some(left), total: plan.seconds_total.or(self.total), started: now },
            None => *self,
        }
    }

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
        assert_eq!(changed, json!({"type": "mode_changed", "from": "deep", "to": "fast", "reason": "requested", "requested": "fast"}), "as the relay sends it");
        let error = |code: &str| RelayError { code: code.into(), message: "RELAY TEXT from the provider".into(), retry: true, suggestion_id: Some("s2".into()), in_reply_to: None, fatal: false };
        let sent = relay_message(&ServerMessage::Error(error("provider_timeout")), None).unwrap();
        assert_eq!(sent, json!({"type": "error", "code": "provider_timeout", "message": "Live Assist couldn't make this suggestion. Try again, or press Help now.", "retry": true, "suggestion_id": "s2", "in_reply_to": null, "fatal": false}));
        // Every code of contract 1.9.0-n has the app's sentence.
        for code in ["rate_limited", "documents_too_large", "nothing_to_rewrite", "budget_exhausted", "provider_not_configured", "provider_unavailable", "provider_timeout", "model_declined", "bad_message", "something_new"] {
            let shown = relay_message(&ServerMessage::Error(error(code)), None).unwrap();
            assert_eq!(shown["code"], code);
            assert!(!shown["message"].as_str().unwrap().contains("RELAY TEXT"), "{code}: never the relay's text");
            assert_eq!(shown["message"].as_str(), Some(error_sentence(code, true)));
        }
        for handled in ["token_refresh_rejected", "token_expired", "token_missing", "token_invalid", "wrong_session"] {
            assert!(!error_sentence(handled, false).is_empty());
            assert_eq!(relay_message(&ServerMessage::Error(error(handled)), None), None, "{handled}: the app handles it");
        }
        // The two kinds of budget_exhausted.
        let held = RelayError { retry: true, ..error("budget_exhausted") };
        assert_eq!(relay_message(&ServerMessage::Error(held), None).unwrap()["message"], "Live Assist is busy. Try again in a moment.");
        let off = RelayError { retry: false, ..error("budget_exhausted") };
        assert_eq!(relay_message(&ServerMessage::Error(off), None).unwrap()["message"], "Suggestions are off: this month's Live Assist time is used up. Captions go on.");
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
        // A token refresh says what is left now.
        let later = started + Duration::from_secs(600);
        let plan = crate::net::models::AssistPlanDto { modes: vec![], seconds_left: Some(5_000), seconds_total: None };
        let renewed = budget.renewed(&plan, later);
        assert_eq!(renewed.message(later + Duration::from_secs(10)), json!({"type": "budget", "seconds_left": 4990, "seconds_total": 36000}));
        let silent = crate::net::models::AssistPlanDto { modes: vec![], seconds_left: None, seconds_total: None };
        assert_eq!(budget.renewed(&silent, later).message(later)["seconds_left"], 0, "a refresh without a plan changes nothing");
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
    fn a_resumed_connection_drops_the_suggestion_that_was_streaming() {
        let mut card = Card::new(None, None);
        card.record(&json!({"type": "ready", "resumed": false}));
        card.record(&json!({"type": "suggestion_start", "suggestion_id": "s1"}));
        card.record(&json!({"type": "token", "suggestion_id": "s1", "text": "Done."}));
        card.record(&json!({"type": "suggestion_end", "suggestion_id": "s1", "status": "done"}));
        card.record(&json!({"type": "suggestion_start", "suggestion_id": "s2"}));
        card.record(&json!({"type": "skip", "suggestion_id": "s2"}));
        card.record(&json!({"type": "suggestion_start", "suggestion_id": "s3"}));
        card.record(&json!({"type": "token", "suggestion_id": "s3", "text": "Half"}));
        card.record(&json!({"type": "ready", "resumed": true}));
        let ids: Vec<String> = card.snapshot(Utc::now()).messages.iter().filter_map(|m| m["suggestion_id"].as_str().map(str::to_string)).collect();
        assert!(!ids.contains(&"s3".to_string()), "s3 was streaming when the connection dropped: {ids:?}");
        assert!(ids.contains(&"s1".to_string()) && ids.contains(&"s2".to_string()), "finished ones stay: {ids:?}");
    }

    #[test]
    fn final_turns_go_every_5_s_or_10_at_a_time_never_twice() {
        let start = Instant::now();
        let mut batch = TurnBatch::new(start);
        assert!(TurnRow::of("as-1", &turn("o-1", Channel::Other, "Hi", false)).is_none(), "partials stay on the card");
        let row = |n: usize| TurnRow::of("as-1", &turn(&format!("o-{n}"), Channel::Other, "Hi", true)).unwrap();
        assert_eq!(row(1).client_ref, "o-1", "the relay's turn_id itself");
        let mine = TurnRow::of("as-1", &turn("m-4", Channel::Me, "Sure.", true)).unwrap();
        assert_eq!(mine.client_ref, "as-1:m-4", "the app's own ref");
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
        assert_eq!(again.first().map(|r| r.client_ref.as_str()), Some("o-2"), "a failed batch goes first");
        assert_eq!(serde_json::to_value(&again[0]).unwrap(), json!({"client_ref": "o-2", "channel": "other", "start_ms": 1000, "end_ms": 2500, "text": "Hi"}));
        assert!(!format!("{:?}", again[0]).contains("Hi"), "the words stay out of logs");
    }
}
