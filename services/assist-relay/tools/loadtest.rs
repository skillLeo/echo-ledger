//! Load test for the Live Assist relay (LOADTEST.md has the results and how to read them).
//!
//! Starts the release relay binary on 127.0.0.1 with the mocked models (`RELAY_PROVIDERS=mock`, no server, so no
//! request leaves this machine), opens many WebSocket sessions at once, and runs a short interview in each: the
//! context, then questions from the other person and my replies. It measures what the plan's targets are about:
//!
//! - concurrent sessions: every session connects, gets `ready` and an answer to every question, with no errors;
//! - end of turn -> first token on the app, p50 and p95 per mode, against L9's targets (mocked models);
//! - relay overhead p95 (the relay's own `latency.relay_overhead_ms`, and the app-side gap: the app's first-token
//!   time minus the model's share), against L2/L9's 30 ms;
//! - memory per session: the relay's resident memory with every session open, over the idle relay;
//! - clean shutdown: SIGTERM with every session open; each app gets close 1012 and the relay exits 0 in time;
//! - nothing anyone said in the relay's log, and no suggestion that no question asked for.
//!
//!   cargo build --release
//!   target/release/assist-relay-loadtest                      # 50 sessions, 8 questions each
//!   target/release/assist-relay-loadtest --sessions 200 --questions 5
//!
//! Options: --sessions N, --questions N, --modes fast,balanced,deep, --think-ms N (pause between questions),
//! --ramp-ms N (connects spread over this long), --relay PATH (the relay binary; default: next to this one),
//! --relay-threads N (the relay's worker threads, e.g. 2 for a 2-vCPU VPS).
//! Exit code 0 when every target passes. Unix only (resident memory from `ps`, SIGTERM from `kill`).

use std::collections::BTreeMap;
use std::collections::hash_map::RandomState;
use std::hash::BuildHasher;
use std::io::{BufRead, BufReader};
use std::net::{SocketAddr, TcpListener};
use std::path::PathBuf;
use std::process::{Child, Command, ExitCode, Stdio};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use assist_relay::auth::{AUDIENCE, BudgetClaim, Claims, TokenSigner};
use assist_relay::config::Secret;
use assist_relay::domain::{Mode, Plan};
use assist_relay::time::unix_now;
use futures_util::{SinkExt, StreamExt};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use tokio::sync::{Barrier, watch};
use tokio::time::Instant;
use tokio_tungstenite::tungstenite::Message;
use tokio_tungstenite::tungstenite::client::IntoClientRequest;

const MODELS: [&str; 4] = ["claude-haiku-5-5", "claude-sonnet-5-5", "claude-opus-5-5", "openai/gpt-oss-120b"];

/// Short questions: under the upgrade's 14 words and none of its phrases, so each gets exactly one suggestion.
const QUESTIONS: [&str; 8] = [
    "What is your notice period?",
    "Do you know Kotlin?",
    "When can you start?",
    "Can you work in our time zone?",
    "What salary do you expect?",
    "Have you used Postgres in production?",
    "Are you open to relocation?",
    "Which cloud have you deployed on?",
];
const REPLY: &str = "One month, and I can start in March.";
/// A marker in the CV: it must never reach the relay's log.
const CV_MARKER: &str = "Zanzibar-payments-ledger";

/// L9: end of turn -> first word on screen p50, mocked.
fn ttft_target_ms(mode: &str) -> Option<f64> {
    match mode {
        "fast" => Some(1500.0),
        "balanced" => Some(2000.0),
        "deep" => Some(3000.0),
        _ => None,
    }
}
const OVERHEAD_P95_TARGET_MS: f64 = 30.0;
/// The relay waits up to 5 s for sessions to close after SIGTERM.
const SHUTDOWN_TARGET: Duration = Duration::from_secs(6);

struct Options {
    sessions: usize,
    questions: usize,
    modes: Vec<String>,
    think: Duration,
    ramp: Duration,
    relay: PathBuf,
    /// The relay's worker threads (`TOKIO_WORKER_THREADS`), to approximate a small VPS; default: one per core.
    relay_threads: Option<u64>,
}

