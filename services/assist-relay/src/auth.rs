//! Assist tokens: compact JWS (HS256) tokens the server mints at `assist/start` and `assist/token`, bound to one user,
//! one assist session, the plan, the allowed modes and models, and the session's budget, for at most 15 minutes.
//!
//! Only HS256 is accepted, the signature is checked in constant time before the claims are read, and no part of a
//! token is ever logged: errors name the reason only.

use base64::Engine as _;
use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use hmac::{Hmac, KeyInit, Mac};
use serde::{Deserialize, Serialize};
use sha2::Sha256;
use thiserror::Error;

use crate::config::Secret;
use crate::domain::{Mode, Plan};

type HmacSha256 = Hmac<Sha256>;

/// The `aud` every assist token carries.
pub const AUDIENCE: &str = "assist-relay";
/// The longest a token may live, from `iat` to `exp`.
pub const MAX_TTL_SECS: i64 = 15 * 60;
/// Clock difference allowed between the server and the relay.
pub const LEEWAY_SECS: i64 = 30;
/// A longer header value is not a token.
pub const MAX_TOKEN_BYTES: usize = 4096;
const MAX_ID_CHARS: usize = 128;
const MAX_MODELS: usize = 32;

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Claims {
    pub iss: String,
    pub aud: String,
    /// The user.
    pub sub: String,
    /// The workspace.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub ws: Option<String>,
    /// The assist session.
    pub sid: String,
    pub plan: Plan,
    /// Modes the plan allows for this session.
    pub modes: Vec<Mode>,
    /// Model ids this session may use (primary, fallback and upgrade models alike).
    pub models: Vec<String>,
    pub budget: BudgetClaim,
    /// A Practice session: the questions come from the model, not from a call.
    #[serde(default)]
    pub practice: bool,
    pub iat: i64,
    pub exp: i64,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub jti: Option<String>,
}

/// What the person has left when the server mints the token. The relay counts down from it: what this session
/// spends, and the time it stays connected, after the relay received the token.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub struct BudgetClaim {
    /// Assist time left, in seconds.
    pub seconds_left: u64,
    /// Model budget left, in US cents.
    pub cents_left: u64,
}

impl Claims {
    /// The model budget left at minting, in micro-dollars.
    pub fn budget_micros(&self) -> u64 {
        self.budget.cents_left.saturating_mul(10_000)
    }

    /// Tells two tokens apart: a reconnect with the same token keeps counting from where it was.
    pub fn token_key(&self) -> (i64, Option<String>) {
        (self.iat, self.jti.clone())
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Error)]
pub enum AuthError {
    #[error("no assist token was sent")]
    Missing,
    #[error("the assist token is not a signed token")]
    Malformed,
    #[error("the assist token uses an algorithm this relay does not accept")]
    Algorithm,
    #[error("the assist token's signature does not match")]
    Signature,
    #[error("the assist token has expired")]
    Expired,
    #[error("the assist token is not valid yet")]
    NotYetValid,
    #[error("the assist token lives longer than 15 minutes")]
    TooLong,
    #[error("the assist token was not issued for this relay")]
    Audience,
    #[error("the assist token's claims are not valid")]
    Claims,
    #[error("the assist token is for another session")]
    WrongSession,
    #[error("the assist token is older than the one this session already uses")]
    Superseded,
}

impl AuthError {
    /// The machine-readable code sent to the app.
    pub fn code(self) -> &'static str {
        match self {
            AuthError::Missing => "token_missing",
            AuthError::Expired => "token_expired",
            AuthError::WrongSession => "wrong_session",
            _ => "token_invalid",
        }
    }

    /// The reason, for logs and telemetry (never the token).
    pub fn reason(self) -> &'static str {
        match self {
            AuthError::Missing => "missing",
            AuthError::Malformed => "malformed",
            AuthError::Algorithm => "algorithm",
            AuthError::Signature => "signature",
            AuthError::Expired => "expired",
            AuthError::NotYetValid => "not_yet_valid",
            AuthError::TooLong => "too_long",
            AuthError::Audience => "audience",
            AuthError::Claims => "claims",
            AuthError::WrongSession => "wrong_session",
            AuthError::Superseded => "superseded",
        }
    }
}

