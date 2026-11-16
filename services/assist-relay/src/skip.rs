//! SKIP: the model answers exactly `SKIP` when no reply is needed. Tokens are held back only while the answer so far
//! could still become `SKIP` (one or two tokens), so the card never flashes a "S" and real answers are not delayed.

/// Whether `text` could still turn out to be `SKIP` (or `SKIP.`), ignoring case and surrounding whitespace.
pub fn could_be_skip(text: &str) -> bool {
    let trimmed = text.trim();
    "SKIP.".get(..trimmed.len()).is_some_and(|prefix| prefix.eq_ignore_ascii_case(trimmed))
}

/// Whether the whole answer is `SKIP`.
pub fn is_skip(text: &str) -> bool {
    let trimmed = text.trim();
    trimmed.eq_ignore_ascii_case("SKIP") || trimmed.eq_ignore_ascii_case("SKIP.")
}

#[derive(Debug, Default)]
pub struct SkipGate {
    held: String,
    open: bool,
}

#[derive(Debug, PartialEq, Eq)]
pub enum GateEnd {
    /// The whole answer was SKIP: send `skip`, no tokens.
    Skip,
    /// Send this last held text (may be empty), then `suggestion_end`.
    Flush(String),
}

impl SkipGate {
    /// Takes the next chunk; returns what may be sent now.
    pub fn feed(&mut self, chunk: &str) -> Option<String> {
        if self.open {
            return Some(chunk.to_owned());
        }
        self.held.push_str(chunk);
        if could_be_skip(&self.held) {
            return None;
        }
        self.open = true;
        Some(std::mem::take(&mut self.held))
    }

    /// At the end of the answer.
    pub fn finish(&mut self) -> GateEnd {
        if self.open {
            return GateEnd::Flush(String::new());
        }
        if is_skip(&self.held) {
            return GateEnd::Skip;
        }
        self.open = true;
        GateEnd::Flush(std::mem::take(&mut self.held))
    }

    /// Something was sent to the app already.
    pub fn is_open(&self) -> bool {
        self.open
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn skip_in_any_split_sends_nothing() {
        for chunks in [vec!["SKIP"], vec!["S", "KIP"], vec!["Sk", "ip", "."], vec!["\n", "SKIP", "\n"]] {
            let mut gate = SkipGate::default();
            assert!(chunks.iter().all(|chunk| gate.feed(chunk).is_none()), "{chunks:?}");
            assert_eq!(gate.finish(), GateEnd::Skip, "{chunks:?}");
        }
    }

    #[test]
    fn a_real_answer_is_released_at_its_first_differing_token() {
        let mut gate = SkipGate::default();
        assert_eq!(gate.feed("S"), None);
        assert_eq!(gate.feed("ure, "), Some("Sure, ".to_owned()));
        assert_eq!(gate.feed("I can."), Some("I can.".to_owned()));
        assert_eq!(gate.finish(), GateEnd::Flush(String::new()));
        let mut gate = SkipGate::default();
        assert_eq!(gate.feed("Yes"), Some("Yes".to_owned()));
    }

    #[test]
    fn an_answer_that_only_starts_like_skip_is_flushed_at_the_end() {
        let mut gate = SkipGate::default();
        assert_eq!(gate.feed("Sk"), None);
        assert_eq!(gate.finish(), GateEnd::Flush("Sk".to_owned()));
        assert!(!is_skip("SKIP it"));
        assert!(!could_be_skip("SKIPPED"));
    }
}
