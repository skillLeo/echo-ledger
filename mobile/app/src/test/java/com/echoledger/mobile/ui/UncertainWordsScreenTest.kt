package com.echoledger.mobile.ui

import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
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
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** The recording page marks what the engine was not sure of (v1.2); the text stays as heard. */
class UncertainWordsScreenTest : ScreenTest() {
    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
    }

    private val item = LibraryItem(
        id = ServerFixtures.SESSION_ID,
        sourceDevice = "phone",
        sourceApp = "whatsapp",
        title = "Call with Acme",
        startedAt = "2026-10-20T10:00:00+00:00",
        durationMs = 60_000,
        status = "completed",
        transcript = TranscriptSummary(finalStatus = "completed", finalTranscriptId = ServerFixtures.TRANSCRIPT_ID),
        updatedAt = "2026-10-20T10:05:00+00:00"
    )

    private val segments = """[
        {"channel":1,"speaker":"Me","start_ms":0,"end_ms":900,"text":"Hello Farah","words":[
            {"text":"Hello","start_ms":0,"end_ms":400,"confidence":0.98},{"text":"Farah","start_ms":450,"end_ms":900,"confidence":0.31}]},
        {"channel":2,"speaker":"Client","start_ms":2000,"end_ms":3000,"text":"I will come tomorrow","uncertain":true},
        {"channel":1,"speaker":"Me","start_ms":4000,"end_ms":5000,"text":"Sure, see you","words":[
            {"text":"Sure,","start_ms":4000,"end_ms":4400,"confidence":0.95},{"text":"see","start_ms":4450,"end_ms":4700,"confidence":0.9},
            {"text":"you","start_ms":4750,"end_ms":5000,"confidence":0.97}]}
    ]"""

    private fun described(text: String) = SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, text)

    @Test
    fun `underlines the uncertain words and lines and leaves the rest alone`() {
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript(text = "Hello Farah", segments = segments)))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        show { SessionScreen(vm, item, onBack = {}) }
        pumpUntil("the transcript", timeoutMs = 45_000) {
            compose.waitForIdle()
            compose.onAllNodesWithText("Hello Farah").fetchSemanticsNodes().isNotEmpty()
        }

        compose.onNodeWithText("Hello Farah").assert(described("Not sure of 1 word"))
        compose.onNodeWithText("I will come tomorrow").assert(described("Not sure of this line"))
        val sure = compose.onNodeWithText("Sure, see you").fetchSemanticsNode()
        assertTrue(SemanticsProperties.StateDescription !in sure.config)
    }
}
