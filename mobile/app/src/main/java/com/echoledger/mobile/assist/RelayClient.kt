package com.echoledger.mobile.assist

import com.echoledger.mobile.ui.v2.assist.MeetingType
import java.net.InetAddress
import java.net.Socket
import java.time.Instant
import javax.net.SocketFactory
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import okhttp3.CertificatePinner
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener

/**
 * The relay block of a session start or token refresh, with the relay host's pins (`relay_pins` in `GET /api/config`,
 * contract 1.9.0-g: never the server's own pins). The token stays out of [toString].
 */
data class RelayGrant(
    val url: String,
    val token: String,
    val expiresAt: String?,
    val pins: List<String>,
    /** This month's assist time left (`plan.seconds_left`), when the answer gave it. */
    val secondsLeft: Long? = null
) {
    override fun toString(): String = "RelayGrant($url, expires $expiresAt, ${pins.size} pins)"
}

/** Why a session's relay connection ended for good. */
enum class RelayEnd {
    /** The phone ended it ([RelayClient.stop]) or the relay closed normally. */
    Closed,

    /** A newer connection for the session took over (4409): this one never reconnects. */
    Replaced,

    /** The session is someone else's (4403). */
    WrongSession,

    /** No connection for the whole resume grace (the relay keeps a session 120 s), or tokens kept being refused. */
    Lost,

    /** The relay host's key is not one of `relay_pins`, or there are none yet: never connected (recording goes on). */
    NotTrusted
}

/** The pauses of one session (docs/API_ASSIST.md "Connecting"); the same as the desktop's. */
data class RelayPolicy(
    val backoffMs: List<Long> = listOf(250, 500, 1_000, 2_000, 4_000, 8_000),
    /** A token is refreshed this long before it expires. */
    val refreshEarlyMs: Long = 120_000,
    /** A failed refresh is tried again after this. */
    val refreshRetryMs: Long = 10_000,
    /** The relay keeps a dropped session this long for a resume. */
    val resumeGraceMs: Long = 120_000,
    /** How long a connection may take to say `ready`. */
    val readyTimeoutMs: Long = 15_000
) {
    fun pause(attempt: Int): Long = backoffMs[(attempt.coerceAtLeast(1) - 1).coerceAtMost(backoffMs.lastIndex)]

    /** How long from [nowMs] until a token expiring at [expiresAt] should be refreshed; 0 when that time has passed. */
    fun refreshIn(expiresAt: String?, nowMs: Long): Long? {
        val at = expiresAt?.let { runCatching { Instant.parse(it).toEpochMilli() }.getOrNull() } ?: return null
        return (at - refreshEarlyMs - nowMs).coerceAtLeast(0)
    }

    companion object {
        /** Not a close code: the relay host's key is not one of the pins (the connection never opened). */
        const val PINS_REFUSED = -1

        /** What a close code means for the session (pure; no close frame at all is null, as for 1006). */
        fun afterClose(code: Int?): After = when (code) {
            1000 -> After.End(RelayEnd.Closed)

            PINS_REFUSED -> After.End(RelayEnd.NotTrusted)

            4409 -> After.End(RelayEnd.Replaced)

            4403 -> After.End(RelayEnd.WrongSession)

            4401 -> After.RefreshThenReconnect

            // 1012 restarting, 4408 idle, 1009 too big, 1006 or no close frame (a slow reader dropped, the network).
            else -> After.Reconnect
        }
    }

    sealed interface After {
        data class End(val why: RelayEnd) : After
        data object Reconnect : After
        data object RefreshThenReconnect : After
    }
}

/**
 * The phone's WebSocket client to the Live Assist relay (docs/API_ASSIST.md 1.9.0-o, "Connecting" and "What the apps
 * must handle"), one per session of this phone:
 * - connects to `relay.url` with the token as `Authorization: Bearer`, TCP_NODELAY on the socket, over `wss://` with the
 *   relay host's key pinned to `relay_pins` (none, or another key: [RelayEnd.NotTrusted], recording unaffected); plain
 *   `ws://` only to this phone itself (tests);
 * - counts `seq` per connection from 1, and sends only after `ready`, paced to its `messages_per_second`; what is sent
 *   while it is down waits in [AssistOutbox]; a `ready` with `resumed: false` after a context was given gets the whole
 *   context again first;
 * - refreshes the token 2 minutes before it expires ([refresh] asks the server), sends `token_refresh`, and on a refused
 *   one asks again after the usual pauses;
 * - on `4401` gets a new token and reconnects; on `1006` (no close frame: a slow reader dropped) or `1012` reconnects and
 *   resumes; on `4409` or `4403` stops; gives up when nothing connected for the 120 s resume grace.
 * Every relay message the card needs goes to [onEvent]; the end comes once, to [onEnded].
 */
