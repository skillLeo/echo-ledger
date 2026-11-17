//! Settings: the relay file (models, modes, timing, limits; `config/relay.toml` is built in), the server's model
//! catalog once fetched (it replaces the file's models and mode map), and the environment (address, the `assist_relay`
//! secret, the server, which providers). Secrets are only ever read from the environment or the server, never printed.

use std::collections::BTreeMap;
use std::fmt;
use std::net::SocketAddr;
use std::path::{Path, PathBuf};
use std::sync::{Arc, RwLock};
use std::time::Duration;

use serde::Deserialize;
use thiserror::Error;

use crate::domain::{Mode, ProviderKind};

/// The settings file built into the binary; `RELAY_CONFIG` replaces it with another file of the same shape.
pub const DEFAULT_CONFIG: &str = include_str!("../config/relay.toml");

#[derive(Debug, Error)]
pub enum ConfigError {
    #[error("the settings file could not be read: {0}")]
    Read(String),
    #[error("the settings file is not valid: {0}")]
    Parse(String),
    #[error("the settings are not usable: {0}")]
    Invalid(String),
    #[error("{name} is not set")]
    Missing { name: &'static str },
    #[error("{name} is not valid: {reason}")]
    BadValue { name: &'static str, reason: &'static str },
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RelayConfig {
    pub speculative: SpeculativeConfig,
    pub context: ContextConfig,
    pub budget: BudgetConfig,
    pub session: SessionConfig,
    pub limits: Limits,
    pub modes: ModesConfig,
    pub upgrade: UpgradeConfig,
    #[serde(default)]
    pub practice: PracticeConfig,
    #[serde(default)]
    pub background: BackgroundConfig,
    pub models: BTreeMap<String, ModelSpec>,
}

/// Practice sessions: the relay asks the questions.
#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PracticeConfig {
    /// Questions in one practice session; then `practice_done`.
    pub questions: u32,
    pub max_output_tokens: u32,
}

impl Default for PracticeConfig {
    fn default() -> Self {
        PracticeConfig { questions: 8, max_output_tokens: 120 }
    }
}

/// The facts ledger and the running summary, written by the relay in the background (never blocking a suggestion).
#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct BackgroundConfig {
    /// New facts from the latest final turns, after this many of them.
    pub facts_every_finals: u32,
    /// The running summary, at most this often, when the call moved on.
    pub summary_every_secs: u64,
    pub max_output_tokens: u32,
}

