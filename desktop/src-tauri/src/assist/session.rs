//! A Live Assist session on this computer (docs/API_ASSIST.md 1.9.0-e "Sessions"): started with
//! `POST /api/assist/sessions`, its relay connection kept by [`super::client`] with tokens from
//! `POST /api/assist/sessions/{id}/token`, ended with `POST /api/assist/sessions/{id}/end`. What the relay sends reaches
//! the window as events in the contract's shapes; a provider's refusal is never shown (the suggestion ends `failed`
//! or `superseded` and its fallback answers), so the relay's `error` messages are not passed on.

use std::sync::Arc;

use serde::Serialize;
use serde_json::{Value, json};

use super::client::{AssistEvent, EndReason, RelayGrant};
use super::protocol::ServerMessage;
use crate::net::models::{AssistRelayDto, AssistStartAnswer};

/// What `assist_start` gives the window.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct AssistStarted {
    pub session_id: String,
    pub mode: String,
    pub practice: bool,
    /// The session started from a prep: it gets no after-call card.
    pub auto_saved: bool,
    pub modes: Vec<String>,
    pub seconds_left: Option<u64>,
}

impl AssistStarted {
    pub fn from_answer(answer: &AssistStartAnswer) -> Self {
        let plan = answer.plan.clone().unwrap_or_default();
        AssistStarted {
            session_id: answer.session.id.clone(),
            mode: answer.session.mode.clone(),
            practice: answer.session.practice,
            auto_saved: answer.session.auto_saved,
            modes: plan.modes,
            seconds_left: plan.seconds_left,
        }
    }
}

/// The relay grant of a session or token answer, with the device security list's pins.
pub fn grant(relay: &AssistRelayDto, pins: &[String]) -> RelayGrant {
    RelayGrant { url: relay.url.clone(), token: relay.token.clone(), expires_at: relay.expires_at, pins: pins.to_vec() }
}

/// The window's event for something the session heard (pure): the event's name and its body, in the contract's
/// shapes. Errors are not passed on (a refusal is never shown); `Unknown` and the token answers are not either.
pub fn webview_event(event: &AssistEvent) -> Option<(&'static str, Value)> {
    fn as_json<T: Serialize>(v: &T) -> Value {
        serde_json::to_value(v).unwrap_or_default()
    }
    Some(match event {
        AssistEvent::Message(ServerMessage::SuggestionStart(start)) => ("assist:suggestion_start", as_json(start)),
        AssistEvent::Message(ServerMessage::Token(token)) => ("assist:token", as_json(token)),
        AssistEvent::Message(ServerMessage::SuggestionEnd(end)) => ("assist:suggestion_end", as_json(end)),
        AssistEvent::Message(ServerMessage::Skip(skip)) => ("assist:skip", as_json(skip)),
        AssistEvent::Message(ServerMessage::ModeChanged(change)) => ("assist:mode_changed", as_json(change)),
        AssistEvent::Message(_) => return None,
        AssistEvent::Connected { resumed, mode } => ("assist:state", json!({"state": "connected", "resumed": resumed, "mode": mode})),
        AssistEvent::Reconnecting { attempt, after, .. } => ("assist:state", json!({"state": "reconnecting", "attempt": attempt, "afterMs": after.as_millis() as u64})),
        AssistEvent::Ended(reason) => ("assist:state", json!({"state": "ended", "reason": end_word(reason), "message": end_sentence(reason)})),
    })
}

fn end_word(reason: &EndReason) -> &'static str {
    match reason {
        EndReason::Closed => "closed",
        EndReason::Replaced => "replaced",
        EndReason::WrongSession => "wrong_session",
        EndReason::Insecure => "insecure",
        EndReason::GaveUp(_) => "gave_up",
        EndReason::TokenRejected => "token_rejected",
    }
}

/// What the window may say when a session ends by itself; none for an ordinary stop.
pub fn end_sentence(reason: &EndReason) -> Option<&'static str> {
    match reason {
        EndReason::Closed => None,
        EndReason::Replaced => Some("Live Assist moved to another window or device."),
        EndReason::WrongSession => Some("This Live Assist session belongs to another account."),
        EndReason::Insecure => Some(crate::net::pinning::RELAY_INSECURE),
        EndReason::GaveUp(_) => Some("Live Assist lost its connection. Captions and the recording go on."),
        EndReason::TokenRejected => Some("Live Assist could not renew its access. Captions and the recording go on."),
    }
}

