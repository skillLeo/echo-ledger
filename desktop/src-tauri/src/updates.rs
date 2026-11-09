//! Updates (v1.5, docs/API.md "Public pages and app downloads"): Tauri's updater reads the signed manifest at
//! `<server>/downloads/desktop.json` of the server this computer is signed in to (the endpoint is set at run time; the
//! minisign public key is in tauri.conf.json, the private key never in the repo). Its requests go through the server's
//! pins, the package must come from the same server, and its signature must name the version the manifest announces.
//!
//! A quiet check runs soon after EchoLedger starts and then every 6 hours (v1.6; and on "Check for updates"). A new
//! version is downloaded, verified and kept in the data folder; "Update ready — Restart" asks the person to restart,
//! and it installs the next time EchoLedger starts either way. It is never installed while a recording runs.
//!
//! The manifest is Tauri's static format (docs/API.md "Public pages and app downloads"): `version`, `notes`,
//! `pub_date` and `platforms` keyed `windows-x86_64` (the NSIS installer), `darwin-aarch64` and `darwin-x86_64` (the
//! `.app.tar.gz`), each with its `url` and `signature`. `offer_for` reads it with the updater's own type.

use std::path::{Path, PathBuf};
use std::sync::Mutex;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::Duration;

use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};

/// How often the quiet check runs (v1.6: every few hours, so an installed app is never long behind).
pub const CHECK_EVERY: Duration = Duration::from_secs(6 * 60 * 60);
/// A failed quiet check is tried again after this long.
pub const RETRY_AFTER: Duration = Duration::from_secs(60 * 60);
/// The first quiet check, on start, waits for the start-up to settle.
pub const FIRST_CHECK_AFTER: Duration = Duration::from_secs(45);
/// The manifest's path under the server.
pub const MANIFEST: &str = "downloads/desktop.json";

/// `<server>/downloads/desktop.json` for the signed-in server, none when there is none or it is not https (the
/// updater refuses plain http, and the manifest decides what is installed).
pub fn endpoint(server_url: Option<&str>) -> Option<reqwest::Url> {
    let server = server_url?.trim().trim_end_matches('/');
    let url = reqwest::Url::parse(&format!("{server}/{MANIFEST}")).ok()?;
    let plain = url.username().is_empty() && url.password().is_none() && url.query().is_none() && url.fragment().is_none();
    (url.scheme() == "https" && url.host_str().is_some() && plain).then_some(url)
}

/// The package must come from the server that sent the manifest (same scheme, host and port).
pub fn same_origin(endpoint: &reqwest::Url, download: &reqwest::Url) -> bool {
    download.scheme() == "https"
        && download.username().is_empty()
        && download.password().is_none()
        && download.host_str() == endpoint.host_str()
        && download.port_or_known_default() == endpoint.port_or_known_default()
}

/// The manifest key this build's package is listed under: the installed app's OS and chip.
pub fn this_target() -> &'static str {
    if cfg!(windows) {
        if cfg!(target_arch = "aarch64") { "windows-aarch64" } else { "windows-x86_64" }
    } else if cfg!(target_os = "macos") {
        if cfg!(target_arch = "aarch64") { "darwin-aarch64" } else { "darwin-x86_64" }
    } else {
        "linux-x86_64"
    }
}

/// What a manifest from `endpoint` offers the app at `current` on `target`, read with the updater's own type (pure):
/// the newer version and its package, none when it is not newer, or why it cannot be used (not the static format,
/// no package for this computer, no signature, a package on another server).
pub fn offer_for(manifest: &serde_json::Value, target: &str, current: &str, endpoint: &reqwest::Url) -> Result<Option<(String, reqwest::Url)>, String> {
    use tauri_plugin_updater::RemoteRelease;
    let release: RemoteRelease = serde_json::from_value(manifest.clone()).map_err(|e| format!("not an update manifest: {e}"))?;
    let installed: RemoteRelease = serde_json::from_value(serde_json::json!({ "version": current, "platforms": {} })).map_err(|e| format!("not a version: {e}"))?;
    if release.version <= installed.version {
        return Ok(None);
    }
    let url = release.download_url(target).map_err(|_| format!("no package for {target}"))?;
    let signature = release.signature(target).map_err(|_| format!("no signature for {target}"))?;
    if signature.trim().is_empty() {
        return Err(format!("an empty signature for {target}"));
    }
    if !same_origin(endpoint, url) {
        return Err("the package is not on the server that sent the manifest".into());
    }
    Ok(Some((release.version.to_string(), url.clone())))
}

