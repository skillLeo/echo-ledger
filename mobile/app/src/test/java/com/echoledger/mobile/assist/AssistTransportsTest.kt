package com.echoledger.mobile.assist

import com.echoledger.mobile.data.AssistHttpDto
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.ui.v2.assist.AssistCard
import com.echoledger.mobile.ui.v2.assist.AssistMessage
import com.echoledger.mobile.ui.v2.assist.CardState
import com.echoledger.mobile.ui.v2.assist.SendKind
import java.io.File
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
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.boolean
import kotlinx.serialization.json.contentOrNull
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
import org.junit.Test

/**
 * Both transports give the card the same thing (contract 1.9.0-q): the shared card sequences
 * (docs/v2/assist-card-sequences.json) run through a phone session on the relay's WebSocket and on HTTP mode against a
 * stand-in server, and the card after each message is the one the sequences expect. Also HTTP mode's turns, and the
 * start's transport answer and refusals.
 */
class AssistTransportsTest {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val closing = CopyOnWriteArrayList<AutoCloseable>()

    @After
    fun tearDown() {
        AssistHub.reset()
        scope.cancel()
        closing.forEach { runCatching { it.close() } }
    }

    private val elapsed = { System.nanoTime() / 1_000_000 }

    /** The REST side, recorded; HTTP mode's POSTs go to [api]. */
    private class Server(private val api: ApiClient? = null) : AssistServer {
        val calls = LinkedBlockingQueue<String>()

        override suspend fun token(sessionId: String): LiveGrant? = null

        override suspend fun liveCall(url: String, json: String): okhttp3.Call? = api?.assistLive(url, json)

        override suspend fun turns(sessionId: String, rows: List<TurnRow>): Boolean {
            calls += "turns"
            return true
        }

        override suspend fun action(sessionId: String, suggestionId: String, action: String, renderMs: Long?) = Unit

        override suspend fun end(sessionId: String) {
            calls += "end $sessionId"
        }
    }

    private fun sequences() = Json.parseToJsonElement(
        File(
            File(System.getProperty("screenshots.dir") ?: "../../docs/design/screens").parentFile!!.parentFile,
            "v2/assist-card-sequences.json"
        ).readText()
    ).jsonObject["sequences"]!!.jsonArray.map { it.jsonObject }

    private fun JsonObject.s(key: String) = (this[key] as? JsonPrimitive)?.contentOrNull

    private fun waitFor(what: String, check: () -> Boolean) {
        val until = System.currentTimeMillis() + 5_000
        while (!check()) {
            check(System.currentTimeMillis() < until) { "timed out waiting: $what" }
            Thread.sleep(5)
        }
    }

    private fun history(assist: PhoneAssist) = runBlocking { assist.snapshot() }

    /** The card after the messages so far is the one [expect] describes. */
    private fun check(at: String, assist: PhoneAssist, count: Int, expect: JsonObject) {
        waitFor("$at: $count messages") { history(assist).size >= count }
        val state = AssistCard.reduce(history(assist), CardState())
        assertEquals(at, expect.s("shown_id"), state.shownId)
        assertEquals(at, expect.s("text"), state.shown?.text.orEmpty())
        assertEquals(at, expect.s("status"), state.shown?.status?.name?.lowercase())
        assertEquals(at, expect["skipped"]!!.jsonPrimitive.boolean, state.skipped)
        expect["also"]?.jsonArray?.forEach { one ->
            val id = one.jsonObject.s("id")!!
            val found = state.suggestions.firstOrNull { it.id == id }
            assertEquals("$at, $id", one.jsonObject.s("text"), found?.text)
            assertEquals("$at, $id", one.jsonObject.s("status"), found?.status?.name?.lowercase())
        }
    }

    @Test
    fun `the shared card sequences give the same card over the relay`() {
        val sequences = sequences()
        assertTrue(sequences.size >= 16)
        for (sequence in sequences) {
            val name = "relay: " + sequence.s("name")
            val relay = MockWebServer().also { it.start() }
            closing += relay
            val sockets = CopyOnWriteArrayList<WebSocket>()
            relay.enqueue(
                MockResponse.Builder().webSocketUpgrade(
                    object : WebSocketListener() {
                        override fun onOpen(webSocket: WebSocket, response: Response) {
                            sockets += webSocket
                        }
                    }
                ).build()
            )
            val url = relay.url("/v1/assist").toString().replaceFirst("http://", "ws://")
            val assist = PhoneAssist("01JSESSION", RelayGrant(url, "t1", null, emptyList()), null, Server(), scope, elapsed = elapsed)
            assist.start()
            waitFor("$name: the connection") { sockets.size == 1 }
            sequence["steps"]!!.jsonArray.forEachIndexed { i, step ->
                sockets[0].send(step.jsonObject["message"]!!.jsonObject.toString())
                check("$name, step ${i + 1}", assist, i + 1, step.jsonObject["expect"]!!.jsonObject)
            }
            assist.end()
        }
    }

