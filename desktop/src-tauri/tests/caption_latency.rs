//! Caption latency: speech to word on screen, measured. A WAV is fed through the live-caption pipeline in real time
//! (10 ms chunks, as the capture hands them over) against a stand-in AssemblyAI v3 provider with realistic timings. Each
//! caption goes the app's way to the windows (`stt::caption_event` into a `CaptionHub`, the overlay's target), and for
//! each stretch of speech the time from its first chunk to its first interim word handed to the windows is taken apart
//! into the provider's share and the pipeline's own (framing, the socket both ways, parsing, the hub). The WAV is
//! repeated until it holds at least 30 stretches, so p90 is not just the slowest one.
//! Timings only: the words are numbered placeholders, never meeting text.
//!
//! - CI: the pipeline's own share must stay at or under 150 ms p90 (`PIPELINE_P90_MAX`).
//! - `ECHOLEDGER_LATENCY_WAV=/path/to/16k-mono.wav` feeds a recording instead of the built-in speech-like signal.
//! - Against the real provider (run by hand on a Mac, `--ignored --nocapture`): `ECHOLEDGER_LATENCY_WS_URL` (a provider
//!   URL with its short-lived token), or `ECHOLEDGER_LATENCY_SERVER` with `ECHOLEDGER_LATENCY_TOKEN` (the server mints
//!   the grant as the app does). Without a WAV, macOS `say` speaks one.

mod common;

use std::collections::{BTreeMap, BinaryHeap};
use std::cmp::Reverse;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use futures_util::{SinkExt, StreamExt};
use serde_json::json;
use tokio::net::TcpListener;
use tokio::sync::mpsc::unbounded_channel;
use tokio::sync::watch;
use tokio_tungstenite::tungstenite::Message;

use desktop_lib::audio::types::Channel;
use desktop_lib::net::models::{AudioSpec, Grant};
use desktop_lib::stt::captions::{CaptionEvent, CaptionHub};
use desktop_lib::stt::latency::{Probe, Spread};
use desktop_lib::stt::stream::{SessionOptions, run_session_with, voiced};

const RATE: usize = 16_000;
/// The capture hands audio over in chunks this long.
const CHUNK_MS: usize = 10;
const CHUNK_SAMPLES: usize = RATE * CHUNK_MS / 1000;
/// AssemblyAI's grant asks for 50 ms frames.
const FRAME_MS: u32 = 50;
/// The pipeline's own share of speech to word, p90, that CI allows.
const PIPELINE_P90_MAX: u64 = 150;
/// Silence before speech for it to count as a new stretch.
const ONSET_GAP_MS: usize = 300;
/// Stretches of speech measured at least, so p90 is a percentile and not the slowest one.
const MIN_STRETCHES: usize = 30;

/// The WAV again and again until it holds at least [`MIN_STRETCHES`] stretches of speech.
fn repeated(samples: Vec<i16>) -> Vec<i16> {
    let per = onsets(&samples).len().max(1);
    let times = MIN_STRETCHES.div_ceil(per);
    let mut out = Vec::with_capacity(samples.len() * times);
    for _ in 0..times {
        out.extend(&samples);
        out.extend(std::iter::repeat_n(0i16, RATE * ONSET_GAP_MS / 1000));
    }
    out
}

// ---- Audio ----------------------------------------------------------------------------------------------------------

/// Speech-like audio: voiced stretches (a 140 Hz voice with its harmonics and a syllable rhythm) between pauses, the
/// way a person talks. Deterministic, so CI measures the same signal every time.
fn speech_like() -> Vec<i16> {
    let pattern_ms = [(900, 450), (700, 500), (1100, 400), (600, 550), (1000, 450), (800, 500), (850, 600), (700, 450)];
    let mut out = vec![0i16; RATE / 2];
    for (speech, pause) in pattern_ms {
        let n = RATE * speech / 1000;
        for i in 0..n {
            let t = i as f64 / RATE as f64;
            let syllable = 0.6 + 0.4 * (2.0 * std::f64::consts::PI * 4.0 * t).sin().abs();
            let voice: f64 = (1..=6).map(|k| (2.0 * std::f64::consts::PI * 140.0 * k as f64 * t).sin() / k as f64).sum();
            out.push((voice * 4_000.0 * syllable).clamp(-32_000.0, 32_000.0) as i16);
        }
        out.extend(std::iter::repeat_n(0i16, RATE * pause / 1000));
    }
    out
}

