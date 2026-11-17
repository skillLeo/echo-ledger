package com.echoledger.mobile.ui.v2

import android.app.Application
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasScrollToNodeAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performScrollToIndex
import androidx.compose.ui.test.performScrollToNode
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.library.LibraryState
import com.echoledger.mobile.ui.v2.library.LocalScreenPictures
import com.echoledger.mobile.ui.v2.library.SaidLine
import com.echoledger.mobile.ui.v2.library.ScreenAssets
import com.echoledger.mobile.ui.v2.library.TranscriptState
import com.echoledger.mobile.ui.v2.library.V2LibraryContent
import com.echoledger.mobile.ui.v2.library.V2RecordingContent
import com.echoledger.mobile.ui.v2.library.V2ScreenViewer
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import java.time.ZonedDateTime
import java.util.Locale
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** Meeting assets on the phone (v1.8 P4): names and what was said, the Screens strip, the viewer, the Library count. */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class ScreensTest {
    @get:Rule
    val compose = createComposeRule()

    private val outDir =
        File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/app")
    private val now = ZonedDateTime.parse("2026-11-17T12:00:00+05:00[Asia/Karachi]")

    private fun screen(
        id: String,
        index: Int,
        atMs: Long,
        description: String?,
        ocr: String? = null,
        url: String = "https://x/s/$id.webp"
    ) = ScreenDto(id, index, atMs, 1600, 900, "change", ocr, description, url, url)

    private val screens = listOf(
        screen("01JS1", 1, 26_000, "The client's purchase order PO-2291 with the rate column", "PO-2291 Basmati 40 cartons Rs 4,200"),
        screen("01JS2", 2, 128_000, "The delivery schedule, Thursday highlighted")
    )

    private val lines = listOf(
        SegmentDto(2, "Client", 4_000, 10_000, "Please send the new rate for forty cartons."),
        SegmentDto(1, "Me", 20_000, 30_000, "It is Rs 4,200 a carton, on the PO you shared."),
        SegmentDto(2, "Client", 38_000, 44_000, "Fine, payment in 45 days."),
        SegmentDto(1, "Me", 125_000, 131_000, "Delivery is Thursday, as on this schedule.")
    )

    /** A drawn stand-in for a screen: a page with a header bar and rows, in the screen's shape. */
    private val pictures: suspend (String) -> ImageBitmap? = {
        val bitmap = Bitmap.createBitmap(480, 270, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap)
        canvas.drawColor(0xFFFFFFFF.toInt())
        val paint = Paint()
        paint.color = 0xFF1F6F6B.toInt()
        canvas.drawRect(0f, 0f, 480f, 40f, paint)
        paint.color = 0xFFD9DDDC.toInt()
        for (row in 0 until 6) canvas.drawRect(24f, 64f + row * 30f, if (row % 2 == 0) 456f else 320f, 80f + row * 30f, paint)
        bitmap.asImageBitmap()
    }

    private fun show(language: EchoLanguage = EchoLanguage.English, dark: Boolean = false, content: @Composable () -> Unit) =
        compose.setContent {
            EchoV2Theme(darkTheme = dark, language = language) {
                CompositionLocalProvider(LocalScreenPictures provides pictures, LocalEchoToasts provides rememberEchoToastState()) {
                    content()
                }
            }
        }

    @Test
    fun aScreensFileNameIsItsNumberTimeAndASlug() {
        assertEquals("S01_0-26_clients-purchase-order-po.webp", ScreenAssets.fileName(screens[0]))
        assertEquals(
            "S07_14-32_orders-page.png",
            ScreenAssets.fileName(screen("x", 7, 872_000, "The orders page", url = "https://x/a.png?sig=1"))
        )
        assertEquals("S120_1-00-00_screen.jpg", ScreenAssets.fileName(screen("x", 120, 3_600_000, null, url = "https://x/a.jpeg")))
        // The server's name wins once it sends one (web P4).
        assertEquals("S01_00-26_po.webp", ScreenAssets.fileName(screens[0], serverName = "S01_00-26_po.webp"))
        assertEquals("screen", ScreenAssets.slug("   "))
    }

    @Test
    fun whatWasSaidIsTheLinesWithin15SecondsOfTheMoment() {
        val said = ScreenAssets.said(screens[0], lines)
        // 0:26 ± 15 s: the line ending at 0:10 is out, the ones from 0:20 and 0:38 are in.
        assertEquals(listOf(20_000L, 38_000L), said.map { it.atMs })
        assertEquals(listOf(125_000L), ScreenAssets.said(screens[1], lines).map { it.atMs })
        val server = listOf(SaidLine("Client", 1_000, "From the server"))
        assertEquals(server, ScreenAssets.said(screens[0], lines, serverSaid = server))
        // Screens go after the last line that started at or before them.
        assertEquals(mapOf(1 to listOf(screens[0]), 3 to listOf(screens[1])), ScreenAssets.byLine(lines, screens))
    }

    @Test
    fun theRecordingPageHasAScreensStripAndTheScreensInTheTranscript() {
        val opened = mutableListOf<Int>()
        show { Recording(opened) }
        compose.onNodeWithText("Screens").assertExists()
        compose.onNodeWithText("2 screens").assertExists()
        // In the strip, and again after the line it follows.
        compose.onAllNodes(hasScrollToNodeAction()).onFirst().performScrollToNode(hasText("Delivery is Thursday, as on this schedule."))
        compose.onAllNodesWithText("Screen 2 · 2:08", substring = true).assertCountEquals(2)
        compose.onAllNodes(hasScrollToNodeAction()).onFirst().performScrollToIndex(0)
        // The strip's tile (the first of the two).
        compose.onAllNodesWithContentDescription("Screen 1 · 0:26. The client's purchase order PO-2291 with the rate column")
            .onFirst()
            .performClick()
        assertEquals(listOf(0), opened)
    }

    @Test
    fun theViewerShowsWhatWasSaidPlaysFromItsMomentAndMovesOn() {
        val played = mutableListOf<Long>()
        var closed = false
        show { V2ScreenViewer(ScreenAssets.moments(screens, lines), 0, { played += it }, { closed = true }) }
        compose.onNodeWithText("1 of 2").assertExists()
        compose.onNodeWithText("What was said").assertExists()
        compose.onNodeWithText("It is Rs 4,200 a carton, on the PO you shared.").assertExists()
        compose.onNodeWithText("PO-2291 Basmati 40 cartons Rs 4,200").performScrollTo().assertExists()
        compose.onNodeWithText("S01_0-26_clients-purchase-order-po.webp").performScrollTo().assertExists()
        compose.onNodeWithText("Play from here").performScrollTo().performClick()
        assertEquals(listOf(26_000L), played)
        compose.onNodeWithContentDescription("Previous screen").assertIsNotEnabled()
        compose.onNodeWithContentDescription("Next screen").performClick()
        compose.onNodeWithText("2 of 2").assertExists()
        compose.onNodeWithText("Delivery is Thursday, as on this schedule.").assertExists()
        compose.onNodeWithText("Fine, payment in 45 days.").assertDoesNotExist()
        compose.onNodeWithContentDescription("Next screen").assertIsNotEnabled()
        assertEquals(false, closed)
    }

    @Test
    fun aScreenWithNothingSaidAroundItSaysSo() {
        val alone = listOf(screen("01JS9", 1, 600_000, "A slide"))
        show { V2ScreenViewer(ScreenAssets.moments(alone, lines), 0, {}, {}) }
        compose.onNodeWithText("Nothing was said in the 15 seconds around it.").assertExists()
    }

    @Test
    fun aLibraryRowShowsHowManyScreensItHas() {
        show { Library() }
        compose.onNodeWithContentDescription("14 screens", useUnmergedTree = true).assertExists()
        compose.onNodeWithContentDescription("6 screens", useUnmergedTree = true).performScrollTo().assertExists()
    }

    @Composable
    private fun Recording(opened: MutableList<Int> = mutableListOf(), urdu: Boolean = false) {
        V2RecordingContent(
            item = V2Fixtures.library(urdu).first(),
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
            onOpenScreen = { opened += it }
        )
    }

    @Composable
    private fun Library(urdu: Boolean = false) {
        val locale = if (urdu) Locale.forLanguageTag("ur-PK") else Locale.UK
        V2LibraryContent(
            state = LibraryState.Ready(
                LibraryModel.rows(V2Fixtures.library(urdu), emptySet(), "", now, v2Text, locale),
                emptyList(),
                false,
                true
            ),
            query = "",
            onQuery = {},
            views = emptySet(),
            onToggleView = {},
            initials = "AS",
            onOpen = {},
            onDelete = {},
            onRetryUpload = {},
            onRetry = {},
            onTrash = {},
            onAccount = {}
        )
    }

    @Test
    fun screenshots() {
        var language by mutableStateOf(EchoLanguage.English)
        var dark by mutableStateOf(false)
        var which by mutableStateOf("recording-screens")
        show {
            EchoV2Theme(darkTheme = dark, language = language) {
                CompositionLocalProvider(LocalScreenPictures provides pictures) {
                    when (which) {
                        "recording-screens" -> Recording(urdu = language == EchoLanguage.Urdu)
                        else -> V2ScreenViewer(ScreenAssets.moments(screens, lines), 0, {}, {})
                    }
                }
            }
        }
        for (name in listOf("recording-screens", "screen-viewer")) {
            for (l in EchoLanguage.entries) {
                for (d in listOf(false, true)) {
                    which = name
                    language = l
                    dark = d
                    compose.waitForIdle()
                    val file = "$name-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png"
                    compose.onRoot().captureRoboImage(File(outDir, file))
                }
            }
        }
    }
}
