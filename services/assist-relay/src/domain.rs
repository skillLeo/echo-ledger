//! The words the relay, its settings and its messages share: modes, plans, channels and the prep choices.

use serde::{Deserialize, Serialize, Serializer};

/// How much model a suggestion gets. Ordered from the cheapest to the most capable.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Mode {
    Fast,
    Balanced,
    Deep,
    Max,
}

impl Mode {
    /// Every mode, the most capable first: the order the budget steps down in.
    pub const LADDER: [Mode; 4] = [Mode::Max, Mode::Deep, Mode::Balanced, Mode::Fast];

    pub fn as_str(self) -> &'static str {
        match self {
            Mode::Fast => "fast",
            Mode::Balanced => "balanced",
            Mode::Deep => "deep",
            Mode::Max => "max",
        }
    }
}

/// The account's plan, from the assist token. The relay checks it again so a token can never unlock more than the plan.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Plan {
    Free,
    Pro,
    Ultra,
}

impl Plan {
    /// Free has practice only (no live suggestions), Pro has Fast and Balanced, Ultra has every mode.
    pub fn allows(self, mode: Mode) -> bool {
        match self {
            Plan::Free => false,
            Plan::Pro => matches!(mode, Mode::Fast | Mode::Balanced),
            Plan::Ultra => true,
        }
    }
}

/// The mode the relay actually runs: a mode, or no suggestions at all (captions keep going on the app).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum EffectiveMode {
    Assist(Mode),
    CaptionsOnly,
}

impl EffectiveMode {
    pub fn as_str(self) -> &'static str {
        match self {
            EffectiveMode::Assist(mode) => mode.as_str(),
            EffectiveMode::CaptionsOnly => "captions_only",
        }
    }

    pub fn mode(self) -> Option<Mode> {
        match self {
            EffectiveMode::Assist(mode) => Some(mode),
            EffectiveMode::CaptionsOnly => None,
        }
    }
}

impl Serialize for EffectiveMode {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        serializer.serialize_str(self.as_str())
    }
}

/// Who spoke. `me` is the person using EchoLedger; `other` is everyone else on the call (the speech grants call that
/// channel `client`, which is accepted too).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Channel {
    Me,
    #[serde(alias = "client")]
    Other,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum MeetingType {
    Interview,
    Boss,
    ExistingClient,
    NewClient,
    #[default]
    General,
}

impl MeetingType {
    pub fn as_str(self) -> &'static str {
        match self {
            MeetingType::Interview => "interview",
            MeetingType::Boss => "boss",
            MeetingType::ExistingClient => "existing_client",
            MeetingType::NewClient => "new_client",
            MeetingType::General => "general",
        }
    }
}

/// How much of the answer the card shows.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum HintLevel {
    Keywords,
    #[default]
    Outline,
    Full,
}

/// An extra line explaining the other person's question in my language.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum LanguageHelp {
    #[default]
    Off,
    Urdu,
    RomanUrdu,
    Arabic,
}

impl LanguageHelp {
    pub fn label(self) -> &'static str {
        match self {
            LanguageHelp::Off => "English",
            LanguageHelp::Urdu => "Urdu",
            LanguageHelp::RomanUrdu => "Roman Urdu",
            LanguageHelp::Arabic => "Arabic",
        }
    }
}

/// Suggestions are made, or paused by me (captions and the transcript keep going either way).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum AssistState {
    #[default]
    On,
    Paused,
}

/// Who runs a model.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum ProviderKind {
    Anthropic,
    Openai,
    Gemini,
    Groq,
}

impl ProviderKind {
    pub const ALL: [ProviderKind; 4] = [ProviderKind::Anthropic, ProviderKind::Openai, ProviderKind::Gemini, ProviderKind::Groq];

    pub fn as_str(self) -> &'static str {
        match self {
            ProviderKind::Anthropic => "anthropic",
            ProviderKind::Openai => "openai",
            ProviderKind::Gemini => "gemini",
            ProviderKind::Groq => "groq",
        }
    }

    pub fn label(self) -> &'static str {
        match self {
            ProviderKind::Anthropic => "Anthropic",
            ProviderKind::Openai => "OpenAI",
            ProviderKind::Gemini => "Gemini",
            ProviderKind::Groq => "Groq",
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn plans_gate_modes() {
        for mode in Mode::LADDER {
            assert!(!Plan::Free.allows(mode));
            assert!(Plan::Ultra.allows(mode));
        }
        assert!(Plan::Pro.allows(Mode::Fast));
        assert!(Plan::Pro.allows(Mode::Balanced));
        assert!(!Plan::Pro.allows(Mode::Deep));
        assert!(!Plan::Pro.allows(Mode::Max));
    }

    #[test]
    fn the_ladder_runs_from_the_most_capable_mode_down() {
        assert!(Mode::LADDER.windows(2).all(|pair| pair[0] > pair[1]));
    }

    #[test]
    fn the_speech_grants_name_for_the_other_channel_is_accepted() {
        let channel: Channel = serde_json::from_str("\"client\"").unwrap();
        assert_eq!(channel, Channel::Other);
        assert_eq!(serde_json::to_string(&Channel::Other).unwrap(), "\"other\"");
    }
}
