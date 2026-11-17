//! What a model reads: the cache order in snapshots (`tests/snapshots`, rewritten with `UPDATE_SNAPSHOTS=1`), the
//! same text the server's renderer makes of the same blocks, and the cache-hit target of the plan (80 % of the input
//! read from the cache after the third suggestion).

mod common;

use std::path::PathBuf;
use std::time::Duration;

use assist_relay::config::{RelayConfig, TranscriptScope};
use assist_relay::context::{CallContext, Context, ContextBuilder, RewriteKind, SegmentKind, Task, Transcript, TranscriptTurn};
use assist_relay::domain::{Channel, HintLevel, LanguageHelp, MeetingType};
use assist_relay::pack::{NONE, Pack};
use assist_relay::protocol::{Brief, Document, Fact};
use common::*;
use serde_json::json;

fn check_snapshot(name: &str, actual: &str) {
    let path = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/snapshots").join(format!("{name}.txt"));
    if std::env::var_os("UPDATE_SNAPSHOTS").is_some() {
        std::fs::create_dir_all(path.parent().unwrap()).unwrap();
        std::fs::write(&path, actual).unwrap();
        return;
    }
    let expected = std::fs::read_to_string(&path).unwrap_or_else(|_| panic!("no snapshot {}; run with UPDATE_SNAPSHOTS=1", path.display()));
    if expected != actual {
        let line = expected.lines().zip(actual.lines()).position(|(a, b)| a != b).unwrap_or(expected.lines().count().min(actual.lines().count()));
        panic!(
            "snapshot {name} differs from line {}:\n  expected: {:?}\n  actual:   {:?}\n(UPDATE_SNAPSHOTS=1 rewrites it after a reviewed change)",
            line + 1,
            expected.lines().nth(line),
            actual.lines().nth(line)
        );
    }
}

fn interview() -> CallContext {
    CallContext {
        brief: Brief {
            meeting_type: MeetingType::Interview,
            title: "Backend developer at Acme".into(),
            counterpart: "Sara Khan".into(),
            company: "Acme".into(),
            goals: "Show the payments work; ask about the team".into(),
            done: "Sent the CV".into(),
            not_done: "Salary not discussed".into(),
            to_discuss: "Notice period, start date".into(),
            instructions: "Keep answers short".into(),
        },
        hint_level: None,
        language_help: LanguageHelp::Urdu,
        documents: vec![Document {
            id: "01JD".into(),
            title: "CV".into(),
            text: "Four years of Laravel and Rust. Built the payments service at Bazaar (2023-2025).".into(),
        }],
        memory: "First call: the role is remote, two rounds.".into(),
        facts: vec![
            Fact { id: Some("f1".into()), text: "Them: the budget is fixed at 5,000 USD (minute 2)".into() },
            Fact { id: Some("f2".into()), text: "Me: notice period is one month".into() },
        ],
        summary: "Introductions; Sara asked about payments experience.".into(),
    }
}

fn transcript(turns: usize) -> Transcript {
    let config = RelayConfig::built_in().context;
    let mut transcript = Transcript::new(&config);
    for i in 0..turns {
        let channel = if i % 2 == 0 { Channel::Other } else { Channel::Me };
        transcript.push(TranscriptTurn {
            turn_id: format!("t-{i}"),
            channel,
            text: format!("Line {i} of the call, with enough words to fill the transcript blocks at a steady pace for the test."),
            start_ms: Some(i as u64 * 9_000),
        });
    }
    transcript
}

fn build(call: &CallContext, transcript: &Transcript, task: Task<'_>, scope: TranscriptScope) -> Context {
    let config = RelayConfig::built_in().context;
    let pack = Pack::built_in();
    ContextBuilder { pack: &pack, config: &config }.build(call, transcript, task, scope)
}

const ASKED: &str = "What is your notice period?";

#[test]
fn snapshot_fast_mode_leaves_the_older_transcript_out() {
    let context =
        build(&interview(), &transcript(60), Task::Answer { turn_id: Some("t-60"), text: ASKED, partial: false }, TranscriptScope::Recent);
    check_snapshot("fast_interview_final", &context.render());
}

#[test]
fn snapshot_balanced_mode_sends_the_whole_transcript_in_blocks() {
    let context =
        build(&interview(), &transcript(60), Task::Answer { turn_id: Some("t-60"), text: ASKED, partial: false }, TranscriptScope::Full);
    check_snapshot("balanced_interview_final", &context.render());
}