/// Whether the quiet check is due: never checked, or the last check (a failed one counts `RETRY_AFTER` before it) is
/// `CHECK_EVERY` old.
pub fn due(last: Option<DateTime<Utc>>, now: DateTime<Utc>) -> bool {
    last.is_none_or(|at| now.signed_duration_since(at).to_std().is_ok_and(|age| age >= CHECK_EVERY) || at > now)
}

/// Where updates stand, for Settings → About and the "ready" prompt.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum UpdatePhase {
    #[default]
    Idle,
    Checking,
    UpToDate,
    Downloading,
    Ready,
    Failed,
    /// Not signed in to an https server, or not the installed app: nothing to check.
    Unavailable,
}

#[derive(Clone, Debug, Default, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct UpdateStatus {
    pub phase: UpdatePhase,
    /// This app's version.
    pub current: String,
    /// The new version (downloading or ready).
    pub version: Option<String>,
    pub notes: Option<String>,
    /// The last check that reached the server (RFC 3339).
    pub checked_at: Option<String>,
    /// Plain words for `failed`, `unavailable` and `up_to_date` (none published yet, say).
    pub message: Option<String>,
    /// Download progress, 0 to 100, when the size is known.
    pub progress: Option<u8>,
}

/// What the plugin's error means for the person.
pub fn describe(error: &tauri_plugin_updater::Error) -> (UpdatePhase, String) {
    use tauri_plugin_updater::Error as E;
    match error {
        E::ReleaseNotFound => (UpdatePhase::UpToDate, "No update is published on your server yet.".into()),
        E::TargetNotFound(_) | E::TargetsNotFound(_) => (UpdatePhase::UpToDate, "Your server has an update, but not for this computer yet.".into()),
        E::Minisign(_) | E::SignedVersionMismatch { .. } | E::MissingSignedVersion => {
            (UpdatePhase::Failed, "The update's signature did not match EchoLedger's, so it was not installed.".into())
        }
        E::FailedToDetermineExtractPath | E::UnsupportedOs | E::UnsupportedArch => (UpdatePhase::Unavailable, "Updates work in the installed app only.".into()),
        E::InsecureTransportProtocol => (UpdatePhase::Unavailable, NEEDS_HTTPS.into()),
        E::Reqwest(_) | E::Network(_) => (UpdatePhase::Failed, "Your server could not be reached to check for updates. EchoLedger tries again later.".into()),
        E::AuthenticationFailed => (UpdatePhase::Failed, "macOS did not allow EchoLedger to replace itself. Install the update from the downloads page instead.".into()),
        E::TempDirNotOnSameMountPoint => (UpdatePhase::Failed, "EchoLedger cannot replace itself where it is. Move it to the Applications folder, then try again.".into()),
        other => (UpdatePhase::Failed, format!("The update failed: {other}")),
    }
}

pub const NEEDS_HTTPS: &str = "Updates come from your server: sign in to a server whose address starts with https://.";

/// A downloaded, verified update kept for the next start: `update.json` (the version and the manifest it came with)
/// beside `update.bin` (the package; its signature is checked again before it is installed).
#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct SavedUpdate {
    pub version: String,
    pub manifest: serde_json::Value,
    /// The start that tried to install it (so a failing install is never tried at every start).
    #[serde(default)]
    pub attempted: bool,
}

pub fn dir(data_dir: &Path) -> PathBuf {
    data_dir.join("updates")
}

fn meta_path(data_dir: &Path) -> PathBuf {
    dir(data_dir).join("update.json")
}

