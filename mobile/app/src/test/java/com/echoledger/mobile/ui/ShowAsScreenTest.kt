package com.echoledger.mobile.ui

import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.data.TranscriptViewsDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Show as (v1.2): the Roman Urdu and English views of a transcript on the recording page. */
class ShowAsScreenTest : ScreenTest() {
    private val viewsPath = "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}/views"

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

    private fun transcript(viewsStatus: String) = """
        {"id":"${ServerFixtures.TRANSCRIPT_ID}","session_id":"${ServerFixtures.SESSION_ID}","kind":"final","provider":"groq","status":"completed",
         "language":"ur","text":"x","segments":[
           {"channel":1,"speaker":"Me","start_ms":0,"end_ms":1000,"text":"آپ کیسے ہیں"},
           {"channel":2,"speaker":"Client","start_ms":1500,"end_ms":3000,"text":"میں ٹھیک ہوں"}],
         "views":{"available":["roman","english"],"status":"$viewsStatus"}}
    """.trimIndent()

    private fun views(status: String) = if (status == "ready") {
        """{"status":"ready","available":["roman","english"],"roman":["aap kaise hain","main theek hoon"],
            "english":["How are you?","I am fine."],"model":"claude-opus-5-5","made_at":null,"error":null}"""
    } else {
        """{"status":"$status","available":["roman","english"],"roman":null,"english":null,"model":null,"made_at":null,"error":null}"""
    }

    private fun serve(viewsStatus: String) {
        server.on("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}", FakeServer.json(200, transcript(viewsStatus)))
        server.on("GET", viewsPath, FakeServer.json(200, views(viewsStatus)))
        server.on("POST", viewsPath, FakeServer.json(202, views("making")))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
    }

    private fun awaitText(text: String) = pumpUntil("the text \"$text\"", timeoutMs = 45_000) {
        compose.waitForIdle()
        compose.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    @Test
    fun `switches to the Roman Urdu and English views and back`() {
        serve("ready")
        show { SessionScreen(vm, item, onBack = {}) }
        awaitText("آپ کیسے ہیں")

        compose.onNodeWithText("Roman Urdu").performClick()
        awaitText("aap kaise hain")
        compose.onNodeWithText("Roman Urdu view by claude-opus-5-5. The transcript itself is unchanged.").assertExists()

        compose.onNodeWithText("English").performClick()
        awaitText("I am fine.")
        compose.onNodeWithText("Original").performClick()
        awaitText("میں ٹھیک ہوں")
        assertTrue(server.requestsTo("POST", viewsPath).isEmpty())
    }

    @Test
    fun `asks the server to write the views when they are not made yet`() {
        serve("none")
        show { SessionScreen(vm, item, onBack = {}) }
        awaitText("آپ کیسے ہیں")

        compose.onNodeWithText("Roman Urdu").performClick()
        pumpUntil("the request") { server.requestsTo("POST", viewsPath).isNotEmpty() }
        awaitText("Writing the Roman Urdu view")
        compose.onNodeWithText("آپ کیسے ہیں").assertExists()
    }

    @Test
    fun `lines and status follow the chosen view`() {
        val segments = listOf(SegmentDto(1, "Me", 0, 1000, "a"), SegmentDto(2, "Client", 1500, 3000, "b"))
        val ready =
            TranscriptViewsDto(
                "ready",
                listOf("roman", "english"),
                roman = listOf("ra", null),
                english = listOf("ea", "eb"),
                model = "claude-opus-5-5"
            )

        assertEquals(listOf("ra", null), ShowAs.lines(segments, ShowAs.ROMAN, ready))
        assertEquals(listOf("ea", "eb"), ShowAs.lines(segments, ShowAs.ENGLISH, ready))
        assertNull(ShowAs.lines(segments, ShowAs.ORIGINAL, ready))
        assertNull(ShowAs.lines(segments, ShowAs.ROMAN, ready.copy(status = "making")))
        assertEquals("The view could not be made: no key", ShowAs.status(ShowAs.ENGLISH, ready.copy(status = "failed", error = "no key")))
        assertNull(ShowAs.status(ShowAs.ORIGINAL, ready))
    }

    @Test
    fun `opens an English call in its Urdu view when that is the default`() {
        val english = """
            {"id":"${ServerFixtures.TRANSCRIPT_ID}","session_id":"${ServerFixtures.SESSION_ID}","kind":"final","provider":"assemblyai","status":"completed",
             "language":"en","text":"x","segments":[{"channel":1,"speaker":"Me","start_ms":0,"end_ms":1000,"text":"I will send the invoice"}],
             "views":{"available":["roman","urdu"],"status":"ready","default":"urdu"}}
        """.trimIndent()
        server.on("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}", FakeServer.json(200, english))
        server.on(
            "GET",
            viewsPath,
            FakeServer.json(
                200,
                """{"status":"ready","available":["roman","urdu"],"roman":["main invoice bhej doon ga"],"english":null,
                    "urdu":["میں invoice بھیج دوں گا"],"default":"urdu","model":"claude-opus-5-5","made_at":null,"error":null}"""
            )
        )
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        show { SessionScreen(vm, item, onBack = {}) }

        awaitText("میں invoice بھیج دوں گا")
        compose.onNodeWithText("Urdu view by claude-opus-5-5. The transcript itself is unchanged.").assertExists()
        assertTrue(compose.onAllNodesWithText("English").fetchSemanticsNodes().isEmpty())
        compose.onNodeWithText("Original").performClick()
        awaitText("I will send the invoice")
    }
}
