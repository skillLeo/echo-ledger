package com.echoledger.mobile.ui.v2

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.FeatureDto
import com.echoledger.mobile.data.LimitDto
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.StorageDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.simulateScreenLock
import com.echoledger.mobile.ui.LocalUnlockPrompter
import com.echoledger.mobile.ui.UnlockPrompter
import com.echoledger.mobile.ui.v2.ask.AskState
import com.echoledger.mobile.ui.v2.ask.V2AskContent
import com.echoledger.mobile.ui.v2.assist.AssistStartUi
import com.echoledger.mobile.ui.v2.assist.V2AssistStartScreen
import com.echoledger.mobile.ui.v2.help.FixtureHelpSource
import com.echoledger.mobile.ui.v2.help.V2HelpScreen
import com.echoledger.mobile.ui.v2.library.LocalScreenPictures
import com.echoledger.mobile.ui.v2.library.ScreenAssets
import com.echoledger.mobile.ui.v2.library.TranscriptState
import com.echoledger.mobile.ui.v2.library.V2RecordingContent
import com.echoledger.mobile.ui.v2.library.V2ScreenViewer
import com.echoledger.mobile.ui.v2.settings.AppLockPage
import com.echoledger.mobile.ui.v2.settings.CallsPage
import com.echoledger.mobile.ui.v2.settings.KeyPage
import com.echoledger.mobile.ui.v2.settings.LanguagePage
import com.echoledger.mobile.ui.v2.settings.NotificationsPage
import com.echoledger.mobile.ui.v2.settings.PrivacyPage
import com.echoledger.mobile.ui.v2.settings.StoragePage
import com.echoledger.mobile.ui.v2.settings.WordsPage
import com.echoledger.mobile.ui.v2.shell.Profile
import com.echoledger.mobile.ui.v2.shell.ProfileState
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.shell.V2SettingsHomeContent
import com.echoledger.mobile.ui.v2.workspace.V2WorkspaceScreen
import kotlinx.coroutines.runBlocking
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * The v2 screens LargeTextTest doesn't cover (Help, Live Assist's start, Ask before a question, a recording's screens,
 * the screen viewer, the avatar's Settings, every v2 Settings page and a project's workspace), at 130 % and 200 % text,
 * light and dark, English and Urdu (BugBash): every control reachable at 200 %, and with `build/bugbash` present the
 * pictures and contact sheets there.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi")
class LargeTextScreensTest : ScreenTest() {
    private val bash by lazy { BugBash(compose, listOf(1.3f, 2.0f)) }

    private fun press(text: String) = runCatching {
        compose.onAllNodes(hasText(text) and hasClickAction()).onFirst().performScrollTo().performClick()
        compose.waitForIdle()
    }

    private fun has(text: String) = compose.onAllNodes(hasText(text, substring = true)).fetchSemanticsNodes().isNotEmpty()

    @Test
    fun helpGuides() = bash.run("help-guides") { HelpScreen(0) }

    @Test
    fun helpWhatsNew() = bash.run("help-new") { HelpScreen(1) }

    @Test
    fun helpContact() = bash.run("help-contact") { HelpScreen(2) }

    @Test
    fun helpGuide() {
        // The first guide, opened again whenever a new variant shows the list.
        val open = { if (has("After a call") && !has("Back to guides")) press("After a call") }
        bash.run("help-guide", each = { open() }) { HelpScreen(0) }
    }

    private val help = FixtureHelpSource(lastReport = "2026-11-17T05:42:00+00:00")

    @androidx.compose.runtime.Composable
    private fun HelpScreen(tab: Int) = V2HelpScreen(help, onPhoneCheck = {}, onBack = {}, startTab = tab)

    @Test
    fun assistStart() = bash.run("assist-start") { AssistStart(AssistStartUi.CanStart()) }

    @Test
    fun assistNeedsRecording() = bash.run("assist-needs-recording") { AssistStart(AssistStartUi.NeedsRecording) }

    @Test
    fun assistRunning() = bash.run("assist-running") { AssistStart(AssistStartUi.On(true)) }

    @androidx.compose.runtime.Composable
    private fun AssistStart(ui: AssistStartUi) = V2AssistStartScreen(ui, {}, {}, {}, {}, {}, {})

    @Test
    fun askEmpty() = bash.run("ask-empty") { _ ->
        V2AskContent(listOf(ClientDto("01JC1", "Bilal Traders")), "01JC1", {}, "", {}, AskState.Idle, {}, { _, _ -> })
    }

    private fun screen(id: String, index: Int, atMs: Long, description: String) =
        ScreenDto(id, index, atMs, 1600, 900, "change", null, description, "https://x/s/$id.webp", "https://x/s/$id.webp")

    private val screens = listOf(
        screen("01JS1", 1, 26_000, "The client's purchase order PO-2291 with the rate column"),
        screen("01JS2", 2, 128_000, "The delivery schedule, Thursday highlighted")
    )

    private val lines = listOf(
        SegmentDto(2, "Client", 4_000, 10_000, "Please send the new rate for forty cartons."),
        SegmentDto(1, "Me", 20_000, 30_000, "It is Rs 4,200 a carton, on the PO you shared."),
        SegmentDto(1, "Me", 125_000, 131_000, "Delivery is Thursday, as on this schedule.")
    )

    private val pictures: suspend (String) -> ImageBitmap? = {
        val bitmap = Bitmap.createBitmap(480, 270, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap)
        canvas.drawColor(0xFFFFFFFF.toInt())
        val paint = Paint()
        paint.color = 0xFFD9DDDC.toInt()
        for (row in 0 until 6) canvas.drawRect(24f, 64f + row * 30f, 456f, 80f + row * 30f, paint)
        bitmap.asImageBitmap()
    }