#[derive(Deserialize)]
struct Header {
    alg: String,
    #[serde(default)]
    typ: Option<String>,
    #[serde(default)]
    crit: Option<serde_json::Value>,
}

/// Checks assist tokens against the current secret and, during a rotation, the previous one.
pub struct TokenVerifier {
    keys: Vec<Secret>,
    issuer: String,
}

impl std::fmt::Debug for TokenVerifier {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("TokenVerifier").field("keys", &self.keys.len()).field("issuer", &self.issuer).finish()
    }
}

impl TokenVerifier {
    pub fn new(keys: Vec<Secret>, issuer: impl Into<String>) -> Self {
        TokenVerifier { keys, issuer: issuer.into() }
    }

    /// Verifies `token` at unix time `now` and returns its claims.
    pub fn verify(&self, token: &str, now: i64) -> Result<Claims, AuthError> {
        if token.is_empty() {
            return Err(AuthError::Missing);
        }
        if token.len() > MAX_TOKEN_BYTES {
            return Err(AuthError::Malformed);
        }
        let mut parts = token.split('.');
        let (Some(header_b64), Some(payload_b64), Some(signature_b64), None) =
            (parts.next(), parts.next(), parts.next(), parts.next())
        else {
            return Err(AuthError::Malformed);
        };
        let header: Header = decode_json(header_b64)?;
        if header.alg != "HS256" || header.crit.is_some() || header.typ.as_deref().is_some_and(|t| t != "JWT") {
            return Err(AuthError::Algorithm);
        }
        let signature = URL_SAFE_NO_PAD.decode(signature_b64).map_err(|_| AuthError::Malformed)?;
        let signed = &token[..header_b64.len() + 1 + payload_b64.len()];
        if !self.keys.iter().any(|key| signature_matches(key, signed.as_bytes(), &signature)) {
            return Err(AuthError::Signature);
        }
        let claims: Claims = decode_json(payload_b64).map_err(|_| AuthError::Claims)?;
        if claims.iss != self.issuer || claims.aud != AUDIENCE {
            return Err(AuthError::Audience);
        }
        if now >= claims.exp.saturating_add(LEEWAY_SECS) {
            return Err(AuthError::Expired);
        }
        if claims.iat > now.saturating_add(LEEWAY_SECS) {
            return Err(AuthError::NotYetValid);
        }
        if claims.exp.saturating_sub(claims.iat) > MAX_TTL_SECS || claims.exp.saturating_sub(now) > MAX_TTL_SECS + LEEWAY_SECS {
            return Err(AuthError::TooLong);
        }
        if !valid_id(&claims.sub)
            || !valid_id(&claims.sid)
            || claims.ws.as_deref().is_some_and(|w| !valid_id(w))
            || claims.jti.as_deref().is_some_and(|j| !valid_id(j))
            || claims.models.len() > MAX_MODELS
            || claims.models.iter().any(|m| !valid_id(m))
        {
            return Err(AuthError::Claims);
        }
        Ok(claims)
    }

    /// As `verify`, and the token must name `session_id`.
    pub fn verify_for_session(&self, token: &str, session_id: &str, now: i64) -> Result<Claims, AuthError> {
        let claims = self.verify(token, now)?;
        if claims.sid != session_id {
            return Err(AuthError::WrongSession);
        }
        Ok(claims)
    }
}

fn decode_json<T: for<'de> Deserialize<'de>>(part: &str) -> Result<T, AuthError> {
    let bytes = URL_SAFE_NO_PAD.decode(part).map_err(|_| AuthError::Malformed)?;
    serde_json::from_slice(&bytes).map_err(|_| AuthError::Malformed)
}

fn signature_matches(key: &Secret, signed: &[u8], signature: &[u8]) -> bool {
    let Ok(mut mac) = HmacSha256::new_from_slice(key.expose()) else {
        return false;
    };
    mac.update(signed);
    mac.verify_slice(signature).is_ok()
}

/// Ids are opaque: ULIDs, numbers or model names such as `openai/gpt-oss-120b`.
fn valid_id(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= MAX_ID_CHARS
        && value.bytes().all(|b| b.is_ascii_alphanumeric() || matches!(b, b'-' | b'_' | b'.' | b':' | b'/'))
}

