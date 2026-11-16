//! Sessions on mocked models, driven through their channels with tokio's paused clock: speculative start and cancel,
//! the Me channel, SKIP, streaming, the balanced upgrade, the fallback model, the budget ladder down to captions only,
//! tokens that expire or refresh, assist time, resuming, and the reports for the server.

mod common;

use std::time::Duration;

use assist_relay::auth::BudgetClaim;
use assist_relay::config::RelayConfig;
use assist_relay::domain::{Mode, ProviderKind};
use assist_relay::internal::Report;
use assist_relay::provider::mock::mock_reply;
use assist_relay::provider::{MockBehavior, MockStats, ProviderError, Purpose};
use assist_relay::telemetry::{Outcome, Trigger};
use common::*;
use serde_json::json;

const QUESTION: &str = "What is your notice period?";
const HARD: &str = "Could you walk me through how you designed the payment system at your last company and why?";

async fn ready(driver: &mut Driver) -> serde_json::Value {
    let ready = driver.next().await;
    assert_eq!(ready["type"], "ready", "{ready}");
    ready
}

/// Lets aborted provider tasks finish dropping their streams.
async fn settle() {
    tokio::time::sleep(Duration::from_millis(5)).await;
}

#[tokio::test(start_paused = true)]
async fn ready_names_the_mode_the_plan_allows_and_the_token_expiry() {
    let h = harness(RelayConfig::built_in());
    let mut pro = claims("s-ready");
    pro.plan = assist_relay::domain::Plan::Pro;
    pro.modes = vec![Mode::Fast, Mode::Balanced, Mode::Deep];
    let mut s = h.connect(pro);
    let ready = ready(&mut s).await;
    assert_eq!(ready["mode"], "balanced");
    assert_eq!(ready["allowed_modes"], json!(["balanced", "fast"]), "the plan caps what the token lists");
    assert_eq!(ready["resumed"], false);
    assert_eq!(ready["practice"], false);
    assert!(ready["expires_at"].as_str().is_some_and(|t| t.ends_with("+00:00")));
}

#[tokio::test(start_paused = true)]
async fn more_words_within_700_ms_cancel_a_speculative_start_and_the_next_pause_restarts_it() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-spec"));
    ready(&mut s).await;

    // Below 128 ms of silence nothing starts.
    s.send(partial("o-1", "What is your notice period", 100)).await;
    assert!(s.next_within(Duration::from_millis(50)).await.is_none());

    s.send(partial("o-1", "What is your notice period", 150)).await;
    let first = s.next().await;
    assert_eq!((first["type"].as_str(), first["speculative"].as_bool()), (Some("suggestion_start"), Some(true)));
    let first_id = first["suggestion_id"].as_str().unwrap().to_owned();

    // 200 ms later (before its first token at 350 ms) they keep talking.
    tokio::time::sleep(Duration::from_millis(200)).await;
    s.send(partial("o-1", "What is your notice period and when could you start", 0)).await;
    let end = s.next().await;
    assert_eq!((end["type"].as_str(), end["status"].as_str()), (Some("suggestion_end"), Some("cancelled")));
    assert_eq!(end["suggestion_id"], first_id);
    settle().await;
    assert_eq!(MockStats::get(&h.stats.cancelled), 1, "the provider request was closed");

    // Nothing until the next pause; then it starts again.
    assert!(s.next_within(Duration::from_millis(50)).await.is_none());
    s.send(partial("o-1", "What is your notice period and when could you start", 160)).await;
    let restart = s.next().await;
    assert_eq!(restart["type"], "suggestion_start");
    let restart_id = restart["suggestion_id"].as_str().unwrap().to_owned();
    assert_ne!(restart_id, first_id);

    // The final says the same words: the speculative suggestion is the answer, no second request.
    s.send(final_turn("o-1", "other", "What is your notice period, and when could you start?")).await;
    let rest = s.until("suggestion_end").await;
    assert!(of_type(&rest, "suggestion_start").is_empty(), "{rest:?}");
    let done = rest.last().unwrap();
    assert_eq!((done["suggestion_id"].as_str(), done["status"].as_str()), (Some(restart_id.as_str()), Some("done")));
    assert_eq!(text_of(&rest, &restart_id), mock_reply(Purpose::Answer, "What is your notice period and when could you start"));
    assert_eq!(MockStats::get(&h.stats.requests), 2);
}