pub fn package_path(data_dir: &Path) -> PathBuf {
    dir(data_dir).join("update.bin")
}

fn checked_path(data_dir: &Path) -> PathBuf {
    dir(data_dir).join("checked.json")
}

impl SavedUpdate {
    pub fn load(data_dir: &Path) -> Option<Self> {
        let text = std::fs::read_to_string(meta_path(data_dir)).ok()?;
        serde_json::from_str(&text).ok().filter(|_| package_path(data_dir).is_file())
    }

    /// Writes the package, then its description (so a description never names a package that is not all there).
    pub fn save(&self, data_dir: &Path, package: &[u8]) -> std::io::Result<()> {
        std::fs::create_dir_all(dir(data_dir))?;
        let part = package_path(data_dir).with_extension("part");
        std::fs::write(&part, package)?;
        std::fs::rename(&part, package_path(data_dir))?;
        self.write_meta(data_dir)
    }

    pub fn mark_attempted(&mut self, data_dir: &Path) -> std::io::Result<()> {
        self.attempted = true;
        self.write_meta(data_dir)
    }

    fn write_meta(&self, data_dir: &Path) -> std::io::Result<()> {
        std::fs::write(meta_path(data_dir), serde_json::to_vec(self).map_err(std::io::Error::other)?)
    }

    pub fn remove(data_dir: &Path) {
        let _ = std::fs::remove_file(meta_path(data_dir));
        let _ = std::fs::remove_file(package_path(data_dir));
    }
}

/// What a start does with a kept update: install it once, or drop it (already tried, or not newer any more, which
/// the updater itself decides when it restores the manifest).
pub fn install_at_start(saved: Option<&SavedUpdate>) -> bool {
    saved.is_some_and(|s| !s.attempted)
}

/// When the quiet check last reached the server (or failed, counted `RETRY_AFTER` early).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct Checked {
    pub at: Option<DateTime<Utc>>,
}

impl Checked {
    pub fn load(data_dir: &Path) -> Self {
        std::fs::read_to_string(checked_path(data_dir)).ok().and_then(|t| serde_json::from_str(&t).ok()).unwrap_or_default()
    }

    pub fn save(&self, data_dir: &Path) {
        if std::fs::create_dir_all(dir(data_dir)).is_ok() {
            let _ = std::fs::write(checked_path(data_dir), serde_json::to_vec(self).unwrap_or_default());
        }
    }

    /// The time to store after a check: now, or for a failure a time that makes the next check due after `RETRY_AFTER`.
    pub fn after(now: DateTime<Utc>, failed: bool) -> Self {
        let back = if failed { chrono::Duration::from_std(CHECK_EVERY - RETRY_AFTER).unwrap_or_default() } else { chrono::Duration::zero() };
        Self { at: Some(now - back) }
    }
}

/// The updater's state in the app: the status the windows show, one job at a time, the update ready to install.
#[derive(Default)]
pub struct Updates {
    status: Mutex<UpdateStatus>,
    busy: AtomicBool,
    installing: AtomicBool,
    ready: Mutex<Option<tauri_plugin_updater::Update>>,
}

impl Updates {
    pub fn status(&self) -> UpdateStatus {
        let mut status = self.status.lock().unwrap_or_else(|p| p.into_inner()).clone();
        status.current = env!("CARGO_PKG_VERSION").to_string();
        status
    }

    pub fn set(&self, f: impl FnOnce(&mut UpdateStatus)) -> UpdateStatus {
        let mut status = self.status.lock().unwrap_or_else(|p| p.into_inner());
        f(&mut status);
        status.current = env!("CARGO_PKG_VERSION").to_string();
        status.clone()
    }

    /// Claims the one check or install that may run; false when one already runs.
    pub fn begin(&self) -> bool {
        !self.busy.swap(true, Ordering::SeqCst)
    }

    pub fn end(&self) {
        self.busy.store(false, Ordering::SeqCst);
    }

    /// An update is being installed: recordings do not start meanwhile.
    pub fn is_installing(&self) -> bool {
        self.installing.load(Ordering::SeqCst)
    }

