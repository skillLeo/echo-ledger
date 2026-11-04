//! Windows.Graphics.Capture (Windows 10 1903 and later) through the `windows` crate: a free-threaded frame pool of the
//! meeting window (`IGraphicsCaptureItemInterop::CreateForWindow`) or of a monitor (`CreateForMonitor`), no cursor,
//! frames at most twice a second (`MinUpdateInterval`, Windows 11 24H2). Its frame handler only keeps the newest frame;
//! the capture thread copies that one into a D3D11 staging texture and reads it back once a second. On Windows 11 the
//! capture asks for no yellow border (`GraphicsCaptureAccess::RequestAccessAsync(Borderless)`, then
//! `IsBorderRequired = false`); Windows 10 always draws it, and Settings says so.

use std::collections::HashMap;
use std::sync::{Arc, Mutex, OnceLock};

use windows::Foundation::{TimeSpan, TypedEventHandler};
use windows::Graphics::Capture::{Direct3D11CaptureFrame, Direct3D11CaptureFramePool, GraphicsCaptureAccess, GraphicsCaptureAccessKind, GraphicsCaptureItem, GraphicsCaptureSession};
use windows::Graphics::DirectX::Direct3D11::IDirect3DDevice;
use windows::Graphics::DirectX::DirectXPixelFormat;
use windows::Graphics::SizeInt32;
use windows::Security::Authorization::AppCapabilityAccess::AppCapabilityAccessStatus;
use windows::Win32::Foundation::{HMODULE, HWND, LPARAM, POINT, RECT};
use windows::Win32::Graphics::Direct3D::{D3D_DRIVER_TYPE_HARDWARE, D3D_DRIVER_TYPE_WARP};
use windows::Win32::Graphics::Direct3D11::{
    D3D11_CPU_ACCESS_READ, D3D11_CREATE_DEVICE_BGRA_SUPPORT, D3D11_MAP_READ, D3D11_MAPPED_SUBRESOURCE, D3D11_SDK_VERSION, D3D11_TEXTURE2D_DESC, D3D11_USAGE_STAGING, D3D11CreateDevice,
    ID3D11Device, ID3D11DeviceContext, ID3D11Texture2D,
};
use windows::Win32::Graphics::Dwm::{DWMWA_CLOAKED, DWMWA_EXTENDED_FRAME_BOUNDS, DwmGetWindowAttribute};
use windows::Win32::Graphics::Dxgi::IDXGIDevice;
use windows::Win32::Graphics::Gdi::{HMONITOR, MONITOR_DEFAULTTONEAREST, MONITOR_DEFAULTTOPRIMARY, MonitorFromPoint, MonitorFromWindow};
use windows::Win32::System::WinRT::Direct3D11::{CreateDirect3D11DeviceFromDXGIDevice, IDirect3DDxgiInterfaceAccess};
use windows::Win32::System::WinRT::Graphics::Capture::IGraphicsCaptureItemInterop;
use windows::Win32::System::WinRT::{RO_INIT_MULTITHREADED, RoInitialize};
use windows::Win32::UI::WindowsAndMessaging::{EnumWindows, GWL_EXSTYLE, GetWindowLongPtrW, GetWindowRect, GetWindowThreadProcessId, InternalGetWindowText, IsIconic, IsWindowVisible, WS_EX_TOOLWINDOW};
use windows::core::{BOOL, Interface, Ref};

use super::{Frame, Grab, Target, Wanted};
use crate::audio::platform::windows::{descends_from, os_build, parent_map, process_identity, resolve_root_with};
use crate::screens::privacy::{HoldReason, Pick, WindowFacts, is_excluded, pick_meeting_window, screen_shows_excluded};

/// The first build with `GraphicsCaptureAccess` and `IsBorderRequired` (Windows Server 2022 / Windows 11).
const BORDERLESS_BUILD: u32 = 20_348;
/// Frames the system may hand over, at most (100 ns units): twice a second.
const MIN_UPDATE_INTERVAL: i64 = 5_000_000;

pub fn thread_init() {
    // Already initialised (for instance as single-threaded) is fine: the capture objects are free-threaded.
    let _ = unsafe { RoInitialize(RO_INIT_MULTITHREADED) };
}

pub fn supported() -> bool {
    static SUPPORTED: OnceLock<bool> = OnceLock::new();
    *SUPPORTED.get_or_init(|| GraphicsCaptureSession::IsSupported().unwrap_or(false))
}

pub fn borderless_supported() -> bool {
    os_build().is_some_and(|b| b >= BORDERLESS_BUILD)
}