#[tokio::test(start_paused = true)]
async fn after_700_ms_a_speculative_suggestion_stands_and_a_different_final_replaces_it() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-window"));
    ready(&mut s).await;
    s.send(partial("o-2", "Do you know Rust", 150)).await;
    let first = s.until("suggestion_end").await;
    let first_id = first[0]["suggestion_id"].as_str().unwrap().to_owned();
    assert_eq!(first.last().unwrap()["status"], "done");

    // Well past the window, more words cancel nothing.
    s.send(partial("o-2", "Do you know Rust and Kotlin and Swift", 0)).await;
    assert!(s.next_within(Duration::from_millis(100)).await.is_none());

    // The final differs by more than a word: a new suggestion replaces the speculative one.
    s.send(final_turn("o-2", "other", "Do you know Rust and Kotlin and Swift?")).await;
    let second = s.next().await;
    assert_eq!(second["type"], "suggestion_start");
    assert_eq!((second["speculative"].as_bool(), second["replaces"].as_str()), (Some(false), Some(first_id.as_str())));
}

#[tokio::test(start_paused = true)]
async fn my_own_channel_never_starts_a_suggestion() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-me"));
    ready(&mut s).await;
    s.send(json!({"type": "turn_partial", "turn_id": "m-1", "channel": "me", "text": "What is your notice period", "silence_ms": 900}))
        .await;
    s.send(final_turn("m-1", "me", "What is your notice period?")).await;
    s.send(final_turn("m-2", "me", "Could you walk me through the design and why it works?")).await;
    let seen = s.drain(Duration::from_secs(3)).await;
    assert!(seen.is_empty(), "{seen:?}");
    assert_eq!(MockStats::get(&h.stats.requests), 0);
}

#[tokio::test(start_paused = true)]
async fn a_statement_gets_skip_without_tokens_and_a_backchannel_gets_nothing() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-skip"));
    ready(&mut s).await;
    s.send(final_turn("o-3", "other", "We will send you the contract tomorrow.")).await;
    let seen = s.until("skip").await;
    let start = &seen[0];
    assert_eq!(start["type"], "suggestion_start");
    let skip = seen.last().unwrap();
    assert_eq!(skip["suggestion_id"], start["suggestion_id"]);
    assert_eq!(skip["turn_id"], "o-3");
    assert!(of_type(&seen, "token").is_empty(), "SKIP was held back: {seen:?}");
    assert!(skip["usage"]["output_tokens"].as_u64().is_some());

    s.send(final_turn("o-4", "other", "Okay.")).await;
    s.send(final_turn("o-5", "other", "theek hai")).await;
    assert!(s.drain(Duration::from_secs(2)).await.is_empty());
    assert_eq!(MockStats::get(&h.stats.requests), 1, "short replies cost no request");
    let records = h.telemetry.suggestions();
    assert_eq!(records.last().map(|r| r.outcome), Some(Outcome::Skip));
}

#[tokio::test(start_paused = true)]
async fn a_final_question_streams_word_by_word_with_latency_and_usage() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-stream"));
    ready(&mut s).await;
    s.send(final_turn("o-6", "other", QUESTION)).await;
    let seen = s.until("suggestion_end").await;
    let start = &seen[0];
    assert_eq!(start["type"], "suggestion_start");
    assert_eq!((start["kind"].as_str(), start["mode"].as_str(), start["model"].as_str()), (Some("answer"), Some("balanced"), Some("claude-haiku-5-5")));
    let id = start["suggestion_id"].as_str().unwrap();
    let tokens = of_type(&seen, "token");
    assert!(tokens.len() > 10, "streamed in pieces");
    assert_eq!(text_of(&seen, id), mock_reply(Purpose::Answer, QUESTION));
    let end = seen.last().unwrap();
    assert_eq!((end["status"].as_str(), end["model"].as_str()), (Some("done"), Some("claude-haiku-5-5")));
    let latency = &end["latency"];
    let model_ms = latency["request_to_first_token_ms"].as_f64().unwrap();
    assert!((349.0..=360.0).contains(&model_ms), "the mocked model's 350 ms, got {model_ms}");
    assert!(latency["relay_overhead_ms"].as_f64().unwrap() < 30.0);
    assert!(latency["end_of_turn_to_first_token_ms"].as_f64().unwrap() >= model_ms);
    let usage = &end["usage"];
    assert_eq!(usage["output_tokens"].as_u64(), Some(tokens.len() as u64));
    assert!(usage["cost_usd"].as_f64().unwrap() > 0.0);
    assert_eq!(usage["estimated"], false);
}