    pub fn set_installing(&self, on: bool) {
        self.installing.store(on, Ordering::SeqCst);
    }

    pub fn keep_ready(&self, update: Option<tauri_plugin_updater::Update>) {
        *self.ready.lock().unwrap_or_else(|p| p.into_inner()) = update;
    }

    pub fn ready(&self) -> Option<tauri_plugin_updater::Update> {
        self.ready.lock().unwrap_or_else(|p| p.into_inner()).clone()
    }
}

pub const INSTALLING: &str = "EchoLedger is installing an update and restarts in a moment.";
const NOT_SIGNED_IN: &str = "Sign in to your server to get updates.";

// ---- The updater in the running app ------------------------------------------------------------------------------

use tauri::{AppHandle, Emitter, Manager};
use tauri_plugin_updater::UpdaterExt;

fn emit(app: &AppHandle, status: &UpdateStatus) {
    let _ = app.emit("update:status", status);
}

/// The updater for `endpoint`, its requests (manifest and package) pinned like every request to the server.
fn updater(app: &AppHandle, endpoint: reqwest::Url, pins: &[String]) -> Result<tauri_plugin_updater::Updater, (UpdatePhase, String)> {
    let tls = crate::net::pinning::server_tls_config(pins).map_err(|e| (UpdatePhase::Failed, e))?;
    let exiting = app.clone();
    app.updater_builder()
        .endpoints(vec![endpoint])
        .map_err(|e| describe(&e))?
        // The package is downloaded within this time too.
        .timeout(Duration::from_secs(15 * 60))
        .configure_client(move |b| b.https_only(true).tls_backend_preconfigured(tls.clone()))
        // Windows ends this process as soon as the installer runs: the tray icon and the windows go first.
        .on_before_exit(move || exiting.cleanup_before_exit())
        .build()
        .map_err(|e| describe(&e))
}

/// Checks the signed-in server and, when it has a newer version, downloads, verifies and keeps it. `quiet` is the
/// 12-hour check: an OS notification says when an update is ready.
pub async fn check(app: AppHandle, quiet: bool) {
    let state = app.state::<crate::AppState>();
    if !state.updates.begin() {
        return;
    }
    let status = run_check(&app, quiet).await;
    state.updates.end();
    emit(&app, &status);
}

