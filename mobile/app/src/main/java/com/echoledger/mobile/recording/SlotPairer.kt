package com.echoledger.mobile.recording

/**
 * Pairs the two sides of a call by capture time, not by arrival (pure, unit-tested). Each chunk carries the monotonic
 * time its first sample was captured; the timeline is cut into slots of [slotMs] and a stereo slot (L = me, R = the
 * other side) is released once both sides have reached it, or once one side is [maxLagMs] behind the other (its part
 * of the slot is then silence). A side's chunks stay back to back while their times agree within [jitterMs]; a larger
 * jump forward is a gap (filled with silence), a jump back an overlap (the newer samples replace what was written
 * there). A jump beyond [maxGapMs] is not believed (a recorder's clock glitch): the side simply continues. So the sides
 * stay in sync however unevenly the two recorders deliver, and memory stays bounded.
 */
class SlotPairer(private val rate: Int, slotMs: Int = 20, jitterMs: Int = 30, maxLagMs: Int = 400, maxGapMs: Int = 2_000) {
    val slotFrames = rate * slotMs / 1000
    private val jitter = rate.toLong() * jitterMs / 1000
    private val maxLag = rate * maxLagMs / 1000
    private val maxGap = rate.toLong() * maxGapMs / 1000

    private class Lane {
        /** Absolute frame of the next sample this side writes; -1 before its first chunk. */
        var cursor = -1L
        var data = ShortArray(8192)

        /** Samples held from the first unreleased frame on (index 0 = frame [released]). */
        var filled = 0
        var gapFrames = 0L
        var lateFrames = 0L
    }

    private val lanes = arrayOf(Lane(), Lane())
    private var originNs = Long.MIN_VALUE
    private var released = 0L

    /** Frames released so far (both sides). */
    val releasedFrames: Long get() = released

    /** Silence put in for a side's gaps, in ms (diagnostics). */
    fun gapMs(side: Int): Long = lanes[side].gapFrames * 1000 / rate

    /** A side's samples dropped because their slot had already been written, in ms (diagnostics). */
    fun lateMs(side: Int): Long = lanes[side].lateFrames * 1000 / rate

    /** [side] 0 = me, 1 = the other side; [timeNs] = when samples[0] was captured (monotonic clock). */
    fun push(side: Int, timeNs: Long, samples: ShortArray, count: Int = samples.size) {
        if (count <= 0) return
        if (originNs == Long.MIN_VALUE) originNs = timeNs
        var at = frameAt(timeNs)
        if (at < 0 && released == 0L && -at <= rate) {
            // This side's first sample came before the first one seen at all: start the timeline there.
            shiftOrigin(-at)
            at = 0
        }
        val lane = lanes[side]
        val drift = if (lane.cursor < 0) 0L else at - lane.cursor
        val start = when {
            lane.cursor < 0 -> at.coerceAtMost(released + maxGap)

            drift > maxGap || drift < -maxGap -> lane.cursor

            drift > jitter -> {
                lane.gapFrames += drift
                at
            }

            drift < -jitter -> at

            else -> lane.cursor
        }
        write(lane, start, samples, count)
    }

    /** Interleaved stereo of every whole slot ready to write; empty when none is. */
    fun drain(): ShortArray {
        val a = lanes[0].filled
        val b = lanes[1].filled
        var ready = maxOf(minOf(a, b), maxOf(a, b) - maxLag)
        ready -= ready % slotFrames
        return if (ready > 0) release(ready) else EMPTY
    }

    /** Everything still held (the end of a recording), the shorter side padded with silence. */
    fun flush(): ShortArray {
        val frames = maxOf(lanes[0].filled, lanes[1].filled)
        return if (frames > 0) release(frames) else EMPTY
    }

    private fun frameAt(timeNs: Long): Long = Math.floorDiv((timeNs - originNs) * rate + HALF_SECOND_NS, NS_PER_SECOND)

    private fun shiftOrigin(frames: Long) {
        originNs -= frames * NS_PER_SECOND / rate
        val n = frames.toInt()
        for (lane in lanes) {
            if (lane.cursor < 0) continue
            ensure(lane, lane.filled + n)
            System.arraycopy(lane.data, 0, lane.data, n, lane.filled)
            java.util.Arrays.fill(lane.data, 0, n, 0)
            lane.filled += n
            lane.cursor += frames
        }
    }

    private fun write(lane: Lane, start: Long, samples: ShortArray, count: Int) {
        var from = 0
        var at = start
        if (at < released) {
            val late = minOf(released - at, count.toLong()).toInt()
            lane.lateFrames += late
            from = late
            at = released
        }
        lane.cursor = start + count
        val n = count - from
        if (n <= 0) return
        val offset = (at - released).toInt()
        ensure(lane, offset + n)
        if (offset > lane.filled) java.util.Arrays.fill(lane.data, lane.filled, offset, 0)
        System.arraycopy(samples, from, lane.data, offset, n)
        lane.filled = maxOf(lane.filled, offset + n)
    }

    private fun ensure(lane: Lane, size: Int) {
        if (size <= lane.data.size) return
        lane.data = lane.data.copyOf(maxOf(size, lane.data.size * 2))
    }

    private fun release(frames: Int): ShortArray {
        val out = ShortArray(frames * 2)
        for ((index, lane) in lanes.withIndex()) {
            val have = minOf(lane.filled, frames)
            for (i in 0 until have) out[2 * i + index] = lane.data[i]
            if (lane.filled > frames) {
                System.arraycopy(lane.data, frames, lane.data, 0, lane.filled - frames)
                lane.filled -= frames
            } else {
                lane.filled = 0
            }
        }
        released += frames
        return out
    }

    private companion object {
        val EMPTY = ShortArray(0)
        const val NS_PER_SECOND = 1_000_000_000L
        const val HALF_SECOND_NS = NS_PER_SECOND / 2
    }
}

/**
 * Silence check of the other side for call capture (pure, unit-tested), fed the paired stereo blocks: the other side
 * digitally silent since the call began while I talked for [talkMs]: it is not being captured, so the helper says why
 * instead of leaving a one-sided recording ([farSilent], true once). A real call never gives exact zeros for long.
 * My own microphone is watched on its raw reads ([MicRetake]).
 */
class SilenceWatch(private val rate: Int, talkMs: Int = 8000, private val talkLevel: Int = 330) {
    private val talkFrames = rate.toLong() * talkMs / 1000
    private var talked = 0L
    private var farSilentSaid = false

    /** Whether the other side has given any sound in this call. */
    var farHeard = false
        private set

    /** Whether my side has given any sound in this call. */
    var micHeard = false
        private set

    /** How long I have talked in this call, in ms. */
    val talkedMs: Long get() = talked * 1000 / rate

    fun feed(stereo: ShortArray) {
        val frames = stereo.size / 2
        if (frames == 0) return
        var block = 0
        while (block < frames) {
            val end = minOf(frames, block + rate / 50)
            var micZero = true
            var energy = 0.0
            for (i in block until end) {
                val me = stereo[2 * i].toInt()
                if (me != 0) micZero = false
                energy += me.toDouble() * me
                if (!farHeard && kotlin.math.abs(stereo[2 * i + 1].toInt()) > 2) farHeard = true
            }
            val n = end - block
            if (!micZero) micHeard = true
            if (kotlin.math.sqrt(energy / n) >= talkLevel) talked += n
            block = end
        }
    }

    /** True once: the other side never gave a sound while I talked for long enough. */
    fun farSilent(): Boolean {
        if (farSilentSaid || farHeard || talked < talkFrames) return false
        farSilentSaid = true
        return true
    }
}
