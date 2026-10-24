package com.echoledger.mobile.recording

import kotlin.math.roundToInt
import kotlin.math.sqrt

/**
 * PCM helpers (pure, unit-tested). Capture runs at 48 kHz 16-bit; stereo frames are L = mic ("Me"),
 * R = far party ("Client"). Live captions need 16 kHz mono per channel.
 */
object Pcm {
    const val CAPTURE_RATE = 48_000
    const val STT_RATE = 16_000

    /** Interleaves two mono 16-bit buffers (shorter one padded with silence). */
    fun interleave(left: ShortArray, right: ShortArray): ShortArray {
        val frames = maxOf(left.size, right.size)
        val out = ShortArray(frames * 2)
        for (i in 0 until frames) {
            out[2 * i] = if (i < left.size) left[i] else 0
            out[2 * i + 1] = if (i < right.size) right[i] else 0
        }
        return out
    }

    /** Splits interleaved stereo into (left, right). */
    fun deinterleave(stereo: ShortArray): Pair<ShortArray, ShortArray> {
        val frames = stereo.size / 2
        val left = ShortArray(frames)
        val right = ShortArray(frames)
        for (i in 0 until frames) {
            left[i] = stereo[2 * i]
            right[i] = stereo[2 * i + 1]
        }
        return left to right
    }

    /** 48 kHz → 16 kHz by averaging groups of 3 samples (box low-pass + decimation; adequate for speech STT). */
    fun downsample48To16(input: ShortArray): ShortArray {
        val out = ShortArray(input.size / 3)
        for (i in out.indices) {
            val sum = input[3 * i] + input[3 * i + 1] + input[3 * i + 2]
            out[i] = (sum / 3).toShort()
        }
        return out
    }

    fun toLittleEndianBytes(samples: ShortArray): ByteArray {
        val bytes = ByteArray(samples.size * 2)
        for (i in samples.indices) {
            val v = samples[i].toInt()
            bytes[2 * i] = (v and 0xFF).toByte()
            bytes[2 * i + 1] = ((v shr 8) and 0xFF).toByte()
        }
        return bytes
    }

    fun fromLittleEndianBytes(bytes: ByteArray, length: Int = bytes.size): ShortArray {
        val out = ShortArray(length / 2)
        for (i in out.indices) {
            out[i] = ((bytes[2 * i].toInt() and 0xFF) or (bytes[2 * i + 1].toInt() shl 8)).toShort()
        }
        return out
    }

    /** RMS level in [0, 1] for the level meter / silence detection. */
    fun rms(samples: ShortArray): Float {
        if (samples.isEmpty()) return 0f
        var acc = 0.0
        for (s in samples) acc += (s.toDouble() / Short.MAX_VALUE).let { it * it }
        return sqrt(acc / samples.size).toFloat()
    }

    fun durationMs(frames: Long, rate: Int = CAPTURE_RATE): Long = (frames * 1000.0 / rate).roundToInt().toLong()
}
