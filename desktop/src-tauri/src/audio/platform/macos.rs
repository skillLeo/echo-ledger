//! macOS 14.4+ capture: a Core Audio process tap (all processes except EchoLedger) combined with the chosen
//! microphone (`inputDevice`, else the default input device) in one private aggregate device, read by a single IOProc so mic and system audio share one
//! clock (docs/research/desktop-audio-native.md §3).
//!
//! NOT VERIFIED ON HARDWARE (this repo is built on Windows): the input-buffer → channel mapping below assumes the
//! sub-device (mic) streams precede the tap stream. docs/MANUAL_QA.md lists the check.

use std::ffi::c_void;
use std::ptr::NonNull;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::mpsc::{RecvTimeoutError, Sender, channel};
use std::thread::JoinHandle;
use std::time::{Duration, Instant};

use block2::RcBlock;
use dispatch2::DispatchQueue;
use objc2::AnyThread;
use objc2_core_audio::*;
use objc2_core_audio_types::{AudioBufferList, AudioTimeStamp};
use objc2_core_foundation::{CFArray, CFBoolean, CFDictionary, CFRetained, CFString, CFType};
use objc2_foundation::{NSArray, NSNumber};

use super::{CaptureBackend, Streams};
use crate::audio::devices::{CaptureHost, DeviceLog, DeviceNotice, Route, Supervisor};
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

/// One open capture: the tap and the aggregate device built on one set of devices. A device change closes it and opens
/// another (`MacHost`); the recorder's timeline fills the gap from the host clock, which they all share.
pub struct Stream {
    aggregate: AudioObjectID,
    tap: AudioObjectID,
    proc_id: AudioDeviceIOProcID,
    block: Option<RcBlock<IoBlock>>,
    _queue: dispatch2::DispatchRetained<DispatchQueue>,
    worker: Option<JoinHandle<()>>,
    /// Tells the worker to end once what is queued is written.
    finish: Arc<AtomicBool>,
    /// IOProc calls so far: a device that dies without telling anyone stops counting.
    packets: Arc<AtomicU64>,
    /// The rate the worker's resamplers were made for.
    device_rate: u32,
}

// SAFETY: the Core Audio objects are plain ids, usable from any thread; the block and queue are only kept alive here.
unsafe impl Send for Stream {}

/// The capture of one recording: keeps a [`Stream`] open on the right devices until it is stopped (`devices.rs`).
pub struct MacBackend {
    stop: Arc<AtomicBool>,
    log: DeviceLog,
    watcher: Option<JoinHandle<()>>,
}

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
    unsafe { string_property(device, kAudioDevicePropertyDeviceUID) }
}

unsafe fn device_name(device: AudioObjectID) -> Option<String> {
    unsafe { string_property(device, kAudioObjectPropertyName) }
}

/// The connected devices with input streams (`input_devices` command); the default input device is marked, and
/// Bluetooth ones are told by their transport type (else by their name).
pub fn input_devices() -> Vec<super::InputDevice> {
    unsafe { input_devices_inner() }
}

unsafe fn input_devices_inner() -> Vec<super::InputDevice> {
    let system_object = kAudioObjectSystemObject as AudioObjectID;
    let default = unsafe { get_u32(system_object, kAudioHardwarePropertyDefaultInputDevice, kAudioObjectPropertyScopeGlobal, None) };
    let ids = unsafe { all_devices() };
    ids.into_iter()
        .filter(|&device| unsafe { input_stream_channels(device) }.iter().sum::<u32>() > 0)
        .filter_map(|device| {
            let id = unsafe { device_uid(device) }?;
            let name = unsafe { device_name(device) }.unwrap_or_else(|| "Microphone".into());
            let bus = match unsafe { get_u32(device, kAudioDevicePropertyTransportType, kAudioObjectPropertyScopeGlobal, None) } {
                Some(kind) if kind == kAudioDeviceTransportTypeBluetooth || kind == kAudioDeviceTransportTypeBluetoothLE => super::MicBus::Bluetooth,
                Some(_) => super::MicBus::Other,
                None => super::MicBus::Unknown,
            };
            let bluetooth = super::is_bluetooth_mic(bus, &name);
            Some(super::InputDevice { id, name, is_default: Some(device) == default, bluetooth })
        })
        .collect()
}

