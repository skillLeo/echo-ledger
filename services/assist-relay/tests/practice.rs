//! Practice sessions (the relay asks the questions) and the relay's background calls (the facts ledger and the
//! running summary), on the mocked models with tokio's paused clock.

mod common;

use std::time::Duration;

use assist_relay::config::RelayConfig;
use assist_relay::domain::{Mode, Plan};
use assist_relay::internal::Report;
use assist_relay::provider::mock::PRACTICE;
use assist_relay::provider::{MockStats, Purpose};
use common::*;
use serde_json::json;

fn practice_claims(session: &str) -> assist_relay::auth::Claims {
    let mut token = claims(session);
    token.plan = Plan::Free;
    token.practice = true;
    token.modes = vec![Mode::Fast];
    token
}

fn config_with(questions: u32) -> RelayConfig {
    let mut config = RelayConfig::built_in();
    config.practice.questions = questions;
    config
}

#[tokio::test(start_paused = true)]
async fn practice_asks_questions_from_the_brief_with_a_hint_each_then_ends() {
    let h = harness(config_with(2));
    let mut s = h.connect(practice_claims("s-practice"));
    assert_eq!(s.next().await["mode"], "fast");
    s.send(json!({"type": "context_update", "brief": {"meeting_type": "interview", "title": "Flutter developer at Nuqta Labs"},
        "documents": [{"id": "jd", "title": "Job description", "text": "Flutter, Bloc, Firebase; three years."}]}))
    .await;
    s.drain(Duration::from_millis(500)).await;

    s.send(json!({"type": "practice_next"})).await;
    let question = s.next().await;
    assert_eq!(question["type"], "practice_question", "{question}");
    assert_eq!((question["index"].as_u64(), question["total"].as_u64(), question["turn_id"].as_str()), (Some(1), Some(2), Some("q-1")));
    assert_eq!(question["text"], PRACTICE[0]);
    // A hint for the question, as in a live call.
    let hint = s.until("suggestion_end").await;
    assert_eq!((hint[0]["type"].as_str(), hint[0]["turn_id"].as_str()), (Some("suggestion_start"), Some("q-1")));
    assert_eq!(hint.last().unwrap()["status"], "done");

    // I answer by voice; then the next question.
    s.send(final_turn("m-1", "me", "I am a Flutter developer with three years at Ghar Dekho.")).await;
    s.send(json!({"type": "practice_next"})).await;
    let second = s.until("practice_question").await.pop().unwrap();
    assert_eq!((second["index"].as_u64(), second["text"].as_str()), (Some(2), Some(PRACTICE[1])));
    s.until("suggestion_end").await;

    s.send(json!({"type": "practice_next"})).await;
    let done = s.until("practice_done").await.pop().unwrap();
    assert_eq!(done["asked"], 2);

    // Each question is charged and reported as the relay's own call.
    let questions: Vec<_> = h
        .reports
        .reports()
        .into_iter()
        .filter_map(|r| match r {
            Report::Usage(usage) if usage.kind == assist_relay::protocol::SuggestionKind::PracticeQuestion => Some(usage),
            _ => None,
        })
        .collect();
    assert_eq!(questions.len(), 2);
    assert!(questions.iter().all(|u| u.cost_usd > 0.0 && u.trigger == "practice"));
    assert!(h.state.registry.spent("s-practice") > 0);
    let purposes: Vec<Purpose> = h.mock(assist_relay::domain::ProviderKind::Anthropic).requests().into_iter().map(|(_, p)| p).collect();
    assert_eq!(purposes.iter().filter(|p| **p == Purpose::PracticeQuestion).count(), 2);
}

