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
    // Transaction reserved by Shizuku to destroy a UserService.
    void destroy() = 16777114;
}