fn options() -> Result<Options, String> {
    let mut o = Options {
        sessions: 50,
        questions: 8,
        modes: vec!["fast".into(), "balanced".into(), "deep".into()],
        think: Duration::from_millis(800),
        ramp: Duration::from_millis(2000),
        relay: std::env::current_exe().map_err(|e| e.to_string())?.with_file_name("assist-relay"),
        relay_threads: None,
    };
    let mut args = std::env::args().skip(1);
    while let Some(flag) = args.next() {
        let mut value = || args.next().ok_or(format!("{flag} needs a value"));
        let number = |v: String| v.parse::<u64>().map_err(|_| format!("{flag}: not a number"));
        match flag.as_str() {
            "--sessions" => o.sessions = usize::try_from(number(value()?)?).map_err(|e| e.to_string())?,
            "--questions" => o.questions = usize::try_from(number(value()?)?).map_err(|e| e.to_string())?,
            "--modes" => o.modes = value()?.split(',').map(str::to_owned).collect(),
            "--think-ms" => o.think = Duration::from_millis(number(value()?)?),
            "--ramp-ms" => o.ramp = Duration::from_millis(number(value()?)?),
            "--relay" => o.relay = PathBuf::from(value()?),
            "--relay-threads" => o.relay_threads = Some(number(value()?)?),
            _ => return Err(format!("unknown option {flag}")),
        }
    }
    if o.sessions == 0 || o.questions == 0 || o.modes.is_empty() {
        return Err("sessions, questions and modes must not be empty".into());
    }
    Ok(o)
}

/// A one-off secret for this run, from the OS-seeded hasher keys. It lives in this process and the relay's
/// environment only.
fn run_secret() -> String {
    let mut digest = Sha256::new();
    for i in 0..8u64 {
        digest.update(RandomState::new().hash_one(i).to_le_bytes());
    }
    digest.update(std::process::id().to_le_bytes());
    digest.finalize().iter().map(|b| format!("{b:02x}")).collect()
}

fn token(signer: &TokenSigner, session: &str) -> String {
    let now = unix_now();
    signer.sign(&Claims {
        iss: "echoledger".into(),
        aud: AUDIENCE.into(),
        sub: format!("user-{session}"),
        ws: Some("ws-load".into()),
        sid: session.into(),
        plan: Plan::Ultra,
        modes: Mode::LADDER.to_vec(),
        models: MODELS.iter().map(|m| (*m).to_owned()).collect(),
        budget: BudgetClaim { seconds_left: 36_000, cents_left: 100_000 },
        practice: false,
        iat: now,
        exp: now + 900,
        jti: Some(format!("load-{session}-{now}")),
    })
}

/// The relay's resident memory in KiB.
fn rss_kib(pid: u32) -> Option<u64> {
    let out = Command::new("ps").args(["-o", "rss=", "-p", &pid.to_string()]).output().ok()?;
    String::from_utf8_lossy(&out.stdout).trim().parse().ok()
}

fn free_port() -> std::io::Result<SocketAddr> {
    TcpListener::bind("127.0.0.1:0")?.local_addr()
}

/// One answered question.
struct Sample {
    mode: String,
    /// Sending `turn_final` -> the first `token`, on the app's clock.
    ttft_ms: f64,
    /// The relay's `latency.relay_overhead_ms`.
    relay_overhead_ms: f64,
    /// The app's first-token time minus the model's share as the relay reports it: the relay plus both sockets.
    app_overhead_ms: f64,
    total_ms: f64,
}

#[derive(Default)]
struct SessionResult {
    ready: bool,
    samples: Vec<Sample>,
    errors: Vec<String>,
    unexpected: u64,
    close_code: Option<u16>,
}

fn percentile(values: &mut [f64], p: f64) -> f64 {
    if values.is_empty() {
        return f64::NAN;
    }
    values.sort_by(f64::total_cmp);
    let rank = ((p / 100.0) * values.len() as f64).ceil() as usize;
    values[rank.clamp(1, values.len()) - 1]
}

