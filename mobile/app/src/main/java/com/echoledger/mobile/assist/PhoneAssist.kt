package com.echoledger.mobile.assist

import com.echoledger.mobile.ui.v2.assist.AssistAdapter
import com.echoledger.mobile.ui.v2.assist.AssistMessage
import com.echoledger.mobile.ui.v2.assist.MeetingType
import com.echoledger.mobile.ui.v2.assist.SendKind
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/** The server side of a Live Assist session (docs/API_ASSIST.md "Sessions"), as the phone needs it; tests give their own. */
interface AssistServer {
    /** `POST /api/assist/sessions/{id}/token`: a new relay grant (with the relay's pins), or null when it failed. */
    suspend fun token(sessionId: String): RelayGrant?

    /** `POST …/turns`: final turns in a batch; false when they did not arrive (they are sent again). */
    suspend fun turns(sessionId: String, rows: List<TurnRow>): Boolean

    /** `POST …/suggestions/{id}/action`. */
    suspend fun action(sessionId: String, suggestionId: String, action: String, renderMs: Long? = null)

    /** `POST …/end`. */
    suspend fun end(sessionId: String)
}

/** One final turn for the server: `other` turns carry the relay's `turn_id` as [clientRef] (the report pairs them). */
data class TurnRow(
    val clientRef: String,
    val other: Boolean,
    val startMs: Long,
    val endMs: Long,
    val text: String,
    val language: String?,
    val isQuestion: Boolean
) {
    override fun toString(): String = "TurnRow($clientRef, other: $other, <${text.length} chars>)"
}

/**
 * The phone's captions as Live Assist turns (docs/API_ASSIST.md 1.9.0-e): the other side's partials and finals go to the
 * relay; the phone's own (`me`) only as finished turns, never partials (a suggestion is never triggered from `me`).
 * Partials and the final of one turn share `turn_id` (`o-1`, `o-2`… and `m-1`…). The engines say nothing of silence, so a
 * partial's `silence_ms` is how long its words have not changed.
 */
class TurnFeeder(private val elapsed: () -> Long) {
    private val numbers = mutableMapOf(true to 0, false to 0)
    private val open = mutableMapOf<Boolean, Pair<String, Long>>() // channel -> (last words, since)

    private fun id(other: Boolean) = (if (other) "o-" else "m-") + numbers.getValue(other)

    private fun openTurn(other: Boolean): String {
        if (other !in open) numbers[other] = numbers.getValue(other) + 1
        return id(other)
    }

    /** A partial: the relay's message (none for `me`) and the card's line. */
    @Synchronized
    fun partial(other: Boolean, text: String): Pair<ClientMessage?, AssistMessage.Turn> {
        val turnId = openTurn(other)
        val now = elapsed()
        val (words, since) = open[other] ?: ("" to now)
        val unchanged = words == text
        open[other] = text to (if (unchanged) since else now)
        val silence = if (unchanged) now - since else 0
        val relay = if (other) ClientMessage.TurnPartial(Turn(turnId, true, text, silenceMs = silence)) else null
        return relay to AssistMessage.Turn(turnId, other, text, final = false)
    }

    /** A final: the relay's message, the card's line and the server's row; the turn closes. */
    @Synchronized
    fun final(
        other: Boolean,
        text: String,
        startMs: Long,
        endMs: Long,
        language: String? = null
    ): Triple<ClientMessage, AssistMessage.Turn, TurnRow> {
        val turnId = openTurn(other)
        open.remove(other)
        val turn = Turn(turnId, other, text, startMs, endMs, language = language)
        val row = TurnRow(turnId, other, startMs, endMs, text, language, isQuestion = other && text.trimEnd().endsWith("?"))
        return Triple(ClientMessage.TurnFinal(turn), AssistMessage.Turn(turnId, other, text, final = true), row)
    }
}

/** Final turns batched for the server: every [EVERY_MS] or [AT_MOST] turns, whichever comes first. */
class TurnBatch {
    private val waiting = mutableListOf<TurnRow>()

    @Synchronized
    fun add(row: TurnRow): Boolean {
        waiting += row
        return waiting.size >= AT_MOST
    }

    /** The turns to send now (they stay out of the batch until [failed] gives them back). */
    @Synchronized
    fun take(): List<TurnRow> = waiting.toList().also { waiting.clear() }

    @Synchronized
    fun failed(rows: List<TurnRow>) {
        waiting.addAll(0, rows)
    }

    @Synchronized
    fun isEmpty(): Boolean = waiting.isEmpty()

    companion object {
        const val EVERY_MS = 5_000L
        const val AT_MOST = 10
    }
}

/**
 * A Live Assist session of this phone (one at a time; the phone never shows another device's session): the relay
 * connection, the captions as turns, the turns for the server, and what the card reads ([AssistAdapter]).
 */
