package com.echoledger.mobile.ui

import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.ServerConfig
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * The redesign's wiring (contract 2.0.0-a/b): the interface flags and "Switch back to classic" come from the server,
 * Trash's keep time too, and Trash and Delete can tell a failure from an empty answer.
 */
class V2WiringTest : AppTest() {
    private val vm by lazy { MainViewModel(app).clearedAfterTest() }

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
    }

    /** The test config with [extra] fields added at its top level. */
    private fun config(extra: String = "") =
        ServerFixtures.config().trimEnd().removeSuffix("}") + (if (extra.isEmpty()) "}" else ",$extra}")

    private fun parse(extra: String = "") = ApiJson.decodeFromString(ServerConfig.serializer(), config(extra))

    private fun settings() = runBlocking { app.graph.settings.current() }

    /** Runs a view model call off the main thread while the main looper keeps going (its refresh needs it). */
    private fun <T> await(block: suspend () -> T): T {
        val call = CoroutineScope(Dispatchers.IO).async { block() }
        pumpUntil("the call") { call.isCompleted }
        return runBlocking { call.await() }
    }

    @Test
    fun `a config without the new fields means no flags, not classic, and 48 hours in Trash`() {
        val config = parse()
        assertTrue(config.uiFlags.isEmpty())
        assertFalse(config.uiClassic)
        assertNull(config.trash)

        server.on("GET", "/api/config", FakeServer.json(200, config()))
        await { vm.refresh().join() }
        assertTrue(settings().uiFlags.isEmpty())
        assertFalse(settings().uiClassic)
        assertEquals(48, settings().trashKeepHours)
    }

    @Test
    fun `only an exact true turns a flag on, and an odd value never breaks the config read`() {
        val config = parse(
            """"ui_flags": {"ui_v2.shell": true, "ui_v2.home": "true", "ui_v2.library": 1, "ui_v2.ask": null,
               "ui_v2.live": {"on": true}, "a_future_flag": true}, "ui_classic": "yes", "trash": {"keep_hours": 72}"""
        )
        assertEquals(true, config.uiFlags["ui_v2.shell"])
        listOf("ui_v2.home", "ui_v2.library", "ui_v2.ask", "ui_v2.live").forEach { assertEquals(it, false, config.uiFlags[it]) }
        assertFalse("a string is not true", config.uiClassic)
        assertEquals(72, config.trash?.keepHours)
        assertEquals("the rest of the config is read as before", 30, config.retentionDays)

        // Trash's keep time: only a positive whole number counts; the config is read whatever it holds.
        listOf(
            """"trash": null""",
            """"trash": {"keep_hours": "48"}""",
            """"trash": {"keep_hours": 48.5}""",
            """"trash": "always"""",
            """"trash": {"keep_hours": -3}""",
            """"ui_flags": null"""
        ).forEach { extra ->
            val read = parse(extra)
            assertEquals(extra, 30, read.retentionDays)
            assertEquals(extra, 48, read.trash?.keepHours ?: 48)
            assertTrue(extra, read.uiFlags.isEmpty())
        }
        assertEquals(36, parse(""""trash": {"keep_hours": 36}""").trash?.keepHours)

        val broken = parse(""""ui_flags": "everything", "ui_classic": 1""")
        assertTrue(broken.uiFlags.isEmpty())
        assertFalse(broken.uiClassic)
        assertEquals(30, broken.retentionDays)
    }

    @Test
    fun `refresh stores the flags, the classic choice and Trash's keep time`() {
        server.on(
            "GET",
            "/api/config",
            FakeServer.json(
                200,
                config(""""ui_flags": {"ui_v2.shell": true, "ui_v2.library": false}, "ui_classic": false, "trash": {"keep_hours": 24}""")
            )
        )
        await { vm.refresh().join() }
        assertEquals(mapOf("ui_v2.shell" to true, "ui_v2.library" to false), settings().uiFlags)
        assertFalse(settings().uiClassic)
        assertEquals(24, settings().trashKeepHours)
    }

    @Test
    fun `Switch back to classic is kept by the server, stored, and the config is read again`() {
        server.on("PUT", "/api/account/ui", FakeServer.json(200, """{"ui_flags": {"ui_v2.shell": false}, "ui_classic": true}"""))
        server.on("GET", "/api/config", FakeServer.json(200, config(""""ui_flags": {"ui_v2.shell": false}, "ui_classic": true""")))

        assertTrue(await { vm.applyClassicUi(true) })
        assertEquals("""{"classic":true}""", server.requestsTo("PUT", "/api/account/ui").single().bodyText())
        assertTrue(settings().uiClassic)
        assertEquals(false, settings().uiFlags["ui_v2.shell"])
        assertEquals("read again right after", 1, server.requestsTo("GET", "/api/config").size)

        // And back: "Try the new design".
        server.on("PUT", "/api/account/ui", FakeServer.json(200, """{"ui_flags": {"ui_v2.shell": true}, "ui_classic": false}"""))
        server.on("GET", "/api/config", FakeServer.json(200, config(""""ui_flags": {"ui_v2.shell": true}, "ui_classic": false""")))
        assertTrue(await { vm.applyClassicUi(false) })
        assertFalse(settings().uiClassic)
        assertEquals(true, settings().uiFlags["ui_v2.shell"])
    }

    @Test
    fun `a switch the server did not take changes nothing and says so`() {
        server.on("PUT", "/api/account/ui", FakeServer.json(500, """{"message":"Server Error"}"""))
        assertFalse(await { vm.applyClassicUi(true) })
        assertFalse(settings().uiClassic)
        assertEquals("Can't reach the server. Try again.", vm.message.value)
    }

    @Test
    fun `Trash tells an empty answer from one it could not load`() {
        server.on("GET", "/api/bin", FakeServer.json(200, """{"data":[]}"""))
        val empty = await { vm.binOrError() }
        assertTrue(empty.isSuccess && empty.getOrThrow().isEmpty())

        server.on("GET", "/api/bin", FakeServer.json(500, """{"message":"Server Error"}"""))
        assertTrue(await { vm.binOrError() }.isFailure)
        assertTrue("the classic list stays empty", await { vm.bin() }.isEmpty())
    }

    @Test
    fun `deleteAwait answers only once the server took the delete`() {
        server.on("DELETE", "/api/sessions/S1", FakeServer.empty(204))
        assertTrue(await { vm.deleteAwait("S1") }.isSuccess)
        assertEquals(1, server.requestsTo("DELETE", "/api/sessions/S1").size)

        server.on("DELETE", "/api/sessions/S2", FakeServer.json(500, """{"message":"Server Error"}"""))
        assertTrue(await { vm.deleteAwait("S2") }.isFailure)
    }
}
