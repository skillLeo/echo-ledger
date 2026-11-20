package com.echoledger.mobile.ui.v2

import androidx.compose.foundation.layout.Column
import androidx.compose.material3.Text
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.text.TextLayoutResult
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.live.LiveEntry
import com.echoledger.mobile.ui.v2.shell.CallsChip
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoRecordButton
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.RecordSheetActions
import com.echoledger.mobile.ui.v2.shell.RecordSheetState
import com.echoledger.mobile.ui.v2.shell.RecordTapGuard
import com.echoledger.mobile.ui.v2.shell.ShellStats
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.shell.V2RecordSheetBody
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Colors
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.theme.LocalEchoV2Colors
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * EL-Lead's review of the phone's v2 shell (U1–U4): Record stops while recording (with a double-tap guard), the
 * recording strip, Retry for failed uploads, Watch translate, Text size and App language that keep the page and reach
 * sheets and dialogs, no redraw of Library each second of a recording, and the minors.
 */
@org.robolectric.annotation.GraphicsMode(org.robolectric.annotation.GraphicsMode.Mode.NATIVE)
class ShellReviewFixesTest : ScreenTest() {
    private val opened = mutableListOf<LiveEntry>()
    private var recordNow = 0
    private var micAllowed = true

    private val classic = ClassicScreens(
        home = { Text("Classic activity") },
        library = { Text("Classic library") },
        live = { Text("Classic live") },
        ask = { Text("Classic projects") },
        settings = { Text("Classic settings") },
        settingsPage = { page, _ -> Text("Classic page $page") },
        upgrade = {},
        liveEntry = { entry, _ ->
            opened += entry
            Text("Classic ${entry.name}")
        }
    )