#[tokio::test(start_paused = true)]
async fn a_hard_question_in_balanced_gets_a_draft_then_the_upgrade() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-upgrade"));
    ready(&mut s).await;
    s.send(final_turn("o-7", "other", HARD)).await;
    let draft = s.until("suggestion_end").await;
    assert_eq!((draft[0]["draft"].as_bool(), draft[0]["model"].as_str()), (Some(true), Some("claude-haiku-5-5")));
    let draft_id = draft[0]["suggestion_id"].as_str().unwrap().to_owned();
    let upgrade = s.until("suggestion_end").await;
    let start = &upgrade[0];
    assert_eq!((start["kind"].as_str(), start["model"].as_str()), (Some("upgrade"), Some("claude-sonnet-5-5")));
    assert_eq!(start["replaces"], draft_id.as_str());
    let tokens = of_type(&upgrade, "token");
    assert_eq!(tokens.len(), 1, "the upgrade arrives whole");
    assert_eq!(tokens[0]["text"], mock_reply(Purpose::Answer, HARD));
}

#[tokio::test(start_paused = true)]
async fn the_upgrade_is_dropped_once_i_start_speaking() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-upgrade-me"));
    ready(&mut s).await;
    s.send(final_turn("o-8", "other", HARD)).await;
    s.until("suggestion_end").await;
    // I start answering before the upgrade is ready (Sonnet's mock takes about 1.3 s).
    s.send(json!({"type": "turn_partial", "turn_id": "m-8", "channel": "me", "text": "So at Acme I", "silence_ms": 0})).await;
    let later = s.drain(Duration::from_secs(3)).await;
    assert!(of_type(&later, "suggestion_start").is_empty(), "{later:?}");
    let upgrade = h.telemetry.suggestions().into_iter().find(|r| r.trigger == Trigger::Upgrade).expect("an upgrade record");
    assert_eq!(upgrade.outcome, Outcome::Discarded);
}

#[tokio::test(start_paused = true)]
async fn a_failing_model_falls_back_before_the_first_token() {
    let h = harness(RelayConfig::built_in());
    h.mock(ProviderKind::Anthropic).push(MockBehavior::Fail(ProviderError::Unavailable));
    let mut s = h.connect(claims("s-fallback"));
    ready(&mut s).await;
    s.send(final_turn("o-9", "other", QUESTION)).await;
    let seen = s.until("suggestion_end").await;
    assert!(of_type(&seen, "error").is_empty());
    assert_eq!(seen.last().unwrap()["model"], "openai/gpt-oss-120b");
    let record = h.telemetry.suggestions().pop().unwrap();
    assert!(record.fallback_used);
    assert_eq!(record.provider, ProviderKind::Groq);
}

#[tokio::test(start_paused = true)]
async fn a_model_that_breaks_mid_answer_is_replaced_by_the_fallback_model() {
    let h = harness(RelayConfig::built_in());
    let mut fast = claims("s-break");
    fast.modes = vec![Mode::Fast];
    let mut s = h.connect(fast);
    ready(&mut s).await;
    s.send(json!({"type": "context_update", "settings": {"mode": "fast"}})).await;
    h.mock(ProviderKind::Anthropic).push(MockBehavior::Break(3));
    s.send(final_turn("o-1", "other", QUESTION)).await;
    let seen = s.until("suggestion_end").await;
    let first = &of_type(&seen, "suggestion_start")[0];
    let broken = first["suggestion_id"].as_str().unwrap();
    assert_eq!(first["model"], "claude-haiku-5-5");
    assert_eq!(text_of(&seen, broken), "Yes, I can ", "three words reached the card");
    let end = seen.last().unwrap();
    assert_eq!((end["suggestion_id"].as_str(), end["status"].as_str()), (Some(broken), Some("superseded")));

    let replacement = s.next().await;
    assert_eq!(replacement["type"], "suggestion_start");
    assert_eq!(replacement["replaces"], broken);
    assert_eq!(replacement["model"], "openai/gpt-oss-120b");
    assert_eq!(replacement["turn_id"], "o-1");
    let id = replacement["suggestion_id"].as_str().unwrap().to_owned();
    let rest = s.until("suggestion_end").await;
    let done = rest.last().unwrap();
    assert_eq!((done["suggestion_id"].as_str(), done["status"].as_str()), (Some(id.as_str()), Some("done")));
    assert!(text_of(&rest, &id).starts_with("Yes, I can answer that"));
    // Timed from the same end of turn: the broken attempt's time counts.
    assert!(done["latency"]["end_of_turn_to_first_token_ms"].as_f64().unwrap() > 350.0, "{done}");
    let records = h.telemetry.suggestions();
    assert!(records.iter().any(|r| r.suggestion == id && r.fallback_used));
}