#[tokio::test(start_paused = true)]
async fn practice_next_is_refused_outside_practice_and_while_a_question_is_coming() {
    let h = harness(config_with(8));
    let mut live = h.connect(claims("s-live"));
    ready_now(&mut live).await;
    live.send(json!({"type": "practice_next"})).await;
    assert_eq!(live.next().await["code"], "not_practice");

    let mut s = h.connect(practice_claims("s-busy"));
    ready_now(&mut s).await;
    s.send(json!({"type": "practice_next"})).await;
    s.send(json!({"type": "practice_next"})).await;
    let busy = s.next().await;
    assert_eq!((busy["code"].as_str(), busy["retry"].as_bool()), (Some("practice_busy"), Some(true)));
    assert_eq!(s.until("practice_question").await.pop().unwrap()["index"], 1);
}

#[tokio::test(start_paused = true)]
async fn with_suggestions_paused_practice_asks_without_hints() {
    let h = harness(config_with(8));
    let mut s = h.connect(practice_claims("s-paused"));
    ready_now(&mut s).await;
    s.send(json!({"type": "context_update", "settings": {"assist": "paused"}})).await;
    s.drain(Duration::from_millis(200)).await;
    s.send(json!({"type": "practice_next"})).await;
    assert_eq!(s.next().await["type"], "practice_question");
    assert!(s.drain(Duration::from_secs(2)).await.is_empty(), "no hint while paused");
}

async fn ready_now(s: &mut Driver) {
    assert_eq!(s.next().await["type"], "ready");
    s.drain(Duration::from_millis(200)).await;
}

#[tokio::test(start_paused = true)]
async fn the_relay_keeps_the_facts_ledger_after_three_final_lines_and_reports_it() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-facts"));
    ready_now(&mut s).await;
    s.send(final_turn("o-1", "other", "Our budget for phase one is 8,000 dollars.")).await;
    s.drain(Duration::from_secs(2)).await;
    s.send(final_turn("m-1", "me", "Okay.")).await;
    s.send(final_turn("m-2", "me", "I can deliver it in 6 weeks.")).await;
    assert!(h.reports.reports().iter().all(|r| !matches!(r, Report::Facts(_))), "a backchannel does not count");
    s.send(final_turn("o-2", "other", "Sounds good, let us talk about the design.")).await;
    s.drain(Duration::from_secs(3)).await;

    let facts = h
        .reports
        .reports()
        .into_iter()
        .find_map(|r| match r {
            Report::Facts(facts) => Some(facts),
            _ => None,
        })
        .expect("a facts report");
    assert_eq!(facts.session_id, "s-facts");
    assert!(facts.facts.iter().any(|f| f.contains("8,000 dollars")), "{:?}", facts.facts);
    assert!(facts.facts.iter().any(|f| f.contains("6 weeks")), "{:?}", facts.facts);
    assert!(
        h.reports.reports().iter().any(|r| matches!(r, Report::Usage(u) if u.kind == assist_relay::protocol::SuggestionKind::Facts)),
        "the facts call is charged"
    );
}

#[tokio::test(start_paused = true)]
async fn the_running_summary_is_written_when_due_and_reported() {
    let mut config = RelayConfig::built_in();
    config.background.summary_every_secs = 60;
    config.background.facts_every_finals = 100;
    let h = harness(config);
    let mut s = h.connect(claims("s-summary"));
    ready_now(&mut s).await;
    s.send(final_turn("o-1", "other", "We are here to plan the launch of the shop app.")).await;
    s.drain(Duration::from_secs(2)).await;
    assert!(h.reports.reports().iter().all(|r| !matches!(r, Report::Summary(_))), "not due yet");
    tokio::time::sleep(Duration::from_secs(61)).await;
    s.send(final_turn("m-1", "me", "Great, the first build is ready next week.")).await;
    s.drain(Duration::from_secs(2)).await;
    let summary = h
        .reports
        .reports()
        .into_iter()
        .find_map(|r| match r {
            Report::Summary(summary) => Some(summary),
            _ => None,
        })
        .expect("a summary report");
    assert!(summary.summary.starts_with("The call so far: 2 new lines"), "{}", summary.summary);
    assert_eq!(MockStats::get(&h.stats.requests) as usize, h.mock(assist_relay::domain::ProviderKind::Anthropic).requests().len());
}

