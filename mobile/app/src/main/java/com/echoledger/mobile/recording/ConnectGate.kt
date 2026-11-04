package com.echoledger.mobile.recording

import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.sin
import kotlin.math.sqrt

/**
 * Since v1.5 a call recording starts when the call is answered, not when it starts ringing (pure, unit-tested).
 *
 * An outgoing call (WhatsApp or SIM) turns the phone's audio to call mode as soon as it is dialled, so the capture
 * starts on the ringback. The gate holds the audio in memory while the call rings and lets it through from a moment
 * before the answer: [PRE_ROLL_MS] before it, but never earlier than the end of the last ringback tone. Nothing is lost:
 * after [MAX_WAIT_MS] without an answer signal everything is let through from the start, and [finish] hands back what is
 * still held so a call that was never answered can still be restored for a while.
 *
 * Answer signals: the call app's notification (its timer starts at the answer: [openAt]), the call log's talk time
 * after a SIM call ([finish] with the answer position), or the audio itself: the other side speaking (not a tone), or
 * this side speaking for a while when no ringback tone has been heard for [QUIET_TONE_MS].
 *
 * Positions are milliseconds of captured audio from the start of the capture. [accept] runs on the reader thread;
 * [openAt] may come from any thread.
 */
class ConnectGate(private val channels: Int, private val rate: Int = Pcm.CAPTURE_RATE, private val maxWaitMs: Long = MAX_WAIT_MS) {
    /** Why the gate opened, for the capture log; null while it waits. */
    @Volatile var openedBy: String? = null
        private set

    /** Where the kept audio starts, in ms of captured audio; null while the gate waits. */
    @Volatile var keptFromMs: Long? = null
        private set

    /** Where the answer was seen, in ms of captured audio; null while the gate waits or when it opened without one. */
    @Volatile var answeredAtMs: Long? = null
        private set

    val waiting: Boolean get() = openedBy == null

    /** The call was answered: an answer was seen, or it lasted past [maxWaitMs] (no call rings that long). */
    val answered: Boolean get() = answeredAtMs != null || timedOut

    @Volatile private var timedOut = false

    private val frameBytes = 2 * channels
    private val held = ArrayList<ByteArray>()
    private var heldBytes = 0L
    private var pendingOpenMs: Long? = null
    private var pendingReason: String? = null
    private val signal = CallSignal(rate)
    private var framesSeen = 0L

    /** Opens the gate at [positionMs] of captured audio (the answer), e.g. from the call notification's timer. */
    @Synchronized
    fun openAt(positionMs: Long, reason: String) {
        if (!waiting || pendingOpenMs != null) return
        pendingOpenMs = positionMs.coerceAtLeast(0)
        pendingReason = reason
    }

    /**
     * Takes [length] bytes of interleaved 16-bit PCM. Returns what goes into the recording now: null while the call
     * rings, the held audio from just before the answer plus these bytes when the answer is found, else the bytes.
     */
    @Synchronized
    fun accept(bytes: ByteArray, length: Int): ByteArray? {
        if (!waiting) return bytes.copyOf(length)
        held += bytes.copyOf(length)
        heldBytes += length
        val samples = Pcm.fromLittleEndianBytes(bytes, length)
        framesSeen += samples.size / channels
        signal.add(samples, channels)
        pendingOpenMs?.let { at ->
            if (at <= positionMs()) return open(at, pendingReason ?: "answer", answered = true)
        }
        signal.answeredAtMs()?.let { at -> return open(at, signal.reason, answered = true) }
        if (positionMs() >= maxWaitMs) {
            timedOut = true
            return open(0, "no answer signal within ${maxWaitMs / 1000} s: kept from the start", answered = false)
        }
        return null
    }

    /**
     * The end of the capture: what is still held, from [answeredAtMs] when it is known now (the call log's talk time of
     * a SIM call), else everything (the call was not answered, or the answer was never seen). Empty when already open.
     */
    @Synchronized
    fun finish(answeredAtMs: Long? = null): ByteArray {
        if (!waiting) return ByteArray(0)
        return if (answeredAtMs != null) {
            open(answeredAtMs.coerceIn(0, positionMs()), "call log talk time", answered = true)
        } else {
            open(0, "never answered", answered = false)
        }
    }

