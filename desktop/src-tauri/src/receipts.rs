//! Payment receipts (v1.7, docs/API.md "Payment receipts"): what the core checks before a screenshot or a PDF of a bank
//! transfer goes to the server, and the words the app says when the server refuses one.
//!
//! The type is read from the bytes, never from the name. Images lose their metadata (EXIF, XMP, text chunks) before
//! they leave this computer; the server re-encodes them again. Nothing is kept on disk: the bytes go from the file or
//! the clipboard straight into the request.

use crate::error::{AppError, AppResult};

/// The largest receipt the server takes (5 MB).
pub const MAX_RECEIPT_BYTES: u64 = 5 * 1024 * 1024;

pub const TOO_LARGE: &str = "This file is over 5 MB. Try a screenshot instead.";
pub const FILE_TYPE: &str = "Send a screenshot or photo (JPEG, PNG or WebP) or a PDF.";
pub const PDF_PASSWORD: &str = "This PDF has a password. Please add a screenshot of the receipt instead.";
pub const PDF_ACTIVE: &str = "We can't accept this PDF. Please add a screenshot of the receipt instead.";
pub const UNREADABLE: &str = "We couldn't read this picture. Please try another screenshot.";
pub const TOO_MANY_ROUND: &str = "You can add up to 3 files at a time. Remove one to add another.";
pub const TOO_MANY_REQUEST: &str = "We can't take more files on this request. Reply to our email if you need to send more.";
pub const PROOF_LOCKED: &str = "This receipt has been sent, so it can't be removed.";
pub const PROOF_REQUIRED: &str = "Add a screenshot, or type the transaction ID.";
pub const REQUEST_DECIDED: &str = "This request has already been decided.";
pub const PLAN_NOT_OFFERED: &str = "This plan isn't available right now. Please choose another.";
pub const NOT_ALLOWED: &str = "Only the owner of this workspace can upgrade it. Ask them to upgrade.";
pub const NO_CONNECTION: &str = "Couldn't reach EchoLedger. Try again.";
pub const SERVER_TROUBLE: &str = "Something went wrong on our side. Try again in a moment.";
pub const REFERENCE_SHAPE: &str = "A transaction ID is 4 to 64 letters or numbers. Spaces and . _ / # - are fine too.";
pub const PAYER_SHAPE: &str = "Write the name in 120 characters or fewer.";

/// What a receipt is, read from its first bytes.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum ReceiptKind {
    Jpeg,
    Png,
    Webp,
    Pdf,
}

impl ReceiptKind {
    pub fn mime(self) -> &'static str {
        match self {
            Self::Jpeg => "image/jpeg",
            Self::Png => "image/png",
            Self::Webp => "image/webp",
            Self::Pdf => "application/pdf",
        }
    }

    fn extension(self) -> &'static str {
        match self {
            Self::Jpeg => "jpg",
            Self::Png => "png",
            Self::Webp => "webp",
            Self::Pdf => "pdf",
        }
    }
}

/// The receipt's type from its magic bytes; none for anything else (a HEIC photo, a Word file, a renamed text file).
pub fn sniff(bytes: &[u8]) -> Option<ReceiptKind> {
    if bytes.starts_with(&[0xFF, 0xD8, 0xFF]) {
        Some(ReceiptKind::Jpeg)
    } else if bytes.starts_with(b"\x89PNG\r\n\x1a\n") {
        Some(ReceiptKind::Png)
    } else if bytes.len() >= 12 && &bytes[..4] == b"RIFF" && &bytes[8..12] == b"WEBP" {
        Some(ReceiptKind::Webp)
    } else if bytes.starts_with(b"%PDF-") {
        Some(ReceiptKind::Pdf)
    } else {
        None
    }
}

/// A receipt ready to send: a clean file name, its type and its bytes without metadata.
#[derive(Debug, PartialEq, Eq)]
pub struct Receipt {
    pub name: String,
    pub mime: &'static str,
    pub bytes: Vec<u8>,
}

/// Checks a receipt's size and type and strips an image's metadata. `name` is only used for the name staff see.
pub fn prepare(name: &str, bytes: Vec<u8>) -> AppResult<Receipt> {
    if bytes.len() as u64 > MAX_RECEIPT_BYTES {
        return Err(AppError::msg(TOO_LARGE));
    }
    let kind = sniff(&bytes).ok_or_else(|| AppError::msg(FILE_TYPE))?;
    let bytes = match kind {
        ReceiptKind::Jpeg => strip_jpeg(&bytes).unwrap_or(bytes),
        ReceiptKind::Png => strip_png(&bytes).unwrap_or(bytes),
        ReceiptKind::Webp => strip_webp(&bytes).unwrap_or(bytes),
        ReceiptKind::Pdf => bytes,
    };
    Ok(Receipt { name: clean_name(name, kind), mime: kind.mime(), bytes })
}

