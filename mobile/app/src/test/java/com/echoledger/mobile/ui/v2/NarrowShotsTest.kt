package com.echoledger.mobile.ui.v2

import android.content.res.Configuration
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Rect
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.R
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.ui.v2.activity.ActivityActions
import com.echoledger.mobile.ui.v2.activity.V2ActivityContent
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.V2SettingsRoot
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.Profile
import com.echoledger.mobile.ui.v2.shell.ProfileState
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.io.File
import java.time.LocalDate
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * The shell's screens on the narrowest phone (360 dp) at text size 100 %, 130 % and 200 %, in English and Urdu, light
 * and dark: Library with the bar, Library while recording (the strip), the Record sheet, Activity and Settings. Written
 * as one contact sheet per screen and theme (rows English and Urdu, columns the text sizes) to
 * docs/v2/parity/phone/narrow/ when recording (`-Proborazzi.test.record=true`), otherwise only drawn.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h780dp-420dpi")
class NarrowShotsTest : ScreenTest() {
    private val out =
        File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/parity/phone/narrow")
    private val record = System.getProperty("roborazzi.test.record") == "true"
    private val scales = listOf(1f, 1.3f, 2f)

    private var scale by mutableFloatStateOf(1f)
    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)

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
                        ServerFixtures.libraryItem(
                            "01JB",
                            title = "Rehman and Sons Textiles",
                            sourceApp = "phone_call",
                            finalStatus = null
                        ),
                        ServerFixtures.libraryItem("01JC", title = "Karachi Cargo", sourceApp = "google_meet", client = null)
                    )
                )
            )
        )
        server.on("GET", "/api/activity", FakeServer.json(200, """{"data": [], "unread_count": 0}"""))
        runBlocking { app.graph.library.refresh() }
        // The setup card put away, so the rows show on the short screen (nav-library-* has the card).
        vm.updateSettings { it.copy(setupCardHidden = true) }
        com.echoledger.mobile.testsupport.pumpUntil("hidden") { vm.settings.value.setupCardHidden }
    }

    @After
    fun noRecording() {
        RecordingService.live.value = LiveRecording()
    }

    private val stub = ClassicScreens(
        home = { Text("") },
        library = { Text("") },
        live = { Text("") },
        ask = { Text("") },
        settings = { Text("") },
        upgrade = {}
    )

    private val flags = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "ui_v2.live" to true, "ui_v2.ask" to true))

    /** Draws [screen] in every variant, [each] after each change; saves a sheet per theme. Returns the variants drawn. */
    private fun shoot(name: String, each: () -> Unit = {}, screen: @Composable () -> Unit): List<String> {
        compose.setContent {
            val base = LocalContext.current
            val config = LocalConfiguration.current
            val scaled = remember(scale) { base.createConfigurationContext(Configuration(config).apply { fontScale = scale }) }
            CompositionLocalProvider(LocalDensity provides Density(scaled), LocalConfiguration provides scaled.resources.configuration) {
                screen()
            }
        }
        val drawn = mutableListOf<String>()
        for (d in listOf(false, true)) {
            val shots = mutableListOf<Bitmap>()
            for (l in EchoLanguage.entries) {
                for (s in scales) {
                    dark = d
                    language = l
                    scale = s
                    compose.waitForIdle()
                    each()
                    compose.waitForIdle()
                    shots += compose.onRoot().captureToImage().asAndroidBitmap()
                    drawn += "${(s * 100).toInt()}-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}"
                }
            }
            if (record) save(sheet(shots), File(out, "$name-${if (d) "dark" else "light"}.png"))
        }
        return drawn
    }

    private val all = listOf(false, true).flatMap { d ->
        listOf("en", "ur").flatMap { l -> listOf(100, 130, 200).map { "$it-${if (d) "dark" else "light"}-$l" } }
    }

    @Composable
    private fun shell() = EchoV2Shell(vm, flags, darkTheme = dark, classic = stub, onSwitchToClassic = {}, language = language)

    private fun rows() = compose.waitUntil(5_000) {
        // At 200 % the first row can sit below the fold: the page's title is enough.
        listOf(EchoLanguage.English, EchoLanguage.Urdu).any { l ->
            compose.onAllNodesWithText(V2Testing.resources(l).getString(R.string.v2_library)).fetchSemanticsNodes().isNotEmpty()
        } && vm.library.value.isNotEmpty()
    }

    @Test
    fun libraryWithTheBar() = assertEquals(all, shoot("library", ::rows) { shell() })

    @Test
    fun libraryWhileRecording() {
        RecordingService.live.value = LiveRecording(active = true, elapsedMs = 134_000)
        assertEquals(all, shoot("library-recording", ::rows) { shell() })
    }

    @Test
    fun theRecordSheet() = assertEquals(
        all,
        shoot("record-sheet", {
            rows()
            val title = listOf(EchoLanguage.English, EchoLanguage.Urdu).map { V2Testing.resources(it).getString(R.string.v2_rs_title) }
            val open = title.any { compose.onAllNodesWithText(it).fetchSemanticsNodes().isNotEmpty() }
            if (!open) {
                val names = listOf(EchoLanguage.English, EchoLanguage.Urdu).map {
                    V2Testing.resources(it).getString(R.string.v2_nav_record)
                }
                compose.onAllNodes(hasContentDescription(names[0]) or hasContentDescription(names[1])).onFirst()
                    .performTouchInput { longClick() }
            }
        }) { shell() }
    )

    private val answer = ActivityResponse(
        listOf(
            ActivityItemDto(
                "a1",
                "upload_failed",
                "Upload failed — retry",
                "Rehman and Sons Textiles",
                "01JB",
                false,
                "2026-11-21T09:00:00Z"
            ),
            ActivityItemDto("a2", "transcript_ready", "Transcript ready", "Bilal Traders", "01JA", false, "2026-11-21T07:00:00Z"),
            ActivityItemDto(
                "a3",
                "device_paired",
                "Pixel 9 signed in",
                "It can now record, sync and see the library.",
                null,
                true,
                "2026-11-10T07:00:00Z"
            )
        ),
        unreadCount = 2
    )

    @Test
    fun activity() = assertEquals(
        all,
        shoot("activity") {
            EchoV2Theme(darkTheme = dark, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2ActivityContent(
                        load = { answer },
                        markRead = { null },
                        waiting = listOf(HomeFixtures.waiting("r-a", "Omar", 60_000)),
                        failedUploads = emptyList(),
                        untranscribed = 0,
                        failedTranscripts = 0,
                        skipped = emptyList(),
                        missed = emptyList(),
                        actions = ActivityActions(onOpenRecording = {}, onFix = {}, onOpenUntranscribed = {}),
                        onKeep = {},
                        onDiscard = {},
                        onRetryUploads = {},
                        onRestore = {},
                        onMissedRead = {},
                        onMissedDismiss = {},
                        today = LocalDate.parse("2026-11-21")
                    )
                }
            }
        }
    )

    @Test
    fun settings() {
        vm.updateSettings { it.copy(plan = it.plan.copy(usedMinutes = 212, monthlyMinutes = 300)) }
        assertEquals(
            all,
            shoot("settings") {
                val settings by vm.settings.collectAsState()
                EchoV2Theme(darkTheme = dark, language = language) {
                    CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                        V2SettingsRoot(
                            settings = settings,
                            profile = ProfileState.Ready(
                                Profile("Asad Shah", "asad@bilaltraders.pk", "Starter", 212, 300, 1_240_000_000, 318)
                            ),
                            signedIn = true,
                            onUpgrade = {},
                            onRetryProfile = {},
                            onOpen = { _, _ -> },
                            onSignOut = {},
                            onBack = {}
                        )
                    }
                }
            }
        )
    }

    /** The shots in a grid, one row per language (each text size a column), at a third of their size. */
    private fun sheet(shots: List<Bitmap>): Bitmap {
        val w = shots.first().width / 3
        val h = shots.first().height / 3
        val cols = scales.size
        val rows = (shots.size + cols - 1) / cols
        val gap = 6
        val sheet = Bitmap.createBitmap(w * cols + gap * (cols - 1), h * rows + gap * (rows - 1), Bitmap.Config.ARGB_8888)
        val canvas = Canvas(sheet)
        canvas.drawColor(android.graphics.Color.GRAY)
        shots.forEachIndexed { i, b ->
            val x = (i % cols) * (w + gap)
            val y = (i / cols) * (h + gap)
            canvas.drawBitmap(b, null, Rect(x, y, x + w, y + h), null)
        }
        return sheet
    }

    private fun save(bitmap: Bitmap, file: File) {
        file.parentFile!!.mkdirs()
        file.outputStream().use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }
    }
}