/// A Balanced hard question whose Haiku draft breaks after three words: the fallback (gpt-oss) answers as the draft.
async fn broken_draft(h: &Harness, s: &mut Driver, turn: &str) -> (String, String) {
    ready(s).await;
    h.mock(ProviderKind::Anthropic).push(MockBehavior::Break(3));
    s.send(final_turn(turn, "other", HARD)).await;
    let first = s.until("suggestion_end").await;
    assert_eq!((first[0]["draft"].as_bool(), first.last().unwrap()["status"].as_str()), (Some(true), Some("superseded")));
    let broken = first[0]["suggestion_id"].as_str().unwrap().to_owned();
    let replacement = s.until("suggestion_end").await;
    let start = &replacement[0];
    assert_eq!(start["replaces"], broken.as_str());
    assert_eq!((start["model"].as_str(), start["draft"].as_bool()), (Some("openai/gpt-oss-120b"), Some(true)), "still a draft");
    assert_eq!(replacement.last().unwrap()["status"], "done");
    (broken, start["suggestion_id"].as_str().unwrap().to_owned())
}

#[tokio::test(start_paused = true)]
async fn a_draft_replaced_mid_answer_keeps_its_upgrade() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-break-upgrade"));
    let (_, fallback_id) = broken_draft(&h, &mut s, "o-21").await;
    let upgrade = s.until("suggestion_end").await;
    let start = &upgrade[0];
    assert_eq!((start["kind"].as_str(), start["model"].as_str()), (Some("upgrade"), Some("claude-sonnet-5-5")));
    assert_eq!(start["replaces"], fallback_id.as_str(), "it replaces the fallback's answer");
    assert_eq!(text_of(&upgrade, start["suggestion_id"].as_str().unwrap()), mock_reply(Purpose::Answer, HARD));
}

#[tokio::test(start_paused = true)]
async fn a_draft_replaced_mid_answer_loses_its_upgrade_once_i_speak() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-break-upgrade-me"));
    broken_draft(&h, &mut s, "o-22").await;
    s.send(json!({"type": "turn_partial", "turn_id": "m-22", "channel": "me", "text": "So at Acme I", "silence_ms": 0})).await;
    let later = s.drain(Duration::from_secs(3)).await;
    assert!(of_type(&later, "suggestion_start").is_empty(), "{later:?}");
    let upgrade = h.telemetry.suggestions().into_iter().find(|r| r.trigger == Trigger::Upgrade).expect("an upgrade record");
    assert_eq!(upgrade.outcome, Outcome::Discarded);
}

/// One Fast question whose Haiku attempt fails with `error` before its first word; the fallback's `suggestion_end`.
async fn fallback_end(error: ProviderError, session: &str) -> (serde_json::Value, u64) {
    let h = harness(RelayConfig::built_in());
    let mut fast = claims(session);
    fast.modes = vec![Mode::Fast];
    let mut s = h.connect(fast);
    ready(&mut s).await;
    s.send(json!({"type": "context_update", "settings": {"mode": "fast"}})).await;
    settle().await;
    h.mock(ProviderKind::Anthropic).push(MockBehavior::Fail(error));
    let before = h.state.registry.spent(session);
    s.send(final_turn("o-1", "other", QUESTION)).await;
    let end = s.until("suggestion_end").await.pop().unwrap();
    assert_eq!(end["model"], "openai/gpt-oss-120b");
    (end, h.state.registry.spent(session) - before)
}

