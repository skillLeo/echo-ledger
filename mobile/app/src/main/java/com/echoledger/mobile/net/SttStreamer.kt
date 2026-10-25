package com.echoledger.mobile.net

import com.echoledger.mobile.data.Grant
import java.io.ByteArrayOutputStream
import java.util.concurrent.TimeUnit
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import okio.ByteString.Companion.toByteString

/**
 * One provider streaming session for one mono channel (16 kHz s16le). Connects with the server-minted token URL,
 * sends the grant's init messages after the session-start message, then audio frames of the grant's frame size.
 * The provider host is NOT pinned (third-party); only the EchoLedger server is.
 */
class SttStreamer(
    private val grant: Grant,
    private val onEvent: (SttEvent) -> Unit,
    client: OkHttpClient = OkHttpClient.Builder().pingInterval(20, TimeUnit.SECONDS).build()
) {
    private val http = client
    private val elevenLabs = grant.protocol == "elevenlabs_realtime"
    private val frameBytes = 16_000 * 2 * (grant.audio?.frameMs ?: 50).coerceIn(50, 250) / 1000
    private val pending = ByteArrayOutputStream()
    private var socket: WebSocket? = null

    @Volatile private var started = false

    @Volatile private var closed = false

    @Volatile private var openedAtMs: Long? = null

    @Volatile private var closedAtMs: Long? = null

    @Volatile private var providerSeconds: Long? = null

    /**
     * How long the socket was open (the provider bills streaming by open time, idle included), or the provider's own
     * billed seconds when AssemblyAI reported them at the end; 0 when the socket never opened.
     */
    fun streamedMs(nowMs: Long = System.currentTimeMillis()): Long {
        providerSeconds?.let { return it * 1000 }
        val opened = openedAtMs ?: return 0
        return ((closedAtMs ?: nowMs) - opened).coerceAtLeast(0)
    }

    fun opened(): Boolean = openedAtMs != null

    fun start() {
        val url = grant.wsUrl ?: error("grant has no ws_url")
        socket = http.newWebSocket(Request.Builder().url(url).header("User-Agent", "EchoLedger-Android").build(), listener)
    }

    private val listener = object : WebSocketListener() {
        override fun onOpen(webSocket: WebSocket, response: Response) {
            openedAtMs = System.currentTimeMillis()
        }

        override fun onMessage(webSocket: WebSocket, text: String) {
            if (!elevenLabs) SttProtocol.assemblyAiSessionSeconds(text)?.let { providerSeconds = it }
            val event = if (elevenLabs) SttProtocol.parseElevenLabs(text) else SttProtocol.parseAssemblyAi(text)
            if (event == SttEvent.Started && !started) {
                grant.initMessages.forEach { webSocket.send(it.toString()) }
                started = true
                flush(webSocket)
            }
            event?.let(onEvent)
            if (event == SttEvent.Terminated) webSocket.close(1000, null)
        }

        override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
            if (openedAtMs != null && closedAtMs == null) closedAtMs = System.currentTimeMillis()
            if (!closed) onEvent(SttEvent.Error(t.message ?: "connection failed"))
        }

        override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
            webSocket.close(1000, null)
        }

        override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
            if (openedAtMs != null && closedAtMs == null) closedAtMs = System.currentTimeMillis()
        }
    }

    /** Queue PCM; frames are sent once the provider has started the session. */
    @Synchronized
    fun sendPcm(bytes: ByteArray) {
        if (closed) return
        pending.write(bytes)
        if (pending.size() > frameBytes * 200) { // cap buffered audio before start (~10 s)
            val all = pending.toByteArray()
            pending.reset()
            pending.write(all, all.size - frameBytes * 200, frameBytes * 200)
        }
        socket?.let { if (started) flush(it) }
    }

    @Synchronized
    private fun flush(ws: WebSocket) {
        val data = pending.toByteArray()
        var offset = 0
        while (data.size - offset >= frameBytes) {
            val frame = data.copyOfRange(offset, offset + frameBytes)
            val ok = if (elevenLabs) ws.send(SttProtocol.elevenLabsAudio(frame)) else ws.send(frame.toByteString())
            if (!ok) break
            offset += frameBytes
        }
        pending.reset()
        if (offset < data.size) pending.write(data, offset, data.size - offset)
    }

    /** Asks the provider to finish (final turn is still delivered), then closes. */
    fun stop() {
        if (closed) return
        closed = true
        socket?.let { ws ->
            if (elevenLabs) ws.send(SttProtocol.elevenLabsAudio(ByteArray(0), commit = true)) else ws.send(SttProtocol.ASSEMBLYAI_TERMINATE)
            Thread {
                Thread.sleep(2_500)
                ws.close(1000, null)
            }.start()
        }
    }
}