/// "Receipt 9 Nov.png" from a path or a pasted file's name: no folders, no control characters, at most 80
/// characters, and the extension of what the file really is.
pub fn clean_name(name: &str, kind: ReceiptKind) -> String {
    let base = name.rsplit(['/', '\\']).next().unwrap_or("");
    let stem = match base.rsplit_once('.') {
        Some((stem, _)) => stem,
        None => base,
    };
    let stem: String = stem.chars().filter(|c| !c.is_control() && !matches!(c, '"' | '<' | '>' | ':' | '|' | '?' | '*')).collect();
    let stem: String = stem.split_whitespace().collect::<Vec<_>>().join(" ").chars().take(80).collect();
    let stem = if stem.is_empty() { "Receipt".to_string() } else { stem };
    format!("{stem}.{}", kind.extension())
}

/// A JPEG without its APP1 (EXIF, XMP), APP13 (IPTC) and comment segments. The colour profile (APP2), JFIF (APP0)
/// and Adobe (APP14) segments stay, since they change how the picture looks. None when the file is not a JPEG this
/// can walk, which is then sent as it is (the server re-encodes it anyway).
pub fn strip_jpeg(bytes: &[u8]) -> Option<Vec<u8>> {
    if !bytes.starts_with(&[0xFF, 0xD8]) {
        return None;
    }
    let mut out = Vec::with_capacity(bytes.len());
    out.extend_from_slice(&[0xFF, 0xD8]);
    let mut i = 2;
    loop {
        if i >= bytes.len() || bytes[i] != 0xFF {
            return None;
        }
        // Fill bytes before a marker.
        let mut m = i + 1;
        while m < bytes.len() && bytes[m] == 0xFF {
            m += 1;
        }
        let marker = *bytes.get(m)?;
        match marker {
            // The image data starts: everything from here on is copied as it is.
            0xDA => {
                out.extend_from_slice(&bytes[i..]);
                return Some(out);
            }
            0xD9 => {
                out.extend_from_slice(&[0xFF, 0xD9]);
                return Some(out);
            }
            0x01 | 0xD0..=0xD7 => {
                out.extend_from_slice(&[0xFF, marker]);
                i = m + 1;
            }
            _ => {
                let len = u16::from_be_bytes([*bytes.get(m + 1)?, *bytes.get(m + 2)?]) as usize;
                if len < 2 {
                    return None;
                }
                let end = m + 1 + len;
                if end > bytes.len() {
                    return None;
                }
                if !matches!(marker, 0xE1 | 0xED | 0xFE) {
                    out.extend_from_slice(&[0xFF, marker]);
                    out.extend_from_slice(&bytes[m + 1..end]);
                }
                i = end;
            }
        }
    }
}

/// A PNG without its text, EXIF and time chunks. None when it is not a PNG this can walk.
pub fn strip_png(bytes: &[u8]) -> Option<Vec<u8>> {
    const SIGNATURE: &[u8] = b"\x89PNG\r\n\x1a\n";
    if !bytes.starts_with(SIGNATURE) {
        return None;
    }
    let mut out = Vec::with_capacity(bytes.len());
    out.extend_from_slice(SIGNATURE);
    let mut i = SIGNATURE.len();
    while i < bytes.len() {
        let len = u32::from_be_bytes(bytes.get(i..i + 4)?.try_into().ok()?) as usize;
        let kind = bytes.get(i + 4..i + 8)?;
        let end = i.checked_add(12)?.checked_add(len)?;
        if end > bytes.len() {
            return None;
        }
        if !matches!(kind, b"eXIf" | b"tEXt" | b"zTXt" | b"iTXt" | b"tIME") {
            out.extend_from_slice(&bytes[i..end]);
        }
        let last = kind == b"IEND";
        i = end;
        if last {
            break;
        }
    }
    Some(out)
}