/// Every model's output at 100 USD per million tokens and input free: one suggestion (160 tokens) costs 1.6 cents, a
/// background call at its longest (400 tokens) 4 cents.
fn pricey_output() -> RelayConfig {
    let mut config = RelayConfig::built_in();
    for spec in config.models.values_mut() {
        spec.price.input = 0.0;
        spec.price.cache_read = 0.0;
        spec.price.cache_write = 0.0;
        spec.price.output = 100.0;
        spec.long_context = None;
    }
    config
}

async fn facts_calls_with_budget(cents_left: u64, session: &str) -> (usize, bool) {
    let h = harness(pricey_output());
    let mut token = claims(session);
    token.budget.cents_left = cents_left;
    let mut s = h.connect(token);
    let ready = s.next().await;
    assert_ne!(ready["mode"], "captions_only", "suggestions are on: {ready}");
    s.drain(Duration::from_millis(200)).await;
    for (i, line) in ["I can start on the 3rd.", "My rate is 35 dollars an hour.", "The first build takes 2 weeks."].iter().enumerate() {
        s.send(final_turn(&format!("m-{i}"), "me", line)).await;
    }
    s.drain(Duration::from_secs(3)).await;
    let facts_requests = h.mock(assist_relay::domain::ProviderKind::Anthropic).requests().iter().filter(|(_, p)| *p == Purpose::Facts).count();
    let reported = h.reports.reports().iter().any(|r| matches!(r, Report::Facts(_)));
    (facts_requests, reported)
}

#[tokio::test(start_paused = true)]
async fn a_background_call_that_could_cross_the_budget_is_skipped() {
    // 7 cents: Fast fits (its worst case is 6 cents), a facts call at its worst (8 cents) beside Fast's reserve does not.
    assert_eq!(facts_calls_with_budget(7, "s-budget-tight").await, (0, false));
    // With room, the same call runs.
    assert_eq!(facts_calls_with_budget(100, "s-budget-room").await, (1, true));
}

#[tokio::test(start_paused = true)]
async fn a_background_call_keeps_room_for_the_reserved_suggestions_at_their_worst() {
    // Balanced, three suggestions kept (8 cents each at their worst) beside a facts call (8 cents): 32 cents.
    assert_eq!(facts_calls_with_budget(31, "s-keep-short").await, (0, false));
    assert_eq!(facts_calls_with_budget(32, "s-keep-room").await, (1, true));
}

#[tokio::test(start_paused = true)]
async fn a_ten_hour_captions_only_session_makes_no_background_calls_and_stays_responsive() {
    let h = harness(pricey_output());
    let mut token = claims("s-captions");
    token.budget.cents_left = 0;
    let mut s = h.connect(token);
    assert_eq!(s.next().await["mode"], "captions_only");
    let long = "word ".repeat(799);
    // Ten hours, a final every 5 seconds, each the longest the protocol allows.
    for i in 0..(10 * 3600 / 5) {
        let channel = if i % 2 == 0 { "other" } else { "me" };
        s.send(json!({"type": "turn_final", "turn_id": format!("t-{i}"), "channel": channel, "text": format!("{i} {long}"),
            "language": "en", "start_ms": i * 5000, "end_ms": i * 5000 + 4000}))
        .await;
        if i % 500 == 0 {
            assert!(s.drain(Duration::from_millis(1)).await.is_empty());
        }
    }
    assert_eq!(MockStats::get(&h.stats.requests), 0, "no suggestion and no background call in captions only");
    s.send(json!({"type": "help_now"})).await;
    assert_eq!(s.next().await["code"], "budget_exhausted", "the session still answers");
}

