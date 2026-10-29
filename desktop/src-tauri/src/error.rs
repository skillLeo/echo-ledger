//! App-wide error type. Serialized to the UI as a plain message (never secrets).

#[derive(Debug, thiserror::Error)]
pub enum AppError {
    #[error("{0}")]
    Message(String),
    #[error("This computer is not paired with an EchoLedger server yet.")]
    NotPaired,
    #[error("Network error: {0}")]
    Network(String),
    /// A non-2xx answer other than 401. `code` is the machine-readable `code` of docs/API.md (since v1.1);
    /// `retry_after_secs` is the `Retry-After` header in seconds (always present on a 429).
    #[error("{}", server_text(*.status, .message, .code.as_deref(), .retry_after_secs))]
    Server { status: u16, message: String, code: Option<String>, retry_after_secs: Option<u64> },
    #[error("Audio error: {0}")]
    Audio(String),
    #[error("Keychain error: {0}")]
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

/// Answers whose `message` is written for people (plan limits and roles, the missing Anthropic key for the views,
/// docs/API.md): shown as they are.
const PLAIN_CODES: [&str; 5] = ["budget_exceeded", "storage_limit_reached", "device_limit_reached", "not_allowed", "views_unavailable"];

fn server_text(status: u16, message: &str, code: Option<&str>, retry_after_secs: &Option<u64>) -> String {
    match code {
        Some(c) if PLAIN_CODES.contains(&c) && !message.trim().is_empty() => message.to_string(),
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
    }

    #[test]
    fn other_answers_keep_the_status() {
        assert_eq!(server(422, "The pairing code is invalid.", Some("validation_failed")).to_string(), "Server returned 422: The pairing code is invalid.");
        assert_eq!(server(403, "", Some("storage_limit_reached")).to_string(), "Server returned 403: ");
    }
}