    private fun positionMs(): Long = framesSeen * 1000 / rate

    private fun open(atMs: Long, reason: String, answered: Boolean): ByteArray {
        val from = if (answered) maxOf(atMs - PRE_ROLL_MS, signal.lastToneEndMs ?: 0L).coerceIn(0, atMs) else 0L
        val fromByte = (from * rate / 1000) * frameBytes
        val out = java.io.ByteArrayOutputStream((heldBytes - fromByte).coerceAtLeast(0).toInt())
        var offset = 0L
        for (chunk in held) {
            val end = offset + chunk.size
            if (end > fromByte) {
                val skip = (fromByte - offset).coerceAtLeast(0).toInt()
                out.write(chunk, skip, chunk.size - skip)
            }
            offset = end
        }
        held.clear()
        heldBytes = 0
        keptFromMs = from
        this.answeredAtMs = if (answered) atMs else null
        openedBy = reason
        return out.toByteArray()
    }

    companion object {
        /** Kept before the answer, so a first "hello" is never cut. */
        const val PRE_ROLL_MS = 3_000L

        /** Longer than any app or network rings a call: past this everything is kept. */
        const val MAX_WAIT_MS = 90_000L

        /** This side speaking only counts as an answer when no ringback tone was heard for this long. */
        const val QUIET_TONE_MS = 6_000L
    }
}

/**
 * Ringback tone or speech, per 20 ms of audio (pure). The other side's channel (the only one of a one-track phone line)
 * is checked for speech; ringback tones are one or two pure frequencies, so almost all of a frame's energy sits in one or
 * two narrow peaks of its spectrum, while speech spreads over many. This side's channel only counts when the other side
 * is quiet and no tone was heard for a while (an answered call where the other person waits for you to speak).
 */
class CallSignal(private val rate: Int = Pcm.CAPTURE_RATE) {
    enum class Kind { QUIET, TONE, SPEECH, UNSURE }

    /** End of the last tone frame, in ms of captured audio; null before any tone. */
    var lastToneEndMs: Long? = null
        private set

    /** What found the answer, for the capture log. */
    var reason: String = ""
        private set

    private val frame = rate / 50
    private val far = ShortArray(frame)
    private val near = ShortArray(frame)
    private var filled = 0
    private var frames = 0L
    private val farKinds = ArrayDeque<Kind>()
    private val nearKinds = ArrayDeque<Kind>()
    private var answer: Long? = null

    fun answeredAtMs(): Long? = answer

    /** Adds interleaved samples of [channels] channels (channel 0 = this side, 1 = the other side). */
    fun add(samples: ShortArray, channels: Int) {
        if (answer != null) return
        val n = samples.size / channels
        for (i in 0 until n) {
            if (channels == 2) {
                near[filled] = samples[i * 2]
                far[filled] = samples[i * 2 + 1]
            } else {
                far[filled] = samples[i]
                near[filled] = 0
            }
            filled++
            if (filled == frame) {
                filled = 0
                onFrame(channels)
                if (answer != null) return
            }
        }
    }

    private fun onFrame(channels: Int) {
        val startMs = frames * 20
        frames++
        val farKind = classify(far)
        if (farKind == Kind.TONE) lastToneEndMs = startMs + 20
        push(farKinds, farKind)
        push(nearKinds, if (channels == 2) classify(near) else Kind.QUIET)
        // The other side talking: 300 ms of speech within 400 ms (a ringback never looks like that).
        if (farKinds.count { it == Kind.SPEECH } >= FAR_SPEECH_FRAMES) {
            answer = windowStartMs(startMs, farKinds) + 20L * farKinds.indexOfFirst { it == Kind.SPEECH }
            reason = "the other side spoke"
            return
        }
        val toneQuiet = lastToneEndMs?.let { startMs - it >= ConnectGate.QUIET_TONE_MS } ?: (startMs >= ConnectGate.QUIET_TONE_MS)
        if (toneQuiet && nearKinds.count { it == Kind.SPEECH } >= NEAR_SPEECH_FRAMES && farKinds.none { it == Kind.TONE }) {
            answer = windowStartMs(startMs, nearKinds) + 20L * nearKinds.indexOfFirst { it == Kind.SPEECH }
            reason = "you spoke and no ringback was heard"
        }
    }

