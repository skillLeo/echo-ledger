package com.echoledger.mobile.capture;

// Events from the capture helper (shell UID) to the app. One-way so the helper never blocks on the app.
oneway interface ICallListener {
    void onCallStarted(String packageName);
    void onCallEnded();
    void onError(String message);
}
