//! Site rules: which web app a browser tree shows, judged from its window titles (Windows) or active-tab URLs
//! (macOS with Automation granted). Pure and data-driven; the user can add rules in Settings (`siteRules`).

use serde::{Deserialize, Serialize};

use super::classify::SourceApp;

/// `app` of a rule that blocks recording instead of naming a call app.
pub const NEVER: &str = "never";

#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SiteRule {
    /// Built-ins: `builtin.<name>`; user rules: UUID v4.
    pub id: String,
    /// `source_app` wire value, or `never` (never-record rule).
    pub app: String,
    /// Matched against tab URLs (macOS) and as a substring of titles.
    pub host: Option<String>,
    /// Case-insensitive starts-with (after trimming).
    pub title_prefix: Option<String>,
    /// Case-insensitive contains.
    pub title_contains: Option<String>,
    /// Case-insensitive equality after removing the browser suffix.
    pub title_equals: Option<String>,
    /// Higher wins; never-record rules use 100.
    pub priority: u8,
    pub enabled: bool,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum SiteVerdict {
    App { app: String, rule_id: String },
    Never { rule_id: String },
    Unknown,
}

fn builtin(id: &str, app: &str, host: &str, prefix: Option<&str>, contains: Option<&str>, equals: Option<&str>, priority: u8) -> SiteRule {
    SiteRule {
        id: format!("builtin.{id}"),
        app: app.into(),
        host: Some(host.into()),
        title_prefix: prefix.map(Into::into),
        title_contains: contains.map(Into::into),
        title_equals: equals.map(Into::into),
        priority,
        enabled: true,
    }
}

/// The rules shipped with the app, in table order (the order breaks priority ties).
pub fn builtin_rules() -> Vec<SiteRule> {
    vec![
        builtin("meet", "google_meet", "meet.google.com", Some("Meet - "), Some("Google Meet"), None, 30),
        builtin("zoom", "zoom", "zoom.us", None, Some("Zoom Meeting"), Some("Zoom"), 30),
        builtin("teams", "microsoft_teams", "teams.microsoft.com", None, Some("Microsoft Teams"), None, 30),
        builtin("teams_live", "microsoft_teams", "teams.live.com", None, None, None, 30),
        builtin("whatsapp_web", "whatsapp_web", "web.whatsapp.com", None, Some("WhatsApp"), None, 10),
        builtin("never_chatgpt", NEVER, "chatgpt.com", None, Some("ChatGPT"), None, 100),
        builtin("never_claude", NEVER, "claude.ai", None, None, None, 100),
        builtin("never_gemini", NEVER, "gemini.google.com", None, None, None, 100),
    ]
}

/// Window-title suffixes browsers append (Edge puts a zero-width space inside "Microsoft Edge").
const BROWSER_SUFFIXES: [&str; 8] = [
    " - Google Chrome",
    " - Microsoft\u{200b} Edge",
    " - Microsoft Edge",
    " \u{2014} Mozilla Firefox",
    " - Mozilla Firefox",
    " - Brave",
    " - Opera",
    " - Vivaldi",
];

/// The page title without the browser's name at the end.
pub fn strip_browser_suffix(title: &str) -> &str {
    let trimmed = title.trim_end();
    BROWSER_SUFFIXES.iter().find_map(|s| trimmed.strip_suffix(s)).unwrap_or(trimmed)
}

/// Host of a URL (`https://user@Meet.Google.com:443/abc` → `meet.google.com`); `None` when there is none.
pub fn url_host(url: &str) -> Option<String> {
    let rest = url.trim();
    let rest = rest.split_once("://").map_or(rest, |(_, r)| r);
    let authority = rest.split(['/', '?', '#']).next().unwrap_or("");
    let authority = authority.rsplit_once('@').map_or(authority, |(_, h)| h);
    let host = if authority.starts_with('[') {
        authority.split_once(']').map_or(authority, |(h, _)| h).trim_start_matches('[')
    } else {
        authority.split(':').next().unwrap_or("")
    };
    let host = host.trim_end_matches('.').to_ascii_lowercase();
    (!host.is_empty()).then_some(host)
}

