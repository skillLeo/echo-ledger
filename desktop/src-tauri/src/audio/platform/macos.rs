//! macOS 14.4+ capture: a Core Audio process tap (all processes except EchoLedger) combined with the default
//! input device in one private aggregate device, read by a single IOProc so mic and system audio share one
//! clock (docs/research/desktop-audio-native.md §3).
//!
//! NOT VERIFIED ON HARDWARE (this repo is built on Windows): the input-buffer → channel mapping below assumes the
//! sub-device (mic) streams precede the tap stream. docs/MANUAL_QA.md lists the check.

use std::ffi::c_void;
use std::ptr::NonNull;
use std::sync::mpsc::{Sender, channel};
use std::thread::JoinHandle;

use block2::RcBlock;
use dispatch2::DispatchQueue;
use objc2::AnyThread;
use objc2_core_audio::*;
use objc2_core_audio_types::{AudioBufferList, AudioTimeStamp};
use objc2_core_foundation::{CFArray, CFBoolean, CFDictionary, CFRetained, CFString, CFType};
use objc2_foundation::{NSArray, NSNumber};

use super::{CaptureBackend, Streams};
use crate::audio::dsp::{Resampler, downmix_to_mono};
use crate::audio::types::{AudioChunk, BackendKind, CaptureError, Channel, TARGET_RATE};

struct RawPacket {
    mic: Vec<f32>,
    system: Vec<f32>,
    ts_ns: u64,
}

/// Signature of the IOProc block (`AudioDeviceIOBlock` in AudioHardware.h): now, input data, input time, output
/// data, output time.
type IoBlock = dyn Fn(NonNull<AudioTimeStamp>, NonNull<AudioBufferList>, NonNull<AudioTimeStamp>, NonNull<AudioBufferList>, NonNull<AudioTimeStamp>);

pub struct MacBackend {
    aggregate: AudioObjectID,
    tap: AudioObjectID,
    proc_id: AudioDeviceIOProcID,
    _block: RcBlock<IoBlock>,
    _queue: dispatch2::DispatchRetained<DispatchQueue>,
    worker: Option<JoinHandle<()>>,
    stopped: bool,
}

unsafe impl Send for MacBackend {}

fn address(selector: AudioObjectPropertySelector, scope: AudioObjectPropertyScope) -> AudioObjectPropertyAddress {
    AudioObjectPropertyAddress { mSelector: selector, mScope: scope, mElement: kAudioObjectPropertyElementMain }
}

unsafe fn get_u32(object: AudioObjectID, selector: AudioObjectPropertySelector, scope: AudioObjectPropertyScope, qualifier: Option<&i32>) -> Option<u32> {
    let addr = address(selector, scope);
    let mut value: u32 = 0;
    let mut size = std::mem::size_of::<u32>() as u32;
    let (q_size, q_ptr) = match qualifier {
        Some(q) => (std::mem::size_of::<i32>() as u32, q as *const i32 as *const c_void),
        None => (0, std::ptr::null()),
    };
    let status = unsafe {
        AudioObjectGetPropertyData(object, NonNull::from(&addr), q_size, q_ptr, NonNull::from(&mut size), NonNull::from(&mut value).cast())
    };
    (status == 0).then_some(value)
}

unsafe fn get_f64(object: AudioObjectID, selector: AudioObjectPropertySelector) -> Option<f64> {
    let addr = address(selector, kAudioObjectPropertyScopeGlobal);
    let mut value: f64 = 0.0;
    let mut size = std::mem::size_of::<f64>() as u32;
    let status = unsafe {
        AudioObjectGetPropertyData(object, NonNull::from(&addr), 0, std::ptr::null(), NonNull::from(&mut size), NonNull::from(&mut value).cast())
    };
    (status == 0).then_some(value)
}

unsafe fn device_uid(device: AudioObjectID) -> Option<String> {
    let addr = address(kAudioDevicePropertyDeviceUID, kAudioObjectPropertyScopeGlobal);
    let mut uid: *const CFString = std::ptr::null();
    let mut size = std::mem::size_of::<*const CFString>() as u32;
    let status = unsafe {
        AudioObjectGetPropertyData(device, NonNull::from(&addr), 0, std::ptr::null(), NonNull::from(&mut size), NonNull::from(&mut uid).cast())
    };
    if status != 0 || uid.is_null() {
        return None;
    }
    // The getter follows the Create rule: we own the returned CFString.
    let owned: CFRetained<CFString> = unsafe { CFRetained::from_raw(NonNull::new_unchecked(uid as *mut CFString)) };
    Some(owned.to_string())
}

