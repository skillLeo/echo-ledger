//! The relay's WebSocket messages (docs/API_ASSIST.md 1.9.0-c "WebSocket (relay)", with the exact shapes of
//! services/assist-relay's protocol). One JSON object per text frame, named by `type`, with a per-direction `seq`.
//!
//! Turns, documents, the brief and suggestion text are what people said and wrote: no `Debug` here prints them, so a
//! stray `{:?}` can never put call content in a log.

use std::fmt;

use serde::{Deserialize, Serialize};

/// Which side said it: `me` (the person using EchoLedger) never starts a suggestion.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Channel {
    Me,
    Other,
}

/// The prep's brief, as `context_update.brief`.
#[derive(Clone, Default, PartialEq, Eq, Serialize)]
pub struct Brief {
    pub meeting_type: String,
    #[serde(skip_serializing_if = "String::is_empty")]
    pub title: String,
    #[serde(skip_serializing_if = "String::is_empty")]
    pub counterpart: String,
    #[serde(skip_serializing_if = "String::is_empty")]
    pub company: String,
    #[serde(skip_serializing_if = "String::is_empty")]
    pub goals: String,
    #[serde(skip_serializing_if = "String::is_empty")]
    pub done: String,
    #[serde(skip_serializing_if = "String::is_empty")]
    pub not_done: String,
    #[serde(skip_serializing_if = "String::is_empty")]
    pub to_discuss: String,
    #[serde(skip_serializing_if = "String::is_empty")]
    pub instructions: String,
}

/// `context_update.settings`: `mode` `fast` | `balanced` | `deep` | `max`; `hint_level` `keywords` | `outline` |
/// `full`; `language_help` `off` | `urdu` | `roman_urdu` | `arabic`; `assist` `on` | `paused`.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
pub struct Settings {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub mode: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub hint_level: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub language_help: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub assist: Option<String>,
}

#[derive(Clone, PartialEq, Eq, Serialize)]
pub struct Document {
    pub id: String,
    pub title: String,
    pub text: String,
}

#[derive(Clone, PartialEq, Eq, Serialize)]
pub struct Fact {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub id: Option<String>,
    pub text: String,
}

/// Everything about the call except the transcript; a field that is present replaces what the relay had.
#[derive(Clone, Default, PartialEq, Eq, Serialize)]
pub struct ContextUpdate {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub brief: Option<Brief>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub settings: Option<Settings>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub documents: Option<Vec<Document>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub memory: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub facts: Option<Vec<Fact>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub summary: Option<String>,
}

/// One turn of the speech engine's text; partials and the final of a turn share `turn_id`.
#[derive(Clone, PartialEq, Eq, Serialize)]
pub struct Turn {
    pub turn_id: String,
    pub channel: Channel,
    pub text: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub language: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub start_ms: Option<u64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub end_ms: Option<u64>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub silence_ms: Option<u64>,
}

/// App → relay.
#[derive(Clone, PartialEq, Eq, Serialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum ClientMessage {
    ContextUpdate(Box<ContextUpdate>),
    TurnPartial(Turn),
    TurnFinal(Turn),
    HelpNow {
        #[serde(skip_serializing_if = "Option::is_none")]
        turn_id: Option<String>,
    },
    Regenerate {
        #[serde(skip_serializing_if = "Option::is_none")]
        suggestion_id: Option<String>,
    },
    Shorter {
        #[serde(skip_serializing_if = "Option::is_none")]
        suggestion_id: Option<String>,
    },
    Formal {
        #[serde(skip_serializing_if = "Option::is_none")]
        suggestion_id: Option<String>,
    },
    Translate {
        #[serde(skip_serializing_if = "Option::is_none")]
        suggestion_id: Option<String>,
        language: String,
    },
    Cancel {
        #[serde(skip_serializing_if = "Option::is_none")]
        suggestion_id: Option<String>,
    },
    TokenRefresh {
        token: String,
    },
}

impl ClientMessage {
    pub fn kind(&self) -> &'static str {
        match self {
            Self::ContextUpdate(_) => "context_update",
            Self::TurnPartial(_) => "turn_partial",
            Self::TurnFinal(_) => "turn_final",
            Self::HelpNow { .. } => "help_now",
            Self::Regenerate { .. } => "regenerate",
            Self::Shorter { .. } => "shorter",
            Self::Formal { .. } => "formal",
            Self::Translate { .. } => "translate",
            Self::Cancel { .. } => "cancel",
            Self::TokenRefresh { .. } => "token_refresh",
        }
    }

    /// The frame for the socket: the message with this direction's `seq`.
    pub fn frame(&self, seq: u64) -> String {
        let mut value = serde_json::to_value(self).unwrap_or_default();
        if let Some(object) = value.as_object_mut() {
            object.insert("seq".into(), seq.into());
        }
        value.to_string()
    }
}