type Socket = tokio_tungstenite::WebSocketStream<tokio_tungstenite::MaybeTlsStream<tokio::net::TcpStream>>;

enum Frame {
    Json(Value),
    Closed(u16),
}

async fn receive(socket: &mut Socket, wait: Duration) -> Result<Frame, String> {
    loop {
        match tokio::time::timeout(wait, socket.next()).await {
            Err(_) => return Err("no message in time".into()),
            Ok(Some(Ok(Message::Text(text)))) => {
                return serde_json::from_str(text.as_str()).map(Frame::Json).map_err(|_| "a frame that is not JSON".into());
            }
            Ok(Some(Ok(Message::Close(frame)))) => return Ok(Frame::Closed(frame.map_or(1005, |f| u16::from(f.code)))),
            Ok(Some(Ok(_))) => {}
            Ok(Some(Err(_)) | None) => return Ok(Frame::Closed(1006)),
        }
    }
}

async fn send(socket: &mut Socket, value: Value) -> Result<(), String> {
    socket.send(Message::Text(value.to_string().into())).await.map_err(|e| format!("send: {e}"))
}

struct Run {
    addr: SocketAddr,
    token: String,
    session: String,
    mode: String,
    questions: usize,
    think: Duration,
    start_after: Duration,
}

/// One app: connects, sends the context, asks the questions, then stays connected until the relay closes it.
async fn run_session(run: Run, all_done: Arc<Barrier>, mut stopping: watch::Receiver<bool>) -> SessionResult {
    let mut result = SessionResult::default();
    tokio::time::sleep(run.start_after).await;
    let mut socket = match connect(&run).await {
        Ok(socket) => socket,
        Err(error) => {
            result.errors.push(format!("{}: {error}", run.session));
            all_done.wait().await;
            return result;
        }
    };
    if let Err(error) = converse(&run, &mut socket, &mut result).await {
        result.errors.push(format!("{}: {error}", run.session));
    }
    all_done.wait().await;
    // Hold the connection until the relay shuts down, answering pings (the reader does that).
    let _ = stopping.wait_for(|stop| *stop).await;
    loop {
        match receive(&mut socket, Duration::from_secs(15)).await {
            Ok(Frame::Closed(code)) => {
                result.close_code = Some(code);
                break;
            }
            Ok(Frame::Json(_)) => {}
            Err(_) => break,
        }
    }
    result
}

async fn connect(run: &Run) -> Result<Socket, String> {
    let mut request = format!("ws://{}/v1/assist", run.addr).into_client_request().map_err(|e| e.to_string())?;
    let bearer = format!("Bearer {}", run.token).parse().map_err(|_| "header".to_owned())?;
    request.headers_mut().insert("authorization", bearer);
    // No Nagle on the app side either, as the apps should: the partial and the final go out back to back.
    let (socket, _) = tokio_tungstenite::connect_async_with_config(request, None, true).await.map_err(|e| format!("connect: {e}"))?;
    Ok(socket)
}

