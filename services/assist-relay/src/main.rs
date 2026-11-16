//! The relay binary: settings from the environment (README, "Settings"), JSON logs on standard output.

use std::process::ExitCode;
use std::sync::Arc;

use assist_relay::config::{ProviderMode, RelayConfig, Settings, Shared};
use assist_relay::internal::{InternalApi, NoReports, ProviderKeys, ReportSink, Reporter, spawn_provider_refresh};
use assist_relay::pack::Pack;
use assist_relay::provider::{MockStats, Providers};
use assist_relay::telemetry::LogSink;

fn main() -> ExitCode {
    if std::env::args().nth(1).as_deref() == Some("--check-health") {
        return assist_relay::health_check(std::env::var("RELAY_BIND").ok().as_deref());
    }
    let settings = match Settings::from_env() {
        Ok(settings) => settings,
        Err(error) => {
            eprintln!("assist-relay: {error}");
            return ExitCode::from(2);
        }
    };
    assist_relay::logging::init(&settings.log);
    let runtime = match tokio::runtime::Builder::new_multi_thread().enable_all().build() {
        Ok(runtime) => runtime,
        Err(error) => {
            eprintln!("assist-relay: the runtime could not start: {error}");
            return ExitCode::FAILURE;
        }
    };
    runtime.block_on(run(settings))
}

async fn run(settings: Settings) -> ExitCode {
    let file = match RelayConfig::load(settings.config_path.as_ref()) {
        Ok(config) => Arc::new(config),
        Err(error) => {
            tracing::error!(event = "startup_failed", reason = %error);
            return ExitCode::from(2);
        }
    };
    let pack = match &settings.pack_dir {
        None => Pack::built_in(),
        Some(dir) => match Pack::load_dir(dir) {
            Ok(pack) => pack,
            Err(error) => {
                tracing::error!(event = "startup_failed", reason = %error);
                return ExitCode::from(2);
            }
        },
    };
    let config = Arc::new(Shared::new((*file).clone()));
    let providers = match settings.providers {
        ProviderMode::Live => Providers::live(),
        ProviderMode::Mock => Providers::mock(&file, Arc::new(MockStats::default())).0,
    };
    // With a server, the provider keys and the model catalog come from it, and usage goes back to it.
    let keys = Arc::new(ProviderKeys::default());
    let reports: Arc<dyn ReportSink> = match &settings.server_url {
        None => Arc::new(NoReports),
        Some(url) => match InternalApi::new(url, settings.secrets.clone()) {
            Ok(api) => {
                let api = Arc::new(api);
                spawn_provider_refresh(api.clone(), file.clone(), config.clone(), keys.clone());
                Arc::new(Reporter::spawn(api))
            }
            Err(error) => {
                tracing::error!(event = "startup_failed", reason = %error);
                return ExitCode::FAILURE;
            }
        },
    };
    let pack_version = pack.version.clone();
    let state = assist_relay::app_state(assist_relay::Parts {
        config,
        pack,
        providers,
        telemetry: Arc::new(LogSink),
        reports,
        secrets: settings.secrets.clone(),
        issuer: settings.token_issuer.clone(),
    });
    let listener = match tokio::net::TcpListener::bind(settings.bind).await {
        Ok(listener) => listener,
        Err(error) => {
            tracing::error!(event = "startup_failed", reason = %error, addr = %settings.bind);
            return ExitCode::FAILURE;
        }
    };
    let providers = match settings.providers {
        ProviderMode::Live => "live",
        ProviderMode::Mock => "mock",
    };
    tracing::info!(
        event = "listening",
        addr = %settings.bind,
        providers,
        server = settings.server_url.is_some(),
        pack = %pack_version,
        version = env!("CARGO_PKG_VERSION")
    );
    match assist_relay::server::serve(listener, state, shutdown_signal()).await {
        Ok(()) => {
            tracing::info!(event = "stopped");
            ExitCode::SUCCESS
        }
        Err(error) => {
            tracing::error!(event = "server_failed", reason = %error);
            ExitCode::FAILURE
        }
    }
}

/// Ctrl-C, or SIGTERM from systemd or Docker.
async fn shutdown_signal() {
    #[cfg(unix)]
    {
        let mut terminate = match tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate()) {
            Ok(signal) => signal,
            Err(_) => {
                let _ = tokio::signal::ctrl_c().await;
                return;
            }
        };
        tokio::select! {
            _ = tokio::signal::ctrl_c() => {}
            _ = terminate.recv() => {}
        }
    }
    #[cfg(not(unix))]
    {
        let _ = tokio::signal::ctrl_c().await;
    }
}
