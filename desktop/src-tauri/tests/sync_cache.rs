//! Library sync: paging with cursors, `since` bookkeeping, tombstones, last-write-wins merging and the on-disk
//! cache.

mod common;

use std::sync::{Arc, Mutex};

use serde_json::{Value, json};

use common::{Action, MockServer, Reply, Router, fixture, query_value};
use desktop_lib::error::AppError;
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::LibraryItem;
use desktop_lib::net::sync::{LibraryCache, merge, paths};

fn item(id: &str, updated_at: &str, started_at: &str, title: &str) -> LibraryItem {
    serde_json::from_value(json!({"id": id, "deleted": false, "updated_at": updated_at, "started_at": started_at, "title": title})).unwrap()
}

fn tombstone(id: &str, updated_at: &str) -> LibraryItem {
    serde_json::from_value(
        json!({"id": id, "client_ref": "r", "deleted": true, "updated_at": updated_at}),
    )
    .unwrap()
}

fn ids(items: &[LibraryItem]) -> Vec<&str> {
    items.iter().map(|i| i.id.as_str()).collect()
}

fn title<'a>(items: &'a [LibraryItem], id: &str) -> &'a str {
    items
        .iter()
        .find(|i| i.id == id)
        .and_then(|i| i.rest.get("title"))
        .and_then(Value::as_str)
        .unwrap_or("")
}

fn client(server: &MockServer) -> ApiClient {
    ApiClient::new(&server.base_url(), &[], Some("tok".into())).unwrap()
}

fn page(items: Vec<Value>, next: Option<&str>, server_time: &str) -> Reply {
    Reply::json(
        200,
        json!({"data": items, "next_cursor": next, "server_time": server_time}),
    )
}

fn raw(id: &str, updated_at: &str, started_at: &str) -> Value {
    json!({"id": id, "deleted": false, "updated_at": updated_at, "started_at": started_at, "title": id})
}

// ---- merge -----------------------------------------------------------------------------------------------------

#[test]
fn new_items_are_inserted_and_ordered_newest_recording_first() {
    let mut items = vec![];
    merge(
        &mut items,
        vec![
            item(
                "old",
                "2026-01-01T00:00:00+00:00",
                "2026-01-01T09:00:00+00:00",
                "old",
            ),
            item(
                "new",
                "2026-01-03T00:00:00+00:00",
                "2026-01-03T09:00:00+00:00",
                "new",
            ),
            item(
                "mid",
                "2026-01-02T00:00:00+00:00",
                "2026-01-02T09:00:00+00:00",
                "mid",
            ),
        ],
    );
    assert_eq!(ids(&items), ["new", "mid", "old"]);
}

#[test]
fn a_newer_version_replaces_and_an_older_one_is_ignored() {
    let mut items = vec![item(
        "a",
        "2026-01-02T00:00:00+00:00",
        "2026-01-01T09:00:00+00:00",
        "v2",
    )];
    merge(
        &mut items,
        vec![item(
            "a",
            "2026-01-01T00:00:00+00:00",
            "2026-01-01T09:00:00+00:00",
            "v1-stale",
        )],
    );
    assert_eq!(title(&items, "a"), "v2");
    merge(
        &mut items,
        vec![item(
            "a",
            "2026-01-03T00:00:00+00:00",
            "2026-01-01T09:00:00+00:00",
            "v3",
        )],
    );
    assert_eq!(title(&items, "a"), "v3");
    assert_eq!(items.len(), 1);
}

#[test]
fn an_identical_timestamp_takes_the_incoming_copy() {
    let mut items = vec![item(
        "a",
        "2026-01-02T00:00:00+00:00",
        "2026-01-01T09:00:00+00:00",
        "first",
    )];
    merge(
        &mut items,
        vec![item(
            "a",
            "2026-01-02T00:00:00+00:00",
            "2026-01-01T09:00:00+00:00",
            "second",
        )],
    );
    assert_eq!(title(&items, "a"), "second");
}

#[test]
fn tombstones_remove_items_whatever_their_timestamp_and_ignore_unknown_ids() {
    let mut items = vec![
        item(
            "a",
            "2026-01-05T00:00:00+00:00",
            "2026-01-01T09:00:00+00:00",
            "a",
        ),
        item(
            "b",
            "2026-01-05T00:00:00+00:00",
            "2026-01-02T09:00:00+00:00",
            "b",
        ),
    ];
    merge(
        &mut items,
        vec![
            tombstone("zzz", "2026-01-06T00:00:00+00:00"),
            tombstone("a", "2026-01-04T00:00:00+00:00"),
        ],
    );
    assert_eq!(ids(&items), ["b"]);
}

