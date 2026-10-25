package com.echoledger.mobile.dictation

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord
import android.media.MediaRecorder
import android.media.ToneGenerator
import androidx.core.content.ContextCompat
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.SttTokenResponse
import com.echoledger.mobile.data.UsageReport
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.net.NotPairedException
import com.echoledger.mobile.net.SttEvent
import com.echoledger.mobile.net.SttStreamer
import com.echoledger.mobile.net.UsageWorker
import com.echoledger.mobile.recording.Pcm
import java.io.IOException
import java.time.Instant
import java.util.UUID
import kotlin.concurrent.thread
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * A dictation token (B14): `use_case: dictation`, the microphone only and never a session, so dictating never makes a
 * recording or asks to keep one. Every refusal comes back as a short message for the keyboard.
 */
class DictationTokens(private val api: ApiClient) {
    sealed interface Outcome {
        data class Token(val token: SttTokenResponse) : Outcome

        data class Refused(val message: String) : Outcome
    }

    suspend fun fetch(clientId: String?, onClientGone: suspend () -> Unit): Outcome {
        var client = clientId
        while (true) {
            try {
                val token = api.sttToken("dictation", listOf("me"), client)
                return if (token.mode == "cloud" &&
                    token.grants.any { it.protocol in PROTOCOLS }
                ) {
                    Outcome.Token(token)
                } else {
                    Outcome.Refused(UNAVAILABLE)
                }
            } catch (e: ApiException) {
                when {
                    e.code == "budget_exceeded" -> return Outcome.Refused(BUDGET)

                    e.code == "client_not_found" && client != null -> {
                        onClientGone()
                        client = null
                    }

                    else -> return Outcome.Refused(UNAVAILABLE)
                }
            } catch (e: NotPairedException) {
                return Outcome.Refused(NOT_PAIRED)
            } catch (e: IOException) {
                return Outcome.Refused(OFFLINE)
            }
        }
    }

    companion object {
        val PROTOCOLS = setOf("assemblyai_v3", "elevenlabs_realtime")
        const val BUDGET = "Monthly transcription limit reached"
        const val UNAVAILABLE = "Dictation is unavailable right now"
        const val OFFLINE = "No connection. Try again when you are online."
        const val NOT_PAIRED = "Pair EchoLedger with your server first"
    }
}

/** What the keyboard shows while dictating: the phrase so far (finished turns plus the one in progress). */
class DictationTurns {
    private val finals = mutableListOf<String>()
    private var partial = ""

    @Synchronized
    fun on(event: SttEvent) {
        when (event) {
            is SttEvent.Partial -> partial = event.text.trim()

            is SttEvent.Final -> {
                if (event.text.isNotBlank()) finals += event.text.trim()
                partial = ""
            }

            else -> Unit
        }
    }

    @Synchronized
    fun finished(): List<String> = finals.toList()

    @Synchronized
    fun preview(): String = (finals + partial).filter { it.isNotEmpty() }.joinToString(" ")
}

enum class DictationPhase { Idle, Connecting, Listening, Finishing }

data class DictationUi(
    val phase: DictationPhase = DictationPhase.Idle,
    val preview: String = "",
    val level: Float = 0f,
    val message: String? = null
) {
    val active: Boolean get() = phase != DictationPhase.Idle
}

/**
 * One dictation from the voice keyboard: microphone → provider stream → text. [finish] hands the composed text to
 * [onText] (the keyboard types it into the focused field), saves it in the history and reports the streamed time.
 * Nothing is recorded or uploaded, and no Keep / Discard prompt ever appears.
 */
class DictationSession(private val context: Context, private val scope: CoroutineScope, private val onText: (String) -> Unit) {
    private val graph get() = (context.applicationContext as EchoLedgerApp).graph
    private val _ui = MutableStateFlow(DictationUi())
    val ui: StateFlow<DictationUi> = _ui.asStateFlow()

    @Volatile private var stopRequested = false

    @Volatile private var reading = false
    private var reader: Thread? = null
    private var streamer: SttStreamer? = null
    private var provider: String? = null
    private var turns = DictationTurns()
    private var tones: ToneGenerator? = null

    fun hasMicPermission(): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED

    fun start() {
        if (_ui.value.active) return
        stopRequested = false
        turns = DictationTurns()
        _ui.value = DictationUi(DictationPhase.Connecting)
        scope.launch {
            val settings = graph.settings.current()
            val api = graph.api()
            val message = when {
                api == null -> DictationTokens.NOT_PAIRED
                !hasMicPermission() -> MIC_PERMISSION
                else -> null
            }
            if (message != null || api == null) return@launch fail(message ?: DictationTokens.NOT_PAIRED)
            val outcome = DictationTokens(api).fetch(settings.clientId) { graph.settings.update { it.copy(clientId = null) } }
            if (outcome is DictationTokens.Outcome.Refused) return@launch fail(outcome.message)
            val token = (outcome as DictationTokens.Outcome.Token).token
            if (stopRequested) return@launch idle() // let go before the connection was ready: nothing to insert
            val grant = token.grants.first { it.protocol in DictationTokens.PROTOCOLS }
            provider = token.provider
            streamer = SttStreamer(grant, ::onEvent).also { it.start() }
            if (!openMic()) {
                streamer?.stop()
                streamer = null
                return@launch fail(MIC_UNAVAILABLE)
            }
            beep(settings, start = true)
            _ui.update { it.copy(phase = DictationPhase.Listening) }
        }
    }