    private val v2 = UiFlags(
        mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "ui_v2.live" to true, "ui_v2.ask" to true, "ui_v2.settings" to true)
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
                    (1..2).map {
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
            }
        )
    }

    private fun rows() = pumpUntil("the library") {
        compose.onAllNodesWithText("Caller 001", substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    private fun live(active: Boolean, elapsedMs: Long = 0) {
        RecordingService.live.value = LiveRecording(active = active, elapsedMs = elapsedMs)
        compose.waitForIdle()
    }

    @After
    fun noRecording() {
        RecordingService.live.value = LiveRecording()
    }

    /** The centre button (it has a long press; the strip's Stop has not). */
    private fun centre(name: String) =
        compose.onNode(hasContentDescription(name) and SemanticsMatcher.keyIsDefined(SemanticsActions.OnLongClick))

    private fun stripStop() =
        compose.onNode(hasContentDescription("Stop recording") and !SemanticsMatcher.keyIsDefined(SemanticsActions.OnLongClick))

    // Blocker: Record while recording stops, as classic; a double tap acts once.

    @Test
    fun recordWhileRecordingStopsAndADoubleTapStartsOnlyOnce() {
        seed()
        shell()
        rows()
        // A double tap: one start, never a start and a stop.
        centre("Record").performClick()
        centre("Record").performClick()
        assertEquals(1, recordNow)
        // The recording runs: the button says Stop and a tap stops it through the same toggle, opening nothing.
        live(true, 42_000)
        centre("Stop recording").performClick()
        assertEquals(2, recordNow)
        assertTrue(opened.isEmpty())
        // While the stop is on its way a second tap does nothing.
        centre("Stop recording").performClick()
        assertEquals(2, recordNow)
    }

    @Test
    fun theGuardLetsTheNextTapActOnceTheRecordingChangedOrASecondWent() {
        var now = 0L
        val guard = RecordTapGuard({ now })
        assertTrue(guard.tap())
        assertFalse(guard.tap())
        now = 999
        assertFalse(guard.tap())
        now = 1_000
        assertTrue(guard.tap())
        guard.settled()
        assertTrue(guard.tap())
    }

    // Major: the recording strip above the bar.

    @Test
    fun whileRecordingTheStripShowsTheTimeStopsAndOpensTheVoicePage() {
        seed()
        shell()
        rows()
        stripStop().assertDoesNotExist()
        live(true, 42_000)
        compose.onNodeWithText("Recording").assertExists()
        compose.onNodeWithText("0:42", substring = true).assertExists()
        live(true, 43_000)
        compose.onNodeWithText("0:43", substring = true).assertExists()
        stripStop().performClick()
        assertEquals(1, recordNow)
        compose.onNodeWithText("Recording").performClick()
        compose.onNodeWithText("Classic VoiceNote").assertExists()
        live(false)
        stripStop().assertDoesNotExist()
    }

    // Major: the recording's clock does not redraw Library.

    @Test
    fun aRecordingsTickingClockDoesNotRedrawLibrary() {
        seed()
        shell()
        rows()
        live(true, 1_000)
        val before = ShellStats.libraryCompositions
        for (s in 2..6) live(true, s * 1_000L)
        assertEquals(before, ShellStats.libraryCompositions)
    }

    // Major: Retry sends each failed upload again (classic's retryUpload), in Library's banner and in Activity.

    @Test
    fun retryInLibrarysBannerSendsTheFailedUploadAgain() {
        seed()
        app.storeRecording(id = "r-failed", state = "failed", lastError = "Server error")
        vm.reloadLocal()
        // The setup card says calls aren't recorded, so the banner is the failed upload.
        shell()
        rows()
        pumpUntil("the banner") {
            compose.onAllNodesWithText("1 recording didn't upload", substring = true).fetchSemanticsNodes().isNotEmpty()
        }
        compose.onNode(hasText("Retry") and hasClickAction()).performClick()
        pumpUntil("sent again") { app.graph.recordings.all().none { it.id == "r-failed" && it.state == "failed" } }
    }

    @Test
    fun retryInActivitySendsTheFailedUploadAgain() {
        seed()
        app.storeRecording(id = "r-failed", state = "failed", lastError = "Server error")
        vm.reloadLocal()
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                CompositionLocalProvider(
                    com.echoledger.mobile.ui.v2.shell.LocalEchoToasts provides
                        com.echoledger.mobile.ui.v2.components.rememberEchoToastState()
                ) {
                    com.echoledger.mobile.ui.v2.activity.V2ActivityScreen(
                        vm,
                        com.echoledger.mobile.ui.v2.activity.ActivityActions(onOpenRecording = {}, onFix = {}, onOpenUntranscribed = {})
                    )
                }
            }
        }
        pumpUntil("the row") { compose.onAllNodesWithText("1 recording didn't upload").fetchSemanticsNodes().isNotEmpty() }
        compose.onNode(hasText("Retry") and hasClickAction()).performClick()
        pumpUntil("sent again") { app.graph.recordings.all().none { it.id == "r-failed" && it.state == "failed" } }
    }

    // Major: Watch translate is reachable, with classic's "blocked" line.

    @Test
    fun theRecordSheetHasGameAndWatchTranslateWithWhyTheyWereRefused() {
        val went = mutableListOf<String>()
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                Column {
                    V2RecordSheetBody(
                        RecordSheetState(
                            com.echoledger.mobile.privilege.AppCallCapture.BOTH_SIDES,
                            armed = false,
                            canArm = true,
                            liveAssist = false,
                            liveTranslate = true,
                            translateLocked = null,
                            game = true,
                            watch = false,
                            blocked = "Needs Soniox credit"
                        ),
                        RecordSheetActions(
                            {}, {}, {}, {}, {}, {},
                            onLiveTranslate = { went += "game" },
                            onLocked = { went += "plans" },
                            onWatchTranslate = { went += "watch" }
                        ),
                        onDismiss = {}
                    )
                }
            }
        }
        compose.onNodeWithText("Game: on").assertExists()
        compose.onNodeWithText("Needs Soniox credit").assertExists()
        compose.onNodeWithText("Watch translate").performClick()
        compose.onNodeWithText("Game translate").performClick()
        assertEquals(listOf("watch", "game"), went)
    }

    @Test
    fun theLiveTabOpensWatchTranslate() {
        seed()
        shell()
        compose.onNodeWithText("Live").performClick()
        compose.onNodeWithText("Watch translate").performClick()
        compose.onNodeWithText("Classic WatchTranslate").assertExists()
    }

    // Major: Text size and App language keep the page, and reach sheets and dialogs.

    @Test
    fun changingTextSizeAndLanguageStaysOnAppearance() {
        seed()
        shell()
        rows()
        compose.onNodeWithContentDescription("Account and settings").performClick()
        compose.onNode(hasText("Appearance") and hasClickAction()).performClick()
        compose.onNodeWithText("Text size").assertExists()
        vm.updateSettings { it.copy(textSize = "larger") }
        pumpUntil("larger") { vm.settings.value.textSize == "larger" }
        compose.waitForIdle()
        compose.onNodeWithText("Text size").assertExists()
        vm.updateSettings { it.copy(appLanguage = "ur") }
        pumpUntil("Urdu") { vm.settings.value.appLanguage == "ur" }
        compose.waitForIdle()
        // Still Appearance, now in Urdu; not back on Library.
        compose.onNode(hasText(V2Testing.resources(EchoLanguage.Urdu).getString(com.echoledger.mobile.R.string.v2_set_page_appearance)))
            .assertExists()
        compose.onNodeWithText("Caller 001", substring = true).assertDoesNotExist()
    }

    private fun SemanticsNodeInteraction.layout(): TextLayoutResult {
        val results = mutableListOf<TextLayoutResult>()
        fetchSemanticsNode().config[SemanticsActions.GetTextLayoutResult].action?.invoke(results)
        return results.single()
    }

    @Test
    fun theRecordSheetAndADialogUseTheAppLanguageAndTextSizeOnAnEnglishPhone() {
        seed()
        vm.updateSettings { it.copy(appLanguage = "ur", textSize = "largest") }
        pumpUntil("set") { vm.settings.value.textSize == "largest" }
        shell()
        rows()
        val ur = V2Testing.resources(EchoLanguage.Urdu)
        val phoneScale = app.resources.configuration.fontScale
        // The Record sheet.
        centre(ur.getString(com.echoledger.mobile.R.string.v2_nav_record)).performTouchInput { longClick() }
        val title = compose.onNodeWithText(ur.getString(com.echoledger.mobile.R.string.v2_rs_title), useUnmergedTree = true)
        title.assertExists()
        assertEquals(phoneScale * 1.3f, title.layout().layoutInput.density.fontScale, 0.001f)
        androidx.test.espresso.Espresso.pressBack()
        compose.waitForIdle()
        // A dialog: Delete for a selected recording.
        compose.onAllNodesWithText("Caller 001", substring = true).onFirst().performTouchInput { longClick() }
        compose.onNodeWithContentDescription(ur.getString(com.echoledger.mobile.R.string.v2_lib_delete)).performClick()
        val ask = compose.onNodeWithText(ur.getString(com.echoledger.mobile.R.string.v2_lib_delete_one_title), useUnmergedTree = true)
        ask.assertExists()
        assertEquals(phoneScale * 1.3f, ask.layout().layoutInput.density.fontScale, 0.001f)
    }

    // Minors.

    @Test
    fun theIdleRecordButtonKeepsItsColourWithWallpaperColours() {
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                // A wallpaper accent far from teal.
                CompositionLocalProvider(
                    LocalEchoV2Colors provides EchoV2Colors.Light.copy(accent = androidx.compose.ui.graphics.Color.Magenta)
                ) {
                    EchoRecordButton(recording = false, onTap = {}, onLongPress = {})
                }
            }
        }
        val image = compose.onNodeWithContentDescription("Record").captureToImage().asAndroidBitmap()
        // A point inside the circle, away from the mic.
        assertEquals(EchoV2Colors.Light.accent.toArgb(), image.getPixel(image.width / 2, image.height / 6))
    }

    @Test
    fun theCallsChipIsBigEnoughToTouch() {
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                CallsChip(com.echoledger.mobile.core.ReadyChip.READY, {})
            }
        }
        val node = compose.onNode(hasClickAction()).fetchSemanticsNode()
        val min = with(compose.density) { com.echoledger.mobile.ui.v2.tokens.EchoTokens.Layout.controlTouchMin.toPx() }
        assertTrue("touch area ${node.touchBoundsInRoot}", node.touchBoundsInRoot.height >= min - 0.5f)
    }

    @Test
    fun withTheShellButClassicLibraryTheAvatarAndBellStillLeadOn() {
        seed()
        shell(UiFlags(mapOf("ui_v2.shell" to true)))
        compose.onNodeWithText("Classic library").assertExists()
        compose.onNodeWithContentDescription("Activity").assertExists()
        compose.onNodeWithContentDescription("Account and settings").performClick()
        compose.onNodeWithText("Classic library").assertDoesNotExist()
    }

    @Test
    fun theAvatarIsLastInLibrarysHeader() {
        seed()
        shell()
        rows()
        val avatar: Rect = compose.onNodeWithContentDescription("Account and settings").fetchSemanticsNode().boundsInRoot
        val more: Rect = compose.onNode(hasContentDescription("More") and hasClickAction()).fetchSemanticsNode().boundsInRoot
        assertTrue("avatar $avatar after ⋮ $more", avatar.left >= more.right - 0.5f)
    }

    @Test
    fun settingsShowsThisMonthsMinutesOnceAsAProgressBar() {
        seed()
        vm.updateSettings { it.copy(plan = it.plan.copy(usedMinutes = 120, monthlyMinutes = 300)) }
        pumpUntil("plan") { vm.settings.value.plan.usedMinutes == 120L }
        shell()
        rows()
        compose.onNodeWithContentDescription("Account and settings").performClick()
        val words = "120 of 300 minutes used"
        pumpUntil("the bar") {
            compose.onAllNodes(hasText(words, substring = true), useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()
        }
        assertEquals(1, compose.onAllNodes(hasText(words, substring = true), useUnmergedTree = true).fetchSemanticsNodes().size)
        compose.onNode(SemanticsMatcher.keyIsDefined(SemanticsProperties.ProgressBarRangeInfo)).assertExists()
    }
}
