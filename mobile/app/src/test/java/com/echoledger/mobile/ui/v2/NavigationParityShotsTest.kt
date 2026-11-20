package com.echoledger.mobile.ui.v2

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.MAIN_TABS
import com.echoledger.mobile.calls.MissedItem
import com.echoledger.mobile.calls.MissedReason
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ReadinessFixtures
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.ui.ActivityScreen
import com.echoledger.mobile.ui.LibraryScreen
import com.echoledger.mobile.ui.RecordSheet
import com.echoledger.mobile.ui.design.EchoBottomBar
import com.echoledger.mobile.ui.v2.activity.ActivityActions
import com.echoledger.mobile.ui.v2.activity.V2ActivityContent
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.time.LocalDate
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * The navigation and Activity, classic | v2 side by side (docs/PLAN_V2_PARITY.md sections 2 and 8): Library with the bar,
 * the Record sheet, and Activity; light and dark, English and Urdu (classic's half stays English).
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi")
class NavigationParityShotsTest : ScreenTest() {
    private val today = LocalDate.parse("2026-11-21")
    private val all = listOf("light-en", "light-ur", "dark-en", "dark-ur")

    @Before
    fun seed() {
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
        runBlocking { app.graph.library.refresh() }
    }

    private val stub = ClassicScreens(
        home = { Text("") },
        library = { Text("") },
        live = { Text("") },
        ask = { Text("") },
        settings = { Text("") },
        upgrade = {}
    )

    @Composable
    private fun classicWithBar(content: @Composable () -> Unit) = Column(Modifier.fillMaxSize()) {
        Box(Modifier.weight(1f).fillMaxWidth()) { content() }
        EchoBottomBar(MAIN_TABS, MAIN_TABS.first().key, {}, {}, recording = false, onRecord = {}, onRecordLongPress = {})
    }

    @Composable
    private fun shell(dark: Boolean, language: EchoLanguage) = EchoV2Shell(
        vm,
        UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "ui_v2.live" to true, "ui_v2.ask" to true)),
        darkTheme = dark,
        classic = stub,
        onSwitchToClassic = {},
        language = language
    )

    private fun rows() =
        compose.waitUntil(5_000) { compose.onAllNodesWithText("Bilal Traders", substring = true).fetchSemanticsNodes().isNotEmpty() }

    @Test
    fun libraryWithTheBar() = assertEquals(
        all,
        ParityShots(compose).shoot(
            "nav-library",
            classic = { classicWithBar { LibraryScreen(vm, readiness = ReadinessFixtures.HELPER_STOPPED, onReady = {}, onOpen = {}) } },
            v2 = { dark, language -> shell(dark, language) },
            each = ::rows
        )
    )

    @Test
    fun theRecordSheet() = assertEquals(
        all,
        ParityShots(compose).shoot(
            "nav-record-sheet",
            classic = {
                classicWithBar { LibraryScreen(vm, readiness = ReadinessFixtures.HELPER_STOPPED, onReady = {}, onOpen = {}) }
                RecordSheet(
                    Capabilities(34, false, micPermission = true, notificationPermission = true, overlayPermission = true),
                    autoRecordOn = false,
                    onPick = {},
                    onDismiss = {},
                    onRecordNow = {}
                )
            },
            v2 = { dark, language -> shell(dark, language) },
            each = {
                rows()
                val record = compose.onAllNodes(hasContentDescription("Record") or hasContentDescription("ریکارڈ"))
                if (record.fetchSemanticsNodes().isNotEmpty()) record.onFirst().performTouchInput { longClick() }
                compose.waitForIdle()
            }
        )
    )

    private val answer = ActivityResponse(
        listOf(
            ActivityItemDto("a1", "upload_failed", "Upload failed", "Rehman and Sons Textiles", "01JB", false, "2026-11-21T09:00:00Z"),
            ActivityItemDto("a2", "transcript_ready", "Transcript ready", "Bilal Traders", "01JA", true, "2026-11-21T07:00:00Z"),
            ActivityItemDto("a3", "device_added", "New device signed in", "Pixel 9", null, true, "2026-11-10T07:00:00Z")
        ),
        unreadCount = 1
    )
    private val missed = MissedItem(
        ref = "m1",
        app = "whatsapp",
        startedAtMs = java.time.Instant.parse("2026-11-21T08:00:00Z").toEpochMilli(),
        durationS = 312,
        reason = MissedReason.MIC_BUSY.wire,
        detail = MissedReason.MIC_BUSY.detail,
        foundAtMs = 0
    )

    @Test
    fun activity() = assertEquals(
        all,
        ParityShots(compose).shoot(
            "activity",
            classic = {
                ActivityScreen(
                    load = { answer },
                    markRead = { 0 },
                    onOpen = {},
                    onUnread = {},
                    waiting = listOf(HomeFixtures.waiting("r-a", "Omar", 60_000)),
                    today = today,
                    missed = listOf(missed)
                )
            },
            v2 = { dark, language ->
                EchoV2Theme(darkTheme = dark, language = language) {
                    CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                        V2ActivityContent(
                            load = { answer },
                            markRead = { 0 },
                            waiting = listOf(HomeFixtures.waiting("r-a", "Omar", 60_000)),
                            failedUploads = emptyList(),
                            untranscribed = 0,
                            failedTranscripts = 0,
                            skipped = emptyList(),
                            missed = listOf(missed),
                            actions = ActivityActions(onOpenRecording = {}, onFix = {}, onOpenUntranscribed = {}),
                            onKeep = {},
                            onDiscard = {},
                            onRetryUploads = {},
                            onRestore = {},
                            onMissedRead = {},
                            onMissedDismiss = {},
                            today = today
                        )
                    }
                }
            },
            each = {
                compose.waitUntil(5_000) {
                    (
                        compose.onAllNodesWithText("Upload failed").fetchSemanticsNodes() +
                            compose.onAllNodesWithText("اپ لوڈ ناکام").fetchSemanticsNodes()
                        )
                        .isNotEmpty()
                }
            }
        )
    )
}
