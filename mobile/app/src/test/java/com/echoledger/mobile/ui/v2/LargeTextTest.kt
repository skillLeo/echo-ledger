package com.echoledger.mobile.ui.v2

import android.app.Application
import android.content.res.Configuration
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Rect
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeUp
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.core.ReadyChip
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.AskAnswerDto
import com.echoledger.mobile.data.BinItemDto
import com.echoledger.mobile.data.CitationDto
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.ui.v2.ask.AskState
import com.echoledger.mobile.ui.v2.ask.V2AskContent
import com.echoledger.mobile.ui.v2.assist.AssistCard
import com.echoledger.mobile.ui.v2.assist.AssistFixtures
import com.echoledger.mobile.ui.v2.assist.V2AssistCard
import com.echoledger.mobile.ui.v2.components.EchoBottomBar
import com.echoledger.mobile.ui.v2.components.EchoDestination
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.home.HomeActions
import com.echoledger.mobile.ui.v2.home.HomeModel
import com.echoledger.mobile.ui.v2.home.V2HomeContent
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.library.LibraryState
import com.echoledger.mobile.ui.v2.library.TranscriptState
import com.echoledger.mobile.ui.v2.library.V2LibraryContent
import com.echoledger.mobile.ui.v2.library.V2RecordingContent
import com.echoledger.mobile.ui.v2.library.V2TrashContent
import com.echoledger.mobile.ui.v2.live.AfterCallMode
import com.echoledger.mobile.ui.v2.live.V2AfterCallCard
import com.echoledger.mobile.ui.v2.live.V2LiveTab
import com.echoledger.mobile.ui.v2.live.V2RecordingPill
import com.echoledger.mobile.ui.v2.settings.AppearancePage
import com.echoledger.mobile.ui.v2.settings.V2SettingsRoot
import com.echoledger.mobile.ui.v2.setup.V2SetupHealthContent
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.Profile
import com.echoledger.mobile.ui.v2.shell.ProfileState
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.io.File
import java.time.ZonedDateTime
import java.util.Locale
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * Every v2 screen at text size 100 %, 130 % and 200 % (Android 14's scaling), light and dark, English and Urdu. Each
 * run checks that every control can still be reached with a finger (48 dp) at 200 %. With a `build/bugbash` folder
 * present it also draws each screen, its end at 200 %, and a contact sheet per screen there (never into the repo).
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class LargeTextTest {
    @get:Rule
    val compose = createComposeRule()

    private val out = File("build/bugbash")
    private val now = ZonedDateTime.parse("2026-11-17T12:00:00+05:00[Asia/Karachi]")
    private val scales = listOf(1.0f, 1.3f, 2.0f)

    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)
    private var scale by mutableStateOf(1f)

    private fun locale(urdu: Boolean) = if (urdu) Locale.forLanguageTag("ur-PK") else Locale.UK

    private fun bash(name: String, screen: @Composable (urdu: Boolean) -> Unit) {
        compose.setContent {
            val base = LocalContext.current
            val config = LocalConfiguration.current
            val scaled = remember(scale) {
                base.createConfigurationContext(Configuration(config).apply { fontScale = scale })
            }
            CompositionLocalProvider(LocalDensity provides Density(scaled), LocalConfiguration provides scaled.resources.configuration) {
                EchoV2Theme(darkTheme = dark, language = language) {
                    CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                        screen(language == EchoLanguage.Urdu)
                    }
                }
            }
        }
        val shoot = out.isDirectory
        val small = mutableListOf<String>()
        val sheets = mutableMapOf<Boolean, MutableList<Bitmap>>()
        for (d in listOf(false, true)) {
            for (l in EchoLanguage.entries) {
                for (s in scales) {
                    dark = d
                    language = l
                    scale = s
                    compose.waitForIdle()
                    val variant = "${(s * 100).toInt()}-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}"
                    if (s == 2.0f && !d) small += tooSmall(variant)
                    if (shoot) {
                        val bitmap = compose.onRoot().captureToImage().asAndroidBitmap()
                        save(bitmap, File(out, "$name/$variant.png"))
                        sheets.getOrPut(d) { mutableListOf() } += bitmap
                        if (s == 2.0f && !d) {
                            repeat(4) { compose.onRoot().performTouchInput { swipeUp() } }
                            compose.waitForIdle()
                            save(compose.onRoot().captureToImage().asAndroidBitmap(), File(out, "$name/$variant-end.png"))
                        }
                    }
                }
            }
        }
        sheets.forEach { (d, list) -> save(sheet(list), File(out, "$name-sheet-${if (d) "dark" else "light"}.png")) }
        assertTrue("$name: controls under 48 dp at 200 %:\n" + small.joinToString("\n"), small.isEmpty())
    }

    /** Controls a finger can't hit: clickable nodes under 48 dp high or wide. */
    private fun tooSmall(variant: String): List<String> {
        val minPx = 48 * compose.density.density - 1
        val found = mutableListOf<String>()
        fun visit(node: SemanticsNode) {
            if (node.config.getOrNull(SemanticsActions.OnClick) != null) {
                // Its own size, not what the window shows of it (a row half off screen is still a whole row), or the
                // larger area a small control answers to (a 32 dp chip takes touches over 48 dp).
                val b = node.size
                val touch = node.touchBoundsInRoot
                val high = b.height >= minPx || touch.height >= minPx
                val wide = b.width >= minPx || touch.width >= minPx
                if (b.width > 0 && (!high || !wide)) {
                    val words = node.config.getOrNull(SemanticsProperties.Text)?.joinToString() ?: node.config
                        .getOrNull(SemanticsProperties.ContentDescription)?.joinToString() ?: "?"
                    found +=
                        "$variant: \"$words\" ${(b.width / compose.density.density).toInt()}x${(b.height / compose.density.density).toInt()} dp"
                }
            }
            node.children.forEach(::visit)
        }
        visit(compose.onRoot(useUnmergedTree = false).fetchSemanticsNode())
        return found
    }

    private fun save(bitmap: Bitmap, file: File) {
        file.parentFile!!.mkdirs()
        file.outputStream().use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }
    }

    /** Six shots side by side (100/130/200 %, English then Urdu), at a third of their size. */
    private fun sheet(shots: List<Bitmap>): Bitmap {
        val w = shots.first().width / 3
        val h = shots.first().height / 3
        val cols = scales.size
        val rows = (shots.size + cols - 1) / cols
        val sheet = Bitmap.createBitmap(w * cols, h * rows, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(sheet)
        shots.forEachIndexed { i, b ->
            val x = (i % cols) * w
            val y = (i / cols) * h
            canvas.drawBitmap(b, null, Rect(x, y, x + w, y + h), null)
        }
        return sheet
    }

    @Composable
    private fun Backdrop(content: @Composable () -> Unit) = Column(
        Modifier.fillMaxSize().background(EchoV2.colors.bgCanvas).verticalScroll(rememberScrollState()).padding(EchoTokens.Space.s16),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
    ) { content() }

    private val noTaps = HomeActions({}, {}, {}, {}, {}, {}, {}, {}, {})

    @Test
    fun home() = bash("home") { ur ->
        val facts = HomeFixtures.facts(
            urdu = ur,
            captureReady = false,
            chip = ReadyChip.NOT_READY,
            pending = listOf(HomeFixtures.waiting("r1", if (ur) "عمر خان" else "Omar Khan", 252_000)),
            language = null
        )
        V2HomeContent(HomeModel.state(facts), "AS", noTaps, {}, {}, {}, {}, now = now)
    }

    @Test
    fun homeRecording() = bash("home-recording") { ur ->
        val facts = HomeFixtures.facts(urdu = ur, live = LiveRecording(active = true, elapsedMs = 134_000))
        V2HomeContent(HomeModel.state(facts), "AS", noTaps, {}, {}, {}, {}, now = now)
    }

    @Test
    fun libraryWithTheBar() = bash("library") { ur ->
        val text = v2Text
        Column(Modifier.fillMaxSize()) {
            Column(Modifier.weight(1f)) {
                V2LibraryContent(
                    state = LibraryState.Ready(
                        LibraryModel.rows(V2Fixtures.library(ur), emptySet(), "", now, text, locale(ur)),
                        emptyList(),
                        offline = false,
                        anyAtAll = true
                    ),
                    query = "",
                    onQuery = {},
                    views = emptySet(),
                    onToggleView = {},
                    initials = if (ur) "ع" else "AS",
                    onOpen = {},
                    onDelete = {},
                    onRetryUpload = {},
                    onRetry = {},
                    onTrash = {},
                    onAccount = {}
                )
            }
            EchoBottomBar(
                listOf(
                    EchoDestination("home", text.home, EchoIcons.Home),
                    EchoDestination("library", text.library, EchoIcons.Library),
                    EchoDestination("live", text.live, EchoIcons.Live),
                    EchoDestination("ask", text.ask, EchoIcons.Ask)
                ),
                "library",
                {}
            )
        }
    }

    @Test
    fun recording() = bash("recording") { ur ->
        val lines = listOf(
            SegmentDto(
                2,
                "Client",
                4_000,
                10_000,
                if (ur) "السلام علیکم۔ باسمتی کے چالیس کارٹن کا نیا ریٹ بھیج دیں۔" else "Please send the new rate for forty cartons."
            ),
            SegmentDto(
                1,
                "Me",
                11_000,
                25_000,
                if (ur) "اب ریٹ 4,200 روپے فی کارٹن ہے۔" else "It is Rs 4,200 a carton now, delivered by Thursday."
            )
        )
        V2RecordingContent(
            item = V2Fixtures.library(ur).first(),
            transcript = TranscriptState.Lines(lines),
            positionMs = 221_000,
            durationMs = 728_000,
            playing = false,
            speed = 1f,
            onPlayPause = {},
            onSeek = {},
            onSpeed = {},
            onShare = {},
            onDelete = {},
            onRetry = {},
            onBack = {}
        )
    }

    @Test
    fun trash() = bash("trash") { ur ->
        V2TrashContent(
            items = listOf(
                BinItemDto(
                    "01JX",
                    if (ur) "کراچی کارگو سروسز" else "Karachi Cargo Services",
                    deletedAt = "2026-11-17T05:00:00Z",
                    eraseAt = "2026-11-19T05:00:00Z"
                ),
                BinItemDto(
                    "01JY",
                    if (ur) "وائس نوٹ" else "Voice note",
                    deletedAt = "2026-11-16T08:30:00Z",
                    eraseAt = "2026-11-18T08:30:00Z"
                )
            ),
            keepHours = 48,
            onBack = {},
            onRestore = {},
            onErase = {}
        )
    }

    private fun profile(ur: Boolean) =
        Profile(if (ur) "عائشہ صدیقی" else "Ayesha Siddiqui", "ayesha@bilaltraders.pk", "Starter", 212, 300, 1_240_000_000, 318)

    @Test
    fun settings() = bash("settings") { ur ->
        V2SettingsRoot(
            settings = AppSettings(themeMode = "system", quietHours = true),
            profile = ProfileState.Ready(profile(ur)),
            signedIn = true,
            onUpgrade = {},
            onRetryProfile = {},
            onOpen = { _, _ -> },
            onSignOut = {},
            onBack = {}
        )
    }

    @Test
    fun settingsAppearance() = bash("settings-appearance") { _ ->
        AppearancePage(AppSettings(), {}, UiFlags(mapOf("ui_v2.shell" to true)), {}, null, {})
    }

    @Test
    fun setup() = bash("setup") { _ ->
        V2SetupHealthContent(SetupHealthFixtures.afterRestart, null, {}, {}, {}, {})
    }

    @Test
    fun liveTab() = bash("live-tab") { _ -> V2LiveTab(liveAssist = true, liveTranslate = true, onOpen = {}) }

    @Test
    fun assistCard() = bash("assist") { _ ->
        Backdrop { V2AssistCard(AssistCard.reduce(AssistFixtures.clientCall), true, {}, {}, {}) }
    }

    @Test
    fun ask() = bash("ask") { ur ->
        val answer = AskAnswerDto(
            "Bilal Traders agreed to pay in 45 days at Rs 4,200 a carton, and asked for their PO number on every invoice.",
            listOf(
                CitationDto("session", sessionId = "01JA", title = "Bilal Traders", atMs = 26_000, quote = "Payment in 45 days as usual.")
            )
        )
        V2AskContent(
            listOf(ClientDto("01JC1", "Bilal Traders")),
            "01JC1",
            {},
            if (ur) "بلال ٹریڈرز نے ادائیگی پر کیا طے کیا؟" else "What did Bilal Traders agree on payment?",
            {},
            AskState.Answer(answer),
            {},
            { _, _ -> }
        )
    }

    @Test
    fun pillAndAfterCall() = bash("pill-after-call") { ur ->
        val name = if (ur) "عمر خان" else "Omar Khan"
        Backdrop {
            V2RecordingPill(PillState(elapsedMs = 134_000), 0, name, {})
            V2RecordingPill(PillState(mode = PillState.Mode.MENU, elapsedMs = 134_000, language = "UR+EN"), 0, name, {})
            V2AfterCallCard(
                title = if (ur) "عمر خان سے کال" else "Call with Omar Khan",
                durationMs = 252_000,
                sourceWire = "whatsapp",
                mode = AfterCallMode.Ask,
                visibleMs = 10_000,
                onSave = {},
                onDelete = {},
                onUndo = {},
                onTitle = {},
                onTranscribe = {},
                countdown = false
            )
        }
    }
}