class RelayClient(
    grant: RelayGrant,
    private val refresh: suspend () -> RelayGrant?,
    private val scope: CoroutineScope,
    private val meetingType: MeetingType? = null,
    private val policy: RelayPolicy = RelayPolicy(),
    private val clock: () -> Long = System::currentTimeMillis,
    private val elapsed: () -> Long = android.os.SystemClock::elapsedRealtime,
    private val onEvent: (RelayEvent) -> Unit,
    private val onEnded: (RelayEnd) -> Unit,
    /** The base HTTP client (tests: their own certificates); TCP_NODELAY and the pins are added to it. */
    private val http: OkHttpClient? = null
) {
    @Volatile private var grant = grant
    private val outbox = AssistOutbox()
    private val context = AssistContext()
    private val incoming = Channel<Incoming>(Channel.UNLIMITED)

    @Volatile private var socket: WebSocket? = null

    @Volatile private var ready = false

    @Volatile private var rate = AssistOutbox.DEFAULT_RATE

    @Volatile private var stopped = false
    private var seq = 0
    private var job: Job? = null
    private var refreshJob: Job? = null

    private sealed interface Incoming {
        data class Text(val socket: WebSocket, val text: String) : Incoming
        data class Closed(val socket: WebSocket, val code: Int?) : Incoming

        /** Something was queued: look at the outbox. */
        data object Wake : Incoming
    }

    /** Opens the connection and keeps it, until [stop] or an end. */
    fun start() {
        job = scope.launch { run() }
    }

    /** Queues [message]: it leaves now when the relay is ready, else when it is again. */
    fun send(message: ClientMessage) {
        val down = !ready
        val queued = if (message is ClientMessage.ContextUpdate) {
            context.update(message.fields)
            // While down a newer context replaces the waiting one, so it must carry every field given so far.
            if (down) context.whole() else message
        } else {
            message
        }
        outbox.push(queued, down)
        incoming.trySend(Incoming.Wake)
    }

    /** Ends the connection; nothing reconnects. */
    fun stop() {
        if (stopped) return
        stopped = true
        refreshJob?.cancel()
        socket?.close(1000, "ended")
        job?.cancel()
        onEnded(RelayEnd.Closed)
    }

    private suspend fun run() {
        var attempt = 0
        var downSince: Long? = null
        while (scope.isActive && !stopped) {
            val client = clientFor(grant, http) ?: return end(RelayEnd.NotTrusted)
            val ws = client.newWebSocket(
                Request.Builder().url(grant.url).header("Authorization", "Bearer ${grant.token}").build(),
                listener
            )
            socket = ws
            seq = 0
            ready = false
            val after = connection(ws)
            socket = null
            ready = false
            refreshJob?.cancel()
            if (stopped) return
            if (readyOnce) {
                // A connection that got as far as ready starts the count (and the resume grace) again.
                readyOnce = false
                attempt = 0
                downSince = null
            }
            when (after) {
                is RelayPolicy.After.End -> return end(after.why)
                RelayPolicy.After.RefreshThenReconnect -> refresh()?.let { grant = it.copy(pins = it.pins.ifEmpty { grant.pins }) }
                RelayPolicy.After.Reconnect -> Unit
            }
            val since = downSince ?: clock().also { downSince = it }
            if (clock() - since >= policy.resumeGraceMs) return end(RelayEnd.Lost)
            attempt++
            delay(policy.pause(attempt))
        }
    }

    @Volatile private var readyOnce = false

    /** One connection, from opening to its close; what to do after it. */
    private suspend fun connection(ws: WebSocket): RelayPolicy.After {
        val readyBy = clock() + policy.readyTimeoutMs
        while (true) {
            val next = if (ready) {
                incoming.tryReceive().getOrNull() ?: run {
                    flush(ws)
                    withTimeoutOrNull(FLUSH_TICK_MS) { incoming.receive() }
                }
            } else {
                withTimeoutOrNull((readyBy - clock()).coerceAtLeast(1)) { incoming.receive() }
                    ?: return RelayPolicy.After.Reconnect.also { ws.cancel() }
            } ?: continue
            when (next) {
                is Incoming.Closed -> if (next.socket === ws) return RelayPolicy.afterClose(next.code)
                is Incoming.Text -> if (next.socket === ws) handle(ws, next.text)
                Incoming.Wake -> Unit
            }
        }
    }

    @Suppress("UNUSED_PARAMETER")
    private fun handle(ws: WebSocket, text: String) {
        val event = RelayDecoder.decode(text, elapsed(), meetingType) ?: return
        when (event) {
            is RelayEvent.Ready -> {
                rate = event.messagesPerSecond
                ready = true
                readyOnce = true
                // A relay that restarted lost the session: the whole context goes again, first.
                if (!event.resumed && !context.isEmpty) outbox.contextFirst(context.whole())
                scheduleRefresh(event.expiresAt)
            }

            is RelayEvent.TokenRefreshed -> scheduleRefresh(event.expiresAt)

            is RelayEvent.Error -> if (event.code == "token_refresh_rejected") scheduleRefreshIn(policy.refreshRetryMs)

            is RelayEvent.Card -> Unit
        }
        onEvent(event)
    }

    private var lastSentMs = 0L

    /** Sends what waits, paced to the relay's rate. */
    private suspend fun flush(ws: WebSocket) {
        val gap = 1_000L / rate.coerceAtLeast(1)
        while (ready && socket === ws) {
            val message = outbox.take() ?: return
            val wait = lastSentMs + gap - elapsed()
            if (wait > 0) delay(wait)
            if (!ws.send(message.frame(++seq))) {
                seq--
                outbox.putBack(message)
                return
            }
            lastSentMs = elapsed()
        }
    }

    private fun scheduleRefresh(expiresAt: String?) {
        policy.refreshIn(expiresAt, clock())?.let(::scheduleRefreshIn)
    }

    private fun scheduleRefreshIn(ms: Long) {
        refreshJob?.cancel()
        refreshJob = scope.launch {
            delay(ms)
            val fresh = refresh()
            if (fresh == null) {
                scheduleRefreshIn(policy.refreshRetryMs)
                return@launch
            }
            grant = fresh.copy(pins = fresh.pins.ifEmpty { grant.pins })
            send(ClientMessage.TokenRefresh(fresh.token))
        }
    }

    private fun end(why: RelayEnd) {
        if (stopped) return
        stopped = true
        refreshJob?.cancel()
        onEnded(why)
    }

    private val listener = object : WebSocketListener() {
        override fun onMessage(webSocket: WebSocket, text: String) {
            incoming.trySend(Incoming.Text(webSocket, text))
        }

        override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
            webSocket.close(code.takeIf { it in 1000..4999 && it != 1005 && it != 1006 } ?: 1000, null)
            incoming.trySend(Incoming.Closed(webSocket, code))
        }

        override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
            // No close frame (1006: the network, or a reader the relay dropped), refused before the upgrade (401: the
            // token), or a relay host whose key is not pinned (never retried with the same pins).
            val code = when {
                pinsRefused(t) -> RelayPolicy.PINS_REFUSED
                response?.code == 401 -> 4401
                else -> null
            }
            incoming.trySend(Incoming.Closed(webSocket, code))
        }
    }

    companion object {
        private const val FLUSH_TICK_MS = 250L

        /**
         * The relay host's key was refused by the pins. OkHttp then tries the host's other addresses, so the refusal may
         * come as a suppressed failure of the last one ("connection refused" on IPv6 after a refused key on IPv4).
         */
        internal fun pinsRefused(t: Throwable): Boolean {
            val seen = mutableListOf<Throwable>()
            val todo = ArrayDeque(listOf(t))
            while (todo.isNotEmpty() && seen.size < 16) {
                val e = todo.removeFirst()
                if (seen.any { it === e }) continue
                seen += e
                if (e is javax.net.ssl.SSLPeerUnverifiedException) return true
                e.cause?.let(todo::addLast)
                e.suppressed.forEach(todo::addLast)
            }
            return false
        }

        /**
         * The HTTP client of one connection: TCP_NODELAY on, and the relay host's key pinned to [RelayGrant.pins]; null
         * when there are no pins for a `wss://` relay (it can't be trusted yet). Plain `ws://` only to this phone itself.
         */
        fun clientFor(grant: RelayGrant, base: OkHttpClient? = null): OkHttpClient? {
            val https = grant.url.replaceFirst(Regex("^wss://"), "https://").replaceFirst(Regex("^ws://"), "http://")
            val host = runCatching { java.net.URI(https).host }.getOrNull() ?: return null
            val builder = (base?.newBuilder() ?: OkHttpClient.Builder())
                .socketFactory(NoDelaySocketFactory(SocketFactory.getDefault()))
                .pingInterval(java.time.Duration.ofSeconds(20))
            if (grant.url.startsWith("wss://")) {
                val pins = com.echoledger.mobile.net.Pins.normalize(grant.pins)
                if (pins.isEmpty()) return null
                builder.certificatePinner(CertificatePinner.Builder().apply { pins.forEach { add(host, it) } }.build())
            } else if (host !in setOf("localhost", "127.0.0.1", "10.0.2.2")) {
                return null
            }
            return builder.build()
        }
    }
}

/** Sockets with TCP_NODELAY on: Nagle's delay cost the relay's load test 20 to 30 ms a suggestion. */
class NoDelaySocketFactory(private val base: SocketFactory) : SocketFactory() {
    private fun Socket.noDelay(): Socket = apply { runCatching { tcpNoDelay = true } }

    override fun createSocket(): Socket = base.createSocket().noDelay()

    override fun createSocket(host: String?, port: Int): Socket = base.createSocket(host, port).noDelay()

    override fun createSocket(host: String?, port: Int, localHost: InetAddress?, localPort: Int): Socket =
        base.createSocket(host, port, localHost, localPort).noDelay()

    override fun createSocket(host: InetAddress?, port: Int): Socket = base.createSocket(host, port).noDelay()

    override fun createSocket(address: InetAddress?, port: Int, localAddress: InetAddress?, localPort: Int): Socket =
        base.createSocket(address, port, localAddress, localPort).noDelay()
}
