package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import mockwebserver3.MockResponse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** The recording player card under the transcript, with the streaming URL served by the scripted server. */
class SessionPlaybackTest : ScreenTest() {
    private val item = LibraryItem(
        id = ServerFixtures.SESSION_ID,
        title = "Call with Acme",
        startedAt = "2026-10-20T10:00:00+00:00",
        durationMs = 30_000,
        status = "completed",
        transcript = TranscriptSummary(finalStatus = "completed", finalTranscriptId = ServerFixtures.TRANSCRIPT_ID, liveAvailable = false),
        updatedAt = "2026-10-20T10:05:00+00:00"
    )

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        server.on("DELETE", "/api/sessions/.+", FakeServer.empty(204))
    }

    private fun serveDetail(playbackPath: String?) {
        val url = playbackPath?.let { "${server.baseUrl}$it?expires=1&signature=abc" }
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = url))
        )
    }

    private fun showSession() = show { SessionScreen(vm, item, onBack = {}) }

    private fun awaitText(text: String, timeoutMs: Long = 20_000) = pumpUntil("the text \"$text\"", timeoutMs) {
        compose.waitForIdle()
        compose.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    @Test
    fun `no player card is shown while the recording has no streaming url`() {
        serveDetail(playbackPath = null)
        showSession()
        awaitText("Hello there.")

        compose.onAllNodesWithContentDescription("Play").assertCountEquals(0)
        compose.onAllNodesWithContentDescription("Pause").assertCountEquals(0)
    }

    @Test
    fun `a streaming url makes the player card appear and the server is asked for the audio`() {
        serveDetail("/api/recordings/${ServerFixtures.RECORDING_ID}/audio")
        server.on("GET", "/api/recordings/.+/audio", MockResponse.Builder().code(404).body("{}").build())
        showSession()
        awaitText("Hello there.")

        pumpUntil("the audio request", timeoutMs = 20_000) {
            compose.waitForIdle()
            server.requestsTo("GET", "/api/recordings/.+/audio").isNotEmpty()
        }
        compose.onAllNodesWithText("0:00").assertCountEquals(2) // the transcript stamp and the player's elapsed time
    }

    @Test
    fun `an audio url the server refuses shows a playback error and the segments stay disabled`() {
        serveDetail("/api/recordings/${ServerFixtures.RECORDING_ID}/audio")
        server.on("GET", "/api/recordings/.+/audio", MockResponse.Builder().code(403).body("{}").build())
        showSession()
        awaitText("Hello there.")

        awaitText("This recording could not be played.")

        compose.onNodeWithText("Hi, thanks for calling.").assertIsNotEnabled()
        assertTrue(server.requestsTo("GET", "/api/recordings/.+/audio").isNotEmpty())
    }

    @Test
    fun `the audio request carries no bearer token because the url is already signed`() {
        serveDetail("/api/recordings/${ServerFixtures.RECORDING_ID}/audio")
        server.on("GET", "/api/recordings/.+/audio", MockResponse.Builder().code(404).body("{}").build())
        showSession()
        awaitText("Hello there.")
        pumpUntil("the audio request", timeoutMs = 20_000) {
            compose.waitForIdle()
            server.requestsTo("GET", "/api/recordings/.+/audio").isNotEmpty()
        }

        val request = server.requestsTo("GET", "/api/recordings/.+/audio").first()
        assertTrue(request.url.queryParameter("signature") == "abc")
        assertTrue("the signed url must not leak the device token: ${request.headers}", request.headers["Authorization"] == null)
    }
}
