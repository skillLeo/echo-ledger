package com.echoledger.mobile.translate

import kotlin.math.sqrt

/**
 * Decides which 100 ms frames go to Soniox (plan B1). Soniox bills the time a stream is open, so audio is sent only
 * while someone speaks: an energy gate over a tracked noise floor, with [PRE_ROLL_FRAMES] of audio from just before
 * the speech so the first syllable is not cut, and [HANG_FRAMES] after it so pauses between words stay in.
 *
 * [mixed] is for sound with game effects or music under the voice: a frame must also look like speech (its zero
 * crossing rate in the range of voiced speech), which drops most explosions, engines and hiss at almost no cost.
 */
class SpeechGate(private val mixed: Boolean = false) {
    data class Result(val frames: List<ShortArray>, val speaking: Boolean, val onset: Boolean)

    private val preRoll = ArrayDeque<ShortArray>()
    private var noiseFloor = INITIAL_FLOOR
    private var hang = 0
    var speaking = false
        private set

    /** Frames since the last speech frame (100 ms each); the stream is closed when this passes the mode's limit. */
    var quietFrames = 0
        private set

    fun offer(frame: ShortArray): Result {
        val level = rms(frame)
        val loud = level > maxOf(MIN_SPEECH_RMS, noiseFloor * SPEECH_OVER_FLOOR)
        val voiced = loud && (!mixed || looksVoiced(frame))
        // The floor follows quiet frames quickly and loud ones very slowly (a long shout does not raise it).
        noiseFloor = if (level < noiseFloor) noiseFloor * 0.8 + level * 0.2 else noiseFloor * 0.995 + level * 0.005
        noiseFloor = noiseFloor.coerceIn(MIN_FLOOR, MAX_FLOOR)
        return if (voiced) {
            quietFrames = 0
            hang = HANG_FRAMES
            if (!speaking) {
                speaking = true
                val out = preRoll.toList() + frame
                preRoll.clear()
                Result(out, speaking = true, onset = true)
            } else {
                Result(listOf(frame), speaking = true, onset = false)
            }
        } else {
            quietFrames++
            if (speaking && hang > 0) {
                hang--
                Result(listOf(frame), speaking = true, onset = false)
            } else {
                speaking = false
                preRoll.addLast(frame)
                while (preRoll.size > PRE_ROLL_FRAMES) preRoll.removeFirst()
                Result(emptyList(), speaking = false, onset = false)
            }
        }
    }

    companion object {
        const val FRAME_SAMPLES = 1_600 // 100 ms at 16 kHz
        const val PRE_ROLL_FRAMES = 3 // 300 ms
        const val HANG_FRAMES = 6 // 600 ms
        private const val INITIAL_FLOOR = 150.0
        private const val MIN_FLOOR = 20.0
        private const val MAX_FLOOR = 4_000.0
        private const val MIN_SPEECH_RMS = 250.0
        private const val SPEECH_OVER_FLOOR = 2.5

        fun rms(frame: ShortArray): Double {
            if (frame.isEmpty()) return 0.0
            var sum = 0.0
            for (s in frame) sum += s.toDouble() * s
            return sqrt(sum / frame.size)
        }

        /** Voiced speech crosses zero roughly 400-3000 times a second at 16 kHz; noise and hiss far more, rumble far less. */
        fun looksVoiced(frame: ShortArray): Boolean {
            if (frame.size < 2) return false
            var crossings = 0
            for (i in 1 until frame.size) if ((frame[i - 1] >= 0) != (frame[i] >= 0)) crossings++
            val perSecond = crossings * 16_000.0 / frame.size
            return perSecond in 300.0..3_200.0
        }
    }
}
