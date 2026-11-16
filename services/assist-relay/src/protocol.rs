//! The WebSocket messages (PROTOCOL.md; contract 1.9.0-f, docs/API_ASSIST.md). One JSON object per text frame, named
//! by `type`, with a per-direction `seq`.
//!
//! Every type that carries words someone said or wrote has a `Debug` that shows lengths only, so a stray `{:?}` can
//! never put call content in a log.

use std::fmt;

use serde::{Deserialize, Serialize};

use crate::config::Limits;
use crate::domain::{AssistState, Channel, EffectiveMode, HintLevel, LanguageHelp, MeetingType, Mode};

/// The protocol version in `ready`. A breaking change bumps it and the URL path.
pub const PROTOCOL_VERSION: u32 = 1;

const MAX_ID_CHARS: usize = 64;
const MAX_FACT_CHARS: usize = 1000;

/// Shows a text field's length instead of its words.
struct Len<'a>(&'a str);

impl fmt::Debug for Len<'_> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "<{} chars>", self.0.chars().count())
    }
}

// ---------------------------------------------------------------------------------------------------------------
// App -> relay
// ---------------------------------------------------------------------------------------------------------------

/// A message from the app with its `seq` (the app's own counter; the relay quotes it in an error about that message).
#[derive(Debug, Clone, Deserialize)]
pub struct ClientEnvelope {
    #[serde(default)]
    pub seq: Option<u64>,
    #[serde(flatten)]
    pub message: ClientMessage,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum ClientMessage {
    /// The token, as the first message, from apps that cannot set `Authorization` on a WebSocket.
    Auth(AuthMessage),
    ContextUpdate(ContextUpdate),
    TurnPartial(TurnMessage),
    TurnFinal(TurnMessage),
    HelpNow(HelpNow),
    Regenerate(Rewrite),
    Shorter(Rewrite),
    Formal(Rewrite),
    Translate(TranslateRequest),
    Cancel(CancelRequest),
    TokenRefresh(TokenRefresh),
}

impl ClientMessage {
    pub fn kind(&self) -> &'static str {
        match self {
            ClientMessage::Auth(_) => "auth",
            ClientMessage::ContextUpdate(_) => "context_update",
            ClientMessage::TurnPartial(_) => "turn_partial",
            ClientMessage::TurnFinal(_) => "turn_final",
            ClientMessage::HelpNow(_) => "help_now",
            ClientMessage::Regenerate(_) => "regenerate",
            ClientMessage::Shorter(_) => "shorter",
            ClientMessage::Formal(_) => "formal",
            ClientMessage::Translate(_) => "translate",
            ClientMessage::Cancel(_) => "cancel",
            ClientMessage::TokenRefresh(_) => "token_refresh",
        }
    }

    /// Checks sizes and ids. The error is a plain sentence for the app's `error.message`.
    pub fn validate(&self, limits: &Limits) -> Result<(), &'static str> {
        match self {
            ClientMessage::ContextUpdate(update) => update.validate(limits),
            ClientMessage::TurnPartial(turn) | ClientMessage::TurnFinal(turn) => {
                check_id(&turn.turn_id)?;
                if turn.text.chars().count() > limits.max_turn_chars {
                    return Err("A turn's text is too long.");
                }
                Ok(())
            }
            ClientMessage::HelpNow(help) => help.turn_id.as_deref().map_or(Ok(()), check_id),
            ClientMessage::Regenerate(rewrite) | ClientMessage::Shorter(rewrite) | ClientMessage::Formal(rewrite) => {
                rewrite.suggestion_id.as_deref().map_or(Ok(()), check_id)
            }
            ClientMessage::Translate(translate) => {
                if translate.language == LanguageHelp::Off {
                    return Err("Translate needs a language: urdu, roman_urdu or arabic.");
                }
                translate.suggestion_id.as_deref().map_or(Ok(()), check_id)
            }
            ClientMessage::Cancel(cancel) => cancel.suggestion_id.as_deref().map_or(Ok(()), check_id),
            ClientMessage::TokenRefresh(TokenRefresh { token }) | ClientMessage::Auth(AuthMessage { token }) => {
                if token.is_empty() || token.len() > crate::auth::MAX_TOKEN_BYTES {
                    return Err("The token is missing or too long.");
                }
                Ok(())
            }
        }
    }
}

fn check_id(id: &str) -> Result<(), &'static str> {
    if id.is_empty() || id.chars().count() > MAX_ID_CHARS {
        return Err("An id is empty or longer than 64 characters.");
    }
    Ok(())
}

