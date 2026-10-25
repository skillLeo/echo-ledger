# Desktop native audio: WASAPI process loopback, Core Audio taps, mic-in-use detection, encoding

Research note for `desktop/src-tauri` (Tauri 2). Researched 2026-10-24.

- Every API fact below comes from a primary source opened during research, with the link next to it.
- **[OBS]** means I saw it read-only on the research machine (Windows 11 26H2, build 26300, from `HKLM\...\CurrentVersion`, `Get-AppxPackage`, and the registry).
- **UNVERIFIED** means it could not be confirmed. The reason is given each time.
- **No Rust toolchain is installed here, so none of the Rust snippets were compiled.** I checked their signatures against docs.rs, windows-docs-rs, or crate source. Run `cargo check` on both targets before you rely on them.

---

## 0. The decisions on one screen

1. **Windows, build ≥ 20348:** use process loopback in **EXCLUDE** mode with our own PID, through `wasapi` 0.26.0:
   - `AudioClient::new_application_loopback_client(std::process::id(), false)`.
   - `false` selects `PROCESS_LOOPBACK_MODE_EXCLUDE_TARGET_PROCESS_TREE`. That is what the source does, even though the docstring says otherwise (§2.2).
   - Process loopback is not tied to an endpoint, so there is no render device to follow.