/// The type only: never the words, the brief, the documents or a token.
impl fmt::Debug for ClientMessage {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "ClientMessage({})", self.kind())
    }
}

/// `ready`: the first message on every connection.
#[derive(Clone, Debug, PartialEq, Eq, Deserialize)]
pub struct Ready {
    #[serde(default)]
    pub protocol: u32,
    #[serde(default)]
    pub session_id: String,
    /// `fast` | `balanced` | `deep` | `max` | `captions_only`.
    #[serde(default)]
    pub mode: String,
    #[serde(default)]
    pub allowed_modes: Vec<String>,
    #[serde(default)]
    pub expires_at: Option<String>,
    #[serde(default)]
    pub resumed: bool,
    #[serde(default)]
    pub practice: bool,
}

#[derive(Clone, Debug, PartialEq, Eq, Deserialize)]
pub struct SuggestionStart {
    pub suggestion_id: String,
    #[serde(default)]
    pub turn_id: Option<String>,
    #[serde(default)]
    pub kind: String,
    #[serde(default)]
    pub mode: String,
    #[serde(default)]
    pub model: String,
    #[serde(default)]
    pub speculative: bool,
    #[serde(default)]
    pub draft: bool,
    #[serde(default)]
    pub replaces: Option<String>,
}

#[derive(Clone, PartialEq, Eq, Deserialize)]
pub struct Token {
    pub suggestion_id: String,
    pub text: String,
}

impl fmt::Debug for Token {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "Token({}, <{} chars>)", self.suggestion_id, self.text.chars().count())
    }
}

#[derive(Clone, Debug, PartialEq, Deserialize)]
pub struct SuggestionEnd {
    pub suggestion_id: String,
    /// `done` | `cancelled` | `superseded`.
    #[serde(default)]
    pub status: String,
    #[serde(default)]
    pub model: String,
    #[serde(default)]
    pub latency: serde_json::Value,
    #[serde(default)]
    pub usage: serde_json::Value,
}

#[derive(Clone, Debug, PartialEq, Deserialize)]
pub struct Skip {
    pub suggestion_id: String,
    #[serde(default)]
    pub turn_id: Option<String>,
}

#[derive(Clone, Debug, PartialEq, Eq, Deserialize)]
pub struct RelayError {
    pub code: String,
    #[serde(default)]
    pub message: String,
    #[serde(default)]
    pub retry: bool,
    #[serde(default)]
    pub suggestion_id: Option<String>,
    #[serde(default)]
    pub in_reply_to: Option<u64>,
    /// The relay closes the connection next.
    #[serde(default)]
    pub fatal: bool,
}

#[derive(Clone, Debug, PartialEq, Eq, Deserialize)]
pub struct ModeChanged {
    pub from: String,
    pub to: String,
    /// `requested` | `plan` | `budget` | `budget_restored` | `assist_time`.
    #[serde(default)]
    pub reason: String,
}

/// Relay → app. A type this app does not know yet is `Unknown` and is ignored.
#[derive(Clone, Debug, PartialEq, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum ServerMessage {
    Ready(Ready),
    SuggestionStart(SuggestionStart),
    Token(Token),
    SuggestionEnd(SuggestionEnd),
    Skip(Skip),
    Error(RelayError),
    ModeChanged(ModeChanged),
    TokenRefreshed {
        expires_at: String,
    },
    #[serde(other)]
    Unknown,
}

