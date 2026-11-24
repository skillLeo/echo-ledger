package com.echoledger.mobile.ui.v2

import androidx.compose.material3.Text
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.R
import com.echoledger.mobile.core.ReadyChip
import com.echoledger.mobile.core.ServerBanners
import com.echoledger.mobile.core.ServerNotices
import com.echoledger.mobile.data.AnnouncementDto
import com.echoledger.mobile.data.AppUpdateDto
import com.echoledger.mobile.data.MaintenanceDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.home.LibraryTop
import com.echoledger.mobile.ui.v2.home.LibraryTopActions
import com.echoledger.mobile.ui.v2.home.V2LibraryTop
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The top of v2 Library says what classic's says (parity.csv PH-LIB-059, 060): the server's banners (an app update
 * with Update, maintenance, the admin's announcement with ×, remembered), and "Calls won't record" (since v2.1) with the
 * Ready check's reason, Fix, and ×.
 */
class V2LibraryBannersParityTest : ScreenTest() {
    private val classic = ClassicScreens(
        home = { Text("Classic activity") },
        library = { Text("Classic library") },
        live = { Text("Classic live") },
        ask = { Text("Classic projects") },
        settings = { Text("Classic settings") },
        upgrade = {}
    )

    private fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem("01JA")))))
        server.on("GET", "/api/activity", FakeServer.json(200, """{"data": [], "unread_count": 0}"""))
        runBlocking { app.graph.library.refresh() }
    }

    private fun shell() = show {
        EchoV2Shell(
            vm,
            UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true)),
            darkTheme = false,
            classic = classic,
            onSwitchToClassic = {},
            language = EchoLanguage.English
        )
    }

    private fun seen(words: String) = compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()

    @Test
    fun theServersUpdateMaintenanceAndAnnouncementShowAndTheAnnouncementStaysClosed() {
        seed()
        runBlocking {
            app.graph.settings.update {
                it.copy(
                    setupCardHidden = true,
                    serverNotices = ServerNotices(
                        AppUpdateDto("1.6.0", required = true, message = "Please update EchoLedger to 1.6.0 or later."),
                        AnnouncementDto("New: plans and badges."),
                        MaintenanceDto("Back at 11:00.")
                    )
                )
            }
        }
        shell()
        pumpUntil("the banners") { seen("Please update EchoLedger to 1.6.0 or later.") }
        assertTrue(seen("Back at 11:00."))
        assertTrue(seen("New: plans and badges."))
        // Update looks for the new version on the server (the website build updates itself).
        compose.onNode(hasText("Update") and hasClickAction()).performClick()
        pumpUntil("the update check") { server.requestsTo("GET", "/downloads/android.json").isNotEmpty() }
        // The announcement's × closes it on this phone for good, and only it.
        val closes = compose.onAllNodes(hasContentDescription("Close") and hasClickAction())
        closes.assertCountEquals(4)
        closes[2].performClick()
        pumpUntil("closed and remembered") {
            runBlocking { app.graph.settings.current() }.closedAnnouncements == listOf(ServerBanners.keyOf("New: plans and badges."))
        }
        pumpUntil("it went") { !seen("New: plans and badges.") }
        assertTrue(seen("Back at 11:00."))
        // Maintenance closes for now: it is not remembered.
        compose.onAllNodes(hasContentDescription("Close") and hasClickAction())[1].performClick()
        pumpUntil("maintenance went") { !seen("Back at 11:00.") }
        assertEquals(1, runBlocking { app.graph.settings.current() }.closedAnnouncements.size)
    }

    @Test
    fun theAppsOwnFallbackWordsAreInTheScreensLanguage() {
        seed()
        val notices = ServerNotices(AppUpdateDto("1.6.0", refused = true), maintenance = MaintenanceDto(""))
        val banners = ServerBanners.of(notices, emptyList())
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.Urdu) {
                V2LibraryTop(emptyList(), setupHidden = true, banner = null, actions = actions(), server = banners)
            }
        }
        compose.waitForIdle()
        val res = app.resources
        assertTrue(seen("سرور اب اس ورژن سے ریکارڈنگز نہیں لیتا"))
        assertTrue(seen("سرور پر مرمت کا کام جاری ہے"))
        assertTrue(compose.onAllNodesWithText(res.getString(R.string.v2_lib_server_refused)).fetchSemanticsNodes().isEmpty())
    }

    private var fixes = 0

    private fun actions() = LibraryTopActions(
        onSetup = {},
        onAllow = {},
        onRecord = {},
        onChooseLanguage = {},
        onHideSetup = {},
        onFix = { fixes++ },
        onReview = {},
        onRetryUploads = {}
    )

    @Test
    fun callsNotRecordedSaysWhyWithFixAndCloses() {
        // The Ready check's first blocking problem names the reason, in Setup & health's words.
        val banner = LibraryTop.banner(ReadyChip.NOT_READY, emptyList(), blocking = "helper_after_restart")!!
        assertEquals(R.string.v2_setup_fail_helper_after_restart, banner.reason)
        assertEquals(null, LibraryTop.banner(ReadyChip.READY, emptyList(), blocking = "helper_after_restart"))
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                V2LibraryTop(emptyList(), setupHidden = true, banner = banner, actions = actions())
            }
        }
        compose.onNodeWithText("Calls won't record: the helper stopped when the phone restarted").assertExists()
        compose.onNode(hasText("Fix") and hasClickAction()).performClick()
        assertEquals(1, fixes)
        compose.onNode(hasContentDescription("Close") and hasClickAction()).performClick()
        compose.waitForIdle()
        assertTrue(!seen("Calls won't record"))
    }

    @Test
    fun inTheShellTheCallsBannerNamesThisPhonesReason() {
        seed()
        runBlocking { app.graph.settings.update { it.copy(setupCardHidden = true) } }
        shell()
        // The banner says, in Setup & health's words, the first of the Ready check's problems that stops calls.
        val facts = com.echoledger.mobile.recording.ReadinessWatch.facts(
            app,
            runBlocking {
                app.graph.settings.current()
            },
            app.graph.helper
        )
        val blocking = com.echoledger.mobile.core.Readiness.evaluate(facts).problems.first { it.blocking }
        val reason = app.getString(com.echoledger.mobile.ui.v2.setup.HealthChecks.failWords(blocking.key)!!).replaceFirstChar {
            it.lowercase()
        }
        pumpUntil("the reason") { compose.onAllNodesWithText("Calls won't record: $reason").fetchSemanticsNodes().isNotEmpty() }
    }
}
