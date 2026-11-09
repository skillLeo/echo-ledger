package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import kotlinx.serialization.Serializable

/** Group by in the Library's View sheet (since v1.6, Phase 1C D1): the desktop's and the console's choices. */
enum class GroupBy(val key: String, val label: String) {
    DATE("date", "Date"),
    APP("app", "App / Type"),
    PROJECT("project", "Project"),
    LANGUAGE("language", "Language"),
    STATUS("status", "Status"),
    DEVICE("device", "Device"),
    NONE("none", "None");

    companion object {
        fun of(key: String?): GroupBy = entries.firstOrNull { it.key == key } ?: DATE
    }
}

/** How tall a Library row is: the usual two lines, or Compact with more rows on the screen. */
enum class Density(val key: String, val label: String) {
    COMFORTABLE("comfortable", "Comfortable"),
    COMPACT("compact", "Compact");

    companion object {
        fun of(key: String?): Density = entries.firstOrNull { it.key == key } ?: COMFORTABLE
    }
}

/**
 * One Library view's Group by, Sort by and Density, kept on this phone per view (the whole Library, or one smart view).
 * Stored as words, so a choice a later version drops reads as the default instead of breaking the settings.
 */
@Serializable
data class LibraryViewChoice(
    val group: String = GroupBy.DATE.key,
    val sort: String = LibrarySort.NEWEST.key,
    val density: String = Density.COMFORTABLE.key
) {
    val groupBy: GroupBy get() = GroupBy.of(group)
    val sortBy: LibrarySort get() = LibrarySort.of(sort)
    val densityOf: Density get() = Density.of(density)

    companion object {
        val DEFAULT = LibraryViewChoice()

        fun of(group: GroupBy, sort: LibrarySort, density: Density) = LibraryViewChoice(group.key, sort.key, density.key)
    }
}

/** The Library in groups, for its sticky headers (since v1.6, Phase 1C D1). Pure; unit-tested. */
object LibraryGrouping {
    /** [key]: stable while the group exists (folding and selection follow it); [label]: the header, empty for None. */
    data class Group<T>(val key: String, val label: String, val items: List<T>)

    const val PINNED = "Pinned"

    private val STATUS_ORDER = listOf("Needs you", "Transcribing", "Ready")
    private val LAST = setOf("No project", "Other", "Unknown device", "Automatic")

    /** Which view a choice is remembered for: one smart view on its own, else the whole Library. */
    fun viewKey(filters: LibraryFilters): String = filters.views.singleOrNull()?.name?.lowercase() ?: "all"

    /**
     * [sorted] is already in the chosen order (pinned first), and each group keeps that order. By Date pinned
     * recordings come first under "Pinned" and the days follow the order (newest days first unless Oldest);
     * Status goes Needs you, Transcribing, Ready; the other groups go A to Z, with "No project" and the like last.
     */
    fun <T : LibraryItem> groups(sorted: List<T>, by: GroupBy, sort: LibrarySort, day: (T) -> String): List<Group<T>> {
        if (sorted.isEmpty()) return emptyList()
        if (by == GroupBy.NONE) return listOf(Group("none", "", sorted))
        if (by == GroupBy.DATE) {
            val out = mutableListOf<Group<T>>()
            sorted.filter { it.pinned }.takeIf { it.isNotEmpty() }?.let { out += Group("date:$PINNED", PINNED, it) }
            val rest = sorted.filterNot { it.pinned }
            val byDay = rest.groupBy(day)
            // A day's place is that of its newest recording (its oldest for Oldest), whatever the order within it.
            val ordered = byDay.entries.sortedWith(
                if (sort == LibrarySort.OLDEST) {
                    compareBy { e -> e.value.minOf { it.startedAt.orEmpty() } }
                } else {
                    compareByDescending { e -> e.value.maxOf { it.startedAt.orEmpty() } }
                }
            )
            ordered.forEach { (label, items) -> out += Group("date:$label", label, items) }
            return out
        }
        val byLabel = LinkedHashMap<String, MutableList<T>>()
        sorted.forEach { byLabel.getOrPut(label(it, by)) { mutableListOf() } += it }
        val entries = byLabel.entries.toList()
        val ordered = if (by == GroupBy.STATUS) {
            entries.sortedBy { STATUS_ORDER.indexOf(it.key) }
        } else {
            entries.sortedWith(
                compareBy<Map.Entry<String, MutableList<T>>> {
                    it.key in LAST
                }.thenBy(String.CASE_INSENSITIVE_ORDER) { it.key }
            )
        }
        return ordered.map { (label, items) -> Group("${by.key}:$label", label, items) }
    }

    fun label(item: LibraryItem, by: GroupBy): String = when (by) {
        GroupBy.APP -> if (item.sourceApp == "voice_note") "Voice notes" else item.sourceAppLabel?.takeIf { it.isNotBlank() } ?: "Other"

        GroupBy.PROJECT -> item.client?.name?.takeIf { it.isNotBlank() } ?: "No project"

        GroupBy.LANGUAGE -> item.languageChip?.takeIf { it.isNotBlank() && it != "Auto" } ?: item.language?.uppercase() ?: "Automatic"

        GroupBy.STATUS -> when (ItemStatus.of(item)) {
            ItemStatus.READY -> "Ready"
            ItemStatus.PROCESSING -> "Transcribing"
            ItemStatus.FAILED, ItemStatus.NO_AUDIO -> "Needs you"
        }

        GroupBy.DEVICE -> item.device?.name?.takeIf { it.isNotBlank() } ?: item.sourceDeviceLabel?.takeIf { it.isNotBlank() }
            ?: "Unknown device"

        GroupBy.DATE, GroupBy.NONE -> ""
    }
}