/// A WebP without its EXIF and XMP chunks, with the extended header's flags and the RIFF size to match. None when it
/// is not a WebP this can walk.
pub fn strip_webp(bytes: &[u8]) -> Option<Vec<u8>> {
    if bytes.len() < 12 || &bytes[..4] != b"RIFF" || &bytes[8..12] != b"WEBP" {
        return None;
    }
    let declared = u32::from_le_bytes(bytes[4..8].try_into().ok()?) as usize;
    let end_of_riff = (8 + declared).min(bytes.len());
    let mut body = Vec::with_capacity(bytes.len());
    body.extend_from_slice(b"WEBP");
    let mut i = 12;
    while i + 8 <= end_of_riff {
        let kind = &bytes[i..i + 4];
        let len = u32::from_le_bytes(bytes[i + 4..i + 8].try_into().ok()?) as usize;
        let padded = len + (len & 1);
        let end = (i + 8).checked_add(padded)?;
        if i + 8 + len > bytes.len() {
            return None;
        }
        let end = end.min(bytes.len());
        if kind != b"EXIF" && kind != b"XMP " {
            let start = body.len();
            body.extend_from_slice(&bytes[i..end]);
            if kind == b"VP8X" && len >= 1 {
                // Flags: ICC 0x20, alpha 0x10, EXIF 0x08, XMP 0x04, animation 0x02.
                body[start + 8] &= !(0x08 | 0x04);
            }
            if end - i < 8 + padded {
                body.push(0);
            }
        }
        i = end;
    }
    let mut out = Vec::with_capacity(body.len() + 8);
    out.extend_from_slice(b"RIFF");
    out.extend_from_slice(&(body.len() as u32).to_le_bytes());
    out.extend_from_slice(&body);
    Some(out)
}

/// The bank's transaction ID as typed: none when empty; 4–64 characters of letters, digits, spaces and `._/#-`.
pub fn reference_of(text: Option<&str>) -> AppResult<Option<String>> {
    let text = text.map(|t| t.split_whitespace().collect::<Vec<_>>().join(" ")).unwrap_or_default();
    if text.is_empty() {
        return Ok(None);
    }
    let fine = |c: char| c.is_ascii_alphanumeric() || matches!(c, ' ' | '.' | '_' | '/' | '#' | '-');
    let count = text.chars().count();
    if !(4..=64).contains(&count) || !text.chars().all(fine) {
        return Err(AppError::msg(REFERENCE_SHAPE));
    }
    Ok(Some(text))
}

/// The name the money came from: none when empty, at most 120 characters.
pub fn payer_of(text: Option<&str>) -> AppResult<Option<String>> {
    let text = text.map(|t| t.split_whitespace().collect::<Vec<_>>().join(" ")).unwrap_or_default();
    if text.is_empty() {
        return Ok(None);
    }
    if text.chars().count() > 120 {
        return Err(AppError::msg(PAYER_SHAPE));
    }
    Ok(Some(text))
}

/// An id of the server's (a request or a receipt): letters and digits only, at most 64.
pub fn checked_id(id: &str) -> AppResult<&str> {
    if id.is_empty() || id.len() > 64 || !id.chars().all(|c| c.is_ascii_alphanumeric()) {
        return Err(AppError::msg("That request is not one of the server's."));
    }
    Ok(id)
}

/// "Too many tries today. Please try again in 45 minutes."
pub fn rate_limited_text(retry_after_secs: Option<u64>) -> String {
    match retry_after_secs {
        Some(secs) if secs >= 3600 => {
            let hours = secs.div_ceil(3600);
            format!("Too many tries today. Please try again in {hours} hour{}.", if hours == 1 { "" } else { "s" })
        }
        Some(secs) => format!("Too many tries today. Please try again in {}.", crate::account::wait_text(secs)),
        None => "Too many tries today. Please try again later.".to_string(),
    }
}

