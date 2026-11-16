//! A local stand-in for the model providers' HTTP APIs: scripted answers per path (an SSE fixture replayed event by
//! event, optionally cut off; or a status code with `retry-after`), and every request kept for checks. No request
//! leaves 127.0.0.1.

use std::collections::{HashMap, VecDeque};
use std::path::PathBuf;
use std::sync::{Arc, Mutex};
use std::time::Duration;

use axum::body::{Body, Bytes};
use axum::http::{HeaderMap, StatusCode, Uri};
use axum::response::{IntoResponse, Response};
use futures_util::{StreamExt, stream};
use serde_json::Value;
use tokio::time::Instant;

pub const ANTHROPIC: &str = "/v1/messages";
pub const GROQ: &str = "/groq/openai/v1/chat/completions";
pub const OPENAI: &str = "/openai/v1/chat/completions";
pub const GEMINI_FLASH: &str = "/gemini/v1beta/models/gemini-3.8-flash:streamGenerateContent";

#[derive(Debug, Clone)]
pub enum Reply {
    /// The fixture's events: the first after `first`, then one every `gap`. With `cut_after`, the connection drops
    /// after that many events.
    Sse { fixture: &'static str, first: Duration, gap: Duration, cut_after: Option<usize> },
    Status { code: u16, retry_after: Option<&'static str> },
}

impl Reply {
    pub fn sse(fixture: &'static str) -> Reply {
        Reply::Sse { fixture, first: Duration::from_millis(120), gap: Duration::from_millis(5), cut_after: None }
    }

    pub fn cut(fixture: &'static str, after: usize) -> Reply {
        Reply::Sse { fixture, first: Duration::from_millis(120), gap: Duration::from_millis(5), cut_after: Some(after) }
    }
}

#[derive(Debug, Clone)]
pub struct Recorded {
    pub path: String,
    pub headers: HeaderMap,
    pub body: Value,
    pub at: Instant,
}

#[derive(Clone, Default)]
struct Shared {
    script: Arc<Mutex<HashMap<String, VecDeque<Reply>>>>,
    requests: Arc<Mutex<Vec<Recorded>>>,
}

pub struct MockHttp {
    pub base: String,
    shared: Shared,
}

pub fn fixture(name: &str) -> String {
    let path = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/sse").join(name);
    std::fs::read_to_string(&path).unwrap_or_else(|_| panic!("no fixture {}", path.display()))
}

/// The fixture's events, each with its blank line. Comment-only blocks (the fixture's note) are left out, so
/// `cut_after` counts real events.
fn events(text: &str) -> Vec<String> {
    text.split("\n\n")
        .filter(|e| e.lines().any(|line| !line.trim().is_empty() && !line.starts_with(':')))
        .map(|e| format!("{e}\n\n"))
        .collect()
}

async fn answer(shared: Shared, uri: Uri, headers: HeaderMap, body: Bytes) -> Response {
    let path = uri.path().to_owned();
    shared.requests.lock().unwrap().push(Recorded {
        path: path.clone(),
        headers,
        body: serde_json::from_slice(&body).unwrap_or(Value::Null),
        at: Instant::now(),
    });
    let reply = shared.script.lock().unwrap().get_mut(&path).and_then(VecDeque::pop_front);
    match reply {
        None => (StatusCode::INTERNAL_SERVER_ERROR, "no scripted answer").into_response(),
        Some(Reply::Status { code, retry_after }) => {
            let mut response = (StatusCode::from_u16(code).unwrap(), r#"{"type":"error","error":{"type":"busy"}}"#).into_response();
            if let Some(after) = retry_after {
                response.headers_mut().insert("retry-after", after.parse().unwrap());
            }
            response
        }
        Some(Reply::Sse { fixture: name, first, gap, cut_after }) => {
            let mut events = events(&fixture(name));
            let cut = cut_after.is_some_and(|n| n < events.len());
            if let Some(n) = cut_after {
                events.truncate(n);
            }
            let body = stream::iter(events.into_iter().enumerate())
                .then(move |(i, event)| async move {
                    tokio::time::sleep(if i == 0 { first } else { gap }).await;
                    Ok::<Bytes, std::io::Error>(Bytes::from(event))
                })
                // The drop comes a little after the last event, so that event reaches the client first.
                .chain(stream::iter(cut.then_some(())).then(|()| async {
                    tokio::time::sleep(Duration::from_millis(30)).await;
                    Err(std::io::Error::other("connection dropped"))
                }));
            Response::builder().header("content-type", "text/event-stream").body(Body::from_stream(body)).unwrap()
        }
    }
}

impl MockHttp {
    pub async fn start() -> MockHttp {
        let shared = Shared::default();
        let state = shared.clone();
        let app = axum::Router::new().fallback(move |uri: Uri, headers: HeaderMap, body: Bytes| answer(state.clone(), uri, headers, body));
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
        MockHttp { base: format!("http://{addr}"), shared }
    }

    pub fn push(&self, path: &str, reply: Reply) {
        self.shared.script.lock().unwrap().entry(path.to_owned()).or_default().push_back(reply);
    }

    pub fn requests(&self, path: &str) -> Vec<Recorded> {
        self.shared.requests.lock().unwrap().iter().filter(|r| r.path == path).cloned().collect()
    }

    pub fn endpoints(&self) -> assist_relay::provider::Endpoints {
        assist_relay::provider::Endpoints {
            anthropic: self.base.clone(),
            groq: format!("{}/groq/openai", self.base),
            openai: format!("{}/openai", self.base),
            gemini: format!("{}/gemini", self.base),
        }
    }
}
