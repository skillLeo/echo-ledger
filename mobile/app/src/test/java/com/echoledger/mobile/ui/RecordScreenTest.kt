package com.echoledger.mobile.ui

import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * The Record tab. The screen polls the recorder once a second forever, so the test clock is advanced by hand
 * instead of letting it run ahead of the background work.
 */
class RecordScreenTest : ScreenTest() {
    private var setupTaps = 0

    @Before
    fun freezeClock() {
        compose.mainClock.autoAdvance = false
    }

    private fun caps(sdk: Int = 34, helper: Boolean = true) =
        Capabilities(sdk, helper, micPermission = true, notificationPermission = true, overlayPermission = true)

    private fun showRecord(capabilities: Capabilities = caps(), mode: RecordMode = RecordMode.VOICE) {
        show { RecordScreen(vm, capabilities, onSetup = { setupTaps++ }, mode = mode) }
        awaitText(if (mode == RecordMode.VOICE) "Voice notes are never recorded automatically" else "Auto-record WhatsApp calls")
    }

    private fun awaitUi(what: String, condition: () -> Boolean) = pumpUntil(what) {
        compose.mainClock.advanceTimeByFrame()
        condition()
    }

    private fun present(text: String, substring: Boolean = false) =
        compose.onAllNodesWithText(text, substring = substring).fetchSemanticsNodes().isNotEmpty()

    private fun awaitText(text: String) = awaitUi("the text \"$text\"") { present(text, substring = true) }

    private fun awaitGone(text: String) = awaitUi("\"$text\" to disappear") { !present(text, substring = true) }

    private fun SemanticsNodeInteraction.click() {
        performClick()
        compose.mainClock.advanceTimeByFrame()
    }

    private fun workStates(id: String): List<WorkInfo.State> =
        WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$id").get(10, TimeUnit.SECONDS).map { it.state }

    // ---- recorder ---------------------------------------------------------------------------------------------------------

    @Test
    fun `an idle recorder offers Record and says it is not recording`() {
        showRecord(caps(helper = false))

        compose.onNodeWithText("Record").assertIsDisplayed()
        compose.onNodeWithText("Record").assertIsDisplayed()
        compose.onNodeWithText("Not recording").assertIsDisplayed()
        compose.onNodeWithText("Microphone only — enable call capture in Setup").assertIsDisplayed()
        assertFalse(RecordingService.isRecording)
    }

    @Test
    fun `with call capture available the recorder promises both sides of a call`() {
        showRecord(caps(helper = true))

        compose.onNodeWithText("Both sides of calls (Me + Client)").assertIsDisplayed()
    }

    @Test
    fun `tapping REC asks the recording service to start or stop`() {
        showRecord()

        compose.onNodeWithText("Record").click()

        val started = shadowOf(app).nextStartedService
        assertNotNull("the recording service should be started", started)
        assertEquals(RecordingService.ACTION_TOGGLE, started.action)
        assertEquals(RecordingService::class.java.name, started.component!!.className)
    }

    // ---- auto-record ------------------------------------------------------------------------------------------------------

    @Test
    fun `auto record is unavailable before Android 14`() {
        showRecord(caps(sdk = 33, helper = false), RecordMode.CALLS)

        compose.onNodeWithText("Requires Android 14 or later").assertIsDisplayed()
        compose.onNode(isToggleable()).assertIsNotEnabled()
    }

    @Test
    fun `auto record needs the capture helper and offers a shortcut to setup`() {
        showRecord(caps(sdk = 34, helper = false), RecordMode.CALLS)

        compose.onNodeWithText("Enable call capture in Setup first").assertIsDisplayed()
        compose.onNode(isToggleable()).assertIsNotEnabled()
        compose.onNodeWithText("Open setup").click()
        assertEquals(1, setupTaps)
    }

    @Test
    fun `with the helper running auto record can be armed and disarmed`() {
        showRecord(caps(sdk = 34, helper = true), RecordMode.CALLS)
        compose.onNodeWithText("You'll be asked after each call whether to keep it").assertIsDisplayed()
        assertFalse(present("Open setup"))

        compose.onNode(isToggleable()).assertIsEnabled()
        compose.onNode(isToggleable()).click()

        assertEquals(RecordingService.ACTION_ARM, shadowOf(app).nextStartedService.action)
    }

    @Test
    fun `an armed recorder shows the switch on and turning it off disarms`() {
        runBlocking { app.graph.settings.update { it.copy(autoRecordArmed = true) } }
        showRecord(caps(sdk = 34, helper = true), RecordMode.CALLS)
        awaitUi("the armed switch") { runCatching { compose.onNode(isToggleable()).assertIsOn() }.isSuccess }

        compose.onNode(isToggleable()).click()

        assertEquals(RecordingService.ACTION_DISARM, shadowOf(app).nextStartedService.action)
    }

    // ---- recordings awaiting a decision ----------------------------------------------------------------------------------------

