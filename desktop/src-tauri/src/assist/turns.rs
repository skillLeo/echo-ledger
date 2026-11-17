//! The live captions' turns as the relay's `turn_partial` and `turn_final` (docs/API_ASSIST.md 1.9.0-e). The other
//! person (the system sound, `other`) is what suggestions answer: their partials and finals go out. The person using
//! EchoLedger (the microphone, `me`) never triggers a suggestion: only their finished turns go out, as context, never
//! their partials.
//!
//! Partials and the final of one turn share `turn_id` (`o-1`, `o-2`… and `m-1`…); a final closes the turn. The speech
//! engines say nothing about silence, so a partial's `silence_ms` is an approximation: how long its words have not
//! changed (0 when they just did, more when the same words come again). It grows only when a partial repeats, which
//! the engines do every few hundred milliseconds while someone pauses; a real silence measure from the audio can
//! replace it later.

use std::time::Instant;

use super::protocol::{Channel, ClientMessage, Turn};
use crate::audio::types::Channel as AudioChannel;

#[derive(Default)]
struct Open {
    number: u32,
    /// The words of the turn's last partial, and when they last changed.
    words: Option<(String, Instant)>,
}

/// Turns of one Live Assist session, per side.
#[derive(Default)]
pub struct TurnFeeder {
    me: Open,
    other: Open,
}

fn side(channel: AudioChannel) -> Channel {
    match channel {
        AudioChannel::Mic => Channel::Me,
        AudioChannel::System => Channel::Other,
    }
}

impl TurnFeeder {
    fn open(&mut self, channel: Channel) -> &mut Open {
        match channel {
            Channel::Me => &mut self.me,
            Channel::Other => &mut self.other,
        }
    }

    fn turn_id(channel: Channel, number: u32) -> String {
        format!("{}-{}", if channel == Channel::Me { "m" } else { "o" }, number)
    }

    /// A partial of the current turn: a `turn_partial` for the other person; none for the microphone (never a
    /// trigger) or for empty words.
    pub fn partial(&mut self, channel: AudioChannel, text: &str, now: Instant) -> Option<ClientMessage> {
        let channel = side(channel);
        let text = text.trim();
        if channel == Channel::Me || text.is_empty() {
            return None;
        }
        let open = self.open(channel);
        if open.words.is_none() {
            open.number += 1;
        }
        let silence_ms = match &open.words {
            Some((words, since)) if words == text => now.saturating_duration_since(*since).as_millis() as u64,
            _ => {
                open.words = Some((text.to_string(), now));
                0
            }
        };
        let turn_id = Self::turn_id(channel, open.number);
        Some(ClientMessage::TurnPartial(Turn { turn_id, channel, text: text.to_string(), language: None, start_ms: None, end_ms: None, silence_ms: Some(silence_ms) }))
    }

    /// The turn's final words, on the recording's clock: a `turn_final` for either side (the microphone's as context);
    /// the turn closes.
    pub fn finished(&mut self, channel: AudioChannel, text: &str, start_ms: u64, end_ms: u64) -> Option<ClientMessage> {
        let channel = side(channel);
        let text = text.trim();
        let open = self.open(channel);
        // A final without partials before it is a turn of its own.
        if open.words.is_none() {
            open.number += 1;
        }
        let turn_id = Self::turn_id(channel, open.number);
        open.words = None;
        if text.is_empty() {
            return None;
        }
        Some(ClientMessage::TurnFinal(Turn { turn_id, channel, text: text.to_string(), language: None, start_ms: Some(start_ms), end_ms: Some(end_ms), silence_ms: None }))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::time::Duration;

    fn json(message: Option<ClientMessage>) -> serde_json::Value {
        serde_json::from_str(&message.expect("a message").frame(1)).unwrap()
    }

    #[test]
    fn the_other_person_s_partials_and_final_share_a_turn_and_the_next_turn_counts_on() {
        let mut feeder = TurnFeeder::default();
        let t0 = Instant::now();
        let first = json(feeder.partial(AudioChannel::System, "so what is", t0));
        assert_eq!((first["turn_id"].as_str(), first["channel"].as_str(), first["silence_ms"].as_u64()), (Some("o-1"), Some("other"), Some(0)));
        // The same words again 140 ms later: they have been still that long.
        let still = json(feeder.partial(AudioChannel::System, "so what is", t0 + Duration::from_millis(140)));
        assert_eq!(still["silence_ms"], 140);
        let more = json(feeder.partial(AudioChannel::System, "so what is your notice", t0 + Duration::from_millis(300)));
        assert_eq!((more["turn_id"].as_str(), more["silence_ms"].as_u64()), (Some("o-1"), Some(0)));
        let done = json(feeder.finished(AudioChannel::System, "So what is your notice period?", 81_200, 86_400));
        assert_eq!(done, serde_json::json!({"type": "turn_final", "seq": 1, "turn_id": "o-1", "channel": "other", "text": "So what is your notice period?", "start_ms": 81200, "end_ms": 86400}));
        let next = json(feeder.partial(AudioChannel::System, "and", t0 + Duration::from_secs(5)));
        assert_eq!(next["turn_id"], "o-2");
        // A final with no partials before it is a turn of its own.
        assert_eq!(json(feeder.finished(AudioChannel::System, "and your salary?", 90_000, 91_000))["turn_id"], "o-2");
        assert_eq!(json(feeder.finished(AudioChannel::System, "Thanks.", 92_000, 92_500))["turn_id"], "o-3");
    }

    #[test]
    fn the_microphone_never_triggers_its_finished_turns_are_context() {
        let mut feeder = TurnFeeder::default();
        assert!(feeder.partial(AudioChannel::Mic, "my notice is", Instant::now()).is_none(), "no partial of mine goes out");
        let mine = json(feeder.finished(AudioChannel::Mic, "My notice is one month.", 87_000, 89_000));
        assert_eq!((mine["type"].as_str(), mine["turn_id"].as_str(), mine["channel"].as_str()), (Some("turn_final"), Some("m-1"), Some("me")));
        // The sides count apart.
        assert_eq!(json(feeder.partial(AudioChannel::System, "okay", Instant::now()))["turn_id"], "o-1");
    }

    #[test]
    fn empty_words_send_nothing() {
        let mut feeder = TurnFeeder::default();
        assert!(feeder.partial(AudioChannel::System, "  ", Instant::now()).is_none());
        assert!(feeder.finished(AudioChannel::System, "", 0, 0).is_none());
    }
}