/// Mints tokens the way the server does. The relay itself never mints: the tests and the load test use this.
pub struct TokenSigner {
    key: Secret,
}

impl std::fmt::Debug for TokenSigner {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("TokenSigner(<redacted>)")
    }
}

impl TokenSigner {
    pub fn new(key: Secret) -> Self {
        TokenSigner { key }
    }

    pub fn sign(&self, claims: &Claims) -> String {
        let header = URL_SAFE_NO_PAD.encode(br#"{"alg":"HS256","typ":"JWT"}"#);
        let payload = URL_SAFE_NO_PAD.encode(serde_json::to_vec(claims).unwrap_or_default());
        let signed = format!("{header}.{payload}");
        format!("{signed}.{}", URL_SAFE_NO_PAD.encode(self.mac(signed.as_bytes())))
    }

    fn mac(&self, signed: &[u8]) -> Vec<u8> {
        let mut mac = HmacSha256::new_from_slice(self.key.expose()).unwrap_or_else(|_| unreachable!("HMAC takes any key length"));
        mac.update(signed);
        mac.finalize().into_bytes().to_vec()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const SECRET: &str = "0123456789abcdefghijklmnopqrstuvwxyz-test-only";
    const NOW: i64 = 1_800_000_000;

    fn claims() -> Claims {
        Claims {
            iss: "echoledger".into(),
            aud: AUDIENCE.into(),
            sub: "42".into(),
            ws: Some("7".into()),
            sid: "01JSESSION".into(),
            plan: Plan::Pro,
            modes: vec![Mode::Fast, Mode::Balanced],
            models: vec!["claude-haiku-5-5".into(), "claude-sonnet-5-5".into()],
            budget: BudgetClaim { seconds_left: 36_000, cents_left: 150 },
            practice: false,
            iat: NOW - 10,
            exp: NOW + 600,
            jti: Some("01JTOKEN".into()),
        }
    }

    fn verifier() -> TokenVerifier {
        TokenVerifier::new(vec![Secret::new(SECRET)], "echoledger")
    }

    fn sign(claims: &Claims) -> String {
        TokenSigner::new(Secret::new(SECRET)).sign(claims)
    }

    #[test]
    fn a_good_token_round_trips() {
        let token = sign(&claims());
        assert_eq!(verifier().verify_for_session(&token, "01JSESSION", NOW), Ok(claims()));
        assert_eq!(claims().budget_micros(), 1_500_000);
    }

    #[test]
    fn a_token_signed_with_another_secret_is_refused() {
        let token = TokenSigner::new(Secret::new("another-secret-that-is-long-enough-0000")).sign(&claims());
        assert_eq!(verifier().verify(&token, NOW), Err(AuthError::Signature));
    }

    #[test]
    fn a_changed_payload_breaks_the_signature() {
        let token = sign(&claims());
        let mut forged = claims();
        forged.plan = Plan::Ultra;
        forged.modes.push(Mode::Max);
        let forged_payload = URL_SAFE_NO_PAD.encode(serde_json::to_vec(&forged).unwrap());
        let parts: Vec<&str> = token.split('.').collect();
        let tampered = format!("{}.{}.{}", parts[0], forged_payload, parts[2]);
        assert_eq!(verifier().verify(&tampered, NOW), Err(AuthError::Signature));
    }

    #[test]
    fn expiry_allows_only_the_clock_leeway() {
        let token = sign(&claims());
        assert!(verifier().verify(&token, NOW + 600 + LEEWAY_SECS - 1).is_ok());
        assert_eq!(verifier().verify(&token, NOW + 600 + LEEWAY_SECS), Err(AuthError::Expired));
    }

    #[test]
    fn tokens_longer_than_fifteen_minutes_are_refused() {
        let mut long = claims();
        long.exp = long.iat + MAX_TTL_SECS + 1;
        assert_eq!(verifier().verify(&sign(&long), NOW), Err(AuthError::TooLong));
        let mut backdated = claims();
        backdated.iat = NOW - 3600;
        backdated.exp = NOW + MAX_TTL_SECS + LEEWAY_SECS + 1;
        assert_eq!(verifier().verify(&sign(&backdated), NOW), Err(AuthError::TooLong));
    }

    #[test]
    fn a_token_from_the_future_is_refused() {
        let mut future = claims();
        future.iat = NOW + 120;
        future.exp = NOW + 700;
        assert_eq!(verifier().verify(&sign(&future), NOW), Err(AuthError::NotYetValid));
    }

    #[test]
    fn the_wrong_session_issuer_or_audience_is_refused() {
        let token = sign(&claims());
        assert_eq!(verifier().verify_for_session(&token, "01JOTHER", NOW), Err(AuthError::WrongSession));
        let mut other_audience = claims();
        other_audience.aud = "console".into();
        assert_eq!(verifier().verify(&sign(&other_audience), NOW), Err(AuthError::Audience));
        let stranger = TokenVerifier::new(vec![Secret::new(SECRET)], "someone-else");
        assert_eq!(stranger.verify(&token, NOW), Err(AuthError::Audience));
    }

    #[test]
    fn only_hs256_is_accepted() {
        let token = sign(&claims());
        let payload_and_signature = token.split_once('.').unwrap().1;
        for header in [r#"{"alg":"none","typ":"JWT"}"#, r#"{"alg":"HS512"}"#, r#"{"alg":"HS256","crit":["exp"]}"#] {
            let forged = format!("{}.{payload_and_signature}", URL_SAFE_NO_PAD.encode(header));
            assert_eq!(verifier().verify(&forged, NOW), Err(AuthError::Algorithm), "{header}");
        }
        let unsigned = format!("{}.", token.rsplit_once('.').unwrap().0);
        assert_eq!(verifier().verify(&unsigned, NOW), Err(AuthError::Signature));
    }

    #[test]
    fn malformed_tokens_are_refused() {
        for token in ["", "abc", "a.b", "a.b.c.d", "!!!.###.$$$", &"a".repeat(MAX_TOKEN_BYTES + 1)] {
            assert!(verifier().verify(token, NOW).is_err(), "{token}");
        }
        assert_eq!(verifier().verify("", NOW), Err(AuthError::Missing));
    }

    #[test]
    fn odd_ids_are_refused() {
        let mut odd = claims();
        odd.sid = "has space".into();
        assert_eq!(verifier().verify(&sign(&odd), NOW), Err(AuthError::Claims));
        let mut odd = claims();
        odd.jti = Some(String::new());
        assert_eq!(verifier().verify(&sign(&odd), NOW), Err(AuthError::Claims));
    }

    #[test]
    fn the_contracts_claims_parse() {
        let json = r#"{"iss":"echoledger","aud":"assist-relay","sub":"42","ws":"7","sid":"01JC","plan":"ultra",
            "modes":["fast","balanced","deep","max"],"models":["claude-opus-5-5"],"budget":{"seconds_left":3600,"cents_left":250},
            "practice":true,"iat":1,"exp":2,"jti":"j"}"#;
        let claims: Claims = serde_json::from_str(json).unwrap();
        assert!(claims.practice);
        assert_eq!(claims.budget, BudgetClaim { seconds_left: 3600, cents_left: 250 });
        assert_eq!(claims.budget_micros(), 2_500_000);
        let missing_budget = json.replace(r#""budget":{"seconds_left":3600,"cents_left":250},"#, "");
        assert!(serde_json::from_str::<Claims>(&missing_budget).is_err());
    }

    #[test]
    fn the_previous_secret_still_verifies_during_a_rotation() {
        let old = "the-previous-secret-still-long-enough-0001";
        let rotating = TokenVerifier::new(vec![Secret::new(SECRET), Secret::new(old)], "echoledger");
        let token = TokenSigner::new(Secret::new(old)).sign(&claims());
        assert!(rotating.verify(&token, NOW).is_ok());
        assert_eq!(verifier().verify(&token, NOW), Err(AuthError::Signature));
    }

    #[test]
    fn errors_never_carry_the_token() {
        let token = sign(&claims());
        let error = verifier().verify_for_session(&token, "01JOTHER", NOW).unwrap_err();
        let shown = format!("{error} {error:?} {} {}", error.code(), error.reason());
        assert!(!shown.contains(&token[..20]));
    }
}