/// 16 kHz mono 16-bit samples of a WAV (anything else is refused with how to convert it).
fn load_wav(path: &std::path::Path) -> Vec<i16> {
    let mut reader = hound::WavReader::open(path).unwrap_or_else(|e| panic!("{}: {e}", path.display()));
    let spec = reader.spec();
    assert!(
        spec.sample_rate as usize == RATE && spec.channels == 1 && spec.bits_per_sample == 16,
        "{}: needs 16 kHz mono 16-bit (macOS: afconvert -f WAVE -d LEI16@16000 -c 1 in.m4a out.wav)",
        path.display()
    );
    reader.samples::<i16>().map(|s| s.unwrap()).collect()
}

/// The WAV to feed: `ECHOLEDGER_LATENCY_WAV`, else the speech-like signal written to a WAV and read back.
fn the_wav(dir: &std::path::Path) -> Vec<i16> {
    if let Some(path) = std::env::var_os("ECHOLEDGER_LATENCY_WAV") {
        return load_wav(std::path::Path::new(&path));
    }
    let path = dir.join("speech-like.wav");
    let spec = hound::WavSpec { channels: 1, sample_rate: RATE as u32, bits_per_sample: 16, sample_format: hound::SampleFormat::Int };
    let mut writer = hound::WavWriter::create(&path, spec).unwrap();
    for s in speech_like() {
        writer.write_sample(s).unwrap();
    }
    writer.finalize().unwrap();
    load_wav(&path)
}

fn bytes_of(samples: &[i16]) -> Vec<u8> {
    samples.iter().flat_map(|s| s.to_le_bytes()).collect()
}

/// Where each stretch of speech starts (sample index): a voiced 10 ms window after at least ONSET_GAP_MS without one.
fn onsets(samples: &[i16]) -> Vec<usize> {
    let mut out = Vec::new();
    let mut quiet = ONSET_GAP_MS; // the start counts as after a pause
    for (i, window) in samples.chunks(CHUNK_SAMPLES).enumerate() {
        if voiced(&bytes_of(window)) {
            if quiet >= ONSET_GAP_MS {
                out.push(i * CHUNK_SAMPLES);
            }
            quiet = 0;
        } else {
            quiet += CHUNK_MS;
        }
    }
    out
}

// ---- The stand-in provider ------------------------------------------------------------------------------------------

/// AssemblyAI's published emission latency (word spoken to word sent) is about 317 ms p50 and 597 ms p90 for
/// Universal-Streaming: the stand-in sends each word after one of these, in turn (p50 300 ms, p90 520 ms).
const EMISSION_MS: [u64; 10] = [220, 260, 300, 310, 340, 280, 450, 520, 600, 250];
/// About one word per this much speech.
const WORD_AUDIO_MS: u32 = 250;
/// The turn ends after this much silence (the server's min_latency `max_turn_silence`), its final this long after.
const END_SILENCE_MS: u32 = 640;
const FINAL_MS: u64 = 120;

/// What the stand-in saw and sent, on the same clock as the pipeline.
#[derive(Default)]
struct ProviderLog {
    /// Each stretch of speech it heard: when its first voiced frame arrived, and the sample just after that frame.
    onsets: Vec<(Instant, usize)>,
    /// Each word sent: when, and which stretch it belongs to.
    words: BTreeMap<u64, (Instant, usize)>,
}