/// Asked once per run: may the capture go without the yellow border? Unpackaged apps are allowed without a prompt on
/// Windows 11; older systems have no such request.
fn borderless_allowed() -> bool {
    static ALLOWED: OnceLock<bool> = OnceLock::new();
    *ALLOWED.get_or_init(|| {
        borderless_supported()
            && GraphicsCaptureAccess::RequestAccessAsync(GraphicsCaptureAccessKind::Borderless).and_then(|op| op.join()).is_ok_and(|s| s == AppCapabilityAccessStatus::Allowed)
    })
}

/// One visible top-level window of another process.
struct Win {
    hwnd: isize,
    pid: u32,
    monitor: isize,
    facts: WindowFacts,
}

/// Every visible top-level window of other processes, with its state. Titles are read with `InternalGetWindowText`,
/// which sends no window message (see `audio::platform::windows::visible_window_titles`).
fn windows_now() -> Vec<Win> {
    struct Collect {
        own: u32,
        buf: Vec<u16>,
        out: Vec<Win>,
    }

    unsafe extern "system" fn each(hwnd: HWND, lparam: LPARAM) -> BOOL {
        // SAFETY: `lparam` is the `&mut Collect` passed to EnumWindows below, alive for the whole enumeration.
        let ctx = unsafe { &mut *(lparam.0 as *mut Collect) };
        if !unsafe { IsWindowVisible(hwnd) }.as_bool() {
            return BOOL::from(true);
        }
        let mut pid = 0u32;
        unsafe { GetWindowThreadProcessId(hwnd, Some(&mut pid)) };
        if pid == 0 || pid == ctx.own {
            return BOOL::from(true);
        }
        let copied = unsafe { InternalGetWindowText(hwnd, &mut ctx.buf) };
        let title = if copied > 0 { String::from_utf16_lossy(&ctx.buf[..copied as usize]) } else { String::new() };
        let minimised = unsafe { IsIconic(hwnd) }.as_bool();
        let mut cloaked = 0u32;
        // SAFETY: DWMWA_CLOAKED writes one DWORD into `cloaked`.
        let _ = unsafe { DwmGetWindowAttribute(hwnd, DWMWA_CLOAKED, (&mut cloaked as *mut u32).cast(), std::mem::size_of::<u32>() as u32) };
        let mut rect = RECT::default();
        // SAFETY: DWMWA_EXTENDED_FRAME_BOUNDS writes one RECT (the window without its invisible resize borders).
        if unsafe { DwmGetWindowAttribute(hwnd, DWMWA_EXTENDED_FRAME_BOUNDS, (&mut rect as *mut RECT).cast(), std::mem::size_of::<RECT>() as u32) }.is_err() {
            let _ = unsafe { GetWindowRect(hwnd, &mut rect) };
        }
        let area = i64::from((rect.right - rect.left).max(0)) * i64::from((rect.bottom - rect.top).max(0));
        let ex_style = unsafe { GetWindowLongPtrW(hwnd, GWL_EXSTYLE) } as u32;
        let monitor = unsafe { MonitorFromWindow(hwnd, MONITOR_DEFAULTTONEAREST) };
        ctx.out.push(Win {
            hwnd: hwnd.0 as isize,
            pid,
            monitor: monitor.0 as isize,
            facts: WindowFacts { title, process: String::new(), visible: true, minimised, cloaked: cloaked != 0, area, tool: ex_style & WS_EX_TOOLWINDOW.0 != 0 },
        });
        BOOL::from(true)
    }

    let mut ctx = Collect { own: std::process::id(), buf: vec![0u16; 512], out: Vec::new() };
    let _ = unsafe { EnumWindows(Some(each), LPARAM(&mut ctx as *mut Collect as isize)) };
    ctx.out
}

fn primary_monitor() -> isize {
    unsafe { MonitorFromPoint(POINT { x: 0, y: 0 }, MONITOR_DEFAULTTOPRIMARY) }.0 as isize
}

