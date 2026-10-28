//! End-to-end tests of the desktop's Rust network pipeline (`net::api`, `net::uploader`, `net::sync` and the audio
//! writer) against a live EchoLedger server. Nothing on the server side is mocked: the tests pair a device, create
//! sessions, upload FLAC files in chunks, wait for the transcript and read everything back the way the app does.
//!
//! The suite is skipped (with a message on stderr) unless `ECHOLEDGER_E2E_BASE_URL` is set, so a normal
//! `cargo test` never needs a server. To run it, start a server whose speech provider is a test double, then:
//!
//! ```text
//! ECHOLEDGER_E2E_BASE_URL=http://127.0.0.1:8112 \
//! ECHOLEDGER_E2E_PAIRING_CODE=ABCD-EFGH \
//! cargo test --test e2e_server -- --test-threads=1 --nocapture
//! ```
//!
//! Environment:
//!
//! * `ECHOLEDGER_E2E_BASE_URL`      server origin, for example `http://127.0.0.1:8112` (plain http is only accepted
//!   for loopback, exactly as in the app).
//! * `ECHOLEDGER_E2E_PAIRING_CODE`  a fresh one-time pairing code. The suite registers one device with it.
//! * `ECHOLEDGER_E2E_TOKEN`         alternative to the pairing code: the token of an already paired device.
//! * `ECHOLEDGER_E2E_CONTROL`       optional command (program plus fixed arguments, split on spaces) that the tests
//!   call as `<command> <action> [args]` to do what only the server operator can do. Cases that need an action
//!   which is not available are reported as `E2E-SKIP` and return early. Actions:
//!   `pairing-code`, `revoke <device_id>`, `expire-tokens <device_id>`, `stt-mode <mode> [count]`,
//!   `stop-server`, `start-server`, `recording-files <recording_id>`, `reconcile`,
//!   `create-client <name> <term|term>` (prints JSON with the new `id`), `delete-client <id>` and
//!   `set-recording-status <recording_id> <status>`.
//! * `ECHOLEDGER_E2E_SPEECH_WAV`    optional 16 kHz stereo 16-bit WAV used as the call audio (left = Me, right =
//!   Client). Without it a synthetic signal is used.
//! * `ECHOLEDGER_E2E_SECRETS`       optional comma separated strings that must never appear in an API response.
//!
//! The server has to be single-user and disposable: the tests create, delete and revoke things. Cases run one at a
//! time (a process-wide lock) because they share the device, the library and the speech provider double.
//!
//! Cases that fail because of a known defect are marked `#[ignore]` with the ID of the planned fix; add
//! `--include-ignored` after `--` to run them as well.
//!
//! No test records the microphone or system audio: the recordings are produced by feeding synthetic packets
//! through the app's `Recorder`, which writes the same WAV and FLAC files as a real capture.

use std::collections::BTreeMap;
use std::io::{Cursor, Read, Write};
use std::net::{Shutdown, SocketAddr, TcpListener, TcpStream, ToSocketAddrs};
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex, Once, OnceLock};
use std::thread::JoinHandle;
use std::time::{Duration, Instant};

use desktop_lib::audio::recorder::{Recorder, RecordingOutput};
use desktop_lib::audio::types::{AudioChunk, Channel, TARGET_RATE};
use desktop_lib::error::AppError;
use desktop_lib::net::api::{ApiClient, with_client_fallback};
use desktop_lib::net::models::{
    ArtifactDto, CreateSessionRequest, LibraryItem, OpenSessionRequest, RegisterResponse,
    SegmentDto,
};
use desktop_lib::net::sync::LibraryCache;
use desktop_lib::net::uploader::{PendingUpload, UploadQueue, file_sha256, sha256_hex, upload};
use serde_json::{Value, json};

// ---- Configuration -------------------------------------------------------------------------------------------

struct Config {
    base_url: String,
    pairing_code: Option<String>,
    token: Option<String>,
    control: Option<Vec<String>>,
    speech_wav: Option<PathBuf>,
    secrets: Vec<String>,
}

fn config() -> Option<&'static Config> {
    static CONFIG: OnceLock<Option<Config>> = OnceLock::new();
    CONFIG
        .get_or_init(|| {
            let var = |name: &str| {
                std::env::var(name)
                    .ok()
                    .map(|v| v.trim().to_string())
                    .filter(|v| !v.is_empty())
            };
            let base_url = var("ECHOLEDGER_E2E_BASE_URL")?
                .trim_end_matches('/')
                .to_string();
            Some(Config {
                base_url,
                pairing_code: var("ECHOLEDGER_E2E_PAIRING_CODE"),
                token: var("ECHOLEDGER_E2E_TOKEN"),
                control: var("ECHOLEDGER_E2E_CONTROL")
                    .map(|c| c.split_whitespace().map(String::from).collect()),
                speech_wav: var("ECHOLEDGER_E2E_SPEECH_WAV").map(PathBuf::from),
                secrets: var("ECHOLEDGER_E2E_SECRETS")
                    .map(|s| s.split(',').map(|p| p.trim().to_string()).collect())
                    .unwrap_or_default(),
            })
        })
        .as_ref()
}

/// Writes straight to the process's stderr: the test runner captures `eprintln!` of passing tests, and these
/// notices must be visible in a normal run.
fn notice(line: &str) {
    let _ = writeln!(std::io::stderr(), "{line}");
}

fn announce_disabled() {
    static ONCE: Once = Once::new();
    ONCE.call_once(|| {
        notice(
            "e2e_server: ECHOLEDGER_E2E_BASE_URL is not set, so the end-to-end tests against a live server are \
             skipped (see the documentation at the top of tests/e2e_server.rs).",
        );
    });
}

fn skipped(case: &str, reason: &str) {
    notice(&format!("E2E-SKIP {case}: {reason}"));
}

/// Only one case talks to the server at a time.
static SERIAL: tokio::sync::Mutex<()> = tokio::sync::Mutex::const_new(());

macro_rules! e2e_test {
    ($(#[$attr:meta])* $name:ident, $timeout_secs:expr, |$cfg:ident, $case:ident| $body:block) => {
        #[tokio::test(flavor = "multi_thread", worker_threads = 4)]
        $(#[$attr])*
        async fn $name() {
            let Some($cfg) = config() else {
                announce_disabled();
                return;
            };
            let $case: &str = stringify!($name);
            let _ = $case;
            let _serial = SERIAL.lock().await;
            let finished =
                tokio::time::timeout(Duration::from_secs($timeout_secs), async { $body }).await;
            if finished.is_err() {
                panic!("{} did not finish within {} s", $case, $timeout_secs);
            }
        }
    };
}

// ---- Operator hook -------------------------------------------------------------------------------------------

#[derive(Clone)]
struct Control {
    program: String,
    args: Vec<String>,
}

impl Control {
    fn of(cfg: &Config) -> Option<Control> {
        let parts = cfg.control.as_ref()?;
        Some(Control {
            program: parts.first()?.clone(),
            args: parts[1..].to_vec(),
        })
    }

    /// Runs the hook and returns its trimmed stdout. Output goes through files, not pipes, so a server process
    /// started by the hook cannot keep this call waiting.
    fn run(&self, action: &[&str]) -> String {
        static COUNTER: AtomicUsize = AtomicUsize::new(0);
        let tag = format!(
            "e2e-control-{}-{}",
            std::process::id(),
            COUNTER.fetch_add(1, Ordering::SeqCst)
        );
        let (out_path, err_path) = (
            std::env::temp_dir().join(format!("{tag}.out")),
            std::env::temp_dir().join(format!("{tag}.err")),
        );
        let mut child = Command::new(&self.program)
            .args(&self.args)
            .args(action)
            .stdin(Stdio::null())
            .stdout(std::fs::File::create(&out_path).expect("control stdout file"))
            .stderr(std::fs::File::create(&err_path).expect("control stderr file"))
            .spawn()
            .unwrap_or_else(|e| panic!("cannot start the control command {}: {e}", self.program));
        let deadline = Instant::now() + Duration::from_secs(240);
        let status = loop {
            if let Some(status) = child.try_wait().expect("wait for the control command") {
                break status;
            }
            if Instant::now() > deadline {
                let _ = child.kill();
                panic!("control action {action:?} did not finish within 240 s");
            }
            std::thread::sleep(Duration::from_millis(50));
        };
        let out = std::fs::read_to_string(&out_path).unwrap_or_default();
        let err = std::fs::read_to_string(&err_path).unwrap_or_default();
        let _ = std::fs::remove_file(&out_path);
        let _ = std::fs::remove_file(&err_path);
        assert!(
            status.success(),
            "control action {action:?} failed ({status}): {} {}",
            out.trim(),
            err.trim()
        );
        out.trim().to_string()
    }

    fn json(&self, action: &[&str]) -> Value {
        let out = self.run(action);
        serde_json::from_str(out.lines().last().unwrap_or(""))
            .unwrap_or_else(|e| panic!("control action {action:?} printed no JSON ({e}): {out}"))
    }
}

/// Puts the speech provider double back to its normal behaviour when a case ends, however it ends.
struct ProviderMode(Control);

impl ProviderMode {
    fn set(control: &Control, mode: &str, count: u32) -> ProviderMode {
        control.run(&["stt-mode", mode, &count.to_string()]);
        ProviderMode(control.clone())
    }
}

impl Drop for ProviderMode {
    fn drop(&mut self) {
        let _ = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
            self.0.run(&["stt-mode", "ok"]);
        }));
    }
}

// ---- Clients and identities ----------------------------------------------------------------------------------

fn init_tls() {
    let _ = rustls::crypto::ring::default_provider().install_default();
}

fn api_at(base_url: &str, token: &str) -> ApiClient {
    init_tls();
    ApiClient::new(base_url, &[], Some(token.to_string())).expect("client for the test server")
}

fn api_for(cfg: &Config, token: &str) -> ApiClient {
    api_at(&cfg.base_url, token)
}

fn plain_http() -> reqwest::Client {
    init_tls();
    reqwest::Client::builder()
        .timeout(Duration::from_secs(120))
        .build()
        .expect("http client")
}

fn short_id() -> String {
    uuid::Uuid::new_v4().simple().to_string()[..8].to_string()
}

struct Identity {
    token: String,
    device_id: String,
    device_name: String,
    registered: Option<RegisterResponse>,
}

static IDENTITY: tokio::sync::OnceCell<Identity> = tokio::sync::OnceCell::const_new();

/// The device every case uses unless it needs one of its own: registered once with the pairing code.
async fn identity(cfg: &'static Config) -> &'static Identity {
    IDENTITY
        .get_or_init(|| async move {
            if let Some(code) = &cfg.pairing_code {
                let name = format!("E2E desktop {}", short_id());
                let registered = register_with_retry(cfg, code, &name).await;
                Identity {
                    token: registered.token.clone(),
                    device_id: registered.device.id.clone(),
                    device_name: registered.device.name.clone(),
                    registered: Some(registered),
                }
            } else if let Some(token) = &cfg.token {
                let config = api_for(cfg, token)
                    .config()
                    .await
                    .expect("the token in ECHOLEDGER_E2E_TOKEN must be accepted");
                Identity {
                    token: token.clone(),
                    device_id: config.device.id,
                    device_name: config.device.name,
                    registered: None,
                }
            } else {
                panic!("set ECHOLEDGER_E2E_PAIRING_CODE or ECHOLEDGER_E2E_TOKEN");
            }
        })
        .await
}

/// Pairing is limited to five attempts a minute per address; wait the window out instead of failing.
async fn register_with_retry(cfg: &Config, code: &str, name: &str) -> RegisterResponse {
    init_tls();
    let api = ApiClient::new(&cfg.base_url, &[], None).expect("client");
    for _ in 0..10 {
        match api.register(code, name, "windows", "e2e").await {
            Ok(registered) => return registered,
            Err(AppError::Server { status: 429, .. }) => {
                tokio::time::sleep(Duration::from_secs(15)).await
            }
            Err(e) => panic!("registering with the pairing code failed: {e}"),
        }
    }
    panic!("pairing stayed rate limited");
}

struct Device {
    token: String,
    id: String,
}

/// A second (or third) device paired through the operator hook.
async fn new_device(cfg: &Config, control: &Control, label: &str) -> Device {
    let code = control.run(&["pairing-code"]);
    let name = format!("E2E {label} {}", short_id());
    let registered = register_with_retry(cfg, &code, &name).await;
    Device {
        token: registered.token,
        id: registered.device.id,
    }
}

// ---- Small helpers -------------------------------------------------------------------------------------------

fn server_status(error: &AppError) -> Option<u16> {
    match error {
        AppError::Server { status, .. } => Some(*status),
        _ => None,
    }
}

fn server_message(error: &AppError) -> String {
    match error {
        AppError::Server { message, .. } => message.clone(),
        other => other.to_string(),
    }
}

fn is_not_paired(result: &Result<impl Sized, AppError>) -> bool {
    matches!(result, Err(AppError::NotPaired))
}

fn text_at<'a>(value: &'a Value, path: &[&str]) -> Option<&'a str> {
    let mut current = value;
    for key in path {
        current = current.get(*key)?;
    }
    current.as_str()
}

fn transcript_of<'a>(detail: &'a Value, kind: &str) -> Option<&'a Value> {
    detail["transcripts"]
        .as_array()?
        .iter()
        .find(|t| t["kind"] == kind)
}

fn item_json(item: &LibraryItem) -> Value {
    serde_json::to_value(item).expect("library item as JSON")
}

async fn pause(ms: u64) {
    tokio::time::sleep(Duration::from_millis(ms)).await;
}

fn rfc3339_seconds(value: &str) -> i64 {
    chrono::DateTime::parse_from_rfc3339(value)
        .unwrap_or_else(|e| panic!("{value:?} is not an ISO-8601 timestamp: {e}"))
        .timestamp()
}

fn live_segments() -> Vec<SegmentDto> {
    let segment = |channel: u8, speaker: &str, start_ms: u64, end_ms: u64, text: &str| SegmentDto {
        channel,
        speaker: speaker.into(),
        start_ms,
        end_ms,
        text: text.into(),
        words: vec![],
        uncertain: false,
        check: None,
    };
    vec![
        segment(1, "Me", 300, 2400, "Hello, can you hear me?"),
        segment(2, "Client", 2600, 4200, "Loud and clear."),
        segment(1, "Me", 4500, 6000, "Great, let's start."),
    ]
}

struct Finished {
    detail: Value,
    session_statuses: Vec<String>,
}

/// Polls `GET /api/sessions/{id}` until the final transcript is completed or failed.
async fn wait_for_final(api: &ApiClient, session_id: &str, timeout: Duration) -> Finished {
    let started = Instant::now();
    let mut session_statuses: Vec<String> = Vec::new();
    loop {
        let detail = api
            .session(session_id)
            .await
            .unwrap_or_else(|e| panic!("reading session {session_id}: {e}"));
        let status = text_at(&detail, &["status"]).unwrap_or("?").to_string();
        if session_statuses.last() != Some(&status) {
            session_statuses.push(status);
        }
        let final_status = transcript_of(&detail, "final").and_then(|t| text_at(t, &["status"]));
        if matches!(final_status, Some("completed" | "failed")) {
            return Finished {
                detail,
                session_statuses,
            };
        }
        assert!(
            started.elapsed() < timeout,
            "no final transcript for session {session_id} after {:?}; last state: {detail}",
            started.elapsed()
        );
        pause(500).await;
    }
}

fn final_transcript_id(detail: &Value) -> String {
    transcript_of(detail, "final")
        .and_then(|t| text_at(t, &["id"]))
        .expect("a final transcript on the session")
        .to_string()
}

fn recording_id(detail: &Value) -> String {
    detail["recordings"][0]["id"]
        .as_str()
        .expect("a recording on the session")
        .to_string()
}

// ---- Audio ---------------------------------------------------------------------------------------------------

