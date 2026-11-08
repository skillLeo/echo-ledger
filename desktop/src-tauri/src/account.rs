//! Signing in with an account (docs/API.md "Signing in with an account", since v1.5): email and password (with the
//! two-factor code when the account has one), or the browser (Google, passkeys and the rest of the console's sign-in
//! page) with PKCE S256 and the `echoledger://signed-in` link. Pairing codes stay for older servers.
//!
//! The browser sign-in's verifier and state stay in this process's memory: nothing is written to disk, and a link that
//! comes back is checked against them before its code is sent anywhere. The link arrives like `echoledger://open`
//! (Windows passes it to the running app through the single-instance callback, macOS as an opened URL), and it is
//! untrusted input from any web page: a link with another state, an expired wait or an odd code is refused.

use std::time::{Duration, Instant};

use base64::Engine;
use ring::rand::{SecureRandom, SystemRandom};

/// Where the browser is sent back to once the person signed in.
pub const REDIRECT_URI: &str = "echoledger://signed-in";
/// How long a browser sign-in waits for its link: the server's code lives 5 minutes, the sign-in page takes a while.
pub const MAX_WAIT: Duration = Duration::from_secs(15 * 60);
/// Longer codes or states are not the server's.
const MAX_PART_CHARS: usize = 512;

/// A random value of `bytes` bytes, base64url without padding (32 bytes give the 43 characters PKCE asks for).
fn random_token(bytes: usize) -> Result<String, String> {
    let mut buf = vec![0u8; bytes];
    SystemRandom::new().fill(&mut buf).map_err(|_| "No random numbers for the sign-in.".to_string())?;
    Ok(base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(buf))
}

/// `BASE64URL(SHA-256(verifier))`: the `code_challenge` of PKCE's S256 method (RFC 7636).
pub fn challenge_of(verifier: &str) -> String {
    let digest = ring::digest::digest(&ring::digest::SHA256, verifier.as_bytes());
    base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(digest.as_ref())
}

/// The same bytes, compared without stopping at the first difference.
fn same(a: &str, b: &str) -> bool {
    a.len() == b.len() && a.bytes().zip(b.bytes()).fold(0u8, |acc, (x, y)| acc | (x ^ y)) == 0
}

/// A code or state as the server writes them: letters, digits and `-._~` (URL-safe), not empty, not too long.
fn plain(part: &str) -> bool {
    !part.is_empty() && part.len() <= MAX_PART_CHARS && part.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.' | '~'))
}

/// A browser sign-in that waits for its link: the server it was started with (and its pins, from a QR or an earlier
/// pairing), the PKCE verifier, the state and the device name the browser was told.
#[derive(Clone, Debug)]
pub struct BrowserSignIn {
    pub server: String,
    pub pins: Vec<String>,
    pub verifier: String,
    pub state: String,
    pub name: String,
    pub started: Instant,
}

impl BrowserSignIn {
    pub fn start(server: &str, pins: Vec<String>, name: &str, now: Instant) -> Result<Self, String> {
        Ok(Self { server: server.trim().trim_end_matches('/').to_string(), pins, verifier: random_token(32)?, state: random_token(24)?, name: name.to_string(), started: now })
    }

    /// The server's sign-in page for this computer: `<server>/device/sign-in?code_challenge=…&code_challenge_method=S256
    /// &state=…&redirect_uri=echoledger://signed-in&name=…&platform=…`.
    pub fn url(&self, platform: &str) -> Result<String, String> {
        let mut url = reqwest::Url::parse(&format!("{}/device/sign-in", self.server)).map_err(|_| "The server address is not valid.".to_string())?;
        url.query_pairs_mut()
            .append_pair("code_challenge", &challenge_of(&self.verifier))
            .append_pair("code_challenge_method", "S256")
            .append_pair("state", &self.state)
            .append_pair("redirect_uri", REDIRECT_URI)
            .append_pair("name", &self.name)
            .append_pair("platform", platform);
        Ok(url.to_string())
    }

    pub fn expired(&self, now: Instant) -> bool {
        now.saturating_duration_since(self.started) > MAX_WAIT
    }
}

/// What `echoledger://signed-in?…` brought back: the one-time code, or the reason the browser gave instead.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Callback {
    pub code: Option<String>,
    pub state: String,
    pub error: Option<String>,
}

