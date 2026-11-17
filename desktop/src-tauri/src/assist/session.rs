//! A Live Assist session's server side (docs/API_ASSIST.md 1.9.0-g "Sessions"): started with
//! `POST /api/assist/sessions`, its relay connection kept by [`super::client`] with tokens from
//! `POST /api/assist/sessions/{id}/token`, ended with `POST /api/assist/sessions/{id}/end`. The card's side is
//! [`super::card`] and [`super::live`].

use std::sync::Arc;

use serde::Serialize;

use super::client::{AssistEvent, EndReason, RelayGrant};
use crate::net::models::{AssistRelayDto, AssistStartAnswer};

/// What `assist_start` gives the window (the card's `AssistStarted`).
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct AssistStarted {
    pub session_id: String,
    /// The session started from a prep: it gets no after-call card.
    pub auto_saved: bool,
    /// `fast` | `balanced` | `deep` | `max`, or `captions_only` when the allowance is used up.
    pub mode: String,
    pub allowed_modes: Vec<String>,
    pub seconds_left: Option<u64>,
    pub practice: bool,
}

impl AssistStarted {
    pub fn from_answer(answer: &AssistStartAnswer) -> Self {
        let plan = answer.plan.clone().unwrap_or_default();
        AssistStarted {
            session_id: answer.session.id.clone(),
            auto_saved: answer.session.auto_saved,
            mode: answer.session.mode.clone(),
            allowed_modes: plan.modes,
            seconds_left: plan.seconds_left,
            practice: answer.session.practice,
        }
    }
}

/// The relay grant of a session or token answer, with the device security list's pins.
pub fn grant(relay: &AssistRelayDto, pins: &[String]) -> RelayGrant {
    RelayGrant { url: relay.url.clone(), token: relay.token.clone(), expires_at: relay.expires_at, pins: pins.to_vec() }
}

/// The word for how a session ended (the card's `ended.reason`).
pub fn end_word(reason: &EndReason) -> &'static str {
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

/// Hears the assist time left that a token refresh brings.
pub type OnPlan = Arc<dyn Fn(crate::net::models::AssistPlanDto) + Send + Sync>;

/// The relay connection's token refresh for session `id`: `POST …/token`, its relay grant pinned with `pins`; the plan
/// it may carry goes to `on_plan`.
pub fn refresh(api: Arc<crate::net::api::ApiClient>, id: String, pins: Vec<String>, on_plan: OnPlan) -> super::client::Refresh {
    Arc::new(move || {
        let (api, id, pins, on_plan) = (api.clone(), id.clone(), pins.clone(), on_plan.clone());
        Box::pin(async move {
            let answer = api.assist_token(&id).await.map_err(|e| e.to_string())?;
            if let Some(plan) = answer.plan.clone() {
                on_plan(plan);
            }
            Ok(grant(&answer.relay, &pins))
        })
    })
}

/// Starts a session: the server first, then the relay, only while `live_assist` is on. What the relay connection
/// hears goes to `on_event`, the plan each token refresh brings to `on_plan`; the answer comes back for the plan and
/// the session's settings.
pub async fn start(
    api: Arc<crate::net::api::ApiClient>,
    flags: &crate::ui_flags::UiFlags,
    pins: Vec<String>,
    request: crate::net::models::AssistStartRequest,
    on_plan: OnPlan,
    on_event: impl Fn(AssistEvent) + Send + Sync + 'static,
) -> crate::error::AppResult<(AssistStartAnswer, super::client::AssistHandle)> {
    if !super::enabled(flags) {
        return Err(crate::error::AppError::msg("Live Assist isn't on for you yet."));
    }
    let answer = api.assist_start(&request).await?;
    let refresh = refresh(api, answer.session.id.clone(), pins.clone(), on_plan);
    let handle = super::client::spawn(grant(&answer.relay, &pins), refresh, super::client::Policy::default(), on_event);
    Ok((answer, handle))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_session_that_ends_by_itself_says_why_in_plain_words() {
        assert_eq!(end_sentence(&EndReason::Closed), None, "an ordinary stop says nothing");
        assert_eq!(end_sentence(&EndReason::Insecure), Some("Live Assist could not make a secure connection."));
        assert_eq!(end_sentence(&EndReason::GaveUp("socket error 104".into())), Some("Live Assist lost its connection. Captions and the recording go on."), "never the technical reason");
        assert_eq!(end_word(&EndReason::TokenRejected), "token_rejected");
        assert_eq!(end_word(&EndReason::Replaced), "replaced");
    }
}
