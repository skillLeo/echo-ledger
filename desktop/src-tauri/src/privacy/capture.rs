//! The Share Check's captures of one screen, made the way meeting apps make them and leaving nothing out (the screen
//! moments capture leaves EchoLedger's own windows out; this one must not, or it would prove nothing):
//! - Windows: Windows.Graphics.Capture of the monitor (`CreateForMonitor`, the screen moments code) and DXGI Desktop
//!   Duplication of the same output, the two ways Zoom, Teams, browsers and OBS capture a screen;
//! - Mac: a ScreenCaptureKit display screenshot (`SCScreenshotManager`) with no window excluded.
//!
//! Each gives the picture and where the screen starts and how many pixels a window unit is (to find the two windows in
//! it). The pictures stay in memory.

use crate::screens::platform::Frame;

/// Why a capture gave no picture.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Failure {
    /// macOS Screen Recording is not allowed for EchoLedger.
    Permission,
    /// The capture failed (the message says how, for the diagnostics).
    Capture(String),
}

/// One captured screen.
pub struct Shot {
    /// The server's name of the method: `wgc`, `dxgi` or `sck`.
    pub method: &'static str,
    pub frame: Frame,
    /// The screen's top-left corner in the units windows are placed in (physical pixels on Windows, points on a Mac).
    pub origin: (f64, f64),
    /// Picture pixels per window unit.
    pub scale: f64,
}

/// Every method of this system, on the screen the check's windows are on (the primary one).
pub fn screen_shots() -> Vec<(&'static str, Result<Shot, Failure>)> {
    #[cfg(target_os = "macos")]
    {
        vec![("sck", mac())]
    }
    #[cfg(windows)]
    {
        vec![("wgc", wgc()), ("dxgi", dxgi::primary())]
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        vec![("none", Err(Failure::Capture("Screen capture is not available on this system.".into())))]
    }
}

#[cfg(target_os = "macos")]
fn mac() -> Result<Shot, Failure> {
    if !crate::screens::platform::macos::permission() {
        return Err(Failure::Permission);
    }
    let (frame, display) = crate::screens::platform::macos::main_display_unfiltered().map_err(Failure::Capture)?;
    let scale = if display.size.width > 0.0 { f64::from(frame.width) / display.size.width } else { 1.0 };
    Ok(Shot { method: "sck", frame, origin: (display.origin.x, display.origin.y), scale })
}

#[cfg(windows)]
fn wgc() -> Result<Shot, Failure> {
    use crate::screens::platform::windows as wgc;
    if !wgc::supported() {
        return Err(Failure::Capture("Windows.Graphics.Capture is not supported here.".into()));
    }
    let (monitor, rect) = wgc::primary_monitor_rect();
    let frame = wgc::monitor_frame(monitor).map_err(Failure::Capture)?;
    let width = (rect.right - rect.left).max(1) as f64;
    Ok(Shot { method: "wgc", origin: (f64::from(rect.left), f64::from(rect.top)), scale: f64::from(frame.width) / width, frame })
}

/// DXGI Desktop Duplication of the primary output.
#[cfg(windows)]
pub mod dxgi {
    use std::time::{Duration, Instant};

    use windows::Win32::Foundation::HMODULE;
    use windows::Win32::Graphics::Direct3D::D3D_DRIVER_TYPE_UNKNOWN;
    use windows::Win32::Graphics::Direct3D11::{
        D3D11_CPU_ACCESS_READ, D3D11_CREATE_DEVICE_BGRA_SUPPORT, D3D11_MAP_READ, D3D11_MAPPED_SUBRESOURCE, D3D11_SDK_VERSION, D3D11_TEXTURE2D_DESC, D3D11_USAGE_STAGING, D3D11CreateDevice,
        ID3D11Device, ID3D11DeviceContext, ID3D11Texture2D,
    };
    use windows::Win32::Graphics::Dxgi::{CreateDXGIFactory1, DXGI_ERROR_WAIT_TIMEOUT, DXGI_OUTDUPL_FRAME_INFO, IDXGIAdapter, IDXGIFactory1, IDXGIOutput1, IDXGIResource};
    use windows::core::Interface;

    use super::{Failure, Shot};
    use crate::screens::platform::Frame;

    fn failed(what: &str, e: windows::core::Error) -> Failure {
        Failure::Capture(format!("{what}: {}", e.message()))
    }

    /// The output whose desktop starts at (0, 0) (the primary monitor), with its adapter.
    fn primary_output() -> Result<(IDXGIAdapter, IDXGIOutput1, windows::Win32::Foundation::RECT), Failure> {
        let factory: IDXGIFactory1 = unsafe { CreateDXGIFactory1() }.map_err(|e| failed("DXGI is not available", e))?;
        let mut adapter_index = 0;
        while let Ok(adapter) = unsafe { factory.EnumAdapters1(adapter_index) } {
            let mut output_index = 0;
            while let Ok(output) = unsafe { adapter.EnumOutputs(output_index) } {
                if let Ok(desc) = unsafe { output.GetDesc() } {
                    let r = desc.DesktopCoordinates;
                    if desc.AttachedToDesktop.as_bool() && r.left == 0 && r.top == 0 {
                        let output1: IDXGIOutput1 = output.cast().map_err(|e| failed("Desktop Duplication is not available", e))?;
                        let adapter: IDXGIAdapter = adapter.cast().map_err(|e| failed("DXGI adapter", e))?;
                        return Ok((adapter, output1, r));
                    }
                }
                output_index += 1;
            }
            adapter_index += 1;
        }
        Err(Failure::Capture("No screen to duplicate (no desktop session).".into()))
    }

