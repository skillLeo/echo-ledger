//! App-wide error type. Serialized to the UI as a plain message (never secrets).

#[derive(Debug, thiserror::Error)]
pub enum AppError {
    #[error("{0}")]
    Message(String),
    #[error("This computer is not paired with an EchoLedger server yet.")]
    NotPaired,
    #[error("Network error: {0}")]
    Network(String),
    #[error("Server returned {status}: {message}")]
    Server { status: u16, message: String },
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

impl AppError {
    pub fn msg(text: impl Into<String>) -> Self {
        Self::Message(text.into())
    }
}

impl From<reqwest::Error> for AppError {
    fn from(e: reqwest::Error) -> Self {
        // reqwest errors may embed the URL; ours never carry tokens in URLs.
        Self::Network(e.to_string())
    }
}

impl serde::Serialize for AppError {
    fn serialize<S: serde::Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        serializer.serialize_str(&self.to_string())
    }
}

pub type AppResult<T> = Result<T, AppError>;
