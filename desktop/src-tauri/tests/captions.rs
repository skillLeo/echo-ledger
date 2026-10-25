//! Caption buffer (rolling window, live-transcript segments) and the event fan-out to webviews, including the JSON
//! the React views receive.

use std::sync::{Arc, Mutex};

use serde_json::{Value, json};
use tauri::ipc::{Channel as IpcChannel, InvokeResponseBody};

use desktop_lib::audio::types::Channel;
use desktop_lib::stt::captions::{CaptionBuffer, CaptionEvent, CaptionHub, FinalTurn};

fn turn(wall_ms: u64, channel: Channel, text: &str, start_ms: u64, end_ms: u64) -> FinalTurn {
    FinalTurn {
        wall_ms,
        channel,
        text: text.into(),
        start_ms,
        end_ms,
    }
}

fn json_of(event: &CaptionEvent) -> Value {
    serde_json::to_value(event).unwrap()
}

/// A subscriber whose received events are recorded as JSON, like the webview would see them.
fn recording_channel() -> (IpcChannel<CaptionEvent>, Arc<Mutex<Vec<Value>>>) {
    let log = Arc::new(Mutex::new(Vec::new()));
    let sink = log.clone();
    let channel = IpcChannel::new(move |body| {
        match body {
            InvokeResponseBody::Json(text) => sink
                .lock()
                .unwrap()
                .push(serde_json::from_str(&text).unwrap()),
            InvokeResponseBody::Raw(_) => panic!("captions are JSON"),
        }
        Ok(())
    });
    (channel, log)
}

// ---- Wire format -----------------------------------------------------------------------------------------------

#[test]
fn events_serialise_with_a_kind_tag_and_camel_case_fields() {
    assert_eq!(
        json_of(&CaptionEvent::Partial {
            speaker: "Me".into(),
            channel: 1,
            text: "hel".into()
        }),
        json!({"kind": "partial", "speaker": "Me", "channel": 1, "text": "hel"})
    );
    assert_eq!(
        json_of(&CaptionEvent::Final {
            speaker: "Client".into(),
            channel: 2,
            text: "Hello.".into(),
            start_ms: 120,
            end_ms: 900
        }),
        json!({"kind": "final", "speaker": "Client", "channel": 2, "text": "Hello.", "startMs": 120, "endMs": 900})
    );
    assert_eq!(
        json_of(&CaptionEvent::Status {
            live: true,
            recording: false,
            provider: Some("assemblyai".into()),
            message: None
        }),
        json!({"kind": "status", "live": true, "recording": false, "provider": "assemblyai", "message": null})
    );
    assert_eq!(json_of(&CaptionEvent::Clear), json!({"kind": "clear"}));
}

#[test]
fn the_event_shape_matches_the_typescript_declaration() {
    // desktop/src/lib/ipc.ts declares the union the views switch on; every Rust field name must appear in it.
    let ts = std::fs::read_to_string(
        std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("..")
            .join("src")
            .join("lib")
            .join("ipc.ts"),
    )
    .expect("ipc.ts");
    let start = ts
        .find("CaptionEvent")
        .expect("CaptionEvent type in ipc.ts");
    let declaration: String = ts[start..].lines().take(6).collect::<Vec<_>>().join("\n");
    for name in [
        "partial",
        "final",
        "status",
        "clear",
        "speaker",
        "channel",
        "text",
        "startMs",
        "endMs",
        "live",
        "recording",
        "provider",
        "message",
    ] {
        assert!(
            declaration.contains(name),
            "`{name}` missing from the TypeScript CaptionEvent: {declaration}"
        );
    }
}

// ---- Hub -------------------------------------------------------------------------------------------------------

#[test]
fn events_reach_only_the_targeted_windows() {
    let hub = CaptionHub::default();
    let (overlay, overlay_log) = recording_channel();
    let (main, main_log) = recording_channel();
    let (dictation, dictation_log) = recording_channel();
    hub.subscribe("overlay", overlay);
    hub.subscribe("main", main);
    hub.subscribe("dictation", dictation);

    hub.send(&["overlay", "main"], CaptionEvent::Clear);
    hub.send(
        &["dictation"],
        CaptionEvent::Partial {
            speaker: "Me".into(),
            channel: 1,
            text: "dictating".into(),
        },
    );

    assert_eq!(*overlay_log.lock().unwrap(), vec![json!({"kind": "clear"})]);
    assert_eq!(*main_log.lock().unwrap(), vec![json!({"kind": "clear"})]);
    assert_eq!(dictation_log.lock().unwrap().len(), 1);
    assert_eq!(dictation_log.lock().unwrap()[0]["text"], "dictating");
}

