//! Projects (docs/API.md "Projects", "Filing rules", since v1.5): the smart folders the Library can show, and filing
//! rules checked before they are sent. The apps call client folders projects; the API keeps the name `clients`.

use futures_util::StreamExt;
use serde_json::Value;

use crate::error::{AppError, AppResult};
use crate::net::api::ApiClient;

/// The smart folders of `GET /api/library?folder=` (computed on the server, never copies).
pub const SMART_FOLDERS: [&str; 10] = ["inbox", "whatsapp", "whatsapp_business", "google_meet", "zoom", "teams", "phone", "voice_notes", "imported", "shared"];
/// The kinds of filing rule: the title contains a text, the recording comes from an app, the caller is a contact.
pub const RULE_KINDS: [&str; 3] = ["title", "app", "contact"];
/// The `source_app` values an app rule may name (docs/API.md "Enumerations"; `other` files nothing useful).
pub const RULE_APPS: [&str; 15] = [
    "whatsapp", "whatsapp_business", "whatsapp_web", "google_meet", "zoom", "microsoft_teams", "telegram", "signal",
    "messenger", "instagram", "skype", "slack", "discord", "phone_call", "voice_note",
];
/// The longest rule value the server keeps.
pub const MAX_RULE_CHARS: usize = 120;
/// Pages of one smart folder read at most (100 recordings each).
pub const MAX_FOLDER_PAGES: usize = 50;
/// Projects looked through at most to say which one already has a rule.
const MAX_OWNER_LOOKUPS: usize = 40;

/// A smart folder name the server knows, else a plain refusal.
pub fn check_folder(folder: &str) -> AppResult<&'static str> {
    SMART_FOLDERS.iter().find(|f| **f == folder).copied().ok_or_else(|| AppError::msg("That folder is not available."))
}

/// A phone number as people write it: digits with an optional leading +, spaces, dashes, dots or brackets, 5-15 digits.
fn is_phone(text: &str) -> bool {
    let digits = text.chars().filter(char::is_ascii_digit).count();
    let body = text.strip_prefix('+').unwrap_or(text);
    (5..=15).contains(&digits) && body.chars().all(|c| c.is_ascii_digit() || matches!(c, ' ' | '-' | '.' | '(' | ')'))
}

/// A filing rule's value, checked for its kind: a title text, an app's wire value, or a phone number or email.
pub fn check_rule(kind: &str, value: &str) -> AppResult<String> {
    if !RULE_KINDS.contains(&kind) {
        return Err(AppError::msg("Choose title, app or contact."));
    }
    let value = value.split_whitespace().collect::<Vec<_>>().join(" ");
    if value.is_empty() {
        return Err(AppError::msg(match kind {
            "title" => "Type the words the title should contain.",
            "app" => "Choose the app.",
            _ => "Type a phone number or an email.",
        }));
    }
    if value.chars().count() > MAX_RULE_CHARS {
        return Err(AppError::msg(format!("Keep it under {MAX_RULE_CHARS} characters.")));
    }
    match kind {
        "app" if !RULE_APPS.contains(&value.as_str()) => Err(AppError::msg("Choose one of the apps in the list.")),
        "contact" if !is_phone(&value) && crate::sharing::clean_email(&value).is_none() => {
            Err(AppError::msg("Type a phone number (like +92 300 1234567) or an email."))
        }
        _ => Ok(value),
    }
}

/// Whether a project's rules (`GET /api/clients/{id}/rules`) hold this kind and value (ignoring capitals).
pub fn has_rule(rules: &Value, kind: &str, value: &str) -> bool {
    rules["data"].as_array().is_some_and(|list| {
        list.iter().any(|r| r["kind"].as_str() == Some(kind) && r["value"].as_str().is_some_and(|v| v.trim().eq_ignore_ascii_case(value.trim())))
    })
}

/// `409 rule_exists` in plain words, naming the project that has the rule when it can be found among the others.
pub async fn rule_exists_text(api: &ApiClient, except: &str, kind: &str, value: &str) -> String {
    let fallback = crate::error::code_text("rule_exists").unwrap_or_default().to_string();
    let Ok(clients) = api.clients().await else { return fallback };
    let others: Vec<(String, String)> = clients["data"]
        .as_array()
        .map(|list| {
            list.iter()
                .filter_map(|c| Some((c["id"].as_str()?.to_string(), c["name"].as_str().unwrap_or_default().to_string())))
                .filter(|(id, _)| id != except)
                .take(MAX_OWNER_LOOKUPS)
                .collect()
        })
        .unwrap_or_default();
    let owners: Vec<Option<String>> = futures_util::stream::iter(others)
        .map(|(id, name)| async move { api.client_rules(&id).await.ok().filter(|rules| has_rule(rules, kind, value)).map(|_| name) })
        .buffered(4)
        .collect()
        .await;
    match owners.into_iter().flatten().find(|name| !name.is_empty()) {
        Some(name) => format!("{name} already has this rule. Remove it there first, or file these recordings by something else."),
        None => fallback,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn only_the_documented_smart_folders_are_asked_for() {
        assert_eq!(check_folder("inbox").unwrap(), "inbox");
        assert_eq!(check_folder("shared").unwrap(), "shared");
        assert!(check_folder("trash").is_err());
        assert!(check_folder("").is_err());
    }

    #[test]
    fn a_title_rule_keeps_its_words_with_single_spaces() {
        assert_eq!(check_rule("title", "  Acme   weekly ").unwrap(), "Acme weekly");
        assert!(check_rule("title", "   ").unwrap_err().to_string().contains("title should contain"));
        assert!(check_rule("title", &"x".repeat(121)).is_err());
        assert!(check_rule("colour", "red").is_err());
    }

    #[test]
    fn an_app_rule_names_a_source_app() {
        assert_eq!(check_rule("app", "zoom").unwrap(), "zoom");
        assert_eq!(check_rule("app", "microsoft_teams").unwrap(), "microsoft_teams");
        assert!(check_rule("app", "Zoom Meetings").is_err());
    }

    #[test]
    fn a_contact_rule_is_a_phone_number_or_an_email() {
        assert_eq!(check_rule("contact", "+92 300 1234567").unwrap(), "+92 300 1234567");
        assert_eq!(check_rule("contact", "(021) 555-0199").unwrap(), "(021) 555-0199");
        assert_eq!(check_rule("contact", "ana@example.com").unwrap(), "ana@example.com");
        for bad in ["1234", "call me", "+92 300 12345678901234", "ana@"] {
            assert!(check_rule("contact", bad).is_err(), "{bad}");
        }
    }

    #[test]
    fn a_rule_list_is_searched_by_kind_and_value_ignoring_capitals() {
        let rules = json!({"data": [{"id": 7, "kind": "title", "value": "Acme"}, {"id": 8, "kind": "app", "value": "zoom"}]});
        assert!(has_rule(&rules, "title", "ACME"));
        assert!(has_rule(&rules, "app", "zoom"));
        assert!(!has_rule(&rules, "contact", "zoom"));
        assert!(!has_rule(&json!({}), "title", "Acme"));
    }
}
