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
    /** A helper whose phone line gives both voices on one track: it always writes 48 kHz stereo frames, left = the line. */
    private class OneTrackHelper(private val seconds: Int) : ICaptureHelper.Stub() {
        @Volatile private var sink: android.os.ParcelFileDescriptor? = null

        override fun version() = 1
        override fun uid() = 2000
        override fun capabilities() = ""
        override fun startCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int): Boolean {
            // A real helper gets its own copy of the pipe through Binder; the service closes its end at once.
            val mine = sink!!.dup()
            this.sink = mine
            Thread {
                val out = java.io.FileOutputStream(mine.fileDescriptor)
                val second = ByteArray(Pcm.CAPTURE_RATE * 4)
                for (i in second.indices step 4) {
                    val v = ((i / 4) % 200 - 100) * 100 // a sawtooth on the left, silence on the right
                    second[i] = (v and 0xff).toByte()
                    second[i + 1] = (v shr 8 and 0xff).toByte()
                }
                runCatching { repeat(seconds) { out.write(second) } }
            }.start()
            return true
        }
        override fun startPhoneCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int) =
            startCapture(sink, sampleRate, micSource)
        override fun arm(on: Boolean): String = if (on) "armed" else "disarmed"
        override fun setCallApps(packages: Array<out String>?) = Unit
        override fun selfTest(): String = "ok"
        override fun captureChannels(): Int = 1
        override fun players(): String = "[]"
        override fun armApps(uids: IntArray?, voiceChat: Boolean): String = "armed"
        override fun startAppCapture(sink: android.os.ParcelFileDescriptor?, uid: Int, usage: Int, voiceChat: Boolean): Boolean = false
        override fun stopAppCapture() = Unit
        override fun stopCapture() {
            runCatching { sink?.close() }
        }
        override fun setCallListener(listener: ICallListener?) = Unit
        override fun destroy() = Unit
    }

    private class RecordingHelper : ICaptureHelper.Stub() {
        var listener: ICallListener? = null
        var listenerSet = 0

        override fun version() = 1

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
    fun `Arm without the capture helper stays on and says capture is not ready instead of turning off`() {
        runBlocking { app.graph.settings.update { it.copy(autoRecordArmed = false) } }

        val service = start(RecordingService.ACTION_ARM)
        pumpUntil("the setting to be saved") { settings().autoRecordArmed }

        assertFalse(shadowOf(service).isStoppedBySelf)
        val notice = shadowOf(service).lastForegroundNotification
        assertEquals("Call capture isn't ready — tap to fix", notice.extras.getString(android.app.Notification.EXTRA_TITLE))
        assertTrue(CaptureLog.lines(app).any { it.contains("auto-record on: helper not running") })
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

    /**
     * Seen on an Android 16 emulator: a 25 s phone call saved as 12 s at double speed. The pill's timer asked for the
     * recorded time before the helper said "one track", which made the encoder stereo; mono audio then went into it.
     */
    @Test
    @org.robolectric.annotation.Config(sdk = [34])
    fun `a one-track helper recording keeps its real length`() {
        val helper = OneTrackHelper(seconds = 4)
        app.graph.helper.attach(helper, source = "adb")
        app.getSystemService(android.media.AudioManager::class.java).mode = android.media.AudioManager.MODE_IN_COMMUNICATION
        val controller = buildService(
            RecordingService::class.java,
            Intent(app, RecordingService::class.java).setAction(RecordingService.ACTION_TOGGLE)
        ).create()
        controller.startCommand(0, 1)
        pumpUntil("the recording to start") { RecordingService.isRecording }
        Thread.sleep(2_500)
        org.robolectric.shadows.ShadowLooper.idleMainLooper(1, java.util.concurrent.TimeUnit.SECONDS)
        Thread.sleep(2_500)
        controller.get().onStartCommand(Intent(app, RecordingService::class.java).setAction(RecordingService.ACTION_TOGGLE), 0, 2)
        pumpUntil("the recording to be saved") { !RecordingService.isRecording && app.graph.recordings.all().isNotEmpty() }

        val saved = app.graph.recordings.all().single()
        assertEquals("one channel", 1, saved.channels)
        assertTrue(
            "4 s of audio is about 4 s long, not half: ${saved.durationMs} ms\n" + java.io.File(app.filesDir, "capture-log.txt").readText(),
            saved.durationMs in 3_500L..4_500L
        )
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
