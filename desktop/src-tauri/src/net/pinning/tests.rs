//! Pin verification against generated certificate chains (root -> intermediate -> leaf).

use std::sync::Arc;

use base64::Engine;
use rcgen::{
    BasicConstraints, CertificateParams, DnType, ExtendedKeyUsagePurpose, IsCa, Issuer, KeyPair,
    KeyUsagePurpose, PublicKeyData, date_time_ymd,
};
use rustls::client::WebPkiServerVerifier;
use rustls::client::danger::ServerCertVerifier;
use rustls::pki_types::{CertificateDer, ServerName, UnixTime};
use rustls::{CertificateError, Error as TlsError, RootCertStore};

use super::*;

const HOST: &str = "echo.test";

fn init() {
    let _ = rustls::crypto::ring::default_provider().install_default();
}

fn spki_hash(key: &KeyPair) -> [u8; 32] {
    ring::digest::digest(&ring::digest::SHA256, &key.subject_public_key_info())
        .as_ref()
        .try_into()
        .unwrap()
}

fn ca_params(name: &str) -> CertificateParams {
    let mut params = CertificateParams::new(Vec::new()).unwrap();
    params.is_ca = IsCa::Ca(BasicConstraints::Unconstrained);
    params.distinguished_name.push(DnType::CommonName, name);
    params.key_usages = vec![
        KeyUsagePurpose::KeyCertSign,
        KeyUsagePurpose::CrlSign,
        KeyUsagePurpose::DigitalSignature,
    ];
    params
}

fn leaf_params(host: &str) -> CertificateParams {
    let mut params = CertificateParams::new(vec![host.to_string()]).unwrap();
    params.distinguished_name.push(DnType::CommonName, host);
    params.key_usages = vec![KeyUsagePurpose::DigitalSignature];
    params.extended_key_usages = vec![ExtendedKeyUsagePurpose::ServerAuth];
    params
}

struct Chain {
    root: CertificateDer<'static>,
    intermediate: CertificateDer<'static>,
    leaf: CertificateDer<'static>,
    root_pin: [u8; 32],
    intermediate_pin: [u8; 32],
    leaf_pin: [u8; 32],
    leaf_key: KeyPair,
    issuer: Issuer<'static, KeyPair>,
}

fn chain_with(leaf: CertificateParams) -> Chain {
    init();
    let root_key = KeyPair::generate().unwrap();
    let root_params = ca_params("EchoLedger test root");
    let root_cert = root_params.self_signed(&root_key).unwrap();
    let root_pin = spki_hash(&root_key);
    let root_issuer = Issuer::new(root_params, root_key);

    let intermediate_key = KeyPair::generate().unwrap();
    let intermediate_params = ca_params("EchoLedger test intermediate");
    let intermediate_cert = intermediate_params
        .signed_by(&intermediate_key, &root_issuer)
        .unwrap();
    let intermediate_pin = spki_hash(&intermediate_key);
    let issuer = Issuer::new(intermediate_params, intermediate_key);

    let leaf_key = KeyPair::generate().unwrap();
    let leaf_cert = leaf.signed_by(&leaf_key, &issuer).unwrap();
    Chain {
        root: root_cert.der().clone(),
        intermediate: intermediate_cert.der().clone(),
        leaf: leaf_cert.der().clone(),
        root_pin,
        intermediate_pin,
        leaf_pin: spki_hash(&leaf_key),
        leaf_key,
        issuer,
    }
}

fn chain() -> Chain {
    chain_with(leaf_params(HOST))
}

fn verifier(trusted_root: &CertificateDer<'static>, pins: Vec<[u8; 32]>) -> SpkiPinVerifier {
    let mut store = RootCertStore::empty();
    store.add(trusted_root.clone()).unwrap();
    SpkiPinVerifier {
        inner: WebPkiServerVerifier::builder(Arc::new(store))
            .build()
            .unwrap(),
        pins,
    }
}

fn check(v: &SpkiPinVerifier, c: &Chain, host: &str) -> Result<(), TlsError> {
    v.verify_server_cert(
        &c.leaf,
        std::slice::from_ref(&c.intermediate),
        &ServerName::try_from(host.to_string()).unwrap(),
        &[],
        UnixTime::now(),
    )
    .map(|_| ())
}