    fn device(adapter: &IDXGIAdapter) -> Result<(ID3D11Device, ID3D11DeviceContext), Failure> {
        let (mut device, mut context) = (None, None);
        unsafe { D3D11CreateDevice(adapter, D3D_DRIVER_TYPE_UNKNOWN, HMODULE::default(), D3D11_CREATE_DEVICE_BGRA_SUPPORT, None, D3D11_SDK_VERSION, Some(&mut device), None, Some(&mut context)) }
            .map_err(|e| failed("Direct3D could not start", e))?;
        Ok((device.ok_or(Failure::Capture("no Direct3D device".into()))?, context.ok_or(Failure::Capture("no Direct3D context".into()))?))
    }

    pub fn primary() -> Result<Shot, Failure> {
        let (adapter, output, rect) = primary_output()?;
        let (device, context) = device(&adapter)?;
        let duplication = unsafe { output.DuplicateOutput(&device) }.map_err(|e| failed("Desktop Duplication could not start", e))?;
        let deadline = Instant::now() + Duration::from_secs(3);
        // The first frames can come before anything was drawn: take one that carries a desktop image.
        loop {
            let mut info = DXGI_OUTDUPL_FRAME_INFO::default();
            let mut resource: Option<IDXGIResource> = None;
            match unsafe { duplication.AcquireNextFrame(250, &mut info, &mut resource) } {
                Ok(()) => {}
                Err(e) if e.code() == DXGI_ERROR_WAIT_TIMEOUT && Instant::now() < deadline => continue,
                Err(e) => return Err(failed("Desktop Duplication gave no frame", e)),
            }
            let image = (info.LastPresentTime != 0).then_some(resource).flatten();
            let result = image.map(|r| read(&device, &context, &r));
            let _ = unsafe { duplication.ReleaseFrame() };
            match result {
                Some(frame) => {
                    let frame = frame?;
                    let width = (rect.right - rect.left).max(1) as f64;
                    return Ok(Shot { method: "dxgi", origin: (f64::from(rect.left), f64::from(rect.top)), scale: f64::from(frame.width) / width, frame });
                }
                None if Instant::now() < deadline => continue,
                None => return Err(Failure::Capture("Desktop Duplication gave no desktop image.".into())),
            }
        }
    }

    fn read(device: &ID3D11Device, context: &ID3D11DeviceContext, resource: &IDXGIResource) -> Result<Frame, Failure> {
        let texture: ID3D11Texture2D = resource.cast().map_err(|e| failed("Desktop Duplication frame", e))?;
        let mut desc = D3D11_TEXTURE2D_DESC::default();
        unsafe { texture.GetDesc(&mut desc) };
        let staging_desc = D3D11_TEXTURE2D_DESC { Usage: D3D11_USAGE_STAGING, BindFlags: 0, CPUAccessFlags: D3D11_CPU_ACCESS_READ.0 as u32, MiscFlags: 0, MipLevels: 1, ArraySize: 1, ..desc };
        let mut staging = None;
        unsafe { device.CreateTexture2D(&staging_desc, None, Some(&mut staging)) }.map_err(|e| failed("Direct3D staging texture", e))?;
        let staging = staging.ok_or(Failure::Capture("no staging texture".into()))?;
        unsafe { context.CopyResource(&staging, &texture) };
        let mut mapped = D3D11_MAPPED_SUBRESOURCE::default();
        unsafe { context.Map(&staging, 0, D3D11_MAP_READ, 0, Some(&mut mapped)) }.map_err(|e| failed("Direct3D map", e))?;
        let (width, height) = (desc.Width, desc.Height);
        let (pitch, row) = (mapped.RowPitch as usize, width as usize * 4);
        let mut bgra = vec![0u8; row * height as usize];
        let source = mapped.pData as *const u8;
        for y in 0..height as usize {
            // SAFETY: the mapped texture holds `height` rows of `pitch` bytes, each at least `row` long.
            unsafe { std::ptr::copy_nonoverlapping(source.add(y * pitch), bgra.as_mut_ptr().add(y * row), row) };
        }
        unsafe { context.Unmap(&staging, 0) };
        Ok(Frame { width, height, stride: row, bgra })
    }
}

/// A window's frame (in window units) as a rectangle of the picture, shrunk by `inset` picture pixels on every side so
/// a soft edge never counts (pure).
pub fn rect_in(shot_origin: (f64, f64), scale: f64, window: ((f64, f64), (f64, f64)), inset: u32) -> super::pixels::Rect {
    let ((x, y), (w, h)) = window;
    let px = |v: f64| v.max(0.0).round() as u32;
    let (left, top) = (px((x - shot_origin.0) * scale), px((y - shot_origin.1) * scale));
    let (width, height) = (px(w * scale), px(h * scale));
    super::pixels::Rect { x: left + inset, y: top + inset, w: width.saturating_sub(2 * inset), h: height.saturating_sub(2 * inset) }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::privacy::pixels::Rect;

    #[test]
    fn a_window_is_found_in_a_retina_picture_and_on_a_second_monitor() {
        // A Mac: 240 × 160 points at (100, 200) on a display captured at 2x.
        assert_eq!(rect_in((0.0, 0.0), 2.0, ((100.0, 200.0), (240.0, 160.0)), 4), Rect { x: 204, y: 404, w: 472, h: 312 });
        // Windows: the monitor starts at (-1920, 0); a window at (-1720, 100), the picture at the monitor's size.
        assert_eq!(rect_in((-1920.0, 0.0), 1.0, ((-1720.0, 100.0), (300.0, 200.0)), 0), Rect { x: 200, y: 100, w: 300, h: 200 });
        // The screen moments size cap (1920 px wide for a 3024 px display): a smaller scale.
        assert_eq!(rect_in((0.0, 0.0), 1920.0 / 1512.0, ((0.0, 0.0), (1512.0, 982.0)), 0).w, 1920);
    }
}
