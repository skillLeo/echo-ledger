//! The token-store abstraction, exercised through the in-memory implementation (the OS keychain itself is never
//! touched by tests: it would read and overwrite the real device token of whoever runs them).

use std::sync::Arc;

use desktop_lib::net::keychain::{MemoryStore, TokenStore};

#[test]
fn a_new_store_holds_no_token() {
    assert_eq!(MemoryStore::default().load(), Ok(None));
}

#[test]
fn a_saved_token_is_returned_exactly() {
    let store = MemoryStore::default();
    store.save("7|eld_TOKEN_PLACEHOLDER").unwrap();
    assert_eq!(
        store.load(),
        Ok(Some("7|eld_TOKEN_PLACEHOLDER".to_string()))
    );
}

#[test]
fn saving_again_replaces_the_token() {
    let store = MemoryStore::default();
    store.save("old").unwrap();
    store.save("new").unwrap();
    assert_eq!(store.load(), Ok(Some("new".to_string())));
}

#[test]
fn forgetting_clears_the_token_and_is_idempotent() {
    let store = MemoryStore::default();
    store.save("t").unwrap();
    store.forget().unwrap();
    assert_eq!(store.load(), Ok(None));
    store.forget().unwrap();
    assert_eq!(store.load(), Ok(None));
}

#[test]
fn unusual_tokens_survive() {
    let store = MemoryStore::default();
    for token in ["", " ", "ünïcödé-令牌", &"x".repeat(8_192), "line\nbreak"] {
        store.save(token).unwrap();
        assert_eq!(store.load().unwrap().as_deref(), Some(token));
    }
}

#[test]
fn the_store_can_be_used_as_a_trait_object_from_many_threads() {
    let store: Arc<dyn TokenStore> = Arc::new(MemoryStore::default());
    let handles: Vec<_> = (0..8)
        .map(|n| {
            let store = store.clone();
            std::thread::spawn(move || {
                for i in 0..100 {
                    store.save(&format!("token-{n}-{i}")).unwrap();
                    assert!(store.load().unwrap().is_some());
                }
            })
        })
        .collect();
    for handle in handles {
        handle.join().unwrap();
    }
    assert!(store.load().unwrap().unwrap().starts_with("token-"));
}
