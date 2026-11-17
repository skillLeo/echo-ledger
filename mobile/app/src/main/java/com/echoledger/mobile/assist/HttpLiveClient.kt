package com.echoledger.mobile.assist

import com.echoledger.mobile.ui.v2.assist.AssistMessage
import com.echoledger.mobile.ui.v2.assist.LiveMode
import com.echoledger.mobile.ui.v2.assist.MeetingType
import java.io.IOException
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Semaphore
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject

/** How a session's live messages travel: the relay's WebSocket ([RelayClient]) or HTTP mode ([HttpLiveClient]). */
interface LiveTransport {
    /** Starts carrying messages, until [stop] or an end. */
    fun start()

    /** Queues [message]; the transport sends it in order. */
    fun send(message: ClientMessage)

    /** Ends the transport; nothing more is sent. */
    fun stop()
}

/** The live block of a session start or token refresh: [RelayGrant] or [HttpGrant]. */
sealed interface LiveGrant {
    /** This month's assist time left (`plan.seconds_left`), when the answer gave it. */
    val secondsLeft: Long?
}

/**
 * The `http` block of a session start or token refresh (contract 1.9.0-q): where each message is POSTed, and the
 * session's `ready` as it is now. HTTP mode uses the device token and the server's own pins, so there is no token here.
 */
data class HttpGrant(val liveUrl: String, val ready: RelayEvent.Ready, override val secondsLeft: Long? = null) : LiveGrant {
    companion object {
        /** The grant from the server's `http` block; a `ready` that is missing or unreadable reads as the defaults. */
        fun of(dto: com.echoledger.mobile.data.AssistHttpDto, sessionId: String, secondsLeft: Long? = null): HttpGrant {
            val decoded = dto.ready?.let { ready ->
                val withType = JsonObject(ready + ("type" to JsonPrimitive("ready")))
                RelayDecoder.decode(withType.toString(), 0, null) as? RelayEvent.Ready
            }
            val ready = decoded?.let { it.copy(sessionId = it.sessionId.ifEmpty { sessionId }) } ?: RelayEvent.Ready(
                sessionId = sessionId,
                mode = LiveMode.Balanced,
                allowedModes = emptyList(),
                resumed = false,
                practice = false,
                expiresAt = null,
                messagesPerSecond = AssistOutbox.DEFAULT_RATE,
                maxMessageBytes = RelayDecoder.MAX_MESSAGE_BYTES
            )
            return HttpGrant(dto.liveUrl, ready, secondsLeft)
        }
    }
}

/** The pauses and limits of HTTP mode (docs/API_ASSIST.md 1.9.0-q, "HTTP mode"). */
data class HttpPolicy(
    /** The session is refreshed this often (the server ends one not refreshed for 20 minutes). */
    val refreshEveryMs: Long = 13 * 60_000L,
    /** A failed refresh is tried again after this. */
    val refreshRetryMs: Long = 10_000,
    /** Streams open at once per session (a fifth is refused with `429`). */
    val maxStreams: Int = 4,
    /** The next message waits this long at most for the previous one's answer to begin, so they reach the server in order. */
    val orderWaitMs: Long = 1_000
)

/**
 * Live Assist without a long-lived connection (docs/API_ASSIST.md 1.9.0-q, "HTTP mode"), for servers that can't hold
 * one: each app message is a POST to `http.live_url` with the device token, and its answer is a stream of server-sent
 * events carrying the relay's own messages.
 * - The `ready` comes with the grant, so it goes to [onEvent] at [start]; no `seq`, `auth` or `token_refresh`.
 * - Messages leave in order, paced to the `ready`'s `messages_per_second`, with at most [HttpPolicy.maxStreams]
 *   answers open at once (the next waits for one to finish).
 * - The events of every open stream go to [onEvent] in arrival order: the card logic is the relay's. The server sends
 *   a `superseded` or `cancelled` end itself; a stream that drops leaves the suggestion it was writing without one, so
 *   that suggestion is cleared ([AssistMessage.Lost]).
 * - The session is refreshed every 13 minutes ([refresh]); that keeps it on.
 * - Refusals read as the card's problems: `429` too fast, `422` a bad message, `426` an app too old for this
 *   session; `401`, `404` and `409 session_ended` end the session.
 */
