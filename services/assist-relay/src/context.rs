//! Building what a model reads: the pack's template for the meeting type with every block filled, in the template's
//! cache order, and cut into segments at the blocks:
//!
//! 1. the system rules (cache point; the same for every session), the meeting's lines and the brief, the documents,
//!    the project memory (cache point), then the facts ledger and the running summary;
//! 2. the older transcript in blocks of about 1,500 tokens (cache point after the last full block);
//! 3. the last turns word for word, "Now" (hint level and language help), and the question.
//!
//! Everything above the question changes rarely and a full transcript block never changes again, so each request
//! re-reads most of its input from the provider's cache. Fast mode leaves the older blocks out; every mode gets the
//! facts and the summary. Joined, the segments are exactly what the server's renderer makes of the same blocks.

use std::fmt;
use std::fmt::Write as _;

use crate::config::{ContextConfig, TranscriptScope};
use crate::domain::{Channel, HintLevel, LanguageHelp};
use crate::pack::{NONE, Pack};
use crate::protocol::{Brief, Document, Fact};
use crate::text::estimate_tokens;

/// The relay's own lines for asks the pack has no wording for (help now and the rewrites).
pub mod asks {
    pub const PARTIAL: &str = "(Them may still be finishing this sentence.)";
    pub const HELP_NOW: &str = "(Me asked for help now: suggest what Me can say next.)";
    pub const HELP_NOW_OPENING: &str =
        "(Me asked for help now, before Them said anything: suggest how Me can open the call, from the brief.)";
    pub const REGENERATE: &str = "Write a different suggestion for these words. Do not repeat the previous one.";
    pub const SHORTER: &str = "Make the previous suggestion shorter: the first line only, at most 12 words, and at most one bullet.";
    pub const FORMAL: &str = "Rewrite the previous suggestion in a more formal, polite tone. Keep the same facts; do not make it longer.";
    /// `{language}` is "Urdu", "Roman Urdu" or "Arabic".
    pub const TRANSLATE: &str =
        "Explain the previous suggestion in {language} in one or two simple lines, then give the English line to say.";
    /// Fast mode leaves the older transcript out.
    pub const OLDER_LEFT_OUT: &str = "(not included in this mode; the running summary covers it)";
}

/// Where a segment goes in a provider request: the fixed system part, or the user message.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Role {
    System,
    User,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SegmentKind {
    Rules,
    Brief,
    Documents,
    Memory,
    Facts,
    Summary,
    TranscriptBlock,
    LastTurns,
    Now,
    Question,
}

impl SegmentKind {
    pub fn as_str(self) -> &'static str {
        match self {
            SegmentKind::Rules => "system rules",
            SegmentKind::Brief => "meeting and brief",
            SegmentKind::Documents => "documents",
            SegmentKind::Memory => "project memory",
            SegmentKind::Facts => "facts ledger",
            SegmentKind::Summary => "running summary",
            SegmentKind::TranscriptBlock => "transcript block",
            SegmentKind::LastTurns => "last turns",
            SegmentKind::Now => "now",
            SegmentKind::Question => "question",
        }
    }
}

#[derive(Clone)]
pub struct Segment {
    pub kind: SegmentKind,
    pub role: Role,
    pub text: String,
    pub tokens: u32,
    /// A cache breakpoint after this segment (Anthropic `cache_control`; other providers cache prefixes by themselves).
    pub cache_after: bool,
}

impl fmt::Debug for Segment {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Segment")
            .field("kind", &self.kind)
            .field("role", &self.role)
            .field("tokens", &self.tokens)
            .field("cache_after", &self.cache_after)
            .finish()
    }
}

impl Segment {
    fn new(kind: SegmentKind, role: Role, text: String) -> Self {
        let tokens = estimate_tokens(&text);
        Segment { kind, role, text, tokens, cache_after: false }
    }

    fn cached(mut self) -> Self {
        self.cache_after = true;
        self
    }
}

