//! This computer at sign-in (v1.6, P3; docs/API.md "Sign-up and sign-in with an email code"): the install id every
//! sign-in sends, so the same computer signing in again keeps its row on the server, and a readable name instead of
//! the raw host name ("Windows 11 PC", "MacBook Pro", "iMac", "Mac").
//!
//! The install id is SHA-256 (hex) of the machine's own id (Windows: `MachineGuid`; macOS: `IOPlatformUUID`; Linux:
//! `/etc/machine-id`) and the app's data folder, so two installs on one computer (another user, another data folder)
//! are two devices and the same install gets the same id back even when its settings file is lost. It is made once
//! and kept in the settings. Without a machine id it is a random one, kept the same way. It says nothing about the
//! person: no host name, no user name, no address.

use std::path::Path;
use std::sync::OnceLock;

/// Bumped only if the recipe changes (it never should: every computer would become a new device).
const RECIPE: &str = "echoledger-install-id:v1";

/// The install id of `machine_id` and `data_dir` (pure): 64 lower-case hex characters.
pub fn install_id_of(machine_id: &str, data_dir: &Path) -> String {
    let text = format!("{RECIPE}\n{}\n{}", machine_id.trim().to_ascii_lowercase(), data_dir.to_string_lossy());
    hex(ring::digest::digest(&ring::digest::SHA256, text.as_bytes()).as_ref())
}

