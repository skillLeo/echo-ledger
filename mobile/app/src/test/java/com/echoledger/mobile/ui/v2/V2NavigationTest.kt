package com.echoledger.mobile.ui.v2

import androidx.compose.material3.Text
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasScrollToIndexAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performScrollToIndex
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.live.LiveEntry
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.RecordHint
import com.echoledger.mobile.ui.v2.shell.RecordSheetActions
import com.echoledger.mobile.ui.v2.shell.RecordSheetState
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.shell.V2RecordSheetBody
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The v2 navigation (docs/PLAN_V2_PARITY.md section 2; parity.csv PH-OTH-001…005, PH-LIB-001, 006, 060, 061, 064, 065):
 * Library · Projects · [Record] · Live · Ask with Library first; Record's tap and long press; a tab tapped again
 * scrolls up; the bell with its badge opens Activity; the Calls chip; the setup card and the one Needs action banner.
 */
class V2NavigationTest : ScreenTest() {
    private val opened = mutableListOf<LiveEntry>()
    private val readyChecks = mutableListOf<Boolean>()
    private var recordNow = 0
    private var micAllowed = true

    /** Moves the shell's clock on (the Record button holds a moment after a tap that acted). */
    private var skew = 0L

    private val classic = ClassicScreens(
        home = { Text("Classic activity") },
        library = { Text("Classic library") },
        live = { Text("Classic live") },
        ask = { Text("Classic projects") },
        settings = { Text("Classic settings") },
        upgrade = {},
        liveEntry = { entry, _ ->
            opened += entry
            Text("Classic ${entry.name}")
        }
    )

