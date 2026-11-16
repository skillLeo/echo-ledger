package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.awaitAppIdle
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * The share sheet and the request links. Revoke on a share link, Stop sharing with a teammate and Turn off on a request
 * link end it for everyone who has it, so each asks first (since v1.8.1, bug P-005, as the desktop's D-072): Cancel
 * sends nothing.
 */
class ShareSheetTest : ScreenTest() {
    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
    }

    private fun exists(text: String) = compose.onAllNodesWithText(text).fetchSemanticsNodes().isNotEmpty()

    /** The sheet draws in its own window, which redraws on the next frame. */
    private fun awaitText(text: String) = pumpUntil("\"$text\"") {
        compose.mainClock.advanceTimeByFrame()
        exists(text)
    }

    /** The confirm's button: the sheet is a dialog window too, so the newest match (the question's) is the one. */
    private fun inDialog(text: String) = compose.onAllNodes(hasText(text) and hasAnyAncestor(isDialog())).onLast()

    private fun showShareSheet() = show { ShareSheet(vm, sessionId = "S1", clientId = null, title = "this recording", onDismiss = {}) }

    @Test
    fun `the sheet makes a link and shows it once`() {
        server.on("GET", "/api/sessions/S1/shares", FakeServer.json(200, """{"data":[]}"""))
        server.on("GET", "/api/sent", FakeServer.json(200, """{"data":[]}"""))
        server.on(
            "POST",
            "/api/sessions/S1/shares",
            FakeServer.json(201, """{"id":"l1","active":true,"url":"https://ledger.example.test/s/abc"}""")
        )
        showShareSheet()
        awaitText("Make link")

        compose.onNodeWithText("7 days").performClick()
        compose.onNodeWithText("Make link").performClick()
        awaitText("https://ledger.example.test/s/abc")
        compose.onNodeWithText("Copy it now: it is shown only once.").assertIsDisplayed()
        val body = server.requestsTo("POST", "/api/sessions/S1/shares").single().bodyText()
        assertTrue(body, body.contains("\"audience\":\"public\"") && body.contains("\"expires_at\""))
    }

    @Test
    fun `Revoke asks first, and Cancel leaves the link working`() {
        // Answered once the sheet is up, as over a network: a sheet still opening can miss an answer that came at once.
        server.on("GET", "/api/sessions/S1/shares") {
            Thread.sleep(500)
            FakeServer.json(200, """{"data":[{"id":"l1","active":true,"view_count":3,"expires_at":"2026-12-01T00:00:00Z"}]}""")
        }
        server.on("GET", "/api/sent", FakeServer.json(200, """{"data":[]}"""))
        server.on("DELETE", "/api/shares/l1", FakeServer.empty(204))
        showShareSheet()
        awaitText("Revoke")

        compose.onNodeWithText("Revoke").performClick()
        compose.onNodeWithText("Revoke this link? It stops working for everyone who has it.").assertIsDisplayed()
        inDialog("Cancel").performClick()
        awaitAppIdle()
        assertTrue("Cancel sends nothing", server.requestsTo("DELETE", "/api/shares/l1").isEmpty())

        compose.onNodeWithText("Revoke").performClick()
        inDialog("Revoke").performClick()
        pumpUntil("the link to be revoked") { server.requestsTo("DELETE", "/api/shares/l1").isNotEmpty() }
        assertEquals(1, server.requestsTo("DELETE", "/api/shares/l1").size)
    }

    @Test
    fun `Stop sharing says what it does for them and asks first`() {
        server.on("GET", "/api/sessions/S1/shares", FakeServer.json(200, """{"data":[]}"""))
        server.on(
            "GET",
            "/api/sent",
            FakeServer.json(
                200,
                """{"data":[{"id":"m1","kind":"session","to":{"name":"Ana","email":"ana@example.com"},"sent_at":"2026-11-01T10:00:00Z"}]}"""
            )
        )
        server.on("DELETE", "/api/sent/m1", FakeServer.empty(204))
        showShareSheet()
        awaitText("Ana")

        compose.onNodeWithText("Stop sharing").performClick()
        compose.onNodeWithText(
            "Stop sharing with Ana? It leaves their list and they can no longer open it. A copy they already saved stays theirs."
        ).assertIsDisplayed()
        inDialog("Cancel").performClick()
        awaitAppIdle()
        assertTrue("Cancel sends nothing", server.requestsTo("DELETE", "/api/sent/m1").isEmpty())

        compose.onNodeWithText("Stop sharing").performClick()
        inDialog("Stop sharing").performClick()
        pumpUntil("sharing to stop") { server.requestsTo("DELETE", "/api/sent/m1").isNotEmpty() }
        assertEquals(1, server.requestsTo("DELETE", "/api/sent/m1").size)
    }

    @Test
    fun `Turn off on a request link asks first`() {
        server.on("GET", "/api/clients", FakeServer.json(200, """{"data":[]}"""))
        server.on("GET", "/api/request-links", FakeServer.json(200, """{"data":[{"id":"r1","title":"Invoices from Acme","received":2}]}"""))
        server.on("DELETE", "/api/request-links/r1", FakeServer.empty(204))
        show { ProjectsScreen(vm, onOpenItem = {}) }
        compose.onNodeWithContentDescription("Request files").performClick()
        awaitText("Invoices from Acme")

        compose.onNodeWithText("Turn off").performClick()
        compose.onNodeWithText(
            "Turn off this request link? It stops working for everyone who has it, and no more files can be sent with it."
        ).assertIsDisplayed()
        inDialog("Cancel").performClick()
        awaitAppIdle()
        assertTrue("Cancel sends nothing", server.requestsTo("DELETE", "/api/request-links/r1").isEmpty())

        compose.onNodeWithText("Turn off").performClick()
        inDialog("Turn off").performClick()
        pumpUntil("the link to be turned off") { server.requestsTo("DELETE", "/api/request-links/r1").isNotEmpty() }
        assertEquals(1, server.requestsTo("DELETE", "/api/request-links/r1").size)
    }
}
