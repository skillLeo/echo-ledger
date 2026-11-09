//! v1.7 (docs/API.md "Payment receipts"), against a scripted server: the request's payment fields as the windows read
//! them, a receipt sent as multipart without its metadata, removing one, its picture, Send receipt, Cancel request, and
//! every refusal in calm words.

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::net::api::ApiClient;
use desktop_lib::receipts;

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";

fn authed(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

fn request_body(stage: &str) -> serde_json::Value {
    json!({
        "id": "01JF", "plan": "pro", "status": "pending", "created_at": "2026-11-09T10:10:00Z",
        "reference_code": "EL-7K2Q9M", "amount_due": "Rs 2,999", "amount_due_pkr": 2999, "currency": "PKR",
        "payment": {
            "stage": stage,
            "accounts": [{"bank": "Example Bank", "branch": "Main Branch (0001)", "title": "EXAMPLE PRIVATE LIMITED", "account_number": "0123456789", "iban": "PK00XXXX0000000123456789"}, "broken"],
            "note": "Put EL-7K2Q9M in the transfer's note.", "review_hours": "We check receipts every day.",
            "paid_at": null, "reference": null, "payer_name": null,
            "proof_request": null,
            "proofs": [{"id": "01P1", "kind": "image", "mime": "image/png", "name": "r.png", "bytes": 1200, "width": 400, "height": 800,
                        "created_at": "2026-11-09T10:12:00Z", "locked": false, "thumb_url": "https://x/thumb", "file_url": "https://x/full"}],
            "max_proofs": 3, "max_bytes": 5242880, "accepts": ["image/jpeg", "image/png", "image/webp", "application/pdf"]
        },
        "timeline": [{"step": "requested", "at": "2026-11-09T10:10:00Z"}, {"oops": true}]
    })
}

fn jpeg_with_gps() -> Vec<u8> {
    let mut j = vec![0xFF, 0xD8, 0xFF, 0xE1];
    let exif = b"Exif\0\0GPS 24.8607 67.0011";
    j.extend_from_slice(&((exif.len() + 2) as u16).to_be_bytes());
    j.extend_from_slice(exif);
    j.extend_from_slice(&[0xFF, 0xDA, 0x00, 0x02, 0x01, 0x02, 0xFF, 0xD9]);
    j
}

#[tokio::test]
async fn the_request_carries_how_to_pay_and_what_was_sent() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/upgrade-requests/latest", Reply::json(200, request_body("awaiting_payment"))));
    let request = authed(&server).latest_upgrade_request().await.unwrap().unwrap();
    assert_eq!(request.reference_code.as_deref(), Some("EL-7K2Q9M"));
    assert_eq!(request.amount_due_pkr, Some(2999.0));
    let payment = request.payment.as_ref().unwrap();
    assert_eq!(payment.stage, "awaiting_payment");
    assert_eq!(payment.accounts.len(), 1, "a malformed account is left out on its own");
    assert_eq!(payment.accounts[0].iban.as_deref(), Some("PK00XXXX0000000123456789"));
    assert_eq!(payment.proofs[0].id, "01P1");
    assert_eq!(request.timeline.len(), 2);
    let wire = serde_json::to_value(&request).unwrap();
    assert!(wire["payment"]["proofs"][0].get("thumb_url").is_none(), "the page never gets a URL that needs the token");
    assert_eq!(wire["payment"]["accounts"][0]["title"], "EXAMPLE PRIVATE LIMITED");

    // A v1.6 server's request still reads, with no payment part.
    let older = MockServer::start(Router::new().fixed("GET", "/api/upgrade-requests/latest", Reply::json(200, json!({"id": "01JF", "plan": "pro", "status": "pending", "payment_instructions": "Bank 0000"}))));
    let request = authed(&older).latest_upgrade_request().await.unwrap().unwrap();
    assert_eq!((request.payment, request.payment_instructions.as_deref()), (None, Some("Bank 0000")));
}

