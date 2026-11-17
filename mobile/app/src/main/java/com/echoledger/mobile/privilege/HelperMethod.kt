package com.echoledger.mobile.privilege

/**
 * How the capture helper was started on this phone (since v1.8.1): through Shizuku on the phone itself, or by the
 * setup command from a computer. Remembered at every connection ([com.echoledger.mobile.recording.ReadinessWatch]), so
 * after a restart, when the helper is gone, the phone knows which way to bring it back.
 */
enum class HelperMethod {
    Shizuku,
    Computer;

    companion object {
        /** From [HelperConnection.State.source]: "shizuku" or "adb"; null for anything else. */
        fun of(source: String?): HelperMethod? = when (source) {
            "shizuku" -> Shizuku
            "adb" -> Computer
            else -> null
        }
    }
}