fn is_pin_failure(result: &Result<(), TlsError>) -> bool {
    matches!(
        result,
        Err(TlsError::InvalidCertificate(
            CertificateError::ApplicationVerificationFailure
        ))
    )
}

fn is_invalid_because(result: &Result<(), TlsError>, what: &str) -> bool {
    matches!(result, Err(TlsError::InvalidCertificate(e)) if format!("{e:?}").contains(what))
}

// ---- SPKI hashing ----------------------------------------------------------------------------------------------

#[test]
fn the_spki_hash_is_the_sha256_of_the_subject_public_key_info() {
    let c = chain();
    assert_eq!(spki_sha256(&c.leaf), Some(c.leaf_pin));
    assert_eq!(spki_sha256(&c.intermediate), Some(c.intermediate_pin));
    assert_eq!(spki_sha256(&c.root), Some(c.root_pin));
    assert_ne!(c.leaf_pin, c.intermediate_pin);
    assert_ne!(c.intermediate_pin, c.root_pin);
}

#[test]
fn renewing_a_certificate_with_the_same_key_keeps_the_pin() {
    let c = chain();
    let mut renewed = leaf_params(HOST);
    renewed.not_before = date_time_ymd(2030, 1, 1);
    renewed.not_after = date_time_ymd(2031, 1, 1);
    let second = renewed.signed_by(&c.leaf_key, &c.issuer).unwrap();
    assert_ne!(
        second.der().as_ref(),
        c.leaf.as_ref(),
        "a different certificate"
    );
    assert_eq!(
        spki_sha256(second.der()),
        Some(c.leaf_pin),
        "the pin follows the key, not the certificate"
    );
}

#[test]
fn a_new_key_changes_the_pin() {
    let (a, b) = (chain(), chain());
    assert_ne!(a.leaf_pin, b.leaf_pin);
}

#[test]
fn bytes_that_are_not_a_certificate_have_no_hash() {
    assert_eq!(spki_sha256(&CertificateDer::from(Vec::<u8>::new())), None);
    assert_eq!(spki_sha256(&CertificateDer::from(vec![0u8; 64])), None);
    assert_eq!(
        spki_sha256(&CertificateDer::from(
            b"-----BEGIN CERTIFICATE-----".to_vec()
        )),
        None
    );
    let mut truncated = chain().leaf.as_ref().to_vec();
    truncated.truncate(truncated.len() / 2);
    assert_eq!(spki_sha256(&CertificateDer::from(truncated)), None);
}

// ---- Verifier --------------------------------------------------------------------------------------------------

#[test]
fn a_trusted_chain_whose_leaf_matches_the_pin_is_accepted() {
    let c = chain();
    assert!(check(&verifier(&c.root, vec![c.leaf_pin]), &c, HOST).is_ok());
}

#[test]
fn a_trusted_chain_with_a_different_key_than_pinned_is_refused() {
    let (c, other) = (chain(), chain());
    let result = check(&verifier(&c.root, vec![other.leaf_pin]), &c, HOST);
    assert!(is_pin_failure(&result), "{result:?}");
}

#[test]
fn the_backup_pin_is_accepted_wherever_it_sits_in_the_set() {
    let (c, unrelated) = (chain(), chain());
    assert!(
        check(
            &verifier(&c.root, vec![unrelated.leaf_pin, c.leaf_pin]),
            &c,
            HOST
        )
        .is_ok(),
        "current pin second"
    );
    assert!(
        check(
            &verifier(&c.root, vec![c.leaf_pin, unrelated.leaf_pin]),
            &c,
            HOST
        )
        .is_ok(),
        "current pin first"
    );
    assert!(
        is_pin_failure(&check(
            &verifier(
                &c.root,
                vec![unrelated.leaf_pin, unrelated.intermediate_pin]
            ),
            &c,
            HOST
        )),
        "neither pin matches"
    );
}

#[test]
fn pinning_the_intermediate_accepts_any_leaf_it_issued() {
    let c = chain();
    assert!(check(&verifier(&c.root, vec![c.intermediate_pin]), &c, HOST).is_ok());
}

#[test]
fn an_empty_pin_set_accepts_nothing() {
    let c = chain();
    assert!(is_pin_failure(&check(&verifier(&c.root, vec![]), &c, HOST)));
}