impl Default for BackgroundConfig {
    fn default() -> Self {
        BackgroundConfig { facts_every_finals: 3, summary_every_secs: 300, max_output_tokens: 400 }
    }
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SpeculativeConfig {
    pub min_silence_ms: u64,
    pub restart_window_ms: u64,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ContextConfig {
    pub block_tokens: u32,
    pub recent_turns: usize,
    pub max_document_tokens: u32,
    /// The most transcript a session keeps in blocks (and so sends in one request); past it the oldest blocks go.
    #[serde(default = "default_max_transcript_tokens")]
    pub max_transcript_tokens: u32,
}

fn default_max_transcript_tokens() -> u32 {
    40_000
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct BudgetConfig {
    pub reserve_suggestions: u32,
    pub expected_output_tokens: u32,
    pub upgrade_share: f64,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SessionConfig {
    pub resume_grace_secs: u64,
    pub ping_interval_secs: u64,
    pub idle_timeout_secs: u64,
    pub warm_min_interval_secs: u64,
}

impl SessionConfig {
    pub fn resume_grace(&self) -> Duration {
        Duration::from_secs(self.resume_grace_secs)
    }
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Limits {
    pub max_message_bytes: usize,
    pub max_turn_chars: usize,
    pub max_field_chars: usize,
    pub max_documents: usize,
    pub max_document_chars: usize,
    pub max_facts: usize,
    pub messages_per_second: u32,
    pub burst: u32,
    /// Open WebSockets the relay holds at once (signed in or still waiting for `auth`); more get `503 relay_busy`.
    #[serde(default = "default_max_connections")]
    pub max_connections: usize,
}

fn default_max_connections() -> usize {
    1000
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ModesConfig {
    pub fast: ModePlan,
    pub balanced: ModePlan,
    pub deep: ModePlan,
    pub max: ModePlan,
}

impl ModesConfig {
    pub fn get(&self, mode: Mode) -> &ModePlan {
        match mode {
            Mode::Fast => &self.fast,
            Mode::Balanced => &self.balanced,
            Mode::Deep => &self.deep,
            Mode::Max => &self.max,
        }
    }
}

/// How much of the transcript a mode sends: the recent turns only, or the older blocks as well.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum TranscriptScope {
    Recent,
    Full,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ModePlan {
    pub primary: String,
    #[serde(default)]
    pub fallback: Option<String>,
    #[serde(default)]
    pub upgrade: Option<String>,
    pub transcript: TranscriptScope,
    pub max_output_tokens: u32,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct UpgradeConfig {
    pub min_words: usize,
    pub phrases: Vec<String>,
}

/// USD per million tokens. A token count times one of these is micro-dollars.
#[derive(Debug, Clone, Copy, PartialEq, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Price {
    pub input: f64,
    pub cache_write: f64,
    pub cache_read: f64,
    pub output: f64,
}

/// The higher price card some models use for long requests.
#[derive(Debug, Clone, Copy, PartialEq, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LongContextPrice {
    pub above_tokens: u32,
    pub input: f64,
    pub cache_write: f64,
    pub cache_read: f64,
    pub output: f64,
}

#[derive(Debug, Clone, Copy, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct MockLatency {
    pub first_token_ms: u64,
    pub token_ms: u64,
}

impl Default for MockLatency {
    fn default() -> Self {
        MockLatency { first_token_ms: 300, token_ms: 10 }
    }
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ModelSpec {
    pub provider: ProviderKind,
    #[serde(default = "enabled_by_default")]
    pub enabled: bool,
    #[serde(default = "default_first_token_timeout_ms")]
    pub first_token_timeout_ms: u64,
    pub price: Price,
    #[serde(default)]
    pub long_context: Option<LongContextPrice>,
    /// Provider request options, passed on unchanged (effort, thinking).
    #[serde(default)]
    pub options: toml::Table,
    #[serde(default)]
    pub mock: MockLatency,
    /// Show none of the answer until it is complete, so that a refusal at the end of the stream is never shown (models
    /// with safety classifiers). Costs the streaming: the first word comes with the last.
    #[serde(default)]
    pub hold_until_complete: bool,
}

fn enabled_by_default() -> bool {
    true
}

fn default_first_token_timeout_ms() -> u64 {
    3000
}

/// The part of `GET /api/internal/assist/providers` that replaces the file's models and mode map.
#[derive(Debug, Clone, Default, Deserialize)]
pub struct Catalog {
    /// Models of a provider this relay has no client for are left out (and logged), so a new provider in the server's
    /// list never makes the whole answer fail.
    #[serde(default, deserialize_with = "known_models")]
    pub models: Option<BTreeMap<String, ModelSpec>>,
    #[serde(default)]
    pub modes: Option<ModesConfig>,
}

/// A name from the server, shortened and limited to plain characters before it goes in a log line.
pub fn safe_name(name: &str) -> String {
    name.chars().filter(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.' | '/' | ':')).take(64).collect()
}

fn known_models<'de, D>(deserializer: D) -> Result<Option<BTreeMap<String, ModelSpec>>, D::Error>
where
    D: serde::Deserializer<'de>,
{
    use serde::de::Error;
    let Some(raw) = Option::<BTreeMap<String, serde_json::Value>>::deserialize(deserializer)? else {
        return Ok(None);
    };
    let mut models = BTreeMap::new();
    for (id, value) in raw {
        let provider = value.get("provider").cloned().unwrap_or_default();
        if serde_json::from_value::<ProviderKind>(provider.clone()).is_err() {
            tracing::warn!(
                event = "catalog_model_skipped",
                model = %safe_name(&id),
                provider = %safe_name(provider.as_str().unwrap_or_default())
            );
            continue;
        }
        models.insert(id, serde_json::from_value(value).map_err(D::Error::custom)?);
    }
    Ok(Some(models))
}

impl ModelSpec {
    /// The price card for a request of `prompt_tokens` input tokens.
    pub fn price_for(&self, prompt_tokens: u32) -> Price {
        match self.long_context {
            Some(long) if prompt_tokens > long.above_tokens => Price {
                input: long.input,
                cache_write: long.cache_write,
                cache_read: long.cache_read,
                output: long.output,
            },
            _ => self.price,
        }
    }

    pub fn first_token_timeout(&self) -> Duration {
        Duration::from_millis(self.first_token_timeout_ms)
    }
}

impl RelayConfig {
    /// The built-in settings. They are checked by the tests, so this cannot fail in a release.
    pub fn built_in() -> Self {
        Self::parse(DEFAULT_CONFIG).unwrap_or_else(|error| panic!("the built-in settings are broken: {error}"))
    }

    pub fn parse(text: &str) -> Result<Self, ConfigError> {
        let config: RelayConfig = toml::from_str(text).map_err(|error| ConfigError::Parse(error.message().to_owned()))?;
        config.validate()?;
        Ok(config)
    }

    pub fn load(path: Option<&PathBuf>) -> Result<Self, ConfigError> {
        match path {
            None => Self::parse(DEFAULT_CONFIG),
            Some(path) => {
                let text = std::fs::read_to_string(path).map_err(|error| ConfigError::Read(error.kind().to_string()))?;
                Self::parse(&text)
            }
        }
    }

    pub fn model(&self, id: &str) -> Option<&ModelSpec> {
        self.models.get(id)
    }

    /// These settings with the server's models and mode map in place, checked like the file.
    pub fn with_catalog(&self, catalog: Catalog) -> Result<RelayConfig, ConfigError> {
        let mut next = self.clone();
        if let Some(models) = catalog.models {
            next.models = models;
        }
        if let Some(modes) = catalog.modes {
            next.modes = modes;
        }
        next.validate()?;
        Ok(next)
    }

    fn validate(&self) -> Result<(), ConfigError> {
        let invalid = |reason: String| Err(ConfigError::Invalid(reason));
        for (id, spec) in &self.models {
            let mut prices = vec![spec.price.input, spec.price.cache_write, spec.price.cache_read, spec.price.output];
            if let Some(long) = spec.long_context {
                prices.extend([long.input, long.cache_write, long.cache_read, long.output]);
            }
            if prices.iter().any(|p| !p.is_finite() || *p < 0.0) {
                return invalid(format!("model {id} has a negative or missing price"));
            }
            if spec.first_token_timeout_ms == 0 {
                return invalid(format!("model {id} needs a first_token_timeout_ms above 0"));
            }
        }
        for mode in Mode::LADDER {
            let plan = self.modes.get(mode);
            let named = [Some(&plan.primary), plan.fallback.as_ref(), plan.upgrade.as_ref()];
            for id in named.into_iter().flatten() {
                match self.models.get(id) {
                    None => return invalid(format!("mode {} names the unknown model {id}", mode.as_str())),
                    Some(spec) if !spec.enabled => {
                        return invalid(format!("mode {} names the disabled model {id}", mode.as_str()));
                    }
                    Some(_) => {}
                }
            }
            if plan.max_output_tokens == 0 {
                return invalid(format!("mode {} needs max_output_tokens above 0", mode.as_str()));
            }
        }
        if self.speculative.min_silence_ms == 0 {
            return invalid("speculative.min_silence_ms must be above 0".to_owned());
        }
        if self.context.block_tokens < 200 || self.context.recent_turns == 0 {
            return invalid("context.block_tokens must be at least 200 and context.recent_turns at least 1".to_owned());
        }
        if self.budget.reserve_suggestions == 0 || self.budget.expected_output_tokens == 0 {
            return invalid("budget.reserve_suggestions and budget.expected_output_tokens must be above 0".to_owned());
        }
        if !(0.0..=1.0).contains(&self.budget.upgrade_share) {
            return invalid("budget.upgrade_share must be between 0 and 1".to_owned());
        }
        let limits = &self.limits;
        if [limits.max_message_bytes, limits.max_turn_chars, limits.max_field_chars, limits.max_documents, limits.max_document_chars]
            .contains(&0)
            || limits.messages_per_second == 0
            || limits.burst < limits.messages_per_second
        {
            return invalid("limits must be above 0, and burst at least messages_per_second".to_owned());
        }
        if self.session.ping_interval_secs == 0 || self.session.idle_timeout_secs <= self.session.ping_interval_secs {
            return invalid("session.idle_timeout_secs must be longer than session.ping_interval_secs".to_owned());
        }
        Ok(())
    }
}

/// A value the whole relay reads and the provider refresh may replace (the settings once the server's catalog is in).
#[derive(Debug)]
pub struct Shared<T>(RwLock<Arc<T>>);

impl<T> Shared<T> {
    pub fn new(value: T) -> Self {
        Shared(RwLock::new(Arc::new(value)))
    }

    pub fn get(&self) -> Arc<T> {
        self.0.read().map_or_else(|poisoned| poisoned.into_inner().clone(), |guard| guard.clone())
    }

    pub fn set(&self, value: T) {
        let value = Arc::new(value);
        match self.0.write() {
            Ok(mut guard) => *guard = value,
            Err(poisoned) => *poisoned.into_inner() = value,
        }
    }
}

/// Which model providers answer: the real ones (live), or deterministic mocks for development, tests and load tests.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ProviderMode {
    Live,
    Mock,
}

/// A secret value: kept as bytes, never shown by `Debug` or `Display`.
#[derive(Clone)]
pub struct Secret(Vec<u8>);

impl Secret {
    pub fn new(bytes: impl Into<Vec<u8>>) -> Self {
        Secret(bytes.into())
    }

    pub fn expose(&self) -> &[u8] {
        &self.0
    }
}

impl fmt::Debug for Secret {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str("Secret(<redacted>)")
    }
}

/// The `assist_relay` secret needs at least this many bytes.
pub const MIN_SECRET_BYTES: usize = 32;

/// Everything the relay reads from its environment.
#[derive(Debug, Clone)]
pub struct Settings {
    pub bind: SocketAddr,
    pub config_path: Option<PathBuf>,
    /// A pack folder instead of the built-in copy.
    pub pack_dir: Option<PathBuf>,
    pub providers: ProviderMode,
    /// The `assist_relay` secret from Admin -> API Credentials: it signs the assist tokens and the relay's requests
    /// to the server. The current one first, then the previous one while a rotation is under way (tokens only).
    pub secrets: Vec<Secret>,
    pub token_issuer: String,
    /// The server for the internal API (`https://console.example`). Absent in development: the relay then runs on its
    /// own settings and reports nothing.
    pub server_url: Option<String>,
    pub log: String,
}

impl Settings {
    /// The environment; the secrets may come from systemd's credentials instead (`LoadCredentialEncrypted=`), so
    /// they are neither in the environment nor in a plain file.
    pub fn from_env() -> Result<Self, ConfigError> {
        let credentials = std::env::var_os("CREDENTIALS_DIRECTORY").map(PathBuf::from);
        Self::from_lookup(|name| std::env::var(name).ok().or_else(|| credential(credentials.as_deref(), name)))
    }

    /// Reads the settings through `lookup` (the environment in the binary, a map in the tests).
    pub fn from_lookup(lookup: impl Fn(&str) -> Option<String>) -> Result<Self, ConfigError> {
        let bind = match lookup("RELAY_BIND") {
            None => SocketAddr::from(([127, 0, 0, 1], 8787)),
            Some(value) => value.parse().map_err(|_| ConfigError::BadValue {
                name: "RELAY_BIND",
                reason: "use an address and port such as 127.0.0.1:8787",
            })?,
        };
        let providers = match lookup("RELAY_PROVIDERS").as_deref() {
            None | Some("live") => ProviderMode::Live,
            Some("mock") => ProviderMode::Mock,
            Some(_) => return Err(ConfigError::BadValue { name: "RELAY_PROVIDERS", reason: "use live or mock" }),
        };
        let secret = |name: &'static str| -> Result<Option<Secret>, ConfigError> {
            match lookup(name) {
                None => Ok(None),
                Some(value) if value.len() < MIN_SECRET_BYTES => {
                    Err(ConfigError::BadValue { name, reason: "use at least 32 characters (openssl rand -base64 48)" })
                }
                Some(value) => Ok(Some(Secret::new(value.into_bytes()))),
            }
        };
        let current = secret("RELAY_SECRET")?.ok_or(ConfigError::Missing { name: "RELAY_SECRET" })?;
        let mut secrets = vec![current];
        secrets.extend(secret("RELAY_SECRET_PREVIOUS")?);
        let server_url = match lookup("RELAY_SERVER_URL") {
            None => None,
            Some(url) => {
                let url = url.trim().trim_end_matches('/').to_owned();
                let local = ["http://127.0.0.1", "http://localhost", "http://[::1]"].iter().any(|p| url.starts_with(p));
                if !url.starts_with("https://") && !local {
                    return Err(ConfigError::BadValue { name: "RELAY_SERVER_URL", reason: "use https:// (http:// only for this machine)" });
                }
                Some(url)
            }
        };
        Ok(Settings {
            bind,
            config_path: lookup("RELAY_CONFIG").map(PathBuf::from),
            pack_dir: lookup("RELAY_PACK_DIR").map(PathBuf::from),
            providers,
            secrets,
            token_issuer: lookup("RELAY_TOKEN_ISSUER").unwrap_or_else(|| "echoledger".to_owned()),
            server_url,
            log: lookup("RELAY_LOG").unwrap_or_else(|| "info".to_owned()),
        })
    }
}

/// The systemd credential that stands in for a secret missing from the environment.
const CREDENTIALS: [(&str, &str); 2] = [("RELAY_SECRET", "relay_secret"), ("RELAY_SECRET_PREVIOUS", "relay_secret_previous")];

/// A secret from systemd's credentials folder: `relay_secret` for `RELAY_SECRET`, `relay_secret_previous` for
/// `RELAY_SECRET_PREVIOUS`. Nothing else is read from there.
fn credential(dir: Option<&Path>, name: &str) -> Option<String> {
    let file = CREDENTIALS.iter().find(|(variable, _)| *variable == name)?.1;
    let text = std::fs::read_to_string(dir?.join(file)).ok()?;
    Some(text.trim_end_matches(['\r', '\n']).to_owned())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::HashMap;

    #[test]
    fn secrets_can_come_from_systemd_credentials_and_nothing_else_does() {
        let dir = std::env::temp_dir().join(format!("assist-relay-credentials-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let secret = "k".repeat(40);
        std::fs::write(dir.join("relay_secret"), format!("{secret}\n")).unwrap();
        std::fs::write(dir.join("relay_bind"), "0.0.0.0:1").unwrap();
        assert_eq!(credential(Some(&dir), "RELAY_SECRET").as_deref(), Some(secret.as_str()), "the newline is dropped");
        assert_eq!(credential(Some(&dir), "RELAY_SECRET_PREVIOUS"), None, "no file, no secret");
        assert_eq!(credential(Some(&dir), "RELAY_BIND"), None, "only the secrets");
        assert_eq!(credential(None, "RELAY_SECRET"), None);
        std::fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn the_built_in_settings_are_valid_and_map_the_plan_modes() {
        let config = RelayConfig::parse(DEFAULT_CONFIG).expect("valid");
        assert_eq!(config.modes.fast.primary, "claude-haiku-5-5");
        assert_eq!(config.modes.fast.fallback.as_deref(), Some("openai/gpt-oss-120b"));
        assert_eq!(config.model("openai/gpt-oss-120b").map(|m| m.provider), Some(ProviderKind::Groq));
        assert_eq!(config.modes.balanced.primary, "claude-haiku-5-5");
        assert_eq!(config.modes.balanced.upgrade.as_deref(), Some("claude-sonnet-5-5"));
        assert_eq!(config.modes.deep.primary, "claude-sonnet-5-5");
        assert_eq!(config.modes.max.primary, "claude-opus-5-5");
        assert_eq!(config.speculative.min_silence_ms, 128);
        assert_eq!(config.speculative.restart_window_ms, 700);
        assert_eq!(config.context.block_tokens, 1500);
    }

    #[test]
    fn a_mode_naming_an_unknown_or_disabled_model_is_refused() {
        let unknown = DEFAULT_CONFIG.replacen("primary = \"claude-opus-5-5\"", "primary = \"claude-nothing\"", 1);
        assert!(matches!(RelayConfig::parse(&unknown), Err(ConfigError::Invalid(_))));
        let disabled = DEFAULT_CONFIG.replacen("primary = \"claude-opus-5-5\"", "primary = \"gpt-6-luna\"", 1);
        assert!(matches!(RelayConfig::parse(&disabled), Err(ConfigError::Invalid(_))));
    }

    #[test]
    fn unknown_keys_are_refused() {
        let typo = DEFAULT_CONFIG.replacen("min_silence_ms = 128", "min_silence = 128", 1);
        assert!(matches!(RelayConfig::parse(&typo), Err(ConfigError::Parse(_))));
    }

    #[test]
    fn the_long_context_price_starts_above_its_threshold() {
        let config = RelayConfig::built_in();
        let haiku = config.model("claude-haiku-5-5").unwrap();
        assert_eq!(haiku.price_for(100_000).input, 0.10);
        assert_eq!(haiku.price_for(100_001).input, 0.50);
    }

    fn env(pairs: &[(&str, &str)]) -> impl Fn(&str) -> Option<String> {
        let map: HashMap<String, String> = pairs.iter().map(|(k, v)| ((*k).to_owned(), (*v).to_owned())).collect();
        move |name| map.get(name).cloned()
    }

    #[test]
    fn the_environment_needs_a_long_secret_and_never_shows_it() {
        assert!(matches!(Settings::from_lookup(env(&[])), Err(ConfigError::Missing { .. })));
        assert!(matches!(Settings::from_lookup(env(&[("RELAY_SECRET", "short")])), Err(ConfigError::BadValue { .. })));
        let secret = "s".repeat(40);
        let settings = Settings::from_lookup(env(&[("RELAY_SECRET", &secret), ("RELAY_PROVIDERS", "mock")])).unwrap();
        assert_eq!(settings.providers, ProviderMode::Mock);
        assert_eq!(settings.bind.to_string(), "127.0.0.1:8787");
        assert_eq!(settings.server_url, None);
        assert!(!format!("{settings:?}").contains(&secret));
    }

    #[test]
    fn a_previous_secret_is_kept_for_rotations() {
        let current = "c".repeat(40);
        let previous = "p".repeat(40);
        let settings = Settings::from_lookup(env(&[("RELAY_SECRET", &current), ("RELAY_SECRET_PREVIOUS", &previous)])).unwrap();
        assert_eq!(settings.secrets.len(), 2);
        assert_eq!(settings.secrets[1].expose(), previous.as_bytes());
    }

    #[test]
    fn the_server_url_must_be_https_unless_it_is_this_machine() {
        let secret = "s".repeat(40);
        let with = |url: &str| Settings::from_lookup(env(&[("RELAY_SECRET", &secret), ("RELAY_SERVER_URL", url)]));
        assert_eq!(with("https://console.example/").unwrap().server_url.as_deref(), Some("https://console.example"));
        assert!(with("http://127.0.0.1:8000").is_ok());
        assert!(matches!(with("http://console.example"), Err(ConfigError::BadValue { .. })));
    }

    #[test]
    fn the_servers_catalog_replaces_models_and_modes_after_the_same_checks() {
        let config = RelayConfig::built_in();
        let catalog: Catalog = serde_json::from_str(
            r#"{"modes":{"fast":{"primary":"claude-sonnet-5-5","transcript":"recent","max_output_tokens":200},
                         "balanced":{"primary":"claude-haiku-5-5","transcript":"full","max_output_tokens":300},
                         "deep":{"primary":"claude-sonnet-5-5","transcript":"full","max_output_tokens":400},
                         "max":{"primary":"claude-opus-5-5","transcript":"full","max_output_tokens":500}}}"#,
        )
        .unwrap();
        let next = config.with_catalog(catalog).unwrap();
        assert_eq!(next.modes.fast.primary, "claude-sonnet-5-5");
        assert_eq!(next.models.len(), config.models.len());
        let broken: Catalog = serde_json::from_str(r#"{"models":{}}"#).unwrap();
        assert!(config.with_catalog(broken).is_err(), "modes would name missing models");
    }
}
