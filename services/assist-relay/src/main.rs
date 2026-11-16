//! The relay binary: settings from the environment (README, "Settings"), JSON logs on standard output.

use std::process::ExitCode;
use std::sync::Arc;

use assist_relay::config::{ProviderMode, RelayConfig, Settings};
use assist_relay::instructions::InstructionPack;
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
    let config = match RelayConfig::load(settings.config_path.as_ref()) {
        Ok(config) => config,
        Err(error) => {
            tracing::error!(event = "startup_failed", reason = %error);
            return ExitCode::from(2);
        }
    };
    let instructions_path = std::env::var_os("RELAY_INSTRUCTIONS").map(std::path::PathBuf::from);
    let pack = match InstructionPack::load(instructions_path.as_deref()) {
        Ok(pack) => pack,
        Err(error) => {
            tracing::error!(event = "startup_failed", reason = %error);
            return ExitCode::from(2);
        }
    };
    let providers = match settings.providers {
        ProviderMode::Live => Providers::live(),
        ProviderMode::Mock => Providers::mock(&config, Arc::new(MockStats::default())).0,
    };
    let state = assist_relay::app_state(
        config,
        pack,
        providers,
        Arc::new(LogSink),
        settings.token_secrets.clone(),
        &settings.token_issuer,
    );
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
    tracing::info!(event = "listening", addr = %settings.bind, providers, version = env!("CARGO_PKG_VERSION"));
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
