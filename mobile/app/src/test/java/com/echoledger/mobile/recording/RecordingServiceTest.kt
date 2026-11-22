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
import org.junit.Assert.assertNull
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
            // Robolectric's pipe is a file: written before the capture's reader starts, else a reader that comes first
            // finds it empty and stops, as at the end of a real pipe (a busy machine lost that race).
            val out = java.io.FileOutputStream(mine.fileDescriptor)
            val second = ByteArray(Pcm.CAPTURE_RATE * 4)
            for (i in second.indices step 4) {
                val v = ((i / 4) % 200 - 100) * 100 // a sawtooth on the left, silence on the right
                second[i] = (v and 0xff).toByte()
                second[i + 1] = (v shr 8 and 0xff).toByte()
            }
            runCatching { repeat(seconds) { out.write(second) } }
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

    /** The capture journal (v1.8 0.2b) from the service: the stages a call reached, and why it stopped. */
    @Test
    fun `a call the helper reports goes into the capture journal stage by stage, with why it stopped`() {
        val helper = RecordingHelper()
        app.graph.helper.attach(helper, source = "adb")
        start(RecordingService.ACTION_ARM)
        pumpUntil("armed") { settings().autoRecordArmed && helper.listener != null }
        assertTrue(CallJournal.book(app).marks.any { it.kind == CallJournal.State.ARMED && it.value == "helper ready" })

        helper.listener!!.onCallStarted("com.whatsapp")
        pumpUntil("the call to start recording") { CallJournal.book(app).calls.any { it.reached(CallJournal.Stage.STARTED) } }
        Thread.sleep(3_200) // a call shorter than 3 s is a missed or rejected one, dropped without a word
        helper.listener!!.onCallEnded()
        pumpUntil("the call to be finished") { CallJournal.book(app).calls.singleOrNull()?.finished == true }

        val call = CallJournal.book(app).calls.single()
        assertEquals("whatsapp", call.app)
        assertEquals(CallJournal.Seen.HELPER, call.seenBy)
        // Nothing said the call was answered, so it was saved and skipped (it can be restored for a day).
        assertEquals(listOf("detected", "armed", "started", "saved"), call.stages.map { it.stage })
        assertEquals("skipped", call.result)
        assertEquals("not answered", call.note)
        assertTrue(call.endedAtMs != null)
    }

    /**
     * A helper that plays [pcm] (48 kHz stereo, left = this side, right = the other side) into the call's pipe. Robolectric's
     * pipe is a file, so it is all written before the capture's reader starts (a reader that came first would find the
     * file empty and stop, as at the end of a real pipe).
     */
    private class ScriptedHelper(private val pcm: ShortArray) : ICaptureHelper.Stub() {
        var listener: ICallListener? = null

        @Volatile private var sink: android.os.ParcelFileDescriptor? = null

        override fun version() = 1
        override fun uid() = 2000
        override fun capabilities() = ""
        override fun startCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int): Boolean {
            val mine = sink!!.dup()
            this.sink = mine
            java.io.FileOutputStream(mine.fileDescriptor).write(Pcm.toLittleEndianBytes(pcm))
            return true
        }
        override fun startPhoneCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int) =
            startCapture(sink, sampleRate, micSource)
        override fun arm(on: Boolean): String = if (on) "armed" else "disarmed"
        override fun setCallApps(packages: Array<out String>?) = Unit
        override fun selfTest(): String = "ok"
        override fun captureChannels(): Int = 2
        override fun players(): String = "[]"
        override fun armApps(uids: IntArray?, voiceChat: Boolean): String = "armed"
        override fun startAppCapture(sink: android.os.ParcelFileDescriptor?, uid: Int, usage: Int, voiceChat: Boolean): Boolean = false
        override fun stopAppCapture() = Unit
        override fun stopCapture() {
            runCatching { sink?.close() }
        }
        override fun setCallListener(listener: ICallListener?) {
            this.listener = listener
        }
        override fun destroy() = Unit
    }

    private val rate = Pcm.CAPTURE_RATE

    private fun tone(ms: Long): ShortArray = ShortArray((rate * ms / 1000).toInt()) { i ->
        (kotlin.math.sin(2 * Math.PI * 425 * i / rate) * 0.2 * 32767).toInt().toShort()
    }

    private fun quiet(ms: Long): ShortArray = ShortArray((rate * ms / 1000).toInt())

    /** Voiced speech stand-in: many harmonics of a gliding pitch with syllable-like loudness (as ConnectGateTest's). */
    private fun speech(ms: Long): ShortArray {
        val random = kotlin.random.Random(1)
        var phase = 0.0
        return ShortArray((rate * ms / 1000).toInt()) { i ->
            val t = i.toDouble() / rate
            phase += 2 * Math.PI * (130 + 40 * kotlin.math.sin(2 * Math.PI * 1.7 * t)) / rate
            var v = 0.0
            for (h in 1..24) v += kotlin.math.sin(phase * h) / 24
            val syllable = 0.6 + 0.4 * kotlin.math.abs(kotlin.math.sin(2 * Math.PI * 3 * t))
            ((v * syllable * 0.5 + random.nextDouble(-0.02, 0.02)) * 32767).toInt().toShort()
        }
    }

    private fun join(vararg parts: ShortArray): ShortArray = parts.fold(ShortArray(0)) { a, b -> a + b }

    private fun stereo(near: ShortArray, far: ShortArray): ShortArray =
        ShortArray(minOf(near.size, far.size) * 2) { i -> if (i % 2 == 0) near[i / 2] else far[i / 2] }

    /** A call ([pkg]: WhatsApp) through [helper] that lasts a little over 3 s; its journal entry once it is finished. */
    private fun callThrough(helper: ScriptedHelper, pkg: String = "com.whatsapp"): CallJournal.Call {
        app.graph.helper.attach(helper, source = "adb")
        start(RecordingService.ACTION_ARM)
        pumpUntil("armed") { settings().autoRecordArmed && helper.listener != null }
        helper.listener!!.onCallStarted(pkg)
        pumpUntil("the call to start recording") { CallJournal.book(app).calls.any { it.reached(CallJournal.Stage.STARTED) } }
        Thread.sleep(3_200) // a call shorter than 3 s is a missed or rejected one
        helper.listener!!.onCallEnded()
        pumpUntil("the call to be finished") { CallJournal.book(app).calls.singleOrNull()?.finished == true }
        return CallJournal.book(app).calls.single()
    }

    /**
     * v1.8 0.2 review: while an outgoing call rings, the ringback tone and your own voice fill both channels. That is
     * neither "both channels have audio" nor an answer: the journal keeps the answer the recording's gate saw.
     */
    @Test
    fun `a call that only rang has no answer and no both-channels stage in the journal, however loud the ringback`() {
        val far = join(tone(1_000), quiet(2_000), tone(1_000), quiet(2_000))
        val call = callThrough(ScriptedHelper(stereo(speech(6_000), far)))
        assertEquals(null, call.answeredAtMs)
        assertFalse(call.stages.map { it.stage }.toString(), call.reached(CallJournal.Stage.BOTH_AUDIO))
        assertEquals("skipped", call.result)
    }

    @Test
    fun `the answer the gate saw goes into the journal, and both channels count from it`() {
        val far = join(tone(1_000), quiet(2_000), speech(4_000))
        val call = callThrough(ScriptedHelper(stereo(join(quiet(3_000), speech(4_000)), far)))
        assertEquals("the other side spoke", call.answeredBy)
        val answeredAfterMs = call.answeredAtMs!! - call.atMs
        assertTrue("answered $answeredAfterMs ms after the call was seen", answeredAfterMs in 2_500L..4_500L)
        assertTrue(call.reached(CallJournal.Stage.BOTH_AUDIO))
    }

    /**
     * A helper that refuses to record, the way it does when another app holds the microphone ([words] what it says, null
     * for nothing).
     */
    private class RefusingHelper(private val words: String? = "Call capture could not start: mic: did not start") : ICaptureHelper.Stub() {
        var listener: ICallListener? = null

        override fun version() = 1
        override fun uid() = 2000
        override fun capabilities() = ""
        override fun startCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int): Boolean {
            words?.let { listener?.onError(it) }
            return false
        }
        override fun startPhoneCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int) =
            startCapture(sink, sampleRate, micSource)
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
        }
        override fun destroy() = Unit
    }

    @Test
    fun `a call the helper cannot record is journaled where it stopped, with the reason from the helper's words`() {
        val helper = RefusingHelper()
        app.graph.helper.attach(helper, source = "adb")
        start(RecordingService.ACTION_ARM)
        pumpUntil("armed") { settings().autoRecordArmed && helper.listener != null }

        helper.listener!!.onCallStarted("us.zoom.videomeetings")
        pumpUntil("the call to be journaled") { CallJournal.book(app).calls.singleOrNull()?.finished == true }

        val call = CallJournal.book(app).calls.single()
        assertEquals("zoom", call.app)
        assertEquals(listOf("detected", "armed"), call.stages.map { it.stage })
        assertEquals(CallJournal.Failure("started", "mic_busy", com.echoledger.mobile.calls.MissedReason.MIC_BUSY.detail), call.failure)
        assertFalse(RecordingService.isRecording)
    }

    @Test
    fun `with Don't record calls a call is not recorded, and the journal says why`() {
        runBlocking { app.graph.settings.update { it.copy(afterEachCall = com.echoledger.mobile.calls.CallChoice.OFF) } }
        val helper = RefusingHelper()
        app.graph.helper.attach(helper, source = "adb")
        start(RecordingService.ACTION_ARM)
        pumpUntil("armed") { settings().autoRecordArmed && helper.listener != null }

        helper.listener!!.onCallStarted("us.zoom.videomeetings")
        pumpUntil("the call to be journaled") { CallJournal.book(app).calls.singleOrNull()?.finished == true }

        val call = CallJournal.book(app).calls.single()
        assertEquals(CallJournal.Result.NOT_WANTED.wire, call.result)
        assertEquals(CallJournal.NotWanted.CALLS_OFF, call.note)
        assertNull("nothing was asked of the helper", call.failure)
        assertFalse(RecordingService.isRecording)
    }

    @Test
    fun `a refused start is never blamed on a problem from an earlier capture`() {
        // An hour ago another app held the microphone; this time the helper gives no reason.
        CaptureProblems.record(app, "Call capture could not start: mic: did not start")
        val helper = RefusingHelper(words = null)
        app.graph.helper.attach(helper, source = "adb")
        start(RecordingService.ACTION_ARM)
        pumpUntil("armed") { settings().autoRecordArmed && helper.listener != null }

        helper.listener!!.onCallStarted("us.zoom.videomeetings")
        pumpUntil("the start to be refused") { CallJournal.book(app).calls.isNotEmpty() && !RecordingService.isRecording }
        // The helper's words may still be on their way from its process: it waits a moment for them.
        org.robolectric.shadows.ShadowLooper.idleMainLooper(1, java.util.concurrent.TimeUnit.SECONDS)
        pumpUntil("the call to be journaled") { CallJournal.book(app).calls.singleOrNull()?.finished == true }

        val failure = CallJournal.book(app).calls.single().failure
        assertEquals(CallJournal.Failure("started", "unknown", com.echoledger.mobile.calls.MissedReason.UNKNOWN.detail), failure)
    }

    @Test
    fun `a phone line that would not open is said so, not blamed on the microphone`() {
        runBlocking { app.graph.settings.update { it.copy(phoneCalls = "always") } }
        val helper = RefusingHelper("Call capture could not start: phone line (both directions): did not start; phone line: did not start")
        app.graph.helper.attach(helper, source = "adb")
        start(RecordingService.ACTION_ARM)
        pumpUntil("armed") { settings().autoRecordArmed && helper.listener != null }

        helper.listener!!.onCallStarted(com.echoledger.mobile.core.CallTargets.TELEPHONY)
        pumpUntil("the call to be journaled") { CallJournal.book(app).calls.singleOrNull()?.finished == true }

        val call = CallJournal.book(app).calls.single()
        assertEquals("phone_call", call.app)
        assertEquals(CallJournal.Failure("started", "unknown", com.echoledger.mobile.calls.MissedReason.PHONE_LINE), call.failure)
    }

    /** v1.8 0.3 review: a SIM call whose line would not open, said once and quietly; never the helper advice. */
    @Test
    fun `a phone line that would not open says nothing when the Phone app's recording is coming, else one quiet line`() {
        org.robolectric.shadows.ShadowBuild.setManufacturer("samsung")
        shadowOf(app).grantPermissions(
            android.Manifest.permission.POST_NOTIFICATIONS,
            android.Manifest.permission.READ_CALL_LOG,
            com.echoledger.mobile.calls.PhoneAppRecordings.permission()
        )
        android.provider.Settings.System.putString(
            app.contentResolver,
            com.echoledger.mobile.calls.PhoneAppRecordings.SAMSUNG_AUTO_RECORD,
            "1"
        )
        runBlocking { app.graph.settings.update { it.copy(phoneCalls = "always") } }
        val helper = RefusingHelper("Call capture could not start: phone line (both directions): did not start; phone line: did not start")
        app.graph.helper.attach(helper, source = "adb")
        start(RecordingService.ACTION_ARM)
        pumpUntil("armed") { settings().autoRecordArmed && helper.listener != null }
        val notifications = shadowOf(app.getSystemService(android.app.NotificationManager::class.java))
        fun titles() = notifications.allNotifications.map { it.extras.getString(android.app.Notification.EXTRA_TITLE) }

        helper.listener!!.onCallStarted(com.echoledger.mobile.core.CallTargets.TELEPHONY)
        pumpUntil("the call to be journaled") { CallJournal.book(app).calls.singleOrNull()?.finished == true }
        org.robolectric.shadows.ShadowLooper.idleMainLooper(1, java.util.concurrent.TimeUnit.SECONDS)
        assertTrue(
            "the Phone app records every call: its recording comes in, nothing to say: ${titles()}",
            titles().none {
                it?.contains("not recorded") ==
                    true ||
                    it == "No audio was captured"
            }
        )
        helper.listener!!.onCallEnded()

        android.provider.Settings.System.putString(
            app.contentResolver,
            com.echoledger.mobile.calls.PhoneAppRecordings.SAMSUNG_AUTO_RECORD,
            "0"
        )
        helper.listener!!.onCallStarted(com.echoledger.mobile.core.CallTargets.TELEPHONY)
        pumpUntil("the second call to be journaled") { CallJournal.book(app).calls.count { it.finished } == 2 }
        pumpUntil("the line about it") { titles().contains("Phone call not recorded") }
        val text = notifications.allNotifications.single {
            it.extras.getString(android.app.Notification.EXTRA_TITLE) ==
                "Phone call not recorded"
        }
            .extras.getCharSequence(android.app.Notification.EXTRA_BIG_TEXT).toString()
        assertTrue(text, text.contains(com.echoledger.mobile.calls.PhoneAppRecordings.guide("samsung")))
        assertFalse(text, text.contains("helper"))
        assertTrue("never the high-priority helper alert", titles().none { it == "Call not recorded" || it == "No audio was captured" })
    }

    /**
     * v1.8 0.3 review: a SIM call that kept your voice only, on a phone whose Phone app records every call: its two-sided
     * copy is looked for and brought in, so nothing tells the person to restart the helper.
     */
    @Test
    fun `a one-sided phone call whose Phone app recording is coming says nothing, and its copy is looked for`() {
        org.robolectric.shadows.ShadowBuild.setManufacturer("samsung")
        shadowOf(app).grantPermissions(
            android.Manifest.permission.POST_NOTIFICATIONS,
            android.Manifest.permission.READ_CALL_LOG,
            com.echoledger.mobile.calls.PhoneAppRecordings.permission()
        )
        android.provider.Settings.System.putString(
            app.contentResolver,
            com.echoledger.mobile.calls.PhoneAppRecordings.SAMSUNG_AUTO_RECORD,
            "1"
        )
        runBlocking { app.graph.settings.update { it.copy(phoneCalls = "always") } }
        // Your voice the whole call; the other side's channel digital silence (the line gave only your side).
        val call = callThrough(ScriptedHelper(stereo(speech(18_000), quiet(18_000))), com.echoledger.mobile.core.CallTargets.TELEPHONY)
        assertEquals(call.toString(), "kept", call.result)
        org.robolectric.shadows.ShadowLooper.idleMainLooper(1, java.util.concurrent.TimeUnit.SECONDS)
        val titles = shadowOf(app.getSystemService(android.app.NotificationManager::class.java)).allNotifications
            .map { it.extras.getString(android.app.Notification.EXTRA_TITLE) }
        assertTrue(titles.toString(), titles.none { it == "Only your voice was recorded" })
        assertEquals("the Phone app's two-sided copy is looked for", 1, com.echoledger.mobile.calls.PendingImports.waiting(app).size)
        // It waits for that copy undecided, with no card of its own: the copy replaces it, so one call is one card.
        val own = app.graph.recordings.all().single()
        assertEquals("pending_decision", own.state)
        assertEquals(null, com.echoledger.mobile.overlay.DecisionPopup.showingFor)
        val keptLater = androidx.work.WorkManager.getInstance(app)
            .getWorkInfosForUniqueWork(
                com.echoledger.mobile.calls.DecisionTimeoutWorker.workName(own.id)
            ).get(10, java.util.concurrent.TimeUnit.SECONDS)
        assertTrue(keptLater.isEmpty())
    }

    /** v1.8 0.3 re-review: only the phone line's own failure gets the Phone app line; others keep their words and fix. */
    @Test
    fun `a phone call that could not start for another reason keeps its own words and the Setup button`() {
        shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        runBlocking { app.graph.settings.update { it.copy(phoneCalls = "always") } }
        val helper = RefusingHelper("Call capture could not start: mic: did not start")
        app.graph.helper.attach(helper, source = "adb")
        start(RecordingService.ACTION_ARM)
        pumpUntil("armed") { settings().autoRecordArmed && helper.listener != null }
        helper.listener!!.onCallStarted(com.echoledger.mobile.core.CallTargets.TELEPHONY)
        pumpUntil("the call to be journaled") { CallJournal.book(app).calls.singleOrNull()?.finished == true }
        val notifications = shadowOf(app.getSystemService(android.app.NotificationManager::class.java))
        pumpUntil("the notice") {
            notifications.allNotifications.any { it.extras.getString(android.app.Notification.EXTRA_TITLE) == "Call not recorded" }
        }
        val text = notifications.allNotifications.single {
            it.extras.getString(android.app.Notification.EXTRA_TITLE) == "Call not recorded"
        }
            .extras.getCharSequence(android.app.Notification.EXTRA_BIG_TEXT).toString()
        assertTrue(text, text.contains("mic: did not start"))
        assertTrue(
            notifications.allNotifications.none {
                it.extras.getString(android.app.Notification.EXTRA_TITLE) ==
                    "Phone call not recorded"
            }
        )
    }

    /**
     * v1.8 0.3 re-review: a one-sided recording is held only once its call waits for the Phone app's copy, so a release
     * that runs in between (another call's check ending, the app opening) never lets it go before the copy can come.
     */
    @Test
    fun `a one-sided phone call's recording is held only after its wait for the Phone app's copy is there`() {
        org.robolectric.shadows.ShadowBuild.setManufacturer("samsung")
        shadowOf(
            app
        ).grantPermissions(android.Manifest.permission.READ_CALL_LOG, com.echoledger.mobile.calls.PhoneAppRecordings.permission())
        android.provider.Settings.System.putString(
            app.contentResolver,
            com.echoledger.mobile.calls.PhoneAppRecordings.SAMSUNG_AUTO_RECORD,
            "1"
        )
        runBlocking { app.graph.settings.update { it.copy(phoneCalls = "always") } }
        val helper = ScriptedHelper(stereo(speech(18_000), quiet(18_000)))
        app.graph.helper.attach(helper, source = "adb")
        start(RecordingService.ACTION_ARM)
        pumpUntil("armed") { settings().autoRecordArmed && helper.listener != null }
        helper.listener!!.onCallStarted(com.echoledger.mobile.core.CallTargets.TELEPHONY)
        pumpUntil("the call to start recording") { CallJournal.book(app).calls.any { it.reached(CallJournal.Stage.STARTED) } }
        Thread.sleep(3_200)
        helper.listener!!.onCallEnded()
        org.robolectric.shadows.ShadowLooper.idleMainLooper()
        // Held on the saving thread; the main thread has not run since.
        val prefs = app.getSharedPreferences("phone_app_recordings", android.content.Context.MODE_PRIVATE)
        val until = System.currentTimeMillis() + 20_000
        while (prefs.getString("held", null).isNullOrEmpty() && System.currentTimeMillis() < until) Thread.sleep(20)
        val id = prefs.getString("held", null)!!.substringBefore(':')
        // A release now (another call's check ending) must leave it held: its call already waits for the copy.
        runBlocking { com.echoledger.mobile.calls.PhoneAppRecordings.releaseHeld(app) }
        assertEquals("pending_decision", app.graph.recordings.get(id)?.state)
        assertEquals(1, com.echoledger.mobile.calls.PendingImports.waiting(app).size)
    }

    /** v1.8 0.3 review: a SIM call EchoLedger recorded itself is not left waiting for the Phone app's copy. */
    @Test
    fun `a phone call EchoLedger recorded on both sides is not left waiting for the Phone app's copy`() {
        org.robolectric.shadows.ShadowBuild.setManufacturer("samsung")
        runBlocking { app.graph.settings.update { it.copy(phoneCalls = "always") } }
        val far = join(tone(1_000), quiet(2_000), speech(4_000))
        val call =
            callThrough(ScriptedHelper(stereo(join(quiet(3_000), speech(4_000)), far)), com.echoledger.mobile.core.CallTargets.TELEPHONY)
        assertEquals("phone_call", call.app)
        assertTrue(call.reached(CallJournal.Stage.BOTH_AUDIO))
        assertEquals(emptyList<LongRange>(), com.echoledger.mobile.calls.PendingImports.waiting(app))
    }

    @Test
    fun `a call in an app whose auto-record is off is journaled as not wanted, and nothing records`() {
        runBlocking { app.graph.settings.update { it.copy(autoRecordApps = mapOf("whatsapp" to false)) } }
        val helper = RecordingHelper()
        app.graph.helper.attach(helper, source = "adb")
        start(RecordingService.ACTION_ARM)
        pumpUntil("armed") { settings().autoRecordArmed && helper.listener != null }
        pumpUntil("the choices to reach the journal") {
            CallJournal.book(app).settings.lastOrNull()?.choices?.appsOff == listOf("whatsapp")
        }

        helper.listener!!.onCallStarted("com.whatsapp")
        pumpUntil("the call to be journaled") { CallJournal.book(app).calls.singleOrNull()?.finished == true }

        val call = CallJournal.book(app).calls.single()
        assertEquals("not_wanted", call.result)
        assertEquals("auto-record is off for WhatsApp", call.note)
        assertFalse(RecordingService.isRecording)
    }

    @Test
    @org.robolectric.annotation.Config(sdk = [34])
    fun `the pill's look is read when a recording starts, never during it`() {
        runBlocking { app.graph.settings.update { it.copy(uiFlags = mapOf("ui_v2.recording_controls" to true)) } }
        app.graph.helper.attach(OneTrackHelper(seconds = 4), source = "adb")
        app.getSystemService(android.media.AudioManager::class.java).mode = android.media.AudioManager.MODE_IN_COMMUNICATION
        val toggle = Intent(app, RecordingService::class.java).setAction(RecordingService.ACTION_TOGGLE)
        val controller = buildService(RecordingService::class.java, toggle).create()
        controller.startCommand(0, 1)
        pumpUntil("the recording to start") { RecordingService.isRecording }
        assertTrue("on when it started", RecordingService.pillV2)

        // The server turns it off during the recording: the pill stays as it began.
        runBlocking { app.graph.settings.update { it.copy(uiFlags = emptyMap()) } }
        Thread.sleep(300)
        org.robolectric.shadows.ShadowLooper.idleMainLooper(200, java.util.concurrent.TimeUnit.MILLISECONDS)
        assertTrue("never changed during it", RecordingService.pillV2)
        controller.get().onStartCommand(toggle, 0, 2)
        pumpUntil("the recording to be saved") { !RecordingService.isRecording && app.graph.recordings.all().isNotEmpty() }

        // The next recording reads the flags again.
        controller.get().onStartCommand(toggle, 0, 3)
        pumpUntil("the next recording to start") { RecordingService.isRecording }
        assertFalse(RecordingService.pillV2)
        controller.get().onStartCommand(toggle, 0, 4)
        pumpUntil("the next recording to be saved") { !RecordingService.isRecording }
    }

    @Test
    @org.robolectric.annotation.Config(sdk = [34])
    fun `the new design chosen on this phone draws the new pill with no server flag (parity PH-OTH-032)`() {
        com.echoledger.mobile.ui.v2.shell.DesignChoice.set(app, com.echoledger.mobile.ui.v2.shell.Design.V2)
        try {
            app.graph.helper.attach(OneTrackHelper(seconds = 4), source = "adb")
            app.getSystemService(android.media.AudioManager::class.java).mode = android.media.AudioManager.MODE_IN_COMMUNICATION
            val toggle = Intent(app, RecordingService::class.java).setAction(RecordingService.ACTION_TOGGLE)
            val controller = buildService(RecordingService::class.java, toggle).create()
            controller.startCommand(0, 1)
            pumpUntil("the recording to start") { RecordingService.isRecording }
            assertTrue("the pill window draws the new pill", RecordingService.pillV2)
            controller.get().onStartCommand(toggle, 0, 2)
            pumpUntil("the recording to be saved") { !RecordingService.isRecording }
            // Back on classic, the next recording has classic's pill.
            com.echoledger.mobile.ui.v2.shell.DesignChoice.set(app, com.echoledger.mobile.ui.v2.shell.Design.Classic)
            controller.get().onStartCommand(toggle, 0, 3)
            pumpUntil("the next recording to start") { RecordingService.isRecording }
            assertFalse(RecordingService.pillV2)
            controller.get().onStartCommand(toggle, 0, 4)
            pumpUntil("the next recording to be saved") { !RecordingService.isRecording }
        } finally {
            com.echoledger.mobile.ui.v2.shell.DesignChoice.set(app, null)
        }
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
        assertEquals("Quick Settings tile", started.getStringExtra(RecordingService.EXTRA_FROM))
        assertTrue(controller.get().isFinishing)
    }

    @Test
    fun `the Record now app shortcut starts the same toggle and says where it came from (Phase 1C A4)`() {
        val shortcut = android.content.Intent(
            app,
            TileTrampolineActivity::class.java
        ).putExtra(TileTrampolineActivity.EXTRA_FROM, "App shortcut")
        buildActivity(TileTrampolineActivity::class.java, shortcut).create()

        val started = shadowOf(app).nextStartedService
        assertEquals(RecordingService.ACTION_TOGGLE, started.action)
        assertEquals("App shortcut", started.getStringExtra(RecordingService.EXTRA_FROM))
    }

    @Test
    fun `the app's shortcuts start with Record now, pointing at the trampoline`() {
        val xml = java.io.File("src/main/res/xml/shortcuts.xml").readText()
        val first = Regex("""android:shortcutId="([a-z_]+)"""").find(xml)?.groupValues?.get(1)
        assertEquals("record_now", first)
        assertTrue(xml.contains("com.echoledger.mobile.tile.TileTrampolineActivity"))
    }
}
