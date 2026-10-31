package com.echoledger.mobile.translate

import com.echoledger.mobile.data.LiveTranslateToken
import java.time.Instant
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import okio.ByteString.Companion.toByteString

/**
 * What the box shows: the settled translation (its recent tail), the words still changing, the same for what was
 * said, and the connection. [heardAt] (elapsed ms) is when the text last changed, for fading out when quiet.
 */
data class TranslateFeed(
    val text: String = "",
    val partial: String = "",
    val original: String = "",
    val originalPartial: String = "",
    val language: String? = null,
    val status: Status = Status.IDLE,
    /** A short message for the box ("No connection — retrying"), shown once; null when all is well. */
    val notice: String? = null,
    val heardAt: Long = 0L,
    /** Debug HUD: end of speech → translated text, upload rate, reconnects. */
    val latencyMs: Long? = null,
    val kbps: Int = 0,
    val reconnects: Int = 0
) {
    enum class Status { IDLE, CONNECTING, LIVE, QUIET, RECONNECTING, STOPPED }

    /** The settled and changing translation as one text (the box shows its last lines). */
    val shown: String get() = (text + partial).trim()
    val shownOriginal: String get() = (original + originalPartial).trim()
}

/** A finished sentence of translation, for "Save session text" and .srt (times in ms since the session started). */
data class TranslatedSentence(val text: String, val original: String, val startMs: Long, val endMs: Long)

/** A Live Translate engine (plan B1): Soniox today; the interface lets another engine be added later. */
interface LiveTranslateEngine {
    val feed: StateFlow<TranslateFeed>

    /** Speech frames (16 kHz mono PCM, 100 ms each): opens the stream if it is closed. */
    fun send(frames: List<ShortArray>)

    /** The gate heard the end of speech (for the latency figure). */
    fun speechEnded()

    /** Nobody has spoken for a while: close the stream (Soniox bills the time it is open). */
    fun rest()

    fun stop()
}

/** Why a key could not be had: [message] is the server's own words ("Soniox has no credit left…") when it gave some. */
class TokenUnavailable(message: String?, val permanent: Boolean) : Exception(message)

/**
 * Live Translate over Soniox's real-time WebSocket (since v1.3, Phase B). The phone talks to Soniox directly with a
 * temporary key from the server ([fetchToken]); nothing is stored. The stream is opened when speech starts (frames
 * wait meanwhile, up to [MAX_PENDING_FRAMES]) and closed by [rest] after quiet; keepalives hold it open between words.
 * It reconnects with a growing pause after a failure (Wi-Fi ↔ mobile switches), fetches the next key before the
 * current one expires, and starts a new stream before Soniox's 300-minute limit. "No connection" is said once.
 */
