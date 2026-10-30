package com.echoledger.mobile.translate

import android.content.Context
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import java.io.File
import java.io.RandomAccessFile
import java.util.Locale
import kotlin.coroutines.resume
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull

/**
 * "Test translate" (plan B3): a short sentence spoken by the phone's own text-to-speech, written to a WAV file and fed
 * to Live Translate like a teammate's voice, so the box can be tuned without opening a game.
 */
object TestTranslate {
    /** What the test says: Arabic for Game translate (teammates), English for Watch translate (a film). */
    fun sentence(mode: TranslateMode): Pair<Locale, String> = if (mode == TranslateMode.GAME) {
        Locale.forLanguageTag("ar") to "يلا يا شباب، العدو عند البيت الأحمر. انتبهوا على اليمين."
    } else {
        Locale.ENGLISH to "We have to leave before sunrise. The road is long, and nobody can follow us."
    }

    /** Synthesises [text] to a WAV in the cache; null when the phone has no voice for [locale]. */
    suspend fun synthesize(context: Context, locale: Locale, text: String): File? {
        val tts = withTimeoutOrNull(8_000) {
            suspendCancellableCoroutine<TextToSpeech?> { cont ->
                var engine: TextToSpeech? = null
                engine = TextToSpeech(context.applicationContext) { status ->
                    if (cont.isActive) cont.resume(if (status == TextToSpeech.SUCCESS) engine else null)
                }
            }
        } ?: return null
        try {
            val available = tts.isLanguageAvailable(locale)
            if (available < TextToSpeech.LANG_AVAILABLE) return null
            tts.language = locale
            val file = File(context.cacheDir, "test-translate.wav")
            val done = withTimeoutOrNull(15_000) {
                suspendCancellableCoroutine<Boolean> { cont ->
                    tts.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
                        override fun onStart(utteranceId: String?) = Unit

                        override fun onDone(utteranceId: String?) {
                            if (cont.isActive) cont.resume(true)
                        }

                        @Deprecated("Deprecated in Java")
                        override fun onError(utteranceId: String?) {
                            if (cont.isActive) cont.resume(false)
                        }
                    })
                    tts.synthesizeToFile(text, null, file, "test")
                }
            } == true
            return file.takeIf { done && it.length() > 44 }
        } finally {
            tts.shutdown()
        }
    }

    /** The WAV's 16-bit mono samples at 16 kHz in 100 ms frames (other rates are resampled linearly). */
    fun frames(wav: File): List<ShortArray> {
        val (rate, channels, samples) = readWav(wav) ?: return emptyList()
        val mono = if (channels == 1) samples else ShortArray(samples.size / channels) { i -> samples[i * channels] }
        val resampled = if (rate == 16_000) mono else resample(mono, rate, 16_000)
        return resampled.toList().chunked(SpeechGate.FRAME_SAMPLES).map { chunk ->
            ShortArray(SpeechGate.FRAME_SAMPLES) { i -> chunk.getOrElse(i) { 0 } }
        }
    }

    fun resample(input: ShortArray, from: Int, to: Int): ShortArray {
        if (input.isEmpty() || from <= 0) return ShortArray(0)
        val out = ShortArray((input.size.toLong() * to / from).toInt())
        for (i in out.indices) {
            val pos = i.toDouble() * from / to
            val a = pos.toInt().coerceAtMost(input.size - 1)
            val b = (a + 1).coerceAtMost(input.size - 1)
            val f = pos - a
            out[i] = (input[a] * (1 - f) + input[b] * f).toInt().toShort()
        }
        return out
    }

    /** A plain PCM16 WAV: (sample rate, channels, samples); null for anything else. */
    fun readWav(file: File): Triple<Int, Int, ShortArray>? = runCatching {
        RandomAccessFile(file, "r").use { f ->
            val header = ByteArray(12)
            f.readFully(header)
            if (String(header, 0, 4) != "RIFF" || String(header, 8, 4) != "WAVE") return null
            var rate = 0
            var channels = 0
            var bits = 0
            while (f.filePointer < f.length()) {
                val chunk = ByteArray(8)
                f.readFully(chunk)
                val id = String(chunk, 0, 4)
                val size = (chunk[4].toInt() and 0xff) or ((chunk[5].toInt() and 0xff) shl 8) or ((chunk[6].toInt() and 0xff) shl 16) or
                    ((chunk[7].toInt() and 0xff) shl 24)
                when (id) {
                    "fmt " -> {
                        val fmt = ByteArray(size)
                        f.readFully(fmt)
                        channels = (fmt[2].toInt() and 0xff) or ((fmt[3].toInt() and 0xff) shl 8)
                        rate = (fmt[4].toInt() and 0xff) or ((fmt[5].toInt() and 0xff) shl 8) or ((fmt[6].toInt() and 0xff) shl 16) or
                            ((fmt[7].toInt() and 0xff) shl 24)
                        bits = (fmt[14].toInt() and 0xff) or ((fmt[15].toInt() and 0xff) shl 8)
                    }

                    "data" -> {
                        if (bits != 16 || channels <= 0 || rate <= 0) return null
                        val length = minOf(size.toLong(), f.length() - f.filePointer).toInt()
                        val bytes = ByteArray(length)
                        f.readFully(bytes)
                        val samples =
                            ShortArray(length / 2) { i -> ((bytes[2 * i].toInt() and 0xff) or (bytes[2 * i + 1].toInt() shl 8)).toShort() }
                        return Triple(rate, channels, samples)
                    }

                    else -> f.seek(f.filePointer + size + (size and 1))
                }
            }
            null
        }
    }.getOrNull()
}