class PhoneAssist(
    val sessionId: String,
    grant: RelayGrant,
    override val meetingType: MeetingType?,
    private val server: AssistServer,
    private val scope: CoroutineScope,
    /** What `translate` asks for (`urdu` | `roman_urdu` | `arabic`), from the prep or the phone's language. */
    private val translateTo: String? = null,
    secondsLeft: Long? = null,
    private val elapsed: () -> Long = android.os.SystemClock::elapsedRealtime,
    relayPolicy: RelayPolicy = RelayPolicy(),
    clock: () -> Long = System::currentTimeMillis,
    /**
     * Both sides recorded apart. A mono recording has one mixed channel, so the person's own words would ask for
     * suggestions (the contract forbids that): the session starts with `settings.assist = paused`; Help me now works.
     */
    private val autoSuggestions: Boolean = true
) : AssistAdapter {
    private val history = ArrayDeque<AssistMessage>()
    private val flow = MutableSharedFlow<AssistMessage>(extraBufferCapacity = 512)
    override val messages: SharedFlow<AssistMessage> = flow.asSharedFlow()
    private val turns = TurnFeeder(elapsed)
    private val batch = TurnBatch()
    private val shown = mutableSetOf<String>()

    @Volatile var ended = false
        private set

    /** Called once when the session ended (after [AssistMessage.Ended]); the hub forgets it then. */
    @Volatile var whenFinished: (() -> Unit)? = null
    private var batcher: Job? = null

    private val relay = RelayClient(
        grant = grant,
        // Each new token also brings this month's assist time for the card.
        refresh = { server.token(sessionId)?.also { fresh -> fresh.secondsLeft?.let { emit(AssistMessage.Budget(it, null)) } } },
        scope = scope,
        meetingType = meetingType,
        policy = relayPolicy,
        clock = clock,
        elapsed = elapsed,
        onEvent = ::onRelay,
        onEnded = ::finish
    )

    init {
        secondsLeft?.let { emit(AssistMessage.Budget(it, null)) }
    }

    /** Connects to the relay and starts sending the turns in batches. */
    fun start() {
        if (!autoSuggestions) {
            relay.send(ClientMessage.ContextUpdate(PAUSED))
            emit(AssistMessage.AutoPaused)
        }
        relay.start()
        batcher = scope.launch {
            while (isActive) {
                delay(TurnBatch.EVERY_MS)
                sendTurns()
            }
        }
    }

    /** The brief, documents, memory and settings for the relay (each field replaces; an empty value clears it). */
    fun context(update: kotlinx.serialization.json.JsonObject) = relay.send(ClientMessage.ContextUpdate(update))

    /** A caption line of this phone's recording: [other] is the person on the call. */
    fun caption(other: Boolean, text: String, final: Boolean, startMs: Long = 0, endMs: Long = 0) {
        if (ended || text.isBlank()) return
        if (final) {
            val (message, line, row) = turns.final(other, text, startMs, endMs)
            relay.send(message)
            emit(line)
            if (batch.add(row)) scope.launch { sendTurns() }
        } else {
            val (message, line) = turns.partial(other, text)
            message?.let(relay::send)
            emit(line)
        }
    }

    /** Practice: the next question. */
    fun practiceNext() = relay.send(ClientMessage.PracticeNext)

    override suspend fun snapshot(): List<AssistMessage> = synchronized(history) { history.toList() }

    override fun send(kind: SendKind) {
        relay.send(ClientMessage.Command(kind, language = translateTo.takeIf { kind == SendKind.Translate }))
    }

    override fun feedback(suggestionId: String, used: Boolean) {
        scope.launch { runCatching { server.action(sessionId, suggestionId, if (used) "used" else "dismissed") } }
    }

    override fun reportShown(suggestionId: String, renderMs: Long) {
        if (!synchronized(shown) { shown.add(suggestionId) }) return
        scope.launch { runCatching { server.action(sessionId, suggestionId, "shown", renderMs.coerceAtLeast(0)) } }
    }

    override fun end() {
        if (ended) return
        relay.stop()
    }

    private fun onRelay(event: RelayEvent) {
        when (event) {
            is RelayEvent.Ready -> emit(
                AssistMessage.Ready(
                    event.sessionId.ifEmpty {
                        sessionId
                    },
                    event.mode,
                    event.allowedModes,
                    event.resumed,
                    meetingType
                )
            )

            is RelayEvent.Card -> emit(event.message)

            is RelayEvent.Error -> emit(AssistMessage.Error(event.code, event.message, event.retry, event.suggestionId, event.fatal))

            is RelayEvent.TokenRefreshed -> Unit
        }
    }

    private suspend fun sendTurns() {
        val rows = batch.take()
        if (rows.isEmpty()) return
        if (!runCatching { server.turns(sessionId, rows) }.getOrDefault(false)) batch.failed(rows)
    }

    /**
     * The relay connection ended: the phone ended it, it was lost, or it can't be trusted, and then the server's session
     * ends too; replaced by a newer connection (4409) or someone else's (4403), the session goes on elsewhere and only
     * this phone stops following it.
     */
    private fun finish(why: RelayEnd) {
        if (ended) return
        ended = true
        batcher?.cancel()
        emit(AssistMessage.Ended)
        whenFinished?.invoke()
        scope.launch {
            sendTurns()
            if (why != RelayEnd.Replaced && why != RelayEnd.WrongSession) runCatching { server.end(sessionId) }
        }
    }

    private fun emit(message: AssistMessage) {
        synchronized(history) {
            history.addLast(message)
            while (history.size > HISTORY) history.removeFirst()
        }
        flow.tryEmit(message)
    }

    companion object {
        /** The messages a card opened late replays (a session's suggestions and lines, the oldest go first). */
        const val HISTORY = 2_000

        /** `context_update.settings` with automatic suggestions paused. */
        val PAUSED = kotlinx.serialization.json.buildJsonObject {
            put(
                "settings",
                kotlinx.serialization.json.buildJsonObject {
                    put("assist", kotlinx.serialization.json.JsonPrimitive("paused"))
                }
            )
        }
    }
}
