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
 * Lists in the v2 Library (D14 on contract 2.0.0-m): the chip row All · Unread · Important · the person's lists · "+",
 * filtering by one chip, an empty list's words, making a list in two steps (the payload with its hex colour), the chip
 * menu (Rename with the name checks, Change colour, Move left / Move right as the screen reads, Delete after asking),
 * Add to list from the selection with Undo for only what changed, Unread clearing on opening, and right to left.
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

    private fun chip(name: String) = compose.onAllNodes(hasText(name) and hasClickAction()).onFirst()

    private fun awaitText(words: String) =
        pumpUntil("\"$words\"") { compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty() }

    private fun shows(title: String) = compose.onAllNodesWithText(title).fetchSemanticsNodes().isNotEmpty()

    private fun body(method: String, path: String) = server.requestsTo(method, path).last().bodyText().let {
        Json.parseToJsonElement(it).jsonObject
    }

    @Test
    fun theChipRowFiltersByOneChipAndAnEmptyListSaysSo() {
        seed()
        listOf("All", "Unread", "Important", "Clients", "Home", "Family").forEach { chip(it).assertExistsNamed(it) }
        compose.onNodeWithContentDescription("New list").assertExists()
        chip("All").assert(SemanticsMatcher.expectValue(SemanticsProperties.Selected, true))
        chip("Clients").performClick()
        chip("Clients").assert(SemanticsMatcher.expectValue(SemanticsProperties.Selected, true))
        assertTrue(shows("Bilal Traders"))
        assertTrue(!shows("Rehman Textiles") && !shows("Qureshi Builders"))
        // "home" on the recording is the list Home: capitals ignored.
        chip("Home").performClick()
        assertTrue(shows("Rehman Textiles") && !shows("Bilal Traders"))
        chip("Important").performClick()
        assertTrue(shows("Qureshi Builders") && !shows("Bilal Traders"))
        chip("Family").performClick()
        compose.onNodeWithText("Nothing in Family yet").assertExists()
        compose.onNodeWithText("Add recordings from their ⋮ menu: Add to list.").assertExists()
        chip("All").performClick()
        assertTrue(shows("Bilal Traders") && shows("Rehman Textiles") && shows("Qureshi Builders"))
    }

    private fun androidx.compose.ui.test.SemanticsNodeInteraction.assertExistsNamed(what: String) =
        runCatching { assertExists() }.getOrElse { throw AssertionError("missing chip: $what", it) }

    @Test
    fun unreadCountsTheUnopenedAndClearsWhenOneIsOpened() {
        seed()
        chip("Unread").performClick()
        assertTrue(shows("Rehman Textiles") && !shows("Bilal Traders"))
        compose.onNodeWithText("Rehman Textiles").performClick()
        pumpUntil("opened") { server.requestsTo("POST", "/api/sessions/01JB/opened").isNotEmpty() }
        pumpUntil("left Unread") { !shows("Rehman Textiles") }
        compose.onNodeWithText("Nothing in Unread yet").assertExists()
    }

    @Test
    fun plusMakesAListInTwoStepsWithTheLeastUsedColourChosenAndSendsItsHex() {
        seed()
        server.on(
            "POST",
            "/api/lists",
            FakeServer.json(201, """{"data":{"key":"suppliers","name":"Suppliers","color":"#8e8a80","position":3,"count":0}}""")
        )
        compose.onNodeWithContentDescription("New list").performClick()
        awaitText("List name")
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
        // The new chip is chosen and its empty state shows.
        awaitText("Nothing in Suppliers yet")
    }

    @Test
    fun aNameAlreadyTakenOrAFixedChipsWordIsRefusedWithItsOwnWords() {
        seed()
        compose.onNodeWithContentDescription("New list").performClick()
        awaitText("List name")
        nameField().performTextInput("home")
        awaitText("You already have a list called Home.")
        compose.onNode(hasText("Create") and hasClickAction()).assert(isNotEnabled())
        nameField().performTextReplacement("Important")
        awaitText("Important is one of the Library's own chips. Choose another name.")
        compose.onNode(hasText("Create") and hasClickAction()).assert(isNotEnabled())
        assertTrue(server.requestsTo("POST", "/api/lists").isEmpty())
    }

    private fun menuOf(name: String) {
        chip(name).performTouchInput { longClick() }
        compose.waitForIdle()
    }

    @Test
    fun renameChecksTheOtherListsNamesAndSendsTheNewOne() {
        seed()
        server.on(
            "PATCH",
            "/api/lists/clients",
            FakeServer.json(200, """{"data":{"key":"customers","name":"Customers","color":"#e15f56","position":0,"count":1}}""")
        )
        menuOf("Clients")
        compose.onNodeWithText("Rename…").performClick()
        awaitText("Rename list")
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
        pumpUntil("chip renamed") { vm.lists.value.any { it.name == "Customers" } }
    }

    @Test
    fun moveLeftAndMoveRightSendTheNewOrder() {
        seed()
        server.on("PUT", "/api/lists/order", FakeServer.json(200, listsJson))
        menuOf("Clients")
        // The first list can only go right.
        compose.onAllNodesWithText("Move left").assertCountEquals(0)
        compose.onNodeWithText("Move right").performClick()
        pumpUntil("ordered") { server.requestsTo("PUT", "/api/lists/order").isNotEmpty() }
        assertEquals(
            listOf("home", "clients", "family"),
            body("PUT", "/api/lists/order")["keys"]!!.jsonArray.map {
                it.jsonPrimitive.content
            }
        )
        menuOf("Family")
        compose.onAllNodesWithText("Move right").assertCountEquals(0)
        compose.onNodeWithText("Move left").performClick()
        pumpUntil("ordered again") { server.requestsTo("PUT", "/api/lists/order").size == 2 }
        assertEquals(
            listOf("clients", "family", "home"),
            body("PUT", "/api/lists/order")["keys"]!!.jsonArray.map {
                it.jsonPrimitive.content
            }
        )
    }

    @Test
    fun inUrduMoveLeftTakesAListLaterInTheOrder() {
        seed(EchoLanguage.Urdu)
        server.on("PUT", "/api/lists/order", FakeServer.json(200, listsJson))
        menuOf("Clients")
        // Right to left, the first list is on the right: it can only go left, which is later in the order.
        compose.onAllNodesWithText("دائیں منتقل کریں").assertCountEquals(0)
        compose.onNodeWithText("بائیں منتقل کریں").performClick()
        pumpUntil("ordered") { server.requestsTo("PUT", "/api/lists/order").isNotEmpty() }
        assertEquals(
            listOf("home", "clients", "family"),
            body("PUT", "/api/lists/order")["keys"]!!.jsonArray.map {
                it.jsonPrimitive.content
            }
        )
        // The fixed chips are in Urdu too.
        listOf("سب", "نہ پڑھی گئی", "اہم").forEach { chip(it).assertExistsNamed(it) }
    }

    @Test
    fun deleteAsksFirstAndTheChosenListGoneGoesBackToAll() {
        seed()
        server.on("DELETE", "/api/lists/home", FakeServer.json(200, """{"key":"home","changed":1}"""))
        chip("Home").performClick()
        menuOf("Home")
        compose.onNodeWithText("Delete list…").performClick()
        awaitText("Delete Home?")
        compose.onNodeWithText("Its recordings stay in your Library; only the list goes.").assertExists()
        compose.onNode(hasText("Cancel") and hasAnyAncestor(isDialog())).performClick()
        assertTrue(server.requestsTo("DELETE", "/api/lists/home").isEmpty())
        menuOf("Home")
        compose.onNodeWithText("Delete list…").performClick()
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
        pumpUntil("back to All") { shows("Bilal Traders") && shows("Qureshi Builders") }
        chip("All").assert(SemanticsMatcher.expectValue(SemanticsProperties.Selected, true))
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
    fun theUnreadChipCountsWhatIsNotOpened() {
        seed()
        compose.onAllNodes(hasText("Unread") and hasText("1")).assertCountEquals(1)
        compose.onAllNodes(hasContentDescription("New list")).assertCountEquals(1)
    }
}
