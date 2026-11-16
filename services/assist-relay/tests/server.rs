//! The relay over a real socket: the connection limit, and refusals before the upgrade.

mod common;

use assist_relay::config::RelayConfig;
use common::*;
use tokio_tungstenite::tungstenite;

#[tokio::test]
async fn connections_beyond_the_limit_get_503_and_a_freed_slot_is_reused() {
    let mut config = RelayConfig::built_in();
    config.limits.max_connections = 2;
    let relay = listen(config).await;
    let mut first = open(relay.addr, Some(&sign(&claims("s-limit-1")))).await.expect("first");
    assert_eq!(receive(&mut first).await["type"], "ready");
    let mut second = open(relay.addr, Some(&sign(&claims("s-limit-2")))).await.expect("second");
    assert_eq!(receive(&mut second).await["type"], "ready");

    match open(relay.addr, Some(&sign(&claims("s-limit-3")))).await {
        Err(tungstenite::Error::Http(response)) => {
            assert_eq!(response.status().as_u16(), 503);
            let body = String::from_utf8(response.body().clone().unwrap_or_default()).unwrap();
            assert!(body.contains("relay_busy"), "{body}");
        }
        other => panic!("expected 503, got {:?}", other.map(|_| "a socket")),
    }

    drop(first);
    // The slot frees when the relay sees the socket close.
    let mut third = None;
    for _ in 0..50 {
        if let Ok(socket) = open(relay.addr, Some(&sign(&claims("s-limit-3")))).await {
            third = Some(socket);
            break;
        }
        tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    }
    let mut third = third.expect("a slot came free");
    assert_eq!(receive(&mut third).await["type"], "ready");
}
