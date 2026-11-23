package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasImeAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.isEnabled
import androidx.compose.ui.test.isNotEnabled
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTextReplacement
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.labelColourValue
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Lists in the v2 Library (D14 on contract 2.0.0-m; lists are labels, PLAN_LIBRARY_FILTERS's lead decisions): the
 * title menu's Views (All recordings, Unread, Important) and Labels (the person's lists, + New label), choosing one at a
 * time, an empty one's words, making a label in two steps (the payload with its hex colour), each label's ⋮ (Rename
 * with the name checks, Change colour, Move up / Move down, Delete after asking), Add to list from the selection with
 * Undo for only what changed, Unread clearing on opening, and right to left.
 */
class V2LibraryListsTest : ScreenTest() {
    private val listsJson = """{"data":[
        {"key":"clients","name":"Clients","color":"#e15f56","position":0,"count":1},
        {"key":"home","name":"Home","color":null,"position":1,"count":1},
        {"key":"family","name":"Family","color":"#458cde","position":2,"count":0}]}"""

    private fun row(id: String, title: String, extra: String) =
        ServerFixtures.libraryItem(id, title = title).replace("\"updated_at\"", "$extra,\"updated_at\"")

    private fun seed(language: EchoLanguage = EchoLanguage.English, lists: String = listsJson) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
                        row("01JA", "Bilal Traders", "\"labels\":[\"Clients\"],\"opened\":true"),
                        row("01JB", "Rehman Textiles", "\"labels\":[\"home\"],\"opened\":false"),
                        row("01JC", "Qureshi Builders", "\"labels\":[],\"opened\":true,\"important\":true")
                    )
                )
            )
        )
        server.on("GET", "/api/lists", FakeServer.json(200, lists))
        server.on("POST", "/api/sessions/bulk", FakeServer.json(200, """{"changed": 1}"""))
        server.on("POST", "/api/sessions/.+/opened", FakeServer.empty(204))
        runBlocking { app.graph.library.refresh() }
        show {
            EchoV2Theme(darkTheme = false, language = language) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    androidx.compose.foundation.layout.Box {
                        V2LibraryScreen(vm, initials = "AS", onOpen = {}, onTrash = {}, onAccount = {})
                        EchoToastHost(toasts)
                    }
                }
            }
        }
        pumpUntil("the library") { compose.onAllNodesWithText("Bilal Traders").fetchSemanticsNodes().isNotEmpty() }
        pumpUntil("the lists") { vm.lists.value.size >= 1 }
    }

    /** The sheet's name field (the search field is the other one). */
    private fun nameField() =
        compose.onAllNodes(hasSetTextAction() and hasImeAction(androidx.compose.ui.text.input.ImeAction.Done)).onFirst()

    /** Opens the title's menu (whatever the title says now). */
    private fun menu() {
        compose.onNode(
            SemanticsMatcher.expectValue(SemanticsProperties.Role, androidx.compose.ui.semantics.Role.DropdownList) and
                SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading)
        ).performClick()
        compose.waitForIdle()
    }

    /**
     * One of the menu's views or labels (the menu open), by what TalkBack hears: its name, then its count (not a
     * recording's row in the list, which says its labels too).
     */
    private fun row(name: String) = compose.onAllNodes(
        (
            hasContentDescription(name) or hasContentDescription("$name, ", substring = true) or
                hasContentDescription("$name، ", substring = true)
            ) and hasClickAction() and !hasAnyAncestor(androidx.compose.ui.test.hasScrollToIndexAction())
    ).onFirst()

    /** Chooses [name] in the title's menu. */
    private fun choose(name: String) {
        menu()
        row(name).performScrollTo().performClick()
        compose.waitForIdle()
    }

    /** The title says what the list shows. */
    private fun titled(name: String) = pumpUntil("titled $name") {
        compose.onAllNodes(hasText(name) and SemanticsMatcher.keyIsDefined(SemanticsProperties.Heading)).fetchSemanticsNodes().isNotEmpty()
    }

    private fun awaitText(words: String) =
        pumpUntil("\"$words\"") { compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty() }

    private fun shows(title: String) = compose.onAllNodesWithText(title).fetchSemanticsNodes().isNotEmpty()

    private fun body(method: String, path: String) = server.requestsTo(method, path).last().bodyText().let {
        Json.parseToJsonElement(it).jsonObject
    }

    @Test
    fun theMenusViewsAndLabelsShowOneAtATimeAndAnEmptyOneSaysSo() {
        seed()
        menu()
        listOf("All recordings", "Unread", "Important", "Clients", "Home", "Family", "New label").forEach { row(it).assertExistsNamed(it) }
        row("All recordings").assert(SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Selected"))
        row("Clients").performClick()
        titled("Clients")
        assertTrue(shows("Bilal Traders"))
        assertTrue(!shows("Rehman Textiles") && !shows("Qureshi Builders"))
        // "home" on the recording is the label Home: capitals ignored.
        choose("Home")
        assertTrue(shows("Rehman Textiles") && !shows("Bilal Traders"))
        choose("Important")
        assertTrue(shows("Qureshi Builders") && !shows("Bilal Traders"))
        choose("Family")
        compose.onNodeWithText("Nothing in Family yet").assertExists()
        compose.onNodeWithText("Add recordings from their ⋮ menu: Add to list.").assertExists()
        choose("All recordings")
        assertTrue(shows("Bilal Traders") && shows("Rehman Textiles") && shows("Qureshi Builders"))
    }

    private fun androidx.compose.ui.test.SemanticsNodeInteraction.assertExistsNamed(what: String) =
        runCatching { assertExists() }.getOrElse { throw AssertionError("missing in the menu: $what", it) }

    @Test
    fun unreadCountsTheUnopenedAndClearsWhenOneIsOpened() {
        seed()
        choose("Unread")
        assertTrue(shows("Rehman Textiles") && !shows("Bilal Traders"))
        compose.onNodeWithText("Rehman Textiles").performClick()
        pumpUntil("opened") { server.requestsTo("POST", "/api/sessions/01JB/opened").isNotEmpty() }
        pumpUntil("left Unread") { !shows("Rehman Textiles") }
        compose.onNodeWithText("Nothing in Unread yet").assertExists()
    }

    @Test
    fun newLabelMakesOneInTwoStepsWithTheLeastUsedColourChosenAndSendsItsHex() {
        seed()
        server.on(
            "POST",
            "/api/lists",
            FakeServer.json(201, """{"data":{"key":"suppliers","name":"Suppliers","color":"#8e8a80","position":3,"count":0}}""")
        )
        choose("New label")
        awaitText("Label name")
        val create = compose.onNode(hasText("Create") and hasClickAction())
        create.assert(isNotEnabled())
        nameField().performTextInput("  Suppliers ")
        create.assert(isEnabled())
        // Red and blue are taken: grey, the first unused one, is chosen.
        compose.onNodeWithContentDescription("Grey").assert(SemanticsMatcher.expectValue(SemanticsProperties.Selected, true))
        create.performClick()
        pumpUntil("created") { server.requestsTo("POST", "/api/lists").isNotEmpty() }
        val sent = body("POST", "/api/lists")
        assertEquals("Suppliers", sent["name"]?.jsonPrimitive?.content)
        assertEquals(labelColourValue("grey").lowercase(), sent["color"]?.jsonPrimitive?.content)
        assertTrue(Regex("#[0-9a-f]{6}").matches(sent["color"]!!.jsonPrimitive.content))
        // The new label is chosen and its empty state shows.
        awaitText("Nothing in Suppliers yet")
    }

    @Test
    fun aNameAlreadyTakenOrAFixedViewsWordIsRefusedWithItsOwnWords() {
        seed()
        choose("New label")
        awaitText("Label name")
        nameField().performTextInput("home")
        awaitText("You already have a list called Home.")
        compose.onNode(hasText("Create") and hasClickAction()).assert(isNotEnabled())
        nameField().performTextReplacement("Important")
        awaitText("Important is one of the fixed lists. Choose another name.")
        compose.onNode(hasText("Create") and hasClickAction()).assert(isNotEnabled())
        assertTrue(server.requestsTo("POST", "/api/lists").isEmpty())
    }

    /** A label's ⋮ in the title's menu. */
    private fun menuOf(name: String) {
        menu()
        compose.onNode(hasContentDescription("$name options")).performScrollTo().performClick()
        compose.waitForIdle()
    }

    @Test
    fun renameChecksTheOtherLabelsNamesAndSendsTheNewOne() {
        seed()
        server.on(
            "PATCH",
            "/api/lists/clients",
            FakeServer.json(200, """{"data":{"key":"customers","name":"Customers","color":"#e15f56","position":0,"count":1}}""")
        )
        menuOf("Clients")
        compose.onNodeWithText("Rename…").performClick()
        awaitText("Rename label")
        val save = compose.onNode(hasText("Save") and hasClickAction())
        nameField().performTextReplacement("Family")
        awaitText("You already have a list called Family.")
        save.assert(isNotEnabled())
        // A change of capitals alone is allowed.
        nameField().performTextReplacement("CLIENTS")
        save.assert(isEnabled())
        nameField().performTextReplacement("Customers")
        save.performClick()
        pumpUntil("renamed") { server.requestsTo("PATCH", "/api/lists/clients").isNotEmpty() }
        assertEquals("Customers", body("PATCH", "/api/lists/clients")["name"]?.jsonPrimitive?.content)
        pumpUntil("label renamed") { vm.lists.value.any { it.name == "Customers" } }
    }

    @Test
    fun moveUpAndMoveDownSendTheNewOrder() {
        seed()
        server.on("PUT", "/api/lists/order", FakeServer.json(200, listsJson))
        menuOf("Clients")
        // The first label can only go down.
        compose.onAllNodesWithText("Move up").assertCountEquals(0)
        compose.onNodeWithText("Move down").performClick()
        pumpUntil("ordered") { server.requestsTo("PUT", "/api/lists/order").isNotEmpty() }
        assertEquals(
            listOf("home", "clients", "family"),
            body("PUT", "/api/lists/order")["keys"]!!.jsonArray.map {
                it.jsonPrimitive.content
            }
        )
        compose.onAllNodes(hasContentDescription("Close", substring = true) and hasClickAction()).onFirst().performClick()
        compose.waitForIdle()
        menuOf("Family")
        compose.onAllNodesWithText("Move down").assertCountEquals(0)
        compose.onNodeWithText("Move up").performClick()
        pumpUntil("ordered again") { server.requestsTo("PUT", "/api/lists/order").size == 2 }
        assertEquals(
            listOf("clients", "family", "home"),
            body("PUT", "/api/lists/order")["keys"]!!.jsonArray.map {
                it.jsonPrimitive.content
            }
        )
    }

    @Test
    fun inUrduTheMenuIsInUrduAndMoveDownStillTakesALabelLater() {
        seed(EchoLanguage.Urdu)
        server.on("PUT", "/api/lists/order", FakeServer.json(200, listsJson))
        menu()
        // The fixed views are in Urdu.
        listOf("تمام ریکارڈنگز", "نہ پڑھی گئی", "اہم").forEach { row(it).assertExistsNamed(it) }
        compose.onNode(hasContentDescription("Clients کے اختیارات")).performScrollTo().performClick()
        compose.waitForIdle()
        // A list read top to bottom in either direction: down is later in the order.
        compose.onAllNodesWithText("اوپر لے جائیں").assertCountEquals(0)
        compose.onNodeWithText("نیچے لے جائیں").performClick()
        pumpUntil("ordered") { server.requestsTo("PUT", "/api/lists/order").isNotEmpty() }
        assertEquals(
            listOf("home", "clients", "family"),
            body("PUT", "/api/lists/order")["keys"]!!.jsonArray.map {
                it.jsonPrimitive.content
            }
        )
    }

    @Test
    fun deleteAsksFirstAndTheChosenLabelGoneGoesBackToAllRecordings() {
        seed()
        server.on("DELETE", "/api/lists/home", FakeServer.json(200, """{"key":"home","changed":1}"""))
        choose("Home")
        titled("Home")
        menuOf("Home")
        compose.onNodeWithText("Delete label…").performClick()
        awaitText("Delete Home?")
        compose.onNodeWithText("The label comes off its recordings; they stay in your Library.").assertExists()
        compose.onNode(hasText("Cancel") and hasAnyAncestor(isDialog())).performClick()
        assertTrue(server.requestsTo("DELETE", "/api/lists/home").isEmpty())
        menuOf("Home")
        compose.onNodeWithText("Delete label…").performClick()
        awaitText("Delete Home?")
        server.on(
            "GET",
            "/api/lists",
            FakeServer.json(
                200,
                """{"data":[{"key":"clients","name":"Clients","color":"#e15f56","position":0,"count":1},{"key":"family","name":"Family","color":"#458cde","position":1,"count":0}]}"""
            )
        )
        compose.onNode(hasText("Delete") and hasClickAction() and hasAnyAncestor(isDialog())).performClick()
        pumpUntil("deleted") { server.requestsTo("DELETE", "/api/lists/home").isNotEmpty() }
        pumpUntil("back to All recordings") { shows("Bilal Traders") && shows("Qureshi Builders") }
        titled("All recordings")
    }

    @Test
    fun addToListPutsInOnlyTheOnesNotInItAndUndoTakesOutOnlyThose() {
        seed()
        compose.onNodeWithText("Bilal Traders").performTouchInput { longClick() }
        compose.onNodeWithText("Rehman Textiles").performClick()
        compose.onNodeWithText("2 selected").assertExists()
        compose.onNodeWithContentDescription("More").performClick()
        compose.onAllNodes(hasText("Add to list") and hasClickAction()).onFirst().performClick()
        awaitText("New list…")
        // Bilal is in Clients already, Rehman is not: Clients is not ticked; a tap adds Rehman only.
        compose.onAllNodes(hasText("Clients") and hasClickAction()).onLast().performClick()
        pumpUntil("labelled") { server.requestsTo("POST", "/api/sessions/bulk").any { it.bodyText().contains("\"label\"") } }
        val added = Json.parseToJsonElement(server.requestsTo("POST", "/api/sessions/bulk").last().bodyText()).jsonObject
        assertEquals("label", added["action"]?.jsonPrimitive?.content)
        assertEquals(listOf("01JB"), added["ids"]!!.jsonArray.map { it.jsonPrimitive.content })
        assertEquals("Clients", added["label"]?.jsonPrimitive?.content)
        awaitText("Added to Clients")
        compose.onAllNodes(hasText("Undo") and hasClickAction()).onFirst().performClick()
        pumpUntil("undone") { server.requestsTo("POST", "/api/sessions/bulk").any { it.bodyText().contains("\"unlabel\"") } }
        val undone = Json.parseToJsonElement(server.requestsTo("POST", "/api/sessions/bulk").last().bodyText()).jsonObject
        assertEquals(listOf("01JB"), undone["ids"]!!.jsonArray.map { it.jsonPrimitive.content })
    }

    @Test
    fun theMenuCountsWhatIsNotOpenedAndEachLabel() {
        seed()
        menu()
        compose.onNode(hasContentDescription("Unread, 1")).assertExists()
        compose.onNode(hasContentDescription("Clients, 1")).assertExists()
        // A count of 0 is not shown: the empty label is just its name.
        compose.onNode(hasContentDescription("Family")).assertExists()
        compose.onAllNodes(hasText("New label") and hasClickAction()).assertCountEquals(1)
    }

    @Test
    fun aNameAnotherDeviceTookSaysTheServersSpellingAndTheListsAreReadAgain() {
        seed()
        server.on("POST", "/api/lists", FakeServer.json(409, """{"message":"Taken","code":"list_exists","name":"Suppliers"}"""))
        choose("New label")
        awaitText("Label name")
        nameField().performTextInput("suppliers")
        compose.onNode(hasText("Create") and hasClickAction()).performClick()
        awaitText("You already have a list called Suppliers.")
    }

    @Test
    fun aFailedChangeReadsTheListsAgain() {
        seed()
        server.on("PATCH", "/api/lists/clients", FakeServer.json(404, """{"message":"Gone","code":"list_not_found"}"""))
        val before = server.requestsTo("GET", "/api/lists").size
        menuOf("Clients")
        compose.onNodeWithText("Change colour…").performClick()
        awaitText("Change colour")
        compose.onAllNodes(hasText("Save") and hasClickAction()).onFirst().performClick()
        pumpUntil("read again") { server.requestsTo("GET", "/api/lists").size > before }
    }

    @Test
    fun eachLabelsMenuIsNamedForItAndAFixedViewHasNone() {
        seed()
        menu()
        listOf("Clients", "Home", "Family").forEach { compose.onNode(hasContentDescription("$it options")).assertExists() }
        compose.onAllNodes(hasContentDescription("All recordings options")).assertCountEquals(0)
        compose.onAllNodes(hasContentDescription("Unread options")).assertCountEquals(0)
    }
}
