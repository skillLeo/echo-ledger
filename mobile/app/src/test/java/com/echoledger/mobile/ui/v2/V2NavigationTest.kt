package com.echoledger.mobile.ui.v2

import androidx.compose.material3.Text
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasScrollToIndexAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollToIndex
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.live.LiveEntry
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
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

    private fun shell(flags: UiFlags = v2) = show {
        EchoV2Shell(
            vm,
            flags,
            darkTheme = false,
            classic = classic,
            onSwitchToClassic = {},
            language = EchoLanguage.English,
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
        compose.onNodeWithText("Recordings").assertExists()
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

    @Test
    fun recordTapRecordsAVoiceNoteNowAndWithoutTheMicrophoneOpensTheVoicePage() {
        seed()
        shell()
        compose.onNodeWithContentDescription("Record").performClick()
        assertEquals(1, recordNow)
        assertTrue(opened.isEmpty())
        micAllowed = false
        compose.onNodeWithContentDescription("Record").performClick()
        assertEquals(2, recordNow)
        compose.onNodeWithText("Classic VoiceNote").assertExists()
    }

    @Test
    fun recordLongPressOpensTheSheetWithCallsLiveAssistDictationAndLiveTranslate() {
        seed()
        shell(UiFlags(v2.flags + ("live_assist" to true)))
        compose.onNodeWithContentDescription("Record").performTouchInput { longClick() }
        compose.onNodeWithText("New").assertExists()
        compose.onNodeWithText("Record now").assertExists()
        compose.onNodeWithText("Voice & files").assertExists()
        compose.onNodeWithText("Calls & meetings").assertExists()
        compose.onNodeWithText("Live Assist").assertExists()
        compose.onNodeWithText("Dictation").assertExists()
        compose.onNodeWithText("Live Translate").assertExists()
        // Auto-record calls: the same arm and disarm as classic's switch.
        val calls = compose.onNode(hasText("Auto-record calls") and isToggleable())
        calls.assertExists()
        compose.onNodeWithText("Dictation").performClick()
        compose.onNodeWithText("Classic Dictation").assertExists()
    }

    @Test
    fun withoutTheCaptureHelperTheSheetSaysSetUpFirstAndCannotArm() {
        seed()
        // This test phone can record calls but its capture helper isn't running.
        shell()
        compose.onNodeWithContentDescription("Record").performTouchInput { longClick() }
        compose.onNode(hasText("Auto-record calls") and isToggleable()).assertIsNotEnabled()
        compose.onAllNodesWithText("Calls: set up first").fetchSemanticsNodes().let { assertTrue(it.isNotEmpty()) }
    }

    @Test
    fun theRecordSheetsSwitchArmsAndDisarmsAndEachRowOpensItsPage() {
        val armed = mutableListOf<Boolean>()
        val went = mutableListOf<String>()
        fun state(on: Boolean) = RecordSheetState(
            calls = com.echoledger.mobile.privilege.AppCallCapture.BOTH_SIDES,
            armed = on,
            canArm = true,
            liveAssist = false,
            liveTranslate = false,
            translateLocked = "Pro",
            game = false,
            watch = false
        )
        var on by androidx.compose.runtime.mutableStateOf(false)
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                androidx.compose.foundation.layout.Column {
                    V2RecordSheetBody(
                        state(on),
                        RecordSheetActions(
                            onRecordNow = { went += "record" },
                            onVoiceAndFiles = { went += "voice" },
                            onCalls = { went += "calls" },
                            onArm = {
                                armed += it
                                on = it
                            },
                            onLiveAssist = { went += "assist" },
                            onDictation = { went += "dictation" },
                            onLiveTranslate = { went += "translate" },
                            onLocked = { went += "plans" }
                        ),
                        onDismiss = {}
                    )
                }
            }
        }
        compose.onNodeWithText("Calls: ready").assertExists()
        compose.onNode(hasText("Auto-record calls") and isToggleable()).performClick()
        compose.onAllNodesWithText("Calls: auto-record on").fetchSemanticsNodes().let { assertTrue(it.isNotEmpty()) }
        compose.onNode(hasText("Auto-record calls") and isToggleable()).performClick()
        assertEquals(listOf(true, false), armed)
        // Live Assist only with its flag; Live Translate not in the plan shows the plan's badge and opens the plans.
        compose.onNodeWithText("Live Assist").assertDoesNotExist()
        compose.onNodeWithText("Pro").performClick()
        compose.onNodeWithText("Record now").performClick()
        compose.onNodeWithText("Voice & files").performClick()
        compose.onNodeWithText("Calls & meetings").performClick()
        compose.onNodeWithText("Dictation").performClick()
        assertEquals(listOf("plans", "record", "voice", "calls", "dictation"), went)
    }

    @Test
    fun theLibraryTabTappedAgainScrollsToTheTop() {
        seed(rows = 60)
        shell()
        compose.onNode(hasScrollToIndexAction()).performScrollToIndex(55)
        compose.onAllNodesWithText("Caller 060", substring = true).fetchSemanticsNodes().let { assertTrue(it.isNotEmpty()) }
        compose.onNodeWithText("Library").performClick()
        compose.waitUntil(5_000) {
            compose.onAllNodesWithText("Caller 060", substring = true).fetchSemanticsNodes().isEmpty()
        }
        compose.onNodeWithText("Caller 060", substring = true).assertDoesNotExist()
        compose.onAllNodesWithText("Caller 05", substring = true).fetchSemanticsNodes()
        compose.onNodeWithText("Recordings").assertIsDisplayed()
    }

    @Test
    fun theBellCountsWhatNeedsThePersonAndOpensActivity() {
        seed()
        app.storeRecording(id = "r-omar", state = "pending_decision", startedAt = "2026-10-24T09:00:00Z")
        vm.reloadLocal()
        shell()
        pumpUntil("the badge") {
            compose.onAllNodes(hasContentDescription("Activity, 1 need you")).fetchSemanticsNodes().isNotEmpty()
        }
        // One quiet banner: the most urgent thing (calls not recorded on this test phone) and how many more.
        compose.onNodeWithText("Calls are not being recorded · 1 more").assertExists()
        compose.onNodeWithText("Fix").performClick()
        assertEquals(listOf(false), readyChecks)
        compose.onNodeWithContentDescription("Activity, 1 need you").performClick()
        compose.onNodeWithText("Needs action").assertExists()
        compose.onNodeWithText("Keep").assertExists()
        compose.onNodeWithText("Discard").assertExists()
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
    fun theCallsChipSaysHowCallsStandAndOpensTheReadyCheck() {
        seed()
        shell()
        // This test phone's helper isn't running: "Not ready · Fix", as classic's chip.
        compose.onNodeWithText("Not ready · Fix").performClick()
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