/// Channel counts of each input stream of `device` (kAudioDevicePropertyStreamConfiguration, input scope).
unsafe fn input_stream_channels(device: AudioObjectID) -> Vec<u32> {
    let addr = address(kAudioDevicePropertyStreamConfiguration, kAudioObjectPropertyScopeInput);
    let mut size: u32 = 0;
    if unsafe { AudioObjectGetPropertyDataSize(device, NonNull::from(&addr), 0, std::ptr::null(), NonNull::from(&mut size)) } != 0 || size == 0 {
        return Vec::new();
    }
    let mut storage = vec![0u8; size as usize];
    let status = unsafe {
        AudioObjectGetPropertyData(device, NonNull::from(&addr), 0, std::ptr::null(), NonNull::from(&mut size), NonNull::new_unchecked(storage.as_mut_ptr().cast()))
    };
    if status != 0 {
        return Vec::new();
    }
    let list = unsafe { &*(storage.as_ptr() as *const AudioBufferList) };
    let buffers = unsafe { std::slice::from_raw_parts(list.mBuffers.as_ptr(), list.mNumberBuffers as usize) };
    buffers.iter().map(|b| b.mNumberChannels).collect()
}

fn cfstr(key: &std::ffi::CStr) -> CFRetained<CFString> {
    CFString::from_str(key.to_str().unwrap_or_default())
}

impl MacBackend {
    pub fn start(streams: Streams, tx: Sender<AudioChunk>) -> Result<Self, CaptureError> {
        unsafe { Self::start_inner(streams, tx) }
    }