    private fun onEvent(event: SttEvent) {
        turns.on(event)
        if (event is SttEvent.Error &&
            _ui.value.phase == DictationPhase.Listening
        ) {
            _ui.update { it.copy(message = DictationTokens.UNAVAILABLE) }
        }
        _ui.update { it.copy(preview = turns.preview()) }
    }

    @SuppressLint("MissingPermission") // checked in start()
    private fun openMic(): Boolean {
        val rate = Pcm.STT_RATE
        val record = runCatching {
            AudioRecord.Builder()
                .setAudioSource(MediaRecorder.AudioSource.VOICE_RECOGNITION)
                .setAudioFormat(
                    AudioFormat.Builder().setSampleRate(rate).setChannelMask(AudioFormat.CHANNEL_IN_MONO)
                        .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
                )
                .setBufferSizeInBytes(AudioRecord.getMinBufferSize(rate, AudioFormat.CHANNEL_IN_MONO, AudioFormat.ENCODING_PCM_16BIT) * 4)
                .build()
        }.getOrNull() ?: return false
        if (record.state != AudioRecord.STATE_INITIALIZED) {
            record.release()
            return false
        }
        reading = true
        reader = thread(name = "el-dictation-mic") {
            val shorts = ShortArray(rate / 10)
            var lastLevel = 0L
            try {
                record.startRecording()
                while (reading) {
                    val n = record.read(shorts, 0, shorts.size)
                    if (n <= 0) continue
                    val chunk = shorts.copyOf(n)
                    streamer?.sendPcm(Pcm.toLittleEndianBytes(chunk))
                    val now = System.currentTimeMillis()
                    if (now - lastLevel >= LEVEL_MS) {
                        lastLevel = now
                        val level = Pcm.rms(chunk)
                        _ui.update { it.copy(level = level) }
                    }
                }
            } finally {
                runCatching { record.stop() }
                record.release()
            }
        }
        return true
    }

    /** Stops listening and inserts the text; called again while connecting, it cancels once the connection is ready. */
    fun finish() {
        when (_ui.value.phase) {
            DictationPhase.Connecting -> stopRequested = true
            DictationPhase.Listening -> scope.launch { complete(insert = true) }
            else -> Unit
        }
    }

    /** Stops without inserting anything (the keyboard was closed). */
    fun cancel() {
        stopRequested = true
        if (_ui.value.phase == DictationPhase.Listening) scope.launch { complete(insert = false) }
    }

    private suspend fun complete(insert: Boolean) {
        _ui.update { it.copy(phase = DictationPhase.Finishing, level = 0f) }
        reading = false
        withContext(Dispatchers.IO) { reader?.join(2_000) }
        reader = null
        val stream = streamer
        stream?.stop()
        delay(FLUSH_MS) // the provider sends the last turn
        streamer = null
        val settings = graph.settings.current()
        beep(settings, start = false)
        report(stream, settings)
        val text = DictationText.compose(
            turns.finished(),
            settings.dictationCleanup,
            settings.dictationReplacements,
            settings.dictationSnippets
        )
        if (insert && text.isNotBlank()) {
            DictationHistory.of(context).add(Instant.now().toString(), text)
            withContext(Dispatchers.Main) { onText(text) }
        }
        idle()
    }

    private fun report(stream: SttStreamer?, settings: AppSettings) {
        if (stream == null || !stream.opened() || !settings.serverIsV1_1) return
        UsageWorker.report(
            context,
            UsageReport(
                reportId = UUID.randomUUID().toString(),
                useCase = "dictation",
                provider = provider ?: "assemblyai",
                streamedMs = stream.streamedMs().coerceAtMost(86_400_000),
                channels = 1,
                endedAt = Instant.now().toString()
            )
        )
    }

    private fun beep(settings: AppSettings, start: Boolean) {
        if (!settings.dictationSounds) return
        runCatching {
            val generator = tones ?: ToneGenerator(AudioManager.STREAM_NOTIFICATION, 60).also { tones = it }
            generator.startTone(if (start) ToneGenerator.TONE_PROP_BEEP else ToneGenerator.TONE_PROP_BEEP2, 120)
        }
    }

    private fun fail(message: String) {
        _ui.value = DictationUi(message = message)
    }

    private fun idle() {
        _ui.value = DictationUi()
    }

    fun release() {
        cancel()
        tones?.release()
        tones = null
    }

    companion object {
        const val LEVEL_MS = 100L
        const val FLUSH_MS = 900L
        const val MIC_PERMISSION = "Allow the microphone in EchoLedger to dictate"
        const val MIC_UNAVAILABLE = "The microphone is busy or unavailable"
    }
}