    @Test
    fun `the shared card sequences give the same card in HTTP mode, each suggestion on its own stream`() {
        for (sequence in sequences()) {
            val name = "http: " + sequence.s("name")
            val sse = SseStandIn()
            closing += sse
            val api = ApiClient(sse.base, emptyList(), "7|device")
            val steps = sequence["steps"]!!.jsonArray.map { it.jsonObject }
            val first = steps.first()["message"]!!.jsonObject
            assertEquals("ready", first.s("type"))
            // The start's http.ready is the relay's ready without its type and seq.
            val ready = JsonObject(first - "type" - "seq")
            val grant = HttpGrant.of(AssistHttpDto(sse.url("/api/assist/sessions/01JSESSION/live"), ready), "01JSESSION")
            val assist = PhoneAssist("01JSESSION", grant, null, Server(api), scope, elapsed = elapsed)
            assist.start()

            val owner = mutableMapOf<String, SseStandIn.Post>()
            val writing = mutableSetOf<String>()
            val ended = mutableSetOf<SseStandIn.Post>()
            var latest: SseStandIn.Post? = null
            fun finish(post: SseStandIn.Post?) {
                if (post != null && ended.add(post)) post.end()
            }
            fun stream(kind: SendKind = SendKind.HelpNow): SseStandIn.Post {
                assist.send(kind)
                return sse.next().also { latest = it }
            }
            var count = 1
            check("$name, step 1", assist, count, steps.first()["expect"]!!.jsonObject)
            steps.drop(1).forEachIndexed { i, step ->
                val m = step["message"]!!.jsonObject
                val id = m.s("suggestion_id")
                when (m.s("type")) {
                    // A resumed ready has no HTTP form: its counterpart is a stream that drops. A stream writing a
                    // suggestion lets it go; with none being written, a stream that drops changes nothing.
                    "ready" -> {
                        val cut = writing.map { owner.getValue(it) }.distinct()
                        if (cut.isEmpty()) {
                            stream().also { ended += it }.drop()
                        } else {
                            cut.forEach { post ->
                                ended += post
                                post.drop()
                            }
                            count += cut.size
                        }
                        writing.clear()
                    }

                    "suggestion_start" -> {
                        val post = stream()
                        owner[id!!] = post
                        writing += id
                        post.send(m)
                        count++
                    }

                    "token" -> {
                        owner.getValue(id!!).send(m)
                        count++
                    }

                    "suggestion_end" -> {
                        when (m.s("status")) {
                            // The new question's stream ends the suggestion on the card.
                            "superseded" -> (latest?.takeIf { it !in ended && it !== owner[id] } ?: stream()).send(m)

                            // The cancel's own stream says so.
                            "cancelled" -> stream(SendKind.Cancel).also { it.send(m) }.also(::finish)

                            else -> owner.getValue(id!!).send(m)
                        }
                        writing -= id!!
                        finish(owner[id])
                        count++
                    }

                    "skip", "error" -> {
                        val post = id?.let { owner.getValue(it) } ?: stream()
                        post.send(m)
                        id?.let { writing -= it }
                        finish(post)
                        count++
                    }

                    else -> error("$name: no such message in the sequences: ${m.s("type")}")
                }
                check("$name, step ${i + 2}", assist, count, step["expect"]!!.jsonObject)
            }
            Thread.sleep(100)
            assertEquals("$name: nothing more reached the card", count, history(assist).size)
            assist.end()
        }
    }

    @Test
    fun `in HTTP mode the other side's partials stay home, the phone's own turn sends one, and the server keeps the turns`() {
        val sse = SseStandIn()
        closing += sse
        val server = Server(ApiClient(sse.base, emptyList(), "7|device"))
        val grant = HttpGrant.of(AssistHttpDto(sse.url("/api/assist/sessions/a1/live")), "a1")
        val assist = PhoneAssist("a1", grant, null, server, scope, elapsed = elapsed)
        assist.start()
        assertTrue("the card has its ready at once", history(assist).first() is AssistMessage.Ready)

        assist.caption(other = true, "What is the", final = false)
        assist.caption(other = true, "What is the price?", final = true, startMs = 0, endMs = 900)
        val otherFinal = sse.next()
        assertEquals("turn_final", otherFinal.json.s("type"))
        assertEquals("other", otherFinal.json.s("channel"))
        assertEquals("What is the price?", otherFinal.json.s("text"))
        otherFinal.end()

        assist.caption(other = false, "It is", final = false)
        assist.caption(other = false, "It is four", final = false)
        val mine = sse.next()
        assertEquals("turn_partial", mine.json.s("type"))
        assertEquals("me", mine.json.s("channel"))
        mine.end()
        assertTrue("once per turn", sse.quiet())
        assist.caption(other = false, "It is four thousand.", final = true, startMs = 1_000, endMs = 2_000)
        assertEquals("turn_final", sse.next().also { it.end() }.json.s("type"))
        assist.caption(other = false, "Also", final = false)
        assertEquals("turn_partial", sse.next().also { it.end() }.json.s("type"))

        assist.end()
        assertEquals("end a1", server.calls.poll(5, TimeUnit.SECONDS))
        assertNull("no turns batch in HTTP mode", server.calls.poll(300, TimeUnit.MILLISECONDS))
        assertTrue(sse.quiet())
    }