/// A CFString property of an audio object (the getter follows the Create rule).
unsafe fn string_property(device: AudioObjectID, selector: AudioObjectPropertySelector) -> Option<String> {
    let addr = address(selector, kAudioObjectPropertyScopeGlobal);
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

/// Every audio device the system knows.
unsafe fn all_devices() -> Vec<AudioObjectID> {
    let system_object = kAudioObjectSystemObject as AudioObjectID;
    let addr = address(kAudioHardwarePropertyDevices, kAudioObjectPropertyScopeGlobal);
    let mut size: u32 = 0;
    if unsafe { AudioObjectGetPropertyDataSize(system_object, NonNull::from(&addr), 0, std::ptr::null(), NonNull::from(&mut size)) } != 0 || size == 0 {
        return Vec::new();
    }
    let mut ids: Vec<AudioObjectID> = vec![0; size as usize / std::mem::size_of::<AudioObjectID>()];
    let status = unsafe {
        AudioObjectGetPropertyData(system_object, NonNull::from(&addr), 0, std::ptr::null(), NonNull::from(&mut size), NonNull::new_unchecked(ids.as_mut_ptr().cast()))
    };
    if status != 0 { Vec::new() } else { ids }
}

unsafe fn device_with_uid(uid: &str) -> Option<AudioObjectID> {
    unsafe { all_devices() }.into_iter().find(|&device| unsafe { device_uid(device) }.as_deref() == Some(uid))
}

/// Which input buffer of the aggregate is the microphone (pure). The sub-devices' streams come in list order and the
/// tap's last, and the output device is listed first, so a USB headset used as output puts its own microphone's streams
/// ahead of another chosen microphone. The same device for both is listed once.
pub fn mic_buffer(buffers: usize, output_input_streams: usize, same_device: bool) -> Option<usize> {
    let at = if same_device { 0 } else { output_input_streams };
    // The last buffer is the tap's.
    (at + 1 < buffers).then_some(at)
}

/// The sub-devices of the aggregate (pure): the output first, the microphone after it when it is another device.
pub fn subdevice_uids<'a>(output: &'a str, mic: Option<&'a str>) -> Vec<&'a str> {
    let mut uids = vec![output];
    if let Some(mic) = mic.filter(|m| *m != output) {
        uids.push(mic);
    }
    uids
}

/// The Mac side of [`Supervisor`]: reads the default devices and opens streams.
struct MacHost {
    streams: Streams,
    tx: Sender<AudioChunk>,
}

impl CaptureHost for MacHost {
    type Stream = Stream;

    fn want(&self) -> Route {
        let system_object = kAudioObjectSystemObject as AudioObjectID;
        let default = |selector| unsafe { get_u32(system_object, selector, kAudioObjectPropertyScopeGlobal, None) }.filter(|&d| d != 0).and_then(|d| unsafe { device_uid(d) });
        // The chosen microphone while it is connected, else the default input device.
        let mic = self.streams.mic.then(|| super::preferred_mic().filter(|chosen| input_devices().iter().any(|d| d.id == *chosen)).or_else(|| default(kAudioHardwarePropertyDefaultInputDevice))).flatten();
        Route { mic, output: default(kAudioHardwarePropertyDefaultOutputDevice) }
    }

    fn open(&mut self, route: &Route) -> Result<Stream, String> {
        unsafe { open_stream(route, self.streams, self.tx.clone()) }.map_err(|e| e.to_string())
    }

    fn close(&mut self, stream: Stream) {
        close_stream(stream);
    }

    fn packets(&self, stream: &Stream) -> u64 {
        stream.packets.load(Ordering::Relaxed)
    }

    fn rate_moved(&self, stream: &Stream) -> bool {
        unsafe { get_f64(stream.aggregate, kAudioDevicePropertyNominalSampleRate) }.is_some_and(|rate| rate as u32 != stream.device_rate)
    }

    fn device_name(&self, id: &str) -> Option<String> {
        unsafe { device_with_uid(id).and_then(|device| device_name(device)) }
    }
}

impl MacBackend {
    /// Opens the first stream (its error is the recording's) and watches the devices from then on.
    pub fn start(streams: Streams, tx: Sender<AudioChunk>) -> Result<Self, CaptureError> {
        let mut host = MacHost { streams, tx };
        let route = host.want();
        let first = unsafe { open_stream(&route, streams, host.tx.clone()) }?;
        let mut supervisor = Supervisor::new(first, route, streams.mic, streams.system, Instant::now());
        let stop = Arc::new(AtomicBool::new(false));
        let log = DeviceLog::default();
        let (watching, notices) = (stop.clone(), log.clone());
        let watcher = std::thread::Builder::new()
            .name("el-audio-devices".into())
            .spawn(move || {
                'watch: loop {
                    for _ in 0..10 {
                        if watching.load(Ordering::SeqCst) {
                            break 'watch;
                        }
                        std::thread::sleep(Duration::from_millis(100));
                    }
                    for notice in supervisor.tick(&mut host, Instant::now()) {
                        notices.push(notice);
                    }
                }
                if let Some(stream) = supervisor.take_stream() {
                    close_stream(stream);
                }
            })
            .map_err(|e| CaptureError::Device(e.to_string()))?;
        Ok(Self { stop, log, watcher: Some(watcher) })
    }
}