    @Test
    fun recordingScreens() = bash.run("recording-screens") { ur ->
        CompositionLocalProvider(LocalScreenPictures provides pictures) {
            V2RecordingContent(
                item = V2Fixtures.library(ur).first(),
                transcript = TranscriptState.Lines(lines),
                positionMs = 26_000,
                durationMs = 728_000,
                playing = false,
                speed = 1f,
                onPlayPause = {},
                onSeek = {},
                onSpeed = {},
                onShare = {},
                onDelete = {},
                onRetry = {},
                onBack = {},
                screens = screens,
                onOpenScreen = {}
            )
        }
    }

    @Test
    fun screenViewer() = bash.run("screen-viewer") { _ ->
        CompositionLocalProvider(LocalScreenPictures provides pictures) {
            V2ScreenViewer(ScreenAssets.moments(screens, lines), 0, {}, {})
        }
    }

    @Test
    fun settingsHome() = bash.run("settings-home") { ur ->
        V2SettingsHomeContent(
            state = ProfileState.Ready(
                Profile(if (ur) "عائشہ صدیقی" else "Ayesha Siddiqui", "ayesha@bilaltraders.pk", "Starter", 212, 300, 1_240_000_000, 318)
            ),
            flags = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true)),
            onSwitchToClassic = {},
            onUpgrade = {},
            onAllSettings = {},
            onRetry = {},
            onBack = {}
        )
    }

    @Test
    fun settingsCalls() = bash.run("settings-calls") { _ ->
        CallsPage(AppSettings(extraCallApps = listOf("com.example.voip")), {}, highlight = null, onBack = {}, manufacturer = "samsung")
    }

    @Test
    fun settingsNotifications() = bash.run("settings-notifications") { _ ->
        NotificationsPage(AppSettings(quietHours = true, notifyOff = listOf("readiness")), {}, highlight = null, onBack = {})
    }

    @Test
    fun settingsLock() {
        app.simulateScreenLock(true)
        vm.updateSettings { it.copy(appLockEnabled = true) }
        pumpUntil("lock on") { vm.settings.value.appLockEnabled }
        bash.run("settings-lock") { _ ->
            val settings by vm.settings.collectAsState()
            CompositionLocalProvider(LocalUnlockPrompter provides UnlockPrompter { it(true) }) {
                AppLockPage(settings, vm::updateSettings, {}, highlight = null, onBack = {})
            }
        }
    }

    @Test
    fun settingsLockUnavailable() = bash.run("settings-lock-unavailable") { _ ->
        CompositionLocalProvider(LocalUnlockPrompter provides UnlockPrompter { it(true) }) {
            AppLockPage(AppSettings(), {}, {}, highlight = null, onBack = {})
        }
    }

    @Test
    fun settingsPrivacy() = bash.run("settings-privacy") { _ ->
        PrivacyPage(AppSettings(), {}, highlight = null, onOpenPage = {}, onBack = {})
    }

    @Test
    fun settingsStorage() = bash.run("settings-storage") { _ ->
        StoragePage(
            { StorageDto(recordingsBytes = 1_500_000_000, recordingsCount = 12, retentionDays = 30) },
            LimitDto(limit = 10_000_000_000, used = 1_500_000_000),
            720,
            highlight = null,
            onBack = {}
        )
    }

    @Test
    fun settingsLanguage() = bash.run("settings-language") { _ ->
        val settings by vm.settings.collectAsState()
        LanguagePage(vm, settings, highlight = null, onBack = {})
    }

    @Test
    fun settingsWords() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        vm.refresh()
        pumpUntil("projects") { vm.clients.value.size == 2 }
        bash.run("settings-words") { _ ->
            val settings by vm.settings.collectAsState()
            WordsPage(vm, settings, highlight = null, onBack = {})
        }
    }

    @Test
    fun settingsKey() {
        vm.updateSettings { it.copy(plan = PlanState(features = mapOf("platform_transcription" to FeatureDto(enabled = false)))) }
        pumpUntil("free plan") { vm.settings.value.plan.needsOwnKey }
        bash.run("settings-key") { _ ->
            val settings by vm.settings.collectAsState()
            KeyPage(vm, settings.plan, highlight = null, onBack = {})
        }
    }

    private fun workspace(name: String, tab: Pair<String, String>, shows: String) {
        WorkspaceFixtures.serve(server, vm, app)
        bash.run(
            name,
            each = {
                pumpUntil("the project") { has("Qureshi") }
                press(if (bash.language == com.echoledger.mobile.ui.v2.theme.EchoLanguage.Urdu) tab.second else tab.first)
                pumpUntil(shows) { has(shows) }
            }
        ) { _ ->
            V2WorkspaceScreen(vm, WorkspaceFixtures.PROJECT, onBack = {}, onOpenProject = {}, onOpenRecording = {}, onOpenAt = { _, _ -> })
        }
    }

    @Test
    fun workspaceRecordings() = workspace("workspace-recordings", "Recordings" to "ریکارڈنگز", "“Qureshi”")

    @Test
    fun workspaceMemory() = workspace("workspace-memory", "Memory & instructions" to "یادداشت اور ہدایات", "Ship 40,000")

    @Test
    fun workspaceFiles() = workspace("workspace-files", "Files" to "فائلیں", "Proforma March.pdf")

    @Test
    fun workspaceCallNotes() = workspace("workspace-call-notes", "Call notes" to "کال نوٹس", "40% advance")
}
