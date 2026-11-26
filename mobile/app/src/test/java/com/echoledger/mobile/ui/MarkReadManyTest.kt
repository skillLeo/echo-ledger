package com.echoledger.mobile.ui

import com.echoledger.mobile.core.Organise
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicInteger
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withTimeout
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import mockwebserver3.RecordedRequest
import mockwebserver3.SocketEffect
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
        // The sync runs on its own (the answer doesn't wait for it), and the gone one leaves the list when it ends.
        waitFor("gone ones to bring a sync") { server.requestsTo("GET", "/api/library").size > syncs }
        waitFor("the gone one to leave the list") { !listed("01K010") }
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
        waitFor("the gone one to leave the list") { !listed("01K003") }
    }

    @Test
    fun `one recording's Mark as unread and Mark as read go at once, here and to the server`() {
        seed(read = true)
        server.on("DELETE", "/api/sessions/01K001/opened", oneAnswer)
        server.on("POST", "/api/sessions/01K001/opened", oneAnswer)
        assertTrue(runBlocking { vm.markReadAs(item("01K001"), read = false) }.isSuccess)
        assertEquals(false, opened("01K001"))
        assertEquals(1, server.requestsTo("DELETE", "/api/sessions/01K001/opened").size)
        assertTrue(runBlocking { vm.markReadAs(item("01K001"), read = true) }.isSuccess)
        assertEquals(true, opened("01K001"))
        assertEquals(1, server.requestsTo("POST", "/api/sessions/01K001/opened").size)
    }

    /** Review of 2.0.0-y: one the server doesn't take goes back as it was, and the failure is the result, for its words. */
    @Test
    fun `one recording the server doesn't take goes back as it was`() {
        val answers = listOf(
            405 to FakeServer.json(405, """{"message":"The DELETE method is not supported.","code":"method_not_allowed"}"""),
            403 to FakeServer.json(403, """{"message":"You can't change this recording.","code":"forbidden"}"""),
            503 to FakeServer.json(503, """{"message":"Down","code":"x"}"""),
            // A connection closed before any answer (as ApiClientErrorHandlingTest).
            0 to MockResponse.Builder().code(200).onResponseStart(SocketEffect.CloseSocket()).build()
        )
        for ((status, answer) in answers) {
            seed(read = true)
            server.on("DELETE", "/api/sessions/01K001/opened", answer)
            val failure = runBlocking { vm.markReadAs(item("01K001"), read = false) }.exceptionOrNull()
            assertEquals("$status: the dot is back", true, opened("01K001"))
            if (status == 0) {
                assertTrue("no connection: $failure", failure is java.io.IOException && failure !is ApiException)
            } else {
                assertEquals(status, (failure as ApiException).status)
            }
        }
    }

    /** A scope of the test's own, so a call can wait while the test answers the server. */
    private val elsewhere = CoroutineScope(Dispatchers.IO)

    private fun waitFor(what: String, done: () -> Boolean) {
        val end = System.currentTimeMillis() + 10_000
        while (!done()) {
            check(System.currentTimeMillis() < end) { "Timed out waiting for $what" }
            Thread.sleep(10)
        }
    }

    /** The opening's POST for 01K001 waits for [gate]; [answered] turns true once the server has taken it. */
    private fun slowOpening(gate: FakeServer.Hold, answered: AtomicBoolean) {
        server.on("POST", "/api/sessions/01K001/opened") { request ->
            gate.await(30)
            oneAnswer(request).also { answered.set(true) }
        }
    }

    /** Follow-up review of 2.0.0-y (1): a refused change goes back only while it is still the latest to its recording. */
    @Test
    fun `a change refused after newer ones leaves the newest showing`() {
        seed(read = true)
        val late = server.hold()
        val deletes = AtomicInteger()
        server.on("DELETE", "/api/sessions/01K001/opened") { request ->
            if (deletes.getAndIncrement() == 0) {
                late.await(30)
                FakeServer.json(503, """{"message":"Down","code":"x"}""")
            } else {
                oneAnswer(request)
            }
        }
        server.on("POST", "/api/sessions/01K001/opened", oneAnswer)
        // Unread (its answer comes late, and is a refusal), then read, then unread again.
        val first = elsewhere.async { vm.markReadAs(item("01K001"), read = false) }
        waitFor("the first on its way") { server.requestsTo("DELETE", "/api/sessions/01K001/opened").isNotEmpty() }
        val second = elsewhere.async { vm.markReadAs(item("01K001"), read = true) }
        waitFor("read here") { opened("01K001") == true }
        val third = elsewhere.async { vm.markReadAs(item("01K001"), read = false) }
        waitFor("unread here") { opened("01K001") == false }
        late.open()
        val results = runBlocking { withTimeout(20_000) { listOf(first.await(), second.await(), third.await()) } }
        assertEquals("the phone shows the newest", false, opened("01K001"))
        assertEquals(503, (results[0].exceptionOrNull() as ApiException).status)
        assertTrue(results[1].isSuccess && results[2].isSuccess)
        assertTrue("the server holds the newest", "01K001" !in serverRead)
    }

    /** Follow-up review of 2.0.0-y (2): Mark as unread just after opening reaches the server after the opening. */
    @Test
    fun `marking unread just after opening leaves it unread on the server too`() {
        seed()
        val gate = server.hold()
        val answered = AtomicBoolean()
        slowOpening(gate, answered)
        server.on("DELETE", "/api/sessions/01K001/opened", oneAnswer)
        vm.markOpened(item("01K001"))
        waitFor("the opening on its way") { server.requestsTo("POST", "/api/sessions/01K001/opened").isNotEmpty() }
        waitFor("read here") { opened("01K001") == true }
        val unread = elsewhere.async { vm.markReadAs(item("01K001"), read = false) }
        waitFor("unread here at once") { opened("01K001") == false }
        gate.open()
        assertTrue(runBlocking { withTimeout(20_000) { unread.await() } }.isSuccess)
        waitFor("the opening answered") { answered.get() }
        assertTrue("the server holds unread", "01K001" !in serverRead)
        assertEquals(false, opened("01K001"))
    }

    /** Follow-up review of 2.0.0-y (2): the same for marking many, one of them just opened. */
    @Test
    fun `marking many unread just after opening one of them leaves it unread on the server too`() {
        seed()
        val gate = server.hold()
        val answered = AtomicBoolean()
        slowOpening(gate, answered)
        server.on("DELETE", "/api/sessions/opened", bulkAnswers())
        vm.markOpened(item("01K001"))
        waitFor("the opening on its way") { server.requestsTo("POST", "/api/sessions/01K001/opened").isNotEmpty() }
        waitFor("read here") { opened("01K001") == true }
        val run = elsewhere.async { vm.setOpened(ids.take(2), false) }
        // Time for the run's request to go, were it not to wait for the opening's.
        val end = System.currentTimeMillis() + 2_000
        while (server.requestsTo("DELETE", "/api/sessions/opened").isEmpty() && System.currentTimeMillis() < end) Thread.sleep(10)
        gate.open()
        assertEquals(ids.take(2).toSet(), runBlocking { withTimeout(20_000) { run.await() } }.done)
        waitFor("the opening answered") { answered.get() }
        assertTrue("the server holds unread", "01K001" !in serverRead)
        assertEquals(false, opened("01K001"))
    }

    /** Follow-up review of 2.0.0-y (3): the answer, and so its toast, doesn't wait for the sync a gone one brings. */
    @Test
    fun `marking many doesn't wait for the sync a gone one brings`() {
        seed()
        val sync = server.hold()
        server.on("GET", "/api/library") {
            sync.await(30)
            FakeServer.json(200, ServerFixtures.libraryPage(rows()))
        }
        server.on("POST", "/api/sessions/opened", bulkAnswers(gone = setOf("01K002")))
        val run = runBlocking { withTimeout(5_000) { vm.setOpened(ids.take(3), true) } }
        assertEquals(setOf("01K002"), run.notFound)
        sync.open()
        waitFor("the gone one to leave the list") { !listed("01K002") }
    }

    /** Follow-up review of 2.0.0-y (3): the same for one recording. */
    @Test
    fun `one recording's answer doesn't wait for the sync when it is gone`() {
        seed(read = true)
        val sync = server.hold()
        server.on("GET", "/api/library") {
            sync.await(30)
            FakeServer.json(200, ServerFixtures.libraryPage(rows()))
        }
        server.on("DELETE", "/api/sessions/01K001/opened") {
            serverGone += "01K001"
            FakeServer.json(404, """{"message":"Not found","code":"session_not_found"}""")
        }
        val result = runBlocking { withTimeout(5_000) { vm.markReadAs(item("01K001"), read = false) } }
        assertEquals(404, (result.exceptionOrNull() as ApiException).status)
        sync.open()
        waitFor("the gone one to leave the list") { !listed("01K001") }
    }

    /** Follow-up review of 2.0.0-y (4): only the asked ones change here, whatever else the server's done lists. */
    @Test
    fun `only the asked ones change here, whatever else the server's done lists`() {
        seed()
        server.on("POST", "/api/sessions/opened") { request ->
            FakeServer.json(200, """{"done":${quoted(idsOf(request) + "01K400")},"not_found":[]}""")
        }
        val run = runBlocking { vm.setOpened(ids.take(2), true) }
        assertEquals(ids.take(2).toSet(), run.done)
        assertEquals(true, opened("01K002"))
        assertEquals("not asked, so not changed here", false, opened("01K400"))
    }

    private val down = """{"message":"Down","code":"x"}"""

    /** Review of the follow-ups (1): two changes to one recording, both refused, leave what the server has. */
    @Test
    fun `two changes to one recording, both refused, leave what the server has`() {
        seed(read = true)
        val late = server.hold()
        server.on("DELETE", "/api/sessions/01K001/opened") {
            late.await(30)
            FakeServer.json(503, down)
        }
        server.on("POST", "/api/sessions/01K001/opened", FakeServer.json(503, down))
        // Mark as unread (refused, late), then Mark as read (refused).
        val first = elsewhere.async { vm.markReadAs(item("01K001"), read = false) }
        waitFor("the first on its way") { server.requestsTo("DELETE", "/api/sessions/01K001/opened").isNotEmpty() }
        val second = elsewhere.async { vm.markReadAs(item("01K001"), read = true) }
        waitFor("read here") { opened("01K001") == true }
        late.open()
        val results = runBlocking { withTimeout(20_000) { listOf(first.await(), second.await()) } }
        assertTrue(results.all { it.isFailure })
        assertEquals("read, as the server has it", true, opened("01K001"))
    }

    /** Review of the follow-ups (1): after an opening the server didn't take, a refused Mark as unread shows what it has. */
    @Test
    fun `after an opening the server didn't take, a refused Mark as unread leaves it unread, as the server has it`() {
        seed()
        server.on("POST", "/api/sessions/01K001/opened", FakeServer.json(503, down))
        server.on("DELETE", "/api/sessions/01K001/opened", FakeServer.json(503, down))
        vm.markOpened(item("01K001"))
        waitFor("read here") { opened("01K001") == true }
        val result = runBlocking { withTimeout(20_000) { vm.markReadAs(item("01K001"), read = false) } }
        assertTrue(result.isFailure)
        assertEquals("unread, as the server has it", false, opened("01K001"))
    }

    /** Review of the follow-ups (1): what a sync brings is what the server has, for a refusal after it. */
    @Test
    fun `a refusal after a sync goes back to what the sync brought`() {
        seed()
        server.on("POST", "/api/sessions/01K001/opened", FakeServer.json(503, down))
        server.on("DELETE", "/api/sessions/01K001/opened", FakeServer.json(503, down))
        assertTrue(runBlocking { vm.markReadAs(item("01K001"), read = true) }.isFailure)
        assertEquals(false, opened("01K001"))
        // Read on another device: the next sync brings it.
        serverRead += "01K001"
        runBlocking { app.graph.library.refresh() }
        assertEquals(true, opened("01K001"))
        assertTrue(runBlocking { vm.markReadAs(item("01K001"), read = false) }.isFailure)
        assertEquals("read, as the sync brought it", true, opened("01K001"))
    }

    /** Review of the follow-ups (2): a local write that fails (as on a full disk) doesn't hold up the next change. */
    @Test
    fun `a failed local write doesn't hold up the next change to that recording`() {
        seed(read = true)
        server.on("DELETE", "/api/sessions/01K001/opened", oneAnswer)
        server.on("POST", "/api/sessions/01K001/opened", oneAnswer)
        // A folder where the cache's temporary file goes: writing the cache fails.
        val blocker = java.io.File(app.noBackupFilesDir, "library.bin.tmp").apply { mkdirs() }
        try {
            runCatching { runBlocking { withTimeout(5_000) { vm.markReadAs(item("01K001"), read = false) } } }
        } finally {
            blocker.delete()
        }
        val now = item("01K001").opened != false
        val next = runBlocking { withTimeout(5_000) { vm.markReadAs(item("01K001"), read = !now) } }
        assertTrue(next.isSuccess)
        assertEquals(!now, opened("01K001"))
    }

    /** Review of the follow-ups, round 3 (1): one at a time, a part's done ones are the server's before a later change goes. */
    @Test
    fun `one at a time, what a part did counts as the server's before a later change to it goes`() {
        seed()
        val older = FakeServer.json(405, """{"message":"Not allowed","code":"method_not_allowed"}""")
        server.on("POST", "/api/sessions/opened", older)
        server.on("DELETE", "/api/sessions/opened", older)
        server.on("POST", "/api/sessions/01K\\d+/opened", oneAnswer)
        server.on("DELETE", "/api/sessions/01K001/opened", oneAnswer)
        // 01K201, in the run's second part, has a request on its way first: the run waits there after its first part.
        val gate = server.hold()
        server.on("POST", "/api/sessions/01K201/opened") { request ->
            gate.await(30)
            oneAnswer(request)
        }
        val first = elsewhere.async { vm.markReadAs(item("01K201"), read = true) }
        waitFor("01K201's request on its way") { server.requestsTo("POST", "/api/sessions/01K201/opened").isNotEmpty() }
        val run = elsewhere.async { vm.setOpened(ids.take(250), true) }
        waitFor("the run's first part done") { singles("POST").count { it != "01K201" } == Organise.MAX_BULK }
        // Marked unread meanwhile (01K001 is in the first part), and the server takes it.
        assertEquals(setOf("01K001"), runBlocking { withTimeout(20_000) { vm.setOpened(listOf("01K001"), false) } }.done)
        gate.open()
        runBlocking { withTimeout(20_000) { first.await() to run.await() } }
        // A refused Mark as read now goes back to what the server has: unread.
        server.on("POST", "/api/sessions/01K001/opened", FakeServer.json(503, down))
        assertTrue(runBlocking { vm.markReadAs(item("01K001"), read = true) }.isFailure)
        assertEquals("unread, as the server has it", false, opened("01K001"))
    }

    /** Review of the follow-ups, round 3 (2): a sync that read the old state while a change was on its way doesn't stay. */
    @Test
    fun `a sync that read the old state before the server took a change doesn't stay on screen`() {
        seed()
        val gate = server.hold()
        server.on("POST", "/api/sessions/01K001/opened") { request ->
            gate.await(30)
            oneAnswer(request)
        }
        val mark = elsewhere.async { vm.markReadAs(item("01K001"), read = true) }
        waitFor("on its way") { server.requestsTo("POST", "/api/sessions/01K001/opened").isNotEmpty() }
        runBlocking { app.graph.library.refresh() }
        assertEquals("the sync read the old state", false, opened("01K001"))
        gate.open()
        assertTrue(runBlocking { withTimeout(20_000) { mark.await() } }.isSuccess)
        assertEquals("read, as the server has it now", true, opened("01K001"))
    }

    private fun item(id: String) = app.graph.library.items.value.first { it.id == id }
}
