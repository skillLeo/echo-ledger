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
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Library's classic features in v2 (docs/v2/audit/parity.csv PH-LIB): selection mode's Move, Label, Export, Rename and
 * Keep; the swipes; sections that fold and select; Sync now; Group by and Density; "Nothing matches" with Clear filters.
 */
class V2LibraryActionsTest : ScreenTest() {
    private fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(
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
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2LibraryScreen(vm, initials = "AS", onOpen = {}, onTrash = {}, onAccount = {})
                }
            }
        }
        pumpUntil("the library") { compose.onAllNodesWithText("Bilal Traders", substring = true).fetchSemanticsNodes().isNotEmpty() }
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
            compose.onNodeWithContentDescription("Share or export").performClick()
            click(name)
            pumpUntil("export $format") { server.requestsTo("POST", "/api/sessions/export").any { it.bodyText().contains("\"$format\"") } }
        }
    }

    @Test
    fun renameAndKeepForeverFromTheMenu() {
        seed()
        server.on("PATCH", "/api/sessions/01JA", FakeServer.json(200, ServerFixtures.libraryItem("01JA", title = "Bilal Traders, rate")))
        select("Bilal Traders")
        compose.onNodeWithContentDescription("More").performClick()
        click("Keep forever")
        bulk("keep")
        select("Bilal Traders")
        compose.onNodeWithContentDescription("More").performClick()
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
        server.on("DELETE", "/api/sessions/01JB", FakeServer.empty(204))
        compose.onNodeWithText("Rehman and Sons Textiles", substring = true).performTouchInput { swipeLeft() }
        pumpUntil("deleted") { server.requestsTo("DELETE", "/api/sessions/01JB").isNotEmpty() }
        compose.onNodeWithText("Bilal Traders", substring = true).performTouchInput { swipeRight() }
        bulk("pin")
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
        compose.onNode(hasText("Waiting for decision") and isToggleable()).performClick()
        click("Apply")
        compose.onNodeWithText("Nothing matches").assertExists()
        click("Clear filters")
        compose.onNodeWithText("Bilal Traders", substring = true).assertExists()
    }
}
