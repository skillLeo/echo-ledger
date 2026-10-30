package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.LibraryResponse
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Ignore
import org.junit.Test

/**
 * The transcript screen fed with documents the real server produced (src/test/resources/fixtures/server): what a
 * person reads for a finished, a failed, a single-speaker and a still-processing recording.
 */
class ServerContractScreenTest : ScreenTest() {
    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
    }

    private fun raw(name: String): String {
        val loader = checkNotNull(javaClass.classLoader) { "no class loader" }
        val stream = checkNotNull(loader.getResourceAsStream("fixtures/server/$name.json")) { "fixture $name is missing" }
        return stream.use { String(it.readBytes(), Charsets.UTF_8) }
    }

    private fun libraryItem(title: String): LibraryItem =
        ApiJson.decodeFromString(LibraryResponse.serializer(), raw("library_200_page")).data.single { it.title == title }

    /** The session document without its playback URL, so no media player is created in the unit test. */
    private fun sessionWithoutPlayback(name: String): String {
        val document: JsonObject = ApiJson.parseToJsonElement(raw(name)).jsonObject
        return JsonObject(document + ("playback_url" to JsonNull)).toString()
    }

    private fun serve(transcriptFixture: String, sessionFixture: String) {
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, raw(transcriptFixture)))
        server.on("GET", "/api/sessions/.+", FakeServer.json(200, sessionWithoutPlayback(sessionFixture)))
    }

    private fun open(item: LibraryItem) {
        show { SessionScreen(vm, item, onBack = {}) }
        pumpUntil("the transcript to load") {
            compose.waitForIdle()
            compose.onAllNodesWithText("Loading transcript", substring = true).fetchSemanticsNodes().isEmpty()
        }
    }

    private fun count(text: String, substring: Boolean = true) =
        compose.onAllNodesWithText(text, substring = substring).fetchSemanticsNodes().size

    @Test
    fun `a completed call lists each speaker's lines`() {
        serve("transcripts_show_200_final_completed", "sessions_show_200_completed")
        open(libraryItem("Quarterly review"))

        compose.onNodeWithText("Quarterly review").assertIsDisplayed()
        compose.onNodeWithText("Final transcript · AssemblyAI").assertIsDisplayed()
        compose.onNodeWithText("This is a test of the EchoLedger transcription system.").assertIsDisplayed()
        compose.onNodeWithText("Loud and clear. Go ahead.").assertIsDisplayed()
        assertEquals(2, count("You", substring = false)) // "Me" reads as "You" on the page
        assertEquals(2, count("Client", substring = false))
    }

    @Test
    fun `a voice note reads as one speaker`() {
        serve("transcripts_show_200_final_mono", "sessions_show_200_completed")
        open(libraryItem("Shopping list"))

        compose.onNodeWithText("Speaker").assertIsDisplayed()
        assertEquals(0, count("Client", substring = false))
    }

    @Test
    fun `a failed recording shows the provider's reason`() {
        serve("transcripts_show_200_final_failed", "sessions_show_200_failed")
        open(libraryItem("WhatsApp call"))

        compose.onNodeWithText("Transcription failed").assertIsDisplayed()
        compose.onNodeWithText("Transcoding failed. File does not appear to contain audio. File type is application/octet-stream (data).")
            .assertIsDisplayed()
    }

    @Test
    fun `a transcript the provider is still working on is not shown as an empty one`() {
        // The server creates the final transcript row when it submits the job (status "submitted", no segments) and
        // the library item already points at it, so the screen fetches it while the provider is still working.
        serve("transcripts_show_200_final_processing", "sessions_show_200_processing")
        open(libraryItem("Vendor sync"))

        assertEquals("no speech was detected", 0, count("no speech was detected"))
    }
}
