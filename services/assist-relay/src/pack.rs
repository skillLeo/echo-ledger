//! The Live Assist pack, the same files as the server's `resources/prompts/assist` (manifest.json, system.md and one
//! template per meeting type). A template's `{{name}}` placeholders are the context blocks in cache order, what never
//! changes first and the question last; the relay fills them as the server's renderer does (an empty block is
//! `(none)`) and cuts the result into cacheable segments at the blocks.
//!
//! The relay carries a copy of pack 1.0.2 (`pack/`), so it runs without the server; `RELAY_PACK_DIR` names another
//! folder of the same shape, and the server's own copy replaces it once the internal endpoint for it exists.

use std::collections::{BTreeMap, HashMap};
use std::path::Path;

use serde::Deserialize;

use crate::config::ConfigError;
use crate::domain::{HintLevel, LanguageHelp, MeetingType};

/// The blocks the relay fills, in the order every template must name them.
pub const PLACEHOLDERS: [&str; 11] = [
    "system_rules",
    "brief",
    "documents",
    "project_memory",
    "facts_ledger",
    "running_summary",
    "transcript_blocks",
    "last_turns",
    "hint_level",
    "language_help",
    "question",
];

/// What an empty block says (the server's `PromptPack::NONE`).
pub const NONE: &str = "(none)";

const MEETINGS: [MeetingType; 5] =
    [MeetingType::Interview, MeetingType::Boss, MeetingType::ExistingClient, MeetingType::NewClient, MeetingType::General];

const BUILT_IN: [(&str, &str); 7] = [
    ("manifest.json", include_str!("../pack/manifest.json")),
    ("system.md", include_str!("../pack/system.md")),
    ("interview.md", include_str!("../pack/interview.md")),
    ("boss.md", include_str!("../pack/boss.md")),
    ("existing_client.md", include_str!("../pack/existing_client.md")),
    ("new_client.md", include_str!("../pack/new_client.md")),
    ("general.md", include_str!("../pack/general.md")),
];

#[derive(Deserialize)]
struct Manifest {
    version: String,
    #[serde(default)]
    contract: String,
    system: String,
    placeholders: Vec<String>,
    language_line_prefixes: BTreeMap<String, String>,
    skip: String,
    consent_line: String,
    templates: BTreeMap<String, TemplateEntry>,
}

#[derive(Deserialize)]
struct TemplateEntry {
    file: String,
    default_hint_level: HintLevel,
    #[serde(default)]
    reminder: Option<String>,
    #[serde(default)]
    reminder_kind: Option<String>,
}

/// One meeting type's template, cut at its placeholders: `pieces[i]` is the text before placeholder `i`, and the last
/// piece the text after the question.
#[derive(Debug, Clone)]
pub struct Template {
    pub pieces: Vec<String>,
    pub default_hint_level: HintLevel,
    /// The responsible-use line the app shows once (the consent line, or the interview reminder).
    pub reminder: String,
}

#[derive(Debug, Clone)]
pub struct Pack {
    pub version: String,
    pub contract: String,
    pub consent_line: String,
    system_rules: String,
    language_prefixes: BTreeMap<String, String>,
    templates: HashMap<MeetingType, Template>,
}

impl Pack {
    /// The copy built into the relay (pack 1.0.2). Checked by the tests, so it cannot fail in a release.
    pub fn built_in() -> Self {
        let files: HashMap<&str, &str> = BUILT_IN.into_iter().collect();
        Self::from_files(|name| files.get(name).map(|text| (*text).to_owned()).ok_or_else(|| missing(name)))
            .unwrap_or_else(|error| panic!("the built-in pack is broken: {error}"))
    }

    /// A pack folder of the same shape (`RELAY_PACK_DIR`).
    pub fn load_dir(dir: &Path) -> Result<Self, ConfigError> {
        Self::from_files(|name| {
            if name.is_empty() || name.contains(['/', '\\']) || name.starts_with('.') {
                return Err(ConfigError::Invalid(format!("the pack names a file it cannot have: {name}")));
            }
            std::fs::read_to_string(dir.join(name)).map_err(|_| missing(name))
        })
    }

    pub fn from_files(read: impl Fn(&str) -> Result<String, ConfigError>) -> Result<Self, ConfigError> {
        let manifest: Manifest = serde_json::from_str(&read("manifest.json")?)
            .map_err(|error| ConfigError::Parse(format!("pack manifest: {error}")))?;
        if manifest.placeholders != PLACEHOLDERS {
            return Err(ConfigError::Invalid(format!(
                "pack {} names the blocks {:?}; this relay fills {:?}",
                manifest.version, manifest.placeholders, PLACEHOLDERS
            )));
        }
        if manifest.skip != "SKIP" {
            return Err(ConfigError::Invalid("the pack's skip word must be SKIP".to_owned()));
        }
        let system_rules = read(&manifest.system)?;
        if !placeholders_in(&system_rules).is_empty() {
            return Err(ConfigError::Invalid("the pack's system rules hold placeholders".to_owned()));
        }
        let mut templates = HashMap::new();
        for meeting in MEETINGS {
            let entry = manifest
                .templates
                .get(meeting.as_str())
                .ok_or_else(|| ConfigError::Invalid(format!("the pack has no {} template", meeting.as_str())))?;
            let text = read(&entry.file)?;
            let (pieces, names) = split(&text);
            if names != PLACEHOLDERS {
                return Err(ConfigError::Invalid(format!(
                    "{}: placeholders {names:?} instead of {PLACEHOLDERS:?}",
                    meeting.as_str()
                )));
            }
            let reminder = if entry.reminder_kind.as_deref() == Some("consent") {
                manifest.consent_line.clone()
            } else {
                entry.reminder.clone().unwrap_or_default()
            };
            templates.insert(meeting, Template { pieces, default_hint_level: entry.default_hint_level, reminder });
        }
        Ok(Pack {
            version: manifest.version,
            contract: manifest.contract,
            consent_line: manifest.consent_line,
            system_rules,
            language_prefixes: manifest.language_line_prefixes,
            templates,
        })
    }

