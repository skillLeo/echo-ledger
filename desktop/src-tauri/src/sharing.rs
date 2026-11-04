//! Sharing (docs/API.md "Share links", "Sending to a teammate", "Request links", since v1.5): what the UI asks for is
//! checked here and turned into the documented request bodies, so nothing half-valid reaches the server.

use serde::Deserialize;
use serde_json::{Value, json};

use crate::error::{AppError, AppResult};

/// Who may open a share link: anyone with it, anyone with it and the password, or only the people named.
pub const AUDIENCES: [&str; 3] = ["public", "password", "people"];
/// The most emails one link or one send may name.
pub const MAX_PEOPLE: usize = 20;
/// The longest note sent with a recording or a project.
pub const MAX_NOTE_CHARS: usize = 500;
/// The longest title of a request link.
pub const MAX_REQUEST_TITLE_CHARS: usize = 200;
/// What one request link may take at most: files per link and megabytes per file (the server may allow less).
pub const MAX_REQUEST_FILES: u32 = 100;
pub const MAX_REQUEST_MB: u32 = 2048;

/// A share link as the share dialog asks for it (camelCase from the UI). `expires_at` is an RFC 3339 time, none = no
/// expiry; `view` is a Show as view the page opens in.
#[derive(Clone, Debug, Default, PartialEq, Deserialize)]
#[serde(default, rename_all = "camelCase")]
pub struct ShareOptions {
    pub audience: String,
    pub password: Option<String>,
    pub people: Vec<String>,
    pub allow_download: bool,
    pub expires_at: Option<String>,
    pub view: Option<String>,
}

/// A request link as the Request files sheet asks for it; `client_id` none = the Inbox.
#[derive(Clone, Debug, Default, PartialEq, Deserialize)]
#[serde(default, rename_all = "camelCase")]
pub struct RequestLinkOptions {
    pub title: String,
    pub client_id: Option<String>,
    pub expires_at: Option<String>,
    pub max_files: Option<u32>,
    pub max_mb: Option<u32>,
}

/// An email address as typed, trimmed, when it looks like one (one `@`, a dotted domain, no spaces).
pub fn clean_email(text: &str) -> Option<String> {
    let email = text.trim().trim_matches(|c| c == '<' || c == '>').trim();
    let (local, domain) = email.split_once('@')?;
    let ok = email.chars().count() <= 254
        && !local.is_empty()
        && domain.len() >= 3
        && domain.contains('.')
        && !domain.starts_with('.')
        && !domain.ends_with('.')
        && !domain.contains('@')
        && !email.chars().any(|c| c.is_whitespace() || c.is_control() || c == ',' || c == ';');
    ok.then(|| email.to_string())
}

/// The emails of a share or a send: each one checked, duplicates (ignoring capitals) dropped, at most [`MAX_PEOPLE`].
pub fn clean_people(people: &[String]) -> AppResult<Vec<String>> {
    let mut out: Vec<String> = Vec::new();
    for raw in people.iter().map(|p| p.trim()).filter(|p| !p.is_empty()) {
        let email = clean_email(raw).ok_or_else(|| AppError::msg(format!("“{raw}” is not an email address.")))?;
        if !out.iter().any(|known| known.eq_ignore_ascii_case(&email)) {
            out.push(email);
        }
    }
    if out.is_empty() {
        return Err(AppError::msg("Add the email of at least one person."));
    }
    if out.len() > MAX_PEOPLE {
        return Err(AppError::msg(format!("Name {MAX_PEOPLE} people at most.")));
    }
    Ok(out)
}

/// An expiry time the UI chose: a valid RFC 3339 time that is still ahead of `now`.
fn check_expiry(expires_at: Option<&str>, now: chrono::DateTime<chrono::Utc>) -> AppResult<Option<String>> {
    let Some(text) = expires_at.map(str::trim).filter(|t| !t.is_empty()) else { return Ok(None) };
    let at = chrono::DateTime::parse_from_rfc3339(text).map_err(|_| AppError::msg("Pick when the link stops working."))?;
    if at.with_timezone(&chrono::Utc) <= now {
        return Err(AppError::msg("Pick a time in the future for the link to stop working."));
    }
    Ok(Some(text.to_string()))
}