/// The `echoledger://signed-in` link in `arg` (also `echoledger:signed-in?…`), or none for anything else.
pub fn signed_in_link(arg: &str) -> Option<Callback> {
    let arg = arg.trim().trim_matches('"');
    if arg.len() > 4 * MAX_PART_CHARS {
        return None;
    }
    let url = reqwest::Url::parse(arg).ok()?;
    if url.scheme() != crate::deep_link::SCHEME {
        return None;
    }
    let target = format!("{}{}", url.host_str().unwrap_or_default(), url.path());
    if !target.trim_matches('/').eq_ignore_ascii_case("signed-in") {
        return None;
    }
    let value = |key: &str| url.query_pairs().find(|(k, _)| k == key).map(|(_, v)| v.into_owned());
    let state = value("state")?;
    let error = value("error").filter(|e| !e.is_empty());
    let code = value("code").filter(|c| !c.is_empty());
    (code.is_some() || error.is_some()).then_some(Callback { code, state, error })
}

/// The first `echoledger://signed-in` link on a command line.
pub fn find_signed_in_link<S: AsRef<str>>(args: &[S]) -> Option<Callback> {
    args.iter().find_map(|arg| signed_in_link(arg.as_ref()))
}

/// Why a link that came back is not used.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Refused {
    /// No browser sign-in was started on this computer (or it was cancelled, or the app restarted meanwhile).
    NotStarted,
    /// The state is not the one this computer sent: someone else's link. The waiting sign-in goes on.
    OtherState,
    /// The wait ran out; the browser sign-in has to start again.
    Expired,
    /// The browser said why it did not sign in (the person said no, say).
    Declined,
    /// The code is not one the server would write.
    BadCode,
}

impl Refused {
    /// What the sign-in screen says.
    pub fn text(&self) -> &'static str {
        match self {
            Self::NotStarted => "This sign-in link was not started on this computer. Choose “Sign in in the browser” to start again.",
            Self::OtherState => "This sign-in link belongs to another sign-in, so it was not used. Finish the sign-in in the browser window EchoLedger opened.",
            Self::Expired => "The browser sign-in took too long. Choose “Sign in in the browser” to start again.",
            Self::Declined => "The sign-in was not finished in the browser. Choose “Sign in in the browser” to try again.",
            Self::BadCode => "The browser sent a sign-in link EchoLedger cannot use. Choose “Sign in in the browser” to start again.",
        }
    }

    /// Whether the waiting sign-in ends: only a link with the right state may end it (another one is ignored).
    pub fn ends_wait(&self) -> bool {
        !matches!(self, Self::NotStarted | Self::OtherState)
    }
}

/// Checks a link against the waiting sign-in: same state (compared in constant time), within the wait, with a plain
/// code. Returns the code to exchange.
pub fn check(pending: Option<&BrowserSignIn>, link: &Callback, now: Instant) -> Result<String, Refused> {
    let pending = pending.ok_or(Refused::NotStarted)?;
    if !plain(&link.state) || !same(&link.state, &pending.state) {
        return Err(Refused::OtherState);
    }
    if pending.expired(now) {
        return Err(Refused::Expired);
    }
    if link.error.is_some() {
        return Err(Refused::Declined);
    }
    match &link.code {
        Some(code) if plain(code) => Ok(code.clone()),
        _ => Err(Refused::BadCode),
    }
}

/// A wait in plain words: "45 seconds", "1 minute", "15 minutes" (rounded up).
pub fn wait_text(seconds: u64) -> String {
    match seconds {
        0..=59 => format!("{} second{}", seconds.max(1), if seconds == 1 { "" } else { "s" }),
        _ => {
            let minutes = seconds.div_ceil(60);
            format!("{minutes} minute{}", if minutes == 1 { "" } else { "s" })
        }
    }
}

/// The sentence a viewer's app shows instead of recording (docs/API.md "Workspaces, roles and privacy").
pub fn viewer_sentence(workspace: Option<&str>) -> String {
    let workspace = workspace.map(str::trim).filter(|w| !w.is_empty()).unwrap_or("this workspace");
    format!("You are a viewer in {workspace}: you can open what is shared with you, not record.")
}

/// A data export's link may be opened in the browser only when it is an https page of the signed-in server.
pub fn export_link_ok(server: &str, link: &str) -> bool {
    let link = link.trim();
    (link.starts_with("https://") || crate::net::pinning::is_local_dev(link)) && crate::net::api::same_server(server, link)
}

/// The scheme and host (with port) of an address, lower-case (pure).
fn origin(url: &str) -> Option<String> {
    let (scheme, rest) = url.trim().split_once("://")?;
    let scheme = scheme.to_ascii_lowercase();
    if scheme != "https" && scheme != "http" {
        return None;
    }
    let host = rest.split(['/', '?', '#']).next()?.to_ascii_lowercase();
    (!host.is_empty() && !host.contains('@')).then(|| format!("{scheme}://{host}"))
}

/// Whether `url` is a page of the server at `server_url` (same scheme and host): the sign-in screen opens only those.
pub fn on_server(url: &str, server_url: &str) -> bool {
    matches!((origin(url), origin(server_url)), (Some(a), Some(b)) if a == b)
}

