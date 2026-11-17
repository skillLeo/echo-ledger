//! Deterministic mocked models: fixed answers streamed word by word at each model's latency from the settings, SKIP for
//! words that are not a question, and a cache that behaves like the providers' (a prefix written at a cache point is
//! read back by the next request with the same prefix). Nothing leaves the process.

use std::collections::hash_map::DefaultHasher;
use std::collections::{BTreeMap, HashSet, VecDeque};
use std::hash::{Hash, Hasher};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use futures_util::future::BoxFuture;
use futures_util::{FutureExt, StreamExt, stream};

use super::{CompletionRequest, Provider, ProviderError, Purpose, StreamEvent, TokenStream, Usage};
use crate::config::{MockLatency, RelayConfig};
use crate::context::Context;
use crate::domain::ProviderKind;
use crate::text::{looks_like_question, normalize, words};

/// Prefixes shorter than this are not cached (the providers' minimum).
pub const MIN_CACHE_TOKENS: u32 = 512;

/// Counts shared by every mock of a relay.
#[derive(Debug, Default)]
pub struct MockStats {
    pub requests: AtomicU64,
    pub completed: AtomicU64,
    /// Streams dropped before their end: the relay cancelled them.
    pub cancelled: AtomicU64,
    pub warmed: AtomicU64,
}

impl MockStats {
    pub fn get(counter: &AtomicU64) -> u64 {
        counter.load(Ordering::SeqCst)
    }
}

/// A scripted answer for the next request (tests).
#[derive(Debug, Clone)]
pub enum MockBehavior {
    /// Fail before the first token.
    Fail(ProviderError),
    /// Never send a token, so the relay's first-token timeout fires.
    Stall,
    /// Stream this text instead of the usual answer.
    Reply(String),
    /// The usual answer, starting this much later than the model's latency.
    Late(Duration),
    /// The usual answer's first this-many words, then a dropped connection.
    Break(usize),
    /// The usual answer, complete, but no token counts (some providers send none).
    NoUsage,
    /// The stream panics when it is first read (a bug in a provider client).
    Panic,
}

#[derive(Debug)]
pub struct MockProvider {
    kind: ProviderKind,
    latency: BTreeMap<String, MockLatency>,
    cache: Mutex<HashSet<(String, u64)>>,
    script: Mutex<VecDeque<MockBehavior>>,
    requests: Mutex<Vec<(String, Purpose)>>,
    stats: Arc<MockStats>,
}

const DEFAULT_LATENCY: MockLatency = MockLatency { first_token_ms: 300, token_ms: 10 };

impl MockProvider {
    pub fn new(kind: ProviderKind, config: &RelayConfig, stats: Arc<MockStats>) -> Self {
        let latency = config.models.iter().filter(|(_, spec)| spec.provider == kind).map(|(id, spec)| (id.clone(), spec.mock)).collect();
        MockProvider {
            kind,
            latency,
            cache: Mutex::new(HashSet::new()),
            script: Mutex::new(VecDeque::new()),
            requests: Mutex::new(Vec::new()),
            stats,
        }
    }

    /// Queues a behaviour for the next request to this provider.
    pub fn push(&self, behavior: MockBehavior) {
        if let Ok(mut script) = self.script.lock() {
            script.push_back(behavior);
        }
    }

    /// Every request so far: the model and what it was for.
    pub fn requests(&self) -> Vec<(String, Purpose)> {
        self.requests.lock().map(|r| r.clone()).unwrap_or_default()
    }

    fn latency_for(&self, model: &str) -> MockLatency {
        self.latency.get(model).copied().unwrap_or(DEFAULT_LATENCY)
    }

    /// Cache accounting: the longest prefix at a cache point seen before is read; up to the last cache point is
    /// written; the rest is plain input.
    fn account(&self, model: &str, context: &Context) -> Usage {
        let mut hasher = DefaultHasher::new();
        let mut tokens = 0u32;
        let mut points = Vec::new();
        for segment in &context.segments {
            segment.text.hash(&mut hasher);
            tokens += segment.tokens;
            if segment.cache_after && tokens >= MIN_CACHE_TOKENS {
                points.push((tokens, hasher.finish()));
            }
        }
        let Ok(mut cache) = self.cache.lock() else {
            return Usage { input_tokens: tokens, ..Usage::default() };
        };
        let hit = points.iter().filter(|(_, hash)| cache.contains(&(model.to_owned(), *hash))).map(|(t, _)| *t).max().unwrap_or(0);
        let last = points.last().map_or(0, |(t, _)| *t);
        for (_, hash) in points {
            cache.insert((model.to_owned(), hash));
        }
        let written = last.saturating_sub(hit);
        Usage { input_tokens: tokens - hit - written, cached_input_tokens: hit, cache_write_tokens: written, output_tokens: 0 }
    }
}