impl ShareOptions {
    /// The body of `POST /api/sessions/{id}/shares` or `POST /api/clients/{id}/shares` (v1.5 fields only; keys that do
    /// not apply to the audience are left out).
    pub fn body(&self, now: chrono::DateTime<chrono::Utc>) -> AppResult<Value> {
        let audience = self.audience.trim();
        if !AUDIENCES.contains(&audience) {
            return Err(AppError::msg("Choose who can open the link."));
        }
        let mut body = json!({ "audience": audience, "allow_download": self.allow_download });
        match audience {
            "password" => {
                let password = self.password.as_deref().unwrap_or_default();
                if !(6..=100).contains(&password.chars().count()) {
                    return Err(AppError::msg("The password needs 6 to 100 characters."));
                }
                body["password"] = json!(password);
            }
            "people" => body["people"] = json!(clean_people(&self.people)?),
            _ => {}
        }
        if let Some(at) = check_expiry(self.expires_at.as_deref(), now)? {
            body["expires_at"] = json!(at);
        }
        if let Some(view) = self.view.as_deref().filter(|v| !v.is_empty()) {
            if !crate::commands::EXPORT_VIEWS.contains(&view) {
                return Err(AppError::msg("That view cannot be shared."));
            }
            body["view"] = json!(view);
        }
        Ok(body)
    }
}

impl RequestLinkOptions {
    /// The body of `POST /api/request-links`.
    pub fn body(&self, now: chrono::DateTime<chrono::Utc>) -> AppResult<Value> {
        let title = self.title.trim();
        if title.is_empty() {
            return Err(AppError::msg("Give the request a title, so people know what to send."));
        }
        if title.chars().count() > MAX_REQUEST_TITLE_CHARS {
            return Err(AppError::msg(format!("Keep the title under {MAX_REQUEST_TITLE_CHARS} characters.")));
        }
        if self.max_files.is_some_and(|n| !(1..=MAX_REQUEST_FILES).contains(&n)) {
            return Err(AppError::msg(format!("Allow between 1 and {MAX_REQUEST_FILES} files.")));
        }
        if self.max_mb.is_some_and(|n| !(1..=MAX_REQUEST_MB).contains(&n)) {
            return Err(AppError::msg(format!("Allow between 1 and {MAX_REQUEST_MB} MB per file.")));
        }
        let client_id = self.client_id.as_deref().map(str::trim).filter(|c| !c.is_empty());
        Ok(json!({
            "title": title,
            "client_id": client_id,
            "expires_at": check_expiry(self.expires_at.as_deref(), now)?,
            "max_files": self.max_files,
            "max_mb": self.max_mb,
        }))
    }
}

/// The body of `POST /api/sessions/{id}/send` or `POST /api/clients/{id}/send`: 1-20 emails and an optional note.
pub fn send_body(to: &[String], note: Option<&str>) -> AppResult<Value> {
    let to = clean_people(to)?;
    let note = note.map(str::trim).filter(|n| !n.is_empty());
    if note.is_some_and(|n| n.chars().count() > MAX_NOTE_CHARS) {
        return Err(AppError::msg(format!("Keep the note under {MAX_NOTE_CHARS} characters.")));
    }
    Ok(json!({ "to": to, "note": note }))
}

/// `404 recipient_not_found` in plain words: which address has no account when that is known (one address was sent,
/// or the server's message names one of them).
pub fn recipient_text(to: &[String], server_message: &str) -> String {
    let message = server_message.to_lowercase();
    let named = match to {
        [only] => Some(only.as_str()),
        _ => to.iter().map(String::as_str).find(|email| message.contains(&email.to_lowercase())),
    };
    match named {
        Some(email) => format!("There is no account for {email} on this server, so nothing was sent. Check the address."),
        None => crate::error::code_text("recipient_not_found").unwrap_or_default().to_string(),
    }
}