async fn converse(run: &Run, socket: &mut Socket, result: &mut SessionResult) -> Result<(), String> {
    match receive(socket, Duration::from_secs(10)).await? {
        Frame::Json(ready) if ready["type"] == "ready" => result.ready = true,
        Frame::Json(other) => return Err(format!("first message {}", other["type"])),
        Frame::Closed(code) => return Err(format!("closed {code} before ready")),
    }
    let cv = format!("Built and ran the {CV_MARKER} service: refunds, payouts, fraud checks and reports. ").repeat(60);
    send(
        socket,
        json!({"type": "context_update", "seq": 1,
            "brief": {"meeting_type": "interview", "title": "Backend developer", "counterpart": "Sara", "company": "Acme",
                      "goals": "Show the payments work", "to_discuss": "Notice period, start date"},
            "settings": {"mode": run.mode, "hint_level": "outline", "language_help": "off"},
            "documents": [{"id": "cv", "title": "CV", "text": cv}],
            "memory": "Second round; the role is remote.",
            "facts": [{"id": "f1", "text": "Me: four years of backend work"}]}),
    )
    .await?;
    // Let the context settle (and the cache warm) before the first question, as a real call does.
    tokio::time::sleep(Duration::from_millis(500)).await;
    let mut seq = 1u64;
    let mut audio_ms = 10_000u64;
    for q in 0..run.questions {
        let question = QUESTIONS[q % QUESTIONS.len()];
        let turn = format!("o-{q}");
        let words: Vec<&str> = question.split_whitespace().collect();
        let half = words[..words.len() / 2].join(" ");
        seq += 1;
        send(socket, json!({"type": "turn_partial", "seq": seq, "turn_id": turn, "channel": "other", "text": half,
            "language": "en", "start_ms": audio_ms, "silence_ms": 40}))
        .await?;
        seq += 1;
        let final_turn = json!({"type": "turn_final", "seq": seq, "turn_id": turn, "channel": "other", "text": question,
            "language": "en", "start_ms": audio_ms, "end_ms": audio_ms + 2500});
        let sent_at = Instant::now();
        send(socket, final_turn).await?;
        let sample = answer(socket, &turn, &run.mode, sent_at, result).await?;
        result.samples.push(sample);
        audio_ms += 3000;
        seq += 1;
        send(socket, json!({"type": "turn_final", "seq": seq, "turn_id": format!("m-{q}"), "channel": "me", "text": REPLY,
            "language": "en", "start_ms": audio_ms, "end_ms": audio_ms + 2000}))
        .await?;
        audio_ms += 3000;
        // My own channel must start nothing: anything arriving during the pause is counted.
        let pause_until = Instant::now() + run.think;
        while let Some(left) = pause_until.checked_duration_since(Instant::now()) {
            match receive(socket, left).await {
                Err(_) => break,
                Ok(Frame::Closed(code)) => return Err(format!("closed {code} mid-call")),
                Ok(Frame::Json(message)) => {
                    if message["type"] == "suggestion_start" {
                        result.unexpected += 1;
                    } else if message["type"] == "error" {
                        return Err(format!("error {}", message["code"]));
                    }
                }
            }
        }
    }
    Ok(())
}

/// Reads until the suggestion for `turn` ends; the first `token` is the app's first-token time.
async fn answer(socket: &mut Socket, turn: &str, mode: &str, sent_at: Instant, result: &mut SessionResult) -> Result<Sample, String> {
    let mut id: Option<String> = None;
    let mut first: Option<Instant> = None;
    loop {
        let message = match receive(socket, Duration::from_secs(15)).await? {
            Frame::Json(message) => message,
            Frame::Closed(code) => return Err(format!("closed {code} during a suggestion")),
        };
        let mine = id.as_deref().is_some_and(|id| message["suggestion_id"] == id);
        match message["type"].as_str() {
            Some("suggestion_start") if message["turn_id"] == turn && id.is_none() => {
                id = message["suggestion_id"].as_str().map(str::to_owned);
            }
            Some("suggestion_start") => result.unexpected += 1,
            Some("token") if mine => {
                first.get_or_insert_with(Instant::now);
            }
            Some("suggestion_end") if mine => {
                let first = first.ok_or("an answer without tokens")?;
                let latency = &message["latency"];
                let ttft_ms = first.saturating_duration_since(sent_at).as_secs_f64() * 1000.0;
                // The model's share of the wait for the first word shown, as the relay reports it: its time to the
                // first word, or to the last for an answer held until complete (Deep's Sonnet).
                let shown_ms = latency["end_of_turn_to_first_token_ms"].as_f64().ok_or("no first-token latency")?;
                let relay_ms = latency["relay_overhead_ms"].as_f64().ok_or("no relay overhead")?;
                let model_ms = shown_ms - relay_ms;
                return Ok(Sample {
                    mode: mode.to_owned(),
                    ttft_ms,
                    relay_overhead_ms: latency["relay_overhead_ms"].as_f64().ok_or("no relay overhead")?,
                    app_overhead_ms: ttft_ms - model_ms,
                    total_ms: latency["total_ms"].as_f64().unwrap_or(f64::NAN),
                });
            }
            Some("skip") if mine => return Err("SKIP for a question".into()),
            Some("error") => return Err(format!("error {}", message["code"])),
            _ => {}
        }
    }
}

