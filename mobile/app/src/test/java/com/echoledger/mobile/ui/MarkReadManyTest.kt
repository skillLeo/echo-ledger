package com.echoledger.mobile.ui

import com.echoledger.mobile.core.Organise
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import java.util.concurrent.ConcurrentHashMap
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import mockwebserver3.RecordedRequest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Contract 2.0.0-y on the phone: Mark as read / unread for many. One request per 200; an older server (405, 404, 403 for
 * the DELETE, a 2xx with no `done`) is asked one recording at a time, at most 200 in the whole run; a server error, 429
 * or no connection stops the run instead. Only what the server did changes here, and gone ones bring a sync.
 */
class MarkReadManyTest : ScreenTest() {
    private val ids = (1..450).map { "01K%03d".format(it) }

    /** What the fake server holds: the read ones, and the gone ones (out of its library from then on). */
    private val serverRead: MutableSet<String> = ConcurrentHashMap.newKeySet()
    private val serverGone: MutableSet<String> = ConcurrentHashMap.newKeySet()

    private fun seed(read: Boolean = false) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        serverRead.clear()
        serverGone.clear()
        if (read) serverRead += ids
        server.on("GET", "/api/library") { FakeServer.json(200, ServerFixtures.libraryPage(rows())) }
        runBlocking { app.graph.library.refresh() }
    }

    private fun rows() = ids.map { id ->
        if (id in serverGone) {
            ServerFixtures.tombstone(id)
        } else {
            ServerFixtures.libraryItem(id, title = "Caller $id").replace("\"updated_at\"", "\"opened\":${id in serverRead},\"updated_at\"")
        }
    }

    private fun serverDid(method: String?, done: List<String>) {
        if (method == "POST") serverRead += done else serverRead -= done.toSet()
    }

    private fun quoted(ids: List<String>) = ids.joinToString(",", "[", "]") { "\"$it\"" }

    private fun idsOf(request: RecordedRequest) =
        ApiJson.parseToJsonElement(request.bodyText()).jsonObject.getValue("ids").jsonArray.map { it.jsonPrimitive.content }

    /** The bulk route of a 2.0.0-y server: every id done but [gone], which it no longer has. */
    private fun bulkAnswers(gone: Set<String> = emptySet()): (RecordedRequest) -> MockResponse = { request ->
        val asked = idsOf(request)
        val done = asked.filterNot { it in gone }
        serverGone += asked.filter { it in gone }
        serverDid(request.method, done)
        FakeServer.json(200, """{"done":${quoted(done)},"not_found":${quoted(asked - done.toSet())}}""")
    }

    /** The one-recording route, on any server. */
    private val oneAnswer: (RecordedRequest) -> MockResponse = { request ->
        serverDid(request.method, listOf(request.url.encodedPath.substringAfter("/api/sessions/").substringBefore("/opened")))
        FakeServer.empty(204)
    }

    /** The ids asked one at a time with [method] since request number [from]. */
    private fun singles(method: String, from: Int = 0) = server.requests.drop(from)
        .filter { it.method == method && Regex("/api/sessions/01K\\d+/opened").matches(it.url.encodedPath) }
        .map { it.url.encodedPath.substringAfter("/api/sessions/").substringBefore("/opened") }

    private fun opened(id: String) = app.graph.library.items.value.first { it.id == id }.opened

    private fun listed(id: String) = app.graph.library.items.value.any { it.id == id }

    @Test
    fun `450 go in three requests, and only what the server did changes here, a gone one bringing a sync`() {
        seed()
        server.on("POST", "/api/sessions/opened", bulkAnswers(gone = setOf("01K010")))
        val syncs = server.requestsTo("GET", "/api/library").size
        val run = runBlocking { vm.setOpened(ids, true) }
        assertEquals(listOf(200, 200, 50), server.requestsTo("POST", "/api/sessions/opened").map { idsOf(it).size })
        assertEquals(449, run.done.size)
        assertEquals(setOf("01K010"), run.notFound)
        assertTrue(run.left.isEmpty())
        assertTrue(singles("POST").isEmpty())
        assertEquals(true, opened("01K001"))
        assertEquals(true, opened("01K450"))
        assertTrue("gone ones bring a sync", server.requestsTo("GET", "/api/library").size > syncs)
        assertTrue("and leave the list", !listed("01K010"))
    }

    @Test
    fun `an older server is asked one recording at a time, at most 200 in the run, and the rest stay`() {
        seed()
        server.on("POST", "/api/sessions/opened", FakeServer.json(405, """{"message":"Not allowed","code":"method_not_allowed"}"""))
        server.on("POST", "/api/sessions/01K\\d+/opened", oneAnswer)
        val run = runBlocking { vm.setOpened(ids, true) }
        assertEquals(ids.take(Organise.MAX_BULK), singles("POST"))
        assertEquals(200, run.done.size)
        assertEquals(ids.drop(200), run.left)
        assertTrue("capped, not failed", run.capped)
        assertEquals(true, opened("01K200"))
        assertEquals(false, opened("01K201"))
    }

    @Test
    fun `when the first 200 went in one request and the next are refused as an older server, only those go one at a time`() {
        seed()
        var bulk = 0
        val answers = bulkAnswers()
        server.on("POST", "/api/sessions/opened") { request ->
            if (bulk++ == 0) answers(request) else FakeServer.json(405, """{"message":"Not allowed","code":"method_not_allowed"}""")
        }
        server.on("POST", "/api/sessions/01K\\d+/opened", oneAnswer)
        val run = runBlocking { vm.setOpened(ids, true) }
        assertEquals("never the first 200 again", ids.subList(200, 400), singles("POST"))
        assertEquals(400, run.done.size)
        assertEquals(ids.drop(400), run.left)
    }

    @Test
    fun `a server error or too many requests stops the run, never one at a time`() {
        for (status in listOf(503, 429, 403)) {
            seed()
            server.on("POST", "/api/sessions/opened", FakeServer.json(status, """{"message":"No.","code":"x"}"""))
            server.on("POST", "/api/sessions/01K\\d+/opened", oneAnswer)
            val from = server.requests.size
            val run = runBlocking { vm.setOpened(ids.take(10), true) }
            assertEquals("$status", status, (run.failure as ApiException).status)
            assertTrue("$status", singles("POST", from).isEmpty())
            assertEquals(ids.take(10), run.left)
        }
    }

    @Test
    fun `for unread an older server's 404 or 403 means one at a time, with DELETE on each`() {
        for (status in listOf(404, 403)) {
            seed(read = true)
            server.on("DELETE", "/api/sessions/opened", FakeServer.json(status, """{"message":"No.","code":"x"}"""))
            server.on("DELETE", "/api/sessions/01K\\d+/opened", oneAnswer)
            val from = server.requests.size
            val run = runBlocking { vm.setOpened(ids.take(3), false) }
            assertEquals("$status", ids.take(3), singles("DELETE", from))
            assertEquals(ids.take(3).toSet(), run.done)
            assertEquals(false, opened("01K001"))
        }
    }

    @Test
    fun `a 2xx with no done is an older server too`() {
        seed()
        server.on("POST", "/api/sessions/opened", FakeServer.json(200, """{"message":"ok"}"""))
        server.on("POST", "/api/sessions/01K\\d+/opened", oneAnswer)
        val run = runBlocking { vm.setOpened(ids.take(2), true) }
        assertEquals(ids.take(2), singles("POST"))
        assertEquals(ids.take(2).toSet(), run.done)
    }

    @Test
    fun `one at a time, a failure partway keeps what was done and a gone one is skipped`() {
        seed()
        server.on("POST", "/api/sessions/opened", FakeServer.json(405, """{"message":"Not allowed","code":"method_not_allowed"}"""))
        server.on("POST", "/api/sessions/01K\\d+/opened", oneAnswer)
        server.on("POST", "/api/sessions/01K003/opened") {
            serverGone += "01K003"
            FakeServer.json(404, """{"message":"Not found","code":"session_not_found"}""")
        }
        server.on("POST", "/api/sessions/01K005/opened", FakeServer.json(503, """{"message":"Down","code":"x"}"""))
        val run = runBlocking { vm.setOpened(ids.take(10), true) }
        assertEquals(setOf("01K001", "01K002", "01K004"), run.done)
        assertEquals(setOf("01K003"), run.notFound)
        assertEquals(503, (run.failure as ApiException).status)
        assertEquals(ids.subList(4, 10), run.left)
        assertEquals(true, opened("01K004"))
        assertEquals(false, opened("01K005"))
        assertTrue("the gone one leaves the list", !listed("01K003"))
    }

    @Test
    fun `one recording's Mark as unread and Mark as read go at once, here and to the server`() {
        seed(read = true)
        server.on("DELETE", "/api/sessions/01K001/opened", oneAnswer)
        server.on("POST", "/api/sessions/01K001/opened", oneAnswer)
        val item = app.graph.library.items.value.first { it.id == "01K001" }
        vm.markReadAs(item, read = false)
        pumpUntil("unread") { opened("01K001") == false && server.requestsTo("DELETE", "/api/sessions/01K001/opened").isNotEmpty() }
        vm.markReadAs(app.graph.library.items.value.first { it.id == "01K001" }, read = true)
        pumpUntil("read") { opened("01K001") == true && server.requestsTo("POST", "/api/sessions/01K001/opened").isNotEmpty() }
    }
}
