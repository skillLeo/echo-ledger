//! The caption latency probe (development and tests only): how long from a chunk of speech sent to the provider until
//! its first word comes back, and from there until the captions window has painted it. Timings only: no text, no audio,
//! no names ever enter it. It is on only in a debug build started with `ECHOLEDGER_LATENCY_PROBE=1`, so a release build
//! never measures anything, and its summary goes to the debug log.
//!
//! One [`Probe`] per provider socket: [`Probe::sent`] for each frame sent, [`Probe::word`] when a caption with words
//! arrives (the first after speech that had none yet), [`painted`] when the window says it has drawn it.

use std::collections::BTreeMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex, MutexGuard, OnceLock, Weak};
use std::time::Instant;

/// The switch: a debug build started with `ECHOLEDGER_LATENCY_PROBE` set.
pub const ENV: &str = "ECHOLEDGER_LATENCY_PROBE";

/// Whether the probe runs in this process (never in a release build).
pub fn enabled() -> bool {
    cfg!(debug_assertions) && std::env::var_os(ENV).is_some()
}

/// Ids of words waiting to be painted, across every socket.
static NEXT_ID: AtomicU64 = AtomicU64::new(1);

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

/// One measured word.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Sample {
    /// From the first speech frame sent (since the last word) to the word received.
    pub sent_to_word_ms: u64,
    /// From the word received to the window's paint, when it said so.
    pub word_to_painted_ms: Option<u64>,
}

impl Sample {
    /// Speech sent to word on screen; none until painted.
    pub fn sent_to_painted_ms(&self) -> Option<u64> {
        self.word_to_painted_ms.map(|p| self.sent_to_word_ms + p)
    }
}

#[derive(Debug, Default)]
struct State {
    /// The first speech frame sent since the last word.
    speech_since: Option<Instant>,
    /// The word just received, not yet handed to the window.
    unreported: Option<u64>,
    /// Words handed to the window, by id: when received, and the sample's index.
    waiting: BTreeMap<u64, (Instant, usize)>,
    samples: Vec<Sample>,
}

/// The probe of one provider socket.
#[derive(Debug, Default)]
pub struct Probe {
    state: Mutex<State>,
}

/// Every live probe, so a paint (which knows only the word's id) finds its own.
fn registry() -> &'static Mutex<Vec<Weak<Probe>>> {
    static PROBES: OnceLock<Mutex<Vec<Weak<Probe>>>> = OnceLock::new();
    PROBES.get_or_init(Default::default)
}

impl Probe {
    /// A probe for a new socket, findable by [`painted`].
    pub fn new_registered() -> Arc<Self> {
        let probe = Arc::new(Self::default());
        let mut all = lock(registry());
        all.retain(|p| p.strong_count() > 0);
        all.push(Arc::downgrade(&probe));
        probe
    }

    /// A frame went to the provider at `at`; `speech`: it carries someone speaking.
    pub fn sent(&self, speech: bool, at: Instant) {
        let mut s = lock(&self.state);
        if speech && s.speech_since.is_none() {
            s.speech_since = Some(at);
        }
    }

    /// A caption with words arrived at `at`. The first one after speech is measured; its id waits for
    /// [`Probe::take_unreported`].
    pub fn word(&self, at: Instant) -> Option<u64> {
        let mut s = lock(&self.state);
        let since = s.speech_since.take()?;
        let id = NEXT_ID.fetch_add(1, Ordering::Relaxed);
        let index = s.samples.len();
        s.samples.push(Sample { sent_to_word_ms: at.saturating_duration_since(since).as_millis() as u64, word_to_painted_ms: None });
        s.waiting.insert(id, (at, index));
        // Words never painted (the window closed) are not kept forever.
        while s.waiting.len() > 256 {
            s.waiting.pop_first();
        }
        s.unreported = Some(id);
        Some(id)
    }

    /// The word just measured, to hand to the window with its caption (once).
    pub fn take_unreported(&self) -> Option<u64> {
        lock(&self.state).unreported.take()
    }

    /// The window painted word `id` at `at`; false when it is not this probe's.
    pub fn painted_at(&self, id: u64, at: Instant) -> bool {
        let mut s = lock(&self.state);
        let Some((received, index)) = s.waiting.remove(&id) else { return false };
        if let Some(sample) = s.samples.get_mut(index) {
            sample.word_to_painted_ms = Some(at.saturating_duration_since(received).as_millis() as u64);
        }
        true
    }

    pub fn samples(&self) -> Vec<Sample> {
        lock(&self.state).samples.clone()
    }

    pub fn summary(&self) -> Summary {
        Summary::of(&self.samples())
    }
}

/// The window painted word `id` now.
pub fn painted(id: u64) {
    let now = Instant::now();
    let all: Vec<Arc<Probe>> = lock(registry()).iter().filter_map(Weak::upgrade).collect();
    for probe in all {
        if probe.painted_at(id, now) {
            return;
        }
    }
}

