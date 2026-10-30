package com.echoledger.mobile.ui

import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.hasClickAction
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
import com.echoledger.mobile.translate.TranslateMode
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test

/**
 * TalkBack (C10): every element that can be tapped says what it is, by its text or a content description, on the
 * main screens. A tappable icon without a name is read as "button" and nothing else.
 */
class AccessibilityTest : ScreenTest() {
    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
    }

    private fun SemanticsNode.named(): Boolean {
        val c = config
        val described = c.getOrNull(SemanticsProperties.ContentDescription)?.any { it.isNotBlank() } == true
        val text = c.getOrNull(SemanticsProperties.Text)?.any { it.isNotBlank() } == true ||
            c.getOrNull(SemanticsProperties.EditableText)?.isNotBlank() == true
        // A switch or checkbox row is named by its text, merged into the row.
        return described || text
    }

    private fun unnamedClickables(): List<String> = compose.onAllNodes(hasClickAction(), useUnmergedTree = false)
        .fetchSemanticsNodes()
        .filterNot { it.named() }
        // Nothing on screen (zero size: a closed menu, an off-screen helper) is not read either.
        .filter { it.boundsInRoot.width > 0f && it.boundsInRoot.height > 0f }
        .map { "node ${it.id} at ${it.boundsInRoot}" }

    @Test
    fun `the Library names everything that can be tapped`() {
        show { LibraryScreen(vm, showCallSetup = true, onSetup = {}, onOpen = {}) }
        compose.waitForIdle()
        assertEquals(emptyList<String>(), unnamedClickables())
    }

    @Test
    fun `the recording page names everything that can be tapped`() {
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        val item = LibraryItem(
            id = ServerFixtures.SESSION_ID,
            sourceDevice = "phone",
            sourceApp = "whatsapp",
            title = "Invoice questions",
            client = NamedRef("01JCLIENT0000000000000000A", "Aria Studio"),
            startedAt = "2026-10-24T07:10:00+00:00",
            durationMs = 742_000,
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
        compose.waitUntil(10_000) {
            runCatching { compose.onNodeWithText("Hello there.", substring = true).fetchSemanticsNode() }.isSuccess
        }
        assertEquals(emptyList<String>(), unnamedClickables())
    }

    @Test
    fun `You and its panels name everything that can be tapped`() {
        show { SettingsScreen(vm, onSetup = {}) }
        assertEquals(emptyList<String>(), unnamedClickables())
        for (panel in listOf("Calls & meetings", "Game translate", "Watch translate", "Voice notes & files")) {
            compose.onNodeWithText(panel).performClick()
            compose.waitForIdle()
            assertEquals("$panel: unnamed", emptyList<String>(), unnamedClickables())
            compose.onNodeWithContentDescription("Back").performClick()
            compose.waitForIdle()
        }
    }

    @Test
    fun `the Game translate page names everything that can be tapped`() {
        show { LiveTranslatePage(vm, TranslateMode.GAME, onSetup = {}) }
        compose.waitForIdle()
        assertEquals(emptyList<String>(), unnamedClickables())
    }
}
