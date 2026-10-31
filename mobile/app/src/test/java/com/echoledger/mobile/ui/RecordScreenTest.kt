package com.echoledger.mobile.ui

import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
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
    private var reviewTaps = 0

    @Before
    fun freezeClock() {
        compose.mainClock.autoAdvance = false
    }

    private fun caps(sdk: Int = 34, helper: Boolean = true) =
        Capabilities(sdk, helper, micPermission = true, notificationPermission = true, overlayPermission = true)

    private fun showRecord(capabilities: Capabilities = caps(), mode: RecordMode = RecordMode.VOICE) {
        show { RecordScreen(vm, capabilities, onSetup = { setupTaps++ }, mode = mode, onReviewWaiting = { reviewTaps++ }) }
        awaitText(if (mode == RecordMode.VOICE) "Voice notes are never recorded automatically" else "Auto-record calls")
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
        assertFalse(RecordingService.isRecording)
    }

    @Test
    fun `a voice note says nothing about call sides, the client or auto-record`() {
        showRecord(caps(helper = true))

        assertFalse(present("Both sides", substring = true))
        assertFalse(present("Microphone only", substring = true))
        assertFalse(present("Auto-record calls"))
        assertEquals("Voice note", SourceApp.VOICE_NOTE.label)
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
        autoSwitch().assertIsNotEnabled()
    }

    @Test
    fun `auto record needs the capture helper and offers a shortcut to setup`() {
        showRecord(caps(sdk = 34, helper = false), RecordMode.CALLS)

        compose.onNodeWithText("Enable call capture in Setup first").assertIsDisplayed()
        autoSwitch().assertIsNotEnabled()
        compose.onNodeWithText("Open setup").click()
        assertEquals(1, setupTaps)
    }

    @Test
    fun `with the helper running auto record can be armed and disarmed`() {
        showRecord(caps(sdk = 34, helper = true), RecordMode.CALLS)
        compose.onNodeWithText("You'll be asked after each call whether to keep it").assertIsDisplayed()
        assertFalse(present("Open setup"))

        autoSwitch().assertIsEnabled()
        autoSwitch().click()

        assertEquals(RecordingService.ACTION_ARM, shadowOf(app).nextStartedService.action)
    }

    @Test
    fun `an armed recorder shows the switch on and turning it off disarms`() {
        runBlocking { app.graph.settings.update { it.copy(autoRecordArmed = true) } }
        showRecord(caps(sdk = 34, helper = true), RecordMode.CALLS)
        awaitUi("the armed switch") { runCatching { autoSwitch().assertIsOn() }.isSuccess }

        autoSwitch().click()

        assertEquals(RecordingService.ACTION_DISARM, shadowOf(app).nextStartedService.action)
    }

    // ---- recordings awaiting a decision ----------------------------------------------------------------------------------------

    private fun showWaiting() {
        show { WaitingScreen(vm, onBack = {}) }
        awaitText("waiting ·")
        compose.mainClock.advanceTimeByFrame()
    }

    private fun autoSwitch() = compose.onNode(hasContentDescription("Auto-record calls") and isToggleable())

    @Test
    fun `calls waiting are one summary card with Review, never a row per call`() {
        app.storeRecording(id = "call-1", state = "pending_decision", durationMs = 60_000)
        app.storeRecording(id = "call-2", state = "pending_decision", durationMs = 78_000, startedAt = "2026-10-23T18:30:00Z")
        showRecord(mode = RecordMode.CALLS)
        awaitText("2 calls waiting")

        compose.onNodeWithText("2 calls waiting · oldest Oct 23 · 2:18 total").assertExists()
        assertFalse(present("WhatsApp call · 1:00"))
        compose.onNodeWithText("Review").performScrollTo().click()
        assertEquals(1, reviewTaps)
    }

    @Test
    fun `the summary line counts the calls, the oldest day and the total length`() {
        val one = app.storeRecording(id = "a", state = "pending_decision", durationMs = 36_000, startedAt = "2026-10-30T06:39:00Z")
        val two = app.storeRecording(id = "b", state = "pending_decision", durationMs = 102_000, startedAt = "2026-10-29T20:00:00Z")
        val karachi = java.time.ZoneId.of("Asia/Karachi")
        assertEquals("1 call waiting · Oct 30 · 0:36 total", WaitingSummary.line(listOf(one), karachi))
        assertEquals("2 calls waiting · oldest Oct 30 · 2:18 total", WaitingSummary.line(listOf(one, two), karachi))
        assertEquals("", WaitingSummary.line(emptyList(), karachi))
    }

    @Test
    fun `finished calls ask whether to save them and keeping queues the upload`() {
        app.storeRecording(id = "call-1", state = "pending_decision", durationMs = 60_000)
        app.storeRecording(id = "call-2", state = "pending_decision", durationMs = 125_000, startedAt = "2026-10-23T18:30:00Z")
        showWaiting()

        compose.onNodeWithText("WhatsApp call · 1:00").assertIsDisplayed()
        compose.onNodeWithText("WhatsApp call · 2:05").assertExists()
        compose.onNodeWithText("Oct 23 · 6:30 PM").assertIsDisplayed()
        compose.onAllNodesWithText("Keep")[0].click()
        awaitUi("the first call to be queued") { app.peekRecording("call-1")?.state == "queued" }
        // The row turns "queued" just before the upload work is enqueued: wait for the work, not only the row.
        awaitUi("the upload work to be enqueued") { workStates("call-1").isNotEmpty() }

        assertEquals(listOf(WorkInfo.State.ENQUEUED), workStates("call-1"))
        assertEquals("pending_decision", app.graph.recordings.get("call-2")!!.state)
    }

    @Test
    fun `a waiting call can get a title and a client before Keep`() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        vm.refresh()
        pumpUntil("the clients to load") { vm.clients.value.isNotEmpty() }
        app.storeRecording(id = "call-1", state = "pending_decision", durationMs = 60_000)
        showWaiting()

        val client = vm.clients.value.first()
        compose.onNodeWithContentDescription("Client: none").assertExists()
        vm.setPendingClient("call-1", client.id)
        pumpUntil("the card to show the client") {
            compose.mainClock.advanceTimeByFrame()
            runCatching { compose.onNodeWithContentDescription("Client: ${client.name}").assertExists() }.isSuccess
        }

        // Keep at once after typing: the title goes with the decision, not after it.
        compose.onNodeWithContentDescription("Title").performScrollTo().performTextInput("Invoice follow-up")
        compose.onNodeWithText("Keep").performScrollTo().click()
        pumpUntil("the recording to be queued with its title") {
            app.graph.recordings.get("call-1")?.let { it.state == "queued" && it.title == "Invoice follow-up" } == true
        }
        assertEquals(client.id, app.graph.recordings.get("call-1")?.clientId)
    }

    @Test
    fun `discarding a finished call deletes it after the undo time`() {
        app.storeRecording(id = "call-1", state = "pending_decision")
        showWaiting()

        compose.onNodeWithText("Discard").click()
        awaitText("Discarding 1 call")
        assertNotNull("nothing is deleted while Undo is offered", app.peekRecording("call-1"))
        compose.mainClock.advanceTimeBy(5_100)
        awaitUi("the recording to be deleted") { app.peekRecording("call-1") == null }
        awaitText("Nothing waiting")

        assertFalse(app.graph.recordings.encryptedFile("call-1").exists())
        assertTrue(workStates("call-1").isEmpty())
    }

    @Test
    fun `Discard all can be undone and Keep all keeps every call`() {
        app.storeRecording(id = "call-1", state = "pending_decision")
        app.storeRecording(id = "call-2", state = "pending_decision", startedAt = "2026-10-23T18:30:00Z")
        showWaiting()

        compose.onNodeWithText("Discard all").click()
        awaitText("Discarding 2 calls")
        compose.onNodeWithText("Undo").click()
        compose.mainClock.advanceTimeBy(6_000)
        awaitText("2 calls waiting")
        assertNotNull(app.peekRecording("call-1"))
        assertNotNull(app.peekRecording("call-2"))

        compose.onNodeWithText("Keep all").click()
        awaitUi("both calls to be queued") {
            listOf("call-1", "call-2").all { app.peekRecording(it)?.state == "queued" }
        }
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

        assertFalse(present("Keep or discard?"))
        assertFalse(present("Uploading", substring = true))
        assertFalse(present("could not be uploaded", substring = true))
        compose.onNodeWithText("Voice notes are never recorded automatically — share them to EchoLedger from WhatsApp.").assertIsDisplayed()
    }
}