/// What a share or a send is for: a recording (`sessions/…`) or a project (`clients/…`).
pub fn target_path(kind: &str) -> AppResult<&'static str> {
    match kind {
        "session" => Ok("sessions"),
        "project" => Ok("clients"),
        _ => Err(AppError::msg("Share a recording or a project.")),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn now() -> chrono::DateTime<chrono::Utc> {
        chrono::DateTime::parse_from_rfc3339("2026-11-04T10:00:00Z").unwrap().with_timezone(&chrono::Utc)
    }

    fn options(audience: &str) -> ShareOptions {
        ShareOptions { audience: audience.into(), ..ShareOptions::default() }
    }

    #[test]
    fn a_public_link_sends_only_the_audience_and_downloads() {
        assert_eq!(options("public").body(now()).unwrap(), json!({"audience": "public", "allow_download": false}));
        let with_all = ShareOptions { allow_download: true, expires_at: Some("2026-11-11T10:00:00+00:00".into()), view: Some("roman".into()), ..options("public") };
        assert_eq!(with_all.body(now()).unwrap(), json!({"audience": "public", "allow_download": true, "expires_at": "2026-11-11T10:00:00+00:00", "view": "roman"}));
    }

    #[test]
    fn a_password_link_needs_six_to_a_hundred_characters() {
        let short = ShareOptions { password: Some("12345".into()), ..options("password") };
        assert!(short.body(now()).unwrap_err().to_string().contains("6 to 100"));
        let ok = ShareOptions { password: Some("sesame-42".into()), people: vec!["ana@example.com".into()], ..options("password") };
        assert_eq!(ok.body(now()).unwrap(), json!({"audience": "password", "allow_download": false, "password": "sesame-42"}), "people are left out");
    }

    #[test]
    fn a_people_link_names_cleaned_unique_emails() {
        let people = ShareOptions { people: vec![" ana@example.com ".into(), "ANA@example.com".into(), "bo@x.org".into(), "".into()], password: Some("ignored".into()), ..options("people") };
        assert_eq!(people.body(now()).unwrap(), json!({"audience": "people", "allow_download": false, "people": ["ana@example.com", "bo@x.org"]}));
        assert!(options("people").body(now()).unwrap_err().to_string().contains("at least one"));
        let bad = ShareOptions { people: vec!["ana at example".into()], ..options("people") };
        assert_eq!(bad.body(now()).unwrap_err().to_string(), "“ana at example” is not an email address.");
        let many = ShareOptions { people: (0..21).map(|i| format!("p{i}@example.com")).collect(), ..options("people") };
        assert!(many.body(now()).unwrap_err().to_string().contains("20 people"));
    }

    #[test]
    fn an_unknown_audience_a_past_expiry_or_an_unknown_view_is_refused() {
        assert!(options("everyone").body(now()).is_err());
        let past = ShareOptions { expires_at: Some("2026-11-04T09:59:00Z".into()), ..options("public") };
        assert!(past.body(now()).unwrap_err().to_string().contains("future"));
        let garbage = ShareOptions { expires_at: Some("next week".into()), ..options("public") };
        assert!(garbage.body(now()).is_err());
        let view = ShareOptions { view: Some("klingon".into()), ..options("public") };
        assert!(view.body(now()).is_err());
    }

    #[test]
    fn emails_are_checked_loosely_but_never_let_a_list_through() {
        assert_eq!(clean_email("<ana@example.com>").as_deref(), Some("ana@example.com"));
        for bad in ["ana", "@example.com", "ana@", "ana@localhost", "a b@x.org", "ana@x.org, bo@y.org", "ana@.org"] {
            assert_eq!(clean_email(bad), None, "{bad}");
        }
    }

    #[test]
    fn a_request_link_needs_a_title_and_sensible_limits() {
        let body = RequestLinkOptions { title: " Send me your voice notes ".into(), client_id: Some("".into()), max_files: Some(20), max_mb: Some(100), ..RequestLinkOptions::default() }.body(now()).unwrap();
        assert_eq!(body, json!({"title": "Send me your voice notes", "client_id": null, "expires_at": null, "max_files": 20, "max_mb": 100}));
        let project = RequestLinkOptions { title: "Files".into(), client_id: Some("01JB".into()), expires_at: Some("2026-12-01T00:00:00+05:00".into()), ..RequestLinkOptions::default() }.body(now()).unwrap();
        assert_eq!(project["client_id"], "01JB");
        assert_eq!(project["expires_at"], "2026-12-01T00:00:00+05:00");
        assert!(RequestLinkOptions::default().body(now()).is_err());
        assert!(RequestLinkOptions { title: "x".into(), max_files: Some(0), ..RequestLinkOptions::default() }.body(now()).is_err());
        assert!(RequestLinkOptions { title: "x".into(), max_mb: Some(4096), ..RequestLinkOptions::default() }.body(now()).is_err());
    }

    #[test]
    fn a_send_has_people_and_a_short_note() {
        assert_eq!(send_body(&["ana@example.com".into()], Some("  for tomorrow ")).unwrap(), json!({"to": ["ana@example.com"], "note": "for tomorrow"}));
        assert_eq!(send_body(&["ana@example.com".into()], Some("  ")).unwrap()["note"], Value::Null);
        assert!(send_body(&[], None).is_err());
        assert!(send_body(&["ana@example.com".into()], Some(&"x".repeat(501))).is_err());
    }

    #[test]
    fn a_missing_recipient_is_named_when_it_is_known() {
        let one = recipient_text(&["ana@example.com".into()], "");
        assert_eq!(one, "There is no account for ana@example.com on this server, so nothing was sent. Check the address.");
        let named = recipient_text(&["ana@example.com".into(), "bo@x.org".into()], "No account for BO@x.org.");
        assert!(named.contains("bo@x.org"), "{named}");
        let unknown = recipient_text(&["ana@example.com".into(), "bo@x.org".into()], "Recipient not found.");
        assert!(unknown.starts_with("One of these emails"), "{unknown}");
    }

    #[test]
    fn only_recordings_and_projects_are_shared() {
        assert_eq!(target_path("session").unwrap(), "sessions");
        assert_eq!(target_path("project").unwrap(), "clients");
        assert!(target_path("device").is_err());
    }
}