    unsafe fn start_inner(streams: Streams, tx: Sender<AudioChunk>) -> Result<Self, CaptureError> {
        let system_object = kAudioObjectSystemObject as AudioObjectID;
        let output = unsafe { get_u32(system_object, kAudioHardwarePropertyDefaultOutputDevice, kAudioObjectPropertyScopeGlobal, None) }
            .ok_or_else(|| CaptureError::Device("no default output device".into()))?;
        let input = unsafe { get_u32(system_object, kAudioHardwarePropertyDefaultInputDevice, kAudioObjectPropertyScopeGlobal, None) };
        let output_uid = unsafe { device_uid(output) }.ok_or_else(|| CaptureError::Device("output device has no UID".into()))?;
        let input_uid = if streams.mic { input.and_then(|d| unsafe { device_uid(d) }) } else { None };

        // Exclude our own process object (0 if we have not done audio I/O yet — then nothing is excluded).
        let pid = std::process::id() as i32;
        let own = unsafe { get_u32(system_object, kAudioHardwarePropertyTranslatePIDToProcessObject, kAudioObjectPropertyScopeGlobal, Some(&pid)) }
            .filter(|&id| id != 0);
        let numbers: Vec<_> = own.into_iter().map(NSNumber::new_u32).collect();
        let excluded = NSArray::from_retained_slice(&numbers);
        let description = unsafe { CATapDescription::initStereoGlobalTapButExcludeProcesses(CATapDescription::alloc(), &excluded) };
        unsafe { description.setPrivate(true) };
        let tap_uid = unsafe { description.UUID() }.UUIDString().to_string();
        let mut tap: AudioObjectID = 0;
        let status = unsafe { AudioHardwareCreateProcessTap(Some(&description), &mut tap) };
        if status != 0 {
            return Err(CaptureError::Permission(format!("system audio tap (OSStatus {status}); allow System Audio Recording")));
        }

        // Aggregate: output as main sub-device, optional mic sub-device (drift-compensated), tap (drift-compensated).
        let yes: &CFType = CFBoolean::new(true);
        let no: &CFType = CFBoolean::new(false);
        let sub_output = CFDictionary::<CFString, CFType>::from_slices(&[&*cfstr(kAudioSubDeviceUIDKey)], &[&*CFString::from_str(&output_uid)]);
        let mut subdevices: Vec<CFRetained<CFDictionary<CFString, CFType>>> = vec![sub_output];
        if let Some(uid) = &input_uid {
            subdevices.push(CFDictionary::<CFString, CFType>::from_slices(
                &[&*cfstr(kAudioSubDeviceUIDKey), &*cfstr(kAudioSubDeviceDriftCompensationKey)],
                &[&*CFString::from_str(uid), yes],
            ));
        }
        let tap_entry = CFDictionary::<CFString, CFType>::from_slices(
            &[&*cfstr(kAudioSubTapUIDKey), &*cfstr(kAudioSubTapDriftCompensationKey)],
            &[&*CFString::from_str(&tap_uid), yes],
        );
        let sub_refs: Vec<&CFDictionary<CFString, CFType>> = subdevices.iter().map(|d| &**d).collect();
        let sub_array = CFArray::from_objects(&sub_refs);
        let tap_array = CFArray::from_objects(&[&*tap_entry]);
        let aggregate_uid = uuid::Uuid::new_v4().to_string();
        let keys = [
            &*cfstr(kAudioAggregateDeviceUIDKey),
            &*cfstr(kAudioAggregateDeviceNameKey),
            &*cfstr(kAudioAggregateDeviceMainSubDeviceKey),
            &*cfstr(kAudioAggregateDeviceIsPrivateKey),
            &*cfstr(kAudioAggregateDeviceIsStackedKey),
            &*cfstr(kAudioAggregateDeviceTapAutoStartKey),
            &*cfstr(kAudioAggregateDeviceSubDeviceListKey),
            &*cfstr(kAudioAggregateDeviceTapListKey),
        ];
        let name = CFString::from_str(&format!("EchoLedger-{pid}"));
        let uid_value = CFString::from_str(&aggregate_uid);
        let main_value = CFString::from_str(&output_uid);
        let values: [&CFType; 8] = [&uid_value, &name, &main_value, yes, no, yes, sub_array.as_ref(), tap_array.as_ref()];
        let dictionary = CFDictionary::<CFString, CFType>::from_slices(&keys, &values);
        let mut aggregate: AudioObjectID = 0;
        let status = unsafe { AudioHardwareCreateAggregateDevice(dictionary.as_opaque(), NonNull::from(&mut aggregate)) };
        if status != 0 {
            unsafe { AudioHardwareDestroyProcessTap(tap) };
            return Err(CaptureError::Device(format!("aggregate device (OSStatus {status})")));
        }

        let device_rate = unsafe { get_f64(aggregate, kAudioDevicePropertyNominalSampleRate) }.unwrap_or(48_000.0) as u32;
        let stream_channels = unsafe { input_stream_channels(aggregate) };
        eprintln!("[echoledger] macOS aggregate input streams (channels): {stream_channels:?}");
        let has_mic = input_uid.is_some();

        // IOProc copies into a channel; a worker thread downmixes + resamples to 16 kHz (no heavy work on the HAL thread).
        let (raw_tx, raw_rx) = channel::<RawPacket>();
        let block = RcBlock::new(
            move |_now: NonNull<AudioTimeStamp>, input: NonNull<AudioBufferList>, in_time: NonNull<AudioTimeStamp>, _out: NonNull<AudioBufferList>, _out_time: NonNull<AudioTimeStamp>| {
                let list = unsafe { input.as_ref() };
                let buffers = unsafe { std::slice::from_raw_parts(list.mBuffers.as_ptr(), list.mNumberBuffers as usize) };
                let read = |i: usize| -> Vec<f32> {
                    let b = &buffers[i];
                    if b.mData.is_null() || b.mNumberChannels == 0 {
                        return Vec::new();
                    }
                    let samples = unsafe { std::slice::from_raw_parts(b.mData as *const f32, b.mDataByteSize as usize / 4) };
                    downmix_to_mono(samples, b.mNumberChannels as usize)
                };
                let (mic, system) = match buffers.len() {
                    0 => (Vec::new(), Vec::new()),
                    1 => (Vec::new(), read(0)),
                    n => (if has_mic { read(0) } else { Vec::new() }, read(n - 1)),
                };
                let ts_ns = unsafe { AudioConvertHostTimeToNanos(in_time.as_ref().mHostTime) };
                let _ = raw_tx.send(RawPacket { mic, system, ts_ns });
            },
        );
        let queue = DispatchQueue::new("com.echoledger.audio.io", None);
        let mut proc_id: AudioDeviceIOProcID = None;
        let status = unsafe { AudioDeviceCreateIOProcIDWithBlock(NonNull::from(&mut proc_id), aggregate, Some(&queue), RcBlock::as_ptr(&block)) };
        if status != 0 {
            unsafe {
                AudioHardwareDestroyAggregateDevice(aggregate);
                AudioHardwareDestroyProcessTap(tap);
            }
            return Err(CaptureError::Device(format!("IOProc (OSStatus {status})")));
        }

        let want_mic = streams.mic && has_mic;
        let want_system = streams.system;
        let worker = std::thread::Builder::new()
            .name("el-audio-mac".into())
            .spawn(move || {
                let mut mic_rs = Resampler::new(device_rate, TARGET_RATE);
                let mut sys_rs = Resampler::new(device_rate, TARGET_RATE);
                while let Ok(packet) = raw_rx.recv() {
                    if want_mic && !packet.mic.is_empty() {
                        let samples = mic_rs.process(&packet.mic);
                        if tx.send(AudioChunk { channel: Channel::Mic, samples, ts_ns: Some(packet.ts_ns), discontinuity: false }).is_err() {
                            break;
                        }
                    }
                    if want_system && !packet.system.is_empty() {
                        let samples = sys_rs.process(&packet.system);
                        if tx.send(AudioChunk { channel: Channel::System, samples, ts_ns: Some(packet.ts_ns), discontinuity: false }).is_err() {
                            break;
                        }
                    }
                }
            })
            .map_err(|e| CaptureError::Device(e.to_string()))?;

        // The first start triggers the one-time "System Audio Recording" prompt (tie to a user action).
        let status = unsafe { AudioDeviceStart(aggregate, proc_id) };
        if status != 0 {
            unsafe {
                AudioDeviceDestroyIOProcID(aggregate, proc_id);
                AudioHardwareDestroyAggregateDevice(aggregate);
                AudioHardwareDestroyProcessTap(tap);
            }
            return Err(CaptureError::Permission(format!("start capture (OSStatus {status})")));
        }

        Ok(Self { aggregate, tap, proc_id, _block: block, _queue: queue, worker: Some(worker), stopped: false })
    }
}

