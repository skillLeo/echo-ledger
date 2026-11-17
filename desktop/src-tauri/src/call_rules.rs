//! Call rules (docs/PLAN_CALL_RULES.md 1-2): what happens to a call, decided so most calls need no question.
//!
//! The four choices have the same words everywhere ([`CallChoice::label`]): Transcribe, Keep audio, Delete (on the
//! after-call card only) and Don't record (a rule only). A call's choice comes from the first rule that names it:
//! the contact's rule, then the source app's (Zoom, Meet, Teams, Slack, WhatsApp desktop), then the global default
//! (Settings › Calls › After a call, the same setting as before). "Don't record" is decided before capture starts:
//! an automatic call it covers is never recorded and nothing is written to disk. A call started by hand is the person
//! asking to record it, so a Don't record rule then asks after the call instead.
//!
//! Pure; unit-tested. The settings hold the rules ([`crate::settings::Settings`]); `actions` applies them.

use std::collections::HashMap;

use serde::{Deserialize, Serialize};

use crate::settings::AfterCall;

/// What happens to a call.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum CallChoice {
    /// The after-call card asks; with no answer it keeps the audio.
    Ask,
    /// Saved as audio only: no transcript, no cost; it can be transcribed later.
    KeepAudio,
    /// Kept and transcribed (uses minutes).
    Transcribe,
    /// Never recorded: capture does not start.
    DontRecord,
}

impl CallChoice {
    /// The words people read, the same in every place (plan 1).
    pub fn label(self) -> &'static str {
        match self {
            CallChoice::Ask => "Ask me",
            CallChoice::KeepAudio => "Keep audio",
            CallChoice::Transcribe => "Transcribe",
            CallChoice::DontRecord => "Don't record",
        }
    }

    /// What the after-call step does with a recording this choice covers. Don't record reaches here only for a call
    /// started by hand: it asks.
    pub fn after_call(self) -> AfterCall {
        match self {
            CallChoice::Ask | CallChoice::DontRecord => AfterCall::Ask,
            CallChoice::KeepAudio => AfterCall::AlwaysAudio,
            CallChoice::Transcribe => AfterCall::AlwaysTranscribe,
        }
    }
}

impl From<AfterCall> for CallChoice {
    fn from(setting: AfterCall) -> Self {
        match setting {
            AfterCall::Ask => CallChoice::Ask,
            AfterCall::AlwaysAudio => CallChoice::KeepAudio,
            AfterCall::AlwaysTranscribe => CallChoice::Transcribe,
            AfterCall::DontRecord => CallChoice::DontRecord,
        }
    }
}

/// Which rule decided.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum RuleFrom {
    Contact,
    Source,
    Default,
}

/// The sources that can have a rule on the desktop (plan 2), with the call apps each covers.
pub const SOURCES: [(&str, &str, &[&str]); 5] = [
    ("zoom", "Zoom", &["zoom"]),
    ("meet", "Google Meet", &["google_meet"]),
    ("teams", "Microsoft Teams", &["microsoft_teams"]),
    ("slack", "Slack", &["slack"]),
    ("whatsapp", "WhatsApp desktop", &["whatsapp", "whatsapp_business"]),
];

/// The rule key of a call app (its wire value, `google_meet`…), none for an app without source rules.
pub fn source_key(source_app: &str) -> Option<&'static str> {
    SOURCES.iter().find(|(_, _, apps)| apps.contains(&source_app)).map(|(key, _, _)| *key)
}

/// A contact's rule key: the name as people write it, compared without case or extra spaces.
pub fn contact_key(name: &str) -> String {
    name.split_whitespace().collect::<Vec<_>>().join(" ").to_lowercase()
}

/// The rules as the settings keep them.
#[derive(Clone, Copy, Debug)]
pub struct Rules<'a> {
    pub default: CallChoice,
    pub sources: &'a HashMap<String, CallChoice>,
    pub contacts: &'a HashMap<String, CallChoice>,
}

/// The choice for a call from `source_app` with `contact` (pure): contact rule > source rule > global default.
pub fn resolve(rules: Rules<'_>, source_app: &str, contact: Option<&str>) -> (CallChoice, RuleFrom) {
    if let Some(choice) = contact.map(contact_key).filter(|k| !k.is_empty()).and_then(|k| rules.contacts.get(&k)) {
        return (*choice, RuleFrom::Contact);
    }
    if let Some(choice) = source_key(source_app).and_then(|k| rules.sources.get(k)) {
        return (*choice, RuleFrom::Source);
    }
    (rules.default, RuleFrom::Default)
}