/// Everything about the call that is not the transcript. Every field is optional; a field that is present replaces
/// what the relay had (an empty string or list clears it).
#[derive(Debug, Clone, Default, Deserialize)]
pub struct ContextUpdate {
    /// The prep's brief (`prep` is accepted too).
    #[serde(default, alias = "prep")]
    pub brief: Option<Brief>,
    #[serde(default)]
    pub settings: Option<SettingsUpdate>,
    #[serde(default)]
    pub documents: Option<Vec<Document>>,
    #[serde(default)]
    pub memory: Option<Text>,
    #[serde(default)]
    pub facts: Option<Vec<Fact>>,
    #[serde(default)]
    pub summary: Option<Text>,
}

impl ContextUpdate {
    fn validate(&self, limits: &Limits) -> Result<(), &'static str> {
        let too_long = |text: &str| text.chars().count() > limits.max_field_chars;
        if let Some(brief) = &self.brief
            && brief.fields().iter().any(|field| too_long(field))
        {
            return Err("A brief field is too long.");
        }
        if let Some(documents) = &self.documents {
            if documents.len() > limits.max_documents {
                return Err("Too many documents.");
            }
            for document in documents {
                check_id(&document.id)?;
                if document.text.chars().count() > limits.max_document_chars || too_long(&document.title) {
                    return Err("A document is too long.");
                }
            }
        }
        if self.memory.as_ref().is_some_and(|m| too_long(&m.0)) || self.summary.as_ref().is_some_and(|s| too_long(&s.0)) {
            return Err("The memory or the summary is too long.");
        }
        if let Some(facts) = &self.facts {
            if facts.len() > limits.max_facts {
                return Err("Too many facts.");
            }
            if facts.iter().any(|fact| fact.text.chars().count() > MAX_FACT_CHARS) {
                return Err("A fact is too long.");
            }
        }
        Ok(())
    }
}

/// A free text field (memory, summary): shown by length only.
#[derive(Clone, Default, PartialEq, Eq, Deserialize)]
#[serde(transparent)]
pub struct Text(pub String);

impl fmt::Debug for Text {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        Len(&self.0).fmt(f)
    }
}

/// The brief, as on the server's `assist_preps`.
#[derive(Clone, Default, PartialEq, Eq, Deserialize)]
pub struct Brief {
    #[serde(default)]
    pub meeting_type: MeetingType,
    #[serde(default)]
    pub title: String,
    #[serde(default)]
    pub counterpart: String,
    #[serde(default)]
    pub company: String,
    #[serde(default)]
    pub goals: String,
    #[serde(default)]
    pub done: String,
    #[serde(default)]
    pub not_done: String,
    #[serde(default)]
    pub to_discuss: String,
    #[serde(default)]
    pub instructions: String,
}

impl Brief {
    fn fields(&self) -> [&str; 8] {
        [
            &self.title,
            &self.counterpart,
            &self.company,
            &self.goals,
            &self.done,
            &self.not_done,
            &self.to_discuss,
            &self.instructions,
        ]
    }
}

impl fmt::Debug for Brief {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        let total: usize = self.fields().iter().map(|field| field.chars().count()).sum();
        f.debug_struct("Brief").field("meeting_type", &self.meeting_type).field("chars", &total).finish()
    }
}

#[derive(Debug, Clone, Copy, Default, PartialEq, Eq, Deserialize)]
pub struct SettingsUpdate {
    #[serde(default)]
    pub mode: Option<Mode>,
    #[serde(default)]
    pub hint_level: Option<HintLevel>,
    #[serde(default)]
    pub language_help: Option<LanguageHelp>,
    #[serde(default)]
    pub assist: Option<AssistState>,
}

#[derive(Clone, PartialEq, Eq, Deserialize)]
pub struct Document {
    pub id: String,
    #[serde(default)]
    pub title: String,
    pub text: String,
}

impl fmt::Debug for Document {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Document").field("id", &self.id).field("text", &Len(&self.text)).finish()
    }
}

/// One line of the facts ledger: something said in this call that must stay true ("Budget: 5,000 USD").
#[derive(Clone, PartialEq, Eq, Deserialize)]
pub struct Fact {
    #[serde(default)]
    pub id: Option<String>,
    pub text: String,
}

impl fmt::Debug for Fact {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Fact").field("text", &Len(&self.text)).finish()
    }
}

