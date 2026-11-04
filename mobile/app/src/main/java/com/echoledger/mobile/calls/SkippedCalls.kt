package com.echoledger.mobile.calls

import com.echoledger.mobile.data.LocalRecording
import java.time.Duration
import java.time.Instant

/**
 * Calls that are skipped quietly (since v1.5, pure, unit-tested): a call that was never answered, one shorter than the
 * "Skip calls shorter than" setting, and one where nobody spoke on either side. Nothing asks and nothing uploads; the
 * recording waits on the phone for [KEEP] so Activity's "Skipped 3 short calls" can still restore it, then it goes.
 * Only automatic call recordings are ever skipped: a recording started by hand is always kept.
 */
object SkippedCalls {
    enum class Reason(val wire: String, val label: String) {
        NOT_ANSWERED("not_answered", "Not answered"),
        TOO_SHORT("too_short", "Too short"),
        NO_SPEECH("no_speech", "No speech")
    }

    /** How long a skipped call can still be restored. */
    val KEEP: Duration = Duration.ofHours(24)

    /** The "Skip calls shorter than" choices in seconds (0 = never skip a short call); 10 by default. */
    val SHORTER_THAN_CHOICES = listOf(0, 5, 10, 20, 30)
    const val DEFAULT_SHORTER_THAN_S = 10

    /** Less speech than this on each side counts as nobody speaking. */
    const val MIN_SPEECH_MS = 1_000L

    /**
     * Why a finished recording is skipped, or null to keep it. [answered] is false when the call never showed an
     * answer; [keptMs] is what the recording holds from the answer on; [speechMs] the speech heard per side.
     */
    fun reason(fromCall: Boolean, answered: Boolean, keptMs: Long, shorterThanS: Int, speechMs: List<Long>): Reason? = when {
        !fromCall -> null
        !answered -> Reason.NOT_ANSWERED
        shorterThanS > 0 && keptMs < shorterThanS * 1000L -> Reason.TOO_SHORT
        speechMs.isNotEmpty() && speechMs.all { it < MIN_SPEECH_MS } -> Reason.NO_SPEECH
        else -> null
    }

    fun reasonOf(rec: LocalRecording): Reason? = Reason.entries.firstOrNull { it.wire == rec.skippedReason }

    /** Skipped recordings past [KEEP], to delete. */
    fun expired(all: List<LocalRecording>, now: Instant = Instant.now()): List<LocalRecording> = all.filter { rec ->
        rec.state == STATE && (runCatching { Instant.parse(rec.skippedAt) }.getOrNull() ?: Instant.EPOCH).plus(KEEP) <= now
    }

    /** "Skipped 3 short calls" / "Skipped 1 short call". */
    fun line(count: Int): String = if (count == 1) "Skipped 1 short call" else "Skipped $count short calls"

    /** The setting's label: "Never" or "10 s". */
    fun shorterThanLabel(seconds: Int): String = if (seconds <= 0) "Never" else "$seconds s"

    const val STATE = "skipped"
}

/**
 * Speech per side of a recording (pure): 20 ms frames louder than about -44 dBFS, so room hiss and digital silence
 * do not count. Fed with the audio that is kept.
 */
class SpeechMeter(private val channels: Int, rate: Int = com.echoledger.mobile.recording.Pcm.CAPTURE_RATE) {
    private val frame = rate / 50
    private val sums = DoubleArray(channels)
    private var filled = 0
    private val speechFrames = LongArray(channels)

    fun add(samples: ShortArray) {
        val n = samples.size / channels
        for (i in 0 until n) {
            for (c in 0 until channels) {
                val v = samples[i * channels + c] / 32768.0
                sums[c] += v * v
            }
            filled++
            if (filled == frame) {
                for (c in 0 until channels) {
                    if (kotlin.math.sqrt(sums[c] / frame) >= SPEECH_RMS) speechFrames[c]++
                    sums[c] = 0.0
                }
                filled = 0
            }
        }
    }

    /** Milliseconds of speech heard per side. */
    fun speechMs(): List<Long> = speechFrames.map { it * 20 }

    private companion object {
        const val SPEECH_RMS = 0.006
    }
}