#[test]
fn a_tombstone_and_an_update_in_the_same_page_resolve_in_page_order() {
    let mut items = vec![];
    merge(
        &mut items,
        vec![
            item(
                "a",
                "2026-01-01T00:00:00+00:00",
                "2026-01-01T09:00:00+00:00",
                "a",
            ),
            tombstone("a", "2026-01-02T00:00:00+00:00"),
        ],
    );
    assert!(items.is_empty());
}

#[test]
fn merging_the_same_page_twice_changes_nothing() {
    let incoming = || {
        vec![
            item(
                "a",
                "2026-01-01T00:00:00+00:00",
                "2026-01-01T09:00:00+00:00",
                "a",
            ),
            item(
                "b",
                "2026-01-02T00:00:00+00:00",
                "2026-01-02T09:00:00+00:00",
                "b",
            ),
            tombstone("c", "2026-01-03T00:00:00+00:00"),
        ]
    };
    let mut items = vec![];
    merge(&mut items, incoming());
    let once = serde_json::to_value(&items).unwrap();
    merge(&mut items, incoming());
    assert_eq!(serde_json::to_value(&items).unwrap(), once);
}

#[test]
fn items_without_a_start_time_sort_last_and_equal_start_times_keep_their_order() {
    let mut items = vec![];
    let mut no_start = item("no-start", "2026-01-01T00:00:00+00:00", "", "x");
    no_start.rest.remove("started_at");
    merge(
        &mut items,
        vec![
            item(
                "first",
                "2026-01-01T00:00:00+00:00",
                "2026-01-02T09:00:00+00:00",
                "x",
            ),
            no_start,
            item(
                "second",
                "2026-01-01T00:00:00+00:00",
                "2026-01-02T09:00:00+00:00",
                "x",
            ),
        ],
    );
    assert_eq!(ids(&items), ["first", "second", "no-start"]);
}

#[test]
fn thousands_of_items_merge_quickly_and_completely() {
    let started = std::time::Instant::now();
    let mut items = vec![];
    for batch in 0..10 {
        let page: Vec<LibraryItem> = (0..500)
            .map(|n| {
                let id = format!("item-{}", batch * 500 + n);
                item(
                    &id,
                    "2026-01-01T00:00:00+00:00",
                    &format!("2026-01-01T{:02}:{:02}:00+00:00", (n / 60) % 24, n % 60),
                    &id,
                )
            })
            .collect();
        merge(&mut items, page);
    }
    assert_eq!(items.len(), 5_000);
    assert!(
        started.elapsed() < std::time::Duration::from_secs(20),
        "{:?}",
        started.elapsed()
    );
}

// ---- refresh ---------------------------------------------------------------------------------------------------

#[tokio::test]
async fn refresh_follows_cursors_to_the_end_and_remembers_the_first_server_time() {
    let seen = Arc::new(Mutex::new(Vec::<(Option<String>, Option<String>)>::new()));
    let log = seen.clone();
    let server = MockServer::start(Router::new().route("GET", "/api/library", move |r, _| {
        let (since, cursor) = (
            query_value(r.query(), "since"),
            query_value(r.query(), "cursor"),
        );
        log.lock().unwrap().push((since, cursor.clone()));
        Action::Reply(match cursor.as_deref() {
            None => page(
                vec![
                    raw(
                        "a",
                        "2026-10-24T08:00:00+00:00",
                        "2026-10-24T08:00:00+00:00",
                    ),
                    raw(
                        "b",
                        "2026-10-24T07:00:00+00:00",
                        "2026-10-24T07:00:00+00:00",
                    ),
                ],
                Some("c2"),
                "2026-10-24T09:05:00+00:00",
            ),
            Some("c2") => page(
                vec![raw(
                    "c",
                    "2026-10-24T06:00:00+00:00",
                    "2026-10-24T06:00:00+00:00",
                )],
                Some("c3"),
                "2026-10-24T09:05:01+00:00",
            ),
            Some(_) => page(
                vec![raw(
                    "d",
                    "2026-10-24T05:00:00+00:00",
                    "2026-10-24T05:00:00+00:00",
                )],
                None,
                "2026-10-24T09:05:02+00:00",
            ),
        })
    }));

    let mut cache = LibraryCache::default();
    let changed = cache.refresh(&client(&server)).await.unwrap();
    assert_eq!(changed, 4);
    assert_eq!(ids(&cache.items), ["a", "b", "c", "d"]);
    assert_eq!(
        cache.since.as_deref(),
        Some("2026-10-24T09:05:00+00:00"),
        "the next sync starts from the first page's server time"
    );
    assert_eq!(
        *seen.lock().unwrap(),
        vec![
            (None, None),
            (None, Some("c2".into())),
            (None, Some("c3".into()))
        ]
    );
}