struct Relay {
    child: Child,
    log: Arc<Mutex<Vec<String>>>,
    log_reader: std::thread::JoinHandle<()>,
}

fn start_relay(path: &PathBuf, addr: SocketAddr, secret: &str, threads: Option<u64>) -> Result<Relay, String> {
    let mut command = Command::new(path);
    match threads {
        Some(n) => command.env("TOKIO_WORKER_THREADS", n.to_string()),
        None => command.env_remove("TOKIO_WORKER_THREADS"),
    };
    let mut child = command
        .env_remove("RELAY_SERVER_URL")
        .env_remove("RELAY_CONFIG")
        .env_remove("RELAY_PACK_DIR")
        .env("RELAY_PROVIDERS", "mock")
        .env("RELAY_BIND", addr.to_string())
        .env("RELAY_SECRET", secret)
        .env("RELAY_LOG", "info")
        .stdout(Stdio::piped())
        .stderr(Stdio::inherit())
        .spawn()
        .map_err(|e| format!("could not start {}: {e} (cargo build --release first)", path.display()))?;
    let stdout = child.stdout.take().ok_or("no stdout")?;
    let log = Arc::new(Mutex::new(Vec::new()));
    let lines = log.clone();
    let log_reader = std::thread::spawn(move || {
        for line in BufReader::new(stdout).lines().map_while(Result::ok) {
            if let Ok(mut lines) = lines.lock() {
                lines.push(line);
            }
        }
    });
    Ok(Relay { child, log, log_reader })
}

fn line(name: &str, target: &str, result: &str, pass: bool) -> bool {
    println!("| {name} | {target} | {result} | {} |", if pass { "pass" } else { "FAIL" });
    pass
}

fn main() -> ExitCode {
    let options = match options() {
        Ok(options) => options,
        Err(error) => {
            eprintln!("assist-relay-loadtest: {error}");
            return ExitCode::from(2);
        }
    };
    let runtime = match tokio::runtime::Builder::new_multi_thread().enable_all().build() {
        Ok(runtime) => runtime,
        Err(error) => {
            eprintln!("assist-relay-loadtest: {error}");
            return ExitCode::FAILURE;
        }
    };
    match runtime.block_on(load(options)) {
        Ok(true) => ExitCode::SUCCESS,
        Ok(false) => ExitCode::FAILURE,
        Err(error) => {
            eprintln!("assist-relay-loadtest: {error}");
            ExitCode::FAILURE
        }
    }
}

