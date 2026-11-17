//! The session's budget: what a request costs, what the next one will probably cost, and which mode the budget left
//! still pays for. The relay steps down max -> deep -> balanced -> fast -> captions only, and never stops captions.

use crate::auth::Claims;
use crate::config::{ModelSpec, RelayConfig};
use crate::domain::{EffectiveMode, Mode};
use crate::provider::Usage;

/// What `usage` cost on `spec`, in micro-dollars (a token count times a price per million tokens).
pub fn cost_micros(spec: &ModelSpec, usage: &Usage) -> u64 {
    let price = spec.price_for(usage.prompt_tokens());
    let micros = f64::from(usage.input_tokens) * price.input
        + f64::from(usage.cached_input_tokens) * price.cache_read
        + f64::from(usage.cache_write_tokens) * price.cache_write
        + f64::from(usage.output_tokens) * price.output;
    to_micros(micros)
}

/// The likely cost of one request: the cached prefix read back (or written, when this session's cache is cold), the
/// rest as plain input, and the usual length of a suggestion as output.
pub fn estimate_micros(spec: &ModelSpec, context_tokens: u32, cacheable_tokens: u32, warm: bool, output_tokens: u32) -> u64 {
    let price = spec.price_for(context_tokens);
    let cacheable = cacheable_tokens.min(context_tokens);
    let prefix_price = if warm { price.cache_read } else { price.cache_write };
    let micros = f64::from(cacheable) * prefix_price
        + f64::from(context_tokens - cacheable) * price.input
        + f64::from(output_tokens) * price.output;
    to_micros(micros)
}

fn to_micros(value: f64) -> u64 {
    if value.is_finite() && value > 0.0 { value.ceil() as u64 } else { 0 }
}

/// Micro-dollars as dollars (exact to the sixth decimal, as the server's `cost_usd`).
pub fn micros_to_usd(micros: u64) -> f64 {
    micros as f64 / 1_000_000.0
}

/// The modes this session may use: allowed for the session, in the token, and with the mode's main model allowed by
/// the token. A Practice session (`practice: true`) gets Fast on every plan, Free included, within the Practice
/// allowance the token's budget carries; the plan's live modes apply to live sessions only.
pub fn allowed_modes(config: &RelayConfig, claims: &Claims) -> Vec<Mode> {
    Mode::LADDER
        .into_iter()
        .filter(|&mode| {
            let allowed = if claims.practice { mode == Mode::Fast } else { claims.plan.allows(mode) };
            allowed && claims.modes.contains(&mode) && claims.models.contains(&config.modes.get(mode).primary)
        })
        .collect()
}

/// Why the effective mode is what it is.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Cause {
    /// The requested mode runs.
    Requested,
    /// The plan or token caps the mode.
    Plan,
    /// The budget left caps the mode (or allows no suggestions at all).
    Budget,
}

