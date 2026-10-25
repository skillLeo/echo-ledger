//! Certificate pinning at the edges: pin parsing, agreement with OpenSSL's SPKI hash, and the behaviour of the
//! HTTPS client against a server whose certificate it cannot trust.

mod common;

use std::io::{Read, Write};
use std::net::TcpListener;
use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::Duration;

use base64::Engine;
use rcgen::{CertificateParams, KeyPair, PublicKeyData};
use rustls::pki_types::{CertificateDer, PrivateKeyDer, PrivatePkcs8KeyDer};

use common::{MockServer, Router, fixture_text};
use desktop_lib::error::AppError;
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::pinning::{build_http_client, is_local_dev, parse_pins, spki_sha256};

fn pem_to_der(pem: &str) -> Vec<u8> {
    let body: String = pem.lines().filter(|l| !l.starts_with("-----")).collect();
    base64::engine::general_purpose::STANDARD
        .decode(body)
        .expect("base64 body")
}

fn b64(bytes: impl AsRef<[u8]>) -> String {
    base64::engine::general_purpose::STANDARD.encode(bytes)
}

// ---- Pin parsing -----------------------------------------------------------------------------------------------

#[test]
fn only_exact_sha256_digests_are_accepted_as_pins() {
    let digest = [0xabu8; 32];
    let good = format!("sha256/{}", b64(digest));
    let pins = vec![
        good.clone(),
        b64(digest),                          // bare digest
        format!("  {good}  "),                // padded with whitespace
        format!("sha256/{}", b64([1u8; 31])), // too short
        format!("sha256/{}", b64([1u8; 33])), // too long
        format!("sha256/{}", b64([1u8; 16])), // an MD5-sized value
        "sha256/".to_string(),                // nothing after the prefix
        "".to_string(),
        "sha256/@@@@".to_string(),
        "sha1/AAAAAAAAAAAAAAAAAAAAAAAAAAA=".to_string(), // another algorithm
    ];
    let parsed = parse_pins(&pins);
    assert_eq!(parsed, vec![digest, digest, digest]);
    assert!(parse_pins(&[]).is_empty());
}

#[test]
fn pins_keep_their_order_and_duplicates() {
    let (a, b) = ([1u8; 32], [2u8; 32]);
    let pins = vec![
        format!("sha256/{}", b64(a)),
        format!("sha256/{}", b64(b)),
        format!("sha256/{}", b64(a)),
    ];
    assert_eq!(parse_pins(&pins), vec![a, b, a]);
}

// ---- Agreement with OpenSSL ------------------------------------------------------------------------------------

#[test]
fn spki_hashes_equal_the_pins_openssl_computes_for_rsa_and_ec_certificates() {
    // Fixture pins were produced with:
    //   openssl x509 -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | openssl enc -base64
    for name in ["rsa", "ec"] {
        let der = CertificateDer::from(pem_to_der(&fixture_text(&format!("pinning/{name}.pem"))));
        let expected = parse_pins(&[fixture_text(&format!("pinning/{name}.pin"))]);
        assert_eq!(expected.len(), 1, "{name} reference pin");
        assert_eq!(
            spki_sha256(&der),
            Some(expected[0]),
            "{name}: SPKI hash differs from OpenSSL's"
        );
    }
}

// ---- Local development detection -------------------------------------------------------------------------------

#[test]
fn loopback_urls_are_recognised_case_insensitively() {
    for url in [
        "http://localhost",
        "http://localhost:8000",
        "http://localhost:8000/api",
        "HTTP://LOCALHOST:8000",
        "http://127.0.0.1",
        "http://127.0.0.1:8080/x",
        "http://[::1]:9000",
    ] {
        assert!(is_local_dev(url), "{url}");
    }
}

#[test]
fn other_urls_are_not_local() {
    for url in [
        "https://localhost",
        "https://ledger.example.com",
        "http://ledger.example.com",
        "http://127.0.0.2",
        "http://0.0.0.0:8000",
        "http://10.0.0.5",
        "",
        "localhost",
    ] {
        assert!(!is_local_dev(url), "{url}");
    }
}

#[test]
#[ignore = "A6.DR2: the loopback check must parse the URL, not compare string prefixes"]
fn look_alike_hosts_are_not_local() {
    let wrongly_local: Vec<&str> = [
        "http://localhost.attacker.example/",
        "http://localhost@attacker.example/",
        "http://127.0.0.1.attacker.example/",
        "http://127.0.0.1@attacker.example/",
        "http://localhostile.example",
    ]
    .into_iter()
    .filter(|url| is_local_dev(url))
    .collect();
    assert!(
        wrongly_local.is_empty(),
        "treated as plain-HTTP development servers: {wrongly_local:?}"
    );
}

// ---- HTTP client policy ----------------------------------------------------------------------------------------

