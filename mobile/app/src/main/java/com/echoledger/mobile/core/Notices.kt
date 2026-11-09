package com.echoledger.mobile.core

import kotlin.math.abs

/**
 * One rule for every in-app notice (since v1.6.1, docs/DESIGN_SYSTEM.md "Notices"): tap it to go where it is about,
 * × to close, a timer for messages, swipe left or right to close. This is the pure part; unit-tested.
 */
enum class NoticeKind { INFO, SUCCESS, ERROR }

/** Where a notice goes when it is tapped. */
sealed interface NoticeTarget {
    data class Recording(val sessionId: String) : NoticeTarget

    /** A Settings page by its catalogue id ("plan", "devices", "notifications"…). */
    data class Settings(val entry: String) : NoticeTarget

    data object Library : NoticeTarget

    data object Ready : NoticeTarget

    /** Android's own notification settings for this app. */
    data object SystemNotifications : NoticeTarget
}

data class Notice(val text: String, val kind: NoticeKind = Notices.kindOf(text), val target: NoticeTarget? = null) {
    /** How long it stays when nothing holds it: 6 s, an error 10 s. */
    val durationMs: Long get() = if (kind == NoticeKind.ERROR) Notices.ERROR_MS else Notices.MESSAGE_MS
}

object Notices {
    const val MESSAGE_MS = 6_000L
    const val ERROR_MS = 10_000L

    /** Past this share of its width a dragged notice leaves; less springs back. */
    const val SWIPE_SHARE = 1f / 3f

    /** A flick this fast (px/s) leaves whatever the distance. */
    const val FLICK_PX_PER_S = 1_200f

    /** A move shorter than this (px) is still a tap. */
    const val TAP_SLOP_PX = 12f

    private val CONFIRMATIONS = listOf(
        "Saved", "Copied", "Restored", "Renamed", "Added", "Moved", "Signed in", "Shared", "Sent", "Erased", "Removed",
        "Settings imported", "Settings saved", "Welcome", "Stopped sharing", "Upload cancelled", "Device revoked"
    )
    private val PROBLEMS = listOf(
        "could not", "couldn't", "can't", "cannot", "failed", "is not running", "not reach", "no audio", "first",
        "signed out on the server", "not for this sign-in", "too many", "is missing"
    )

    /** "Saved" is a success; "The export failed." an error (it stays longer); the rest information. */
    fun kindOf(text: String): NoticeKind {
        val t = text.trim()
        val lower = t.lowercase()
        return when {
            CONFIRMATIONS.any { t.startsWith(it) } -> NoticeKind.SUCCESS
            PROBLEMS.any { it in lower } -> NoticeKind.ERROR
            else -> NoticeKind.INFO
        }
    }

    /** Whether a drag that ended at [offsetPx] with [velocityPxPerS] closes a notice [widthPx] wide. */
    fun swipeCloses(offsetPx: Float, widthPx: Float, velocityPxPerS: Float = 0f): Boolean =
        widthPx > 0f && (abs(offsetPx) >= widthPx * SWIPE_SHARE || (abs(velocityPxPerS) >= FLICK_PX_PER_S && abs(offsetPx) > TAP_SLOP_PX))

    /** What is left of a notice's time after [elapsedMs] of running (holding it does not count). */
    fun remaining(durationMs: Long, elapsedMs: Long): Long = (durationMs - elapsedMs).coerceAtLeast(0)

    /** The timer bar's length, 1 → 0. */
    fun barFraction(durationMs: Long, elapsedMs: Long): Float =
        if (durationMs <= 0) 0f else remaining(durationMs, elapsedMs).toFloat() / durationMs
}
