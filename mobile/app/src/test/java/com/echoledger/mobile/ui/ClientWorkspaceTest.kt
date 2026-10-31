package com.echoledger.mobile.ui

import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import java.time.LocalDate
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** A client's workspace on the phone (since 1.4): brief, memory, call notes, AI notes switch, Ask with sources. */
class ClientWorkspaceTest : ScreenTest() {
    private val client = ClientDto("01JCLIENT0000000000000000", "Acme")
    private val opened = mutableListOf<Pair<String, Long>>()

    @Before
    fun serveWorkspace() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/clients/${client.id}/workspace",
            FakeServer.json(
                200,
                """{"data":{"client":{"id":"${client.id}","name":"Acme","ai_notes":true},
                "ai":{"configured":true,"spent_usd":0.42,"budget_usd":5,"client_spent_usd":0.12},
                "brief":{"sections":{"goal":"An online shop","scope":["Login"],"constraints":[],"deadlines":["Nov 15"],"budget":[],"people":[],"glossary":[]},"edited":false,"status":"ready"},
                "memory":[{"id":"m1","kind":"decision","text":"Launch on November 15","happened_on":"2026-10-30","pinned":false,"source":{"type":"call","id":"s1","at_ms":125000}}],
                "notes":[{"id":"n1","session_id":"s1","status":"ready","language":"en","sections":[{"key":"to_confirm","heading":"To confirm with the client","items":[{"text":"Hosting plan","verify":true,"at_ms":61000}]}],"session":{"id":"s1","title":"Kick-off","started_at":"2026-10-30T06:39:00Z"}}],
                "files":[{"id":"f1","name":"scope.pdf","mime":"application/pdf","size_bytes":182340,"pages":4,"status":"ready","instructions":false}]}}"""
            )
        )
    }

    private fun showWorkspace() {
        show {
            ClientWorkspaceScreen(vm, client, emptyList(), LocalDate.parse("2026-10-31"), onOpenItem = {}, onOpenAt = { id, ms ->
                opened +=
                    id to ms
            }, onBack = {})
        }
        pumpUntil("the workspace") { runCatching { compose.onNodeWithText("An online shop").assertExists() }.isSuccess }
    }

    @Test
    fun `the overview shows the brief, memory and call notes, and a line opens its moment`() {
        showWorkspace()

        compose.onNodeWithText("• Login").assertExists()
        compose.onNodeWithText("AI this month: $0.42 of $5.00 · this client $0.12").assertExists()
        compose.onNodeWithText("Launch on November 15").performClick()
        compose.onNodeWithText("• Hosting plan").performClick()
        compose.onNodeWithText("1:01 · verify").assertExists()
        assertEquals(listOf("s1" to 125_000L, "s1" to 61_000L), opened)
    }

    @Test
    fun `the AI notes switch is saved for the client`() {
        server.on(
            "PATCH",
            "/api/clients/${client.id}",
            FakeServer.json(200, """{"data":{"id":"${client.id}","name":"Acme","ai_notes":false}}""")
        )
        showWorkspace()

        compose.onNodeWithContentDescription("AI notes").performClick()

        pumpUntil("the PATCH") { server.requestsTo("PATCH", "/api/clients/${client.id}").isNotEmpty() }
        assertEquals("""{"ai_notes":false}""", server.requestsTo("PATCH", "/api/clients/${client.id}").single().bodyText())
    }

    @Test
    fun `Ask answers with sources that open the call at its moment`() {
        server.on(
            "POST",
            "/api/clients/${client.id}/ask",
            FakeServer.json(
                200,
                """{"data":{"answer":"Launch is on November 15.","citations":[{"type":"call","session_id":"s1","title":"Kick-off · Oct 30","at_ms":125000,"quote":"We launch"},{"type":"file","file_id":"f1","title":"scope.pdf","page":2,"quote":"x"}]}}"""
            )
        )
        showWorkspace()
        compose.onNodeWithText("Ask").performClick()
        compose.onNodeWithText("Ask about Acme").performTextInput("When do we launch?")
        compose.onNodeWithText("Roman Urdu").performClick()
        // The second "Ask" is the button (the first is the tab).
        compose.onAllNodesWithText("Ask")[1].performClick()

        pumpUntil("the answer") { runCatching { compose.onNodeWithText("Launch is on November 15.").assertExists() }.isSuccess }
        assertTrue(server.requestsTo("POST", "/api/clients/${client.id}/ask").single().bodyText().contains(""""language":"roman_urdu""""))
        compose.onNodeWithText("scope.pdf · page 2").assertExists()
        compose.onNodeWithText("Kick-off · Oct 30 · 2:05").performClick()
        assertEquals(listOf("s1" to 125_000L), opened)
    }
}
