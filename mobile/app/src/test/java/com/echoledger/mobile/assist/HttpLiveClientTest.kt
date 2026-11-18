package com.echoledger.mobile.assist

import com.echoledger.mobile.data.AssistHttpDto
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.ui.v2.assist.AssistMessage
import com.echoledger.mobile.ui.v2.assist.EndStatus
import com.echoledger.mobile.ui.v2.assist.LiveMode
import com.echoledger.mobile.ui.v2.assist.SendKind
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import okhttp3.RequestBody.Companion.toRequestBody
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** HTTP mode against a stand-in server: one POST per message, its event stream, the card's order, limits and refusals. */
class HttpLiveClientTest {
    private val sse = SseStandIn()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val events = CopyOnWriteArrayList<RelayEvent>()
    private val ended = LinkedBlockingQueue<RelayEnd>()
    private val api = ApiClient(sse.base, emptyList(), "7|device")

    @After
    fun tearDown() {
        scope.cancel()
        sse.close()
    }

    private fun grant(path: String = "/api/assist/sessions/a1/live", rate: Int = 200) = HttpGrant.of(
        AssistHttpDto(
            sse.url(path),
            Json.parseToJsonElement(
                """{"protocol":1,"session_id":"a1","mode":"balanced","requested_mode":"balanced","allowed_modes":["balanced","fast"],
                   "resumed":false,"practice":false,"limits":{"max_message_bytes":2097152,"max_turn_chars":4000,"messages_per_second":$rate}}"""
            ).jsonObject
        ),
        "a1"
    )

    private fun client(
        grant: HttpGrant = grant(),
        refresh: suspend () -> HttpGrant? = { null },
        policy: HttpPolicy = HttpPolicy(),
        call: suspend (String, String) -> okhttp3.Call? = { url, json -> api.assistLive(url, json) }
    ) = HttpLiveClient(
        grant = grant,
        refresh = refresh,
        call = call,
        scope = scope,
        policy = policy,
        elapsed = { System.nanoTime() / 1_000_000 },
        onEvent = { events += it },
        onEnded = { ended += it }
    )

    private fun msg(type: String, vararg fields: Pair<String, Any?>) = buildJsonObject {
        put("type", type)
        fields.forEach { (k, v) ->
            when (v) {
                null -> put(k, kotlinx.serialization.json.JsonNull)
                is String -> put(k, v)
                is Boolean -> put(k, v)
                is Number -> put(k, v)
            }
        }
    }

    private fun start(id: String, replaces: String? = null) =
        msg("suggestion_start", "suggestion_id" to id, "turn_id" to "o-1", "kind" to "answer", "replaces" to replaces, "mode" to "balanced")

    private fun cards() = events.filterIsInstance<RelayEvent.Card>().map { it.message }

    private fun waitFor(what: String, check: () -> Boolean) {
        val until = System.currentTimeMillis() + 5_000
        while (!check()) {
            check(System.currentTimeMillis() < until) { "timed out waiting: $what" }
            Thread.sleep(10)
        }
    }

    @Test
    fun `the ready comes with the grant, and each message is one POST with the device token and no seq`() {
        val live = client()
        live.start()
        val ready = events.first() as RelayEvent.Ready
        assertEquals("a1", ready.sessionId)
        assertEquals(listOf(LiveMode.Balanced, LiveMode.Fast), ready.allowedModes)
        assertFalse(ready.resumed)

        live.send(ClientMessage.Command(SendKind.HelpNow))
        val post = sse.next()
        assertEquals("POST", post.method)
        assertEquals("/api/assist/sessions/a1/live", post.path)
        assertEquals("Bearer 7|device", post.headers["authorization"])
        assertEquals("text/event-stream", post.headers["accept"])
        assertEquals("help_now", post.json["type"]!!.jsonPrimitive.content)
        assertNull("no seq in HTTP mode", post.json["seq"])

        // Keep-alives are skipped; an event whose data has no type takes the event's name.
        post.keepAlive()
        post.send(start("s-1"))
        post.write("event: token\ndata: {\"suggestion_id\":\"s-1\",\"text\":\"Yes\"}\n\n")
        post.send(msg("suggestion_end", "suggestion_id" to "s-1", "status" to "done"))
        post.end()
        waitFor("the three messages") { cards().size == 3 }
        assertEquals(AssistMessage.Token("s-1", "Yes"), cards()[1])
        assertEquals(AssistMessage.SuggestionEnd("s-1", EndStatus.Done), cards()[2])
        Thread.sleep(200)
        assertEquals("an ended stream that ended its suggestion lets nothing go", 3, cards().size)

        // A message with nothing to say answers just end.
        live.send(ClientMessage.ContextUpdate(buildJsonObject { put("brief", "Sales call") }))
        val context = sse.next()
        assertEquals("Sales call", context.json["brief"]!!.jsonPrimitive.content)
        context.end()
        live.stop()
        assertEquals(RelayEnd.Closed, ended.poll(5, TimeUnit.SECONDS))
    }

