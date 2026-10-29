package com.echoledger.mobile.privilege

import android.content.ComponentName
import android.content.Context
import android.content.ServiceConnection
import android.content.pm.PackageManager
import android.os.IBinder
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.capture.CaptureHelperService
import com.echoledger.mobile.capture.ICallListener
import com.echoledger.mobile.capture.ICaptureHelper
import com.echoledger.mobile.core.CallTargets
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import rikka.shizuku.Shizuku

/**
 * Holds the binder of the privileged capture helper, delivered either by Shizuku (UserService) or by the ADB-launched
 * helper through BinderHandoffProvider. Shizuku performs the one-time Wireless-debugging pairing itself.
 */
class HelperConnection(private val context: Context) {
    /** [stale]: the running helper was started by an older build of the app (it must be restarted to get fixes). */
    data class State(
        val connected: Boolean = false,
        val source: String? = null,
        val helperUid: Int? = null,
        val error: String? = null,
        val stale: Boolean = false
    )

    private val _state = MutableStateFlow(State())
    val state: StateFlow<State> = _state

    @Volatile var helper: ICaptureHelper? = null
        private set

    private var callListener: ICallListener? = null

    /** Whether call capture is on: the helper's voice-call mix is then armed before any call (see FarPartyCapture). */
    @Volatile private var armWanted = false

    /** The call-app list the helper watches (the known apps plus the person's additions). */
    @Volatile private var callApps: Set<String> = CallTargets.DEFAULT_PACKAGES

    /** The helper's answer to the last arm request ("armed", "disarmed", "error: …"), for Setup and diagnostics. */
    private val _armState = MutableStateFlow<String?>(null)
    val armState: StateFlow<String?> = _armState

    private val death = IBinder.DeathRecipient {
        helper = null
        _armState.value = null
        _state.value = State(error = "Capture helper stopped (reboot or Wireless debugging off). Re-enable it from Setup.")
    }

    fun attach(binder: ICaptureHelper, source: String) {
        if (helper?.asBinder() === binder.asBinder() && _state.value.connected) return // the ADB helper repeats its hand-off
        helper = binder
        runCatching { binder.asBinder().linkToDeath(death, 0) }
        callListener?.let { runCatching { binder.setCallListener(it) } }
        val version = runCatching { binder.version() }.getOrNull()
        _state.value = State(
            connected = true,
            source = source,
            helperUid = runCatching { binder.uid() }.getOrNull(),
            stale = version != BuildConfig.HELPER_BUILD
        )
        // A helper that just (re)started has no mix: arm it now, before the next call's voice track exists.
        pushCallApps(binder)
        if (armWanted) applyArm(binder, true)
    }

    fun setCallListener(listener: ICallListener?) {
        callListener = listener
        helper?.let { runCatching { it.setCallListener(listener) } }
    }

    /** Call capture switched on or off: arms or removes the helper's voice-call mix now and after every helper restart. */
    fun setArmed(on: Boolean) {
        armWanted = on
        helper?.let { applyArm(it, on) }
    }

    fun setCallApps(packages: Set<String>) {
        callApps = packages
        helper?.let { pushCallApps(it) }
    }

    private fun pushCallApps(binder: ICaptureHelper) {
        runCatching { binder.setCallApps(callApps.toTypedArray()) }
    }

    private fun applyArm(binder: ICaptureHelper, on: Boolean) {
        // An older helper has no arm(): it answers with an error and is replaced on the next bind (its version differs).
        _armState.value = runCatching { binder.arm(on) }.getOrElse { "error: ${it.message ?: it.javaClass.simpleName}" }
    }

    // ---- Shizuku mode -------------------------------------------------------------------------------------------

    fun shizukuAvailable(): Boolean = runCatching { Shizuku.pingBinder() && !Shizuku.isPreV11() }.getOrDefault(false)

    fun shizukuPermissionGranted(): Boolean =
        runCatching { Shizuku.checkSelfPermission() == PackageManager.PERMISSION_GRANTED }.getOrDefault(false)

    fun requestShizukuPermission(requestCode: Int = 7) {
        runCatching { Shizuku.requestPermission(requestCode) }
    }

    private val userServiceArgs by lazy {
        Shizuku.UserServiceArgs(ComponentName(context.packageName, CaptureHelperService::class.java.name))
            .daemon(true) // survives app restarts until reboot
            .processNameSuffix("capture")
            .tag("echoledger-capture")
            .debuggable(BuildConfig.DEBUG)
            .version(BuildConfig.HELPER_BUILD)
    }

    private val serviceConnection = object : ServiceConnection {
        override fun onServiceConnected(name: ComponentName?, service: IBinder?) {
            val binder = service?.takeIf { it.pingBinder() } ?: return
            attach(ICaptureHelper.Stub.asInterface(binder), source = "shizuku")
        }

        override fun onServiceDisconnected(name: ComponentName?) {
            helper = null
            _state.value = State(error = "Shizuku service disconnected")
        }
    }

    /**
     * Connects by itself whenever Shizuku is (or comes back) up and allowed: after the app was stopped by Android, after
     * Shizuku restarts. Without it the helper kept running but this app no longer talked to it.
     */
    fun autoConnect() {
        runCatching {
            Shizuku.addBinderReceivedListenerSticky {
                if (helper == null && shizukuPermissionGranted()) bindShizuku()
            }
        }
    }

    fun bindShizuku(): Boolean {
        if (!shizukuAvailable() || !shizukuPermissionGranted()) return false
        return runCatching { Shizuku.bindUserService(userServiceArgs, serviceConnection) }.isSuccess
    }

    fun unbindShizuku() {
        runCatching { Shizuku.unbindUserService(userServiceArgs, serviceConnection, true) }
    }

    // ---- ADB command mode ---------------------------------------------------------------------------------------

    /** Command shown in Setup; run once from a computer after each reboot (USB or Wireless debugging). */
    fun adbCommand(): String = "adb shell \"CLASSPATH=\$(pm path ${context.packageName} | cut -d: -f2) nohup app_process /system/bin " +
        "com.echoledger.mobile.capture.HelperMain ${context.packageName}.handoff >/dev/null 2>&1 &\""
}
