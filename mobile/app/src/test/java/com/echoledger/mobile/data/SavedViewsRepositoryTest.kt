package com.echoledger.mobile.data

import com.echoledger.mobile.core.FilterSection
import com.echoledger.mobile.core.LibraryFilterSet
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import java.io.File
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.launchIn
import kotlinx.coroutines.flow.onEach
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * My views on the phone (contract 2.0.0-p "Saved views"): kept per account (server, email and workspace) and shown only
 * once it is known; made offline with a client_ref that makes it once however often it is sent; read again when the
 * library's views_version is not the copy's; a permanent refusal (validation_failed, views_limit, view_not_found) drops
 * or gives back the change and says why, every other error leaves it waiting; signing out deletes the copy.
 */
class SavedViewsRepositoryTest : AppTest() {
    private companion object {
        const val ZOOM = """{"source":{"op":"is","values":["zoom"]}}"""
    }

    private val views get() = app.graph.views
    private val problems = CopyOnWriteArrayList<ViewProblem>()
    private val account = "http://phone.test|asad@example.com|7"

    private fun view(id: String, name: String, ref: String? = null, position: Int = 0, filters: String = ZOOM) =
        """{"id":"$id","name":"$name","position":$position,"filters":$filters,"search":"budget","sort":"newest","group":"date",
            "density":"comfortable","client_ref":${ref?.let { "\"$it\"" } ?: "null"}}"""

    private fun list(version: Int, vararg items: String) = """{"data":[${items.joinToString(",")}],"views_version":$version,"limit":30}"""

    private val first = """{"id":"01JV000000000000000000000A","name":"WhatsApp this week","position":0,
        "filters":{"source":{"op":"is","values":["whatsapp"]},"date":{"op":"is","values":["last_7_days"]},"colour":{"values":["red"]}},
        "search":"invoice","sort":"name","group":"source","density":"compact","client_ref":null}"""
    private val oneView = list(4, first)

    private val spec = ViewSpec(filters = LibraryFilterSet().toggle(FilterSection.SOURCE, "zoom").toJson(), search = " budget ")