/// The most capable mode at or below `requested` that is allowed and that the budget left still pays for, keeping
/// enough for `reserve` more suggestions (one, for Fast: after Fast come captions only).
/// The mode to run: the most capable allowed mode at or below the requested one whose cost the budget left covers.
/// A mode needs the larger of its usual reserve (`reserve` suggestions at `estimate`, one in Fast) and one suggestion
/// at its `worst` case (both models, the longest answer), so the hard gate a suggestion passes at start never refuses
/// what the mode allows unless other calls hold budget at that moment.
pub fn choose_mode(
    requested: Mode,
    allowed: &[Mode],
    remaining_micros: u64,
    reserve: u32,
    estimate: impl Fn(Mode) -> u64,
    worst: impl Fn(Mode) -> u64,
) -> (EffectiveMode, Cause) {
    let mut candidates: Vec<Mode> = allowed.iter().copied().filter(|&m| m <= requested).collect();
    if candidates.is_empty() {
        // The token allows only modes above the requested one: the cheapest of them.
        candidates.extend(allowed.iter().copied().min());
    }
    candidates.sort_by(|a, b| b.cmp(a));
    let Some(&capped) = candidates.first() else {
        return (EffectiveMode::CaptionsOnly, Cause::Plan);
    };
    for mode in candidates {
        let keep = if mode == Mode::Fast { 1 } else { u64::from(reserve) };
        if remaining_micros >= estimate(mode).saturating_mul(keep).max(worst(mode)) {
            let cause = if mode != capped {
                Cause::Budget
            } else if capped == requested {
                Cause::Requested
            } else {
                Cause::Plan
            };
            return (EffectiveMode::Assist(mode), cause);
        }
    }
    (EffectiveMode::CaptionsOnly, Cause::Budget)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn usage_is_priced_per_million_tokens() {
        let config = RelayConfig::built_in();
        let sonnet = config.model("claude-sonnet-5-5").unwrap();
        let usage = Usage { input_tokens: 1_000, cached_input_tokens: 10_000, cache_write_tokens: 0, output_tokens: 100 };
        // 1,000 x $2 + 10,000 x $0.10 + 100 x $10 per million = 2,000 + 1,000 + 1,000 micro-dollars.
        assert_eq!(cost_micros(sonnet, &usage), 4_000);
        assert_eq!(micros_to_usd(4_000), 0.004);
    }

    #[test]
    fn a_warm_cache_makes_the_estimate_cheaper() {
        let config = RelayConfig::built_in();
        let opus = config.model("claude-opus-5-5").unwrap();
        let cold = estimate_micros(opus, 20_000, 18_000, false, 160);
        let warm = estimate_micros(opus, 20_000, 18_000, true, 160);
        assert!(warm * 5 < cold, "warm {warm} cold {cold}");
    }

    fn flat(costs: [u64; 4]) -> impl Fn(Mode) -> u64 {
        move |mode| match mode {
            Mode::Fast => costs[0],
            Mode::Balanced => costs[1],
            Mode::Deep => costs[2],
            Mode::Max => costs[3],
        }
    }

    #[test]
    fn the_budget_steps_down_one_mode_at_a_time_to_captions() {
        let all = Mode::LADDER.to_vec();
        let costs = flat([100, 1_000, 5_000, 20_000]);
        assert_eq!(choose_mode(Mode::Max, &all, 100_000, 3, &costs, |_| 0), (EffectiveMode::Assist(Mode::Max), Cause::Requested));
        assert_eq!(choose_mode(Mode::Max, &all, 59_999, 3, &costs, |_| 0), (EffectiveMode::Assist(Mode::Deep), Cause::Budget));
        assert_eq!(choose_mode(Mode::Max, &all, 14_999, 3, &costs, |_| 0), (EffectiveMode::Assist(Mode::Balanced), Cause::Budget));
        assert_eq!(choose_mode(Mode::Max, &all, 2_999, 3, &costs, |_| 0), (EffectiveMode::Assist(Mode::Fast), Cause::Budget));
        assert_eq!(choose_mode(Mode::Max, &all, 100, 3, &costs, |_| 0), (EffectiveMode::Assist(Mode::Fast), Cause::Budget));
        assert_eq!(choose_mode(Mode::Max, &all, 99, 3, &costs, |_| 0), (EffectiveMode::CaptionsOnly, Cause::Budget));
    }

    #[test]
    fn the_plan_caps_the_mode_before_the_budget_does() {
        let pro = [Mode::Balanced, Mode::Fast];
        let costs = flat([1, 1, 1, 1]);
        assert_eq!(choose_mode(Mode::Max, &pro, 1_000, 3, &costs, |_| 0), (EffectiveMode::Assist(Mode::Balanced), Cause::Plan));
        assert_eq!(choose_mode(Mode::Fast, &pro, 1_000, 3, &costs, |_| 0), (EffectiveMode::Assist(Mode::Fast), Cause::Requested));
        assert_eq!(choose_mode(Mode::Fast, &[], 1_000, 3, &costs, |_| 0), (EffectiveMode::CaptionsOnly, Cause::Plan));
        // One suggestion's worst case counts too: a mode whose single worst case doesn't fit steps down.
        assert_eq!(choose_mode(Mode::Fast, &pro, 1_000, 3, &costs, |_| 1_001), (EffectiveMode::CaptionsOnly, Cause::Budget));
        assert_eq!(choose_mode(Mode::Fast, &[Mode::Deep], 1_000, 3, &costs, |_| 0), (EffectiveMode::Assist(Mode::Deep), Cause::Plan));
    }
}