async fn load(o: Options) -> Result<bool, String> {
    let secret = run_secret();
    let signer = TokenSigner::new(Secret::new(secret.clone()));
    let addr = free_port().map_err(|e| e.to_string())?;
    let mut relay = start_relay(&o.relay, addr, &secret, o.relay_threads)?;
    let pid = relay.child.id();

    let up_by = Instant::now() + Duration::from_secs(10);
    while assist_relay::health_check(Some(&addr.to_string())) != ExitCode::SUCCESS {
        if Instant::now() > up_by {
            let _ = relay.child.kill();
            return Err("the relay did not answer /healthz within 10 s".into());
        }
        tokio::time::sleep(Duration::from_millis(50)).await;
    }
    tokio::time::sleep(Duration::from_millis(300)).await;
    let idle_kib = rss_kib(pid).ok_or("cannot read the relay's memory")?;

    // Resident memory, sampled every 100 ms while the sessions run.
    let peak = Arc::new(AtomicU64::new(idle_kib));
    let sampling = {
        let peak = peak.clone();
        tokio::spawn(async move {
            loop {
                if let Some(kib) = rss_kib(pid) {
                    peak.fetch_max(kib, Ordering::Relaxed);
                }
                tokio::time::sleep(Duration::from_millis(100)).await;
            }
        })
    };

    println!(
        "Relay pid {pid} on {addr}: {} sessions, {} questions each, modes {}, think {} ms, ramp {} ms, relay threads {}.",
        o.sessions,
        o.questions,
        o.modes.join(","),
        o.think.as_millis(),
        o.ramp.as_millis(),
        o.relay_threads.map_or("one per core".to_owned(), |n| n.to_string())
    );
    let all_done = Arc::new(Barrier::new(o.sessions + 1));
    let (stop_tx, stop_rx) = watch::channel(false);
    let started = Instant::now();
    let mut tasks = Vec::with_capacity(o.sessions);
    for i in 0..o.sessions {
        let session = format!("load-{i:04}");
        let run = Run {
            addr,
            token: token(&signer, &session),
            session,
            mode: o.modes[i % o.modes.len()].clone(),
            questions: o.questions,
            think: o.think,
            start_after: o.ramp.mul_f64(i as f64 / o.sessions as f64),
        };
        tasks.push(tokio::spawn(run_session(run, all_done.clone(), stop_rx.clone())));
    }
    all_done.wait().await;
    let conversation = started.elapsed();
    tokio::time::sleep(Duration::from_millis(300)).await;
    let connected_kib = rss_kib(pid).unwrap_or(0);
    sampling.abort();
    let peak_kib = peak.load(Ordering::Relaxed).max(connected_kib);

    // Clean shutdown: SIGTERM with every session still open.
    let _ = stop_tx.send(true);
    let term_at = Instant::now();
    let killed = Command::new("kill").args(["-TERM", &pid.to_string()]).status().map_err(|e| e.to_string())?;
    if !killed.success() {
        return Err("kill -TERM failed".into());
    }
    let exit = loop {
        if let Some(status) = relay.child.try_wait().map_err(|e| e.to_string())? {
            break Some(status);
        }
        if term_at.elapsed() > Duration::from_secs(15) {
            let _ = relay.child.kill();
            break None;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    };
    let stop_time = term_at.elapsed();
    let mut results = Vec::with_capacity(tasks.len());
    for task in tasks {
        results.push(task.await.map_err(|e| e.to_string())?);
    }
    let _ = relay.log_reader.join();
    let log = relay.log.lock().map(|l| l.clone()).unwrap_or_default();

    // Results.
    let ready = results.iter().filter(|r| r.ready).count();
    let errors: Vec<&String> = results.iter().flat_map(|r| &r.errors).collect();
    let answered: usize = results.iter().map(|r| r.samples.len()).sum();
    let expected = o.sessions * o.questions;
    let unexpected: u64 = results.iter().map(|r| r.unexpected).sum();
    let mut closes: BTreeMap<String, usize> = BTreeMap::new();
    for r in &results {
        *closes.entry(r.close_code.map_or("none".into(), |c| c.to_string())).or_default() += 1;
    }
    let samples: Vec<&Sample> = results.iter().flat_map(|r| &r.samples).collect();
    let mut relay_overhead: Vec<f64> = samples.iter().map(|s| s.relay_overhead_ms).collect();
    let mut app_overhead: Vec<f64> = samples.iter().map(|s| s.app_overhead_ms).collect();
    let leaked = log.iter().any(|l| l.contains(CV_MARKER) || l.contains(&secret) || QUESTIONS.iter().any(|q| l.contains(q)) || l.contains(REPLY));
    let stopped_logged = log.iter().any(|l| l.contains(r#""event":"stopped""#));
    let suggestions_logged = log.iter().filter(|l| l.contains(r#""event":"suggestion""#)).count();

    println!();
    println!("Conversation phase {:.1} s; {answered}/{expected} answers; {} log lines.", conversation.as_secs_f64(), log.len());
    println!();
    println!("| Mode | Answers | TTFT p50 ms | TTFT p95 ms | Relay overhead p50 / p95 ms | App-side overhead p50 / p95 ms | Total p50 ms |");
    println!("|---|---|---|---|---|---|---|");
    for mode in &o.modes {
        let of: Vec<&&Sample> = samples.iter().filter(|s| &s.mode == mode).collect();
        let mut ttft: Vec<f64> = of.iter().map(|s| s.ttft_ms).collect();
        let mut relay_o: Vec<f64> = of.iter().map(|s| s.relay_overhead_ms).collect();
        let mut app_o: Vec<f64> = of.iter().map(|s| s.app_overhead_ms).collect();
        let mut total: Vec<f64> = of.iter().map(|s| s.total_ms).collect();
        println!(
            "| {mode} | {} | {:.1} | {:.1} | {:.2} / {:.2} | {:.2} / {:.2} | {:.1} |",
            of.len(),
            percentile(&mut ttft, 50.0),
            percentile(&mut ttft, 95.0),
            percentile(&mut relay_o, 50.0),
            percentile(&mut relay_o, 95.0),
            percentile(&mut app_o, 50.0),
            percentile(&mut app_o, 95.0),
            percentile(&mut total, 50.0),
        );
    }
    println!();
    println!("| Target | Plan | Result | Pass |");
    println!("|---|---|---|---|");
    let mut pass = true;
    pass &= line(
        "Concurrent sessions",
        &format!("{} sessions, every question answered, no errors", o.sessions),
        &format!("{ready} ready, {answered}/{expected} answered, {} errors", errors.len()),
        ready == o.sessions && answered == expected && errors.is_empty(),
    );
    for mode in &o.modes {
        let mut ttft: Vec<f64> = samples.iter().filter(|s| &s.mode == mode).map(|s| s.ttft_ms).collect();
        let (p50, p95) = (percentile(&mut ttft, 50.0), percentile(&mut ttft, 95.0));
        if let Some(target) = ttft_target_ms(mode) {
            pass &= line(
                &format!("First token, {mode}"),
                &format!("p50 <= {target:.0} ms (mocked)"),
                &format!("p50 {p50:.1} ms, p95 {p95:.1} ms"),
                p50 <= target,
            );
        }
    }
    let relay_p95 = percentile(&mut relay_overhead, 95.0);
    pass &= line("Relay overhead (relay's measure)", "p95 <= 30 ms", &format!("p95 {relay_p95:.2} ms"), relay_p95 <= OVERHEAD_P95_TARGET_MS);
    let app_p95 = percentile(&mut app_overhead, 95.0);
    pass &= line(
        "Relay overhead (app-side, incl. socket)",
        "p95 <= 30 ms",
        &format!("p95 {app_p95:.2} ms"),
        app_p95 <= OVERHEAD_P95_TARGET_MS,
    );
    let per_session = peak_kib.saturating_sub(idle_kib) as f64 / o.sessions as f64;
    println!(
        "| Memory per session | no target in the plan (reported) | idle {:.1} MiB, {} sessions open {:.1} MiB, peak {:.1} MiB; {per_session:.0} KiB per session | - |",
        idle_kib as f64 / 1024.0,
        o.sessions,
        connected_kib as f64 / 1024.0,
        peak_kib as f64 / 1024.0
    );
    let closes_text = closes.iter().map(|(code, n)| format!("{n}x {code}")).collect::<Vec<_>>().join(", ");
    let exit_ok = exit.is_some_and(|s| s.success());
    pass &= line(
        "Clean shutdown (SIGTERM, all sessions open)",
        &format!("every app gets 1012, exit 0 within {} s", SHUTDOWN_TARGET.as_secs()),
        &format!(
            "closes: {closes_text}; exit {}; {:.0} ms; \"stopped\" logged: {stopped_logged}",
            exit.map_or("killed".into(), |s| s.code().map_or("signal".into(), |c| c.to_string())),
            stop_time.as_secs_f64() * 1000.0
        ),
        closes.get("1012") == Some(&o.sessions) && exit_ok && stop_time <= SHUTDOWN_TARGET && stopped_logged,
    );
    pass &= line(
        "No suggestion from my channel or unasked",
        "0",
        &unexpected.to_string(),
        unexpected == 0,
    );
    pass &= line(
        "No content or secret in the relay's log",
        "0 lines",
        &format!("{} ({suggestions_logged} suggestion records checked)", if leaked { "leaked" } else { "0 lines" }),
        !leaked,
    );
    for error in errors.iter().take(10) {
        println!("error: {error}");
    }
    println!();
    println!("{}", if pass { "All targets pass." } else { "Some targets FAIL." });
    Ok(pass)
}