    private val v2 = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "ui_v2.live" to true, "ui_v2.ask" to true))

    private fun seed(rows: Int = 2) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    (1..rows).map {
                        ServerFixtures.libraryItem("01K%03d".format(it), title = "Caller %03d".format(it))
                    }
                )
            )
        )
        server.on("GET", "/api/activity", FakeServer.json(200, """{"data": [], "unread_count": 0}"""))
        runBlocking { app.graph.library.refresh() }
    }

    private fun shell(flags: UiFlags = v2) = show { shellContent(flags) }

    @androidx.compose.runtime.Composable
    private fun shellContent(flags: UiFlags = v2) {
        EchoV2Shell(
            vm,
            flags,
            darkTheme = false,
            classic = classic,
            onSwitchToClassic = {},
            language = EchoLanguage.English,
            clock = { System.currentTimeMillis() + skew },
            onRecordNow = {
                recordNow++
                micAllowed
            },
            onReadyCheck = { readyChecks += it }
        )
    }

    @Test
    fun theBarIsLibraryProjectsRecordLiveAskAndLibraryComesFirst() {
        seed()
        shell()
        // The page says what it shows, All recordings first, and its title opens the menu of the Library's places.
        compose.onNode(hasText("All recordings") and isHeading()).assertExists()
        compose.onNode(
            hasText("All recordings") and
                androidx.compose.ui.test.SemanticsMatcher.expectValue(
                    androidx.compose.ui.semantics.SemanticsProperties.Role,
                    androidx.compose.ui.semantics.Role.DropdownList
                )
        ).assertExists()
        // The bar's tabs, and no other (the Library's chips under the search are choices of the page's own).
        val tabs = compose.onAllNodes(
            androidx.compose.ui.test.SemanticsMatcher.expectValue(
                androidx.compose.ui.semantics.SemanticsProperties.Role,
                androidx.compose.ui.semantics.Role.Tab
            )
        ).fetchSemanticsNodes().map { com.echoledger.mobile.ui.v2.ParityKit.name(it) }
        assertEquals(listOf("Library", "Projects", "Live", "Ask"), tabs)
        compose.onNodeWithContentDescription("Record").assertExists()
        // No Home tab.
        compose.onAllNodesWithText("Home").fetchSemanticsNodes().let { assertTrue(it.isEmpty()) }
        compose.onNodeWithText("Projects").performClick()
        compose.onNodeWithContentDescription("New project").assertExists()
        // Settings from the avatar on every main screen.
        compose.onNodeWithContentDescription("Account and settings").assertExists()
    }

    /** v2.1 FIX-03: the app's messages (an after-call choice's line too) sit just above the bottom bar, never on it. */
    @Test
    fun aMessageSitsJustAboveTheBottomBar() {
        seed()
        shell()
        com.echoledger.mobile.testsupport.pumpUntil("the shell takes the after-call lines") {
            com.echoledger.mobile.calls.CallLines.host !=
                null
        }
        compose.runOnIdle {
            com.echoledger.mobile.calls.CallLines.host!!.show(
                com.echoledger.mobile.calls.CallLines.Line("call-1", com.echoledger.mobile.calls.CallChoice.Pick.TRANSCRIBE),
                com.echoledger.mobile.calls.CallLines.LONG_MS,
                com.echoledger.mobile.calls.CallLines.Actions(action = {})
            )
        }
        val message = compose.onNodeWithText("Transcribing…").fetchSemanticsNode().boundsInRoot
        val barTop = compose.onAllNodes(
            androidx.compose.ui.test.SemanticsMatcher.expectValue(
                androidx.compose.ui.semantics.SemanticsProperties.Role,
                androidx.compose.ui.semantics.Role.Tab
            )
        ).fetchSemanticsNodes().minOf { it.boundsInRoot.top }
        assertTrue("above the bar: ${message.bottom} <= $barTop", message.bottom <= barTop)
        assertTrue("just above it: ${barTop - message.bottom}", barTop - message.bottom < 64f)
    }

    @Test
    fun recordTapRecordsAVoiceNoteNowAndWithoutTheMicrophoneOpensTheVoicePage() {
        seed()
        shell()
        compose.onNodeWithContentDescription("Record").performClick()
        assertEquals(1, recordNow)
        assertTrue(opened.isEmpty())
        // A moment later (a tap right after one that acted is ignored, so a double tap never starts and stops at once).
        skew += 1_000
        micAllowed = false
        compose.onNodeWithContentDescription("Record").performClick()
        assertEquals(2, recordNow)
        compose.onNodeWithText("Classic VoiceNote").assertExists()
    }

    /** v2.1 UX-15: a long press opens "New", which holds actions only, at most six; Record now is the big one. */
    @Test
    fun recordLongPressOpensTheNewSheetWithItsSixActionsOnly() {
        seed()
        shell(UiFlags(v2.flags + ("live_assist" to true)))
        compose.onNodeWithContentDescription("Record").performTouchInput { longClick() }
        compose.onNodeWithText("New").assertExists()
        val actions = listOf("Record now", "Voice note", "Import audio", "Start Live Assist", "Dictation", "Translate a game or video")
        actions.forEach { compose.onNodeWithText(it).assertExists() }
        // No switch and no statuses: they are on the Live tab and in Settings.
        assertTrue(compose.onAllNodes(isToggleable()).fetchSemanticsNodes().isEmpty())
        listOf("Calls & meetings", "Voice & files", "Game translate", "Watch translate").forEach {
            assertTrue(it, compose.onAllNodesWithText(it).fetchSemanticsNodes().isEmpty())
        }
        val big = compose.onNode(hasText("Record now") and hasClickAction()).fetchSemanticsNode().size.height
        assertTrue("Record now is the big one ($big px)", big >= with(compose.density) { 56.dp.roundToPx() })
        compose.onNodeWithText("Import audio").performClick()
        compose.onNodeWithText("Classic ImportAudio").assertExists()
    }

    @Test
    fun translateAGameOrVideoGoesToTheLiveTabWithBothAndTheirState() {
        seed()
        shell()
        compose.onNodeWithContentDescription("Record").performTouchInput { longClick() }
        compose.onNodeWithText("Translate a game or video").performClick()
        compose.waitForIdle()
        compose.onNodeWithText("Game translate").assertExists()
        compose.onNodeWithText("Watch translate").assertExists()
        compose.onNodeWithText("Game: off").assertExists()
    }

    @Test
    fun theLiveTabHasAutoRecordCallsWithHowCallsStand() {
        seed()
        // This test phone can record calls but its capture helper isn't running.
        shell()
        compose.onNode(hasText("Live") and !isHeading()).performClick()
        compose.waitForIdle()
        compose.onNode(hasText("Auto-record calls") and isToggleable()).assertIsNotEnabled()
        compose.onAllNodesWithText("Calls: set up first").fetchSemanticsNodes().let { assertTrue(it.isNotEmpty()) }
    }

    /** v2.1 UX-15: the switch that left the New sheet arms and disarms on the Live tab, saying how calls stand. */
    @Test
    fun theLiveTabsSwitchArmsAndDisarmsAutoRecordCalls() {
        val armed = mutableListOf<Boolean>()
        var on by androidx.compose.runtime.mutableStateOf(false)
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                com.echoledger.mobile.ui.v2.live.V2LiveTab(
                    liveAssist = false,
                    liveTranslate = false,
                    onOpen = {},
                    status = com.echoledger.mobile.ui.v2.live.LiveStatus(
                        armed = on,
                        canArm = true,
                        calls = if (on) "Calls: auto-record on" else "Calls: ready",
                        onArm = {
                            armed += it
                            on = it
                        }
                    )
                )
            }
        }
        compose.onNodeWithText("Calls: ready").assertExists()
        compose.onNode(hasText("Auto-record calls") and isToggleable()).performClick()
        compose.onAllNodesWithText("Calls: auto-record on").fetchSemanticsNodes().let { assertTrue(it.isNotEmpty()) }
        compose.onNode(hasText("Auto-record calls") and isToggleable()).performClick()
        assertEquals(listOf(true, false), armed)
    }

    @Test
    fun theNewSheetsRowsEachOpenTheirPage() {
        val went = mutableListOf<String>()
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                androidx.compose.foundation.layout.Column {
                    V2RecordSheetBody(
                        RecordSheetState(liveAssist = false, liveTranslate = false, translateLocked = "Pro"),
                        RecordSheetActions(
                            onRecordNow = { went += "record" },
                            onVoiceNote = { went += "voice" },
                            onImport = { went += "import" },
                            onLiveAssist = { went += "assist" },
                            onDictation = { went += "dictation" },
                            onTranslate = { went += "translate" },
                            onLocked = { went += "plans" }
                        ),
                        onDismiss = {}
                    )
                }
            }
        }
        // Live Assist only with its flag; Live Translate not in the plan shows the plan's badge and opens the plans.
        compose.onNodeWithText("Start Live Assist").assertDoesNotExist()
        compose.onNodeWithText("Pro").performClick()
        compose.onNodeWithText("Record now").performClick()
        compose.onNodeWithText("Voice note").performClick()
        compose.onNodeWithText("Import audio").performClick()
        compose.onNodeWithText("Dictation").performClick()
        assertEquals(listOf("plans", "record", "voice", "import", "dictation"), went)
    }

    /** The Record hint's nodes saying [words] (they are tagged: TalkBack never focuses the hint). */
    private fun hints(words: String) =
        compose.onAllNodes(SemanticsMatcher.expectValue(com.echoledger.mobile.ui.v2.shell.RecordHintText, words)).fetchSemanticsNodes()

    /** Review of UX-15: with TalkBack on, the hint says TalkBack's gestures (double-tap, double-tap and hold). */
    @Test
    fun withTalkBackOnTheRecordHintSaysDoubleTapAndHold() {
        seed()
        val a11y = app.getSystemService(android.view.accessibility.AccessibilityManager::class.java)
        org.robolectric.Shadows.shadowOf(a11y).setTouchExplorationEnabled(true)
        vm.updateSettings { it.copy(appOpens = 1) }
        pumpUntil("stored") { vm.settings.value.appOpens == 1 }
        shell()
        pumpUntil("the hint") {
            hints("Double-tap to record · Double-tap and hold for more").isNotEmpty()
        }
        assertTrue(hints("Tap to record · Hold for more").isEmpty())
    }

    /** v2.1 UX-15: from the second opening, "Tap to record · Hold for more", at most three times, never after a long press. */
    @Test
    fun theRecordHintShowsFromTheSecondOpeningAndGoesAfterTheFirstLongPress() {
        seed()
        assertTrue(!RecordHint.due(com.echoledger.mobile.data.AppSettings(appOpens = 1)))
        assertTrue(RecordHint.due(com.echoledger.mobile.data.AppSettings(appOpens = 2)))
        assertTrue(!RecordHint.due(com.echoledger.mobile.data.AppSettings(appOpens = 5, recordHintShows = 3)))
        assertTrue(!RecordHint.due(com.echoledger.mobile.data.AppSettings(appOpens = 5, recordHintDone = true)))
        vm.updateSettings { it.copy(appOpens = 1) }
        pumpUntil("stored") { vm.settings.value.appOpens == 1 }
        shell()
        pumpUntil("the second opening") { vm.settings.value.appOpens == 2 }
        pumpUntil("the hint") { hints("Tap to record · Hold for more").isNotEmpty() }
        // Never a place for TalkBack's focus (no words or tap of its own there), so a double tap always records.
        hints("Tap to record · Hold for more").forEach { node ->
            assertTrue(!SemanticsMatcher.keyIsDefined(androidx.compose.ui.semantics.SemanticsActions.OnClick).matches(node))
            assertTrue(!SemanticsMatcher.keyIsDefined(androidx.compose.ui.semantics.SemanticsProperties.Text).matches(node))
        }
        pumpUntil("counted") { vm.settings.value.recordHintShows == 1 }
        compose.onNodeWithContentDescription("Record").performTouchInput { longClick() }
        compose.waitForIdle()
        assertTrue(hints("Tap to record · Hold for more").isEmpty())
        pumpUntil("never again") { vm.settings.value.recordHintDone }
    }

    @Test
    fun theLibraryTabTappedAgainScrollsToTheTop() {
        seed(rows = 60)
        shell()
        compose.onNode(hasScrollToIndexAction()).performScrollToIndex(55)
        compose.onAllNodesWithText("Caller 060", substring = true).fetchSemanticsNodes().let { assertTrue(it.isNotEmpty()) }
        compose.onNode(hasText("Library") and !isHeading()).performClick()
        compose.waitUntil(5_000) {
            compose.onAllNodesWithText("Caller 060", substring = true).fetchSemanticsNodes().isEmpty()
        }
        compose.onNodeWithText("Caller 060", substring = true).assertDoesNotExist()
        compose.onAllNodesWithText("Caller 05", substring = true).fetchSemanticsNodes()
        compose.onNode(hasText("All recordings") and isHeading()).assertIsDisplayed()
    }

    /** PLAN_NAV_3_LEVELS §0: from any view, the Library tab tapped again is All recordings, with the filters cleared. */
    @Test
    fun theLibraryTabTappedAgainIsAllRecordingsWithTheFiltersCleared() {
        seed(rows = 3)
        server.on("POST", "/api/sessions/.+/opened", FakeServer.empty(204))
        shell()
        // Unread, then a filter on (Marked › Pinned: nothing has it).
        compose.onNode(hasText("All recordings") and isHeading()).performClick()
        compose.onNode(hasContentDescription("Unread", substring = true) and androidx.compose.ui.test.hasClickAction()).performClick()
        compose.onNode(hasText("Unread") and isHeading()).assertExists()
        compose.onNode(hasContentDescription("Filters") and androidx.compose.ui.test.hasClickAction()).performClick()
        compose.onNode(hasText("Pinned") and androidx.compose.ui.test.isToggleable()).performScrollTo().performClick()
        compose.onNode(hasText("Show 0 results")).performClick()
        compose.onNodeWithText("No recordings match these filters").assertExists()
        compose.onNode(hasText("Clear filters")).assertExists()
        // The tab tapped again: All recordings, nothing filtered.
        compose.onNode(hasText("Library") and !isHeading()).performClick()
        pumpUntil("all recordings again") {
            compose.onAllNodes(hasText("All recordings") and isHeading()).fetchSemanticsNodes().isNotEmpty() &&
                compose.onAllNodesWithText("Caller 00", substring = true).fetchSemanticsNodes().size == 3
        }
        compose.onAllNodes(hasContentDescription("Filters, 1 on")).assertCountEquals(0)
    }

    /** Opening a recording and coming back keeps where Library was: its view, filters, tab and search. */
    @Test
    fun aRecordingOpenedAndClosedKeepsWhereLibraryWas() {
        seed(rows = 3)
        server.on("POST", "/api/sessions/.+/opened", FakeServer.empty(204))
        server.on("GET", "/api/sessions/.+", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        var back: androidx.activity.OnBackPressedDispatcher? = null
        show {
            back = androidx.activity.compose.LocalOnBackPressedDispatcherOwner.current!!.onBackPressedDispatcher
            shellContent()
        }
        compose.onNode(hasContentDescription("Filters") and androidx.compose.ui.test.hasClickAction()).performClick()
        compose.onNode(hasText("WhatsApp") and isToggleable()).performScrollTo().performClick()
        compose.onNode(hasText("Show 3 results")).performClick()
        compose.onNode(hasContentDescription("Filters, 1 on")).assertExists()
        compose.onAllNodesWithText("Caller 001", substring = true).onFirst().performClick()
        pumpUntil("the recording") { compose.onAllNodes(hasContentDescription("Filters, 1 on")).fetchSemanticsNodes().isEmpty() }
        compose.runOnIdle { back!!.onBackPressed() }
        pumpUntil("back in Library, as it was") {
            compose.onAllNodes(hasContentDescription("Filters, 1 on")).fetchSemanticsNodes().isNotEmpty()
        }
        compose.onNodeWithText("Source: WhatsApp").assertExists()
    }

    @Test
    fun theBellCountsWhatNeedsThePersonAndOpensActivity() {
        seed()
        app.storeRecording(id = "r-omar", state = "pending_decision", startedAt = "2026-10-24T09:00:00Z")
        vm.reloadLocal()
        shell()
        pumpUntil("the badge") {
            compose.onAllNodes(hasContentDescription("Activity, 1 needs you")).fetchSemanticsNodes().isNotEmpty()
        }
        // One quiet banner. Calls are not recorded on this test phone, but the setup card's "Call recording ready" row
        // says so already: the banner says the next thing, the call waiting. No Calls chip since v2.1 (UX-14).
        compose.onNodeWithText("Call recording ready").assertExists()
        compose.onNodeWithText("Calls won't record", substring = true).assertDoesNotExist()
        compose.onNodeWithText("1 call waiting: Transcribe or Delete").assertExists()
        compose.onNodeWithText("Not ready · Fix").assertDoesNotExist()
        compose.onNodeWithContentDescription("Activity, 1 needs you").performClick()
        compose.onNodeWithText("Needs action").assertExists()
        compose.onNodeWithText("Transcribe").assertExists()
        compose.onNodeWithText("Delete").assertExists()
    }

    @Test
    fun theBannerPutsCallsNotRecordedFirstThenCallsWaitingThenUploads() {
        val waiting = com.echoledger.mobile.ui.v2.home.NeedsAction.WaitingDecision(
            HomeFixtures.waiting("r", "Omar", 60_000)
        )
        val uploads = com.echoledger.mobile.ui.v2.home.NeedsAction.FailedUploads(listOf("a", "b"))
        val top = com.echoledger.mobile.ui.v2.home.LibraryTop
        assertEquals(
            com.echoledger.mobile.ui.v2.home.NeedsBanner.Kind.CallsOff to 2,
            top.banner(com.echoledger.mobile.core.ReadyChip.NOT_READY, listOf(waiting, uploads))!!.let {
                it.kind to
                    it.more
            }
        )
        assertEquals(
            com.echoledger.mobile.ui.v2.home.NeedsBanner.Kind.Waiting to 1,
            top.banner(com.echoledger.mobile.core.ReadyChip.READY, listOf(waiting, uploads))!!.let {
                it.kind to
                    it.more
            }
        )
        assertEquals(
            com.echoledger.mobile.ui.v2.home.NeedsBanner.Kind.FailedUploads to 2,
            top.banner(com.echoledger.mobile.core.ReadyChip.READY, listOf(uploads))!!.let {
                it.kind to
                    it.count
            }
        )
        assertEquals(null, top.banner(com.echoledger.mobile.core.ReadyChip.READY, emptyList()))
    }

    @Test
    fun callsThatWontRecordAreOneLineInTheListWhoseFixOpensTheReadyCheck() {
        seed()
        runBlocking { app.graph.settings.update { it.copy(setupCardHidden = true) } }
        shell()
        // This test phone's helper isn't running: one line in the list (v2.1 UX-14), no chip in the header.
        pumpUntil("the line") { compose.onAllNodesWithText("Calls won't record", substring = true).fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Not ready · Fix").assertDoesNotExist()
        compose.onNode(hasText("Fix") and hasClickAction()).performClick()
        assertEquals(listOf(false), readyChecks)
    }

    @Test
    fun theSetupCardHidesAndStaysHidden() {
        seed()
        shell()
        compose.onNodeWithText("Finish setting up").assertExists()
        compose.onNodeWithText("Choose the transcription language").assertExists()
        compose.onNodeWithContentDescription("Hide").performClick()
        pumpUntil("hidden") { vm.settings.value.setupCardHidden }
        compose.onNodeWithText("Finish setting up").assertDoesNotExist()
    }
}
