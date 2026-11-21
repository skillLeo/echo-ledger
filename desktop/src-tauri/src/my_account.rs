//! Your account from the app (contract 2.0.0-l, docs/API.md "Account and security from the apps"): the name, the
//! profile picture, the email, the password, sign-in methods, two-step sign-in, browsers and devices. The window asks
//! for one of the actions below; the answer comes back whole (`AccountReply`), refusals included, so the window can say
//! each one in its own words (`code`, `accepts`, `error_codes`) instead of one sentence for all.
//!
//! The pure parts are here and tested: which route an action is, the ids that may go in a path, the reply, the QR code
//! of two-step sign-in, and the picture made ready for upload (JPEG, PNG or WebP of at most 5 MB, metadata dropped).

use serde::Serialize;

use crate::error::{AppError, AppResult};

/// One thing the window may ask of the account routes: its method and path (`{id}` for a browser, device or passkey).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Action {
    pub name: &'static str,
    pub method: &'static str,
    pub path: &'static str,
}

/// Every action the window may ask for; anything else is refused before a request is made.
pub const ACTIONS: [Action; 18] = [
    Action { name: "security", method: "GET", path: "account/security" },
    Action { name: "proof_code", method: "POST", path: "account/proof-code" },
    Action { name: "name", method: "PATCH", path: "account" },
    Action { name: "avatar_remove", method: "DELETE", path: "account/avatar" },
    Action { name: "email", method: "POST", path: "account/email" },
    Action { name: "email_confirm", method: "POST", path: "account/email/confirm" },
    Action { name: "email_cancel", method: "DELETE", path: "account/email" },
    Action { name: "password", method: "PUT", path: "account/password" },
    Action { name: "device_rename", method: "PATCH", path: "account/devices/{id}" },
    Action { name: "device_sign_out", method: "DELETE", path: "account/devices/{id}" },
    Action { name: "browser_sign_out", method: "DELETE", path: "account/browsers/{id}" },
    Action { name: "google_unlink", method: "DELETE", path: "account/google" },
    Action { name: "passkey_rename", method: "PATCH", path: "account/passkeys/{id}" },
    Action { name: "passkey_remove", method: "DELETE", path: "account/passkeys/{id}" },
    Action { name: "two_factor_begin", method: "POST", path: "account/two-factor" },
    Action { name: "two_factor_confirm", method: "POST", path: "account/two-factor/confirm" },
    Action { name: "recovery_codes", method: "POST", path: "account/two-factor/recovery-codes" },
    Action { name: "two_factor_off", method: "DELETE", path: "account/two-factor" },
];

/// The route of `name` with `id` in its path; an unknown action, a missing id or an id that could leave the path is
/// refused.
pub fn route(name: &str, id: Option<&str>) -> AppResult<(&'static str, String)> {
    let action = ACTIONS.iter().find(|a| a.name == name).ok_or_else(|| AppError::msg("That is not something this app can change."))?;
    if !action.path.contains("{id}") {
        return Ok((action.method, action.path.to_string()));
    }
    let id = id.filter(|id| safe_id(id)).ok_or_else(|| AppError::msg("That is no longer on the server."))?;
    Ok((action.method, action.path.replace("{id}", id)))
}

/// An id the server gave (ULIDs, opaque browser ids): letters, digits, `-` and `_`, at most 128.
pub fn safe_id(id: &str) -> bool {
    (1..=128).contains(&id.len()) && id.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_')
}

/// What the window gets back from an account action: the answer, or the refusal with what the window needs to say it.
#[derive(Clone, Debug, Default, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct AccountReply {
    pub ok: bool,
    pub status: u16,
    /// The answer's body (`null` for a `204`).
    pub data: serde_json::Value,
    /// The refusal's `code` (`wrong_password`, `invalid_otp`, `proof_required`, `email_taken`…).
    pub code: Option<String>,
    /// The app's sentence for the refusal, for a code the window has no words of its own for.
    pub message: Option<String>,
    /// The proofs this account can give (`password`, `otp`, `email_code`), on `proof_required` and `invalid_otp`.
    pub accepts: Vec<String>,
    /// Field errors as codes (`{"new": [{"code": "too_short", "min": 12}]}`), for the window's own words.
    pub error_codes: serde_json::Value,
    /// Field errors as the server wrote them, when a field has no code.
    pub errors: serde_json::Value,
    pub retry_after: Option<u64>,
    /// Two-step sign-in's QR code, drawn from `otpauth_uri`: each row of dark (`1`) and light (`0`) modules.
    pub qr: Option<Vec<String>>,
}