#[tokio::test(start_paused = true)]
async fn an_abandoned_attempt_is_charged_before_the_fallback_unless_it_was_turned_away() {
    let (charged, spent_charged) = fallback_end(ProviderError::Unavailable, "s-abandon-1").await;
    let (free, spent_free) = fallback_end(ProviderError::RateLimited, "s-abandon-2").await;
    let input = |end: &serde_json::Value| end["usage"]["input_tokens"].as_u64().unwrap();
    assert_eq!(charged["usage"]["estimated"], true, "the abandoned attempt is an estimate");
    assert_eq!(free["usage"]["estimated"], false);
    assert!(input(&charged) > input(&free), "{} vs {}", input(&charged), input(&free));
    assert!(spent_charged > spent_free, "the spend counts the abandoned attempt: {spent_charged} vs {spent_free}");
    let reported = |end: &serde_json::Value| (end["usage"]["cost_usd"].as_f64().unwrap() * 1_000_000.0).round() as u64;
    assert_eq!(reported(&charged), spent_charged, "the report and the spend agree");
}

#[tokio::test(start_paused = true)]
async fn a_model_that_never_answers_ends_in_a_retryable_error_after_the_fallback() {
    let h = harness(RelayConfig::built_in());
    h.mock(ProviderKind::Anthropic).push(MockBehavior::Stall);
    h.mock(ProviderKind::Groq).push(MockBehavior::Stall);
    let mut s = h.connect(claims("s-timeout"));
    ready(&mut s).await;
    s.send(final_turn("o-10", "other", QUESTION)).await;
    let seen = s.until("error").await;
    let error = seen.last().unwrap();
    assert_eq!((error["code"].as_str(), error["retry"].as_bool()), (Some("provider_timeout"), Some(true)));
    assert_eq!(error["suggestion_id"], seen[0]["suggestion_id"]);
}

/// Prices that make the ladder easy to follow: no input cost, and estimates as long as the mocked answers.
fn ladder_config() -> RelayConfig {
    let mut config = RelayConfig::built_in();
    for (id, output) in [("claude-haiku-5-5", 5.0), ("claude-sonnet-5-5", 100.0), ("claude-opus-5-5", 200.0), ("openai/gpt-oss-120b", 6.0)] {
        let model = config.models.get_mut(id).unwrap();
        model.price.input = 0.0;
        model.price.cache_read = 0.0;
        model.price.cache_write = 0.0;
        model.price.output = output;
        model.long_context = None;
    }
    config.budget.expected_output_tokens = 32;
    config
}

#[tokio::test(start_paused = true)]
async fn the_budget_steps_down_one_mode_at_a_time_and_captions_never_stop() {
    let h = harness(ladder_config());
    let mut tight = claims("s-ladder");
    tight.budget = BudgetClaim { seconds_left: 36_000, cents_left: 2 };
    let mut s = h.connect(tight.clone());
    ready(&mut s).await;
    s.send(json!({"type": "context_update", "settings": {"mode": "max"}})).await;
    let changed = s.next().await;
    assert_eq!((changed["type"].as_str(), changed["to"].as_str()), (Some("mode_changed"), Some("max")));

    let mut steps = Vec::new();
    for turn in 0..40 {
        if steps.last().map(String::as_str) == Some("captions_only") {
            break;
        }
        s.send(final_turn(&format!("o-{turn}"), "other", HARD)).await;
        for message in s.drain(Duration::from_secs(4)).await {
            if message["type"] == "mode_changed" {
                assert_eq!(message["reason"], "budget", "{message}");
                steps.push(message["to"].as_str().unwrap().to_owned());
            }
        }
    }
    assert_eq!(steps, ["deep", "balanced", "fast", "captions_only"]);

    // Captions only: turns keep coming in without errors, the connection stays, help now says why it is off.
    for turn in 0..5 {
        s.send(final_turn(&format!("late-{turn}"), "other", QUESTION)).await;
        s.send(partial(&format!("late-p-{turn}"), "And what about the deadline", 400)).await;
    }
    assert!(s.drain(Duration::from_secs(2)).await.is_empty(), "no suggestions and no errors in captions only");
    s.send(json!({"type": "help_now"})).await;
    let refused = s.next().await;
    assert_eq!((refused["code"].as_str(), refused["retry"].as_bool()), (Some("budget_exhausted"), Some(false)));
    assert!(refused["in_reply_to"].as_u64().is_some());

    // A refreshed token with budget brings suggestions back.
    let mut topped_up = tight;
    topped_up.budget.cents_left = 500;
    topped_up.jti = Some("jti-topped-up".into());
    s.send(json!({"type": "token_refresh", "token": sign(&topped_up)})).await;
    let answers = s.drain(Duration::from_millis(500)).await;
    assert_eq!(answers[0]["type"], "token_refreshed");
    let back = &answers[1];
    assert_eq!((back["type"].as_str(), back["to"].as_str(), back["reason"].as_str()), (Some("mode_changed"), Some("max"), Some("budget_restored")));
    s.send(final_turn("o-back", "other", QUESTION)).await;
    let start = s.next().await;
    assert_eq!((start["type"].as_str(), start["mode"].as_str()), (Some("suggestion_start"), Some("max")));
}

