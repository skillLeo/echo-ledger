//! The instruction pack: the fixed rules, the meeting-type lines, the hint and language lines and the task lines
//! (`instructions/pack.toml`, built in; `RELAY_INSTRUCTIONS` names another file of the same shape).

use std::path::Path;

use serde::Deserialize;

use crate::config::ConfigError;
use crate::domain::{HintLevel, LanguageHelp, MeetingType};

pub const DEFAULT_PACK: &str = include_str!("../instructions/pack.toml");

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InstructionPack {
    pub rules: String,
    pub meeting: MeetingLines,
    pub hint: HintLines,
    pub language: LanguageLines,
    pub task: TaskLines,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct MeetingLines {
    pub interview: String,
    pub boss: String,
    pub existing_client: String,
    pub new_client: String,
    pub general: String,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct HintLines {
    pub keywords: String,
    pub outline: String,
    pub full: String,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LanguageLines {
    pub urdu: String,
    pub roman_urdu: String,
    pub arabic: String,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TaskLines {
    pub answer: String,
    pub answer_partial: String,
    pub help_now: String,
    pub regenerate: String,
    pub shorter: String,
    pub formal: String,
    /// Contains `{language}`.
    pub translate: String,
}

impl InstructionPack {
    pub fn built_in() -> Self {
        Self::parse(DEFAULT_PACK).unwrap_or_else(|error| panic!("the built-in instructions are broken: {error}"))
    }

    pub fn parse(text: &str) -> Result<Self, ConfigError> {
        let mut pack: InstructionPack =
            toml::from_str(text).map_err(|error| ConfigError::Parse(error.message().to_owned()))?;
        if !pack.task.translate.contains("{language}") {
            return Err(ConfigError::Invalid("task.translate must contain {language}".to_owned()));
        }
        pack.trim();
        Ok(pack)
    }

    pub fn load(path: Option<&Path>) -> Result<Self, ConfigError> {
        match path {
            None => Self::parse(DEFAULT_PACK),
            Some(path) => {
                let text = std::fs::read_to_string(path).map_err(|error| ConfigError::Read(error.kind().to_string()))?;
                Self::parse(&text)
            }
        }
    }

    /// Multi-line TOML strings start with a newline and end with one; the request carries the text only.
    fn trim(&mut self) {
        let meeting = &mut self.meeting;
        let hint = &mut self.hint;
        let language = &mut self.language;
        let task = &mut self.task;
        for text in [
            &mut self.rules,
            &mut meeting.interview,
            &mut meeting.boss,
            &mut meeting.existing_client,
            &mut meeting.new_client,
            &mut meeting.general,
            &mut hint.keywords,
            &mut hint.outline,
            &mut hint.full,
            &mut language.urdu,
            &mut language.roman_urdu,
            &mut language.arabic,
            &mut task.answer,
            &mut task.answer_partial,
            &mut task.help_now,
            &mut task.regenerate,
            &mut task.shorter,
            &mut task.formal,
            &mut task.translate,
        ] {
            *text = text.trim().to_owned();
        }
    }

    pub fn meeting(&self, meeting: MeetingType) -> &str {
        match meeting {
            MeetingType::Interview => &self.meeting.interview,
            MeetingType::Boss => &self.meeting.boss,
            MeetingType::ExistingClient => &self.meeting.existing_client,
            MeetingType::NewClient => &self.meeting.new_client,
            MeetingType::General => &self.meeting.general,
        }
    }

    pub fn hint(&self, hint: HintLevel) -> &str {
        match hint {
            HintLevel::Keywords => &self.hint.keywords,
            HintLevel::Outline => &self.hint.outline,
            HintLevel::Full => &self.hint.full,
        }
    }

    pub fn language(&self, language: LanguageHelp) -> Option<&str> {
        match language {
            LanguageHelp::Off => None,
            LanguageHelp::Urdu => Some(&self.language.urdu),
            LanguageHelp::RomanUrdu => Some(&self.language.roman_urdu),
            LanguageHelp::Arabic => Some(&self.language.arabic),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_built_in_pack_carries_the_output_contract() {
        let pack = InstructionPack::built_in();
        for rule in ["at most 25 words", "at most 3 short bullets", "**bold**", "Say next:", "exactly SKIP", "Never invent"] {
            assert!(pack.rules.contains(rule), "{rule}");
        }
        assert!(pack.rules.contains("Never ask for something that was already said"));
        assert!(!pack.rules.starts_with('\n') && !pack.rules.ends_with('\n'));
        assert!(pack.language(LanguageHelp::Off).is_none());
        assert!(pack.language(LanguageHelp::Urdu).is_some_and(|line| line.contains("اردو:")));
    }

    #[test]
    fn a_translate_line_without_its_placeholder_is_refused() {
        let broken = DEFAULT_PACK.replace("in {language} in", "in");
        assert!(InstructionPack::parse(&broken).is_err());
    }
}