impl CaptureBackend for MacBackend {
    fn kind(&self) -> BackendKind {
        BackendKind::CoreAudioTap
    }

    fn stop(&mut self) {
        if self.stopped {
            return;
        }
        self.stopped = true;
        // Teardown order from Apple's sample / AudioCap: stop → destroy IOProc → destroy aggregate → destroy tap.
        unsafe {
            AudioDeviceStop(self.aggregate, self.proc_id);
            AudioDeviceDestroyIOProcID(self.aggregate, self.proc_id);
            AudioHardwareDestroyAggregateDevice(self.aggregate);
            AudioHardwareDestroyProcessTap(self.tap);
        }
        if let Some(worker) = self.worker.take() {
            let _ = worker.join();
        }
    }
}

impl Drop for MacBackend {
    fn drop(&mut self) {
        self.stop();
    }
}

/// One Core Audio process object (macOS 14.4+): who it is and whether it runs audio input / output.
struct AudioProcess {
    pid: u32,
    bundle: String,
    input: bool,
    output: bool,
}

/// Every process object Core Audio knows (`kAudioHardwarePropertyProcessObjectList`) with
/// `kAudioProcessPropertyPID`, `kAudioProcessPropertyBundleID`, `kAudioProcessPropertyIsRunningInput` and
/// `kAudioProcessPropertyIsRunningOutput` (objc2-core-audio-0.3.2 `src/generated/AudioHardware.rs` lines 1082,
/// 1758-1768). Polled; property listeners are unreliable for other processes.
fn audio_processes() -> Vec<AudioProcess> {
    let mut out = Vec::new();
    unsafe {
        let system_object = kAudioObjectSystemObject as AudioObjectID;
        let addr = address(kAudioHardwarePropertyProcessObjectList, kAudioObjectPropertyScopeGlobal);
        let mut size: u32 = 0;
        if AudioObjectGetPropertyDataSize(system_object, NonNull::from(&addr), 0, std::ptr::null(), NonNull::from(&mut size)) != 0 {
            return out;
        }
        let count = size as usize / std::mem::size_of::<AudioObjectID>();
        let mut ids = vec![0 as AudioObjectID; count];
        if count == 0
            || AudioObjectGetPropertyData(system_object, NonNull::from(&addr), 0, std::ptr::null(), NonNull::from(&mut size), NonNull::new_unchecked(ids.as_mut_ptr().cast())) != 0
        {
            return out;
        }
        for id in ids {
            let Some(pid) = get_u32(id, kAudioProcessPropertyPID, kAudioObjectPropertyScopeGlobal, None) else { continue };
            let input = get_u32(id, kAudioProcessPropertyIsRunningInput, kAudioObjectPropertyScopeGlobal, None) == Some(1);
            let output = get_u32(id, kAudioProcessPropertyIsRunningOutput, kAudioObjectPropertyScopeGlobal, None) == Some(1);
            let baddr = address(kAudioProcessPropertyBundleID, kAudioObjectPropertyScopeGlobal);
            let mut bundle: *const CFString = std::ptr::null();
            let mut bsize = std::mem::size_of::<*const CFString>() as u32;
            let bundle = if AudioObjectGetPropertyData(id, NonNull::from(&baddr), 0, std::ptr::null(), NonNull::from(&mut bsize), NonNull::from(&mut bundle).cast()) == 0
                && !bundle.is_null()
            {
                // The getter follows the Create rule: we own the returned CFString.
                let owned: CFRetained<CFString> = CFRetained::from_raw(NonNull::new_unchecked(bundle as *mut CFString));
                owned.to_string()
            } else {
                String::new()
            };
            out.push(AudioProcess { pid, bundle, input, output });
        }
    }
    out
}

