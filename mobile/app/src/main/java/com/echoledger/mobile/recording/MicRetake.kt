package com.echoledger.mobile.recording

/**
 * When the helper takes the microphone again during an app call (pure, unit-tested), fed every raw read of the
 * microphone in use (not the paired output, whose gaps are silence too):
 * - digital silence (every sample 0) for [deadMs] while the phone's microphone is muted is the call app's own mute
 *   ("You muted yourself"): nothing is retaken, and the same recorder carries on when the sound comes back;
 * - digital silence while not muted is Android silencing our recorder: a new one is opened BESIDE the working one and
 *   used only once it gives sound within [probeMs]; otherwise it is dropped and the old one kept. Android 17 does not
 *   let a new capture become active while the call app's own capture runs, so releasing first loses my side for good.
 * Quiet sound (any sample not 0) is never retaken: it is a real microphone.
 */
class MicRetake(
    rate: Int,
    deadMs: Int = 1500,
    private val probeMs: Long = 1_000,
    private val gapMs: Long = 5_000,
    private val maxTries: Int = 6
) {
    enum class Step { NONE, MUTED, UNMUTED, OPEN_CANDIDATE, GIVE_UP }

    enum class Probe { WAIT, SWAP, DROP }

    private val deadFrames = rate.toLong() * deadMs / 1000
    private var zeroRun = 0L
    private var tries = 0
    private var lastTry = Long.MIN_VALUE
    private var gaveUp = false
    private var probeUntil = 0L

    /** Whether the phone's microphone was muted the last time the silence was checked. */
    var muted = false
        private set

    /** Whether a new recorder is open beside the working one, waiting to give sound. */
    var probing = false
        private set

    /** Retakes tried in this call. */
    val tried: Int get() = tries

    /** One read of the microphone in use: [zeros] when every sample was 0; [isMuted] is asked only once silence is long. */
    fun mic(frames: Int, zeros: Boolean, nowMs: Long, isMuted: () -> Boolean): Step {
        if (frames <= 0) return Step.NONE
        if (!zeros) {
            zeroRun = 0
            if (muted) {
                muted = false
                return Step.UNMUTED
            }
            return Step.NONE
        }
        zeroRun += frames
        if (zeroRun < deadFrames || probing) return Step.NONE
        if (isMuted()) {
            zeroRun = 0
            if (muted) return Step.NONE
            muted = true
            return Step.MUTED
        }
        if (tries >= maxTries) {
            if (gaveUp) return Step.NONE
            gaveUp = true
            return Step.GIVE_UP
        }
        if (lastTry != Long.MIN_VALUE && nowMs - lastTry < gapMs) return Step.NONE
        tries++
        lastTry = nowMs
        probing = true
        probeUntil = nowMs + probeMs
        return Step.OPEN_CANDIDATE
    }

    /** The new recorder could not be opened: the working one stays. */
    fun candidateFailed() {
        probing = false
    }

    /** One read of the new recorder ([frames] may be 0: nothing yet): SWAP once it gives sound, DROP after [probeMs]. */
    fun candidate(frames: Int, zeros: Boolean, nowMs: Long): Probe {
        if (!probing) return Probe.DROP
        if (frames > 0 && !zeros) {
            probing = false
            zeroRun = 0
            return Probe.SWAP
        }
        if (nowMs >= probeUntil) {
            probing = false
            return Probe.DROP
        }
        return Probe.WAIT
    }
}