/// A frame from the relay: its `seq` and the message; none for a frame that is not a JSON object with a `type`.
pub fn parse(frame: &str) -> Option<(Option<u64>, ServerMessage)> {
    let value: serde_json::Value = serde_json::from_str(frame).ok()?;
    let seq = value.get("seq").and_then(serde_json::Value::as_u64);
    let message = serde_json::from_value(value).ok()?;
    Some((seq, message))
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn sent(message: &ClientMessage, seq: u64) -> serde_json::Value {
        serde_json::from_str(&message.frame(seq)).unwrap()
    }

    #[test]
    fn the_app_s_messages_have_the_relay_s_shapes_and_a_seq() {
        let turn = Turn { turn_id: "o-12".into(), channel: Channel::Other, text: "So what is your notice period?".into(), language: Some("en".into()), start_ms: Some(731_200), end_ms: Some(733_900), silence_ms: None };
        assert_eq!(
            sent(&ClientMessage::TurnFinal(turn.clone()), 7),
            json!({"type": "turn_final", "seq": 7, "turn_id": "o-12", "channel": "other", "text": "So what is your notice period?", "language": "en", "start_ms": 731200, "end_ms": 733900})
        );
        let partial = Turn { channel: Channel::Me, silence_ms: Some(140), end_ms: None, ..turn };
        assert_eq!(sent(&ClientMessage::TurnPartial(partial), 8)["channel"], "me");
        assert_eq!(sent(&ClientMessage::HelpNow { turn_id: None }, 9), json!({"type": "help_now", "seq": 9}));
        assert_eq!(sent(&ClientMessage::Translate { suggestion_id: Some("1f3a-7".into()), language: "urdu".into() }, 10), json!({"type": "translate", "seq": 10, "suggestion_id": "1f3a-7", "language": "urdu"}));
        assert_eq!(sent(&ClientMessage::Shorter { suggestion_id: None }, 11), json!({"type": "shorter", "seq": 11}));
        assert_eq!(sent(&ClientMessage::Cancel { suggestion_id: None }, 12), json!({"type": "cancel", "seq": 12}));
        assert_eq!(sent(&ClientMessage::TokenRefresh { token: "t2".into() }, 13), json!({"type": "token_refresh", "seq": 13, "token": "t2"}));
        let update = ClientMessage::ContextUpdate(Box::new(ContextUpdate {
            brief: Some(Brief { meeting_type: "interview".into(), title: "Backend role".into(), ..Brief::default() }),
            settings: Some(Settings { mode: Some("balanced".into()), ..Settings::default() }),
            facts: Some(vec![Fact { id: None, text: "Budget: 5,000 USD".into() }]),
            ..ContextUpdate::default()
        }));
        assert_eq!(
            sent(&update, 1),
            json!({"type": "context_update", "seq": 1, "brief": {"meeting_type": "interview", "title": "Backend role"}, "settings": {"mode": "balanced"}, "facts": [{"text": "Budget: 5,000 USD"}]})
        );
    }

    #[test]
    fn the_relay_s_messages_are_read_and_unknown_ones_ignored() {
        let (seq, ready) = parse(r#"{"type":"ready","seq":1,"protocol":1,"session_id":"01JS","mode":"balanced","requested_mode":"balanced","allowed_modes":["balanced","fast"],"expires_at":"2026-11-16T09:15:00+00:00","resumed":false,"practice":false,"limits":{"max_message_bytes":2097152}}"#).unwrap();
        assert_eq!(seq, Some(1));
        assert!(matches!(ready, ServerMessage::Ready(ref r) if r.mode == "balanced" && r.allowed_modes.len() == 2 && !r.resumed));
        let (_, start) = parse(r#"{"type":"suggestion_start","seq":2,"suggestion_id":"1f3a-7","turn_id":"o-12","kind":"answer","mode":"balanced","model":"m","speculative":true,"draft":true,"replaces":null}"#).unwrap();
        assert!(matches!(start, ServerMessage::SuggestionStart(ref s) if s.speculative && s.draft && s.kind == "answer"));
        assert!(matches!(parse(r#"{"type":"token","seq":3,"suggestion_id":"1f3a-7","text":"Yes, I "}"#).unwrap().1, ServerMessage::Token(ref t) if t.text == "Yes, I "));
        let (_, end) = parse(r#"{"type":"suggestion_end","seq":4,"suggestion_id":"1f3a-7","status":"done","model":"m","latency":{"total_ms":702.5},"usage":{"output_tokens":31}}"#).unwrap();
        assert!(matches!(end, ServerMessage::SuggestionEnd(ref e) if e.status == "done" && e.latency["total_ms"] == 702.5));
        assert!(matches!(parse(r#"{"type":"skip","seq":5,"suggestion_id":"x","turn_id":"o-1","latency":{},"usage":{}}"#).unwrap().1, ServerMessage::Skip(_)));
        let (_, error) = parse(r#"{"type":"error","seq":6,"code":"token_expired","message":"…","retry":false,"suggestion_id":null,"in_reply_to":null,"fatal":true}"#).unwrap();
        assert!(matches!(error, ServerMessage::Error(ref e) if e.code == "token_expired" && e.fatal));
        assert!(matches!(parse(r#"{"type":"mode_changed","seq":7,"from":"deep","to":"balanced","reason":"budget"}"#).unwrap().1, ServerMessage::ModeChanged(ref m) if m.to == "balanced"));
        assert!(matches!(parse(r#"{"type":"token_refreshed","seq":8,"expires_at":"x"}"#).unwrap().1, ServerMessage::TokenRefreshed { .. }));
        assert_eq!(parse(r#"{"type":"something_new","seq":9,"x":1}"#).unwrap().1, ServerMessage::Unknown);
        assert!(parse("not json").is_none());
        assert!(parse(r#"{"no":"type"}"#).is_none());
    }

    #[test]
    fn debug_output_never_shows_words_or_tokens() {
        let turn = ClientMessage::TurnFinal(Turn { turn_id: "t".into(), channel: Channel::Other, text: "SECRET-TURN".into(), language: None, start_ms: None, end_ms: None, silence_ms: None });
        let refresh = ClientMessage::TokenRefresh { token: "SECRET-TOKEN".into() };
        let token = Token { suggestion_id: "s".into(), text: "SECRET-OUTPUT".into() };
        let shown = format!("{turn:?} {refresh:?} {token:?}");
        assert!(!shown.contains("SECRET"), "{shown}");
    }
}