#[tokio::test(start_paused = true)]
async fn a_background_call_without_provider_counts_is_reported_as_estimated() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(practice_claims("s-estimated"));
    ready_now(&mut s).await;
    // A provider that streams the question but sends no counts.
    h.mock(assist_relay::domain::ProviderKind::Anthropic).push(assist_relay::provider::MockBehavior::NoUsage);
    s.send(json!({"type": "practice_next"})).await;
    s.drain(Duration::from_secs(3)).await;
    let usage = h
        .reports
        .reports()
        .into_iter()
        .find_map(|r| match r {
            Report::Usage(u) if u.kind == assist_relay::protocol::SuggestionKind::PracticeQuestion => Some(u),
            _ => None,
        })
        .expect("a usage record");
    assert!(usage.estimated, "{usage:?}");
}

/// A practice question whose Haiku attempt fails with `behavior`; Groq answers. The usage record of the question.
async fn question_usage(behavior: assist_relay::provider::MockBehavior, session: &str) -> assist_relay::internal::UsageRecord {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(practice_claims(session));
    ready_now(&mut s).await;
    h.mock(assist_relay::domain::ProviderKind::Anthropic).push(behavior);
    s.send(json!({"type": "practice_next"})).await;
    assert_eq!(s.until("practice_question").await.pop().unwrap()["index"], 1);
    s.drain(Duration::from_secs(2)).await;
    h.reports
        .reports()
        .into_iter()
        .find_map(|r| match r {
            Report::Usage(u) if u.kind == assist_relay::protocol::SuggestionKind::PracticeQuestion => Some(u),
            _ => None,
        })
        .expect("a usage record")
}

#[tokio::test(start_paused = true)]
async fn a_primary_that_fails_after_streaming_is_charged_with_the_fallback() {
    let broken = question_usage(assist_relay::provider::MockBehavior::Break(3), "s-broken").await;
    let refused = question_usage(assist_relay::provider::MockBehavior::Fail(assist_relay::provider::ProviderError::RateLimited), "s-refused").await;
    assert_eq!((broken.model.as_str(), broken.fallback), ("openai/gpt-oss-120b", true));
    assert!(broken.estimated, "part of it is the abandoned attempt's estimate");
    assert!(broken.tokens.input > refused.tokens.input, "{} vs {}", broken.tokens.input, refused.tokens.input);
    assert!(broken.cost_usd > refused.cost_usd);
    assert!(!refused.estimated, "a rate-limited request bills nothing");
}

#[tokio::test(start_paused = true)]
async fn facts_and_summary_due_together_with_room_for_one_start_only_one() {
    let mut config = pricey_output();
    config.background.summary_every_secs = 1;
    config.budget.reserve_suggestions = 0;
    let h = harness(config);
    let mut token = claims("s-together");
    // 10 cents: one background call at its worst (primary and fallback, 8 cents) fits, two don't.
    token.budget.cents_left = 10;
    let mut s = h.connect(token);
    ready_now(&mut s).await;
    tokio::time::sleep(Duration::from_secs(2)).await;
    for (i, line) in ["I can start on the 3rd.", "My rate is 35 dollars an hour.", "The first build takes 2 weeks."].iter().enumerate() {
        s.send(final_turn(&format!("m-{i}"), "me", line)).await;
    }
    let background = || -> Vec<Purpose> {
        h.mock(assist_relay::domain::ProviderKind::Anthropic)
            .requests()
            .into_iter()
            .map(|(_, p)| p)
            .filter(|p| matches!(p, Purpose::Facts | Purpose::Summary))
            .collect()
    };
    // The summary was due at the first line and started; the facts call, due at the third, did not fit beside it.
    s.drain(Duration::from_secs(3)).await;
    assert_eq!(background(), vec![Purpose::Summary], "only one call in flight at a time on this budget");
    // Once the first is done (and charged), the next line starts the waiting call, its lines kept.
    s.send(final_turn("m-3", "me", "And the second build in 4 weeks.")).await;
    s.drain(Duration::from_secs(3)).await;
    assert_eq!(background(), vec![Purpose::Summary, Purpose::Facts]);
    let facts = h.reports.reports().into_iter().find_map(|r| match r {
        Report::Facts(f) => Some(f),
        _ => None,
    });
    assert!(facts.is_some_and(|f| f.facts.iter().any(|x| x.contains("35 dollars"))), "the lines waited for the call");
}

