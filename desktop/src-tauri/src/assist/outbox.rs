//! The messages waiting for the relay. While the socket is up they leave in order, paced to the relay's
//! `ready.limits.messages_per_second`; while it is down they wait, bounded, and only what still matters is kept: the
//! latest `turn_partial` and the latest `context_update`, but every `turn_final` and every command. A message whose send
//! failed goes back to the front, so nothing is lost or reordered by a broken socket.

use std::collections::VecDeque;
use std::time::Duration;

use super::protocol::ClientMessage;

/// At most this many messages wait.
pub const MAX_MESSAGES: usize = 500;
/// At most this many bytes of messages wait (a full context update is up to 2 MiB).
pub const MAX_BYTES: usize = 4 * 1024 * 1024;
/// The relay's rate when its `ready` names none.
pub const DEFAULT_RATE: u32 = 40;

#[derive(Default)]
pub struct Outbox {
    queue: VecDeque<(ClientMessage, usize)>,
    bytes: usize,
    /// Messages dropped because the bounds were reached (the oldest go first).
    pub dropped: usize,
}

fn size(message: &ClientMessage) -> usize {
    message.frame(0).len()
}

fn is_partial(message: &ClientMessage) -> bool {
    matches!(message, ClientMessage::TurnPartial(_))
}

fn is_context(message: &ClientMessage) -> bool {
    matches!(message, ClientMessage::ContextUpdate(_))
}

impl Outbox {
    pub fn len(&self) -> usize {
        self.queue.len()
    }

    pub fn is_empty(&self) -> bool {
        self.queue.is_empty()
    }

    pub fn bytes(&self) -> usize {
        self.bytes
    }

    /// Adds a message at the back. While `down`, a `turn_partial` or a `context_update` replaces the one of its kind
    /// already waiting (only the latest still matters). Then the oldest messages go until the bounds hold again.
    pub fn push(&mut self, message: ClientMessage, down: bool) {
        if down && (is_partial(&message) || is_context(&message)) {
            let same: fn(&ClientMessage) -> bool = if is_partial(&message) { is_partial } else { is_context };
            let before = self.queue.len();
            self.queue.retain(|(waiting, _)| !same(waiting));
            if self.queue.len() != before {
                self.bytes = self.queue.iter().map(|(_, n)| n).sum();
            }
        }
        let n = size(&message);
        self.queue.push_back((message, n));
        self.bytes += n;
        while self.queue.len() > MAX_MESSAGES || (self.bytes > MAX_BYTES && self.queue.len() > 1) {
            if let Some((_, n)) = self.queue.pop_front() {
                self.bytes -= n;
                self.dropped += 1;
            }
        }
    }

    /// Takes the oldest message out to send it.
    pub fn pop(&mut self) -> Option<ClientMessage> {
        let (message, n) = self.queue.pop_front()?;
        self.bytes -= n;
        Some(message)
    }

    /// Puts back a message whose send failed, first in line.
    pub fn unpop(&mut self, message: ClientMessage) {
        let n = size(&message);
        self.bytes += n;
        self.queue.push_front((message, n));
    }

    /// Puts `context` first in line in place of any `context_update` still waiting (it already holds them).
    pub fn context_first(&mut self, context: ClientMessage) {
        self.queue.retain(|(waiting, _)| !is_context(waiting));
        self.bytes = self.queue.iter().map(|(_, n)| n).sum();
        self.unpop(context);
    }

    /// When the socket goes down, what is already waiting is thinned as if it had been queued while down.
    pub fn went_down(&mut self) {
        let waiting: Vec<ClientMessage> = self.queue.drain(..).map(|(m, _)| m).collect();
        self.bytes = 0;
        for message in waiting {
            self.push(message, true);
        }
    }
}

