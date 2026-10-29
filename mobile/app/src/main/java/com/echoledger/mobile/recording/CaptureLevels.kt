package com.echoledger.mobile.recording

import android.content.Context
import com.echoledger.mobile.BuildConfig
import java.io.File
import java.io.RandomAccessFile
import kotlin.math.log10
import kotlin.math.sqrt

/**
 * Per-channel level of a whole recording (pure, unit-tested): RMS and peak in dBFS, so the capture log can say whether
 * each side really carried sound ("me -31 / -4 dBFS, other -27 / -2 dBFS"). Never keeps any audio.
 */
class CaptureLevels(private val channels: Int) {
    private val sumSquares = DoubleArray(channels)
    private val peaks = IntArray(channels)
    private var frames = 0L

    /** Adds interleaved 16-bit samples of [channels] channels. */
    fun add(samples: ShortArray) {
        val n = samples.size / channels
        for (i in 0 until n) {
            for (c in 0 until channels) {
                val v = samples[i * channels + c].toInt()
                sumSquares[c] += v.toDouble() * v
                val a = if (v < 0) -v else v
                if (a > peaks[c]) peaks[c] = a
            }
        }
        frames += n
    }

    /** Every channel was digital silence (nothing above ±2): the recording holds no sound to transcribe. */
    fun silent(): Boolean = peaks.all { it <= 2 }

    fun rmsDb(channel: Int): Double = db(if (frames == 0L) 0.0 else sqrt(sumSquares[channel] / frames))

    fun peakDb(channel: Int): Double = db(peaks[channel].toDouble())

    /** One line for the capture log, e.g. "me rms -31.2 peak -4.0 dBFS; other rms -27.5 peak -2.1 dBFS". */
    fun describe(): String {
        val names = if (channels == 2) listOf("me", "other") else listOf("both")
        return names.indices.joinToString("; ") { c ->
            "${names[c]} rms ${"%.1f".format(rmsDb(c))} peak ${"%.1f".format(peakDb(c))} dBFS"
        }
    }

    private fun db(value: Double): Double = if (value <= 0.0) -120.0 else 20 * log10(value / 32768.0)
}

/**
 * Debug builds only, and only when `files/debug-keep-plain` exists (made over adb with run-as): the PCM given to the
 * encoder, kept as a WAV in `files/debug-recordings/` so a device test can pull it and measure each side. Release builds
 * never write it.
 */
class DebugWav private constructor(private val file: RandomAccessFile, private val channels: Int, private val rate: Int) {
    private var bytes = 0L

    fun write(pcm: ByteArray, length: Int) {
        runCatching {
            file.write(pcm, 0, length)
            bytes += length
        }
    }

    fun close() {
        runCatching {
            file.seek(0)
            file.write(header(bytes, channels, rate))
            file.close()
        }
    }

    companion object {
        fun open(context: Context, id: String, channels: Int, rate: Int): DebugWav? {
            if (!BuildConfig.DEBUG || !File(context.filesDir, "debug-keep-plain").exists()) return null
            return runCatching {
                val dir = File(context.filesDir, "debug-recordings").apply { mkdirs() }
                val out = RandomAccessFile(File(dir, "$id.wav"), "rw")
                out.setLength(0)
                out.write(header(0, channels, rate))
                DebugWav(out, channels, rate)
            }.getOrNull()
        }

        /** A 44-byte PCM WAV header for [dataBytes] of 16-bit audio. */
        fun header(dataBytes: Long, channels: Int, rate: Int): ByteArray {
            val b = java.nio.ByteBuffer.allocate(44).order(java.nio.ByteOrder.LITTLE_ENDIAN)
            b.put("RIFF".toByteArray()).putInt((36 + dataBytes).toInt()).put("WAVE".toByteArray())
            b.put("fmt ".toByteArray()).putInt(16).putShort(1).putShort(channels.toShort()).putInt(rate)
            b.putInt(rate * channels * 2).putShort((channels * 2).toShort()).putShort(16)
            b.put("data".toByteArray()).putInt(dataBytes.toInt())
            return b.array()
        }
    }
}