async fn run_check(app: &AppHandle, quiet: bool) -> UpdateStatus {
    let state = app.state::<crate::AppState>();
    let updates = &state.updates;
    if updates.status().phase == UpdatePhase::Ready && updates.ready().is_some() {
        return updates.status();
    }
    let settings = state.settings();
    let Some(endpoint) = endpoint(settings.server_url.as_deref()) else {
        let message = if state.is_paired() { NEEDS_HTTPS } else { NOT_SIGNED_IN };
        return updates.set(|s| {
            s.phase = UpdatePhase::Unavailable;
            s.message = Some(message.into());
        });
    };
    emit(app, &updates.set(|s| {
        s.phase = UpdatePhase::Checking;
        s.message = None;
        s.progress = None;
    }));
    let now = Utc::now();
    let found = match updater(app, endpoint.clone(), &settings.pins) {
        Ok(updater) => updater.check().await.map_err(|e| describe(&e)),
        Err(e) => Err(e),
    };
    let update = match found {
        Ok(Some(update)) => update,
        Ok(None) => {
            Checked::after(now, false).save(&state.data_dir);
            return updates.set(|s| {
                s.phase = UpdatePhase::UpToDate;
                s.version = None;
                s.message = Some("You have the latest version.".into());
                s.checked_at = Some(now.to_rfc3339());
            });
        }
        Err((phase, message)) => {
            Checked::after(now, phase == UpdatePhase::Failed).save(&state.data_dir);
            return updates.set(|s| {
                s.phase = phase;
                s.message = Some(message);
                if phase != UpdatePhase::Failed {
                    s.checked_at = Some(now.to_rfc3339());
                }
            });
        }
    };
    Checked::after(now, false).save(&state.data_dir);
    if !same_origin(&endpoint, &update.download_url) {
        return updates.set(|s| {
            s.phase = UpdatePhase::Failed;
            s.message = Some("The update's download is not on your server, so it was not downloaded.".into());
            s.checked_at = Some(now.to_rfc3339());
        });
    }
    emit(app, &updates.set(|s| {
        s.phase = UpdatePhase::Downloading;
        s.version = Some(update.version.clone());
        s.notes = update.body.clone().filter(|n| !n.trim().is_empty());
        s.checked_at = Some(now.to_rfc3339());
        s.progress = Some(0);
    }));
    let (mut received, mut shown) = (0u64, 0u8);
    let progress_app = app.clone();
    let downloaded = update
        .download(
            move |chunk, total| {
                received += chunk as u64;
                let Some(total) = total.filter(|t| *t > 0) else { return };
                let percent = (received.saturating_mul(100) / total).min(100) as u8;
                if percent >= shown.saturating_add(5) {
                    shown = percent;
                    let status = progress_app.state::<crate::AppState>().updates.set(|s| s.progress = Some(percent));
                    emit(&progress_app, &status);
                }
            },
            || {},
        )
        .await;
    let bytes = match downloaded {
        Ok(bytes) => bytes,
        Err(e) => {
            let (phase, message) = describe(&e);
            return updates.set(|s| {
                s.phase = if phase == UpdatePhase::UpToDate { UpdatePhase::Failed } else { phase };
                s.message = Some(message);
                s.progress = None;
            });
        }
    };
    let saved = SavedUpdate { version: update.version.clone(), manifest: update.raw_json.clone(), attempted: false };
    if let Err(e) = saved.save(&state.data_dir, &bytes) {
        return updates.set(|s| {
            s.phase = UpdatePhase::Failed;
            s.message = Some(format!("The update could not be kept on this computer: {e}"));
        });
    }
    updates.keep_ready(Some(update.clone()));
    let status = updates.set(|s| {
        s.phase = UpdatePhase::Ready;
        s.message = None;
        s.progress = None;
    });
    if quiet {
        crate::notifications::send(
            app,
            crate::notifications::Category::AppUpdates,
            &format!("EchoLedger {} is ready", update.version),
            "Restart EchoLedger to install it, or it installs the next time EchoLedger starts.",
        );
    }
    status
}

/// "Restart": installs the update that is ready and starts the new version. Never while a recording runs.
pub async fn install_now(app: &AppHandle) -> crate::error::AppResult<()> {
    use crate::error::AppError;
    let state = app.state::<crate::AppState>();
    if state.capture.is_recording() {
        return Err(AppError::msg("Stop the recording first: EchoLedger restarts to install the update."));
    }
    let update = state.updates.ready().ok_or_else(|| AppError::msg("No update is ready yet. Check for updates first."))?;
    if !state.updates.begin() {
        return Err(AppError::msg("EchoLedger is already checking for or installing an update."));
    }
    let data_dir = state.data_dir.clone();
    let bytes = match std::fs::read(package_path(&data_dir)) {
        Ok(bytes) => bytes,
        Err(e) => {
            state.updates.end();
            return Err(AppError::msg(format!("The downloaded update is missing ({e}). Check for updates again.")));
        }
    };
    if let Some(mut saved) = SavedUpdate::load(&data_dir) {
        let _ = saved.mark_attempted(&data_dir);
    }
    state.updates.set_installing(true);
    // Windows: the installer takes over and this process ends inside `install`; it starts EchoLedger again.
    let installed = tauri::async_runtime::spawn_blocking(move || update.install(&bytes)).await;
    match installed {
        Ok(Ok(())) => {
            SavedUpdate::remove(&data_dir);
            app.restart()
        }
        Ok(Err(e)) => {
            state.updates.set_installing(false);
            state.updates.end();
            SavedUpdate::remove(&data_dir);
            state.updates.keep_ready(None);
            let (_, message) = describe(&e);
            emit(app, &state.updates.set(|s| {
                s.phase = UpdatePhase::Failed;
                s.message = Some(message.clone());
            }));
            Err(AppError::Message(message))
        }
        Err(e) => {
            state.updates.set_installing(false);
            state.updates.end();
            Err(AppError::msg(e.to_string()))
        }
    }
}

