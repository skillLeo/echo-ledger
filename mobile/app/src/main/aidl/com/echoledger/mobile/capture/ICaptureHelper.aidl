package com.echoledger.mobile.capture;

import android.os.ParcelFileDescriptor;
import com.echoledger.mobile.capture.ICallListener;

// Privileged capture helper running as the shell user (Shizuku UserService or app_process).
// Audio flows through the pipe given to startCapture(): interleaved 16-bit stereo, L = mic ("Me"), R = far party ("Client").
interface ICaptureHelper {
    int version() = 1;
    int uid() = 2;
    String capabilities() = 3;
    boolean startCapture(in ParcelFileDescriptor sink, int sampleRate, int micSource) = 4;
    void stopCapture() = 5;
    void setCallListener(ICallListener listener) = 6;
    // A SIM phone call (since v1.2): the phone line instead of the microphone and the voice-call mix.
    boolean startPhoneCapture(in ParcelFileDescriptor sink, int sampleRate, int micSource) = 7;
    // Since v1.3: registers (true) or removes (false) the voice-call mix before any call; "armed", "disarmed" or "error: …".
    String arm(boolean on) = 8;
    // Since v1.3: the packages whose voice calls are calls (the app's editable call-app list).
    void setCallApps(in String[] packages) = 9;
    // Since v1.3: plays a soft voice-call tone and reads it back through the mix: "ok …" or "fail: …".
    String selfTest() = 10;
    // Since v1.3: channels of the capture just started: 2 = me and the other side apart, 1 = both voices on one track.
    int captureChannels() = 11;
    // Since v1.3 (Live Translate): the players the helper sees, as JSON (uid, packages, usage, content type, capture policy).
    String players() = 12;
    // Since v1.3: registers one mix per app before it plays ("armed" or "error: …"); an empty list removes them.
    String armApps(in int[] uids, boolean voiceChat) = 13;
    // Since v1.3: one app's sound as 16 kHz mono 16-bit PCM into the pipe until stopAppCapture(); usage 0 = all of it.
    boolean startAppCapture(in ParcelFileDescriptor sink, int uid, int usage, boolean voiceChat) = 14;
    void stopAppCapture() = 15;
    // Transaction reserved by Shizuku to destroy a UserService.
    void destroy() = 16777114;
}
