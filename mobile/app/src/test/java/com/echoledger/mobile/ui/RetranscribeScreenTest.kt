package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** "Re-transcribe as <language>" on the recording screen and the switch between the newest and the previous version (v1.2). */
class RetranscribeScreenTest : ScreenTest() {
    private val previousId = "01JTRANSCRIPT00000000000B"
    private val retranscribePath = "/api/sessions/${ServerFixtures.SESSION_ID}/retranscribe"

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
    }

    private fun item(alternateStatus: String? = null) = LibraryItem(
        id = ServerFixtures.SESSION_ID,
        sourceDevice = "phone",
        sourceApp = "whatsapp",
        title = "Call with Acme",
        startedAt = "2026-10-20T10:00:00+00:00",
        durationMs = 60_000,
        status = "completed",
        language = "ur",
        transcript = TranscriptSummary(
            finalStatus = "completed",
            finalTranscriptId = ServerFixtures.TRANSCRIPT_ID,
            alternateTranscriptId = alternateStatus?.let { previousId },
            alternateStatus = alternateStatus,
            alternateLanguage = alternateStatus?.let { "en" }
        ),
        updatedAt = "2026-10-20T10:05:00+00:00"
    )

    private fun line(text: String) = """[{"channel":1,"speaker":"Me","start_ms":0,"end_ms":1000,"text":"$text"}]"""

    private fun serve(retranscribe: Int = 202) {
        val newest = ServerFixtures.transcript(text = "Newest text", segments = line("Newest text"))
        val previous = ServerFixtures.transcript(
            text = "Previous text",
            segments = line("Previous text")
        ).replace(ServerFixtures.TRANSCRIPT_ID, previousId)
        server.on("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}", FakeServer.json(200, newest))
        server.on("GET", "/api/transcripts/$previousId", FakeServer.json(200, previous))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        val answer = if (retranscribe == 202) {
            """{"transcript":{"id":"01JRUN","kind":"alternate","provider":"groq","status":"pending","language":"ur"}}"""
        } else {
            """{"message":"This recording cannot be re-transcribed yet.","code":"cannot_retranscribe"}"""
        }
        server.on("POST", retranscribePath, FakeServer.json(retranscribe, answer))
    }

    private fun awaitText(text: String) = pumpUntil("the text \"$text\"", timeoutMs = 45_000) {
        compose.waitForIdle()
        compose.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    @Test
    fun `re-transcribes in the language picked and keeps the current transcript`() {
        serve()
        show { SessionScreen(vm, item(), onBack = {}) }
        awaitText("Newest text")

        compose.onNodeWithText("Re-transcribe as…").performClick()
        compose.onNodeWithText("The current transcript stays until the new one is ready.").assertExists()
        compose.onNodeWithContentDescription("Language: Urdu").assertIsSelected()
        compose.onNodeWithContentDescription("Language: Arabic").performClick()
        compose.onNodeWithText("Re-transcribe").performClick()

        pumpUntil("the re-transcribe request") { server.requestsTo("POST", retranscribePath).isNotEmpty() }
        assertEquals("""{"language":"ar"}""", server.requestsTo("POST", retranscribePath).single().bodyText())
        pumpUntil("the message") { vm.message.value != null }
        assertEquals("Re-transcribing in Arabic. The current transcript stays until the new one is ready.", vm.message.value)
        compose.onNodeWithText("Newest text").assertExists()
    }

    @Test
    fun `Auto sends no language and a refusal is reported`() {
        serve(retranscribe = 409)
        show { SessionScreen(vm, item(), onBack = {}) }
        awaitText("Newest text")

        compose.onNodeWithText("Re-transcribe as…").performClick()
        compose.onNodeWithContentDescription("Language: Auto").performClick()
        compose.onNodeWithText("Re-transcribe").performClick()

        pumpUntil("the message") { vm.message.value != null }
        assertEquals("""{"language":null}""", server.requestsTo("POST", retranscribePath).single().bodyText())
        assertTrue(vm.message.value!!.contains("cannot be re-transcribed"))
    }

    @Test
    fun `switches between the newest and the previous version`() {
        serve()
        show { SessionScreen(vm, item(alternateStatus = "completed"), onBack = {}) }
        awaitText("Newest text")
        compose.onNodeWithText("Current").assertIsSelected()

        compose.onNodeWithText("Previous (English)").performClick()
        awaitText("Previous text")
        compose.onNodeWithText("Previous (English)").assertIsSelected()

        compose.onNodeWithText("Current").performClick()
        awaitText("Newest text")
    }

    @Test
    fun `no version switch while the new version is still being made`() {
        serve()
        show { SessionScreen(vm, item(alternateStatus = "processing"), onBack = {}) }
        awaitText("Newest text")

        compose.onNodeWithText("Re-transcribe as…").assertExists()
        assertTrue(compose.onAllNodesWithText("Previous", substring = true).fetchSemanticsNodes().isEmpty())
    }
}