/// At start: an update kept by an earlier run is installed now, once, before anything records; the new version then
/// starts in its place. One that is not newer any more (installed already) or was tried before is dropped.
pub fn install_kept(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let data_dir = state.data_dir.clone();
    let saved = SavedUpdate::load(&data_dir);
    if !install_at_start(saved.as_ref()) {
        SavedUpdate::remove(&data_dir);
        return;
    }
    let Some(mut saved) = saved else { return };
    let _ = saved.mark_attempted(&data_dir);
    let settings = state.settings();
    // The kept manifest decides what is installed; the updater only needs the endpoint to be built.
    let Some(endpoint) = endpoint(settings.server_url.as_deref()) else {
        SavedUpdate::remove(&data_dir);
        return;
    };
    let update = match updater(app, endpoint, &settings.pins).map_err(|(_, m)| m).and_then(|u| u.restore_update(saved.manifest.clone()).map_err(|e| describe(&e).1)) {
        Ok(Some(update)) => update,
        Ok(None) => {
            SavedUpdate::remove(&data_dir);
            return;
        }
        Err(message) => {
            eprintln!("[echoledger] the kept update was not installed: {message}");
            SavedUpdate::remove(&data_dir);
            return;
        }
    };
    let Ok(bytes) = std::fs::read(package_path(&data_dir)) else {
        SavedUpdate::remove(&data_dir);
        return;
    };
    state.updates.set_installing(true);
    let app = app.clone();
    tauri::async_runtime::spawn_blocking(move || match update.install(&bytes) {
        Ok(()) => {
            SavedUpdate::remove(&data_dir);
            app.restart()
        }
        Err(e) => {
            let (_, message) = describe(&e);
            eprintln!("[echoledger] the kept update was not installed: {message}");
            SavedUpdate::remove(&data_dir);
            let state = app.state::<crate::AppState>();
            state.updates.set_installing(false);
            emit(&app, &state.updates.set(|s| {
                s.phase = UpdatePhase::Failed;
                s.message = Some(message);
            }));
        }
    });
}

