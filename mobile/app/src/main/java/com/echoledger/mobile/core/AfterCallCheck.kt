package com.echoledger.mobile.core

/**
 * The checks around a call (since v1.5, Phase 1C A5). [backupDue]: a call was detected but nothing records it 5 s later,
 * so recording starts by itself. [oneSided]: after a call both sides are checked; a two-sided call whose other side
 * stayed silent says "Only your voice was recorded", why, and how to fix it.
 */
object AfterCallCheck {
    const val BACKUP_AFTER_MS = 5_000L

    /** Below this peak the other side was silence (digital zero or a muted line), not a quiet voice. */
    const val SILENT_PEAK_DB = -70.0

    fun backupDue(callStartedAtMs: Long, nowMs: Long, recording: Boolean, helperReady: Boolean, alreadyTried: Boolean): Boolean =
        !recording && helperReady && !alreadyTried && nowMs - callStartedAtMs >= BACKUP_AFTER_MS

    /**
     * Why only your voice was recorded, or null when both sides were heard (or the call was one track by design, or
     * nothing was heard at all: that is the "no sound" case). [helperReason]: what the helper said during the call.
     */
    fun oneSided(channels: Int, myPeakDb: Double, otherPeakDb: Double, helperReason: String?): String? {
        if (channels != 2 || myPeakDb <= SILENT_PEAK_DB || otherPeakDb > SILENT_PEAK_DB) return null
        val why = helperReason?.takeIf { it.isNotBlank() }?.trim()?.removeSuffix(".") ?: "The other side gave no sound"
        return "$why. Run the Ready check: restart the capture helper and test a call. If it happens again, " +
            "make sure the call app is not on speaker through a Bluetooth device that keeps the sound to itself."
    }
}