impl AccountReply {
    /// The reply to an answer with this status and body (`Retry-After` in seconds when sent).
    pub fn from_answer(status: u16, body: serde_json::Value, retry_after: Option<u64>) -> Self {
        if (200..300).contains(&status) {
            let qr = body.get("otpauth_uri").and_then(serde_json::Value::as_str).and_then(qr_rows);
            return Self { ok: true, status, data: body, qr, ..Self::default() };
        }
        let code = body.get("code").and_then(serde_json::Value::as_str).map(str::to_string);
        let message = AppError::Server {
            status,
            message: body.get("message").and_then(serde_json::Value::as_str).unwrap_or_default().to_string(),
            code: code.clone(),
            retry_after_secs: retry_after,
        }
        .to_string();
        let accepts = body.get("accepts").and_then(serde_json::Value::as_array)
            .map(|all| all.iter().filter_map(|a| a.as_str().map(str::to_string)).collect()).unwrap_or_default();
        Self {
            ok: false,
            status,
            data: serde_json::Value::Null,
            code,
            message: Some(message),
            accepts,
            error_codes: body.get("error_codes").cloned().unwrap_or(serde_json::Value::Null),
            errors: body.get("errors").cloned().unwrap_or(serde_json::Value::Null),
            retry_after,
            qr: None,
        }
    }
}

/// The QR code of an `otpauth://` link as rows of `1` (dark) and `0` (light), medium error correction; `None` for
/// anything else or a link too long for a QR code.
pub fn qr_rows(text: &str) -> Option<Vec<String>> {
    if !text.starts_with("otpauth://") {
        return None;
    }
    let code = qrcodegen::QrCode::encode_text(text, qrcodegen::QrCodeEcc::Medium).ok()?;
    let size = code.size();
    Some((0..size).map(|y| (0..size).map(|x| if code.get_module(x, y) { '1' } else { '0' }).collect()).collect())
}

/// The largest picture the server takes.
pub const MAX_AVATAR_BYTES: usize = 5 * 1024 * 1024;
/// A picture made again here is at most this wide or tall (the server keeps 512 px).
pub const AVATAR_SIDE: u32 = 2048;
/// What the picker offers; the bytes decide all the same.
pub const AVATAR_EXTENSIONS: [&str; 11] = ["jpg", "jpeg", "png", "webp", "heic", "heif", "gif", "bmp", "tif", "tiff", "avif"];

/// A picture ready to send: its bytes and type.
#[derive(Debug, PartialEq, Eq)]
pub struct AvatarFile {
    pub bytes: Vec<u8>,
    pub mime: &'static str,
    pub name: &'static str,
}

/// The picture is HEIC or HEIF (an iPhone's photos), which only the system can read.
pub fn is_heif(bytes: &[u8]) -> bool {
    bytes.len() >= 12 && &bytes[4..8] == b"ftyp" && matches!(&bytes[8..12], b"heic" | b"heix" | b"hevc" | b"heim" | b"heis" | b"mif1" | b"msf1" | b"avif")
}

/// What the app says when a picture can't be read.
pub const UNREADABLE: &str = "This picture can't be read. Choose a JPEG, PNG or WebP photo.";
/// HEIC on a computer that can't read it.
pub const HEIC_UNREADABLE: &str = "This computer can't read HEIC photos. Save the photo as JPEG or PNG, then choose it again.";
/// Still over 5 MB after making it smaller.
pub const TOO_LARGE: &str = "This picture is too large. Choose one under 5 MB.";

