//! Logging: JSON lines on standard output (journald or Docker keep them). `RELAY_LOG` sets this relay's level only;
//! the libraries stay at `warn` whatever it says, so their traces (headers, frames) never reach the log. The `log`
//! facade is not collected at all.

use tracing::Subscriber;
use tracing_subscriber::EnvFilter;
use tracing_subscriber::fmt::MakeWriter;

const LEVELS: [&str; 5] = ["error", "warn", "info", "debug", "trace"];

/// The filter for a `RELAY_LOG` value: a level for this relay; anything else means `info`.
pub fn filter(level: &str) -> EnvFilter {
    let level = level.trim().to_ascii_lowercase();
    let level = if LEVELS.contains(&level.as_str()) { level } else { "info".to_owned() };
    EnvFilter::new(format!("warn,assist_relay={level}"))
}

/// The relay's subscriber writing to `writer` (standard output in the binary, a buffer in the redaction test).
pub fn subscriber<W>(level: &str, writer: W) -> impl Subscriber + Send + Sync
where
    W: for<'a> MakeWriter<'a> + Send + Sync + 'static,
{
    tracing_subscriber::fmt()
        .json()
        .flatten_event(true)
        .with_current_span(false)
        .with_span_list(false)
        .with_target(true)
        .with_env_filter(filter(level))
        .with_writer(writer)
        .finish()
}

pub fn init(level: &str) {
    let _ = tracing::subscriber::set_global_default(subscriber(level, std::io::stdout));
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn libraries_stay_at_warn_whatever_the_level() {
        assert_eq!(filter("trace").to_string(), "warn,assist_relay=trace");
        assert_eq!(filter("hyper=trace").to_string(), "warn,assist_relay=info");
        assert_eq!(filter(" DEBUG ").to_string(), "warn,assist_relay=debug");
    }
}
