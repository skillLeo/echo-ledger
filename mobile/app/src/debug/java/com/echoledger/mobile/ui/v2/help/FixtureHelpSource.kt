package com.echoledger.mobile.ui.v2.help

/** Help as a server would answer it (debug builds and tests): three guides, two releases, sends that are kept. */
class FixtureHelpSource(private val lastReport: String? = null, private val failing: HelpResult.Failed? = null) : HelpSource {
    val sent = mutableListOf<Triple<String, String, Boolean>>()
    val searched = mutableListOf<String>()

    private val all = listOf(
        Guide(
            "after-a-call",
            "After a call",
            """
            # After a call

            When a call ends, a card asks whether to **keep** it. Tap Keep, or let it keep itself after `10 s`.

            1. Keep or Delete
            2. Transcribe, now or later
               (later from Library)

            | Button | What it does |
            |---|---|
            | Keep | Saves the recording |
            | Delete | Moves it to Trash |

            > Recordings you delete stay in Trash for 48 hours.

            Read more in [the full guide](https://echoledger.example/guide).
            """.trimIndent()
        ),
        Guide("record-whatsapp", "Recording WhatsApp calls", "Turn on the capture helper once; after a restart it takes one tap."),
        Guide("urdu-transcripts", "Urdu and Roman Urdu transcripts", "Pick the language in Settings, or let it be found.")
    )

    override suspend fun guides(query: String): HelpResult<List<GuideHit>> {
        failing?.let { return it }
        searched += query
        val q = query.lowercase()
        return HelpResult.Ok(
            all.filter { q.isBlank() || it.title.lowercase().contains(q) || it.markdown.lowercase().contains(q) }
                // As the server's excerpt: plain words, no Markdown marks.
                .map { g ->
                    val first = g.markdown.lines().firstOrNull { l -> l.isNotBlank() && !l.startsWith("#") }
                    GuideHit(g.id, g.title, first?.replace("**", "")?.replace("`", ""))
                }
        )
    }

    override suspend fun guide(id: String): HelpResult<Guide> =
        failing ?: all.firstOrNull { it.id == id }?.let { HelpResult.Ok(it) } ?: HelpResult.Failed(false, "That guide doesn't exist.")

    override suspend fun whatsNew(): HelpResult<List<ReleaseNotes>> = failing ?: HelpResult.Ok(
        listOf(
            ReleaseNotes(
                "2.0.0",
                "2026-11-17T06:00:00+00:00",
                "- **Help** inside the app\n- Screens from your meetings on the recording page"
            ),
            ReleaseNotes("1.9.0", "2026-11-03T06:00:00+00:00", "Live Assist on calls recorded on this phone.")
        )
    )

    override suspend fun send(subject: String, message: String, includeReport: Boolean): HelpResult<Unit> {
        failing?.let { return it }
        sent += Triple(subject, message, includeReport)
        return HelpResult.Ok(Unit)
    }

    override fun lastReportAt(): String? = lastReport
}
