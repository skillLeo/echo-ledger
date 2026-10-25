package com.echoledger.mobile.net

import com.echoledger.mobile.data.AudioSpec
import com.echoledger.mobile.data.Grant
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import kotlinx.serialization.json.Json
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import okio.ByteString
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** One provider streaming session over a real WebSocket: message order, framing, buffering and shutdown. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class SttStreamerTest {
    private lateinit var server: MockWebServer
    private val received = CopyOnWriteArrayList<Any>() // String for text frames, ByteArray for binary frames
    private val events = CopyOnWriteArrayList<SttEvent>()
    private var serverSocket: WebSocket? = null
    private val connected = CountDownLatch(1)
    private val closedByServer = CountDownLatch(1)

    @Before
    fun startServer() {
        server = MockWebServer()
        server.start()
    }

    @After
    fun stopServer() {
        runCatching { serverSocket?.close(1000, null) }
        server.close()
    }

    private fun listener(onOpenMessage: String? = null) = object : WebSocketListener() {
        override fun onOpen(webSocket: WebSocket, response: Response) {
            serverSocket = webSocket
            onOpenMessage?.let { webSocket.send(it) }
            connected.countDown()
        }

        override fun onMessage(webSocket: WebSocket, text: String) {
            received += text
        }

        override fun onMessage(webSocket: WebSocket, bytes: ByteString) {
            received += bytes.toByteArray()
        }

        override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
            webSocket.close(code, null)
            closedByServer.countDown()
        }
    }

    private fun upgrade(onOpenMessage: String?) {
        server.enqueue(MockResponse.Builder().webSocketUpgrade(listener(onOpenMessage)).build())
    }

    private fun grant(
        protocol: String = "assemblyai_v3",
        frameMs: Int? = 50,
        initMessages: List<String> = emptyList(),
        url: String? = null
    ) = Grant(
        channel = "me",
        transport = "websocket",
        protocol = protocol,
        wsUrl = url ?: server.url("/stream").toString().replaceFirst("http", "ws"),
        audio = frameMs?.let { AudioSpec("pcm_s16le", 16_000, 1, it) },
        initMessages = initMessages.map { Json.parseToJsonElement(it) }
    )

    private fun streamer(grant: Grant) = SttStreamer(grant, { events += it })

    private fun awaitFrames(count: Int, timeoutMs: Long = 5_000) {
        val deadline = System.nanoTime() + timeoutMs * 1_000_000
        while (received.size < count && System.nanoTime() < deadline) Thread.sleep(10)
        assertTrue("expected $count frames but saw ${received.size}", received.size >= count)
    }

    private fun awaitEvent(predicate: (SttEvent) -> Boolean) {
        val deadline = System.nanoTime() + 5_000_000_000L
        while (events.none(predicate) && System.nanoTime() < deadline) Thread.sleep(10)
        assertTrue("event not seen: $events", events.any(predicate))
    }

    private val begin = """{"type":"Begin","id":"s1"}"""

    @Test
    fun `init messages go first after the session begins and audio follows in frames of the advertised size`() {
        upgrade(begin)
        val sut = streamer(grant(initMessages = listOf("""{"type":"UpdateConfiguration","keyterms_prompt":["Acme"]}""")))

        sut.start()
        awaitEvent { it == SttEvent.Started }
        sut.sendPcm(ByteArray(1_600 * 3 + 500) { 1 })
        awaitFrames(1 + 3)

        assertEquals("""{"type":"UpdateConfiguration","keyterms_prompt":["Acme"]}""", received[0])
        assertEquals(listOf(1_600, 1_600, 1_600), received.drop(1).take(3).map { (it as ByteArray).size })
        Thread.sleep(150)
        assertEquals("the incomplete tail stays buffered", 4, received.size)

        sut.sendPcm(ByteArray(1_100) { 2 })
        awaitFrames(5)
        assertEquals(1_600, (received[4] as ByteArray).size)
        assertTrue(
            "buffered bytes keep their order",
            (received[4] as ByteArray).take(500).all { it == 1.toByte() } && (received[4] as ByteArray).drop(500).all { it == 2.toByte() }
        )
    }

    @Test
    fun `audio queued before the session begins is delivered after the init messages`() {
        upgrade(null)
        val sut = streamer(grant(initMessages = listOf("""{"type":"UpdateConfiguration"}""")))
        sut.start()
        assertTrue(connected.await(5, TimeUnit.SECONDS))
        sut.sendPcm(ByteArray(3_200) { 9 })
        Thread.sleep(200)
        assertEquals("nothing may be sent before the provider says Begin", 0, received.size)

        serverSocket!!.send(begin)
        awaitFrames(3)

        assertTrue(received[0] is String)
        assertEquals(1_600, (received[1] as ByteArray).size)
        assertEquals(1_600, (received[2] as ByteArray).size)
    }

    @Test
    fun `audio queued before the session begins is capped at about ten seconds`() {
        upgrade(null)
        val sut = streamer(grant())
        sut.start()
        assertTrue(connected.await(5, TimeUnit.SECONDS))
        repeat(300) { sut.sendPcm(ByteArray(1_600)) } // 15 s of audio

        serverSocket!!.send(begin)
        awaitFrames(200)
        Thread.sleep(300)

        assertEquals("only the most recent 200 frames are kept", 200, received.size)
    }

    @Test
    fun `the frame size follows frame_ms within the supported range`() {
        mapOf(null to 1_600, 20 to 1_600, 50 to 1_600, 100 to 3_200, 250 to 8_000, 500 to 8_000).forEach { (frameMs, expected) ->
            received.clear()
            events.clear()
            upgrade(begin)
            val sut = streamer(grant(frameMs = frameMs))
            sut.start()
            awaitEvent { it == SttEvent.Started }
            sut.sendPcm(ByteArray(expected))
            awaitFrames(1)
            assertEquals("frame_ms=$frameMs", expected, (received[0] as ByteArray).size)
            sut.stop()
            val deadline = System.nanoTime() + 5_000_000_000L
            while (received.none { it == """{"type":"Terminate"}""" } && System.nanoTime() < deadline) Thread.sleep(10)
            serverSocket?.close(1000, null)
        }
    }

    @Test
    fun `provider events reach the callback and a termination closes the socket`() {
        upgrade(begin)
        val sut = streamer(grant())
        sut.start()
        awaitEvent { it == SttEvent.Started }

        serverSocket!!.send("""{"type":"Turn","transcript":"hello wor","end_of_turn":false}""")
        serverSocket!!.send(
            """{"type":"Turn","transcript":"Hello world.","end_of_turn":true,"turn_is_formatted":true,"words":[{"start":10,"end":900}]}"""
        )
        serverSocket!!.send("""{"type":"Termination"}""")

        awaitEvent { it == SttEvent.Terminated }
        assertTrue(events.contains(SttEvent.Partial("hello wor")))
        assertTrue(events.contains(SttEvent.Final("Hello world.", 10, 900)))
        assertTrue("the client answers a termination by closing", closedByServer.await(5, TimeUnit.SECONDS))
    }

    @Test
    fun `stopping asks the provider to finish with a Terminate message`() {
        upgrade(begin)
        val sut = streamer(grant())
        sut.start()
        awaitEvent { it == SttEvent.Started }

        sut.stop()

        awaitFrames(1)
        assertEquals("""{"type":"Terminate"}""", received[0])
    }

    @Test
    fun `audio sent after stopping is dropped`() {
        upgrade(begin)
        val sut = streamer(grant())
        sut.start()
        awaitEvent { it == SttEvent.Started }
        sut.stop()
        awaitFrames(1)

        sut.sendPcm(ByteArray(1_600))
        Thread.sleep(200)

        assertEquals(1, received.size)
    }

    @Test
    fun `stopping twice sends one terminate`() {
        upgrade(begin)
        val sut = streamer(grant())
        sut.start()
        awaitEvent { it == SttEvent.Started }

        sut.stop()
        sut.stop()
        Thread.sleep(300)

        assertEquals(1, received.count { it == """{"type":"Terminate"}""" })
    }

    @Test
    fun `a dropped connection is reported as an error event`() {
        upgrade(begin)
        val sut = streamer(grant())
        sut.start()
        awaitEvent { it == SttEvent.Started }

        server.close()

        awaitEvent { it is SttEvent.Error }
    }

    @Test
    fun `a server that refuses the upgrade is reported as an error event`() {
        server.enqueue(MockResponse.Builder().code(401).body("""{"message":"token expired"}""").build())

        streamer(grant()).start()

        awaitEvent { it is SttEvent.Error }
    }

    @Test
    fun `an error after stopping is not reported`() {
        upgrade(begin)
        val sut = streamer(grant())
        sut.start()
        awaitEvent { it == SttEvent.Started }

        sut.stop()
        server.close()
        Thread.sleep(300)

        assertTrue(events.none { it is SttEvent.Error })
    }

    @Test
    fun `a grant without a socket address cannot start`() {
        val grant = Grant(channel = "me", transport = "websocket", protocol = "assemblyai_v3", wsUrl = null)

        assertThrows(IllegalStateException::class.java) { SttStreamer(grant, {}).start() }
    }

    @Test
    fun `ElevenLabs audio is sent as base64 JSON chunks and stopping commits`() {
        upgrade("""{"message_type":"session_started"}""")
        val sut = streamer(grant(protocol = "elevenlabs_realtime", frameMs = 100))
        sut.start()
        awaitEvent { it == SttEvent.Started }

        val pcm = ByteArray(3_200) { (it % 7).toByte() }
        sut.sendPcm(pcm)
        awaitFrames(1)
        val chunk = Json.parseToJsonElement(received[0] as String).toString()
        assertTrue(chunk, chunk.contains("\"message_type\":\"input_audio_chunk\""))
        assertTrue(chunk, chunk.contains("\"commit\":false"))
        assertTrue(chunk, chunk.contains("\"sample_rate\":16000"))
        val decoded = java.util.Base64.getDecoder().decode(Regex("\"audio_base_64\":\"([^\"]*)\"").find(chunk)!!.groupValues[1])
        assertTrue(pcm.contentEquals(decoded))

        sut.stop()
        awaitFrames(2)
        assertTrue(received[1] as String, (received[1] as String).contains("\"commit\":true"))
    }
}