/// Fast only, with Haiku's output priced so that a few suggestions use up two cents.
fn pricey_fast(session: &str) -> (RelayConfig, assist_relay::auth::Claims) {
    let mut config = ladder_config();
    config.models.get_mut("claude-haiku-5-5").unwrap().price.output = 100.0;
    let mut token = claims(session);
    token.modes = vec![Mode::Fast];
    token.budget = BudgetClaim { seconds_left: 36_000, cents_left: 2 };
    (config, token)
}

/// Asks questions until the budget leaves captions only; the number of suggestions it took.
async fn use_up(s: &mut Driver, tag: &str) -> usize {
    for turn in 0..60 {
        s.send(final_turn(&format!("{tag}-{turn}"), "other", QUESTION)).await;
        let seen = s.drain(Duration::from_secs(3)).await;
        if seen.iter().any(|m| m["type"] == "mode_changed" && m["to"] == "captions_only") {
            return turn + 1;
        }
    }
    panic!("the budget never ran out");
}

#[tokio::test(start_paused = true)]
async fn going_back_to_an_earlier_token_never_restores_budget() {
    let (config, x) = pricey_fast("s-alt");
    let h = harness(config);
    let mut y = x.clone();
    y.jti = Some("jti-y".into());
    let mut s = h.connect(x.clone());
    ready(&mut s).await;
    assert!(use_up(&mut s, "x").await >= 2);

    // A new token from the server (the same second, another jti) brings budget, and is used up too.
    s.send(json!({"type": "token_refresh", "token": sign(&y)})).await;
    let answers = s.drain(Duration::from_millis(500)).await;
    assert_eq!(answers[0]["type"], "token_refreshed");
    let back = answers.iter().find(|m| m["type"] == "mode_changed").expect("a mode change");
    assert_eq!((back["to"].as_str(), back["reason"].as_str()), (Some("fast"), Some("budget_restored")), "the plan caps it at fast, the budget is the change");
    use_up(&mut s, "y").await;

    // Back to the first token: it continues where it was, so nothing comes back.
    s.send(json!({"type": "token_refresh", "token": sign(&x)})).await;
    let answers = s.drain(Duration::from_millis(500)).await;
    assert_eq!(answers.len(), 1, "{answers:?}");
    assert_eq!(answers[0]["type"], "token_refreshed");
    s.send(json!({"type": "help_now"})).await;
    assert_eq!(s.next().await["code"], "budget_exhausted");

    // And y again: also where it was.
    s.send(json!({"type": "token_refresh", "token": sign(&y)})).await;
    assert_eq!(s.drain(Duration::from_millis(500)).await.len(), 1);
    s.send(json!({"type": "help_now"})).await;
    assert_eq!(s.next().await["code"], "budget_exhausted");

    // A token issued before the newest one is refused, by refresh and by reconnect alike.
    let mut older = x.clone();
    older.iat -= 60;
    older.jti = Some("jti-older".into());
    s.send(json!({"type": "token_refresh", "token": sign(&older)})).await;
    let refused = s.next().await;
    assert_eq!(refused["code"], "token_refresh_rejected");
    assert!(refused["message"].as_str().unwrap().contains("older"), "{refused}");
    assert!(!h.state.registry.accepts("s-alt", older.iat), "refused before the upgrade too");
    let mut late = h.connect(older);
    let error = late.next().await;
    assert_eq!((error["code"].as_str(), error["fatal"].as_bool()), (Some("token_invalid"), Some(true)));
    assert_eq!(late.next().await["code"], 4401);
    // The connection in use was not replaced.
    s.send(json!({"type": "help_now"})).await;
    assert_eq!(s.next().await["code"], "budget_exhausted");
}

