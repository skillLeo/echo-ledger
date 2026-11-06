//! The answers the real server gives (docs/api-samples, kept true by the server's ApiSamplesTest) read with this app's own
//! models. Fakes in the other tests repeat what their author believes; these files are what the server sends.

use std::path::PathBuf;

use desktop_lib::net::models::{
    AccountDto, AccountExport, CompleteResponse, CreateSessionResponse, LibraryResponse, RecordingUpload, RegisterResponse,
    ServerConfig, SttTokenResponse,
};
use desktop_lib::translate::protocol::LiveTranslateToken;
use serde::de::DeserializeOwned;

fn sample(name: &str) -> String {
    let path = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../../docs/api-samples").join(format!("{name}.json"));
    std::fs::read_to_string(&path).unwrap_or_else(|e| panic!("{}: {e}", path.display()))
}

fn reads<T: DeserializeOwned>(name: &str, failures: &mut Vec<String>) {
    if let Err(e) = serde_json::from_str::<T>(&sample(name)) {
        failures.push(format!("{name}: {e}"));
    }
}

#[test]
fn the_desktop_reads_every_real_answer_it_has_a_model_for() {
    let mut failures = Vec::new();
    reads::<RegisterResponse>("auth_password", &mut failures);
    reads::<ServerConfig>("config", &mut failures);
    reads::<AccountDto>("account", &mut failures);
    reads::<AccountExport>("account_export", &mut failures);
    reads::<SttTokenResponse>("stt_token", &mut failures);
    reads::<LiveTranslateToken>("live_translate_token", &mut failures);
    reads::<CreateSessionResponse>("session_open", &mut failures);
    reads::<CreateSessionResponse>("session_finish", &mut failures);
    reads::<CreateSessionResponse>("session_create", &mut failures);
    reads::<RecordingUpload>("recording_status", &mut failures);
    reads::<CompleteResponse>("recording_complete", &mut failures);
    reads::<LibraryResponse>("library", &mut failures);
    reads::<LibraryResponse>("library_folder", &mut failures);
    assert!(failures.is_empty(), "{}", failures.join("\n"));
}