/// What one request sends, segment by segment.
#[derive(Debug, Clone, Default)]
pub struct Context {
    pub segments: Vec<Segment>,
}

impl Context {
    pub fn tokens(&self) -> u32 {
        self.segments.iter().map(|s| s.tokens).sum()
    }

    /// Tokens up to and including the last cache breakpoint.
    pub fn cacheable_tokens(&self) -> u32 {
        let last = self.segments.iter().rposition(|s| s.cache_after);
        last.map_or(0, |i| self.segments[..=i].iter().map(|s| s.tokens).sum())
    }

    pub fn breakpoints(&self) -> usize {
        self.segments.iter().filter(|s| s.cache_after).count()
    }

    /// The whole text, as one string (what the server's renderer makes of the same blocks).
    pub fn text(&self) -> String {
        self.segments.iter().map(|s| s.text.as_str()).collect()
    }

    /// A picture of the request with its segment boundaries, for the snapshot tests and nothing else (never logged).
    pub fn render(&self) -> String {
        let mut out = String::new();
        for segment in &self.segments {
            let role = match segment.role {
                Role::System => "system",
                Role::User => "user",
            };
            let cache = if segment.cache_after { " | cache" } else { "" };
            let _ = writeln!(out, "<<< {role} | {} | ~{} tokens{cache} >>>", segment.kind.as_str(), segment.tokens);
            out.push_str(&segment.text);
            if !segment.text.ends_with('\n') {
                out.push('\n');
            }
        }
        out
    }
}

/// The call as the app described it in `context_update`.
#[derive(Debug, Clone, Default)]
pub struct CallContext {
    pub brief: Brief,
    /// `None`: the meeting type's default from the pack.
    pub hint_level: Option<HintLevel>,
    pub language_help: LanguageHelp,
    pub documents: Vec<Document>,
    pub memory: String,
    pub facts: Vec<Fact>,
    pub summary: String,
}

/// One final turn.
#[derive(Clone)]
pub struct TranscriptTurn {
    pub turn_id: String,
    pub channel: Channel,
    pub text: String,
    pub start_ms: Option<u64>,
}

impl fmt::Debug for TranscriptTurn {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("TranscriptTurn").field("turn_id", &self.turn_id).field("channel", &self.channel).finish()
    }
}

impl TranscriptTurn {
    fn line(&self) -> String {
        let who = match self.channel {
            Channel::Me => "Me",
            Channel::Other => "Them",
        };
        match self.start_ms {
            Some(ms) => {
                let seconds = ms / 1000;
                format!("[{:02}:{:02}] {who}: {}", seconds / 60, seconds % 60, self.text)
            }
            None => format!("{who}: {}", self.text),
        }
    }
}

#[derive(Clone, Default)]
struct Block {
    text: String,
    tokens: u32,
}

/// The final turns of the call. Turns older than the last few go into blocks; a block closes at about `block_tokens`
/// and never changes after that.
#[derive(Clone)]
pub struct Transcript {
    turns: Vec<TranscriptTurn>,
    /// `turns[..in_blocks]` are in `full` or `open`.
    in_blocks: usize,
    full: Vec<Block>,
    open: Block,
    block_tokens: u32,
    recent_turns: usize,
}

impl fmt::Debug for Transcript {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Transcript").field("turns", &self.turns.len()).field("full_blocks", &self.full.len()).finish()
    }
}

impl Transcript {
    pub fn new(config: &ContextConfig) -> Self {
        Transcript {
            turns: Vec::new(),
            in_blocks: 0,
            full: Vec::new(),
            open: Block::default(),
            block_tokens: config.block_tokens,
            recent_turns: config.recent_turns,
        }
    }

    pub fn push(&mut self, turn: TranscriptTurn) {
        self.turns.push(turn);
        while self.turns.len() - self.in_blocks > self.recent_turns {
            let line = self.turns[self.in_blocks].line();
            if !self.open.text.is_empty() {
                self.open.text.push('\n');
            }
            self.open.text.push_str(&line);
            self.open.tokens = estimate_tokens(&self.open.text);
            self.in_blocks += 1;
            if self.open.tokens >= self.block_tokens {
                self.full.push(std::mem::take(&mut self.open));
            }
        }
    }