    @Test
    fun `messages leave in order, at most four streams open, the next when one ends`() {
        val live = client()
        live.start()
        repeat(6) { live.send(ClientMessage.Command(SendKind.HelpNow, turnId = "o-$it")) }
        val first = (1..4).map { sse.next() }
        assertEquals((0..3).map { "o-$it" }, first.map { it.json["turn_id"]!!.jsonPrimitive.content })
        assertTrue("a fifth waits while four are open", sse.quiet(500))
        first[2].end()
        assertEquals("o-4", sse.next().json["turn_id"]!!.jsonPrimitive.content)
        assertTrue(sse.quiet(300))
        first[0].drop()
        assertEquals("o-5", sse.next().json["turn_id"]!!.jsonPrimitive.content)
    }

    @Test
    fun `the events of every open stream reach the card in arrival order, and the server ends what another stream wrote`() {
        val live = client()
        live.start()
        live.send(ClientMessage.Command(SendKind.HelpNow))
        val one = sse.next()
        one.send(start("s-1"))
        one.send(msg("token", "suggestion_id" to "s-1", "text" to "First"))
        waitFor("s-1's token") { cards().size == 2 }

        // A new question's stream ends the suggestion on the card; s-1's stream then stops without another word of it.
        live.send(ClientMessage.TurnFinal(Turn("o-2", true, "And the price?", 0, 900)))
        val two = sse.next()
        two.send(msg("suggestion_end", "suggestion_id" to "s-1", "status" to "superseded"))
        two.send(start("s-2"))
        waitFor("s-2") { cards().size == 4 }
        one.end()
        two.send(msg("token", "suggestion_id" to "s-2", "text" to "Second"))
        waitFor("s-2's token") { cards().size == 5 }
        assertEquals(
            listOf("SuggestionStart", "Token", "SuggestionEnd", "SuggestionStart", "Token"),
            cards().map { it::class.simpleName }
        )
        Thread.sleep(200)
        assertFalse("s-1 was ended by the server: nothing to let go", cards().any { it is AssistMessage.Lost })

        // s-2's stream drops: s-2 gets no end, so the card is told to clear it.
        two.drop()
        waitFor("s-2 let go") { cards().lastOrNull() == AssistMessage.Lost(setOf("s-2")) }
    }

    @Test
    fun `a stream that ends before the other stream's superseded end lets nothing go`() {
        val live = client()
        live.start()
        live.send(ClientMessage.Command(SendKind.HelpNow))
        val one = sse.next()
        one.send(start("s-1"))
        live.send(ClientMessage.Command(SendKind.HelpNow))
        val two = sse.next()
        two.send(start("s-2"))
        waitFor("both starts") { cards().size == 2 }
        // s-1's stream stops first; the end of s-1 arrives afterwards on s-2's stream.
        one.end()
        Thread.sleep(200)
        two.send(msg("suggestion_end", "suggestion_id" to "s-1", "status" to "superseded"))
        waitFor("the superseded end") { cards().size == 3 }
        assertFalse(cards().any { it is AssistMessage.Lost })
    }

