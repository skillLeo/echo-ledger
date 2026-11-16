//! EchoLedger Live Assist relay: one WebSocket per call between an app and the model providers. The app sends the
//! call's context and the speech engine's turns; the relay builds each request in a cache-friendly order, starts a
//! suggestion early when the other person asks something, streams it back word by word, and keeps every session
//! inside its plan and budget. PROTOCOL.md describes the messages, README.md how to run it.

pub mod auth;
pub mod budget;
pub mod config;
pub mod context;
pub mod domain;
pub mod eval;
pub mod internal;
pub mod logging;
pub mod pack;
pub mod protocol;
pub mod provider;
pub mod registry;
pub mod server;
pub mod session;
pub mod skip;
pub mod telemetry;
pub mod text;
pub mod time;

use std::io::{Read, Write};
use std::net::{SocketAddr, TcpStream};
use std::process::ExitCode;
use std::sync::Arc;
use std::time::Duration;

use auth::TokenVerifier;
use config::{RelayConfig, Secret, Shared};
use internal::ReportSink;
use pack::Pack;
use provider::Providers;
use registry::Registry;
use server::AppState;
use session::SessionDeps;
use telemetry::TelemetrySink;

/// What a relay is built from.
#[derive(Debug)]
pub struct Parts {
    pub config: Arc<Shared<RelayConfig>>,
    pub pack: Pack,
    pub providers: Providers,
    pub telemetry: Arc<dyn TelemetrySink>,
    pub reports: Arc<dyn ReportSink>,
    /// The `assist_relay` secret (and the previous one during a rotation).
    pub secrets: Vec<Secret>,
    pub issuer: String,
}

/// Builds the shared state of a relay.
pub fn app_state(parts: Parts) -> AppState {
    let verifier = Arc::new(TokenVerifier::new(parts.secrets, parts.issuer));
    let deps = SessionDeps {
        config: parts.config.clone(),
        pack: Arc::new(Shared::new(parts.pack)),
        providers: Arc::new(parts.providers),
        telemetry: parts.telemetry.clone(),
        reports: parts.reports,
        verifier: verifier.clone(),
    };
    AppState {
        registry: Registry::new(deps),
        verifier,
        config: parts.config,
        telemetry: parts.telemetry,
        connections: Arc::default(),
    }
}

/// `assist-relay --check-health`: asks the local relay's `/healthz` (for container health checks, where there is no
/// curl). Exit code 0 when it answers 200.
pub fn health_check(bind: Option<&str>) -> ExitCode {
    let mut addr: SocketAddr = bind.and_then(|b| b.parse().ok()).unwrap_or_else(|| SocketAddr::from(([127, 0, 0, 1], 8787)));
    if addr.ip().is_unspecified() {
        addr.set_ip([127, 0, 0, 1].into());
    }
    let answer = (|| -> std::io::Result<String> {
        let mut stream = TcpStream::connect_timeout(&addr, Duration::from_secs(2))?;
        stream.set_read_timeout(Some(Duration::from_secs(2)))?;
        stream.write_all(b"GET /healthz HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")?;
        let mut head = [0u8; 64];
        let read = stream.read(&mut head)?;
        Ok(String::from_utf8_lossy(&head[..read]).into_owned())
    })();
    match answer {
        Ok(head) if head.starts_with("HTTP/1.1 200") => ExitCode::SUCCESS,
        _ => ExitCode::FAILURE,
    }
}
