package com.echoledger.mobile.ui.v2

import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasImeAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isSelectable
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.LayoutDirection
import com.echoledger.mobile.R
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.KIND_ROW_TAG
import com.echoledger.mobile.ui.v2.library.PILLS_ROW_TAG
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The phone's Library filters and views (PLAN_LIBRARY_FILTERS §2, PLAN_NAV_3_LEVELS §7, contract 2.0.0-p): the title
 * "All recordings ▾" and its menu (Views with their counts, My views, Labels, Trash), the tabs All · Calls · Meetings ·
 * Voice notes with counts, the Filters sheet (each section's options with live counts, is / is not, each section's
 * Clear, "Show N results"), the pills with Clear all, Save as view and opening a view again, a refusal said, and the same
 * in Urdu, right to left.
 */
class V2LibraryFiltersTest : ScreenTest() {
    private companion object {
        const val RECORDING = "\"status\":\"recording\""
    }

    private val now: ZonedDateTime by lazy { ZonedDateTime.now() }
    private val today: String by lazy {
        minOf(now, now.toLocalDate().atStartOfDay(now.zone).plusMinutes(1)).format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)
    }
    private val longAgo: String by lazy { now.minusDays(60).format(DateTimeFormatter.ISO_OFFSET_DATE_TIME) }
    private val trashed = mutableListOf<String>()
    private val shared = mutableListOf<String>()

    private fun row(id: String, title: String, app: String, extra: String, startedAt: String = longAgo) =
        ServerFixtures.libraryItem(id, title = title, sourceApp = app, startedAt = startedAt, client = null)
            .replace("\"updated_at\"", "$extra,\"updated_at\"")

    private fun seed(
        language: EchoLanguage = EchoLanguage.English,
        views: String = """{"data":[],"views_version":0,"limit":30}""",
        more: List<String> = emptyList()
    ) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        val rows = listOf(
            row("01JA", "Rec Alpha", "whatsapp", "\"labels\":[\"Clients\"],\"opened\":false", today),
            row("01JB", "Rec Bravo", "zoom", "\"labels\":[],\"opened\":true,\"important\":true"),
            row("01JC", "Rec Charlie", "voice_note", "\"labels\":[],\"opened\":true", today),
            row("01JD", "Rec Delta", "skype", "\"labels\":[\"Clients\"],\"opened\":false"),
            row("01JE", "Rec Echo", "slack", "\"labels\":[],\"opened\":true,\"pinned\":true")
        ) + more
        server.on("GET", "/api/library") { req -> FakeServer.json(200, library(rows, req)) }
        server.on(
            "GET",
            "/api/lists",
            FakeServer.json(200, """{"data":[{"key":"clients","name":"Clients","color":"#e15f56","position":0,"count":2}]}""")
        )
        server.on("GET", "/api/search.*", FakeServer.json(200, """{"data":[]}"""))
        server.on("GET", "/api/views", FakeServer.json(200, views))
        // Whose views: this sign-in's account read (the email and the workspace).
        server.on("GET", "/api/account", FakeServer.json(200, """{"email":"asad@example.com","workspace":{"id":7,"name":"Bilal"}}"""))
        vm.refreshQuietly()
        val waiting = rows.count { it.contains(RECORDING) }
        pumpUntil("the account's views") {
            vm.savedViewsReady.value && vm.library.value.size == rows.size - waiting && vm.libraryWaiting.value.size == waiting
        }
        val page: @androidx.compose.runtime.Composable () -> Unit = {
            EchoV2Theme(darkTheme = false, language = language) {
                val toasts = rememberEchoToastState()
                androidx.compose.runtime.CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    androidx.compose.foundation.layout.Box {
                        V2LibraryScreen(
                            vm,
                            initials = "AS",
                            onOpen = {},
                            onTrash = { trashed += "trash" },
                            onAccount = {},
                            onSharedWithMe = { shared += "with me" },
                            onRequests = { shared += "requests" },
                            onReviewWaiting = { shared += "review" }
                        )
                        EchoToastHost(toasts)
                    }
                }
            }
        }
        show(page)
        pumpUntil("the library") { compose.onAllNodesWithText("Rec ", substring = true).fetchSemanticsNodes().size >= 5 }
    }

    /**
     * `GET /api/library` as LibraryController answers it: a session still `recording` (a call waiting for Keep or
     * Discard) only when `status`, `view=waiting` or `view=needs_you` asks for it; `status` keeps that status only.
     */
    private fun library(rows: List<String>, req: mockwebserver3.RecordedRequest): String {
        val status = req.url.queryParameter("status")
        val waitingToo = req.url.queryParameter("view") in setOf("waiting", "needs_you")
        val kept = rows.filter { r ->
            when {
                status != null -> r.contains("\"status\":\"$status\"")
                else -> waitingToo || !r.contains(RECORDING)
            }
        }
        return ServerFixtures.libraryPage(kept).replace("\"server_time\"", "\"views_version\":3,\"server_time\"")
    }

    private fun shown(vararg titles: String) {
        val all = listOf("Rec Alpha", "Rec Bravo", "Rec Charlie", "Rec Delta", "Rec Echo")
        pumpUntil("only ${titles.toList()}") {
            all.all { t -> compose.onAllNodesWithText(t).fetchSemanticsNodes().isNotEmpty() == (t in titles) }
        }
    }

    private fun tap(words: String) {
        compose.onAllNodes(hasText(words) and hasClickAction()).onFirst().performClick()
        compose.waitForIdle()
    }

    private fun tapDescribed(words: String) {
        compose.onAllNodes(hasContentDescription(words, substring = true) and hasClickAction()).onFirst().performClick()
        compose.waitForIdle()
    }

    /** An option of the Filters sheet: a box, or Date's round choice. */
    private fun option(words: String) = compose.onAllNodes(hasText(words) and (isToggleable() or isSelectable())).onFirst()

    /** A name field of a sheet (the search field is the other one). */
    private fun nameField() = compose.onAllNodes(hasSetTextAction() and hasImeAction(ImeAction.Done)).onFirst()

    @Test
    fun theTitleIsAllRecordingsAndItsMenuHasTheViewsMyViewsLabelsAndTrash() {
        seed()
        tap("All recordings")
        // Views with their counts (Needs you and Unread bold); TalkBack hears the count with the name.
        // Views are buttons; their counts end the name, and a count of 0 is not shown.
        listOf("All recordings, 5", "Needs you", "Unread, 2", "Important, 1", "Not transcribed", "Audio only", "Clients, 2")
            .forEach {
                compose.onNode(
                    hasContentDescription(it) and SemanticsMatcher.expectValue(SemanticsProperties.Role, Role.Button)
                ).assertExists()
            }
        compose.onNode(
            hasContentDescription("All recordings, 5")
        ).assert(SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Selected"))
        compose.onAllNodesWithText("0").assertCountEquals(0)
        listOf("Views", "My views", "Labels", "Shared", "New view", "New label", "Trash").forEach {
            compose.onAllNodesWithText(it).fetchSemanticsNodes().let { n -> assertTrue(it, n.isNotEmpty()) }
        }
        // Shared opens to Shared with me and Request files.
        tap("Shared")
        tap("Shared with me")
        assertEquals(listOf("with me"), shared)
        tap("All recordings")
        compose.onNode(hasText("Trash") and hasClickAction()).performScrollTo().performClick()
        assertEquals(listOf("trash"), trashed)
        // Nothing is called Inbox.
        tap("All recordings")
        compose.onAllNodesWithText("Inbox", substring = true).assertCountEquals(0)
    }

    @Test
    fun aViewOrALabelFromTheMenuNarrowsTheListAndNamesTheTitle() {
        seed()
        tap("All recordings")
        tapDescribed("Unread, 2")
        shown("Rec Alpha", "Rec Delta")
        compose.onNode(hasText("Unread") and SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading)).assertExists()
        tap("Unread")
        tapDescribed("Important, 1")
        shown("Rec Bravo")
        tap("Important")
        tapDescribed("Clients, 2")
        shown("Rec Alpha", "Rec Delta")
        tap("Clients")
        tapDescribed("All recordings, 5")
        shown("Rec Alpha", "Rec Bravo", "Rec Charlie", "Rec Delta", "Rec Echo")
    }

    /**
     * Needs you lists the calls waiting for Transcribe or Delete (the server's still `recording`, and this phone's on
     * the card) whatever the filters, the tab and the search, while the filters, the tabs and their counts never count
     * them; anywhere else they don't show.
     */
    @Test
    fun needsYouAlwaysListsTheCallsWaitingButNeverCountsThemInTheFilters() {
        seed(
            more = listOf(
                ServerFixtures.libraryItem(
                    "01JW",
                    title = "Rec Waiting",
                    sourceApp = "zoom",
                    status = "recording",
                    finalStatus = null,
                    client = null
                )
            )
        )
        runBlocking { app.storeRecording(id = "r-omar", state = "pending_decision", startedAt = today) }
        vm.reloadLocal()
        tap("All recordings")
        // Both are in Needs you's count; All recordings is still the five.
        compose.onNode(hasContentDescription("Needs you, 2")).assertExists()
        compose.onNode(hasContentDescription("All recordings, 5")).assertExists()
        tapDescribed("Needs you, 2")
        pumpUntil("both listed") {
            compose.onAllNodesWithText("Rec Waiting").fetchSemanticsNodes().isNotEmpty() &&
                compose.onAllNodesWithText("Waiting: Transcribe or Delete").fetchSemanticsNodes().isNotEmpty()
        }
        // The tabs count none of them.
        compose.onNode(hasText("All · 0") and hasAnyAncestor(hasTestTag(KIND_ROW_TAG))).assertExists()
        // A filter keeps nothing else, and they stay listed.
        tapDescribed("Filters")
        compose.onNodeWithText("Show 0 results").assertExists()
        option("Pinned").performScrollTo().performClick()
        tap("Show 0 results")
        assertTrue(compose.onAllNodesWithText("Rec Waiting").fetchSemanticsNodes().isNotEmpty())
        assertTrue(compose.onAllNodesWithText("Waiting: Transcribe or Delete").fetchSemanticsNodes().isNotEmpty())
        // The phone's own opens where the choice is made.
        compose.onAllNodes(hasText("Waiting: Transcribe or Delete", substring = true) and hasClickAction()).onFirst().performClick()
        assertEquals(listOf("review"), shared)
        // Anywhere else they don't show.
        tap("Needs you")
        tapDescribed("All recordings, 5")
        compose.onAllNodesWithText("Rec Waiting").assertCountEquals(0)
    }

    /**
     * A call the server still lists as waiting, already decided on this phone (Transcribe: queued to upload) while the
     * phone is offline, so the server isn't told yet: it is this phone's own, shown as its upload row in All
     * recordings, and neither listed nor counted in Needs you.
     */
    @Test
    fun aCallDecidedHereWhileOfflineIsNotWaitingInNeedsYou() {
        seed(
            more = listOf(
                ServerFixtures.libraryItem(
                    "01JW",
                    title = "Rec Waiting",
                    sourceApp = "zoom",
                    status = "recording",
                    finalStatus = null,
                    client = null
                )
            )
        )
        // Decided a moment ago (not stuck: that would be Needs you's own, after 24 hours).
        val moment = now.format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)
        val decided = runBlocking { app.storeRecording(id = "r-omar", state = "queued", startedAt = moment, endedAt = moment) }
        app.graph.recordings.save(decided.copy(serverSessionId = "01JW"))
        vm.reloadLocal()
        // Offline from now on: the server's list stays as it was read.
        server.on("*", ".*", FakeServer.json(503, """{"message":"Maintenance"}"""))
        vm.refreshQuietly()
        pumpUntil("its upload row") { compose.onAllNodesWithText("Waiting to upload").fetchSemanticsNodes().isNotEmpty() }
        assertEquals(listOf("01JW"), vm.libraryWaiting.value.map { it.id })
        tap("All recordings")
        // Needs you counts nothing (its count of 0 is not shown).
        compose.onNode(hasContentDescription("Needs you")).assertExists()
        tapDescribed("Needs you")
        compose.onNodeWithText("Nothing in Needs you yet").assertExists()
        compose.onAllNodesWithText("Rec Waiting").assertCountEquals(0)
        compose.onAllNodesWithText("Waiting: Transcribe or Delete").assertCountEquals(0)
    }

    @Test
    fun theTabsSplitByKindWithCountsAndAndWithTheView() {
        seed()
        val kinds = hasAnyAncestor(hasTestTag(KIND_ROW_TAG))
        listOf("All · 5", "Calls · 2", "Meetings · 2", "Voice notes · 1").forEach { compose.onNode(hasText(it) and kinds).assertExists() }
        // Nothing of another kind: no Other tab.
        assertTrue(compose.onAllNodes(hasText("Other", substring = true) and kinds).fetchSemanticsNodes().isEmpty())
        // Calls are WhatsApp and Skype; Meetings Zoom and Slack (docs/API.md "The Library's tabs").
        tap("Calls · 2")
        shown("Rec Alpha", "Rec Delta")
        tap("Meetings · 2")
        shown("Rec Bravo", "Rec Echo")
        // A tab is counted with the place: in Unread, Meetings has none.
        tap("All recordings")
        tapDescribed("Unread, 2")
        compose.onNode(hasText("Meetings · 0") and kinds).assertExists()
        compose.onNode(hasText("Calls · 2") and kinds).assertExists()
        shown()
    }

    /** Contract 2.0.0-q: every recording has one kind, so the tabs add up to All; Other only while it holds something. */
    @Test
    fun otherIsATabOnlyWhileItHoldsSomethingAndTheTabsAlwaysAddUpToAll() {
        seed(more = listOf(row("01JF", "Rec Foxtrot", "imported", "\"labels\":[],\"opened\":true")))
        val kinds = hasAnyAncestor(hasTestTag(KIND_ROW_TAG))
        listOf("All · 6", "Calls · 2", "Meetings · 2", "Voice notes · 1", "Other · 1").forEach {
            compose.onNode(hasText(it) and kinds).assertExists()
        }
        // The last tab: scrolled to in the row first.
        compose.onNode(hasText("Other · 1") and kinds).performScrollTo().performClick()
        compose.waitForIdle()
        pumpUntil("only the imported file") {
            compose.onAllNodesWithText("Rec Foxtrot").fetchSemanticsNodes().isNotEmpty() &&
                compose.onAllNodesWithText("Rec Alpha").fetchSemanticsNodes().isEmpty()
        }
        // A filter that leaves Other empty (the sheet counts inside the tab: none): the tab goes, the list falls back to All.
        tapDescribed("Filters")
        option("Zoom").performClick()
        tap(V2Testing.resources(EchoLanguage.English).getQuantityString(R.plurals.v2_lf_show_results, 0, 0))
        pumpUntil("Other gone") { compose.onAllNodes(hasText("Other", substring = true) and kinds).fetchSemanticsNodes().isEmpty() }
        compose.onNode(hasText("All · 1") and kinds).assertIsSelected()
        pumpUntil("back on All") { compose.onAllNodesWithText("Rec Bravo").fetchSemanticsNodes().isNotEmpty() }
    }

    /** The Library opened on a kept Other, its first frame before the library is read (back from the background). */
    private fun openOnOtherBeforeTheLibraryIsRead(rows: List<String>) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/library") { req -> FakeServer.json(200, library(rows, req)) }
        server.on("GET", "/api/views", FakeServer.json(200, """{"data":[],"views_version":0,"limit":30}"""))
        app.graph.library.clear()
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                androidx.compose.runtime.CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2LibraryScreen(
                        vm,
                        initials = "AS",
                        onOpen = {},
                        onTrash = {},
                        onAccount = {},
                        initialKind = com.echoledger.mobile.core.LibraryKind.OTHER
                    )
                }
            }
        }
        compose.waitForIdle()
    }

    /** Review of I-07: a kept Other survives the first frame, and stays once the sync brings its recordings. */
    @Test
    fun aKeptOtherSurvivesTheFirstFrameAndStaysWhenTheSyncBringsItsRecordings() {
        openOnOtherBeforeTheLibraryIsRead(
            listOf(row("01JA", "Rec Alpha", "whatsapp", "\"labels\":[]"), row("01JF", "Rec Foxtrot", "imported", "\"labels\":[]"))
        )
        vm.refresh()
        pumpUntil("synced") { compose.onAllNodesWithText("Rec Foxtrot").fetchSemanticsNodes().isNotEmpty() }
        compose.onNode(hasText("Other · 1") and hasAnyAncestor(hasTestTag(KIND_ROW_TAG))).assertIsSelected()
        assertTrue("only Other's", compose.onAllNodesWithText("Rec Alpha").fetchSemanticsNodes().isEmpty())
    }

    /** Review of I-07: a kept Other whose sync brings none goes back to All when the sync ends, never a hidden tab. */
    @Test
    fun aKeptOtherGoesBackToAllWhenTheSyncEndsWithoutAny() {
        openOnOtherBeforeTheLibraryIsRead(listOf(row("01JA", "Rec Alpha", "whatsapp", "\"labels\":[]")))
        vm.refresh()
        pumpUntil("synced") { compose.onAllNodesWithText("Rec Alpha").fetchSemanticsNodes().isNotEmpty() && !vm.syncing.value }
        val kinds = hasAnyAncestor(hasTestTag(KIND_ROW_TAG))
        pumpUntil("back on All") {
            compose.onAllNodes(hasText("All · 1") and kinds and androidx.compose.ui.test.isSelected()).fetchSemanticsNodes().isNotEmpty()
        }
        assertTrue("no hidden tab", compose.onAllNodes(hasText("Other", substring = true) and kinds).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun theFiltersSheetCountsLiveSaysIsNotClearsASectionAndShowsTheResults() {
        seed()
        tapDescribed("Filters")
        // Each option with its count; the button says how many the choice keeps.
        compose.onNodeWithText("Show 5 results").assertExists()
        option("WhatsApp").performClick()
        option("Zoom").performClick()
        compose.onNodeWithText("Show 2 results").assertExists()
        // Source on: its "is not" and its Clear.
        tap("is not")
        compose.onNodeWithText("Show 3 results").assertExists()
        // The other sections count what Source keeps (the facet rule): Marked › Pinned 1 (Rec Echo, Slack).
        compose.onNode(hasText("Show 3 results")).assertExists()
        tap("Show 3 results")
        shown("Rec Charlie", "Rec Delta", "Rec Echo")
        // The pill says it, and [Filters 1] that one section is on.
        compose.onNode(hasText("Source is not: WhatsApp, Zoom") and hasAnyAncestor(hasTestTag(PILLS_ROW_TAG))).assertExists()
        compose.onNode(hasContentDescription("Filters, 1 on")).assertExists()
        // A tap on the pill opens the sheet there; Clear empties that section.
        tap("Source is not: WhatsApp, Zoom")
        tap("Clear")
        compose.onNodeWithText("Show 5 results").assertExists()
        tap("Show 5 results")
        shown("Rec Alpha", "Rec Bravo", "Rec Charlie", "Rec Delta", "Rec Echo")
        compose.onAllNodes(hasAnyAncestor(hasTestTag(PILLS_ROW_TAG))).assertCountEquals(0)
    }

    @Test
    fun thePillsTakeASectionOffAndClearAllTakesThemAll() {
        seed()
        tapDescribed("Filters")
        option("Today").performClick()
        option("Pinned").performScrollTo().performClick()
        tap("Show 0 results")
        shown()
        compose.onNodeWithText("No recordings match these filters").assertExists()
        compose.onNode(hasContentDescription("Remove Marked: Pinned")).performClick()
        shown("Rec Alpha", "Rec Charlie")
        compose.onNode(hasText("Date: Today") and hasAnyAncestor(hasTestTag(PILLS_ROW_TAG))).assertExists()
        tap("Clear all")
        shown("Rec Alpha", "Rec Bravo", "Rec Charlie", "Rec Delta", "Rec Echo")
    }

    @Test
    fun saveAsViewSendsTheFilterSetAndTheViewOpensAgainFromTheMenu() {
        seed()
        // The server keeps what it was sent, and answers it when the views are read again.
        val kept = java.util.concurrent.CopyOnWriteArrayList<String>()
        server.on("POST", "/api/views") { req ->
            val ref = Json.parseToJsonElement(req.bodyText()).jsonObject.getValue("client_ref").jsonPrimitive.content
            val view = """{"id":"01JV000000000000000000000A","name":"Meetings","position":0,
                "filters":{"source":{"op":"is","values":["zoom","slack"]}},"search":null,"sort":"newest","group":"date",
                "density":"comfortable","client_ref":"$ref"}"""
            if (kept.isEmpty()) kept += view
            FakeServer.json(201, """{"data":$view,"views_version":4}""")
        }
        server.on("GET", "/api/views") {
            FakeServer.json(200, """{"data":[${kept.joinToString(",")}],"views_version":${3 + kept.size},"limit":30}""")
        }
        tapDescribed("Filters")
        option("Zoom").performClick()
        option("Slack").performClick()
        tap("Save as view")
        nameField().performTextInput("Meetings")
        tap("Save")
        pumpUntil("sent") { server.requestsTo("POST", "/api/views").isNotEmpty() }
        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/views").first().bodyText()).jsonObject
        assertEquals("Meetings", body.getValue("name").jsonPrimitive.content)
        assertEquals("""{"source":{"op":"is","values":["zoom","slack"]}}""", body.getValue("filters").toString())
        // The title is the view now; leaving it clears its filters, opening it again brings them back.
        pumpUntil("the view's title") {
            compose.onAllNodes(hasText("Meetings") and SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading))
                .fetchSemanticsNodes().isNotEmpty()
        }
        shown("Rec Bravo", "Rec Echo")
        tap("Meetings")
        tapDescribed("All recordings, 5")
        shown("Rec Alpha", "Rec Bravo", "Rec Charlie", "Rec Delta", "Rec Echo")
        tap("All recordings")
        compose.onAllNodes(hasText("Meetings") and hasClickAction()).onFirst().performClick()
        shown("Rec Bravo", "Rec Echo")
    }

    @Test
    fun aViewTheServerRefusesGoesAndSaysWhy() {
        seed()
        server.on("POST", "/api/views", FakeServer.json(422, """{"message":"You can keep 30 views.","code":"views_limit","limit":30}"""))
        tapDescribed("Filters")
        option("Zoom").performClick()
        tap("Save as view")
        nameField().performTextInput("One too many")
        tap("Save")
        pumpUntil("said") {
            compose.onAllNodesWithText("One too many wasn't saved: you can keep up to 30 views.").fetchSemanticsNodes().isNotEmpty()
        }
        pumpUntil("dropped") { vm.savedViews.value.isEmpty() }
    }

    @Test
    fun aViewsMenuMovesAndDeletes() {
        // The server's copy follows what it was sent.
        val order = java.util.concurrent.CopyOnWriteArrayList(listOf("A" to "First", "B" to "Second"))
        fun answer(version: Int) = order.mapIndexed { i, (id, name) ->
            """{"id":"01JV00000000000000000000$id$id","name":"$name","position":$i,"filters":{},"search":null}"""
        }.joinToString(",", """{"data":[""", """],"views_version":$version,"limit":30}""")
        seed(views = answer(3))
        server.on("GET", "/api/views") { FakeServer.json(200, answer(3 + order.size)) }
        server.on("PUT", "/api/views/order") { req ->
            val ids = Json.parseToJsonElement(req.bodyText()).jsonObject.getValue("ids").toString()
            order.sortBy { (id, _) -> ids.indexOf("$id$id\"") }
            FakeServer.json(200, answer(10))
        }
        server.on("DELETE", "/api/views/.+") { req ->
            order.removeAll { (id, _) -> req.url.encodedPath.endsWith("$id$id") }
            FakeServer.json(200, """{"id":"x","views_version":11}""")
        }
        pumpUntil("the views") { vm.savedViews.value.size == 2 }
        tap("All recordings")
        compose.onNode(hasContentDescription("First options")).performClick()
        tap("Move down")
        pumpUntil("moved") { vm.savedViews.value.map { it.name } == listOf("Second", "First") }
        pumpUntil("sent") { order.map { it.second } == listOf("Second", "First") }
        tap("All recordings")
        compose.onNode(hasContentDescription("Second options")).performClick()
        tap("Delete view…")
        compose.onNodeWithText("Delete Second?").assertExists()
        tap("Delete")
        pumpUntil("deleted") { vm.savedViews.value.map { it.name } == listOf("First") && order.size == 1 }
    }

    @Test
    fun urduRightToLeftHasTheSameMenuTabsAndPills() {
        seed(EchoLanguage.Urdu)
        val ur = V2Testing.resources(EchoLanguage.Urdu)
        val word = { id: Int -> ur.getString(id) }
        compose.onNode(hasText(word(R.string.v2_lf_all_recordings)) and hasClickAction()).assertExists()
        compose.onNode(hasText("${word(R.string.v2_lf_calls)} 2") and hasAnyAncestor(hasTestTag(KIND_ROW_TAG))).assertExists()
        val row = compose.onAllNodes(hasTestTag(KIND_ROW_TAG)).onFirst().fetchSemanticsNode()
        assertEquals(LayoutDirection.Rtl, row.layoutInfo.layoutDirection)
        tapDescribed(word(R.string.v2_lf_filters))
        option(word(R.string.v2_source_zoom)).performClick()
        tap(ur.getQuantityString(R.plurals.v2_lf_show_results, 1, 1))
        val pill = "${word(R.string.v2_lib_source)}: ${word(R.string.v2_source_zoom)}"
        compose.onNode(hasText(pill) and hasAnyAncestor(hasTestTag(PILLS_ROW_TAG))).assertExists()
        tap(word(R.string.v2_lf_all_recordings))
        listOf(R.string.v2_lf_views, R.string.v2_lf_my_views, R.string.v2_lf_labels, R.string.v2_lf_trash)
            .forEach { id -> assertTrue(compose.onAllNodesWithText(word(id)).fetchSemanticsNodes().isNotEmpty()) }
    }
}
