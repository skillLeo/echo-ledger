//! TLS for the EchoLedger server: normal WebPKI chain + hostname validation, then an SPKI SHA-256 pin check
//! against the pin set handed out at pairing (current + backup). docs/research/desktop-tauri.md §8.3.
//! Third-party STT WebSockets use plain WebPKI (no pin).

use std::sync::Arc;

use base64::Engine;
use rustls::client::WebPkiServerVerifier;
use rustls::client::danger::{HandshakeSignatureValid, ServerCertVerified, ServerCertVerifier};
use rustls::pki_types::{CertificateDer, ServerName, UnixTime};
use rustls::{CertificateError, DigitallySignedStruct, Error as TlsError, RootCertStore, SignatureScheme};

/// Parses pins of the form `sha256/<base64>` (or bare base64) into 32-byte digests; invalid entries are skipped.
pub fn parse_pins(pins: &[String]) -> Vec<[u8; 32]> {
    pins.iter()
        .filter_map(|p| {
            let b64 = p.trim().strip_prefix("sha256/").unwrap_or(p.trim());
            let bytes = base64::engine::general_purpose::STANDARD.decode(b64).ok()?;
            bytes.try_into().ok()
        })
        .collect()
}

pub fn spki_sha256(der: &CertificateDer<'_>) -> Option<[u8; 32]> {
    let cert = webpki::EndEntityCert::try_from(der).ok()?;
    ring::digest::digest(&ring::digest::SHA256, cert.subject_public_key_info().as_ref()).as_ref().try_into().ok()
}

#[derive(Debug)]
pub struct SpkiPinVerifier {
    inner: Arc<WebPkiServerVerifier>,
    pins: Vec<[u8; 32]>,
}

impl ServerCertVerifier for SpkiPinVerifier {
    fn verify_server_cert(
        &self,
        end_entity: &CertificateDer<'_>,
        intermediates: &[CertificateDer<'_>],
        server_name: &ServerName<'_>,
        ocsp: &[u8],
        now: UnixTime,
    ) -> Result<ServerCertVerified, TlsError> {
        // Chain, hostname and expiry first; the pin is an additional constraint, never a replacement.
        self.inner.verify_server_cert(end_entity, intermediates, server_name, ocsp, now)?;
        let pinned = std::iter::once(end_entity).chain(intermediates.iter()).filter_map(spki_sha256).any(|h| self.pins.contains(&h));
        if pinned { Ok(ServerCertVerified::assertion()) } else { Err(TlsError::InvalidCertificate(CertificateError::ApplicationVerificationFailure)) }
    }

    fn verify_tls12_signature(&self, m: &[u8], c: &CertificateDer<'_>, d: &DigitallySignedStruct) -> Result<HandshakeSignatureValid, TlsError> {
        self.inner.verify_tls12_signature(m, c, d)
    }

    fn verify_tls13_signature(&self, m: &[u8], c: &CertificateDer<'_>, d: &DigitallySignedStruct) -> Result<HandshakeSignatureValid, TlsError> {
        self.inner.verify_tls13_signature(m, c, d)
    }

    fn supported_verify_schemes(&self) -> Vec<SignatureScheme> {
        self.inner.supported_verify_schemes()
    }
}

fn roots() -> Arc<RootCertStore> {
    Arc::new(RootCertStore { roots: webpki_roots::TLS_SERVER_ROOTS.to_vec() })
}

/// rustls config for the EchoLedger server. With no pins configured it falls back to WebPKI only.
pub fn server_tls_config(pins: &[String]) -> Result<rustls::ClientConfig, String> {
    let parsed = parse_pins(pins);
    let mut config = if parsed.is_empty() {
        rustls::ClientConfig::builder().with_root_certificates(roots()).with_no_client_auth()
    } else {
        let inner = WebPkiServerVerifier::builder(roots()).build().map_err(|e| e.to_string())?;
        rustls::ClientConfig::builder()
            .dangerous()
            .with_custom_certificate_verifier(Arc::new(SpkiPinVerifier { inner, pins: parsed }))
            .with_no_client_auth()
    };
    config.alpn_protocols = vec![b"h2".to_vec(), b"http/1.1".to_vec()];
    Ok(config)
}

/// WebPKI-only config for third-party WebSockets (AssemblyAI / ElevenLabs).
pub fn public_tls_config() -> Arc<rustls::ClientConfig> {
    Arc::new(rustls::ClientConfig::builder().with_root_certificates(roots()).with_no_client_auth())
}

/// True for plain-HTTP local development servers, where TLS/pinning cannot apply.
pub fn is_local_dev(base_url: &str) -> bool {
    let lower = base_url.to_ascii_lowercase();
    lower.starts_with("http://localhost") || lower.starts_with("http://127.0.0.1") || lower.starts_with("http://[::1]")
}

pub fn build_http_client(base_url: &str, pins: &[String]) -> Result<reqwest::Client, String> {
    let builder = reqwest::Client::builder()
        .connect_timeout(std::time::Duration::from_secs(10))
        .timeout(std::time::Duration::from_secs(120))
        .user_agent(concat!("EchoLedger-Desktop/", env!("CARGO_PKG_VERSION")));
    let builder = if is_local_dev(base_url) {
        builder
    } else {
        builder.https_only(true).tls_backend_preconfigured(server_tls_config(pins)?)
    };
    builder.build().map_err(|e| e.to_string())
}

#[cfg(test)]
mod tests;