    @Before
    fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/views", FakeServer.json(200, oneView))
        views.problems.onEach { problems += it }.launchIn(CoroutineScope(Dispatchers.Unconfined))
    }

    /**
     * Waits for the sync running now, then runs one more, off the main thread (the main looper keeps turning, as the
     * app's work may need it).
     */
    private fun settle(serverVersion: Long? = null) {
        val done = java.util.concurrent.atomic.AtomicBoolean(false)
        CoroutineScope(Dispatchers.IO).launch {
            try {
                views.sync(serverVersion, wait = true)
            } finally {
                done.set(true)
            }
        }
        pumpUntil("synced") { done.get() }
    }

    @Test
    fun noneShowsUntilTheAccountIsKnownThenTheServersCopyReadForgivingly() {
        assertTrue(views.views.value.isEmpty())
        assertFalse(views.ready.value)
        try {
            views.create("Mine", spec)
            error("made without an account")
        } catch (e: ViewError) {
            assertEquals(ViewError.Reason.NOT_READY, e.reason)
        }
        assertTrue(server.requestsTo("GET", "/api/views").isEmpty())
        views.use(account)
        settle()
        val view = views.views.value.single()
        assertEquals("WhatsApp this week", view.name)
        // The section this app doesn't know is left out; the rest kept, in the panel's order.
        assertEquals(listOf(FilterSection.DATE, FilterSection.SOURCE), view.spec.filterSet.sections)
        assertEquals("invoice", view.spec.search)
        assertEquals("name", view.spec.sort)
        assertEquals("source", view.spec.group)
        assertEquals(4L, views.version())
    }

    @Test
    fun aViewMadeOfflineIsSentWithItsClientRefAndMadeOnce() {
        views.use(account)
        settle()
        server.on("POST", "/api/views", FakeServer.json(502, """{"message":"Bad gateway"}"""))
        val local = views.create("Zoom budget", spec)
        settle()
        // It shows at once, waiting.
        assertEquals(listOf("WhatsApp this week", "Zoom budget"), views.views.value.map { it.name })
        assertEquals("create", views.views.value.last().pending)
        val tried = server.requestsTo("POST", "/api/views").first()
        val body = Json.parseToJsonElement(tried.bodyText()).jsonObject
        assertEquals(local.clientRef, body.getValue("client_ref").jsonPrimitive.content)
        assertEquals("""{"source":{"op":"is","values":["zoom"]}}""", body.getValue("filters").toString())
        assertEquals("budget", body.getValue("search").jsonPrimitive.content)
        // Back online: the same client_ref again; the server answers the view it made, and it is the one view.
        server.on("POST", "/api/views") { req ->
            val ref = Json.parseToJsonElement(req.bodyText()).jsonObject.getValue("client_ref").jsonPrimitive.content
            FakeServer.json(201, """{"data":${view("01JV000000000000000000000B", "Zoom budget", ref, 1)},"views_version":5}""")
        }
        val made = view("01JV000000000000000000000B", "Zoom budget", local.clientRef, 1)
        server.on("GET", "/api/views", FakeServer.json(200, list(5, first, made)))
        settle()
        val sent = server.requestsTo("POST", "/api/views").map {
            Json.parseToJsonElement(it.bodyText()).jsonObject.getValue("client_ref").jsonPrimitive.content
        }
        assertTrue(sent.size >= 2)
        assertEquals(setOf(local.clientRef), sent.toSet())
        assertEquals(listOf("01JV000000000000000000000A", "01JV000000000000000000000B"), views.views.value.map { it.id })
        assertTrue(views.views.value.all { it.pending == null })
    }

    @Test
    fun theCopyIsReadAgainOnlyWhenTheLibrarysViewsVersionIsNotItsOwn() {
        views.use(account)
        settle()
        val reads = server.requestsTo("GET", "/api/views").size
        settle(serverVersion = 4)
        assertEquals(reads, server.requestsTo("GET", "/api/views").size)
        server.on("GET", "/api/views", FakeServer.json(200, list(6, first.replace("WhatsApp this week", "Renamed elsewhere"))))
        settle(serverVersion = 6)
        assertEquals(reads + 1, server.requestsTo("GET", "/api/views").size)
        assertEquals("Renamed elsewhere", views.views.value.single().name)
        assertEquals(6L, views.version())
    }

    @Test
    fun theLibrarySyncCarriesTheViewsVersion() {
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(200, """{"data":[],"next_cursor":null,"server_time":"2026-11-23T10:00:00+00:00","views_version":9}""")
        )
        runBlocking { app.graph.library.refresh() }
        assertEquals(9L, app.graph.library.viewsVersion.value)
    }

    @Test
    fun aViewThePhoneCantKeepAnyMoreIsDroppedAndSaid() {
        views.use(account)
        settle()
        server.on("POST", "/api/views", FakeServer.json(422, """{"message":"You can keep 30 views.","code":"views_limit","limit":30}"""))
        views.create("Thirty-first", spec)
        settle()
        assertEquals(listOf("WhatsApp this week"), views.views.value.map { it.name })
        pumpUntil("said") { problems.isNotEmpty() }
        assertEquals(ViewProblem(ViewProblem.Kind.NOT_SAVED_FULL, "Thirty-first"), problems.single())
    }

    @Test
    fun aRefusedChangeIsGivenBackAndAViewGoneElsewhereIsDroppedEachSaid() {
        views.use(account)
        settle()
        val id = views.views.value.single().id
        server.on("PATCH", "/api/views/.+", FakeServer.json(422, """{"message":"The name is too long.","code":"validation_failed"}"""))
        views.rename(id, "New name")
        settle()
        // The server's name is back, and why is said.
        assertEquals("WhatsApp this week", views.views.value.single().name)
        assertNull(views.views.value.single().pending)
        pumpUntil("said") { problems.isNotEmpty() }
        assertEquals(ViewProblem.Kind.NOT_CHANGED, problems.last().kind)
        // A rename sends only the name: the filters a newer app saved stay as they are.
        val body = Json.parseToJsonElement(server.requestsTo("PATCH", "/api/views/.+").last().bodyText()).jsonObject
        assertEquals(setOf("name"), body.keys)
        // Deleted on another device meanwhile.
        server.on("PATCH", "/api/views/.+", FakeServer.json(404, """{"message":"Not found.","code":"view_not_found"}"""))
        server.on("GET", "/api/views", FakeServer.json(200, list(7)))
        views.update(id, spec)
        settle()
        assertTrue(views.views.value.isEmpty())
        pumpUntil("said again") { problems.size >= 2 }
        assertEquals(ViewProblem(ViewProblem.Kind.GONE, "WhatsApp this week"), problems.last())
    }

    @Test
    fun everyOtherErrorLeavesTheChangeWaiting() {
        views.use(account)
        settle()
        val id = views.views.value.single().id
        server.on("DELETE", "/api/views/.+", FakeServer.json(429, """{"message":"Slow down.","code":"rate_limited"}"""))
        views.remove(id)
        settle()
        // Gone from the list at once, and still to be sent.
        assertTrue(views.views.value.isEmpty())
        assertTrue(problems.isEmpty())
        server.on("DELETE", "/api/views/.+", FakeServer.json(200, """{"id":"$id","views_version":5}"""))
        server.on("GET", "/api/views", FakeServer.json(200, list(5)))
        settle()
        assertTrue(server.requestsTo("DELETE", "/api/views/.+").size >= 2)
        assertTrue(views.views.value.isEmpty())
        assertEquals(5L, views.version())
    }

    @Test
    fun eachAccountHasItsOwnCopyAndTheLastIsRememberedForTheSameServer() {
        views.use(account)
        settle()
        assertEquals(1, views.views.value.size)
        server.on("GET", "/api/views", FakeServer.json(200, list(0)))
        views.use("http://phone.test|someone@example.com|7")
        settle()
        assertTrue(views.views.value.isEmpty())
        views.use(account)
        // This account's copy shows at once, before any answer.
        assertEquals("WhatsApp this week", views.views.value.single().name)
        assertEquals(account, views.lastAccount("http://phone.test/"))
        assertNull(views.lastAccount("http://other.test"))
        // The key needs all three.
        assertNull(SavedViewsRepository.accountKey("http://phone.test", null, 7))
        assertNull(SavedViewsRepository.accountKey("http://phone.test", "a@b.c", null))
        assertEquals("http://phone.test|a@b.c|7", SavedViewsRepository.accountKey("HTTP://Phone.test/", " A@B.c ", 7))
    }

    @Test
    fun aNameIsCheckedHereFirst() {
        views.use(account)
        settle()
        fun reason(name: String) = runCatching { views.create(name, spec) }.exceptionOrNull().let { (it as ViewError).reason }
        assertEquals(ViewError.Reason.EMPTY, reason("   "))
        assertEquals(ViewError.Reason.TOO_LONG, reason("x".repeat(61)))
        assertEquals(ViewError.Reason.TAKEN, reason("whatsapp  THIS week"))
        server.on("POST", "/api/views", FakeServer.json(502, """{"message":"Bad gateway"}"""))
        repeat(29) { views.create("View $it", spec) }
        assertEquals(ViewError.Reason.FULL, reason("One more"))
    }

    @Test
    fun signingOutDeletesTheCopy() {
        views.use(account)
        settle()
        assertTrue(File(app.noBackupFilesDir, "views.bin").exists())
        val vm = com.echoledger.mobile.ui.MainViewModel(app).clearedAfterTest()
        val out = vm.unpair()
        pumpUntil("signed out") { out.isCompleted }
        assertTrue(views.views.value.isEmpty())
        assertFalse(File(app.noBackupFilesDir, "views.bin").exists())
        assertNull(views.lastAccount(server.baseUrl))
    }
}