pub fn find_target(wanted: &Wanted<'_>) -> Result<Target, HoldReason> {
    if wanted.meeting.is_some_and(|m| is_excluded(&m.name, wanted.excluded)) {
        return Err(HoldReason::ExcludedApp);
    }
    let mut wins = windows_now();
    let identities: std::cell::RefCell<HashMap<u32, (String, Option<String>)>> = Default::default();
    let identity = |pid: u32| identities.borrow_mut().entry(pid).or_insert_with(|| process_identity(pid)).clone();
    for w in wins.iter_mut() {
        w.facts.process = identity(w.pid).0;
    }
    // The meeting app's windows: the processes of its tree (a Zoom window opened by Chrome is Zoom's own tree).
    let meeting_window = wanted.meeting.map(|m| {
        let parents = parent_map();
        let tree: Vec<&Win> = wins.iter().filter(|w| descends_from(w.pid, m.root_pid, &parents) && resolve_root_with(w.pid, &parents, identity) == m.root_pid).collect();
        let facts: Vec<WindowFacts> = tree.iter().map(|w| w.facts.clone()).collect();
        match pick_meeting_window(m.app, m.browser, &facts, wanted.site_rules) {
            Pick::Window(i) => Ok((tree[i].hwnd, tree[i].monitor)),
            Pick::Hold(reason) => Err(reason),
        }
    });
    if wanted.mode != crate::screens::privacy::WHOLE_SCREEN {
        return match meeting_window {
            Some(Ok((hwnd, _))) => Ok(Target::Window(hwnd)),
            Some(Err(reason)) => Err(reason),
            None => Err(HoldReason::NoWindow),
        };
    }
    // The whole screen the meeting is on (the main screen when its window is not showing).
    let monitor = match meeting_window {
        Some(Ok((_, monitor))) => monitor,
        _ => primary_monitor(),
    };
    let on_screen: Vec<WindowFacts> = wins.into_iter().filter(|w| w.monitor == monitor).map(|w| w.facts).collect();
    if screen_shows_excluded(&on_screen, wanted.excluded) {
        return Err(HoldReason::ExcludedApp);
    }
    Ok(Target::Display(monitor))
}

pub fn open(target: Target) -> Result<Box<dyn Grab>, String> {
    WgcCapture::open(target).map(|c| Box::new(c) as Box<dyn Grab>).map_err(|e| format!("The screen capture could not start: {}", e.message()))
}

fn d3d_device() -> windows::core::Result<(ID3D11Device, ID3D11DeviceContext)> {
    let mut last = windows::core::Error::from(windows::Win32::Foundation::E_FAIL);
    // The graphics card first, the software renderer when there is none (a remote session).
    for driver in [D3D_DRIVER_TYPE_HARDWARE, D3D_DRIVER_TYPE_WARP] {
        let (mut device, mut context) = (None, None);
        match unsafe { D3D11CreateDevice(None, driver, HMODULE::default(), D3D11_CREATE_DEVICE_BGRA_SUPPORT, None, D3D11_SDK_VERSION, Some(&mut device), None, Some(&mut context)) } {
            Ok(()) => {
                if let (Some(device), Some(context)) = (device, context) {
                    return Ok((device, context));
                }
            }
            Err(e) => last = e,
        }
    }
    Err(last)
}

const FORMAT: DirectXPixelFormat = DirectXPixelFormat::B8G8R8A8UIntNormalized;

/// An open Windows.Graphics.Capture session. Lives and dies on the capture thread.
struct WgcCapture {
    target: Target,
    device: ID3D11Device,
    context: ID3D11DeviceContext,
    d3d: IDirect3DDevice,
    _item: GraphicsCaptureItem,
    pool: Direct3D11CaptureFramePool,
    session: GraphicsCaptureSession,
    /// The newest frame, replaced by the frame handler (the one it replaces goes back to the pool).
    latest: Arc<Mutex<Option<Direct3D11CaptureFrame>>>,
    token: i64,
    size: SizeInt32,
    staging: Option<(ID3D11Texture2D, u32, u32)>,
}

impl WgcCapture {
    fn open(target: Target) -> windows::core::Result<Self> {
        let (device, context) = d3d_device()?;
        let dxgi: IDXGIDevice = device.cast()?;
        let d3d: IDirect3DDevice = unsafe { CreateDirect3D11DeviceFromDXGIDevice(&dxgi) }?.cast()?;
        let interop = windows::core::factory::<GraphicsCaptureItem, IGraphicsCaptureItemInterop>()?;
        let item: GraphicsCaptureItem = match target {
            Target::Window(hwnd) => unsafe { interop.CreateForWindow(HWND(hwnd as *mut _)) }?,
            Target::Display(monitor) => unsafe { interop.CreateForMonitor(HMONITOR(monitor as *mut _)) }?,
        };
        let size = item.Size()?;
        let pool = Direct3D11CaptureFramePool::CreateFreeThreaded(&d3d, FORMAT, 2, size)?;
        let latest: Arc<Mutex<Option<Direct3D11CaptureFrame>>> = Arc::new(Mutex::new(None));
        let sink = latest.clone();
        let token = pool.FrameArrived(&TypedEventHandler::new(move |pool: Ref<Direct3D11CaptureFramePool>, _: Ref<windows::core::IInspectable>| {
            if let Some(pool) = pool.as_ref()
                && let Ok(frame) = pool.TryGetNextFrame()
            {
                *sink.lock().unwrap_or_else(|p| p.into_inner()) = Some(frame);
            }
            Ok(())
        }))?;
        let session = pool.CreateCaptureSession(&item)?;
        let _ = session.SetIsCursorCaptureEnabled(false);
        if borderless_allowed() {
            let _ = session.SetIsBorderRequired(false);
        }
        let _ = session.SetMinUpdateInterval(TimeSpan { Duration: MIN_UPDATE_INTERVAL });
        session.StartCapture()?;
        Ok(Self { target, device, context, d3d, _item: item, pool, session, latest, token, size, staging: None })
    }

