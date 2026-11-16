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
    private class Helper(
        private val reportedUid: Int,
        private val failUid: Boolean = false,
        private val build: Int = com.echoledger.mobile.BuildConfig.HELPER_BUILD
    ) : ICaptureHelper.Stub() {
        var listener: ICallListener? = null
        var destroyed = false

        override fun version() = build

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
        override fun players(): String = "[]"
        override fun armApps(uids: IntArray?, voiceChat: Boolean): String = "armed"
        override fun startAppCapture(sink: android.os.ParcelFileDescriptor?, uid: Int, usage: Int, voiceChat: Boolean): Boolean = false
        override fun stopAppCapture() = Unit

        override fun stopCapture() = Unit

        override fun setCallListener(listener: ICallListener?) {
            this.listener = listener
        }

        override fun destroy() {
            destroyed = true
        }
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

        assertEquals(
            HelperConnection.State(
                connected = true,
                source = "adb",
                helperUid = 2000,
                helperBuild = com.echoledger.mobile.BuildConfig.HELPER_BUILD
            ),
            connection.state.value
        )
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
            override fun players(): String = "[]"
            override fun armApps(uids: IntArray?, voiceChat: Boolean): String = "armed"
            override fun startAppCapture(sink: android.os.ParcelFileDescriptor?, uid: Int, usage: Int, voiceChat: Boolean): Boolean = false
            override fun stopAppCapture() = Unit
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

    // ---- after an app update: the helper left running from before (the Android 17 report, v1.8) -------------------

    @Test
    fun `a helper left running from before an update never takes a current helper's place, and is asked to make way`() {
        val connection = connection()
        var restarts = 0
        connection.restartStale = { restarts++ }
        val old = Helper(2000, build = com.echoledger.mobile.BuildConfig.HELPER_BUILD - 7)
        connection.attach(old, "adb")
        assertTrue(connection.state.value.stale)
        assertEquals(com.echoledger.mobile.BuildConfig.HELPER_BUILD - 7, connection.state.value.helperBuild)
        assertEquals("this version is asked for through Shizuku", 1, restarts)

        // This version arrives (Shizuku started it): it takes over, and the old one is stopped.
        val current = Helper(2000)
        connection.attach(current, "shizuku")
        assertTrue(connection.helper === current)
        assertFalse(connection.state.value.stale)
        assertTrue("the old one is stopped, so the two never both hold a call's mix", old.destroyed)

        // The old one hands its binder over again (every 30 s): it is not taken back.
        connection.attach(old, "adb")
        assertTrue(connection.helper === current)
        assertFalse(current.destroyed)
    }

    @Test
    fun `a helper whose process is already gone is never kept as the helper`() {
        val connection = connection()
        connection.attach(ICaptureHelper.Stub.asInterface(GoneBinder()), "adb")
        assertNull(connection.helper)
        assertFalse(connection.state.value.connected)
    }

    @Test
    fun `a helper found gone during a call is let go and connected again, and the players read says so`() {
        val connection = connection()
        val gone = ICaptureHelper.Stub.asInterface(GoneBinder())
        // Attached while it was alive (its death notice has not come yet): its next call finds it gone.
        val field = HelperConnection::class.java.getDeclaredField("helper").apply { isAccessible = true }
        field.set(connection, gone)
        val answer = connection.players()
        assertTrue(answer.problem, answer.problem!!.contains("stopped answering"))
        assertNull("let go", connection.helper)
        assertFalse(connection.state.value.connected)
    }

    /** A binder whose process is gone. */
    private class GoneBinder : android.os.IBinder {
        override fun getInterfaceDescriptor(): String = "com.echoledger.mobile.capture.ICaptureHelper"
        override fun pingBinder() = false
        override fun isBinderAlive() = false
        override fun queryLocalInterface(descriptor: String): android.os.IInterface? = null
        override fun dump(fd: java.io.FileDescriptor, args: Array<out String>?) = Unit
        override fun dumpAsync(fd: java.io.FileDescriptor, args: Array<out String>?) = Unit
        override fun transact(code: Int, data: android.os.Parcel, reply: android.os.Parcel?, flags: Int): Boolean =
            throw android.os.DeadObjectException()
        override fun linkToDeath(recipient: android.os.IBinder.DeathRecipient, flags: Int) = throw android.os.DeadObjectException()
        override fun unlinkToDeath(recipient: android.os.IBinder.DeathRecipient, flags: Int) = false
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