#[tokio::test(start_paused = true)]
async fn practice_stops_after_three_failed_tries_at_a_question() {
    let h = harness(config_with(8));
    let mut s = h.connect(practice_claims("s-practice-fails"));
    ready_now(&mut s).await;
    let fail = || assist_relay::provider::MockBehavior::Fail(assist_relay::provider::ProviderError::Unavailable);
    for _ in 0..3 {
        h.mock(assist_relay::domain::ProviderKind::Anthropic).push(fail());
        h.mock(assist_relay::domain::ProviderKind::Groq).push(fail());
    }
    for attempt in 1..=3 {
        s.send(json!({"type": "practice_next"})).await;
        let error = s.until("error").await.pop().unwrap();
        assert_eq!(error["code"], "provider_unavailable");
        assert_eq!(error["retry"], attempt < 3, "try {attempt}: {error}");
    }
    // Practice has stopped: the next request ends it.
    s.send(json!({"type": "practice_next"})).await;
    assert_eq!(s.next().await, json!({"type": "practice_done", "asked": 0}));
}

#[tokio::test(start_paused = true)]
async fn a_question_turned_away_beside_a_facts_call_is_answered_once_the_call_finishes() {
    let mut config = pricey_output();
    // The slow facts call waits for Haiku instead of timing out to the fallback.
    config.models.get_mut("claude-haiku-5-5").unwrap().first_token_timeout_ms = 30_000;
    // Background calls keep room for one suggestion at the mode's worst case.
    config.budget.reserve_suggestions = 1;
    // The facts call takes 20 s: a held question may wait that long here.
    config.budget.hold_max_ms = 60_000;
    let h = harness(config);
    let mut token = claims("s-gate");
    // 16 cents. A slow facts call holds 8 cents (its worst case) beside one Balanced suggestion's worst (8 cents);
    // answers spend the rest down while it is in flight.
    token.budget.cents_left = 16;
    let budget_micros = 160_000;
    let mut s = h.connect(token);
    assert_eq!(s.next().await["mode"], "balanced");
    s.drain(Duration::from_millis(200)).await;
    h.mock(assist_relay::domain::ProviderKind::Anthropic).push(assist_relay::provider::MockBehavior::Late(Duration::from_secs(20)));
    for (i, line) in ["I can start on the 3rd.", "My rate is 35 dollars an hour.", "The first build takes 2 weeks."].iter().enumerate() {
        s.send(final_turn(&format!("m-{i}"), "me", line)).await;
    }
    let mut seen = Vec::new();
    let mut held = Vec::new();
    for turn in 0..30 {
        if !held.is_empty() {
            break;
        }
        let turn_id = format!("o-{turn}");
        s.send(final_turn(&turn_id, "other", "What is your notice period?")).await;
        // A Fast answer starts at once; a question the gate turns away gets no message at all.
        match s.next_within(Duration::from_secs(1)).await {
            Some(reply) if reply["type"] == "suggestion_start" => {
                seen.push(reply);
                seen.extend(s.until("suggestion_end").await);
            }
            Some(reply) => seen.push(reply),
            None => held.push(turn_id),
        }
        assert!(h.state.registry.spent("s-gate") <= budget_micros);
    }
    // Balanced answers while its worst case fits beside the facts call; then Fast takes the questions, silently.
    let modes: Vec<&str> = of_type(&seen, "suggestion_start").iter().filter_map(|m| m["mode"].as_str()).collect();
    assert_eq!(modes.first(), Some(&"balanced"), "{modes:?}");
    assert!(modes.contains(&"fast") && modes.iter().skip_while(|m| **m == "balanced").all(|m| *m == "fast"), "{modes:?}");
    // When even Fast's worst case doesn't fit, an automatic question is held quietly, no error...
    assert!(!held.is_empty(), "some questions waited for budget: {seen:?}");
    assert!(of_type(&seen, "error").is_empty(), "{seen:?}");
    assert!(!h.reports.reports().iter().any(|r| matches!(r, Report::Facts(_))), "the facts call is still in flight");
    // ...while the person asking hears why, and may ask again. Their request replaces the held question.
    s.send(json!({"type": "help_now"})).await;
    let refused = s.next().await;
    assert_eq!((refused["code"].as_str(), refused["retry"].as_bool()), (Some("budget_exhausted"), Some(true)), "{refused}");
    // The newest question is held; once the facts call finishes and releases its reservation, it is answered.
    s.send(final_turn("o-7", "other", "And when could you start?")).await;
    assert_eq!(s.next_within(Duration::from_secs(1)).await, None, "held");
    let after = s.drain(Duration::from_secs(25)).await;
    assert!(h.reports.reports().iter().any(|r| matches!(r, Report::Facts(_))), "the facts call finished");
    let restarted = of_type(&after, "suggestion_start");
    assert_eq!(restarted.len(), 1, "only the newest held question starts: {after:?}");
    assert_eq!(restarted[0]["turn_id"], "o-7");
    assert_eq!(of_type(&after, "suggestion_end").last().map(|m| m["status"].clone()), Some(json!("done")), "{after:?}");
    seen.extend(after);
    assert!(of_type(&seen, "mode_changed").is_empty(), "no mode_changed: {:?}", of_type(&seen, "mode_changed"));
    assert!(h.state.registry.spent("s-gate") <= budget_micros, "spent {}", h.state.registry.spent("s-gate"));
    // With the facts call done and its reservation released, Balanced answers again.
    s.send(final_turn("o-9", "other", "What is your notice period?")).await;
    assert_eq!(s.next().await["mode"], "balanced");
}