#[tokio::test]
async fn a_receipt_goes_as_multipart_without_its_metadata() {
    let proof = json!({"data": {"id": "01P2", "kind": "image", "mime": "image/jpeg", "name": "Receipt.jpg", "bytes": 10, "locked": false, "created_at": "2026-11-09T10:13:00Z"}});
    let server = MockServer::start(Router::new().fixed("POST", "/api/upgrade-requests/01JF/proofs", Reply::json(201, proof)));
    let receipt = receipts::prepare("C:\\Users\\me\\Receipt.JPG", jpeg_with_gps()).unwrap();
    let sent = authed(&server).upload_proof("01JF", receipt).await.unwrap();
    assert_eq!(sent.id, "01P2");
    let request = &server.requests_to("POST", "/api/upgrade-requests/01JF/proofs")[0];
    assert!(request.header("content-type").unwrap().starts_with("multipart/form-data; boundary=echoledger-"));
    assert_eq!(request.header("authorization"), Some(format!("Bearer {TOKEN}").as_str()));
    let body = String::from_utf8_lossy(&request.body);
    assert!(body.contains("name=\"file\"; filename=\"Receipt.jpg\""), "{body}");
    assert!(body.contains("Content-Type: image/jpeg"));
    assert!(!body.contains("GPS"), "the location never leaves this computer");
}

#[tokio::test]
async fn the_server_s_refusals_read_as_calm_words() {
    let server = MockServer::start(
        Router::new()
            .route("POST", "/api/upgrade-requests/01JF/proofs", |_, n| match n {
                0 => Reply::json(413, json!({"message": "Too large", "code": "too_large"})).into(),
                1 => Reply::json(422, json!({"message": "The file is not valid.", "code": "validation_failed", "errors": {"file": ["The file type"]}, "reason": "pdf_password"})).into(),
                2 => Reply::json(409, json!({"message": "Too many", "code": "too_many_proofs", "max": 3})).into(),
                3 => Reply::json(409, json!({"message": "Too many", "code": "too_many_proofs", "max": 10})).into(),
                4 => Reply::json(429, json!({"message": "Slow down", "code": "rate_limited"})).header("Retry-After", "3600").into(),
                _ => Reply::json(409, json!({"message": "Decided", "code": "request_decided"})).into(),
            })
            .fixed("POST", "/api/upgrade-requests/01JF/paid", Reply::json(422, json!({"message": "Proof required", "code": "proof_required"}))),
    );
    let api = authed(&server);
    let send = || async { api.upload_proof("01JF", receipts::prepare("r.png", b"\x89PNG\r\n\x1a\n".to_vec()).unwrap()).await.unwrap_err().to_string() };
    assert_eq!(send().await, receipts::TOO_LARGE);
    assert_eq!(send().await, receipts::PDF_PASSWORD);
    assert_eq!(send().await, receipts::TOO_MANY_ROUND);
    assert_eq!(send().await, receipts::TOO_MANY_REQUEST);
    assert_eq!(send().await, "Too many tries today. Please try again in 1 hour.");
    assert_eq!(send().await, receipts::REQUEST_DECIDED);
    assert_eq!(api.mark_paid("01JF", None, None).await.unwrap_err().to_string(), receipts::PROOF_REQUIRED);
}

#[tokio::test]
async fn a_receipt_not_sent_yet_can_be_removed_and_a_sent_one_cannot() {
    let server = MockServer::start(
        Router::new()
            .fixed("DELETE", "/api/upgrade-requests/01JF/proofs/01P1", Reply::empty(204))
            .fixed("DELETE", "/api/upgrade-requests/01JF/proofs/01P2", Reply::json(409, json!({"message": "Locked", "code": "proof_locked"})))
            .fixed("DELETE", "/api/upgrade-requests/01JF/proofs/01P3", Reply::json(404, json!({"message": "Gone", "code": "proof_not_found"}))),
    );
    let api = authed(&server);
    api.delete_proof("01JF", "01P1").await.unwrap();
    assert_eq!(api.delete_proof("01JF", "01P2").await.unwrap_err().to_string(), receipts::PROOF_LOCKED);
    api.delete_proof("01JF", "01P3").await.expect("one already gone counts as removed");
}

