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

        override fun version() = 1

        override fun uid(): Int = if (failUid) throw IllegalStateException("helper died") else reportedUid

        override fun capabilities() = ""

        override fun startCapture(sink: ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true

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
    }

    private fun connection() = HelperConnection(app)

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
