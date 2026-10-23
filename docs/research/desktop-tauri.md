# Desktop shell research: Tauri 2 (windows, tray, plugins, IPC, testing, networking)

Research note for `desktop/` (Tauri 2 + React 19 + TS). Researched 2026-10-24.

- **Method.** Every API fact was read during research in a primary source, cited inline: installed `desktop/node_modules/@tauri-apps/**` and `desktop/src-tauri/*`, docs.rs, the crates.io API, v2.tauri.app, GitHub source at the release tag, learn.microsoft.com, or Apple docs JSON.
- **UNVERIFIED** marks an inference I could not confirm in a primary source.
- **Rust is not installed here**, so snippets match the verified signatures but have never been compiled.
- **This machine:** node v24.19.0, npm 11.17.0, no `cargo`/`rustc`. No `vswhere.exe` and no `C:\Program Files (x86)\Windows Kits\10`, so the MSVC Build Tools and Windows SDK are missing. WebView2 is installed (registry `HKLM\...\EdgeUpdate\Clients\{F3017226-...}` `pv=155.0.4283.62`).
- **Scaffold today:** `tauri.conf.json` has `identifier "com.admin.desktop"`, `productName "desktop"`, `csp: null` and one 800x600 `main` window. `Cargo.toml` has `tauri = "2"`, `tauri-plugin-opener = "2"`, edition 2024. `capabilities/default.json` grants `core:default`, `opener:default` to `["main"]`.

---

## 1. Versions (verified 2026-10-24)

**Policy.**
- Keep "the same minor version of the npm package `@tauri-apps/api` and cargo crate `tauri`", and the same *exact* version for each plugin's npm package and crate ([updating deps](https://v2.tauri.app/develop/updating-dependencies/)).
- `tauri build` errors on mismatches unless you pass `--ignore-version-mismatches` (local `tauri build --help`).

