package com.echoledger.mobile.ui

import android.content.ClipboardManager
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextClearance
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.ProgressDto
import com.echoledger.mobile.data.RowViewsDto
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.awaitAppIdle
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
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

    private var settingsOpened: String? = null

    private fun showSession(item: LibraryItem = item()) = show {
        SessionScreen(vm, item, onBack = { backTaps++ }, onOpenSettings = { settingsOpened = it })
    }

    private fun awaitText(text: String) {
        // The whole suite runs in parallel forks; a loaded machine can take longer than the default 20 s.
        pumpUntil("the text \"$text\"", timeoutMs = 45_000) {
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
    fun `call notes are offered on a project's recording only when the plan has AI notes`() {
        serve()
        val notes = "/api/sessions/${ServerFixtures.SESSION_ID}/notes"
        server.on("GET", notes, FakeServer.json(404, """{"code":"notes_not_found","message":"No notes yet."}"""))
        showSession()
        awaitText("Write call notes")

        runBlocking { app.graph.settings.update { it.copy(plan = it.plan.copy(aiNotes = false)) } }
        pumpUntil("the call notes to go") {
            compose.waitForIdle()
            compose.onAllNodesWithText("Write call notes").fetchSemanticsNodes().isEmpty()
        }
        compose.onAllNodesWithText("Call notes").assertCountEquals(0)
        compose.onNodeWithText("Copy text").assertExists()
    }

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

        compose.onNodeWithText("Final transcript").assertIsDisplayed()
        compose.onAllNodesWithText("AssemblyAI", substring = true).assertCountEquals(0)
        compose.onNodeWithText("Hello there.").assertIsDisplayed()
        compose.onNodeWithText("Hi, thanks for calling.").assertIsDisplayed()
        compose.onAllNodesWithText("You", substring = false).assertCountEquals(1)
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

        // A line still offers Copy and Share on a long-press, but nothing to play.
        compose.onNodeWithText("Hi, thanks for calling.").performClick()
        compose.onNodeWithText("Hi, thanks for calling.").performTouchInput { longClick() }
        compose.onNodeWithText("Copy line").assertExists()
        compose.onAllNodesWithText("Play from here").assertCountEquals(0)
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

        // Since v1.6 (P5.4): the three steps, the running one named.
        awaitText("Transcribing")
        compose.onNodeWithText("Uploaded").assertIsDisplayed()
        compose.onNodeWithText("Improve").assertIsDisplayed()
    }

    @Test
    fun `a long recording made in parts says which part is being transcribed`() {
        serve(transcriptStatus = 404, transcriptJson = """{"message":"No query results."}""")
        val inParts = item(finalStatus = "processing")
        showSession(inParts.copy(transcript = inParts.transcript?.copy(finalProgress = ProgressDto(done = 2, total = 5))))

        awaitText("Transcribing · part 3 of 5")
    }

    @Test
    fun `an on-device transcript that is not back yet is shown as being transcribed`() {
        serve(transcriptStatus = 404, transcriptJson = """{"message":"No query results."}""")
        showSession(item(finalStatus = "awaiting_device"))

        awaitText("Transcribing")
    }

    @Test
    fun `Notify me when ready asks for one notice about this recording`() {
        serve(transcriptStatus = 404, transcriptJson = """{"message":"No query results."}""")
        showSession(item(finalStatus = "processing"))
        awaitText("Notify me when ready")

        compose.onNodeWithText("Notify me when ready").performClick()

        awaitText("You will get a notification when it is ready.")
        val work = androidx.work.WorkManager.getInstance(app).getWorkInfosForUniqueWork("ready-watch:${ServerFixtures.SESSION_ID}").get()
        assertEquals(1, work.size)
    }

    @Test
    fun `the page follows a recording being transcribed and shows it when it is ready`() {
        serve(transcriptStatus = 404, transcriptJson = """{"message":"No query results."}""")
        val shown = androidx.compose.runtime.mutableStateOf(item(finalStatus = "processing"))
        show { SessionScreen(vm, shown.value, onBack = {}) }
        awaitText("Transcribing")

        // The Library row (refreshed by the page) says it is done: the page loads the transcript by itself.
        serve()
        shown.value = item()

        awaitText("Hi, thanks for calling.")
        compose.onAllNodesWithText("Notify me when ready").assertCountEquals(0)
    }

    @Test
    fun `a readable transcript shows Improving with its percent while the views are made`() {
        serve()
        val ready = item()
        val views = RowViewsDto(status = "making", default = "roman", progress = mapOf("roman" to ProgressDto(done = 3, total = 5)))
        showSession(ready.copy(transcript = ready.transcript?.copy(views = views)))

        awaitText("Improving Roman Urdu… 60%")
        compose.onAllNodesWithText("Notify me when ready").assertCountEquals(0)
    }

    @Test
    fun `a failed transcription whose details cannot be fetched still says it failed`() {
        serve(transcriptStatus = 500, transcriptJson = """{"message":"Server Error"}""")
        showSession(item(finalStatus = "failed"))

        awaitText("Transcription failed.")
    }

    @Test
    fun `a failed transcript says why in the phone's own words for its error, never the engine's`() {
        // An older server's text names the engine (contract 1.8.1-a: the phone shows its own words per error_code).
        serve(
            ServerFixtures.transcript(
                status = "failed",
                error = "Groq Whisper large-v3 rejected the audio: invalid_request_error",
                errorCode = "provider_rejected",
                segments = "[]",
                text = null
            )
        )
        showSession(item(finalStatus = "failed"))
        awaitText("Transcription failed")

        compose.onNodeWithText("The transcription service could not transcribe this recording.", substring = true).assertIsDisplayed()
        compose.onAllNodesWithText("Groq", substring = true).assertCountEquals(0)
        compose.onAllNodesWithText("Copy text").assertCountEquals(0)
    }

    @Test
    fun `a transcript made by the backup engine offers the best one without naming either engine`() {
        serve(ServerFixtures.transcript(backupEngine = true, providerLabel = "Groq Whisper large-v3"))
        showSession(item())
        awaitText("Made with a backup service. Re-transcribe for the best quality?")
        compose.onAllNodesWithText("Groq", substring = true).assertCountEquals(0)
        compose.onAllNodesWithText("AssemblyAI", substring = true).assertCountEquals(0)
    }

    @Test
    fun `a refused own key says so and opens the key page, with no Retry (P-076)`() {
        serve(
            ServerFixtures.transcript(status = "failed", errorCode = "own_key_rejected", error = "rejected", segments = "[]", text = null)
        )
        showSession(item(finalStatus = "failed"))

        awaitText("Groq did not accept your key. Check it in Transcription key, then try again.")
        compose.onAllNodesWithText("Retry").assertCountEquals(0)
        compose.onNodeWithText("Check your key").performClick()
        assertEquals("key", settingsOpened)
        assertTrue(server.requestsTo("POST", "/api/sessions/.+/retranscribe").isEmpty())
    }

    @Test
    fun `once a key is saved after the refusal, the banner offers Retry so "then try again" can be done`() {
        runBlocking { app.graph.settings.update { it.copy(ownKeySavedAt = "2026-10-20T11:00:00Z") } }
        server.on("POST", "/api/sessions/.+/retranscribe", FakeServer.empty(202))
        serve(
            ServerFixtures.transcript(status = "failed", errorCode = "own_key_rejected", error = "rejected", segments = "[]", text = null)
        )
        showSession(item(finalStatus = "failed"))

        awaitText("Groq did not accept your key. Check it in Transcription key, then try again.")
        compose.onAllNodesWithText("Check your key").assertCountEquals(0)
        compose.onNodeWithText("Retry").performClick()
        pumpUntil("the retry") { server.requestsTo("POST", "/api/sessions/.+/retranscribe").isNotEmpty() }
        assertNull(settingsOpened)
    }

    @Test
    fun `a key saved before the refusal is not a new one`() {
        assertFalse(KeyRetry.newerKey("2026-10-20T10:00:00Z", "2026-10-20T10:05:00+00:00"))
        assertTrue(KeyRetry.newerKey("2026-10-20T10:06:00Z", "2026-10-20T10:05:00+00:00"))
        assertFalse("none saved on this phone", KeyRetry.newerKey(null, "2026-10-20T10:05:00+00:00"))
    }

    @Test
    fun `audio the server does not have reads as such and offers no retry (1_8_1-b)`() {
        serve(ServerFixtures.transcript(status = "failed", errorCode = "recording_missing", error = "x", segments = "[]", text = null))
        showSession(item(finalStatus = "failed"))

        awaitText("The audio did not fully reach the server, or it is no longer stored.")
        compose.onAllNodesWithText("Retry").assertCountEquals(0)
    }

    @Test
    fun `the no-audio card's Delete asks first, like the menu's (P-007)`() {
        serve(ServerFixtures.transcript(status = "failed", errorCode = "no_audio", error = "x", segments = "[]", text = null))
        server.on("DELETE", "/api/sessions/.+", FakeServer.empty(204))
        showSession(item(finalStatus = "failed"))
        awaitText("Nothing was heard in this recording")

        compose.onNodeWithText("Delete").performClick()
        awaitText("Delete this recording on every device?")
        compose.onNode(hasText("Cancel") and hasAnyAncestor(isDialog())).performClick()
        awaitAppIdle()
        assertTrue("Cancel deletes nothing", server.requestsTo("DELETE", "/api/sessions/.+").isEmpty())
        assertEquals(0, backTaps)

        compose.onNodeWithText("Delete").performClick()
        compose.onNode(hasText("Delete") and hasAnyAncestor(isDialog())).performClick()
        pumpUntil("the delete") { server.requestsTo("DELETE", "/api/sessions/.+").isNotEmpty() }
    }

    @Test
    fun `a failed transcript without a known error says so plainly`() {
        serve(ServerFixtures.transcript(status = "failed", error = "Whisper said no", segments = "[]", text = null))
        showSession(item(finalStatus = "failed"))

        awaitText("The transcript could not be made. Tap Retry.")
        compose.onAllNodesWithText("Whisper", substring = true).assertCountEquals(0)
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

    // ---- find and line actions ------------------------------------------------------------------------------------------------

    @Test
    fun `Find in transcript counts the matching lines and steps through them`() {
        serve()
        showSession()
        awaitText("Hello there.")

        compose.onNodeWithContentDescription("Find in transcript").performClick()
        compose.onNodeWithContentDescription("Find in transcript").performTextInput("THANKS")
        awaitText("1 of 1")
        compose.onNodeWithContentDescription("Find in transcript").performTextClearance()
        compose.onNodeWithContentDescription("Find in transcript").performTextInput("zebra")
        awaitText("No matches")

        compose.onNodeWithContentDescription("Close find").performClick()
        compose.onAllNodesWithText("No matches").assertCountEquals(0)
    }

    @Test
    fun `long-pressing a line offers to copy it with the speaker`() {
        serve()
        showSession()
        awaitText("Hello there.")

        compose.onNodeWithText("Hi, thanks for calling.").performTouchInput { longClick() }
        compose.onNodeWithText("Copy line").performClick()

        compose.waitForIdle()
        assertEquals("Client: Hi, thanks for calling.", clipboardText())
        compose.onNodeWithText("Share line").assertDoesNotExist()
    }

    @Test
    fun `the matcher ignores case and accents and skips blank queries`() {
        val lines = listOf("Meeting kab hai?", "Café at noon", "nothing")
        assertEquals(listOf(0), TranscriptFind.matches(lines, "MEETING"))
        assertEquals(listOf(1), TranscriptFind.matches(lines, "cafe"))
        assertEquals(emptyList<Int>(), TranscriptFind.matches(lines, "   "))
        assertEquals("2 of 3", TranscriptFind.counter(1, 3))
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

        compose.onNodeWithContentDescription("More").performClick()
        compose.onNodeWithText("Delete").performClick()
        assertEquals(
            "nothing is deleted before the user confirms",
            0,
            server.requestsTo("DELETE", "/api/sessions/${ServerFixtures.SESSION_ID}").size
        )
        compose.onNodeWithText(
            "Delete this recording on every device? You can restore it from Recently deleted for 48 hours."
        ).assertExists()
        compose.onNodeWithText("Delete").performClick()
        pumpUntil("the delete request") { server.requestsTo("DELETE", "/api/sessions/${ServerFixtures.SESSION_ID}").isNotEmpty() }

        assertEquals(1, backTaps)
        assertEquals(1, server.requestsTo("DELETE", "/api/sessions/${ServerFixtures.SESSION_ID}").size)
    }
}
