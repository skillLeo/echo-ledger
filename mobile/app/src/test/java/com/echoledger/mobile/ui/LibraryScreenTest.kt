package com.echoledger.mobile.ui

import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextClearance
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Ignore
import org.junit.Test

/** The library list: empty state, rows, status dots, search, source filters and the call-setup banner. */
class LibraryScreenTest : ScreenTest() {
    private var opened: LibraryItem? = null
    private var setupTaps = 0

    private fun seed(vararg items: String) {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(items.toList())))
        runBlocking { app.graph.library.refresh() }
    }

    private fun showLibrary(showCallSetup: Boolean = false) =
        show { LibraryScreen(vm, showCallSetup = showCallSetup, onSetup = { setupTaps++ }, onOpen = { opened = it }) }

    private fun search(text: String) {
        if (compose.onAllNodes(hasSetTextAction()).fetchSemanticsNodes().isEmpty()) {
            compose.onNodeWithText("Search recordings and transcripts").performClick()
        }
        compose.onNode(hasSetTextAction()).performTextInput(text)
    }

    /** Opens the [facet] chip (its label may carry a count), ticks [choices] and applies them. */
    private fun filter(facet: String, vararg choices: String) {
        compose.onNode(hasText(facet, substring = true) and hasClickAction()).performClick()
        choices.forEach { compose.onNode(hasText(it) and hasClickAction()).performClick() }
        compose.onNodeWithText("Apply").performClick()
    }

    /** Opens the [facet] chip and clears it. */
    private fun clearFilter(facet: String) {
        compose.onNode(hasText(facet, substring = true) and hasClickAction()).performClick()
        compose.onNodeWithText("Clear").performClick()
        compose.onNodeWithText("Apply").performClick()
    }

    /** A row whose state (what TalkBack reads after the title) is [label]. */
    private fun state(label: String) = SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, label)

    private fun item(
        id: String,
        title: String? = "Call $id",
        sourceDevice: String = "phone",
        sourceApp: String = "whatsapp",
        status: String = "completed",
        finalStatus: String? = "completed",
        preview: String? = "Preview of $id",
        client: String? = null,
        startedAt: String = "2026-10-20T10:00:00+00:00"
    ) = ServerFixtures.libraryItem(
        id,
        title,
        startedAt,
        "2026-10-20T10:05:00+00:00",
        sourceDevice,
        sourceApp,
        status,
        finalStatus,
        preview,
        client
    )

    // ---- empty and loading ---------------------------------------------------------------------------------------------

    @Test
    fun `an empty library shows the empty state with guidance`() {
        showLibrary()

        compose.onNodeWithText("Library").assertIsDisplayed()
        compose.onNodeWithText("No recordings yet").assertIsDisplayed()
        compose.onNodeWithText("Calls, meetings and voice notes you capture will appear here.").assertIsDisplayed()
    }

    @Test
    fun `the empty state is not shown while the first sync is still running`() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/library",
            MockResponse.Builder().code(200).addHeader("Content-Type", "application/json")
                .body(ServerFixtures.libraryPage(listOf(item("a"))))
                .headersDelay(1_000, TimeUnit.MILLISECONDS)
                .build()
        )
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        showLibrary()

        vm.refresh()
        pumpUntil("the first sync to start") { server.requestsTo("GET", "/api/library").isNotEmpty() }
        compose.waitForIdle()

        compose.onNodeWithText("No recordings yet").assertDoesNotExist()
    }

    // ---- rows -------------------------------------------------------------------------------------------------------------

    @Test
    fun `a recording row shows title time duration client and its app, and long-press selects it`() {
        seed(
            item("a", title = "Call with Acme", client = "Acme Holdings", preview = "Thanks for calling about the invoice"),
            item("d", title = "Desktop call", sourceDevice = "desktop", startedAt = "2026-10-20T09:00:00+00:00")
        )

        showLibrary()

        compose.onNodeWithText("Call with Acme").assertIsDisplayed()
        compose.onNodeWithText("42:10 · Acme Holdings").assertIsDisplayed()
        compose.onAllNodesWithText("Oct 20").assertCountEquals(2)
        // No preview text; the app is the row's icon, and only computer and web recordings carry a device badge.
        compose.onNodeWithText("Thanks for calling about the invoice").assertDoesNotExist()
        compose.onAllNodesWithContentDescription("WhatsApp").assertCountEquals(2)
        compose.onAllNodesWithContentDescription("Desktop").assertCountEquals(1)
        compose.onNodeWithText("No recordings yet").assertDoesNotExist()

        compose.onNodeWithText("Call with Acme").performTouchInput { longClick() }
        compose.onNodeWithText("1 selected").assertIsDisplayed()
        compose.onNodeWithText("Call with Acme").performClick()
        compose.onNodeWithText("Library").assertIsDisplayed()
    }

    @Test
    fun `a recording without a title is named after its app and one without a client omits it`() {
        seed(item("a", title = null, client = null))

        showLibrary()

        compose.onNodeWithText("WhatsApp call").assertIsDisplayed()
        compose.onNodeWithText("42:10").assertIsDisplayed()
    }

    @Test
    fun `rows are ordered newest first`() {
        seed(
            item("old", title = "Old call", startedAt = "2026-10-01T09:00:00+00:00"),
            item("new", title = "New call", startedAt = "2026-10-24T09:00:00+00:00"),
            item("mid", title = "Mid call", startedAt = "2026-10-12T09:00:00+00:00")
        )

        showLibrary()

        val tops = listOf("New call", "Mid call", "Old call").map { compose.onNodeWithText(it).fetchSemanticsNode().boundsInRoot.top }
        assertEquals(tops.sorted(), tops)
    }

    @Test
    fun `source badges use the server labels and a desktop recording is marked Desktop`() {
        seed(item("z", title = "Zoom review", sourceDevice = "desktop", sourceApp = "zoom"))

        showLibrary()

        compose.onNodeWithText("Zoom review").assertIsDisplayed()
        compose.onAllNodesWithContentDescription("Desktop", substring = true).assertCountEquals(1)
    }

    @Test
    fun `tapping a recording opens it`() {
        seed(item("a", title = "Call A"), item("b", title = "Call B"))
        showLibrary()

        compose.onNodeWithText("Call B").performClick()

        assertEquals("b", opened?.id)
    }

    // ---- status dots ------------------------------------------------------------------------------------------------------

    @Test
    fun `each row tells ready processing and failed recordings apart`() {
        seed(
            item("ready", title = "Ready call", status = "completed", finalStatus = "completed"),
            item("busy", title = "Busy call", status = "processing", finalStatus = "processing"),
            item("uploading", title = "Uploading call", status = "uploading", finalStatus = null),
            item("failed", title = "Failed call", status = "failed", finalStatus = "failed"),
            item("failedtx", title = "Failed transcript", status = "completed", finalStatus = "failed")
        )

        showLibrary()

        compose.onAllNodes(state("Ready")).assertCountEquals(1)
        compose.onAllNodes(state("Processing")).assertCountEquals(2)
        compose.onAllNodes(state("Failed")).assertCountEquals(2)
    }

    @Test
    fun `a session that is completed without a final transcript yet still counts as ready`() {
        seed(item("a", status = "completed", finalStatus = null))

        showLibrary()

        compose.onAllNodes(state("Ready")).assertCountEquals(1)
    }

    // ---- search ------------------------------------------------------------------------------------------------------------

    private fun seedSearchLibrary() = seed(
        item("a", title = "Budget review", preview = "We cut the marketing spend", client = "Acme Holdings", sourceApp = "zoom"),
        item("b", title = "Voice note 14", preview = "Remember to call the plumber", client = null, sourceApp = "voice_note"),
        item("c", title = "Quarterly planning", preview = "Hiring plan approved", client = "Zed Logistics", sourceApp = "google_meet")
    )

    @Test
    fun `search matches titles`() {
        seedSearchLibrary()
        showLibrary()

        search("budget")

        compose.onNodeWithText("Budget review").assertIsDisplayed()
        compose.onAllNodesWithText("Voice note 14").assertCountEquals(0)
        compose.onAllNodesWithText("Quarterly planning").assertCountEquals(0)
    }

    @Test
    fun `search matches transcript previews`() {
        seedSearchLibrary()
        showLibrary()

        search("plumber")

        compose.onNodeWithText("Voice note 14").assertIsDisplayed()
        compose.onAllNodesWithText("Budget review").assertCountEquals(0)
    }

    @Test
    fun `search matches client names`() {
        seedSearchLibrary()
        showLibrary()

        search("zed log")

        compose.onNodeWithText("Quarterly planning").assertIsDisplayed()
        compose.onAllNodesWithText("Budget review").assertCountEquals(0)
    }

    @Test
    fun `search matches the source app label`() {
        seedSearchLibrary()
        showLibrary()

        search("google meet")

        compose.onNodeWithText("Quarterly planning").assertIsDisplayed()
        compose.onAllNodesWithText("Voice note 14").assertCountEquals(0)
    }

    @Test
    fun `search ignores case and surrounding spaces`() {
        seedSearchLibrary()
        showLibrary()

        search("   BUDGET REVIEW  ")

        compose.onNodeWithText("Budget review").assertIsDisplayed()
        compose.onAllNodesWithText("Quarterly planning").assertCountEquals(0)
    }

    @Test
    fun `a search with no match explains that nothing matches and clearing it restores the list`() {
        seedSearchLibrary()
        showLibrary()

        search("zzz-no-such-thing")

        compose.onNodeWithText("Nothing matches").assertIsDisplayed()
        compose.onNodeWithText("Try a different search or filter.").assertIsDisplayed()
        compose.onAllNodesWithText("Budget review").assertCountEquals(0)

        compose.onNode(hasSetTextAction()).performTextClearance()
        compose.onNodeWithContentDescription("Leave search").performClick()

        compose.onNodeWithText("Budget review").assertIsDisplayed()
        compose.onNodeWithText("Voice note 14").assertIsDisplayed()
        compose.onNodeWithText("Quarterly planning").assertIsDisplayed()
    }

    @Test
    fun `search treats special characters literally`() {
        seed(item("a", title = "Q&A (draft) [v2] 100%"), item("b", title = "Other"))
        showLibrary()

        search("(draft) [v2] 100%")

        compose.onNodeWithText("Q&A (draft) [v2] 100%").assertIsDisplayed()
        compose.onAllNodesWithText("Other").assertCountEquals(0)
    }

    @Test
    fun `search finds non-ASCII text`() {
        seed(item("a", title = "Réunion équipe", preview = "你好世界"), item("b", title = "Other"))
        showLibrary()

        search("你好")

        compose.onNodeWithText("Réunion équipe").assertIsDisplayed()
        compose.onAllNodesWithText("Other").assertCountEquals(0)
    }

    // ---- source filters ---------------------------------------------------------------------------------------------------

    private fun seedMixedDevices() = seed(
        item("p", title = "Phone call", sourceDevice = "phone"),
        item("d", title = "Desktop meeting", sourceDevice = "desktop", sourceApp = "zoom"),
        item("w", title = "Web upload", sourceDevice = "web", sourceApp = "other")
    )

    @Test
    fun `the Source choices add up and clearing them shows everything again`() {
        seedMixedDevices()
        showLibrary()

        filter("Source", "Desktop")
        compose.onNodeWithText("Desktop meeting").assertIsDisplayed()
        compose.onAllNodesWithText("Phone call").assertCountEquals(0)
        compose.onAllNodesWithText("Web upload").assertCountEquals(0)

        // Choices in one chip add up.
        filter("Source", "Web")
        compose.onNodeWithText("Web upload").assertIsDisplayed()
        compose.onNodeWithText("Desktop meeting").assertIsDisplayed()
        compose.onAllNodesWithText("Phone call").assertCountEquals(0)

        clearFilter("Source")
        compose.onNodeWithText("Phone call").assertIsDisplayed()
        compose.onNodeWithText("Desktop meeting").assertIsDisplayed()
        compose.onNodeWithText("Web upload").assertIsDisplayed()
    }

    @Test
    fun `a filter with no recordings shows the nothing matches state`() {
        seed(item("p", title = "Phone call", sourceDevice = "phone"))
        showLibrary()

        filter("Source", "Desktop")

        compose.onNodeWithText("Nothing matches").assertIsDisplayed()
    }

    @Test
    fun `search and device filter combine`() {
        seedMixedDevices()
        showLibrary()

        filter("Source", "Desktop")
        search("phone")

        compose.onNodeWithText("Nothing matches").assertIsDisplayed()
        compose.onNode(hasSetTextAction()).performTextClearance()
        search("meeting")
        compose.onNodeWithText("Desktop meeting").assertIsDisplayed()
    }

    // ---- banner and sync -----------------------------------------------------------------------------------------------------

    @Test
    fun `the call setup banner is shown only when asked and opens the setup guide`() {
        seed(item("a"))
        showLibrary(showCallSetup = true)

        compose.onNodeWithText("WhatsApp calls are not being recorded", substring = true).assertIsDisplayed()

        compose.onNodeWithText("Set up").performClick()

        assertEquals(1, setupTaps)
    }

    @Test
    fun `no banner appears when call capture is already set up`() {
        showLibrary(showCallSetup = false)

        compose.onAllNodesWithText("WhatsApp calls are not being recorded", substring = true).assertCountEquals(0)
        assertEquals(0, setupTaps)
    }

    @Test
    fun `Sync now in the menu asks the server for new recordings`() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(listOf(item("a", title = "Fresh call")))))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        showLibrary()

        compose.onNodeWithContentDescription("More").performClick()
        compose.onNodeWithText("Sync now").performClick()
        pumpUntil("the library and the clients to sync") {
            server.requestsTo("GET", "/api/library").isNotEmpty() &&
                server.requestsTo("GET", "/api/clients").isNotEmpty() &&
                vm.library.value.isNotEmpty()
        }
        compose.waitForIdle()

        compose.onNodeWithText("Fresh call").assertIsDisplayed()
        assertNull(opened)
        assertTrue(server.requestsTo("GET", "/api/clients").isNotEmpty())
    }

    @Test
    fun `a recording deleted on another device disappears after the next sync`() {
        seed(item("a", title = "Doomed call"), item("b", title = "Surviving call"))
        showLibrary()
        compose.onNodeWithText("Doomed call").assertIsDisplayed()
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(200, ServerFixtures.libraryPage(listOf(ServerFixtures.tombstone("a")), null, "2026-10-24T10:00:00+00:00"))
        )

        runBlocking { app.graph.library.refresh() }
        compose.waitForIdle()

        compose.onAllNodesWithText("Doomed call").assertCountEquals(0)
        compose.onNodeWithText("Surviving call").assertIsDisplayed()
    }
}