2. **Windows, build < 20348:** fall back to endpoint loopback on the default render device.
   - This covers **every** Windows 10 client release, because the highest is 22H2 = 19045 ([release info](https://learn.microsoft.com/en-us/windows/release-health/release-information)).
   - Fill silence gaps from QPC timestamps.
   - Reopen the stream when the default render device changes.
3. **Windows formats:** ask WASAPI for **16 kHz mono f32** on both channels, with `autoconvert` on. The engine then does the resampling and downmix, using `AUTOCONVERTPCM|SRC_DEFAULT_QUALITY` (§2.3).
4. **macOS, ≥ 14.2 (Apple's API floor); ship with `minimumSystemVersion` 14.4 as the spec says:**
   - Create a private **global stereo tap that excludes our own process**.
   - Put it in a private aggregate device: main sub-device = default output; sub-devices = output + default input, with drift compensation; taps = the tap, with drift compensation; `tapautostart` = true.
   - One IOProc then delivers mic and system audio on the same clock.
   - Bindings: `objc2-core-audio` 0.3.2.
5. **Mic-in-use detection, Windows:** enumerate WASAPI capture sessions on **all** active capture endpoints. This is documented and gives PIDs. The CapabilityAccessManager ConsentStore is a secondary signal only, because it is undocumented.
6. **Mic-in-use detection, macOS:** listen to `kAudioDevicePropertyDeviceIsRunningSomewhere`, then poll process objects for `IsRunningInput` and `BundleID`.
7. **Upload format:** Ogg Opus.
   - Two **uncoupled mono streams** (mapping family 1, N=2, M=0): stream 0 = mic = left, stream 1 = system = right.
   - 16 kHz input, 32 kbit/s per stream, VBR, 20 ms frames, `Voip` + `Voice`.
   - That is about 29 MB per hour.
   - Fallback: FLAC, 16 kHz, 16-bit stereo, using `flacenc` (pure Rust).
   - Keep `mic.wav` and `system.wav` locally for QA.
8. **Alignment:**
   - Place every packet on one shared clock: QPC in 100 ns units on Windows, `mHostTime` on macOS.
   - Zero-fill gaps and drop overlaps.
   - Measure drift and correct it with `rubato`.
   - When recording stops, pad the shorter channel so both have the same length.

---

## 1. Versions verified today

| Item | Version, date | Source |
|---|---|---|
| `wasapi` (HEnquist) | **0.26.0** (2026-10-11), MSRV 1.85, MIT. Depends on `windows` 0.62.2 and `windows-core` 0.62.2 | [crates.io API](https://crates.io/api/v1/crates/wasapi), [Cargo.toml](https://raw.githubusercontent.com/HEnquist/wasapi-rs/master/Cargo.toml) |
| `windows` | **0.62.2** (2025-10-06) is still the newest published version. MSRV 1.82 | [crates.io API](https://crates.io/api/v1/crates/windows) |
| `windows-core` | 0.100.0 exists (2026-09-03), but `windows` 0.62.2 pins **0.62.2**. Release 74 renamed `Ref`→`InRef` and `Generic`→`Ref` | [crates.io API](https://crates.io/api/v1/crates/windows-core), [release 74](https://github.com/microsoft/windows-rs/releases/tag/74) |
| windows-rs `master` | Cargo.toml says **0.100.0**, with lowercase header-style features. **Do not** take a git dependency on master | [master Cargo.toml](https://raw.githubusercontent.com/microsoft/windows-rs/master/crates/libs/windows/Cargo.toml) |
| `objc2-core-audio` / `-types` | **0.3.2** (2025-10-04), MSRV 1.71, `Zlib OR Apache-2.0 OR MIT` | [crates.io](https://crates.io/api/v1/crates/objc2-core-audio), [Cargo.toml](https://docs.rs/crate/objc2-core-audio/0.3.2/source/Cargo.toml) |
| `objc2` / `block2` / `dispatch2` | 0.6.5 / 0.6.2 / 0.3.1 | [crates.io](https://crates.io/api/v1/crates/objc2), [block2](https://docs.rs/block2/latest/block2/struct.RcBlock.html), [dispatch2](https://docs.rs/dispatch2/latest/dispatch2/struct.DispatchQueue.html) |
| `cidre` | 0.29.0 (2026-09-10), MIT, MSRV 1.88. docs.rs builds aarch64 targets only | [crates.io](https://crates.io/api/v1/crates/cidre), [Cargo.toml](https://docs.rs/crate/cidre/0.29.0/source/Cargo.toml) |
| `coreaudio-sys` | 0.2.18 (2026-06-04). Bindgen at build time; docs.rs build failed; "maintenance mode" | [crates.io](https://crates.io/api/v1/crates/coreaudio-sys), [docs.rs](https://docs.rs/coreaudio-sys/0.2.18/coreaudio_sys/) |
| `cpal` | **0.18.2** (2026-08-16), MSRV 1.85 | [crates.io](https://crates.io/api/v1/crates/cpal) |
| `hound` | 3.5.1 (2023-09-25), Apache-2.0 | [crates.io](https://crates.io/api/v1/crates/hound) |
| `rubato` | **5.0.1** (2026-10-01), MSRV 1.87, MIT/Apache-2.0. Major versions 2→5 all shipped in 2026 | [crates.io](https://crates.io/api/v1/crates/rubato) |
| `opus` | **0.4.0** (2026-08-23). Now uses `opusic-sys` 0.7.3, not `audiopus_sys` | [Cargo.toml](https://docs.rs/crate/opus/0.4.0/source/Cargo.toml) |
| `opusic-sys` | 0.7.3, BSD-3. Bundles libopus **1.6.1** and needs **cmake** with the default `bundled` feature | [README](https://docs.rs/crate/opusic-sys/0.7.3/source/README.md), [Cargo.toml](https://docs.rs/crate/opusic-sys/0.7.3/source/Cargo.toml) |
| `audiopus_sys` | 0.2.2 (2021). Stale; no longer used by `opus` | [crates.io](https://crates.io/api/v1/crates/audiopus_sys) |
| `ogg` | 0.9.2 (2025-01-12), BSD-3, pure Rust | [crates.io](https://crates.io/api/v1/crates/ogg) |
| `flacenc` | 0.5.1 (2025-12-18), Apache-2.0, pure Rust | [crates.io](https://crates.io/api/v1/crates/flacenc) |

---

## 2. Windows

### 2.1 OS gate: `RtlGetVersion`

- **Use `RtlGetVersion`, not `GetVersionEx`.** Apps that are "not manifested for Windows 8.1 or Windows 10 will return the Windows 8 OS version value (6.2)" ([GetVersionExW](https://learn.microsoft.com/en-us/windows/win32/api/sysinfoapi/nf-sysinfoapi-getversionexw)).
- `RtlGetVersion` is exported from **Ntdll.dll**. You set `dwOSVersionInfoSize`, and it returns `STATUS_SUCCESS` ([devnotes](https://learn.microsoft.com/en-us/windows/win32/devnotes/rtlgetversion), [WDK](https://learn.microsoft.com/en-us/windows-hardware/drivers/ddi/wdm/nf-wdm-rtlgetversion)).
- windows 0.62.2 signature: `windows::Wdk::System::SystemServices::RtlGetVersion(lpversioninformation: *mut OSVERSIONINFOW) -> NTSTATUS` ([docs](https://microsoft.github.io/windows-docs-rs/doc/windows/Wdk/System/SystemServices/fn.RtlGetVersion.html)).
- `OSVERSIONINFOW` lives in `windows::Win32::System::SystemInformation`. Fields: `dwOSVersionInfoSize`, `dwMajorVersion`, `dwMinorVersion`, `dwBuildNumber`, `dwPlatformId` (all `u32`), and `szCSDVersion: [u16;128]`. It derives `Default` ([docs](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/System/SystemInformation/struct.OSVERSIONINFOW.html)).
- `NTSTATUS` has `is_ok()` and `ok()` ([docs](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Foundation/struct.NTSTATUS.html)).
- Features:
  - `Wdk_System_SystemServices` (→ `Wdk_System` → `Wdk` → `Win32_Foundation`) and `Win32_System_SystemInformation` both exist in 0.62.2 ([Cargo.toml](https://docs.rs/crate/windows/0.62.2/source/Cargo.toml)).
  - The item's `#[cfg]` line could not be checked to confirm that exactly these two gate the function, so treat that as medium confidence.

```rust
#[cfg(windows)]
pub fn os_build() -> Option<u32> {
    use windows::Wdk::System::SystemServices::RtlGetVersion;
    use windows::Win32::System::SystemInformation::OSVERSIONINFOW;
    let mut v = OSVERSIONINFOW { dwOSVersionInfoSize: std::mem::size_of::<OSVERSIONINFOW>() as u32, ..Default::default() };
    if unsafe { RtlGetVersion(&mut v) }.is_ok() { Some(v.dwBuildNumber) } else { None }
}
/// Pure and unit-tested. 20348 per the struct docs and the sample README (see the discrepancy note).
pub const fn supports_process_loopback(build: u32) -> bool { build >= 20348 }
```

**Build-number discrepancy:**
- The `ActivateAudioInterfaceAsync` page says "Starting with Windows 10 Build **20438**" ([doc](https://learn.microsoft.com/en-us/windows/win32/api/mmdeviceapi/nf-mmdeviceapi-activateaudiointerfaceasync)).
- `AUDIOCLIENT_ACTIVATION_PARAMS`, `AUDIOCLIENT_PROCESS_LOOPBACK_PARAMS`, `PROCESS_LOOPBACK_MODE` and `AUDIOCLIENT_ACTIVATION_TYPE` all say "Windows 10 Build **20348**" ([1](https://learn.microsoft.com/en-us/windows/win32/api/audioclientactivationparams/ns-audioclientactivationparams-audioclient_activation_params), [2](https://learn.microsoft.com/en-us/windows/win32/api/audioclientactivationparams/ns-audioclientactivationparams-audioclient_process_loopback_params), [3](https://learn.microsoft.com/en-us/windows/win32/api/audioclientactivationparams/ne-audioclientactivationparams-process_loopback_mode)). So does the sample README ([README](https://raw.githubusercontent.com/microsoft/Windows-classic-samples/main/Samples/ApplicationLoopback/README.md)).
- Use 20348. This is moot for real clients:
  - Windows 10 tops out at 19045.
  - Windows 11 starts at 22000 (21H2): 22621, 22631, 26100, 26200, 28000 (26H1), 26300 (26H2) ([Win11 release info](https://learn.microsoft.com/en-us/windows/release-health/windows11-release-information)).
  - 20348 itself is Windows Server 2022.
- Also apply **feature-test fallback**: if process-loopback activation fails for any reason, drop to endpoint loopback. Microsoft recommends testing for features rather than versions ([GetVersionExW remarks](https://learn.microsoft.com/en-us/windows/win32/api/sysinfoapi/nf-sysinfoapi-getversionexw)).

### 2.2 Process loopback (`ActivateAudioInterfaceAsync` + `VIRTUAL_AUDIO_DEVICE_PROCESS_LOOPBACK`)

From [ActivateAudioInterfaceAsync](https://learn.microsoft.com/en-us/windows/win32/api/mmdeviceapi/nf-mmdeviceapi-activateaudiointerfaceasync):

- C signature: `HRESULT ActivateAudioInterfaceAsync(LPCWSTR deviceInterfacePath, REFIID riid, PROPVARIANT *activationParams, IActivateAudioInterfaceCompletionHandler *completionHandler, IActivateAudioInterfaceAsyncOperation **activationOperation)`.
- It lives in `Mmdevapi.dll`, and the function itself has existed since Windows 8.
- For process loopback, pass `VIRTUAL_AUDIO_DEVICE_PROCESS_LOOPBACK` as the path.
- Windows calls the completion handler "from a worker thread in the COM Multi-threaded Apartment (MTA)".
- The completion object must stay alive until the callback has run.
- Before Windows 10, the handler had to be agile (`IAgileObject`), or the call returned `E_ILLEGAL_METHOD_CALL`.

Rust signature in windows 0.62.2 ([docs](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Media/Audio/fn.ActivateAudioInterfaceAsync.html)):

```rust
pub unsafe fn ActivateAudioInterfaceAsync<P0: Param<PCWSTR>, P3: Param<IActivateAudioInterfaceCompletionHandler>>(
    deviceinterfacepath: P0, riid: *const GUID, activationparams: Option<*const PROPVARIANT>, completionhandler: P3,
) -> Result<IActivateAudioInterfaceAsyncOperation>
```

- `VIRTUAL_AUDIO_DEVICE_PROCESS_LOOPBACK: PCWSTR` ([docs](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Media/Audio/constant.VIRTUAL_AUDIO_DEVICE_PROCESS_LOOPBACK.html)).
- `IActivateAudioInterfaceAsyncOperation::GetActivateResult(&self, activateresult: *mut HRESULT, activatedinterface: *mut Option<IUnknown>) -> Result<()>` ([docs](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Media/Audio/struct.IActivateAudioInterfaceAsyncOperation.html)).
- Handler trait: `IActivateAudioInterfaceCompletionHandler_Impl::ActivateCompleted(&self, activateoperation: Ref<'_, IActivateAudioInterfaceAsyncOperation>) -> Result<()>` ([docs](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Media/Audio/trait.IActivateAudioInterfaceCompletionHandler_Impl.html)).
- `#[implement]` objects are agile by default. In windows-implement 0.60.2, the `agile` attribute "Determines whether `IAgileObject` and `IMarshal` are implemented automatically" and parses to `agile: true` by default ([src](https://docs.rs/crate/windows-implement/0.60.2/source/src/lib.rs)). windows-core 0.62.2 depends on windows-implement 0.60.2 ([Cargo.toml](https://docs.rs/crate/windows-core/0.62.2/source/Cargo.toml)).

**Activation structs** (all "Minimum supported client: Windows 10 Build 20348"):

```c
typedef struct AUDIOCLIENT_ACTIVATION_PARAMS { AUDIOCLIENT_ACTIVATION_TYPE ActivationType;
  union { AUDIOCLIENT_PROCESS_LOOPBACK_PARAMS ProcessLoopbackParams; } DUMMYUNIONNAME; } AUDIOCLIENT_ACTIVATION_PARAMS;
typedef struct AUDIOCLIENT_PROCESS_LOOPBACK_PARAMS { DWORD TargetProcessId; PROCESS_LOOPBACK_MODE ProcessLoopbackMode; } ...;
enum AUDIOCLIENT_ACTIVATION_TYPE { AUDIOCLIENT_ACTIVATION_TYPE_DEFAULT, AUDIOCLIENT_ACTIVATION_TYPE_PROCESS_LOOPBACK };
enum PROCESS_LOOPBACK_MODE { PROCESS_LOOPBACK_MODE_INCLUDE_TARGET_PROCESS_TREE, PROCESS_LOOPBACK_MODE_EXCLUDE_TARGET_PROCESS_TREE };
```

Sources: [params](https://learn.microsoft.com/en-us/windows/win32/api/audioclientactivationparams/ns-audioclientactivationparams-audioclient_activation_params), [loopback params](https://learn.microsoft.com/en-us/windows/win32/api/audioclientactivationparams/ns-audioclientactivationparams-audioclient_process_loopback_params), [type](https://learn.microsoft.com/en-us/windows/win32/api/audioclientactivationparams/ne-audioclientactivationparams-audioclient_activation_type), [mode](https://learn.microsoft.com/en-us/windows/win32/api/audioclientactivationparams/ne-audioclientactivationparams-process_loopback_mode).

- `TargetProcessId` covers "the render streams of its child processes" too.
- INCLUDE captures only that process tree. EXCLUDE captures everything except that tree.
- In Rust the union field is `Anonymous: AUDIOCLIENT_ACTIVATION_PARAMS_0 { ProcessLoopbackParams: ... }` ([struct](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Media/Audio/struct.AUDIOCLIENT_ACTIVATION_PARAMS.html), [wasapi src](https://raw.githubusercontent.com/HEnquist/wasapi-rs/master/src/api.rs)).

The sample README says ([README](https://raw.githubusercontent.com/microsoft/Windows-classic-samples/main/Samples/ApplicationLoopback/README.md)):
- "the capture is not tied to a specific audio endpoint", which eliminates "the need to create a separate IAudioClient to capture from each physical audio endpoint".
- If the target "does not have any audio rendering streams, then the capturing process receives silence".

**How the official sample drives it** ([LoopbackCapture.cpp](https://raw.githubusercontent.com/microsoft/Windows-classic-samples/main/Samples/ApplicationLoopback/cpp/LoopbackCapture.cpp)):
- The `PROPVARIANT` is `VT_BLOB` and points at the params struct.
- The format is a hard-coded `WAVE_FORMAT_PCM` at 2 ch / 44100 Hz / 16-bit.
- `Initialize(SHARED, AUDCLNT_STREAMFLAGS_LOOPBACK | EVENTCALLBACK | AUTOCONVERTPCM, 0, 0, &fmt, nullptr)`, then `SetEventHandle`.
- On each event it loops `while (GetNextPacketSize(&n) && n > 0) { GetBuffer … ReleaseBuffer(n) }`.

**WAVEFORMATEX handling is the gotcha:**
- The sample has a comment saying the app "can also call GetMixFormat".
- wasapi's docs record that on an application-loopback client:
  - `get_mixformat`, `is_supported`, `get_device_period`, and `get_current_padding` "just return `Not implemented`";
  - `get_buffer_size` "returns huge values like 3131961357";
  - `get_audioclock` and `get_audiosessioncontrol` return "No such interface supported" ([AudioClient docs](https://docs.rs/wasapi/0.26.0/wasapi/struct.AudioClient.html)).
- So **always pass an explicit format with `AUTOCONVERTPCM`**.
- Never rely on `IAudioClock` for process loopback. Take timing from `GetBuffer`'s QPC position.

**Using `wasapi` 0.26.0 is recommended.** It already wraps everything above.
- `pub fn new_application_loopback_client(process_id: u32, include_tree: bool) -> Result<Self, WasapiError>`.
- It uses `#[implement(IActivateAudioInterfaceCompletionHandler)] struct Handler(Arc<(Mutex<bool>, Condvar)>)` and waits on the `Condvar` ([src](https://docs.rs/wasapi/0.26.0/src/wasapi/api.rs.html)).
- **The source maps `include_tree == false` to `PROCESS_LOOPBACK_MODE_EXCLUDE_TARGET_PROCESS_TREE`.** The docstring wrongly says "if false only audio from the target process is captured".
- Callers must initialize with `Direction::Capture` + `ShareMode::Shared`.

Here is the verified call sequence, from the `record_application` example ([example](https://raw.githubusercontent.com/HEnquist/wasapi-rs/master/examples/record_application.rs)):

```rust
use wasapi::*; // initialize_mta() -> HRESULT; WaveFormat::new(storebits, validbits, &SampleType, rate, channels, Option<u32>)
fn system_loopback(own_pid: u32, stop: &std::sync::atomic::AtomicBool,
                   sink: &mut dyn FnMut(&[u8], BufferInfo)) -> Result<(), WasapiError> {
    initialize_mta().ok()?;                                                     // dedicated thread, never the Tauri UI thread
    let mut client = AudioClient::new_application_loopback_client(own_pid, false)?; // false = EXCLUDE our tree
    let fmt = WaveFormat::new(32, 32, &SampleType::Float, 16_000, 1, None);      // engine does SRC + downmix
    client.initialize_client(&fmt, &Direction::Capture,
        &StreamMode::EventsShared { autoconvert: true, buffer_duration_hns: 0 })?;  // shared+event ⇒ both 0 (MS rule)
    let ev = client.set_get_eventhandle()?;
    let cap = client.get_audiocaptureclient()?;
    client.start_stream()?;
    let mut q = std::collections::VecDeque::<u8>::new();
    while !stop.load(std::sync::atomic::Ordering::Relaxed) {
        if ev.wait_for_event(200).is_err() { continue; }      // timeout ⇒ no packet; timeline fills the gap
        while cap.get_next_packet_size()?.unwrap_or(0) > 0 {   // drain ALL packets per wake-up
            let start = q.len();
            let info = cap.read_from_device_to_deque(&mut q)?; // one GetBuffer/ReleaseBuffer
            if info.flags.silent { q.range_mut(start..).for_each(|b| *b = 0); } // wasapi does NOT zero-fill
            let bytes: Vec<u8> = q.drain(..).collect();
            sink(&bytes, info);                                // info.index = device pos, info.timestamp = QPC 100 ns
        }
    }
    client.stop_stream()
}
```

Other verified pieces:
- `initialize_mta() -> HRESULT` ([doc](https://docs.rs/wasapi/0.26.0/wasapi/fn.initialize_mta.html)).
- `StreamMode::EventsShared { autoconvert: bool, buffer_duration_hns: i64 }` ([doc](https://docs.rs/wasapi/0.26.0/wasapi/enum.StreamMode.html)).
- `get_next_packet_size(&self) -> Result<Option<u32>, _>` returns `None` in exclusive mode. `read_from_device_to_deque(&self, &mut VecDeque<u8>) -> Result<BufferInfo, _>` ([doc](https://docs.rs/wasapi/0.26.0/wasapi/struct.AudioCaptureClient.html)).
- `BufferInfo { flags: BufferFlags, index: u64, timestamp: u64 }`, where `timestamp` is "100-nanosecond units of the first frame" ([doc](https://docs.rs/wasapi/0.26.0/wasapi/struct.BufferInfo.html)).
- `BufferFlags { data_discontinuity, silent, timestamp_error }` ([doc](https://docs.rs/wasapi/0.26.0/wasapi/struct.BufferFlags.html)).
- `Handle::wait_for_event(&self, timeout_ms: u32)`. `as_handle()` exists for `WaitForMultipleObjects` with a stop event ([doc](https://docs.rs/wasapi/0.26.0/wasapi/struct.Handle.html)).
- Both read functions pass the 4th and 5th `GetBuffer` out-params into `index` and `timestamp`, and **do not check `SILENT`** ([src](https://raw.githubusercontent.com/HEnquist/wasapi-rs/master/src/api.rs)).

**The raw `windows` crate route** (only if wasapi is dropped):
- Features: `Win32_Media_Audio`, `Win32_System_Com`, `Win32_System_Com_StructuredStorage` (PROPVARIANT), `Win32_System_Variant` (`VT_BLOB`), `Win32_Foundation`, `Win32_System_Threading`. That is the subset wasapi itself enables ([Cargo.toml](https://raw.githubusercontent.com/HEnquist/wasapi-rs/master/Cargo.toml)).
- Copy the `PROPVARIANT`/`BLOB` construction from wasapi `src/api.rs`: `vt: VT_BLOB`, `cbSize = size_of::<AUDIOCLIENT_ACTIVATION_PARAMS>()`, `pBlobData` pointing at the pinned params, and the PROPVARIANT wrapped in `ManuallyDrop`. Do not hand-write it.
- Other raw signatures:
  - `IAudioClient::Initialize(&self, sharemode, streamflags: u32, hnsbufferduration: i64, hnsperiodicity: i64, pformat: *const WAVEFORMATEX, audiosessionguid: Option<*const GUID>)`.
  - `IAudioCaptureClient::GetBuffer(&self, *mut *mut u8, *mut u32, *mut u32, Option<*mut u64>, Option<*mut u64>)`.
  - `GetNextPacketSize() -> Result<u32>`.
  - Sources: [IAudioClient](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Media/Audio/struct.IAudioClient.html), [IAudioCaptureClient](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Media/Audio/struct.IAudioCaptureClient.html).

**Stream flags** ([constants](https://learn.microsoft.com/en-us/windows/win32/coreaudio/audclnt-streamflags-xxx-constants)):

| Flag | Value |
|---|---|
| `LOOPBACK` | 0x00020000 |
| `EVENTCALLBACK` | 0x00040000 |
| `AUTOCONVERTPCM` | 0x80000000 ("A channel matrixer and a sample rate converter are inserted as necessary") |
| `SRC_DEFAULT_QUALITY` | 0x08000000 (better SRC when combined with AUTOCONVERTPCM) |

wasapi adds `AUTOCONVERTPCM|SRC_DEFAULT_QUALITY` whenever `autoconvert: true`. It adds `LOOPBACK` when a Render-direction client is initialized with `Direction::Capture` in Shared mode, and returns `LoopbackWithExclusiveMode` for the exclusive case ([src](https://raw.githubusercontent.com/HEnquist/wasapi-rs/master/src/api.rs)).

**Shared event mode rule:** "For a shared-mode stream that uses event-driven buffering, the caller must set both *hnsPeriodicity* and *hnsBufferDuration* to 0" ([IAudioClient::Initialize](https://learn.microsoft.com/en-us/windows/win32/api/audioclient/nf-audioclient-iaudioclient-initialize)).

### 2.3 Endpoint loopback fallback (builds < 20348)

- Open the **render** endpoint and initialize a shared capture stream with `AUDCLNT_STREAMFLAGS_LOOPBACK`. Exclusive mode cannot loop back ([Loopback Recording](https://learn.microsoft.com/en-us/windows/win32/coreaudio/loopback-recording)).
- "In Windows 10 versions 1703 and higher, event-driven loopback clients are supported" (same page).
- The `Initialize` page words it more narrowly: "As of Windows 10 the relevant event handles are now set for loopback-enabled streams **that are active**" ([Initialize](https://learn.microsoft.com/en-us/windows/win32/api/audioclient/nf-audioclient-iaudioclient-initialize)).
- "The loopback data in the capture buffer is in the device format" (same page). Use `autoconvert` to get 16 kHz mono anyway.
- Endpoint loopback includes **our own** audio. You cannot exclude a process here.

```rust
let dev = DeviceEnumerator::new()?.get_default_device(&Direction::Render)?;  // Console role
let mut client = dev.get_iaudioclient()?;                                     // client direction = Render
client.initialize_client(&fmt16k_mono, &Direction::Capture,                   // Render+Capture+Shared ⇒ LOOPBACK flag
    &StreamMode::EventsShared { autoconvert: true, buffer_duration_hns: 0 })?;
```

Sources: [DeviceEnumerator](https://docs.rs/wasapi/0.26.0/wasapi/struct.DeviceEnumerator.html), [Device](https://docs.rs/wasapi/0.26.0/wasapi/struct.Device.html), [flag logic](https://raw.githubusercontent.com/HEnquist/wasapi-rs/master/src/api.rs).

**Silence behaviour: endpoint loopback delivers no packets while nothing is playing.**
- Matthew van Eerde: "WASAPI will only push data down to the render endpoint when there are active streams… When nothing is playing, there is nothing to capture." His fix was to run a "silence" player ([post](https://matthewvaneerde.wordpress.com/2008/12/16/sample-wasapi-loopback-capture-record-what-you-hear/), which moved there from [blogs.msdn](https://learn.microsoft.com/en-us/archive/blogs/matthew_van_eerde/sample-wasapi-loopback-capture-record-what-you-hear)).
- OBS today, for device-output capture, activates a render client on the same device and fills its buffer with zeros: "/* Silent loopback fix. Prevents audio stream from stopping and messing up timestamps and other weird glitches during silence */" (`WASAPISource::ClearBuffer`, [win-wasapi.cpp](https://raw.githubusercontent.com/obsproject/obs-studio/master/plugins/win-wasapi/win-wasapi.cpp)).
- OBS's version initializes the render client with a 5 s buffer, writes one zeroed buffer, and **does not call `Start()`**.
- **Design: do not depend on keep-alive tricks.** Fill gaps from QPC timestamps (§6). A started silent render stream is optional hardening, and **UNVERIFIED** as to whether it is needed on Win10 22H2.

### 2.4 Microphone capture (Windows)

- Use the same crate and the same clock: `DeviceEnumerator::get_default_device_for_role(&Direction::Capture, &Role::Communications)`, then `get_iaudioclient()`, then `initialize_client(&fmt16k_mono, &Direction::Capture, &EventsShared{autoconvert:true, buffer_duration_hns:0})` ([DeviceEnumerator](https://docs.rs/wasapi/0.26.0/wasapi/struct.DeviceEnumerator.html), [Role](https://docs.rs/wasapi/0.26.0/wasapi/enum.Role.html): `Console | Multimedia | Communications`).
- That gives the same `BufferInfo.timestamp` (QPC) basis as the loopback channel.
- Call apps usually use the *communications* default. Make the role, or a fixed device, a user setting. **UNVERIFIED** per app; Zoom, for one, has its own device picker.
- `cpal` 0.18.2 is **not needed** on Windows. It has endpoint loopback (an input stream built on a render device sets `AUDCLNT_STREAMFLAGS_LOOPBACK`, [device.rs @0.18.2](https://docs.rs/crate/cpal/0.18.2/source/src/host/wasapi/device.rs)) and default-device rerouting through `DEVINTERFACE_AUDIO_RENDER/CAPTURE` (0.18.0 changelog).
- But cpal has **no process loopback**, and its 0.18.2 changelog says "Default device changes no longer report `DeviceChanged`" ([CHANGELOG](https://raw.githubusercontent.com/RustAudio/cpal/master/CHANGELOG.md)). That removes the very signal we need to mark gaps.

### 2.5 Default-device changes and `AUDCLNT_E_DEVICE_INVALIDATED`

**Easiest path, wasapi 0.26.0:**
- `DeviceEventCallbacks::set_default_device_callback(&mut self, c: impl Fn(Direction, Role, Option<String>) + 'static + Sync + Send)`. The ID is `None` when there is no default ([doc](https://docs.rs/wasapi/0.26.0/wasapi/struct.DeviceEventCallbacks.html)).
- Register with `DeviceEnumerator::register_notification_callback(&self, DeviceEventCallbacks) -> Result<DeviceEventRegistration, _>`.
- Notifications stop when the registration is dropped. Callbacks run on a Windows audio thread and "must not call back into the enumerator" ([doc](https://docs.rs/wasapi/0.26.0/wasapi/struct.DeviceEnumerator.html)).

**Raw `#[implement]` version** (trait signatures from [IMMNotificationClient_Impl](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Media/Audio/trait.IMMNotificationClient_Impl.html); imports need a compile check):

```rust
#[windows::core::implement(IMMNotificationClient)]
struct Watcher { tx: std::sync::mpsc::Sender<DevEvt> }
impl IMMNotificationClient_Impl for Watcher_Impl {
    fn OnDefaultDeviceChanged(&self, flow: EDataFlow, role: ERole, id: &PCWSTR) -> windows::core::Result<()> {
        let id = if id.is_null() { None } else { unsafe { id.to_string().ok() } };
        let _ = self.tx.send(DevEvt::Default { flow: flow.0, role: role.0, id }); Ok(()) // never block here
    }
    fn OnDeviceStateChanged(&self, _: &PCWSTR, _: DEVICE_STATE) -> windows::core::Result<()> { Ok(()) }
    fn OnDeviceAdded(&self, _: &PCWSTR) -> windows::core::Result<()> { Ok(()) }
    fn OnDeviceRemoved(&self, _: &PCWSTR) -> windows::core::Result<()> { Ok(()) }
    fn OnPropertyValueChanged(&self, _: &PCWSTR, _: &PROPERTYKEY) -> windows::core::Result<()> { Ok(()) }
}
// IMMDeviceEnumerator::RegisterEndpointNotificationCallback<P0: Param<IMMNotificationClient>>(&self, P0) -> Result<()>
```

Source for the registration signature: [IMMDeviceEnumerator](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Media/Audio/struct.IMMDeviceEnumerator.html).

Rules from [IMMNotificationClient](https://learn.microsoft.com/en-us/windows/win32/api/mmdeviceapi/nn-mmdeviceapi-immnotificationclient):
- Callbacks must be non-blocking.
- Never call Register/Unregister inside a callback.
- Never release the last MMDevice reference in a callback.
- Treat endpoint ID strings as opaque.

From [OnDefaultDeviceChanged](https://learn.microsoft.com/en-us/windows/win32/api/mmdeviceapi/nf-mmdeviceapi-immnotificationclient-ondefaultdevicechanged):
- One user change can fire **three** callbacks, one per role (eConsole, eMultimedia, eCommunications).
- `pwstrDefaultDeviceId` is NULL when no device is left.
- So debounce, and filter by the role you follow.

**Recovery from [Recovering from an Invalid-Device Error](https://learn.microsoft.com/en-us/windows/win32/coreaudio/recovering-from-an-invalid-device-error):**
1. Release the `IAudioClient` and every interface obtained from it.
2. Get the current default endpoint again.
3. Activate a new `IAudioClient`.

**How errors surface in wasapi:**
- HRESULTs come back as `WasapiError::Windows(windows_result::Error)`. There is no dedicated invalidated variant ([WasapiError](https://docs.rs/wasapi/0.26.0/wasapi/enum.WasapiError.html)).
- Compare `e.code() == AUDCLNT_E_DEVICE_INVALIDATED`. The constant is an `HRESULT` in `windows::Win32::Media::Audio` ([doc](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Media/Audio/constant.AUDCLNT_E_DEVICE_INVALIDATED.html)).
- `GetBuffer` can also return `AUDCLNT_E_RESOURCES_INVALIDATED`, `AUDCLNT_E_SERVICE_NOT_RUNNING`, and `AUDCLNT_E_BUFFER_OPERATION_PENDING` ([GetBuffer](https://learn.microsoft.com/en-us/windows/win32/api/audioclient/nf-audioclient-iaudiocaptureclient-getbuffer)). Treat them all as "reopen with backoff".

**Who follows what:**
- **Mic:** reopen on default-capture change for the role you follow, and on invalidation.
- **Process loopback:** nothing to follow, because it is endpoint-independent (README). Reopen only on error.
- **Endpoint loopback:** reopen on a default-render (eConsole) change.
- Every reopen leaves a gap. The timeline (§6) fills it from QPC.
- Optional alternative for the mic: automatic stream routing. Activating through `StringFromIID(DEVINTERFACE_AUDIO_CAPTURE)` + `ActivateAudioInterfaceAsync` follows the default device from Windows 10 1607 ([Automatic Stream Routing](https://learn.microsoft.com/en-us/windows/win32/coreaudio/automatic-stream-routing), [DEVINTERFACE GUIDs](https://learn.microsoft.com/en-us/windows/win32/coreaudio/devinterface-xxx-guids)).
  - wasapi exposes `AudioClient::from_iaudioclient(client, Direction)` to wrap such a client ([doc](https://docs.rs/wasapi/0.26.0/wasapi/struct.AudioClient.html)).
  - It is **UNVERIFIED** whether the stream flags discontinuity on reroute. The explicit reopen is easier to reason about, so prefer it for v1.

### 2.6 Mic-in-use detection (Windows): two approaches compared

| | (a) WASAPI capture sessions | (b) CapabilityAccessManager ConsentStore |
|---|---|---|
| Status | **Documented** API (Win7+) | **Undocumented.** No Microsoft page; layout observed **[OBS]** + forensic tooling ([Velociraptor](https://docs.velociraptor.app/exchange/artifacts/pages/windows.registry.capabilityaccessmanager)) |
| Identifies | PID, which you map to exe or PFN | Packaged: package family name. Desktop: full exe path |
| Real time | Yes: state + `OnStateChanged`, or a 1 s poll | Latency **UNVERIFIED**; use `RegNotifyChangeKeyValue` |
| Covers | Only the endpoints you enumerate, so enumerate **all** active capture devices | Every capture through the OS privacy layer |
| Our own process | Filter by our PID and our child PIDs | Our exe also shows up under NonPackaged |

**(a) Sessions, from Microsoft docs:**
- `IAudioSessionManager2::GetSessionEnumerator` gives a collection. **"You must call IAudioSessionEnumerator::GetCount to begin receiving notifications"** ([RegisterSessionNotification](https://learn.microsoft.com/en-us/windows/win32/api/audiopolicy/nf-audiopolicy-iaudiosessionmanager2-registersessionnotification)).
- Notifications need a COM **MTA** thread (same page).
- The enumerator "might not be aware of the new sessions"; keep your own list ([GetSessionEnumerator](https://learn.microsoft.com/en-us/windows/win32/api/audiopolicy/nf-audiopolicy-iaudiosessionmanager2-getsessionenumerator)).
- `IAudioSessionControl2::GetProcessId` returns `AUDCLNT_S_NO_SINGLE_PROCESS` for cross-process sessions, where you get the creator's PID ([doc](https://learn.microsoft.com/en-us/windows/win32/api/audiopolicy/nf-audiopolicy-iaudiosessioncontrol2-getprocessid)).
- States ([AudioSessionState](https://learn.microsoft.com/en-us/windows/win32/api/audiosessiontypes/ne-audiosessiontypes-audiosessionstate)):
  - `Active`: at least one stream running.
  - `Inactive`: streams exist but are stopped.
  - `Expired`: no streams left.

wasapi 0.26.0 wraps all of this:
- `Device::get_iaudiosessionmanager()` → `AudioSessionManager::get_audiosessionenumerator()` → `get_count() -> i32`, `get_session(i32) -> AudioSessionControl`.
- `AudioSessionControl::{get_state() -> SessionState, get_process_id() -> u32, register_session_notification(EventCallbacks) -> EventRegistration}`.
- `SessionState::{Active, Inactive, Expired}`.
- Sources: [Device](https://docs.rs/wasapi/0.26.0/wasapi/struct.Device.html), [AudioSessionManager](https://docs.rs/wasapi/0.26.0/wasapi/struct.AudioSessionManager.html), [Enumerator](https://docs.rs/wasapi/0.26.0/wasapi/struct.AudioSessionEnumerator.html), [Control](https://docs.rs/wasapi/0.26.0/wasapi/struct.AudioSessionControl.html), [SessionState](https://docs.rs/wasapi/0.26.0/wasapi/enum.SessionState.html).
- Enumerate devices with `DeviceEnumerator::get_device_collection(&Direction::Capture)`.

PID to identity:
- `OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, false, pid) -> Result<HANDLE>` ([doc](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/System/Threading/fn.OpenProcess.html)).
- `QueryFullProcessImageNameW(h, PROCESS_NAME_FORMAT(0), PWSTR, *mut u32) -> Result<()>` in `Win32::System::Threading` ([Rust](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/System/Threading/fn.QueryFullProcessImageNameW.html), [MS](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-queryfullprocessimagenamew)).
- `GetPackageFamilyName(h, *mut u32, Option<PWSTR>) -> WIN32_ERROR` in `Win32::Storage::Packaging::Appx`. It returns `APPMODEL_ERROR_NO_PACKAGE` for desktop apps ([Rust](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/Storage/Packaging/Appx/fn.GetPackageFamilyName.html), [MS](https://learn.microsoft.com/en-us/windows/win32/api/appmodel/nf-appmodel-getpackagefamilyname)).

**(b) ConsentStore layout, all [OBS] on build 26300:**
- Root: `HKCU\Software\Microsoft\Windows\CurrentVersion\CapabilityAccessManager\ConsentStore\microphone`.
  - It has its own `Value` = `Allow`. The HKLM twin also has `Value` = `Allow`.
- **Packaged apps** sit in subkeys named by **package family name**, directly under `microphone`. Example: `5319275A.51895FA4EA97F_cv1g1gvanyjgm`, `MSTeams_8wekyb3d8bbwe`.
- **Desktop apps** sit under `microphone\NonPackaged`. That key has its own `Value`=`Allow` and holds subkeys named by the full exe path with `\`→`#`, for example `C:#Program Files#Google#Chrome#Application#chrome.exe`.
- Values per app:

| Value | Type |
|---|---|
| `LastUsedTimeStart` | REG_QWORD FILETIME |
| `LastUsedTimeStop` | REG_QWORD FILETIME |
| `Value` | REG_SZ, packaged apps only |
| `LastSetTime` | |
| `LastUserAnnotatedLabel` | DWORD |
| `PersistedInDatabase` | DWORD |

- FILETIME 134354605520153006 decodes to 2026-10-03T00:22:32Z.
- **UNVERIFIED:** "in use ⇔ `LastUsedTimeStop == 0` (or Start > Stop)". This comes from community notes only. I did not open the mic to test it.
- Watch the key with `RegNotifyChangeKeyValue(hkey, bwatchsubtree=true, REG_NOTIFY_CHANGE_LAST_SET|REG_NOTIFY_CHANGE_NAME, Some(event), true) -> WIN32_ERROR` ([Rust](https://microsoft.github.io/windows-docs-rs/doc/windows/Win32/System/Registry/fn.RegNotifyChangeKeyValue.html)).
  - It is **one-shot**: re-arm after each signal.
  - It needs a persistent thread unless you pass `REG_NOTIFY_THREAD_AGNOSTIC`.
  - It needs `KEY_NOTIFY` access ([MS](https://learn.microsoft.com/en-us/windows/win32/api/winreg/nf-winreg-regnotifychangekeyvalue)).

**Recommendation:** use (a) for start/stop decisions. Use (b) only to label recent activity, or as a fallback when (a) sees no session.

### 2.7 App identity table (for `source_app`)

| App | Windows identity | Confidence |
|---|---|---|
| WhatsApp (Store) | PFN `5319275A.WhatsAppDesktop_cv1g1gvanyjgm`, user scope ([Store manifest API](https://storeedgefd.dsx.mp.microsoft.com/v9.0/packageManifests/9NKSQGP7F2NH)) | high |
| WhatsApp Beta | PFN `5319275A.51895FA4EA97F_cv1g1gvanyjgm`, DisplayName "WhatsApp Beta", publisher "WhatsApp Inc.", `Executable="WhatsApp.Root.exe"`, `Windows.FullTrustApplication` **[OBS]** | high (this machine) |
| WhatsApp, rule | Match a PFN that **starts with `5319275A.`** (WhatsApp Inc.'s publisher prefix) through `GetPackageFamilyName(pid)` | high |
| WhatsApp Business | **UNVERIFIED** that a separate Windows app exists. Business accounts link into the same WhatsApp desktop app (third-party guides only) | low |
| Zoom | `Zoom.exe`. Per-user install `%APPDATA%\Zoom\bin\Zoom.exe`, MSI install `C:\Program Files\Zoom\bin\Zoom.exe`. **UNVERIFIED**: only search-result snippets from community and IT pages; no support.zoom.com page found, and Zoom is not installed on the research machine. Match on the image name `zoom.exe`, case-insensitive | medium |
| Chrome (Meet) | `chrome.exe`, at `C:\Program Files\Google\Chrome\Application\chrome.exe` **[OBS]** (the running path and the ConsentStore key). The audio session belongs to a chrome.exe utility child, which has the same image name | high (image name) |
| Edge (Meet) | `msedge.exe` at `C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe` **[OBS]** | high (image name) |
| Teams (new) | PFN `MSTeams_8wekyb3d8bbwe` **[OBS]**. Maps to `other` | high |
| Meet vs. other browser use | A browser PID only says "browser". Optionally look for "Meet" in that PID's top-level window titles. This heuristic is **UNVERIFIED** | low |

---

## 3. macOS

### 3.1 Versions and permissions

- `AudioHardwareCreateProcessTap` and `AudioHardwareDestroyProcessTap` are **macOS 14.2+** ([create](https://developer.apple.com/tutorials/data/documentation/coreaudio/audiohardwarecreateprocesstap(_:_:).json), [destroy](https://developer.apple.com/tutorials/data/documentation/coreaudio/audiohardwaredestroyprocesstap(_:).json)).
- The sample says "ensure that you're using macOS 14.2 or later" ([sample .md](https://developer.apple.com/documentation/coreaudio/capturing-system-audio-with-core-audio-taps.md)).
- AudioCap says the API arrived "With macOS 14.4" ([README](https://raw.githubusercontent.com/insidegui/AudioCap/main/README.md)).
- cpal only turns on its loopback above macOS 14.6 (0.17.0 changelog).
- Apple's per-symbol metadata is inconsistent: the class says 12.0, the initializer 14.0, the mute enum 13.0. Gate on the **functions**.
- New properties `bundleIDs` and `isProcessRestoreEnabled` are **macOS 26.0+** ([bundleIDs](https://developer.apple.com/documentation/coreaudio/catapdescription/bundleids.md), [restore](https://developer.apple.com/documentation/coreaudio/catapdescription/isprocessrestoreenabled.md)).
- **Plan:** `tauri.conf.json > bundle > macOS > minimumSystemVersion = "14.4"` (the spec), plus a runtime check with `NSProcessInfo::processInfo().isOperatingSystemAtLeastVersion(..)` ([doc](https://docs.rs/objc2-foundation/0.3.2/objc2_foundation/struct.NSProcessInfo.html)).
- **Info.plist:** `NSAudioCaptureUsageDescription` (macOS 14.2+, "why your app is requesting access to capture system audio") ([key](https://developer.apple.com/documentation/bundleresources/information-property-list/nsaudiocaptureusagedescription.md)), plus `NSMicrophoneUsageDescription`.
  - Tauri merges `src-tauri/Info.plist` into the generated one ([Tauri](https://v2.tauri.app/distribute/macos-application-bundle/)).
- **Entitlements, hardened runtime:** `com.apple.security.device.audio-input`, "may record audio using the built-in microphone and access audio input using Core Audio" ([doc](https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.security.device.audio-input.md)). Set it with `bundle > macOS > entitlements` in Tauri.
  - No tap-specific entitlement turned up in my research (**UNVERIFIED** that none exists).
- **Permission prompt:** "The first time you start recording from an aggregate device that contains a tap, the system prompts…" (Apple sample).
- **There is no public preflight/request API.**
  - AudioCap uses the private `TCC.framework` `TCCAccessPreflight`/`TCCAccessRequest` with `"kTCCServiceAudioCapture"`, where 0 = authorized and 1 = denied ([AudioRecordingPermission.swift](https://raw.githubusercontent.com/insidegui/AudioCap/main/AudioCap/ProcessTap/AudioRecordingPermission.swift)).
  - **Do not ship private SPI.** Trigger the prompt from an explicit "Enable system audio" button, so it is never a surprise popup.

### 3.2 Creating the tap

- Swift initializers ([CATapDescription](https://developer.apple.com/tutorials/data/documentation/coreaudio/catapdescription.json)): `init(stereoGlobalTapButExcludeProcesses:)`, `init(stereoMixdownOfProcesses:)`, the `mono…` variants, `init(processes:deviceUID:stream:)`, and `init(excludingProcesses:deviceUID:stream:)`.
- Property docs:
  - `isExclusive`: "tap all processes except the process listed in the 'processes' property" ([doc](https://developer.apple.com/documentation/coreaudio/catapdescription/isexclusive.md)).
  - `isPrivate`: "only visible to the client process that created the tap" ([doc](https://developer.apple.com/documentation/coreaudio/catapdescription/isprivate.md)).
  - `deviceUID`: set "if this tap only taps a specific hardware device" ([doc](https://developer.apple.com/documentation/coreaudio/catapdescription/deviceuid.md)).
  - `CATapMuteBehavior`: the default is unmuted ([doc](https://developer.apple.com/documentation/coreaudio/catapmutebehavior.md)).
- `objc2-core-audio` 0.3.2, features `AudioHardware` + `objc2` + `objc2-foundation` ([CATapDescription](https://docs.rs/objc2-core-audio/0.3.2/objc2_core_audio/struct.CATapDescription.html)):
  - `initStereoGlobalTapButExcludeProcesses(this: Allocated<Self>, &NSArray<NSNumber>) -> Retained<Self>`.
  - `setPrivate(bool)`, `UUID() -> Retained<NSUUID>`.
  - The mute getter is oddly named `isMuted() -> CATapMuteBehavior`. Constants are `Unmuted | Muted | MutedWhenTapped` ([doc](https://docs.rs/objc2-core-audio/0.3.2/objc2_core_audio/struct.CATapMuteBehavior.html)).
  - `AudioHardwareCreateProcessTap(in_description: Option<&CATapDescription>, out_tap_id: *mut AudioObjectID) -> i32` ([doc](https://docs.rs/objc2-core-audio/0.3.2/objc2_core_audio/fn.AudioHardwareCreateProcessTap.html)).

```rust
use objc2::AnyThread; // fn alloc() -> Allocated<Self>   (objc2 0.6.5)
use objc2_core_audio::{AudioHardwareCreateProcessTap, AudioObjectID, CATapDescription};
use objc2_foundation::{NSArray, NSNumber};
unsafe fn create_system_tap(own_process_obj: Option<AudioObjectID>) -> Result<(AudioObjectID, String), i32> {
    let ids: Vec<_> = own_process_obj.into_iter().map(NSNumber::new_u32).collect(); // NSNumber::new_u32 (feature NSValue)
    let arr = NSArray::from_retained_slice(&ids);
    let desc = CATapDescription::initStereoGlobalTapButExcludeProcesses(CATapDescription::alloc(), &arr);
    desc.setPrivate(true);
    let uid = desc.UUID().UUIDString().to_string();                                 // NSUUID::UUIDString
    let mut tap: AudioObjectID = 0;
    match AudioHardwareCreateProcessTap(Some(&desc), &mut tap) { 0 => Ok((tap, uid)), e => Err(e) }
}
```

- Get our own process object by reading `kAudioHardwarePropertyTranslatePIDToProcessObject` (`0x69643270`) on `kAudioObjectSystemObject` (=1), with our `pid_t` as the qualifier ([AudioHardware.rs](https://docs.rs/objc2-core-audio/0.3.2/src/objc2_core_audio/generated/AudioHardware.rs.html), [AudioCap README](https://raw.githubusercontent.com/insidegui/AudioCap/main/README.md)).
- If it yields 0 (we have not done any audio I/O yet), tapping "all" is equivalent. That equivalence is **UNVERIFIED**.
- The tap UID can also be read with `kAudioTapPropertyUID` (`0x74756964`), which returns a CFString (Apple sample).
- `kAudioTapPropertyFormat` is `0x74666d74`.
- Destroy order, from AudioCap's `invalidate()` ([ProcessTap.swift](https://raw.githubusercontent.com/insidegui/AudioCap/main/AudioCap/ProcessTap/ProcessTap.swift)):
  1. `AudioDeviceStop`
  2. `AudioDeviceDestroyIOProcID`
  3. `AudioHardwareDestroyAggregateDevice`
  4. `AudioHardwareDestroyProcessTap`

### 3.3 Aggregate device: keys and the mic + tap layout

Verified string values ([generated AudioHardware.rs](https://docs.rs/objc2-core-audio/0.3.2/src/objc2_core_audio/generated/AudioHardware.rs.html)):

| Constant | String |
|---|---|
| `kAudioAggregateDeviceUIDKey` | `"uid"` |
| `kAudioAggregateDeviceNameKey` | `"name"` |
| `kAudioAggregateDeviceMainSubDeviceKey` | `"master"` |
| `kAudioAggregateDeviceClockDeviceKey` | `"clock"` |
| `kAudioAggregateDeviceIsPrivateKey` | `"private"` |
| `kAudioAggregateDeviceIsStackedKey` | `"stacked"` |
| `kAudioAggregateDeviceSubDeviceListKey` | `"subdevices"` |
| `kAudioAggregateDeviceTapListKey` | `"taps"` |
| `kAudioAggregateDeviceTapAutoStartKey` | `"tapautostart"` |
| `kAudioSubDeviceUIDKey` / `kAudioSubTapUIDKey` | `"uid"` |
| `kAudioSubDeviceDriftCompensationKey` / `kAudioSubTapDriftCompensationKey` | `"drift"` |

In Rust these are `&CStr`; convert with `CFString::from_str(k.to_str().unwrap())`.

**The verified baseline is AudioCap's dictionary** (system audio only):

```
name: "Tap-<pid>"
uid: <new UUID>
master: <default system output UID>
private: true
stacked: false
tapautostart: true
subdevices: [{uid: <output UID>}]
taps: [{drift: true, uid: <tap UUID>}]
```

- AudioCap builds the tap with `stereoMixdownOfProcesses:[objectID]`.
- The output UID comes from `readDefaultSystemOutputDevice()` ([ProcessTap.swift](https://raw.githubusercontent.com/insidegui/AudioCap/main/AudioCap/ProcessTap/ProcessTap.swift)).
- `tapautostart: true` matters. cpal 0.18.0 fixed "loopback capture returning silence due to disabled tap auto-start" ([CHANGELOG](https://raw.githubusercontent.com/RustAudio/cpal/master/CHANGELOG.md)).

**EchoLedger layout.** This is a **design hypothesis to verify on hardware**: it extends AudioCap by adding the mic as a second sub-device.

```
master: <default output UID>
subdevices: [{uid: <output UID>}, {uid: <default input UID>, drift: true}]
taps: [{uid: <tap UUID>, drift: true}]
private: true
stacked: false
tapautostart: true
uid: <fresh UUID>
```

Why this layout:
- The HAL then delivers the mic input stream(s) and the tap stream to **one IOProc, on the aggregate's clock**, so mic and system are sample-aligned by construction.
- Apple's sample presents the tap as an aggregate input "just like a microphone".
- Fallback if this misbehaves: two separate captures (mic-only device + tap aggregate), aligned by `mHostTime` (§6).

Building it:
- `AudioHardwareCreateAggregateDevice(in_description: &CFDictionary, out_device_id: NonNull<AudioObjectID>) -> OSStatus` (same generated file).
- Build `CFDictionary::<CFString, CFType>::from_slices(&keys, &values)` and pass `.as_opaque()` ([CFDictionary](https://docs.rs/objc2-core-foundation/0.3.2/objc2_core_foundation/struct.CFDictionary.html)).
- `CFBoolean::new(bool) -> &'static CFBoolean` (feature `CFNumber`) ([doc](https://docs.rs/objc2-core-foundation/0.3.2/objc2_core_foundation/struct.CFBoolean.html)).
- `CFArray::from_objects(&[&T])` ([doc](https://docs.rs/objc2-core-foundation/0.3.2/objc2_core_foundation/struct.CFArray.html)).
- `CFString::from_str` ([doc](https://docs.rs/objc2-core-foundation/0.3.2/objc2_core_foundation/struct.CFString.html)).

Rules:
- Use a fresh UUID for every aggregate. cpal 0.18.0 fixed naming collisions "so concurrent or post-crash instances no longer clash".
- Pass taps in the creation dictionary, not by setting `kAudioAggregateDevicePropertyTapList` afterwards. Apple's own snippet reads the list from `tapID` where it should use the aggregate ID (the forum-reported bug).

### 3.4 Reading audio (IOProc)

- `AudioDeviceCreateIOProcIDWithBlock(out_io_proc_id: NonNull<AudioDeviceIOProcID>, in_device: AudioObjectID, in_dispatch_queue: Option<&DispatchQueue>, in_io_block: AudioDeviceIOBlock) -> i32`. Features `AudioHardware` + `block2` + `dispatch2` + `objc2-core-audio-types` ([doc](https://docs.rs/objc2-core-audio/0.3.2/objc2_core_audio/fn.AudioDeviceCreateIOProcIDWithBlock.html)).
- `AudioDeviceIOBlock = *mut DynBlock<dyn Fn(NonNull<AudioTimeStamp>, NonNull<AudioBufferList>, NonNull<AudioTimeStamp>, NonNull<AudioBufferList>, NonNull<AudioTimeStamp>)>`. The arguments are now, input, input time, output, output time.
- `AudioDeviceIOProcID = Option<unsafe extern "C-unwind" fn(..) -> i32>` ([doc](https://docs.rs/objc2-core-audio/0.3.2/objc2_core_audio/type.AudioDeviceIOProcID.html)).
- `AudioDeviceStart(in_device, in_proc_id) -> i32` ([doc](https://docs.rs/objc2-core-audio/0.3.2/objc2_core_audio/fn.AudioDeviceStart.html)).
- `AudioTimeStamp { mSampleTime: f64, mHostTime: u64, mRateScalar, mWordClockTime, mSMPTETime, mFlags, mReserved }` ([doc](https://docs.rs/objc2-core-audio-types/0.3.2/objc2_core_audio_types/struct.AudioTimeStamp.html)).
- `AudioBufferList { mNumberBuffers: u32, mBuffers: [AudioBuffer; 1] }`, a variable-length array, so read `mNumberBuffers` entries ([doc](https://docs.rs/objc2-core-audio-types/0.3.2/objc2_core_audio_types/struct.AudioBufferList.html)).
- `AudioConvertHostTimeToNanos(u64) -> u64` (feature `HostTime`) ([doc](https://docs.rs/objc2-core-audio/0.3.2/objc2_core_audio/fn.AudioConvertHostTimeToNanos.html)).
- `RcBlock::new(closure)` and `RcBlock::as_ptr(&b) -> *mut Block<F>` (block2 0.6.2). `DispatchQueue::new(label, None) -> DispatchRetained<DispatchQueue>` (dispatch2 0.3.1).

```rust
let queue = DispatchQueue::new("echoledger.audio.io", None);
let block = RcBlock::new(move |_now: NonNull<AudioTimeStamp>, input: NonNull<AudioBufferList>,
                               in_time: NonNull<AudioTimeStamp>, _o: NonNull<AudioBufferList>, _ot: NonNull<AudioTimeStamp>| {
    let abl = unsafe { input.as_ref() };
    let bufs = unsafe { std::slice::from_raw_parts(abl.mBuffers.as_ptr(), abl.mNumberBuffers as usize) };
    let t_ns = unsafe { AudioConvertHostTimeToNanos(in_time.as_ref().mHostTime) };
    // copy bufs[i].mData (mDataByteSize bytes, mNumberChannels ch, f32) into a pre-allocated SPSC ring; no alloc/locks here
});
let mut proc_id: AudioDeviceIOProcID = None;
let st = unsafe { AudioDeviceCreateIOProcIDWithBlock(NonNull::from(&mut proc_id), aggregate_id, Some(&queue), RcBlock::as_ptr(&block)) };
// then AudioDeviceStart(aggregate_id, proc_id); keep `block`, `queue`, ids alive until teardown
```

**UNVERIFIED:** which input buffer is the mic and which is the tap.
- Read `kAudioDevicePropertyStreamConfiguration` / `kAudioDevicePropertyStreams` on the aggregate's input scope after creation.
- Log the per-stream channel counts.
- Verify the mapping on hardware.
- Do not hard-code buffer indexes.

### 3.5 Following device changes (macOS)

- `AudioObjectAddPropertyListenerBlock(in_object_id, in_address: NonNull<AudioObjectPropertyAddress>, in_dispatch_queue: Option<&DispatchQueue>, in_listener: AudioObjectPropertyListenerBlock) -> OSStatus`. The listener type is `*mut DynBlock<dyn Fn(u32, NonNull<AudioObjectPropertyAddress>)>` ([AudioHardware.rs](https://docs.rs/objc2-core-audio/0.3.2/src/objc2_core_audio/generated/AudioHardware.rs.html)).
- `AudioObjectPropertyAddress { mSelector, mScope, mElement }` ([doc](https://docs.rs/objc2-core-audio/0.3.2/objc2_core_audio/struct.AudioObjectPropertyAddress.html)).
- Listen on `kAudioObjectSystemObject` for:
  - `kAudioHardwarePropertyDefaultInputDevice` (`0x64496e20`), "The AudioObjectID of the default input AudioDevice";
  - `kAudioHardwarePropertyDefaultOutputDevice` (`0x644f7574`) ([10.8 SDK header copy](https://jenkins.heirloomcomputing.com/downloads/MacOSX10.8.sdk/System/Library/Frameworks/CoreAudio.framework/Headers/AudioHardware.h)).
- Scope `kAudioObjectPropertyScopeGlobal`, element `kAudioObjectPropertyElementMain`.
- On either change:
  1. Stop IO.
  2. Destroy the **aggregate only**. The global tap is not tied to a device; keeping it is **UNVERIFIED** but logical.
  3. Rebuild with the new UIDs.
  4. Restart.
  5. Let the timeline zero-fill the gap.
- Also listen for `kAudioDevicePropertyNominalSampleRate` on the aggregate. Bluetooth HFP can change the rate, and cpal 0.18.0 now raises `StreamInvalidated` "on any sample rate change on macOS". Rebuild the resampler when it fires.

### 3.6 Mic-in-use detection (macOS)

**Device level:** `kAudioDevicePropertyDeviceIsRunningSomewhere` (`0x676f6e65`) is "A UInt32 where 1 means that the AudioDevice is running in at least one process on the system and 0 means that it isn't running at all" ([header](https://jenkins.heirloomcomputing.com/downloads/MacOSX10.8.sdk/System/Library/Frameworks/CoreAudio.framework/Headers/AudioHardware.h)).
- Put a listener on the default input device, and re-register it when the default input changes.
- **Once we are recording, our own capture holds it at 1.** So it can only *start* detection.

**Process level:**
- `kAudioHardwarePropertyProcessObjectList` (`0x70727323`) on the system object gives the process objects.
- For each one, read:
  - `kAudioProcessPropertyIsRunningInput` (`0x70697269`), a UInt32;
  - `kAudioProcessPropertyBundleID` (`0x70626964`), a CFString;
  - `kAudioProcessPropertyPID` (`0x70706964`).
- Values from the [generated source](https://docs.rs/objc2-core-audio/0.3.2/src/objc2_core_audio/generated/AudioHardware.rs.html). Apple's pages give no version; a developer forum says these arrived with the **macOS 14.0 SDK** ([forum](https://developer.apple.com/forums/thread/798884)), which is medium confidence and moot at 14.4+.
- **Do not rely on listeners** for `IsRunningInput`/`IsRunningOutput`. A report on macOS 15.0.1 says they never fire, while `IsRunning` and `Devices` do ([forum 770348](https://developer.apple.com/forums/thread/770348)).
- `kAudioProcessPropertyDevices` returned the wrong input device after a mic switch ([forum 748257](https://developer.apple.com/forums/thread/748257)).
- So: poll the process list once a second while `DeviceIsRunningSomewhere` = 1, and also during recording, to see when the call app releases input.

**cidre alternative:** `cidre::core_audio::hardware::Process::list()`, `is_running_input()`, `bundle_id()`, `pid()` ([doc](https://docs.rs/cidre/0.29.0/cidre/core_audio/hardware/struct.Process.html)).

**Bundle IDs (all UNVERIFIED by vendor docs):**

| App | Bundle ID | Source |
|---|---|---|
| Zoom | `us.zoom.xos` | MDM catalogs |
| Edge | `com.microsoft.edgemac` | App catalogs. Its *policy* domain is `com.microsoft.Edge` ([MS Learn](https://learn.microsoft.com/en-us/deployedge/configure-microsoft-edge-on-mac)) |
| Chrome | `com.google.Chrome` | — |
| WhatsApp | `net.whatsapp.WhatsApp` | — |

- Match on **prefix**, because helper processes such as `*.helper` may own the audio.
- Confirm with `mdls -name kMDItemCFBundleIdentifier <app>` during manual QA.

### 3.7 Rust bindings: which crate to use

| Crate | Tap/aggregate/process symbols | Notes |
|---|---|---|
| **objc2-core-audio 0.3.2** | **All 54 symbols checked are present**, including `AudioHardwareCreateProcessTap/DestroyProcessTap`, `CATapDescription`, every `kAudioAggregateDevice*Key`/`kAudioSub*Key`, `kAudioTapProperty{UID,Format,Description}`, `kAudioHardwarePropertyProcessObjectList`, `kAudioProcessProperty{PID,BundleID,IsRunningInput,IsRunningOutput,Devices}`, `kAudioDevicePropertyDeviceIsRunningSomewhere`, `AudioObjectAddPropertyListenerBlock`, `AudioDeviceCreateIOProcIDWithBlock` ([all items](https://docs.rs/objc2-core-audio/0.3.2/objc2_core_audio/all.html)) | Thin, header-generated, part of objc2, the same family cpal uses. Default features include `AudioHardware`, `HostTime`, `block2`, `dispatch2`, `objc2`, `objc2-foundation`, `objc2-core-audio-types` ([Cargo.toml](https://docs.rs/crate/objc2-core-audio/0.3.2/source/Cargo.toml)). **Recommended** |
| cidre 0.29.0 | `core_audio::{TapDesc, Tap, TapGuard, AggregateDevice, Process}`. `TapDesc::with_stereo_global_tap_excluding_processes(&Array<Number>)`, `create_process_tap() -> Result<TapGuard>` ([TapDesc](https://docs.rs/cidre/0.29.0/cidre/core_audio/struct.TapDesc.html)) | Nicer API. But the README calls it a "personal research project" with "No legacy platforms support" ([README](https://raw.githubusercontent.com/yury/cidre/main/README.md)), and docs.rs builds aarch64 only, so Intel Macs are **UNVERIFIED** |
| coreaudio-sys 0.2.18 | Generated by bindgen against the local SDK at build time, so availability depends on the SDK. docs.rs build failed | Needs libclang. In maintenance mode |

---

## 4. Cross-platform crates

- **cpal 0.18.2:**
  - WASAPI loopback works through an input stream on a render device (§2.4).
  - macOS loopback since 0.17.0, "on macOS > 14.6"; 0.18.0 fixed its silence and auto-start problems.
  - WASAPI default-device rerouting arrived in 0.18.0, then the `DeviceChanged` report was reverted in 0.18.2 ([CHANGELOG](https://raw.githubusercontent.com/RustAudio/cpal/master/CHANGELOG.md)).
  - **Verdict: skip it.** It cannot exclude our own process on either OS, and it adds a second clock model.
- **hound 3.5.1:**
  - `WavWriter::create(path, WavSpec) -> Result<WavWriter<BufWriter<File>>>`, `write_sample<S: Sample>`, `flush()` (makes the file valid up to that point), `finalize()`. On drop, errors "cannot be observed" ([WavWriter](https://docs.rs/hound/3.5.1/hound/struct.WavWriter.html)).
  - `WavSpec { channels: u16, sample_rate: u32, bits_per_sample: u16, sample_format: SampleFormat }` ([WavSpec](https://docs.rs/hound/3.5.1/hound/struct.WavSpec.html)).
  - Call `flush()` every few seconds so a crash still leaves a playable file.
- **rubato 5.0.1** ([crate docs](https://docs.rs/rubato/5.0.1/rubato/index.html)):
  - Buffers go through `audioadapter` adapters.
  - `Fft::new(sample_rate_input, sample_rate_output, chunk_size, nbr_channels, fixed: FixedSync)` for a fixed 48k→16k conversion (macOS) ([Fft](https://docs.rs/rubato/5.0.1/rubato/struct.Fft.html)).
  - `Async::new_poly(resample_ratio, max_resample_ratio_relative, PolynomialDegree, chunk_size, nbr_channels, FixedAsync)` / `new_sinc(..)`, with `Adjustable::set_resample_ratio_relative(rel, ramp)` for drift ([Async](https://docs.rs/rubato/5.0.1/rubato/struct.Async.html)).
  - **`Slip::new(chunk_size, nbr_channels, FixedAsync)`** "occasionally insert[s] or drop[s] a single frame" with a crossfade. It handles about ±0.8% and is meant for "a few ppm" of drift in a feedback loop ([Slip](https://docs.rs/rubato/5.0.1/rubato/struct.Slip.html)).
  - On Windows, WASAPI autoconvert delivers 16 kHz already, so rubato is only needed for drift there.

---

## 5. Encoding the upload

**What providers accept:**
- AssemblyAI pre-recorded accepts `.ogg/.oga/.mogg`, `.opus`, `.flac`, `.wav`, among others. It "converts all files to 16khz uncompressed audio" ([FAQ](https://www.assemblyai.com/docs/faq/what-audio-and-video-file-types-are-supported-by-your-api)).
  - Multichannel is `"multichannel": true`. The response adds `audio_channels`, and channels number from 1 ([multichannel](https://www.assemblyai.com/docs/pre-recorded-audio/multichannel)).
  - It warns that lossy compression "may impact transcription accuracy" ([downsampling guide](https://www.assemblyai.com/docs/pre-recorded-audio/guides/downsampling)).
  - Left = "1", right = "2" (see `docs/research/stt-assemblyai.md`).
- ElevenLabs uses `use_multi_channel: true`, at most 5 channels, and must have `diarize: false`. It suggests lossless for best quality ([guide](https://elevenlabs.io/docs/eleven-api/guides/how-to/speech-to-text/batch/multichannel-transcription)).
  - "All major audio and video formats are supported" ([API](https://elevenlabs.io/docs/api-reference/speech-to-text/convert)).

**Recommended format: Ogg Opus, two independent mono streams.**
- Opus stereo (one coupled stream) uses joint and intensity stereo tools; `MSEncoder::set_phase_inversion_disabled` exists precisely "for intensity stereo" ([MSEncoder](https://docs.rs/opus/0.4.0/opus/struct.MSEncoder.html)). With one speaker per channel, channel separation matters.
- So encode **N=2 streams, M=0 coupled**: `MSEncoder::new(16_000, 2, 0, &[0, 1], Application::Voip) -> Result<MSEncoder>`.
- In libopus, uncoupled streams are mono; mapping value i (when ≥ coupled_streams) means "encoded as mono in stream (i − coupled_streams)" ([multistream API](https://opus-codec.org/docs/opus_api-1.5/group__opus__multistream.html)).
- Allowed Fs values are 8000, 12000, 16000, 24000, 48000. A frame is 2.5/5/10/20/40/60 ms, and `max_data_bytes` 4000 is recommended ([encoder API](https://opus-codec.org/docs/opus_api-1.5/group__opus__encoder.html)).
- At 16 kHz a 20 ms frame is 320 samples per channel, so 640 interleaved f32 go into `encode_float`.
- Bitrate:
  - RFC 6716's sweet spot for **WB speech is 16–20 kbit/s** at 20 ms ([RFC 6716 §2.1.1](https://www.rfc-editor.org/rfc/rfc6716.html#section-2.1.1)).
  - For accuracy headroom, use **`Bitrate::Bits(32_000)`** per stream, which is 64 kbit/s total, about **28.8 MB/h**. Settle on 24 k (21.6 MB/h) after an accuracy A/B test. Nothing published quantifies the WER cost.
  - Also set `set_vbr(true)` and `set_signal(Signal::Voice)` ([Encoder](https://docs.rs/opus/0.4.0/opus/struct.Encoder.html), [enums](https://docs.rs/opus/0.4.0/opus/enum.Bitrate.html)).
- Ogg mapping ([RFC 7845](https://www.rfc-editor.org/rfc/rfc7845.html)):
  - **OpusHead** = `"OpusHead"`, version 1, channels 2, pre-skip (u16 LE, **48 kHz units**), input rate 16000 (u32 LE), gain 0 (i16 LE), **family 1**, then N=2, M=0, mapping `[0,1]`.
  - Family 1 with 2 channels means "stereo (left, right)", so channel 0 (mic) is left.
  - **OpusTags** = `"OpusTags"`, vendor length + vendor, comment count 0.
  - The ID header goes alone on page 1 with BOS set. The comment header must end its page. Both pages have granule 0.
  - Audio granule = cumulative **48 kHz** samples, *including* pre-skip. The last page's granule trims padding.
- Pre-skip: libopus reports lookahead = `Fs/400 + delay_compensation` (`Fs/250`) samples **at the encoder rate** ([opus_encoder.c](https://raw.githubusercontent.com/xiph/opus/main/src/opus_encoder.c)). At 16 kHz that is 104, so pre-skip = 104 × 3 = **312**.
- ogg 0.9.2:
  - `PacketWriter::new(w)`, `write_packet(pck, serial: u32, inf: PacketWriteEndInfo, absgp: u64)`.
  - `PacketWriteEndInfo::{NormalPacket, EndPage, EndStream}` ([PacketWriter](https://docs.rs/ogg/0.9.2/ogg/writing/struct.PacketWriter.html), [enum](https://docs.rs/ogg/0.9.2/ogg/writing/enum.PacketWriteEndInfo.html)).
- **Build cost:**
  - `opus` → `opusic-sys` → **cmake** plus a C compiler (MSVC Build Tools on Windows, Xcode CLT on macOS).
  - Setting `OPUS_LIB_DIR` links a prebuilt library instead ([README](https://docs.rs/crate/opusic-sys/0.7.3/source/README.md)).
  - Put this in `desktop/README.md` as a prerequisite, or put `opus` behind a feature.
- **UNVERIFIED:**
  - That AssemblyAI and ElevenLabs decode family-1 multistream Ogg Opus correctly. Add a manual-QA upload test.
  - If either fails, fall back to FLAC. Do not fall back to family-0 coupled stereo.

**FLAC fallback (lossless, pure Rust):**
- `flacenc::config::Encoder::default().into_verified()`, then `source::MemSource::from_samples(samples /*&[i32] interleaved*/, channels, bits_per_sample, sample_rate)`, then `encode_with_fixed_block_size(&cfg, src, cfg.block_size)`, then `stream.write(&mut bitsink::ByteSink::new())` ([crate docs](https://docs.rs/flacenc/0.5.1/flacenc/index.html)).
- Raw 16 kHz 16-bit stereo is 512 kbit/s, which is 230 MB/h. FLAC on speech roughly halves that (estimate, **UNVERIFIED**).

**Local QA artefacts:** write `mic.wav` + `system.wav` (mono, 16 kHz, i16 or f32) with hound, then build the upload file from them. The acceptance test compares their lengths.

---

## 6. Two-channel alignment

**Clocks:**
- Windows: `GetBuffer`'s `*pu64QPCPosition` is the performance counter "at the time that the audio endpoint device recorded the device position of the first audio frame", converted to **100 ns units** as `10,000,000·t/f` ([GetBuffer](https://learn.microsoft.com/en-us/windows/win32/api/audioclient/nf-audioclient-iaudiocaptureclient-getbuffer)).
  - This is wasapi's `BufferInfo.timestamp`, and both channels share that clock.
- macOS:
  - The `inInputTime.mHostTime` argument of the IOProc, converted with `AudioConvertHostTimeToNanos`.
  - Inside one aggregate, both channels arrive in the same callback.

**Flags:**
- `AUDCLNT_BUFFERFLAGS_SILENT`: "Treat all of the data in the packet as silence and ignore the actual data values" → zero-fill.
- `DATA_DISCONTINUITY`: "not correlated with the previous packet's device position" → re-anchor from the timestamp.
- `TIMESTAMP_ERROR` → do not trust the timestamp; extrapolate from the previous one ([flags](https://learn.microsoft.com/en-us/windows/win32/api/audioclient/ne-audioclient-_audclnt_bufferflags)).
- OBS also zero-fills on `SILENT` (win-wasapi.cpp).

**Silence and gaps:**
- Endpoint loopback produces **no packets** while nothing plays (§2.3).
- Process loopback "receives silence" (README). Whether it keeps raising events in exclude mode during total silence is **UNVERIFIED**. Treat it like endpoint loopback either way.
- Every device reopen leaves a gap.

**Drift:**
- Mic and render hardware run on different crystals. Even with WASAPI SRC, each stream's sample count follows its own device clock.
- Do not assume a number; **measure** it: regress frames against timestamps per channel.
- rubato frames `Slip` around "a few ppm" (rubato docs). At 16 kHz, 100 ppm over 1 h is 5.76 k samples (360 ms). That is why sample counting alone fails the ±50 ms alignment target.
- On macOS, aggregate drift compensation (`"drift": true`) handles this inside the HAL.

**Algorithm (pure, deterministic):**
1. Pick T0 = the first timestamp seen on either channel. Each packet's target frame is `round((ts − T0) · rate / 1e7)` on Windows (or `/1e9` with nanoseconds).
2. If target > write cursor + tolerance (half a period, about 5 ms), write zeros up to the target. If target < cursor − tolerance, drop the overlapping head of the packet. Otherwise append, and absorb the sub-tolerance jitter.
3. Every N seconds, estimate the slope (frames per tick) per channel. If |ppm| > 20, feed `Slip`/`Async::set_resample_ratio_relative` so the written sample rate matches the timeline.
4. On stop, pad the shorter channel with zeros to `max(len_mic, len_sys)`, so the lengths are equal.
5. Interleave L = mic and R = system for the encoder.

**Pure functions worth unit tests** (no devices needed):
- `supports_process_loopback(build)`
- `hns_to_frames(delta_hns, rate)`
- `nanos_to_frames`
- `place_packet(cursor, target, len, tol) -> {pad, skip, take}`
- `apply_silent_flag`
- `downmix_to_mono`
- `f32_to_i16` (clip)
- `estimate_ppm(points)` (least squares)
- `pad_to_equal(a, b)`
- `interleave(mic, sys)`
- `opus_head(pre_skip, rate, family=1, N=2, M=0, map=[0,1])` with golden bytes
- `opus_tags(vendor)`
- `granule_for(samples_16k, pre_skip)` (×3)
- `classify_app(exe | pfn | bundle_id) -> SourceApp`
- `consent_key_to_path("C:#…#chrome.exe")`
- `filetime_to_unix`
- the mic-in-use state machine: Idle→Candidate(≥3 s)→Recording→Releasing(≥5 s grace)→Stop, ignoring our own PIDs
- the device-change debounce, collapsing three role callbacks into one reopen

---

## Recommendations for EchoLedger

**Module layout** (`desktop/src-tauri/src/audio/`). Platform code stays thin; everything testable is pure:

```
audio/
  mod.rs            CaptureSession (start/stop), CaptureConfig, CaptureEvent; picks backend via cfg
  types.rs          Channel{Mic,System}, Chunk{channel, samples:Vec<f32>, ts_ns:u64, flags}, AudioError
  timeline.rs       place_packet, gap fill, overlap drop, T0 anchoring                (pure, tested)
  drift.rs          estimate_ppm, correction policy; drives rubato::Slip                (pure + rubato)
  align.rs          pad_to_equal, interleave, downmix, f32_to_i16                       (pure, tested)
  writer/{wav.rs, opus_ogg.rs, flac.rs}   hound; OpusHead/OpusTags/granule builders (pure, tested); flacenc
  detect/
    state.rs        MicUseStateMachine (debounce, own-PID filter)                       (pure, tested)
    classify.rs     exe/PFN/bundle-id → source_app                                      (pure, tested)
    win_sessions.rs   #[cfg(windows)] wasapi session poll over all capture endpoints
    win_consent.rs    #[cfg(windows)] ConsentStore reader + RegNotifyChangeKeyValue (secondary)
    mac_process.rs    #[cfg(target_os = "macos")] DeviceIsRunningSomewhere listener + process-object poll
  platform/
    windows/{mod.rs, os_version.rs, loopback.rs (process→endpoint fallback), mic.rs, devices.rs}
    macos/{mod.rs, tap.rs, aggregate.rs, ioproc.rs, listeners.rs}
  mock.rs           deterministic fake source (tone + gaps + drift) for cargo tests and the acceptance test
```

**Threading:**
- One dedicated OS thread per capture stream, each calling `initialize_mta()`.
- One more MTA thread for sessions and device events.
- The Tauri UI thread never touches WASAPI.
- On macOS, keep a private serial `DispatchQueue`.
- The IOProc only copies into an SPSC ring. Encoding and writing happen on a worker thread.

**Minimal crate set:**

```toml
[dependencies]
hound = "3.5.1"
rubato = "5.0.1"
ogg = "0.9.2"
flacenc = "0.5.1"
opus = { version = "0.4.0", optional = true }   # needs cmake + C compiler (opusic-sys bundled libopus 1.6.1)

[target.'cfg(windows)'.dependencies]
wasapi = "0.26.0"
windows = { version = "0.62.2", features = [    # same minor as wasapi's, so it unifies
  "Wdk_System_SystemServices", "Win32_System_SystemInformation", "Win32_Foundation",
  "Win32_Media_Audio", "Win32_System_Registry", "Win32_System_Threading",
  "Win32_Storage_Packaging_Appx" ] }            # Appx feature name inferred from the module path; cargo check

[target.'cfg(target_os = "macos")'.dependencies]
objc2 = "0.6.5"
objc2-core-audio = "0.3.2"                      # defaults include AudioHardware, HostTime, block2, dispatch2
objc2-core-audio-types = "0.3.2"
objc2-foundation = "0.3.2"                      # NSArray, NSNumber(NSValue), NSUUID, NSProcessInfo
objc2-core-foundation = "0.3.2"                 # CFString, CFDictionary, CFArray, CFBoolean(CFNumber)
block2 = "0.6.2"
dispatch2 = "0.3.1"
```

**Manual QA lines to add to `docs/MANUAL_QA.md`:**
- Win11: play a sound from EchoLedger itself during capture. It must be **absent** from `system.wav`, which proves EXCLUDE mode.
- Win10 22H2: confirm the fallback to endpoint loopback, and that 30 s of silence with no playback still produces aligned, equal-length files.
- Unplug a headset mid-call on both OSes.
- macOS: first-run prompt for "System Audio Recording" and Microphone.
- macOS: denied permission, which should give a silent tap (**UNVERIFIED**). Show a hint after N seconds of digital zero while output is running.
- Bluetooth HFP switch.
- Verify the mic-vs-tap buffer index mapping.
- Upload a family-1 Ogg Opus file to AssemblyAI and to ElevenLabs.

---

## Gotchas

1. **The wasapi `include_tree` docstring is wrong.** `false` gives EXCLUDE mode (source), not "only the target process". The Windows API has no target-only mode.
2. **Process-loopback clients break several methods:** no `GetMixFormat` (E_NOTIMPL), garbage `GetBufferSize`, no `IAudioClock`, no `IAudioSessionControl`. Pass an explicit format with autoconvert, and use QPC from `GetBuffer`.
3. **wasapi copies bytes even when `SILENT` is set.** Zero-fill them yourself.
4. **Endpoint loopback goes quiet when nothing plays.** No packets arrive, so sample counting drifts. Fill gaps from timestamps.
5. **Shared event mode needs `hnsBufferDuration = hnsPeriodicity = 0`.** Drain every packet on each wake (`GetNextPacketSize` loop).
6. **Three role callbacks per user device change.** Debounce, never block, and never (un)register from inside a callback.
7. **The ActivateAudioInterfaceAsync completion handler** must outlive the callback. `#[implement]` objects are agile by default (windows-implement 0.60.2).
8. **Do not use windows-rs master (0.100.0) or windows-core 0.100.0** next to `windows` 0.62.2. Release 74 renamed `Ref`→`InRef`; keep everything on 0.62.x.
9. **Session notifications need MTA and a first `GetCount()` call.** The session enumerator can miss new sessions, so keep your own list or re-enumerate.
10. **The ConsentStore registry is undocumented and can change.** Its "in-use" semantics are UNVERIFIED, and our own exe appears there.
11. **The same app must not trigger two recordings.** For example, Chrome's mic session is held by a utility child, so a renderer PID ≠ the audio PID. Key decisions on the image name or PFN, not the PID.
12. **On macOS there is no public permission preflight.** The prompt appears on the first `AudioDeviceStart` of an aggregate containing a tap, so tie it to a user click ("no disturbance" rule).
13. **`tapautostart` must be true**, or capture is silent (cpal 0.18.0 fix). Use a unique aggregate UID per instance, and keep it private.
14. **`kAudioProcessPropertyIsRunningInput` listeners may never fire** (macOS 15 report). Poll instead.
15. **`DeviceIsRunningSomewhere` stays at 1 while we record**, so use process-level state to detect "call ended".
16. **Apple's availability metadata for CATapDescription is unreliable** (12.0/13.0/14.0). The functions are 14.2+, and `bundleIDs`/`isProcessRestoreEnabled` are 26.0+.
17. **`opus` 0.4.0 changed its sys crate to `opusic-sys`**, which needs cmake. CI images need cmake plus a C compiler.
18. **Opus pre-skip is in 48 kHz units**, but libopus lookahead is reported at the encoder rate (×3 at 16 kHz).
19. **Endpoint loopback (Win10 fallback) captures EchoLedger's own sounds.** Keep the app silent while recording.

---

## Load-bearing claims

| # | Claim | Source | Confidence |
|---|---|---|---|
| 1 | Process loopback needs build ≥ 20348 (one page says 20438). No Windows 10 client reaches it (max 19045); all Windows 11 builds do (≥ 22000) | [params](https://learn.microsoft.com/en-us/windows/win32/api/audioclientactivationparams/ns-audioclientactivationparams-audioclient_activation_params), [README](https://raw.githubusercontent.com/microsoft/Windows-classic-samples/main/Samples/ApplicationLoopback/README.md), [Win10 info](https://learn.microsoft.com/en-us/windows/release-health/release-information) | high |
| 2 | `wasapi` 0.26.0 `new_application_loopback_client(pid, false)` = `PROCESS_LOOPBACK_MODE_EXCLUDE_TARGET_PROCESS_TREE` | [src 0.26.0](https://docs.rs/wasapi/0.26.0/src/wasapi/api.rs.html) | high |
| 3 | Process loopback is endpoint-independent and yields silence when the target renders nothing | [README](https://raw.githubusercontent.com/microsoft/Windows-classic-samples/main/Samples/ApplicationLoopback/README.md) | high |
| 4 | App-loopback clients: `GetMixFormat`, `IsFormatSupported`, `GetCurrentPadding` not implemented; no `IAudioClock`. Explicit format + `AUTOCONVERTPCM` required | [wasapi docs](https://docs.rs/wasapi/0.26.0/wasapi/struct.AudioClient.html), [MS sample](https://raw.githubusercontent.com/microsoft/Windows-classic-samples/main/Samples/ApplicationLoopback/cpp/LoopbackCapture.cpp) | high (empirical, crate-documented) |
| 5 | Endpoint loopback delivers no data while nothing plays; event loopback works from Win10 1703 | [van Eerde](https://matthewvaneerde.wordpress.com/2008/12/16/sample-wasapi-loopback-capture-record-what-you-hear/), [OBS](https://raw.githubusercontent.com/obsproject/obs-studio/master/plugins/win-wasapi/win-wasapi.cpp), [Loopback Recording](https://learn.microsoft.com/en-us/windows/win32/coreaudio/loopback-recording) | high (behaviour) / medium (still true on 22H2) |
| 6 | `GetBuffer` QPC position = 100 ns units of the first frame; `SILENT` means ignore the data. wasapi passes the timestamp through but does not zero-fill | [GetBuffer](https://learn.microsoft.com/en-us/windows/win32/api/audioclient/nf-audioclient-iaudiocaptureclient-getbuffer), [flags](https://learn.microsoft.com/en-us/windows/win32/api/audioclient/ne-audioclient-_audclnt_bufferflags), [wasapi src](https://raw.githubusercontent.com/HEnquist/wasapi-rs/master/src/api.rs) | high |
| 7 | Session approach: `GetCount()` must precede notifications; MTA required; `GetProcessId` gives PID | [RegisterSessionNotification](https://learn.microsoft.com/en-us/windows/win32/api/audiopolicy/nf-audiopolicy-iaudiosessionmanager2-registersessionnotification), [GetProcessId](https://learn.microsoft.com/en-us/windows/win32/api/audiopolicy/nf-audiopolicy-iaudiosessioncontrol2-getprocessid) | high |
| 8 | Taps: `AudioHardwareCreateProcessTap` macOS 14.2+; `NSAudioCaptureUsageDescription` required; prompt on first recording; no public preflight | [create](https://developer.apple.com/tutorials/data/documentation/coreaudio/audiohardwarecreateprocesstap(_:_:).json), [sample](https://developer.apple.com/documentation/coreaudio/capturing-system-audio-with-core-audio-taps.md), [AudioCap SPI](https://raw.githubusercontent.com/insidegui/AudioCap/main/AudioCap/ProcessTap/AudioRecordingPermission.swift) | high |
| 9 | Aggregate keys `taps`/`subdevices`/`master`/`private`/`tapautostart`/`uid`/`drift`; AudioCap dictionary works; `tapautostart` needed | [AudioHardware.rs](https://docs.rs/objc2-core-audio/0.3.2/src/objc2_core_audio/generated/AudioHardware.rs.html), [ProcessTap.swift](https://raw.githubusercontent.com/insidegui/AudioCap/main/AudioCap/ProcessTap/ProcessTap.swift), [cpal CHANGELOG](https://raw.githubusercontent.com/RustAudio/cpal/master/CHANGELOG.md) | high (keys) / **low** (mic + tap in one aggregate: untested) |
| 10 | objc2-core-audio 0.3.2 exposes every tap, aggregate, process-object and IOProc symbol needed | [all.html](https://docs.rs/objc2-core-audio/0.3.2/objc2_core_audio/all.html) | high |
| 11 | `opus` 0.4.0 → `opusic-sys` 0.7.3 (libopus 1.6.1, cmake); `MSEncoder::new(rate, streams, coupled, &mapping, app)` gives uncoupled mono streams | [opus Cargo.toml](https://docs.rs/crate/opus/0.4.0/source/Cargo.toml), [opusic README](https://docs.rs/crate/opusic-sys/0.7.3/source/README.md), [MSEncoder](https://docs.rs/opus/0.4.0/opus/struct.MSEncoder.html) | high |
| 12 | AssemblyAI accepts Ogg/Opus/FLAC and converts to 16 kHz; ElevenLabs multichannel ≤5 ch needs `diarize:false` | [AAI FAQ](https://www.assemblyai.com/docs/faq/what-audio-and-video-file-types-are-supported-by-your-api), [EL guide](https://elevenlabs.io/docs/eleven-api/guides/how-to/speech-to-text/batch/multichannel-transcription) | high (formats) / medium (family-1 multistream decode) |

---

## Open questions

1. Does one aggregate with {output (main), default input (drift), tap (drift)} deliver mic and tap buffers in one IOProc with a stable buffer order? If it does not, fall back to two devices aligned by `mHostTime`. **Hardware test.**
2. With permission denied, does a tap return digital silence, or does `AudioDeviceStart` fail? This decides the UX hint.
3. In exclude mode, during total system silence, does WASAPI process loopback keep signalling events (silent packets), or stall like endpoint loopback?
4. Is `ConsentStore …LastUsedTimeStop == 0` a reliable "in use now" flag, and what is its update latency?
5. Do AssemblyAI and ElevenLabs decode Ogg Opus channel-mapping family 1 (N=2, M=0) and keep left/right channel order? If not, use FLAC.
6. Which default role (Console vs Communications) do WhatsApp Desktop, Zoom and Chrome use for capture, on typical setups? This drives which default the mic follows.
7. How do we distinguish Google Meet from other browser mic use (window title, or a companion extension)? Is that acceptable for v1, or should all browser mic use be labelled `other`?
8. Is there a separate WhatsApp Business desktop app on Windows or macOS, and what is its PFN or bundle ID?
9. Should the macOS floor be 14.2 (Apple's API floor) or 14.4 (spec, AudioCap)? Is there a known 14.2/14.3 tap bug that justifies 14.4?
10. Does an `#[implement]` IMMNotificationClient need anything beyond the default agile behaviour when it is registered from an MTA worker? (Expected: no.)
