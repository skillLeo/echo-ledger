//! Building what a model reads, in the order that keeps the longest prefix cached:
//!
//! 1. the rules, the rules for this call, the brief, the documents, the project memory (cache point),
//!    then the facts ledger and the running summary;
//! 2. the older transcript in blocks of about 1,500 tokens (cache point after the last full block);
//! 3. the latest turns word for word, then the question and the task.
//!
//! Everything above the question changes rarely, and a full transcript block never changes again, so each request
//! re-reads most of its input from the provider's cache. Fast mode leaves the older blocks out; every mode gets the
//! facts and the summary.

use std::fmt;
use std::fmt::Write as _;

use crate::config::{ContextConfig, TranscriptScope};
use crate::domain::{Channel, HintLevel, LanguageHelp};
use crate::instructions::InstructionPack;
use crate::protocol::{Document, Fact, Prep};
use crate::text::estimate_tokens;

/// Where a segment goes in a provider request: the fixed system part, or the user message.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Role {
    System,
    User,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SegmentKind {
    Rules,
    CallRules,
    Brief,
    Documents,
    Memory,
    Facts,
    Summary,
    TranscriptBlock,
    LatestTurns,
    Question,
}

impl SegmentKind {
    pub fn as_str(self) -> &'static str {
        match self {
            SegmentKind::Rules => "rules",
            SegmentKind::CallRules => "call rules",
            SegmentKind::Brief => "brief",
            SegmentKind::Documents => "documents",
            SegmentKind::Memory => "memory",
            SegmentKind::Facts => "facts",
            SegmentKind::Summary => "summary",
            SegmentKind::TranscriptBlock => "transcript block",
            SegmentKind::LatestTurns => "latest turns",
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

    /// A plain-text picture of the request, for the snapshot tests and nothing else (never logged).
    pub fn render(&self) -> String {
        let mut out = String::new();
        for segment in &self.segments {
            let role = match segment.role {
                Role::System => "system",
                Role::User => "user",
            };
            let cache = if segment.cache_after { " | cache" } else { "" };
            let _ = writeln!(out, "=== {role} | {} | ~{} tokens{cache} ===", segment.kind.as_str(), segment.tokens);
            out.push_str(&segment.text);
            out.push('\n');
        }
        out
    }
}

/// The call as the app described it in `context_update`.
#[derive(Debug, Clone, Default)]
pub struct CallContext {
    pub prep: Prep,
    pub hint_level: HintLevel,
    pub language_help: LanguageHelp,
    pub documents: Vec<Document>,
    pub memory: String,
    pub facts: Vec<Fact>,
    pub summary: String,
}

impl CallContext {
    pub fn document_tokens(&self) -> u32 {
        self.documents.iter().map(|d| estimate_tokens(&d.text)).sum()
    }
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

/// The final turns of the call. Turns older than the latest few go into blocks; a block closes at about
/// `block_tokens` and never changes after that.
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
    HelpNow,
    Rewrite { kind: RewriteKind, question: &'a str, previous: &'a str },
}

impl fmt::Debug for Task<'_> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Task::Answer { partial, .. } => f.debug_struct("Answer").field("partial", partial).finish(),
            Task::HelpNow => f.write_str("HelpNow"),
            Task::Rewrite { kind, .. } => f.debug_struct("Rewrite").field("kind", kind).finish(),
        }
    }
}

#[derive(Debug, Clone, Copy)]
pub struct ContextBuilder<'a> {
    pub pack: &'a InstructionPack,
    pub config: &'a ContextConfig,
}

