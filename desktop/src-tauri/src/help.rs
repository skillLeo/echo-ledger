//! Help (docs/API.md 2.0.0-d "Help"): the user guide's sections, what's new for this computer's platform and a message
//! to support. What the server refuses reads as the app's own sentences.

use crate::error::{AppError, AppResult};
use crate::net::api::ApiClient;
use crate::net::models::{DataList, HelpGuide, HelpGuideHit, WhatsNewRelease};

/// The longest subject and message the server takes (characters).
pub const SUBJECT_MAX: usize = 120;
pub const MESSAGE_MAX: usize = 5000;

/// The `platform` of `GET /api/help/whats-new`: this computer's.
pub fn platform() -> &'static str {
    if cfg!(target_os = "macos") { "mac" } else { "windows" }
}

/// The search as sent (pure): trimmed, none when empty.
pub fn search_of(q: Option<&str>) -> Option<String> {
    q.map(str::trim).filter(|q| !q.is_empty()).map(str::to_string)
}

/// A guide's id as the server makes them (`after-a-call`): anything else is not one of its guides (pure).
pub fn guide_id_of(id: &str) -> AppResult<String> {
    let id = id.trim();
    if !id.is_empty() && id.len() <= 100 && id.chars().all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '-' || c == '_') {
        Ok(id.to_string())
    } else {
        Err(AppError::msg(GUIDE_GONE))
    }
}

/// What the app says when a guide is not on the server (any more).
pub const GUIDE_GONE: &str = "This guide is no longer in the help. Search for it again.";

/// The subject and message as sent (pure): trimmed, both needed, within the server's lengths.
pub fn draft_of(subject: &str, message: &str) -> AppResult<(String, String)> {
    let (subject, message) = (subject.trim(), message.trim());
    if subject.is_empty() || message.is_empty() {
        return Err(AppError::msg("Write a subject and a message."));
    }
    if subject.chars().count() > SUBJECT_MAX {
        return Err(AppError::msg(format!("The subject can be {SUBJECT_MAX} characters at most.")));
    }
    if message.chars().count() > MESSAGE_MAX {
        return Err(AppError::msg(format!("The message can be {MESSAGE_MAX} characters at most.")));
    }
    Ok((subject.to_string(), message.to_string()))
}

/// What a refused message to support says (pure). The server's limit is 5 an hour and it answers `429` with the code
/// it uses for sign-in locks, so every `429` here is that limit; `403 email_not_verified` and `503 server_busy` get
/// sentences for this place. Anything else keeps the app's usual wording (maintenance included).
pub fn support_error(e: AppError) -> AppError {
    match &e {
        AppError::Server { status: 429, retry_after_secs, .. } => {
            let wait = retry_after_secs.map_or_else(|| "a while".to_string(), crate::account::wait_text);
            AppError::msg(format!("You have written to support several times in the last hour. You can send another message in {wait}."))
        }
        AppError::Server { code: Some(code), .. } if code == "email_not_verified" => {
            AppError::msg("Verify your email address before writing to support: open the link in the email EchoLedger sent you, then send your message again.")
        }
        AppError::Server { status: 503, code, retry_after_secs, .. } if code.as_deref() != Some("maintenance") => {
            let wait = retry_after_secs.map_or_else(|| "a moment".to_string(), crate::account::wait_text);
            AppError::msg(format!("The server is busy right now, and your message was not sent. Try again in {wait}."))
        }
        _ => e,
    }
}

/// The guide's sections, those matching `q` when there is a search.
pub async fn guides(api: &ApiClient, q: Option<&str>) -> AppResult<DataList<HelpGuideHit>> {
    api.help_guides(search_of(q).as_deref()).await
}

/// One section in full.
pub async fn guide(api: &ApiClient, id: &str) -> AppResult<HelpGuide> {
    let id = guide_id_of(id)?;
    api.help_guide(&id).await.map_err(|e| if e.status() == Some(404) { AppError::msg(GUIDE_GONE) } else { e })
}

/// What's new on this computer's platform.
pub async fn whats_new(api: &ApiClient) -> AppResult<DataList<WhatsNewRelease>> {
    api.help_whats_new(platform()).await
}

/// A message to support; nothing comes back when the server took it.
pub async fn send(api: &ApiClient, subject: &str, message: &str, include_device_report: bool) -> AppResult<()> {
    let (subject, message) = draft_of(subject, message)?;
    api.support_message(&subject, &message, include_device_report).await.map_err(support_error)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn server(status: u16, code: Option<&str>, retry_after_secs: Option<u64>) -> AppError {
        AppError::Server { status, message: "server words".into(), code: code.map(String::from), retry_after_secs }
    }

    #[test]
    fn a_refused_message_to_support_reads_as_plain_sentences() {
        let limited = support_error(server(429, Some("too_many_attempts"), Some(1800))).to_string();
        assert_eq!(limited, "You have written to support several times in the last hour. You can send another message in 30 minutes.");
        assert!(!limited.contains("sign-in"), "the server's sign-in code says nothing about sign-in here");
        assert!(support_error(server(429, None, None)).to_string().ends_with("in a while."));
        assert!(support_error(server(403, Some("email_not_verified"), None)).to_string().starts_with("Verify your email address before writing to support"));
        assert_eq!(support_error(server(503, Some("server_busy"), Some(5))).to_string(), "The server is busy right now, and your message was not sent. Try again in 5 seconds.");
        assert_eq!(support_error(server(503, Some("maintenance"), Some(60))).to_string(), crate::error::MAINTENANCE_TEXT, "maintenance keeps its own words");
        let other = support_error(server(422, Some("validation_failed"), None)).to_string();
        assert_eq!(other, "server words", "field errors are written for people");
    }

    #[test]
    fn the_draft_is_trimmed_and_kept_within_the_server_s_lengths() {
        assert_eq!(draft_of("  Captions stop ", " After a minute. ").unwrap(), ("Captions stop".into(), "After a minute.".into()));
        assert_eq!(draft_of(" ", "x").unwrap_err().to_string(), "Write a subject and a message.");
        assert!(draft_of(&"s".repeat(SUBJECT_MAX), "m").is_ok());
        assert!(draft_of(&"s".repeat(SUBJECT_MAX + 1), "m").unwrap_err().to_string().contains("120 characters"));
        assert!(draft_of("s", &"ü".repeat(MESSAGE_MAX)).is_ok(), "characters, not bytes");
        assert!(draft_of("s", &"m".repeat(MESSAGE_MAX + 1)).is_err());
    }

    #[test]
    fn searches_and_guide_ids_are_what_the_server_makes() {
        assert_eq!(search_of(Some("  captions ")), Some("captions".into()));
        assert_eq!(search_of(Some("  ")), None);
        assert_eq!(search_of(None), None);
        assert_eq!(guide_id_of(" after-a-call ").unwrap(), "after-a-call");
        assert_eq!(guide_id_of("../config").unwrap_err().to_string(), GUIDE_GONE);
        assert!(guide_id_of("").is_err());
        assert_eq!(platform(), if cfg!(target_os = "macos") { "mac" } else { "windows" });
    }
}