#[tokio::test(start_paused = true)]
async fn practice_gets_fast_on_free_and_a_live_free_session_gets_captions_only() {
    let h = harness(RelayConfig::built_in());
    let mut practice = claims("s-practice");
    practice.plan = assist_relay::domain::Plan::Free;
    practice.practice = true;
    let mut s = h.connect(practice);
    let first = ready(&mut s).await;
    assert_eq!((first["mode"].as_str(), first["practice"].as_bool()), (Some("fast"), Some(true)));
    assert_eq!(first["allowed_modes"], json!(["fast"]), "practice is Fast only, whatever the plan");
    s.send(final_turn("o-1", "other", QUESTION)).await;
    let seen = s.until("suggestion_end").await;
    assert_eq!((seen[0]["mode"].as_str(), seen.last().unwrap()["status"].as_str()), (Some("fast"), Some("done")));

    let mut live = claims("s-free-live");
    live.plan = assist_relay::domain::Plan::Free;
    let mut s = h.connect(live);
    let first = ready(&mut s).await;
    assert_eq!((first["mode"].as_str(), first["allowed_modes"].clone()), (Some("captions_only"), json!([])));
    s.send(final_turn("o-1", "other", QUESTION)).await;
    assert!(s.drain(Duration::from_secs(2)).await.is_empty(), "no suggestion in a live Free session");
    s.send(json!({"type": "help_now"})).await;
    let refused = s.next().await;
    assert_eq!(refused["code"], "budget_exhausted");
    assert!(refused["message"].as_str().unwrap().contains("no live suggestions"), "{refused}");

    // An Ultra practice session is Fast too: the plan's live modes don't apply.
    let mut ultra = claims("s-ultra-practice");
    ultra.practice = true;
    let mut s = h.connect(ultra);
    assert_eq!(ready(&mut s).await["allowed_modes"], json!(["fast"]));
}

#[tokio::test(start_paused = true)]
async fn assist_time_running_out_leaves_captions_only() {
    let h = harness(RelayConfig::built_in());
    let mut short = claims("s-time");
    short.budget.seconds_left = 5;
    let mut s = h.connect(short);
    ready(&mut s).await;
    let changed = s.next_within(Duration::from_secs(10)).await.expect("a notice");
    assert_eq!((changed["to"].as_str(), changed["reason"].as_str()), (Some("captions_only"), Some("assist_time")));
}

#[tokio::test(start_paused = true)]
async fn an_expired_token_closes_the_connection_with_4401() {
    let h = harness(RelayConfig::built_in());
    let mut short = claims("s-expiry");
    short.exp = short.iat + 60;
    let mut s = h.connect(short);
    ready(&mut s).await;
    tokio::time::sleep(Duration::from_secs(95)).await;
    let error = s.next().await;
    assert_eq!((error["code"].as_str(), error["fatal"].as_bool(), error["retry"].as_bool()), (Some("token_expired"), Some(true), Some(true)));
    let close = s.next().await;
    assert_eq!((close["type"].as_str(), close["code"].as_u64()), (Some("close"), Some(4401)));
}

#[tokio::test(start_paused = true)]
async fn a_refresh_for_another_session_or_person_is_refused() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-refresh"));
    ready(&mut s).await;
    s.send(json!({"type": "token_refresh", "token": sign(&claims("s-other"))})).await;
    let refused = s.next().await;
    assert_eq!(refused["code"], "token_refresh_rejected");
    assert!(refused["message"].as_str().unwrap().contains("another session"));
    let mut stranger = claims("s-refresh");
    stranger.sub = "user-2".into();
    s.send(json!({"type": "token_refresh", "token": sign(&stranger)})).await;
    assert_eq!(s.next().await["code"], "token_refresh_rejected");
    s.send(json!({"type": "token_refresh", "token": sign(&claims("s-refresh"))})).await;
    assert_eq!(s.next().await["type"], "token_refreshed");
}

