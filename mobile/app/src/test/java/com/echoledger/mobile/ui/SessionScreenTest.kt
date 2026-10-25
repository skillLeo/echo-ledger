package com.echoledger.mobile.ui

import android.content.ClipboardManager
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** The transcript view for one recording: states, copy actions, navigation and deletion. */
class SessionScreenTest : ScreenTest() {
    private var backTaps = 0

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
    }

    private fun item(
        title: String? = "Call with Acme",
        finalStatus: String? = "completed",
        finalId: String? = ServerFixtures.TRANSCRIPT_ID,
        liveId: String? = null,
        client: String? = "Acme Holdings"
    ) = LibraryItem(
        id = ServerFixtures.SESSION_ID,
        sourceDevice = "phone",
        sourceApp = "whatsapp",
        title = title,
        client = client?.let { NamedRef("01JCLIENT0000000000000000A", it) },
        startedAt = "2026-10-20T10:00:00+00:00",
        durationMs = 2_530_000,
        status = "completed",
        transcript = TranscriptSummary(
            finalStatus = finalStatus,
            finalTranscriptId = finalId,
            liveAvailable = liveId != null,
            liveTranscriptId = liveId,
            preview = "preview"
        ),
        updatedAt = "2026-10-20T10:05:00+00:00"
    )

    private fun serve(transcriptJson: String? = ServerFixtures.transcript(), transcriptStatus: Int = 200, delayMs: Long = 0) {
        val builder = MockResponse.Builder().code(
            transcriptStatus
        ).addHeader("Content-Type", "application/json").body(transcriptJson ?: "{}")
        if (delayMs > 0) builder.headersDelay(delayMs, TimeUnit.MILLISECONDS)
        server.on("GET", "/api/transcripts/.+", builder.build())
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        server.on("DELETE", "/api/sessions/${ServerFixtures.SESSION_ID}", FakeServer.empty(204))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
    }

    private fun showSession(item: LibraryItem = item()) = show { SessionScreen(vm, item, onBack = { backTaps++ }) }

    private fun awaitText(text: String) {
        pumpUntil("the text \"$text\"") {
            compose.waitForIdle()
            compose.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isNotEmpty()
        }
    }

    private fun clipboardText(): String? {
        val clipboard = app.getSystemService(ClipboardManager::class.java)
        return clipboard.primaryClip?.getItemAt(0)?.text?.toString()
    }

    // ---- header and transcript ---------------------------------------------------------------------------------------------

    @Test
    fun `the header shows title when who and how long`() {
        serve()
        showSession()

        compose.onNodeWithText("Call with Acme").assertIsDisplayed()
        compose.onNodeWithText("Oct 20 · 10:00 AM · 42:10 · Acme Holdings").assertIsDisplayed()
        compose.onNodeWithText("WhatsApp").assertIsDisplayed()
        compose.onNodeWithText("Phone").assertIsDisplayed()
    }

    @Test
    fun `a recording without a title is shown as Untitled`() {
        serve()
        showSession(item(title = null, client = null))

        compose.onNodeWithText("Untitled").assertIsDisplayed()
        compose.onNodeWithText("Oct 20 · 10:00 AM · 42:10").assertIsDisplayed()
    }

    @Test
    fun `the final transcript lists each segment with its time speaker and text`() {
        serve()
        showSession()
        awaitText("Hello there.")

        compose.onNodeWithText("Final transcript · assemblyai").assertIsDisplayed()
        compose.onNodeWithText("Hello there.").assertIsDisplayed()
        compose.onNodeWithText("Hi, thanks for calling.").assertIsDisplayed()
        compose.onAllNodesWithText("Me", substring = false).assertCountEquals(1)
        compose.onAllNodesWithText("Client", substring = false).assertCountEquals(1)
        compose.onAllNodesWithText("0:00", substring = false).assertCountEquals(1)
        compose.onAllNodesWithText("0:12", substring = false).assertCountEquals(1)
        assertTrue(server.requestsTo("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}").isNotEmpty())
    }

    @Test
    fun `a live transcript is labelled as captions pending the final one`() {
        serve(ServerFixtures.transcript(kind = "live"))
        showSession(item(finalStatus = null, finalId = null, liveId = ServerFixtures.TRANSCRIPT_ID))
        awaitText("Hello there.")

        compose.onNodeWithText("Live captions (final pending)").assertIsDisplayed()
        assertEquals(1, server.requestsTo("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}").size)
    }

    @Test
    fun `the final transcript is preferred over live captions when both exist`() {
        serve()
        showSession(item(liveId = "01JLIVE000000000000000000A"))
        awaitText("Hello there.")

        assertTrue(server.requestsTo("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}").isNotEmpty())
        assertTrue(server.requestsTo("GET", "/api/transcripts/01JLIVE000000000000000000A").isEmpty())
    }

    @Test
    fun `segment rows cannot seek while there is no playable recording`() {
        serve()
        showSession()
        awaitText("Hello there.")

        compose.onNodeWithText("Hi, thanks for calling.").assertIsNotEnabled()
    }

    @Test
    fun `long transcripts and unicode text render`() {
        val segments = (0 until 60).joinToString(",") {
            """{"channel":${1 + it % 2},"speaker":"${if (it % 2 == 0) "Me" else "Client"}","start_ms":${it * 4000},"end_ms":${it * 4000 + 3000},"text":"Line $it café 你好"}"""
        }
        serve(ServerFixtures.transcript(segments = "[$segments]"))
        showSession()
        awaitText("Line 0")

        compose.onNodeWithText("Line 0 café 你好").assertIsDisplayed()
    }

    // ---- states without a transcript ---------------------------------------------------------------------------------------

    @Test
    fun `while the transcript is loading a placeholder is shown`() {
        serve(delayMs = 1_500)
        showSession()

        compose.onNodeWithText("Loading transcript…").assertIsDisplayed()
        awaitText("Hello there.")
        compose.onNodeWithText("Loading transcript…").assertDoesNotExist()
    }

    @Test
    fun `a recording that never got a transcript says so`() {
        serve()
        showSession(item(finalStatus = null, finalId = null))
        awaitText("No transcript for this recording.")

        assertTrue("no transcript request without an id", server.requestsTo("GET", "/api/transcripts/.+").isEmpty())
    }

    @Test
    fun `a transcript still being produced says it is not ready yet`() {
        serve(transcriptStatus = 404, transcriptJson = """{"message":"No query results."}""")
        showSession(item(finalStatus = "processing"))

        awaitText("Transcript processing.")
    }

    @Test
    fun `an on-device transcript state is spelled out with spaces`() {
        serve(transcriptStatus = 404, transcriptJson = """{"message":"No query results."}""")
        showSession(item(finalStatus = "awaiting_device"))

        awaitText("Transcript awaiting device.")
    }

    @Test
    fun `a failed transcription whose details cannot be fetched still says it failed`() {
        serve(transcriptStatus = 500, transcriptJson = """{"message":"Server Error"}""")
        showSession(item(finalStatus = "failed"))

        awaitText("Transcription failed.")
    }

    @Test
    fun `a failed transcript shows the reason from the server`() {
        serve(
            ServerFixtures.transcript(
                status = "failed",
                error = "The speech provider could not decode this file.",
                segments = "[]",
                text = null
            )
        )
        showSession(item(finalStatus = "failed"))
        awaitText("Transcription failed")

        compose.onNodeWithText("The speech provider could not decode this file.").assertIsDisplayed()
        compose.onAllNodesWithText("Copy text").assertCountEquals(0)
    }

    @Test
    fun `a failed transcript without a reason shows a generic one`() {
        serve(ServerFixtures.transcript(status = "failed", error = null, segments = "[]", text = null))
        showSession(item(finalStatus = "failed"))

        awaitText("The speech provider could not process this recording.")
    }

    @Test
    fun `a transcript with no speech says it is empty`() {
        serve(ServerFixtures.transcript(segments = "[]", text = ""))
        showSession()

        awaitText("The transcript is empty: no speech was detected.")
        compose.onAllNodesWithText("Copy text").assertCountEquals(0)
    }

    // ---- copy actions -------------------------------------------------------------------------------------------------------

    @Test
    fun `Copy text puts the speaker lines on the clipboard and confirms`() {
        serve()
        showSession()
        awaitText("Hello there.")

        compose.onNodeWithText("Copy text").performClick()
        pumpUntil("the clipboard") { vm.message.value != null }

        assertEquals("Me: Hello there.\nClient: Hi, thanks for calling.", clipboardText())
        assertEquals("Copied", vm.message.value)
    }

    @Test
    fun `Copy with timestamps adds the time before each line and confirms`() {
        serve()
        showSession()
        awaitText("Hello there.")

        compose.onNodeWithText("Copy with timestamps").performClick()
        pumpUntil("the clipboard") { vm.message.value != null }

        assertEquals("[0:00] Me: Hello there.\n[0:12] Client: Hi, thanks for calling.", clipboardText())
        assertEquals("Copied with timestamps", vm.message.value)
    }

    @Test
    fun `copying leaves any earlier clipboard content replaced rather than appended`() {
        serve()
        showSession()
        awaitText("Hello there.")

        compose.onNodeWithText("Copy text").performClick()
        pumpUntil("first copy") { vm.message.value != null }
        vm.consumeMessage()
        compose.onNodeWithText("Copy with timestamps").performClick()
        pumpUntil("second copy") { vm.message.value != null }

        assertEquals("[0:00] Me: Hello there.\n[0:12] Client: Hi, thanks for calling.", clipboardText())
        assertNotNull(app.getSystemService(ClipboardManager::class.java).primaryClipDescription)
    }

    // ---- navigation and deletion ----------------------------------------------------------------------------------------------

    @Test
    fun `Back returns to the library`() {
        serve()
        showSession()

        compose.onNodeWithContentDescription("Back").performClick()

        assertEquals(1, backTaps)
    }

    @Test
    fun `Delete removes the session on the server and returns to the library`() {
        serve()
        showSession()

        compose.onNodeWithContentDescription("Delete").performClick()
        pumpUntil("the delete request") { server.requestsTo("DELETE", "/api/sessions/${ServerFixtures.SESSION_ID}").isNotEmpty() }

        assertEquals(1, backTaps)
        assertEquals(1, server.requestsTo("DELETE", "/api/sessions/${ServerFixtures.SESSION_ID}").size)
    }
}