/// Starts a session: the server first, then the relay, only while `live_assist` is on. Events go to `emit`.
pub async fn start(
    api: Arc<crate::net::api::ApiClient>,
    flags: &crate::ui_flags::UiFlags,
    pins: Vec<String>,
    request: crate::net::models::AssistStartRequest,
    emit: impl Fn(&'static str, Value) + Send + Sync + 'static,
) -> crate::error::AppResult<(AssistStarted, super::client::AssistHandle)> {
    if !super::enabled(flags) {
        return Err(crate::error::AppError::msg("Live Assist isn't on for you yet."));
    }
    let answer = api.assist_start(&request).await?;
    let started = AssistStarted::from_answer(&answer);
    let session_id = started.session_id.clone();
    let refresh_pins = pins.clone();
    let refresh: super::client::Refresh = Arc::new(move || {
        let (api, id, pins) = (api.clone(), session_id.clone(), refresh_pins.clone());
        Box::pin(async move { api.assist_token(&id).await.map(|t| grant(&t.relay, &pins)).map_err(|e| e.to_string()) })
    });
    let handle = super::client::spawn(grant(&answer.relay, &pins), refresh, super::client::Policy::default(), move |event| {
        if let Some((name, body)) = webview_event(&event) {
            emit(name, body);
        }
    });
    Ok((started, handle))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::assist::protocol::{EndStatus, ModeChanged, ModeReason, RelayError, SuggestionEnd, SuggestionKind, SuggestionStart, Token};
    use std::time::Duration;

    #[test]
    fn suggestions_reach_the_window_in_the_contract_s_shapes() {
        let start = AssistEvent::Message(ServerMessage::SuggestionStart(SuggestionStart {
            suggestion_id: "s2".into(), turn_id: Some("o-12".into()), kind: SuggestionKind::Fallback, mode: "balanced".into(), model: "m".into(), speculative: false, draft: false, replaces: Some("s1".into()),
        }));
        let (name, body) = webview_event(&start).unwrap();
        assert_eq!(name, "assist:suggestion_start");
        assert_eq!(body, json!({"suggestion_id": "s2", "turn_id": "o-12", "kind": "fallback", "mode": "balanced", "model": "m", "speculative": false, "draft": false, "replaces": "s1"}));
        let (name, body) = webview_event(&AssistEvent::Message(ServerMessage::Token(Token { suggestion_id: "s2".into(), text: "Yes, ".into() }))).unwrap();
        assert_eq!((name, body), ("assist:token", json!({"suggestion_id": "s2", "text": "Yes, "})));
        let end = AssistEvent::Message(ServerMessage::SuggestionEnd(SuggestionEnd { suggestion_id: "s1".into(), status: EndStatus::Superseded, model: "m".into(), latency: json!({}), usage: json!({}) }));
        assert_eq!(webview_event(&end).unwrap().1["status"], "superseded");
        let mode = AssistEvent::Message(ServerMessage::ModeChanged(ModeChanged { from: "deep".into(), to: "balanced".into(), reason: ModeReason::Budget }));
        assert_eq!(webview_event(&mode).unwrap(), ("assist:mode_changed", json!({"from": "deep", "to": "balanced", "reason": "budget"})));
    }

    #[test]
    fn a_refusal_is_never_shown() {
        let refusal = AssistEvent::Message(ServerMessage::Error(RelayError { code: "model_declined".into(), message: "The model declined.".into(), retry: false, suggestion_id: Some("s1".into()), in_reply_to: None, fatal: false }));
        assert_eq!(webview_event(&refusal), None);
        assert_eq!(webview_event(&AssistEvent::Message(ServerMessage::Unknown)), None);
        assert_eq!(webview_event(&AssistEvent::Message(ServerMessage::TokenRefreshed { expires_at: "x".into() })), None);
    }

    #[test]
    fn the_connection_s_state_reaches_the_window_in_plain_words() {
        assert_eq!(webview_event(&AssistEvent::Connected { resumed: true, mode: "fast".into() }).unwrap(), ("assist:state", json!({"state": "connected", "resumed": true, "mode": "fast"})));
        assert_eq!(webview_event(&AssistEvent::Reconnecting { attempt: 2, after: Duration::from_millis(500), why: "connection".into() }).unwrap().1, json!({"state": "reconnecting", "attempt": 2, "afterMs": 500}));
        let insecure = webview_event(&AssistEvent::Ended(EndReason::Insecure)).unwrap().1;
        assert_eq!(insecure, json!({"state": "ended", "reason": "insecure", "message": "Live Assist could not make a secure connection."}));
        assert_eq!(webview_event(&AssistEvent::Ended(EndReason::Closed)).unwrap().1["message"], Value::Null);
        assert_eq!(webview_event(&AssistEvent::Ended(EndReason::GaveUp("socket error 104".into()))).unwrap().1["message"], "Live Assist lost its connection. Captions and the recording go on.", "never the technical reason");
    }
}