#[test]
fn subscribing_again_replaces_the_previous_channel_of_that_window() {
    let hub = CaptionHub::default();
    let (first, first_log) = recording_channel();
    let (second, second_log) = recording_channel();
    hub.subscribe("overlay", first);
    hub.send(&["overlay"], CaptionEvent::Clear);
    hub.subscribe("overlay", second); // the React view remounted
    hub.send(&["overlay"], CaptionEvent::Clear);
    assert_eq!(
        first_log.lock().unwrap().len(),
        1,
        "the stale channel gets nothing further"
    );
    assert_eq!(second_log.lock().unwrap().len(), 1);
}

#[test]
fn a_closed_webview_is_dropped_without_affecting_the_others() {
    let hub = CaptionHub::default();
    let calls = Arc::new(Mutex::new(0usize));
    let counter = calls.clone();
    let dead: IpcChannel<CaptionEvent> = IpcChannel::new(move |_| {
        *counter.lock().unwrap() += 1;
        Err(tauri::Error::WebviewNotFound)
    });
    let (alive, alive_log) = recording_channel();
    hub.subscribe("main", dead);
    hub.subscribe("overlay", alive);

    for _ in 0..3 {
        hub.send(&["main", "overlay"], CaptionEvent::Clear);
    }
    assert_eq!(
        *calls.lock().unwrap(),
        1,
        "a failing channel is unsubscribed after its first failure"
    );
    assert_eq!(alive_log.lock().unwrap().len(), 3);
}

#[test]
fn sending_with_no_subscribers_or_no_matching_target_is_harmless() {
    let hub = CaptionHub::default();
    hub.send(&["overlay"], CaptionEvent::Clear);
    let (c, log) = recording_channel();
    hub.subscribe("main", c);
    hub.send(&["nobody"], CaptionEvent::Clear);
    hub.send(&[], CaptionEvent::Clear);
    assert!(log.lock().unwrap().is_empty());
}

// ---- Buffer: windows -------------------------------------------------------------------------------------------

#[test]
fn an_empty_buffer_has_no_text_and_no_segments() {
    let buf = CaptionBuffer::default();
    assert!(buf.is_empty());
    assert_eq!(buf.len(), 0);
    assert_eq!(buf.text_since(1_000_000, 120_000), "");
    assert!(buf.segments().is_empty());
}

#[test]
fn the_window_includes_the_cutoff_instant_and_excludes_anything_older() {
    let mut buf = CaptionBuffer::default();
    buf.push(turn(1_000, Channel::Mic, "edge", 0, 1));
    assert_eq!(
        buf.text_since(121_000, 120_000),
        "Me: edge",
        "exactly on the cutoff"
    );
    assert_eq!(
        buf.text_since(121_001, 120_000),
        "",
        "one millisecond older than the window"
    );
}

#[test]
fn a_window_longer_than_the_clock_value_includes_everything() {
    let mut buf = CaptionBuffer::default();
    buf.push(turn(0, Channel::Mic, "a", 0, 1));
    assert_eq!(buf.text_since(5_000, 120_000), "Me: a");
    assert_eq!(buf.text_since(0, u64::MAX), "Me: a");
}

#[test]
fn a_zero_length_window_keeps_only_turns_from_this_instant_on() {
    let mut buf = CaptionBuffer::default();
    buf.push(turn(9_999, Channel::Mic, "before", 0, 1));
    buf.push(turn(10_000, Channel::System, "now", 0, 1));
    assert_eq!(buf.text_since(10_000, 0), "Client: now");
}

#[test]
fn text_lists_turns_oldest_first_labelled_by_speaker() {
    let mut buf = CaptionBuffer::default();
    buf.push(turn(10, Channel::Mic, "How are you?", 0, 1));
    buf.push(turn(20, Channel::System, "Fine, thanks.", 2, 3));
    buf.push(turn(30, Channel::Mic, "Great.\nSee you.", 4, 5));
    assert_eq!(
        buf.text_since(40, 1_000),
        "Me: How are you?\nClient: Fine, thanks.\nMe: Great.\nSee you."
    );
}

#[test]
fn the_two_minute_copy_scenario() {
    // 90 turns, one every two seconds for three minutes of wall clock; "copy last 2 minutes" at the end.
    let mut buf = CaptionBuffer::default();
    for n in 0..90u64 {
        buf.push(turn(
            n * 2_000,
            if n % 2 == 0 {
                Channel::Mic
            } else {
                Channel::System
            },
            &format!("line {n}"),
            n * 2_000,
            n * 2_000 + 1_500,
        ));
    }
    let now = 180_000;
    let text = buf.text_since(now, 120_000);
    let lines: Vec<&str> = text.lines().collect();
    assert_eq!(lines.len(), 60, "turns finalised from 60 s up to 178 s");
    assert_eq!(lines[0], "Me: line 30");
    assert_eq!(*lines.last().unwrap(), "Client: line 89");
}