#[test]
fn snapshot_a_speculative_partial_and_a_rewrite() {
    let partial =
        build(&interview(), &transcript(6), Task::Answer { turn_id: None, text: "What is your notice", partial: true }, TranscriptScope::Full);
    check_snapshot("partial_interview", &partial.render());
    let mut boss = interview();
    boss.brief.meeting_type = MeetingType::Boss;
    boss.hint_level = Some(HintLevel::Keywords);
    boss.language_help = LanguageHelp::Off;
    let rewrite = build(
        &boss,
        &transcript(4),
        Task::Rewrite { kind: RewriteKind::Translate(LanguageHelp::RomanUrdu), question: ASKED, previous: "One month, negotiable." },
        TranscriptScope::Full,
    );
    check_snapshot("rewrite_boss_translate", &rewrite.render());
}

#[test]
fn snapshot_help_now_before_anything_was_said() {
    let context = build(&CallContext::default(), &transcript(0), Task::HelpNow { latest: None }, TranscriptScope::Full);
    check_snapshot("help_now_empty_general", &context.render());
}

#[test]
fn every_meeting_type_and_mode_keeps_the_cache_order() {
    for meeting in [MeetingType::Interview, MeetingType::Boss, MeetingType::ExistingClient, MeetingType::NewClient, MeetingType::General] {
        for scope in [TranscriptScope::Recent, TranscriptScope::Full] {
            let mut call = interview();
            call.brief.meeting_type = meeting;
            let context = build(&call, &transcript(80), Task::Answer { turn_id: Some("t-80"), text: ASKED, partial: false }, scope);
            let kinds: Vec<SegmentKind> = context.segments.iter().map(|s| s.kind).collect();
            let mut expected = vec![SegmentKind::Rules, SegmentKind::Brief, SegmentKind::Documents, SegmentKind::Memory, SegmentKind::Facts, SegmentKind::Summary];
            let blocks = kinds.iter().filter(|k| **k == SegmentKind::TranscriptBlock).count();
            expected.extend(std::iter::repeat_n(SegmentKind::TranscriptBlock, blocks));
            expected.extend([SegmentKind::LastTurns, SegmentKind::Now, SegmentKind::Question]);
            assert_eq!(kinds, expected, "{meeting:?} {scope:?}");
            let points: Vec<SegmentKind> = context.segments.iter().filter(|s| s.cache_after).map(|s| s.kind).collect();
            match scope {
                TranscriptScope::Recent => assert_eq!(points, [SegmentKind::Rules, SegmentKind::Memory]),
                TranscriptScope::Full => {
                    assert!(blocks >= 2);
                    assert_eq!(points, [SegmentKind::Rules, SegmentKind::Memory, SegmentKind::TranscriptBlock]);
                }
            }
            assert!(!context.text().contains("{{"));
        }
    }
}

#[test]
fn the_text_is_what_the_servers_one_pass_fill_makes_of_the_same_blocks() {
    let mut call = CallContext::default();
    call.brief.meeting_type = MeetingType::NewClient;
    call.brief.title = "Shop app".into();
    call.documents = vec![Document { id: "d".into(), title: "Price list".into(), text: "App: 2,000 USD".into() }];
    call.memory = "  ".into();
    call.facts = vec![Fact { id: None, text: "Them: launch in March".into() }];
    call.summary = "They sell shoes.".into();
    call.hint_level = Some(HintLevel::Outline);
    call.language_help = LanguageHelp::Arabic;
    let mut turns = Transcript::new(&RelayConfig::built_in().context);
    turns.push(TranscriptTurn { turn_id: "m-1".into(), channel: Channel::Me, text: "Hello".into(), start_ms: Some(0) });
    let context = build(&call, &turns, Task::Answer { turn_id: Some("o-2"), text: "Can you build it by March?", partial: false }, TranscriptScope::Full);

    // The server's PromptPack::render: every placeholder replaced once, an empty block is "(none)".
    let raw = include_str!("../pack/new_client.md");
    let values = [
        ("system_rules", include_str!("../pack/system.md").to_owned()),
        ("brief", "Title: Shop app".to_owned()),
        ("documents", "[Price list]\nApp: 2,000 USD".to_owned()),
        ("project_memory", NONE.to_owned()),
        ("facts_ledger", "- Them: launch in March".to_owned()),
        ("running_summary", "They sell shoes.".to_owned()),
        ("transcript_blocks", NONE.to_owned()),
        ("last_turns", "[00:00] Me: Hello".to_owned()),
        ("hint_level", "outline".to_owned()),
        ("language_help", "arabic".to_owned()),
        ("question", "Can you build it by March?".to_owned()),
    ];
    let mut expected = raw.to_owned();
    for (name, value) in values {
        assert_eq!(expected.matches(&format!("{{{{{name}}}}}")).count(), 1, "{name}");
        expected = expected.replacen(&format!("{{{{{name}}}}}"), &value, 1);
    }
    assert_eq!(context.text(), expected);
}