/// What the person reads when a payment route refuses: every code of the contract in calm words, with a next step.
/// `body` is the server's answer (its `errors.file`, `reason`, `max` and `scope` choose the sentence).
pub fn refusal_text(status: u16, body: &serde_json::Value, retry_after_secs: Option<u64>) -> String {
    let code = body.get("code").and_then(|c| c.as_str()).unwrap_or("");
    let reason = body
        .get("reason")
        .and_then(|r| r.as_str())
        .or_else(|| body.pointer("/errors/reason").and_then(|r| r.as_str()))
        .or_else(|| body.pointer("/errors/file/reason").and_then(|r| r.as_str()))
        .unwrap_or("");
    match (status, code) {
        (429, _) | (_, "rate_limited") => rate_limited_text(retry_after_secs),
        (413, _) | (_, "too_large") => TOO_LARGE.into(),
        (_, "too_many_proofs") => {
            let scope = body.get("scope").and_then(|s| s.as_str());
            let max = body.get("max").and_then(|m| m.as_u64());
            if scope == Some("request") || (scope.is_none() && max.is_some_and(|m| m > 3)) { TOO_MANY_REQUEST.into() } else { TOO_MANY_ROUND.into() }
        }
        (_, "proof_locked") => PROOF_LOCKED.into(),
        (_, "proof_required") => PROOF_REQUIRED.into(),
        (_, "request_decided") => REQUEST_DECIDED.into(),
        (_, "plan_not_offered") => PLAN_NOT_OFFERED.into(),
        (403, "not_allowed") => NOT_ALLOWED.into(),
        (_, "validation_failed") | (422, _) => match reason {
            "pdf_password" => PDF_PASSWORD.into(),
            "pdf_active" => PDF_ACTIVE.into(),
            "unreadable" | "too_many_pixels" => UNREADABLE.into(),
            "file_type" => FILE_TYPE.into(),
            _ if body.pointer("/errors/file").is_some() => FILE_TYPE.into(),
            _ if body.pointer("/errors/plan").is_some() => PLAN_NOT_OFFERED.into(),
            _ => body.get("message").and_then(|m| m.as_str()).map(str::trim).filter(|m| !m.is_empty()).unwrap_or(SERVER_TROUBLE).to_string(),
        },
        (s, _) if s >= 500 => SERVER_TROUBLE.into(),
        _ => body.get("message").and_then(|m| m.as_str()).map(str::trim).filter(|m| !m.is_empty()).unwrap_or(SERVER_TROUBLE).to_string(),
    }
}