#[test]
fn a_matching_pin_does_not_rescue_a_chain_from_an_untrusted_issuer() {
    let (c, stranger) = (chain(), chain());
    let result = check(&verifier(&stranger.root, vec![c.leaf_pin]), &c, HOST);
    assert!(
        result.is_err() && !is_pin_failure(&result),
        "the chain itself must be refused before pins are consulted: {result:?}"
    );
}

#[test]
fn a_matching_pin_does_not_rescue_a_certificate_for_another_host() {
    let c = chain();
    let result = check(&verifier(&c.root, vec![c.leaf_pin]), &c, "evil.test");
    assert!(is_invalid_because(&result, "NotValidForName"), "{result:?}");
}

#[test]
fn a_matching_pin_does_not_rescue_an_expired_certificate() {
    let mut params = leaf_params(HOST);
    params.not_before = date_time_ymd(2001, 1, 1);
    params.not_after = date_time_ymd(2002, 1, 1);
    let c = chain_with(params);
    let result = check(&verifier(&c.root, vec![c.leaf_pin]), &c, HOST);
    assert!(is_invalid_because(&result, "Expired"), "{result:?}");
}

#[test]
fn a_certificate_that_is_not_yet_valid_is_refused() {
    let mut params = leaf_params(HOST);
    params.not_before = date_time_ymd(3500, 1, 1);
    params.not_after = date_time_ymd(3501, 1, 1);
    let c = chain_with(params);
    let result = check(&verifier(&c.root, vec![c.leaf_pin]), &c, HOST);
    assert!(is_invalid_because(&result, "NotValidYet"), "{result:?}");
}

#[test]
fn a_leaf_presented_without_its_intermediate_cannot_be_validated() {
    let c = chain();
    let v = verifier(&c.root, vec![c.leaf_pin]);
    let result = v
        .verify_server_cert(
            &c.leaf,
            &[],
            &ServerName::try_from(HOST.to_string()).unwrap(),
            &[],
            UnixTime::now(),
        )
        .map(|_| ());
    assert!(is_invalid_because(&result, "UnknownIssuer"), "{result:?}");
}

#[test]
fn the_signature_schemes_are_those_of_the_webpki_verifier() {
    let c = chain();
    let v = verifier(&c.root, vec![c.leaf_pin]);
    assert_eq!(
        v.supported_verify_schemes(),
        v.inner.supported_verify_schemes()
    );
    assert!(!v.supported_verify_schemes().is_empty());
}

// ---- Configuration ---------------------------------------------------------------------------------------------

#[test]
fn pins_round_trip_through_their_textual_form() {
    let c = chain();
    let text = format!(
        "sha256/{}",
        base64::engine::general_purpose::STANDARD.encode(c.leaf_pin)
    );
    assert_eq!(parse_pins(std::slice::from_ref(&text)), vec![c.leaf_pin]);
    assert_eq!(
        parse_pins(&[format!("  {text}\n")]),
        vec![c.leaf_pin],
        "surrounding whitespace is ignored"
    );
    assert_eq!(
        parse_pins(&[base64::engine::general_purpose::STANDARD.encode(c.leaf_pin)]),
        vec![c.leaf_pin],
        "a bare digest is accepted"
    );
}

#[test]
fn tls_configurations_advertise_http2_and_http1() {
    init();
    for pins in [
        vec![],
        vec![format!(
            "sha256/{}",
            base64::engine::general_purpose::STANDARD.encode([7u8; 32])
        )],
    ] {
        let config = server_tls_config(&pins).unwrap();
        assert_eq!(
            config.alpn_protocols,
            vec![b"h2".to_vec(), b"http/1.1".to_vec()]
        );
    }
    assert!(public_tls_config().alpn_protocols.is_empty());
}

#[test]
#[ignore = "A6.DR3: a configured pin set in which no pin parses must be refused, not treated as no pinning"]
fn a_pin_set_that_cannot_be_parsed_does_not_silently_turn_pinning_off() {
    init();
    for pins in [
        vec!["sha256/not-valid-base64!!".to_string()],
        vec!["sha256/short".to_string(), "garbage".to_string()],
    ] {
        let result = server_tls_config(&pins);
        assert!(
            result.is_err(),
            "pins {pins:?} configured, none usable, yet a WebPKI-only client was built"
        );
    }
}
