package com.echoledger.mobile.core

/**
 * The 11 kinds of notification (since v1.5, Phase 1C): one Android channel each, the same list in Settings →
 * Notifications on the phone and the desktop, and the email preferences on the web. Recording has a second Android
 * channel for the question after a call, which must pop up while "Recording now" stays quiet.
 * [critical]: says that recording stopped working, so quiet hours never hold it back.
 */
enum class NotifyCategory(
    val key: String,
    val channelId: String,
    val title: String,
    val description: String,
    val level: Level,
    val critical: Boolean = false,
    val testTitle: String,
    val testBody: String
) {
    RECORDING(
        "recording",
        "recording",
        "Recording",
        "Recording now, and the question after a call",
        Level.LOW,
        testTitle = "Recording call with Omar",
        testBody = "This is how a recording looks. Nothing was recorded."
    ),
    TRANSCRIPTION(
        "transcription",
        "transcription",
        "Transcription",
        "A transcript is ready, or could not be made",
        Level.DEFAULT,
        testTitle = "Transcript ready",
        testBody = "WhatsApp call with Omar · 4:12"
    ),
    ENHANCEMENT(
        "enhancement",
        "enhancement",
        "Enhancement",
        "Roman Urdu and other views are ready",
        Level.LOW,
        testTitle = "Roman Urdu is ready",
        testBody = "WhatsApp call with Omar"
    ),
    UPLOADS(
        "uploads",
        "uploads",
        "Uploads",
        "A recording could not be uploaded",
        Level.DEFAULT,
        testTitle = "Upload failed",
        testBody = "It will try again when the phone is online."
    ),
    READINESS(
        "readiness",
        "readiness",
        "Call capture & readiness",
        "Recording calls stopped working, and how to fix it. Comes through even in quiet hours.",
        Level.HIGH,
        critical = true,
        testTitle = "Calls are not being recorded",
        testBody = "This is a test. A real alert says what stopped and has a one-tap fix."
    ),
    SHARING(
        "sharing",
        "sharing",
        "Sharing",
        "Someone shared a recording or project with you",
        Level.DEFAULT,
        testTitle = "Ana shared a recording",
        testBody = "Weekly review · 32:10"
    ),
    ACCOUNT(
        "account",
        "account",
        "Account & security",
        "A new device signed in, and other account changes",
        Level.DEFAULT,
        testTitle = "New device signed in",
        testBody = "Pixel 9 · just now"
    ),
    USAGE(
        "usage",
        "usage",
        "Usage & limits",
        "Close to or at the monthly limit",
        Level.DEFAULT,
        testTitle = "80% of this month's minutes used",
        testBody = "240 of 300 minutes"
    ),
    LIVE_TRANSLATE(
        "live_translate",
        "live_translate",
        "Live Translate",
        "Live Translate is running",
        Level.LOW,
        testTitle = "Live Translate",
        testBody = "Translating Spanish to English"
    ),
    UPDATES(
        "updates",
        "updates",
        "App updates",
        "A new version of EchoLedger",
        Level.LOW,
        testTitle = "EchoLedger 1.5 is ready",
        testBody = "Tap to update."
    ),
    OTHER(
        "other",
        "other",
        "Other",
        "Everything else",
        Level.DEFAULT,
        testTitle = "EchoLedger",
        testBody = "This is a test notification."
    );

    enum class Level { LOW, DEFAULT, HIGH }

    companion object {
        /** The Android channel of the question after a call (part of Recording; it pops up but never makes a sound). */
        const val AFTER_CALL_CHANNEL = "decisions_headsup"

        /** The single channel of earlier versions for everything from the server. */
        const val OLD_ACTIVITY_CHANNEL = "activity"

        fun of(key: String): NotifyCategory? = entries.firstOrNull { it.key == key }

        /** The category of an activity item from the server (`GET /api/activity` `kind`); unknown kinds are Other. */
        fun forActivity(kind: String?): NotifyCategory = when (kind) {
            "transcript_ready", "transcript_failed" -> TRANSCRIPTION
            "views_ready", "view_ready", "enhancement_ready" -> ENHANCEMENT
            "upload_failed" -> UPLOADS
            "shared_with_you", "invitation" -> SHARING
            "device_paired", "sign_in", "password_changed", "two_factor" -> ACCOUNT
            "budget_warn", "budget_reached" -> USAGE
            else -> OTHER
        }

        /**
         * Whether a notification of [category] is shown now: its switch is on, and it is not quiet hours unless it is
         * critical. [off]: the keys switched off in Settings → Notifications; [now]: minutes after midnight.
         */
        fun allowed(
            category: NotifyCategory,
            off: Collection<String>,
            quietHours: Boolean,
            quietFrom: String,
            quietTo: String,
            now: Int
        ): Boolean = when {
            category.key in off -> false
            category.critical -> true
            else -> !Activity.inQuietHours(quietHours, quietFrom, quietTo, now)
        }
    }
}
