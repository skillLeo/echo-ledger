//! Device token storage in the OS keychain (Windows Credential Manager / macOS Keychain) via keyring 4.x.
//! The token is loaded into Rust state and never crosses IPC to the webview.

use std::sync::Mutex;

pub trait TokenStore: Send + Sync {
    fn load(&self) -> Result<Option<String>, String>;
    fn save(&self, token: &str) -> Result<(), String>;
    fn forget(&self) -> Result<(), String>;
}

const SERVICE: &str = "com.echoledger.desktop";
const USER: &str = "device-token";

pub struct KeyringStore;

impl TokenStore for KeyringStore {
    fn load(&self) -> Result<Option<String>, String> {
        let entry = keyring::Entry::new(SERVICE, USER).map_err(|e| e.to_string())?;
        match entry.get_password() {
            Ok(token) => Ok(Some(token)),
            Err(keyring::Error::NoEntry) => Ok(None),
            Err(e) => Err(e.to_string()),
        }
    }

    fn save(&self, token: &str) -> Result<(), String> {
        keyring::Entry::new(SERVICE, USER).and_then(|e| e.set_password(token)).map_err(|e| e.to_string())
    }

    fn forget(&self) -> Result<(), String> {
        match keyring::Entry::new(SERVICE, USER).and_then(|e| e.delete_credential()) {
            Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
            Err(e) => Err(e.to_string()),
        }
    }
}

/// In-memory store for tests.
#[derive(Default)]
pub struct MemoryStore(pub Mutex<Option<String>>);

impl TokenStore for MemoryStore {
    fn load(&self) -> Result<Option<String>, String> {
        Ok(self.0.lock().unwrap().clone())
    }

    fn save(&self, token: &str) -> Result<(), String> {
        *self.0.lock().unwrap() = Some(token.to_string());
        Ok(())
    }

    fn forget(&self) -> Result<(), String> {
        *self.0.lock().unwrap() = None;
        Ok(())
    }
}