struct Signal {
    left: Vec<i16>,
    right: Vec<i16>,
}

impl Signal {
    fn repeated(&self, times: usize) -> Signal {
        Signal {
            left: self.left.repeat(times),
            right: self.right.repeat(times),
        }
    }

    /// Avoids a quirk of the FLAC writer that is not under test here: a final block of 1 to 15 samples is
    /// written with an invalid minimum block size, which strict decoders refuse.
    fn with_safe_length(mut self) -> Signal {
        let frames = self.left.len().max(self.right.len());
        if (1..16).contains(&(frames % 4096)) {
            self.left.extend([0; 64]);
            self.right.extend([0; 64]);
        }
        self
    }
}

fn xorshift(state: &mut u64) -> u64 {
    *state ^= *state << 13;
    *state ^= *state >> 7;
    *state ^= *state << 17;
    *state
}

/// Eight seconds of a two-voice conversation: the supplied speech file, or tones with noise.
fn base_signal(cfg: &Config) -> Signal {
    if let Some(path) = &cfg.speech_wav
        && let Ok(mut reader) = hound::WavReader::open(path)
    {
        let spec = reader.spec();
        if spec.channels == 2
            && spec.sample_rate == TARGET_RATE
            && spec.bits_per_sample == 16
            && spec.sample_format == hound::SampleFormat::Int
        {
            let all: Vec<i16> = reader
                .samples::<i16>()
                .map(|s| s.expect("readable sample"))
                .collect();
            return Signal {
                left: all.iter().step_by(2).copied().collect(),
                right: all.iter().skip(1).step_by(2).copied().collect(),
            };
        }
    }
    let frames = 8 * TARGET_RATE as usize;
    let mut state = 0x9E37_79B9_7F4A_7C15u64;
    let mut voice = |freq: f32, start: usize, n: usize| {
        let wave = (2.0 * std::f32::consts::PI * freq * n as f32 / TARGET_RATE as f32).sin();
        let envelope = if n >= start && (n / 4000).is_multiple_of(2) {
            1.0
        } else {
            0.0
        };
        let noise = (xorshift(&mut state) % 600) as f32 - 300.0;
        (wave * 7000.0 * envelope + noise) as i16
    };
    let left = (0..frames).map(|n| voice(330.0, 0, n)).collect();
    let right = (0..frames).map(|n| voice(440.0, 9600, n)).collect();
    Signal { left, right }
}

fn push_signal(recorder: &mut Recorder, channel: Channel, pcm: &[i16]) {
    const T0_NS: u64 = 5_000_000_000;
    const PACKET: usize = 160; // 10 ms
    for (index, block) in pcm.chunks(PACKET).enumerate() {
        let ts_ns = T0_NS + (index * PACKET) as u64 * 1_000_000_000 / u64::from(TARGET_RATE);
        recorder
            .push(AudioChunk {
                channel,
                samples: block.iter().map(|&s| f32::from(s) / 32768.0).collect(),
                ts_ns: Some(ts_ns),
                discontinuity: false,
            })
            .expect("the recorder accepts the packet");
    }
}

/// A two-channel call recording written by the app's recorder: `mic.wav`, `system.wav` and `recording.flac`.
fn record_call(dir: &Path, signal: Signal) -> RecordingOutput {
    let signal = signal.with_safe_length();
    let mut recorder = Recorder::create(dir, false).expect("create the recorder");
    push_signal(&mut recorder, Channel::Mic, &signal.left);
    push_signal(&mut recorder, Channel::System, &signal.right);
    recorder.finish().expect("finish the recording")
}

/// A voice note spoken into the microphone: a mono FLAC of the microphone channel.
fn record_voice_note(dir: &Path, signal: Signal) -> RecordingOutput {
    let signal = signal.with_safe_length();
    let mut recorder = Recorder::create_mono(dir, Channel::Mic).expect("create the recorder");
    push_signal(&mut recorder, Channel::Mic, &signal.left);
    recorder.finish().expect("finish the recording")
}

/// A call whose upload file is at least `min_bytes` long.
fn call_of_at_least(cfg: &Config, dir: &Path, min_bytes: u64) -> RecordingOutput {
    let base = base_signal(cfg);
    let probe = record_call(
        &dir.join("probe"),
        Signal {
            left: base.left.clone(),
            right: base.right.clone(),
        },
    );
    let per_repeat = std::fs::metadata(&probe.primary)
        .expect("probe file")
        .len()
        .max(1);
    let _ = std::fs::remove_dir_all(dir.join("probe"));
    let mut repeats = (min_bytes / per_repeat + 1) as usize;
    loop {
        let out = record_call(&dir.join(format!("call-{repeats}")), base.repeated(repeats));
        if std::fs::metadata(&out.primary).expect("recording").len() >= min_bytes {
            return out;
        }
        repeats += 1;
    }
}

/// Eight seconds of speech: the size of an ordinary short call (two chunks on a server with 256 KiB chunks).
fn small_call(cfg: &Config, dir: &Path) -> RecordingOutput {
    record_call(dir, base_signal(cfg))
}

fn silence(seconds: usize, noise_amplitude: u64) -> Signal {
    let mut state = 0x2545_F491_4F6C_DD1Du64;
    let mut channel = || -> Vec<i16> {
        (0..seconds * TARGET_RATE as usize)
            .map(|_| {
                if noise_amplitude == 0 {
                    0
                } else {
                    ((xorshift(&mut state) % (noise_amplitude * 2 + 1)) as i64
                        - noise_amplitude as i64) as i16
                }
            })
            .collect()
    };
    Signal {
        left: channel(),
        right: channel(),
    }
}

/// What `actions::enqueue` puts in the upload queue after a call.
fn pending_call(out: &RecordingOutput, source_app: &str) -> PendingUpload {
    let ended = chrono::Utc::now();
    let started = ended - chrono::Duration::milliseconds(out.duration_ms as i64);
    PendingUpload {
        client_ref: uuid::Uuid::new_v4().to_string(),
        source_app: source_app.into(),
        client_id: None,
        started_at: started.to_rfc3339(),
        ended_at: ended.to_rfc3339(),
        duration_ms: out.duration_ms,
        dir: out.dir.clone(),
        primary: out.primary.clone(),
        primary_channels: out.channels,
        mic_wav: (out.channels == 2).then(|| out.mic_wav.clone()),
        system_wav: (out.channels == 2).then(|| out.system_wav.clone()),
        live_segments: vec![],
        live_provider: None,
        attempts: 0,
        last_error: None,
        ..PendingUpload::default()
    }
}

/// The open session a v1.1 server requires before it hands out a live-caption token (`None` on a v1 server).
async fn caption_session(api: &ApiClient) -> Option<String> {
    let config = api.config().await.expect("config");
    if !config.is_v1_1() {
        return None;
    }
    let opened = api
        .open_session(&OpenSessionRequest {
            client_ref: uuid::Uuid::new_v4().to_string(),
            source_app: "other".into(),
            client_id: None,
            title: None,
            started_at: chrono::Utc::now().to_rfc3339(),
            language: None,
        })
        .await
        .expect("open session for captions");
    Some(opened.session.id)
}

async fn close_caption_session(api: &ApiClient, session: Option<String>) {
    if let Some(id) = session {
        let _ = api.delete_session(&id).await;
    }
}

fn pending_voice_note(out: &RecordingOutput) -> PendingUpload {
    pending_call(out, "voice_note")
}

fn session_request(client_ref: &str, source_app: &str, size: u64) -> CreateSessionRequest {
    CreateSessionRequest {
        markers: vec![],
        pauses: vec![],
        language_marks: vec![],
        client_ref: client_ref.into(),
        source_app: source_app.into(),
        client_id: None,
        title: None,
        started_at: chrono::Utc::now().to_rfc3339(),
        ended_at: None,
        duration_ms: Some(1000),
        language: None,
        artifacts: vec![ArtifactDto {
            role: "primary".into(),
            mime: "audio/flac".into(),
            codec: Some("flac".into()),
            channels: 2,
            sample_rate: Some(16_000),
            size_bytes: size,
            sha256: sha256_hex(client_ref.as_bytes()),
            duration_ms: Some(1000),
        }],
    }
}

struct FlacInfo {
    sample_rate: u32,
    channels: u32,
    frames: u64,
}

/// Decodes every sample (so a truncated or damaged file fails) and returns the stream parameters.
fn decode_flac(bytes: &[u8]) -> FlacInfo {
    let mut reader = claxon::FlacReader::new(Cursor::new(bytes)).expect("a decodable FLAC stream");
    let info = reader.streaminfo();
    let total = info.samples.expect("the total sample count in STREAMINFO");
    let mut decoded = 0u64;
    for sample in reader.samples() {
        sample.expect("every sample decodes");
        decoded += 1;
    }
    assert_eq!(
        decoded,
        total * u64::from(info.channels),
        "decoded sample count"
    );
    FlacInfo {
        sample_rate: info.sample_rate,
        channels: info.channels,
        frames: total,
    }
}

// ---- Fault injecting proxy -----------------------------------------------------------------------------------

#[derive(Clone, Debug)]
struct Seen {
    method: String,
    path: String,
    chunk: Option<u64>,
    body_len: usize,
    fault: &'static str,
    status: Option<u16>,
    at: Instant,
}

struct ProxyRequest {
    path: String,
    chunk: Option<u64>,
    /// 1 for the first time this method, path and chunk index are seen.
    attempt: usize,
}

enum Fault {
    Pass,
    /// Answer without contacting the server.
    Respond {
        status: u16,
        headers: Vec<(&'static str, String)>,
        body: String,
    },
    /// Close the connection without answering and without contacting the server.
    Hangup,
    /// Forward the request, let the server finish it, then close the connection without relaying the answer.
    LoseResponse,
    /// Flip one bit of the request body before forwarding.
    Corrupt,
}

type Rule = dyn Fn(&ProxyRequest) -> Fault + Send + Sync;
type Attempts = Mutex<BTreeMap<(String, String, Option<u64>), usize>>;

struct RawRequest {
    method: String,
    target: String,
    headers: Vec<(String, String)>,
    body: Vec<u8>,
}

impl RawRequest {
    fn header(&self, name: &str) -> Option<&str> {
        self.headers
            .iter()
            .find(|(k, _)| k == name)
            .map(|(_, v)| v.as_str())
    }
}

/// A small HTTP/1.1 proxy between the client and the server under test that can fail selected requests.
struct Proxy {
    addr: SocketAddr,
    seen: Arc<Mutex<Vec<Seen>>>,
    stop: Arc<AtomicBool>,
    accept_thread: Option<JoinHandle<()>>,
}

impl Proxy {
    fn start(
        upstream_base: &str,
        rule: impl Fn(&ProxyRequest) -> Fault + Send + Sync + 'static,
    ) -> Proxy {
        let upstream: SocketAddr = upstream_base
            .trim_start_matches("http://")
            .trim_end_matches('/')
            .to_socket_addrs()
            .expect("upstream address")
            .next()
            .expect("upstream address");
        let listener = TcpListener::bind("127.0.0.1:0").expect("bind the proxy");
        listener.set_nonblocking(true).expect("non-blocking proxy");
        let addr = listener.local_addr().expect("proxy address");
        let seen = Arc::new(Mutex::new(Vec::new()));
        let stop = Arc::new(AtomicBool::new(false));
        let rule: Arc<Rule> = Arc::new(rule);
        let attempts: Arc<Attempts> = Arc::new(Mutex::new(BTreeMap::new()));
        let accept_thread = {
            let (seen, stop) = (seen.clone(), stop.clone());
            std::thread::spawn(move || {
                while !stop.load(Ordering::SeqCst) {
                    match listener.accept() {
                        Ok((stream, _)) => {
                            let _ = stream.set_nonblocking(false);
                            let (rule, seen, attempts) =
                                (rule.clone(), seen.clone(), attempts.clone());
                            std::thread::spawn(move || {
                                serve(stream, upstream, &*rule, &seen, &attempts)
                            });
                        }
                        Err(e) if e.kind() == std::io::ErrorKind::WouldBlock => {
                            std::thread::sleep(Duration::from_millis(2))
                        }
                        Err(_) => break,
                    }
                }
            })
        };
        Proxy {
            addr,
            seen,
            stop,
            accept_thread: Some(accept_thread),
        }
    }

    fn base_url(&self) -> String {
        format!("http://127.0.0.1:{}", self.addr.port())
    }

    fn seen(&self) -> Vec<Seen> {
        self.seen.lock().unwrap().clone()
    }

    fn mark(&self) -> usize {
        self.seen.lock().unwrap().len()
    }

    /// Chunk requests recorded after `mark`, in arrival order.
    fn chunk_requests_since(&self, mark: usize) -> Vec<Seen> {
        self.seen()
            .into_iter()
            .skip(mark)
            .filter(|s| s.method == "POST" && s.path.ends_with("/chunks"))
            .collect()
    }

    fn requests_since(&self, mark: usize, method: &str, suffix: &str) -> Vec<Seen> {
        self.seen()
            .into_iter()
            .skip(mark)
            .filter(|s| s.method == method && s.path.ends_with(suffix))
            .collect()
    }
}

impl Drop for Proxy {
    fn drop(&mut self) {
        self.stop.store(true, Ordering::SeqCst);
        if let Some(handle) = self.accept_thread.take() {
            let _ = handle.join();
        }
    }
}

fn read_request(stream: &mut TcpStream) -> Option<RawRequest> {
    let mut buf: Vec<u8> = Vec::new();
    let mut read_buf = [0u8; 16 * 1024];
    let header_end = loop {
        if let Some(pos) = buf.windows(4).position(|w| w == b"\r\n\r\n") {
            break pos;
        }
        let n = stream.read(&mut read_buf).ok()?;
        if n == 0 {
            return None;
        }
        buf.extend_from_slice(&read_buf[..n]);
    };
    let head = String::from_utf8_lossy(&buf[..header_end]).to_string();
    let mut lines = head.split("\r\n");
    let mut request_line = lines.next()?.split_whitespace();
    let method = request_line.next()?.to_string();
    let target = request_line.next()?.to_string();
    let headers: Vec<(String, String)> = lines
        .filter_map(|l| l.split_once(':'))
        .map(|(k, v)| (k.trim().to_ascii_lowercase(), v.trim().to_string()))
        .collect();
    let length: usize = headers
        .iter()
        .find(|(k, _)| k == "content-length")
        .and_then(|(_, v)| v.parse().ok())
        .unwrap_or(0);
    let mut body = buf[header_end + 4..].to_vec();
    while body.len() < length {
        let n = stream.read(&mut read_buf).ok()?;
        if n == 0 {
            return None;
        }
        body.extend_from_slice(&read_buf[..n]);
    }
    body.truncate(length);
    Some(RawRequest {
        method,
        target,
        headers,
        body,
    })
}

fn forward(upstream: SocketAddr, request: &RawRequest, body: &[u8]) -> std::io::Result<Vec<u8>> {
    let mut server = TcpStream::connect_timeout(&upstream, Duration::from_secs(5))?;
    server.set_read_timeout(Some(Duration::from_secs(120)))?;
    let mut head = format!("{} {} HTTP/1.1\r\n", request.method, request.target);
    for (name, value) in &request.headers {
        if matches!(
            name.as_str(),
            "connection" | "keep-alive" | "proxy-connection"
        ) {
            continue;
        }
        head.push_str(&format!("{name}: {value}\r\n"));
    }
    head.push_str("Connection: close\r\n\r\n");
    server.write_all(head.as_bytes())?;
    server.write_all(body)?;
    let mut response = Vec::new();
    server.read_to_end(&mut response)?;
    Ok(response)
}

fn status_of(response: &[u8]) -> Option<u16> {
    let line = response.split(|&b| b == b'\r').next()?;
    std::str::from_utf8(line)
        .ok()?
        .split_whitespace()
        .nth(1)?
        .parse()
        .ok()
}

fn reason(status: u16) -> &'static str {
    match status {
        200 => "OK",
        422 => "Unprocessable Entity",
        429 => "Too Many Requests",
        500 => "Internal Server Error",
        502 => "Bad Gateway",
        503 => "Service Unavailable",
        _ => "Status",
    }
}