#[tokio::test]
async fn a_remote_client_never_speaks_plain_http_even_if_asked_to() {
    common::init_tls();
    let server = MockServer::start(Router::new());
    let client = build_http_client("https://ledger.example.com", &[]).unwrap();
    let result = client
        .get(format!("{}/anything", server.base_url()))
        .send()
        .await;
    assert!(
        result.is_err(),
        "an http:// request was allowed by an https-only client"
    );
    assert!(
        server.requests().is_empty(),
        "nothing may reach the plain-HTTP server"
    );
}

#[tokio::test]
async fn a_loopback_client_may_use_plain_http() {
    let server = MockServer::start(Router::new().fixed(
        "GET",
        "/anything",
        common::Reply::json(200, serde_json::json!({"ok": true})),
    ));
    let client = build_http_client(&server.base_url(), &[]).unwrap();
    let response = client
        .get(format!("{}/anything", server.base_url()))
        .send()
        .await
        .unwrap();
    assert_eq!(response.status(), 200);
}

// ---- A server the client cannot trust --------------------------------------------------------------------------

struct TlsServer {
    port: u16,
    completed_handshakes: Arc<AtomicUsize>,
    key: KeyPair,
}

fn start_tls_server() -> TlsServer {
    common::init_tls();
    let key = KeyPair::generate().unwrap();
    let cert = CertificateParams::new(vec!["127.0.0.1".to_string(), "localhost".to_string()])
        .unwrap()
        .self_signed(&key)
        .unwrap();
    let config = rustls::ServerConfig::builder()
        .with_no_client_auth()
        .with_single_cert(
            vec![cert.der().clone()],
            PrivateKeyDer::Pkcs8(PrivatePkcs8KeyDer::from(key.serialize_der())),
        )
        .unwrap();
    let config = Arc::new(config);
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    let completed = Arc::new(AtomicUsize::new(0));
    let counter = completed.clone();
    std::thread::spawn(move || {
        for stream in listener.incoming() {
            let Ok(mut tcp) = stream else { break };
            let _ = tcp.set_read_timeout(Some(Duration::from_secs(5)));
            let Ok(mut conn) = rustls::ServerConnection::new(config.clone()) else {
                continue;
            };
            let mut tls = rustls::Stream::new(&mut conn, &mut tcp);
            let mut buf = [0u8; 1024];
            if tls.read(&mut buf).is_ok_and(|n| n > 0) {
                counter.fetch_add(1, Ordering::SeqCst);
                let _ = tls.write_all(
                    b"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}",
                );
            }
        }
    });
    TlsServer {
        port,
        completed_handshakes: completed,
        key,
    }
}

#[tokio::test]
async fn a_server_with_a_self_signed_certificate_is_refused_with_or_without_pins() {
    let server = start_tls_server();
    let matching_pin = format!(
        "sha256/{}",
        b64(ring::digest::digest(
            &ring::digest::SHA256,
            &server.key.subject_public_key_info()
        ))
    );
    let mismatching_pin = format!("sha256/{}", b64([9u8; 32]));
    for pins in [vec![], vec![matching_pin], vec![mismatching_pin]] {
        let client = ApiClient::new(
            &format!("https://127.0.0.1:{}", server.port),
            &pins,
            Some("tok".into()),
        )
        .unwrap();
        let outcome = tokio::time::timeout(Duration::from_secs(15), client.config())
            .await
            .expect("the TLS failure should be immediate");
        assert!(
            matches!(outcome, Err(AppError::Network(_))),
            "pins {pins:?}: {outcome:?}"
        );
    }
    assert_eq!(
        server.completed_handshakes.load(Ordering::SeqCst),
        0,
        "no request may be sent over an unverified connection"
    );
}

#[tokio::test]
#[ignore = "A6.DR12: TLS and pin failures must be reported as such, not as a generic network error"]
async fn a_tls_failure_is_distinguishable_from_the_server_being_offline() {
    // A user whose pin no longer matches (or who sits behind an interception proxy) needs to be told that the
    // certificate is the problem, not that "the network" failed.
    let server = start_tls_server();
    let client = ApiClient::new(
        &format!("https://127.0.0.1:{}", server.port),
        &[],
        Some("tok".into()),
    )
    .unwrap();
    let message = match client.config().await {
        Err(AppError::Network(message)) => message.to_ascii_lowercase(),
        other => panic!("{other:?}"),
    };
    assert!(
        message.contains("certificate")
            || message.contains("tls")
            || message.contains("ssl")
            || message.contains("unknownissuer"),
        "the error carries no TLS detail: {message:?}"
    );
}

#[tokio::test]
async fn an_https_server_that_is_down_is_a_network_error() {
    common::init_tls();
    let port = {
        let l = TcpListener::bind("127.0.0.1:0").unwrap();
        l.local_addr().unwrap().port()
    };
    let client = ApiClient::new(
        &format!("https://127.0.0.1:{port}"),
        &[],
        Some("tok".into()),
    )
    .unwrap();
    assert!(matches!(client.config().await, Err(AppError::Network(_))));
}