/// The quiet check: on start (once it settled), then whenever 6 hours passed since the last one (looked at every half
/// hour, so a computer that slept checks soon after it wakes).
pub fn spawn_quiet_checks(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(FIRST_CHECK_AFTER).await;
        loop {
            let (paired, last) = {
                let state = app.state::<crate::AppState>();
                (state.is_paired(), Checked::load(&state.data_dir).at)
            };
            if paired && due(last, Utc::now()) {
                check(app.clone(), true).await;
            }
            tokio::time::sleep(Duration::from_secs(30 * 60)).await;
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    fn url(text: &str) -> reqwest::Url {
        reqwest::Url::parse(text).unwrap()
    }

    #[test]
    fn the_manifest_is_read_from_the_signed_in_server() {
        assert_eq!(endpoint(Some("https://ledger.example.test")).unwrap().as_str(), "https://ledger.example.test/downloads/desktop.json");
        assert_eq!(endpoint(Some(" https://ledger.example.test/ ")).unwrap().as_str(), "https://ledger.example.test/downloads/desktop.json");
        // A server under a path keeps it.
        assert_eq!(endpoint(Some("https://example.test/echoledger")).unwrap().as_str(), "https://example.test/echoledger/downloads/desktop.json");
        assert_eq!(endpoint(Some("https://ledger.example.test:8443")).unwrap().as_str(), "https://ledger.example.test:8443/downloads/desktop.json");
        // Not signed in, plain http (local development included), or an address with more in it: nothing to check.
        for none in [None, Some(""), Some("http://ledger.example.test"), Some("http://localhost:8000"), Some("https://user:pw@ledger.example.test"), Some("https://ledger.example.test/?x=1"), Some("not a url")] {
            assert_eq!(endpoint(none), None, "{none:?}");
        }
    }

    #[test]
    fn the_package_must_come_from_the_same_server() {
        let manifest = url("https://ledger.example.test/downloads/desktop.json");
        assert!(same_origin(&manifest, &url("https://ledger.example.test/downloads/files/EchoLedger-1.5.1-setup.exe")));
        assert!(same_origin(&manifest, &url("https://ledger.example.test:443/downloads/files/x.tar.gz")));
        for other in [
            "https://cdn.example.test/downloads/files/x.exe",
            "http://ledger.example.test/downloads/files/x.exe",
            "https://ledger.example.test:8443/downloads/files/x.exe",
            "https://ledger.example.test.evil.test/x.exe",
            "https://me@ledger.example.test/x.exe",
        ] {
            assert!(!same_origin(&manifest, &url(other)), "{other}");
        }
    }

    #[test]
    fn the_quiet_check_runs_on_start_every_6_hours_and_soon_after_a_failure() {
        let now = Utc::now();
        assert!(FIRST_CHECK_AFTER <= Duration::from_secs(60), "on start, once it settled");
        assert!(due(None, now), "never checked");
        assert!(!due(Some(now - chrono::Duration::hours(5)), now));
        assert!(due(Some(now - chrono::Duration::hours(6)), now));
        assert!(due(Some(now + chrono::Duration::hours(1)), now), "a clock that went back");
        // A failure is stored so that the next check is due an hour later.
        let failed = Checked::after(now, true).at.unwrap();
        assert!(!due(Some(failed), now + chrono::Duration::minutes(59)));
        assert!(due(Some(failed), now + chrono::Duration::minutes(60)));
        assert_eq!(Checked::after(now, false).at, Some(now));
    }

    /// The manifest `GET /downloads/desktop.json` serves (docs/API.md "Public pages and app downloads").
    fn manifest() -> serde_json::Value {
        serde_json::from_str(include_str!("../tests/fixtures/server/downloads_desktop_200.json")).unwrap()
    }

    #[test]
    fn the_manifest_offers_each_installed_app_its_own_package() {
        let endpoint = url("https://ledger.example.test/downloads/desktop.json");
        for (target, file) in [
            ("windows-x86_64", "EchoLedger-1.6.0-setup.exe"),
            ("darwin-aarch64", "EchoLedger-1.6.0-mac-apple-silicon.app.tar.gz"),
            ("darwin-x86_64", "EchoLedger-1.6.0-mac-intel.app.tar.gz"),
        ] {
            let (version, package) = offer_for(&manifest(), target, "1.1.0", &endpoint).unwrap().expect(target);
            assert_eq!(version, "1.6.0");
            assert!(package.as_str().ends_with(file), "{package}");
        }
        assert!(["windows-x86_64", "darwin-aarch64", "darwin-x86_64"].contains(&this_target()) || cfg!(not(any(windows, target_os = "macos"))));
        assert!(manifest()["platforms"].get(this_target()).is_some() || cfg!(not(any(windows, target_os = "macos"))), "this build's own key is in the manifest");
    }

    #[test]
    fn an_app_already_on_that_version_or_newer_is_offered_nothing() {
        let endpoint = url("https://ledger.example.test/downloads/desktop.json");
        assert_eq!(offer_for(&manifest(), "windows-x86_64", "1.6.0", &endpoint), Ok(None));
        assert_eq!(offer_for(&manifest(), "windows-x86_64", "1.7.0-beta.1", &endpoint), Ok(None));
        // A pre-release is older than its release.
        assert!(offer_for(&manifest(), "windows-x86_64", "1.6.0-beta.3", &endpoint).unwrap().is_some());
        assert!(offer_for(&manifest(), "windows-x86_64", env!("CARGO_PKG_VERSION"), &endpoint).is_ok());
    }

    #[test]
    fn a_manifest_the_updater_cannot_use_says_why() {
        let endpoint = url("https://ledger.example.test/downloads/desktop.json");
        let mut no_linux = manifest();
        assert!(offer_for(&no_linux, "linux-x86_64", "1.1.0", &endpoint).unwrap_err().contains("no package for linux-x86_64"));
        no_linux["platforms"]["windows-x86_64"]["signature"] = serde_json::json!(" ");
        assert!(offer_for(&no_linux, "windows-x86_64", "1.1.0", &endpoint).unwrap_err().contains("empty signature"));
        let mut elsewhere = manifest();
        elsewhere["platforms"]["darwin-aarch64"]["url"] = serde_json::json!("https://cdn.example.test/EchoLedger.app.tar.gz");
        assert!(offer_for(&elsewhere, "darwin-aarch64", "1.1.0", &endpoint).unwrap_err().contains("not on the server"));
        assert!(offer_for(&serde_json::json!({"version": "1.6.0"}), "windows-x86_64", "1.1.0", &endpoint).is_err(), "no platforms");
        assert!(offer_for(&serde_json::json!({"platforms": {}}), "windows-x86_64", "1.1.0", &endpoint).is_err(), "no version");
    }

    #[test]
    fn a_kept_update_is_installed_once_at_the_next_start() {
        let dir = tempfile::tempdir().unwrap();
        assert_eq!(SavedUpdate::load(dir.path()), None);
        assert!(!install_at_start(None));

        let mut saved = SavedUpdate { version: "1.5.1".into(), manifest: serde_json::json!({"version": "1.5.1"}), attempted: false };
        saved.save(dir.path(), b"package bytes").unwrap();
        assert_eq!(std::fs::read(package_path(dir.path())).unwrap(), b"package bytes");
        let loaded = SavedUpdate::load(dir.path()).unwrap();
        assert_eq!(loaded, saved);
        assert!(install_at_start(Some(&loaded)));

        saved.mark_attempted(dir.path()).unwrap();
        assert!(!install_at_start(SavedUpdate::load(dir.path()).as_ref()), "a start that tried never tries again");

        // A description without its package is not an update.
        std::fs::remove_file(package_path(dir.path())).unwrap();
        assert_eq!(SavedUpdate::load(dir.path()), None);
        SavedUpdate::remove(dir.path());
        assert!(!dir.path().join("updates").join("update.json").exists());
    }

    #[test]
    fn the_last_check_is_remembered() {
        let dir = tempfile::tempdir().unwrap();
        assert_eq!(Checked::load(dir.path()).at, None);
        let now = Utc::now();
        Checked::after(now, false).save(dir.path());
        assert_eq!(Checked::load(dir.path()).at, Some(now));
    }

    #[test]
    fn updater_errors_read_as_plain_words() {
        use tauri_plugin_updater::Error as E;
        assert_eq!(describe(&E::ReleaseNotFound), (UpdatePhase::UpToDate, "No update is published on your server yet.".into()));
        assert_eq!(describe(&E::TargetNotFound("darwin-aarch64".into())).0, UpdatePhase::UpToDate);
        assert_eq!(describe(&E::MissingSignedVersion).0, UpdatePhase::Failed);
        assert!(describe(&E::MissingSignedVersion).1.contains("signature"));
        assert_eq!(describe(&E::FailedToDetermineExtractPath), (UpdatePhase::Unavailable, "Updates work in the installed app only.".into()));
        assert_eq!(describe(&E::InsecureTransportProtocol).1, NEEDS_HTTPS);
        assert!(describe(&E::Network("timed out".into())).1.starts_with("Your server could not be reached"));
    }

    #[test]
    fn the_status_names_its_phase_and_this_version() {
        let updates = Updates::default();
        assert_eq!(updates.status().phase, UpdatePhase::Idle);
        assert_eq!(updates.status().current, env!("CARGO_PKG_VERSION"));
        let ready = updates.set(|s| {
            s.phase = UpdatePhase::Ready;
            s.version = Some("1.5.1".into());
        });
        let json = serde_json::to_value(&ready).unwrap();
        assert_eq!(json["phase"], "ready");
        assert_eq!(json["version"], "1.5.1");
        assert!(json.get("checkedAt").is_some());
        assert!(updates.begin());
        assert!(!updates.begin(), "one job at a time");
        updates.end();
        assert!(updates.begin());
    }
}