/// A session in Balanced at 16 cents with a slow facts call (20 s) holding 8 cents, after Fast answers have spent the
/// rest down until a question at the end of a turn (`o-<n>`) is held. Returns the held turn's id.
async fn held_question(session: &str, hold_max_ms: u64) -> (Harness, Driver, String) {
    let mut config = pricey_output();
    config.models.get_mut("claude-haiku-5-5").unwrap().first_token_timeout_ms = 30_000;
    config.budget.reserve_suggestions = 1;
    config.budget.hold_max_ms = hold_max_ms;
    let h = harness(config);
    let mut token = claims(session);
    token.budget.cents_left = 16;
    let mut s = h.connect(token);
    assert_eq!(s.next().await["mode"], "balanced");
    s.drain(Duration::from_millis(200)).await;
    h.mock(assist_relay::domain::ProviderKind::Anthropic).push(assist_relay::provider::MockBehavior::Late(Duration::from_secs(20)));
    for (i, line) in ["I can start on the 3rd.", "My rate is 35 dollars an hour.", "The first build takes 2 weeks."].iter().enumerate() {
        s.send(final_turn(&format!("m-{i}"), "me", line)).await;
    }
    for turn in 0..30 {
        let turn_id = format!("o-{turn}");
        s.send(final_turn(&turn_id, "other", "What is your notice period?")).await;
        match s.next_within(Duration::from_secs(1)).await {
            Some(reply) if reply["type"] == "suggestion_start" => drop(s.until("suggestion_end").await),
            Some(reply) => panic!("unexpected {reply}"),
            None => return (h, s, turn_id),
        }
    }
    panic!("no question was held");
}

/// Waits past the facts call's end and returns what arrived meanwhile.
async fn after_the_facts_call(h: &Harness, s: &mut Driver) -> Vec<serde_json::Value> {
    let after = s.drain(Duration::from_secs(25)).await;
    assert!(h.reports.reports().iter().any(|r| matches!(r, Report::Facts(_))), "the facts call finished");
    after
}

