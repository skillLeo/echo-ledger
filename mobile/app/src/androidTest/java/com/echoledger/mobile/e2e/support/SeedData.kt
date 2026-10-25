package com.echoledger.mobile.e2e.support

/**
 * A small library produced by "another device" (a desktop) through the real API: two good stereo transcripts, one the
 * provider failed and one without speech. Created once per run and re-created when a test wiped the server.
 */
object SeedData {
    const val GOOD_ONE = "Seeded zoom 1"
    const val GOOD_TWO = "Seeded zoom 2"
    const val FAILED = "Seeded google_meet 1"
    const val SILENT = "Seeded whatsapp_business 1"

    /** The conversation the stand-in speech provider returns for stereo audio. */
    val CONVERSATION = listOf(
        Triple("0:00", "Me", "This is a test of the EchoLedger transcription system."),
        Triple("0:04", "Client", "Loud and clear. Go ahead."),
        Triple("0:06", "Me", "Please call me back tomorrow at three thirty."),
        Triple("0:08", "Client", "Will do, thanks.")
    )

    private val expectedTitles = listOf(GOOD_ONE, GOOD_TWO, FAILED, SILENT)

    @Synchronized
    fun ensure() {
        Backend.dropSessionsTitled("Seeded other 1")
        val live = Backend.liveSessions()
        val present = live.mapNotNull { it.title }.toSet()
        val complete = expectedTitles.all { it in present } &&
            live.filter { it.title in expectedTitles }.all { it.finalStatus == "completed" || it.finalStatus == "failed" }
        if (complete) return
        Backend.wipeSessions()
        Backend.seedDesktopSessions(2, "zoom")
        Backend.seedDesktopSessions(1, "google_meet", providerMode = "fail")
        Backend.seedDesktopSessions(1, "whatsapp_business", providerMode = "empty")
        // seed-session numbers its titles per call, so the two zoom sessions are "Seeded zoom 1" and "Seeded zoom 2" only
        // when created together, as above.
    }
}