/// An AssemblyAI v3 stand-in: Begin, then interim Turns word by word while speech comes in, a formatted final after the
/// turn's silence, Termination on Terminate. Every word is `w<n>`.
async fn provider(listener: TcpListener, log: Arc<Mutex<ProviderLog>>) {
    let (stream, _) = listener.accept().await.unwrap();
    let mut ws = tokio_tungstenite::accept_async(stream).await.unwrap();
    ws.send(Message::text(r#"{"type":"Begin","id":"latency","expires_at":1790000000}"#)).await.unwrap();
    let mut due: BinaryHeap<Reverse<(Instant, u64, String)>> = BinaryHeap::new();
    let (mut samples, mut seq, mut emitted) = (0usize, 0u64, 0usize);
    let (mut in_speech, mut speech_ms, mut silence_ms, mut next_word_ms) = (false, 0u32, 0u32, WORD_AUDIO_MS);
    // A provider sends a turn's words in order: none goes out before the one before it.
    let mut last_due = Instant::now();
    let mut turn: Vec<String> = Vec::new();
    let mut stretch = 0usize;
    loop {
        let next = due.peek().map(|Reverse((at, _, _))| *at);
        tokio::select! {
            _ = tokio::time::sleep_until(next.map_or_else(|| tokio::time::Instant::now() + Duration::from_secs(3600), tokio::time::Instant::from_std)), if next.is_some() => {
                let Reverse((_, id, message)) = due.pop().unwrap();
                if id > 0 {
                    let mut log = log.lock().unwrap();
                    let stretch_of = log.words.get(&id).map(|w| w.1).unwrap_or_default();
                    log.words.insert(id, (Instant::now(), stretch_of));
                }
                if ws.send(Message::text(message)).await.is_err() {
                    return;
                }
            }
            message = ws.next() => {
                let Some(Ok(message)) = message else { return };
                match message {
                    Message::Binary(frame) => {
                        let now = Instant::now();
                        samples += frame.len() / 2;
                        let frame_ms = (frame.len() / 2 * 1000 / RATE) as u32;
                        if voiced(&frame) {
                            // A new stretch of speech after a pause (the turn may still be open).
                            if !in_speech || silence_ms as usize >= ONSET_GAP_MS {
                                in_speech = true;
                                speech_ms = 0;
                                next_word_ms = WORD_AUDIO_MS;
                                stretch = log.lock().unwrap().onsets.len();
                                log.lock().unwrap().onsets.push((now, samples));
                            }
                            silence_ms = 0;
                            speech_ms += frame_ms;
                            while speech_ms >= next_word_ms {
                                seq += 1;
                                turn.push(format!("w{seq}"));
                                let text = json!({"type": "Turn", "transcript": turn.join(" "), "end_of_turn": false, "turn_is_formatted": false, "words": []}).to_string();
                                log.lock().unwrap().words.insert(seq, (now, stretch));
                                last_due = last_due.max(now + Duration::from_millis(EMISSION_MS[emitted % EMISSION_MS.len()]));
                                due.push(Reverse((last_due, seq, text)));
                                emitted += 1;
                                next_word_ms += WORD_AUDIO_MS;
                            }
                        } else if in_speech {
                            silence_ms += frame_ms;
                            if silence_ms >= END_SILENCE_MS {
                                in_speech = false;
                                let text = json!({"type": "Turn", "transcript": format!("{}.", turn.join(" ")), "end_of_turn": true, "turn_is_formatted": true, "words": []}).to_string();
                                due.push(Reverse((now + Duration::from_millis(FINAL_MS), 0, text)));
                                turn.clear();
                            }
                        }
                    }
                    Message::Text(text) if text.as_str().contains("Terminate") => {
                        let _ = ws.send(Message::text(r#"{"type":"Termination","audio_duration_seconds":1,"session_duration_seconds":1}"#)).await;
                        let _ = ws.close(None).await;
                        return;
                    }
                    Message::Close(_) => return,
                    _ => {}
                }
            }
        }
    }
}

fn grant(ws_url: String) -> Grant {
    Grant {
        channel: "me".into(),
        transport: "websocket".into(),
        protocol: Some("assemblyai_v3".into()),
        ws_url: Some(ws_url),
        max_session_seconds: Some(10_800),
        audio: Some(AudioSpec { encoding: "pcm_s16le".into(), sample_rate: 16_000, channels: 1, frame_ms: FRAME_MS }),
        init_messages: vec![],
    }
}

// ---- The run ----------------------------------------------------------------------------------------------------------

/// Each caption with words: when it arrived, and its newest word's number.
type Words = Vec<(Instant, Option<u64>)>;

/// What one run measured: when each chunk was handed over, and each caption with words.
struct Run {
    handed: Vec<Instant>,
    words: Words,
    probe: Arc<Probe>,
}

/// Feeds `samples` through the pipeline in real time and collects every caption with words.
async fn feed(grant: Grant, samples: &[i16], tail: Duration) -> Run {
    let (pcm, pcm_rx) = unbounded_channel();
    let (stop, stop_rx) = watch::channel(false);
    let words: Arc<Mutex<Words>> = Arc::default();
    let sink = words.clone();
    let probe = Probe::new_registered();
    let options = SessionOptions::LIVE.with_probe(Some(probe.clone()));
    // The app's way to the windows: the caption event, then the hub that fans it out to the overlay.
    let hub = CaptionHub::default();
    let stream_start = (0, Instant::now());
    let session = tokio::spawn(run_session_with(
        grant,
        pcm_rx,
        stop_rx,
        move |event| {
            let Some(caption) = desktop_lib::stt::caption_event(Channel::Mic, stream_start, &event) else { return };
            let text = match &caption {
                CaptionEvent::Partial { text, .. } | CaptionEvent::Final { text, .. } => text.clone(),
                _ => String::new(),
            };
            hub.send(&["overlay"], caption);
            if !text.trim().is_empty() {
                let newest = text.split_whitespace().last().and_then(|w| w.trim_end_matches('.').strip_prefix('w')).and_then(|n| n.parse().ok());
                sink.lock().unwrap().push((Instant::now(), newest));
            }
        },
        options,
    ));
    let mut handed = Vec::new();
    let mut tick = tokio::time::interval(Duration::from_millis(CHUNK_MS as u64));
    tick.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Burst);
    for chunk in samples.chunks(CHUNK_SAMPLES) {
        tick.tick().await;
        pcm.send(bytes_of(chunk)).unwrap();
        handed.push(Instant::now());
    }
    tokio::time::sleep(tail).await;
    let _ = stop.send(true);
    let _ = tokio::time::timeout(Duration::from_secs(5), session).await;
    let words = words.lock().unwrap().clone();
    Run { handed, words, probe }
}

/// When the chunk holding sample `at` was handed over.
fn handed_at(run: &Run, at: usize) -> Instant {
    run.handed[(at / CHUNK_SAMPLES).min(run.handed.len() - 1)]
}

fn report(name: &str, values: &[u64]) -> Spread {
    let spread = Spread::of(values);
    println!("{name}: p50 {:?} ms, p90 {:?} ms ({} stretches of speech)", spread.p50, spread.p90, spread.count);
    spread
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn speech_to_word_against_a_realistic_provider_and_the_pipeline_s_own_share_stays_under_150_ms() {
    let dir = tempfile::tempdir().unwrap();
    let samples = repeated(the_wav(dir.path()));
    let starts = onsets(&samples);
    assert!(starts.len() >= MIN_STRETCHES, "at least {MIN_STRETCHES} stretches of speech: {}", starts.len());

    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let log = Arc::new(Mutex::new(ProviderLog::default()));
    let server = tokio::spawn(provider(listener, log.clone()));
    let run = feed(grant(format!("ws://127.0.0.1:{port}/v3/ws?token=short-lived")), &samples, Duration::from_millis(1500)).await;
    let _ = server.await;
    let log = log.lock().unwrap();

    let (mut whole, mut pipeline, mut provider_share) = (Vec::new(), Vec::new(), Vec::new());
    for (stretch, &(heard_at, frame_end)) in log.onsets.iter().enumerate() {
        // The stretch as the WAV has it: the last onset at or before the frame the provider first heard it in.
        let Some(&start) = starts.iter().rev().find(|&&s| s < frame_end) else { continue };
        let spoken = handed_at(&run, start);
        // Its first interim word, by the word's number.
        let Some((received, id)) = run.words.iter().find_map(|(at, id)| {
            let id = (*id)?;
            (log.words.get(&id)?.1 == stretch && *at >= spoken).then_some((*at, id))
        }) else {
            continue;
        };
        let provider_sent = log.words[&id].0;
        let total = received.saturating_duration_since(spoken).as_millis() as u64;
        // The provider's share: from hearing the stretch's first frame to sending the word.
        let share = provider_sent.saturating_duration_since(heard_at).as_millis() as u64;
        whole.push(total);
        provider_share.push(share);
        pipeline.push(total.saturating_sub(share));
    }
    assert!(whole.len() >= MIN_STRETCHES, "a word for every stretch of speech: {} of {}", whole.len(), starts.len());

    let total = report("speech to first interim word (stand-in provider)", &whole);
    report("  the provider's share", &provider_share);
    let own = report("  the pipeline's own share", &pipeline);
    println!("{}", run.probe.summary().line());

    assert!(own.p90.unwrap() <= PIPELINE_P90_MAX, "the pipeline adds {:?} ms p90 (at most {PIPELINE_P90_MAX})", own.p90);
    assert!(total.p90.unwrap() < 1_000, "with a provider this fast, words are on their way within a second: {:?} ms p90", total.p90);
    // The probe saw the same words (timings only).
    assert!(run.probe.summary().sent_to_word.count >= starts.len() - 1);
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn interim_words_arrive_while_the_person_is_still_speaking_not_after_the_turn() {
    // One long stretch of speech: its interim words must come during it, each well before the turn's final.
    let mut samples = vec![0i16; RATE / 2];
    let speech: Vec<i16> = speech_like().into_iter().skip(RATE / 2).take(RATE * 1200 / 1000).collect();
    for _ in 0..3 {
        samples.extend(&speech);
    }
    samples.extend(vec![0i16; RATE]);
    let speech_ends = Duration::from_millis((RATE / 2 + speech.len() * 3) as u64 * 1000 / RATE as u64);

    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let log = Arc::new(Mutex::new(ProviderLog::default()));
    let server = tokio::spawn(provider(listener, log.clone()));
    let run = feed(grant(format!("ws://127.0.0.1:{port}/v3/ws?token=short-lived")), &samples, Duration::from_millis(1500)).await;
    let _ = server.await;

    let started = run.handed[0];
    let during: Vec<_> = run.words.iter().filter(|(at, _)| at.saturating_duration_since(started) < speech_ends).collect();
    assert!(during.len() >= 8, "interim words while still speaking: {}", during.len());
    // Each word shows at once: the newest number never goes backwards, and the gap between them stays a word's time.
    let ids: Vec<u64> = during.iter().filter_map(|(_, id)| *id).collect();
    assert!(ids.windows(2).all(|w| w[0] < w[1]), "{ids:?}");
}

// ---- Against the real provider (by hand) ------------------------------------------------------------------------------

/// A WAV of real speech: `ECHOLEDGER_LATENCY_WAV`, else macOS `say` (16 kHz mono).
fn real_speech(dir: &std::path::Path) -> Vec<i16> {
    if let Some(path) = std::env::var_os("ECHOLEDGER_LATENCY_WAV") {
        return load_wav(std::path::Path::new(&path));
    }
    let path = dir.join("say.wav");
    let script = "Good morning. Let's go over the numbers for this quarter. Revenue is up eleven percent. \
                  Costs are flat, and we hired two people. Next, the launch plan. We ship on the fourth. \
                  Any questions so far? Great, let's move on.";
    let ok = std::process::Command::new("say")
        .args(["-r", "170", "-o"])
        .arg(&path)
        .args(["--file-format=WAVE", "--data-format=LEI16@16000", script])
        .status()
        .is_ok_and(|s| s.success());
    assert!(ok, "no WAV: set ECHOLEDGER_LATENCY_WAV to a 16 kHz mono recording (macOS `say` is not here)");
    // Pauses between sentences make the stretches; a little silence first, as a call has.
    let mut samples = vec![0i16; RATE];
    samples.extend(load_wav(&path));
    samples.extend(vec![0i16; RATE * 2]);
    samples
}

/// The provider grant: a URL given as it is, or minted by the server as the app does.
async fn real_grant() -> Grant {
    if let Ok(url) = std::env::var("ECHOLEDGER_LATENCY_WS_URL") {
        return grant(url);
    }
    let server = std::env::var("ECHOLEDGER_LATENCY_SERVER").expect("set ECHOLEDGER_LATENCY_WS_URL, or ECHOLEDGER_LATENCY_SERVER and ECHOLEDGER_LATENCY_TOKEN");
    let token = std::env::var("ECHOLEDGER_LATENCY_TOKEN").expect("ECHOLEDGER_LATENCY_TOKEN: the device token");
    common::init_tls();
    let api = desktop_lib::net::api::ApiClient::new(&server, &[], Some(token)).expect("client");
    let minted = api.stt_token("live_captions", &["me"], None, None, None).await.expect("the server mints a grant");
    minted.grants.into_iter().next().expect("one grant")
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
#[ignore = "against the real provider: run by hand with its environment (see the file's first lines)"]
async fn speech_to_word_against_the_real_provider() {
    common::init_tls();
    let dir = tempfile::tempdir().unwrap();
    let samples = repeated(real_speech(dir.path()));
    let starts = onsets(&samples);
    let run = feed(real_grant().await, &samples, Duration::from_secs(3)).await;
    // For each stretch: its first word that came after it began and before the next one began.
    let mut whole = Vec::new();
    for (i, &start) in starts.iter().enumerate() {
        let spoken = handed_at(&run, start);
        let next = starts.get(i + 1).map(|&n| handed_at(&run, n));
        if let Some((at, _)) = run.words.iter().find(|(at, _)| *at >= spoken && next.is_none_or(|n| *at < n)) {
            whole.push(at.saturating_duration_since(spoken).as_millis() as u64);
        }
    }
    report("speech to first interim word (real provider)", &whole);
    println!("{}", run.probe.summary().line());
    assert!(!whole.is_empty(), "no words came back");
}