/// The picture as the server takes it: a JPEG, PNG or WebP of at most 5 MB is sent with its metadata (place, camera)
/// taken out; anything else, or a larger file, is made again as a JPEG of at most 2048 px. HEIC is turned into a
/// JPEG by the system first (`from_heif`, macOS) and refused where it can't be.
pub fn prepare_avatar(bytes: Vec<u8>, from_heif: impl FnOnce(&[u8]) -> Option<Vec<u8>>) -> Result<AvatarFile, &'static str> {
    use crate::receipts::{ReceiptKind, sniff, strip_jpeg, strip_png, strip_webp};
    let bytes = if is_heif(&bytes) { from_heif(&bytes).ok_or(HEIC_UNREADABLE)? } else { bytes };
    match sniff(&bytes) {
        Some(ReceiptKind::Jpeg) if bytes.len() <= MAX_AVATAR_BYTES => {
            return Ok(AvatarFile { bytes: strip_jpeg(&bytes).unwrap_or(bytes), mime: "image/jpeg", name: "avatar.jpg" });
        }
        Some(ReceiptKind::Png) if bytes.len() <= MAX_AVATAR_BYTES => {
            return Ok(AvatarFile { bytes: strip_png(&bytes).unwrap_or(bytes), mime: "image/png", name: "avatar.png" });
        }
        Some(ReceiptKind::Webp) if bytes.len() <= MAX_AVATAR_BYTES => {
            return Ok(AvatarFile { bytes: strip_webp(&bytes).unwrap_or(bytes), mime: "image/webp", name: "avatar.webp" });
        }
        _ => {}
    }
    let picture = decode(&bytes).ok_or(UNREADABLE)?;
    let picture = if picture.width() > AVATAR_SIDE || picture.height() > AVATAR_SIDE {
        picture.resize(AVATAR_SIDE, AVATAR_SIDE, image::imageops::FilterType::Lanczos3)
    } else {
        picture
    };
    let rgb = picture.to_rgb8();
    for quality in [88u8, 75, 60] {
        let mut out = Vec::new();
        let encoder = image::codecs::jpeg::JpegEncoder::new_with_quality(&mut out, quality);
        rgb.write_with_encoder(encoder).map_err(|_| UNREADABLE)?;
        if out.len() <= MAX_AVATAR_BYTES {
            return Ok(AvatarFile { bytes: out, mime: "image/jpeg", name: "avatar.jpg" });
        }
    }
    Err(TOO_LARGE)
}

/// Any picture this app can read: WebP through libwebp (already here for screens), the rest through the image crate.
fn decode(bytes: &[u8]) -> Option<image::DynamicImage> {
    if crate::receipts::sniff(bytes) == Some(crate::receipts::ReceiptKind::Webp) {
        let decoded = webp::Decoder::new(bytes).decode()?;
        let (width, height, pixels) = (decoded.width(), decoded.height(), decoded.to_vec());
        return if decoded.is_alpha() {
            image::RgbaImage::from_raw(width, height, pixels).map(image::DynamicImage::ImageRgba8)
        } else {
            image::RgbImage::from_raw(width, height, pixels).map(image::DynamicImage::ImageRgb8)
        };
    }
    image::load_from_memory(bytes).ok()
}

/// macOS turns a HEIC photo into a JPEG with its own `sips` (in /usr/bin on every Mac); `None` when it can't.
#[cfg(target_os = "macos")]
pub fn heif_to_jpeg(bytes: &[u8]) -> Option<Vec<u8>> {
    // A folder of its own in the system's temporary folder, removed after.
    let dir = std::env::temp_dir().join(format!("echoledger-avatar-{}", uuid::Uuid::new_v4()));
    std::fs::create_dir(&dir).ok()?;
    let (input, output) = (dir.join("photo.heic"), dir.join("photo.jpg"));
    let answer = (|| {
        std::fs::write(&input, bytes).ok()?;
        let done = std::process::Command::new("/usr/bin/sips")
        // No -Z: it makes small photos larger. A large result is made smaller by prepare_avatar.
            .args(["-s", "format", "jpeg"])
        .arg(&input)
        .arg("--out")
        .arg(&output)
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .status()
        .ok()?;
        if !done.success() {
            return None;
        }
        std::fs::read(&output).ok()
    })();
    let _ = std::fs::remove_dir_all(&dir);
    answer
}

/// Other systems read no HEIC here.
#[cfg(not(target_os = "macos"))]
pub fn heif_to_jpeg(_bytes: &[u8]) -> Option<Vec<u8>> {
    None
}

