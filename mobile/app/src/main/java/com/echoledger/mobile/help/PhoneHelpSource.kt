package com.echoledger.mobile.help

import android.content.Context
import com.echoledger.mobile.core.Problems
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.ui.v2.help.Guide
import com.echoledger.mobile.ui.v2.help.GuideHit
import com.echoledger.mobile.ui.v2.help.HelpResult
import com.echoledger.mobile.ui.v2.help.HelpSource
import com.echoledger.mobile.ui.v2.help.ReleaseNotes
import kotlinx.coroutines.CancellationException

/**
 * Help v2 on the phone's own API client (contract 2.0.0-d): the user guide's sections, Android's release notes, a
 * message to support, and when this phone last sent its device report. A failure is [HelpResult.Failed]: `offline`
 * when the server can't be reached, else plain words (the server's own when written for people, never a status code).
 */
class PhoneHelpSource(private val api: suspend () -> ApiClient?, private val reports: DeviceReports) : HelpSource {
    override suspend fun guides(query: String): HelpResult<List<GuideHit>> = help(GUIDES_FAILED) { client ->
        client.helpGuides(query.trim().ifBlank { null }).data.map { GuideHit(it.id, it.title, it.excerpt?.ifBlank { null }) }
    }

    override suspend fun guide(id: String): HelpResult<Guide> = help(GUIDE_FAILED) { client ->
        client.helpGuide(id).let { Guide(it.id, it.title, it.markdown) }
    }

    override suspend fun whatsNew(): HelpResult<List<ReleaseNotes>> = help(WHATS_NEW_FAILED) { client ->
        client.whatsNew("android").data.map { ReleaseNotes(it.version, it.releasedAt, it.notes) }
    }

    override suspend fun send(subject: String, message: String, includeReport: Boolean): HelpResult<Unit> = help(SEND_FAILED) { client ->
        client.sendSupportMessage(subject, message, includeReport && reports.lastAt() != null)
    }

    override fun lastReportAt(): String? = reports.lastAt()

    private suspend fun <T> help(failed: String, block: suspend (ApiClient) -> T): HelpResult<T> = try {
        HelpResult.Ok(block(api() ?: return HelpResult.Failed(offline = false, words = Problems.SIGN_IN)))
    } catch (e: CancellationException) {
        throw e
    } catch (e: Exception) {
        failure(e, failed)
    }

    companion object {
        const val GUIDES_FAILED = "The guides could not be loaded. Try again in a minute."
        const val GUIDE_FAILED = "This guide could not be opened. Try again in a minute."
        const val WHATS_NEW_FAILED = "What's new could not be loaded. Try again in a minute."
        const val SEND_FAILED = "Your message could not be sent. Try again in a minute."
        const val TOO_MANY = "You have sent 5 messages in the last hour. Try again later, or write to support by email."
        const val NOT_VERIFIED = "Confirm your email address first: the link is in the email we sent you. Then send your message."
        const val BUSY = "The server is busy right now. Try again in a minute."
        const val NOT_FOUND = "This guide is no longer there."

        /** Offline, or the words for people: by the server's code first, then [Problems]. */
        internal fun <T> failure(e: Throwable, failed: String): HelpResult<T> {
            val refused = e as? ApiException
            val words = when {
                refused == null -> Problems.text(e, failed)
                refused.status == 429 -> TOO_MANY
                refused.code == "email_not_verified" -> NOT_VERIFIED
                refused.code == "server_busy" || refused.status == 503 -> BUSY
                refused.status == 404 -> NOT_FOUND
                else -> Problems.text(e, failed)
            }
            return HelpResult.Failed(offline = refused == null && words == Problems.OFFLINE, words = words)
        }
    }
}

/** When this phone last sent its device report (`POST /api/devices/report`), kept across restarts, as an ISO time. */
class DeviceReports(context: Context, private val now: () -> java.time.Instant = java.time.Instant::now) {
    private val prefs = context.applicationContext.getSharedPreferences("device_reports", Context.MODE_PRIVATE)

    fun lastAt(): String? = prefs.getString(KEY, null)

    /** The report reached the server. */
    fun sent() {
        prefs.edit().putString(KEY, now().toString()).apply()
    }

    private companion object {
        const val KEY = "last_sent_at"
    }
}
