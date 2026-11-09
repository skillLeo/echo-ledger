package com.echoledger.mobile.core

import com.echoledger.mobile.data.AnnouncementDto
import com.echoledger.mobile.data.AppUpdateDto
import com.echoledger.mobile.data.MaintenanceDto
import java.time.Instant
import kotlinx.serialization.Serializable

/** What the server last said (since v1.6, P2.7, `GET /api/config`): kept so the banners show offline and after a restart. */
@Serializable
data class ServerNotices(
    val appUpdate: AppUpdateDto? = null,
    val announcement: AnnouncementDto? = null,
    val maintenance: MaintenanceDto? = null
)

/** The banners at the top of the Library: an update, maintenance, the admin's announcement. Pure; unit-tested. */
object ServerBanners {
    enum class Kind { UPDATE, MAINTENANCE, ANNOUNCEMENT }

    /** [closable]: only an announcement; [key]: what closing it remembers. */
    data class Banner(val kind: Kind, val text: String, val warning: Boolean, val key: String? = null)

    const val UPDATE_FALLBACK = "Please update EchoLedger to keep sending recordings."
    const val REFUSED = "The server no longer takes recordings from this version. Update EchoLedger: your recordings wait on this phone."
    const val MAINTENANCE_FALLBACK = "The server is in maintenance. Your recordings wait on this phone and are sent afterwards."

    fun of(notices: ServerNotices, closed: Collection<String>, now: Instant = Instant.now()): List<Banner> = buildList {
        notices.appUpdate?.let { u ->
            val text = if (u.refused) REFUSED else u.message.ifBlank { UPDATE_FALLBACK }
            add(Banner(Kind.UPDATE, text, warning = u.refused || u.required))
        }
        notices.maintenance?.takeIf { stillOn(it.until, now) }?.let { m ->
            add(Banner(Kind.MAINTENANCE, m.message.ifBlank { MAINTENANCE_FALLBACK }, warning = true))
        }
        notices.announcement?.takeIf { it.text.isNotBlank() && stillOn(it.until, now) }?.let { a ->
            val key = keyOf(a.text)
            if (key !in closed) add(Banner(Kind.ANNOUNCEMENT, a.text, warning = a.level == "warning", key = key))
        }
    }

    /** A short stable key for a closed announcement (no text is stored twice). */
    fun keyOf(text: String): String = Integer.toHexString(text.trim().hashCode())

    private fun stillOn(until: String?, now: Instant): Boolean {
        val end = until?.let { runCatching { Instant.parse(it) }.getOrNull() } ?: return true
        return now.isBefore(end)
    }
}