class HttpLiveClient(
    grant: HttpGrant,
    private val refresh: suspend () -> HttpGrant?,
    /** One POST of a message's JSON to the live URL, not yet sent; null when that URL can't be trusted. */
    private val call: suspend (url: String, json: String) -> okhttp3.Call?,
    private val scope: CoroutineScope,
    private val meetingType: MeetingType? = null,
    private val policy: HttpPolicy = HttpPolicy(),
    private val elapsed: () -> Long = android.os.SystemClock::elapsedRealtime,
    private val onEvent: (RelayEvent) -> Unit,
    private val onEnded: (RelayEnd) -> Unit
) : LiveTransport {
    @Volatile private var grant = grant
    private val outbox = Channel<ClientMessage>(Channel.UNLIMITED)
    private val streams = Semaphore(policy.maxStreams)
    private val calls = java.util.Collections.synchronizedSet(mutableSetOf<okhttp3.Call>())

    /** Suggestions started and not ended yet, with the stream writing each. */
    private val writing = mutableMapOf<String, Int>()
    private val lock = Any()

    @Volatile private var stopped = false
    private var sender: Job? = null
    private var refresher: Job? = null
    private var streamCount = 0
    private var lastSentMs = Long.MIN_VALUE / 2

    override fun start() {
        deliver(-1, grant.ready)
        sender = scope.launch { sendAll() }
        refresher = scope.launch { refreshAll() }
    }

    override fun send(message: ClientMessage) {
        // The session is kept by the refresh; HTTP mode has no token message.
        if (stopped || message is ClientMessage.TokenRefresh) return
        outbox.trySend(message)
    }

    override fun stop() {
        if (stopped) return
        close()
        onEnded(RelayEnd.Closed)
    }

    private fun end(why: RelayEnd) {
        if (stopped) return
        close()
        onEnded(why)
    }

    private fun close() {
        stopped = true
        sender?.cancel()
        refresher?.cancel()
        outbox.close()
        synchronized(calls) { calls.toList() }.forEach { it.cancel() }
    }

    private suspend fun sendAll() {
        for (message in outbox) {
            if (stopped) return
            val gap = 1_000L / grant.ready.messagesPerSecond.coerceAtLeast(1)
            val wait = lastSentMs + gap - elapsed()
            if (wait > 0) delay(wait)
            streams.acquire()
            if (stopped) {
                streams.release()
                return
            }
            lastSentMs = elapsed()
            val id = ++streamCount
            val begun = CompletableDeferred<Unit>()
            scope.launch {
                try {
                    stream(id, message, begun)
                } finally {
                    begun.complete(Unit)
                    streams.release()
                }
            }
            // The next message goes once this one's answer has begun (or a moment has passed), so they arrive in order.
            withTimeoutOrNull(policy.orderWaitMs) { begun.await() }
        }
    }

    private suspend fun refreshAll() {
        var next = policy.refreshEveryMs
        while (scope.isActive && !stopped) {
            delay(next)
            val fresh = runCatching { refresh() }.getOrNull()
            next = if (fresh == null) {
                policy.refreshRetryMs
            } else {
                grant = fresh
                policy.refreshEveryMs
            }
        }
    }

    /** One message's POST and the stream it answers with. */
    private suspend fun stream(id: Int, message: ClientMessage, begun: CompletableDeferred<Unit>) {
        val c = call(grant.liveUrl, message.body()) ?: return end(RelayEnd.NotTrusted)
        calls += c
        try {
            withContext(Dispatchers.IO) {
                c.execute().use { response ->
                    begun.complete(Unit)
                    if (!response.isSuccessful) {
                        refused(response.code, runCatching { response.body.string() }.getOrDefault(""))
                        return@use
                    }
                    val source = response.body.source()
                    var event: String? = null
                    val data = StringBuilder()
                    while (!stopped) {
                        val line = source.readUtf8Line() ?: break
                        when {
                            line.isEmpty() -> {
                                if (event == "end") break
                                if (data.isNotEmpty()) received(id, event, data.toString())
                                event = null
                                data.setLength(0)
                            }

                            // A keep-alive.
                            line.startsWith(":") -> Unit

                            line.startsWith("event:") -> event = line.removePrefix("event:").trim()

                            line.startsWith("data:") -> {
                                if (data.isNotEmpty()) data.append('\n')
                                data.append(line.removePrefix("data:").removePrefix(" "))
                            }
                        }
                    }
                }
            }
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (e: Exception) {
            // Refused before its answer began (offline, the server): the message is lost, and the card says so. A stream
            // cut later is the same as one that ended: what it was writing is let go below.
            if (!begun.isCompleted && !stopped && e is IOException) problem("connection_failed", CONNECTION_FAILED, retry = true)
        } finally {
            calls -= c
            letGo(id)
        }
    }

    /** One event of stream [id]: the relay's message as JSON (its `type` from the event's name when the data has none). */
    private fun received(id: Int, event: String?, data: String) {
        val o = runCatching { com.echoledger.mobile.data.ApiJson.parseToJsonElement(data).jsonObject }.getOrNull() ?: return
        val typed = if (o["type"] == null && event != null) JsonObject(o + ("type" to JsonPrimitive(event))) else o
        val decoded = RelayDecoder.decode(typed.toString(), elapsed(), meetingType) ?: return
        // Not in HTTP mode: the ready comes with the grant, and there is no token message.
        if (decoded is RelayEvent.Ready || decoded is RelayEvent.TokenRefreshed) return
        deliver(id, decoded)
        if (decoded is RelayEvent.Error && decoded.fatal) end(RelayEnd.Closed)
    }

    private fun deliver(id: Int, event: RelayEvent) {
        synchronized(lock) {
            if (stopped) return
            when (event) {
                is RelayEvent.Card -> when (val m = event.message) {
                    is AssistMessage.SuggestionStart -> writing[m.suggestionId] = id
                    is AssistMessage.SuggestionEnd -> writing.remove(m.suggestionId)
                    is AssistMessage.Skip -> m.suggestionId?.let(writing::remove)
                    else -> Unit
                }

                is RelayEvent.Error -> event.suggestionId?.let(writing::remove)

                else -> Unit
            }
            onEvent(event)
        }
    }

    /** Stream [id] is over (ended, or dropped): a suggestion it was still writing gets no end, so the card clears it. */
    private fun letGo(id: Int) {
        synchronized(lock) {
            val orphans = writing.filterValues { it == id }.keys.toSet()
            if (orphans.isEmpty() || stopped) return
            orphans.forEach(writing::remove)
            onEvent(RelayEvent.Card(AssistMessage.Lost(orphans)))
        }
    }

    /** A POST refused before its stream began. */
    private fun refused(status: Int, body: String) {
        val o = runCatching { com.echoledger.mobile.data.ApiJson.parseToJsonElement(body).jsonObject }.getOrNull()
        val code = (o?.get("code") as? JsonPrimitive)?.contentOrNull
        val words = (o?.get("message") as? JsonPrimitive)?.contentOrNull
        when {
            status == 401 || status == 404 || (status == 409 && code != "session_active") -> end(RelayEnd.Closed)

            status == 426 || code == "app_update_required" -> {
                problem("app_update_required", AssistHub.UPDATE_APP, retry = false, fatal = true)
                end(RelayEnd.Refused)
            }

            status == 429 -> problem("rate_limited", words ?: TOO_FAST, retry = true)

            status == 422 -> problem("bad_message", words ?: CONNECTION_FAILED, retry = false)

            else -> problem("connection_failed", CONNECTION_FAILED, retry = true)
        }
    }

    private fun problem(code: String, words: String, retry: Boolean, fatal: Boolean = false) {
        deliver(-1, RelayEvent.Error(code, words, retry, fatal, suggestionId = null, inReplyTo = null))
    }

    companion object {
        const val CONNECTION_FAILED = "Live Assist couldn't reach the server. Try again."
        const val TOO_FAST = "Too much at once. Try again in a moment."
    }
}