/// The sign-in options with their links kept only when they are on the server's own address (Phase 1C).
pub fn checked_options(mut options: crate::net::models::AuthOptions, server_url: &str) -> crate::net::models::AuthOptions {
    options.sign_up_url = options.sign_up_url.filter(|u| on_server(u, server_url));
    options.reset_url = options.reset_url.filter(|u| on_server(u, server_url));
    options.sign_up &= options.sign_up_url.is_some();
    options
}

#[cfg(test)]
mod tests {
    use super::*;

    fn pending() -> BrowserSignIn {
        BrowserSignIn::start("https://ledger.example.test/", vec![], "Studio Mac", Instant::now()).unwrap()
    }

    fn link(code: Option<&str>, state: &str) -> Callback {
        Callback { code: code.map(str::to_string), state: state.to_string(), error: None }
    }

    #[test]
    fn the_challenge_is_s256_of_the_verifier() {
        // RFC 7636 appendix B.
        assert_eq!(challenge_of("dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"), "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM");
        let p = pending();
        assert_eq!(p.verifier.len(), 43, "32 random bytes");
        assert!(p.verifier.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_'));
        assert_ne!(p.verifier, pending().verifier, "a new verifier each time");
        assert_ne!(p.state, pending().state);
        assert_ne!(p.state, p.verifier);
    }

    #[test]
    fn the_sign_in_page_gets_the_challenge_never_the_verifier() {
        let p = pending();
        let url = reqwest::Url::parse(&p.url("macos").unwrap()).unwrap();
        assert_eq!(url.as_str().split('?').next(), Some("https://ledger.example.test/device/sign-in"));
        let q: std::collections::HashMap<String, String> = url.query_pairs().into_owned().collect();
        assert_eq!(q["code_challenge"], challenge_of(&p.verifier));
        assert_eq!(q["code_challenge_method"], "S256");
        assert_eq!(q["state"], p.state);
        assert_eq!(q["redirect_uri"], "echoledger://signed-in");
        assert_eq!(q["name"], "Studio Mac");
        assert_eq!(q["platform"], "macos");
        assert!(!url.as_str().contains(&p.verifier));
        // A server under a path keeps it.
        let sub = BrowserSignIn::start("https://example.test/echoledger", vec![], "PC & co", Instant::now()).unwrap();
        let sub_url = sub.url("windows").unwrap();
        assert!(sub_url.starts_with("https://example.test/echoledger/device/sign-in?"), "{sub_url}");
        assert!(sub_url.contains("name=PC+%26+co"));
    }

    #[test]
    fn the_signed_in_link_is_read_from_the_command_line() {
        assert_eq!(signed_in_link("echoledger://signed-in?code=abc123&state=st-1"), Some(link(Some("abc123"), "st-1")));
        assert_eq!(signed_in_link("\"echoledger://signed-in/?state=s&code=c\""), Some(link(Some("c"), "s")));
        assert_eq!(signed_in_link("ECHOLEDGER://SIGNED-IN?code=c&state=s"), Some(link(Some("c"), "s")));
        assert_eq!(
            signed_in_link("echoledger://signed-in?error=access_denied&state=s"),
            Some(Callback { code: None, state: "s".into(), error: Some("access_denied".into()) })
        );
        for other in [
            "echoledger://open?link=https://ledger.example.test/s/t",
            "echoledger://signed-in?code=c",
            "echoledger://signed-in?state=s",
            "echoledger://signed-out?code=c&state=s",
            "https://ledger.example.test/signed-in?code=c&state=s",
            "--autostart",
        ] {
            assert_eq!(signed_in_link(other), None, "{other}");
        }
        let argv = ["EchoLedger.exe", "--autostart", "echoledger://signed-in?code=c&state=s"];
        assert_eq!(find_signed_in_link(&argv).and_then(|c| c.code).as_deref(), Some("c"));
        // An open link is not a sign-in, and a sign-in link is not an open link.
        assert_eq!(crate::deep_link::find_open_link(&argv), None);
    }

    #[test]
    fn only_the_link_with_this_computer_s_state_is_used() {
        let p = pending();
        let now = p.started + Duration::from_secs(30);
        assert_eq!(check(Some(&p), &link(Some("one-time_code.9~"), &p.state), now), Ok("one-time_code.9~".into()));
        assert_eq!(check(None, &link(Some("c"), &p.state), now), Err(Refused::NotStarted));
        assert_eq!(check(Some(&p), &link(Some("c"), "someone-else"), now), Err(Refused::OtherState));
        // A state that differs only at the end, or is a prefix, is another state.
        let mut almost = p.state.clone();
        almost.pop();
        assert_eq!(check(Some(&p), &link(Some("c"), &almost), now), Err(Refused::OtherState));
        assert!(check(Some(&p), &link(Some("c"), &format!("{almost}x")), now).is_err());
        // Another state never ends the wait; the others do.
        assert!(!Refused::OtherState.ends_wait() && !Refused::NotStarted.ends_wait());
        assert!(Refused::Expired.ends_wait() && Refused::BadCode.ends_wait() && Refused::Declined.ends_wait());
    }

    #[test]
    fn an_old_wait_an_odd_code_or_a_refusal_is_not_exchanged() {
        let p = pending();
        let late = p.started + MAX_WAIT + Duration::from_secs(1);
        assert_eq!(check(Some(&p), &link(Some("c"), &p.state), late), Err(Refused::Expired));
        let now = p.started + Duration::from_secs(5);
        for bad in ["", "with space", "quote\"", "a/b", "ü", &"x".repeat(MAX_PART_CHARS + 1)] {
            assert_eq!(check(Some(&p), &link(Some(bad), &p.state), now), Err(Refused::BadCode), "{bad:?}");
        }
        assert_eq!(check(Some(&p), &link(None, &p.state), now), Err(Refused::BadCode));
        let declined = Callback { code: Some("c".into()), state: p.state.clone(), error: Some("access_denied".into()) };
        assert_eq!(check(Some(&p), &declined, now), Err(Refused::Declined));
    }

    #[test]
    fn waits_and_the_viewer_sentence_read_as_plain_words() {
        assert_eq!(wait_text(1), "1 second");
        assert_eq!(wait_text(45), "45 seconds");
        assert_eq!(wait_text(60), "1 minute");
        assert_eq!(wait_text(61), "2 minutes");
        assert_eq!(wait_text(900), "15 minutes");
        assert_eq!(viewer_sentence(Some("Acme team")), "You are a viewer in Acme team: you can open what is shared with you, not record.");
        assert_eq!(viewer_sentence(Some("  ")), "You are a viewer in this workspace: you can open what is shared with you, not record.");
    }

    #[test]
    fn an_export_link_opens_only_on_the_signed_in_server() {
        let server = "https://ledger.example.test";
        assert!(export_link_ok(server, "https://ledger.example.test/account/export/01JF/download?signature=abc"));
        assert!(!export_link_ok(server, "https://evil.example.test/account/export/01JF"));
        assert!(!export_link_ok(server, "http://ledger.example.test/account/export/01JF"));
        assert!(!export_link_ok(server, "https://ledger.example.test@evil.example.test/x"));
        assert!(!export_link_ok(server, "javascript:alert(1)"));
        assert!(export_link_ok("http://localhost:8000", "http://localhost:8000/account/export/1"));
    }

    #[test]
    fn sign_in_pages_open_only_on_the_server_itself() {
        assert!(on_server("https://ledger.example.com/admin/register", "https://ledger.example.com"));
        assert!(on_server("https://LEDGER.example.com/admin/password-reset/request", "https://ledger.example.com/"));
        assert!(!on_server("https://evil.example.net/admin/register", "https://ledger.example.com"));
        assert!(!on_server("https://ledger.example.com@evil.example.net/", "https://ledger.example.com"));
        assert!(!on_server("javascript:alert(1)", "https://ledger.example.com"));
        assert!(!on_server("http://ledger.example.com/admin/register", "https://ledger.example.com"), "not down to http");
    }

    #[test]
    fn options_keep_only_their_own_links_and_read_tolerantly() {
        let read: crate::net::models::AuthOptions = serde_json::from_value(serde_json::json!({
            "password": true, "google": true, "sign_up": true, "sign_up_url": "https://elsewhere.example/register",
            "reset_url": "https://ledger.example.com/admin/password-reset/request", "browser_sign_in": true
        }))
        .unwrap();
        let checked = checked_options(read, "https://ledger.example.com");
        assert!(checked.google && checked.browser_sign_in && checked.password);
        assert_eq!(checked.sign_up_url, None);
        assert!(!checked.sign_up, "no Create account without its page");
        assert_eq!(checked.reset_url.as_deref(), Some("https://ledger.example.com/admin/password-reset/request"));
        let empty: crate::net::models::AuthOptions = serde_json::from_value(serde_json::json!({})).unwrap();
        assert!(empty.password && !empty.google && !empty.sign_up && !empty.browser_sign_in, "a field left out reads as before");
        let wire = serde_json::to_value(&checked).unwrap();
        assert!(wire.get("signUpUrl").is_some() && wire.get("browserSignIn").is_some(), "the page reads camelCase");
    }
}