#[tokio::test]
async fn the_next_refresh_sends_since_and_applies_tombstones_and_updates() {
    let calls = Arc::new(Mutex::new(0usize));
    let counter = calls.clone();
    let server = MockServer::start(Router::new().route("GET", "/api/library", move |_, _| {
        let n = {
            let mut c = counter.lock().unwrap();
            *c += 1;
            *c
        };
        Action::Reply(if n == 1 {
            page(vec![raw("a", "2026-10-24T08:00:00+00:00", "2026-10-24T08:00:00+00:00"), raw("b", "2026-10-24T07:00:00+00:00", "2026-10-24T07:00:00+00:00")], None, "2026-10-24T09:00:00+00:00")
        } else {
            page(
                vec![
                    json!({"id": "a", "client_ref": "r", "deleted": true, "updated_at": "2026-10-24T09:30:00+00:00"}),
                    json!({"id": "b", "deleted": false, "updated_at": "2026-10-24T09:20:00+00:00", "started_at": "2026-10-24T07:00:00+00:00", "title": "renamed"}),
                ],
                None,
                "2026-10-24T09:40:00+00:00",
            )
        })
    }));
    let c = client(&server);
    let mut cache = LibraryCache::default();
    cache.refresh(&c).await.unwrap();
    assert_eq!(cache.refresh(&c).await.unwrap(), 2);

    assert_eq!(ids(&cache.items), ["b"]);
    assert_eq!(title(&cache.items, "b"), "renamed");
    assert_eq!(cache.since.as_deref(), Some("2026-10-24T09:40:00+00:00"));
    let requests = server.requests();
    assert_eq!(query_value(requests[0].query(), "since"), None);
    assert_eq!(
        query_value(requests[1].query(), "since").as_deref(),
        Some("2026-10-24T09:00:00+00:00")
    );
}

#[tokio::test]
async fn a_page_that_fails_leaves_the_sync_position_unchanged() {
    let server = MockServer::start(Router::new().route("GET", "/api/library", |r, _| {
        Action::Reply(match query_value(r.query(), "cursor") {
            None => page(
                vec![raw(
                    "a",
                    "2026-10-24T08:00:00+00:00",
                    "2026-10-24T08:00:00+00:00",
                )],
                Some("c2"),
                "2026-10-24T09:00:00+00:00",
            ),
            Some(_) => Reply::json(500, json!({"message": "Server Error"})),
        })
    }));
    let mut cache = LibraryCache {
        since: Some("2026-10-23T00:00:00+00:00".into()),
        items: vec![],
    };
    let result = cache.refresh(&client(&server)).await;
    assert!(
        matches!(result, Err(AppError::Server { status: 500, .. })),
        "{result:?}"
    );
    assert_eq!(
        cache.since.as_deref(),
        Some("2026-10-23T00:00:00+00:00"),
        "since may only advance after a complete sync"
    );
}

#[tokio::test]
async fn a_revoked_device_cannot_refresh_and_keeps_its_cache() {
    let server = MockServer::start(Router::new().fixed(
        "GET",
        "/api/library",
        Reply::json(401, json!({"message": "Unauthenticated."})),
    ));
    let mut cache = LibraryCache::default();
    cache.items.push(item(
        "a",
        "2026-10-24T08:00:00+00:00",
        "2026-10-24T08:00:00+00:00",
        "kept",
    ));
    assert!(matches!(
        cache.refresh(&client(&server)).await,
        Err(AppError::NotPaired)
    ));
    assert_eq!(ids(&cache.items), ["a"]);
}