    /// A CPU-readable texture of the frame texture's size, kept while the size stays.
    fn staging_for(&mut self, desc: &D3D11_TEXTURE2D_DESC) -> windows::core::Result<ID3D11Texture2D> {
        if let Some((texture, w, h)) = &self.staging
            && *w == desc.Width
            && *h == desc.Height
        {
            return Ok(texture.clone());
        }
        let staging_desc = D3D11_TEXTURE2D_DESC {
            Usage: D3D11_USAGE_STAGING,
            BindFlags: 0,
            CPUAccessFlags: D3D11_CPU_ACCESS_READ.0 as u32,
            MiscFlags: 0,
            MipLevels: 1,
            ArraySize: 1,
            ..*desc
        };
        let mut texture = None;
        unsafe { self.device.CreateTexture2D(&staging_desc, None, Some(&mut texture)) }?;
        let texture = texture.ok_or_else(|| windows::core::Error::from(windows::Win32::Foundation::E_FAIL))?;
        self.staging = Some((texture.clone(), desc.Width, desc.Height));
        Ok(texture)
    }

    fn read(&mut self, frame: &Direct3D11CaptureFrame) -> windows::core::Result<Frame> {
        let content = frame.ContentSize()?;
        if content.Width > 0 && content.Height > 0 && (content.Width != self.size.Width || content.Height != self.size.Height) {
            // The window was resized: the next frames come at its new size.
            self.pool.Recreate(&self.d3d, FORMAT, 2, content)?;
            self.size = content;
        }
        let access: IDirect3DDxgiInterfaceAccess = frame.Surface()?.cast()?;
        let texture: ID3D11Texture2D = unsafe { access.GetInterface() }?;
        let mut desc = D3D11_TEXTURE2D_DESC::default();
        unsafe { texture.GetDesc(&mut desc) };
        let width = (content.Width.max(1) as u32).min(desc.Width);
        let height = (content.Height.max(1) as u32).min(desc.Height);
        let staging = self.staging_for(&desc)?;
        unsafe { self.context.CopyResource(&staging, &texture) };
        let mut mapped = D3D11_MAPPED_SUBRESOURCE::default();
        unsafe { self.context.Map(&staging, 0, D3D11_MAP_READ, 0, Some(&mut mapped)) }?;
        let (pitch, row) = (mapped.RowPitch as usize, width as usize * 4);
        let mut bgra = vec![0u8; row * height as usize];
        let source = mapped.pData as *const u8;
        for y in 0..height as usize {
            // SAFETY: the mapped texture holds `height` rows of `pitch` bytes, each at least `row` long.
            unsafe { std::ptr::copy_nonoverlapping(source.add(y * pitch), bgra.as_mut_ptr().add(y * row), row) };
        }
        unsafe { self.context.Unmap(&staging, 0) };
        Ok(Frame { width, height, stride: row, bgra })
    }
}

impl Grab for WgcCapture {
    fn target(&self) -> Target {
        self.target
    }

    fn grab(&mut self) -> Result<Option<Frame>, String> {
        let frame = self.latest.lock().unwrap_or_else(|p| p.into_inner()).clone();
        let Some(frame) = frame else { return Ok(None) };
        self.read(&frame).map(Some).map_err(|e| e.message().to_string())
    }
}

impl Drop for WgcCapture {
    fn drop(&mut self) {
        let _ = self.pool.RemoveFrameArrived(self.token);
        *self.latest.lock().unwrap_or_else(|p| p.into_inner()) = None;
        let _ = self.session.Close();
        let _ = self.pool.Close();
    }
}