    pub fn len(&self) -> usize {
        self.turns.len()
    }

    pub fn is_empty(&self) -> bool {
        self.turns.is_empty()
    }

    pub fn latest(&self) -> &[TranscriptTurn] {
        &self.turns[self.in_blocks..]
    }

    pub fn last_from(&self, channel: Channel) -> Option<&TranscriptTurn> {
        self.turns.iter().rev().find(|t| t.channel == channel)
    }

    pub fn find(&self, turn_id: &str) -> Option<&TranscriptTurn> {
        self.turns.iter().rev().find(|t| t.turn_id == turn_id)
    }

    pub fn full_blocks(&self) -> usize {
        self.full.len()
    }

    /// Tokens in the full blocks (the cached part of the transcript).
    pub fn full_block_tokens(&self) -> u32 {
        self.full.iter().map(|b| b.tokens).sum()
    }

    /// Tokens the transcript adds to a request in `scope`.
    pub fn tokens(&self, scope: TranscriptScope) -> u32 {
        let latest: u32 = self.latest().iter().map(|t| estimate_tokens(&t.line()) + 1).sum();
        match scope {
            TranscriptScope::Recent => latest,
            TranscriptScope::Full => latest + self.full_block_tokens() + self.open.tokens,
        }
    }
}

/// A rewrite of an earlier suggestion.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RewriteKind {
    Regenerate,
    Shorter,
    Formal,
    Translate(LanguageHelp),
}

/// What the request asks for.
#[derive(Clone, Copy)]
pub enum Task<'a> {
    /// Answer Them's words: a partial (speculative start) or the final turn.
    Answer { turn_id: Option<&'a str>, text: &'a str, partial: bool },
    /// Me asked for help; `latest` is Them's last turn, when there is one.
    HelpNow { latest: Option<&'a str> },
    Rewrite { kind: RewriteKind, question: &'a str, previous: &'a str },
}

impl fmt::Debug for Task<'_> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Task::Answer { partial, .. } => f.debug_struct("Answer").field("partial", partial).finish(),
            Task::HelpNow { latest } => f.debug_struct("HelpNow").field("has_latest", &latest.is_some()).finish(),
            Task::Rewrite { kind, .. } => f.debug_struct("Rewrite").field("kind", kind).finish(),
        }
    }
}

#[derive(Debug, Clone, Copy)]
pub struct ContextBuilder<'a> {
    pub pack: &'a Pack,
    pub config: &'a ContextConfig,
}