/// A picture of the person kept on this computer by its version (`avatars/<version>.img`), so it shows offline.
pub fn avatar_cache_path(dir: &std::path::Path, version: u64) -> std::path::PathBuf {
    dir.join("avatars").join(format!("{version}.img"))
}

/// The recovery codes as a text file to keep somewhere safe.
pub fn recovery_codes_text(codes: &[String], email: &str) -> String {
    let mut text = format!("EchoLedger recovery codes for {email}\n\nEach code works once, in place of the 6-digit code from your authenticator app.\nKeep them somewhere safe.\n\n");
    for code in codes {
        text.push_str(code.trim());
        text.push('\n');
    }
    text
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn only_the_listed_actions_reach_the_server_and_ids_stay_in_their_segment() {
        assert_eq!(route("name", None).unwrap(), ("PATCH", "account".to_string()));
        assert_eq!(route("two_factor_off", None).unwrap(), ("DELETE", "account/two-factor".to_string()));
        assert_eq!(route("device_sign_out", Some("01m7zddc7v12m6cg01k9mbxa2h")).unwrap(), ("DELETE", "account/devices/01m7zddc7v12m6cg01k9mbxa2h".to_string()));
        assert_eq!(route("passkey_rename", Some("abc_DEF-1")).unwrap().1, "account/passkeys/abc_DEF-1");
        assert!(route("delete_everything", None).is_err());
        assert!(route("device_sign_out", None).is_err(), "an id is needed");
        for bad in ["../workspace", "a/b", "a?b=1", "a%2F", "", &"x".repeat(129)] {
            assert!(route("browser_sign_out", Some(bad)).is_err(), "{bad}");
        }
        assert_eq!(ACTIONS.iter().map(|a| a.name).collect::<std::collections::HashSet<_>>().len(), ACTIONS.len());
    }

    #[test]
    fn a_refusal_keeps_what_the_window_needs_to_say_it() {
        let reply = AccountReply::from_answer(422, json!({"message": "Proof needed.", "code": "proof_required", "accepts": ["otp"]}), None);
        assert!(!reply.ok);
        assert_eq!((reply.status, reply.code.as_deref(), reply.accepts.clone()), (422, Some("proof_required"), vec!["otp".to_string()]));
        let fields = AccountReply::from_answer(422, json!({"message": "Too short.", "code": "validation_failed",
            "errors": {"new": ["The password must be at least 12 characters."]}, "error_codes": {"new": [{"code": "too_short", "min": 12}]}}), None);
        assert_eq!(fields.error_codes["new"][0]["min"], 12);
        assert_eq!(fields.errors["new"][0], "The password must be at least 12 characters.");
        let locked = AccountReply::from_answer(429, json!({"message": "Too many.", "code": "too_many_attempts"}), Some(900));
        assert_eq!(locked.retry_after, Some(900));
        assert_eq!(locked.message.as_deref(), Some("Too many sign-in attempts. Wait 15 minutes, then try again."));
        let done = AccountReply::from_answer(204, serde_json::Value::Null, None);
        assert!(done.ok && done.code.is_none() && done.qr.is_none());
    }

    #[test]
    fn two_step_s_answer_comes_with_its_qr_code() {
        let reply = AccountReply::from_answer(200, json!({"ceremony": "c", "secret": "JBSWY3DPEHPK3PXP",
            "otpauth_uri": "otpauth://totp/EchoLedger:a%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=EchoLedger"}), None);
        let rows = reply.qr.expect("a QR code");
        assert!(rows.len() >= 21 && rows.iter().all(|r| r.len() == rows.len() && r.chars().all(|c| c == '0' || c == '1')));
        // The finder pattern in the top left corner: seven dark modules.
        assert_eq!(&rows[0][..7], "1111111");
        assert_eq!(qr_rows("https://example.test"), None, "only an otpauth link");
    }

    fn jpeg(width: u32, height: u32) -> Vec<u8> {
        let mut out = Vec::new();
        image::RgbImage::from_pixel(width, height, image::Rgb([200, 30, 30]))
            .write_with_encoder(image::codecs::jpeg::JpegEncoder::new_with_quality(&mut out, 90)).unwrap();
        out
    }

    #[test]
    fn a_jpeg_png_or_webp_under_5_mb_is_sent_as_it_is_without_its_metadata() {
        let photo = jpeg(300, 300);
        let ready = prepare_avatar(photo.clone(), |_| None).unwrap();
        assert_eq!((ready.mime, ready.name), ("image/jpeg", "avatar.jpg"));
        assert!(ready.bytes.len() <= photo.len());
        let mut png = Vec::new();
        image::RgbImage::from_pixel(200, 200, image::Rgb([0, 0, 0])).write_to(&mut std::io::Cursor::new(&mut png), image::ImageFormat::Png).unwrap();
        assert_eq!(prepare_avatar(png, |_| None).unwrap().mime, "image/png");
    }

    #[test]
    fn another_format_is_made_again_as_a_jpeg_and_a_large_one_made_smaller() {
        let mut gif = Vec::new();
        image::RgbaImage::from_pixel(300, 200, image::Rgba([0, 120, 0, 255])).write_to(&mut std::io::Cursor::new(&mut gif), image::ImageFormat::Gif).unwrap();
        let ready = prepare_avatar(gif, |_| None).unwrap();
        assert_eq!(ready.mime, "image/jpeg");
        assert_eq!(image::load_from_memory(&ready.bytes).unwrap().width(), 300);
        let mut bmp = Vec::new();
        image::RgbImage::from_pixel(3000, 1000, image::Rgb([9, 9, 9])).write_to(&mut std::io::Cursor::new(&mut bmp), image::ImageFormat::Bmp).unwrap();
        let ready = prepare_avatar(bmp, |_| None).unwrap();
        let back = image::load_from_memory(&ready.bytes).unwrap();
        assert_eq!((back.width(), back.height()), (2048, 683));
        assert!(ready.bytes.len() <= MAX_AVATAR_BYTES);
    }

    #[test]
    fn heic_goes_through_the_system_or_is_refused_in_plain_words() {
        let mut heic = vec![0, 0, 0, 24];
        heic.extend_from_slice(b"ftypheic");
        heic.extend_from_slice(&[0; 16]);
        assert!(is_heif(&heic));
        assert_eq!(prepare_avatar(heic.clone(), |_| None).unwrap_err(), HEIC_UNREADABLE);
        let converted = prepare_avatar(heic, |_| Some(jpeg(64, 64))).unwrap();
        assert_eq!(converted.mime, "image/jpeg");
        assert_eq!(prepare_avatar(b"not a picture".to_vec(), |_| None).unwrap_err(), UNREADABLE);
    }

    /// On a Mac the system's own tool turns an iPhone photo (HEIC) into a JPEG: made here with the same tool.
    #[cfg(target_os = "macos")]
    #[test]
    fn a_heic_photo_is_turned_into_a_jpeg_on_a_mac() {
        let dir = std::env::temp_dir().join(format!("echoledger-heic-test-{}", uuid::Uuid::new_v4()));
        std::fs::create_dir(&dir).unwrap();
        let (jpg, heic) = (dir.join("in.jpg"), dir.join("in.heic"));
        std::fs::write(&jpg, jpeg(400, 300)).unwrap();
        let made = std::process::Command::new("/usr/bin/sips").args(["-s", "format", "heic"]).arg(&jpg).arg("--out").arg(&heic)
            .stdout(std::process::Stdio::null()).stderr(std::process::Stdio::null()).status().map(|s| s.success()).unwrap_or(false);
        if made {
            let bytes = std::fs::read(&heic).unwrap();
            assert!(is_heif(&bytes));
            let ready = prepare_avatar(bytes, heif_to_jpeg).unwrap();
            assert_eq!(ready.mime, "image/jpeg");
            assert_eq!(image::load_from_memory(&ready.bytes).unwrap().width(), 400);
        }
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn recovery_codes_are_saved_one_a_line() {
        let text = recovery_codes_text(&["aaaa-bbbb".into(), " cccc-dddd ".into()], "a@example.test");
        assert!(text.starts_with("EchoLedger recovery codes for a@example.test"));
        assert!(text.ends_with("aaaa-bbbb\ncccc-dddd\n"));
    }
}