    /** Start of the oldest frame in [queue], whose newest frame starts at [newestStartMs]. */
    private fun windowStartMs(newestStartMs: Long, queue: ArrayDeque<Kind>): Long = newestStartMs - (queue.size - 1) * 20L

    private fun push(queue: ArrayDeque<Kind>, kind: Kind) {
        queue.addLast(kind)
        while (queue.size > WINDOW) queue.removeFirst()
    }

    /** One 20 ms frame: quiet, a tone, speech, or unsure. */
    fun classify(samples: ShortArray): Kind {
        var sum = 0.0
        for (s in samples) sum += (s / 32768.0) * (s / 32768.0)
        val rms = sqrt(sum / samples.size)
        if (rms < SPEECH_RMS) return Kind.QUIET
        val share = peakShare(samples)
        return when {
            share >= TONE_SHARE -> Kind.TONE
            share <= SPEECH_SHARE -> Kind.SPEECH
            else -> Kind.UNSURE
        }
    }

    /**
     * Share of the frame's 250-3500 Hz energy in its two strongest narrow peaks (each three bins of a 512-point spectrum
     * at 16 kHz, about 94 Hz wide): near 1 for a ringback tone, well below a half for speech.
     */
    fun peakShare(samples: ShortArray): Double {
        val step = (rate / 16_000).coerceAtLeast(1)
        val re = DoubleArray(FFT)
        val im = DoubleArray(FFT)
        val count = minOf(samples.size / step, FFT)
        for (i in 0 until count) {
            var acc = 0.0
            for (j in 0 until step) acc += samples[i * step + j]
            val hann = 0.5 - 0.5 * cos(2 * PI * i / (count - 1).coerceAtLeast(1))
            re[i] = acc / step * hann
        }
        fft(re, im)
        val binHz = 16_000.0 / FFT
        val lo = (250 / binHz).toInt()
        val hi = (3500 / binHz).toInt()
        val power = DoubleArray(hi + 2) { k -> if (k < lo || k > hi) 0.0 else re[k] * re[k] + im[k] * im[k] }
        var total = 0.0
        for (k in lo..hi) total += power[k]
        if (total <= 0.0) return 0.0
        fun band(k: Int) = power[k - 1] + power[k] + power[k + 1]
        var first = lo + 1
        for (k in lo + 1 until hi) if (band(k) > band(first)) first = k
        var second = -1
        for (k in lo + 1 until hi) {
            if (kotlin.math.abs(k - first) < 3) continue
            if (second < 0 || band(k) > band(second)) second = k
        }
        val top = band(first) + (if (second >= 0) band(second) else 0.0)
        return (top / total).coerceAtMost(1.0)
    }

    private fun fft(re: DoubleArray, im: DoubleArray) {
        val n = re.size
        var j = 0
        for (i in 1 until n) {
            var bit = n shr 1
            while (j and bit != 0) {
                j = j xor bit
                bit = bit shr 1
            }
            j = j xor bit
            if (i < j) {
                re[i] = re[j].also { re[j] = re[i] }
                im[i] = im[j].also { im[j] = im[i] }
            }
        }
        var len = 2
        while (len <= n) {
            val angle = -2 * PI / len
            for (start in 0 until n step len) {
                for (k in 0 until len / 2) {
                    val wr = cos(angle * k)
                    val wi = sin(angle * k)
                    val ar = re[start + k + len / 2]
                    val ai = im[start + k + len / 2]
                    val tr = ar * wr - ai * wi
                    val ti = ar * wi + ai * wr
                    re[start + k + len / 2] = re[start + k] - tr
                    im[start + k + len / 2] = im[start + k] - ti
                    re[start + k] += tr
                    im[start + k] += ti
                }
            }
            len = len shl 1
        }
    }

    companion object {
        /** About -44 dBFS: quieter frames are not speech (and not a tone worth noting). */
        const val SPEECH_RMS = 0.006

        const val TONE_SHARE = 0.80
        const val SPEECH_SHARE = 0.55
        const val WINDOW = 20
        const val FAR_SPEECH_FRAMES = 15

        /** This side: 360 ms of speech within 400 ms. */
        const val NEAR_SPEECH_FRAMES = 18
        private const val FFT = 512
    }
}