    /** A REST stand-in answering the session start with [start] (status, JSON) and recording every request. */
    private fun rest(start: Pair<Int, String>, requests: LinkedBlockingQueue<RecordedRequest>): MockWebServer {
        val api = MockWebServer()
        api.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                requests += request
                val (code, body) = if (request.url.encodedPath == "/api/assist/sessions") start else 200 to "{}"
                return MockResponse.Builder().code(code).addHeader("Content-Type", "application/json").body(body).build()
            }
        }
        api.start()
        closing += api
        return api
    }

    private val pins = listOf("sha256/" + "A".repeat(43) + "=")

    @Test
    fun `the hub starts HTTP mode when the server answers http, and the card's messages go to its live address`() = runBlocking {
        val requests = LinkedBlockingQueue<RecordedRequest>()
        val api = rest(0 to "", requests)
        val live = api.url("/api/assist/sessions/a1/live").toString()
        api.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                requests += request
                return if (request.url.encodedPath == "/api/assist/sessions") {
                    MockResponse.Builder().code(201).addHeader("Content-Type", "application/json").body(
                        """{"session":{"id":"a1","auto_saved":true,"mode":"balanced","practice":false},"transport":"http","relay":null,
                            "http":{"live_url":"$live","ready":{"protocol":1,"session_id":"a1","mode":"fast","requested_mode":"balanced",
                            "allowed_modes":["fast"],"resumed":false,"practice":false,"limits":{"messages_per_second":10}}},
                            "stt":null,"plan":{"modes":["fast"],"seconds_left":1200}}"""
                    ).build()
                } else {
                    MockResponse.Builder().code(200).addHeader("Content-Type", "text/event-stream").body("event: end\ndata: {}\n\n").build()
                }
            }
        }
        val client = ApiClient(api.url("/").toString(), emptyList(), "7|token")
        AssistHub.recordingSession("rec-1")
        // The phone offers the relay first; the server falls back to HTTP mode, which needs no relay pins.
        val started = AssistHub.start(client, emptyList(), scope) as AssistHub.Start.Started
        val body = Json.parseToJsonElement(requests.poll(5, TimeUnit.SECONDS)!!.body!!.utf8()).jsonObject
        assertEquals(listOf("relay", "http"), body["transports"]!!.jsonArray.map { it.jsonPrimitive.content })
        val ready = history(started.assist).filterIsInstance<AssistMessage.Ready>().single()
        assertEquals("a1", ready.sessionId)
        assertEquals("fast", ready.mode.name.lowercase())

        started.assist.send(SendKind.HelpNow)
        val post = requests.poll(5, TimeUnit.SECONDS)!!
        assertEquals("/api/assist/sessions/a1/live", post.url.encodedPath)
        assertEquals("Bearer 7|token", post.headers["Authorization"])
        assertEquals("help_now", Json.parseToJsonElement(post.body!!.utf8()).jsonObject.s("type"))
        AssistHub.end()
    }

    @Test
    fun `an app too old for the session and a relay that is down are plain messages`() = runBlocking {
        val requests = LinkedBlockingQueue<RecordedRequest>()
        AssistHub.recordingSession("rec-1")

        val old = rest(426 to """{"code":"app_update_required","message":"Live Assist needs the latest version of the app."}""", requests)
        val tooOld = AssistHub.start(ApiClient(old.url("/").toString(), emptyList(), "7|token"), pins, scope)
        assertEquals(AssistHub.Start.Refused("Live Assist needs the latest version of the app."), tooOld)
        val offered = Json.parseToJsonElement(requests.poll(5, TimeUnit.SECONDS)!!.body!!.utf8()).jsonObject
        assertEquals(listOf("relay", "http"), offered["transports"]!!.jsonArray.map { it.jsonPrimitive.content })

        // Without relay pins the phone still offers the relay first, so an app too old reads the same.
        assertEquals(
            AssistHub.Start.Refused("Live Assist needs the latest version of the app."),
            AssistHub.start(ApiClient(old.url("/").toString(), emptyList(), "7|token"), emptyList(), scope)
        )
        val again = Json.parseToJsonElement(requests.poll(5, TimeUnit.SECONDS)!!.body!!.utf8()).jsonObject
        assertEquals(listOf("relay", "http"), again["transports"]!!.jsonArray.map { it.jsonPrimitive.content })

        val down =
            rest(
                503 to """{"code":"relay_unavailable","message":"Live Assist can't connect right now. Try again in a few minutes."}""",
                requests
            )
        val relayDown = AssistHub.start(ApiClient(down.url("/").toString(), emptyList(), "7|token"), pins, scope)
        assertEquals(AssistHub.Start.Failed("Live Assist can't connect right now. Try again in a few minutes."), relayDown)
        assertNull(AssistHub.active.value)
        assertFalse(requests.any { it.url.encodedPath.endsWith("/end") })
    }
}