#[tokio::test(start_paused = true)]
async fn after_the_third_suggestion_most_of_the_input_is_read_from_the_cache() {
    let h = harness(RelayConfig::built_in());
    let mut s = h.connect(claims("s-cache"));
    assert_eq!(s.next().await["type"], "ready");
    let cv = "Built and ran the payments service: refunds, payouts, fraud checks and reports. ".repeat(120);
    s.send(json!({"type": "context_update", "brief": {"meeting_type": "interview", "title": "Backend role", "goals": "Show the payments work"},
        "documents": [{"id": "cv", "title": "CV", "text": cv}], "memory": "Second round."}))
        .await;
    let questions = ["What is your notice period?", "Do you know Kotlin?", "When can you start?", "Can you work in our time zone?"];
    let mut ratios = Vec::new();
    for (i, question) in questions.iter().enumerate() {
        s.send(final_turn(&format!("o-{i}"), "other", question)).await;
        let seen = s.until("suggestion_end").await;
        let usage = &seen.last().unwrap()["usage"];
        let cached = usage["cached_input_tokens"].as_f64().unwrap();
        let prompt = cached + usage["input_tokens"].as_f64().unwrap() + usage["cache_write_tokens"].as_f64().unwrap();
        ratios.push(cached / prompt);
        s.send(final_turn(&format!("m-{i}"), "me", "One month, and I can start in March.")).await;
        tokio::time::sleep(Duration::from_millis(50)).await;
    }
    assert!(ratios[3] >= 0.8, "cached share after the third suggestion: {ratios:?}");
}

#[test]
fn every_rewrite_has_its_own_now_without_skip_or_the_hint_shape() {
    for (kind, language) in [
        (RewriteKind::Regenerate, "Language: English"),
        (RewriteKind::Shorter, "Language: English"),
        (RewriteKind::Formal, "Language: English"),
        (RewriteKind::Translate(LanguageHelp::Urdu), "\"Urdu:\""),
        (RewriteKind::Translate(LanguageHelp::Arabic), "\"Arabic:\""),
    ] {
        let context =
            build(&interview(), &transcript(4), Task::Rewrite { kind, question: ASKED, previous: "One month." }, TranscriptScope::Full);
        let now = &context.segments.iter().find(|s| s.kind == SegmentKind::Now).unwrap().text;
        assert!(now.contains("do not output SKIP"), "{kind:?}: {now}");
        assert!(!now.contains("Or output exactly SKIP") && !now.contains("Hint level"), "{kind:?}: {now}");
        assert!(now.contains(language), "{kind:?}: {now}");
    }
}

#[test]
fn an_urdu_context_billed_a_token_a_character_stays_within_its_reservation() {
    use assist_relay::budget::{cost_micros, estimate_micros, worst_micros};
    use assist_relay::provider::Usage;
    let mut call = interview();
    call.language_help = LanguageHelp::Urdu;
    call.brief.goals = "ادائیگی کے نظام کا کام دکھانا اور ٹیم کے بارے میں پوچھنا".into();
    call.documents = vec![Document {
        id: "cv".into(),
        title: "سی وی".into(),
        text: "میں نے ادائیگی کا نظام بنایا، روزانہ چالیس ہزار ادائیگیاں، اور ملاپ تین دن سے چار گھنٹے کر دیا۔ ".repeat(40),
    }];
    let context = build(&call, &transcript(10), Task::Answer { turn_id: Some("o-1"), text: "آپ کا نوٹس پیریڈ کتنا ہے؟", partial: false }, TranscriptScope::Full);
    let config = RelayConfig::built_in();
    let spec = config.model("claude-haiku-5-5").unwrap();
    let max_output = 400;
    // The worst bill for Urdu: every Urdu (non-ASCII) character a token, the English pack rules at their usual four
    // characters a token, and the longest answer.
    let text = context.text();
    let urdu = text.chars().filter(|c| !c.is_ascii()).count();
    let english = text.chars().filter(char::is_ascii).count().div_ceil(4);
    assert!(urdu > english, "a mostly Urdu context: {urdu} Urdu characters, {english} English tokens");
    let input = u32::try_from(urdu + english).unwrap();
    let billed = cost_micros(spec, &Usage { input_tokens: input, output_tokens: max_output, ..Usage::default() });
    let reserved = worst_micros(spec, &context, max_output);
    assert!(billed <= reserved, "billed {billed} > reserved {reserved}");
    // The mode-choice estimate (two characters a token) would not have covered it.
    let estimate = estimate_micros(spec, context.tokens(), 0, false, max_output);
    assert!(estimate < billed, "the estimate {estimate} already covered {billed}");
}