    @Test
    fun `the session is refreshed on time, and the new grant's address is used`() {
        val refreshes = AtomicInteger()
        val live = client(
            refresh = { if (refreshes.incrementAndGet() == 1) null else grant("/api/assist/sessions/a1/live2") },
            policy = HttpPolicy(refreshEveryMs = 150, refreshRetryMs = 50)
        )
        live.start()
        waitFor("a refused refresh tried again, then a good one") { refreshes.get() >= 2 }
        live.send(ClientMessage.PracticeNext)
        assertEquals("/api/assist/sessions/a1/live2", sse.next().path)
        waitFor("still refreshing") { refreshes.get() >= 4 }
        live.stop()
        val after = refreshes.get()
        Thread.sleep(400)
        assertEquals("a stopped session is not refreshed", after, refreshes.get())
    }

    @Test
    fun `the 13 minute refresh and the four streams are the contract's`() {
        assertEquals(13 * 60_000L, HttpPolicy().refreshEveryMs)
        assertEquals(4, HttpPolicy().maxStreams)
    }

    @Test
    fun `refusals read as the card's problems (429, 503, 422), and an ended session or an old app (426) ends it`() {
        val live = client()
        live.start()
        sse.refuse(429, """{"code":"rate_limited","message":"Slow down."}""")
        live.send(ClientMessage.Command(SendKind.HelpNow))
        waitFor("the 429") { events.any { it is RelayEvent.Error } }
        val tooFast = events.filterIsInstance<RelayEvent.Error>().single()
        assertEquals("rate_limited", tooFast.code)
        assertTrue(tooFast.retry)
        assertFalse(tooFast.fatal)

        sse.refuse(503, """{"code":"relay_unavailable","message":"Live Assist can't connect right now. Try again in a few minutes."}""")
        live.send(ClientMessage.Command(SendKind.HelpNow))
        waitFor("the 503") { events.filterIsInstance<RelayEvent.Error>().size == 2 }
        val down = events.filterIsInstance<RelayEvent.Error>().last()
        assertEquals("relay_unavailable", down.code)
        assertEquals(AssistHub.RELAY_DOWN, down.message)
        assertTrue(down.retry)
        assertTrue("the session goes on", ended.isEmpty())

        sse.refuse(422, """{"code":"bad_message","message":"The message could not be read."}""")
        live.send(ClientMessage.Command(SendKind.HelpNow))
        waitFor("the 422") { events.filterIsInstance<RelayEvent.Error>().size == 3 }
        assertEquals("bad_message", events.filterIsInstance<RelayEvent.Error>().last().code)

        sse.refuse(426, """{"code":"app_update_required","message":"Live Assist needs the latest version of the app."}""")
        live.send(ClientMessage.Command(SendKind.HelpNow))
        assertEquals(RelayEnd.Refused, ended.poll(5, TimeUnit.SECONDS))
        val update = events.filterIsInstance<RelayEvent.Error>().last()
        assertEquals("app_update_required", update.code)
        assertEquals(AssistHub.UPDATE_APP, update.message)

        val other = client()
        other.start()
        sse.refuse(409, """{"code":"session_ended","message":"This session has ended."}""")
        other.send(ClientMessage.Command(SendKind.HelpNow))
        assertEquals(RelayEnd.Closed, ended.poll(5, TimeUnit.SECONDS))
    }

    @Test
    fun `a refusal body past 64 KiB is read from its start, and the session goes on`() {
        val live = client()
        live.start()
        // The status alone can't say it (a 409 is also an ended session): the code at the body's start does.
        sse.refuse(409, """{"code":"session_active","message":"${"x".repeat(200 * 1024)}"}""")
        live.send(ClientMessage.Command(SendKind.HelpNow))
        waitFor("the 409") { events.any { it is RelayEvent.Error } }
        assertEquals("session_active", events.filterIsInstance<RelayEvent.Error>().single().code)
        // Nothing waits on the rest of the body: the next message goes out.
        live.send(ClientMessage.Command(SendKind.HelpNow))
        sse.next()
        sse.next().end()
        assertTrue(ended.isEmpty())
    }

