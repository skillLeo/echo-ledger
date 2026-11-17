package com.echoledger.mobile.ui.v2.help

/** A guide in the list: its id, title and a line of what it covers. */
data class GuideHit(val id: String, val title: String, val excerpt: String?)

/** A guide to read: its title and Markdown. */
data class Guide(val id: String, val title: String, val markdown: String)

/** One Android release's notes (Markdown). */
data class ReleaseNotes(val version: String, val releasedAt: String?, val notes: String)

/** What a help call gave: the value, or why not in words for people (offline or the server's plain sentence). */
sealed interface HelpResult<out T> {
    data class Ok<T>(val value: T) : HelpResult<T>
    data class Failed(val offline: Boolean, val words: String?) : HelpResult<Nothing>
}

/**
 * The typed seam between Help v2 and the phone core (contract 2.0.0-d, v1.8 P6.4). Help only reads and sends through
 * this; a fixture plays it in the debug build and the tests.
 *
 * What the core must provide (to be routed to the phone core):
 * - [guides]: `GET /api/help/guides?q=` (without q when [query] is blank): `{"data": [{"id","title","excerpt"}]}`.
 * - [guide]: `GET /api/help/guides/{id}`: `{"id","title","markdown"}`; 404 → Failed with the server's words.
 * - [whatsNew]: `GET /api/help/whats-new?platform=android`: `{"data": [{"version","platform","released_at","notes"}]}`.
 * - [send]: `POST /api/support/messages` `{"subject" ≤ 120, "message" ≤ 5000, "include_device_report"}` → 202;
 *   `429` (5 an hour) as Failed with plain words.
 * - [lastReportAt]: when this phone last sent its device report (`POST /api/devices/report`, the phone check's Send
 *   to my admin), as an ISO time, or null when it never has: Contact support says exactly which report goes with the
 *   message, and offers none when there isn't one. The core keeps that time when the report is sent.
 * - Failures as [HelpResult.Failed]: `offline` when the server can't be reached; else the server's plain words.
 */
interface HelpSource {
    suspend fun guides(query: String): HelpResult<List<GuideHit>>

    suspend fun guide(id: String): HelpResult<Guide>

    suspend fun whatsNew(): HelpResult<List<ReleaseNotes>>

    suspend fun send(subject: String, message: String, includeReport: Boolean): HelpResult<Unit>

    fun lastReportAt(): String?
}