/// A turn from the speech engine. Partials of one turn and its final share `turn_id`.
#[derive(Clone, Deserialize)]
pub struct TurnMessage {
    pub turn_id: String,
    pub channel: Channel,
    pub text: String,
    #[serde(default, alias = "lang")]
    pub language: Option<String>,
    /// Start of the turn, ms since the session started (audio clock).
    #[serde(default)]
    pub start_ms: Option<u64>,
    #[serde(default)]
    pub end_ms: Option<u64>,
    /// Partials: how long the speaker has been silent, as the speech engine reports it.
    #[serde(default)]
    pub silence_ms: Option<u64>,
}

impl fmt::Debug for TurnMessage {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("TurnMessage")
            .field("turn_id", &self.turn_id)
            .field("channel", &self.channel)
            .field("text", &Len(&self.text))
            .field("silence_ms", &self.silence_ms)
            .finish()
    }
}

#[derive(Debug, Clone, Default, Deserialize)]
pub struct HelpNow {
    #[serde(default)]
    pub turn_id: Option<String>,
}

/// `regenerate`, `shorter`, `formal`: about one suggestion (the latest one when absent).
#[derive(Debug, Clone, Default, Deserialize)]
pub struct Rewrite {
    #[serde(default)]
    pub suggestion_id: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct TranslateRequest {
    #[serde(default)]
    pub suggestion_id: Option<String>,
    pub language: LanguageHelp,
}

#[derive(Debug, Clone, Default, Deserialize)]
pub struct CancelRequest {
    #[serde(default)]
    pub suggestion_id: Option<String>,
}

#[derive(Clone, Deserialize)]
pub struct TokenRefresh {
    pub token: String,
}

impl fmt::Debug for TokenRefresh {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str("TokenRefresh(<redacted>)")
    }
}

#[derive(Clone, Deserialize)]
pub struct AuthMessage {
    pub token: String,
}

impl fmt::Debug for AuthMessage {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str("AuthMessage(<redacted>)")
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Relay -> app
// ---------------------------------------------------------------------------------------------------------------

/// A message to the app with its `seq` (1, 2, 3… on each connection).
#[derive(Debug, Serialize)]
pub struct ServerEnvelope<'a> {
    pub seq: u64,
    #[serde(flatten)]
    pub message: &'a ServerMessage,
}

#[derive(Debug, Clone, Serialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum ServerMessage {
    Ready(Ready),
    SuggestionStart(SuggestionStart),
    Token(TokenChunk),
    SuggestionEnd(SuggestionEnd),
    Skip(Skip),
    Error(ErrorMessage),
    ModeChanged(ModeChanged),
    TokenRefreshed(TokenRefreshed),
}

#[derive(Debug, Clone, Serialize)]
pub struct Ready {
    pub protocol: u32,
    pub session_id: String,
    pub mode: EffectiveMode,
    pub requested_mode: Mode,
    pub allowed_modes: Vec<Mode>,
    pub expires_at: String,
    pub resumed: bool,
    pub practice: bool,
    pub limits: ReadyLimits,
}

#[derive(Debug, Clone, Copy, Serialize)]
pub struct ReadyLimits {
    pub max_message_bytes: usize,
    pub max_turn_chars: usize,
    pub messages_per_second: u32,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum SuggestionKind {
    /// Started by the other person's question (speculatively, or at the end of the turn).
    Answer,
    HelpNow,
    Regenerate,
    Shorter,
    Formal,
    Translate,
    /// The balanced mode's better answer, replacing the draft.
    Upgrade,
}

impl SuggestionKind {
    pub fn as_str(self) -> &'static str {
        match self {
            SuggestionKind::Answer => "answer",
            SuggestionKind::HelpNow => "help_now",
            SuggestionKind::Regenerate => "regenerate",
            SuggestionKind::Shorter => "shorter",
            SuggestionKind::Formal => "formal",
            SuggestionKind::Translate => "translate",
            SuggestionKind::Upgrade => "upgrade",
        }
    }
}

#[derive(Debug, Clone, Serialize)]
pub struct SuggestionStart {
    pub suggestion_id: String,
    pub turn_id: Option<String>,
    pub kind: SuggestionKind,
    pub mode: Mode,
    pub model: String,
    /// Started before the end of the turn, on a partial.
    pub speculative: bool,
    /// A better answer may replace this one (balanced mode, hard question).
    pub draft: bool,
    /// The suggestion this one replaces on the card.
    pub replaces: Option<String>,
}