/// The pause between two messages at the relay's rate (pure).
pub fn gap(messages_per_second: Option<u32>) -> Duration {
    let rate = messages_per_second.filter(|r| *r > 0).unwrap_or(DEFAULT_RATE);
    Duration::from_secs(1) / rate
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::assist::protocol::{Channel, ContextUpdate, Settings, Turn};

    fn turn(final_: bool, text: &str) -> ClientMessage {
        let turn = Turn { turn_id: "o-1".into(), channel: Channel::Other, text: text.into(), language: None, start_ms: None, end_ms: None, silence_ms: None };
        if final_ { ClientMessage::TurnFinal(turn) } else { ClientMessage::TurnPartial(turn) }
    }

    fn context(mode: &str) -> ClientMessage {
        ClientMessage::ContextUpdate(Box::new(ContextUpdate { settings: Some(Settings { mode: Some(mode.into()), ..Settings::default() }), ..ContextUpdate::default() }))
    }

    fn drain(outbox: &mut Outbox) -> Vec<String> {
        std::iter::from_fn(|| outbox.pop()).map(|m| {
            let json: serde_json::Value = serde_json::from_str(&m.frame(0)).unwrap();
            format!("{} {}", json["type"].as_str().unwrap(), json["text"].as_str().or(json["settings"]["mode"].as_str()).unwrap_or(""))
        }).collect()
    }

    #[test]
    fn while_down_only_the_latest_partial_and_context_are_kept_but_every_final_and_command() {
        let mut outbox = Outbox::default();
        outbox.push(context("fast"), true);
        outbox.push(turn(false, "so what"), true);
        outbox.push(turn(true, "So what is your notice?"), true);
        outbox.push(ClientMessage::HelpNow { turn_id: None }, true);
        outbox.push(turn(false, "and your"), true);
        outbox.push(context("deep"), true);
        outbox.push(turn(false, "and your salary"), true);
        outbox.push(turn(true, "And your salary?"), true);
        assert_eq!(drain(&mut outbox), ["turn_final So what is your notice?", "help_now ", "context_update deep", "turn_partial and your salary", "turn_final And your salary?"]);
    }

    #[test]
    fn while_up_everything_is_kept_in_order() {
        let mut outbox = Outbox::default();
        outbox.push(turn(false, "a"), false);
        outbox.push(turn(false, "ab"), false);
        outbox.push(context("fast"), false);
        outbox.push(context("deep"), false);
        assert_eq!(drain(&mut outbox), ["turn_partial a", "turn_partial ab", "context_update fast", "context_update deep"]);
        // Going down thins what still waits.
        for m in [turn(false, "a"), turn(false, "ab"), turn(true, "AB")] {
            outbox.push(m, false);
        }
        outbox.went_down();
        assert_eq!(drain(&mut outbox), ["turn_partial ab", "turn_final AB"]);
    }

    #[test]
    fn the_queue_is_bounded_by_count_and_bytes_the_oldest_going_first() {
        let mut outbox = Outbox::default();
        for i in 0..(MAX_MESSAGES + 10) {
            outbox.push(turn(true, &format!("t{i}")), true);
        }
        assert_eq!(outbox.len(), MAX_MESSAGES);
        assert_eq!(outbox.dropped, 10);
        assert_eq!(drain(&mut outbox)[0], "turn_final t10");
        let mut outbox = Outbox::default();
        let big = "x".repeat(MAX_BYTES / 3);
        for _ in 0..4 {
            outbox.push(turn(true, &big), true);
        }
        assert!(outbox.bytes() <= MAX_BYTES, "{}", outbox.bytes());
        assert_eq!(outbox.len(), 2);
        assert_eq!(outbox.dropped, 2);
    }

    #[test]
    fn the_whole_context_goes_first_in_place_of_a_waiting_one() {
        let mut outbox = Outbox::default();
        outbox.push(turn(true, "first"), false);
        outbox.push(context("fast"), false);
        outbox.context_first(context("deep"));
        assert_eq!(drain(&mut outbox), ["context_update deep", "turn_final first"]);
        assert_eq!(outbox.bytes(), 0);
    }

    #[test]
    fn a_failed_send_goes_back_to_the_front() {
        let mut outbox = Outbox::default();
        outbox.push(turn(true, "first"), false);
        outbox.push(turn(true, "second"), false);
        let first = outbox.pop().unwrap();
        outbox.unpop(first);
        assert_eq!(drain(&mut outbox), ["turn_final first", "turn_final second"]);
        assert_eq!(outbox.bytes(), 0);
    }

    #[test]
    fn messages_are_paced_to_the_relay_s_rate() {
        assert_eq!(gap(Some(40)), Duration::from_millis(25));
        assert_eq!(gap(Some(10)), Duration::from_millis(100));
        assert_eq!(gap(None), Duration::from_millis(25), "the relay's documented 40 a second");
        assert_eq!(gap(Some(0)), Duration::from_millis(25));
    }
}
