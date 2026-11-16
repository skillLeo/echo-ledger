//! Model providers behind one trait: a streaming completion that is cancelled by dropping its stream, and a warm-up
//! that opens the connection and writes the cache for a session's fixed prefix.
//!
//! Real providers: Anthropic (Messages API), Groq and OpenAI (Chat Completions), Gemini (streamGenerateContent). Keys come from the server (Admin -> API Credentials) and live in memory only. The
//! deterministic mocks (`RELAY_PROVIDERS=mock`) stand in for all of them in tests and the load test.

pub mod anthropic;
pub mod gemini;
pub mod http;
pub mod mock;
pub mod openai_compat;
pub mod unconfigured;

use std::collections::BTreeMap;
use std::fmt;
use std::sync::Arc;

use futures_util::future::BoxFuture;
use futures_util::stream::BoxStream;
use thiserror::Error;

use crate::config::RelayConfig;
use crate::context::Context;
use crate::domain::ProviderKind;
use crate::internal::ProviderKeys;

pub use anthropic::Anthropic;
pub use gemini::Gemini;
pub use http::Retry;
pub use mock::{MockBehavior, MockProvider, MockStats};
pub use openai_compat::OpenAiCompatible;
pub use unconfigured::Unconfigured;

/// Token counts as providers report them. `input_tokens` is the uncached rest only.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Usage {
    pub input_tokens: u32,
    pub cached_input_tokens: u32,
    pub cache_write_tokens: u32,
    pub output_tokens: u32,
}

impl Usage {
    /// Every input token of the request, cached or not.
    pub fn prompt_tokens(&self) -> u32 {
        self.input_tokens.saturating_add(self.cached_input_tokens).saturating_add(self.cache_write_tokens)
    }
}

/// What a request is for. The mocks answer from it; real providers read only the context.
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum Purpose {
    Answer,
    HelpNow,
    Regenerate,
    Shorter,
    Formal,
    Translate,
    Warm,
}

impl fmt::Debug for Purpose {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        let name = match self {
            Purpose::Answer => "Answer",
            Purpose::HelpNow => "HelpNow",
            Purpose::Regenerate => "Regenerate",
            Purpose::Shorter => "Shorter",
            Purpose::Formal => "Formal",
            Purpose::Translate => "Translate",
            Purpose::Warm => "Warm",
        };
        f.write_str(name)
    }
}

#[derive(Clone)]
pub struct CompletionRequest {
    pub model: String,
    pub context: Arc<Context>,
    pub max_output_tokens: u32,
    /// The model's `options` from the settings (effort, thinking), passed on unchanged.
    pub options: toml::Table,
    pub purpose: Purpose,
    /// The words being answered (empty for help now).
    pub question: String,
}

impl fmt::Debug for CompletionRequest {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("CompletionRequest")
            .field("model", &self.model)
            .field("tokens", &self.context.tokens())
            .field("purpose", &self.purpose)
            .finish()
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum StreamEvent {
    /// The next piece of the answer.
    Text(String),
    /// Token counts, once, usually at the end.
    Usage(Usage),
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Error)]
pub enum ProviderError {
    #[error("{} is not configured on this relay", .0.label())]
    NotConfigured(ProviderKind),
    #[error("the model provider is unavailable")]
    Unavailable,
    #[error("the model provider is busy")]
    RateLimited,
    #[error("the model did not answer in time")]
    Timeout,
    #[error("the model declined this request")]
    Declined,
    #[error("the model provider sent something unexpected")]
    Protocol,
}

impl ProviderError {
    /// The `error.code` the app gets.
    pub fn code(self) -> &'static str {
        match self {
            ProviderError::NotConfigured(_) => "provider_not_configured",
            ProviderError::Unavailable | ProviderError::RateLimited | ProviderError::Protocol => "provider_unavailable",
            ProviderError::Timeout => "provider_timeout",
            ProviderError::Declined => "model_declined",
        }
    }
}

pub type TokenStream = BoxStream<'static, Result<StreamEvent, ProviderError>>;

pub trait Provider: Send + Sync + fmt::Debug {
    fn kind(&self) -> ProviderKind;

    /// Starts a streaming completion. Dropping the stream cancels the request at the provider (the HTTP stream is
    /// closed, so generation and billing stop).
    fn stream(&self, request: CompletionRequest) -> TokenStream;

    /// Opens the connection and writes the cache for the request's cached prefix (no output tokens), so the first
    /// suggestion of a session starts warm.
    fn warm(&self, request: CompletionRequest) -> BoxFuture<'static, Result<Usage, ProviderError>>;
}

/// Where each real provider is: the official addresses, or a local server in the tests.
#[derive(Debug, Clone)]
pub struct Endpoints {
    pub anthropic: String,
    pub groq: String,
    pub openai: String,
    pub gemini: String,
}

impl Default for Endpoints {
    fn default() -> Self {
        Endpoints {
            anthropic: anthropic::BASE_URL.to_owned(),
            groq: openai_compat::GROQ_BASE_URL.to_owned(),
            openai: openai_compat::OPENAI_BASE_URL.to_owned(),
            gemini: gemini::BASE_URL.to_owned(),
        }
    }
}

/// One provider per kind.
#[derive(Debug, Clone)]
pub struct Providers {
    by_kind: BTreeMap<ProviderKind, Arc<dyn Provider>>,
}

impl Providers {
    /// The real providers, reading their keys from `keys` (filled from the server's Admin -> API Credentials) at each
    /// request. A provider without a key answers "not configured".
    pub fn live(keys: Arc<ProviderKeys>, endpoints: &Endpoints, retry: Retry) -> Self {
        let client = http::client();
        let mut by_kind: BTreeMap<ProviderKind, Arc<dyn Provider>> = BTreeMap::new();
        by_kind.insert(ProviderKind::Anthropic, Arc::new(Anthropic::new(&endpoints.anthropic, keys.clone(), client.clone(), retry)));
        for (kind, base) in [(ProviderKind::Groq, &endpoints.groq), (ProviderKind::Openai, &endpoints.openai)] {
            by_kind.insert(kind, Arc::new(OpenAiCompatible::new(kind, base, keys.clone(), client.clone(), retry)));
        }
        by_kind.insert(ProviderKind::Gemini, Arc::new(Gemini::new(&endpoints.gemini, keys.clone(), client.clone(), retry)));
        Providers { by_kind }
    }

    /// Deterministic mocks for every kind, with each model's latency from the settings; they share `stats`.
    pub fn mock(config: &RelayConfig, stats: Arc<MockStats>) -> (Self, BTreeMap<ProviderKind, Arc<MockProvider>>) {
        let mocks: BTreeMap<ProviderKind, Arc<MockProvider>> =
            ProviderKind::ALL.into_iter().map(|kind| (kind, Arc::new(MockProvider::new(kind, config, stats.clone())))).collect();
        let by_kind = mocks.iter().map(|(kind, mock)| (*kind, mock.clone() as Arc<dyn Provider>)).collect();
        (Providers { by_kind }, mocks)
    }

    pub fn get(&self, kind: ProviderKind) -> Arc<dyn Provider> {
        self.by_kind.get(&kind).cloned().unwrap_or_else(|| Arc::new(Unconfigured::new(kind)))
    }
}