#[tokio::test(start_paused = true)]
async fn a_held_question_starts_once_the_hold_is_released_within_hold_max() {
    let (h, mut s, held) = held_question("s-held-ok", 60_000).await;
    let after = after_the_facts_call(&h, &mut s).await;
    let starts = of_type(&after, "suggestion_start");
    assert_eq!(starts.len(), 1, "{after:?}");
    assert_eq!(starts[0]["turn_id"], held.as_str());
}

#[tokio::test(start_paused = true)]
async fn a_held_question_older_than_hold_max_is_dropped() {
    // The default: 5 s. The facts call holds its budget for about 20 s more.
    let (h, mut s, _) = held_question("s-held-old", RelayConfig::built_in().budget.hold_max_ms).await;
    let after = after_the_facts_call(&h, &mut s).await;
    assert!(of_type(&after, "suggestion_start").is_empty(), "{after:?}");
}

#[tokio::test(start_paused = true)]
async fn a_held_question_is_dropped_once_me_starts_speaking() {
    let (h, mut s, _) = held_question("s-held-me", 60_000).await;
    // Me answers it themselves before the facts call is done: the moment has passed.
    s.send(json!({"type": "turn_partial", "turn_id": "m-9", "channel": "me", "text": "Two weeks, I", "silence_ms": 0})).await;
    let after = after_the_facts_call(&h, &mut s).await;
    assert!(of_type(&after, "suggestion_start").is_empty(), "{after:?}");
}

#[tokio::test(start_paused = true)]
async fn a_held_question_is_dropped_when_the_other_side_keeps_talking() {
    let (h, mut s, _) = held_question("s-held-partial", 60_000).await;
    s.send(partial("o-40", "And also, before that", 0)).await;
    let after = after_the_facts_call(&h, &mut s).await;
    assert!(of_type(&after, "suggestion_start").is_empty(), "{after:?}");
}

#[tokio::test(start_paused = true)]
async fn a_held_question_is_dropped_by_a_newer_final_from_the_other_side() {
    let (h, mut s, _) = held_question("s-held-final", 60_000).await;
    // Even a line that asks nothing ("Okay.") moves the conversation on.
    s.send(final_turn("o-40", "other", "Okay.")).await;
    let after = after_the_facts_call(&h, &mut s).await;
    assert!(of_type(&after, "suggestion_start").is_empty(), "{after:?}");
}

#[tokio::test(start_paused = true)]
async fn a_speculative_question_the_gate_turns_away_is_not_held() {
    let (h, mut s, _) = held_question("s-held-spec", 60_000).await;
    // A question at a pause: the gate turns it away too, and its final would come anyway, so it isn't held.
    s.send(partial("o-40", "When could you join us?", 900)).await;
    assert_eq!(s.next_within(Duration::from_secs(1)).await, None);
    let after = after_the_facts_call(&h, &mut s).await;
    assert!(of_type(&after, "suggestion_start").is_empty(), "{after:?}");
}

#[tokio::test(start_paused = true)]
async fn a_held_question_is_dropped_when_the_app_disconnects() {
    let (h, s, _) = held_question("s-held-gone", 60_000).await;
    let answers = || h.mock(assist_relay::domain::ProviderKind::Anthropic).requests().iter().filter(|(_, p)| *p == Purpose::Answer).count();
    let (asked, spent) = (answers(), h.state.registry.spent("s-held-gone"));
    // The app goes away; the facts call finishes within the resume grace and releases its hold.
    drop(s);
    tokio::time::sleep(Duration::from_secs(25)).await;
    assert!(h.reports.reports().iter().any(|r| matches!(r, Report::Facts(_))), "the facts call finished");
    assert_eq!(answers(), asked, "no suggestion started for no one");
    // Nothing but the facts call was charged after the app left.
    let facts_micros: u64 = h
        .reports
        .reports()
        .iter()
        .filter_map(|r| match r {
            Report::Usage(u) if u.kind == assist_relay::protocol::SuggestionKind::Facts => Some((u.cost_usd * 1_000_000.0).round() as u64),
            _ => None,
        })
        .sum();
    assert_eq!(h.state.registry.spent("s-held-gone") - spent, facts_micros);
}