/// The mock's practice questions, in order.
pub const PRACTICE: [&str; 5] = [
    "Tell me about yourself and your current role?",
    "What is a project you are proud of, and what was your part in it?",
    "How do you handle a deadline you cannot meet?",
    "Why are you interested in this role?",
    "What would you do in your first month here?",
];

/// The mock's answer: SKIP for words that are not a question, otherwise a short answer in the output contract's shape.
pub fn mock_reply(purpose: Purpose, question: &str) -> String {
    let normalized = normalize(question);
    let topic = words(&normalized).into_iter().take(4).collect::<Vec<_>>().join(" ");
    let topic = if topic.is_empty() { "this".to_owned() } else { topic };
    match purpose {
        Purpose::Answer if !looks_like_question(question) => "SKIP".to_owned(),
        Purpose::Answer | Purpose::HelpNow => format!(
            "Yes, I can answer that about {topic}, and keep it short.\n- **Experience**: two projects like this one\n- **Result**: delivered on time\nSay next: Would an example help?"
        ),
        Purpose::Regenerate => {
            format!("Here is another way to answer {topic}.\n- **Example**: the last project\n- **Lesson**: plan early")
        }
        Purpose::Shorter => "Yes, I can do that.".to_owned(),
        Purpose::Formal => "Certainly, I would be glad to explain.\n- **Experience**: two similar projects".to_owned(),
        Purpose::Translate => "اردو: وہ آپ کے تجربے کے بارے میں پوچھ رہے ہیں۔\nSay: I have two years of experience.".to_owned(),
        Purpose::Warm => String::new(),
        // `question` is the question's number.
        Purpose::PracticeQuestion => {
            let index: usize = question.trim().parse().unwrap_or(1);
            PRACTICE[(index.max(1) - 1) % PRACTICE.len()].to_owned()
        }
        // `question` is the new lines of the call: each line with a number becomes a fact.
        Purpose::Facts => {
            let facts: Vec<String> =
                question.lines().filter(|line| line.chars().any(|c| c.is_ascii_digit())).map(|line| line.trim().to_owned()).collect();
            if facts.is_empty() { "NONE".to_owned() } else { facts.join("\n") }
        }
        Purpose::Summary => format!("The call so far: {} new lines, starting with {}.", question.lines().count(), topic),
    }
}

/// Counts a stream as completed or cancelled when it is dropped.
struct CancelGuard {
    stats: Arc<MockStats>,
    finished: bool,
}

impl Drop for CancelGuard {
    fn drop(&mut self) {
        let counter = if self.finished { &self.stats.completed } else { &self.stats.cancelled };
        counter.fetch_add(1, Ordering::SeqCst);
    }
}

struct Streaming {
    chunks: VecDeque<String>,
    first: Duration,
    next: Duration,
    started: bool,
    usage: Option<Usage>,
    guard: CancelGuard,
}

impl Provider for MockProvider {
    fn kind(&self) -> ProviderKind {
        self.kind
    }