#[derive(Clone, Serialize)]
pub struct TokenChunk {
    pub suggestion_id: String,
    pub text: String,
}

impl fmt::Debug for TokenChunk {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("TokenChunk").field("suggestion_id", &self.suggestion_id).field("text", &Len(&self.text)).finish()
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum EndStatus {
    Done,
    /// The app cancelled it, or the speaker kept talking right after a speculative start.
    Cancelled,
    /// A newer suggestion took its place.
    Superseded,
}

/// Milliseconds, one decimal, from the end of the turn (when the relay got the message that started the suggestion).
#[derive(Debug, Clone, Copy, Default, PartialEq, Serialize)]
pub struct Latency {
    pub end_of_turn_to_request_ms: f64,
    pub request_to_first_token_ms: Option<f64>,
    pub end_of_turn_to_first_token_ms: Option<f64>,
    /// The relay's own share: end of turn to first token, minus the model's time to first token.
    pub relay_overhead_ms: Option<f64>,
    pub total_ms: f64,
}

#[derive(Debug, Clone, Copy, Default, PartialEq, Serialize)]
pub struct UsageReport {
    pub input_tokens: u32,
    pub cached_input_tokens: u32,
    pub cache_write_tokens: u32,
    pub output_tokens: u32,
    pub cost_usd: f64,
    /// The provider sent no usage (cancelled early): the counts are the relay's estimate.
    pub estimated: bool,
}

#[derive(Debug, Clone, Serialize)]
pub struct SuggestionEnd {
    pub suggestion_id: String,
    pub status: EndStatus,
    pub model: String,
    pub latency: Latency,
    pub usage: UsageReport,
}

/// No reply is needed: the model answered SKIP. Ends the suggestion; no tokens were sent.
#[derive(Debug, Clone, Serialize)]
pub struct Skip {
    pub suggestion_id: String,
    pub turn_id: Option<String>,
    pub latency: Latency,
    pub usage: UsageReport,
}

#[derive(Debug, Clone, Serialize)]
pub struct ErrorMessage {
    pub code: &'static str,
    pub message: String,
    /// Trying the same again later may work.
    pub retry: bool,
    pub suggestion_id: Option<String>,
    /// The `seq` of the app's message this error answers.
    pub in_reply_to: Option<u64>,
    /// The relay closes the connection after a fatal error.
    pub fatal: bool,
}

impl ErrorMessage {
    pub fn new(code: &'static str, message: impl Into<String>) -> Self {
        ErrorMessage { code, message: message.into(), retry: false, suggestion_id: None, in_reply_to: None, fatal: false }
    }

    pub fn retry(mut self) -> Self {
        self.retry = true;
        self
    }

    pub fn in_reply_to(mut self, seq: Option<u64>) -> Self {
        self.in_reply_to = seq;
        self
    }

    pub fn for_suggestion(mut self, suggestion_id: &str) -> Self {
        self.suggestion_id = Some(suggestion_id.to_owned());
        self
    }

    pub fn fatal(mut self) -> Self {
        self.fatal = true;
        self
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum ModeReason {
    /// The app asked for the mode in `context_update.settings.mode`.
    Requested,
    /// The plan or the token does not allow the requested mode.
    Plan,
    /// The session's budget is running out: a cheaper mode, or captions only.
    Budget,
    /// A refreshed token brought more budget.
    BudgetRestored,
    /// The plan's assist time is used up.
    AssistTime,
}

impl ModeReason {
    pub fn as_str(self) -> &'static str {
        match self {
            ModeReason::Requested => "requested",
            ModeReason::Plan => "plan",
            ModeReason::Budget => "budget",
            ModeReason::BudgetRestored => "budget_restored",
            ModeReason::AssistTime => "assist_time",
        }
    }
}

#[derive(Debug, Clone, Serialize)]
pub struct ModeChanged {
    pub from: EffectiveMode,
    pub to: EffectiveMode,
    pub reason: ModeReason,
}

#[derive(Debug, Clone, Serialize)]
pub struct TokenRefreshed {
    pub expires_at: String,
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::RelayConfig;