fn serve(
    mut client: TcpStream,
    upstream: SocketAddr,
    rule: &Rule,
    seen: &Mutex<Vec<Seen>>,
    attempts: &Attempts,
) {
    let _ = client.set_read_timeout(Some(Duration::from_secs(60)));
    let Some(request) = read_request(&mut client) else {
        return;
    };
    let path = request.target.split('?').next().unwrap_or("").to_string();
    let chunk = request
        .header("upload-chunk-index")
        .and_then(|v| v.parse::<u64>().ok());
    let attempt = {
        let mut map = attempts.lock().unwrap();
        let n = map
            .entry((request.method.clone(), path.clone(), chunk))
            .or_insert(0);
        *n += 1;
        *n
    };
    let fault = rule(&ProxyRequest {
        path: path.clone(),
        chunk,
        attempt,
    });
    let note = |fault: &'static str, status: Option<u16>| {
        seen.lock().unwrap().push(Seen {
            method: request.method.clone(),
            path: path.clone(),
            chunk,
            body_len: request.body.len(),
            fault,
            status,
            at: Instant::now(),
        });
    };
    match fault {
        Fault::Hangup => note("hangup", None),
        Fault::Respond {
            status,
            headers,
            body,
        } => {
            let mut head = format!(
                "HTTP/1.1 {status} {}\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n",
                reason(status),
                body.len()
            );
            for (name, value) in headers {
                head.push_str(&format!("{name}: {value}\r\n"));
            }
            head.push_str("\r\n");
            let _ = client.write_all(head.as_bytes());
            let _ = client.write_all(body.as_bytes());
            note("respond", Some(status));
        }
        other => {
            let mut body = request.body.clone();
            let corrupt = matches!(other, Fault::Corrupt);
            if corrupt && let Some(last) = body.last_mut() {
                *last ^= 0x01;
            }
            match forward(upstream, &request, &body) {
                Ok(response) => {
                    let status = status_of(&response);
                    if matches!(other, Fault::LoseResponse) {
                        note("lost-response", status);
                    } else {
                        let _ = client.write_all(&response);
                        note(if corrupt { "corrupt" } else { "pass" }, status);
                    }
                }
                Err(_) => note("upstream-error", None),
            }
        }
    }
    let _ = client.shutdown(Shutdown::Both);
}

fn pass_all(_: &ProxyRequest) -> Fault {
    Fault::Pass
}

// ---- Pairing, configuration and grants -----------------------------------------------------------------------

e2e_test!(
    pairing_code_registers_a_desktop_device_and_returns_a_token_and_config,
    240,
    |cfg, case| {
        if cfg.pairing_code.is_none() {
            return skipped(
                case,
                "needs ECHOLEDGER_E2E_PAIRING_CODE to register a device",
            );
        }
        let id = identity(cfg).await;
        let registered = id.registered.as_ref().expect("registered with the code");
        assert!(
            registered.token.contains("eld_"),
            "device tokens carry the eld_ prefix: {}",
            registered.token.split('|').next().unwrap_or("")
        );
        assert_eq!(registered.device.id.len(), 26, "device ids are ULIDs");
        assert_eq!(registered.device.platform, "windows");
        assert_eq!(registered.device.source_device, "desktop");
        let expires = registered
            .token_expires_at
            .as_deref()
            .expect("the token has an expiry");
        let days = (rfc3339_seconds(expires) - chrono::Utc::now().timestamp()) / 86_400;
        assert!(
            (360..=366).contains(&days),
            "tokens last a year, not {days} days"
        );
        assert_eq!(
            registered.config.device.id, registered.device.id,
            "the embedded config describes the new device"
        );
        assert!(registered.config.upload.chunk_size_bytes >= 256 * 1024);
    }
);

e2e_test!(
    a_used_pairing_code_cannot_register_a_second_device,
    240,
    |cfg, case| {
        let Some(code) = &cfg.pairing_code else {
            return skipped(case, "needs ECHOLEDGER_E2E_PAIRING_CODE");
        };
        identity(cfg).await; // the code is consumed by now
        init_tls();
        let api = ApiClient::new(&cfg.base_url, &[], None).unwrap();
        let mut result = api.register(code, "Second device", "windows", "e2e").await;
        for _ in 0..6 {
            if matches!(result, Err(AppError::Server { status: 429, .. })) {
                pause(15_000).await;
                result = api.register(code, "Second device", "windows", "e2e").await;
            }
        }
        let error = result.expect_err("a pairing code is single use");
        assert_eq!(server_status(&error), Some(422), "{error}");
        assert!(
            server_message(&error).contains("invalid, expired, or already used"),
            "readable message, got: {}",
            server_message(&error)
        );
    }
);

e2e_test!(
    an_unknown_pairing_code_is_rejected_with_a_readable_message,
    240,
    |cfg, case| {
        init_tls();
        let api = ApiClient::new(&cfg.base_url, &[], None).unwrap();
        let mut result = api.register("7777-7777", "Nobody", "windows", "e2e").await;
        for _ in 0..6 {
            if matches!(result, Err(AppError::Server { status: 429, .. })) {
                pause(15_000).await;
                result = api.register("7777-7777", "Nobody", "windows", "e2e").await;
            }
        }
        let error = result.expect_err("an unknown code must not register a device");
        assert_eq!(server_status(&error), Some(422), "{error}");
        assert!(server_message(&error).contains("pairing code"));
    }
);

e2e_test!(
    config_describes_uploads_providers_and_captions,
    120,
    |cfg, case| {
        let id = identity(cfg).await;
        let config = api_for(cfg, &id.token).config().await.expect("config");
        assert_eq!(config.device.id, id.device_id);
        assert_eq!(config.device.source_device, "desktop");
        assert_eq!(config.device.name, id.device_name);
        let chunk = config.upload.chunk_size_bytes;
        assert!(
            (256 * 1024..=5 * 1024 * 1024).contains(&chunk) && chunk % (256 * 1024) == 0,
            "chunk size {chunk} must be a multiple of 256 KiB up to 5 MiB"
        );
        assert!(config.upload.max_recording_bytes >= 1 << 30);
        for mime in ["audio/flac", "audio/wav", "audio/ogg"] {
            assert!(
                config.upload.accepted_mimes.iter().any(|m| m == mime),
                "{mime} must be accepted"
            );
        }
        for use_case in [
            "live_captions",
            "dictation",
            "final_transcript",
            "voice_note",
        ] {
            assert!(
                config.providers.contains_key(use_case),
                "provider for {use_case}"
            );
        }
        assert_eq!(
            config.required_artifacts("final_transcript"),
            vec!["primary".to_string()]
        );
        assert!((1..=365).contains(&config.retention_days));
        assert!(["both", "client_only", "off"].contains(&config.captions.channels.as_str()));
        let skew = (rfc3339_seconds(&config.server_time) - chrono::Utc::now().timestamp()).abs();
        assert!(skew < 300, "server time is {skew} s away from this machine");
    }
);

e2e_test!(
    live_caption_grants_cover_both_channels_and_hold_no_provider_key,
    120,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let session = caption_session(&api).await;
        let live = api
            .stt_token("live_captions", &["me", "client"], None, session.as_deref(), None)
            .await
            .expect("stt token");
        close_caption_session(&api, session).await;
        assert_eq!(live.mode, "cloud");
        let channels: Vec<&str> = live.grants.iter().map(|g| g.channel.as_str()).collect();
        assert_eq!(channels, vec!["me", "client"]);
        for grant in &live.grants {
            assert_eq!(grant.transport, "websocket");
            assert_eq!(grant.protocol.as_deref(), Some("assemblyai_v3"));
            let url = grant.ws_url.as_deref().expect("a ready-to-use ws_url");
            assert!(url.starts_with("wss://"), "{url}");
            let audio = grant.audio.as_ref().expect("audio format");
            assert_eq!((audio.sample_rate, audio.channels), (16_000, 1));
            assert!(grant.max_session_seconds.unwrap_or(0) > 0);
        }
        let dictation = api
            .stt_token("dictation", &["me", "client"], None, None, None)
            .await
            .expect("dictation token");
        let channels: Vec<&str> = dictation
            .grants
            .iter()
            .map(|g| g.channel.as_str())
            .collect();
        assert_eq!(
            channels,
            vec!["me"],
            "dictation is always the microphone only"
        );

        // The raw bodies of the configuration and token calls hold no provider secret.
        let http = plain_http();
        for (method, path, body) in [
            ("GET", "api/config", None),
            (
                "POST",
                "api/stt/token",
                Some(
                    json!({"use_case": "live_captions", "channels": ["me", "client"], "sample_rate": 16000}),
                ),
            ),
        ] {
            let url = format!("{}/{path}", cfg.base_url);
            let mut request = if method == "GET" {
                http.get(&url)
            } else {
                http.post(&url)
            }
            .bearer_auth(&id.token)
            .header("Accept", "application/json");
            if let Some(body) = body {
                request = request.json(&body);
            }
            let text = request.send().await.unwrap().text().await.unwrap();
            for secret in &cfg.secrets {
                assert!(!text.contains(secret.as_str()), "{path} leaks a secret");
            }
            assert!(
                !text.contains("api_key"),
                "{path} mentions an api_key field"
            );
        }
    }
);

e2e_test!(the_clients_list_is_readable, 60, |cfg, case| {
    let id = identity(cfg).await;
    let clients = api_for(cfg, &id.token).clients().await.expect("clients");
    assert!(clients["data"].is_array(), "{clients}");
});

// ---- Recording journeys --------------------------------------------------------------------------------------

e2e_test!(
    whatsapp_call_is_uploaded_in_chunks_and_transcribed,
    600,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let chunk = api.config().await.unwrap().upload.chunk_size_bytes;
        let dir = tempfile::tempdir().unwrap();
        let min_bytes = (chunk * 5 / 2).min(18 * 1024 * 1024);
        let out = call_of_at_least(cfg, dir.path(), min_bytes);
        let size = std::fs::metadata(&out.primary).unwrap().len();
        let total_chunks = size.div_ceil(chunk);
        assert!(
            total_chunks >= 3,
            "{size} bytes in {chunk} byte chunks is only {total_chunks} chunk(s)"
        );

        let proxy = Proxy::start(&cfg.base_url, pass_all);
        let through = api_at(&proxy.base_url(), &id.token);
        let mut pending = pending_call(&out, "whatsapp");
        pending.live_segments = live_segments();
        pending.live_provider = Some("assemblyai".into());
        let session_id = upload(&through, &pending)
            .await
            .expect("the upload succeeds");

        // What went over the wire, in order.
        let seen = proxy.seen();
        let sequence: Vec<String> = seen
            .iter()
            .map(|s| {
                if s.path.ends_with("/chunks") {
                    "chunk".into()
                } else if s.path.ends_with("/complete") {
                    "complete".into()
                } else if s.path.ends_with("/transcripts") {
                    "transcript".into()
                } else {
                    format!("{} {}", s.method, s.path)
                }
            })
            .collect();
        let mut expected: Vec<String> = vec!["GET /api/config".into(), "POST /api/sessions".into()];
        expected.extend(std::iter::repeat_n(
            "chunk".to_string(),
            total_chunks as usize,
        ));
        expected.push("complete".into());
        expected.push("transcript".into());
        assert_eq!(sequence, expected, "request order");
        let chunks = proxy.chunk_requests_since(0);
        let indexes: Vec<u64> = chunks.iter().map(|c| c.chunk.unwrap()).collect();
        assert_eq!(indexes, (0..total_chunks).collect::<Vec<_>>());
        for (position, request) in chunks.iter().enumerate() {
            let expected_len = if position as u64 + 1 < total_chunks {
                chunk
            } else {
                size - chunk * (total_chunks - 1)
            };
            assert_eq!(request.body_len as u64, expected_len, "chunk {position}");
            assert_eq!(request.status, Some(200), "chunk {position}");
        }

        // The server's view of the session.
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        let detail = &finished.detail;
        assert_eq!(text_at(detail, &["source_app"]), Some("whatsapp"));
        assert_eq!(text_at(detail, &["source_app_label"]), Some("WhatsApp"));
        assert_eq!(text_at(detail, &["source_device"]), Some("desktop"));
        assert_eq!(text_at(detail, &["source_device_label"]), Some("Desktop"));
        assert_eq!(
            text_at(detail, &["source_device_icon"]),
            Some("computer-desktop")
        );
        assert_eq!(
            text_at(detail, &["device", "id"]),
            Some(id.device_id.as_str())
        );
        assert_eq!(
            text_at(detail, &["client_ref"]),
            Some(pending.client_ref.as_str())
        );
        assert_eq!(detail["duration_ms"], json!(out.duration_ms));
        assert_eq!(
            rfc3339_seconds(text_at(detail, &["started_at"]).unwrap()),
            rfc3339_seconds(&pending.started_at)
        );
        assert_eq!(
            text_at(detail, &["status"]),
            Some("completed"),
            "{:?}",
            finished.session_statuses
        );
        assert!(
            finished
                .session_statuses
                .iter()
                .all(|s| ["uploading", "processing", "completed"].contains(&s.as_str())),
            "unexpected status path {:?}",
            finished.session_statuses
        );
        let recordings = detail["recordings"].as_array().unwrap();
        assert_eq!(recordings.len(), 1);
        assert_eq!(recordings[0]["role"], "primary");
        assert_eq!(recordings[0]["mime"], "audio/flac");
        assert_eq!(recordings[0]["status"], "ready");
        assert_eq!(recordings[0]["size_bytes"], json!(size));

        // Final transcript: normalised segments, Me on channel 1 and Client on channel 2.
        let final_id = final_transcript_id(detail);
        let transcript = api.transcript(&final_id).await.expect("final transcript");
        assert_eq!(transcript["kind"], "final");
        assert_eq!(transcript["status"], "completed");
        assert_eq!(transcript["session_id"], json!(session_id));
        let text = transcript["text"].as_str().unwrap_or("");
        assert!(!text.trim().is_empty(), "the transcript has text");
        let segments = transcript["segments"].as_array().unwrap();
        assert!(!segments.is_empty(), "the transcript has segments");
        let mut previous_start = 0;
        for segment in segments {
            let channel = segment["channel"].as_u64().unwrap();
            let speaker = segment["speaker"].as_str().unwrap();
            assert_eq!(
                (channel, speaker),
                if channel == 1 {
                    (1, "Me")
                } else {
                    (2, "Client")
                },
                "a stereo call labels channel 1 Me and channel 2 Client: {segment}"
            );
            let (start, end) = (
                segment["start_ms"].as_u64().unwrap(),
                segment["end_ms"].as_u64().unwrap(),
            );
            assert!(
                start <= end && start >= previous_start,
                "segment order: {segment}"
            );
            previous_start = start;
        }

        // Live transcript posted by the app after the upload.
        let live = transcript_of(detail, "live").expect("the live transcript was stored");
        let live = api
            .transcript(live["id"].as_str().unwrap())
            .await
            .expect("live transcript");
        assert_eq!(live["status"], "completed");
        let live_texts: Vec<&str> = live["segments"]
            .as_array()
            .unwrap()
            .iter()
            .map(|s| s["text"].as_str().unwrap())
            .collect();
        assert_eq!(
            live_texts,
            vec![
                "Hello, can you hear me?",
                "Loud and clear.",
                "Great, let's start."
            ]
        );

        // The library the app syncs.
        let mut cache = LibraryCache::default();
        cache.refresh(&api).await.expect("library refresh");
        let item = cache
            .items
            .iter()
            .find(|i| i.id == session_id)
            .expect("the session is in the library");
        let item = item_json(item);
        assert_eq!(item["transcript"]["final_status"], "completed");
        assert_eq!(item["transcript"]["final_transcript_id"], json!(final_id));
        assert_eq!(item["transcript"]["live_available"], true);
        let preview = item["transcript"]["preview"].as_str().unwrap_or("");
        assert!(
            !preview.is_empty() && preview.chars().count() <= 200,
            "preview {preview:?}"
        );
        assert_eq!(item["deleted"], false);

        api.delete_session(&session_id).await.expect("cleanup");
    }
);