    @Test
    fun `another live session of this person reads as busy, and this one goes on`() {
        val live = client()
        live.start()
        sse.refuse(409, """{"code":"session_active","message":"A session is on.","session_id":"old-1"}""")
        live.send(ClientMessage.Command(SendKind.HelpNow))
        waitFor("the 409") { events.any { it is RelayEvent.Error } }
        val busy = events.filterIsInstance<RelayEvent.Error>().single()
        assertEquals("session_active", busy.code)
        assertEquals(
            com.echoledger.mobile.ui.v2.assist.AssistProblem.Busy,
            com.echoledger.mobile.ui.v2.assist.AssistCard.problemOf(busy.code, busy.retry, busy.fatal)
        )
        assertTrue("the session goes on", ended.isEmpty())
    }

    @Test
    fun `a live address on another host is never sent the device token`() {
        assertNull(api.assistLive("https://elsewhere.example/api/assist/sessions/a1/live", "{}"))
        assertNull(api.assistLive(sse.base.replace("http://", "https://") + "/api/assist/sessions/a1/live", "{}"))
        val live = client(grant = HttpGrant(grant().liveUrl.replace("127.0.0.1", "localhost"), grant().ready))
        live.start()
        live.send(ClientMessage.Command(SendKind.HelpNow))
        assertEquals(RelayEnd.NotTrusted, ended.poll(5, TimeUnit.SECONDS))
        assertTrue(sse.quiet())
    }

    @Test
    fun `a redirect is never followed, so the message never reaches another host`() {
        val elsewhere = SseStandIn()
        try {
            val live = client()
            live.start()
            sse.redirect(307, elsewhere.url("/api/assist/sessions/a1/live"))
            live.send(ClientMessage.TurnFinal(Turn("o-1", true, "The meeting's words", 0, 900)))
            sse.next()
            waitFor("the problem") { events.any { it is RelayEvent.Error } }
            assertEquals("connection_failed", events.filterIsInstance<RelayEvent.Error>().single().code)
            assertTrue("the body never arrives at the redirect's host", elsewhere.quiet(500))
            sse.redirect(308, elsewhere.url("/api/assist/sessions/a1/live"))
            live.send(ClientMessage.Command(SendKind.HelpNow))
            sse.next()
            assertTrue(elsewhere.quiet(500))
        } finally {
            elsewhere.close()
        }
    }

    @Test
    fun `a line longer than 64 KiB ends the stream as a drop`() {
        val live = client()
        live.start()
        live.send(ClientMessage.Command(SendKind.HelpNow))
        val post = sse.next()
        post.send(start("s-1"))
        waitFor("s-1") { cards().size == 1 }
        post.write("data: " + "x".repeat(70 * 1024) + "\n\n")
        waitFor("s-1 let go") { cards().lastOrNull() == AssistMessage.Lost(setOf("s-1")) }
    }

    @Test
    fun `event data past 256 KiB ends the stream as a drop`() {
        val live = client()
        live.start()
        live.send(ClientMessage.Command(SendKind.HelpNow))
        val post = sse.next()
        post.send(start("s-1"))
        waitFor("s-1") { cards().size == 1 }
        // Five lines of 60 KiB each fit the line limit, but not the event's.
        val chunk = "data: " + "y".repeat(60 * 1024) + "\n"
        repeat(5) { runCatching { post.write(chunk) } }
        waitFor("s-1 let go") { cards().lastOrNull() == AssistMessage.Lost(setOf("s-1")) }
        assertEquals(2, cards().size)
    }

    @Test
    fun `a failure before the stream begins says the server couldn't be reached`() {
        val nobody = okhttp3.Request.Builder().url("http://127.0.0.1:1/").post("{}".toRequestBody()).build()
        val live = client(call = { _, _ -> okhttp3.OkHttpClient().newCall(nobody) })
        live.start()
        live.send(ClientMessage.Command(SendKind.HelpNow))
        waitFor("the problem") { events.any { it is RelayEvent.Error } }
        val problem = events.filterIsInstance<RelayEvent.Error>().single()
        assertEquals("connection_failed", problem.code)
        assertTrue(problem.retry)
        assertTrue("the session goes on", ended.isEmpty())
    }
}