    #[test]
    fn client_messages_parse_by_type() {
        let partial: ClientEnvelope = serde_json::from_str(
            r#"{"type":"turn_partial","seq":7,"turn_id":"o-1","channel":"other","text":"what is your","language":"en","silence_ms":40,"extra":1}"#,
        )
        .unwrap();
        assert_eq!(partial.seq, Some(7));
        assert!(matches!(partial.message, ClientMessage::TurnPartial(ref t) if t.silence_ms == Some(40) && t.language.as_deref() == Some("en")));
        let auth: ClientEnvelope = serde_json::from_str(r#"{"type":"auth","seq":1,"token":"t"}"#).unwrap();
        assert!(matches!(auth.message, ClientMessage::Auth(_)));
        let old_names: ClientMessage =
            serde_json::from_str(r#"{"type":"context_update","prep":{"meeting_type":"boss"}}"#).unwrap();
        assert!(matches!(old_names, ClientMessage::ContextUpdate(ref u) if u.brief.as_ref().is_some_and(|b| b.meeting_type == MeetingType::Boss)));
        let translate: ClientMessage = serde_json::from_str(r#"{"type":"translate","language":"roman_urdu"}"#).unwrap();
        assert!(matches!(translate, ClientMessage::Translate(ref t) if t.language == LanguageHelp::RomanUrdu));
        let update: ClientMessage = serde_json::from_str(
            r#"{"type":"context_update","settings":{"mode":"deep"},"memory":"m","facts":[{"text":"Budget 5k"}]}"#,
        )
        .unwrap();
        assert!(matches!(update, ClientMessage::ContextUpdate(ref u) if u.settings.and_then(|s| s.mode) == Some(Mode::Deep)));
        assert!(serde_json::from_str::<ClientMessage>(r#"{"type":"launch_rockets"}"#).is_err());
    }

    #[test]
    fn sizes_are_checked() {
        let limits = RelayConfig::built_in().limits;
        let long_turn = ClientMessage::TurnFinal(TurnMessage {
            turn_id: "o-1".into(),
            channel: Channel::Other,
            text: "x".repeat(limits.max_turn_chars + 1),
            language: None,
            start_ms: None,
            end_ms: None,
            silence_ms: None,
        });
        assert!(long_turn.validate(&limits).is_err());
        let off: ClientMessage = serde_json::from_str(r#"{"type":"translate","language":"off"}"#).unwrap();
        assert!(off.validate(&limits).is_err());
    }

    #[test]
    fn debug_output_never_shows_content() {
        let message: ClientMessage = serde_json::from_str(
            r#"{"type":"context_update","brief":{"goals":"SECRET-GOAL"},"documents":[{"id":"d","text":"SECRET-DOC"}],
                "memory":"SECRET-MEMORY","facts":[{"text":"SECRET-FACT"}],"summary":"SECRET-SUMMARY"}"#,
        )
        .unwrap();
        let turn: ClientMessage =
            serde_json::from_str(r#"{"type":"turn_final","turn_id":"t","channel":"me","text":"SECRET-TURN"}"#).unwrap();
        let refresh: ClientMessage = serde_json::from_str(r#"{"type":"token_refresh","token":"SECRET-TOKEN"}"#).unwrap();
        let auth: ClientMessage = serde_json::from_str(r#"{"type":"auth","token":"SECRET-AUTH"}"#).unwrap();
        let token = TokenChunk { suggestion_id: "s".into(), text: "SECRET-OUTPUT".into() };
        let shown = format!("{message:?} {turn:?} {refresh:?} {auth:?} {token:?}");
        assert!(!shown.contains("SECRET"), "{shown}");
    }

    #[test]
    fn server_messages_serialize_with_their_type() {
        let skip = ServerMessage::Skip(Skip {
            suggestion_id: "s-1".into(),
            turn_id: Some("o-1".into()),
            latency: Latency::default(),
            usage: UsageReport::default(),
        });
        let json = serde_json::to_value(ServerEnvelope { seq: 3, message: &skip }).unwrap();
        assert_eq!(json["type"], "skip");
        assert_eq!(json["seq"], 3);
        assert_eq!(json["suggestion_id"], "s-1");
        let error = ServerMessage::Error(ErrorMessage::new("provider_timeout", "x").retry().in_reply_to(Some(9)));
        let json = serde_json::to_value(ServerEnvelope { seq: 4, message: &error }).unwrap();
        assert_eq!((json["retry"].as_bool(), json["in_reply_to"].as_u64()), (Some(true), Some(9)));
        let changed = ServerMessage::ModeChanged(ModeChanged {
            from: EffectiveMode::Assist(Mode::Fast),
            to: EffectiveMode::CaptionsOnly,
            reason: ModeReason::Budget,
        });
        let json = serde_json::to_value(&changed).unwrap();
        assert_eq!((json["from"].as_str(), json["to"].as_str()), (Some("fast"), Some("captions_only")));
    }
}
