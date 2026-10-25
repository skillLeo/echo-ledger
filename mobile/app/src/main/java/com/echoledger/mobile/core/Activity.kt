package com.echoledger.mobile.core

import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ApiJson
import java.io.File
import kotlinx.serialization.Serializable

/** The activity centre on the phone (B18): quiet hours, what is new, and what a notification says. Pure. */
object Activity {
    const val MAX_SINGLE = 3
    private const val SEEN_LIMIT = 200

    /** "22:30" → minutes after midnight; null for anything else. */
    fun parseHhmm(text: String): Int? {
        val match = Regex("""^(\d{2}):(\d{2})$""").matchEntire(text) ?: return null
        val (h, m) = match.destructured
        return if (h.toInt() < 24 && m.toInt() < 60) h.toInt() * 60 + m.toInt() else null
    }

    /** Overnight ranges wrap; equal ends mean all day. */
    fun inQuietHours(enabled: Boolean, from: String, to: String, now: Int): Boolean {
        val start = parseHhmm(from) ?: return false
        val end = parseHhmm(to) ?: return false
        if (!enabled) return false
        return when {
            start == end -> true
            start < end -> now in start until end
            else -> now >= start || now < end
        }
    }

    /** One notification per item, or one summary for a burst. */
    fun notificationText(items: List<ActivityItemDto>): List<Pair<String, String>> =
        if (items.size > MAX_SINGLE) listOf("EchoLedger" to "${items.size} new notifications") else items.map { it.title to it.body }

    @Serializable
    data class Seen(val initialised: Boolean = false, val ids: List<String> = emptyList())

    /** The ids already shown, kept in no-backup storage so a restart never repeats them. */
    class SeenStore(private val file: File) {
        @Synchronized
        fun load(): Seen = runCatching { ApiJson.decodeFromString(Seen.serializer(), file.readText()) }.getOrDefault(Seen())

        /** The unread items not shown before, oldest first; the first run only learns what exists. */
        @Synchronized
        fun fresh(items: List<ActivityItemDto>): List<ActivityItemDto> {
            val seen = load()
            val ids = seen.ids.toMutableList()
            val out = mutableListOf<ActivityItemDto>()
            items.asReversed().forEach { item ->
                if (item.id in ids) return@forEach
                ids += item.id
                if (seen.initialised && !item.read) out += item
            }
            file.writeText(ApiJson.encodeToString(Seen.serializer(), Seen(true, ids.takeLast(SEEN_LIMIT))))
            return out
        }
    }
}
