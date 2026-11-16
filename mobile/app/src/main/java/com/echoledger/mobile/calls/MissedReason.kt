package com.echoledger.mobile.calls

/**
 * Why a call was not recorded (since v1.8, P0 0.2; contract 1.8.0-e `POST /api/devices/missed-recordings` `reason`).
 * [phrase] is the server's reason phrase for its Activity item; [detail] the plain sentence the phone shows (on the phone
 * only: it is never sent, the server keeps no free text); [fix] where the item's button goes. Pure.
 */
enum class MissedReason(val wire: String, val phrase: String, val detail: String, val fix: MissedFix) {
    HELPER_NOT_ARMED(
        "helper_not_armed",
        "The helper was not connected",
        "The helper was not connected when the call started.",
        MissedFix.START_HELPER
    ),
    PERMISSION("permission", "A permission was off", "A permission EchoLedger needs to record calls was off.", MissedFix.PERMISSIONS),
    DETECTION("detection", "The call was not detected", "Auto-record was on, but this call was not detected.", MissedFix.TEST),
    MIC_BUSY(
        "mic_busy",
        "Another app was using the microphone",
        "Another app was using the microphone, so the call could not be recorded.",
        MissedFix.TEST
    ),
    CRASH("crash", "The app stopped during the call", "EchoLedger stopped during the call, so the recording was lost.", MissedFix.CHECK),
    PLAN("plan", "Your plan did not allow the recording", "Your plan or role did not allow recording calls.", MissedFix.PLANS),
    UNKNOWN("unknown", "The reason is not known", "The call was not recorded, and the reason is not known.", MissedFix.CHECK);

    companion object {
        fun of(wire: String?): MissedReason = entries.firstOrNull { it.wire == wire } ?: UNKNOWN

        /** A phone line that would not open (the phone keeps it for its own Phone app): the reason is not a known one. */
        const val PHONE_LINE = "The phone line could not be opened, so the call was not recorded."

        /**
         * The reason in what the capture helper said when it could not record ("Call capture could not start: mic: did not
         * start; …"): a refusal that names a permission is [PERMISSION]; a phone line that did not open is [UNKNOWN] (the
         * microphone is not in use then); a microphone Android would not start, or one silenced, is the microphone in use
         * elsewhere; anything else is [UNKNOWN].
         */
        fun fromProblem(message: String?): MissedReason {
            val text = message.orEmpty().lowercase()
            return when {
                PERMISSION_WORDS.any { it in text } -> PERMISSION
                LINE_WORDS.any { it in text } -> UNKNOWN
                BUSY_WORDS.any { it in text } -> MIC_BUSY
                else -> UNKNOWN
            }
        }

        /** The reason and the sentence for [message]: a phone line that would not open says so in its own words. */
        fun ofProblem(message: String?): Pair<MissedReason, String> {
            val reason = fromProblem(message)
            val line = reason == UNKNOWN && LINE_WORDS.any { it in message.orEmpty().lowercase() }
            return reason to if (line) PHONE_LINE else reason.detail
        }

        private val PERMISSION_WORDS = listOf("permission", "securityexception", "not allowed", "denied")
        private val LINE_WORDS = listOf("phone line")
        private val BUSY_WORDS = listOf("did not start", "silenced", "in use", "busy")
    }
}

/** Where a missed recording's button goes: the Ready check (or one of its fixes), the call test in Setup, or Plan & usage. */
enum class MissedFix(val label: String) {
    START_HELPER("Start the helper"),
    PERMISSIONS("Check permissions"),
    TEST("Test a call"),
    CHECK("Check call capture"),
    PLANS("See plans"),

    /** Auto-record was not running: the Ready check turns it on at once. */
    TURN_ON("Turn on");

    companion object {
        fun of(name: String?): MissedFix? = entries.firstOrNull { it.name == name }
    }
}
