//! Send to WhatsApp (v1.6, P5.5) on the computer: `wa.me` opens WhatsApp (the app, else WhatsApp Web) with the text
//! ready to send. The text travels in the address, and Windows and the WhatsApp app take only so long an address, so a
//! long transcript is cut at a word with "…" (the window puts the whole text on the clipboard and says so). Only this
//! module builds the address: the window hands over text, never a URL.

/// The longest address handed to the system: under Windows' 2,048-character limit for URLs passed to a protocol
/// handler, with room for the handler's own redirect.
pub const MAX_URL_CHARS: usize = 1900;
const BASE: &str = "https://wa.me/?text=";
const ELLIPSIS: &str = "…";

/// `text` percent-encoded for a query value: letters, digits and `-_.~` stay, everything else (spaces and line breaks
/// included, and every byte of Urdu and Arabic letters) becomes `%XX`.
pub fn encode(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    for byte in text.bytes() {
        if byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_' | b'.' | b'~') {
            out.push(byte as char);
        } else {
            out.push_str(&format!("%{byte:02X}"));
        }
    }
    out
}

/// The `wa.me` address for `text` (trimmed), and whether the text had to be cut to fit. A cut ends at the last space or
/// line break that fits (never inside a word or a letter), followed by "…".
pub fn whatsapp_url(text: &str) -> (String, bool) {
    let text = text.trim();
    let whole = format!("{BASE}{}", encode(text));
    if whole.len() <= MAX_URL_CHARS {
        return (whole, false);
    }
    let room = MAX_URL_CHARS - BASE.len() - encode(ELLIPSIS).len();
    let mut end = 0;
    let mut used = 0;
    for (at, ch) in text.char_indices() {
        let size = encode(ch.encode_utf8(&mut [0; 4])).len();
        if used + size > room {
            break;
        }
        used += size;
        end = at + ch.len_utf8();
    }
    let head = &text[..end];
    // Back to the last break between words, when there is one in the second half of what fits.
    let cut = head.rfind(char::is_whitespace).filter(|&at| at > head.len() / 2).map_or(head, |at| &head[..at]);
    (format!("{BASE}{}{}", encode(cut.trim_end()), encode(ELLIPSIS)), true)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn encodes_spaces_line_breaks_and_urdu() {
        assert_eq!(encode("Kick-off notes_1.~"), "Kick-off%20notes_1.~");
        assert_eq!(encode("a\nb&c=d?e#f+g"), "a%0Ab%26c%3Dd%3Fe%23f%2Bg");
        assert_eq!(encode("سلام"), "%D8%B3%D9%84%D8%A7%D9%85");
    }

    #[test]
    fn a_short_text_goes_whole() {
        let (url, cut) = whatsapp_url("  Me: hello\nClient: hi  ");
        assert_eq!(url, "https://wa.me/?text=Me%3A%20hello%0AClient%3A%20hi");
        assert!(!cut);
    }

    #[test]
    fn a_long_text_is_cut_at_a_word_and_stays_under_the_limit() {
        let text = "word ".repeat(1000);
        let (url, cut) = whatsapp_url(&text);
        assert!(cut);
        assert!(url.len() <= MAX_URL_CHARS, "{}", url.len());
        assert!(url.ends_with("word%E2%80%A6"), "ends at a whole word, then …: {}", &url[url.len() - 30..]);
    }

    #[test]
    fn a_long_urdu_text_is_never_cut_inside_a_letter() {
        let text = "یہ ایک لمبی بات ہے ".repeat(200);
        let (url, cut) = whatsapp_url(&text);
        assert!(cut);
        assert!(url.len() <= MAX_URL_CHARS);
        // Every %XX group decodes back to valid UTF-8.
        let bytes: Vec<u8> = url[BASE.len()..].split('%').filter(|p| !p.is_empty()).map(|p| u8::from_str_radix(&p[..2], 16).unwrap()).collect();
        assert!(String::from_utf8(bytes).is_ok());
    }

    #[test]
    fn one_long_word_is_cut_where_it_must() {
        let (url, cut) = whatsapp_url(&"x".repeat(5000));
        assert!(cut);
        assert!(url.len() <= MAX_URL_CHARS);
        assert!(url.starts_with("https://wa.me/?text=xxx"));
    }
}