    pub fn system_rules(&self) -> &str {
        &self.system_rules
    }

    pub fn template(&self, meeting: MeetingType) -> &Template {
        // Every meeting type was checked at load.
        &self.templates[&meeting]
    }

    /// The prefix of the language-help line ("Urdu:", "Roman Urdu:", "Arabic:").
    pub fn language_prefix(&self, language: LanguageHelp) -> Option<&str> {
        let key = match language {
            LanguageHelp::Off => return None,
            LanguageHelp::Urdu => "urdu",
            LanguageHelp::RomanUrdu => "roman_urdu",
            LanguageHelp::Arabic => "arabic",
        };
        self.language_prefixes.get(key).map(String::as_str)
    }
}

fn missing(name: &str) -> ConfigError {
    ConfigError::Read(format!("the pack has no {name}"))
}

/// Splits `text` at its `{{ name }}` placeholders: the pieces around them, and the names in order.
fn split(text: &str) -> (Vec<String>, Vec<String>) {
    let mut pieces = Vec::new();
    let mut names = Vec::new();
    let mut rest = text;
    let mut piece = String::new();
    while let Some(open) = rest.find("{{") {
        let after = &rest[open + 2..];
        let name_end = after.find("}}");
        let name = name_end.map(|end| after[..end].trim());
        match (name, name_end) {
            (Some(name), Some(end)) if !name.is_empty() && name.bytes().all(|b| b.is_ascii_lowercase() || b == b'_') => {
                piece.push_str(&rest[..open]);
                pieces.push(std::mem::take(&mut piece));
                names.push(name.to_owned());
                rest = &after[end + 2..];
            }
            _ => {
                piece.push_str(&rest[..open + 2]);
                rest = after;
            }
        }
    }
    piece.push_str(rest);
    pieces.push(piece);
    (pieces, names)
}

fn placeholders_in(text: &str) -> Vec<String> {
    split(text).1
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_built_in_pack_is_1_0_2_for_contract_1_9_0_b() {
        let pack = Pack::built_in();
        assert_eq!(pack.version, "1.0.2");
        assert_eq!(pack.contract, "1.9.0-b");
        assert_eq!(
            pack.consent_line,
            "Just so you know: I record this call and use an AI assistant that takes notes and suggests points to me. Is that okay with you?"
        );
        assert_eq!(pack.language_prefix(LanguageHelp::Urdu), Some("Urdu:"));
        assert_eq!(pack.language_prefix(LanguageHelp::RomanUrdu), Some("Roman Urdu:"));
        assert_eq!(pack.language_prefix(LanguageHelp::Arabic), Some("Arabic:"));
        assert_eq!(pack.template(MeetingType::Interview).default_hint_level, HintLevel::Outline);
        assert_eq!(pack.template(MeetingType::Boss).default_hint_level, HintLevel::Full);
        assert_eq!(pack.template(MeetingType::Interview).reminder, "Check whether this interview allows AI help.");
        assert_eq!(pack.template(MeetingType::General).reminder, pack.consent_line);
        for rule in ["At most 25 words", "at most 3 bullets", "**bold**", "Say next:", "exactly SKIP", "Never invent"] {
            assert!(pack.system_rules().contains(rule), "{rule}");
        }
    }

    #[test]
    fn every_template_is_cut_into_twelve_pieces() {
        let pack = Pack::built_in();
        for meeting in MEETINGS {
            let template = pack.template(meeting);
            assert_eq!(template.pieces.len(), PLACEHOLDERS.len() + 1);
            assert_eq!(template.pieces[0], "", "{meeting:?} starts with the system rules");
        }
    }

    #[test]
    fn placeholders_are_found_with_spaces_and_odd_braces_stay_text() {
        let (pieces, names) = split("a{{ brief }}b{{x y}}c{{}}d{{question}}");
        assert_eq!(names, vec!["brief", "question"]);
        assert_eq!(pieces, vec!["a", "b{{x y}}c{{}}d", ""]);
    }

    #[test]
    fn a_pack_with_blocks_out_of_order_is_refused() {
        let files: HashMap<&str, String> = BUILT_IN
            .iter()
            .map(|(name, text)| (*name, if *name == "boss.md" { text.replace("{{documents}}", "{{tmp}}").replace("{{brief}}", "{{documents}}").replace("{{tmp}}", "{{brief}}") } else { (*text).to_owned() }))
            .collect();
        let error = Pack::from_files(|name| files.get(name).cloned().ok_or_else(|| missing(name))).unwrap_err();
        assert!(error.to_string().contains("boss"), "{error}");
    }
}