| Crate | Latest stable | Installed npm | Source |
|---|---|---|---|
| `tauri` | **2.12.5** (2026-10-21). 3.0.0-alpha.5 exists; do not use it | api **2.12.4**, cli **2.12.4** (same minor, OK) | [crates.io](https://crates.io/api/v1/crates/tauri), `node_modules/@tauri-apps/*/package.json` |
| `tauri-build` | **2.7.4** (2026-10-21; separate numbering) | – | [crates.io](https://crates.io/api/v1/crates/tauri-build) |
| `tauri-plugin-global-shortcut` | **2.4.1** | 2.4.1 | [crates.io](https://crates.io/api/v1/crates/tauri-plugin-global-shortcut) |
| `tauri-plugin-autostart` | **2.7.0** | 2.7.0 | [crates.io](https://crates.io/api/v1/crates/tauri-plugin-autostart) |
| `tauri-plugin-notification` | **2.7.0** | 2.7.0 | [crates.io](https://crates.io/api/v1/crates/tauri-plugin-notification) |
| `tauri-plugin-positioner` | **2.4.0** | 2.4.0 | [crates.io](https://crates.io/api/v1/crates/tauri-plugin-positioner) |
| `tauri-plugin-store` | **2.5.0** | 2.5.0 | [crates.io](https://crates.io/api/v1/crates/tauri-plugin-store) |
| `tauri-plugin-updater` | **2.14.0** | 2.14.0 | [crates.io](https://crates.io/api/v1/crates/tauri-plugin-updater) |
| `tauri-plugin-opener` | **2.7.2** | 2.7.2 | [crates.io](https://crates.io/api/v1/crates/tauri-plugin-opener) |
| `tauri-plugin-single-instance` | **2.5.4** | Rust-only, no npm package (CLI 2.12.0 changelog) | [crates.io](https://crates.io/api/v1/crates/tauri-plugin-single-instance) |
| `tauri-plugin-clipboard-manager` | **2.5.0** | npm 2.5.0 exists, not installed | [crates.io](https://crates.io/api/v1/crates/tauri-plugin-clipboard-manager) |

**Under tauri 2.12.5:**
- `rust-version = "1.90"` ([workspace Cargo.toml](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/Cargo.toml)).
- tauri-runtime-wry 2.12.3 pulls `tao = "0.37.0"` and `wry = "0.57.0"` ([Cargo.toml](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri-runtime-wry/Cargo.toml)). The newest tao is 0.37.2 (2026-10-23) ([crates.io](https://crates.io/api/v1/crates/tao)).
- `windows = "0.62"` (Windows only), `tray-icon 0.25`, `muda 0.20`, and `tokio 1` with only `rt, rt-multi-thread, sync, fs, io-util` ([tauri Cargo.toml](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri/Cargo.toml)).

**Other crates** (crates.io API): `reqwest` 0.13.5 (MSRV 1.85) · `rustls` 0.23.46 (0.24 is dev-only) · `rustls-webpki` 0.103.16 · `webpki-roots` 1.0.11 · `ring` 0.17.14 · `tokio-tungstenite`/`tungstenite` 0.30.0 · `keyring` **4.2.0** (last 3.x is 3.6.3) · `enigo` 0.6.1 · `tokio` 1.54.1 (1.54.0 yanked) · `futures-util` 0.3.34 · `thiserror` 2.0.21 · `base64` 0.23.1.

**JS test tooling** (npm registry): `vitest` 5.0.5 (peer `vite ^6.4 || ^7 || ^8`; `jsdom` is an optional peer) · `jsdom` 30.1.3 (engines `^22.22.2 || ^24.15.0 || >=26`, OK for Node 24.19) · `@testing-library/react` 16.3.3 (peers React 18/19, `@testing-library/dom ^10`) · `@testing-library/dom` 10.4.2 · `@testing-library/jest-dom` 7.0.1 (has a `./vitest` export) · `@playwright/test` 1.64.1. Installed frontend: vite 8.3.5, @vitejs/plugin-react 6.1.2, react 19.3.1, typescript 6.0.3.

Recommended `src-tauri/Cargo.toml`. Not compiled; plugin pins mirror npm exactly:

```toml
[build-dependencies]
tauri-build = { version = "2.7.4", features = [] }

[dependencies]
tauri = { version = "2.12.5", features = ["tray-icon", "image-png"] }
tauri-plugin-single-instance = "=2.5.4"
tauri-plugin-global-shortcut = "=2.4.1"
tauri-plugin-autostart = "=2.7.0"
tauri-plugin-notification = "=2.7.0"
tauri-plugin-positioner = { version = "=2.4.0", features = ["tray-icon"] }
tauri-plugin-store = "=2.5.0"
tauri-plugin-updater = "=2.14.0"
tauri-plugin-opener = "=2.7.2"
tauri-plugin-clipboard-manager = "=2.5.0"
serde = { version = "1", features = ["derive"] }
serde_json = "1"
thiserror = "2.0.21"
tokio = { version = "1.54.1", features = ["time", "macros", "sync"] }   # tauri does NOT enable "time"
futures-util = { version = "0.3.34", features = ["sink"] }             # SinkExt is behind "sink"
reqwest = { version = "0.13.5", default-features = false, features = ["rustls-no-provider", "json", "multipart", "http2", "stream"] }
rustls = { version = "0.23.46", default-features = false, features = ["ring", "std", "tls12", "logging"] }
rustls-webpki = "0.103.16"                                             # default std+alloc (SPKI accessor)
webpki-roots = "1.0.11"
ring = "0.17.14"
tokio-tungstenite = { version = "0.30.0", features = ["rustls-tls-webpki-roots"] }
keyring = "4.2.0"                                                      # default feature "v1" = Entry API
enigo = "0.6.1"
base64 = "0.23.1"

[target.'cfg(windows)'.dependencies]
windows = { version = "0.62.2", features = ["Win32_Foundation", "Win32_UI_WindowsAndMessaging"] }

[dev-dependencies]
tauri = { version = "2.12.5", features = ["test"] }                    # exposes tauri::test (MockRuntime)
```

**Feature facts:**

| Crate | Feature facts | Source |
|---|---|---|
| tauri | `tray-icon` "Enables application tray icon APIs" and is auto-enabled when `trayIcon` is in the config. `image-ico`/`image-png` gate `Image::from_bytes`/`from_path`. `test` "Enables the `mod@test` module exposing unit test helpers" | [lib.rs](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri/src/lib.rs), [Image](https://docs.rs/tauri/2.12.5/tauri/image/struct.Image.html) |
| rustls | `default = ["aws_lc_rs","logging","prefer-post-quantum","std","tls12"]`; `ring = ["dep:ring","webpki/ring"]` | [rustls Cargo.toml](https://raw.githubusercontent.com/rustls/rustls/v/0.23.46/rustls/Cargo.toml) |
| reqwest | `default-tls = ["rustls"]`; `rustls = ["__rustls-aws-lc-rs","dep:rustls-platform-verifier","__rustls"]`; `rustls-no-provider = ["dep:rustls-platform-verifier","__rustls"]` | [reqwest Cargo.toml](https://raw.githubusercontent.com/seanmonstar/reqwest/v0.13.5/Cargo.toml) |
| tokio-tungstenite | `default = ["connect","handshake"]`; `rustls-tls-webpki-roots = ["__rustls-tls","webpki-roots"]` | [Cargo.toml](https://raw.githubusercontent.com/snapview/tokio-tungstenite/v0.30.0/Cargo.toml) |

---

## 2. Overlay window (captions + dictation)

### 2.1 `tauri.conf.json` window keys

`WindowConfig` is `#[serde(rename_all = "camelCase", deny_unknown_fields)]` ([config.rs](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri-utils/src/config.rs)):

| Key | Doc comment / default |
|---|---|
| `label` | "The window identifier. It must be alphanumeric." Default `"main"` |
| `create` | "Whether Tauri should create this window at app startup or not." Default `true` |
| `url`, `width`, `height`, `x`, `y` | Sizes and positions are in **logical** pixels |
| `visible`, `decorations`, `resizable` | Each defaults to `true` |
| `transparent` | "Whether the window is transparent or not." On Windows, `noRedirectionBitmap` avoids the white flash |
| `alwaysOnTop` / `skipTaskbar` | On top of other windows / "hides the window icon from the taskbar on Windows and Linux" |
| `focus` | "Whether the window will be initially focused or not." `WindowConfig::default()` sets it to `false` since 2.10.0 ([CHANGELOG](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri/CHANGELOG.md)) |
| `focusable` | "Whether the window will be focusable or not." |
| `shadow` | Windows: `false` has no effect on decorated windows. `true` gives an undecorated window "a 1px white border" (rounded on Windows 11). Unsupported on Linux |
| `visibleOnAllWorkspaces` | Unsupported on Windows, iOS and Android |
| `contentProtected` | "Prevents the window contents from being captured by other apps." |
| `acceptFirstMouse` | "Whether clicking an inactive window also clicks through to the webview on macOS." |
| `app.macOSPrivateApi` | "No-op in Tauri 2.12.1+ because the APIs are always enabled now." CHANGELOG 2.12.1: "The `macos-private-api` feature flag / `macOSPrivateAPI` tauri.conf.json value is no longer required" |

**→ Transparency on macOS needs no private-API flag with tauri >= 2.12.1.** Leave both the config key and the Cargo feature out.

### 2.2 `WebviewWindowBuilder` (tauri 2.12.5)

Signatures are from [docs.rs](https://docs.rs/tauri/2.12.5/tauri/webview/struct.WebviewWindowBuilder.html). Bodies are from [source](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri/src/webview/webview_window.rs).

```rust
pub fn new<L: Into<String>>(manager: &'a M, label: L, url: WebviewUrl) -> Self
pub fn from_config(manager: &'a M, config: &WindowConfig) -> Result<Self>
pub fn transparent(self, transparent: bool) -> Self      // window + webview
pub fn decorations(self, decorations: bool) -> Self
pub fn always_on_top(self, always_on_top: bool) -> Self
pub fn skip_taskbar(self, skip: bool) -> Self            // macOS: unsupported
pub fn focused(self, focused: bool) -> Self              // sets window_builder.focused AND webview_builder.focused
pub fn focusable(self, focusable: bool) -> Self
pub fn shadow(self, enable: bool) -> Self
pub fn resizable(self, resizable: bool) -> Self
pub fn visible_on_all_workspaces(self, v: bool) -> Self
pub fn content_protected(self, protected: bool) -> Self
pub fn accept_first_mouse(self, accept: bool) -> Self    // webview builder only
pub fn no_redirection_bitmap(self, enable: bool) -> Self // WS_EX_NOREDIRECTIONBITMAP, Windows only (new in 2.12.0)
pub fn visible(self, visible: bool) -> Self
pub fn inner_size(self, width: f64, height: f64) -> Self // logical
pub fn position(self, x: f64, y: f64) -> Self            // logical
pub fn build(self) -> Result<WebviewWindow<R>>
```

**Known issue, quoted from `new()`:** "On Windows, this function deadlocks when used in a synchronous command and event handlers … You should use `async` commands and separate threads when creating windows." `from_config` carries the same warning.

### 2.3 Runtime window API (Rust)

Sources: [Window](https://docs.rs/tauri/2.12.5/tauri/window/struct.Window.html), [WebviewWindow](https://docs.rs/tauri/2.12.5/tauri/webview/struct.WebviewWindow.html), [AppHandle](https://docs.rs/tauri/2.12.5/tauri/struct.AppHandle.html), [Monitor](https://docs.rs/tauri/2.12.5/tauri/window/struct.Monitor.html), [PhysicalRect](https://docs.rs/tauri/2.12.5/tauri/struct.PhysicalRect.html).

```rust
pub fn set_ignore_cursor_events(&self, ignore: bool) -> Result<()>      // desktop only
pub fn cursor_position(&self) -> Result<PhysicalPosition<f64>>          // Window, WebviewWindow AND AppHandle
pub fn inner_position(&self) -> Result<PhysicalPosition<i32>>;  pub fn outer_position(&self) -> Result<PhysicalPosition<i32>>
pub fn inner_size(&self) -> Result<PhysicalSize<u32>>;          pub fn outer_size(&self) -> Result<PhysicalSize<u32>>
pub fn scale_factor(&self) -> Result<f64>;                      pub fn start_dragging(&self) -> Result<()>
pub fn set_focusable(&self, focusable: bool) -> Result<()>;     pub fn set_always_on_top(&self, v: bool) -> Result<()>
pub fn set_content_protected(&self, protected: bool) -> Result<()>; pub fn set_visible_on_all_workspaces(&self, v: bool) -> Result<()>
pub fn set_skip_taskbar(&self, skip: bool) -> Result<()>;       pub fn show(&self) -> Result<()>;  pub fn hide(&self) -> Result<()>
pub fn set_position<Pos: Into<Position>>(&self, position: Pos) -> Result<()>  // Position::{Physical(PhysicalPosition<i32>), Logical(LogicalPosition<f64>)}
pub fn current_monitor(&self) -> Result<Option<Monitor>>;       /* AppHandle: */ pub fn primary_monitor(&self) -> Result<Option<Monitor>>
/* Monitor: */ pub fn work_area(&self) -> &PhysicalRect<i32, u32>; pub fn scale_factor(&self) -> f64   // PhysicalRect { position, size }
pub fn hwnd(&self) -> Result<HWND>              // Windows only; tauri uses windows 0.62
pub fn ns_window(&self) -> Result<*mut c_void>  // macOS only
```

Platform-only methods: `hwnd` per the [Windows build docs](https://docs.rs/tauri/2.12.5/x86_64-pc-windows-msvc/tauri/webview/struct.WebviewWindow.html), `ns_window` per the [macOS build docs](https://docs.rs/tauri/2.12.5/x86_64-apple-darwin/tauri/webview/struct.WebviewWindow.html).

**Which cursor API to use, and in what units.**
- `AppHandle::cursor_position()` and `Window::cursor_position()` both exist.
- Both return **physical** `PhysicalPosition<f64>` "relative to the top-left hand corner of the desktop". That is the main monitor's top-left on Windows and macOS. "The coordinates can be negative."
- The JS equivalent is `cursorPosition(): Promise<PhysicalPosition>`, "in physical pixels". Its permission `core:window:allow-cursor-position` is in `core:window:default` (`node_modules/@tauri-apps/api/window.d.ts`).

### 2.4 Platform behaviour, from tao source

**Windows** ([window_state.rs](https://raw.githubusercontent.com/tauri-apps/tao/dev/src/platform_impl/windows/window_state.rs), [window.rs](https://raw.githubusercontent.com/tauri-apps/tao/dev/src/platform_impl/windows/window.rs)):

| Call | Win32 effect |
|---|---|
| `focusable(false)` | `if !self.contains(WindowFlags::FOCUSABLE) { style_ex \|= WS_EX_NOACTIVATE; }` |
| `always_on_top` | `WS_EX_TOPMOST` / `HWND_TOPMOST` |
| `set_ignore_cursor_events(true)` | `WS_EX_TRANSPARENT \| WS_EX_LAYERED` |
| `skip_taskbar` | `ITaskbarList::DeleteTab` |
| content protection | `SetWindowDisplayAffinity(.., WDA_EXCLUDEFROMCAPTURE)` |
| show | `if old.contains(WindowFlags::MARKER_DONT_FOCUS) { SW_SHOWNOACTIVATE } else { SW_SHOW }` |

- `init` sets the marker from `!attributes.focused` and removes it after creation.
- **tao 0.37.1 (2026-09-26), quoted:** "if the window is created through `with_focused(false)`, subsequent `window.set_visible(true)` calls will now focus the window … this is consistent with the other platforms" ([notes](https://v2.tauri.app/release/tao/v0.37.1/), [CHANGELOG](https://raw.githubusercontent.com/tauri-apps/tao/dev/CHANGELOG.md)). **→ On Windows a later `show()` steals focus.**
- **WS_EX_NOACTIVATE:** "does not become the foreground window when the user clicks it … should not be activated through programmatic access … To activate the window, use the SetActiveWindow or SetForegroundWindow function. The window does not appear on the taskbar by default" ([MS](https://learn.microsoft.com/en-us/windows/win32/winmsg/extended-window-styles)).
- **ShowWindow:** `SW_SHOW` "Activates the window and displays it"; `SW_SHOWNOACTIVATE`/`SW_SHOWNA` display it "except that the window is not activated" ([MS](https://learn.microsoft.com/en-us/windows/win32/api/winuser/nf-winuser-showwindow)).
- **UNVERIFIED:** whether `SW_SHOW` activates a window that has `WS_EX_NOACTIVATE`. Design around it (§2.6).
- **WDA_EXCLUDEFROMCAPTURE** was "Introduced in Windows 10 Version 2004". Earlier versions behave "as if WDA_MONITOR is applied", meaning the window appears with no content in captures ([MS](https://learn.microsoft.com/en-us/windows/win32/api/winuser/nf-winuser-setwindowdisplayaffinity)).

**macOS** ([tao macos/window.rs](https://raw.githubusercontent.com/tauri-apps/tao/dev/src/platform_impl/macos/window.rs)):

| Call | AppKit effect |
|---|---|
| `set_visible(true)` / hide | `make_key_and_order_front_sync` / `order_out_sync` |
| `focusable(false)` | the `canBecomeKeyWindow`/`canBecomeMainWindow` overrides return the `focusable` ivar, so the window can never become key |
| visible at creation | `makeKeyAndOrderFront` only `if focused`, else `orderFront(None)` |
| ignore cursor events | `setIgnoresMouseEvents` |
| all workspaces | toggles `NSWindowCollectionBehavior::CanJoinAllSpaces` only |
| content protection | `setSharingType(NSWindowSharingType::None)` |
| transparent | `setOpaque(false)` + clear `backgroundColor` |

- Docs caveat for `set_focusable`: "If the window is already focused, it is not possible to unfocus it after calling `set_focusable(false)`."
- **UNVERIFIED:** whether the overlay can show above another app's *fullscreen* Space. If QA fails, the fallback is `tauri-nspanel` 2.1 (MIT/Apache), which turns windows into `NSPanel` ([GitHub](https://github.com/ahkohd/tauri-nspanel)).

### 2.5 Click-through on empty space (cursor poll, in Rust)

When `set_ignore_cursor_events(true)` is on, the webview gets no mouse events at all. Poll from Rust so there is no IPC per tick, and toggle only on transitions. React reports interactive rects (CSS px multiplied by `devicePixelRatio`, i.e. physical px relative to the client area) only when layout changes.

```rust
// overlay/hit.rs (pure, unit-tested)
#[derive(Clone, Copy, Debug, PartialEq, serde::Deserialize)]
pub struct RectPx { pub x: f64, pub y: f64, pub w: f64, pub h: f64 }
pub fn cursor_in_regions(cursor: (f64, f64), client_origin: (i32, i32), regions: &[RectPx]) -> bool {
    let (lx, ly) = (cursor.0 - client_origin.0 as f64, cursor.1 - client_origin.1 as f64);
    regions.iter().any(|r| lx >= r.x && lx < r.x + r.w && ly >= r.y && ly < r.y + r.h)
}
// overlay/poll.rs
use std::{sync::Mutex, time::Duration};
use tauri::{AppHandle, Manager, Runtime};
#[derive(Default)] pub struct HitRegions(pub Mutex<Vec<RectPx>>);
#[tauri::command]
fn overlay_set_hit_regions(regions: Vec<RectPx>, state: tauri::State<'_, HitRegions>) { *state.0.lock().unwrap() = regions; }
pub fn spawn_clickthrough_poll<R: Runtime>(app: AppHandle<R>, label: &'static str) {
    tauri::async_runtime::spawn(async move {
        let mut ignoring: Option<bool> = None;
        let mut tick = tokio::time::interval(Duration::from_millis(33));        // ~30 Hz
        loop {
            tick.tick().await;
            let Some(win) = app.get_webview_window(label) else { break };
            let (Ok(c), Ok(o)) = (app.cursor_position(), win.inner_position()) else { continue };
            let regions = app.state::<HitRegions>().0.lock().unwrap().clone();
            let want_ignore = !cursor_in_regions((c.x, c.y), (o.x, o.y), &regions);
            if ignoring != Some(want_ignore) && win.set_ignore_cursor_events(want_ignore).is_ok() { ignoring = Some(want_ignore); }
        }
    });
}
```

`tokio::time` needs our own tokio `time` feature. Tauri's default runtime is `TokioRuntime::new()` ([async_runtime.rs](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri/src/async_runtime.rs)). `Runtime::new()` initializes "the multi threaded scheduler, I/O driver, and time driver" ([tokio](https://docs.rs/tokio/1.54.1/tokio/runtime/struct.Runtime.html)). Tauri compiles tokio without `time`.

### 2.6 Showing without stealing focus: strategy

1. **Create once, visible, unfocused, unfocusable**: `.visible(true).focused(false).focusable(false)`.
   - On Windows the first show then uses `SW_SHOWNOACTIVATE` and the window gets `WS_EX_NOACTIVATE`.
   - On macOS it gets `orderFront` and can never become key.
2. **Never call `show()` or `set_focus()` on the overlay afterwards.** "Hidden" means rendering nothing with `set_ignore_cursor_events(true)` on. To really remove it, `destroy()` it and **re-create** it from an async command or thread (the deadlock rule in §2.2).
3. **Fallback if QA shows activation:** call `ShowWindow(hwnd, SW_SHOWNA)` directly. Signature: `pub unsafe fn ShowWindow(hwnd: HWND, ncmdshow: SHOW_WINDOW_CMD) -> BOOL` ([windows-docs-rs](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/UI/WindowsAndMessaging/fn.ShowWindow.html)). **UNVERIFIED:** tao's cached `VISIBLE` flag will not know about it.
4. Set `accept_first_mouse(true)`.
5. Drag with JS `getCurrentWindow().startDragging()`, which needs `core:window:allow-start-dragging` (not in the defaults, per `window.d.ts`), or with `data-tauri-drag-region`. That attribute "will only work on the element to which it is directly applied" ([window customization](https://v2.tauri.app/learn/window-customization/)).

```rust
use tauri::{AppHandle, Runtime, WebviewUrl, WebviewWindowBuilder};
pub fn create_overlay<R: Runtime>(app: &AppHandle<R>) -> tauri::Result<tauri::WebviewWindow<R>> {
    const W: f64 = 440.0; const H: f64 = 132.0; const M: f64 = 16.0;            // logical px
    let (x, y) = match app.primary_monitor()? {                                  // physical work area → logical
        Some(m) => { let s = m.scale_factor(); let wa = m.work_area();
            ((wa.position.x as f64 + wa.size.width as f64) / s - W - M, (wa.position.y as f64 + wa.size.height as f64) / s - H - M) }
        None => (64.0, 64.0),
    };
    WebviewWindowBuilder::new(app, "overlay", WebviewUrl::App("index.html".into()))
        .title("EchoLedger captions").inner_size(W, H).position(x, y)
        .transparent(true).no_redirection_bitmap(true).decorations(false).shadow(false).resizable(false)
        .always_on_top(true).skip_taskbar(true).visible_on_all_workspaces(true)
        .content_protected(true)                     // hidden from screen share; expose as a setting
        .focused(false).focusable(false).accept_first_mouse(true).visible(true)
        .build()
}
```

- The page needs `html, body { background: transparent }`.
- Docking is a pure `dock(work_area, outer_size, corner, margin_px) -> PhysicalPosition<i32>` applied with `set_position(PhysicalPosition)`.
- Re-dock via `current_monitor()` after creation: logical `position()` on mixed-DPI setups is UNVERIFIED.

---

## 3. Tray, and staying alive with no windows

**Tray** ([tray docs](https://v2.tauri.app/learn/system-tray/), [TrayIconBuilder](https://docs.rs/tauri/2.12.5/tauri/tray/struct.TrayIconBuilder.html), [TrayIconEvent](https://docs.rs/tauri/2.12.5/tauri/tray/enum.TrayIconEvent.html), [TrayIcon](https://docs.rs/tauri/2.12.5/tauri/tray/struct.TrayIcon.html), [MenuItem](https://docs.rs/tauri/2.12.5/tauri/menu/struct.MenuItem.html), [Menu](https://docs.rs/tauri/2.12.5/tauri/menu/struct.Menu.html)):
- Needs the Cargo feature `tray-icon`.
- `menu_on_left_click` is deprecated since 2.2.0; use `show_menu_on_left_click`, which is unsupported on Linux.
- `TrayIconEvent` is `#[non_exhaustive]`:
  - `Click { id, position: PhysicalPosition<f64>, rect, button: MouseButton, button_state: MouseButtonState }`
  - `DoubleClick`, `Enter`, `Move`, `Leave`

```rust
pub fn with_id<I: Into<TrayIconId>>(id: I) -> Self;  pub fn menu<M: ContextMenu>(self, menu: &M) -> Self
pub fn icon(self, icon: Image<'_>) -> Self;  pub fn tooltip<S: AsRef<str>>(self, s: S) -> Self;  pub fn show_menu_on_left_click(self, enable: bool) -> Self
pub fn on_menu_event<F: Fn(&AppHandle<R>, MenuEvent) + Sync + Send + 'static>(self, f: F) -> Self
pub fn on_tray_icon_event<F: Fn(&TrayIcon<R>, TrayIconEvent) + Sync + Send + 'static>(self, f: F) -> Self
pub fn build<M: Manager<R>>(self, manager: &M) -> Result<TrayIcon<R>>
// TrayIcon: set_icon(Option<Image>), set_tooltip(Option<S>), set_menu(Option<M>), set_visible(bool); AppHandle::tray_by_id(&id)
// MenuItem::with_id(manager, id, text, enabled: bool, accelerator: Option<A>) -> Result<Self>; Menu::with_items(manager, &[&dyn IsMenuItem<R>])
```

**Keep-alive APIs:**
- `Builder::on_window_event<F: Fn(&Window<R>, &WindowEvent) + Send + Sync + 'static>` ([Builder](https://docs.rs/tauri/2.12.5/tauri/struct.Builder.html)).
- `WindowEvent::CloseRequested { api: CloseRequestApi }` (`#[non_exhaustive]`); `CloseRequestApi::prevent_close(&self)` does "Prevents the window from being closed" ([WindowEvent](https://docs.rs/tauri/2.12.5/tauri/enum.WindowEvent.html), [CloseRequestApi](https://docs.rs/tauri/2.12.5/tauri/struct.CloseRequestApi.html)).
- `App::run<F: FnMut(&AppHandle<R>, RunEvent) + 'static>` ([App](https://docs.rs/tauri/2.12.5/tauri/struct.App.html)).
- `RunEvent::ExitRequested { code: Option<i32>, api: ExitRequestApi }`. `code` is `None` for user-initiated exits and `Some` for programmatic `AppHandle` exits. `prevent_exit(&self)` is ignored for `AppHandle::restart` ([RunEvent](https://docs.rs/tauri/2.12.5/tauri/enum.RunEvent.html), [ExitRequestApi](https://docs.rs/tauri/2.12.5/tauri/struct.ExitRequestApi.html)).
- macOS only ([app.rs](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri/src/app.rs)):
  - `RunEvent::Reopen { has_visible_windows }`
  - `App::set_activation_policy(&mut self, ActivationPolicy)`, with `ActivationPolicy::{Regular, Accessory, Prohibited}` ([docs](https://docs.rs/tauri/2.12.5/x86_64-apple-darwin/tauri/enum.ActivationPolicy.html))
  - `AppHandle::set_dock_visibility(&self, bool)`

```rust
let app = tauri::Builder::default()
    .plugin(tauri_plugin_single_instance::init(|app, _argv, _cwd| {              // MUST be first (§4.1)
        if let Some(w) = app.get_webview_window("main") { let _ = w.show(); let _ = w.set_focus(); }
    }))
    // ... other plugins ...
    .on_window_event(|window, event| {
        if let tauri::WindowEvent::CloseRequested { api, .. } = event {
            if window.label() == "main" { api.prevent_close(); let _ = window.hide(); }
        }
    })
    .setup(|app| {
        use tauri::{menu::{Menu, MenuItem}, tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent}};
        let open = MenuItem::with_id(app, "open", "Open EchoLedger", true, None::<&str>)?;
        let quit = MenuItem::with_id(app, "quit", "Quit", true, None::<&str>)?;
        let menu = Menu::with_items(app, &[&open, &quit])?;
        TrayIconBuilder::with_id("main").icon(app.default_window_icon().unwrap().clone())
            .tooltip("EchoLedger").menu(&menu).show_menu_on_left_click(false)
            .on_menu_event(|app, e| match e.id.as_ref() {
                "open" => if let Some(w) = app.get_webview_window("main") { let _ = w.show(); let _ = w.set_focus(); },
                "quit" => app.exit(0),                                           // code Some(0): not prevented below
                _ => {}
            })
            .on_tray_icon_event(|tray, e| {
                tauri_plugin_positioner::on_tray_event(tray.app_handle(), &e);
                if let TrayIconEvent::Click { button: MouseButton::Left, button_state: MouseButtonState::Up, .. } = e { /* toggle main */ }
            })
            .build(app)?;
        #[cfg(target_os = "macos")] app.set_activation_policy(tauri::ActivationPolicy::Accessory);   // menu-bar app
        Ok(())
    })
    .build(tauri::generate_context!()).expect("build");
app.run(|_h, event| {
    if let tauri::RunEvent::ExitRequested { code, api, .. } = &event { if code.is_none() { api.prevent_exit(); } }
});
```

The `Info.plist` alternative to `Accessory` is `LSUIElement`: "whether the app is an agent app that runs in the background and doesn't appear in the Dock" ([Apple](https://developer.apple.com/tutorials/data/documentation/bundleresources/information-property-list/lsuielement.json)).

---

## 4. Plugins: Rust init, permissions, APIs

| Plugin | Rust init | JS permissions |
|---|---|---|
| single-instance 2.5.4 | `tauri_plugin_single_instance::init(\|app, argv, cwd\| ..)`, registered **first** | none; there is no JS API ([docs](https://v2.tauri.app/plugin/single-instance/)) |
| global-shortcut 2.4.1 | `tauri_plugin_global_shortcut::Builder::new().with_handler(..).build()` | none by default: "No features are enabled by default", `permissions = []` ([default.toml](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/global-shortcut-v2.4.1/plugins/global-shortcut/permissions/default.toml)). Explicit: `global-shortcut:allow-register\|allow-unregister\|allow-is-registered\|allow-register-all\|allow-unregister-all` |
| autostart 2.7.0 | `tauri_plugin_autostart::init(MacosLauncher::LaunchAgent, Some(vec!["--autostart"]))` | `autostart:default` = allow-enable/disable/is-enabled ([docs](https://v2.tauri.app/plugin/autostart/)) |
| notification 2.7.0 | `tauri_plugin_notification::init()` | `notification:default` (all) ([docs](https://v2.tauri.app/plugin/notification/)) |
| positioner 2.4.0 + `tray-icon` | `tauri_plugin_positioner::init()` | `positioner:default` = allow-move-window, allow-move-window-constrained, allow-set-tray-icon-state ([docs](https://v2.tauri.app/plugin/positioner/)) |
| store 2.5.0 | `tauri_plugin_store::Builder::new().build()` | `store:default` = load, get-store, set, get, has, delete, clear, reset, keys, values, entries, length, reload, save ([docs](https://v2.tauri.app/plugin/store/)) |
| updater 2.14.0 | `tauri_plugin_updater::Builder::new().build()` | `updater:default` = allow-check, allow-download, allow-install, allow-download-and-install ([default.toml](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/updater-v2.14.0/plugins/updater/permissions/default.toml)) |
| opener 2.7.2 | `tauri_plugin_opener::init()` | `opener:default` = allow-open-url, allow-reveal-item-in-dir, allow-default-urls ("`mailto:`, `tel:`, `https://` and `http://`") ([docs](https://v2.tauri.app/plugin/opener/)) |
| clipboard-manager 2.5.0 | `tauri_plugin_clipboard_manager::init()` | none by default. Explicit: `clipboard-manager:allow-write-text\|allow-read-text\|allow-write-secret\|allow-write-html\|allow-write-image\|allow-read-image\|allow-clear` ([docs](https://v2.tauri.app/plugin/clipboard/)) |

### 4.1 single-instance
- Signature: `pub fn init<R: Runtime, F: FnMut(&AppHandle<R>, Vec<String>, String) + Send + Sync + 'static>(f: F) -> TauriPlugin<R>` ([docs.rs](https://docs.rs/tauri-plugin-single-instance/2.5.4/tauri_plugin_single_instance/fn.init.html)).
- Docs: "The Single Instance plugin must be the first one to be registered to work well." It requires Rust 1.90+.

### 4.2 global-shortcut (user-configurable, re-registered at runtime)
- **Builder** ([lib.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/global-shortcut-v2.4.1/plugins/global-shortcut/src/lib.rs)): `new()`, `with_shortcut(T) -> Result<Self>`, `with_shortcuts(S) -> Result<Self>`, `with_handler<F: Fn(&AppHandle<R>, &Shortcut, ShortcutEvent) + Send + Sync + 'static>`, `build() -> TauriPlugin<R>`.
- **`app.global_shortcut()`** ([docs.rs](https://docs.rs/tauri-plugin-global-shortcut/2.4.1/tauri_plugin_global_shortcut/struct.GlobalShortcut.html)): `register<S>(&self, shortcut: S) -> Result<(), Error> where S: TryInto<ShortcutWrapper>, S::Error: std::error::Error`, plus `on_shortcut(S, F)`, `unregister(S)`, `unregister_all()`, `is_registered(S) -> bool`.
- **Types** ([event](https://docs.rs/tauri-plugin-global-shortcut/2.4.1/tauri_plugin_global_shortcut/struct.ShortcutEvent.html), [Shortcut](https://docs.rs/tauri-plugin-global-shortcut/2.4.1/tauri_plugin_global_shortcut/struct.Shortcut.html)): `ShortcutEvent { pub id: u32, pub state: HotKeyState }` with `state()`; the crate re-exports `GlobalHotKeyEvent as ShortcutEvent, HotKeyState as ShortcutState`. `Shortcut { mods, key, id }` has `new(Option<Modifiers>, Code)`, `id()`, `FromStr`/`TryFrom<&str|String>`, `Display`, `PartialEq`, `Copy`.
- **Parser tokens**, case-insensitive ([global-hotkey 0.8.0](https://raw.githubusercontent.com/tauri-apps/global-hotkey/global-hotkey-v0.8.0/src/hotkey.rs)): `ALT|OPTION`, `CONTROL|CTRL`, `COMMAND|CMD|SUPER`, `SHIFT`. `COMMANDORCONTROL|CMDORCTRL|…` mean **Super on macOS** and Control elsewhere. Keys like `SPACE`, `R|KEYR`, `D|KEYD`.
- **Threading.** "On macOS, registering and unregistering shortcuts must happen on the main thread, so every [`GlobalShortcut`] operation dispatches to it and blocks until it completes." `run_main_thread!` calls `run_on_main_thread(task)?` then `rx.recv()?` with no on-main-thread check. Which thread runs the handlers: UNVERIFIED. **→ Register and unregister only from async commands or tasks, never inside a handler.**
- **Errors.** Plugin: `Error::{GlobalHotkey(String), RecvError, Tauri}` ([docs.rs](https://docs.rs/tauri-plugin-global-shortcut/2.4.1/tauri_plugin_global_shortcut/enum.Error.html)). global-hotkey: `AlreadyRegistered(HotKey)`, `FailedToRegister(String)` ([docs.rs](https://docs.rs/global-hotkey/0.8.0/global_hotkey/enum.Error.html)). JS d.ts: "If the shortcut is already taken by another application, the handler will not be triggered."

```rust
use std::{collections::HashMap, sync::Mutex};
use tauri::Manager;
use tauri_plugin_global_shortcut::{GlobalShortcutExt, Shortcut, ShortcutState};
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, serde::Serialize, serde::Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Action { ToggleCaptions, ToggleSaving, CopyLast2Min, Dictation }
#[derive(Default)] pub struct Bindings(pub Mutex<HashMap<Action, Shortcut>>);   // app.manage(Bindings::default()) in setup

pub fn shortcut_plugin<R: tauri::Runtime>() -> tauri::plugin::TauriPlugin<R> {
    tauri_plugin_global_shortcut::Builder::new()
        .with_handler(|app, shortcut, event| {
            if event.state() != ShortcutState::Pressed { return; }
            let action = app.state::<Bindings>().0.lock().unwrap().iter().find(|(_, s)| *s == shortcut).map(|(a, _)| *a);
            if let Some(a) = action { let app = app.clone(); tauri::async_runtime::spawn(async move { crate::actions::dispatch(app, a).await }); }
        })
        .build()
}
#[tauri::command]
async fn set_shortcut(app: tauri::AppHandle, action: Action, accelerator: String) -> Result<(), String> {
    let new: Shortcut = accelerator.parse().map_err(|e| format!("{e:?}"))?;      // e.g. "Ctrl+Shift+Space"
    let gs = app.global_shortcut();
    let old = app.state::<Bindings>().0.lock().unwrap().get(&action).copied();
    if old == Some(new) { return Ok(()); }
    gs.register(new).map_err(|e| e.to_string())?;         // new first: on failure the old binding survives
    if let Some(o) = old { let _ = gs.unregister(o); }
    app.state::<Bindings>().0.lock().unwrap().insert(action, new);              // + persist via store (§4.4)
    Ok(())
}
```

### 4.3 autostart, positioner
- **autostart** ([lib.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/autostart-v2.7.0/plugins/autostart/src/lib.rs)): `init(macos_launcher: MacosLauncher, args: Option<Vec<&'static str>>)` with `MacosLauncher::{LaunchAgent, AppleScript}`, or `Builder::new().args(..).arg(..).macos_launcher(..).app_name(..).build()`. `app.autolaunch() -> State<AutoLaunchManager>` offers `enable()`, `disable()`, `is_enabled() -> Result<bool>`. Check `std::env::args()` for `--autostart` and start hidden.
- **positioner:** `init()` and `#[cfg(feature = "tray-icon")] on_tray_event(app: &AppHandle<R>, event: &TrayIconEvent)` ([lib.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/positioner-v2.4.0/plugins/positioner/src/lib.rs)). `WindowExt::move_window(&self, Position) -> Result<()>` on `Window`/`WebviewWindow`, with `Position::{TopLeft, TopRight, BottomLeft, BottomRight, …, Tray*}`.
  - **Caveat:** `BottomRight` computes `y = screen_size.height - (window_size.height - screen_position.y)` from `current_monitor()`. That is the **full monitor size, not the work area**, so it can overlap the taskbar ([ext.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/positioner-v2.4.0/plugins/positioner/src/ext.rs)). Use work-area docking (§2.6).

### 4.4 store
- `StoreExt` ([docs.rs](https://docs.rs/tauri-plugin-store/2.5.0/tauri_plugin_store/trait.StoreExt.html)): `store(&self, path) -> Result<Arc<Store<R>>>`, `store_builder(path)`, `get_store(path) -> Option<Arc<Store<R>>>`.
- `Store` ([docs.rs](https://docs.rs/tauri-plugin-store/2.5.0/tauri_plugin_store/struct.Store.html)): `set(key: impl Into<String>, value: impl Into<JsonValue>)`, `get(key) -> Option<JsonValue>`, `delete -> bool`, `keys`, `entries`, `reload()`, `save() -> Result<()>`, `close_resource()`. `StoreBuilder` ([docs.rs](https://docs.rs/tauri-plugin-store/2.5.0/tauri_plugin_store/struct.StoreBuilder.html)): `default(k, v)`, `defaults(..)`, `auto_save(Duration)`, `disable_auto_save()`, `build()`.
- Files live in `app_data_dir`. Docs: "values must be serde_json::Value instances". JS `autoSave` defaults to a 100 ms debounce. Never put the device token here (§9).

### 4.5 updater (signature-verified)
- **Config** ([config.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/updater-v2.14.0/plugins/updater/src/config.rs)): `endpoints`, `pubkey` (required), `dangerousInsecureTransportProtocol` (false), `dangerousAcceptInvalidCerts`, `dangerousAcceptInvalidHostnames`, `requireSignedVersion` (false), `allowDowngrades`, `windows.installMode` (`passive` default = MSI `/passive`, NSIS `/P`; `basicUi`; `quiet` = NSIS `/S`), `windows.installerArgs`. A non-https endpoint without the dangerous flag fails deserialization in release (`Error::InsecureTransportProtocol`); debug builds only warn.
- **Verification** ([updater.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/updater-v2.14.0/plugins/updater/src/updater.rs)): `verify_signature` decodes the base64 pubkey and signature, then runs minisign `public_key.verify(data, &signature, true)?` and `verify_signed_version(..)`. Both `download()` (`self.verify(&buffer)?`) and `install()` (`self.verify(bytes)?`) call it. Docs: signing "cannot be disabled" ([docs](https://v2.tauri.app/plugin/updater/)).
- **Version binding.** CLI 2.12.0 writes the app version into the signature's trusted comment. `requireSignedVersion` rejects a manifest that pairs a version with another release (`node_modules/@tauri-apps/cli/CHANGELOG.md`, `tauri signer sign --help`).

```json
{ "bundle": { "createUpdaterArtifacts": true },
  "plugins": { "updater": { "pubkey": "<contents of the .pub file>",
      "endpoints": ["https://api.example.com/api/desktop/updates/{{target}}/{{arch}}/{{current_version}}"],
      "requireSignedVersion": true, "windows": { "installMode": "passive" } } } }
```

- **Endpoints and responses.** Variables: `{{current_version}}`, `{{target}}` (`linux|windows|darwin`), `{{arch}}` (`x86_64|i686|aarch64|armv7`), `{{bundle_type}}` (updater.rs). Return **204 No Content** when there's no update, otherwise 200 with `{version, pub_date, url, signature, notes}`. `signature` is "The content of the generated `.sig` file … A path or URL does not work!" ([docs source](https://raw.githubusercontent.com/tauri-apps/tauri-docs/v2/src/content/docs/plugin/updater.mdx)). Static `platforms` keys are tried as `{os}-{arch}-{installer}` (e.g. `windows-x86_64-nsis`), then `{os}-{arch}`.
- **Artifacts:** macOS `myapp.app.tar.gz` + `.sig`; Windows `myapp-setup.exe.sig` / `myapp.msi.sig`. `"v1Compatible"` produces zipped legacy bundles.
- **Keys** (local `tauri signer generate|sign --help`, CLI CHANGELOG 2.12.0): generate with `npm run tauri signer generate -- -w ~/.tauri/echoledger.key` (also `-p`, `--ci`). `TAURI_SIGNING_PRIVATE_KEY` is a string or path for `build`/`bundle` but must be the literal key for `signer sign`. Also `TAURI_SIGNING_PRIVATE_KEY_PATH` and `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`; the docs say `.env` files are unsupported.
- **Rust API** ([lib.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/updater-v2.14.0/plugins/updater/src/lib.rs)):
  - `UpdaterExt::{updater() -> Result<Updater>, updater_builder() -> UpdaterBuilder}`.
  - `UpdaterBuilder`: `.header(k, v)?`, `.timeout(..)`, `.target(..)`, `.on_before_exit(Fn())`, `.version_comparator(..)`, `.configure_client(Fn(reqwest::ClientBuilder) -> ClientBuilder)`. Its signature is `build(self) -> Result<Updater>` (corrected by verification: https://docs.rs/tauri-plugin-updater/2.14.0/tauri_plugin_updater/struct.UpdaterBuilder.html).
  - `Update::download_and_install(FnMut(usize, Option<u64>), FnOnce()) -> Result<()>`. Windows `install()` "exits the app after launching the updater installer" (JS d.ts).
- **TLS.** The `rustls-tls` default feature maps to `reqwest/rustls-no-provider` + `dep:rustls` ([Cargo.toml](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/updater-v2.14.0/plugins/updater/Cargo.toml)). In `check()`, if `CryptoProvider::get_default().is_none()` it calls `rustls::crypto::ring::default_provider().install_default()`.

```rust
use tauri_plugin_updater::UpdaterExt;
async fn check_update(app: tauri::AppHandle) -> tauri_plugin_updater::Result<()> {
    if let Some(update) = app.updater()?.check().await? {          // pattern from the official docs
        update.download_and_install(|_chunk, _total| {}, || {}).await?;
        app.restart();
    }
    Ok(())
}
```

### 4.6 clipboard-manager, notification, opener
- **Clipboard** ([desktop.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/clipboard-manager-v2.5.0/plugins/clipboard-manager/src/desktop.rs)):
  - `app.clipboard()` offers `write_text<'a, T: Into<Cow<'a, str>>>(&self, text: T) -> Result<()>`.
  - `write_secret(..)` is the "same as write_text but it will set hints using `arboard`'s `SetExt*` extensions so clipboard managers exclude the text from history".
  - `read_text() -> Result<String>` warns "This method should not be used on the main thread!"
- **Notification:** `app.notification().builder().title(..).body(..).show()?` ([docs.rs](https://docs.rs/tauri-plugin-notification/2.7.0/tauri_plugin_notification/struct.NotificationBuilder.html)). On Windows it "Only works for installed apps. Shows powershell name & icon in development." Use it rarely (spec rule #3).
- **Opener:** `app.opener().open_url(url, None::<&str>)`.

---

## 5. Capabilities and CSP

**Capabilities** ([docs](https://v2.tauri.app/security/capabilities/)):
- Files in `src-tauri/capabilities/` are "automatically enabled by default"; once `app.security.capabilities` lists identifiers, only those are used.
- Windows are matched by **label**, and globs like `"*"` work. `platforms` takes `linux|macOS|windows|iOS|android`.
- `remote.urls` grants IPC to remote origins. We never load remote content, so don't use it.
- "By default, all commands that you registered in your app … are allowed to be used by all the windows". To restrict them, use `tauri_build::AppManifest::new().commands(&[...])` in `build.rs`, which generates "`allow-$command` and `deny-$command` where $command is the command name in snake_case" ([docs.rs](https://docs.rs/tauri-build/2.7.4/tauri_build/struct.AppManifest.html)). How capabilities reference those: UNVERIFIED; check `gen/schemas` after the first build.

**Core permission sets:**
- `core:default` = `core:{app,event,image,menu,path,resources,tray,webview,window}:default` ([core permissions](https://v2.tauri.app/reference/acl/core-permissions/)).
- `core:window:default` is getters only (plus `allow-internal-toggle-maximize`), including `allow-cursor-position` (corrected by verification: https://v2.tauri.app/reference/acl/core-permissions/). Setters and `show`/`hide`/`start-dragging`/`set-ignore-cursor-events` each need a `core:window:allow-*` (`window.d.ts` remarks).

`src-tauri/capabilities/main.json`:

```json
{ "$schema": "../gen/schemas/desktop-schema.json", "identifier": "main", "windows": ["main"], "platforms": ["windows", "macOS"],
  "permissions": ["core:default", "opener:default", "store:default", "autostart:default", "updater:default", "notification:default"] }
```

`src-tauri/capabilities/overlay.json`:

```json
{ "$schema": "../gen/schemas/desktop-schema.json", "identifier": "overlay", "windows": ["overlay", "dictation"], "platforms": ["windows", "macOS"],
  "permissions": ["core:event:default", "core:window:default", "core:window:allow-start-dragging"] }
```

**CSP** ([CSP docs](https://v2.tauri.app/security/csp/), [config.rs](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri-utils/src/config.rs)):
- `csp` is "injected on all HTML files on the built application. If `devCsp` is not specified, this value is also injected on dev."
- Tauri "appends its nonces and hashes to the relevant CSP attributes automatically".
- The official example uses `"connect-src": "ipc: http://ipc.localhost"`.
- `freezePrototype` runs `Object.freeze(Object.prototype)`.

With all networking in Rust:

```json
"security": {
  "csp":    { "default-src": "'self'", "script-src": "'self'", "style-src": "'self' 'unsafe-inline'", "img-src": "'self' data: blob:",
              "connect-src": "ipc: http://ipc.localhost", "object-src": "'none'" },
  "devCsp": { "default-src": "'self'", "script-src": "'self'", "style-src": "'self' 'unsafe-inline'", "img-src": "'self' data: blob:",
              "connect-src": "ipc: http://ipc.localhost ws://localhost:1420 ws://localhost:1421", "object-src": "'none'" },
  "freezePrototype": true }
```

- The dev ports come from `vite.config.ts`: server 1420 (strictPort), and HMR 1421 when `TAURI_DEV_HOST` is set.
- If the webview ever connected directly, it would need `wss://streaming.assemblyai.com` (+ `.us.`/`.eu.`) ([AssemblyAI](https://www.assemblyai.com/docs/api-reference/streaming-api/streaming-api.md)) and `https://<api-host>`. Don't: that puts the token in JS and loses cert pinning.

---

## 6. IPC

Sources: [calling Rust](https://v2.tauri.app/develop/calling-rust/), [calling frontend](https://v2.tauri.app/develop/calling-frontend/), [state](https://v2.tauri.app/develop/state-management/), [Emitter](https://docs.rs/tauri/2.12.5/tauri/trait.Emitter.html), [Channel](https://docs.rs/tauri/2.12.5/tauri/ipc/struct.Channel.html), `core.d.ts`, `event.d.ts`.

- **Commands.** "Async commands can't take borrowed arguments such as `&str` or `State<'_, Data>`" unless they return `Result`. Errors must be `serde::Serialize`; the documented pattern is a `thiserror` enum plus `serializer.serialize_str(self.to_string().as_ref())`. JS argument keys are camelCase (`on_event` maps to `onEvent`). Only the last `invoke_handler` counts, so list every command in one `generate_handler![...]`.
- **State.** Register with `app.manage(T)`; read with `State<'_, Mutex<T>>` or `app_handle.state::<T>()`. "You don't need to use Arc for things stored in State". A wrong type gives "state not managed" at runtime, and `Manager::state()` panics. A std `Mutex` is fine unless held across `.await`.
- **Events.** Rust: `emit<S: Serialize + Clone>(&self, event, payload)`, `emit_to<I: Into<EventTarget>, S>(&self, target, event, payload)`, `emit_filter(.., F: Fn(&EventTarget) -> bool)`. JS: `listen<T>(event, handler, { target? })`, `emitTo(target, event, payload)`. Events are "not designed for low latency or high throughput"; payloads are JSON.
- **Channels** (use them for captions). `Channel<TSend>::send(&self, data: TSend) -> Result<()>` where `TSend: IpcResponse` (any `Serialize`). It is `Clone` and "automatically orders the messages". Dropping the last clone of a frontend-created channel unregisters its JS callback. 2.12.3: "Keep delivering `Channel` messages after the `onmessage` handler throws." 2.12.0: "Stop queuing large channel payloads once the target webview is closed."

```rust
#[derive(Clone, serde::Serialize)]
#[serde(rename_all = "camelCase", rename_all_fields = "camelCase", tag = "kind")]
pub enum CaptionEvent {
    Partial { speaker: String, text: String, start_ms: u64 },
    Final   { speaker: String, text: String, start_ms: u64, end_ms: u64 },
    Status  { connected: bool, recording: bool },
}
#[derive(Default)] pub struct CaptionSink(pub std::sync::Mutex<Option<tauri::ipc::Channel<CaptionEvent>>>);
#[tauri::command]
fn subscribe_captions(on_event: tauri::ipc::Channel<CaptionEvent>, sink: tauri::State<'_, CaptionSink>) {
    *sink.0.lock().unwrap() = Some(on_event);                  // replace on every (re)mount
}
// producer: if let Some(ch) = app.state::<CaptionSink>().0.lock().unwrap().as_ref() { let _ = ch.send(ev); }
```

```ts
import { Channel, invoke } from "@tauri-apps/api/core";
const ch = new Channel<CaptionEvent>((m) => dispatch(m));   // set the handler before invoking (d.ts note)
await invoke("subscribe_captions", { onEvent: ch });
```

---

## 7. Testing

### 7.1 Vitest + jsdom + RTL (UI only)

What the mocks do (from `node_modules/@tauri-apps/api/mocks.js`, `core.js`):

| API | Signature | Behaviour |
|---|---|---|
| `mockIPC` | `(cb: (cmd, payload?) => unknown, options?: { shouldMockEvents?: boolean })` | Installs `__TAURI_INTERNALS__.invoke`. `core.invoke` passes args through **unserialized**, so a `Channel` arrives as the object. `shouldMockEvents` (since 2.7.0) fakes `listen`/`emit`; "`emitTo` is currently **not** supported" |
| `mockWindows` | `(current, ...others)` | Sets `metadata.currentWindow.label`; it "only mocks the *presence* of windows" |
| `clearMocks` | `()` | Removes the injected internals |

- Mock callbacks use `window.crypto.getRandomValues`.
- `isTauri()` returns `!!globalThis.isTauri`, and **the mocks don't set it**.

```ts
// desktop/vitest.config.ts — a separate file "will override the configuration from vite.config.ts"
import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
export default defineConfig({ plugins: [react()],
  test: { environment: "jsdom", setupFiles: ["./src/test/setup.ts"], include: ["src/**/*.test.{ts,tsx}"] } });

// desktop/src/test/setup.ts
import "@testing-library/jest-dom/vitest";
import { afterEach } from "vitest";
import { cleanup } from "@testing-library/react";
import { clearMocks } from "@tauri-apps/api/mocks";
afterEach(() => { cleanup(); clearMocks(); });
```

```tsx
// desktop/src/overlay/CaptionOverlay.test.tsx
import { act, render, screen, waitFor } from "@testing-library/react";
import { expect, test } from "vitest";
import { mockIPC, mockWindows } from "@tauri-apps/api/mocks";
import type { Channel } from "@tauri-apps/api/core";
test("renders a caption pushed through the channel", async () => {
  mockWindows("overlay");
  let ch: Channel<CaptionEvent> | undefined;
  mockIPC((cmd, args) => { if (cmd === "subscribe_captions") ch = (args as { onEvent: Channel<CaptionEvent> }).onEvent; });
  render(<CaptionOverlay />);
  await waitFor(() => expect(ch).toBeDefined());
  act(() => ch!.onmessage({ kind: "partial", speaker: "Client", text: "hello", startMs: 0 }));
  expect(await screen.findByText("hello")).toBeInTheDocument();
});
```

To exercise the real ordering logic, call `window.__TAURI_INTERNALS__.runCallback(ch.id, { index: 0, message })`; the Channel callback expects `{index, message}` or `{index, end}`.

Sources: [mocking docs](https://v2.tauri.app/develop/tests/mocking/), [Vitest config](https://vitest.dev/config/), [environment](https://vitest.dev/config/environment).

### 7.2 Playwright against the Vite dev server
- In a browser, `__TAURI_INTERNALS__` is undefined, so `invoke` fails.
- Fix: when `import.meta.env.VITE_E2E === "1"`, import `@tauri-apps/api/mocks`, run `mockWindows(label)` + `mockIPC(fakeBackend, { shouldMockEvents: true })`, then render.
- Only `VITE_`-prefixed variables are exposed, and `vite --mode e2e` loads `.env.e2e` ([Vite](https://vite.dev/guide/env-and-mode)).

```ts
// desktop/playwright.config.ts
import { defineConfig } from "@playwright/test";
export default defineConfig({
  webServer: { command: "npm run dev -- --mode e2e", url: "http://localhost:1420", reuseExistingServer: !process.env.CI, timeout: 120_000 },
  use: { baseURL: "http://localhost:1420" } });
```

- `webServer` options: `command, url, reuseExistingServer, timeout, cwd, env, stdout, stderr, gracefulShutdown, wait` ([Playwright](https://playwright.dev/docs/test-webserver)).
- Real transparency, click-through and focus need MANUAL_QA. WebDriver works on Windows and Linux only: "macOS provides no desktop WebDriver client" ([tests](https://v2.tauri.app/develop/tests/)).

### 7.3 Rust
- Keep hit-testing, docking, the shortcut map, pins and channel mixing in pure, tauri-free modules.
- `tauri::test` needs feature `test` and is "unstable" ([module](https://docs.rs/tauri/2.12.5/tauri/test/index.html)). Functions ([mod.rs](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri/src/test/mod.rs)):
  - `mock_builder() -> Builder<MockRuntime>`
  - `mock_context<R, A: Assets<R>>(assets: A) -> Context<R>`, `noop_assets() -> NoopAsset`, `mock_app() -> App<MockRuntime>`
  - `INVOKE_KEY = "__invoke-key__"`
  - `get_ipc_response<W: AsRef<Webview<MockRuntime>>>(webview: &W, request: InvokeRequest) -> Result<InvokeResponseBody, Value>` ([docs.rs](https://docs.rs/tauri/2.12.5/tauri/test/fn.get_ipc_response.html))
  - `assert_ipc_response(..)`

```rust
#[cfg(test)] mod ipc_tests {
    #[test] fn set_hit_regions_is_wired() {
        // build_app<R: Runtime>(b: Builder<R>) -> App<R> uses .build(tauri::test::mock_context(tauri::test::noop_assets())) under cfg(test)
        let app = crate::build_app(tauri::test::mock_builder());
        let w = tauri::WebviewWindowBuilder::new(&app, "overlay", Default::default()).build().unwrap();
        let res = tauri::test::get_ipc_response(&w, tauri::webview::InvokeRequest {
            cmd: "overlay_set_hit_regions".into(), callback: tauri::ipc::CallbackFn(0), error: tauri::ipc::CallbackFn(1),
            url: if cfg!(windows) { "http://tauri.localhost" } else { "tauri://localhost" }.parse().unwrap(),
            body: tauri::ipc::InvokeBody::Json(serde_json::json!({ "regions": [] })),   // docs example uses InvokeBody::default()
            headers: Default::default(), invoke_key: tauri::test::INVOKE_KEY.to_string() });
        assert!(res.is_ok());
    }
}
```

- `InvokeBody` is `{ Json(serde_json::Value), Raw(Vec<u8>) }` and its `Default` is `Json(Null)` (corrected by verification: https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri/src/ipc/mod.rs). Whether `generate_context!()` needs `../dist` in tests is UNVERIFIED; `mock_context(noop_assets())` avoids the question.
- 2.12.0 fixed a `run_main_thread!` "deadlock when called on main thread with `MockRuntime`".

---

## 8. Networking from Rust

### 8.1 Crypto provider: install one first
- `ClientConfig::builder()` uses "the process-default `CryptoProvider`". It panics "if a `CryptoProvider` cannot be resolved using a combination of the crate features and process default" ([ClientConfig](https://docs.rs/rustls/0.23.46/rustls/client/struct.ClientConfig.html)). That panic note is documented on `builder_with_protocol_versions`, which `builder()` calls; with both providers compiled and no default installed, `from_crate_features()` returns `None` and the panic message is "Could not automatically determine the process-level CryptoProvider from Rustls crate features." (corrected by verification: https://raw.githubusercontent.com/rustls/rustls/v/0.23.46/rustls/src/crypto/mod.rs).
- `CryptoProvider::install_default(self) -> Result<(), Arc<Self>>` ([docs](https://docs.rs/rustls/0.23.46/rustls/crypto/struct.CryptoProvider.html)).
- The updater compiles ring (§4.5) and reqwest's default TLS is aws-lc-rs. **→ The first line of `run()` is `let _ = rustls::crypto::ring::default_provider().install_default();`**
- Windows build cost:
  - ring needs only "Build Tools for Visual Studio 2022 … 'Desktop development with C++'"; the crates.io package has precompiled asm ([BUILDING.md](https://raw.githubusercontent.com/briansmith/ring/main/BUILDING.md)).
  - aws-lc-rs needs NASM unless it uses prebuilt objects (rustls enables `aws-lc-rs/prebuilt-nasm`). CMake is "only required for FIPS builds on Windows" ([aws-lc-rs](https://aws.github.io/aws-lc-rs/requirements/windows.html)).

### 8.2 reqwest 0.13 with a preconfigured rustls config
- `ClientBuilder::tls_backend_preconfigured(self, tls: impl Any)` replaces the deprecated `use_preconfigured_tls`.
  - An unknown backend means "the `ClientBuilder` will error when calling `build`", and the TLS crate version "must stay in sync with reqwest" ([docs](https://docs.rs/reqwest/0.13.5/reqwest/struct.ClientBuilder.html)).
  - The source downcasts `Option<rustls::ClientConfig>`, and **does not set ALPN** for preconfigured configs ([client.rs](https://raw.githubusercontent.com/seanmonstar/reqwest/v0.13.5/src/async_impl/client.rs)).
- Other builder methods: `https_only`, `connect_timeout`, `timeout`, `tls_version_min`.
- Multipart: `Part::bytes(..)`, `Part::stream(..)`, `.file_name(..)`, `.mime_str(..)` ([Part](https://docs.rs/reqwest/0.13.5/reqwest/multipart/struct.Part.html)).

### 8.3 SPKI pinning (rustls 0.23.46)
Trait ([docs.rs](https://docs.rs/rustls/0.23.46/rustls/client/danger/trait.ServerCertVerifier.html)):
- `verify_server_cert(&self, end_entity: &CertificateDer<'_>, intermediates: &[CertificateDer<'_>], server_name: &ServerName<'_>, ocsp_response: &[u8], now: UnixTime) -> Result<ServerCertVerified, Error>`
- `verify_tls12_signature` / `verify_tls13_signature(&self, message: &[u8], cert: &CertificateDer<'_>, dss: &DigitallySignedStruct) -> Result<HandshakeSignatureValid, Error>`
- `supported_verify_schemes(&self) -> Vec<SignatureScheme>`
- Provided: `requires_raw_public_keys`, `root_hint_subjects`

Building blocks:
- `WebPkiServerVerifier::builder(Arc<RootCertStore>).build() -> Result<Arc<WebPkiServerVerifier>, VerifierBuilderError>` ([1](https://docs.rs/rustls/0.23.46/rustls/client/struct.WebPkiServerVerifier.html), [2](https://docs.rs/rustls/0.23.46/rustls/client/struct.ServerCertVerifierBuilder.html)).
- `ConfigBuilder::dangerous()` then `with_custom_certificate_verifier(Arc<dyn ServerCertVerifier>)` then `with_no_client_auth() -> ClientConfig` ([3](https://docs.rs/rustls/0.23.46/rustls/struct.ConfigBuilder.html), [4](https://docs.rs/rustls/0.23.46/rustls/client/danger/struct.DangerousClientConfigBuilder.html)).
- `ServerCertVerified::assertion()`, `CertificateError::ApplicationVerificationFailure`, and `RootCertStore { pub roots }` with `webpki_roots::TLS_SERVER_ROOTS.to_vec()`.
- `webpki::EndEntityCert::try_from(&CertificateDer)` then `.subject_public_key_info()`, which "**does** include the outer `SEQUENCE` tag and length" ([cert.rs](https://docs.rs/rustls-webpki/0.103.16/src/webpki/cert.rs.html)). The method is defined on `webpki::Cert` (feature `alloc`) and reached through `impl Deref for EndEntityCert<Target = Cert>` (corrected by verification: https://docs.rs/rustls-webpki/0.103.16/webpki/struct.EndEntityCert.html).
- `ring::digest::digest(&SHA256, data)`.

```rust
use std::sync::Arc;
use rustls::client::danger::{HandshakeSignatureValid, ServerCertVerified, ServerCertVerifier};
use rustls::client::WebPkiServerVerifier;
use rustls::pki_types::{CertificateDer, ServerName, UnixTime};
use rustls::{CertificateError, DigitallySignedStruct, Error as TlsError, RootCertStore, SignatureScheme};

#[derive(Debug)] pub struct SpkiPinVerifier { inner: Arc<WebPkiServerVerifier>, pins: Vec<[u8; 32]> }  // SHA-256(SPKI DER)
pub fn spki_sha256(der: &CertificateDer<'_>) -> Option<[u8; 32]> {
    let cert = webpki::EndEntityCert::try_from(der).ok()?;
    ring::digest::digest(&ring::digest::SHA256, cert.subject_public_key_info().as_ref()).as_ref().try_into().ok()
}
impl ServerCertVerifier for SpkiPinVerifier {
    fn verify_server_cert(&self, ee: &CertificateDer<'_>, im: &[CertificateDer<'_>], name: &ServerName<'_>, ocsp: &[u8], now: UnixTime)
        -> Result<ServerCertVerified, TlsError> {
        self.inner.verify_server_cert(ee, im, name, ocsp, now)?;                      // chain + hostname + expiry first
        let pinned = std::iter::once(ee).chain(im.iter()).filter_map(spki_sha256).any(|h| self.pins.contains(&h));
        if pinned { Ok(ServerCertVerified::assertion()) } else { Err(TlsError::InvalidCertificate(CertificateError::ApplicationVerificationFailure)) }
    }
    fn verify_tls12_signature(&self, m: &[u8], c: &CertificateDer<'_>, d: &DigitallySignedStruct) -> Result<HandshakeSignatureValid, TlsError> { self.inner.verify_tls12_signature(m, c, d) }
    fn verify_tls13_signature(&self, m: &[u8], c: &CertificateDer<'_>, d: &DigitallySignedStruct) -> Result<HandshakeSignatureValid, TlsError> { self.inner.verify_tls13_signature(m, c, d) }
    fn supported_verify_schemes(&self) -> Vec<SignatureScheme> { self.inner.supported_verify_schemes() }
}
pub fn pinned_api_client(pins: Vec<[u8; 32]>) -> Result<reqwest::Client, Box<dyn std::error::Error + Send + Sync>> {
    let roots = Arc::new(RootCertStore { roots: webpki_roots::TLS_SERVER_ROOTS.to_vec() });
    let verifier = Arc::new(SpkiPinVerifier { inner: WebPkiServerVerifier::builder(roots).build()?, pins });
    let mut cfg = rustls::ClientConfig::builder().dangerous().with_custom_certificate_verifier(verifier).with_no_client_auth();
    cfg.alpn_protocols = vec![b"h2".to_vec(), b"http/1.1".to_vec()];
    Ok(reqwest::Client::builder().tls_backend_preconfigured(cfg).https_only(true).connect_timeout(std::time::Duration::from_secs(10)).build()?)
}
```

### 8.4 WSS streaming (tokio-tungstenite 0.30 over rustls)
- `connect_async_tls_with_config<R: IntoClientRequest + Unpin>(request: R, config: Option<WebSocketConfig>, disable_nagle: bool, connector: Option<Connector>) -> Result<(WebSocketStream<MaybeTlsStream<TcpStream>>, Response), Error>` ([docs](https://docs.rs/tokio-tungstenite/0.30.0/tokio_tungstenite/fn.connect_async_tls_with_config.html)).
- `#[non_exhaustive] enum Connector { Plain, NativeTls(TlsConnector), Rustls(Arc<ClientConfig>) }` ([docs](https://docs.rs/tokio-tungstenite/0.30.0/tokio_tungstenite/enum.Connector.html)).
- `ClientRequestBuilder::new(Uri).with_header(k, v).with_sub_protocol(p)`, with `tungstenite::http` re-exported ([docs](https://docs.rs/tungstenite/0.30.0/tungstenite/client/struct.ClientRequestBuilder.html)).
- `Message::{Text(Utf8Bytes), Binary(Bytes), Ping, Pong, Close, Frame}` and `Message::binary(Into<Bytes>)` ([docs](https://docs.rs/tungstenite/0.30.0/tungstenite/protocol/enum.Message.html)); `Utf8Bytes::as_str()`.
- AssemblyAI streaming: `wss://streaming.assemblyai.com/v3/ws` (plus `.us.`/`.eu.` zones). The temp token goes in query param `token`, since "Browsers cannot set headers on a WebSocket connection". Tokens are **not** single-use: until `expires_in_seconds` (1–600 s) elapses, the same token can open more than one session, including concurrent ones, so keep the window short and mint one per session (corrected by verification: https://www.assemblyai.com/docs/streaming/authenticate-with-a-temporary-token.md) ([WS spec](https://www.assemblyai.com/docs/api-reference/streaming-api/streaming-api.md), [token](https://www.assemblyai.com/docs/streaming/authenticate-with-a-temporary-token.md)). Message protocol details are in `docs/research/stt-assemblyai.md`.

```rust
use std::sync::Arc;
use futures_util::{SinkExt, StreamExt};
use tokio_tungstenite::{connect_async_tls_with_config, Connector, tungstenite::{self, http::Uri, protocol::WebSocketConfig, ClientRequestBuilder, Message}};
type BoxErr = Box<dyn std::error::Error + Send + Sync>;
pub async fn run_stream(ws_url_with_token: &str, tls: Arc<rustls::ClientConfig>,
                        mut pcm: tokio::sync::mpsc::Receiver<Vec<u8>>, on_text: impl Fn(&str) + Send) -> Result<(), BoxErr> {
    let uri: Uri = ws_url_with_token.parse()?;                 // full URL (already encoded) comes from POST /api/stt/token
    let req = ClientRequestBuilder::new(uri).with_header("User-Agent", "EchoLedger-Desktop");
    let (ws, _resp) = connect_async_tls_with_config(req, Some(WebSocketConfig::default()), true, Some(Connector::Rustls(tls))).await?;
    let (mut tx, mut rx) = ws.split();
    let send = async move { while let Some(c) = pcm.recv().await { tx.send(Message::binary(c)).await?; } Ok::<(), tungstenite::Error>(()) };
    let recv = async move { while let Some(m) = rx.next().await { if let Message::Text(t) = m? { on_text(t.as_str()); } } Ok::<(), tungstenite::Error>(()) };
    tokio::try_join!(send, recv)?;
    Ok(())
}
// standard WebPKI (no pin) for the third-party STT host:
// Arc::new(rustls::ClientConfig::builder().with_root_certificates(RootCertStore { roots: webpki_roots::TLS_SERVER_ROOTS.to_vec() }).with_no_client_auth())
```

---

## 9. keyring (device token)

- **The latest is 4.2.0, not 3.x.** v4's default feature `v1` "behaves essentially the same as the v1 version of Keyring": Keychain Services on macOS, the Windows Credential Manager on Windows ([4.2.0](https://docs.rs/keyring/4.2.0/keyring/), [v1](https://docs.rs/keyring/4.2.0/keyring/v1/index.html)). `lib.rs` has `#[cfg(feature = "v1")] pub use v1::*;`, so `keyring::Entry` and `keyring::Error` work ([lib.rs](https://docs.rs/keyring/4.2.0/src/keyring/lib.rs.html)). `v1` enables the apple-keychain, windows-native and zbus stores ([features](https://docs.rs/crate/keyring/4.2.0/features)).
- **v4 `Entry`** ([docs.rs](https://docs.rs/keyring/4.2.0/keyring/v1/struct.Entry.html)): `new(service: &str, username: &str) -> Result<Self>`, `set_password(&self, &str) -> Result<()>`, `get_password(&self) -> Result<String>`, `delete_credential(&self) -> Result<()>`, `set_secret(&[u8])`, `get_secret() -> Result<Vec<u8>>`. `Error` (`#[non_exhaustive]`): `NoEntry`, `NoDefaultStore`, `PlatformFailure(..)`, `NoStorageAccess(..)`, `BadEncoding(..)`, `TooLong(..)`, `Invalid(..)`, `Ambiguous(..)` … ([docs.rs](https://docs.rs/keyring/4.2.0/keyring/v1/enum.Error.html)).
- **Choosing stores yourself:** use `keyring-core` 1.0.1 plus store crates, e.g. `keyring_core::set_default_store(windows_native_keyring_store::Store::new().unwrap())`. Windows store caveats ([docs](https://docs.rs/windows-native-keyring-store/latest/windows_native_keyring_store/)): a read right after a write on another thread may return `NoEntry`, and credentials default to Enterprise persistence.
- **3.6.3 fallback.** It has the same `Entry` methods, but "There are no default features in this crate". Without `features = ["apple-native", "windows-native"]` it uses the **mock store** ([3.6.3](https://docs.rs/keyring/3.6.3/keyring/), [features](https://docs.rs/crate/keyring/3.6.3/features)).

```rust
const SERVICE: &str = "com.echoledger.desktop"; const USER: &str = "device-token";
pub fn load_token() -> keyring::Result<Option<String>> {
    match keyring::Entry::new(SERVICE, USER)?.get_password() { Ok(t) => Ok(Some(t)), Err(keyring::Error::NoEntry) => Ok(None), Err(e) => Err(e) }
}
pub fn save_token(t: &str) -> keyring::Result<()> { keyring::Entry::new(SERVICE, USER)?.set_password(t) }
pub fn forget_token() -> keyring::Result<()> {
    match keyring::Entry::new(SERVICE, USER)?.delete_credential() { Ok(()) | Err(keyring::Error::NoEntry) => Ok(()), Err(e) => Err(e) }
}
```

Put this behind a `TokenStore` trait with an in-memory fake for tests. Load it once at startup into managed state; the token never crosses IPC.

---

## 10. Dictation text injection

**enigo 0.6.1** ([docs](https://docs.rs/enigo/latest/enigo/), [Keyboard](https://docs.rs/enigo/0.6.1/enigo/trait.Keyboard.html), [Settings](https://docs.rs/enigo/0.6.1/enigo/struct.Settings.html), [Key](https://docs.rs/enigo/0.6.1/enigo/enum.Key.html), [NewConError](https://docs.rs/enigo/0.6.1/enigo/enum.NewConError.html)):
- `Enigo::new(&Settings) -> Result<Self, NewConError>`, where `NewConError::{EstablishCon(&str), NoPermission, Reply, NoEmptyKeycodes}`. `Enigo` is `Send`, `!Sync` on the docs.rs build.
- `Keyboard` trait: `key(&mut self, Key, Direction) -> InputResult<()>` with `Direction::{Press, Release, Click}`; `raw(u16, Direction)`; `text(&mut self, &str) -> InputResult<()>`, which works "regardless of the current keyboard layout", rejects NUL bytes, and: "You cannot use this function for entering shortcuts".
- `Settings`: `open_prompt_to_get_permissions` (macOS, **default true**), `independent_of_keyboard_state` (macOS, default true), `release_keys_when_dropped` (true), `windows_dw_extra_info`, …
- `Key::Control`, `Key::Meta` ("windows", "super", "command"), `Key::Unicode(char)`. There is no `Key::V`.

**Platform internals:**
- **Windows** ([win_impl.rs](https://raw.githubusercontent.com/enigo-rs/enigo/v0.6.1/src/win/win_impl.rs)):
  - One `SendInput` of `KEYEVENTF_UNICODE` down/up events per UTF-16 unit. `\n` maps to the Return key; `\r` has a TODO.
  - Partial sends return `Simulate("not all input events were sent. they may have been blocked by UIPI")`.
  - UIPI blocks input to elevated (higher-integrity) apps ([Permissions.md](https://raw.githubusercontent.com/enigo-rs/enigo/v0.6.1/Permissions.md)).
- **macOS** ([macos_impl.rs](https://raw.githubusercontent.com/enigo-rs/enigo/v0.6.1/src/macos/macos_impl.rs)):
  - 20-char chunks, because `CGEventKeyboardSetUnicodeString` "truncates strings down to 20 characters". Modifiers are cleared.
  - `AXIsProcessTrustedWithOptions` decides `NoPermission`.

**Clipboard and paste alternative:**
- Flow: `write_secret(text)` (excluded from history, §4.6), then Ctrl/Cmd+V, then restore the previous **text** about 300 ms later.
- Read the previous clipboard off the main thread.
- Images or files already on the clipboard are lost.

```rust
use enigo::{Direction, Enigo, Key, Keyboard, Settings};
pub fn new_enigo() -> Result<Enigo, String> {
    Enigo::new(&Settings { open_prompt_to_get_permissions: false, ..Default::default() }).map_err(|e| format!("{e:?}"))
}
pub fn paste(enigo: &mut Enigo) -> enigo::InputResult<()> {
    let m = if cfg!(target_os = "macos") { Key::Meta } else { Key::Control };
    enigo.key(m, Direction::Press)?; enigo.key(Key::Unicode('v'), Direction::Click)?; enigo.key(m, Direction::Release)
}
```

---

## 11. Build requirements and bundling

**Windows** ([prereqs](https://v2.tauri.app/start/prerequisites/), [installer](https://v2.tauri.app/distribute/windows-installer/), [signing](https://v2.tauri.app/distribute/sign/windows/)):
- **Toolchain:** `winget install --id Rustlang.Rustup`, then `rustup default stable-msvc` (latest stable ≈ 1.99.1 per the [Rust blog redirect](https://blog.rust-lang.org/releases/latest/); MSRV 1.90). VS Build Tools: "check the 'Desktop development with C++' option". WebView2 is preinstalled on Windows 10 1803+ (v155 on this machine).
- **Installer:** MSI needs the VBSCRIPT optional feature and only builds on Windows (WiX v3), so ship **NSIS** (`tauri build --bundles nsis`). NSIS `installMode`: `currentUser` (default, no admin), `perMachine`, or `both`.
- `webviewInstallMode` options:

| Mode | Installer size impact |
|---|---|
| `downloadBootstrapper` (default) | ~0 MB |
| `embedBootstrapper` | +~1.8 MB |
| `offlineInstaller` | ~127 MB |
| `fixedRuntime` | ~180 MB |
| `skip` | none, but the app won't work without the runtime |

- New in 2.12.0 (CLI CHANGELOG): `bundle.windows.bundleVCRuntime` and `build.windows.staticVCRuntime`.
- Signing: `certificateThumbprint`, `digestAlgorithm`, `timestampUrl`, or a custom `signCommand` (Azure Key Vault/relic, Azure Artifact Signing). "Since 2024, an EV Certificate no longer gives your app an immediate reputation with Microsoft SmartScreen."

**macOS** ([bundle](https://v2.tauri.app/distribute/macos-application-bundle/), [signing](https://v2.tauri.app/distribute/sign/macos/)):
- Desktop-only builds need just the Xcode Command Line Tools (`xcode-select --install`).
- `src-tauri/Info.plist` "is merged with the values generated by the Tauri CLI":
  - `NSMicrophoneUsageDescription`: macOS 10.14+, "required if your app uses APIs that access the device's microphone" ([Apple](https://developer.apple.com/tutorials/data/documentation/bundleresources/information-property-list/nsmicrophoneusagedescription.json))
  - `NSAudioCaptureUsageDescription`: macOS **14.2**+, "why your app is requesting access to capture system audio" ([Apple](https://developer.apple.com/tutorials/data/documentation/bundleresources/information-property-list/nsaudiocaptureusagedescription.json))
- `AudioHardwareCreateProcessTap` was introduced in macOS 14.2 ([Apple](https://developer.apple.com/tutorials/data/documentation/coreaudio/audiohardwarecreateprocesstap(_:_:).json)). The spec and `desktop-audio-native.md` pick `minimumSystemVersion "14.4"`; the default is `10.13`.
- Hardened-runtime entitlement `com.apple.security.device.audio-input` ([Apple](https://developer.apple.com/tutorials/data/documentation/bundleresources/entitlements/com.apple.security.device.audio-input.json)), referenced via `bundle.macOS.entitlements`.
- Notarization requires a "Developer ID Application" certificate:
  - Signing env: `APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_SIGNING_IDENTITY`.
  - Notarization env: `APPLE_ID` + `APPLE_PASSWORD` + `APPLE_TEAM_ID`, or `APPLE_API_ISSUER` + `APPLE_API_KEY` + `APPLE_API_KEY_PATH`.
  - Ad-hoc signing: `"signingIdentity": "-"`.

Platform config files are merged automatically (`tauri build --help`).

`tauri.windows.conf.json`:

```json
{ "bundle": { "targets": ["nsis"], "windows": { "webviewInstallMode": { "type": "embedBootstrapper" }, "nsis": { "installMode": "currentUser" } } } }
```

`tauri.macos.conf.json`:

```json
{ "bundle": { "targets": ["app", "dmg"], "macOS": { "minimumSystemVersion": "14.4", "entitlements": "./Entitlements.plist" } } }
```

---

## Recommendations for EchoLedger

1. **Lockstep versions.** tauri 2.12.5, tauri-build 2.7.4. Plugin crates `=` the npm versions (gs 2.4.1, autostart 2.7.0, notification 2.7.0, positioner 2.4.0, store 2.5.0, updater 2.14.0, opener 2.7.2), plus clipboard-manager 2.5.0 and single-instance 2.5.4. Drop `^` in the npm plugin pins. Stay off 3.0 alphas.
2. **Fix identity first.** `identifier "com.echoledger.desktop"`, `productName "EchoLedger"`. The identifier drives the app-data dir, the keychain service and the single-instance identity.
3. **Windows.** `main` (library/settings) comes from config, is hidden at autostart, and hides on close. `overlay` and `dictation` are created in Rust (setup or async commands). One `index.html`; React picks the view by `getCurrentWebviewWindow().label`, with `React.lazy` per view.
4. **Never steal focus.** Build overlays visible with `focused(false)` and `focusable(false)`. Never `show()` or `set_focus()` them; hide by content plus click-through; re-create instead of re-showing. Also set `transparent`, `no_redirection_bitmap`, `decorations(false)`, `shadow(false)`, `always_on_top`, `skip_taskbar`, `visible_on_all_workspaces`, `accept_first_mouse(true)`. Make `content_protected` a setting, default on.
5. **Click-through.** A Rust poll at about 30 Hz (`AppHandle::cursor_position()` + `inner_position()`, both physical) toggles `set_ignore_cursor_events` only on transitions, and pauses while idle. React pushes hit regions in device px when layout changes. Unit-test hit-testing and docking, including negative coordinates and mixed DPI.
6. **Docking.** Use `Monitor::work_area()`, not positioner `BottomRight`. Persist the corner and offset after `WindowEvent::Moved` settles (debounce).
7. **Tray and keep-alive.** `CloseRequested` on `main` calls `prevent_close()` + `hide()`. `ExitRequested` with `code == None` calls `prevent_exit()`. Quit calls `app.exit(0)`. macOS uses `ActivationPolicy::Accessory`. Register single-instance first; it re-shows `main`.
8. **Shortcuts.** One `with_handler` plus a `Bindings` map; the handler only spawns. Re-register from an async command, new binding before dropping the old. Persist accelerators in `settings.json` and surface "taken by another app" errors in Settings.
9. **IPC.** `Channel<CaptionEvent>` for captions and dictation partials, `emit_to` for low-rate status. Separate capability files for main and overlay; restrict app commands with `AppManifest::commands`.
10. **All network I/O in Rust.** CSP `connect-src ipc: http://ipc.localhost` only. Install the ring provider first. API traffic goes through `pinned_api_client` with a pin *set* (current key plus backup key). The STT WebSocket uses plain WebPKI. The device token stays in Rust.
11. **Updater.** `createUpdaterArtifacts: true`, `requireSignedVersion: true`, HTTPS endpoint on the Laravel host returning 204 or JSON. The key lives in CI secrets. `on_before_exit` finalizes recordings; never auto-install while recording.
12. **Dictation.** `enigo.text()` types *final* turns only, after the shortcut keys are released. Fall back to `write_secret` + paste + restore. On macOS create Enigo with `open_prompt_to_get_permissions: false` and show our own one-time explainer.
13. **Keychain.** keyring 4.2.0 (`v1` API) behind a `TokenStore` trait. On 3.6.3 the `apple-native` + `windows-native` features are mandatory.
14. **Tests.** Vitest + jsdom + RTL with `mockIPC`/`mockWindows`/`clearMocks`; Playwright on `vite --mode e2e` with mocks injected; Rust pure modules plus `mock_builder()` + `get_ipc_response`.
15. **Build machine.** Install rustup stable-msvc and VS Build Tools ("Desktop development with C++") before Phase 2. Ship NSIS on Windows and `app` + `dmg` on macOS via the platform config files.

## Gotchas

- **`show()` focuses windows built with `focused(false)` on Windows** (tao 0.37.1). Only creation is guaranteed `SW_SHOWNOACTIVATE`. Whether `WS_EX_NOACTIVATE` blocks `SW_SHOW` activation is UNVERIFIED.
- **Creating a window in a sync command or event handler deadlocks on Windows.**
- **`set_ignore_cursor_events(true)` blinds the webview.** Hover must come from the Rust poll. [#11461](https://github.com/tauri-apps/tauri/issues/11461) reported it not working on Windows 10; it was closed as not planned with no repro. Covered in MANUAL_QA.
- **Undecorated + default `shadow(true)` gives a 1px white border on Windows.**
- **Transparent windows also need transparent CSS** on `html, body`, plus `no_redirection_bitmap(true)` against the white flash.
- **Workspaces.** `visible_on_all_workspaces` is unsupported on Windows (virtual desktops UNVERIFIED). macOS fullscreen Spaces are UNVERIFIED.
- **Older Windows.** Before Windows 10 2004, `WDA_EXCLUDEFROMCAPTURE` acts like `WDA_MONITOR`: the window shows with no content in captures.
- **Positioner `BottomRight` overlaps the taskbar** (it uses the full monitor size).
- **No default crypto provider plus both ring and aws-lc-rs compiled means `ClientConfig::builder()` panics.** The updater installs ring only at its first `check()`.
- **Preconfigured TLS loses ALPN, and the rustls versions must unify** or reqwest's `build()` errors.
- **Tauri's tokio lacks `time`.** Enable it yourself.
- **Global-shortcut register/unregister block on main-thread dispatch.** Never call them from handlers.
- **Shortcut collisions** (UNVERIFIED list): Ctrl+Shift+C is DevTools inspect and Ctrl+Shift+R is hard reload in Chromium browsers. Global registration steals them system-wide, so make them configurable from day 1.
- **`CommandOrControl` is Cmd on macOS.** The spec says Ctrl.
- **Typing while Ctrl+Shift is still held** may combine modifiers on Windows (`independent_of_keyboard_state` is macOS-only). Wait for `Released`.
- **Prompts.** enigo prompts for macOS permission by default. Keychain prompts for unsigned dev builds are UNVERIFIED.
- **`isTauri()` is false under `mockIPC`**, and the mocks don't support `emitTo`.
- **Windows notifications only work for installed apps.**
- **`csp` is also injected in dev unless `devCsp` is set.** Allow `ws://localhost:1420` or Vite HMR breaks.
- **keyring 3.x without platform features uses the mock store.**
- **Updater `signature` must be the `.sig` contents.** Non-HTTPS endpoints fail in release.
- **Windows `install()` exits the app.**

## Load-bearing claims

| # | Claim | Source | Confidence |
|---|---|---|---|
| 1 | Latest: tauri 2.12.5, tauri-build 2.7.4. Plugin crates equal the installed npm versions (gs 2.4.1, autostart 2.7.0, notification 2.7.0, positioner 2.4.0, store 2.5.0, updater 2.14.0, opener 2.7.2), plus single-instance 2.5.4 and clipboard 2.5.0. Plugin npm and crate must match exactly | [crates.io](https://crates.io/api/v1/crates/tauri), [updating deps](https://v2.tauri.app/develop/updating-dependencies/) | high |
| 2 | `macOSPrivateApi` (config and feature) is a no-op and not required since tauri 2.12.1 | [config.rs](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri-utils/src/config.rs), [CHANGELOG](https://raw.githubusercontent.com/tauri-apps/tauri/tauri-v2.12.5/crates/tauri/CHANGELOG.md) | high |
| 3 | `WebviewWindowBuilder` has `transparent`, `decorations`, `always_on_top`, `skip_taskbar`, `focused` (sets window + webview), `focusable`, `shadow`, `visible_on_all_workspaces`, `content_protected`, `accept_first_mouse`, `no_redirection_bitmap` | [docs.rs](https://docs.rs/tauri/2.12.5/tauri/webview/struct.WebviewWindowBuilder.html) | high |
| 4 | `set_ignore_cursor_events(bool)` exists. `AppHandle`/`Window::cursor_position()` returns physical `PhysicalPosition<f64>` from the desktop origin. `inner_position` is `PhysicalPosition<i32>`. `Monitor::work_area()` returns `&PhysicalRect<i32,u32>` | [AppHandle](https://docs.rs/tauri/2.12.5/tauri/struct.AppHandle.html), [Window](https://docs.rs/tauri/2.12.5/tauri/window/struct.Window.html), [Monitor](https://docs.rs/tauri/2.12.5/tauri/window/struct.Monitor.html) | high |
| 5 | Windows: `focusable(false)` gives `WS_EX_NOACTIVATE`. Creation with `focused(false)` uses `SW_SHOWNOACTIVATE`. Since tao 0.37.1 a later `set_visible(true)` focuses the window | [window_state.rs](https://raw.githubusercontent.com/tauri-apps/tao/dev/src/platform_impl/windows/window_state.rs), [tao 0.37.1](https://v2.tauri.app/release/tao/v0.37.1/) | high (source) / medium (runtime) |
| 6 | Keep-alive: `CloseRequested { api }` with `prevent_close()`. `ExitRequested { code, api }` with `prevent_exit()`, where `code` is `None` for user-initiated exits | [WindowEvent](https://docs.rs/tauri/2.12.5/tauri/enum.WindowEvent.html), [RunEvent](https://docs.rs/tauri/2.12.5/tauri/enum.RunEvent.html) | high |
| 7 | Global shortcut: `Builder::new().with_handler(Fn(&AppHandle,&Shortcut,ShortcutEvent))`. register/unregister dispatch to the main thread and block. Shortcuts parse via `FromStr`. `CommandOrControl` means Super on macOS | [lib.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/global-shortcut-v2.4.1/plugins/global-shortcut/src/lib.rs), [hotkey.rs](https://raw.githubusercontent.com/tauri-apps/global-hotkey/global-hotkey-v0.8.0/src/hotkey.rs) | high |
| 8 | Updater: minisign verified on download *and* install (cannot be disabled). HTTPS enforced in release. `requireSignedVersion` binds the version. Keys come from `TAURI_SIGNING_PRIVATE_KEY[_PATH]` + `_PASSWORD` | [updater.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/updater-v2.14.0/plugins/updater/src/updater.rs), [config.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/updater-v2.14.0/plugins/updater/src/config.rs), `tauri signer sign --help` | high |
| 9 | Pinning: rustls 0.23.46 `ServerCertVerifier` has 4 required methods. Delegate to `WebPkiServerVerifier`, then compare SHA-256 of `subject_public_key_info()` (full SPKI DER). Install via `dangerous()` plus reqwest `tls_backend_preconfigured(ClientConfig)`, setting ALPN yourself | [trait](https://docs.rs/rustls/0.23.46/rustls/client/danger/trait.ServerCertVerifier.html), [cert.rs](https://docs.rs/rustls-webpki/0.103.16/src/webpki/cert.rs.html), [client.rs](https://raw.githubusercontent.com/seanmonstar/reqwest/v0.13.5/src/async_impl/client.rs) | high |
| 10 | The updater installs ring as the default provider only if none is set, and reqwest's default TLS uses aws-lc-rs. Install a provider at startup | [updater.rs](https://raw.githubusercontent.com/tauri-apps/plugins-workspace/updater-v2.14.0/plugins/updater/src/updater.rs), [reqwest Cargo.toml](https://raw.githubusercontent.com/seanmonstar/reqwest/v0.13.5/Cargo.toml), [ClientConfig](https://docs.rs/rustls/0.23.46/rustls/client/struct.ClientConfig.html) | high |
| 11 | tokio-tungstenite 0.30 `connect_async_tls_with_config(req, Option<WebSocketConfig>, bool, Option<Connector>)` with `Connector::Rustls(Arc<ClientConfig>)`. AssemblyAI streaming: `wss://streaming.assemblyai.com/v3/ws?…&token=` | [connect](https://docs.rs/tokio-tungstenite/0.30.0/tokio_tungstenite/fn.connect_async_tls_with_config.html), [AssemblyAI](https://www.assemblyai.com/docs/api-reference/streaming-api/streaming-api.md) | high |
| 12 | keyring latest is 4.2.0, where `keyring::Entry` comes from the default `v1` feature. 3.6.3 needs `apple-native`/`windows-native` or falls back to the mock store | [lib.rs](https://docs.rs/keyring/4.2.0/src/keyring/lib.rs.html), [3.6.3](https://docs.rs/keyring/3.6.3/keyring/) | high |

## Open questions

1. Should `content_protected` default to on? It hides captions from screen share, but the user may want to share them.
2. On macOS, should the defaults be Ctrl+Shift+… or `CommandOrControl+Shift+…`? Which defaults avoid Chromium's Ctrl+Shift+C/R?
3. Where are update artifacts hosted: on the Laravel host (pinnable via `configure_client` + `tls_backend_preconfigured`, a combination not tested end-to-end) or on GitHub Releases (minisign plus `requireSignedVersion` only)?
4. Pin policy: does the Let's Encrypt leaf key rotate (`--reuse-key` or a backup pin)? How is the pin set delivered or rotated?
5. Does the create-once overlay really never steal focus on Windows 10/11? Is the `SW_SHOWNA` fallback needed (MANUAL_QA)?
6. Must the overlay show over fullscreen Zoom/Meet on macOS? If so, use `tauri-nspanel` or custom `objc2` collection-behaviour code (not researched in depth).
7. Does keyring 4.2.0 (`v1` API) on signed macOS builds stay free of keychain prompts? Confirm in QA.
8. Dictation: type final turns only (recommended), or also partials with backspace correction?
9. Does `cargo test` with `generate_context!()` need `../dist`? Unverified; `mock_context(noop_assets())` sidesteps it.
10. What exact capability identifiers do `AppManifest::commands` permissions get? Check after the first build.
