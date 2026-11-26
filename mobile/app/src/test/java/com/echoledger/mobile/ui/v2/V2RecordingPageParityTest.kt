package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.click
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasScrollToIndexAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.percentOffset
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performScrollToIndex
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.EchoPlayer
import com.echoledger.mobile.ui.v2.components.EchoPlayerLabels
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The v2 recording page against classic's (docs/v2/audit/parity.csv PH-REC): the title in the bar once the page scrolls,
 * the file's size in Details, and the scrubber's tap and drag (left to right in every language, as media never mirrors).
 */
class V2RecordingPageParityTest : ScreenTest() {
    private fun pair(audio: Boolean = false) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        val many = (0 until 40).joinToString(",") { i ->
            """{"channel":${1 + i % 2},"speaker":"${if (i % 2 == 0) "Me" else "Client"}","start_ms":${i * 5000},"end_ms":${i * 5000 + 4000},"text":"Line number $i of the call.","words":[]}"""
        }
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript(segments = "[$many]")))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, if (audio) ServerFixtures.sessionDetail() else ServerFixtures.sessionDetail(playbackUrl = null))
        )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
    }

    private val item = LibraryItem(
        id = ServerFixtures.SESSION_ID,
        sourceDevice = "phone",
        sourceApp = "whatsapp",
        title = "Call with Acme",
        client = NamedRef("01JCLIENT0000000000000000A", "Acme Holdings"),
        startedAt = "2026-10-20T10:00:00+00:00",
        durationMs = 2_530_000,
        status = "completed",
        transcript = TranscriptSummary(finalStatus = "completed", finalTranscriptId = ServerFixtures.TRANSCRIPT_ID, finalLanguage = "en"),
        updatedAt = "2026-10-20T10:05:00+00:00"
    )

    private fun open(audio: Boolean = false) {
        pair(audio)
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    androidx.compose.foundation.layout.Box {
                        V2RecordingScreen(vm, item, onBack = {})
                        EchoToastHost(toasts)
                    }
                }
            }
        }
        pumpUntil("the page") { compose.onAllNodesWithText("Line number 0 of the call.").fetchSemanticsNodes().isNotEmpty() }
    }

    private fun titleTops(): List<Float> = compose.onAllNodesWithText("Call with Acme").fetchSemanticsNodes().map { it.boundsInRoot.top }

    @Test
    fun theTitleMovesIntoTheBarOnceThePageScrolls() {
        open()
        // At the top the title is the page's head, under the bar; the bar's back button is the only thing up there.
        val back = compose.onNodeWithContentDescription("Back").fetchSemanticsNode().boundsInRoot
        assertTrue(titleTops().all { it >= back.bottom - 1f })
        compose.onNode(hasScrollToIndexAction()).performScrollToIndex(20)
        compose.waitForIdle()
        // Scrolled: the title is in the bar, beside Back.
        pumpUntil("in the bar") { titleTops().any { it < back.bottom } }
        compose.onNode(hasScrollToIndexAction()).performScrollToIndex(0)
        compose.waitForIdle()
        pumpUntil("back in the head") { titleTops().all { it >= back.bottom - 1f } }
    }

    @Test
    fun detailsSayHowBigTheFileIs() {
        open()
        val tab = compose.onAllNodes(hasText("Details") and hasClickAction()).onFirst()
        runCatching { tab.performScrollTo() }
        tab.performClick()
        pumpUntil("the size") { compose.onAllNodesWithText("File size").fetchSemanticsNodes().isNotEmpty() }
        // sessionDetail's primary file is 10,485,760 bytes.
        compose.onAllNodesWithText("10 MB").onFirst().assertExists()
    }

    private fun scrubber(language: EchoLanguage, onSeek: (Long) -> Unit) = show {
        EchoV2Theme(darkTheme = false, language = language) {
            EchoPlayer(
                positionMs = 0,
                durationMs = 100_000,
                playing = false,
                speed = "1×",
                labels = EchoPlayerLabels("Play", "Pause", "Back 10 s", "Forward 10 s", "Position", "Speed"),
                onPlayPause = {},
                onSeek = onSeek,
                onSpeed = {}
            )
        }
    }

    @Test
    fun aTapOnTheScrubberMovesThereAndADragFollows() {
        var at = -1L
        scrubber(EchoLanguage.English) { at = it }
        compose.onNode(hasContentDescription("Position")).performTouchInput { click(percentOffset(0.75f, 0.5f)) }
        assertTrue("seeks to $at", at in 70_000L..80_000L)
        compose.onNode(hasContentDescription("Position")).performTouchInput {
            down(percentOffset(0.2f, 0.5f))
            moveTo(percentOffset(0.4f, 0.5f))
            up()
        }
        assertTrue("drags to $at", at in 35_000L..45_000L)
    }

    @Test
    fun inUrduTheTimelineStillRunsLeftToRight() {
        var at = -1L
        scrubber(EchoLanguage.Urdu) { at = it }
        compose.onNode(hasContentDescription("Position")).performTouchInput { click(percentOffset(0.75f, 0.5f)) }
        assertTrue("seeks to $at", at in 70_000L..80_000L)
        assertEquals(true, at > 50_000L)
    }

    /** The page's player: "position / length" (its Position's state), in milliseconds. */
    private fun clock(): Pair<Long, Long> {
        val words = compose.onNode(hasContentDescription("Position")).fetchSemanticsNode()
            .config[androidx.compose.ui.semantics.SemanticsProperties.StateDescription]
        fun ms(t: String) = t.trim().split(":").map { it.toLong() }.fold(0L) { acc, n -> acc * 60 + n } * 1000
        val (at, of) = words.split("/")
        return ms(at) to ms(of)
    }

    @Test
    fun onTheRecordingPageTheScrubbersTapMovesThePlayerAndALinePlaysFromItsStart() {
        open(audio = true)
        pumpUntil("the player") { compose.onAllNodes(hasContentDescription("Position")).fetchSemanticsNodes().isNotEmpty() }
        pumpUntil("its length") { clock().second > 0 }
        compose.onNode(hasContentDescription("Position")).performTouchInput { click(percentOffset(0.75f, 0.5f)) }
        pumpUntil("moved") { clock().first > 0 }
        val (at, of) = clock()
        assertTrue("the page's player moved to $at of $of", at.toDouble() / of in 0.7..0.8)
        // A line plays from where it starts: line 10 starts at 0:50.
        compose.onNodeWithText("Line number 10 of the call.").performClick()
        pumpUntil("at the line") { clock().first == 50_000L }
    }
}
