package com.echoledger.mobile.assist

import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.ui.v2.assist.AssistMessage
import com.echoledger.mobile.ui.v2.assist.SendKind
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.Dispatcher
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import mockwebserver3.RecordedRequest
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** A phone session end to end: captions to the relay and the server, the card's commands, its reports and the end. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class PhoneAssistTest {
    private lateinit var server: MockWebServer
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val sockets = CopyOnWriteArrayList<WebSocket>()
    private val frames = LinkedBlockingQueue<JsonObject>()
    private val calls = LinkedBlockingQueue<String>()

    /** The server side, recorded: what the phone asked of the REST API. */
    private inner class Server : AssistServer {
        val rows = CopyOnWriteArrayList<TurnRow>()
        var acceptTurns = true
        var grant: RelayGrant? = null

        override suspend fun token(sessionId: String): RelayGrant? = grant

        override suspend fun turns(sessionId: String, rows: List<TurnRow>): Boolean {
            calls += "turns"
            if (acceptTurns) this.rows += rows
            return acceptTurns
        }

        override suspend fun action(sessionId: String, suggestionId: String, action: String, renderMs: Long?) {
            calls += "action $suggestionId $action ${renderMs ?: ""}".trim()
        }

        override suspend fun end(sessionId: String) {
            calls += "end $sessionId"
        }
    }

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()
        AssistHub.reset()
    }

    @After
    fun tearDown() {
        AssistHub.reset()
        scope.cancel()
        sockets.forEach { runCatching { it.cancel() } }
        server.close()
    }

    private fun accept() {
        server.enqueue(
            MockResponse.Builder().webSocketUpgrade(
                object : WebSocketListener() {
                    override fun onOpen(webSocket: WebSocket, response: Response) {
                        sockets += webSocket
                        webSocket.send("""{"type":"ready","seq":1,"session_id":"s1","mode":"balanced","resumed":false}""")
                    }

                    override fun onMessage(webSocket: WebSocket, text: String) {
                        frames += Json.parseToJsonElement(text).jsonObject
                    }

                    override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
                        webSocket.close(code, null)
                    }
                }
            ).build()
        )
    }

    private val wsUrl get() = server.url("/v1/assist").toString().replaceFirst("http://", "ws://")

    private fun session(api: Server = Server(), translateTo: String? = null, autoSuggestions: Boolean = true) = PhoneAssist(
        "s1",
        RelayGrant(wsUrl, "t1", null, emptyList()),
        null,
        api,
        scope,
        translateTo,
        secondsLeft = 3600,
        elapsed = { 0 },
        autoSuggestions = autoSuggestions
    )

    /** The relay side, saying ready with [ready] (its own JSON) as soon as the phone connects. */
    private fun acceptWith(ready: String) {
        server.enqueue(
            MockResponse.Builder().webSocketUpgrade(
                object : WebSocketListener() {
                    override fun onOpen(webSocket: WebSocket, response: Response) {
                        sockets += webSocket
                        webSocket.send(ready)
                    }

                    override fun onMessage(webSocket: WebSocket, text: String) {
                        frames += Json.parseToJsonElement(text).jsonObject
                    }

                    override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
                        webSocket.close(code, null)
                    }
                }
            ).build()
        )
    }

    private fun next(): JsonObject = frames.poll(5, TimeUnit.SECONDS) ?: error("no frame from the phone")

    private fun JsonObject.s(key: String) = this[key]?.jsonPrimitive?.content

    @Test
    fun `the other side's words reach the relay as they come, the phone's own only when finished`() = runBlocking {
        accept()
        val assist = session()
        assist.start()
        assist.caption(other = true, "What is", final = false)
        assist.caption(other = false, "Well", final = false)
        assist.caption(other = true, "What is the price?", final = true, startMs = 1000, endMs = 2400)
        assist.caption(other = false, "It is ten.", final = true, startMs = 2600, endMs = 3500)
        val sent = (1..3).map { next() }
        assertEquals(listOf("turn_partial", "turn_final", "turn_final"), sent.map { it.s("type") })
        assertEquals(listOf("o-1", "o-1", "m-1"), sent.map { it.s("turn_id") })
        assertNull(frames.poll(200, TimeUnit.MILLISECONDS))

        val lines = assist.snapshot().filterIsInstance<AssistMessage.Turn>()
        assertEquals(4, lines.size)
        assertTrue(assist.snapshot().first() is AssistMessage.Budget)
        assist.end()
    }

    @Test
    fun `final turns go to the server with the relay's turn ids, and a failed batch is sent again`() {
        accept()
        val api = Server().apply { acceptTurns = false }
        val assist = session(api)
        assist.start()
        repeat(TurnBatch.AT_MOST) { assist.caption(other = it % 2 == 0, "Line $it", final = true, startMs = 0, endMs = 1) }
        assertEquals("turns", calls.poll(5, TimeUnit.SECONDS))
        assertTrue(api.rows.isEmpty())
        api.acceptTurns = true
        assist.end()
        // The end sends what waits before ending the session.
        assertEquals("turns", calls.poll(5, TimeUnit.SECONDS))
        assertEquals("end s1", calls.poll(5, TimeUnit.SECONDS))
        assertEquals(TurnBatch.AT_MOST, api.rows.size)
        assertEquals("o-1", api.rows.first().clientRef)
        assertEquals("m-1", api.rows[1].clientRef)
    }

    @Test
    fun `the card's commands go to the relay, translate with the person's language`() {
        accept()
        val assist = session(translateTo = "urdu")
        assist.start()
        assist.send(SendKind.HelpNow)
        assist.send(SendKind.Translate)
        assertEquals(SendKind.HelpNow.wire, next().s("type"))
        val translate = next()
        assertEquals(SendKind.Translate.wire, translate.s("type"))
        assertEquals("urdu", translate.s("language"))
        assist.end()
    }

    @Test
    fun `a mono recording starts with automatic suggestions paused, and the card says so`() = runBlocking {
        accept()
        val assist = session(autoSuggestions = false)
        assist.start()
        val first = next()
        assertEquals("context_update", first.s("type"))
        assertEquals("paused", first["settings"]!!.jsonObject.s("assist"))
        assertTrue(AssistMessage.AutoPaused in assist.snapshot())
        // Help me now still asks.
        assist.send(SendKind.HelpNow)
        assertEquals(SendKind.HelpNow.wire, next().s("type"))
        assist.end()
    }

    @Test
    fun `both sides recorded apart, nothing is paused`() = runBlocking {
        accept()
        val assist = session()
        assist.start()
        assist.send(SendKind.HelpNow)
        assertEquals(SendKind.HelpNow.wire, next().s("type"))
        assertFalse(AssistMessage.AutoPaused in assist.snapshot())
        assist.end()
    }

    @Test
    fun `every token refresh brings the month's assist time to the card`() = runBlocking {
        val expires = java.time.Instant.ofEpochMilli(System.currentTimeMillis() + 120_000 + 200).toString()
        acceptWith("""{"type":"ready","seq":1,"session_id":"s1","mode":"balanced","resumed":false,"expires_at":"$expires"}""")
        val api = Server().apply { grant = RelayGrant(wsUrl, "t2", null, emptyList(), secondsLeft = 1200) }
        val assist = session(api)
        assist.start()
        assertEquals("token_refresh", next().s("type"))
        assertEquals(AssistMessage.Budget(1200, null), assist.snapshot().filterIsInstance<AssistMessage.Budget>().last())
        assist.end()
    }

    @Test
    fun `a suggestion is reported shown once, with its render time`() {
        val assist = session()
        assist.reportShown("g1", 180)
        assist.reportShown("g1", 900)
        assist.feedback("g1", used = true)
        val got = listOfNotNull(calls.poll(5, TimeUnit.SECONDS), calls.poll(5, TimeUnit.SECONDS)).sorted()
        assertEquals(listOf("action g1 shown 180", "action g1 used"), got)
        assertNull(calls.poll(200, TimeUnit.MILLISECONDS))
    }

    @Test
    fun `replaced by another connection, this phone stops following without ending the session`() {
        accept()
        val assist = session()
        assist.start()
        val deadline = System.currentTimeMillis() + 5_000
        while (sockets.isEmpty() && System.currentTimeMillis() < deadline) Thread.sleep(10)
        sockets[0].close(4409, "replaced")
        while (!assist.ended && System.currentTimeMillis() < deadline) Thread.sleep(10)
        assertTrue(assist.ended)
        Thread.sleep(300)
        assertFalse(calls.any { it.startsWith("end") })
        assertTrue(runBlocking { assist.snapshot() }.last() == AssistMessage.Ended)
    }

    @Test
    fun `the hub starts a session on the recording under way, linked to it`() = runBlocking {
        val requests = LinkedBlockingQueue<RecordedRequest>()
        val api = MockWebServer()
        api.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                requests += request
                return MockResponse.Builder().code(201).addHeader("Content-Type", "application/json").body(
                    """{"session":{"id":"a1","auto_saved":false,"mode":"balanced","practice":false},
                        "relay":{"url":"$wsUrl","token":"t1","expires_at":null},"stt":null,
                        "plan":{"modes":["fast","balanced"],"seconds_left":3600}}"""
                ).build()
            }
        }
        api.start()
        try {
            val client = ApiClient(api.url("/").toString(), emptyList(), "7|token")
            val pins = listOf("sha256/" + "A".repeat(43) + "=")
            assertEquals(AssistHub.Start.NoRecording, AssistHub.start(client, pins, scope))
            AssistHub.recordingSession("rec-1", bothSides = false)
            assertEquals(AssistHub.Start.NotSetUp, AssistHub.start(client, emptyList(), scope))
            assertEquals(0, api.requestCount)

            accept()
            val started = AssistHub.start(client, pins, scope) as AssistHub.Start.Started
            assertEquals("a1", started.assist.sessionId)
            val body = Json.parseToJsonElement(requests.poll(5, TimeUnit.SECONDS)!!.body!!.utf8()).jsonObject
            assertEquals("android", body.s("platform"))
            assertEquals("rec-1", body.s("recording_session_id"))
            assertEquals("false", body.s("practice"))
            assertEquals(started.assist, AssistHub.active.value)

            // A mono recording: automatic suggestions paused from the start.
            assertEquals("paused", next()["settings"]!!.jsonObject.s("assist"))
            // Captions now reach it; the recording's end ends it and the hub forgets it.
            AssistHub.caption(other = true, "Hello?", final = true, startMs = 0, endMs = 500)
            assertEquals("turn_final", next().s("type"))
            AssistHub.recordingSession(null)
            assertTrue(started.assist.ended)
            assertNull(AssistHub.active.value)
            val end = requests.poll(5, TimeUnit.SECONDS)!!
            val last = generateSequence { requests.poll(1, TimeUnit.SECONDS) }.lastOrNull() ?: end
            assertEquals("/api/assist/sessions/a1/end", last.url.encodedPath)
        } finally {
            api.close()
        }
    }

    @Test
    fun `a session already on (409) is busy, with the person's say-so it is ended and this one starts`() = runBlocking {
        val paths = CopyOnWriteArrayList<String>()
        var starts = 0
        val api = MockWebServer()
        api.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                paths += request.url.encodedPath
                return when (request.url.encodedPath) {
                    "/api/assist/sessions" -> if (starts++ < 2) {
                        MockResponse.Builder().code(409).addHeader("Content-Type", "application/json")
                            .body(
                                """{"code":"session_active","message":"A session is on.","session_id":"old-1","practice":false}"""
                            ).build()
                    } else {
                        MockResponse.Builder().code(201).addHeader("Content-Type", "application/json").body(
                            """{"session":{"id":"a2"},"relay":{"url":"$wsUrl","token":"t1"}}"""
                        ).build()
                    }

                    else -> MockResponse.Builder().code(
                        200
                    ).addHeader("Content-Type", "application/json").body("""{"report":"queued"}""").build()
                }
            }
        }
        api.start()
        try {
            val client = ApiClient(api.url("/").toString(), emptyList(), "7|token")
            val pins = listOf("sha256/" + "A".repeat(43) + "=")
            AssistHub.recordingSession("rec-1")
            assertEquals(AssistHub.Start.Busy, AssistHub.start(client, pins, scope))
            accept()
            val started = AssistHub.start(client, pins, scope, endOther = true)
            assertTrue(started is AssistHub.Start.Started)
            assertEquals(
                listOf("/api/assist/sessions", "/api/assist/sessions", "/api/assist/sessions/old-1/end", "/api/assist/sessions"),
                paths
            )
            AssistHub.end()
        } finally {
            api.close()
        }
    }

    @Test
    fun `turn rows are sent as the contract's batch`() = runBlocking {
        val bodies = LinkedBlockingQueue<String>()
        val api = MockWebServer()
        api.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                bodies += request.url.encodedPath + " " + request.body!!.utf8()
                return MockResponse.Builder().code(200).addHeader("Content-Type", "application/json").body("""{"accepted":1}""").build()
            }
        }
        api.start()
        try {
            val server = ApiAssistServer({ ApiClient(api.url("/").toString(), emptyList(), "7|token") }, { emptyList() })
            assertTrue(server.turns("a1", listOf(TurnRow("o-4", true, 81200, 86400, "When can you start?", "en", true))))
            val (path, body) = bodies.poll(5, TimeUnit.SECONDS)!!.split(" ", limit = 2)
            assertEquals("/api/assist/sessions/a1/turns", path)
            val turn = Json.parseToJsonElement(body).jsonObject["turns"]!!.jsonArray.single().jsonObject
            assertEquals("o-4", turn.s("client_ref"))
            assertEquals("other", turn.s("channel"))
            assertEquals("81200", turn.s("start_ms"))
            assertEquals("true", turn.s("is_question"))

            server.action("a1", "g1", "shown", 140)
            val (actionPath, actionBody) = bodies.poll(5, TimeUnit.SECONDS)!!.split(" ", limit = 2)
            assertEquals("/api/assist/sessions/a1/suggestions/g1/action", actionPath)
            assertEquals("140", Json.parseToJsonElement(actionBody).jsonObject.s("render_ms"))

            // A token refresh carries the month's assist time.
            api.dispatcher = object : Dispatcher() {
                override fun dispatch(request: RecordedRequest): MockResponse = MockResponse.Builder().code(200)
                    .addHeader("Content-Type", "application/json")
                    .body("""{"relay":{"url":"wss://relay.example/v1/assist","token":"t9"},"stt":null,"plan":{"seconds_left":900}}""")
                    .build()
            }
            val fresh = server.token("a1")!!
            assertEquals("t9", fresh.token)
            assertEquals(900L, fresh.secondsLeft)
        } finally {
            api.close()
        }
    }
}
