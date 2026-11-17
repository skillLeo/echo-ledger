package com.echoledger.mobile.ui.v2.setup

import android.content.Context

/**
 * How the capture helper was started on this phone, so the wizard knows after a restart, when the helper is gone, which
 * method to bring back. The phone's core keeps it at every connection (ReadinessWatch, Readiness.helperMethod); this
 * reads it in the wizard's own terms.
 */
object HelperMethodMemory {
    fun method(source: String?): HelperMethod? = when (source) {
        "shizuku" -> HelperMethod.Shizuku
        "adb" -> HelperMethod.Computer
        else -> null
    }

    /** How the helper was last started, as the core remembers it (ReadinessWatch, Readiness.helperMethod). */
    fun last(context: Context): HelperMethod? = com.echoledger.mobile.recording.ReadinessWatch.lastMethod(context)?.let { core ->
        HelperMethod.entries.firstOrNull {
            it.name ==
                core.name
        }
    }
}