/// Whether a call starts recording (pure): only an automatic start under a Don't record rule is refused; a start by
/// hand is the person asking.
pub fn records(choice: CallChoice, auto: bool) -> bool {
    !(auto && choice == CallChoice::DontRecord)
}

/// Rule maps as they come from the window: only known keys. On a contact, Ask me means "follow the default" (plan 2),
/// so `ask_follows_default` removes it; a source can say Ask me against another default.
pub fn cleaned(rules: &HashMap<String, CallChoice>, known: impl Fn(&str) -> bool, ask_follows_default: bool) -> HashMap<String, CallChoice> {
    rules.iter().filter(|(key, choice)| known(key) && !(ask_follows_default && **choice == CallChoice::Ask)).map(|(k, c)| (k.clone(), *c)).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn map(pairs: &[(&str, CallChoice)]) -> HashMap<String, CallChoice> {
        pairs.iter().map(|(k, c)| (k.to_string(), *c)).collect()
    }

    #[test]
    fn a_contact_rule_beats_a_source_rule_which_beats_the_default() {
        let sources = map(&[("zoom", CallChoice::Transcribe), ("whatsapp", CallChoice::DontRecord)]);
        let contacts = map(&[("ahmed khan", CallChoice::KeepAudio)]);
        let rules = Rules { default: CallChoice::Ask, sources: &sources, contacts: &contacts };
        assert_eq!(resolve(rules, "zoom", Some("  Ahmed   KHAN ")), (CallChoice::KeepAudio, RuleFrom::Contact));
        assert_eq!(resolve(rules, "zoom", Some("Sara")), (CallChoice::Transcribe, RuleFrom::Source));
        assert_eq!(resolve(rules, "zoom", None), (CallChoice::Transcribe, RuleFrom::Source));
        assert_eq!(resolve(rules, "whatsapp_business", None), (CallChoice::DontRecord, RuleFrom::Source), "WhatsApp Business is WhatsApp desktop");
        assert_eq!(resolve(rules, "microsoft_teams", Some("")), (CallChoice::Ask, RuleFrom::Default));
        assert_eq!(resolve(rules, "telegram", Some("Sara")), (CallChoice::Ask, RuleFrom::Default), "no source rule for Telegram");
        let quiet = Rules { default: CallChoice::DontRecord, ..rules };
        assert_eq!(resolve(quiet, "discord", None), (CallChoice::DontRecord, RuleFrom::Default));
    }

    #[test]
    fn don_t_record_stops_only_an_automatic_start() {
        assert!(!records(CallChoice::DontRecord, true));
        assert!(records(CallChoice::DontRecord, false), "Record now is the person asking");
        for choice in [CallChoice::Ask, CallChoice::KeepAudio, CallChoice::Transcribe] {
            assert!(records(choice, true));
        }
        assert_eq!(CallChoice::DontRecord.after_call(), AfterCall::Ask, "a call recorded by hand under the rule asks");
    }

    #[test]
    fn the_four_choices_have_one_set_of_words_and_map_to_the_after_call_setting() {
        let words: Vec<&str> = [CallChoice::Transcribe, CallChoice::KeepAudio, CallChoice::DontRecord, CallChoice::Ask].iter().map(|c| c.label()).collect();
        assert_eq!(words, ["Transcribe", "Keep audio", "Don't record", "Ask me"]);
        for setting in [AfterCall::Ask, AfterCall::AlwaysAudio, AfterCall::AlwaysTranscribe] {
            assert_eq!(CallChoice::from(setting).after_call(), setting);
        }
        assert_eq!(serde_json::to_value(CallChoice::DontRecord).unwrap(), "dont_record");
        assert_eq!(serde_json::to_value(CallChoice::KeepAudio).unwrap(), "keep_audio");
    }

    #[test]
    fn rules_from_the_window_keep_only_known_keys_and_drop_ask_me() {
        let sent = map(&[("zoom", CallChoice::Transcribe), ("meet", CallChoice::Ask), ("bogus", CallChoice::KeepAudio)]);
        assert_eq!(cleaned(&sent, source_key_known, false), map(&[("zoom", CallChoice::Transcribe), ("meet", CallChoice::Ask)]), "a source can ask against another default");
        assert_eq!(cleaned(&sent, source_key_known, true), map(&[("zoom", CallChoice::Transcribe)]), "a contact's Ask me follows the default");
    }

    fn source_key_known(key: &str) -> bool {
        SOURCES.iter().any(|(k, _, _)| *k == key)
    }
}
