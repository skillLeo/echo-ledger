package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isSelectable
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeLeft
import androidx.compose.ui.test.swipeRight
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Library's classic features in v2 (docs/v2/audit/parity.csv PH-LIB): selection mode's Move, Label, Export, Rename and
 * Keep; the swipes; sections that fold and select; Sync now; Group by and Density; "Nothing matches" with Clear filters.
 */
class V2LibraryActionsTest : ScreenTest() {
    private var openedAt: Pair<String, Long>? = null

    private fun seed(rows: List<String>? = null) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    rows ?: listOf(
                        ServerFixtures.libraryItem("01JA", title = "Bilal Traders", preview = "New rate for 40 cartons of basmati"),
                        ServerFixtures.libraryItem("01JB", title = "Rehman and Sons Textiles", sourceApp = "phone_call", finalStatus = null)
                    )
                )
            )
        )
        server.on("POST", "/api/sessions/bulk", FakeServer.json(200, """{"changed": 1}"""))
        server.on("DELETE", "/api/sessions/01JA", FakeServer.empty(204))
        runBlocking { app.graph.library.refresh() }
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    androidx.compose.foundation.layout.Box {
                        V2LibraryScreen(
                            vm,
                            initials = "AS",
                            onOpen = {},
                            onTrash = {},
                            onAccount = {},
                            onOpenAt = { item, ms -> openedAt = item.id to ms }
                        )
                        com.echoledger.mobile.ui.v2.components.EchoToastHost(toasts)
                    }
                }
            }
        }
        val first = if (rows == null) "Bilal Traders" else "Caller 001"
        pumpUntil("the library") { compose.onAllNodesWithText(first, substring = true).fetchSemanticsNodes().isNotEmpty() }
    }

    private fun select(title: String) = compose.onNodeWithText(title, substring = true).performTouchInput { longClick() }

    private fun click(text: String) {
        compose.onAllNodes(hasText(text) and hasClickAction()).onFirst().performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private fun bulk(action: String): String {
        pumpUntil("bulk $action") { server.requestsTo("POST", "/api/sessions/bulk").any { it.bodyText().contains("\"$action\"") } }
        return server.requestsTo("POST", "/api/sessions/bulk").last { it.bodyText().contains("\"$action\"") }.bodyText()
    }

    @Test
    fun moveToAProjectAndToANewOne() {
        seed()
        server.on("POST", "/api/clients", FakeServer.json(201, """{"data": {"id": "01JNEW", "name": "Karachi Cargo"}}"""))
        select("Bilal Traders")
        compose.onNodeWithContentDescription("Move to project").performClick()
        click("Acme Holdings")
        assertTrue(bulk("move").contains("01JA"))
        select("Bilal Traders")
        compose.onNodeWithContentDescription("Move to project").performClick()
        click("New project")
        compose.onNode(hasSetTextAction()).performTextInput("Karachi Cargo")
        click("Create")
        pumpUntil("the new project") { server.requestsTo("POST", "/api/clients").isNotEmpty() }
        pumpUntil("moved there") { server.requestsTo("POST", "/api/sessions/bulk").any { it.bodyText().contains("01JNEW") } }
    }

    @Test
    fun labelAddsANewLabel() {
        seed()
        select("Bilal Traders")
        compose.onNodeWithContentDescription("Label").performClick()
        compose.onNode(hasSetTextAction()).performTextInput("Urgent")
        click("Add")
        assertTrue(bulk("label").contains("Urgent"))
    }

    @Test
    fun exportAsEachFormatAsksTheServer() {
        V2Testing.freshFileProvider()
        seed()
        server.on("POST", "/api/sessions/export", FakeServer.json(200, "{}"))
        for ((format, name) in listOf(
            "docx" to "Word (.docx)",
            "txt" to "Text (.txt)",
            "md" to "Markdown (.md)",
            "srt" to "Subtitles (.srt)",
            "vtt" to "Web subtitles (.vtt)",
            "json" to "JSON"
        )) {
            if (compose.onAllNodesWithText("1 selected").fetchSemanticsNodes().isEmpty()) select("Bilal Traders")
            // ⋮ › Export ▸ › the format.
            compose.onNodeWithContentDescription("More").performClick()
            click("Export")
            click(name)
            pumpUntil("export $format") { server.requestsTo("POST", "/api/sessions/export").any { it.bodyText().contains("\"$format\"") } }
            // Shared before the next one is asked for (each export replaces the last file).
            pumpUntil("shared $format") { org.robolectric.Shadows.shadowOf(app).nextStartedActivity != null }
        }
        // ⋮ › Share asks for the format in a sheet, as the bar's Share did.
        val before = server.requestsTo("POST", "/api/sessions/export").size
        if (compose.onAllNodesWithText("1 selected").fetchSemanticsNodes().isEmpty()) select("Bilal Traders")
        compose.onNodeWithContentDescription("More").performClick()
        click("Share")
        click("Word (.docx)")
        pumpUntil("asked") { server.requestsTo("POST", "/api/sessions/export").size > before }
        pumpUntil("shared") { org.robolectric.Shadows.shadowOf(app).nextStartedActivity != null }
    }

    @Test
    fun renameAndKeepForeverFromTheMenu() {
        seed()
        server.on("PATCH", "/api/sessions/01JA", FakeServer.json(200, ServerFixtures.libraryItem("01JA", title = "Bilal Traders, rate")))
        select("Bilal Traders")
        compose.onNodeWithContentDescription("More").performClick()
        click("Keep forever")
        bulk("keep")
        // Now kept on the server: the menu offers Allow auto clean-up, and no longer Keep forever.
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        ServerFixtures.libraryItem(
                            "01JA",
                            title = "Bilal Traders"
                        ).replace("\"deleted\":false", "\"deleted\":false,\"keep\":true"),
                        ServerFixtures.libraryItem("01JB", title = "Rehman and Sons Textiles", sourceApp = "phone_call", finalStatus = null)
                    )
                )
            )
        )
        runBlocking { app.graph.library.refresh() }
        pumpUntil("kept") { vm.library.value.any { it.id == "01JA" && it.keep } }
        select("Bilal Traders")
        compose.onNodeWithContentDescription("More").performClick()
        compose.onNodeWithText("Keep forever").assertDoesNotExist()
        click("Allow auto clean-up")
        bulk("unkeep")
        select("Bilal Traders")
        compose.onNodeWithContentDescription("More").performClick()
        click("Rename")
        compose.onNode(hasSetTextAction()).performTextInput(", rate")
        click("Save")
        pumpUntil("renamed") { server.requestsTo("PATCH", "/api/sessions/01JA").isNotEmpty() }
    }

    @Test
    fun swipeRightPinsAndSwipeLeftDeletes() {
        seed()
        server.on("POST", "/api/sessions/01JB/restore", FakeServer.empty(204))
        compose.onNodeWithText("Rehman and Sons Textiles", substring = true).performTouchInput { swipeLeft() }
        // Classic's path: bulk "delete"; Undo shows once it went and brings it back.
        assertTrue(bulk("delete").contains("01JB"))
        pumpUntil("Recording deleted · Undo") { compose.onAllNodesWithText("Recording deleted").fetchSemanticsNodes().isNotEmpty() }
        click("Undo")
        pumpUntil("restored") { server.requestsTo("POST", "/api/sessions/01JB/restore").isNotEmpty() }
        compose.onNodeWithText("Bilal Traders", substring = true).performTouchInput { swipeRight() }
        bulk("pin")
    }

    @Test
    fun undoAfterDeletingManyRestoresEveryOne() {
        seed()
        server.on("POST", "/api/sessions/bulk", FakeServer.json(200, """{"changed": 2}"""))
        server.on("POST", "/api/sessions/01JA/restore", FakeServer.empty(204))
        server.on("POST", "/api/sessions/01JB/restore", FakeServer.empty(204))
        select("Bilal Traders")
        compose.onNodeWithText("Rehman and Sons Textiles", substring = true).performClick()
        compose.onNodeWithContentDescription("Delete").performClick()
        click("Delete 2")
        val body = bulk("delete")
        assertTrue(body, body.contains("01JA") && body.contains("01JB"))
        pumpUntil("2 deleted · Undo") { compose.onAllNodesWithText("2 deleted").fetchSemanticsNodes().isNotEmpty() }
        click("Undo")
        pumpUntil("both restored") {
            server.requestsTo("POST", "/api/sessions/01JA/restore").isNotEmpty() &&
                server.requestsTo("POST", "/api/sessions/01JB/restore").isNotEmpty()
        }
        pumpUntil("2 restored") { compose.onAllNodesWithText("2 restored").fetchSemanticsNodes().isNotEmpty() }
    }

    @Test
    fun whenTheServerDeletesOneOfTwoUndoBringsBackOnlyThatOne() {
        seed()
        server.on("POST", "/api/sessions/bulk", FakeServer.json(200, """{"changed": 1}"""))
        server.on("POST", "/api/sessions/01JA/restore", FakeServer.empty(204))
        server.on("POST", "/api/sessions/01JB/restore", FakeServer.empty(204))
        select("Bilal Traders")
        compose.onNodeWithText("Rehman and Sons Textiles", substring = true).performClick()
        // After the delete the server has Bilal Traders gone and Rehman and Sons still there.
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        ServerFixtures.tombstone("01JA"),
                        ServerFixtures.libraryItem("01JB", title = "Rehman and Sons Textiles", sourceApp = "phone_call", finalStatus = null)
                    )
                )
            )
        )
        compose.onNodeWithContentDescription("Delete").performClick()
        click("Delete 2")
        bulk("delete")
        pumpUntil("Recording deleted · Undo") { compose.onAllNodesWithText("Recording deleted").fetchSemanticsNodes().isNotEmpty() }
        click("Undo")
        pumpUntil("restored") { server.requestsTo("POST", "/api/sessions/01JA/restore").isNotEmpty() }
        assertTrue(server.requestsTo("POST", "/api/sessions/01JB/restore").isEmpty())
    }

    @Test
    fun whenTheSyncAfterAPartialDeleteFailsItSaysHowManyWithoutUndo() {
        seed()
        server.on("POST", "/api/sessions/bulk", FakeServer.json(200, """{"changed": 1}"""))
        select("Bilal Traders")
        compose.onNodeWithText("Rehman and Sons Textiles", substring = true).performClick()
        // The sync after the delete fails: which one went is not known.
        server.on("GET", "/api/library", FakeServer.json(500, """{"message":"Server error","code":"server_error"}"""))
        compose.onNodeWithContentDescription("Delete").performClick()
        click("Delete 2")
        bulk("delete")
        pumpUntil("Recording deleted") { compose.onAllNodesWithText("Recording deleted").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Undo").assertDoesNotExist()
    }

    @Test
    fun aSelectedRowThatIsNoLongerShownIsSkipped() {
        seed()
        select("Bilal Traders")
        compose.onNodeWithText("Rehman and Sons Textiles", substring = true).performClick()
        compose.onNodeWithText("2 selected").assertExists()
        // Another device deleted Rehman and Sons: after the sync only Bilal Traders is shown, and only it is deleted.
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(ServerFixtures.libraryItem("01JA", title = "Bilal Traders"), ServerFixtures.tombstone("01JB"))
                )
            )
        )
        runBlocking { app.graph.library.refresh() }
        pumpUntil("synced") { compose.onAllNodesWithText("Rehman and Sons Textiles", substring = true).fetchSemanticsNodes().isEmpty() }
        compose.onNodeWithText("1 selected").assertExists()
        compose.onNodeWithContentDescription("Delete").performClick()
        click("Delete 1")
        val body = bulk("delete")
        assertTrue(body, body.contains("01JA") && !body.contains("01JB"))
    }

    @Test
    fun selectAllStopsAtWhatTheServerTakesAtOnceAndSaysWhy() {
        seed((1..205).map { ServerFixtures.libraryItem("01K%03d".format(it), title = "Caller %03d".format(it)) })
        select("Caller 001")
        compose.onNodeWithContentDescription("More").performClick()
        click("Select all")
        compose.onNodeWithText("200 selected").assertExists()
        pumpUntil("the reason") {
            compose.onAllNodesWithText("You can select up to 200 recordings at a time.").fetchSemanticsNodes().isNotEmpty()
        }
    }

    @Test
    fun searchFindsTranscriptWordsOnTheServerAndOpensAtTheMoment() {
        seed()
        server.on(
            "GET",
            "/api/search",
            FakeServer.json(
                200,
                """{"data":[{"session":${ServerFixtures.libraryItem("01JB", title = "Rehman and Sons Textiles")},
                   "hits":[{"transcript_id":"t","segment_index":3,"start_ms":65000,"speaker":"Client",
                   "snippet":"the invoice for June","highlights":[[4,11]]}],"hit_count":1}],"next_page":null}"""
            )
        )
        compose.onAllNodes(hasSetTextAction()).onFirst().performTextInput("invoice")
        compose.waitUntil(10_000) { server.requestsTo("GET", "/api/search").any { it.url.query.orEmpty().contains("q=invoice") } }
        compose.waitUntil(10_000) { compose.onAllNodesWithText("Transcripts").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("the invoice for June").performClick()
        assertEquals("01JB" to 65_000L, openedAt)
    }

    @Test
    fun sectionsFoldAndSelectAsAWhole() {
        seed()
        // Both recordings fall on one day: its section folds them away and back.
        val header = compose.onAllNodes(hasClickAction() and hasText(" · 2", substring = true)).onFirst()
        header.performClick()
        compose.onNodeWithText("Bilal Traders", substring = true).assertDoesNotExist()
        header.performClick()
        compose.onNodeWithText("Bilal Traders", substring = true).assertExists()
        select("Bilal Traders")
        compose.onNode(hasContentDescription("Select", substring = true) and isToggleable()).performClick()
        compose.onNodeWithText("2 selected").assertExists()
    }

    @Test
    fun syncNowAsksTheServerAgain() {
        seed()
        val before = server.requestsTo("GET", "/api/library").size
        compose.onNodeWithContentDescription("More").performClick()
        click("Sync now")
        pumpUntil("synced") { server.requestsTo("GET", "/api/library").size > before }
    }

    @Test
    fun groupByAndDensityAreChosenInTheSheet() {
        seed()
        compose.onNodeWithContentDescription("Sort and group").performClick()
        compose.onNode(hasText("Project") and isSelectable()).performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("grouped") { vm.settings.value.libraryViews.values.any { it.group == "project" } }
        compose.onNode(hasText("Compact") and isSelectable()).performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("compact") { vm.settings.value.libraryViews.values.any { it.density == "compact" } }
    }

    @Test
    fun nothingMatchesOffersToClearTheFilters() {
        seed()
        compose.onNodeWithText("Status ▾").performClick()
        // Each choice says how many it holds, as classic: none are waiting.
        compose.onNode(hasText("Waiting for decision (0)") and isToggleable()).performClick()
        click("Apply")
        compose.onNodeWithText("Nothing matches").assertExists()
        click("Clear filters")
        compose.onNodeWithText("Bilal Traders", substring = true).assertExists()
    }
}
