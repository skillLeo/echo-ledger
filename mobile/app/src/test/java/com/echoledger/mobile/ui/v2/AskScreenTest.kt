package com.echoledger.mobile.ui.v2

import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.ui.v2.ask.AskLanguage
import com.echoledger.mobile.ui.v2.ask.V2AskScreen
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Ask v2 on the real view model and a scripted server: the answer's language, a new project, and the failures. */
class AskScreenTest : ScreenTest() {
    private val answer = """{"data":{"answer":"Launch is on November 15.","citations":[]}}"""

    private fun has(text: String) = runCatching { compose.onNodeWithText(text, substring = true).fetchSemanticsNode() }.isSuccess

    private fun open() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        vm.refresh()
        compose.setContent { EchoV2Theme(darkTheme = false, language = EchoLanguage.English) { V2AskScreen(vm, onOpenAt = { _, _ -> }) } }
        // Since 2.0.0-o the Ask tab starts on All my recordings: these ask a project, so they choose one first.
        compose.waitUntil(5_000) { has("All my recordings") && vm.clients.value.isNotEmpty() }
        compose.onAllNodesWithText("Ask about", useUnmergedTree = true).onFirst().performClick()
        compose.onAllNodes(hasText("Acme Holdings") and hasClickAction()).onFirst().performClick()
        compose.waitUntil(5_000) { has("Acme Holdings") }
    }

    private fun ask(question: String = "When do we launch?") {
        compose.onNode(hasSetTextAction()).performTextInput(question)
        compose.onNode(hasText("Ask") and hasClickAction()).performClick()
    }

    @Test
    fun theAnswerComesInTheLanguageChosenAndTheDefaultFollowsThePhone() {
        assertEquals(AskLanguage.RomanUrdu, AskLanguage.from(EchoLanguage.Urdu))
        assertEquals(AskLanguage.English, AskLanguage.from(EchoLanguage.English))
        open()
        server.on("POST", "/api/clients/.+/ask", FakeServer.json(200, answer))
        compose.onNodeWithText("Answer in").assertExists()
        compose.onNodeWithText("Roman Urdu").performClick()
        ask()
        compose.waitUntil(5_000) { has("Launch is on November 15.") }
        val body = server.requestsTo("POST", "/api/clients/01JCLIENT0000000000000000A/ask").single().bodyText()
        assertTrue(body, body.contains("\"language\":\"roman_urdu\""))
    }

    @Test
    fun anotherProjectClearsTheAnswer() {
        open()
        server.on("POST", "/api/clients/.+/ask", FakeServer.json(200, answer))
        ask()
        compose.waitUntil(5_000) { has("Launch is on November 15.") }
        compose.onNodeWithText("Ask about", useUnmergedTree = true).performClick()
        compose.onNodeWithText("Zed Logistics").performClick()
        compose.waitForIdle()
        assertTrue(!has("Launch is on November 15."))
        compose.onNodeWithText("Ask your recordings").assertExists()
    }

    @Test
    fun aRefusalShowsTheServersPlainWordsInsteadOfAToast() {
        open()
        server.on(
            "POST",
            "/api/clients/.+/ask",
            FakeServer.json(403, """{"message":"This month's AI budget is used up.","code":"ai_budget_reached"}""")
        )
        ask()
        compose.waitUntil(5_000) { has("This month's AI budget is used up.") }
        assertTrue(!has("internet connection"))
        // Taken from the view model's message, so the same words don't also come up as a toast.
        assertNull(vm.message.value)
    }

    @Test
    fun theConnectionSentenceOnlyWhenOffline() {
        open()
        server.on("POST", "/api/clients/.+/ask", FakeServer.json(502, """{"code":"provider_error"}"""))
        ask()
        compose.waitUntil(5_000) { has("Try again") }
        assertTrue(!has("internet connection"))
        // The server out of reach: nothing answers at its address any more.
        server.close()
        compose.onAllNodes(hasText("Ask") and hasClickAction()).onFirst().performClick()
        compose.waitUntil(5_000) { has("You're offline. Check the internet connection and ask again.") }
    }
}