/// Active-tab URLs of a running browser through Apple Events (`osascript`, 1 s timeout). Needs the Automation
/// permission (`NSAppleEventsUsageDescription` in Info.plist); without it the script fails and the list is empty.
fn tab_urls(application: &str, safari: bool) -> Vec<String> {
    use std::io::Read;
    use std::process::{Command, Stdio};
    use std::time::{Duration, Instant};

    let tab = if safari { "current tab" } else { "active tab" };
    let script = format!("tell application \"{application}\" to get URL of {tab} of every window");
    let Ok(mut child) = Command::new("/usr/bin/osascript").arg("-e").arg(&script).stdout(Stdio::piped()).stderr(Stdio::null()).spawn() else {
        return Vec::new();
    };
    let deadline = Instant::now() + Duration::from_secs(1);
    loop {
        match child.try_wait() {
            Ok(Some(status)) if status.success() => break,
            Ok(None) if Instant::now() < deadline => std::thread::sleep(Duration::from_millis(20)),
            Ok(Some(_)) => return Vec::new(),
            _ => {
                let _ = child.kill();
                let _ = child.wait();
                return Vec::new();
            }
        }
    }
    let mut text = String::new();
    if let Some(mut stdout) = child.stdout.take() {
        let _ = stdout.read_to_string(&mut text);
    }
    crate::detect::sites::parse_osascript_list(&text)
}

/// The applications that hold a microphone, grouped with their helper processes by bundle id, each with whether
/// it also plays audio. Tab URLs are read only when `read_urls` (Settings → browser automation) is on.
/// NOT VERIFIED ON HARDWARE: docs/MANUAL_QA.md lists the check.
pub fn observe_trees(read_urls: bool) -> Vec<crate::detect::decide::TreeAudio> {
    use crate::detect::classify::{app_bundle_id, browser_script, is_browser_bundle};
    use crate::detect::decide::TreeAudio;

    let own = std::process::id();
    let processes: Vec<AudioProcess> = audio_processes().into_iter().filter(|p| p.pid != own).collect();
    if !processes.iter().any(|p| p.input) {
        return Vec::new();
    }
    let key = |p: &AudioProcess| if p.bundle.is_empty() { format!("pid:{}", p.pid) } else { app_bundle_id(&p.bundle).to_ascii_lowercase() };
    let mut trees: Vec<(String, TreeAudio)> = Vec::new();
    for p in processes.iter().filter(|p| p.input) {
        let k = key(p);
        match trees.iter_mut().find(|(group, _)| *group == k) {
            Some((_, tree)) => tree.root_pid = tree.root_pid.min(p.pid),
            None => {
                let name = if p.bundle.is_empty() { "a protected process".to_string() } else { app_bundle_id(&p.bundle).to_string() };
                trees.push((k, TreeAudio { root_pid: p.pid, name, pfn: None, capture_active: true, render_active: false, titles: Vec::new(), urls: Vec::new() }));
            }
        }
    }
    for p in processes.iter().filter(|p| p.output) {
        let k = key(p);
        if let Some((_, tree)) = trees.iter_mut().find(|(group, _)| *group == k) {
            tree.render_active = true;
        }
    }
    let mut out: Vec<TreeAudio> = trees.into_iter().map(|(_, t)| t).collect();
    if read_urls {
        for tree in out.iter_mut().filter(|t| is_browser_bundle(&t.name)) {
            if let Some((application, safari)) = browser_script(&tree.name) {
                tree.urls = tab_urls(application, safari);
            }
        }
    }
    out.sort_by_key(|t| t.root_pid);
    out
}