#[tokio::test(start_paused = true)]
async fn a_background_task_that_panics_frees_its_reservation_and_its_busy_flag() {
    let h = harness(pricey_output());
    let mut token = practice_claims("s-panic");
    // 10 cents: one practice question at its worst (8 cents) fits beside a Fast suggestion; a leaked reservation would
    // leave no room for the next.
    token.budget.cents_left = 10;
    let mut s = h.connect(token);
    ready_now(&mut s).await;
    s.send(json!({"type": "context_update", "settings": {"assist": "paused"}})).await;
    s.drain(Duration::from_millis(100)).await;
    h.mock(assist_relay::domain::ProviderKind::Anthropic).push(assist_relay::provider::MockBehavior::Panic);
    s.send(json!({"type": "practice_next"})).await;
    let error = s.next().await;
    assert_eq!((error["code"].as_str(), error["retry"].as_bool()), (Some("provider_unavailable"), Some(true)), "{error}");
    // Not busy, and the reservation is back: the next question comes.
    s.send(json!({"type": "practice_next"})).await;
    let question = s.next().await;
    assert_eq!((question["type"].as_str(), question["index"].as_u64()), (Some("practice_question"), Some(1)), "{question}");
}

fn pricey_input() -> RelayConfig {
    let mut config = RelayConfig::built_in();
    for spec in config.models.values_mut() {
        spec.price.input = 10.0;
        spec.price.cache_read = 0.0;
        spec.price.cache_write = 0.0;
        spec.price.output = 0.0;
        spec.long_context = None;
    }
    config
}

/// A session with (or without) a long CV, then three lines: the mode it ran in and how many facts calls started.
async fn facts_beside_a_document(cents_left: u64, session: &str, document: bool) -> (String, usize) {
    let h = harness(pricey_input());
    let mut token = claims(session);
    token.budget.cents_left = cents_left;
    let mut s = h.connect(token);
    let mut mode = s.next().await["mode"].as_str().unwrap_or_default().to_owned();
    let documents = if document { json!([{"id": "cv", "title": "CV", "text": "Payments, refunds and payouts. ".repeat(400)}]) } else { json!([]) };
    s.send(json!({"type": "context_update", "documents": documents})).await;
    for m in s.drain(Duration::from_millis(200)).await {
        if m["type"] == "mode_changed" {
            mode = m["mode"].as_str().unwrap_or_default().to_owned();
        }
    }
    for (i, line) in ["I can start on the 3rd.", "My rate is 35 dollars an hour.", "The first build takes 2 weeks."].iter().enumerate() {
        s.send(final_turn(&format!("m-{i}"), "me", line)).await;
    }
    s.drain(Duration::from_secs(3)).await;
    let facts = h.mock(assist_relay::domain::ProviderKind::Anthropic).requests().iter().filter(|(_, p)| *p == Purpose::Facts).count();
    (mode, facts)
}

#[tokio::test(start_paused = true)]
async fn the_reserved_suggestions_worst_case_counts_the_documents() {
    // Input priced, output free: a long CV makes every suggestion dearer, not the facts call (its context has no
    // documents). At 30 cents the facts call fits beside three Balanced suggestions without the CV, not with it.
    assert_eq!(facts_beside_a_document(30, "s-doc-none", false).await, ("balanced".to_owned(), 1));
    assert_eq!(facts_beside_a_document(30, "s-doc-cv", true).await, ("balanced".to_owned(), 0));
    // With room for both, it runs.
    assert_eq!(facts_beside_a_document(60, "s-doc-room", true).await, ("balanced".to_owned(), 1));
}
