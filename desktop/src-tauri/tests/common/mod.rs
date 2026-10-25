//! Shared helpers for the integration tests: a small in-process HTTP/1.1 server that records every request and
//! answers from a scripted router, plus loaders for the JSON fixtures under `tests/fixtures`.

#![allow(dead_code)]

use std::io::{Read, Write};
use std::net::{SocketAddr, TcpListener, TcpStream};
use std::path::PathBuf;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::thread::JoinHandle;
use std::time::{Duration, Instant};

use serde_json::Value;

// ---- TLS -------------------------------------------------------------------------------------------------------

/// The app installs the `ring` provider at start-up (`run`); tests that build an HTTPS client must do the same.
pub fn init_tls() {
    let _ = rustls::crypto::ring::default_provider().install_default();
}

// ---- Fixtures --------------------------------------------------------------------------------------------------

pub fn fixture_path(name: &str) -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("tests")
        .join("fixtures")
        .join(name)
}

pub fn fixture_text(name: &str) -> String {
    std::fs::read_to_string(fixture_path(name)).unwrap_or_else(|e| panic!("fixture {name}: {e}"))
}

pub fn fixture(name: &str) -> Value {
    serde_json::from_str(&fixture_text(name))
        .unwrap_or_else(|e| panic!("fixture {name} is not valid JSON: {e}"))
}

// ---- Requests and replies --------------------------------------------------------------------------------------

#[derive(Clone, Debug)]
pub struct Recorded {
    pub method: String,
    /// Request target exactly as sent: path plus optional query string.
    pub target: String,
    /// Header names are lower-cased.
    pub headers: Vec<(String, String)>,
    pub body: Vec<u8>,
    pub received_at: Instant,
}

impl Recorded {
    pub fn path(&self) -> &str {
        self.target.split('?').next().unwrap_or("")
    }

    pub fn query(&self) -> Option<&str> {
        self.target.split_once('?').map(|(_, q)| q)
    }

    pub fn header(&self, name: &str) -> Option<&str> {
        let name = name.to_ascii_lowercase();
        self.headers
            .iter()
            .find(|(k, _)| *k == name)
            .map(|(_, v)| v.as_str())
    }

    pub fn json(&self) -> Value {
        serde_json::from_slice(&self.body)
            .unwrap_or_else(|e| panic!("request body is not JSON: {e}"))
    }
}

#[derive(Clone, Debug)]
pub struct Reply {
    pub status: u16,
    pub headers: Vec<(String, String)>,
    pub body: Vec<u8>,
}

impl Reply {
    pub fn json(status: u16, value: Value) -> Self {
        Self {
            status,
            headers: vec![("Content-Type".into(), "application/json".into())],
            body: value.to_string().into_bytes(),
        }
    }

    pub fn raw_json(status: u16, text: &str) -> Self {
        Self {
            status,
            headers: vec![("Content-Type".into(), "application/json".into())],
            body: text.as_bytes().to_vec(),
        }
    }

    pub fn html(status: u16, text: &str) -> Self {
        Self {
            status,
            headers: vec![("Content-Type".into(), "text/html; charset=UTF-8".into())],
            body: text.as_bytes().to_vec(),
        }
    }

    pub fn empty(status: u16) -> Self {
        Self {
            status,
            headers: vec![],
            body: vec![],
        }
    }

    pub fn header(mut self, name: &str, value: &str) -> Self {
        self.headers.push((name.into(), value.into()));
        self
    }
}

pub enum Action {
    Reply(Reply),
    /// Accept the request, wait this long, then close the socket without answering.
    Stall(Duration),
    /// Close the socket immediately without answering.
    Drop,
    /// Send the status line and headers promising `claimed` body bytes, deliver only `body`, then close.
    Truncated {
        reply: Reply,
        claimed: usize,
    },
}

impl From<Reply> for Action {
    fn from(reply: Reply) -> Self {
        Action::Reply(reply)
    }
}

type RouteFn = Box<dyn Fn(&Recorded, usize) -> Action + Send + Sync>;

/// Scripted routes: `route(method, path, |request, nth_call| ...)`. Unmatched requests get a Laravel-style 404.
#[derive(Default)]
pub struct Router {
    routes: Vec<(String, String, RouteFn, std::sync::atomic::AtomicUsize)>,
}

impl Router {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn route(
        mut self,
        method: &str,
        path: &str,
        handler: impl Fn(&Recorded, usize) -> Action + Send + Sync + 'static,
    ) -> Self {
        self.routes.push((
            method.to_string(),
            path.to_string(),
            Box::new(handler),
            std::sync::atomic::AtomicUsize::new(0),
        ));
        self
    }

