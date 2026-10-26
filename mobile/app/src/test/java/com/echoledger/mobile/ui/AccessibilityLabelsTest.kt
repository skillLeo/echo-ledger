package com.echoledger.mobile.ui

import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Test

/** C7: everything that can be tapped on the main screens has a label TalkBack can read. */
class AccessibilityLabelsTest : ScreenTest() {
    private fun SemanticsNode.label(): String {
        val c = config
        return listOfNotNull(
            c.getOrNull(SemanticsProperties.ContentDescription)?.joinToString(),
            c.getOrNull(SemanticsProperties.Text)?.joinToString(),
            c.getOrNull(SemanticsProperties.EditableText)?.text,
            c.getOrNull(SemanticsProperties.StateDescription)
        ).joinToString(" ").trim()
    }

    private fun unlabelled(): List<String> = compose.onAllNodes(hasClickAction()).fetchSemanticsNodes()
        .filter { it.label().isEmpty() }
        .map { "${it.config} at ${it.boundsInRoot}" }

    private fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem("01JA"), ServerFixtures.libraryItem("01JB", title = "Second")))
            )
        )
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        runBlocking { app.graph.library.refresh() }
    }

    @Test
    fun `library controls are labelled`() {
        seed()
        show { LibraryScreen(vm, showCallSetup = true, onSetup = {}, onOpen = {}) }
        compose.waitForIdle()
        assertEquals(emptyList<String>(), unlabelled())
    }

    @Test
    fun `record controls are labelled`() {
        runBlocking { app.pairLocally(server) }
        compose.mainClock.autoAdvance = false
        show {
            RecordScreen(
                vm,
                Capabilities(
                    34,
                    true,
                    micPermission = true,
                    notificationPermission = true,
                    overlayPermission = true
                ),
                onSetup = {
                }
            )
        }
        compose.mainClock.advanceTimeBy(1_500)
        assertEquals(emptyList<String>(), unlabelled())
    }

    @Test
    fun `settings controls are labelled, also inside a group`() {
        runBlocking { app.pairLocally(server) }
        show { SettingsScreen(vm, onSetup = {}) }
        compose.waitForIdle()
        assertEquals(emptyList<String>(), unlabelled())
        compose.onNodeWithText("General").performClick()
        compose.waitForIdle()
        assertEquals(emptyList<String>(), unlabelled())
    }
}