// ---- Buffer: capacity ------------------------------------------------------------------------------------------

#[test]
fn the_buffer_keeps_the_newest_five_thousand_turns() {
    let mut buf = CaptionBuffer::default();
    for n in 0..5_001u64 {
        buf.push(turn(n, Channel::Mic, &format!("t{n}"), n, n + 1));
    }
    assert_eq!(buf.len(), 5_000);
    let text = buf.text_since(10_000, 100_000);
    assert!(
        !text.contains("Me: t0\n") && !text.starts_with("Me: t0"),
        "the oldest turn is evicted"
    );
    assert!(text.starts_with("Me: t1\n"));
    assert!(text.ends_with("Me: t5000"));
    buf.clear();
    assert!(buf.is_empty());
    assert_eq!(buf.text_since(10_000, 100_000), "");
}

// ---- Buffer: live transcript segments --------------------------------------------------------------------------

#[test]
fn segments_use_server_channel_numbers_and_speaker_names() {
    let mut buf = CaptionBuffer::default();
    buf.push(turn(1, Channel::Mic, "Hello.", 250, 2_600));
    buf.push(turn(2, Channel::System, "Hi.", 2_700, 3_100));
    let segments = buf.segments();
    assert_eq!(segments.len(), 2);
    assert_eq!(
        (
            segments[0].channel,
            segments[0].speaker.as_str(),
            segments[0].start_ms,
            segments[0].end_ms
        ),
        (1, "Me", 250, 2_600)
    );
    assert_eq!(
        (segments[1].channel, segments[1].speaker.as_str()),
        (2, "Client")
    );
    assert!(segments.iter().all(|s| s.words.is_empty()));
    assert_eq!(
        serde_json::to_value(&segments[0]).unwrap(),
        json!({"channel": 1, "speaker": "Me", "start_ms": 250, "end_ms": 2600, "text": "Hello.", "words": []})
    );
}

#[test]
fn segments_are_sorted_by_start_time_even_when_turns_finish_out_of_order() {
    let mut buf = CaptionBuffer::default();
    // The Client turn began first but its final arrived after the Me turn that began later.
    buf.push(turn(5_000, Channel::Mic, "second", 4_000, 4_800));
    buf.push(turn(5_200, Channel::System, "first", 1_000, 3_900));
    let order: Vec<&str> = buf
        .segments()
        .iter()
        .map(|s| if s.channel == 2 { "first" } else { "second" })
        .collect();
    assert_eq!(order, ["first", "second"]);
}

#[test]
fn simultaneous_speech_lists_me_before_client() {
    let mut buf = CaptionBuffer::default();
    buf.push(turn(1, Channel::System, "c", 1_000, 2_000));
    buf.push(turn(2, Channel::Mic, "m", 1_000, 2_000));
    assert_eq!(
        buf.segments().iter().map(|s| s.channel).collect::<Vec<_>>(),
        [1, 2]
    );
}

#[test]
fn a_turn_that_ends_before_it_starts_is_clamped_so_the_server_accepts_it() {
    let mut buf = CaptionBuffer::default();
    buf.push(turn(1, Channel::Mic, "x", 5_000, 4_000));
    let s = &buf.segments()[0];
    assert_eq!((s.start_ms, s.end_ms), (5_000, 5_000));
}

#[test]
fn segments_do_not_consume_the_buffer() {
    let mut buf = CaptionBuffer::default();
    buf.push(turn(1, Channel::Mic, "x", 0, 1));
    assert_eq!(buf.segments().len(), 1);
    assert_eq!(buf.segments().len(), 1);
    assert_eq!(buf.len(), 1);
}

#[test]
#[ignore = "A6.DR11: live turns without provider timing must keep the order of the conversation"]
fn turns_without_provider_timing_keep_the_order_of_the_conversation() {
    // ElevenLabs commits carry no timestamps, so every turn is recorded with the same start and end (the offset of
    // the stream). The live transcript must still read as a conversation, not as all of Me followed by all of Client.
    let mut buf = CaptionBuffer::default();
    let offset = 0;
    for (n, (channel, text)) in [
        (Channel::Mic, "Hi, can you hear me?"),
        (Channel::System, "Yes, loud and clear."),
        (Channel::Mic, "Great, let's start."),
        (Channel::System, "Go ahead."),
    ]
    .into_iter()
    .enumerate()
    {
        buf.push(turn(
            1_000 + n as u64 * 3_000,
            channel,
            text,
            offset,
            offset,
        ));
    }
    let texts: Vec<String> = buf.segments().into_iter().map(|s| s.text).collect();
    assert_eq!(
        texts,
        [
            "Hi, can you hear me?",
            "Yes, loud and clear.",
            "Great, let's start.",
            "Go ahead."
        ]
    );
}