/// The `p`-th percentile (0 to 100) of `values`, nearest rank (pure); none for no values.
pub fn percentile(values: &[u64], p: f64) -> Option<u64> {
    if values.is_empty() {
        return None;
    }
    let mut sorted = values.to_vec();
    sorted.sort_unstable();
    let rank = ((p / 100.0) * sorted.len() as f64).ceil().max(1.0) as usize;
    sorted.get(rank.min(sorted.len()) - 1).copied()
}

/// p50 and p90 of one stage, in ms.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Spread {
    pub count: usize,
    pub p50: Option<u64>,
    pub p90: Option<u64>,
}

impl Spread {
    pub fn of(values: &[u64]) -> Self {
        Self { count: values.len(), p50: percentile(values, 50.0), p90: percentile(values, 90.0) }
    }
}

/// The probe's numbers: each stage and the whole way.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Summary {
    pub sent_to_word: Spread,
    pub word_to_painted: Spread,
    pub sent_to_painted: Spread,
}

impl Summary {
    pub fn of(samples: &[Sample]) -> Self {
        let words: Vec<u64> = samples.iter().map(|s| s.sent_to_word_ms).collect();
        let paints: Vec<u64> = samples.iter().filter_map(|s| s.word_to_painted_ms).collect();
        let whole: Vec<u64> = samples.iter().filter_map(Sample::sent_to_painted_ms).collect();
        Self { sent_to_word: Spread::of(&words), word_to_painted: Spread::of(&paints), sent_to_painted: Spread::of(&whole) }
    }

    /// One line for the debug log: numbers only.
    pub fn line(&self) -> String {
        let ms = |v: Option<u64>| v.map_or_else(|| "-".to_string(), |v| format!("{v} ms"));
        format!(
            "caption latency: speech sent to word p50 {} p90 {} ({} words); word to painted p50 {} p90 {}; speech to word on screen p50 {} p90 {}",
            ms(self.sent_to_word.p50),
            ms(self.sent_to_word.p90),
            self.sent_to_word.count,
            ms(self.word_to_painted.p50),
            ms(self.word_to_painted.p90),
            ms(self.sent_to_painted.p50),
            ms(self.sent_to_painted.p90),
        )
    }
}

/// How often a running probe writes its line to the debug log.
pub const LOG_EVERY_WORDS: usize = 20;

#[cfg(test)]
mod tests {
    use super::*;
    use std::time::Duration;

    #[test]
    fn it_is_off_unless_a_debug_build_asks_for_it() {
        // The switch is read from the environment, and a release build never measures.
        let source = include_str!("latency.rs");
        assert!(source.contains("cfg!(debug_assertions) && std::env::var_os(ENV).is_some()"));
        assert_eq!(ENV, "ECHOLEDGER_LATENCY_PROBE");
    }

    #[test]
    fn it_measures_the_first_word_after_speech_and_its_paint() {
        let probe = Probe::new_registered();
        let t = Instant::now();
        probe.sent(false, t);
        assert_eq!(probe.word(t + Duration::from_millis(10)), None, "no speech sent: nothing to measure");
        probe.sent(true, t + Duration::from_millis(100));
        probe.sent(true, t + Duration::from_millis(150));
        let id = probe.word(t + Duration::from_millis(450)).expect("measured");
        assert_eq!(probe.word(t + Duration::from_millis(500)), None, "the next caption of the same speech is not a new first word");
        assert_eq!(probe.take_unreported(), Some(id));
        assert_eq!(probe.take_unreported(), None, "handed to the window once");
        assert!(probe.painted_at(id, t + Duration::from_millis(470)));
        assert!(!probe.painted_at(id, t + Duration::from_millis(480)), "painted once");
        assert_eq!(probe.samples(), [Sample { sent_to_word_ms: 350, word_to_painted_ms: Some(20) }]);
        assert_eq!(probe.samples()[0].sent_to_painted_ms(), Some(370));
        // A paint finds its probe by id alone.
        probe.sent(true, t + Duration::from_millis(1000));
        let next = probe.word(t + Duration::from_millis(1300)).unwrap();
        painted(next);
        assert!(probe.samples()[1].word_to_painted_ms.is_some());
    }

    #[test]
    fn percentiles_are_nearest_rank() {
        let values: Vec<u64> = (1..=10).map(|v| v * 100).collect();
        assert_eq!(percentile(&values, 50.0), Some(500));
        assert_eq!(percentile(&values, 90.0), Some(900));
        assert_eq!(percentile(&[42], 90.0), Some(42));
        assert_eq!(percentile(&[], 50.0), None);
        let summary = Summary::of(&[Sample { sent_to_word_ms: 300, word_to_painted_ms: Some(16) }, Sample { sent_to_word_ms: 500, word_to_painted_ms: None }]);
        assert_eq!(summary.sent_to_word, Spread { count: 2, p50: Some(300), p90: Some(500) });
        assert_eq!(summary.sent_to_painted, Spread { count: 1, p50: Some(316), p90: Some(316) });
        let line = summary.line();
        assert!(line.starts_with("caption latency: speech sent to word p50 300 ms p90 500 ms (2 words)"), "{line}");
    }
}