e2e_test!(
    playback_url_serves_back_the_exact_audio_with_the_right_duration,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let local = std::fs::read(&out.primary).unwrap();
        let (local_sha, local_len) = file_sha256(&out.primary).unwrap();
        let pending = pending_call(&out, "zoom");
        let session_id = upload(&api, &pending).await.expect("upload");
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        let url = text_at(&finished.detail, &["playback_url"])
            .expect("a completed session has a playback URL")
            .to_string();
        assert!(
            url.starts_with(&cfg.base_url),
            "the signed URL is built for the host the app used: {url}"
        );

        let http = plain_http();
        let response = http.get(&url).send().await.unwrap();
        assert_eq!(response.status(), 200);
        let header = |name: &str| {
            response
                .headers()
                .get(name)
                .and_then(|v| v.to_str().ok())
                .unwrap_or("")
                .to_string()
        };
        assert_eq!(header("content-type"), "audio/flac");
        let cache_control = header("cache-control");
        assert!(
            cache_control.contains("no-store") && cache_control.contains("private"),
            "cache-control: {cache_control}"
        );
        assert_eq!(header("accept-ranges"), "bytes");
        assert_eq!(header("x-content-type-options"), "nosniff");
        let served = response.bytes().await.unwrap().to_vec();
        assert_eq!(served.len() as u64, local_len, "served length");
        assert_eq!(
            sha256_hex(&served),
            local_sha,
            "the decrypted audio is the uploaded file"
        );
        assert_eq!(served, local);

        // Duration and layout of what comes back.
        let info = decode_flac(&served);
        assert_eq!((info.sample_rate, info.channels), (16_000, 2));
        let served_ms = info.frames * 1000 / u64::from(info.sample_rate);
        assert_eq!(served_ms, out.duration_ms, "duration of the served audio");
        assert_eq!(
            finished.detail["duration_ms"],
            json!(out.duration_ms),
            "the library duration matches the audio"
        );

        // Range requests.
        let total = served.len();
        let ranged = |range: &'static str| {
            let (http, url) = (http.clone(), url.clone());
            async move { http.get(&url).header("Range", range).send().await.unwrap() }
        };
        let head = ranged("bytes=0-99").await;
        assert_eq!(head.status(), 206);
        assert_eq!(
            head.headers()["content-range"].to_str().unwrap(),
            format!("bytes 0-99/{total}")
        );
        assert_eq!(head.bytes().await.unwrap().as_ref(), &served[..100]);
        let tail = ranged("bytes=-100").await;
        assert_eq!(tail.status(), 206);
        assert_eq!(tail.bytes().await.unwrap().as_ref(), &served[total - 100..]);
        let open = ranged("bytes=1000-").await;
        assert_eq!(open.status(), 206);
        assert_eq!(open.bytes().await.unwrap().as_ref(), &served[1000..]);
        let beyond = http
            .get(&url)
            .header("Range", format!("bytes={}-{}", total + 10, total + 20))
            .send()
            .await
            .unwrap();
        assert_eq!(beyond.status(), 416);
        assert_eq!(
            beyond.headers()["content-range"].to_str().unwrap(),
            format!("bytes */{total}")
        );

        // The URL is signed: a changed signature, or none, is refused.
        let tampered = if let Some((head, signature)) = url.rsplit_once("signature=") {
            let flipped: String = signature
                .chars()
                .enumerate()
                .map(|(i, c)| {
                    if i == 0 {
                        if c == 'a' { 'b' } else { 'a' }
                    } else {
                        c
                    }
                })
                .collect();
            format!("{head}signature={flipped}")
        } else {
            panic!("the playback URL carries no signature: {url}");
        };
        assert_eq!(http.get(&tampered).send().await.unwrap().status(), 403);
        let unsigned = url.split('?').next().unwrap();
        assert_eq!(http.get(unsigned).send().await.unwrap().status(), 403);

        api.delete_session(&session_id).await.expect("cleanup");
    }
);

e2e_test!(
    voice_note_from_the_microphone_is_uploaded_as_mono_and_transcribed,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = record_voice_note(dir.path(), base_signal(cfg));
        assert_eq!(out.channels, 1);
        let pending = pending_voice_note(&out);
        let session_id = upload(&api, &pending).await.expect("upload");
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        let detail = &finished.detail;
        assert_eq!(text_at(detail, &["source_app"]), Some("voice_note"));
        assert_eq!(text_at(detail, &["source_app_label"]), Some("Voice note"));
        assert_eq!(text_at(detail, &["source_device"]), Some("desktop"));
        assert_eq!(detail["recordings"].as_array().unwrap().len(), 1);
        assert_eq!(detail["recordings"][0]["mime"], "audio/flac");
        assert_eq!(text_at(detail, &["status"]), Some("completed"));
        assert!(
            transcript_of(detail, "live").is_none(),
            "no captions for a note"
        );

        let transcript = api
            .transcript(&final_transcript_id(detail))
            .await
            .expect("transcript");
        assert_eq!(transcript["status"], "completed");
        let segments = transcript["segments"].as_array().unwrap();
        assert!(!segments.is_empty());
        for segment in segments {
            assert_eq!(
                (segment["channel"].as_u64(), segment["speaker"].as_str()),
                (Some(1), Some("Speaker")),
                "a mono recording has one speaker"
            );
        }

        let served = plain_http()
            .get(text_at(detail, &["playback_url"]).unwrap())
            .send()
            .await
            .unwrap()
            .bytes()
            .await
            .unwrap();
        let info = decode_flac(&served);
        assert_eq!((info.sample_rate, info.channels), (16_000, 1));
        assert_eq!(info.frames * 1000 / 16_000, out.duration_ms);
        api.delete_session(&session_id).await.expect("cleanup");
    }
);

e2e_test!(
    a_dropped_wav_file_is_uploaded_as_a_voice_note,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let source = record_voice_note(&dir.path().join("capture"), base_signal(cfg));
        // `import_voice_note` copies the dropped file into the queue directory and declares it mono.
        let dropped = dir.path().join("Voice note.wav");
        std::fs::copy(&source.mic_wav, &dropped).unwrap();
        let mut pending = pending_voice_note(&source);
        pending.client_ref = uuid::Uuid::new_v4().to_string();
        pending.primary = dropped.clone();
        pending.primary_channels = 1;
        pending.mic_wav = None;
        pending.system_wav = None;
        pending.duration_ms = 0;
        let session_id = upload(&api, &pending).await.expect("upload of a .wav");
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        assert_eq!(finished.detail["recordings"][0]["mime"], "audio/wav");
        assert_eq!(text_at(&finished.detail, &["status"]), Some("completed"));
        let served = plain_http()
            .get(text_at(&finished.detail, &["playback_url"]).unwrap())
            .send()
            .await
            .unwrap();
        assert_eq!(served.headers()["content-type"], "audio/wav");
        assert_eq!(
            sha256_hex(&served.bytes().await.unwrap()),
            file_sha256(&dropped).unwrap().0
        );
        api.delete_session(&session_id).await.expect("cleanup");
    }
);

e2e_test!(
    deleting_a_session_removes_media_and_leaves_a_tombstone,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let control = Control::of(cfg);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let mut pending = pending_call(&out, "google_meet");
        pending.live_segments = live_segments();
        let session_id = upload(&api, &pending).await.expect("upload");
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        let detail = finished.detail;
        let final_id = final_transcript_id(&detail);
        let live_id = transcript_of(&detail, "live").unwrap()["id"]
            .as_str()
            .unwrap()
            .to_string();
        let recording = recording_id(&detail);
        let playback = text_at(&detail, &["playback_url"]).unwrap().to_string();
        let http = plain_http();
        assert_eq!(
            http.get(&playback)
                .header("Range", "bytes=0-9")
                .send()
                .await
                .unwrap()
                .status(),
            206
        );

        let mut cache = LibraryCache::default();
        cache.refresh(&api).await.unwrap();
        assert!(cache.items.iter().any(|i| i.id == session_id));
        let since = cache.since.clone().expect("a sync point");
        pause(1_200).await; // timestamps have one second resolution

        api.delete_session(&session_id).await.expect("delete");

        // Everything behind the session is gone.
        for (what, result) in [
            ("session", api.session(&session_id).await.map(|_| ())),
            (
                "final transcript",
                api.transcript(&final_id).await.map(|_| ()),
            ),
            (
                "live transcript",
                api.transcript(&live_id).await.map(|_| ()),
            ),
            (
                "recording",
                api.recording_status(&recording).await.map(|_| ()),
            ),
        ] {
            let error = result.expect_err(what);
            assert_eq!(server_status(&error), Some(404), "{what}: {error}");
        }
        assert_eq!(
            server_status(
                &api.delete_session(&session_id)
                    .await
                    .expect_err("second delete")
            ),
            Some(404)
        );
        let late_chunk = api
            .upload_chunk(&recording, 0, vec![0u8; 16], &sha256_hex(&[0u8; 16]))
            .await
            .expect_err("no uploads into a deleted session");
        assert_eq!(server_status(&late_chunk), Some(404), "{late_chunk}");
        assert_eq!(
            http.get(&playback).send().await.unwrap().status(),
            404,
            "the signed URL stops working"
        );
        if let Some(control) = &control {
            let files = control.json(&["recording-files", &recording]);
            assert_eq!(
                files["files"], 0,
                "stored files are erased with the session"
            );
        }

        // The next sync carries the tombstone, and only the tombstone fields.
        let page = api
            .library(Some(since.as_str()), None)
            .await
            .expect("library since");
        let tombstone = page
            .data
            .iter()
            .find(|i| i.id == session_id)
            .expect("a tombstone for the deleted session");
        assert!(tombstone.deleted);
        let mut keys: Vec<String> = item_json(tombstone)
            .as_object()
            .unwrap()
            .keys()
            .cloned()
            .collect();
        keys.sort();
        assert_eq!(keys, vec!["client_ref", "deleted", "id", "updated_at"]);
        assert_eq!(
            item_json(tombstone)["client_ref"],
            json!(pending.client_ref)
        );

        // The app removes it from its cache, and a full listing no longer returns it.
        cache.refresh(&api).await.unwrap();
        assert!(!cache.items.iter().any(|i| i.id == session_id));
        let mut fresh = LibraryCache::default();
        fresh.refresh(&api).await.unwrap();
        assert!(!fresh.items.iter().any(|i| i.id == session_id));
    }
);

e2e_test!(
    a_session_deleted_on_one_device_disappears_from_the_library_of_another,
    400,
    |cfg, case| {
        let Some(control) = Control::of(cfg) else {
            return skipped(case, "needs ECHOLEDGER_E2E_CONTROL to pair a second device");
        };
        let a = identity(cfg).await;
        let other = new_device(cfg, &control, "second device").await;
        let api_a = api_for(cfg, &a.token);
        let api_b = api_for(cfg, &other.token);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let session_id = upload(&api_a, &pending_call(&out, "zoom"))
            .await
            .expect("upload");
        let finished = wait_for_final(&api_a, &session_id, Duration::from_secs(90)).await;

        let mut cache_b = LibraryCache::default();
        cache_b.refresh(&api_b).await.unwrap();
        let item = cache_b
            .items
            .iter()
            .find(|i| i.id == session_id)
            .expect("the second device sees the first device's session");
        let item = item_json(item);
        assert_eq!(item["device"]["id"], json!(a.device_id));
        assert_eq!(item["device"]["name"], json!(a.device_name));
        assert_eq!(item["source_device"], "desktop");
        let transcript = api_b
            .transcript(&final_transcript_id(&finished.detail))
            .await
            .expect("the second device reads the transcript");
        assert_eq!(transcript["status"], "completed");
        assert_eq!(
            api_b.session(&session_id).await.unwrap()["status"],
            "completed"
        );

        pause(1_200).await;
        api_a
            .delete_session(&session_id)
            .await
            .expect("delete on A");
        cache_b.refresh(&api_b).await.unwrap();
        assert!(
            !cache_b.items.iter().any(|i| i.id == session_id),
            "the deletion reached the other device with its next sync"
        );
        assert_eq!(
            server_status(&api_b.session(&session_id).await.unwrap_err()),
            Some(404)
        );
        control.run(&["revoke", &other.id]);
    }
);

e2e_test!(
    library_sync_pages_through_more_than_one_hundred_sessions,
    600,
    |cfg, case| {
        // A device of its own keeps this case clear of the 240 requests a minute limit shared with other cases.
        let device_token;
        let token: &str = match Control::of(cfg) {
            Some(control) => {
                device_token = new_device(cfg, &control, "paging").await.token;
                &device_token
            }
            None => &identity(cfg).await.token,
        };
        let api = api_for(cfg, token);
        let mut created: Vec<String> = Vec::new();
        for n in 0..101u64 {
            // Each tiny upload is finished at once, so the device never holds more unfinished uploads than the
            // server allows (50 per device); the sessions still all count for the paging below.
            let bytes = vec![b'a' + (n % 26) as u8; 2048 + n as usize];
            let mut request = session_request(
                &uuid::Uuid::new_v4().to_string(),
                "other",
                bytes.len() as u64,
            );
            request.artifacts[0].sha256 = sha256_hex(&bytes);
            let response = api.create_session(&request).await.expect("create");
            let recording = response.recordings[0].id.clone();
            api.upload_chunk(&recording, 0, bytes.clone(), &sha256_hex(&bytes))
                .await
                .expect("chunk");
            api.complete(&recording).await.expect("complete");
            created.push(response.session.id);
        }
        let mut cache = LibraryCache::default();
        let changed = cache.refresh(&api).await.expect("paged refresh");
        assert!(changed >= 101, "refresh reported {changed} changes");
        let mut ids: Vec<&str> = cache.items.iter().map(|i| i.id.as_str()).collect();
        let listed = ids.len();
        ids.sort_unstable();
        ids.dedup();
        assert_eq!(ids.len(), listed, "no session is listed twice across pages");
        for id in &created {
            assert!(
                ids.contains(&id.as_str()),
                "session {id} missing after paging"
            );
        }
        let starts: Vec<&str> = cache
            .items
            .iter()
            .map(|i| {
                i.rest
                    .get("started_at")
                    .and_then(|v| v.as_str())
                    .unwrap_or("")
            })
            .collect();
        assert!(starts.windows(2).all(|w| w[0] >= w[1]), "newest first");

        // Tombstones page the same way.
        pause(1_200).await;
        for id in created.iter().take(3) {
            api.delete_session(id).await.expect("delete");
        }
        cache.refresh(&api).await.unwrap();
        for id in created.iter().take(3) {
            assert!(
                !cache.items.iter().any(|i| &i.id == id),
                "{id} should be gone"
            );
        }
        assert!(cache.items.iter().any(|i| i.id == created[3]));
    }
);