/// The tab URLs in `osascript` output (`get URL of active tab of every window` prints `url1, url2`; windows
/// without a tab print `missing value`).
pub fn parse_osascript_list(output: &str) -> Vec<String> {
    output.split(", ").map(str::trim).filter(|u| !u.is_empty() && *u != "missing value").map(str::to_string).collect()
}

fn pattern(value: &Option<String>) -> Option<String> {
    value.as_deref().map(str::trim).filter(|v| !v.is_empty()).map(str::to_lowercase)
}

/// Does `rule` match one window title? Any non-empty title field, or the host appearing in the title.
pub fn rule_matches_title(rule: &SiteRule, title: &str) -> bool {
    let page = strip_browser_suffix(title).trim().to_lowercase();
    if page.is_empty() {
        return false;
    }
    pattern(&rule.title_prefix).is_some_and(|p| page.starts_with(&p))
        || pattern(&rule.title_contains).is_some_and(|p| page.contains(&p))
        || pattern(&rule.title_equals).is_some_and(|p| page == p)
        || pattern(&rule.host).is_some_and(|h| page.contains(&h))
}

/// Does `rule` match one tab URL? Its host equals the rule's host or is a subdomain of it.
pub fn rule_matches_url(rule: &SiteRule, url: &str) -> bool {
    let (Some(host), Some(want)) = (url_host(url), pattern(&rule.host)) else { return false };
    let want = want.trim_end_matches('.');
    host == want || host.strip_suffix(want).is_some_and(|rest| rest.ends_with('.'))
}

/// User rules followed by the built-ins they do not replace (a user rule with a built-in's id replaces it). The
/// order is the tie-break order.
pub fn effective_rules(user: &[SiteRule]) -> Vec<SiteRule> {
    let mut rules: Vec<SiteRule> = user.to_vec();
    rules.extend(builtin_rules().into_iter().filter(|b| !user.iter().any(|u| u.id == b.id)));
    rules
}

fn usable(rule: &SiteRule) -> bool {
    rule.enabled && (rule.app == NEVER || SourceApp::from_wire(&rule.app).is_some())
}

fn rule_matches(rule: &SiteRule, titles: &[String], urls: &[String]) -> bool {
    titles.iter().any(|t| rule_matches_title(rule, t)) || urls.iter().any(|u| rule_matches_url(rule, u))
}

/// Judges every title and URL of one browser tree: any never-record match wins, then the call rule with the
/// highest priority (ties: user rules first, then table order), else unknown.
pub fn match_site(titles: &[String], urls: &[String], user: &[SiteRule]) -> SiteVerdict {
    let rules: Vec<SiteRule> = effective_rules(user).into_iter().filter(usable).collect();
    if let Some(never) = rules.iter().find(|r| r.app == NEVER && rule_matches(r, titles, urls)) {
        return SiteVerdict::Never { rule_id: never.id.clone() };
    }
    let mut best: Option<&SiteRule> = None;
    for rule in rules.iter().filter(|r| r.app != NEVER && rule_matches(r, titles, urls)) {
        if best.is_none_or(|b| rule.priority > b.priority) {
            best = Some(rule);
        }
    }
    best.map_or(SiteVerdict::Unknown, |r| SiteVerdict::App { app: r.app.clone(), rule_id: r.id.clone() })
}

/// What the explainer shows for a matched rule: its host, else its id.
pub fn site_of(rule_id: &str, user: &[SiteRule]) -> String {
    effective_rules(user).into_iter().find(|r| r.id == rule_id).and_then(|r| r.host.filter(|h| !h.trim().is_empty())).unwrap_or_else(|| rule_id.to_string())
}

/// Is `name` (a non-browser image name or bundle id) blocked by an enabled user never-record rule?
pub fn never_by_name(name: &str, user: &[SiteRule]) -> bool {
    let name = name.trim();
    !name.is_empty()
        && effective_rules(user)
            .iter()
            .filter(|r| r.enabled && r.app == NEVER)
            .any(|r| r.title_equals.as_deref().is_some_and(|e| e.trim().eq_ignore_ascii_case(name)))
}
