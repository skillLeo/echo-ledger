package com.echoledger.mobile.capture;

// Events from the capture helper (shell UID) to the app. One-way so the helper never blocks on the app.
interface ICallListener {
    oneway void onCallStarted(String packageName) = 0;
    oneway void onCallEnded() = 1;
    oneway void onError(String message) = 2;
    // Since v1.3: "suspended" while a SIM call holds the app call, "resumed" after it, "far_silent" when the other side
    // gives no sound; the app shows it on the control pill and the after-call card. "self_muted" / "self_unmuted" for
    // the call app's own mute; "mic_retake_*" (open, failed, swapped, kept) are only logged.
    oneway void onCaptureState(String state) = 3;
}