e2e_test!(
    a_session_created_in_the_same_second_as_a_sync_is_picked_up_by_the_next_sync,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let mut cache = LibraryCache::default();
        let mut candidates = 0;
        let mut created_ids = Vec::new();
        let mut missed: Option<String> = None;
        for _ in 0..12 {
            cache.refresh(&api).await.unwrap();
            let since = cache.since.clone().unwrap();
            let request = session_request(&uuid::Uuid::new_v4().to_string(), "other", 4096);
            let session = api.create_session(&request).await.unwrap().session;
            created_ids.push(session.id.clone());
            let same_second = session.updated_at.get(..19) == since.get(..19);
            if !same_second {
                continue;
            }
            candidates += 1;
            cache.refresh(&api).await.unwrap();
            if !cache.items.iter().any(|i| i.id == session.id) {
                missed = Some(format!(
                    "session {} updated_at={} was created in the same second as the sync point {}",
                    session.id, session.updated_at, since
                ));
                break;
            }
        }
        for session_id in &created_ids {
            let _ = api.delete_session(session_id).await;
        }
        if candidates == 0 {
            return skipped(case, "no attempt landed in the same second as a sync");
        }
        if let Some(details) = missed {
            panic!(
                "a session created right after a sync is not returned by the next sync (the filter is updated_at > since with whole seconds): {details}"
            );
        }
    }
);

e2e_test!(
    repeating_an_upload_with_the_same_client_ref_reuses_the_session,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let proxy = Proxy::start(&cfg.base_url, pass_all);
        let through = api_at(&proxy.base_url(), &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let pending = pending_call(&out, "zoom");
        let first = upload(&through, &pending).await.expect("first upload");
        wait_for_final(&api, &first, Duration::from_secs(90)).await;

        let mark = proxy.mark();
        let second = upload(&through, &pending).await.expect("repeated upload");
        assert_eq!(first, second, "the same client_ref is the same session");
        assert!(
            proxy.chunk_requests_since(mark).is_empty(),
            "no chunk is sent again for a recording that is already complete"
        );
        assert!(proxy.requests_since(mark, "POST", "/complete").is_empty());

        let mut cache = LibraryCache::default();
        cache.refresh(&api).await.unwrap();
        let matching = cache
            .items
            .iter()
            .filter(|i| item_json(i)["client_ref"] == json!(pending.client_ref))
            .count();
        assert_eq!(matching, 1, "one session for one client_ref");

        // The raw API contract: 201 the first time, 200 afterwards, with the same ids.
        let http = plain_http();
        let body = serde_json::to_value(session_request(
            &uuid::Uuid::new_v4().to_string(),
            "other",
            5000,
        ))
        .unwrap();
        let url = format!("{}/api/sessions", cfg.base_url);
        let post = || {
            http.post(&url)
                .bearer_auth(&id.token)
                .header("Accept", "application/json")
                .json(&body)
                .send()
        };
        let created = post().await.unwrap();
        assert_eq!(created.status(), 201);
        let created: Value = created.json().await.unwrap();
        let again = post().await.unwrap();
        assert_eq!(again.status(), 200);
        let again: Value = again.json().await.unwrap();
        assert_eq!(created["session"]["id"], again["session"]["id"]);
        assert_eq!(created["recordings"][0]["id"], again["recordings"][0]["id"]);
        let _ = api
            .delete_session(created["session"]["id"].as_str().unwrap())
            .await;
        let _ = api.delete_session(&first).await;
    }
);

e2e_test!(
    re_uploading_a_session_that_was_deleted_elsewhere_ends_without_resurrecting_it,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let proxy = Proxy::start(&cfg.base_url, pass_all);
        let through = api_at(&proxy.base_url(), &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let pending = pending_call(&out, "other");
        let first = upload(&through, &pending).await.expect("upload");
        wait_for_final(&api, &first, Duration::from_secs(90)).await;
        api.delete_session(&first).await.expect("delete");

        // The desktop still has the item in its queue (for example it was offline when the upload finished).
        let mark = proxy.mark();
        let started = Instant::now();
        let result = upload(&through, &pending).await;
        assert!(
            result.is_ok(),
            "a queued upload of a session that was deleted on the server must end (the app retries failures every 30 s forever): {:?}",
            result.as_ref().err().map(|e| e.to_string())
        );
        assert!(started.elapsed() < Duration::from_secs(10));
        assert!(
            proxy.chunk_requests_since(mark).is_empty(),
            "no audio is sent for a deleted session"
        );
        assert_eq!(
            server_status(&api.session(&first).await.unwrap_err()),
            Some(404)
        );
        let mut cache = LibraryCache::default();
        cache.refresh(&api).await.unwrap();
        assert!(
            !cache.items.iter().any(|i| i.id == first),
            "not resurrected"
        );
    }
);

e2e_test!(
    every_source_app_the_client_sends_is_accepted_and_labelled_by_the_server,
    120,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let expected = [
            ("whatsapp", "WhatsApp"),
            ("whatsapp_business", "WhatsApp Business"),
            ("google_meet", "Google Meet"),
            ("zoom", "Zoom"),
            ("voice_note", "Voice note"),
            ("other", "Other"),
        ];
        for (source_app, label) in expected {
            let created = api
                .create_session(&session_request(
                    &uuid::Uuid::new_v4().to_string(),
                    source_app,
                    3000,
                ))
                .await
                .unwrap_or_else(|e| panic!("{source_app} rejected: {e}"));
            let session = item_json(&created.session);
            assert_eq!(session["source_app"], source_app);
            assert_eq!(session["source_app_label"], label);
            assert_eq!(
                session["source_device"], "desktop",
                "derived by the server, never sent"
            );
            assert_eq!(created.required_artifacts, vec!["primary".to_string()]);
            api.delete_session(&created.session.id).await.unwrap();
        }
        let error = api
            .create_session(&session_request(
                &uuid::Uuid::new_v4().to_string(),
                "skype",
                3000,
            ))
            .await
            .expect_err("an unknown source_app");
        assert_eq!(server_status(&error), Some(422), "{error}");
    }
);

e2e_test!(
    every_audio_type_the_app_can_import_is_accepted_by_the_server,
    600,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let mut rejected: Vec<String> = Vec::new();
        let mut sessions: Vec<String> = Vec::new();
        // The drop zone of the main window accepts these extensions (see `commands::import_voice_notes`).
        for extension in [
            "flac", "wav", "ogg", "opus", "mp3", "m4a", "mp4", "webm", "oga", "aac", "amr",
        ] {
            let file = dir.path().join(format!("note.{extension}"));
            std::fs::write(&file, vec![0x5Au8; 4096]).unwrap();
            let mut pending = pending_voice_note(&RecordingOutput {
                dir: dir.path().to_path_buf(),
                mic_wav: file.clone(),
                system_wav: file.clone(),
                primary: file.clone(),
                channels: 1,
                duration_ms: 0,
            });
            pending.mic_wav = None;
            pending.system_wav = None;
            match upload(&api, &pending).await {
                Ok(session_id) => sessions.push(session_id),
                Err(e) => rejected.push(format!(".{extension}: {e}")),
            }
        }
        for session_id in sessions {
            let _ = api.delete_session(&session_id).await;
        }
        assert!(rejected.is_empty(), "the server refuses: {rejected:?}");
    }
);

// ---- Resilience ----------------------------------------------------------------------------------------------

/// A call with at least `chunks` chunks on this server, plus the chunk size.
async fn call_with_chunks(
    cfg: &Config,
    api: &ApiClient,
    dir: &Path,
    chunks: u64,
) -> (RecordingOutput, u64, u64) {
    let chunk = api.config().await.unwrap().upload.chunk_size_bytes;
    let out = call_of_at_least(
        cfg,
        dir,
        (chunk * (chunks * 2 - 1) / 2).min(18 * 1024 * 1024),
    );
    let size = std::fs::metadata(&out.primary).unwrap().len();
    (out, chunk, size.div_ceil(chunk))
}

e2e_test!(
    an_interrupted_upload_resumes_with_only_the_missing_chunks,
    600,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let (out, _chunk, total) = call_with_chunks(cfg, &api, dir.path(), 4).await;
        assert!(total >= 4, "needs at least four chunks, got {total}");
        let cut = Arc::new(AtomicBool::new(true));
        let proxy = {
            let cut = cut.clone();
            Proxy::start(&cfg.base_url, move |r| {
                if cut.load(Ordering::SeqCst) && r.chunk.is_some_and(|c| c >= 2) {
                    Fault::Hangup
                } else {
                    Fault::Pass
                }
            })
        };
        let through = api_at(&proxy.base_url(), &id.token);
        let pending = pending_call(&out, "whatsapp");

        // The connection dies when chunk 2 is about to be sent: the call gives up after its retries.
        let started = Instant::now();
        let first = upload(&through, &pending).await;
        let error = first.expect_err("the upload cannot finish while the network is down");
        assert!(matches!(error, AppError::Network(_)), "{error}");
        assert!(
            started.elapsed() >= Duration::from_secs(14),
            "three retries with 2, 4 and 8 s back-off took {:?}",
            started.elapsed()
        );
        let tries: Vec<Seen> = proxy
            .chunk_requests_since(0)
            .into_iter()
            .filter(|s| s.chunk == Some(2))
            .collect();
        assert_eq!(tries.len(), 4, "one attempt and three retries of chunk 2");

        // The server kept what arrived.
        let mut cache = LibraryCache::default();
        cache.refresh(&api).await.unwrap();
        let item = cache
            .items
            .iter()
            .find(|i| item_json(i)["client_ref"] == json!(pending.client_ref))
            .expect("the session exists on the server");
        assert_eq!(item_json(item)["status"], "uploading");
        let session_id = item.id.clone();
        let detail = api.session(&session_id).await.unwrap();
        let recording = recording_id(&detail);
        assert_eq!(detail["recordings"][0]["status"], "uploading");
        assert!(
            detail["playback_url"].is_null(),
            "nothing to play before the upload is assembled"
        );
        assert!(
            transcript_of(&detail, "final").is_none(),
            "no transcription starts for a half-uploaded recording"
        );
        let state = api.recording_status(&recording).await.unwrap();
        assert_eq!(state.upload.received_chunks, vec![0, 1]);
        assert_eq!(state.upload.status, "uploading");

        // Network back: only the missing chunks go out.
        cut.store(false, Ordering::SeqCst);
        let mark = proxy.mark();
        let resumed = upload(&through, &pending)
            .await
            .expect("the resumed upload");
        assert_eq!(resumed, session_id, "same session");
        let indexes: Vec<u64> = proxy
            .chunk_requests_since(mark)
            .iter()
            .map(|c| c.chunk.unwrap())
            .collect();
        assert_eq!(
            indexes,
            (2..total).collect::<Vec<_>>(),
            "chunks 0 and 1 are not sent again"
        );
        assert_eq!(proxy.requests_since(mark, "POST", "/complete").len(), 1);

        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        assert_eq!(text_at(&finished.detail, &["status"]), Some("completed"));
        assert_assembled_file_matches(&api, &session_id, &out).await;
        api.delete_session(&session_id).await.unwrap();
    }
);

/// Playback URL of a session as seen by a client talking to the server directly.
async fn detail_playback_url(api: &ApiClient, session_id: &str) -> String {
    api.session(session_id).await.unwrap()["playback_url"]
        .as_str()
        .expect("playback URL")
        .to_string()
}

e2e_test!(
    an_upload_that_stopped_before_complete_is_finished_by_the_next_attempt,
    600,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let cut = Arc::new(AtomicBool::new(true));
        let proxy = {
            let cut = cut.clone();
            Proxy::start(&cfg.base_url, move |r| {
                if cut.load(Ordering::SeqCst) && r.path.ends_with("/complete") {
                    Fault::Hangup
                } else {
                    Fault::Pass
                }
            })
        };
        let through = api_at(&proxy.base_url(), &id.token);
        let pending = pending_call(&out, "zoom");
        let error = upload(&through, &pending)
            .await
            .expect_err("complete is unreachable");
        assert!(matches!(error, AppError::Network(_)), "{error}");
        let chunk_count = proxy.chunk_requests_since(0).len();
        assert!(
            chunk_count >= 1,
            "every chunk was delivered before the failure"
        );

        let mut cache = LibraryCache::default();
        cache.refresh(&api).await.unwrap();
        let session_id = cache
            .items
            .iter()
            .find(|i| item_json(i)["client_ref"] == json!(pending.client_ref))
            .expect("session exists")
            .id
            .clone();
        let recording = recording_id(&api.session(&session_id).await.unwrap());
        let state = api.recording_status(&recording).await.unwrap();
        assert_eq!(
            state.upload.received_chunks.len() as u64,
            state.upload.total_chunks
        );
        assert_eq!(state.upload.status, "uploading", "not completed yet");

        cut.store(false, Ordering::SeqCst);
        let mark = proxy.mark();
        let again = upload(&through, &pending).await.expect("second attempt");
        assert_eq!(again, session_id);
        assert!(
            proxy.chunk_requests_since(mark).is_empty(),
            "nothing left to send"
        );
        assert_eq!(proxy.requests_since(mark, "POST", "/complete").len(), 1);
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        assert_eq!(text_at(&finished.detail, &["status"]), Some("completed"));
        api.delete_session(&session_id).await.unwrap();
    }
);

e2e_test!(
    a_transient_503_on_a_chunk_is_retried_with_back_off_and_the_upload_completes,
    600,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let (out, _chunk, total) = call_with_chunks(cfg, &api, dir.path(), 3).await;
        let proxy = Proxy::start(&cfg.base_url, |r| {
            if r.chunk == Some(1) && r.attempt <= 2 {
                Fault::Respond {
                    status: 503,
                    headers: vec![],
                    body: json!({"message": "Service Unavailable."}).to_string(),
                }
            } else {
                Fault::Pass
            }
        });
        let through = api_at(&proxy.base_url(), &id.token);
        let started = Instant::now();
        let session_id = upload(&through, &upload_pending(&out))
            .await
            .expect("upload despite two 503s");
        assert!(
            started.elapsed() >= Duration::from_secs(6),
            "2 s then 4 s of back-off: {:?}",
            started.elapsed()
        );
        let tries: Vec<Seen> = proxy
            .chunk_requests_since(0)
            .into_iter()
            .filter(|s| s.chunk == Some(1))
            .collect();
        assert_eq!(tries.len(), 3, "two failures and a success for chunk 1");
        assert_eq!(
            tries.iter().map(|t| t.status).collect::<Vec<_>>(),
            vec![Some(503), Some(503), Some(200)]
        );
        let all: Vec<u64> = proxy
            .chunk_requests_since(0)
            .iter()
            .filter(|s| s.status == Some(200))
            .map(|s| s.chunk.unwrap())
            .collect();
        assert_eq!(all, (0..total).collect::<Vec<_>>());
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        assert_eq!(text_at(&finished.detail, &["status"]), Some("completed"));
        assert_assembled_file_matches(&api, &session_id, &out).await;
        api.delete_session(&session_id).await.unwrap();
    }
);

fn upload_pending(out: &RecordingOutput) -> PendingUpload {
    pending_call(out, "whatsapp")
}

/// The audio the server serves for a session is byte for byte the file the app recorded.
async fn assert_assembled_file_matches(api: &ApiClient, session_id: &str, out: &RecordingOutput) {
    let url = detail_playback_url(api, session_id).await;
    let bytes = plain_http()
        .get(&url)
        .send()
        .await
        .unwrap()
        .bytes()
        .await
        .unwrap();
    assert_eq!(
        sha256_hex(&bytes),
        file_sha256(&out.primary).unwrap().0,
        "the assembled file is the recorded file"
    );
}

