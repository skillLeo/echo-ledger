package com.echoledger.mobile.ui.v2.library

import androidx.compose.ui.graphics.vector.ImageVector
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.shell.V2Text
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoUnit
import java.util.Locale

/** Where a recording stands, as one word on its row. [Done] shows nothing. */
enum class RowStatus { Done, Untranscribed, Transcribing, Failed }

/** The saved views of Library (docs/v2/IA.md 2.1): quick filters that combine. */
enum class SavedView { Today, Untranscribed }

/** One Library row, worked out from a [LibraryItem]: everything the row shows, nothing it has to compute. */
data class V2Row(
    val id: String,
    val title: String,
    val meta: String,
    val time: String,
    val duration: String,
    val icon: ImageVector,
    val source: String,
    val status: RowStatus,
    val item: LibraryItem
)

/** Turns the library into rows and applies the saved views and the search; no Android or Compose state. */
object LibraryModel {
    fun status(item: LibraryItem): RowStatus {
        val t = item.transcript
        return when {
            t?.finalStatus == "failed" || item.status == "failed" -> RowStatus.Failed
            t == null || t.finalStatus == null || t.finalStatus == "none" -> RowStatus.Untranscribed
            t.finalStatus == "completed" -> RowStatus.Done
            else -> RowStatus.Transcribing
        }
    }

    fun icon(app: SourceApp): ImageVector = when (app) {
        SourceApp.WHATSAPP, SourceApp.WHATSAPP_WEB, SourceApp.TELEGRAM, SourceApp.SIGNAL, SourceApp.MESSENGER,
        SourceApp.INSTAGRAM, SourceApp.SLACK, SourceApp.DISCORD -> EchoIcons.WhatsApp

        SourceApp.WHATSAPP_BUSINESS -> EchoIcons.WhatsAppBusiness

        SourceApp.GOOGLE_MEET, SourceApp.ZOOM, SourceApp.MICROSOFT_TEAMS, SourceApp.SKYPE -> EchoIcons.Meeting

        SourceApp.PHONE_CALL -> EchoIcons.PhoneCall

        SourceApp.VOICE_NOTE -> EchoIcons.VoiceNote

        SourceApp.OTHER -> EchoIcons.Library
    }

    private fun started(item: LibraryItem, zone: ZoneId): ZonedDateTime? =
        runCatching { OffsetDateTime.parse(item.startedAt).atZoneSameInstant(zone) }.getOrNull()

    /**
     * The time on the row, WhatsApp style: 10:42 today, "Yesterday", the weekday within the week, then the date.
     * Digits are always Latin; names follow [locale].
     */
    fun time(item: LibraryItem, now: ZonedDateTime, text: V2Text, locale: Locale): String {
        val at = started(item, now.zone) ?: return ""
        val days = ChronoUnit.DAYS.between(at.toLocalDate(), now.toLocalDate())
        return when {
            days <= 0L -> at.format(DateTimeFormatter.ofPattern("HH:mm", Locale.ROOT))
            days == 1L -> text.yesterday
            days < 7L -> at.format(DateTimeFormatter.ofPattern("EEE", locale))
            at.year == now.year -> at.format(DateTimeFormatter.ofPattern("d MMM", locale))
            else -> at.format(DateTimeFormatter.ofPattern("d MMM yyyy", locale))
        }.let { latinDigits(it) }
    }

    fun isToday(item: LibraryItem, now: ZonedDateTime): Boolean = started(item, now.zone)?.toLocalDate() == now.toLocalDate()

    /** One meta line: what was said, else the project, else where it came from. */
    fun meta(item: LibraryItem, source: String): String = item.transcript?.preview?.lineSequence()?.firstOrNull { it.isNotBlank() }?.trim()
        ?: item.client?.name
        ?: source

    fun row(item: LibraryItem, now: ZonedDateTime, text: V2Text, locale: Locale): V2Row {
        val app = SourceApp.from(item.sourceApp)
        val source = text.sourceName(item.sourceApp)
        return V2Row(
            id = item.id,
            title = item.title?.takeIf { it.isNotBlank() } ?: source,
            meta = meta(item, source),
            time = time(item, now, text, locale),
            duration = item.durationMs?.takeIf { it > 0 }?.let { formatClock(it + 500) }.orEmpty(),
            icon = icon(app),
            source = source,
            status = status(item),
            item = item
        )
    }

    /** The rows the saved [views] and the [query] leave, newest first; deleted items never show. */
    fun rows(
        items: List<LibraryItem>,
        views: Set<SavedView>,
        query: String,
        now: ZonedDateTime,
        text: V2Text,
        locale: Locale
    ): List<V2Row> {
        val q = query.trim()
        return items.asSequence()
            .filter { !it.deleted }
            .filter { SavedView.Today !in views || isToday(it, now) }
            .filter { SavedView.Untranscribed !in views || status(it) == RowStatus.Untranscribed || status(it) == RowStatus.Failed }
            .map { row(it, now, text, locale) }
            .filter {
                q.isEmpty() || it.title.contains(q, true) || it.meta.contains(q, true) ||
                    it.item.client?.name?.contains(q, true) == true
            }
            .sortedByDescending { it.item.startedAt ?: it.item.updatedAt }
            .toList()
    }

    /** Arabic-Indic and Eastern Arabic digits to Latin ones: times and durations stay 0-9 in every language. */
    fun latinDigits(s: String): String = buildString(s.length) {
        for (ch in s) {
            append(
                when (ch) {
                    in '٠'..'٩' -> '0' + (ch - '٠')
                    in '۰'..'۹' -> '0' + (ch - '۰')
                    else -> ch
                }
            )
        }
    }

    /** "1.2 GB", "340 MB": the storage meter in Latin digits. */
    fun size(bytes: Long): String {
        val mb = bytes / 1_000_000.0
        return if (mb >= 1000) String.format(Locale.ROOT, "%.1f GB", mb / 1000) else String.format(Locale.ROOT, "%.0f MB", mb)
    }
}
