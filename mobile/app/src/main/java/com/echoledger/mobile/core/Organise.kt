package com.echoledger.mobile.core

import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import java.time.DayOfWeek
import java.time.Instant
import java.time.ZonedDateTime
import java.time.temporal.TemporalAdjusters

/** Smart views of the library (B15), the same rules as the server and the desktop. */
enum class SmartView(val label: String) {
    ALL("All recordings"),
    TODAY("Today"),
    THIS_WEEK("This week"),
    WAITING("Waiting for decision"),
    BOOKMARKED("Bookmarked"),
    FAILED("Failed"),
    PINNED("Pinned")
}

/** Labels, pins and client folders of the library (B15). Pure; unit-tested. */
object Organise {
    const val MAX_LABELS = 20
    const val MAX_LABEL_CHARS = 40
    const val MAX_BULK = 200
    val EXPORT_FORMATS = listOf(
        "docx" to "Word",
        "txt" to "Text",
        "md" to "Markdown",
        "srt" to "Subtitles",
        "vtt" to "Web subtitles",
        "json" to "JSON"
    )

    fun inView(item: LibraryItem, view: SmartView, now: ZonedDateTime = ZonedDateTime.now()): Boolean {
        if (item.deleted) return false
        val started = item.startedAt?.let {
            runCatching { Instant.from(java.time.format.DateTimeFormatter.ISO_OFFSET_DATE_TIME.parse(it)) }.getOrNull()
        }
        val day = now.toLocalDate().atStartOfDay(now.zone)
        val week = now.toLocalDate().with(TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY)).atStartOfDay(now.zone)
        return when (view) {
            SmartView.ALL -> true
            SmartView.TODAY -> started != null && !started.isBefore(day.toInstant()) && started.isBefore(day.plusDays(1).toInstant())
            SmartView.THIS_WEEK -> started != null && !started.isBefore(week.toInstant()) && started.isBefore(week.plusWeeks(1).toInstant())
            SmartView.WAITING -> item.status == "recording"
            SmartView.BOOKMARKED -> item.markerCount > 0
            SmartView.FAILED -> item.status == "failed" || item.transcript?.finalStatus == "failed"
            SmartView.PINNED -> item.pinned
        }
    }

    fun counts(items: List<LibraryItem>, now: ZonedDateTime = ZonedDateTime.now()): Map<SmartView, Int> =
        SmartView.entries.associateWith { view -> items.count { inView(it, view, now) } }

    /** Every recording label in the library, once (ignoring capitals), sorted. */
    fun labels(items: List<LibraryItem>): List<String> =
        items.filterNot { it.deleted }.flatMap { it.labels }.distinctBy { it.lowercase() }.sortedBy { it.lowercase() }

    fun hasLabel(item: LibraryItem, label: String?): Boolean = label == null || item.labels.any { it.equals(label, ignoreCase = true) }

    /** The folder filter: null = any, "" = no client, else that client's recordings. */
    fun inFolder(item: LibraryItem, clientId: String?): Boolean = when (clientId) {
        null -> true
        "" -> item.client == null
        else -> item.client?.id == clientId
    }

    /** A label as the server stores it: single spaces, no control characters, at most 40 characters. */
    fun cleanLabel(text: String): String? {
        val clean = text.filterNot { it.isISOControl() }.trim().replace(Regex("\\s+"), " ")
        return clean.takeIf { it.isNotEmpty() && it.codePointCount(0, it.length) <= MAX_LABEL_CHARS }
    }

    /** The labels with [label] added; null when blank, already there (ignoring capitals) or the list is full. */
    fun withLabel(labels: List<String>, label: String): List<String>? {
        val clean = cleanLabel(label) ?: return null
        if (labels.size >= MAX_LABELS || labels.any { it.equals(clean, ignoreCase = true) }) return null
        return labels + clean
    }

    /** What a folder shows: its avatar, else the initials of its name. */
    fun badge(name: String, avatar: String?): String {
        if (!avatar.isNullOrBlank()) return avatar
        val words = name.trim().split(Regex("\\s+")).filter { it.isNotEmpty() }
        return when {
            words.size > 1 -> "${words[0].first()}${words[1].first()}"
            words.isNotEmpty() -> words[0].take(2)
            else -> "?"
        }.uppercase()
    }

    /** The client folders of synced recordings merged with the server's list (the server wins), pinned first. */
    fun folders(items: List<LibraryItem>, known: List<ClientDto>): List<ClientDto> {
        val byId = linkedMapOf<String, ClientDto>()
        items.filterNot { it.deleted }.mapNotNull { it.client }.forEach {
            byId[it.id] =
                ClientDto(it.id, it.name, color = it.color, avatar = it.avatar)
        }
        known.forEach { byId[it.id] = it }
        return byId.values.sortedWith(compareByDescending<ClientDto> { it.pinned }.thenBy { it.name.lowercase() })
    }
}