e2e_test!(
    a_response_lost_after_the_server_stored_a_chunk_is_resent_idempotently,
    600,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let (out, _chunk, total) = call_with_chunks(cfg, &api, dir.path(), 3).await;
        let proxy = Proxy::start(&cfg.base_url, |r| {
            if r.chunk == Some(0) && r.attempt == 1 {
                Fault::LoseResponse
            } else {
                Fault::Pass
            }
        });
        let through = api_at(&proxy.base_url(), &id.token);
        let session_id = upload(&through, &upload_pending(&out))
            .await
            .expect("upload");
        let tries: Vec<Seen> = proxy
            .chunk_requests_since(0)
            .into_iter()
            .filter(|s| s.chunk == Some(0))
            .collect();
        assert_eq!(tries.len(), 2, "chunk 0 was sent twice");
        assert_eq!(tries[0].fault, "lost-response");
        assert_eq!(tries[0].status, Some(200), "the server had stored it");
        assert_eq!(
            tries[1].status,
            Some(200),
            "the repeat is an idempotent 200"
        );
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        assert_eq!(text_at(&finished.detail, &["status"]), Some("completed"));
        let recording = recording_id(&finished.detail);
        let state = api.recording_status(&recording).await.unwrap();
        assert_eq!(state.upload.total_chunks, total);
        assert_assembled_file_matches(&api, &session_id, &out).await;
        api.delete_session(&session_id).await.unwrap();
    }
);

e2e_test!(
    a_chunk_corrupted_in_transit_is_refused_by_the_server_and_sent_again,
    600,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let (out, _chunk, _total) = call_with_chunks(cfg, &api, dir.path(), 3).await;
        let proxy = Proxy::start(&cfg.base_url, |r| {
            if r.chunk == Some(1) && r.attempt == 1 {
                Fault::Corrupt
            } else {
                Fault::Pass
            }
        });
        let through = api_at(&proxy.base_url(), &id.token);
        let pending = upload_pending(&out);
        // The refused chunk is sent again, either within this attempt or by the next one; either way the corrupted
        // bytes are never stored.
        let session_id = match upload(&through, &pending).await {
            Ok(session_id) => session_id,
            Err(error) => {
                assert_eq!(server_status(&error), Some(422), "{error}");
                upload(&through, &pending)
                    .await
                    .expect("the next attempt sends the chunk again")
            }
        };
        let tries: Vec<Seen> = proxy
            .chunk_requests_since(0)
            .into_iter()
            .filter(|s| s.chunk == Some(1))
            .collect();
        assert_eq!(tries.len(), 2);
        assert_eq!(tries[0].fault, "corrupt");
        assert_eq!(
            tries[0].status,
            Some(422),
            "the checksum mismatch is refused"
        );
        assert_eq!(tries[1].status, Some(200));
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        assert_eq!(text_at(&finished.detail, &["status"]), Some("completed"));
        assert_assembled_file_matches(&api, &session_id, &out).await;
        api.delete_session(&session_id).await.unwrap();
    }
);

e2e_test!(
    a_rate_limited_chunk_waits_for_the_retry_after_the_server_asked_for,
    600,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let (out, _chunk, _total) = call_with_chunks(cfg, &api, dir.path(), 3).await;
        let proxy = Proxy::start(&cfg.base_url, |r| {
            if r.chunk == Some(0) && r.attempt == 1 {
                Fault::Respond {
                    status: 429,
                    headers: vec![("Retry-After", "5".into())],
                    body: json!({"message": "Too Many Attempts."}).to_string(),
                }
            } else {
                Fault::Pass
            }
        });
        let through = api_at(&proxy.base_url(), &id.token);
        let session_id = upload(&through, &upload_pending(&out))
            .await
            .expect("upload");
        let tries: Vec<Seen> = proxy
            .chunk_requests_since(0)
            .into_iter()
            .filter(|s| s.chunk == Some(0))
            .collect();
        assert_eq!(tries.len(), 2);
        let waited = tries[1].at.duration_since(tries[0].at);
        api.delete_session(&session_id).await.unwrap();
        assert!(
            waited >= Duration::from_secs(5),
            "docs/API.md says to honour Retry-After; the server asked for 5 s and the client came back after {waited:?}"
        );
    }
);

e2e_test!(
    a_server_restart_in_the_middle_of_an_upload_is_survived,
    600,
    |cfg, case| {
        let Some(control) = Control::of(cfg) else {
            return skipped(
                case,
                "needs ECHOLEDGER_E2E_CONTROL to stop and start the server",
            );
        };
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let (out, _chunk, total) = call_with_chunks(cfg, &api, dir.path(), 4).await;
        let proxy = {
            let control = control.clone();
            Proxy::start(&cfg.base_url, move |r| {
                if r.chunk == Some(2) && r.attempt == 1 {
                    control.run(&["stop-server"]); // the server goes away before chunk 2 is delivered
                    Fault::Pass // forwarded to a server that is not there
                } else if r.chunk == Some(2) && r.attempt == 2 {
                    control.run(&["start-server"]); // back by the time the first retry arrives
                    Fault::Pass
                } else {
                    Fault::Pass
                }
            })
        };
        let through = api_at(&proxy.base_url(), &id.token);
        let session_id = upload(&through, &upload_pending(&out))
            .await
            .expect("the upload rides out the restart");
        let tries: Vec<Seen> = proxy
            .chunk_requests_since(0)
            .into_iter()
            .filter(|s| s.chunk == Some(2))
            .collect();
        assert_eq!(
            tries[0].fault, "upstream-error",
            "chunk 2 met a stopped server"
        );
        assert_eq!(tries.last().unwrap().status, Some(200));
        let delivered: Vec<u64> = proxy
            .chunk_requests_since(0)
            .iter()
            .filter(|s| s.status == Some(200))
            .map(|s| s.chunk.unwrap())
            .collect();
        assert_eq!(
            delivered,
            (0..total).collect::<Vec<_>>(),
            "each chunk stored exactly once"
        );
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        assert_eq!(text_at(&finished.detail, &["status"]), Some("completed"));
        assert_assembled_file_matches(&api, &session_id, &out).await;
        api.delete_session(&session_id).await.unwrap();
    }
);

// ---- Chunk and completion contracts --------------------------------------------------------------------------

/// A session with an upload slot of `parts` chunks that will never be assembled: only chunk bytes are sent.
async fn open_slot(api: &ApiClient, parts: u64) -> (String, String, u64) {
    let chunk = api.config().await.unwrap().upload.chunk_size_bytes;
    let size = chunk * (parts - 1) + 1000;
    let created = api
        .create_session(&session_request(
            &uuid::Uuid::new_v4().to_string(),
            "other",
            size,
        ))
        .await
        .expect("create session");
    let recording = &created.recordings[0];
    assert_eq!(recording.upload.total_chunks, parts);
    assert_eq!(recording.upload.chunk_size_bytes, chunk);
    assert_eq!(recording.upload.status, "pending");
    (created.session.id.clone(), recording.id.clone(), chunk)
}

e2e_test!(
    completing_before_all_chunks_arrived_is_refused_and_lists_what_is_missing,
    120,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let (session_id, recording, chunk) = open_slot(&api, 3).await;
        let bytes = vec![7u8; chunk as usize];
        let state = api
            .upload_chunk(&recording, 0, bytes.clone(), &sha256_hex(&bytes))
            .await
            .unwrap();
        assert_eq!(state.received_chunks, vec![0]);
        assert_eq!(state.total_chunks, 3);
        assert_eq!(state.status, "uploading");

        let error = api.complete(&recording).await.expect_err("incomplete");
        assert_eq!(server_status(&error), Some(422), "{error}");
        assert!(
            server_message(&error).contains("incomplete"),
            "{}",
            server_message(&error)
        );
        let raw = plain_http()
            .post(format!(
                "{}/api/recordings/{recording}/complete",
                cfg.base_url
            ))
            .bearer_auth(&id.token)
            .header("Accept", "application/json")
            .send()
            .await
            .unwrap();
        assert_eq!(raw.status(), 422);
        let raw: Value = raw.json().await.unwrap();
        assert_eq!(raw["missing_chunks"], json!([1, 2]));
        let resume = api.recording_status(&recording).await.unwrap();
        assert_eq!(resume.upload.received_chunks, vec![0]);
        assert_eq!(resume.upload.status, "uploading");
        api.delete_session(&session_id).await.unwrap();
    }
);

e2e_test!(
    chunk_requests_are_validated_by_index_checksum_and_length,
    120,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let (session_id, recording, chunk) = open_slot(&api, 3).await;
        let good = vec![9u8; chunk as usize];
        let good_sha = sha256_hex(&good);

        let first = api
            .upload_chunk(&recording, 0, good.clone(), &good_sha)
            .await
            .unwrap();
        let again = api
            .upload_chunk(&recording, 0, good.clone(), &good_sha)
            .await
            .unwrap();
        assert_eq!(first.received_chunks, vec![0]);
        assert_eq!(
            again.received_chunks,
            vec![0],
            "re-sending a chunk is a no-op"
        );

        let wrong_sha = api
            .upload_chunk(&recording, 1, good.clone(), &sha256_hex(b"other"))
            .await
            .unwrap_err();
        assert_eq!(server_status(&wrong_sha), Some(422), "{wrong_sha}");
        assert!(
            server_message(&wrong_sha)
                .to_lowercase()
                .contains("checksum"),
            "{}",
            server_message(&wrong_sha)
        );
        let short = vec![9u8; chunk as usize - 1];
        let wrong_len = api
            .upload_chunk(&recording, 1, short.clone(), &sha256_hex(&short))
            .await
            .unwrap_err();
        assert_eq!(server_status(&wrong_len), Some(422), "{wrong_len}");
        let long = vec![9u8; chunk as usize + 1];
        let too_long = api
            .upload_chunk(&recording, 1, long.clone(), &sha256_hex(&long))
            .await
            .unwrap_err();
        assert_eq!(server_status(&too_long), Some(413), "{too_long}");
        let out_of_range = api
            .upload_chunk(&recording, 3, good.clone(), &good_sha)
            .await
            .unwrap_err();
        assert_eq!(server_status(&out_of_range), Some(422), "{out_of_range}");
        let unknown = api
            .upload_chunk("01ARZ3NDEKTSV4RRFFQ69G5FAV", 0, good.clone(), &good_sha)
            .await
            .unwrap_err();
        assert_eq!(server_status(&unknown), Some(404), "{unknown}");

        // Header problems, which the typed client cannot produce.
        let http = plain_http();
        let url = format!("{}/api/recordings/{recording}/chunks", cfg.base_url);
        let missing_index = http
            .post(&url)
            .bearer_auth(&id.token)
            .header("Accept", "application/json")
            .header("Content-Type", "application/octet-stream")
            .header("Upload-Chunk-Sha256", &good_sha)
            .body(good.clone())
            .send()
            .await
            .unwrap();
        assert_eq!(missing_index.status(), 422);
        let missing_sha = http
            .post(&url)
            .bearer_auth(&id.token)
            .header("Accept", "application/json")
            .header("Content-Type", "application/octet-stream")
            .header("Upload-Chunk-Index", "1")
            .body(good.clone())
            .send()
            .await
            .unwrap();
        assert_eq!(missing_sha.status(), 422);
        let junk_index = http
            .post(&url)
            .bearer_auth(&id.token)
            .header("Accept", "application/json")
            .header("Content-Type", "application/octet-stream")
            .header("Upload-Chunk-Index", "abc")
            .header("Upload-Chunk-Sha256", &good_sha)
            .body(good.clone())
            .send()
            .await
            .unwrap();
        assert_eq!(junk_index.status(), 422);

        // None of the refusals changed what the server holds.
        let state = api.recording_status(&recording).await.unwrap();
        assert_eq!(state.upload.received_chunks, vec![0]);
        api.delete_session(&session_id).await.unwrap();
    }
);

e2e_test!(
    a_chunk_sent_after_the_recording_is_complete_is_a_conflict,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = record_voice_note(dir.path(), base_signal(cfg));
        let bytes = std::fs::read(&out.primary).unwrap();
        let chunk = api.config().await.unwrap().upload.chunk_size_bytes;
        assert!(
            (bytes.len() as u64) <= chunk,
            "this case needs a one chunk recording"
        );
        let session_id = upload(&api, &pending_voice_note(&out))
            .await
            .expect("upload");
        let recording = recording_id(&api.session(&session_id).await.unwrap());
        let late = api
            .upload_chunk(&recording, 0, bytes.clone(), &sha256_hex(&bytes))
            .await
            .expect_err("the upload is already complete");
        assert_eq!(server_status(&late), Some(409), "{late}");
        api.complete(&recording)
            .await
            .expect("completing twice is harmless");
        wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        api.delete_session(&session_id).await.unwrap();
    }
);

// ---- Failure paths -------------------------------------------------------------------------------------------

/// Every authenticated call of the client, with a token the server will not accept.
async fn every_call_is_not_paired(cfg: &Config, token: Option<&str>) -> Vec<String> {
    init_tls();
    let api = ApiClient::new(&cfg.base_url, &[], token.map(String::from)).unwrap();
    let id = "01ARZ3NDEKTSV4RRFFQ69G5FAV";
    let sha = sha256_hex(b"x");
    let mut wrong: Vec<String> = Vec::new();
    let mut check = |name: &str, ok: bool| {
        if !ok {
            wrong.push(name.to_string());
        }
    };
    check("config", is_not_paired(&api.config().await));
    check("clients", is_not_paired(&api.clients().await));
    check(
        "stt_token",
        is_not_paired(&api.stt_token("live_captions", &["me"], None, None, None).await),
    );
    check(
        "create_session",
        is_not_paired(&api.create_session(&session_request(id, "other", 10)).await),
    );
    check(
        "recording_status",
        is_not_paired(&api.recording_status(id).await),
    );
    check(
        "upload_chunk",
        is_not_paired(&api.upload_chunk(id, 0, vec![1], &sha).await),
    );
    check("complete", is_not_paired(&api.complete(id).await));
    check(
        "post_transcript",
        is_not_paired(
            &api.post_transcript(
                id,
                &desktop_lib::net::models::DeviceTranscript {
                    kind: "live".into(),
                    provider: "assemblyai".into(),
                    language: None,
                    segments: vec![],
                },
            )
            .await,
        ),
    );
    check("library", is_not_paired(&api.library(None, None).await));
    check("session", is_not_paired(&api.session(id).await));
    check("transcript", is_not_paired(&api.transcript(id).await));
    check(
        "delete_session",
        is_not_paired(&api.delete_session(id).await),
    );
    wrong
}

e2e_test!(
    a_bad_token_is_reported_as_not_paired_by_every_call,
    180,
    |cfg, case| {
        for token in [
            "not-a-token",
            "",
            "1|eld_0000000000000000000000000000000000000000",
        ] {
            let wrong = every_call_is_not_paired(cfg, Some(token)).await;
            assert!(
                wrong.is_empty(),
                "token {token:?}: these calls did not report NotPaired: {wrong:?}"
            );
        }
        let wrong = every_call_is_not_paired(cfg, None).await;
        assert!(wrong.is_empty(), "no token: {wrong:?}");

        // An upload with a bad token stops at once, sends nothing and keeps the local files for a later attempt.
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let proxy = Proxy::start(&cfg.base_url, pass_all);
        let through = api_at(
            &proxy.base_url(),
            "1|eld_0000000000000000000000000000000000000000",
        );
        let started = Instant::now();
        let result = upload(&through, &pending_call(&out, "zoom")).await;
        assert!(
            matches!(result, Err(AppError::NotPaired)),
            "{:?}",
            result.err().map(|e| e.to_string())
        );
        assert!(
            started.elapsed() < Duration::from_secs(5),
            "no retries for a 401"
        );
        assert_eq!(proxy.seen().len(), 1, "only the config request was made");
        assert!(out.primary.exists() && out.mic_wav.exists() && out.system_wav.exists());
    }
);