impl ContextBuilder<'_> {
    pub fn build(&self, call: &CallContext, transcript: &Transcript, task: Task<'_>, scope: TranscriptScope) -> Context {
        let template = self.pack.template(call.brief.meeting_type);
        let piece = |i: usize| template.pieces[i].as_str();
        let block = |i: usize, value: &str| format!("{}{}", piece(i), or_none(value));
        let mut segments = vec![
            Segment::new(SegmentKind::Rules, Role::System, format!("{}{}", piece(0), self.pack.system_rules())).cached(),
            Segment::new(SegmentKind::Brief, Role::System, block(1, &brief(&call.brief))),
            Segment::new(SegmentKind::Documents, Role::System, block(2, &documents(&call.documents))),
            Segment::new(SegmentKind::Memory, Role::System, block(3, &call.memory)).cached(),
            Segment::new(SegmentKind::Facts, Role::User, block(4, &facts(&call.facts))),
            Segment::new(SegmentKind::Summary, Role::User, block(5, &call.summary)),
        ];
        let mut blocks: Vec<(String, bool)> = Vec::new();
        if scope == TranscriptScope::Full {
            for (index, full) in transcript.full.iter().enumerate() {
                blocks.push((format!("Part {}\n{}", index + 1, full.text), true));
            }
            if !transcript.open.text.is_empty() {
                blocks.push((format!("Part {}\n{}", transcript.full.len() + 1, transcript.open.text), false));
            }
        }
        if blocks.is_empty() {
            let older = transcript.in_blocks > 0;
            let value = if older && scope == TranscriptScope::Recent { asks::OLDER_LEFT_OUT } else { NONE };
            segments.push(Segment::new(SegmentKind::TranscriptBlock, Role::User, format!("{}{value}", piece(6))));
        } else {
            for (index, (text, full)) in blocks.into_iter().enumerate() {
                let text = if index == 0 { format!("{}{text}", piece(6)) } else { format!("\n\n{text}") };
                let mut segment = Segment::new(SegmentKind::TranscriptBlock, Role::User, text);
                segment.cache_after = full;
                segments.push(segment);
            }
            // Only the last full block is a cache point (the earlier ones are inside its prefix).
            let last_full = segments.iter().rposition(|s| s.kind == SegmentKind::TranscriptBlock && s.cache_after);
            for (index, segment) in segments.iter_mut().enumerate() {
                if segment.kind == SegmentKind::TranscriptBlock && Some(index) != last_full {
                    segment.cache_after = false;
                }
            }
        }
        let skip_turn = match task {
            Task::Answer { turn_id, partial: false, .. } => turn_id,
            _ => None,
        };
        let latest: Vec<String> =
            transcript.latest().iter().filter(|t| Some(t.turn_id.as_str()) != skip_turn).map(TranscriptTurn::line).collect();
        segments.push(Segment::new(SegmentKind::LastTurns, Role::User, block(7, &latest.join("\n"))));
        let hint = call.hint_level.unwrap_or(template.default_hint_level);
        let now = format!("{}{}{}{}{}", piece(8), hint_name(hint), piece(9), language_name(call.language_help), piece(10));
        segments.push(Segment::new(SegmentKind::Now, Role::User, now));
        segments.push(Segment::new(SegmentKind::Question, Role::User, format!("{}{}", question(task), piece(11))));
        Context { segments }
    }
}

fn or_none(value: &str) -> &str {
    let value = value.trim();
    if value.is_empty() { NONE } else { value }
}

fn hint_name(hint: HintLevel) -> &'static str {
    match hint {
        HintLevel::Keywords => "keywords",
        HintLevel::Outline => "outline",
        HintLevel::Full => "full",
    }
}

fn language_name(language: LanguageHelp) -> &'static str {
    match language {
        LanguageHelp::Off => "off",
        LanguageHelp::Urdu => "urdu",
        LanguageHelp::RomanUrdu => "roman_urdu",
        LanguageHelp::Arabic => "arabic",
    }
}

/// The question block: Them's words, and for help now and the rewrites what Me asked for.
fn question(task: Task<'_>) -> String {
    match task {
        Task::Answer { text, partial: false, .. } => text.trim().to_owned(),
        Task::Answer { text, partial: true, .. } => format!("{}\n{}", text.trim(), asks::PARTIAL),
        Task::HelpNow { latest: Some(latest) } if !latest.trim().is_empty() => format!("{}\n\n{}", latest.trim(), asks::HELP_NOW),
        Task::HelpNow { .. } => asks::HELP_NOW_OPENING.to_owned(),
        Task::Rewrite { kind, question, previous } => {
            let ask = match kind {
                RewriteKind::Regenerate => asks::REGENERATE.to_owned(),
                RewriteKind::Shorter => asks::SHORTER.to_owned(),
                RewriteKind::Formal => asks::FORMAL.to_owned(),
                RewriteKind::Translate(language) => asks::TRANSLATE.replace("{language}", language.label()),
            };
            let words = if question.trim().is_empty() { "(Me asked for help.)" } else { question.trim() };
            format!("{words}\n\nThe previous suggestion:\n{}\n\n{ask}", previous.trim())
        }
    }
}

