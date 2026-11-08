package com.echoledger.mobile.testsupport

import com.echoledger.mobile.core.Readiness
import com.echoledger.mobile.core.ReadyChip
import com.echoledger.mobile.core.ReadyFix
import com.echoledger.mobile.core.ReadyProblem

/** A Ready check as the Library shows it: all well, or the helper gone after a restart. */
object ReadinessFixtures {
    val READY = Readiness(emptyList(), ReadyChip.READY)

    val HELPER_STOPPED = Readiness(
        listOf(
            ReadyProblem(
                "helper_after_restart",
                "The helper stopped when the phone restarted",
                "Open Shizuku, tap Start, then come back.",
                ReadyFix.OPEN_SHIZUKU,
                blocking = true,
                critical = true
            )
        ),
        ReadyChip.NOT_READY
    )
}