e2e_test!(
    a_revoked_device_is_not_paired_anymore_and_keeps_its_queued_files,
    400,
    |cfg, case| {
        let Some(control) = Control::of(cfg) else {
            return skipped(case, "needs ECHOLEDGER_E2E_CONTROL to revoke a device");
        };
        let main = identity(cfg).await;
        let device = new_device(cfg, &control, "to be revoked").await;
        let api = api_for(cfg, &device.token);
        let dir = tempfile::tempdir().unwrap();
        let first = small_call(cfg, &dir.path().join("first"));
        let session_id = upload(&api, &pending_call(&first, "zoom"))
            .await
            .expect("works before the revocation");
        wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        assert!(api.config().await.is_ok());

        control.run(&["revoke", &device.id]);

        let wrong = every_call_is_not_paired(cfg, Some(&device.token)).await;
        assert!(
            wrong.is_empty(),
            "after the revocation these calls did not report NotPaired: {wrong:?}"
        );
        assert!(is_not_paired(&api.library(None, None).await));
        assert!(is_not_paired(&api.session(&session_id).await));

        // A queued upload fails fast with NotPaired and leaves the recording where it is.
        let second = small_call(cfg, &dir.path().join("second"));
        let started = Instant::now();
        let result = upload(&api, &pending_call(&second, "zoom")).await;
        assert!(
            matches!(result, Err(AppError::NotPaired)),
            "{:?}",
            result.err().map(|e| e.to_string())
        );
        assert!(started.elapsed() < Duration::from_secs(5));
        assert!(second.primary.exists() && second.mic_wav.exists() && second.system_wav.exists());

        // What the device uploaded stays in the library for everybody else.
        let main_api = api_for(cfg, &main.token);
        let kept = main_api
            .session(&session_id)
            .await
            .expect("the data outlives the device");
        assert_eq!(kept["status"], "completed");
        let mut cache = LibraryCache::default();
        cache.refresh(&main_api).await.unwrap();
        assert!(cache.items.iter().any(|i| i.id == session_id));
        main_api.delete_session(&session_id).await.unwrap();
    }
);

e2e_test!(
    an_expired_token_is_reported_as_not_paired,
    240,
    |cfg, case| {
        let Some(control) = Control::of(cfg) else {
            return skipped(case, "needs ECHOLEDGER_E2E_CONTROL to expire a token");
        };
        let device = new_device(cfg, &control, "expiring").await;
        let api = api_for(cfg, &device.token);
        assert!(api.config().await.is_ok());
        control.run(&["expire-tokens", &device.id]);
        let wrong = every_call_is_not_paired(cfg, Some(&device.token)).await;
        assert!(wrong.is_empty(), "{wrong:?}");
        control.run(&["revoke", &device.id]);
    }
);

e2e_test!(
    token_rotation_issues_a_new_token_and_revokes_the_old_one,
    300,
    |cfg, case| {
        let Some(control) = Control::of(cfg) else {
            return skipped(
                case,
                "needs ECHOLEDGER_E2E_CONTROL to pair a device that can be rotated",
            );
        };
        let device = new_device(cfg, &control, "rotating").await;
        let http = plain_http();
        let rotate = |token: String| {
            let (http, url) = (
                http.clone(),
                format!("{}/api/devices/rotate-token", cfg.base_url),
            );
            async move {
                http.post(url)
                    .bearer_auth(token)
                    .header("Accept", "application/json")
                    .send()
                    .await
                    .unwrap()
            }
        };
        let response = rotate(device.token.clone()).await;
        assert_eq!(response.status(), 200);
        let body: Value = response.json().await.unwrap();
        let new_token = body["token"].as_str().expect("a new token").to_string();
        assert_ne!(new_token, device.token);
        assert!(new_token.contains("eld_"));
        let days = (rfc3339_seconds(body["token_expires_at"].as_str().unwrap())
            - chrono::Utc::now().timestamp())
            / 86_400;
        assert!((360..=366).contains(&days), "{days} days");

        // The old token is dead at once; the new one carries every ability the app uses.
        let old = api_for(cfg, &device.token);
        assert!(
            is_not_paired(&old.config().await),
            "the old token must stop working immediately"
        );
        assert_eq!(rotate(device.token.clone()).await.status(), 401);
        let api = api_for(cfg, &new_token);
        let config = api.config().await.expect("config:read");
        assert_eq!(config.device.id, device.id, "same device");
        api.library(None, None).await.expect("library:read");
        // Dictation needs no open session, so this checks the ability alone.
        api.stt_token("dictation", &["me"], None, None, None)
            .await
            .expect("stt:token");
        let created = api
            .create_session(&session_request(
                &uuid::Uuid::new_v4().to_string(),
                "other",
                5000,
            ))
            .await
            .expect("sessions:write");
        api.recording_status(&created.recordings[0].id)
            .await
            .expect("recordings:upload");
        api.delete_session(&created.session.id).await.unwrap();

        // Rotation can be repeated, and each generation replaces the previous one.
        let second = rotate(new_token.clone()).await;
        assert_eq!(second.status(), 200);
        let second: Value = second.json().await.unwrap();
        assert!(is_not_paired(&api.config().await));
        assert!(
            api_for(cfg, second["token"].as_str().unwrap())
                .config()
                .await
                .is_ok()
        );
        control.run(&["revoke", &device.id]);
    }
);

e2e_test!(
    an_empty_audio_file_is_refused_by_the_server_with_a_validation_error,
    120,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let empty = dir.path().join("empty.flac");
        std::fs::write(&empty, b"").unwrap();
        let mut pending = pending_call(
            &RecordingOutput {
                dir: dir.path().to_path_buf(),
                mic_wav: empty.clone(),
                system_wav: empty.clone(),
                primary: empty.clone(),
                channels: 1,
                duration_ms: 0,
            },
            "voice_note",
        );
        pending.mic_wav = None;
        pending.system_wav = None;
        let started = Instant::now();
        let error = upload(&api, &pending)
            .await
            .expect_err("a zero-byte file cannot be uploaded");
        assert_eq!(server_status(&error), Some(422), "{error}");
        assert!(
            server_message(&error).contains("size_bytes"),
            "{}",
            server_message(&error)
        );
        assert!(
            started.elapsed() < Duration::from_secs(10),
            "a validation error is not retried"
        );
        let mut cache = LibraryCache::default();
        cache.refresh(&api).await.unwrap();
        assert!(
            !cache
                .items
                .iter()
                .any(|i| item_json(i)["client_ref"] == json!(pending.client_ref)),
            "no session is created for an empty file"
        );
    }
);

e2e_test!(
    audio_below_the_size_floor_ends_as_a_failed_transcript_with_a_readable_message,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = record_call(dir.path(), silence(1, 0));
        let size = std::fs::metadata(&out.primary).unwrap().len();
        assert!(
            size < 1024,
            "one second of digital silence is a tiny FLAC, got {size} bytes"
        );
        let session_id = upload(&api, &pending_call(&out, "whatsapp"))
            .await
            .expect("the upload itself works");
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(60)).await;
        let transcript = api
            .transcript(&final_transcript_id(&finished.detail))
            .await
            .unwrap();
        assert_eq!(transcript["status"], "failed");
        assert_eq!(text_at(&finished.detail, &["status"]), Some("failed"));
        let message = transcript["error"].as_str().unwrap_or("");
        assert!(
            !message.trim().is_empty(),
            "a failed transcript explains itself"
        );
        assert_readable(message);

        let mut cache = LibraryCache::default();
        cache.refresh(&api).await.unwrap();
        let item = item_json(cache.items.iter().find(|i| i.id == session_id).unwrap());
        assert_eq!(item["transcript"]["final_status"], "failed");
        assert_eq!(item["status"], "failed");
        api.delete_session(&session_id).await.unwrap();

        // The remedy in the message has to fit the platform of the recording (this one came from the desktop app).
        assert!(
            !message.contains("Android"),
            "the message sends a desktop user to Android settings: {message:?}"
        );
    }
);

e2e_test!(
    a_range_that_starts_at_or_after_the_end_of_the_audio_is_unsatisfiable,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let session_id = upload(&api, &pending_call(&out, "zoom"))
            .await
            .expect("upload");
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        let url = text_at(&finished.detail, &["playback_url"])
            .unwrap()
            .to_string();
        let total = std::fs::metadata(&out.primary).unwrap().len();
        let http = plain_http();
        let mut wrong: Vec<String> = Vec::new();
        for range in [
            format!("bytes={total}-"),
            format!("bytes={}-", total + 10),
            format!("bytes={}-", total + 1_000_000),
            format!("bytes={}-{}", total + 10, total + 20),
        ] {
            let response = http.get(&url).header("Range", &range).send().await.unwrap();
            let status = response.status().as_u16();
            let content_range = response
                .headers()
                .get("content-range")
                .and_then(|v| v.to_str().ok())
                .unwrap_or("-")
                .to_string();
            if status != 416 || content_range != format!("bytes */{total}") {
                wrong.push(format!("{range} -> {status} content-range {content_range}"));
            }
        }
        api.delete_session(&session_id).await.unwrap();
        assert!(
            wrong.is_empty(),
            "docs/API.md promises 206/416 and RFC 9110 calls these ranges unsatisfiable (416 with 'bytes */{total}'): {wrong:?}"
        );
    }
);

e2e_test!(
    a_queued_upload_survives_a_restart_of_the_app_and_is_uploaded_from_the_queue_file,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let data_dir = tempfile::tempdir().unwrap();
        let queue_path = data_dir.path().join("uploads.json");
        let recordings = data_dir.path().join("recordings");
        let out = small_call(cfg, &recordings.join(uuid::Uuid::new_v4().to_string()));
        let mut pending = pending_call(&out, "google_meet");
        pending.live_segments = live_segments();
        pending.live_provider = Some("assemblyai".into());
        pending.attempts = 2;
        pending.last_error = Some("Network error: offline".into());

        // The app queued the recording and was closed.
        let queue = UploadQueue {
            items: vec![pending.clone()],
        };
        queue.save(&queue_path).expect("save the queue");
        drop(queue);

        // The app starts again: the queue is read from disk and the upload worker's loop runs.
        let restored = UploadQueue::load(&queue_path);
        assert_eq!(restored.items.len(), 1);
        let item = &restored.items[0];
        assert_eq!(item.client_ref, pending.client_ref);
        assert_eq!(item.attempts, 2);
        assert_eq!(item.live_segments.len(), 3);
        let session_id = upload(&api, item).await.expect("the restored item uploads");
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        assert_eq!(text_at(&finished.detail, &["status"]), Some("completed"));
        assert_eq!(
            text_at(&finished.detail, &["source_app"]),
            Some("google_meet")
        );
        assert!(
            transcript_of(&finished.detail, "live").is_some(),
            "the captions kept in the queue were uploaded"
        );
        assert_assembled_file_matches(&api, &session_id, &out).await;
        api.delete_session(&session_id).await.unwrap();
    }
);

/// A message meant for the person using the app: no stack trace, class name, file path or raw JSON.
fn assert_readable(message: &str) {
    for needle in [
        "Stack trace",
        "#0 ",
        "App\\",
        ".php",
        "Exception",
        "{\"",
        "cURL error",
    ] {
        assert!(
            !message.contains(needle),
            "the message exposes internals ({needle:?}): {message:?}"
        );
    }
    assert!(
        message.chars().count() <= 400,
        "a long message: {message:?}"
    );
}

e2e_test!(
    silence_that_reaches_the_provider_gives_an_empty_completed_transcript,
    300,
    |cfg, case| {
        let Some(control) = Control::of(cfg) else {
            return skipped(
                case,
                "needs ECHOLEDGER_E2E_CONTROL to set the speech provider double to 'empty'",
            );
        };
        let _mode = ProviderMode::set(&control, "empty", 1);
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = record_call(dir.path(), silence(2, 3));
        assert!(
            std::fs::metadata(&out.primary).unwrap().len() >= 1024,
            "above the size floor"
        );
        let session_id = upload(&api, &pending_call(&out, "zoom"))
            .await
            .expect("upload");
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(60)).await;
        let transcript = api
            .transcript(&final_transcript_id(&finished.detail))
            .await
            .unwrap();
        assert_eq!(transcript["status"], "completed");
        assert_eq!(transcript["text"].as_str().unwrap_or("").trim(), "");
        assert_eq!(transcript["segments"], json!([]));
        assert_eq!(text_at(&finished.detail, &["status"]), Some("completed"));
        let mut cache = LibraryCache::default();
        cache.refresh(&api).await.unwrap();
        let item = item_json(cache.items.iter().find(|i| i.id == session_id).unwrap());
        assert_eq!(item["transcript"]["final_status"], "completed");
        assert!(
            item["transcript"]["preview"].is_null(),
            "no preview for an empty transcript"
        );
        api.delete_session(&session_id).await.unwrap();
    }
);

e2e_test!(
    a_provider_failure_gives_a_failed_transcript_with_a_readable_message,
    300,
    |cfg, case| {
        let Some(control) = Control::of(cfg) else {
            return skipped(
                case,
                "needs ECHOLEDGER_E2E_CONTROL to set the speech provider double to 'fail'",
            );
        };
        let _mode = ProviderMode::set(&control, "fail", 1);
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let session_id = upload(&api, &pending_call(&out, "whatsapp"))
            .await
            .expect("upload");
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(60)).await;
        assert_eq!(text_at(&finished.detail, &["status"]), Some("failed"));
        let transcript = api
            .transcript(&final_transcript_id(&finished.detail))
            .await
            .unwrap();
        assert_eq!(transcript["status"], "failed");
        assert!(transcript["completed_at"].is_null() || transcript["completed_at"].is_string());
        let message = transcript["error"].as_str().unwrap_or("");
        assert!(
            !message.trim().is_empty(),
            "the failure has a message for the person: {transcript}"
        );
        assert_readable(message);
        assert_eq!(
            transcript["text"].as_str().unwrap_or(""),
            "",
            "no partial text from a failed job"
        );

        let mut cache = LibraryCache::default();
        cache.refresh(&api).await.unwrap();
        let item = item_json(cache.items.iter().find(|i| i.id == session_id).unwrap());
        assert_eq!(item["transcript"]["final_status"], "failed");
        assert_eq!(item["status"], "failed");
        // The recording is still there to play even though the transcript failed.
        let url = text_at(&finished.detail, &["playback_url"]).expect("playback still works");
        assert_eq!(
            plain_http()
                .get(url)
                .header("Range", "bytes=0-9")
                .send()
                .await
                .unwrap()
                .status(),
            206
        );
        api.delete_session(&session_id).await.unwrap();
    }
);

e2e_test!(
    a_provider_that_rejects_the_job_does_not_leave_the_transcript_processing_forever,
    300,
    |cfg, case| {
        let Some(control) = Control::of(cfg) else {
            return skipped(
                case,
                "needs ECHOLEDGER_E2E_CONTROL to set the speech provider double to 'reject'",
            );
        };
        let _mode = ProviderMode::set(&control, "reject", 1);
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let session_id = upload(&api, &pending_call(&out, "zoom"))
            .await
            .expect("upload");
        let started = Instant::now();
        let mut reconciled = false;
        let verdict = loop {
            let detail = api.session(&session_id).await.unwrap();
            let status = transcript_of(&detail, "final")
                .and_then(|t| text_at(t, &["status"]))
                .unwrap_or("none")
                .to_string();
            if status == "failed" || status == "completed" {
                break Ok(status);
            }
            if started.elapsed() > Duration::from_secs(15) && !reconciled {
                // The scheduled safety net (stt:reconcile) gets its chance too.
                control.run(&["reconcile"]);
                reconciled = true;
            }
            if started.elapsed() > Duration::from_secs(40) {
                break Err(format!(
                    "after {:?} and a reconcile run the final transcript is still {status:?} and the session {:?}",
                    started.elapsed(),
                    text_at(&detail, &["status"])
                ));
            }
            pause(1_000).await;
        };
        let _ = api.delete_session(&session_id).await;
        match verdict {
            Ok(status) => assert_eq!(status, "failed", "a rejected job must end as failed"),
            Err(stuck) => panic!("the transcript never leaves processing: {stuck}"),
        }
    }
);

