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
    // 3 cents left: a Fast suggestion fits, a facts call at its longest (4 cents) does not.
    assert_eq!(facts_calls_with_budget(3, "s-budget-tight").await, (0, false));
    // With room, the same call runs.
    assert_eq!(facts_calls_with_budget(100, "s-budget-room").await, (1, true));
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
