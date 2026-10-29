package com.echoledger.mobile.capture

import android.os.ParcelFileDescriptor
import com.echoledger.mobile.privilege.HelperConnection
import com.echoledger.mobile.testsupport.AppTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test

/** The app's view of the privileged capture helper. */
class HelperConnectionTest : AppTest() {
    private class Helper(private val reportedUid: Int, private val failUid: Boolean = false) : ICaptureHelper.Stub() {
        var listener: ICallListener? = null

        override fun version() = com.echoledger.mobile.BuildConfig.HELPER_BUILD

        override fun uid(): Int = if (failUid) throw IllegalStateException("helper died") else reportedUid

        override fun capabilities() = ""

        override fun startCapture(sink: ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true

        override fun startPhoneCapture(sink: ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true

        /** Every arm request, in order (true = arm, false = disarm). */
        val arms = mutableListOf<Boolean>()
        var callApps: List<String>? = null

        override fun arm(on: Boolean): String {
            arms += on
            return if (on) "armed" else "disarmed"
        }

        override fun setCallApps(packages: Array<out String>?) {
            callApps = packages?.toList()
        }

        override fun selfTest(): String = "ok"

        override fun captureChannels(): Int = 2

        override fun stopCapture() = Unit

        override fun setCallListener(listener: ICallListener?) {
            this.listener = listener
        }

        override fun destroy() = Unit
    }

    private class Listener : ICallListener.Stub() {
        override fun onCallStarted(packageName: String?) = Unit

        override fun onCallEnded() = Unit

        override fun onError(message: String?) = Unit
        override fun onCaptureState(state: String?) = Unit
    }

    private fun connection() = HelperConnection(app)

    @Test
    fun `call capture switched on before the helper connects arms it as soon as it does`() {
        val connection = connection()
        connection.setArmed(true)
        val helper = Helper(2000)

        connection.attach(helper, "shizuku")

        assertEquals(listOf(true), helper.arms)
        assertEquals("armed", connection.armState.value)
        assertEquals(com.echoledger.mobile.core.CallTargets.DEFAULT_PACKAGES, helper.callApps?.toSet())
    }

    @Test
    fun `a restarted helper is armed again, and switching off disarms it`() {
        val connection = connection()
        connection.setArmed(true)
        val first = Helper(2000)
        connection.attach(first, "shizuku")
        val restarted = Helper(2000) // a new process after a reboot or a crash: a new binder

        connection.attach(restarted, "shizuku")
        connection.setArmed(false)

        assertEquals(listOf(true), first.arms)
        assertEquals(listOf(true, false), restarted.arms)
        assertEquals("disarmed", connection.armState.value)
    }

    @Test
    fun `with call capture off a connecting helper is never armed`() {
        val connection = connection()
        val helper = Helper(2000)

        connection.attach(helper, "adb")

        assertTrue(helper.arms.isEmpty())
        assertNull(connection.armState.value)
    }

    @Test
    fun `an edited call-app list reaches the running helper`() {
        val connection = connection()
        val helper = Helper(2000)
        connection.attach(helper, "adb")

        connection.setCallApps(setOf("com.whatsapp", "com.example.calls"))

        assertEquals(listOf("com.whatsapp", "com.example.calls"), helper.callApps)
    }

    @Test
    fun `a new connection has no helper and no error`() {
        val connection = connection()

        assertEquals(HelperConnection.State(), connection.state.value)
        assertNull(connection.helper)
        assertFalse(connection.state.value.connected)
    }

    @Test
    fun `attaching records the source and the helper's user id`() {
        val connection = connection()
        val helper = Helper(2000)

        connection.attach(helper, "adb")

        assertEquals(HelperConnection.State(connected = true, source = "adb", helperUid = 2000), connection.state.value)
        assertSame(helper, connection.helper)
    }

    @Test
    fun `a helper left running by an older build of the app is marked stale`() {
        val connection = connection()
        val old = object : ICaptureHelper.Stub() {
            override fun version() = com.echoledger.mobile.BuildConfig.HELPER_BUILD - 1
            override fun uid() = 2000
            override fun capabilities() = ""
            override fun startCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true
            override fun startPhoneCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true
            override fun arm(on: Boolean): String = if (on) "armed" else "disarmed"
            override fun setCallApps(packages: Array<out String>?) = Unit
            override fun selfTest(): String = "ok"
            override fun captureChannels(): Int = 2
            override fun stopCapture() = Unit
            override fun setCallListener(listener: ICallListener?) = Unit
            override fun destroy() = Unit
        }

        connection.attach(old, "shizuku")

        assertTrue(connection.state.value.connected)
        assertTrue(connection.state.value.stale)
    }

    @Test
    fun `a helper that cannot report its uid is still connected`() {
        val connection = connection()

        connection.attach(Helper(0, failUid = true), "shizuku")

        assertTrue(connection.state.value.connected)
        assertNull(connection.state.value.helperUid)
    }

    @Test
    fun `attaching again replaces the helper and keeps the call listener`() {
        val connection = connection()
        val listener = Listener()
        val first = Helper(2000)
        val second = Helper(0)
        connection.attach(first, "adb")
        connection.setCallListener(listener)

        connection.attach(second, "shizuku")

        assertSame(second, connection.helper)
        assertSame("the new helper must report calls to the same listener", listener, second.listener)
        assertEquals("shizuku", connection.state.value.source)
        assertEquals(0, connection.state.value.helperUid)
    }

    @Test
    fun `the call listener can be cleared again`() {
        val connection = connection()
        val helper = Helper(2000)
        connection.attach(helper, "adb")
        connection.setCallListener(Listener())

        connection.setCallListener(null)

        assertNull(helper.listener)
    }

    @Test
    fun `Shizuku is reported unavailable and cannot be bound when it is not running`() {
        val connection = connection()

        assertFalse(connection.shizukuAvailable())
        assertFalse(connection.shizukuPermissionGranted())
        assertFalse(connection.bindShizuku())
        connection.requestShizukuPermission()
        connection.unbindShizuku()
    }

    @Test
    fun `the ADB command runs the helper from this package and names the handoff authority`() {
        val command = connection().adbCommand()

        assertEquals(
            "adb shell \"CLASSPATH=\$(pm path ${app.packageName} | cut -d: -f2) nohup app_process /system/bin " +
                "com.echoledger.mobile.capture.HelperMain ${app.packageName}.handoff >/dev/null 2>&1 &\"",
            command
        )
    }
}
