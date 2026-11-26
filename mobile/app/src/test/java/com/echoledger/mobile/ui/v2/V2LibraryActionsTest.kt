package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isSelectable
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
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
import com.echoledger.mobile.ui.v2.library.KIND_ROW_TAG
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
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
                        // Where the app's shell puts it, so a toast never covers the top bar here either.
                        com.echoledger.mobile.ui.v2.components.EchoToastHost(
                            toasts,
                            androidx.compose.ui.Modifier.align(androidx.compose.ui.Alignment.BottomCenter)
                        )
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
        // The ⌕ in the title row opens search over the page (v2.1 UX-14).
        compose.onNodeWithContentDescription("Search recordings").performClick()
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
        // (Not the tab "All · 2" under the search.)
        val header = compose.onAllNodes(
            hasClickAction() and hasText(" · 2", substring = true) and
                !hasAnyAncestor(hasTestTag(KIND_ROW_TAG))
        ).onFirst()
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
        // The avatar's menu (v2.1 UX-14: no ⋮).
        compose.onNodeWithContentDescription("Account and settings").performClick()
        click("Sync now")
        pumpUntil("synced") { server.requestsTo("GET", "/api/library").size > before }
    }

    @Test
    fun groupByAndDensityAreChosenInTheSheet() {
        seed()
        // Sort & display, the chips row's last chip (v2.1 UX-14; it was in ⋮).
        click("Sort & display")
        compose.onNode(hasText("Project") and isSelectable()).performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("grouped") { vm.settings.value.libraryViews.values.any { it.group == "project" } }
        compose.onNode(hasText("Compact") and isSelectable()).performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("compact") { vm.settings.value.libraryViews.values.any { it.density == "compact" } }
    }

    @Test
    fun nothingMatchesOffersToClearTheFilters() {
        seed()
        compose.onNode(hasContentDescription("Filters") and hasClickAction()).performClick()
        // Each choice says how many it would keep: none has failed.
        compose.onNode(hasText("Failed") and isToggleable()).performScrollTo().performClick()
        click("Show 0 results")
        compose.onNodeWithText("No recordings match these filters").assertExists()
        click("Clear filters")
        compose.onNodeWithText("Bilal Traders", substring = true).assertExists()
    }

    // ---- Mark as read and unread (contract 2.0.0-y) -----------------------------------------------------------------

    private fun unread(id: String, title: String) =
        ServerFixtures.libraryItem(id, title = title).replace("\"updated_at\"", "\"opened\":false,\"updated_at\"")

    private fun idsOf(request: mockwebserver3.RecordedRequest) =
        com.echoledger.mobile.data.ApiJson.parseToJsonElement(request.bodyText()).jsonObject.getValue("ids").jsonArray.map {
            it.jsonPrimitive.content
        }

    /** A 2.0.0-y server: every id asked is done. */
    private fun doneAll(request: mockwebserver3.RecordedRequest) =
        FakeServer.json(200, """{"done":[${idsOf(request).joinToString(",") { "\"$it\"" }}],"not_found":[]}""")

    private fun said(words: String) = pumpUntil(words) { compose.onAllNodesWithText(words).fetchSemanticsNodes().isNotEmpty() }

    /** Opens the title's menu and Unread's own ⋮. */
    private fun unreadsMenu() {
        val title = SemanticsMatcher.expectValue(SemanticsProperties.Role, Role.DropdownList) and
            SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading)
        compose.onNode(title).performClick()
        compose.waitForIdle()
        compose.onNode(hasContentDescription("Unread options")).performScrollTo().performClick()
        compose.waitForIdle()
    }

    @Test
    fun markAsReadAndAsUnreadForTheSelectionInOneRequest() {
        seed(listOf(unread("01JA", "Caller 001"), ServerFixtures.libraryItem("01JB", title = "Caller 002")))
        server.on("POST", "/api/sessions/opened") { doneAll(it) }
        server.on("DELETE", "/api/sessions/opened") { doneAll(it) }
        select("Caller 001")
        compose.onNodeWithText("Caller 002", substring = true).performClick()
        compose.onNodeWithText("2 selected").assertExists()
        compose.onNodeWithContentDescription("More").performClick()
        click("Mark as read")
        said("2 recordings marked as read")
        assertEquals(listOf(listOf("01JA", "01JB")), server.requestsTo("POST", "/api/sessions/opened").map { idsOf(it).sorted() })
        compose.onNodeWithText("2 selected").assertDoesNotExist()
        assertEquals(true, app.graph.library.items.value.first { it.id == "01JA" }.opened)
        compose.mainClock.advanceTimeBy(6_000) // the first toast's time runs out, so the next one shows
        select("Caller 001")
        compose.onNodeWithText("1 selected").assertExists()
        compose.onNodeWithContentDescription("More").performClick()
        click("Mark as unread")
        said("1 recording marked as unread")
        assertEquals(listOf("01JA"), idsOf(server.requestsTo("DELETE", "/api/sessions/opened").single()))
        assertEquals(false, app.graph.library.items.value.first { it.id == "01JA" }.opened)
    }

    /** Review of 2.0.0-y: only what was done leaves the selection; a tick made while it ran stays. */
    @Test
    fun aTickMadeWhileMarkingRunsStaysSelected() {
        seed(listOf(unread("01JA", "Caller 001"), unread("01JB", "Caller 002"), unread("01JC", "Caller 003")))
        val gate = server.hold()
        server.on("POST", "/api/sessions/opened") {
            gate.await(30)
            doneAll(it)
        }
        select("Caller 001")
        compose.onNodeWithText("Caller 002", substring = true).performClick()
        compose.onNodeWithContentDescription("More").performClick()
        click("Mark as read")
        pumpUntil("on its way") { server.requestsTo("POST", "/api/sessions/opened").isNotEmpty() }
        compose.onNodeWithText("Caller 003", substring = true).performClick()
        compose.onNodeWithText("3 selected").assertExists()
        gate.open()
        said("2 recordings marked as read")
        compose.onNodeWithText("1 selected").assertExists()
        assertEquals(listOf("01JA", "01JB"), idsOf(server.requestsTo("POST", "/api/sessions/opened").single()).sorted())
    }

    @Test
    fun unreadsMarkAllAsReadSends450InThreeRequests() {
        seed((1..450).map { unread("01K%03d".format(it), "Caller %03d".format(it)) })
        server.on("POST", "/api/sessions/opened") { doneAll(it) }
        unreadsMenu()
        click("Mark all as read")
        said("450 recordings marked as read")
        assertEquals(listOf(200, 200, 50), server.requestsTo("POST", "/api/sessions/opened").map { idsOf(it).size })
        assertTrue(app.graph.library.items.value.all { it.opened == true })
    }

    @Test
    fun onAnOlderServerMarkAllAsReadStopsAt200AndSaysToDoItAgain() {
        seed((1..450).map { unread("01K%03d".format(it), "Caller %03d".format(it)) })
        server.on("POST", "/api/sessions/opened", FakeServer.json(405, """{"message":"Not allowed","code":"method_not_allowed"}"""))
        server.on("POST", "/api/sessions/01K\\d+/opened", FakeServer.empty(204))
        unreadsMenu()
        click("Mark all as read")
        said("200 of 450 marked. Do it again for the rest.")
        assertEquals(200, server.requestsTo("POST", "/api/sessions/01K\\d+/opened").size)
    }
}
