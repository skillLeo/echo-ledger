package com.echoledger.mobile.e2e

import android.media.MediaRecorder
import android.os.Build
import android.util.Log
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.recording.CaptureTest
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assume.assumeTrue
import org.junit.Test
import org.junit.runner.RunWith

/**
 * The capture helper below Android 14 (since v1.5): it starts as the shell user, records your voice on one channel, and
 * never asks for the other side. Start the helper first with the command in Setup (`adb shell "CLASSPATH=… app_process …"`);
 * it needs no account, so it also runs on a fresh emulator. Skipped from Android 14 on, and when the helper is not running.
 */
@RunWith(AndroidJUnit4::class)
class VoiceOnlyHelperTest {
    private val app get() = InstrumentationRegistry.getInstrumentation().targetContext.applicationContext as EchoLedgerApp

    @Test
    fun theHelperRecordsYourVoiceOnOneChannelBelowAndroid14() {
        assumeTrue("Android 11 to 13 only", Build.VERSION.SDK_INT in 30..33)
        val connection = app.graph.helper
        val deadline = System.currentTimeMillis() + HANDOFF_WAIT_MS
        while (connection.helper == null && System.currentTimeMillis() < deadline) Thread.sleep(500)
        val helper = connection.helper
        assumeTrue("the helper is not running: start it with the command in Setup", helper != null)
        helper!!

        val capabilities = helper.capabilities()
        Log.i(TAG, capabilities)
        assertEquals("the shell user", 2000, helper.uid())
        assertTrue(capabilities, capabilities.contains("far_party=false"))
        assertTrue("the microphone stays open beside a call: $capabilities", capabilities.contains("mic_beside_calls=true"))
        assertEquals("nothing to arm below Android 14", "armed", helper.arm(true))
        assertTrue(helper.selfTest(), helper.selfTest().startsWith("fail: hearing the other side needs Android 14"))

        val result = runBlocking { CaptureTest.run(app, helper, false, MediaRecorder.AudioSource.MIC) }
        result.stages.forEach { Log.i(TAG, "${it.title}: ${it.ok} (${it.detail})") }
        Log.i(TAG, "${result.verdict}: ${result.headline} ${result.fix.orEmpty()}")

        assertEquals("one channel: never a silent second one", 1, helper.captureChannels())
        val voice = result.stages.single { it.check == CaptureTest.Check.MY_VOICE }
        assertNotEquals("the microphone gave no sound at all: ${voice.detail}", "No sound arrived from the microphone.", voice.detail)
        assertNull(result.stages.single { it.check == CaptureTest.Check.OTHER_SIDE }.ok)
        assertEquals(true, result.stages.single { it.check == CaptureTest.Check.FILE }.ok)
    }

    private companion object {
        const val TAG = "VoiceOnlyHelperTest"
        const val HANDOFF_WAIT_MS = 30_000L
    }
}
