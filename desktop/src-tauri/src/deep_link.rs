//! Links in the apps (docs/API.md, since v1.5): `echoledger://open?link=<url>` opens a share (`/s/…`), project
//! (`/p/…`) or request (`/r/…`) link of the server in the app. The installer registers the scheme on Windows (the
//! bundler's deep-link protocols) and Info.plist does on macOS. Windows starts the app with the link as an argument,
//! which reaches the running app through the single-instance callback; macOS hands it over as an opened URL.
//!
//! The link inside is untrusted input from any web page: only an https (or local development) page link with a plain
//! token is let through, and it is resolved only with the paired server when it is that server's own link.

/// The app's URL scheme.
pub const SCHEME: &str = "echoledger";
/// Longer links are not ours.
const MAX_LINK_CHARS: usize = 2048;
/// The pages a link may open: a recording's share, a project's share, a request link.
const PAGES: [&str; 3] = ["s", "p", "r"];

/// The server link inside `echoledger://open?link=<url>` (also `echoledger:open?link=…`), checked with [`app_link`].
pub fn open_link(arg: &str) -> Option<String> {
    let arg = arg.trim().trim_matches('"');
    if arg.len() > MAX_LINK_CHARS * 3 {
        return None;
    }
    let url = reqwest::Url::parse(arg).ok()?;
    if url.scheme() != SCHEME {
        return None;
    }
    // The scheme's host is kept as written (only web addresses are lower-cased), so OPEN counts as open.
    let target = format!("{}{}", url.host_str().unwrap_or_default(), url.path());
    if !target.trim_matches('/').eq_ignore_ascii_case("open") {
        return None;
    }
    let link = url.query_pairs().find(|(key, _)| key == "link").map(|(_, value)| value.into_owned())?;
    app_link(&link)
}

/// The first argument that is an `echoledger://open` link (a second start of the app gets the whole command line).
pub fn find_open_link<S: AsRef<str>>(args: &[S]) -> Option<String> {
    args.iter().find_map(|arg| open_link(arg.as_ref()))
}

/// A share, project or request page of an EchoLedger server (`…/s/<token>`, `…/p/<token>`, `…/r/<token>`, under any
/// path the server lives at), without its query or fragment; none for anything else.
pub fn app_link(link: &str) -> Option<String> {
    let link = link.trim();
    if link.len() > MAX_LINK_CHARS {
        return None;
    }
    let mut url = reqwest::Url::parse(link).ok()?;
    let secure = url.scheme() == "https" || crate::net::pinning::is_local_dev(link);
    if !secure || !url.username().is_empty() || url.password().is_some() || url.host_str().is_none() {
        return None;
    }
    let segments: Vec<String> = url.path_segments()?.filter(|s| !s.is_empty()).map(str::to_string).collect();
    let [.., page, token] = segments.as_slice() else { return None };
    let plain_token = !token.is_empty() && token.len() <= 128 && token.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_');
    if !PAGES.contains(&page.as_str()) || !plain_token {
        return None;
    }
    url.set_query(None);
    url.set_fragment(None);
    url.set_path(&format!("/{}", segments.join("/")));
    Some(url.to_string())
}

/// Whether `link` is a page of the paired server at `server_url`: same scheme, host and port, under the server's path.
/// Only then may it be sent to the server to be resolved (its token must never reach another server).
pub fn same_server(link: &str, server_url: &str) -> bool {
    let (Ok(link), Ok(server)) = (reqwest::Url::parse(link), reqwest::Url::parse(server_url.trim())) else { return false };
    let base = server.path().trim_end_matches('/');
    link.scheme() == server.scheme()
        && link.host_str() == server.host_str()
        && link.port_or_known_default() == server.port_or_known_default()
        && link.path().starts_with(&format!("{base}/"))
}

#[cfg(test)]
mod tests {
    use super::*;

    const SHARE: &str = "https://ledger.example.test/s/AbC123_-xyz";

    #[test]
    fn an_open_link_gives_the_page_inside() {
        assert_eq!(open_link("echoledger://open?link=https%3A%2F%2Fledger.example.test%2Fs%2FAbC123_-xyz").as_deref(), Some(SHARE));
        assert_eq!(open_link("echoledger://open/?link=https://ledger.example.test/p/proj01").as_deref(), Some("https://ledger.example.test/p/proj01"));
        assert_eq!(open_link("\"echoledger://open?link=https://ledger.example.test/r/req9\"").as_deref(), Some("https://ledger.example.test/r/req9"));
        assert_eq!(open_link("ECHOLEDGER://OPEN?link=https://ledger.example.test/s/t1").as_deref(), Some("https://ledger.example.test/s/t1"));
    }

    #[test]
    fn anything_else_on_the_command_line_is_not_a_link() {
        for arg in [
            "C:\\Program Files\\EchoLedger\\EchoLedger.exe",
            "--autostart",
            "echoledger://settings?link=https://ledger.example.test/s/t1",
            "echoledger://open?url=https://ledger.example.test/s/t1",
            "echoledger://open",
            "https://ledger.example.test/s/t1",
            "otherapp://open?link=https://ledger.example.test/s/t1",
        ] {
            assert_eq!(open_link(arg), None, "{arg}");
        }
    }

    #[test]
    fn only_https_page_links_with_a_plain_token_are_let_through() {
        assert_eq!(app_link("https://ledger.example.test/s/tok?x=1#frag").as_deref(), Some("https://ledger.example.test/s/tok"));
        assert_eq!(app_link("https://example.test/echoledger/p/tok/").as_deref(), Some("https://example.test/echoledger/p/tok"));
        assert_eq!(app_link("http://localhost:8000/r/tok").as_deref(), Some("http://localhost:8000/r/tok"));
        for bad in [
            "http://ledger.example.test/s/tok",
            "javascript:alert(1)",
            "file:///C:/Windows/s/tok",
            "https://user:pw@ledger.example.test/s/tok",
            "https://ledger.example.test/admin/settings",
            "https://ledger.example.test/s/",
            "https://ledger.example.test/s/tok%20en",
            "https://ledger.example.test/s/tok/extra",
        ] {
            assert_eq!(app_link(bad), None, "{bad}");
        }
        assert_eq!(app_link(&format!("https://ledger.example.test/s/{}", "a".repeat(129))), None);
    }

    #[test]
    fn the_first_link_on_a_command_line_is_found() {
        let argv = ["EchoLedger.exe", "echoledger://open?link=https://ledger.example.test/s/one", "echoledger://open?link=https://ledger.example.test/s/two"];
        assert_eq!(find_open_link(&argv).as_deref(), Some("https://ledger.example.test/s/one"));
        assert_eq!(find_open_link(&["EchoLedger.exe", "--autostart"]), None);
    }

    #[test]
    fn a_link_is_resolved_only_by_its_own_server() {
        assert!(same_server(SHARE, "https://ledger.example.test"));
        assert!(same_server(SHARE, "https://ledger.example.test/"));
        assert!(same_server("https://ledger.example.test:443/s/t", "https://ledger.example.test"));
        assert!(same_server("https://example.test/echoledger/s/t", "https://example.test/echoledger"));
        assert!(!same_server("https://example.test/other/s/t", "https://example.test/echoledger"));
        assert!(!same_server("https://ledger.example.test.evil.test/s/t", "https://ledger.example.test"));
        assert!(!same_server("https://ledger.example.test:8443/s/t", "https://ledger.example.test"));
        assert!(!same_server(SHARE, "not a url"));
    }
}