    pub fn fixed(self, method: &str, path: &str, reply: Reply) -> Self {
        self.route(method, path, move |_, _| Action::Reply(reply.clone()))
    }

    fn dispatch(&self, request: &Recorded) -> Action {
        for (method, path, handler, calls) in &self.routes {
            if *method == request.method && path == request.path() {
                let nth = calls.fetch_add(1, Ordering::SeqCst);
                return handler(request, nth);
            }
        }
        Action::Reply(Reply::json(
            404,
            serde_json::json!({ "message": "The route could not be found." }),
        ))
    }
}

// ---- Server ----------------------------------------------------------------------------------------------------

pub struct MockServer {
    addr: SocketAddr,
    requests: Arc<Mutex<Vec<Recorded>>>,
    stop: Arc<AtomicBool>,
    accept_thread: Option<JoinHandle<()>>,
}

impl MockServer {
    pub fn start(router: Router) -> Self {
        init_tls();
        let listener = TcpListener::bind("127.0.0.1:0").expect("bind mock server");
        listener
            .set_nonblocking(true)
            .expect("non-blocking listener");
        let addr = listener.local_addr().expect("local addr");
        let requests = Arc::new(Mutex::new(Vec::new()));
        let stop = Arc::new(AtomicBool::new(false));
        let router = Arc::new(router);

        let accept_thread = {
            let (requests, stop) = (requests.clone(), stop.clone());
            std::thread::spawn(move || {
                while !stop.load(Ordering::SeqCst) {
                    match listener.accept() {
                        Ok((stream, _)) => {
                            // Sockets accepted from a non-blocking listener inherit that mode on Windows.
                            let _ = stream.set_nonblocking(false);
                            let (router, requests, stop) =
                                (router.clone(), requests.clone(), stop.clone());
                            std::thread::spawn(move || {
                                serve_connection(stream, &router, &requests, &stop)
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

        Self {
            addr,
            requests,
            stop,
            accept_thread: Some(accept_thread),
        }
    }

    pub fn base_url(&self) -> String {
        format!("http://127.0.0.1:{}", self.addr.port())
    }

    pub fn port(&self) -> u16 {
        self.addr.port()
    }

    pub fn requests(&self) -> Vec<Recorded> {
        self.requests.lock().unwrap().clone()
    }

    pub fn requests_to(&self, method: &str, path: &str) -> Vec<Recorded> {
        self.requests()
            .into_iter()
            .filter(|r| r.method == method && r.path() == path)
            .collect()
    }
}

impl Drop for MockServer {
    fn drop(&mut self) {
        self.stop.store(true, Ordering::SeqCst);
        if let Some(handle) = self.accept_thread.take() {
            let _ = handle.join();
        }
    }
}

fn serve_connection(
    mut stream: TcpStream,
    router: &Router,
    requests: &Mutex<Vec<Recorded>>,
    stop: &AtomicBool,
) {
    let _ = stream.set_read_timeout(Some(Duration::from_secs(15)));
    let Some(request) = read_request(&mut stream) else {
        return;
    };
    requests.lock().unwrap().push(request.clone());
    match router.dispatch(&request) {
        Action::Reply(reply) => {
            let _ = write_reply(&mut stream, &reply, reply.body.len());
        }
        Action::Truncated { reply, claimed } => {
            let _ = write_reply(&mut stream, &reply, claimed);
        }
        Action::Stall(duration) => {
            let until = Instant::now() + duration;
            while Instant::now() < until && !stop.load(Ordering::SeqCst) {
                std::thread::sleep(Duration::from_millis(20));
            }
        }
        Action::Drop => {}
    }
    let _ = stream.shutdown(std::net::Shutdown::Both);
}

fn read_request(stream: &mut TcpStream) -> Option<Recorded> {
    let mut buf: Vec<u8> = Vec::new();
    let mut chunk = [0u8; 8192];
    let header_end = loop {
        if let Some(pos) = find(&buf, b"\r\n\r\n") {
            break pos;
        }
        let n = stream.read(&mut chunk).ok()?;
        if n == 0 {
            return None;
        }
        buf.extend_from_slice(&chunk[..n]);
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

    let mut body = buf[header_end + 4..].to_vec();
    let get = |name: &str| {
        headers
            .iter()
            .find(|(k, _)| k == name)
            .map(|(_, v)| v.clone())
    };
    if get("transfer-encoding").is_some_and(|v| v.eq_ignore_ascii_case("chunked")) {
        loop {
            if let Some(decoded) = decode_chunked(&body) {
                body = decoded;
                break;
            }
            let n = stream.read(&mut chunk).ok()?;
            if n == 0 {
                return None;
            }
            body.extend_from_slice(&chunk[..n]);
        }
    } else {
        let length: usize = get("content-length")
            .and_then(|v| v.parse().ok())
            .unwrap_or(0);
        while body.len() < length {
            let n = stream.read(&mut chunk).ok()?;
            if n == 0 {
                return None;
            }
            body.extend_from_slice(&chunk[..n]);
        }
        body.truncate(length);
    }
    Some(Recorded {
        method,
        target,
        headers,
        body,
        received_at: Instant::now(),
    })
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack.windows(needle.len()).position(|w| w == needle)
}

/// Decodes a complete chunked body, or returns `None` while more bytes are needed.
fn decode_chunked(raw: &[u8]) -> Option<Vec<u8>> {
    let mut out = Vec::new();
    let mut pos = 0;
    loop {
        let line_end = find(&raw[pos..], b"\r\n")? + pos;
        let size = usize::from_str_radix(
            std::str::from_utf8(&raw[pos..line_end])
                .ok()?
                .split(';')
                .next()?
                .trim(),
            16,
        )
        .ok()?;
        pos = line_end + 2;
        if size == 0 {
            return Some(out);
        }
        if raw.len() < pos + size + 2 {
            return None;
        }
        out.extend_from_slice(&raw[pos..pos + size]);
        pos += size + 2;
    }
}

fn reason(status: u16) -> &'static str {
    match status {
        200 => "OK",
        201 => "Created",
        202 => "Accepted",
        204 => "No Content",
        401 => "Unauthorized",
        403 => "Forbidden",
        404 => "Not Found",
        409 => "Conflict",
        413 => "Payload Too Large",
        422 => "Unprocessable Entity",
        429 => "Too Many Requests",
        500 => "Internal Server Error",
        502 => "Bad Gateway",
        503 => "Service Unavailable",
        _ => "Status",
    }
}

fn write_reply(
    stream: &mut TcpStream,
    reply: &Reply,
    claimed_length: usize,
) -> std::io::Result<()> {
    let mut head = format!(
        "HTTP/1.1 {} {}\r\nContent-Length: {}\r\nConnection: close\r\n",
        reply.status,
        reason(reply.status),
        claimed_length
    );
    for (name, value) in &reply.headers {
        head.push_str(&format!("{name}: {value}\r\n"));
    }
    head.push_str("\r\n");
    stream.write_all(head.as_bytes())?;
    stream.write_all(&reply.body)?;
    stream.flush()
}

// ---- Misc helpers ----------------------------------------------------------------------------------------------

pub fn percent_decode(raw: &str) -> String {
    let bytes = raw.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    let hex = |at: usize| {
        bytes
            .get(at..at + 2)
            .and_then(|pair| std::str::from_utf8(pair).ok())
            .and_then(|s| u8::from_str_radix(s, 16).ok())
    };
    while i < bytes.len() {
        match bytes[i] {
            b'%' if hex(i + 1).is_some() => {
                out.push(hex(i + 1).unwrap());
                i += 3;
            }
            b'+' => {
                out.push(b' ');
                i += 1;
            }
            b => {
                out.push(b);
                i += 1;
            }
        }
    }
    String::from_utf8_lossy(&out).to_string()
}

/// Query parameters as a server would decode them (`+` means space, `%2B` means plus).
pub fn query_pairs(query: Option<&str>) -> Vec<(String, String)> {
    query
        .unwrap_or("")
        .split('&')
        .filter(|p| !p.is_empty())
        .map(|p| {
            let (k, v) = p.split_once('=').unwrap_or((p, ""));
            (percent_decode(k), percent_decode(v))
        })
        .collect()
}

pub fn query_value(query: Option<&str>, name: &str) -> Option<String> {
    query_pairs(query)
        .into_iter()
        .find(|(k, _)| k == name)
        .map(|(_, v)| v)
}

/// Deterministic pseudo-random bytes (xorshift) so tests need no RNG dependency.
pub fn pseudo_random_bytes(len: usize, seed: u64) -> Vec<u8> {
    let mut state = seed | 1;
    (0..len)
        .map(|_| {
            state ^= state << 13;
            state ^= state >> 7;
            state ^= state << 17;
            (state >> 24) as u8
        })
        .collect()
}

pub fn sha256_hex(bytes: &[u8]) -> String {
    ring::digest::digest(&ring::digest::SHA256, bytes)
        .as_ref()
        .iter()
        .map(|b| format!("{b:02x}"))
        .collect()
}
