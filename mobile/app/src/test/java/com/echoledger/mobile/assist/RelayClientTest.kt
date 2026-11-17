package com.echoledger.mobile.assist

import com.echoledger.mobile.ui.v2.assist.SendKind
import java.time.Instant
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.TimeUnit
import javax.net.SocketFactory
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import okhttp3.CertificatePinner
import okhttp3.OkHttpClient
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import okhttp3.tls.HandshakeCertificates
import okhttp3.tls.HeldCertificate
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** The relay connection against a real WebSocket server: ready, seq, the queue, reconnects, close codes, refresh, pins. */
class RelayClientTest {
    private lateinit var server: MockWebServer
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val sockets = CopyOnWriteArrayList<WebSocket>()
    private val frames = LinkedBlockingQueue<Pair<Int, JsonObject>>()
    private val ended = LinkedBlockingQueue<RelayEnd>()
    private val events = CopyOnWriteArrayList<RelayEvent>()
    private val fast = RelayPolicy(backoffMs = listOf(20, 40), readyTimeoutMs = 3_000, resumeGraceMs = 5_000)

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()
    }

    @After
    fun tearDown() {
        scope.cancel()
        sockets.forEach { runCatching { it.cancel() } }
        server.close()
    }

    /** The relay side of one connection: [ready] (when given) goes as soon as it opens. */
    private fun accept(ready: String? = null) {
        server.enqueue(
            MockResponse.Builder().webSocketUpgrade(
                object : WebSocketListener() {
                    override fun onOpen(webSocket: WebSocket, response: Response) {
                        sockets += webSocket
                        ready?.let(webSocket::send)
                    }

                    override fun onMessage(webSocket: WebSocket, text: String) {
                        frames += sockets.indexOf(webSocket) to Json.parseToJsonElement(text).jsonObject
                    }

                    override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
                        webSocket.close(code, null)
                    }
                }
            ).build()
        )
    }

    private fun ready(resumed: Boolean = false, expiresAt: String? = null) = buildJsonObject {
        put("type", "ready")
        put("seq", 1)
        put("session_id", "s1")
        put("mode", "balanced")
        put("resumed", resumed)
        expiresAt?.let { put("expires_at", it) }
        put("limits", buildJsonObject { put("messages_per_second", 200) })
    }.toString()

    private val url get() = server.url("/v1/assist").toString().replaceFirst("http://", "ws://")

    private fun client(refresh: suspend () -> RelayGrant? = { null }, token: String = "t1", policy: RelayPolicy = fast) = RelayClient(
        grant = RelayGrant(url, token, null, emptyList()),
        refresh = refresh,
        scope = scope,
        policy = policy,
        elapsed = { System.nanoTime() / 1_000_000 },
        onEvent = { events += it },
        onEnded = { ended += it }
    )

    private fun next(): Pair<Int, JsonObject> = frames.poll(5, TimeUnit.SECONDS) ?: error("no frame from the phone")

    private fun JsonObject.s(key: String) = this[key]?.jsonPrimitive?.content

    private fun waitFor(what: String, check: () -> Boolean) {
        val until = System.currentTimeMillis() + 5_000
        while (!check()) {
            check(System.currentTimeMillis() < until) { "timed out waiting: $what" }
            Thread.sleep(10)
        }
    }

    @Test
    fun `nothing leaves before ready, then the queue goes in order with seq from 1 and the bearer token`() {
        accept()
        val relay = client()
        relay.send(ClientMessage.ContextUpdate(buildJsonObject { put("brief", "Sales call") }))
        relay.send(ClientMessage.TurnFinal(Turn("o-1", true, "Hello?", 0, 900)))
        relay.start()
        waitFor("connection") { sockets.size == 1 }
        assertEquals("Bearer t1", server.takeRequest().headers["Authorization"])
        assertNull(frames.poll(300, TimeUnit.MILLISECONDS))

        sockets[0].send(ready())
        val (c1, context) = next()
        val (_, turn) = next()
        assertEquals(0, c1)
        assertEquals("context_update", context.s("type"))
        assertEquals(1, context["seq"]!!.jsonPrimitive.int)
        assertEquals("Sales call", context.s("brief"))
        assertEquals("turn_final", turn.s("type"))
        assertEquals(2, turn["seq"]!!.jsonPrimitive.int)
        assertTrue(events.first() is RelayEvent.Ready)

        relay.send(ClientMessage.Command(SendKind.HelpNow))
        assertEquals(3, next().second["seq"]!!.jsonPrimitive.int)
    }

    @Test
    fun `a connection the relay closed comes back, seq starts again, and a relay that lost the session gets the whole context first`() {
        accept(ready())
        accept(ready(resumed = false))
        val relay = client()
        relay.start()
        waitFor("ready") { events.any { it is RelayEvent.Ready } }
        relay.send(ClientMessage.ContextUpdate(buildJsonObject { put("brief", "Sales call") }))
        relay.send(ClientMessage.ContextUpdate(buildJsonObject { put("documents", "Price list") }))
        assertEquals(1, next().second["seq"]!!.jsonPrimitive.int)
        assertEquals(2, next().second["seq"]!!.jsonPrimitive.int)

        // The relay restarting (1012); a connection lost without a close frame takes the same way (see the grace test).
        sockets[0].close(1012, "restarting")
        val (connection, context) = next()
        assertEquals(1, connection)
        assertEquals(1, context["seq"]!!.jsonPrimitive.int)
        assertEquals("Sales call", context.s("brief"))
        assertEquals("Price list", context.s("documents"))
        relay.send(ClientMessage.Command(SendKind.HelpNow))
        assertEquals(2, next().second["seq"]!!.jsonPrimitive.int)
        assertTrue(ended.isEmpty())
    }

    @Test
    fun `while down a newer context waits whole, never losing a field`() {
        accept()
        val relay = client()
        relay.start()
        relay.send(ClientMessage.ContextUpdate(buildJsonObject { put("brief", "Sales call") }))
        relay.send(ClientMessage.ContextUpdate(buildJsonObject { put("documents", "Price list") }))
        waitFor("connection") { sockets.size == 1 }
        sockets[0].send(ready(resumed = true))
        val (_, context) = next()
        assertEquals("Sales call", context.s("brief"))
        assertEquals("Price list", context.s("documents"))
        assertNull(frames.poll(200, TimeUnit.MILLISECONDS))
    }

    @Test
    fun `replaced by a newer connection (4409) it ends and never reconnects`() {
        accept(ready())
        accept(ready())
        val relay = client()
        relay.start()
        waitFor("ready") { events.any { it is RelayEvent.Ready } }
        sockets[0].close(4409, "replaced")
        assertEquals(RelayEnd.Replaced, ended.poll(5, TimeUnit.SECONDS))
        Thread.sleep(300)
        assertEquals(1, server.requestCount)
        relay.stop()
        assertNull(ended.poll(100, TimeUnit.MILLISECONDS))
    }

    @Test
    fun `an expired token (4401) is refreshed, then it reconnects with the new one`() {
        accept(ready())
        accept(ready(resumed = true))
        val relay = client(refresh = { RelayGrant(url, "t2", null, emptyList()) })
        relay.start()
        waitFor("ready") { events.any { it is RelayEvent.Ready } }
        assertEquals("Bearer t1", server.takeRequest().headers["Authorization"])
        sockets[0].close(4401, "token_expired")
        waitFor("second connection") { sockets.size == 2 }
        assertEquals("Bearer t2", server.takeRequest(5, TimeUnit.SECONDS)!!.headers["Authorization"])
        assertTrue(ended.isEmpty())
    }

    @Test
    fun `the token is refreshed two minutes before it expires, over the open connection`() {
        val expires = Instant.ofEpochMilli(System.currentTimeMillis() + 120_000 + 300).toString()
        accept(ready(expiresAt = expires))
        var asked = 0
        val relay = client(refresh = {
            asked++
            RelayGrant(url, "t2", null, emptyList())
        })
        relay.start()
        val (_, refresh) = next()
        assertEquals("token_refresh", refresh.s("type"))
        assertEquals("t2", refresh.s("token"))
        assertEquals(1, asked)
    }

    @Test
    fun `the phone's end closes normally and ends once`() {
        accept(ready())
        val relay = client()
        relay.start()
        waitFor("ready") { events.any { it is RelayEvent.Ready } }
        relay.stop()
        relay.stop()
        assertEquals(RelayEnd.Closed, ended.poll(1, TimeUnit.SECONDS))
        assertNull(ended.poll(300, TimeUnit.MILLISECONDS))
    }

    @Test
    fun `no relay for the whole resume grace ends the session`() {
        val port = server.port
        server.close()
        val relay = RelayClient(
            grant = RelayGrant("ws://127.0.0.1:$port/v1/assist", "t1", null, emptyList()),
            refresh = { null },
            scope = scope,
            policy = RelayPolicy(backoffMs = listOf(20), readyTimeoutMs = 500, resumeGraceMs = 300),
            elapsed = { System.nanoTime() / 1_000_000 },
            onEvent = {},
            onEnded = { ended += it }
        )
        relay.start()
        assertEquals(RelayEnd.Lost, ended.poll(5, TimeUnit.SECONDS))
    }

    @Test
    fun `a wss relay without pins is never contacted`() {
        val relay = RelayClient(
            grant = RelayGrant("wss://localhost:${server.port}/v1/assist", "t1", null, emptyList()),
            refresh = { null },
            scope = scope,
            elapsed = { 0 },
            onEvent = {},
            onEnded = { ended += it }
        )
        relay.start()
        assertEquals(RelayEnd.NotTrusted, ended.poll(5, TimeUnit.SECONDS))
        assertEquals(0, server.requestCount)
    }

    @Test
    fun `refused before the upgrade with 403, the session is someone else's and it never tries again`() {
        server.enqueue(MockResponse.Builder().code(403).body("""{"code":"wrong_session","message":"No."}""").build())
        accept(ready())
        client().start()
        assertEquals(RelayEnd.WrongSession, ended.poll(5, TimeUnit.SECONDS))
        Thread.sleep(300)
        assertEquals(1, server.requestCount)
    }

    @Test
    fun `refused before the upgrade with 400, it ends and never tries again`() {
        server.enqueue(MockResponse.Builder().code(400).body("""{"code":"bad_request","message":"No."}""").build())
        accept(ready())
        client().start()
        assertEquals(RelayEnd.Refused, ended.poll(5, TimeUnit.SECONDS))
        Thread.sleep(300)
        assertEquals(1, server.requestCount)
    }

    @Test
    fun `a 401 before the upgrade is a token to refresh, then it connects`() {
        server.enqueue(MockResponse.Builder().code(401).build())
        accept(ready())
        var asked = 0
        client(refresh = {
            asked++
            RelayGrant(url, "t2", null, emptyList())
        }).start()
        waitFor("ready after the refresh") { events.any { it is RelayEvent.Ready } }
        assertEquals(1, asked)
        assertTrue(ended.isEmpty())
    }

    @Test
    fun `plain ws only in a debug build`() {
        assertNull(RelayClient.clientFor(RelayGrant("ws://127.0.0.1:9/v1/assist", "t", null, emptyList()), allowLocal = false))
        assertNull(RelayClient.clientFor(RelayGrant("ws://10.0.2.2:9/v1/assist", "t", null, emptyList()), allowLocal = false))
        assertNotNull(RelayClient.clientFor(RelayGrant("ws://10.0.2.2:9/v1/assist", "t", null, emptyList()), allowLocal = true))
    }

    @Test
    fun `plain ws only to this phone itself`() {
        assertNull(RelayClient.clientFor(RelayGrant("ws://relay.example/v1/assist", "t", null, emptyList())))
        assertNotNull(RelayClient.clientFor(RelayGrant("ws://127.0.0.1:9/v1/assist", "t", null, emptyList())))
        assertNull(RelayClient.clientFor(RelayGrant("wss://relay.example/v1/assist", "t", null, listOf("not a pin"))))
    }

    @Test
    fun `a refused key is seen even when it comes as a later address's suppressed failure`() {
        val refused = java.net.ConnectException("Connection refused").apply {
            addSuppressed(javax.net.ssl.SSLPeerUnverifiedException("pin"))
        }
        assertTrue(RelayClient.pinsRefused(refused))
        assertTrue(RelayClient.pinsRefused(java.io.IOException(javax.net.ssl.SSLPeerUnverifiedException("pin"))))
        assertFalse(RelayClient.pinsRefused(java.net.ConnectException("Connection refused")))
    }

    @Test
    fun `sockets are made with TCP_NODELAY`() {
        NoDelaySocketFactory(SocketFactory.getDefault()).createSocket().use { assertTrue(it.tcpNoDelay) }
        val client = RelayClient.clientFor(RelayGrant("ws://localhost:9/v1/assist", "t", null, emptyList()))!!
        assertTrue(client.socketFactory is NoDelaySocketFactory)
    }

    @Test
    fun `over wss the relay's key must be one of relay_pins`() {
        val held = HeldCertificate.Builder().commonName("localhost").addSubjectAlternativeName("localhost").build()
        val tls = MockWebServer()
        tls.useHttps(HandshakeCertificates.Builder().heldCertificate(held).build().sslSocketFactory())
        tls.start()
        try {
            val trust = HandshakeCertificates.Builder().addTrustedCertificate(held.certificate).build()
            val base = OkHttpClient.Builder().sslSocketFactory(trust.sslSocketFactory(), trust.trustManager).build()
            val wss = tls.url("/v1/assist").toString().replaceFirst("https://", "wss://")
            val otherPin = "sha256/" + "A".repeat(43) + "="

            val refused = RelayClient(
                RelayGrant(wss, "t1", null, listOf(otherPin)),
                refresh = { null },
                scope = scope,
                policy = fast,
                elapsed = { 0 },
                onEvent = {},
                onEnded = { ended += it },
                http = base
            )
            refused.start()
            assertEquals(RelayEnd.NotTrusted, ended.poll(5, TimeUnit.SECONDS))

            tls.enqueue(
                MockResponse.Builder().webSocketUpgrade(
                    object : WebSocketListener() {
                        override fun onOpen(webSocket: WebSocket, response: Response) {
                            sockets += webSocket
                            webSocket.send(ready())
                        }

                        override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
                            webSocket.close(code, null)
                        }
                    }
                ).build()
            )
            val pinned = RelayClient(
                RelayGrant(wss, "t1", null, listOf(otherPin, CertificatePinner.pin(held.certificate))),
                refresh = { null },
                scope = scope,
                policy = fast,
                elapsed = { 0 },
                onEvent = { events += it },
                onEnded = { ended += it },
                http = base
            )
            pinned.start()
            waitFor("ready over wss") { events.any { it is RelayEvent.Ready } }
            pinned.stop()
            assertEquals(RelayEnd.Closed, ended.poll(5, TimeUnit.SECONDS))
            Thread.sleep(200)
        } finally {
            tls.close()
        }
    }
}
