package com.echoledger.mobile.assist

import java.io.BufferedInputStream
import java.io.ByteArrayOutputStream
import java.io.Closeable
import java.io.InputStream
import java.io.OutputStream
import java.net.InetAddress
import java.net.ServerSocket
import java.net.Socket
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.TimeUnit
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject

/**
 * A stand-in for the server's HTTP mode (contract 1.9.0-q): each POST is answered with a server-sent event stream that
 * stays open until the test writes its events and ends or drops it, or with a refusal ([refuse]). One connection per
 * request (`Connection: close`), so a stream's end is the connection's.
 */
class SseStandIn : Closeable {
    private val socket = ServerSocket(0, 50, InetAddress.getByName("127.0.0.1"))
    private val open = CopyOnWriteArrayList<Socket>()

    /** Every request, in the order it arrived. */
    val requests = LinkedBlockingQueue<Post>()

    /** Refusals for the next requests, in order (status and JSON body); empty: a stream. */
    private val refusals = LinkedBlockingQueue<Pair<Int, String>>()

    val base: String get() = "http://127.0.0.1:${socket.localPort}"

    fun url(path: String) = base + path

    init {
        Thread({
            while (!socket.isClosed) {
                val client = runCatching { socket.accept() }.getOrNull() ?: break
                open += client
                Thread({ serve(client) }, "sse-stand-in-request").apply { isDaemon = true }.start()
            }
        }, "sse-stand-in").apply { isDaemon = true }.start()
    }

    /** The next request is refused with [status] and [json] before any stream. */
    fun refuse(status: Int, json: String) {
        refusals += status to json
    }

    /** The next request is answered with a redirect ([status], usually 307) to [location]. */
    fun redirect(status: Int, location: String) {
        refusals += status to "LOCATION $location"
    }

    /** The next request (waits up to 5 s). */
    fun next(): Post = requests.poll(5, TimeUnit.SECONDS) ?: error("no request reached the stand-in")

    /** Nothing more arrives within [ms]. */
    fun quiet(ms: Long = 300): Boolean = requests.poll(ms, TimeUnit.MILLISECONDS) == null

    private fun serve(client: Socket) {
        val input = BufferedInputStream(client.getInputStream())
        val requestLine = line(input) ?: return client.close()
        val headers = mutableMapOf<String, String>()
        while (true) {
            val header = line(input) ?: return client.close()
            if (header.isEmpty()) break
            val colon = header.indexOf(':')
            if (colon > 0) headers[header.substring(0, colon).trim().lowercase()] = header.substring(colon + 1).trim()
        }
        val length = headers["content-length"]?.toIntOrNull() ?: 0
        val body = input.readNBytes(length)
        val parts = requestLine.split(' ')
        val out = client.getOutputStream()
        val refusal = refusals.poll()
        val post = Post(parts.getOrElse(0) { "" }, parts.getOrElse(1) { "" }, headers, String(body), client, out)
        if (refusal != null && refusal.second.startsWith("LOCATION ")) {
            out.write(
                (
                    "HTTP/1.1 ${refusal.first} Moved\r\nLocation: ${refusal.second.removePrefix("LOCATION ")}\r\n" +
                        "Content-Length: 0\r\nConnection: close\r\n\r\n"
                    ).toByteArray()
            )
            out.flush()
            client.close()
        } else if (refusal != null) {
            val text = refusal.second.toByteArray()
            out.write(
                (
                    "HTTP/1.1 ${refusal.first} Refused\r\nContent-Type: application/json\r\nContent-Length: ${text.size}\r\n" +
                        "Connection: close\r\n\r\n"
                    ).toByteArray()
            )
            out.write(text)
            out.flush()
            client.close()
        } else {
            out.write(
                "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache\r\nConnection: close\r\n\r\n".toByteArray()
            )
            out.flush()
        }
        requests += post
    }

    private fun line(input: InputStream): String? {
        val bytes = ByteArrayOutputStream()
        while (true) {
            val b = input.read()
            if (b < 0) return null
            if (b == '\n'.code) return bytes.toString(Charsets.UTF_8.name()).trimEnd('\r')
            bytes.write(b)
        }
    }

    override fun close() {
        runCatching { socket.close() }
        open.forEach { runCatching { it.close() } }
    }

    /** One POST and its stream. */
    class Post(
        val method: String,
        val path: String,
        val headers: Map<String, String>,
        val body: String,
        private val client: Socket,
        private val out: OutputStream
    ) {
        val json: JsonObject get() = Json.parseToJsonElement(body).jsonObject

        /** One relay message as an event (its `type` names the event). */
        fun send(message: JsonObject) =
            write("event: ${(message["type"] as kotlinx.serialization.json.JsonPrimitive).content}\ndata: $message\n\n")

        fun keepAlive() = write(": keep-alive\n\n")

        /** The work for this message is done: `end`, then the response closes. */
        fun end() {
            write("event: end\ndata: {}\n\n")
            client.close()
        }

        /** The connection is cut with no `end`. */
        fun drop() {
            runCatching { client.shutdownOutput() }
            client.close()
        }

        fun write(text: String) {
            out.write(text.toByteArray())
            out.flush()
        }
    }
}