    @Test
    fun `finished calls ask whether to save them and keeping queues the upload`() {
        app.storeRecording(id = "call-1", state = "pending_decision", durationMs = 60_000)
        app.storeRecording(id = "call-2", state = "pending_decision", durationMs = 125_000, startedAt = "2026-10-23T18:30:00Z")
        showRecord()
        awaitText("Save these recordings?")

        compose.onNodeWithText("WhatsApp call · 1:00").assertIsDisplayed()
        compose.onNodeWithText("WhatsApp call · 2:05").assertIsDisplayed()
        compose.onNodeWithText("Oct 23 · 6:30 PM").assertIsDisplayed()
        compose.onAllNodesWithText("Keep")[0].click()
        awaitUi("the first call to be queued") { app.peekRecording("call-1")?.state == "queued" }
        // The row turns "queued" just before the upload work is enqueued: wait for the work, not only the row.
        awaitUi("the upload work to be enqueued") { workStates("call-1").isNotEmpty() }

        assertEquals(listOf(WorkInfo.State.ENQUEUED), workStates("call-1"))
        assertEquals("pending_decision", app.graph.recordings.get("call-2")!!.state)
    }

    @Test
    fun `discarding a finished call deletes it`() {
        app.storeRecording(id = "call-1", state = "pending_decision")
        showRecord()
        awaitText("Save these recordings?")

        compose.onNodeWithText("Discard").click()
        awaitUi("the recording to be deleted") { app.peekRecording("call-1") == null }
        awaitGone("Save these recordings?")

        assertFalse(app.graph.recordings.encryptedFile("call-1").exists())
        assertTrue(workStates("call-1").isEmpty())
    }

    // ---- uploads in progress -------------------------------------------------------------------------------------------------

    @Test
    fun `queued and uploading recordings are counted with the last error and can be retried now`() {
        app.storeRecording(id = "q1", state = "queued", lastError = "Server Error")
        app.storeRecording(id = "q2", state = "uploading")
        app.storeRecording(id = "done", state = "uploaded")
        showRecord()
        awaitText("Uploading 2 recording(s)")

        compose.onNodeWithText("Will retry: Server Error", substring = true).assertIsDisplayed()
        compose.onNodeWithText("Retry now").click()
        awaitUi("the retry") { workStates("q1").isNotEmpty() }

        assertEquals(listOf(WorkInfo.State.ENQUEUED), workStates("q1"))
        assertTrue("an upload that is running right now is not restarted", workStates("q2").isEmpty())
        assertTrue(workStates("done").isEmpty())
    }

    @Test
    fun `no retry hint is shown when the queued recordings have no error`() {
        app.storeRecording(id = "q1", state = "queued")
        showRecord()
        awaitText("Uploading 1 recording(s)")

        assertFalse(present("Will retry", substring = true))
    }

    // ---- failed uploads ------------------------------------------------------------------------------------------------------------

    @Test
    fun `a refused recording shows its reason with Retry and Discard`() {
        app.storeRecording(
            id = "bad",
            state = "failed",
            lastError = "The artifacts.0.sha256 field format is invalid.",
            sourceApp = "whatsapp_business",
            startedAt = "2026-10-24T14:05:00Z"
        )
        showRecord()
        awaitText("could not be uploaded")

        compose.onNodeWithText("1 recording(s) could not be uploaded").assertIsDisplayed()
        compose.onNodeWithText("WhatsApp Business · Oct 24 · 2:05 PM").assertIsDisplayed()
        compose.onNodeWithText("The artifacts.0.sha256 field format is invalid.").assertIsDisplayed()
        compose.onNodeWithText("Retry").assertIsDisplayed()
        compose.onNodeWithText("Discard").assertIsDisplayed()
    }

    @Test
    fun `Retry on a failed recording requeues it and moves it to the uploading list`() {
        app.storeRecording(id = "bad", state = "failed", lastError = "Chunk checksum mismatch.")
        showRecord()
        awaitText("could not be uploaded")

        compose.onNodeWithText("Retry").click()
        awaitText("Uploading 1 recording(s)")
        awaitGone("could not be uploaded")

        val rec = app.graph.recordings.get("bad")!!
        assertEquals("queued", rec.state)
        assertNull(rec.lastError)
        assertEquals(listOf(WorkInfo.State.ENQUEUED), workStates("bad"))
    }

    @Test
    fun `Discard on a failed recording removes it for good`() {
        app.storeRecording(id = "bad", state = "failed", lastError = "Chunk checksum mismatch.")
        app.storeRecording(id = "also-bad", state = "failed", lastError = "Payload too large.", startedAt = "2026-10-23T09:00:00Z")
        showRecord()
        awaitText("2 recording(s) could not be uploaded")

        compose.onAllNodesWithText("Discard")[0].click()
        awaitText("1 recording(s) could not be uploaded")

        assertEquals(1, app.graph.recordings.all().size)
        assertTrue(app.graph.recordings.all().none { it.id == "bad" })
        assertTrue(workStates("bad").isEmpty())
    }

    @Test
    fun `with nothing waiting only the recorder and the voice note hint are shown`() {
        showRecord()

        assertFalse(present("Save these recordings?"))
        assertFalse(present("Uploading", substring = true))
        assertFalse(present("could not be uploaded", substring = true))
        compose.onNodeWithText("Voice notes are never recorded automatically — share them to EchoLedger from WhatsApp.").assertIsDisplayed()
    }
}