#[tokio::test]
async fn the_real_page_shape_from_the_server_syncs_cleanly() {
    let server = MockServer::start(Router::new().fixed(
        "GET",
        "/api/library",
        Reply::json(200, fixture("library_page.json")),
    ));
    let mut cache = LibraryCache::default();
    cache.items.push(item(
        "01JB8QB4A1B2C3D4E5F6G7H8J9",
        "2026-10-24T01:00:00+00:00",
        "2026-10-24T01:00:00+00:00",
        "to be deleted",
    ));
    let changed = cache.refresh(&client(&server)).await.unwrap();
    assert_eq!(changed, 3, "two sessions and one tombstone");
    assert_eq!(
        ids(&cache.items),
        ["01JB8QB0A1B2C3D4E5F6G7H8J9", "01JB8QB3A1B2C3D4E5F6G7H8J9"],
        "newest recording first; the tombstoned one is gone"
    );
}

#[tokio::test]
#[ignore = "A6.DR15: library refresh must stop when the server repeats next_cursor"]
async fn a_server_that_keeps_returning_the_same_cursor_cannot_trap_the_client() {
    let server = MockServer::start(Router::new().fixed(
        "GET",
        "/api/library",
        page(
            vec![raw(
                "a",
                "2026-10-24T08:00:00+00:00",
                "2026-10-24T08:00:00+00:00",
            )],
            Some("same-cursor-again"),
            "2026-10-24T09:00:00+00:00",
        ),
    ));
    let mut cache = LibraryCache::default();
    let outcome = tokio::time::timeout(
        std::time::Duration::from_secs(5),
        cache.refresh(&client(&server)),
    )
    .await;
    assert!(
        outcome.is_ok(),
        "refresh was still paging after {} requests",
        server.requests().len()
    );
}
#[tokio::test]
async fn an_empty_library_is_fine() {
    let server = MockServer::start(Router::new().fixed(
        "GET",
        "/api/library",
        page(vec![], None, "2026-10-24T09:00:00+00:00"),
    ));
    let mut cache = LibraryCache::default();
    assert_eq!(cache.refresh(&client(&server)).await.unwrap(), 0);
    assert!(cache.items.is_empty());
    assert_eq!(cache.since.as_deref(), Some("2026-10-24T09:00:00+00:00"));
}

// ---- Cache file ------------------------------------------------------------------------------------------------

#[test]
fn the_cache_file_round_trips_items_and_the_sync_position() {
    let dir = tempfile::tempdir().unwrap();
    let path = paths::library(dir.path());
    let mut cache = LibraryCache {
        since: Some("2026-10-24T09:00:00+00:00".into()),
        items: vec![],
    };
    merge(
        &mut cache.items,
        vec![
            item(
                "a",
                "2026-10-24T08:00:00+00:00",
                "2026-10-24T08:00:00+00:00",
                "a",
            ),
            item(
                "b",
                "2026-10-24T07:00:00+00:00",
                "2026-10-24T07:00:00+00:00",
                "b",
            ),
        ],
    );
    cache.save(&path).unwrap();

    let loaded = LibraryCache::load(&path);
    assert_eq!(loaded.since, cache.since);
    assert_eq!(
        serde_json::to_value(&loaded.items).unwrap(),
        serde_json::to_value(&cache.items).unwrap()
    );
    assert!(!path.with_extension("json.tmp").exists());
}

#[test]
fn a_missing_or_corrupt_cache_starts_empty() {
    let dir = tempfile::tempdir().unwrap();
    let path = paths::library(dir.path());
    assert!(LibraryCache::load(&path).items.is_empty());
    for content in [
        "",
        "garbage",
        "{\"items\": \"x\"}",
        "{\"items\": [{\"id\": 1}]}",
    ] {
        std::fs::write(&path, content).unwrap();
        let cache = LibraryCache::load(&path);
        assert!(
            cache.items.is_empty() && cache.since.is_none(),
            "{content:?}"
        );
    }
}

#[test]
fn saving_replaces_an_existing_cache_atomically() {
    let dir = tempfile::tempdir().unwrap();
    let path = paths::library(dir.path());
    for round in 0..5 {
        let mut cache = LibraryCache::default();
        cache.items.push(item(
            &format!("round-{round}"),
            "2026-10-24T08:00:00+00:00",
            "2026-10-24T08:00:00+00:00",
            "x",
        ));
        cache.save(&path).unwrap();
        assert_eq!(
            ids(&LibraryCache::load(&path).items),
            [format!("round-{round}")]
        );
    }
}

#[test]
fn data_directory_layout() {
    let base = std::path::Path::new("data");
    assert_eq!(paths::library(base), base.join("library.json"));
    assert_eq!(paths::uploads(base), base.join("uploads.json"));
    assert_eq!(paths::recordings(base), base.join("recordings"));
}