    fn stream(&self, request: CompletionRequest) -> TokenStream {
        self.stats.requests.fetch_add(1, Ordering::SeqCst);
        if let Ok(mut requests) = self.requests.lock() {
            requests.push((request.model.clone(), request.purpose));
        }
        let latency = self.latency_for(&request.model);
        let behavior = self.script.lock().ok().and_then(|mut script| script.pop_front());
        let mut first = Duration::from_millis(latency.first_token_ms);
        let text = match behavior {
            Some(MockBehavior::Fail(error)) => {
                let delay = first / 4;
                return stream::once(async move {
                    tokio::time::sleep(delay).await;
                    Err(error)
                })
                .boxed();
            }
            Some(MockBehavior::Stall) => {
                let guard = CancelGuard { stats: self.stats.clone(), finished: false };
                return stream::once(async move {
                    let _guard = guard;
                    std::future::pending::<()>().await;
                    Err(ProviderError::Timeout)
                })
                .boxed();
            }
            Some(MockBehavior::Reply(text)) => text,
            Some(MockBehavior::Late(extra)) => {
                first += extra;
                mock_reply(request.purpose, &request.question)
            }
            Some(MockBehavior::Break(words)) => {
                let next = Duration::from_millis(latency.token_ms);
                let chunks: Vec<String> =
                    mock_reply(request.purpose, &request.question).split_inclusive(char::is_whitespace).take(words).map(str::to_owned).collect();
                let guard = CancelGuard { stats: self.stats.clone(), finished: false };
                let texts = stream::iter(chunks.into_iter().enumerate()).then(move |(i, chunk)| async move {
                    tokio::time::sleep(if i == 0 { first } else { next }).await;
                    Ok(StreamEvent::Text(chunk))
                });
                let broken = stream::once(async move {
                    let _guard = guard;
                    Err(ProviderError::Unavailable)
                });
                return texts.chain(broken).boxed();
            }
            Some(MockBehavior::Panic) => {
                return stream::poll_fn(|_| -> std::task::Poll<Option<Result<StreamEvent, ProviderError>>> {
                    panic!("the mock provider panics, as asked")
                })
                .boxed();
            }
            Some(MockBehavior::NoUsage) => {
                let next = Duration::from_millis(latency.token_ms);
                let chunks: Vec<String> =
                    mock_reply(request.purpose, &request.question).split_inclusive(char::is_whitespace).map(str::to_owned).collect();
                // Counted as completed when the stream is dropped: it ends normally, only without counts.
                let guard = Arc::new(CancelGuard { stats: self.stats.clone(), finished: true });
                return stream::iter(chunks.into_iter().enumerate())
                    .then(move |(i, chunk)| {
                        let _held = guard.clone();
                        async move {
                            tokio::time::sleep(if i == 0 { first } else { next }).await;
                            Ok(StreamEvent::Text(chunk))
                        }
                    })
                    .boxed();
            }
            None => mock_reply(request.purpose, &request.question),
        };
        let chunks: VecDeque<String> = text.split_inclusive(char::is_whitespace).map(str::to_owned).collect();
        let mut usage = self.account(&request.model, &request.context);
        usage.output_tokens = u32::try_from(chunks.len()).unwrap_or(u32::MAX);
        let state = Streaming {
            chunks,
            first,
            next: Duration::from_millis(latency.token_ms),
            started: false,
            usage: Some(usage),
            guard: CancelGuard { stats: self.stats.clone(), finished: false },
        };
        stream::unfold(state, |mut state| async move {
            if let Some(chunk) = state.chunks.pop_front() {
                tokio::time::sleep(if state.started { state.next } else { state.first }).await;
                state.started = true;
                return Some((Ok(StreamEvent::Text(chunk)), state));
            }
            let usage = state.usage.take()?;
            state.guard.finished = true;
            Some((Ok(StreamEvent::Usage(usage)), state))
        })
        .boxed()
    }

    fn warm(&self, request: CompletionRequest) -> BoxFuture<'static, Result<Usage, ProviderError>> {
        self.stats.warmed.fetch_add(1, Ordering::SeqCst);
        let mut usage = self.account(&request.model, &request.context);
        // A warm-up sends the cached prefix and a one-line placeholder, and asks for no output.
        usage.input_tokens = 8;
        let delay = Duration::from_millis(self.latency_for(&request.model).first_token_ms / 2);
        async move {
            tokio::time::sleep(delay).await;
            Ok(usage)
        }
        .boxed()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn statements_get_skip_and_questions_get_the_output_contract() {
        assert_eq!(mock_reply(Purpose::Answer, "We will email you tomorrow"), "SKIP");
        let answer = mock_reply(Purpose::Answer, "What is your notice period?");
        let first_line = answer.lines().next().unwrap();
        assert!(first_line.split_whitespace().count() <= 25);
        assert!(answer.lines().filter(|l| l.starts_with("- ")).count() <= 3);
        assert!(answer.contains("**"));
        assert_eq!(mock_reply(Purpose::HelpNow, ""), mock_reply(Purpose::HelpNow, "this"));
    }
}