#[tokio::test(start_paused = true)]
async fn a_reconnect_resumes_the_session_and_its_spend_and_a_second_connection_takes_over() {
    let h = harness(RelayConfig::built_in());
    let token = claims("s-resume");
    let mut first = h.connect(token.clone());
    ready(&mut first).await;
    first.send(final_turn("o-11", "other", QUESTION)).await;
    first.until("suggestion_end").await;
    let spent = h.state.registry.spent("s-resume");
    assert!(spent > 0);

    let mut second = h.connect(token.clone());
    let close = first.next().await;
    assert_eq!((close["type"].as_str(), close["code"].as_u64()), (Some("close"), Some(4409)));
    let resumed = second.next().await;
    assert_eq!((resumed["type"].as_str(), resumed["resumed"].as_bool()), (Some("ready"), Some(true)));

    // The connection drops; within the grace period the same session comes back with what it had.
    drop(second);
    tokio::time::sleep(Duration::from_secs(60)).await;
    let mut third = h.connect(token);
    assert_eq!(third.next().await["resumed"], true);
    assert_eq!(h.state.registry.spent("s-resume"), spent);
    third.send(json!({"type": "shorter"})).await;
    let shorter = third.until("suggestion_end").await;
    assert_eq!(shorter[0]["kind"], "shorter", "the history came back too");
}

#[tokio::test(start_paused = true)]
async fn rewrites_replace_the_card_and_cancel_stops_a_suggestion() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-rewrite"));
    ready(&mut s).await;
    s.send(json!({"type": "shorter"})).await;
    assert_eq!(s.next().await["code"], "nothing_to_rewrite");

    s.send(final_turn("o-12", "other", QUESTION)).await;
    let first = s.until("suggestion_end").await;
    let first_id = first[0]["suggestion_id"].as_str().unwrap().to_owned();
    for (message, kind, purpose) in [
        (json!({"type": "shorter"}), "shorter", Purpose::Shorter),
        (json!({"type": "formal", "suggestion_id": first_id}), "formal", Purpose::Formal),
        (json!({"type": "translate", "language": "urdu"}), "translate", Purpose::Translate),
        (json!({"type": "regenerate"}), "regenerate", Purpose::Regenerate),
    ] {
        s.send(message).await;
        let seen = s.until("suggestion_end").await;
        let id = seen[0]["suggestion_id"].as_str().unwrap();
        assert_eq!(seen[0]["kind"], kind);
        assert!(seen[0]["replaces"].is_string());
        assert_eq!(text_of(&seen, id), mock_reply(purpose, QUESTION), "{kind}");
    }

    s.send(final_turn("o-13", "other", "And how do you handle pressure at work?")).await;
    let start = s.next().await;
    s.send(json!({"type": "cancel"})).await;
    let end = s.until("suggestion_end").await;
    let end = end.last().unwrap();
    assert_eq!((end["suggestion_id"].clone(), end["status"].as_str()), (start["suggestion_id"].clone(), Some("cancelled")));
    assert_eq!(end["usage"]["estimated"], true);
}

#[tokio::test(start_paused = true)]
async fn usage_and_shown_texts_are_reported_for_the_server() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-reports"));
    ready(&mut s).await;
    s.send(final_turn("o-14", "other", QUESTION)).await;
    let shown = s.until("suggestion_end").await;
    s.send(final_turn("o-15", "other", "We will send you the contract tomorrow.")).await;
    let skipped = s.until("skip").await;
    let reports = h.reports.reports();
    let usage: Vec<_> = reports.iter().filter_map(|r| if let Report::Usage(u) = r { Some(u) } else { None }).collect();
    let texts: Vec<_> = reports.iter().filter_map(|r| if let Report::Suggestion(t) = r { Some(t) } else { None }).collect();
    assert_eq!(usage.len(), 2);
    assert_eq!(usage[0].suggestion_id, shown[0]["suggestion_id"].as_str().unwrap());
    assert_eq!((usage[0].outcome, usage[1].outcome), ("done", "skip"));
    assert!(usage[0].tokens.output > 0 && usage[0].cost_usd > 0.0);
    assert_eq!(usage[1].suggestion_id, skipped[0]["suggestion_id"].as_str().unwrap());
    assert_eq!(texts.len(), 1, "only shown suggestions have a text");
    assert_eq!(texts[0].text, mock_reply(Purpose::Answer, QUESTION));
    assert_eq!(texts[0].turn_id.as_deref(), Some("o-14"));
}
