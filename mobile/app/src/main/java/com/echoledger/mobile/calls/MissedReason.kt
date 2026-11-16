package com.echoledger.mobile.calls

/**
 * Why a call was not recorded (since v1.8, P0 0.2; contract 1.8.0-b `POST /api/devices/missed-recordings` `reason`).
 * [phrase] is the server's reason phrase for the Activity item; [detail] the plain sentence the app sends and shows;
 * [fix] where the item's button goes. Pure.
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

        /**
         * The reason in what the capture helper said when it could not record ("Call capture could not start: mic: did not
         * start; …"): a recorder Android would not start, or one silenced, is the microphone in use elsewhere; a refusal
         * that names a permission is [PERMISSION]; anything else is [UNKNOWN].
         */
        fun fromProblem(message: String?): MissedReason {
            val text = message.orEmpty().lowercase()
            return when {
                PERMISSION_WORDS.any { it in text } -> PERMISSION
                BUSY_WORDS.any { it in text } -> MIC_BUSY
                else -> UNKNOWN
            }
        }

        private val PERMISSION_WORDS = listOf("permission", "securityexception", "not allowed", "denied")
        private val BUSY_WORDS = listOf("did not start", "silenced", "in use", "busy")
    }
}

/** Where a missed recording's button goes: the Ready check, the call test in Setup, or Plan & usage. */
enum class MissedFix(val label: String) {
    START_HELPER("Start the helper"),
    PERMISSIONS("Check permissions"),
    TEST("Test a call"),
    CHECK("Check call capture"),
    PLANS("See plans")
}
