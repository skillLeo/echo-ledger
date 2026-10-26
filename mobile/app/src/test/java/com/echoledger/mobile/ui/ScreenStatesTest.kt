package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import kotlinx.coroutines.runBlocking
import org.junit.Test

/** C6: offline and load-failure states say what happened and offer Retry. */
class ScreenStatesTest : ScreenTest() {
    private fun hasText(text: String) = runCatching { compose.onNodeWithText(text, substring = true).fetchSemanticsNode() }.isSuccess

    @Test
    fun `the library shows an offline banner and Retry brings it back online`() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/library", FakeServer.json(500, """{"message":"down"}"""))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        show { LibraryScreen(vm, showCallSetup = false, onSetup = {}, onOpen = {}) }
        vm.refresh()
        compose.waitUntil(5_000) { hasText("You're offline") }

        server.on(
            "GET",
            "/api/library",
            FakeServer.json(200, ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem("01JA", title = "Back online"))))
        )
        compose.onNodeWithText("Retry").performClick()
        compose.waitUntil(5_000) { !hasText("You're offline") }
        compose.waitUntil(5_000) { hasText("Back online") }
    }

    @Test
    fun `a transcript that does not load offers Retry`() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/transcripts/.+", FakeServer.json(500, """{"message":"down"}"""))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        val item = LibraryItem(
            id = ServerFixtures.SESSION_ID,
            sourceDevice = "phone",
            sourceApp = "whatsapp",
            title = "Call",
            startedAt = "2026-10-24T07:10:00+00:00",
            durationMs = 60_000,
            status = "completed",
            transcript = TranscriptSummary(
                finalStatus = "completed",
                finalTranscriptId = ServerFixtures.TRANSCRIPT_ID,
                liveAvailable = false,
                preview = "p"
            ),
            updatedAt = "2026-10-24T07:30:00+00:00"
        )
        show { SessionScreen(vm, item, onBack = {}) }
        compose.waitUntil(5_000) { hasText("Couldn't load the transcript.") }

        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        compose.onNodeWithText("Retry").performClick()
        compose.waitUntil(5_000) { hasText("Hello there.") }
        compose.onNodeWithText("Hello there.", substring = true).assertIsDisplayed()
    }
}
