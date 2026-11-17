package com.echoledger.mobile.help

import android.content.Context
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Problems
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.net.EMPTY_ANSWER
import com.echoledger.mobile.net.UNREADABLE_ANSWER
import com.echoledger.mobile.ui.v2.help.Guide
import com.echoledger.mobile.ui.v2.help.GuideHit
import com.echoledger.mobile.ui.v2.help.HelpResult
import com.echoledger.mobile.ui.v2.help.HelpSource
import com.echoledger.mobile.ui.v2.help.ReleaseNotes
import kotlinx.coroutines.CancellationException

/**
 * Help v2 on the phone's API client (contract 2.0.0-d): the user guide's sections, Android's release notes, a
 * message to support, and when this phone last sent its device report. A failure is [HelpResult.Failed]: `offline`
 * when the server can't be reached; the server's own words when written for people; else our sentence as a string
 * resource, which the screen says in its own language. Never a status code.
 */
class PhoneHelpSource(private val api: suspend () -> ApiClient?, private val reports: DeviceReports) : HelpSource {
    /** Which call failed: a 429 and a 404 mean something different for each. */
    enum class Call(val failed: Int) {
        GUIDES(R.string.v2_help_core_guides_failed),
        GUIDE(R.string.v2_help_core_guide_failed),
        WHATS_NEW(R.string.v2_help_core_whats_new_failed),
        SEND(R.string.v2_help_core_send_failed)
    }

    override suspend fun guides(query: String): HelpResult<List<GuideHit>> = help(Call.GUIDES) { client ->
        client.helpGuides(query.trim().ifBlank { null }).data.map { GuideHit(it.id, it.title, it.excerpt?.ifBlank { null }) }
    }

    override suspend fun guide(id: String): HelpResult<Guide> = help(Call.GUIDE) { client ->
        client.helpGuide(id).let { Guide(it.id, it.title, it.markdown) }
    }

    override suspend fun whatsNew(): HelpResult<List<ReleaseNotes>> = help(Call.WHATS_NEW) { client ->
        client.whatsNew("android").data.map { ReleaseNotes(it.version, it.releasedAt, it.notes) }
    }

    override suspend fun send(subject: String, message: String, includeReport: Boolean): HelpResult<Unit> = help(Call.SEND) { client ->
        client.sendSupportMessage(subject, message, includeReport && reports.lastAt() != null)
    }

    override fun lastReportAt(): String? = reports.lastAt()

    private suspend fun <T> help(call: Call, block: suspend (ApiClient) -> T): HelpResult<T> = try {
        HelpResult.Ok(block(api() ?: return HelpResult.Failed(offline = false, words = null, message = R.string.v2_help_core_sign_in)))
    } catch (e: CancellationException) {
        throw e
    } catch (e: Exception) {
        failure(e, call)
    }

    /** Offline, or the words for people: by the server's code and the call first, then [Problems] in the phone's language. */
    internal fun <T> failure(e: Throwable, call: Call): HelpResult<T> {
        val refused = e as? ApiException
        val key: Int? = when {
            refused == null -> null

            refused.status == 429 -> if (call ==
                Call.SEND
            ) {
                R.string.v2_help_core_too_many_messages
            } else {
                R.string.v2_help_core_too_many_tries
            }

            refused.code == "email_not_verified" -> R.string.v2_help_core_not_verified

            refused.code == "server_busy" || refused.status == 503 -> R.string.v2_help_core_busy

            refused.status == 404 -> if (call == Call.GUIDE) R.string.v2_help_core_guide_gone else call.failed

            else -> null
        }
        if (key != null) return HelpResult.Failed(offline = false, words = null, message = key)
        val english = Problems.text(e, FAILED_ENGLISH)
        val offline = refused == null && english == Problems.OFFLINE
        // Problems' own sentences and the "no better words" fallback are ours; anything else is the server's own words.
        val ours = PROBLEM_KEYS[english] ?: call.failed.takeIf { english == FAILED_ENGLISH }
        return if (ours != null) {
            HelpResult.Failed(offline = offline, words = null, message = ours)
        } else {
            HelpResult.Failed(offline = offline, words = english, message = call.failed)
        }
    }

    private companion object {
        /** Problems' fallback, only to tell "no better words" apart; it never reaches the screen. */
        const val FAILED_ENGLISH = "\u0000help call failed"

        /** Problems' own sentences in the phone's language. */
        val PROBLEM_KEYS = mapOf(
            Problems.OFFLINE to R.string.v2_help_core_offline,
            Problems.SERVER to R.string.v2_help_core_server,
            Problems.SIGN_IN to R.string.v2_help_core_sign_in,
            Problems.CERTIFICATE to R.string.v2_help_core_certificate,
            EMPTY_ANSWER to R.string.v2_help_core_empty_answer,
            UNREADABLE_ANSWER to R.string.v2_help_core_unreadable_answer
        )
    }
}

/** When this phone last sent its device report (`POST /api/devices/report`), kept across restarts, as an ISO time. */
class DeviceReports(context: Context, private val now: () -> java.time.Instant = java.time.Instant::now) {
    private val prefs = context.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    fun lastAt(): String? = prefs.getString(KEY, null)

    /** The report reached the server. */
    fun sent() {
        prefs.edit().putString(KEY, now().toString()).apply()
    }

    /** Signed out: a report sent for this account is never offered with another's message. */
    fun clear() {
        prefs.edit().remove(KEY).commit()
    }

    private companion object {
        const val FILE = "device_reports"
        const val KEY = "last_sent_at"
    }
}
