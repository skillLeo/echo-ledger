//! The golden eval set in CI: the transcripts follow the plan, the reference answers pass every check through a real
//! relay session on the mocked models, and each bad answer fails exactly the checks it names. No model is called.

use std::path::PathBuf;

use assist_relay::eval::{load_dir, run::ci_check};

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn the_golden_set_and_its_scorer_work() {
    let dir = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("eval/transcripts");
    let cases = load_dir(&dir).expect("transcripts load");
    let problems = ci_check(&cases).await;
    assert!(problems.is_empty(), "{} problems:\n{}", problems.len(), problems.join("\n"));
}