e2e_test!(
    unknown_ids_give_404_with_a_message_that_does_not_name_server_internals,
    120,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let unknown = "01ARZ3NDEKTSV4RRFFQ69G5FAV";
        let mut messages: Vec<(String, String)> = Vec::new();
        for (what, result) in [
            ("session", api.session(unknown).await.map(|_| ())),
            ("transcript", api.transcript(unknown).await.map(|_| ())),
            ("recording", api.recording_status(unknown).await.map(|_| ())),
            ("delete", api.delete_session(unknown).await),
            (
                "session (not a ULID)",
                api.session("not-an-id").await.map(|_| ()),
            ),
            (
                "session (quote)",
                api.session("%27%20OR%201=1").await.map(|_| ()),
            ),
            ("complete", api.complete(unknown).await.map(|_| ())),
        ] {
            let error = result.expect_err(what);
            assert_eq!(server_status(&error), Some(404), "{what}: {error}");
            messages.push((what.to_string(), server_message(&error)));
        }
        let leaks: Vec<String> = messages
            .iter()
            .filter(|(_, m)| m.contains("App\\") || m.contains("model [") || m.contains(".php"))
            .map(|(w, m)| format!("{w}: {m}"))
            .collect();
        assert!(
            leaks.is_empty(),
            "404 messages are shown verbatim in the app and must not name server classes: {leaks:?}"
        );
    }
);

// ---- Clients ---------------------------------------------------------------------------------------------------

e2e_test!(
    a_client_attached_to_a_recording_is_listed_labelled_and_supplies_keyterms,
    300,
    |cfg, case| {
        let Some(control) = Control::of(cfg) else {
            return skipped(case, "needs ECHOLEDGER_E2E_CONTROL to create a client");
        };
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let client_name = format!("Acme E2E {}", short_id());
        let created = control.json(&["create-client", &client_name, "Acme Holdings|Farah Khan"]);
        let client_id = created["id"].as_str().expect("client id").to_string();

        let clients = api.clients().await.expect("clients");
        let listed = clients["data"]
            .as_array()
            .unwrap()
            .iter()
            .find(|c| c["id"] == json!(client_id))
            .expect("the new client is listed")
            .clone();
        assert_eq!(listed["name"], json!(client_name));
        assert_eq!(listed["keyterm_count"], 2);

        // Live captions get the client's vocabulary.
        let session = caption_session(&api).await;
        let grants = api
            .stt_token(
                "live_captions",
                &["me", "client"],
                Some(&client_id),
                session.as_deref(),
                None,
            )
            .await
            .expect("stt token for a client");
        close_caption_session(&api, session).await;
        assert_eq!(grants.keyterms, vec!["Acme Holdings", "Farah Khan"]);
        for grant in &grants.grants {
            assert_eq!(
                grant.init_messages,
                vec![
                    json!({"type": "UpdateConfiguration", "keyterms_prompt": ["Acme Holdings", "Farah Khan"]})
                ],
                "the keyterms are sent as the first message of the {} stream",
                grant.channel
            );
        }

        // The recording is filed under the client.
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let mut pending = pending_call(&out, "zoom");
        pending.client_id = Some(client_id.clone());
        let session_id = upload(&api, &pending).await.expect("upload for a client");
        let finished = wait_for_final(&api, &session_id, Duration::from_secs(90)).await;
        assert_eq!(finished.detail["client"]["id"], json!(client_id));
        assert_eq!(finished.detail["client"]["name"], json!(client_name));
        assert_eq!(text_at(&finished.detail, &["status"]), Some("completed"));
        let mut cache = LibraryCache::default();
        cache.refresh(&api).await.unwrap();
        let item = item_json(cache.items.iter().find(|i| i.id == session_id).unwrap());
        assert_eq!(item["client"]["name"], json!(client_name));

        api.delete_session(&session_id).await.unwrap();
        control.run(&["delete-client", &client_id]);
    }
);

e2e_test!(
    a_client_deleted_in_the_console_does_not_block_uploads_or_live_captions,
    300,
    |cfg, case| {
        let Some(control) = Control::of(cfg) else {
            return skipped(
                case,
                "needs ECHOLEDGER_E2E_CONTROL to create and delete a client",
            );
        };
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let client_name = format!("Short-lived {}", short_id());
        let client_id = control.json(&["create-client", &client_name, "Term"])["id"]
            .as_str()
            .unwrap()
            .to_string();
        // The app remembers the selected client (settings.client_id) and attaches it to everything it does.
        control.run(&["delete-client", &client_id]);

        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let mut pending = pending_call(&out, "zoom");
        pending.client_id = Some(client_id.clone());
        let started = Instant::now();
        let upload_result = upload(&api, &pending).await;
        // The app's caption start: the selected client is dropped when the server no longer knows it.
        let session = caption_session(&api).await;
        let captions_result = with_client_fallback(Some(client_id.clone()), |client| {
            let (api, session) = (api.clone(), session.clone());
            async move {
                api.stt_token(
                    "live_captions",
                    &["me", "client"],
                    client.as_deref(),
                    session.as_deref(),
                    None,
                )
                .await
            }
        })
        .await;
        close_caption_session(&api, session).await;
        if let Ok((_, cleared)) = &captions_result {
            assert_eq!(
                cleared.as_deref(),
                Some(client_id.as_str()),
                "the deleted client is forgotten"
            );
        }
        if let Ok(session_id) = &upload_result {
            let _ = api.delete_session(session_id).await;
        }
        let mut problems: Vec<String> = Vec::new();
        if let Err(e) = &upload_result {
            problems.push(format!(
                "upload fails for good after {:?}: {e}",
                started.elapsed()
            ));
        }
        if let Err(e) = &captions_result {
            problems.push(format!("live captions cannot start: {e}"));
        }
        assert!(
            problems.is_empty(),
            "the app keeps sending the deleted client's id, so everything it does is refused: {problems:?}"
        );
    }
);

// ---- Server-side failures the app cannot see happening ----------------------------------------------------------

async fn session_id_for(api: &ApiClient, client_ref: &str) -> String {
    let mut cache = LibraryCache::default();
    cache.refresh(api).await.expect("library refresh");
    cache
        .items
        .iter()
        .find(|i| item_json(i)["client_ref"] == json!(client_ref))
        .unwrap_or_else(|| panic!("no session for client_ref {client_ref}"))
        .id
        .clone()
}

e2e_test!(
    a_recording_left_in_assembling_by_a_crashed_request_is_not_lost,
    400,
    |cfg, case| {
        let Some(control) = Control::of(cfg) else {
            return skipped(
                case,
                "needs ECHOLEDGER_E2E_CONTROL to put a recording into the assembling state",
            );
        };
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let cut = Arc::new(AtomicBool::new(true));
        let proxy = {
            let cut = cut.clone();
            Proxy::start(&cfg.base_url, move |r| {
                if cut.load(Ordering::SeqCst) && r.path.ends_with("/complete") {
                    Fault::Hangup
                } else {
                    Fault::Pass
                }
            })
        };
        let through = api_at(&proxy.base_url(), &id.token);
        let pending = pending_call(&out, "zoom");
        upload(&through, &pending)
            .await
            .expect_err("complete is unreachable");
        let session_id = session_id_for(&api, &pending.client_ref).await;
        let recording = recording_id(&api.session(&session_id).await.unwrap());

        // The request that handles `complete` marks the recording as assembling and then assembles it inline. If that
        // request dies half way (restart, deploy, PHP time limit) this is the state it leaves behind.
        control.run(&["set-recording-status", &recording, "assembling"]);
        cut.store(false, Ordering::SeqCst);

        // The app's next attempt, then the server's own recovery tasks.
        let retry = upload(&through, &pending).await;
        let app_deletes_its_copy = retry.is_ok();
        control.run(&["reconcile"]);
        let started = Instant::now();
        let outcome = loop {
            let detail = api.session(&session_id).await.unwrap();
            let recording_status = detail["recordings"][0]["status"]
                .as_str()
                .unwrap_or("?")
                .to_string();
            let final_status = transcript_of(&detail, "final")
                .and_then(|t| text_at(t, &["status"]))
                .unwrap_or("none")
                .to_string();
            if recording_status == "ready" && final_status == "completed" {
                break Ok(());
            }
            if started.elapsed() > Duration::from_secs(40) {
                break Err(format!(
                    "after {:?} the recording is {recording_status:?}, the final transcript {final_status:?} and the session {:?}",
                    started.elapsed(),
                    text_at(&detail, &["status"])
                ));
            }
            pause(1_000).await;
        };
        let _ = api.delete_session(&session_id).await;
        if let Err(stuck) = outcome {
            panic!(
                "nothing ever finishes a recording that was left in 'assembling'; the app's retry returned {} (success makes the app delete its local audio): {stuck}",
                if app_deletes_its_copy {
                    "Ok"
                } else {
                    "an error"
                }
            );
        }
    }
);

e2e_test!(
    a_corrupt_upload_is_reported_as_failed_and_not_as_uploaded,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let bytes = std::fs::read(&out.primary).unwrap();
        // The file changed after the app computed its checksum (or the bytes were damaged on the way).
        let mut request = session_request(
            &uuid::Uuid::new_v4().to_string(),
            "zoom",
            bytes.len() as u64,
        );
        request.artifacts[0].sha256 = sha256_hex(b"not the file that was sent");
        let created = api.create_session(&request).await.expect("create session");
        let recording = created.recordings[0].clone();
        let chunk = recording.upload.chunk_size_bytes as usize;
        for (index, part) in bytes.chunks(chunk).enumerate() {
            api.upload_chunk(
                &recording.id,
                index as u64,
                part.to_vec(),
                &sha256_hex(part),
            )
            .await
            .expect("every chunk is valid on its own");
        }
        let completed = api.complete(&recording.id).await;
        pause(500).await;
        let state = api.recording_status(&recording.id).await.unwrap();
        let detail = api.session(&created.session.id).await.unwrap();
        let summary = format!(
            "complete -> {:?}, recording {:?}, session {:?}",
            completed
                .as_ref()
                .map(|_| "accepted")
                .map_err(|e| e.to_string()),
            state.upload.status,
            text_at(&detail, &["status"])
        );
        let _ = api.delete_session(&created.session.id).await;
        assert_eq!(state.upload.status, "failed", "{summary}");
        assert!(
            completed.is_err() || text_at(&detail, &["status"]) == Some("failed"),
            "the app counts this upload as done, the server rejected the file, and the session stays in progress for ever: {summary}"
        );
    }
);

e2e_test!(
    a_clock_step_back_during_a_call_does_not_make_the_recording_unsendable,
    120,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let out = small_call(cfg, dir.path());
        let mut pending = pending_call(&out, "zoom");
        // The system clock was corrected backwards while the call was running: the recording "ends" before it began.
        let now = chrono::Utc::now();
        pending.started_at = now.to_rfc3339();
        pending.ended_at = (now - chrono::Duration::seconds(90)).to_rfc3339();
        let result = upload(&api, &pending).await;
        if let Ok(session_id) = &result {
            let _ = api.delete_session(session_id).await;
        }
        assert!(
            result.is_ok(),
            "the recording is refused for good and stays in the queue, retried every 30 s: {:?}",
            result.err().map(|e| e.to_string())
        );
    }
);

e2e_test!(
    deleting_a_session_while_it_is_still_uploading_ends_the_upload_without_retrying_a_404,
    400,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let dir = tempfile::tempdir().unwrap();
        let (out, _chunk, _total) = call_with_chunks(cfg, &api, dir.path(), 4).await;
        // Chunk 2 is held back for a moment so the deletion lands between chunk 1 and chunk 2.
        let proxy = Proxy::start(&cfg.base_url, |r| {
            if r.chunk == Some(2) && r.attempt == 1 {
                std::thread::sleep(Duration::from_secs(3));
            }
            Fault::Pass
        });
        let through = api_at(&proxy.base_url(), &id.token);
        let pending = pending_call(&out, "zoom");

        let uploading = async {
            let started = Instant::now();
            (upload(&through, &pending).await, started.elapsed())
        };
        let deleting = async {
            loop {
                let delivered = proxy
                    .chunk_requests_since(0)
                    .iter()
                    .any(|c| c.chunk == Some(1) && c.status == Some(200));
                if delivered {
                    break;
                }
                pause(50).await;
            }
            let session_id = session_id_for(&api, &pending.client_ref).await;
            api.delete_session(&session_id).await.expect("delete");
            session_id
        };
        let ((first, first_took), session_id) =
            futures_util::future::join(uploading, deleting).await;

        let error = first.expect_err("the session is gone, so the upload cannot finish");
        assert_eq!(server_status(&error), Some(404), "{error}");
        let again_started = Instant::now();
        let mark = proxy.mark();
        let again = upload(&through, &pending).await;
        assert!(
            again.is_ok(),
            "the next attempt must end cleanly: {:?}",
            again.err().map(|e| e.to_string())
        );
        assert!(proxy.chunk_requests_since(mark).is_empty());
        assert!(again_started.elapsed() < Duration::from_secs(10));
        assert_eq!(
            server_status(&api.session(&session_id).await.unwrap_err()),
            Some(404)
        );
        assert!(
            first_took < Duration::from_secs(8),
            "a 404 will not change, yet the app retried it with back-off for {first_took:?}"
        );
    }
);

e2e_test!(
    a_deletion_in_the_same_second_as_a_sync_reaches_the_next_sync,
    300,
    |cfg, case| {
        let id = identity(cfg).await;
        let api = api_for(cfg, &id.token);
        let mut cache = LibraryCache::default();
        let mut candidates = 0;
        let mut ghost: Option<String> = None;
        for _ in 0..12 {
            let session = api
                .create_session(&session_request(
                    &uuid::Uuid::new_v4().to_string(),
                    "other",
                    4096,
                ))
                .await
                .unwrap()
                .session;
            pause(1_100).await; // keep the creation out of the sync point's second
            cache.refresh(&api).await.unwrap();
            assert!(cache.items.iter().any(|i| i.id == session.id));
            let since = cache.since.clone().unwrap();
            api.delete_session(&session.id).await.unwrap();
            let everything = api
                .library(Some("2000-01-01T00:00:00+00:00"), None)
                .await
                .unwrap();
            let tombstone = everything
                .data
                .iter()
                .find(|i| i.id == session.id)
                .expect("the tombstone exists");
            if tombstone.updated_at.get(..19) != since.get(..19) {
                continue;
            }
            candidates += 1;
            cache.refresh(&api).await.unwrap();
            if cache.items.iter().any(|i| i.id == session.id) {
                pause(2_000).await;
                cache.refresh(&api).await.unwrap();
                let later = cache.items.iter().any(|i| i.id == session.id);
                ghost = Some(format!(
                    "session {} was deleted at {} in the same second as the sync point {}; still listed after a further sync: {later}",
                    session.id, tombstone.updated_at, since
                ));
                break;
            }
        }
        if candidates == 0 {
            return skipped(case, "no attempt landed in the same second as a sync");
        }
        if let Some(details) = ghost {
            panic!(
                "a deletion made right after a sync never reaches the cache, so the deleted session stays listed: {details}"
            );
        }
    }
);

e2e_test!(
    pairing_attempts_are_limited_per_address_with_a_readable_message,
    400,
    |cfg, case| {
        init_tls();
        let api = ApiClient::new(&cfg.base_url, &[], None).unwrap();
        let mut statuses: Vec<u16> = Vec::new();
        let mut limited: Option<String> = None;
        for _ in 0..8 {
            match api.register("7777-7777", "Nobody", "windows", "e2e").await {
                Err(AppError::Server {
                    status, message, ..
                }) => {
                    statuses.push(status);
                    if status == 429 {
                        limited = Some(message);
                        break;
                    }
                }
                other => panic!("unexpected result for an unknown code: {other:?}"),
            }
        }
        let message = limited
            .unwrap_or_else(|| panic!("no 429 after {} attempts: {statuses:?}", statuses.len()));
        assert!(!message.trim().is_empty());
        assert_readable(&message);
    }
);
