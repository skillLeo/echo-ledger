package com.echoledger.mobile.ui

import android.graphics.Bitmap
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.hasScrollToIndexAction
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performScrollToIndex
import androidx.compose.ui.test.performScrollToNode
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.overlay.PillDot
import com.echoledger.mobile.overlay.PillToolbar
import com.echoledger.mobile.overlay.PillView
import com.echoledger.mobile.overlay.PrivateDot
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ReadinessFixtures
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.simulateBiometrics
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.design.DesignGallery
import com.echoledger.mobile.util.AppClock
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertTrue
import org.junit.Assume.assumeTrue
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * Renders the key screens and the Design gallery for design review (Roborazzi: docs/design/screens, see
 * docs/DESIGN_SYSTEM.md), and checks that each one actually drew something.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w393dp-h852dp-hdpi")
open class ScreenshotsTest : ScreenTest() {
    /** The F2 sweep's condition when a ClassicSweep test runs these screens (ClassicSweepTest.kt); null here. */
    protected open val sweep: SweepCondition? = null

    private val outDir get() = sweep?.dir ?: File(System.getProperty("screenshots.dir") ?: "build/screenshots")

    private var themeMode by mutableStateOf("dark")

    /** Screens draw on the app's window background (MainActivity); give them the same backdrop here. */
    private fun shoot(content: @Composable () -> Unit) = show {
        EchoLedgerTheme(themeMode = themeMode) {
            Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) { content() }
        }
    }

    /** Captures the screen in the dark and then the light theme (the sweep: light only, and its text checked). */
    private fun capture(name: String) {
        sweep?.let {
            themeMode = "light"
            compose.mainClock.advanceTimeBy(500)
            captureOne(name)
            return
        }
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
        val file = File(outDir, "phone-$name.png")
        // The sweep keeps its pictures on every run, for a look (build/sweep).
        if (sweep == null) compose.onRoot().captureRoboImage(file) else compose.onRoot().captureRoboImage(file, SweepCondition.RECORD)
        assertTrue("$name drew nothing", bitmap.width > 0 && distinctColours(bitmap) > 2)
        sweep?.assertTextWhole(compose, name)
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
                    ) + sweep?.extraItems().orEmpty()
                )
            )
        )
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        runBlocking { app.graph.library.refresh() }
    }

    @Test
    fun library() {
        seedLibrary()
        shoot { LibraryScreen(vm, readiness = ReadinessFixtures.READY, onReady = {}, onOpen = {}) }
        capture("library")
    }

    @Test
    fun libraryEmpty() {
        shoot { LibraryScreen(vm, readiness = ReadinessFixtures.READY, onReady = {}, onOpen = {}) }
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

    /** A recording's page with [transcript] (the server's answer), for the transcript's states (P-001: plain words). */
    private fun sessionState(name: String, transcript: String, wait: String, then: (() -> Unit)? = null) {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, transcript))
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
            transcript = TranscriptSummary(finalTranscriptId = ServerFixtures.TRANSCRIPT_ID, liveAvailable = false, preview = "preview"),
            updatedAt = "2026-10-24T07:30:00+00:00"
        )
        shoot { SessionScreen(vm, item, onBack = {}) }
        compose.waitUntil(10_000) { runCatching { compose.onNodeWithText(wait, substring = true).fetchSemanticsNode() }.isSuccess }
        if (then == null) capture(name) else then().also { captureWindows(name) }
    }

    /** A failed transcript: why, in the phone's own words for its error (never the engine's). */
    @Test
    fun sessionFailed() = sessionState(
        "session-failed",
        ServerFixtures.transcript(status = "failed", errorCode = "provider_rejected", error = "rejected", segments = "[]", text = null),
        "The transcription service could not transcribe this recording."
    )

    /** A refused own key (since 1.8.1-b, P-076): the sentence and "Check your key", with no Retry. */
    @Test
    fun sessionOwnKeyRejected() = sessionState(
        "session-own-key-rejected",
        ServerFixtures.transcript(status = "failed", errorCode = "own_key_rejected", error = "rejected", segments = "[]", text = null),
        "Groq did not accept your key."
    )

    /** Made by the backup engine: the offer of the best one, naming neither engine. */
    @Test
    fun sessionBackup() = sessionState(
        "session-backup",
        ServerFixtures.transcript(backupEngine = true, providerLabel = "a backup engine"),
        "Made with a backup service. Re-transcribe for the best quality?"
    )

    /** A line a second engine heard differently: "A second transcription heard: …" (never that engine's name). */
    @Test
    fun sessionSecondHearing() = sessionState(
        "session-second-hearing",
        ServerFixtures.transcript(
            segments = """[{"channel":1,"speaker":"Me","start_ms":250,"end_ms":2600,"text":"I will come on Sunday.","uncertain":true,
                "check":{"provider":"other","label":"Another engine","text":"I will come on Monday"},"words":[]}]"""
        ),
        "A second transcription heard: I will come on Monday"
    )

    /** Waiting for the preferred engine: "Waiting to transcribe — retrying at …". */
    @Test
    fun sessionWaiting() = sessionState(
        "session-waiting",
        ServerFixtures.transcript(status = "pending", segments = "[]", text = null).replace(
            "\"error_code\":null",
            "\"error_code\":null,\"retry_at\":\"2026-10-24T09:15:00+00:00\",\"retry_reason\":\"rate_limited\""
        ),
        "Waiting to transcribe"
    )

    /** A recording's page with its transcript, as [session] shows it, scrolled to the action row. */
    private fun sessionActions(name: String, fontScale: Float) {
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
        shoot {
            val density = androidx.compose.ui.platform.LocalDensity.current
            androidx.compose.runtime.CompositionLocalProvider(
                androidx.compose.ui.platform.LocalDensity provides androidx.compose.ui.unit.Density(density.density, fontScale = fontScale)
            ) { SessionScreen(vm, item, onBack = {}) }
        }
        compose.waitUntil(5_000) { runCatching { compose.onNodeWithText("Hello there.", substring = true).fetchSemanticsNode() }.isSuccess }
        // The call notes load on their own: wait for their buttons, then bring the action row into view.
        compose.waitUntil(10_000) { runCatching { compose.onNodeWithText("In Roman Urdu").fetchSemanticsNode() }.isSuccess }
        compose.onNode(androidx.compose.ui.test.hasScrollAction()).performScrollToNode(androidx.compose.ui.test.hasText("Send to WhatsApp"))
        compose.waitForIdle()
        listOf("Send to WhatsApp", "In Roman Urdu").forEach { label ->
            val bounds = compose.onNodeWithText(label).fetchSemanticsNode().boundsInRoot
            assertTrue("$label: ${bounds.width} wide, ${bounds.height} high", bounds.width > bounds.height)
        }
        // A long label is never cut off: it takes a second line.
        val layouts = mutableListOf<androidx.compose.ui.text.TextLayoutResult>()
        compose.onNodeWithText("Copy for AI (marks unsure words)", useUnmergedTree = true).fetchSemanticsNode()
            .config[androidx.compose.ui.semantics.SemanticsActions.GetTextLayoutResult].action?.invoke(layouts)
        // Cut off = lines that did not fit under the label's line limit (its width is measured loosely by the test fonts).
        assertTrue("Copy for AI is cut off", layouts.isNotEmpty() && !layouts.first().didOverflowHeight)
        capture(name)
    }

    /** The action row on a 360 dp phone: Export · Share link · Send, and Send to WhatsApp on the next line. */
    @Test
    @Config(qualifiers = "w360dp-h800dp-hdpi")
    fun sessionActions360() {
        skipInSweep()
        sessionActions("session-actions-360", 1f)
    }

    /** The action row at twice the text size (Android's largest font). */
    @Test
    fun sessionActionsLargeText() {
        skipInSweep()
        sessionActions("session-actions-text200", 2f)
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
        compose.onNodeWithText("Calls & meetings").performClick()
        capture("settings-calls")
    }

    @Test
    fun settingsGeneral() {
        runBlocking { app.pairLocally(server) }
        shoot { SettingsScreen(vm, onSetup = {}) }
        compose.onNodeWithText("Appearance").performClick()
        capture("settings-general")
    }

    /**
     * Settings → Privacy & data, with Block screenshots of EchoLedger (off by default) and Hide EchoLedger's live words
     * (on by default; both since 2.0.5).
     */
    @Test
    fun settingsPrivacy() {
        runBlocking { app.pairLocally(server) }
        shoot { SettingsScreen(vm, onSetup = {}) }
        compose.onNodeWithText("Privacy & data").performScrollTo().performClick()
        compose.onNodeWithText(app.getString(com.echoledger.mobile.R.string.cc_block_screens)).assertExists()
        compose.onNodeWithText(app.getString(com.echoledger.mobile.R.string.cc_hide_live_words)).assertExists()
        capture("settings-privacy")
    }

    /** Settings pages in plain words (since v1.8.1, batch 3): Help, About, Microphone & audio, Storage. */
    private fun settingsPage(page: String, name: String, wait: String, shownServer: String? = null) {
        runBlocking { app.pairLocally(server) }
        // A page that only shows the address: a fixed one, so the picture does not change with the test server's port.
        shownServer?.let { url -> runBlocking { app.graph.settings.update { it.copy(serverUrl = url) } } }
        server.on(
            "GET",
            "/api/storage",
            FakeServer.json(
                200,
                """{"recordings_bytes":3221225472,"recordings_count":42,"disk_free_bytes":1,"disk_total_bytes":2,"retention_days":30}"""
            )
        )
        runBlocking {
            app.graph.settings.update {
                it.copy(
                    plan = it.plan.copy(
                        limits = mapOf(
                            "storage_bytes" to
                                com.echoledger.mobile.data.LimitDto(limit = 10L * 1024 * 1024 * 1024, used = 3L * 1024 * 1024 * 1024)
                        )
                    )
                )
            }
        }
        shoot { SettingsScreen(vm, onSetup = {}, openPage = page) }
        pumpUntil("the page") { exists(wait) }
        capture(name)
    }

    @Test
    fun settingsHelp() = settingsPage("help", "settings-help", "Copy diagnostics")

    @Test
    fun settingsAbout() = settingsPage("about", "settings-about", "Setup guide", shownServer = "https://ledger.example.com")

    @Test
    fun settingsMicrophone() = settingsPage("mic", "settings-microphone", "Recording quality")

    @Test
    fun settingsStorage() = settingsPage("storage", "settings-storage", "Recordings on the server: 3.0 GB in 42 files.")

    /** Setup: Display over other apps names the floating windows it is for (review of v1.8.1). */
    @Test
    fun setupOverlayStep() {
        runBlocking { app.pairLocally(server) }
        shoot {
            SetupScreen(
                vm,
                Capabilities(34, true, micPermission = true, notificationPermission = false, overlayPermission = false),
                onBack = {},
                onRecheck = {}
            )
        }
        compose.onNodeWithText("Display over other apps (optional)").assertExists()
        capture("setup")
    }

    /**
     * Live Translate while the floating windows are hidden (review of v1.8.1): the real box with its text, then the same
     * place hidden, where only the grey dot ("Show EchoLedger windows") stays, in Game and in Watch mode.
     */
    @Test
    fun translateHidden() {
        shoot {
            androidx.compose.foundation.layout.Column(
                Modifier.padding(16.dp),
                verticalArrangement = androidx.compose.foundation.layout.Arrangement.spacedBy(24.dp)
            ) {
                listOf(false, true).forEach { subtitles ->
                    androidx.compose.ui.viewinterop.AndroidView(
                        factory = { context ->
                            com.echoledger.mobile.overlay.TranslateTextView(context, subtitles).apply {
                                style(
                                    18,
                                    2,
                                    com.echoledger.mobile.translate.BoxBackground.DARK,
                                    showOriginal = true,
                                    language = "EN",
                                    subtitleBox = true
                                )
                                content(
                                    "Push to the left side, they are behind the wall.",
                                    "",
                                    "بائیں طرف چلو، وہ دیوار کے پیچھے ہیں۔",
                                    "UR",
                                    false,
                                    null
                                )
                            }
                        },
                        modifier = Modifier.fillMaxWidth().height(110.dp)
                    )
                    androidx.compose.foundation.layout.Box(Modifier.fillMaxWidth().height(110.dp)) {
                        com.echoledger.mobile.overlay.PrivateDot(
                            onShow = {},
                            modifier = Modifier.align(androidx.compose.ui.Alignment.TopEnd)
                        )
                    }
                }
            }
        }
        capture("translate-hidden")
    }

    /** Settings → App lock with the lock on: Lock after (hiding content in screenshots is Privacy & data's since 2.0.5). */
    @Test
    fun settingsAppLock() {
        runBlocking {
            app.pairLocally(server)
            app.graph.settings.update { it.copy(appLockEnabled = true) }
        }
        app.simulateBiometrics(true)
        pumpUntil("the lock to be on") { vm.settings.value.appLockEnabled }
        shoot {
            androidx.compose.runtime.CompositionLocalProvider(LocalUnlockPrompter provides UnlockPrompter { it(true) }) {
                SettingsScreen(vm, onSetup = {})
            }
        }
        compose.onNodeWithText("App lock").performScrollTo().performClick()
        compose.onNodeWithText("Lock after", useUnmergedTree = true).assertExists()
        capture("settings-lock")
    }

    /** Settings → Captions: live captions are a floating window over the call. */
    @Test
    fun settingsCaptions() {
        runBlocking { app.pairLocally(server) }
        shoot { SettingsScreen(vm, onSetup = {}) }
        compose.onNodeWithText("Captions").performScrollTo().performClick()
        compose.onNodeWithText("A floating window over the call; needs Display over other apps", useUnmergedTree = true).assertExists()
        capture("settings-captions")
    }

    @Test
    fun gameTranslate() {
        runBlocking { app.pairLocally(server) }
        shoot { LiveTranslatePage(vm, com.echoledger.mobile.translate.TranslateMode.GAME, onSetup = {}) }
        capture("game")
    }

    @Test
    fun watchTranslate() {
        runBlocking { app.pairLocally(server) }
        shoot { LiveTranslatePage(vm, com.echoledger.mobile.translate.TranslateMode.WATCH, onSetup = {}) }
        capture("watch")
    }

    /** Twice the phone's text size (C10): nothing overlaps or is cut off. */
    @Test
    fun libraryLargeText() {
        skipInSweep()
        seedLibrary()
        shoot { LargeText { LibraryScreen(vm, readiness = ReadinessFixtures.READY, onReady = {}, onOpen = {}) } }
        capture("library-font200")
    }

    @Test
    fun settingsLargeText() {
        skipInSweep()
        runBlocking { app.pairLocally(server) }
        shoot { LargeText { SettingsScreen(vm, onSetup = {}) } }
        capture("settings-font200")
    }

    @Composable
    private fun LargeText(content: @Composable () -> Unit) {
        val density = androidx.compose.ui.platform.LocalDensity.current
        androidx.compose.runtime.CompositionLocalProvider(
            androidx.compose.ui.platform.LocalDensity provides androidx.compose.ui.unit.Density(density.density, fontScale = 2f),
            content = content
        )
    }

    /** F2: the classic screens the sweep needs too: signing in, the account, and voice notes shared from another app. */
    @Test
    fun signIn() {
        shoot { PairingScreen(vm) }
        capture("sign-in")
    }

    @Test
    fun account() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/account",
            FakeServer.json(
                200,
                """{"name":"Ayesha Khan","email":"ayesha.khan@northwind-traders.example.com","email_verified":true,"two_factor":true}"""
            )
        )
        shoot { AccountScreen(vm, onBack = {}) }
        pumpUntil("the account to load") { exists("Ayesha Khan") }
        capture("account")
    }

    @Test
    fun sharedNotes() {
        shoot { SharedNotesScreen(vm, listOf("note-1", "note-2"), onLeave = {}, sourceApp = "WhatsApp") }
        capture("shared-notes")
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
        skipInSweep()
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
        shoot { ProjectsScreen(vm, onOpenItem = {}) }
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

    /**
     * Activity with missed recordings (v1.8 0.2): this phone's own, with why in plain words and the button that fixes it,
     * above a missed recording the laptop reported.
     */
    @Test
    fun activityMissed() {
        // The same day and times on every day it runs (F2): the picture is compared with a saved one.
        AppClock.fix(java.time.Instant.parse("2026-11-17T06:42:00Z"), java.time.ZoneId.of("UTC"))
        try {
            activityMissedAtFixedTime()
        } finally {
            AppClock.reset()
        }
    }

    private fun activityMissedAtFixedTime() {
        val now = AppClock.millis()
        val missed = listOf(
            com.echoledger.mobile.calls.MissedItem(
                "r1",
                "whatsapp",
                now - 25 * 60_000,
                312,
                "helper_not_armed",
                "Auto-record was not running when the call started.",
                now
            ),
            com.echoledger.mobile.calls.MissedItem(
                "r2",
                "phone_call",
                now - 3 * 60 * 60_000,
                94,
                "detection",
                com.echoledger.mobile.calls.MissedReason.DETECTION.detail,
                now
            )
        )
        val today = AppClock.today().toString()
        val items = listOf(
            ActivityItemDto(
                "a1",
                "missed_recording",
                "Missed recording",
                "Zoom call · 31:02 · The call was not detected",
                null,
                false,
                "${today}T06:40:00+00:00",
                reason = "detection",
                missedRecordingId = "from-the-laptop"
            ),
            ActivityItemDto(
                "a2",
                "transcript_ready",
                "Transcript ready",
                "Weekly sync with Northwind",
                "s2",
                true,
                "${today}T05:10:00+00:00"
            )
        )
        shoot { ActivityScreen(load = { ActivityResponse(items, 1) }, markRead = { 0 }, onOpen = {}, onUnread = {}, missed = missed) }
        pumpUntil("the list to load") { runCatching { compose.onNodeWithText("Needs action").assertExists() }.isSuccess }
        capture("activity-missed")
    }

    /** What "Start the helper" opens: the Ready check, with the helper's problem and its one-tap fix. */
    @Test
    fun missedFixReadyCheck() {
        shoot {
            ReadyCheckSheet(vm, ReadinessFixtures.HELPER_STOPPED, onDismiss = {}, onSetup = {}, onRecheck = {})
        }
        compose.waitForIdle()
        captureScreenPair("activity-missed-fix", 1_000)
        compose.onNodeWithText("Open Shizuku").assertExists()
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

    /** Calls & meetings → Auto-record apps → Phone calls: the Phone app's own recordings on a Galaxy (v1.8 0.3). */
    @Test
    fun phoneAppRecordings() {
        org.robolectric.Shadows.shadowOf(app).grantPermissions(
            android.Manifest.permission.READ_CALL_LOG,
            android.Manifest.permission.READ_MEDIA_AUDIO
        )
        android.provider.Settings.System.putString(
            app.contentResolver,
            com.echoledger.mobile.calls.PhoneAppRecordings.SAMSUNG_AUTO_RECORD,
            "0"
        )
        org.robolectric.Shadows.shadowOf(app.packageManager).addResolveInfoForIntent(
            android.content.Intent(android.telecom.TelecomManager.ACTION_SHOW_CALL_SETTINGS),
            android.content.pm.ResolveInfo().apply {
                activityInfo = android.content.pm.ActivityInfo().apply {
                    packageName = "com.samsung.android.dialer"
                    name = "CallSettings"
                }
            }
        )
        val tenFortyTwo = java.time.LocalDate.now().atTime(10, 42).atZone(java.time.ZoneId.systemDefault()).toInstant().toEpochMilli()
        app.getSharedPreferences("phone_app_recordings", android.content.Context.MODE_PRIVATE).edit()
            .putString(
                "note",
                com.echoledger.mobile.calls.PhoneAppNote(com.echoledger.mobile.calls.PhoneAppNote.Kind.IMPORTED, seconds = 125).wire()
            )
            .putLong("note_at", tenFortyTwo)
            .putString("ask", "41:${tenFortyTwo - 3_600_000}")
            .commit()
        shoot {
            Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp)) {
                PhoneAppRecordingsBlock(
                    AppSettings(phoneCalls = "always"),
                    onChange = {},
                    manufacturer = "samsung",
                    fileOf = { _, id ->
                        com.echoledger.mobile.calls.PhoneAppRecordings.MediaFile(
                            id,
                            "Call recording Omar Khan_261116_094215.m4a",
                            "Recordings/Call/",
                            tenFortyTwo - 3_600_000,
                            125_000
                        )
                    }
                )
            }
        }
        pumpUntil("the guide") { runCatching { compose.onNodeWithText("Import this recording").assertExists() }.isSuccess }
        compose.onNodeWithText("Phone app → ⋮ → Settings → Record calls → Auto record calls → All calls.").assertExists()
        compose.onNodeWithText("Open the Phone app's settings").assertExists()
        // Each waiting call's buttons say which call they are for (TalkBack).
        compose.onNodeWithContentDescription("Import the recording of your call at 09:42").assertExists()
        compose.onNodeWithContentDescription("Not the recording of your call at 09:42").assertExists()
        capture("settings-phone-app")

        // "Import this recording": the file's name and length, and Play, before anything is taken in (v1.8 0.3 review).
        compose.onNodeWithText("Import this recording").performScrollTo().performClick() // below the fold with large text
        pumpUntil("the question with the file") {
            runCatching { compose.onNodeWithText("Call recording Omar Khan_261116_094215.m4a").assertExists() }.isSuccess
        }
        compose.onNodeWithText("2:05").assertExists()
        compose.onNodeWithContentDescription("Play the recording of your call at 09:42").assertExists()
        captureScreenPair("settings-phone-app-import", 500)
    }

    /** A capture helper that answers players() with [json] (the Audio probe's screenshots). */
    private class PlayersHelper(private val json: String) : com.echoledger.mobile.capture.ICaptureHelper.Stub() {
        override fun version() = com.echoledger.mobile.BuildConfig.HELPER_BUILD
        override fun uid() = 2000
        override fun capabilities() = ""
        override fun startCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true
        override fun startPhoneCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true
        override fun arm(on: Boolean): String = "armed"
        override fun setCallApps(packages: Array<out String>?) = Unit
        override fun selfTest(): String = "ok"
        override fun captureChannels(): Int = 2
        override fun players(): String = json
        override fun armApps(uids: IntArray?, voiceChat: Boolean): String = "armed"
        override fun startAppCapture(sink: android.os.ParcelFileDescriptor?, uid: Int, usage: Int, voiceChat: Boolean): Boolean = false
        override fun stopAppCapture() = Unit
        override fun stopCapture() = Unit
        override fun setCallListener(listener: com.echoledger.mobile.capture.ICallListener?) = Unit
        override fun destroy() = Unit
    }

    /**
     * Settings → Help & diagnostics → Audio probe (v1.8, the Android 17 report): each player with what the helper read
     * of it (uid, state, usage), and one whose app the helper could not name listed apart instead of dropped.
     */
    @Test
    fun audioProbe() {
        app.graph.helper.attach(
            PlayersHelper(
                """[{"uid":10133,"uid_from":"text","packages":["com.samsung.android.video"],"usage":1,"content_type":3,
                    "capture_policy":1,"state":2,"state_from":"text","piid":2215},
                   {"uid":-1,"uid_from":"none","packages":[],"usage":1,"content_type":2,"capture_policy":1,"state":-1,
                    "state_from":"none","piid":2216}]"""
            ),
            source = "adb"
        )
        shoot { AudioProbeSheet(vm, helperReady = true, onDismiss = {}) }
        pumpUntil("the players") { runCatching { compose.onNodeWithText("An app the helper can't name").assertExists() }.isSuccess }
        compose.onNodeWithText("Media · film · allows capture · uid 10133 · started").assertExists()
        compose.onNodeWithText("uid unknown (none) · state unknown (none) · Media").assertExists()
        captureScreenPair("audio-probe", 500)
    }

    /** On a phone with no known call folder (a Pixel: Google's Phone app keeps its recordings), one plain line. */
    @Test
    fun phoneAppRecordingsElsewhere() {
        shoot {
            Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp)) {
                PhoneAppRecordingsBlock(AppSettings(phoneCalls = "always"), onChange = {}, manufacturer = "Google")
            }
        }
        compose.onNodeWithText(
            "On this phone the Phone app keeps its call recordings to itself, so EchoLedger can't bring them in."
        ).assertExists()
        compose.onNodeWithText("Import the Phone app's recordings").assertDoesNotExist()
        capture("settings-phone-app-pixel")
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
        pumpUntil("the waiting card") { runCatching { compose.onNodeWithText("Review").assertExists() }.isSuccess }
        capture("record-calls")
    }

    @Test
    fun waiting() {
        runBlocking { app.pairLocally(server) }
        app.storeRecording(id = "call-1", state = "pending_decision", durationMs = 125_000)
        app.storeRecording(id = "call-2", state = "pending_decision", durationMs = 36_000, startedAt = "2026-10-23T18:30:00Z")
        shoot { WaitingScreen(vm, onBack = {}) }
        pumpUntil("the waiting calls") {
            val saveAll = app.getString(com.echoledger.mobile.R.string.cc_save_audio_all)
            runCatching { compose.onNodeWithText(saveAll).assertExists() }.isSuccess
        }
        capture("waiting")
    }

    @Test
    fun newSheet() {
        shoot {
            RecordSheet(
                Capabilities(34, true, micPermission = true, notificationPermission = true, overlayPermission = true),
                autoRecordOn = true,
                onPick = {},
                onDismiss = {}
            )
        }
        pumpUntil("the sheet") { runCatching { compose.onNodeWithText("New").assertExists() }.isSuccess }
        compose.mainClock.advanceTimeBy(1_000)
        capture("new-sheet")
    }

    /** The open recording controls with Hide EchoLedger windows named (a long-press), and the grey dot it leaves. */
    @Test
    fun floatingWindowsHidden() {
        shoot {
            androidx.compose.foundation.layout.Column(
                Modifier.padding(16.dp),
                verticalArrangement = androidx.compose.foundation.layout.Arrangement.spacedBy(16.dp)
            ) {
                PillToolbar(PillState(mode = PillState.Mode.MENU, elapsedMs = 134_000, language = "UR"))
                PrivateDot(onShow = {})
            }
        }
        compose.onNodeWithContentDescription("Hide EchoLedger windows").performTouchInput { longClick() }
        compose.onNodeWithText("Hide EchoLedger windows").assertExists()
        capture("floating-hidden")
    }

    @Test
    fun pill() {
        shoot {
            // Scrolls, so that on a small phone the last card is not squeezed into what is left of the screen (F2 sweep).
            androidx.compose.foundation.layout.Column(
                Modifier.verticalScroll(androidx.compose.foundation.rememberScrollState()).padding(16.dp),
                verticalArrangement = androidx.compose.foundation.layout.Arrangement.spacedBy(16.dp)
            ) {
                PillView(PillState(elapsedMs = 134_000))
                PillView(PillState(elapsedMs = 134_000, paused = true, warning = "Not hearing the other side"))
                PillDot(PillState(mode = PillState.Mode.DOT))
                PillToolbar(PillState(mode = PillState.Mode.MENU, elapsedMs = 134_000, language = "UR"))
                PillToolbar(PillState(mode = PillState.Mode.MENU, elapsedMs = 134_000, stopArmedTicks = 2))
                // Since 2.0.5 (the call-choice plan): a call's menu starts with its three choices.
                PillToolbar(PillState(mode = PillState.Mode.MENU, elapsedMs = 134_000, call = true, choice = "audio"))
                // The after-call card and two of its lines (CallChoiceCardTest has every size and language).
                com.echoledger.mobile.ui.v2.theme.EchoV2Theme(darkTheme = themeMode == "dark") {
                    listOf(
                        com.echoledger.mobile.ui.v2.live.CallCardScreen.Ask("Omar Khan", 252_000, "whatsapp"),
                        com.echoledger.mobile.ui.v2.live.CallCardScreen.Done(com.echoledger.mobile.calls.CallChoice.Pick.AUDIO),
                        com.echoledger.mobile.ui.v2.live.CallCardScreen.Done(com.echoledger.mobile.calls.CallChoice.Pick.DELETE)
                    ).forEach { screen ->
                        com.echoledger.mobile.ui.v2.live.CallChoiceCard(screen, {}, {}, {}, {}, still = true)
                    }
                }
            }
        }
        capture("pill")
    }

    /** Sizes of their own (twice the text, a fixed width) or the gallery's samples: not part of the sweep's conditions. */
    private fun skipInSweep() = assumeTrue("its own size, not a sweep condition", sweep == null)

    /** Captures every window (the screen with its menu, sheet or dialog) in the dark and then the light theme. */
    private fun captureWindows(name: String) {
        sweep?.let {
            themeMode = "light"
            compose.waitForIdle()
            compose.mainClock.advanceTimeBy(1_000)
            compose.waitForIdle()
            com.github.takahirom.roborazzi.captureScreenRoboImage(File(outDir, "phone-$name.png"), SweepCondition.RECORD)
            it.assertTextWhole(compose, name)
            return
        }
        listOf("dark", "light").forEach { mode ->
            themeMode = mode
            compose.waitForIdle()
            compose.mainClock.advanceTimeBy(1_000)
            compose.waitForIdle()
            com.github.takahirom.roborazzi.captureScreenRoboImage(File(outDir, "phone-$name-$mode.png"))
        }
    }

    /**
     * The whole screen in the dark and then the light theme, [stepMs] apart, as these shots have always been taken; in the
     * sweep as every window is ([captureWindows]: light only, kept, and its text checked).
     */
    private fun captureScreenPair(name: String, stepMs: Long) {
        if (sweep != null) return captureWindows(name)
        listOf("dark", "light").forEach { mode ->
            themeMode = mode
            compose.mainClock.advanceTimeBy(stepMs)
            compose.waitForIdle()
            com.github.takahirom.roborazzi.captureScreenRoboImage(File(outDir, "phone-$name-$mode.png"))
        }
    }

    private fun exists(text: String) = compose.onAllNodesWithText(text).fetchSemanticsNodes().isNotEmpty()

    /**
     * Whether the Library shows its uploads; if not yet, the list goes back to its top. On a short screen (split screen) a
     * list position kept from an earlier screen can leave the uploads above the fold, where they are never drawn.
     */
    private fun uploadsShown(): Boolean = exists("Call with Sara").also { shown ->
        if (!shown) runCatching { compose.onNode(hasScrollToIndexAction()).performScrollToIndex(0) }
    }

    /** Nothing heard: its Delete asks the same question as the ⋮ menu's (P-007). */
    @Test
    fun sessionNoAudioDelete() = sessionState(
        "session-no-audio-delete",
        ServerFixtures.transcript(status = "failed", errorCode = "no_audio", error = "x", segments = "[]", text = null),
        "Nothing was heard"
    ) { compose.onNodeWithText("Delete").performClick() }

    /** An empty Library still shows what is uploading (P-077): the upload is the phone's only copy. */
    @Test
    fun libraryEmptyUploads() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        runBlocking { app.graph.library.refresh() }
        app.storeRecording(id = "f1", state = "failed", sourceApp = "phone_call", startedAt = "2026-10-24T10:05:00Z")
        app.graph.recordings.update("f1") { it.copy(title = "Call with Sara") }
        shoot { LibraryScreen(vm, readiness = ReadinessFixtures.READY, onReady = {}, onOpen = {}) }
        pumpUntil("the upload") { uploadsShown() }
        capture("library-empty-uploads")
    }

    /** Clear history asks first: the dictations are kept on this phone only (P-008). */
    @Test
    fun dictationClearHistory() {
        val history = com.echoledger.mobile.dictation.DictationHistory(File(app.noBackupFilesDir, "dictation_history.json"))
        history.add("2026-10-24T09:00:00Z", "Send the second invoice to Aria Studio today.")
        shoot { HistorySection(history) }
        compose.onNodeWithText("Clear history").performClick()
        compose.onNodeWithText(CLEAR_HISTORY_QUESTION).assertExists()
        captureWindows("dictation-clear-history")
    }

    /** Remove key asks first: on the Free plan nothing new is transcribed without it (P-009). */
    @Test
    fun transcriptionKeyRemove() {
        val plan = com.echoledger.mobile.core.PlanState(
            transcriptionKey = com.echoledger.mobile.data.TranscriptionKeyDto(set = true, last4 = "3f9a")
        )
        shoot { TranscriptionKeySection(vm, plan) }
        compose.onNodeWithText("Remove key").performClick()
        compose.onNodeWithText(REMOVE_KEY_QUESTION).assertExists()
        captureWindows("transcription-key-remove")
    }

    /** Two recordings on their way to the server: one waiting, one whose upload failed. */
    private fun seedUploads() {
        seedLibrary()
        app.storeRecording(id = "q1", state = "queued", startedAt = "2026-10-24T10:20:00Z", durationMs = 272_000)
        app.graph.recordings.update("q1") { it.copy(title = "Call with Omar") }
        app.storeRecording(id = "f1", state = "failed", sourceApp = "phone_call", startedAt = "2026-10-24T10:05:00Z")
        app.graph.recordings.update("f1") { it.copy(title = "Call with Sara") }
    }

    /** Uploads in the Library (since v1.8.1, P-002): no "hold to cancel"; the ⋮ (or a long press) has Retry and Delete. */
    @Test
    fun libraryUploads() {
        seedUploads()
        shoot { LibraryScreen(vm, readiness = ReadinessFixtures.READY, onReady = {}, onOpen = {}) }
        pumpUntil("the uploads") { uploadsShown() }
        compose.onNodeWithContentDescription("More for Call with Sara").performClick()
        compose.onNodeWithText("Delete recording…").assertExists()
        captureWindows("library-uploads")
    }

    /** Delete on an upload asks first: it was never uploaded, so the phone has the only copy (P-002, P-004). */
    @Test
    fun libraryUploadDelete() {
        seedUploads()
        shoot { LibraryScreen(vm, readiness = ReadinessFixtures.READY, onReady = {}, onOpen = {}) }
        pumpUntil("the uploads") { uploadsShown() }
        compose.onNodeWithContentDescription("More for Call with Sara").performClick()
        compose.onNodeWithText("Delete recording…").performClick()
        compose.onNodeWithText(DELETE_NOT_UPLOADED).assertExists()
        captureWindows("library-upload-delete")
    }

    /** A failed upload on Record: Retry, and Delete (was Discard, which read like dismissing the error) (P-004). */
    @Test
    fun recordFailedUpload() {
        runBlocking { app.pairLocally(server) }
        app.storeRecording(id = "f1", state = "failed", sourceApp = "whatsapp_business", startedAt = "2026-10-24T14:05:00Z")
        compose.mainClock.autoAdvance = false
        shoot {
            RecordScreen(
                vm,
                Capabilities(34, true, micPermission = true, notificationPermission = true, overlayPermission = true),
                onSetup = {}
            )
        }
        pumpUntil("the failed upload") {
            compose.mainClock.advanceTimeByFrame()
            exists("1 recording(s) could not be uploaded")
        }
        // The clock runs again for the scroll: on a short screen (split screen) Delete is below the fold, and a scroll
        // that animates on a stopped clock never ends.
        compose.mainClock.autoAdvance = true
        compose.onNodeWithText("Delete").performScrollTo()
        compose.mainClock.autoAdvance = false
        captureWindows("record-failed-upload")
    }

    /** Revoke on a share link asks first: the link stops working for everyone who has it (P-005). */
    @Test
    fun shareRevoke() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/sessions/S1/shares") {
            Thread.sleep(500) // answered once the sheet is up, as over a network
            FakeServer.json(200, """{"data":[{"id":"l1","active":true,"view_count":3,"expires_at":"2026-12-01T00:00:00Z"}]}""")
        }
        server.on("GET", "/api/sent", FakeServer.json(200, """{"data":[]}"""))
        shoot { ShareSheet(vm, sessionId = "S1", clientId = null, title = "this recording", onDismiss = {}) }
        pumpUntil("the link") {
            compose.mainClock.advanceTimeByFrame() // the sheet's own window redraws on the next frame
            exists("Revoke")
        }
        compose.onNodeWithText("Revoke").performScrollTo().performClick()
        compose.onNodeWithText(ShareText.REVOKE_QUESTION).assertExists()
        captureWindows("share-revoke")
    }

    /** Turn off on a request link asks first (P-005). */
    @Test
    fun requestLinkTurnOff() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        server.on(
            "GET",
            "/api/request-links",
            FakeServer.json(200, """{"data":[{"id":"r1","title":"Invoices from Acme","received":2,"expires_at":"2026-12-01T00:00:00Z"}]}""")
        )
        shoot { ProjectsScreen(vm, onOpenItem = {}) }
        compose.onNodeWithContentDescription("Request files").performClick()
        pumpUntil("the request link") { exists("Invoices from Acme") }
        compose.onNodeWithText("Turn off").performClick()
        compose.onNodeWithText(RequestLinkText.TURN_OFF_QUESTION).assertExists()
        captureWindows("request-links-turn-off")
    }
}
