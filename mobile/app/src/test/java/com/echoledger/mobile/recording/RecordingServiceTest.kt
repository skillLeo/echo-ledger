package com.echoledger.mobile.recording

import android.content.Intent
import com.echoledger.mobile.capture.ICallListener
import com.echoledger.mobile.capture.ICaptureHelper
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.tile.TileTrampolineActivity
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Service entry points that need no audio hardware. Every start arrives through startForegroundService, so each path must
 * go foreground before it stops, and a missing permission or helper must end quietly.
 */
class RecordingServiceTest : AppTest() {
    private class RecordingHelper : ICaptureHelper.Stub() {
        var listener: ICallListener? = null
        var listenerSet = 0

        override fun version() = 1

        override fun uid() = 2000

        override fun capabilities() = ""

        override fun startCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true

        override fun stopCapture() = Unit

        override fun setCallListener(listener: ICallListener?) {
            this.listener = listener
            listenerSet++
        }

        override fun destroy() = Unit
    }

    private fun start(action: String?): RecordingService {
        val intent = Intent(app, RecordingService::class.java).apply { if (action != null) setAction(action) }
        val controller = buildService(RecordingService::class.java, intent).create()
        controller.startCommand(0, 1)
        return controller.get()
    }

    private fun settings() = runBlocking { app.graph.settings.current() }

    @Test
    fun `an unknown or missing action goes foreground briefly and stops the service`() {
        listOf(null, "com.echoledger.mobile.UNKNOWN").forEach { action ->
            val service = start(action)

            assertTrue("action $action", shadowOf(service).isStoppedBySelf)
        }
    }

    @Test
    fun `Stop with nothing recording stops the service without crashing`() {
        val service = start(RecordingService.ACTION_STOP)

        assertTrue(shadowOf(service).isStoppedBySelf)
        assertFalse(RecordingService.isRecording)
    }

    @Test
    fun `Disarm clears the armed setting and stops the service`() {
        runBlocking { app.graph.settings.update { it.copy(autoRecordArmed = true) } }

        val service = start(RecordingService.ACTION_DISARM)
        pumpUntil("the setting to be cleared") { !settings().autoRecordArmed }

        assertTrue(shadowOf(service).isStoppedBySelf)
    }

    @Test
    fun `Arm without the capture helper refuses and leaves the setting off`() {
        runBlocking { app.graph.settings.update { it.copy(autoRecordArmed = true) } }

        val service = start(RecordingService.ACTION_ARM)
        pumpUntil("the setting to be cleared") { !settings().autoRecordArmed }

        assertTrue(shadowOf(service).isStoppedBySelf)
    }

    @Test
    fun `Arm with the helper running stays alive in the foreground and listens for calls`() {
        val helper = RecordingHelper()
        app.graph.helper.attach(helper, source = "adb")

        val service = start(RecordingService.ACTION_ARM)
        pumpUntil("the setting to be saved") { settings().autoRecordArmed }

        assertFalse("an armed service keeps running", shadowOf(service).isStoppedBySelf)
        assertNotNull(shadowOf(service).lastForegroundNotification)
        assertNotNull("the helper must be told where to report calls", helper.listener)
    }

    @Test
    fun `Disarm after Arm removes the call listener and stops`() {
        val helper = RecordingHelper()
        app.graph.helper.attach(helper, source = "adb")
        val controller = buildService(
            RecordingService::class.java,
            Intent(app, RecordingService::class.java).setAction(RecordingService.ACTION_ARM)
        ).create()
        controller.startCommand(0, 1)
        pumpUntil("armed") { settings().autoRecordArmed }

        controller.get().onStartCommand(Intent(app, RecordingService::class.java).setAction(RecordingService.ACTION_DISARM), 0, 2)
        pumpUntil("disarmed") { !settings().autoRecordArmed }

        assertTrue(shadowOf(controller.get()).isStoppedBySelf)
        assertEquals(null, helper.listener)
    }

    @Test
    fun `recording without the microphone permission ends quietly`() {
        shadowOf(app).denyPermissions(android.Manifest.permission.RECORD_AUDIO)
        val service = start(RecordingService.ACTION_TOGGLE)
        pumpUntil("the service to settle") { shadowOf(service).isStoppedBySelf }

        assertFalse(RecordingService.isRecording)
    }

    @Test
    fun `the service intent helper targets the service with the requested action`() {
        val intent = RecordingService.intent(app, RecordingService.ACTION_TOGGLE)

        assertEquals(RecordingService::class.java.name, intent.component!!.className)
        assertEquals(RecordingService.ACTION_TOGGLE, intent.action)
        assertEquals(
            setOf("ARM", "DISARM", "TOGGLE", "STOP"),
            setOf(
                RecordingService.ACTION_ARM,
                RecordingService.ACTION_DISARM,
                RecordingService.ACTION_TOGGLE,
                RecordingService.ACTION_STOP
            ).map {
                it.substringAfterLast('.')
            }.toSet()
        )
    }

    @Test
    fun `the quick settings trampoline starts a toggle and closes at once`() {
        val controller = buildActivity(TileTrampolineActivity::class.java).create()

        val started = shadowOf(app).nextStartedService
        assertEquals(RecordingService.ACTION_TOGGLE, started.action)
        assertTrue(controller.get().isFinishing)
    }
}