/// A random install id for a computer whose own id cannot be read (the same shape as a real one).
pub fn random_install_id() -> String {
    let mut bytes = *uuid::Uuid::new_v4().as_bytes();
    bytes[0] ^= 0x5a; // never mistaken for the uuid itself
    hex(ring::digest::digest(&ring::digest::SHA256, &bytes).as_ref())
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

/// A kept install id is used only when it has the shape this app writes.
pub fn is_install_id(value: &str) -> bool {
    value.len() == 64 && value.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}

/// The value of `"key" = "value"` or `"key" = <"value">` in `ioreg` output (pure).
pub fn ioreg_value(output: &str, key: &str) -> Option<String> {
    let needle = format!("\"{key}\" = ");
    let line = output.lines().find_map(|l| l.trim().strip_prefix(needle.as_str()))?;
    let value = line.trim().trim_start_matches('<').trim_end_matches('>').trim().trim_matches('"').trim();
    (!value.is_empty()).then(|| value.to_string())
}

/// What Windows calls itself in a device name: 11 from build 22000 on (pure).
pub fn windows_name(build: Option<u32>) -> String {
    match build {
        Some(b) if b >= 22_000 => "Windows 11 PC".into(),
        Some(b) if b >= 10_240 => "Windows 10 PC".into(),
        _ => "Windows PC".into(),
    }
}

/// The kind of Mac from its marketing name ("MacBook Pro (16-inch, 2021)" → "MacBook Pro"), else from its model
/// identifier ("MacBookPro18,1" → "MacBook Pro", "iMac21,1" → "iMac"), else "Mac" (pure). Newer identifiers
/// ("Mac14,2") name no kind; Apple Silicon Macs give their marketing name, which does.
pub fn mac_name(product_name: Option<&str>, model: Option<&str>) -> String {
    if let Some(name) = product_name.map(|n| n.split('(').next().unwrap_or(n).trim()).filter(|n| known_mac(n)) {
        return name.to_string();
    }
    let model = model.unwrap_or_default().trim();
    const KINDS: [(&str, &str); 8] = [
        ("MacBookPro", "MacBook Pro"),
        ("MacBookAir", "MacBook Air"),
        ("MacBook", "MacBook"),
        ("iMacPro", "iMac Pro"),
        ("iMac", "iMac"),
        ("Macmini", "Mac mini"),
        ("MacPro", "Mac Pro"),
        ("MacStudio", "Mac Studio"),
    ];
    KINDS
        .iter()
        .find(|(prefix, _)| model.strip_prefix(prefix).is_some_and(|rest| rest.starts_with(|c: char| c.is_ascii_digit())))
        .map_or_else(|| "Mac".to_string(), |(_, name)| name.to_string())
}

/// A marketing name that is one of Apple's Mac families (anything else, an odd value, is not used).
fn known_mac(name: &str) -> bool {
    ["MacBook Pro", "MacBook Air", "MacBook", "iMac Pro", "iMac", "Mac mini", "Mac Pro", "Mac Studio", "Mac"].contains(&name)
}

/// The name a new sign-in gives this computer when the person typed none.
pub fn device_name() -> String {
    static NAME: OnceLock<String> = OnceLock::new();
    NAME.get_or_init(native::device_name).clone()
}

/// This machine's own id, when the system gives one.
pub fn machine_id() -> Option<String> {
    native::machine_id().map(|id| id.trim().to_string()).filter(|id| id.len() >= 8 && !id.chars().all(|c| c == '0' || c == '-'))
}

#[cfg(target_os = "macos")]
mod native {
    use std::process::{Command, Stdio};

    fn ioreg(args: &[&str]) -> Option<String> {
        let out = Command::new("/usr/sbin/ioreg").args(args).stdin(Stdio::null()).stderr(Stdio::null()).output().ok()?;
        out.status.success().then(|| String::from_utf8_lossy(&out.stdout).into_owned())
    }

    pub fn machine_id() -> Option<String> {
        super::ioreg_value(&ioreg(&["-rd1", "-c", "IOPlatformExpertDevice"])?, "IOPlatformUUID")
    }

    pub fn device_name() -> String {
        let product = ioreg(&["-p", "IODeviceTree", "-n", "product", "-r", "-d1"]).and_then(|o| super::ioreg_value(&o, "product-name"));
        let model = ioreg(&["-rd1", "-c", "IOPlatformExpertDevice"]).and_then(|o| super::ioreg_value(&o, "model"));
        super::mac_name(product.as_deref(), model.as_deref())
    }
}

#[cfg(windows)]
mod native {
    /// `HKLM\SOFTWARE\Microsoft\Cryptography\MachineGuid`, from the 64-bit view of the registry.
    pub fn machine_id() -> Option<String> {
        use windows::Win32::System::Registry::{HKEY_LOCAL_MACHINE, RRF_RT_REG_SZ, RRF_SUBKEY_WOW6464KEY, RegGetValueW};
        use windows::core::{HSTRING, PCWSTR};
        let mut buf = [0u16; 128];
        let mut size = (buf.len() * 2) as u32;
        let key = HSTRING::from(r"SOFTWARE\Microsoft\Cryptography");
        let value = HSTRING::from("MachineGuid");
        let status = unsafe {
            RegGetValueW(HKEY_LOCAL_MACHINE, &key, PCWSTR(value.as_ptr()), RRF_RT_REG_SZ | RRF_SUBKEY_WOW6464KEY, None, Some(buf.as_mut_ptr().cast()), Some(&mut size))
        };
        status.is_ok().then(|| String::from_utf16_lossy(&buf[..(size as usize / 2).saturating_sub(1)]))
    }

    pub fn device_name() -> String {
        super::windows_name(crate::audio::platform::windows::os_build())
    }
}

#[cfg(not(any(windows, target_os = "macos")))]
mod native {
    pub fn machine_id() -> Option<String> {
        std::fs::read_to_string("/etc/machine-id").ok()
    }

    pub fn device_name() -> String {
        "Linux computer".into()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::path::PathBuf;

    #[test]
    fn the_install_id_is_a_stable_hash_of_the_machine_and_the_data_folder() {
        let dir = PathBuf::from("/Users/sam/Library/Application Support/com.echoledger.desktop");
        let id = install_id_of("E86C0B1A-F012-52C2-9FA1-6C17BE312754", &dir);
        assert!(is_install_id(&id), "{id}");
        assert_eq!(id, install_id_of(" e86c0b1a-f012-52c2-9fa1-6c17be312754 ", &dir), "the same machine, however it is written");
        assert_ne!(id, install_id_of("E86C0B1A-F012-52C2-9FA1-6C17BE312754", &PathBuf::from("/Users/kim/Library/Application Support/com.echoledger.desktop")), "another user");
        assert_ne!(id, install_id_of("0F1E2D3C-4B5A-6978-8796-A5B4C3D2E1F0", &dir), "another machine");
        assert!(!id.contains("E86C0B1A") && !id.to_lowercase().contains("e86c0b1a"), "the machine id itself is never sent");
        let random = random_install_id();
        assert!(is_install_id(&random));
        assert_ne!(random, random_install_id());
        for bad in ["", "abc", &"g".repeat(64), &"A".repeat(64)] {
            assert!(!is_install_id(bad), "{bad}");
        }
    }

    #[test]
    fn ioreg_values_are_read_with_or_without_brackets() {
        let platform = r#"+-o J316sAP  <class IOPlatformExpertDevice>
    {
      "model" = <"MacBookPro18,1">
      "IOPlatformUUID" = "E86C0B1A-F012-52C2-9FA1-6C17BE312754"
    }"#;
        assert_eq!(ioreg_value(platform, "IOPlatformUUID").as_deref(), Some("E86C0B1A-F012-52C2-9FA1-6C17BE312754"));
        assert_eq!(ioreg_value(platform, "model").as_deref(), Some("MacBookPro18,1"));
        assert_eq!(ioreg_value(platform, "product-name"), None);
        let product = r#"      "product-name" = <"MacBook Pro (16-inch, 2021)">"#;
        assert_eq!(ioreg_value(product, "product-name").as_deref(), Some("MacBook Pro (16-inch, 2021)"));
    }

    #[test]
    fn computers_get_readable_names() {
        assert_eq!(windows_name(Some(26_100)), "Windows 11 PC");
        assert_eq!(windows_name(Some(22_000)), "Windows 11 PC");
        assert_eq!(windows_name(Some(19_045)), "Windows 10 PC");
        assert_eq!(windows_name(Some(20_348)), "Windows 10 PC", "Server 2022 is of the Windows 10 family");
        assert_eq!(windows_name(None), "Windows PC");

        assert_eq!(mac_name(Some("MacBook Pro (16-inch, 2021)"), Some("MacBookPro18,1")), "MacBook Pro");
        assert_eq!(mac_name(Some("MacBook Air (15-inch, M3, 2024)"), Some("Mac15,13")), "MacBook Air");
        assert_eq!(mac_name(Some("iMac (24-inch, 2024)"), Some("Mac16,3")), "iMac");
        assert_eq!(mac_name(Some("Mac mini (2024)"), None), "Mac mini");
        assert_eq!(mac_name(None, Some("MacBookPro16,1")), "MacBook Pro", "an Intel Mac has no marketing name");
        assert_eq!(mac_name(None, Some("MacBookAir10,1")), "MacBook Air");
        assert_eq!(mac_name(None, Some("iMac20,1")), "iMac");
        assert_eq!(mac_name(None, Some("iMacPro1,1")), "iMac Pro");
        assert_eq!(mac_name(None, Some("Macmini9,1")), "Mac mini");
        assert_eq!(mac_name(None, Some("MacPro7,1")), "Mac Pro");
        assert_eq!(mac_name(None, Some("Mac14,2")), "Mac", "a newer identifier names no kind");
        assert_eq!(mac_name(Some("Sam's computer"), Some("Mac14,2")), "Mac", "only Apple's family names are used");
        assert_eq!(mac_name(None, None), "Mac");
    }

    #[test]
    fn this_computer_has_a_name_and_no_host_name_in_it() {
        let name = device_name();
        assert!(!name.is_empty());
        for host in [std::env::var("COMPUTERNAME").ok(), std::env::var("HOSTNAME").ok()].into_iter().flatten().filter(|h| h.len() > 3) {
            assert!(!name.contains(&host), "{name} names the host {host}");
        }
    }
}
