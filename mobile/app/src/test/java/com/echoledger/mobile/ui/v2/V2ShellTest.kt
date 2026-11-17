package com.echoledger.mobile.ui.v2

import androidx.compose.material3.Text
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTouchInput
import androidx.work.WorkManager
import androidx.work.testing.TestListenableWorkerBuilder
import com.echoledger.mobile.calls.DecisionTimeoutWorker
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.calls.DiscardLaterWorker
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** The v2 shell on the real view model and a scripted server: the flags, Library, delete with Undo, Settings. */
class V2ShellTest : ScreenTest() {
    private val switched = mutableListOf<Boolean>()

    private val classic = ClassicScreens(
        home = { Text("Classic home") },
        library = { Text("Classic library") },
        live = { Text("Classic live") },
        ask = { Text("Classic ask") },
        settings = { Text("Classic settings") },
        upgrade = {}
    )

    private fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        ServerFixtures.libraryItem("01JA", title = "Bilal Traders", preview = "New rate for 40 cartons of basmati"),
                        ServerFixtures.libraryItem("01JB", title = "Rehman and Sons Textiles", sourceApp = "phone_call", finalStatus = null)
                    )
                )
            )
        )
        server.on("DELETE", "/api/sessions/01JA", FakeServer.empty(204))
        server.on("POST", "/api/sessions/01JA/restore", FakeServer.empty(204))
        runBlocking { app.graph.library.refresh() }
    }

    private fun shell(flags: UiFlags) = show {
        EchoV2Shell(vm, flags, darkTheme = false, classic = classic, onSwitchToClassic = {
            switched += it
        }, language = EchoLanguage.English)
    }

    private val v2 = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true))

    @Test
    fun libraryIsTheV2OneAndOtherTabsStayClassic() {
        seed()
        shell(v2)
        compose.onNodeWithText("Recordings").assertExists()
        compose.onNodeWithText("Bilal Traders", substring = true).assertExists()
        compose.onNodeWithText("Home").performClick()
        compose.onNodeWithText("Classic home").assertExists()
    }

    @Test
    fun withoutTheLibraryFlagLibraryStaysClassic() {
        seed()
        shell(UiFlags(mapOf("ui_v2.shell" to true)))
        compose.onNodeWithText("Classic library").assertExists()
    }

    @Test
    fun deleteMovesToTrashAndUndoRestores() {
        seed()
        shell(v2)
        compose.onNodeWithText("Bilal Traders", substring = true).performTouchInput { longClick() }
        compose.onNodeWithText("Delete").performClick()
        compose.waitUntil(5_000) { server.requestsTo("DELETE", "/api/sessions/01JA").isNotEmpty() }
        compose.onNodeWithText("Moved to Trash").assertExists()
        compose.onNodeWithText("Undo").performClick()
        compose.waitUntil(5_000) { server.requestsTo("POST", "/api/sessions/01JA/restore").isNotEmpty() }
    }

    @Test
    fun settingsFromTheAvatarOffersClassicLook() {
        seed()
        shell(v2)
        compose.onNodeWithContentDescription("Account and settings").performClick()
        compose.onNodeWithText("Classic look").performClick()
        assertEquals(listOf(true), switched)
        compose.onNodeWithText("All settings").performClick()
        compose.onNodeWithText("Classic settings").assertExists()
    }

    @Test
    fun theViewModelsMessagesBecomeToasts() {
        seed()
        shell(v2)
        vm.showMessage("Restored")
        compose.waitUntil(5_000) { runCatching { compose.onNodeWithText("Restored").fetchSemanticsNode() }.isSuccess }
    }

    private fun runWorker(worker: Class<out androidx.work.ListenableWorker>, id: String) = runBlocking {
        val input = androidx.work.workDataOf("recording_id" to id)
        when (worker) {
            DecisionTimeoutWorker::class.java -> TestListenableWorkerBuilder<DecisionTimeoutWorker>(
                app
            ).setInputData(input).build().doWork()

            else -> TestListenableWorkerBuilder<DiscardLaterWorker>(app).setInputData(input).build().doWork()
        }
    }

    private fun homeWithWaitingCall() {
        seed()
        app.storeRecording(id = "r-omar", state = "pending_decision")
        vm.reloadLocal()
        compose.mainClock.autoAdvance = true
        shell(UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.home" to true, "ui_v2.library" to true)))
        compose.onNodeWithText("Home").performClick()
        compose.waitUntil(5_000) { runCatching { compose.onNodeWithText("Discard").fetchSemanticsNode() }.isSuccess }
    }

    private fun state() = app.graph.recordings.get("r-omar")?.state

    /** Waits for the phone's own work (storage, WorkManager) while the screen's clock stands still. */
    private fun pumpFramesUntil(done: () -> Boolean) {
        compose.mainClock.autoAdvance = false
        compose.waitUntil(5_000) { done() }
        compose.mainClock.advanceTimeByFrame()
    }

    /** Steps frame by frame until [text] is drawn (a few frames, so a toast's own time hardly moves). */
    private fun untilShown(text: String) {
        compose.waitUntil(5_000) {
            compose.mainClock.advanceTimeByFrame()
            runCatching { compose.onNodeWithText(text, useUnmergedTree = true).fetchSemanticsNode() }.isSuccess
        }
    }

    private fun untilToastShows() = untilShown("Call discarded")

    @Test
    fun homeDiscardIsTheDelayedDiscardAndUndoBringsTheCallBack() {
        homeWithWaitingCall()
        compose.mainClock.autoAdvance = false
        compose.onNodeWithText("Discard").performScrollTo().performClick()
        // Stored at once as discarding: it survives the process dying, and nothing is deleted yet.
        pumpFramesUntil { state() == "discarding" }
        untilToastShows()
        compose.onNodeWithText("Undo").performClick()
        pumpFramesUntil { state() == "pending_decision" }
        // The call is back on Home once the toast has gone. When nobody undoes it, the discard worker ends it.
        compose.mainClock.autoAdvance = true
        pumpUntil("the call back in the queue") { vm.pending.value.any { it.id == "r-omar" && it.state == "pending_decision" } }
        compose.waitForIdle()
        compose.onNodeWithText("Discard").performScrollTo().performClick()
        compose.waitUntil(5_000) { state() == "discarding" }
        runWorker(DiscardLaterWorker::class.java, "r-omar")
        assertEquals(null, state())
    }

    @Test
    fun theTenSecondKeepFiringDuringTheToastKeepsNothing() {
        homeWithWaitingCall()
        compose.onNodeWithText("Discard").performScrollTo().performClick()
        compose.waitUntil(5_000) { state() == "discarding" }
        // The keep timer was cancelled; even if it had already started, it finds the call discarding and leaves it.
        runWorker(DecisionTimeoutWorker::class.java, "r-omar")
        assertEquals("discarding", state())
        assertTrue(WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-r-omar").get().isEmpty())
    }

    @Test
    fun undoInTheToastsLastSecondStillBringsTheCallBack() {
        homeWithWaitingCall()
        compose.mainClock.autoAdvance = false
        compose.onNodeWithText("Discard").performScrollTo().performClick()
        pumpFramesUntil { state() == "discarding" }
        untilToastShows()
        // The toast lasts until 2 s before the file's discard time; tap Undo 300 ms before it ends.
        compose.mainClock.advanceTimeBy(Decisions.DISCARD_AFTER_MS - DISCARD_MARGIN - 300)
        compose.onNodeWithText("Undo").assertExists().performClick()
        pumpFramesUntil { state() == "pending_decision" }
        // The discard worker, had it run on time, finds the call waiting again and leaves it.
        runWorker(DiscardLaterWorker::class.java, "r-omar")
        assertEquals("pending_decision", state())
    }

    @Test
    fun theToastEndsWithTheDiscardTimerNotAfterIt() {
        homeWithWaitingCall()
        compose.mainClock.autoAdvance = false
        compose.onNodeWithText("Discard").performScrollTo().performClick()
        pumpFramesUntil { state() == "discarding" }
        untilToastShows()
        compose.mainClock.advanceTimeBy(Decisions.DISCARD_AFTER_MS - DISCARD_MARGIN + 100)
        compose.onNodeWithText("Call discarded", useUnmergedTree = true).assertDoesNotExist()
        assertEquals("discarding", state())
    }

    private companion object {
        const val DISCARD_MARGIN = 2_000L
    }
}