#[tokio::test]
async fn a_receipt_s_picture_is_read_with_the_token_and_its_type_from_the_bytes() {
    let png = b"\x89PNG\r\n\x1a\nrest".to_vec();
    let mut reply = Reply::empty(200).header("Content-Type", "application/octet-stream").header("Cache-Control", "private, no-store");
    reply.body = png.clone();
    let server = MockServer::start(Router::new().fixed("GET", "/api/upgrade-requests/01JF/proofs/01P1", reply));
    let (mime, bytes) = authed(&server).proof_file("01JF", "01P1", "thumb").await.unwrap();
    assert_eq!((mime.as_str(), bytes), ("image/png", png));
    let request = &server.requests_to("GET", "/api/upgrade-requests/01JF/proofs/01P1")[0];
    assert_eq!(request.query(), Some("variant=thumb"));
    assert_eq!(request.header("authorization"), Some(format!("Bearer {TOKEN}").as_str()));
}

#[tokio::test]
async fn send_receipt_and_cancel_answer_with_the_request() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/upgrade-requests/01JF/paid", Reply::json(200, request_body("in_review")))
            .fixed("POST", "/api/upgrade-requests/01JF/cancel", Reply::json(200, {
                let mut b = request_body("cancelled");
                b["status"] = json!("declined");
                b
            })),
    );
    let api = authed(&server);
    let paid = api.mark_paid("01JF", Some("FT26313ABC7781"), Some("Sara Khan")).await.unwrap();
    assert_eq!(paid.payment.unwrap().stage, "in_review");
    assert_eq!(server.requests_to("POST", "/api/upgrade-requests/01JF/paid")[0].json(), json!({"payment_reference": "FT26313ABC7781", "payer_name": "Sara Khan"}));
    let cancelled = api.cancel_request("01JF").await.unwrap();
    assert_eq!((cancelled.status.as_str(), cancelled.payment.unwrap().stage.as_str()), ("declined", "cancelled"));
}

#[tokio::test]
async fn an_upgrade_the_server_will_not_take_reads_plainly() {
    let server = MockServer::start(Router::new().route("POST", "/api/upgrade-requests", |request, _| match request.json()["plan"].as_str() {
        Some("pro") => Reply::json(422, json!({"message": "The plan is invalid.", "code": "plan_not_offered"})).into(),
        Some("ultra") => Reply::json(403, json!({"message": "Only the owner", "code": "not_allowed"})).into(),
        _ => Reply::json(409, json!({"message": "Pending", "code": "request_pending", "request": {"plan_name": "Pro", "stage": "in_review", "owner_name": "Ana"}})).into(),
    }));
    let api = authed(&server);
    assert_eq!(api.request_upgrade("pro", None, None).await.unwrap_err().to_string(), receipts::PLAN_NOT_OFFERED);
    assert_eq!(api.request_upgrade("ultra", None, None).await.unwrap_err().to_string(), receipts::NOT_ALLOWED);
    assert_eq!(
        api.request_upgrade("team", None, None).await.unwrap_err().to_string(),
        "Ana has already asked to upgrade this workspace to Pro. We'll let everyone know when it's decided."
    );
}

#[tokio::test]
async fn plan_cards_carry_rupees_and_minutes_say_when_they_reset() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/me/entitlements", Reply::json(200, json!({
        "plan": {"key": "free", "name": "Free"},
        "limits": {"monthly_minutes": {"limit": 600, "used": 230, "resets_at": "2026-12-01"}},
        "plans": [{"key": "pro", "name": "Pro", "price": "Rs 2,999 a month", "price_pkr": 2999, "price_text": "Rs 2,999 a month",
                   "highlights": [" 600 transcription minutes a month ", "", "a", "b", "c", "d", "e"], "features": [], "limits": {}}]
    }))));
    let e = authed(&server).entitlements().await.unwrap().unwrap();
    assert_eq!(e.limits["monthly_minutes"].resets_at.as_deref(), Some("2026-12-01"));
    assert_eq!((e.plans[0].price_pkr, e.plans[0].price_text.as_deref()), (Some(2999.0), Some("Rs 2,999 a month")));
    assert_eq!(e.plans[0].highlights, ["600 transcription minutes a month", "a", "b", "c", "d"], "trimmed, five at most");
}
