package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.graphics.toPixelMap
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.text.font.FontFamily
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.design.ArabicNaskh
import com.echoledger.mobile.ui.design.Inter
import com.echoledger.mobile.ui.design.UrduNastaliq
import com.echoledger.mobile.ui.v2.components.EchoPlayer
import com.echoledger.mobile.ui.v2.components.EchoPlayerLabels
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Colors
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.annotation.GraphicsMode

/**
 * The recording page's transcript is in the app's bundled faces whatever the phone's font (parity.csv PH-REC-040):
 * Urdu lines in Noto Nastaliq Urdu, Arabic in Noto Naskh Arabic, the rest in Inter; and the player's track really marks
 * each bookmark where it is (PH-REC-068).
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
class V2RecordingFacesAndMarksParityTest : ScreenTest() {
    private val segments = """[
        {"channel":1,"speaker":"Me","start_ms":250,"end_ms":2600,"text":"Payment in 45 days","words":[]},
        {"channel":2,"speaker":"Client","start_ms":3000,"end_ms":5000,"text":"کل کی میٹنگ","words":[]},
        {"channel":2,"speaker":"Client","start_ms":6000,"end_ms":8000,"text":"السلام عليكم","words":[]}
    ]"""

    private fun family(words: String): FontFamily? {
        val results = mutableListOf<TextLayoutResult>()
        compose.onAllNodesWithText(words, substring = true, useUnmergedTree = true).onFirst().fetchSemanticsNode()
            .config[SemanticsActions.GetTextLayoutResult].action?.invoke(results)
        return results.first().layoutInput.style.fontFamily
    }

    @Test
    fun eachTranscriptLineIsInItsScriptsBundledFace() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript(segments = segments)))
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
            title = "Call with Acme",
            startedAt = "2026-10-20T10:00:00+00:00",
            durationMs = 2_530_000,
            status = "completed",
            transcript = TranscriptSummary(
                finalStatus = "completed",
                finalTranscriptId = ServerFixtures.TRANSCRIPT_ID,
                finalLanguage = "ur"
            ),
            updatedAt = "2026-10-20T10:05:00+00:00"
        )
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2RecordingScreen(vm, item, onBack = {})
                }
            }
        }
        pumpUntil("the lines") { compose.onAllNodesWithText("السلام عليكم", substring = true).fetchSemanticsNodes().isNotEmpty() }
        assertEquals(Inter, family("Payment in 45 days"))
        assertEquals(UrduNastaliq, family("کل کی میٹنگ"))
        assertEquals(ArabicNaskh, family("السلام عليكم"))
    }

    /** The x of each run of bookmark-coloured columns across the track, as a share of its width. */
    private fun marks(): List<Float> {
        val map = compose.onNode(hasContentDescription("Position")).captureToImage().toPixelMap()
        val mark = EchoV2Colors.Light.warning
        fun close(a: Float, b: Float) = kotlin.math.abs(a - b) < 0.08f
        val columns = (0 until map.width).filter { x ->
            (0 until map.height).any { y ->
                map[x, y].let {
                    close(it.red, mark.red) && close(it.green, mark.green) &&
                        close(it.blue, mark.blue)
                }
            }
        }
        val runs = mutableListOf<MutableList<Int>>()
        columns.forEach { x -> if (runs.lastOrNull()?.last() == x - 1) runs.last() += x else runs += mutableListOf(x) }
        return runs.map { it.average().toFloat() / map.width }
    }

    private fun player(markers: List<Long>) = show {
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            EchoPlayer(
                positionMs = 0,
                durationMs = 100_000,
                playing = false,
                speed = "1×",
                labels = EchoPlayerLabels("Play", "Pause", "Back 10 seconds", "Forward 10 seconds", "Position", "Speed"),
                onPlayPause = {},
                onSeek = {},
                onSpeed = {},
                markers = markers
            )
        }
    }

    @Test
    fun theTrackMarksEachBookmarkWhereItIs() {
        player(listOf(25_000L, 75_000L))
        compose.waitForIdle()
        val at = marks()
        assertEquals("two marks: $at", 2, at.size)
        assertTrue("the first at a quarter: $at", kotlin.math.abs(at[0] - 0.25f) < 0.06f)
        assertTrue("the second at three quarters: $at", kotlin.math.abs(at[1] - 0.75f) < 0.06f)
    }

    @Test
    fun aTrackWithoutBookmarksHasNoMarks() {
        player(emptyList())
        compose.waitForIdle()
        assertEquals(emptyList<Float>(), marks())
    }
}
