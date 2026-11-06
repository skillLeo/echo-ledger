package com.echoledger.mobile.ui

import com.echoledger.mobile.data.SenderDto
import com.echoledger.mobile.data.SentItemDto
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.pairLocally
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** The sender's side of sending to a teammate (docs/API.md, since v1.5): who it went to, and taking it back. */
class StopSharingTest : AppTest() {
    private val vm by lazy { MainViewModel(app).clearedAfterTest() }

    @Before
    fun setUp() {
        runBlocking { app.pairLocally(server) }
    }

    @Test
    fun `it asks for what was sent of this recording and reads the answer`() {
        server.on(
            "GET",
            "/api/sent",
            FakeServer.json(
                200,
                """{"data":[{"id":"m1","kind":"session","to":{"name":"Ana","email":"ana@example.com"},"sent_at":"2026-11-01T10:00:00Z"}]}"""
            )
        )

        val sent = runBlocking { vm.sentOf("s1", null) }

        assertEquals(listOf("m1"), sent.map { it.id })
        assertEquals("s1", server.requestsTo("GET", "/api/sent").single().url.queryParameter("session_id"))
    }

    @Test
    fun `a server without the list shows an empty one, not an error`() {
        server.on("GET", "/api/sent", FakeServer.json(404, """{"message":"Not found"}"""))

        assertTrue(runBlocking { vm.sentOf(null, "c1") }.isEmpty())
    }

    @Test
    fun `stopping sends the delete and says so`() {
        server.on("DELETE", "/api/sent/m1", FakeServer.json(204, ""))

        assertTrue(runBlocking { vm.stopSharing("m1") })
        assertEquals(1, server.requestsTo("DELETE", "/api/sent/m1").size)
    }

    @Test
    fun `a row names the address beside the name and says when they kept a copy`() {
        val item =
            SentItemDto("m1", to = SenderDto("Ana", "ana@example.com"), sentAt = "2026-11-01T10:00:00Z", savedAt = "2026-11-02T10:00:00Z")

        assertEquals("ana@example.com · sent 2026-11-01 · they saved a copy", ShareText.sentMeta(item))
        assertEquals(
            "sent 2026-11-01",
            ShareText.sentMeta(SentItemDto("m2", to = SenderDto(null, "bo@x.org"), sentAt = "2026-11-01T10:00:00Z"))
        )
    }
}
