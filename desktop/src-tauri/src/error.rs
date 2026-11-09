//! App-wide error type. Serialized to the UI as a plain message (never secrets).

#[derive(Debug, thiserror::Error)]
pub enum AppError {
    #[error("{0}")]
    Message(String),
    #[error("This computer is not signed in to an EchoLedger server yet.")]
    NotPaired,
    #[error("Network error: {0}")]
    Network(String),
    /// A non-2xx answer other than 401. `code` is the machine-readable `code` of docs/API.md (since v1.1);
    /// `retry_after_secs` is the `Retry-After` header in seconds (always present on a 429).
    #[error("{}", server_text(*.status, .message, .code.as_deref(), .retry_after_secs))]
    Server { status: u16, message: String, code: Option<String>, retry_after_secs: Option<u64> },
    #[error("Audio error: {0}")]
    Audio(String),
    #[error("This computer's secure storage did not answer, so the sign-in could not be kept: {0}")]
    Keychain(String),
    #[error("I/O error: {0}")]
    Io(#[from] std::io::Error),
    #[error("Data error: {0}")]
    Json(#[from] serde_json::Error),
    #[error("Tauri error: {0}")]
    Tauri(#[from] tauri::Error),
}

fn retry_hint(retry_after_secs: &Option<u64>) -> String {
    retry_after_secs.map(|n| format!(" Try again in {n} s.")).unwrap_or_default()
}

/// Answers whose `message` is written for people (plan limits and roles, the missing Anthropic key for the views, the
/// client workspace's AI budget and key, docs/API.md): shown as they are.
const PLAIN_CODES: [&str; 11] = [
    "budget_exceeded", "storage_limit_reached", "device_limit_reached", "not_allowed", "views_unavailable", "ai_budget_reached", "ai_unavailable", "no_client",
    "suggestion_not_found", "feature_not_in_plan", "seat_limit_reached",
];

/// Projects, filing rules and sending (since v1.5): what the app says, whatever words the server chose. The commands
/// that know more (which project has the rule, which address has no account) say that instead.
const CODE_TEXT: [(&str, &str); 15] = [
    ("invalid_parent", "A project can't go inside itself or one of its own folders. Pick another folder."),
    ("too_deep", "Projects can be nested 4 levels deep at most. Pick a folder higher up."),
    ("rule_exists", "Another project already has this rule. Remove it there first, or file these recordings by something else."),
    ("rule_not_found", "That filing rule is no longer there."),
    ("recipient_not_found", "One of these emails has no account on this server, so nothing was sent. Check the addresses."),
    // Signing in and the account (since v1.5).
    ("invalid_credentials", "The email or password is not right. Check both and try again."),
    ("invalid_otp", "That code did not work. Type the 6-digit code your authenticator app shows now, or one of your recovery codes."),
    ("email_not_verified", "Your email address is not verified yet. Open the link in the email EchoLedger sent you, then sign in again."),
    ("invalid_code", "The browser sign-in did not finish: its code was wrong, already used or expired. Choose “Sign in in the browser” to start again."),
    ("owner_of_team", "You own a workspace that has other members. Hand it over to one of them on the console's Workspace page first, then delete your account."),
    // Sign-up and email codes (since v1.6).
    ("sign_up_closed", "New accounts are not open on this server. Ask its owner for an invitation."),
    ("email_not_allowed", "This email address can't be used for an account here. Use another address."),
    ("sign_up_limit", "Too many new accounts were made from this network today. Try again tomorrow."),
    ("code_expired", "This code has expired or was tried too often. Choose “Send a new code”."),
    ("turnstile_failed", "The sign-up check did not pass. Try again in a moment."),
];

/// The app's own sentence for a v1.5 error code, when it has one.
pub fn code_text(code: &str) -> Option<&'static str> {
    CODE_TEXT.iter().find(|(c, _)| *c == code).map(|(_, text)| *text)
}

fn server_text(status: u16, message: &str, code: Option<&str>, retry_after_secs: &Option<u64>) -> String {
    let message = message.trim();
    if let Some(text) = code.and_then(code_text) {
        return text.to_string();
    }
    if code == Some("too_many_attempts") {
        let wait = retry_after_secs.map(crate::account::wait_text).unwrap_or_else(|| "a few minutes".into());
        return format!("Too many sign-in attempts. Wait {wait}, then try again.");
    }
    match code {
        Some(c) if PLAIN_CODES.contains(&c) && !message.is_empty() => message.to_string(),
        // An answer without a message (a proxy's empty 502, say) gives the status, not "Server returned 502: ".
        _ if message.is_empty() => format!("Server returned {status}.{}", retry_hint(retry_after_secs)),
        _ => format!("Server returned {status}: {message}{}", retry_hint(retry_after_secs)),
    }
}

impl AppError {
    pub fn msg(text: impl Into<String>) -> Self {
        Self::Message(text.into())
    }

    /// The server's machine-readable error code (`session_not_found`, `budget_exceeded`, ...), when it sent one.
    pub fn code(&self) -> Option<&str> {
        match self {
            Self::Server { code, .. } => code.as_deref(),
            _ => None,
        }
    }

    /// HTTP status of a server answer (401 is `NotPaired` and has none here).
    pub fn status(&self) -> Option<u16> {
        match self {
            Self::Server { status, .. } => Some(*status),
            _ => None,
        }
    }

    pub fn retry_after_secs(&self) -> Option<u64> {
        match self {
            Self::Server { retry_after_secs, .. } => *retry_after_secs,
            _ => None,
        }
    }

    /// The route is not on this server (`404 not_found` for an unknown route, `405 method_not_allowed` when only
    /// other methods exist): a server older than the feature. `404 client_not_found` and the like are real answers.
    pub fn is_missing_route(&self) -> bool {
        match self {
            Self::Server { status, code, .. } => match code.as_deref() {
                Some(code) => matches!(code, "not_found" | "method_not_allowed"),
                None => matches!(status, 404 | 405),
            },
            _ => false,
        }
    }

    /// Errors that the same request will never get past: retrying them only wastes time and quota (docs/API.md
    /// "Error codes"). `upload_incomplete` (resume) and `verification_failed` (send again) are 4xx but recoverable;
    /// 408 and 429 are transient like 5xx and network failures.
    pub fn is_permanent(&self) -> bool {
        match self {
            Self::NotPaired => true,
            Self::Server { status, code, .. } => {
                (400..500).contains(status)
                    && *status != 408
                    && *status != 429
                    && !matches!(code.as_deref(), Some("upload_incomplete" | "verification_failed"))
            }
            _ => false,
        }
    }
}

impl From<reqwest::Error> for AppError {
    fn from(e: reqwest::Error) -> Self {
        // reqwest errors may embed the URL; ours never carry tokens in URLs.
        Self::Network(network_detail(&e))
    }
}

/// The text of a transport failure with its causes. reqwest's own message ("error sending request for url ...")
/// hides why the request failed; a certificate or pin rejection must be visible as such (A6.DR12).
fn network_detail(e: &reqwest::Error) -> String {
    if let Some(tls) = tls_failure(e) {
        return format!("the server's TLS certificate was not accepted ({tls})");
    }
    let mut text = e.to_string();
    let mut source = std::error::Error::source(e);
    while let Some(cause) = source {
        let part = cause.to_string();
        if !part.is_empty() && !text.contains(&part) {
            text.push_str(": ");
            text.push_str(&part);
        }
        source = cause.source();
    }
    text
}

/// Finds a rustls error in the cause chain. `std::io::Error::source()` skips the error it wraps, so io errors are
/// unwrapped with `get_ref()` (the handshake failure reaches reqwest as an io error around a `rustls::Error`).
fn tls_failure(e: &(dyn std::error::Error + 'static)) -> Option<String> {
    let mut current = Some(e);
    while let Some(err) = current {
        if let Some(tls) = err.downcast_ref::<rustls::Error>() {
            return Some(tls.to_string());
        }
        if let Some(io) = err.downcast_ref::<std::io::Error>()
            && let Some(inner) = io.get_ref()
            && let Some(tls) = inner.downcast_ref::<rustls::Error>()
        {
            return Some(tls.to_string());
        }
        current = err.source();
    }
    None
}

impl serde::Serialize for AppError {
    fn serialize<S: serde::Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        serializer.serialize_str(&self.to_string())
    }
}

pub type AppResult<T> = Result<T, AppError>;

#[cfg(test)]
mod server_text_tests {
    use super::AppError;

    fn server(status: u16, message: &str, code: Option<&str>) -> AppError {
        AppError::Server { status, message: message.into(), code: code.map(String::from), retry_after_secs: None }
    }

    #[test]
    fn plan_limit_answers_read_as_plain_sentences() {
        assert_eq!(server(403, "Storage limit reached — raise it in Settings", Some("storage_limit_reached")).to_string(), "Storage limit reached — raise it in Settings");
        assert_eq!(server(403, "Device limit reached — raise it in Settings", Some("device_limit_reached")).to_string(), "Device limit reached — raise it in Settings");
        assert_eq!(server(403, "Only the workspace owner can delete recordings made by someone else.", Some("not_allowed")).to_string(), "Only the workspace owner can delete recordings made by someone else.");
        assert_eq!(server(403, "This month's AI budget ($5.00) is used up. Raise it in Settings → AI.", Some("ai_budget_reached")).to_string(), "This month's AI budget ($5.00) is used up. Raise it in Settings → AI.");
        assert_eq!(server(409, "File this recording under a client first.", Some("no_client")).to_string(), "File this recording under a client first.");
    }

    #[test]
    fn other_answers_keep_the_status() {
        assert_eq!(server(422, "The pairing code is invalid.", Some("validation_failed")).to_string(), "Server returned 422: The pairing code is invalid.");
        assert_eq!(server(403, "", Some("storage_limit_reached")).to_string(), "Server returned 403.");
    }

    #[test]
    fn project_and_sharing_errors_read_as_the_app_s_own_plain_words() {
        assert_eq!(server(422, "The parent is invalid.", Some("invalid_parent")).to_string(), "A project can't go inside itself or one of its own folders. Pick another folder.");
        assert_eq!(server(422, "", Some("too_deep")).to_string(), "Projects can be nested 4 levels deep at most. Pick a folder higher up.");
        assert!(server(409, "Duplicate.", Some("rule_exists")).to_string().starts_with("Another project already has this rule."));
        assert_eq!(server(404, "No query results", Some("rule_not_found")).to_string(), "That filing rule is no longer there.");
        assert!(server(404, "Recipient missing", Some("recipient_not_found")).to_string().contains("nothing was sent"));
        assert_eq!(server(404, "Not found", Some("client_not_found")).to_string(), "Server returned 404: Not found", "older codes keep their text");
    }

    #[test]
    fn only_an_unknown_route_counts_as_a_missing_feature() {
        assert!(server(404, "Not found.", Some("not_found")).is_missing_route());
        assert!(server(405, "The DELETE method is not supported.", Some("method_not_allowed")).is_missing_route());
        assert!(server(404, "", None).is_missing_route());
        assert!(!server(404, "Unknown client.", Some("client_not_found")).is_missing_route());
        assert!(!server(500, "", None).is_missing_route());
        assert!(!AppError::NotPaired.is_missing_route());
    }

    #[test]
    fn an_answer_without_a_message_never_ends_in_a_dangling_colon() {
        assert_eq!(server(502, "", None).to_string(), "Server returned 502.");
        assert_eq!(server(500, "  ", None).to_string(), "Server returned 500.");
        let limited = AppError::Server { status: 429, message: String::new(), code: None, retry_after_secs: Some(30) };
        assert_eq!(limited.to_string(), "Server returned 429. Try again in 30 s.");
        assert_eq!(server(404, " Not found ", None).to_string(), "Server returned 404: Not found");
    }

    #[test]
    fn sign_in_answers_read_as_plain_words() {
        assert_eq!(server(422, "These credentials do not match our records.", Some("invalid_credentials")).to_string(), "The email or password is not right. Check both and try again.");
        assert!(server(422, "Invalid code.", Some("invalid_otp")).to_string().starts_with("That code did not work."));
        assert!(server(403, "Verify your email.", Some("email_not_verified")).to_string().starts_with("Your email address is not verified yet."));
        assert!(server(422, "", Some("invalid_code")).to_string().contains("Sign in in the browser"));
        assert!(server(409, "", Some("owner_of_team")).to_string().contains("Workspace page"));
        let locked = |secs| AppError::Server { status: 429, message: "Too many attempts.".into(), code: Some("too_many_attempts".into()), retry_after_secs: secs };
        assert_eq!(locked(Some(900)).to_string(), "Too many sign-in attempts. Wait 15 minutes, then try again.");
        assert_eq!(locked(Some(30)).to_string(), "Too many sign-in attempts. Wait 30 seconds, then try again.");
        assert_eq!(locked(None).to_string(), "Too many sign-in attempts. Wait a few minutes, then try again.");
        assert_eq!(server(403, "Screen moments are not in your plan", Some("feature_not_in_plan")).to_string(), "Screen moments are not in your plan");
        assert_eq!(server(403, "Your plan has 3 seats.", Some("seat_limit_reached")).to_string(), "Your plan has 3 seats.");
    }
}
