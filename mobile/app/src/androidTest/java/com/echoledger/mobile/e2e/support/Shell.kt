package com.echoledger.mobile.e2e.support

import android.util.Base64
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.uiautomator.UiDevice

/** Shell commands run with shell privileges through UiAutomator (network, lock screen, theme, files pushed with adb). */
object Shell {
    private val device: UiDevice get() = UiDevice.getInstance(InstrumentationRegistry.getInstrumentation())

    fun run(command: String): String = device.executeShellCommand(command)

    /** Bytes of a file pushed with `adb push` (the shell can read it; the app cannot necessarily). */
    fun readFile(path: String): ByteArray = Base64.decode(run("base64 $path"), Base64.DEFAULT)

    fun fileExists(path: String): Boolean = run("ls $path").trim() == path

    fun setNetwork(enabled: Boolean) {
        val word = if (enabled) "enable" else "disable"
        run("svc wifi $word")
        run("svc data $word")
    }

    fun setNightMode(enabled: Boolean) {
        run("cmd uimode night ${if (enabled) "yes" else "no"}")
    }

    fun setDevicePin(pin: String) {
        run("locksettings set-pin $pin")
    }

    fun clearDevicePin(pin: String) {
        run("locksettings clear --old $pin")
    }

    fun keyboardShown(): Boolean = run("dumpsys input_method").contains("mInputShown=true")

    fun typeText(text: String) {
        run("input text $text")
    }

    fun keyEvent(code: String) {
        run("input keyevent $code")
    }
}
