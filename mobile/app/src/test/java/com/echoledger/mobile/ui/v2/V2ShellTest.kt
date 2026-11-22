package com.echoledger.mobile.ui.v2

import androidx.compose.material3.Text
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.test.isSelectable
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTouchInput
import androidx.work.WorkManager
import androidx.work.testing.TestListenableWorkerBuilder
import com.echoledger.mobile.calls.DecisionTimeoutWorker
import com.echoledger.mobile.calls.DiscardLaterWorker
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.home.DISCARD_MARGIN_MS
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

    /** The wall clock the shell's toasts and delayed discards use: the test's frame clock, so time is exact. */
    private val wallBase = 1_763_366_400_000L
    private val clock: () -> Long = { wallBase + compose.mainClock.currentTime }

    private fun shell(flags: UiFlags) = show {
        EchoV2Shell(
            vm,
            flags,
            darkTheme = false,
            classic = classic,
            onSwitchToClassic = { switched += it },
            language = EchoLanguage.English,
            clock = clock
        )
    }

    private val v2 = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true))

    @Test
    fun libraryIsTheV2OneAndOtherTabsStayClassic() {
        seed()
        shell(v2)
        compose.onNode(hasText("Library") and isHeading()).assertExists()
        compose.onNodeWithText("Bilal Traders", substring = true).assertExists()
        compose.onNodeWithText("Live").performClick()
        compose.onNodeWithText("Classic live").assertExists()
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
        server.on("POST", "/api/sessions/bulk", FakeServer.json(200, """{"changed": 1}"""))
        shell(v2)
        // A long press starts selection mode (docs/PLAN_V2_PARITY.md 3); Delete asks first.
        compose.onNodeWithText("Bilal Traders", substring = true).performTouchInput { longClick() }
        compose.onNodeWithText("1 selected").assertExists()
        compose.onNodeWithContentDescription("Delete").performClick()
        compose.onNodeWithText("Delete this recording?").assertExists()
        compose.onNodeWithText("Delete 1").performClick()
        // Classic's path (bulk "delete"); Undo shows once it went.
        compose.waitUntil(5_000) { server.requestsTo("POST", "/api/sessions/bulk").any { it.bodyText().contains("\"delete\"") } }
        compose.waitUntil(5_000) { compose.onAllNodesWithText("Recording deleted").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Undo").performClick()
        compose.waitUntil(5_000) { server.requestsTo("POST", "/api/sessions/01JA/restore").isNotEmpty() }
    }

    @Test
    fun settingsFromTheAvatarOffersClassicLook() {
        seed()
        shell(v2)
        compose.onNodeWithContentDescription("Account and settings").performClick()
        compose.onNodeWithText("Switch back to classic").performClick()
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
        if (worker == DecisionTimeoutWorker::class.java) {
            TestListenableWorkerBuilder<DecisionTimeoutWorker>(app).setInputData(input).build().doWork()
        } else {
            TestListenableWorkerBuilder<DiscardLaterWorker>(app).setInputData(input).build().doWork()
        }
    }

    private fun homeWithWaitingCalls(vararg ids: String) {
        seed()
        // Later ids are newer calls, so Activity lists them first.
        ids.forEachIndexed { i, id -> app.storeRecording(id = id, state = "pending_decision", startedAt = "2026-10-24T09:0$i:00Z") }
        vm.reloadLocal()
        shell(UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.home" to true, "ui_v2.library" to true)))
        // Home's Needs action is Activity now: the bell in Library's header.
        compose.onNodeWithContentDescription("Activity", substring = true).performClick()
        compose.waitUntil(5_000) { runCatching { compose.onAllNodesWithText("Delete")[ids.size - 1].fetchSemanticsNode() }.isSuccess }
        compose.mainClock.autoAdvance = false
    }

    private fun state(id: String = "r-omar") = app.graph.recordings.get(id)?.state

    /** Waits for the phone's own work (storage, WorkManager, the view model's queue) while the screen's clock stands still. */
    private fun settle(done: () -> Boolean) {
        pumpUntil("the phone's work") { done() }
        compose.mainClock.advanceTimeByFrame()
    }

    /** Steps frame by frame until [text] is drawn (a few frames, so a toast's own time hardly moves). */
    private fun untilShown(text: String) {
        val ok = runCatching {
            compose.waitUntil(5_000) {
                compose.mainClock.advanceTimeByFrame()
                runCatching { compose.onNodeWithText(text, useUnmergedTree = true).fetchSemanticsNode() }.isSuccess
            }
        }
        if (ok.isFailure) {
            val texts = compose.onAllNodes(androidx.compose.ui.test.hasText("", substring = true), useUnmergedTree = true)
                .fetchSemanticsNodes().mapNotNull {
                    it.config.getOrElseNullable(androidx.compose.ui.semantics.SemanticsProperties.Text) { null }?.joinToString()
                }
            throw AssertionError("\"$text\" not shown; on screen: $texts", ok.exceptionOrNull())
        }
    }

    private fun shown(text: String) = runCatching { compose.onNodeWithText(text, useUnmergedTree = true).fetchSemanticsNode() }.isSuccess

    /** The toast's end time: the call's stored discard time less the margin. */
    private fun deadline(id: String = "r-omar") =
        java.time.Instant.parse(app.graph.recordings.get(id)!!.discardAt).toEpochMilli() - DISCARD_MARGIN_MS

    private fun advanceTo(wallMs: Long) = compose.mainClock.advanceTimeBy(wallMs - clock())

    private fun discard(index: Int = 0) =
        compose.onAllNodesWithText("Delete", useUnmergedTree = true)[index].performScrollTo().performClick()

    @Test
    fun homeDiscardIsTheDelayedDiscardAndUndoBringsTheCallBack() {
        homeWithWaitingCalls("r-omar")
        discard()
        // Stored at once as discarding: it survives the process dying, and nothing is deleted yet.
        settle { state() == "discarding" }
        untilShown("Call discarded")
        compose.onNodeWithText("Undo").performClick()
        settle { state() == "pending_decision" && vm.pending.value.any { it.id == "r-omar" && it.state == "pending_decision" } }
        // When nobody undoes it, the discard worker ends it.
        untilShown("Delete")
        discard()
        settle { state() == "discarding" }
        runWorker(DiscardLaterWorker::class.java, "r-omar")
        assertEquals(null, state())
    }

    @Test
    fun theTenSecondKeepFiringDuringTheToastKeepsNothing() {
        homeWithWaitingCalls("r-omar")
        discard()
        settle { state() == "discarding" }
        // The keep timer was cancelled; even if it had already started, it finds the call discarding and leaves it.
        runWorker(DecisionTimeoutWorker::class.java, "r-omar")
        assertEquals("discarding", state())
        assertTrue(WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-r-omar").get().isEmpty())
    }

    @Test
    fun undoInTheToastsLastSecondStillBringsTheCallBack() {
        homeWithWaitingCalls("r-omar")
        discard()
        settle { state() == "discarding" }
        untilShown("Call discarded")
        advanceTo(deadline() - 300)
        compose.onNodeWithText("Undo").assertExists().performClick()
        settle { state() == "pending_decision" }
        // The discard worker, had it run on time, finds the call waiting again and leaves it.
        runWorker(DiscardLaterWorker::class.java, "r-omar")
        assertEquals("pending_decision", state())
    }

    @Test
    fun theToastIsGoneByItsEndTime() {
        homeWithWaitingCalls("r-omar")
        discard()
        settle { state() == "discarding" }
        untilShown("Call discarded")
        advanceTo(deadline() + 50)
        assertTrue(!shown("Call discarded"))
        assertEquals("discarding", state())
    }

    @Test
    fun aDiscardWhileAnotherToastShowsComesFirstAndEndsOnTime() {
        homeWithWaitingCalls("r-omar")
        vm.showMessage("Restored")
        untilShown("Restored")
        discard()
        settle { state() == "discarding" }
        untilShown("Call discarded")
        // The other toast made way; the discard toast is gone by its own end time, then the other one shows again.
        assertTrue(!shown("Restored"))
        advanceTo(deadline() + 50)
        assertTrue(!shown("Call discarded"))
        untilShown("Restored")
    }

    @Test
    fun twoDiscardsInARowShareOneToastThatEndsBeforeEitherCall() {
        homeWithWaitingCalls("r-a", "r-b")
        discard(1)
        settle { state("r-a") == "discarding" }
        untilShown("Call discarded")
        compose.mainClock.advanceTimeBy(1_000)
        discard(0)
        settle { state("r-b") == "discarding" }
        untilShown("2 calls discarded")
        // Its end time is the earlier call's.
        val end = minOf(deadline("r-a"), deadline("r-b"))
        assertEquals(deadline("r-a"), end)
        advanceTo(end - 300)
        compose.onNodeWithText("Undo").performClick()
        settle { state("r-a") == "pending_decision" && state("r-b") == "pending_decision" }
    }

    @Test
    fun twoDiscardsInARowAreGoneByTheirEndTime() {
        homeWithWaitingCalls("r-a", "r-b")
        discard(1)
        settle { state("r-a") == "discarding" }
        untilShown("Call discarded")
        compose.mainClock.advanceTimeBy(1_000)
        discard(0)
        settle { state("r-b") == "discarding" }
        untilShown("2 calls discarded")
        advanceTo(deadline("r-a") + 50)
        assertTrue(!shown("2 calls discarded"))
        assertTrue(!shown("Call discarded"))
    }

    @Test
    fun aLateUndoSaysTheCallWasAlreadyDeleted() {
        homeWithWaitingCalls("r-omar")
        discard()
        settle { state() == "discarding" }
        untilShown("Call discarded")
        // The worker got there first (the phone's clock jumped, say).
        runWorker(DiscardLaterWorker::class.java, "r-omar")
        compose.onNodeWithText("Undo").performClick()
        untilShown("That call was already deleted.")
        assertEquals(null, state())
    }

    @Test
    fun aDiscardRightAfterUndoDoesNotStopTheUndo() {
        homeWithWaitingCalls("r-a", "r-b")
        discard(1)
        settle { state("r-a") == "discarding" }
        untilShown("Call discarded")
        // r-a was already deleted by the time Undo is tapped; another Discard follows at once.
        runWorker(DiscardLaterWorker::class.java, "r-a")
        compose.onNodeWithText("Undo").performClick()
        compose.mainClock.advanceTimeByFrame()
        discard(0)
        settle { state("r-b") == "discarding" }
        untilShown("Call discarded")
        // The Undo of r-a still finished and its message comes once r-b's toast has gone.
        advanceTo(deadline("r-b") + 50)
        untilShown("That call was already deleted.")
    }

    @Test
    fun undoThenAnotherDiscardBringsTheFirstCallBack() {
        homeWithWaitingCalls("r-a", "r-b")
        discard(1)
        settle { state("r-a") == "discarding" }
        untilShown("Call discarded")
        compose.onNodeWithText("Undo").performClick()
        compose.mainClock.advanceTimeByFrame()
        // r-b is the only call left on Home until r-a is back.
        discard(0)
        settle { state("r-a") == "pending_decision" && state("r-b") == "discarding" }
        settle { vm.pending.value.any { it.id == "r-a" && it.state == "pending_decision" } }
    }

    @Test
    fun selectionModeActsOnEverySelectedRecording() {
        seed()
        server.on("POST", "/api/sessions/bulk", FakeServer.json(200, """{"changed": 2}"""))
        shell(v2)
        compose.onNodeWithText("Bilal Traders", substring = true).performTouchInput { longClick() }
        // A tap adds a row while selecting; Select all and Unselect all.
        compose.onNodeWithText("Rehman and Sons Textiles", substring = true).performClick()
        compose.onNodeWithText("2 selected").assertExists()
        // Select all and Unselect all are in ⋮.
        compose.onNodeWithContentDescription("More").performClick()
        compose.onNodeWithText("Unselect all").performClick()
        compose.onNodeWithText("2 selected").assertDoesNotExist()
        compose.onNodeWithText("Bilal Traders", substring = true).performTouchInput { longClick() }
        compose.onNodeWithContentDescription("More").performClick()
        compose.onNodeWithText("Select all").performClick()
        compose.onNodeWithText("2 selected").assertExists()
        // ⋮ › Pin goes to the server for both, then selection ends.
        compose.onNodeWithContentDescription("More").performClick()
        compose.onNodeWithText("Pin").performClick()
        compose.waitUntil(5_000) { server.requestsTo("POST", "/api/sessions/bulk").isNotEmpty() }
        val body = server.requestsTo("POST", "/api/sessions/bulk").single().bodyText()
        assertTrue(body, body.contains("\"pin\"") && body.contains("01JA") && body.contains("01JB"))
        compose.waitUntil(5_000) { compose.onAllNodesWithText("2 selected").fetchSemanticsNodes().isEmpty() }
        // Back leaves selection.
        compose.onNodeWithText("Bilal Traders", substring = true).performTouchInput { longClick() }
        androidx.test.espresso.Espresso.pressBack()
        compose.onNodeWithText("1 selected").assertDoesNotExist()
    }

    @Test
    fun theFilterChipsNarrowTheListAndClearAllBringsItBack() {
        seed()
        shell(v2)
        compose.onNodeWithText("Views ▾").performClick()
        compose.onNode(hasText("Untranscribed") and isToggleable()).performClick()
        compose.onNodeWithText("Apply").performClick()
        compose.onNodeWithText("Bilal Traders", substring = true).assertDoesNotExist()
        compose.onNodeWithText("Rehman and Sons Textiles", substring = true).assertExists()
        compose.onNodeWithText("Clear all").performClick()
        compose.onNodeWithText("Bilal Traders", substring = true).assertExists()
    }

    @Test
    fun sortAndGroupIsRememberedForTheView() {
        seed()
        shell(v2)
        compose.onNodeWithContentDescription("Sort and group").performClick()
        compose.onNode(hasText("Name A–Z") and isSelectable()).performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("sort saved") { vm.settings.value.libraryViews.values.any { it.sort == "title" } }
    }
}
