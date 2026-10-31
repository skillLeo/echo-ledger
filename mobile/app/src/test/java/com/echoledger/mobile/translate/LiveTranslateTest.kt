package com.echoledger.mobile.translate

import com.echoledger.mobile.data.LiveTranslateToken
import com.echoledger.mobile.data.LiveTranslation
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import okhttp3.OkHttpClient
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import okio.ByteString
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Live Translate (Phase B) against a stand-in for Soniox's real-time WebSocket: the protocol and the session. */
class LiveTranslateTest {
    private lateinit var server: MockWebServer
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val received = CopyOnWriteArrayList<Any>()
    private var soniox: WebSocket? = null
    private val opened = CountDownLatch(1)

    @Before
    fun start() {
        server = MockWebServer()
        server.start()
    }

    @After
    fun finish() {
        scope.cancel()
        runCatching { soniox?.close(1000, null) }
        server.close()
    }

    private fun token(expiresAt: String = "2099-01-01T00:00:00Z") = LiveTranslateToken(
        provider = "soniox",
        apiKey = "temp:abc",
        expiresAt = expiresAt,
        websocketUrl = server.url("/transcribe-websocket").toString().replaceFirst("http", "ws"),
        model = "stt-rt-v5",
        translation = LiveTranslation("one_way", "en"),
        languageHints = listOf("ar")
    )

    private fun upgrade() {
        server.enqueue(
            MockResponse.Builder().webSocketUpgrade(object : WebSocketListener() {
                override fun onOpen(webSocket: WebSocket, response: Response) {
                    soniox = webSocket
                    opened.countDown()
                }

                override fun onMessage(webSocket: WebSocket, text: String) {
                    received += text
                }

                override fun onMessage(webSocket: WebSocket, bytes: ByteString) {
                    received += bytes.toByteArray()
                }
            }).build()
        )
    }

    private fun waitFor(what: String, condition: () -> Boolean) {
        val deadline = System.currentTimeMillis() + 10_000
        while (!condition()) {
            if (System.currentTimeMillis() > deadline) throw AssertionError("timed out waiting for $what")
            Thread.sleep(10)
        }
    }

    @Test
    fun `the first frame is the configuration with the key, the model, the hints and the translation`() {
        val json = Json.parseToJsonElement(SonioxProtocol.config(token(), SpeedPreset.FASTEST)).jsonObject

        assertEquals("temp:abc", json["api_key"]!!.jsonPrimitive.content)
        assertEquals("stt-rt-v5", json["model"]!!.jsonPrimitive.content)
        assertEquals("pcm_s16le", json["audio_format"]!!.jsonPrimitive.content)
        assertEquals(16_000, json["sample_rate"]!!.jsonPrimitive.int)
        assertEquals(500, json["max_endpoint_delay_ms"]!!.jsonPrimitive.int)
        assertEquals("en", json["translation"]!!.jsonObject["target_language"]!!.jsonPrimitive.content)
        assertEquals("ar", (json["language_hints"] as kotlinx.serialization.json.JsonArray).single().jsonPrimitive.content)
    }

    @Test
    fun `a reply separates what was said from its translation, final from still heard, and the end of a sentence`() {
        val reply = SonioxProtocol.parse(
            """{"tokens":[
              {"text":"مرحبا","is_final":true,"translation_status":"original","language":"ar"},
              {"text":"Hello","is_final":true,"translation_status":"translation","language":"en"},
              {"text":" يا","is_final":false,"translation_status":"original","language":"ar"},
              {"text":"<end>","is_final":true}
            ]}"""
        )

        assertEquals("مرحبا", reply.finalOriginal)
        assertEquals("Hello", reply.finalTranslation)
        assertEquals(" يا", reply.partialOriginal)
        assertEquals("ar", reply.language)
        assertTrue(reply.endpoint)
        assertEquals(400, SonioxProtocol.parse("""{"error_code":400,"error_message":"bad"}""").errorCode)
        assertEquals(-1, SonioxProtocol.parse("not json").errorCode)
    }

    private fun speech(frames: Int = 2) = List(frames) { ShortArray(1_600) { (if (it % 8 < 4) 3_000 else -3_000).toShort() } }

    @Test
    fun `the stream opens only when speech arrives, sends the configuration first, then the waiting audio`() {
        upgrade()
        val session = LiveTranslateSession(OkHttpClient(), scope, SpeedPreset.BALANCED, fetchToken = { token() })
        Thread.sleep(200)
        assertEquals("nothing is opened before someone speaks", 0, server.requestCount)

        session.send(speech(2))
        assertTrue(opened.await(10, TimeUnit.SECONDS))
        waitFor("the configuration and the audio") { received.size >= 3 }

        assertTrue("the configuration comes first", (received[0] as String).contains("\"api_key\":\"temp:abc\""))
        assertEquals(3_200, (received[1] as ByteArray).size)
        assertEquals(3_200, (received[2] as ByteArray).size)
        session.stop()
    }

