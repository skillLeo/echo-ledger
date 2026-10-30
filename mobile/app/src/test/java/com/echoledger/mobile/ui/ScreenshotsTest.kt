package com.echoledger.mobile.ui

import android.graphics.Bitmap
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.design.DesignGallery
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * Renders the key screens and the Design gallery for design review (Roborazzi: docs/design/screens, see
 * docs/DESIGN_SYSTEM.md), and checks that each one actually drew something.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w393dp-h852dp-hdpi")
class ScreenshotsTest : ScreenTest() {
    private val outDir = File(System.getProperty("screenshots.dir") ?: "build/screenshots")

    private var themeMode by mutableStateOf("dark")

    /** Screens draw on the app's window background (MainActivity); give them the same backdrop here. */
    private fun shoot(content: @Composable () -> Unit) = show {
        EchoLedgerTheme(themeMode = themeMode) {
            Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) { content() }
        }
    }

    /** Captures the screen in the dark and then the light theme. */
    private fun capture(name: String) {
        themeMode = "dark"
        captureOne("$name-dark")
        themeMode = "light"
        compose.mainClock.advanceTimeBy(500)
        captureOne("$name-light")
    }

    private fun captureOne(name: String) {
        compose.waitForIdle()
        val bitmap = compose.onRoot().captureToImage().asAndroidBitmap()
        // Written (or compared) only by the Roborazzi tasks; a plain test run just checks that the screen drew.
        compose.onRoot().captureRoboImage(File(outDir, "phone-$name.png"))
        assertTrue("$name drew nothing", bitmap.width > 0 && distinctColours(bitmap) > 2)
    }

    private fun distinctColours(bitmap: Bitmap): Int {
        val seen = HashSet<Int>()
        for (y in 0 until bitmap.height step 16) for (x in 0 until bitmap.width step 16) seen += bitmap.getPixel(x, y)
        return seen.size
    }

    private fun item(
        id: String,
        title: String,
        app: String,
        device: String,
        preview: String?,
        started: String,
        client: String? = null,
        final: String = "completed"
    ) = ServerFixtures.libraryItem(id, title, started, started, device, app, "completed", final, preview, client)

    private fun seedLibrary() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        item(
                            "01JA",
                            "Weekly sync with Northwind",
                            "google_meet",
                            "desktop",
                            "Thanks for joining. Let us start with the budget for the second phase.",
                            "2026-10-24T09:00:00+00:00",
                            "Northwind"
                        ),
                        item(
                            "01JB",
                            "Invoice questions",
                            "whatsapp",
                            "phone",
                            "Hi Sam, thanks for calling back about the second invoice.",
                            "2026-10-24T07:10:00+00:00",
                            "Aria Studio"
                        ),
                        item("01JC", "Voice note", "voice_note", "phone", null, "2026-10-23T16:02:00+00:00", final = "processing"),
                        item(
                            "01JD",
                            "Kick-off: Contoso website",
                            "microsoft_teams",
                            "desktop",
                            "Our main goal for the new website is fewer support calls.",
                            "2026-10-22T10:00:00+00:00"
                        )
                    )
                )
            )
        )
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        runBlocking { app.graph.library.refresh() }
    }

    @Test
    fun library() {
        seedLibrary()
        shoot { LibraryScreen(vm, showCallSetup = false, onSetup = {}, onOpen = {}) }
        capture("library")
    }

    @Test
    fun libraryEmpty() {
        shoot { LibraryScreen(vm, showCallSetup = false, onSetup = {}, onOpen = {}) }
        capture("library-empty")
    }

    @Test
    fun session() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        val item = LibraryItem(
            id = ServerFixtures.SESSION_ID,
            sourceDevice = "phone",
            sourceApp = "whatsapp",
            title = "Invoice questions",
            client = NamedRef("01JCLIENT0000000000000000A", "Aria Studio"),
            startedAt = "2026-10-24T07:10:00+00:00",
            durationMs = 742_000,
            status = "completed",
            transcript = TranscriptSummary(
                finalStatus = "completed",
                finalTranscriptId = ServerFixtures.TRANSCRIPT_ID,
                liveAvailable = false,
                preview = "preview"
            ),
            updatedAt = "2026-10-24T07:30:00+00:00"
        )
        shoot { SessionScreen(vm, item, onBack = {}) }
        compose.waitUntil(5_000) { runCatching { compose.onNodeWithText("Hello there.", substring = true).fetchSemanticsNode() }.isSuccess }
        capture("session")
    }

    @Test
    fun record() {
        runBlocking { app.pairLocally(server) }
        compose.mainClock.autoAdvance = false
        shoot {
            RecordScreen(
                vm,
                Capabilities(
                    34,
                    true,
                    micPermission = true,
                    notificationPermission = true,
                    overlayPermission = true
                ),
                onSetup = {
                }
            )
        }
        compose.mainClock.advanceTimeBy(1_500)
        capture("record")
    }

    @Test
    fun settingsHub() {
        runBlocking { app.pairLocally(server) }
        shoot { SettingsScreen(vm, onSetup = {}) }
        capture("settings")
    }

    @Test
    fun settingsGroup() {
        runBlocking { app.pairLocally(server) }
        shoot { SettingsScreen(vm, onSetup = {}) }
        compose.onNodeWithText("Recording").performClick()
        capture("settings-recording")
    }

    @Test
    fun onboarding() {
        shoot { OnboardingScreen(vm) }
        capture("onboarding")
    }

    @Test
    fun lock() {
        shoot { LockScreen(onUnlock = {}) }
        capture("lock")
    }

    @Test
    fun `design gallery`() {
        var part by mutableStateOf(0)
        shoot { DesignGallery(part = part) }
        capture("gallery-1")
        part = 1
        capture("gallery-2")
    }

    @Test
    fun clients() {
        seedLibrary()
        vm.refresh()
        pumpUntil("the clients to load") { vm.clients.value.isNotEmpty() }
        shoot { ClientsScreen(vm, onOpenItem = {}) }
        capture("clients")
    }

    @Test
    fun activity() {
        val today = java.time.LocalDate.now().toString()
        val items = listOf(
            ActivityItemDto(
                "a1",
                "transcription_failed",
                "Transcription failed",
                "Call with +92 300 1234567 · Tap to retry",
                "s1",
                false,
                "${today}T06:02:00+00:00"
            ),
            ActivityItemDto(
                "a2",
                "transcript_ready",
                "Transcript ready",
                "Weekly sync with Northwind",
                "s2",
                false,
                "${today}T09:40:00+00:00"
            ),
            ActivityItemDto("a3", "transcript_ready", "Transcript ready", "Invoice questions", "s3", true, "2026-10-24T07:30:00+00:00"),
            ActivityItemDto("a4", "device_paired", "New device", "Pixel 9 was paired", null, true, "2026-10-22T10:00:00+00:00")
        )
        val waiting = listOf(app.storeRecording(id = "call-1", state = "pending_decision", durationMs = 125_000))
        shoot { ActivityScreen(load = { ActivityResponse(items, 2) }, markRead = { 0 }, onOpen = {}, onUnread = {}, waiting = waiting) }
        pumpUntil("the list to load") { runCatching { compose.onNodeWithText("Needs action").assertExists() }.isSuccess }
        capture("activity")
    }

    @Test
    fun recordLive() {
        runBlocking { app.pairLocally(server) }
        RecordingService.live.value = LiveRecording(active = true, elapsedMs = 134_000, meLevel = 0.6f, clientLevel = 0.3f, markers = 1)
        try {
            shoot {
                RecordScreen(
                    vm,
                    Capabilities(
                        34,
                        true,
                        micPermission = true,
                        notificationPermission = true,
                        overlayPermission = true
                    ),
                    onSetup = {
                    }
                )
            }
            compose.mainClock.advanceTimeBy(1_500)
            capture("record-live")
        } finally {
            RecordingService.live.value = LiveRecording()
        }
    }

    @Test
    fun recordCalls() {
        runBlocking { app.pairLocally(server) }
        app.storeRecording(id = "call-1", state = "pending_decision", durationMs = 125_000)
        shoot {
            RecordScreen(
                vm,
                Capabilities(34, true, micPermission = true, notificationPermission = true, overlayPermission = true),
                onSetup = {},
                mode = RecordMode.CALLS
            )
        }
        pumpUntil("the waiting call") { runCatching { compose.onNodeWithText("Keep").assertExists() }.isSuccess }
        capture("record-calls")
    }
}