fn brief(brief: &Brief) -> String {
    let with = match (brief.counterpart.trim(), brief.company.trim()) {
        ("", "") => String::new(),
        (person, "") => person.to_owned(),
        ("", company) => company.to_owned(),
        (person, company) => format!("{person} ({company})"),
    };
    let mut lines = Vec::new();
    for (label, value) in [
        ("Title", brief.title.trim()),
        ("With", with.as_str()),
        ("Goals", brief.goals.trim()),
        ("Done", brief.done.trim()),
        ("Not done", brief.not_done.trim()),
        ("To discuss", brief.to_discuss.trim()),
        ("Me's instructions", brief.instructions.trim()),
    ] {
        if !value.is_empty() {
            lines.push(format!("{label}: {value}"));
        }
    }
    lines.join("\n")
}

fn documents(documents: &[Document]) -> String {
    let mut text = String::new();
    for document in documents.iter().filter(|d| !d.text.trim().is_empty()) {
        if !text.is_empty() {
            text.push_str("\n\n");
        }
        let title = if document.title.trim().is_empty() { document.id.as_str() } else { document.title.trim() };
        let _ = write!(text, "[{title}]\n{}", document.text.trim());
    }
    text
}

fn facts(facts: &[Fact]) -> String {
    facts.iter().filter(|f| !f.text.trim().is_empty()).map(|f| format!("- {}", f.text.trim())).collect::<Vec<_>>().join("\n")
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::RelayConfig;

    fn turn(id: usize, channel: Channel, words: usize) -> TranscriptTurn {
        TranscriptTurn {
            turn_id: format!("t{id}"),
            channel,
            text: format!("turn {id} {}", "word ".repeat(words)).trim().to_owned(),
            start_ms: Some(id as u64 * 7_000),
        }
    }

    #[test]
    fn full_blocks_never_change_as_the_call_goes_on() {
        let config = RelayConfig::built_in().context;
        let mut transcript = Transcript::new(&config);
        for id in 0..40 {
            transcript.push(turn(id, if id % 2 == 0 { Channel::Other } else { Channel::Me }, 60));
        }
        let first = transcript.full.iter().map(|b| b.text.clone()).collect::<Vec<_>>();
        assert!(!first.is_empty());
        for id in 40..80 {
            transcript.push(turn(id, Channel::Other, 60));
        }
        assert!(transcript.full.len() > first.len());
        for (before, after) in first.iter().zip(&transcript.full) {
            assert_eq!(before, &after.text);
        }
        assert!(transcript.full.iter().all(|b| b.tokens >= config.block_tokens));
        assert_eq!(transcript.latest().len(), config.recent_turns);
    }

    #[test]
    fn breakpoints_stay_within_the_providers_limit() {
        let config = RelayConfig::built_in().context;
        let pack = Pack::built_in();
        let mut transcript = Transcript::new(&config);
        for id in 0..200 {
            transcript.push(turn(id, Channel::Other, 40));
        }
        let builder = ContextBuilder { pack: &pack, config: &config };
        let context = builder.build(&CallContext::default(), &transcript, Task::HelpNow { latest: None }, TranscriptScope::Full);
        assert_eq!(context.breakpoints(), 3);
        assert!(context.cacheable_tokens() < context.tokens());
    }

    #[test]
    fn every_placeholder_is_filled_and_empty_blocks_say_none() {
        let config = RelayConfig::built_in().context;
        let pack = Pack::built_in();
        let builder = ContextBuilder { pack: &pack, config: &config };
        let context = builder.build(
            &CallContext::default(),
            &Transcript::new(&config),
            Task::Answer { turn_id: Some("o-1"), text: "What is your notice period?", partial: false },
            TranscriptScope::Full,
        );
        let text = context.text();
        assert!(!text.contains("{{"), "a placeholder was left");
        assert!(text.contains("## The brief\n"));
        assert!(text.contains("Hint level: full\nLanguage help: off"));
        assert!(text.ends_with("What is your notice period?\n"));
        assert!(text.matches(NONE).count() >= 7);
    }
}