    @Test
    fun `replies build the translation as it is heard, settled words solid and changing ones apart`() {
        upgrade()
        val sentences = CopyOnWriteArrayList<TranslatedSentence>()
        val session = LiveTranslateSession(OkHttpClient(), scope, SpeedPreset.FASTEST, fetchToken = { token() }, onSentence = {
            sentences +=
                it
        })
        session.send(speech())
        assertTrue(opened.await(10, TimeUnit.SECONDS))
        waitFor("the stream is live") { session.feed.value.status == TranslateFeed.Status.LIVE }

        soniox!!.send(
            """{"tokens":[{"text":"مرحبا","is_final":true,"translation_status":"original","language":"ar"},{"text":"Hel","is_final":false,"translation_status":"translation"}]}"""
        )
        waitFor("the changing words") { session.feed.value.partial == "Hel" }
        assertEquals("مرحبا", session.feed.value.original)
        soniox!!.send(
            """{"tokens":[{"text":"<end>","is_final":true},{"text":"Hello.","is_final":true,"translation_status":"translation"}]}"""
        )
        waitFor("the settled words") { session.feed.value.text == "Hello." }

        assertEquals("", session.feed.value.partial)
        assertEquals("Hello.", session.feed.value.shown)
        waitFor("the finished sentence") { sentences.isNotEmpty() }
        assertEquals("Hello.", sentences.single().text)
        session.stop()
        assertEquals(TranslateFeed.Status.STOPPED, session.feed.value.status)
    }

    @Test
    fun `rest closes the stream and reports how long it was open`() {
        upgrade()
        var openMs = -1L
        var clock = 1_000L
        val session =
            LiveTranslateSession(OkHttpClient(), scope, SpeedPreset.BALANCED, fetchToken = { token() }, elapsed = { clock }, onOpenMs = {
                openMs =
                    it
            })
        session.send(speech())
        assertTrue(opened.await(10, TimeUnit.SECONDS))
        waitFor("the stream is live") { session.feed.value.status == TranslateFeed.Status.LIVE }
        clock += 42_000

        session.rest()

        assertEquals(42_000L, openMs)
        assertEquals(TranslateFeed.Status.QUIET, session.feed.value.status)
        waitFor("the end of audio") { received.any { it is String && it.isEmpty() } }
    }

    @Test
    fun `without a key it says No connection once and keeps retrying quietly`() {
        var asked = 0
        val session = LiveTranslateSession(OkHttpClient(), scope, SpeedPreset.BALANCED, fetchToken = {
            asked++
            error("offline")
        }, backoffMs = { 20 })

        session.send(speech())
        waitFor("a few retries") { asked >= 3 }

        assertEquals(LiveTranslateSession.NO_CONNECTION, session.feed.value.notice)
        assertEquals(TranslateFeed.Status.RECONNECTING, session.feed.value.status)
        assertTrue(session.feed.value.reconnects >= 3)
        session.stop()
    }

    @Test
    fun `a refused key ends Live Translate with the server's own words instead of retrying`() {
        var asked = 0
        val session = LiveTranslateSession(OkHttpClient(), scope, SpeedPreset.BALANCED, fetchToken = {
            asked++
            throw TokenUnavailable("Soniox has no credit left. Add credit to the Soniox account, then try again.", permanent = true)
        }, backoffMs = { 20 })

        session.send(speech())
        waitFor("the end") { session.feed.value.status == TranslateFeed.Status.STOPPED }
        Thread.sleep(200)
        session.send(speech())
        Thread.sleep(200)

        assertTrue(session.feed.value.notice!!.startsWith("Soniox has no credit left"))
        assertEquals("asked once, never again", 1, asked)
    }

    @Test
    fun `one key opens the stream, and keepalive and rotation stay inside Soniox limits`() {
        upgrade()
        var asked = 0
        val session = LiveTranslateSession(OkHttpClient(), scope, SpeedPreset.BALANCED, fetchToken = {
            asked++
            token()
        })
        session.send(speech())
        assertTrue(opened.await(10, TimeUnit.SECONDS))
        assertEquals(1, asked)
        assertEquals("""{"type":"keepalive"}""", SonioxProtocol.KEEPALIVE)
        assertTrue(LiveTranslateSession.KEEPALIVE_EVERY_MS < 20_000)
        assertTrue(LiveTranslateSession.ROTATE_AFTER_MS < 300L * 60_000L)
        session.stop()
    }
}