/// Builds the tap and the aggregate device on `route` and starts them.
unsafe fn open_stream(route: &Route, streams: Streams, tx: Sender<AudioChunk>) -> Result<Stream, CaptureError> {
    let system_object = kAudioObjectSystemObject as AudioObjectID;
    let output_uid = route.output.clone().ok_or_else(|| CaptureError::Device("no default output device".into()))?;
    let input_uid = route.mic.clone().filter(|_| streams.mic);
    let same_device = input_uid.as_deref() == Some(output_uid.as_str());
    let output_inputs = unsafe { device_with_uid(&output_uid).map(|device| input_stream_channels(device).len()) }.unwrap_or(0);

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
    let mut subdevices: Vec<CFRetained<CFDictionary<CFString, CFType>>> = Vec::new();
    for (i, uid) in subdevice_uids(&output_uid, input_uid.as_deref()).into_iter().enumerate() {
        subdevices.push(if i == 0 {
            CFDictionary::<CFString, CFType>::from_slices(&[&*cfstr(kAudioSubDeviceUIDKey)], &[&*CFString::from_str(uid)])
        } else {
            CFDictionary::<CFString, CFType>::from_slices(&[&*cfstr(kAudioSubDeviceUIDKey), &*cfstr(kAudioSubDeviceDriftCompensationKey)], &[&*CFString::from_str(uid), yes])
        });
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
    let packets = Arc::new(AtomicU64::new(0));
    let counted = packets.clone();
    let block = RcBlock::new(
        move |_now: NonNull<AudioTimeStamp>, input: NonNull<AudioBufferList>, in_time: NonNull<AudioTimeStamp>, _out: NonNull<AudioBufferList>, _out_time: NonNull<AudioTimeStamp>| {
            counted.fetch_add(1, Ordering::Relaxed);
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
            let mic = mic_buffer(buffers.len(), output_inputs, same_device).filter(|_| has_mic).map(read).unwrap_or_default();
            let system = buffers.len().checked_sub(1).map(read).unwrap_or_default();
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
    let finish = Arc::new(AtomicBool::new(false));
    let finishing = finish.clone();
    let worker = std::thread::Builder::new()
        .name("el-audio-mac".into())
        .spawn(move || {
            let mut mic_rs = Resampler::new(device_rate, TARGET_RATE);
            let mut sys_rs = Resampler::new(device_rate, TARGET_RATE);
            loop {
                // The sender lives in the IOProc's block, which Core Audio may hold on to: the flag ends this.
                let packet = match raw_rx.recv_timeout(Duration::from_millis(100)) {
                    Ok(packet) => packet,
                    Err(RecvTimeoutError::Timeout) if finishing.load(Ordering::SeqCst) => break,
                    Err(RecvTimeoutError::Timeout) => continue,
                    Err(RecvTimeoutError::Disconnected) => break,
                };
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
    let stream = Stream { aggregate, tap, proc_id, block: Some(block), _queue: queue, worker: Some(worker), finish, packets, device_rate };
    if status != 0 {
        close_stream(stream);
        return Err(CaptureError::Permission(format!("start capture (OSStatus {status})")));
    }
    Ok(stream)
}

/// Teardown order from Apple's sample / AudioCap: stop → destroy IOProc → destroy aggregate → destroy tap. Then the
/// worker writes what is queued and ends (every captured packet is in the recorder's channel when this returns).
fn close_stream(mut stream: Stream) {
    unsafe {
        AudioDeviceStop(stream.aggregate, stream.proc_id);
        AudioDeviceDestroyIOProcID(stream.aggregate, stream.proc_id);
        AudioHardwareDestroyAggregateDevice(stream.aggregate);
        AudioHardwareDestroyProcessTap(stream.tap);
    }
    drop(stream.block.take());
    stream.finish.store(true, Ordering::SeqCst);
    if let Some(worker) = stream.worker.take() {
        let _ = worker.join();
    }
}

impl CaptureBackend for MacBackend {
    fn kind(&self) -> BackendKind {
        BackendKind::CoreAudioTap
    }

    fn stop(&mut self) {
        self.stop.store(true, Ordering::SeqCst);
        if let Some(watcher) = self.watcher.take() {
            let _ = watcher.join();
        }
    }

    fn take_notices(&mut self) -> Vec<DeviceNotice> {
        self.log.take()
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_microphone_is_found_among_the_aggregate_s_buffers() {
        // Speakers (no input streams) + built-in microphone + tap.
        assert_eq!(mic_buffer(2, 0, false), Some(0));
        // A USB headset as output, with its own microphone stream, and another microphone chosen: the headset's comes first.
        assert_eq!(mic_buffer(3, 1, false), Some(1));
        // The headset's microphone itself (the same device, listed once) + tap.
        assert_eq!(mic_buffer(2, 1, true), Some(0));
        // Only the tap: no microphone to read.
        assert_eq!(mic_buffer(1, 0, false), None);
        assert_eq!(mic_buffer(0, 0, false), None);
        // A microphone that did not make it into the aggregate is never read from the tap's buffer.
        assert_eq!(mic_buffer(1, 1, false), None);
    }

    #[test]
    fn a_headset_used_for_both_is_one_sub_device() {
        assert_eq!(subdevice_uids("airpods", Some("airpods")), ["airpods"]);
        assert_eq!(subdevice_uids("speakers", Some("usb-mic")), ["speakers", "usb-mic"]);
        assert_eq!(subdevice_uids("speakers", None), ["speakers"]);
    }
}
