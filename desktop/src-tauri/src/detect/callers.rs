//! Caller names (since v1.2): who a call or meeting is with, read from the app's window titles ("Omar Khan |
//! Microsoft Teams", "Meet - Weekly sync"). Generic titles ("Zoom Meeting", "WhatsApp", a Meet code) give nothing.
//! Pure; unit-tested. The name goes to the server, which matches it to a client contact.

use super::classify::SourceApp;

/// Words that are an app's own window names, never a person or a meeting.
const GENERIC: [&str; 18] = [
    "whatsapp", "whatsapp business", "zoom", "zoom meeting", "zoom workplace", "zoom cloud meetings", "microsoft teams",
    "teams", "chat", "calendar", "activity", "calls", "files", "meet", "google meet", "meeting", "home", "new tab",
];

/// The name a meeting app's windows show for the running call, or `None`.
pub fn meeting_name(source: SourceApp, titles: &[String]) -> Option<String> {
    titles.iter().filter_map(|title| name_from(source, title)).next()
}

fn name_from(source: SourceApp, title: &str) -> Option<String> {
    let title = title.trim();
    let name = match source {
        SourceApp::MicrosoftTeams => strip_suffixes(title, &[" | Microsoft Teams (work or school)", " | Microsoft Teams (free)", " | Microsoft Teams"]),
        SourceApp::GoogleMeet => strip_prefixes(title, &["Meet - ", "Meet – ", "Meet: "]),
        SourceApp::Whatsapp | SourceApp::WhatsappBusiness | SourceApp::WhatsappWeb => strip_suffixes(title, &[" - WhatsApp", " | WhatsApp"]),
        SourceApp::Zoom => strip_suffixes(title, &[" - Zoom", " | Zoom"]),
        SourceApp::PhoneCall | SourceApp::VoiceNote | SourceApp::Other => return None,
    };
    let name = name.trim().trim_start_matches(['(', '[']).trim_end_matches([')', ']']).trim();
    if name.is_empty() || name.chars().count() > 120 || GENERIC.contains(&name.to_lowercase().as_str()) || is_meet_code(name) || is_count_only(name) {
        return None;
    }
    // "Chat | Omar Khan" style leftovers keep the part after the app's own section name.
    let name = name.split(" | ").last().unwrap_or(name).trim();
    if GENERIC.contains(&name.to_lowercase().as_str()) {
        return None;
    }
    Some(name.to_string())
}

fn strip_suffixes<'a>(title: &'a str, suffixes: &[&str]) -> &'a str {
    suffixes.iter().find_map(|s| title.strip_suffix(s)).unwrap_or(title)
}

fn strip_prefixes<'a>(title: &'a str, prefixes: &[&str]) -> &'a str {
    prefixes.iter().find_map(|p| title.strip_prefix(p)).unwrap_or(title)
}

/// A Google Meet code such as `abc-defg-hij`.
fn is_meet_code(name: &str) -> bool {
    let parts: Vec<&str> = name.split('-').collect();
    parts.len() == 3 && parts.iter().map(|p| p.len()).eq([3, 4, 3]) && name.chars().all(|c| c.is_ascii_lowercase() || c == '-')
}

/// "(3)" or "2 new messages": an unread count, not a name.
fn is_count_only(name: &str) -> bool {
    name.chars().all(|c| c.is_ascii_digit() || c.is_whitespace()) || name.ends_with("new messages") || name.ends_with("new message")
}

#[cfg(test)]
mod tests {
    use super::*;

    fn titles(list: &[&str]) -> Vec<String> {
        list.iter().map(|s| s.to_string()).collect()
    }

    #[test]
    fn teams_window_names_the_person_or_meeting() {
        assert_eq!(meeting_name(SourceApp::MicrosoftTeams, &titles(&["Chat | Microsoft Teams", "Omar Khan | Microsoft Teams"])), Some("Omar Khan".into()));
        assert_eq!(meeting_name(SourceApp::MicrosoftTeams, &titles(&["Acme kickoff | Microsoft Teams (work or school)"])), Some("Acme kickoff".into()));
        assert_eq!(meeting_name(SourceApp::MicrosoftTeams, &titles(&["Microsoft Teams"])), None);
    }

    #[test]
    fn meet_tab_title_is_the_meeting_unless_it_is_a_code() {
        assert_eq!(meeting_name(SourceApp::GoogleMeet, &titles(&["Meet - Weekly sync"])), Some("Weekly sync".into()));
        assert_eq!(meeting_name(SourceApp::GoogleMeet, &titles(&["Meet - abc-defg-hij"])), None);
    }

    #[test]
    fn generic_app_windows_give_nothing() {
        assert_eq!(meeting_name(SourceApp::Zoom, &titles(&["Zoom Meeting", "Zoom Workplace"])), None);
        assert_eq!(meeting_name(SourceApp::Whatsapp, &titles(&["WhatsApp", "(3)"])), None);
        assert_eq!(meeting_name(SourceApp::Whatsapp, &titles(&["Sara Ahmed"])), Some("Sara Ahmed".into()));
        assert_eq!(meeting_name(SourceApp::Other, &titles(&["Omar Khan"])), None);
    }
}