impl ContextBuilder<'_> {
    pub fn build(&self, call: &CallContext, transcript: &Transcript, task: Task<'_>, scope: TranscriptScope) -> Context {
        let mut segments = vec![
            Segment::new(SegmentKind::Rules, Role::System, self.pack.rules.clone()).cached(),
            Segment::new(SegmentKind::CallRules, Role::System, self.call_rules(call)),
            Segment::new(SegmentKind::Brief, Role::System, brief(&call.prep)),
            Segment::new(SegmentKind::Documents, Role::System, documents(&call.documents)),
            Segment::new(SegmentKind::Memory, Role::System, titled("Project memory", &call.memory, "none")).cached(),
            Segment::new(SegmentKind::Facts, Role::User, facts(&call.facts)),
            Segment::new(SegmentKind::Summary, Role::User, titled("Summary so far", &call.summary, "none yet")),
        ];
        if scope == TranscriptScope::Full {
            for (index, block) in transcript.full.iter().enumerate() {
                let text = format!("Transcript, part {}\n{}", index + 1, block.text);
                segments.push(Segment::new(SegmentKind::TranscriptBlock, Role::User, text));
            }
            if let Some(last_full) = segments.last_mut().filter(|s| s.kind == SegmentKind::TranscriptBlock) {
                last_full.cache_after = true;
            }
            if !transcript.open.text.is_empty() {
                let text = format!("Transcript, part {}\n{}", transcript.full.len() + 1, transcript.open.text);
                segments.push(Segment::new(SegmentKind::TranscriptBlock, Role::User, text));
            }
        }
        let skip_turn = match task {
            Task::Answer { turn_id, partial: false, .. } => turn_id,
            _ => None,
        };
        let latest: Vec<String> =
            transcript.latest().iter().filter(|t| Some(t.turn_id.as_str()) != skip_turn).map(TranscriptTurn::line).collect();
        segments.push(Segment::new(SegmentKind::LatestTurns, Role::User, titled("Latest turns", &latest.join("\n"), "none yet")));
        segments.push(Segment::new(SegmentKind::Question, Role::User, self.question(task)));
        Context { segments }
    }

    fn call_rules(&self, call: &CallContext) -> String {
        let mut text = format!("{}\n{}", self.pack.meeting(call.prep.meeting_type), self.pack.hint(call.hint_level));
        if let Some(language) = self.pack.language(call.language_help) {
            text.push('\n');
            text.push_str(language);
        }
        text
    }

    fn question(&self, task: Task<'_>) -> String {
        let lines = &self.pack.task;
        match task {
            Task::Answer { text, partial, .. } => {
                let instruction = if partial { &lines.answer_partial } else { &lines.answer };
                format!("Them (now): {text}\n\n{instruction}")
            }
            Task::HelpNow => lines.help_now.clone(),
            Task::Rewrite { kind, question, previous } => {
                let instruction = match kind {
                    RewriteKind::Regenerate => lines.regenerate.clone(),
                    RewriteKind::Shorter => lines.shorter.clone(),
                    RewriteKind::Formal => lines.formal.clone(),
                    RewriteKind::Translate(language) => lines.translate.replace("{language}", language.label()),
                };
                let asked = if question.is_empty() { String::new() } else { format!("Them (earlier): {question}\n\n") };
                format!("{asked}Your previous suggestion:\n{previous}\n\n{instruction}")
            }
        }
    }
}

fn titled(title: &str, body: &str, empty: &str) -> String {
    let body = body.trim();
    if body.is_empty() { format!("{title}: {empty}.") } else { format!("{title}\n{body}") }
}

fn brief(prep: &Prep) -> String {
    let mut text = format!("Call brief\nMeeting: {}", prep.meeting_type.as_str().replace('_', " "));
    let with = match (prep.counterpart.trim(), prep.company.trim()) {
        ("", "") => String::new(),
        (person, "") => person.to_owned(),
        ("", company) => company.to_owned(),
        (person, company) => format!("{person} ({company})"),
    };
    for (label, value) in [
        ("Title", prep.title.trim()),
        ("With", with.as_str()),
        ("Goals", prep.goals.trim()),
        ("Done", prep.done.trim()),
        ("Not done", prep.not_done.trim()),
        ("To discuss", prep.to_discuss.trim()),
        ("Instructions from Me", prep.instructions.trim()),
    ] {
        if !value.is_empty() {
            let _ = write!(text, "\n{label}: {value}");
        }
    }
    text
}

fn documents(documents: &[Document]) -> String {
    if documents.is_empty() {
        return "Documents: none.".to_owned();
    }
    let mut text = String::from("Documents");
    for document in documents {
        let title = if document.title.trim().is_empty() { document.id.as_str() } else { document.title.trim() };
        let _ = write!(text, "\n\n[{title}]\n{}", document.text.trim());
    }
    text
}

fn facts(facts: &[Fact]) -> String {
    let lines: Vec<String> = facts.iter().filter(|f| !f.text.trim().is_empty()).map(|f| format!("- {}", f.text.trim())).collect();
    titled("Facts ledger (said in this call; stay consistent with them)", &lines.join("\n"), "none yet")
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
        let pack = InstructionPack::built_in();
        let mut transcript = Transcript::new(&config);
        for id in 0..200 {
            transcript.push(turn(id, Channel::Other, 40));
        }
        let builder = ContextBuilder { pack: &pack, config: &config };
        let context = builder.build(&CallContext::default(), &transcript, Task::HelpNow, TranscriptScope::Full);
        assert_eq!(context.breakpoints(), 3);
        assert!(context.cacheable_tokens() < context.tokens());
    }
}