/// A transport failure on a payment route reads as "Couldn't reach EchoLedger. Try again."; everything else stays.
pub fn calm(e: AppError) -> AppError {
    match e {
        AppError::Network(_) => AppError::msg(NO_CONNECTION),
        e => e,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn jpeg_with_exif() -> Vec<u8> {
        let mut j = vec![0xFF, 0xD8];
        // APP0 JFIF
        j.extend_from_slice(&[0xFF, 0xE0, 0x00, 0x10, b'J', b'F', b'I', b'F', 0, 1, 1, 0, 0, 1, 0, 1, 0, 0]);
        // APP1 Exif with a GPS-looking payload
        let exif = b"Exif\0\0GPS 24.8607 67.0011";
        j.extend_from_slice(&[0xFF, 0xE1]);
        j.extend_from_slice(&((exif.len() + 2) as u16).to_be_bytes());
        j.extend_from_slice(exif);
        // COM
        j.extend_from_slice(&[0xFF, 0xFE, 0x00, 0x07, b'p', b'h', b'o', b'n', b'e']);
        // DQT (kept)
        j.extend_from_slice(&[0xFF, 0xDB, 0x00, 0x04, 0x00, 0x01]);
        // SOS and data
        j.extend_from_slice(&[0xFF, 0xDA, 0x00, 0x02, 0x11, 0x22, 0xFF, 0x00, 0x33, 0xFF, 0xD9]);
        j
    }

    fn png_chunk(kind: &[u8], data: &[u8]) -> Vec<u8> {
        let mut c = (data.len() as u32).to_be_bytes().to_vec();
        c.extend_from_slice(kind);
        c.extend_from_slice(data);
        c.extend_from_slice(&[0, 0, 0, 0]);
        c
    }

    #[test]
    fn the_type_is_read_from_the_bytes() {
        assert_eq!(sniff(&jpeg_with_exif()), Some(ReceiptKind::Jpeg));
        assert_eq!(sniff(b"\x89PNG\r\n\x1a\n...."), Some(ReceiptKind::Png));
        assert_eq!(sniff(b"RIFF\x04\0\0\0WEBPVP8 "), Some(ReceiptKind::Webp));
        assert_eq!(sniff(b"%PDF-1.7\n"), Some(ReceiptKind::Pdf));
        assert_eq!(sniff(b"\0\0\0\x18ftypheic"), None, "HEIC is not taken");
        assert_eq!(sniff(b"PK\x03\x04"), None);
        assert_eq!(sniff(b"hello.png"), None);
    }

    #[test]
    fn a_file_over_5_mb_or_of_another_type_is_refused_before_it_is_sent() {
        let big = vec![0xFF; (MAX_RECEIPT_BYTES + 1) as usize];
        assert_eq!(prepare("big.jpg", big).unwrap_err().to_string(), TOO_LARGE);
        assert_eq!(prepare("notes.png", b"just text".to_vec()).unwrap_err().to_string(), FILE_TYPE);
        let fine = prepare("C:\\Users\\me\\Pictures\\Receipt 9 Nov.PNG", b"\x89PNG\r\n\x1a\n".to_vec()).unwrap();
        assert_eq!((fine.name.as_str(), fine.mime), ("Receipt 9 Nov.png", "image/png"));
    }

    #[test]
    fn a_jpeg_loses_its_exif_and_comments_and_keeps_the_rest() {
        let original = jpeg_with_exif();
        let stripped = strip_jpeg(&original).unwrap();
        assert!(!stripped.windows(4).any(|w| w == b"Exif"), "no EXIF left");
        assert!(!stripped.windows(5).any(|w| w == b"phone"), "no comment left");
        assert!(stripped.windows(4).any(|w| w == b"JFIF"), "JFIF stays");
        assert!(stripped.ends_with(&[0xFF, 0xDA, 0x00, 0x02, 0x11, 0x22, 0xFF, 0x00, 0x33, 0xFF, 0xD9]), "the image data is untouched");
        assert_eq!(prepare("r.jpg", original).unwrap().bytes, stripped);
        assert_eq!(strip_jpeg(&[0xFF, 0xD8, 0x00]), None, "a broken file is sent as it is");
    }

    #[test]
    fn a_png_loses_its_text_and_exif_chunks() {
        let mut png = b"\x89PNG\r\n\x1a\n".to_vec();
        png.extend(png_chunk(b"IHDR", &[0; 13]));
        png.extend(png_chunk(b"tEXt", b"Author\0Me"));
        png.extend(png_chunk(b"eXIf", b"MM\0*GPS"));
        png.extend(png_chunk(b"IDAT", &[1, 2, 3]));
        png.extend(png_chunk(b"IEND", &[]));
        let stripped = strip_png(&png).unwrap();
        assert!(!stripped.windows(4).any(|w| w == b"tEXt" || w == b"eXIf"));
        assert!(stripped.windows(4).any(|w| w == b"IHDR") && stripped.windows(4).any(|w| w == b"IDAT") && stripped.windows(4).any(|w| w == b"IEND"));
        assert_eq!(stripped.len(), png.len() - (12 + 9) - (12 + 7));
    }

    #[test]
    fn a_webp_loses_its_exif_and_xmp_and_says_so_in_its_header() {
        let chunk = |kind: &[u8], data: &[u8]| {
            let mut c = kind.to_vec();
            c.extend_from_slice(&(data.len() as u32).to_le_bytes());
            c.extend_from_slice(data);
            if data.len() % 2 == 1 {
                c.push(0);
            }
            c
        };
        let mut body = b"WEBP".to_vec();
        body.extend(chunk(b"VP8X", &[0x08 | 0x04 | 0x10, 0, 0, 0, 0, 0, 0, 0, 0, 0]));
        body.extend(chunk(b"VP8 ", &[9, 9, 9]));
        body.extend(chunk(b"EXIF", b"GPS here"));
        body.extend(chunk(b"XMP ", b"<x/>"));
        let mut webp = b"RIFF".to_vec();
        webp.extend_from_slice(&(body.len() as u32).to_le_bytes());
        webp.extend(body);
        let stripped = strip_webp(&webp).unwrap();
        assert!(!stripped.windows(4).any(|w| w == b"EXIF" || w == b"XMP "));
        assert_eq!(stripped[20], 0x10, "only the alpha flag is left");
        let size = u32::from_le_bytes(stripped[4..8].try_into().unwrap()) as usize;
        assert_eq!(size, stripped.len() - 8, "the RIFF size matches");
        assert!(stripped.windows(4).any(|w| w == b"VP8 "));
    }

    #[test]
    fn a_pdf_goes_as_it_is() {
        let pdf = b"%PDF-1.4\n/Author (Me)\n%%EOF".to_vec();
        let r = prepare("statement", pdf.clone()).unwrap();
        assert_eq!((r.name.as_str(), r.mime, r.bytes), ("statement.pdf", "application/pdf", pdf));
    }

    #[test]
    fn names_are_cleaned() {
        assert_eq!(clean_name("/home/me/../bad\"name<>.jpeg", ReceiptKind::Jpeg), "badname.jpg");
        assert_eq!(clean_name("", ReceiptKind::Png), "Receipt.png");
        assert_eq!(clean_name(".png", ReceiptKind::Png), "Receipt.png");
        assert_eq!(clean_name(&"a".repeat(200), ReceiptKind::Pdf).chars().count(), 84);
    }

    #[test]
    fn the_transaction_id_and_payer_are_checked_like_the_server() {
        assert_eq!(reference_of(Some("  FT26313 ABC7781 ")).unwrap().as_deref(), Some("FT26313 ABC7781"));
        assert_eq!(reference_of(Some("")).unwrap(), None);
        assert_eq!(reference_of(None).unwrap(), None);
        assert_eq!(reference_of(Some("TID#12/3-4_5.6")).unwrap().as_deref(), Some("TID#12/3-4_5.6"));
        for bad in ["abc", "x;DROP TABLE", &"9".repeat(65)] {
            assert_eq!(reference_of(Some(bad)).unwrap_err().to_string(), REFERENCE_SHAPE, "{bad}");
        }
        assert_eq!(payer_of(Some(" Sara  Khan ")).unwrap().as_deref(), Some("Sara Khan"));
        assert_eq!(payer_of(Some(&"x".repeat(121))).unwrap_err().to_string(), PAYER_SHAPE);
        assert!(checked_id("01JF8ZK").is_ok());
        assert!(checked_id("../etc").is_err());
    }

    #[test]
    fn every_refusal_of_the_contract_has_calm_words() {
        let t = |status, body: serde_json::Value| refusal_text(status, &body, None);
        assert_eq!(t(413, json!({"code": "too_large"})), TOO_LARGE);
        assert_eq!(t(422, json!({"code": "validation_failed", "errors": {"file": ["The type"]}, "reason": "file_type"})), FILE_TYPE);
        assert_eq!(t(422, json!({"code": "validation_failed", "errors": {"file": ["x"]}, "reason": "pdf_password"})), PDF_PASSWORD);
        assert_eq!(t(422, json!({"code": "validation_failed", "errors": {"file": {"reason": "pdf_active"}}})), PDF_ACTIVE);
        assert_eq!(t(422, json!({"code": "validation_failed", "reason": "unreadable"})), UNREADABLE);
        assert_eq!(t(422, json!({"code": "validation_failed", "reason": "too_many_pixels"})), UNREADABLE);
        assert_eq!(t(422, json!({"code": "validation_failed", "errors": {"file": ["Bad"]}})), FILE_TYPE);
        assert_eq!(t(409, json!({"code": "too_many_proofs", "max": 3})), TOO_MANY_ROUND);
        assert_eq!(t(409, json!({"code": "too_many_proofs", "max": 10})), TOO_MANY_REQUEST);
        assert_eq!(t(409, json!({"code": "too_many_proofs", "scope": "round", "max": 3})), TOO_MANY_ROUND);
        assert_eq!(t(409, json!({"code": "too_many_proofs", "scope": "request"})), TOO_MANY_REQUEST);
        assert_eq!(t(409, json!({"code": "proof_locked"})), PROOF_LOCKED);
        assert_eq!(t(422, json!({"code": "proof_required"})), PROOF_REQUIRED);
        assert_eq!(t(409, json!({"code": "request_decided"})), REQUEST_DECIDED);
        assert_eq!(t(422, json!({"code": "plan_not_offered"})), PLAN_NOT_OFFERED);
        assert_eq!(t(422, json!({"code": "validation_failed", "errors": {"plan": ["x"]}})), PLAN_NOT_OFFERED);
        assert_eq!(t(403, json!({"code": "not_allowed"})), NOT_ALLOWED);
        assert_eq!(t(500, json!({})), SERVER_TROUBLE);
        assert_eq!(refusal_text(429, &json!({"code": "rate_limited"}), Some(3600)), "Too many tries today. Please try again in 1 hour.");
        assert_eq!(refusal_text(429, &json!({}), Some(600)), "Too many tries today. Please try again in 10 minutes.");
        assert_eq!(refusal_text(429, &json!({}), None), "Too many tries today. Please try again later.");
        for text in [TOO_LARGE, FILE_TYPE, PDF_PASSWORD, PDF_ACTIVE, UNREADABLE, TOO_MANY_ROUND, TOO_MANY_REQUEST, PROOF_LOCKED, PROOF_REQUIRED, REQUEST_DECIDED, PLAN_NOT_OFFERED] {
            assert!(!text.to_lowercase().contains("invalid"), "{text}");
        }
        assert_eq!(calm(AppError::Network("dns".into())).to_string(), NO_CONNECTION);
    }
}