class LiveTranslateSession(
    private val client: OkHttpClient,
    private val scope: CoroutineScope,
    private val speed: SpeedPreset,
    private val fetchToken: suspend () -> LiveTranslateToken,
    private val now: () -> Instant = Instant::now,
    private val elapsed: () -> Long = { System.nanoTime() / 1_000_000 },
    private val backoffMs: (Int) -> Long = { attempt -> minOf(30_000L, 1_000L shl attempt.coerceAtMost(5)) },
    private val onSentence: (TranslatedSentence) -> Unit = {},
    /** Called with the milliseconds each stream stayed open (what Soniox bills). */
    private val onOpenMs: (Long) -> Unit = {}
) : LiveTranslateEngine {
    private val state = MutableStateFlow(TranslateFeed())
    override val feed: StateFlow<TranslateFeed> = state.asStateFlow()

    private val lock = Any()
    private var socket: WebSocket? = null
    private var live = false
    private var stopped = false
    private var wanted = false
    private var attempt = 0
    private var token: LiveTranslateToken? = null
    private var connectJob: Job? = null
    private var keepAliveJob: Job? = null
    private var rotateJob: Job? = null
    private val pending = ArrayDeque<ShortArray>()
    private var openedAt = 0L
    private var lastAudioAt = 0L
    private val startedAt = elapsed()
    private var speechEndAt: Long? = null
    private var sentBytes = 0L
    private var rateFrom = 0L
    private var sentence = StringBuilder()
    private var sentenceOriginal = StringBuilder()
    private var sentenceStart = -1L

    override fun send(frames: List<ShortArray>) {
        if (frames.isEmpty()) return
        synchronized(lock) {
            if (stopped) return
            wanted = true
            val ws = socket
            if (ws != null && live) {
                frames.forEach { write(ws, it) }
            } else {
                frames.forEach { pending.addLast(it) }
                while (pending.size > MAX_PENDING_FRAMES) pending.removeFirst()
                if (connectJob?.isActive != true && ws == null) connect()
            }
        }
    }

    override fun speechEnded() {
        synchronized(lock) { speechEndAt = elapsed() }
    }

    override fun rest() {
        synchronized(lock) {
            wanted = false
            pending.clear()
            closeSocket("quiet")
            connectJob?.cancel()
        }
        state.update { it.copy(status = TranslateFeed.Status.QUIET, partial = "", originalPartial = "") }
    }

    override fun stop() {
        synchronized(lock) {
            stopped = true
            wanted = false
            pending.clear()
            connectJob?.cancel()
            closeSocket("stopped")
        }
        flushSentence(elapsed())
        state.update { it.copy(status = TranslateFeed.Status.STOPPED, partial = "", originalPartial = "") }
    }

    private fun write(ws: WebSocket, frame: ShortArray) {
        val bytes = ByteArray(frame.size * 2)
        for (i in frame.indices) {
            bytes[2 * i] = (frame[i].toInt() and 0xff).toByte()
            bytes[2 * i + 1] = (frame[i].toInt() shr 8 and 0xff).toByte()
        }
        ws.send(bytes.toByteString())
        val t = elapsed()
        lastAudioAt = t
        sentBytes += bytes.size
        if (t - rateFrom >= RATE_WINDOW_MS) {
            val kbps = (sentBytes * 8 / maxOf(1L, t - rateFrom)).toInt()
            sentBytes = 0
            rateFrom = t
            state.update { it.copy(kbps = kbps) }
        }
    }

    /** Must hold [lock]. */
    private fun closeSocket(reason: String) {
        keepAliveJob?.cancel()
        rotateJob?.cancel()
        val ws = socket ?: return
        socket = null
        live = false
        runCatching { ws.send(SonioxProtocol.END_OF_AUDIO) }
        runCatching { ws.close(NORMAL_CLOSURE, reason) }
        countOpenTime()
    }

    /** Must hold [lock]. */
    private fun countOpenTime() {
        if (openedAt > 0) {
            val ms = elapsed() - openedAt
            openedAt = 0
            runCatching { onOpenMs(ms) }
        }
    }

    /** Must hold [lock]. */
    private fun connect() {
        connectJob = scope.launch {
            state.update {
                it.copy(
                    status = if (it.status == TranslateFeed.Status.IDLE || it.status == TranslateFeed.Status.QUIET) {
                        TranslateFeed.Status.CONNECTING
                    } else {
                        TranslateFeed.Status.RECONNECTING
                    }
                )
            }
            val key = runCatching { usableToken() }.getOrElse { problem ->
                retryLater(problem)
                return@launch
            }
            synchronized(lock) {
                if (stopped || !wanted) return@launch
                socket = client.newWebSocket(Request.Builder().url(key.websocketUrl).build(), Listener(key))
            }
        }
    }

    /** The cached key while it has a minute left, else a new one; the next one is fetched ahead of time. */
    private suspend fun usableToken(): LiveTranslateToken {
        val cached = synchronized(lock) { token }
        val left = cached?.let { expiresInMs(it) } ?: 0L
        if (cached != null && left > MIN_KEY_LEFT_MS) return cached
        val fresh = fetchToken()
        synchronized(lock) { token = fresh }
        return fresh
    }

    private fun expiresInMs(t: LiveTranslateToken): Long =
        runCatching { Instant.parse(t.expiresAt).toEpochMilli() - now().toEpochMilli() }.getOrDefault(0L)

    private fun retryLater(problem: Throwable?) {
        val first: Boolean
        val wait: Long
        synchronized(lock) {
            if (stopped || !wanted) return
            first = attempt == 0
            wait = backoffMs(attempt++)
        }
        val words = (problem as? TokenUnavailable)?.message?.takeIf { it.isNotBlank() }
        // No credit, no key, the budget used up, not paired: retrying cannot help, so Live Translate ends and the
        // service says why (since v1.3.0.1: the box stayed on screen retrying every minute).
        if ((problem as? TokenUnavailable)?.permanent == true) {
            synchronized(lock) {
                stopped = true
                wanted = false
                pending.clear()
            }
            state.update {
                it.copy(status = TranslateFeed.Status.STOPPED, notice = words ?: NO_CONNECTION, partial = "", originalPartial = "")
            }
            return
        }
        state.update {
            it.copy(
                status = TranslateFeed.Status.RECONNECTING,
                notice = when {
                    words != null -> words
                    first -> NO_CONNECTION
                    else -> it.notice
                },
                reconnects = it.reconnects + 1
            )
        }
        synchronized(lock) {
            connectJob = scope.launch {
                delay(wait)
                synchronized(lock) { if (!stopped && wanted && socket == null) connect() }
            }
        }
    }

    private fun onOpen(ws: WebSocket, key: LiveTranslateToken) {
        synchronized(lock) {
            if (ws !== socket) return
            ws.send(SonioxProtocol.config(key, speed))
            live = true
            attempt = 0
            openedAt = elapsed()
            rateFrom = openedAt
            sentBytes = 0
            while (pending.isNotEmpty()) write(ws, pending.removeFirst())
            keepAliveJob = scope.launch {
                while (isActive) {
                    delay(KEEPALIVE_EVERY_MS)
                    synchronized(lock) {
                        val s = socket
                        if (s === ws && elapsed() - lastAudioAt >= KEEPALIVE_EVERY_MS) s.send(SonioxProtocol.KEEPALIVE)
                    }
                }
            }
            // A new stream shortly before Soniox's 300-minute limit; the next key is fetched before this one expires.
            rotateJob = scope.launch {
                delay(ROTATE_AFTER_MS)
                synchronized(lock) {
                    if (socket === ws) {
                        closeSocket("rotate")
                        if (wanted) connect()
                    }
                }
            }
            scope.launch {
                delay((expiresInMs(key) - PREFETCH_BEFORE_MS).coerceAtLeast(0L))
                if (synchronized(lock) { !stopped && token === key }) {
                    runCatching { fetchToken() }.onSuccess { fresh -> synchronized(lock) { token = fresh } }
                }
            }
        }
        state.update { it.copy(status = TranslateFeed.Status.LIVE, notice = null) }
    }

    private fun onReply(ws: WebSocket, reply: SonioxReply) {
        if (synchronized(lock) { ws !== socket }) return
        if (reply.errorCode != null) {
            synchronized(lock) {
                // A refused or expired key: drop it and start again with a new one.
                if (reply.errorCode == 401 || reply.errorCode == 403) token = null
                socket = null
                live = false
                countOpenTime()
            }
            runCatching { ws.close(NORMAL_CLOSURE, "error") }
            val problem = if (reply.errorCode == 402) TokenUnavailable(NO_CREDIT, permanent = true) else null
            retryLater(problem)
            return
        }
        val t = elapsed()
        val latency = synchronized(lock) {
            if (reply.finalTranslation.isNotBlank() && speechEndAt != null) {
                (t - speechEndAt!!).also { speechEndAt = null }
            } else {
                null
            }
        }
        if (reply.finalTranslation.isNotEmpty()) collectSentence(reply.finalTranslation, reply.finalOriginal, t)
        state.update { feed ->
            val changed = reply.finalTranslation.isNotEmpty() || reply.partialTranslation != feed.partial ||
                reply.finalOriginal.isNotEmpty() || reply.partialOriginal != feed.originalPartial
            feed.copy(
                text = (feed.text + reply.finalTranslation).takeLast(TAIL_CHARS),
                partial = reply.partialTranslation,
                original = (feed.original + reply.finalOriginal + if (reply.endpoint) " " else "").takeLast(TAIL_CHARS),
                originalPartial = reply.partialOriginal,
                language = reply.language ?: feed.language,
                heardAt = if (changed) t else feed.heardAt,
                latencyMs = latency ?: feed.latencyMs
            )
        }
    }

    private fun collectSentence(translation: String, original: String, at: Long) {
        val emit: TranslatedSentence?
        synchronized(lock) {
            if (sentenceStart < 0) sentenceStart = at - startedAt
            sentence.append(translation)
            sentenceOriginal.append(original)
            emit = if (SENTENCE_END.containsMatchIn(sentence.trimEnd().takeLast(1))) {
                TranslatedSentence(sentence.toString().trim(), sentenceOriginal.toString().trim(), sentenceStart, at - startedAt + 1_500)
                    .also {
                        sentence = StringBuilder()
                        sentenceOriginal = StringBuilder()
                        sentenceStart = -1
                    }
            } else {
                null
            }
        }
        emit?.let { runCatching { onSentence(it) } }
    }

    private fun flushSentence(at: Long) {
        val emit = synchronized(lock) {
            if (sentence.isBlank()) return
            TranslatedSentence(sentence.toString().trim(), sentenceOriginal.toString().trim(), maxOf(0, sentenceStart), at - startedAt)
                .also { sentence = StringBuilder() }
        }
        runCatching { onSentence(emit) }
    }

    private inner class Listener(private val key: LiveTranslateToken) : WebSocketListener() {
        override fun onOpen(webSocket: WebSocket, response: Response) = onOpen(webSocket, key)

        override fun onMessage(webSocket: WebSocket, text: String) = onReply(webSocket, SonioxProtocol.parse(text))

        override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
            val again = synchronized(lock) {
                if (webSocket !== socket) return
                socket = null
                live = false
                countOpenTime()
                wanted
            }
            if (again) retryLater(null)
        }

        override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
            val again = synchronized(lock) {
                if (webSocket !== socket) return
                socket = null
                live = false
                countOpenTime()
                wanted && !stopped
            }
            if (again) retryLater(null)
        }
    }

    companion object {
        const val NO_CONNECTION = "No connection — retrying"
        const val NO_CREDIT = "Soniox has no credit left. Add credit to the Soniox account to use Live Translate."
        const val MAX_PENDING_FRAMES = 50 // 5 s of speech waits for the stream to open
        const val KEEPALIVE_EVERY_MS = 10_000L // Soniox ends a stream after 20 s without audio or keepalive
        const val ROTATE_AFTER_MS = 290L * 60_000L // Soniox's limit is 300 minutes per stream
        const val TAIL_CHARS = 600
        private const val NORMAL_CLOSURE = 1000
        private const val MIN_KEY_LEFT_MS = 60_000L
        private const val PREFETCH_BEFORE_MS = 90_000L
        private const val RATE_WINDOW_MS = 2_000L
        private val SENTENCE_END = Regex("[.!?۔؟。]")
    }
}
