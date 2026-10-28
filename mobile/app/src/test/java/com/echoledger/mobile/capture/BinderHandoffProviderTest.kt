package com.echoledger.mobile.capture

import android.net.Uri
import android.os.Bundle
import android.os.ParcelFileDescriptor
import android.os.Process
import com.echoledger.mobile.testsupport.AppTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Before
import org.junit.Test
import org.robolectric.Robolectric
import org.robolectric.shadows.ShadowBinder

/** The exported provider through which the ADB-launched helper hands over its binder. */
class BinderHandoffProviderTest : AppTest() {
    private val authority = "com.echoledger.mobile.handoff"
    private val uri get() = Uri.parse("content://$authority")

    private class FakeHelper(private val helperUid: Int = 2000) : ICaptureHelper.Stub() {
        var listener: ICallListener? = null

        override fun version() = 1

        override fun uid() = helperUid

        override fun capabilities() = "voice_communication"

        override fun startCapture(sink: ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true

        override fun startPhoneCapture(sink: ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true

        override fun stopCapture() = Unit

        override fun setCallListener(listener: ICallListener?) {
            this.listener = listener
        }

        override fun destroy() = Unit
    }

    @Before
    fun registerProvider() {
        Robolectric.setupContentProvider(BinderHandoffProvider::class.java, authority)
    }

    private fun attach(
        callingUid: Int,
        helper: FakeHelper = FakeHelper(),
        method: String = BinderHandoffProvider.METHOD_ATTACH,
        withBinder: Boolean = true
    ): Bundle? {
        ShadowBinder.setCallingUid(callingUid)
        val extras = Bundle().apply { if (withBinder) putBinder(BinderHandoffProvider.EXTRA_BINDER, helper) }
        return app.contentResolver.call(uri, method, null, extras)
    }

    @Test
    fun `the shell user can hand over the capture binder`() {
        val answer = attach(CallerPolicy.SHELL_UID)

        assertTrue(answer!!.getBoolean("ok"))
        val state = app.graph.helper.state.value
        assertTrue(state.connected)
        assertEquals("adb", state.source)
        assertEquals(2000, state.helperUid)
        assertNotNull(app.graph.helper.helper)
    }

    @Test
    fun `root and the app itself may also hand over a binder`() {
        assertTrue(attach(CallerPolicy.ROOT_UID, FakeHelper(0))!!.getBoolean("ok"))
        assertEquals(0, app.graph.helper.state.value.helperUid)

        assertTrue(attach(Process.myUid(), FakeHelper(Process.myUid()))!!.getBoolean("ok"))
        assertEquals(Process.myUid(), app.graph.helper.state.value.helperUid)
    }

    @Test
    fun `any other app is refused and nothing is attached`() {
        listOf(1000, 10_999, Process.myUid() + 1, 1999, 2001).forEach { uid ->
            try {
                attach(uid)
                fail("uid $uid was allowed")
            } catch (expected: SecurityException) {
                assertTrue(expected.message.orEmpty(), expected.message.orEmpty().contains(uid.toString()))
            }
            assertFalse("uid $uid must not connect a helper", app.graph.helper.state.value.connected)
            assertNull(app.graph.helper.helper)
        }
    }

    @Test
    fun `an allowed caller using another method gets nothing and attaches nothing`() {
        assertNull(attach(CallerPolicy.SHELL_UID, method = "detach"))
        assertNull(attach(CallerPolicy.SHELL_UID, method = ""))
        assertFalse(app.graph.helper.state.value.connected)
    }

    @Test
    fun `an attach call without a binder is ignored`() {
        assertNull(attach(CallerPolicy.SHELL_UID, withBinder = false))
        ShadowBinder.setCallingUid(CallerPolicy.SHELL_UID)
        assertNull(app.contentResolver.call(uri, BinderHandoffProvider.METHOD_ATTACH, null, null))
        assertFalse(app.graph.helper.state.value.connected)
    }

    @Test
    fun `the provider exposes no data to anyone`() {
        ShadowBinder.setCallingUid(10_999)
        assertNull(app.contentResolver.query(Uri.parse("content://$authority/anything"), null, null, null, null))
        assertNull(app.contentResolver.getType(uri))
        assertNull(app.contentResolver.insert(uri, android.content.ContentValues()))
        assertEquals(0, app.contentResolver.delete(uri, null, null))
        assertEquals(0, app.contentResolver.update(uri, android.content.ContentValues(), null, null))
    }

    @Test
    fun `a call listener set before the helper connects is passed on when it attaches`() {
        val listener = object : ICallListener.Stub() {
            override fun onCallStarted(packageName: String?) = Unit

            override fun onCallEnded() = Unit

            override fun onError(message: String?) = Unit
        }
        app.graph.helper.setCallListener(listener)
        val helper = FakeHelper()

        attach(CallerPolicy.SHELL_UID, helper)

        assertEquals(listener, helper.listener)
    }
}
