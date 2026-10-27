package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import java.time.ZonedDateTime

/** How the library list is ordered (the Sort menu). */
enum class LibrarySort(val label: String) {
    NEWEST("Newest first"),
    OLDEST("Oldest first"),
    LONGEST("Longest first"),
    TITLE("Title A–Z")
}

/** What a recording's status dot means. */
enum class ItemStatus(val label: String) {
    READY("Ready"),
    PROCESSING("Processing"),
    FAILED("Failed"),

    /** Nothing was heard: not a failure to retry but a capture problem (grey, with the capture test). */
    NO_AUDIO("No audio");

    companion object {
        fun of(item: LibraryItem): ItemStatus = when {
            item.transcript?.finalErrorCode == "no_audio" -> NO_AUDIO
            item.transcript?.finalStatus == "failed" || item.status == "failed" -> FAILED
            item.status == "completed" || item.transcript?.finalStatus == "completed" -> READY
            else -> PROCESSING
        }
    }
}

/**
 * The Filter menu's ticked boxes, the same rules as the desktop: boxes ticked in one group add up (Phone or Desktop),
 * groups narrow each other (Phone and This week). Empty groups filter nothing. Pure; unit-tested.
 */
data class LibraryFilters(
    val views: Set<SmartView> = emptySet(),
    val devices: Set<SourceDevice> = emptySet(),
    /** Client ids; "" = no client. */
    val folders: Set<String> = emptySet(),
    val labels: Set<String> = emptySet(),
    /** Bookmark tags; [Bookmarks.ANY] = any bookmark. */
    val tags: Set<String> = emptySet()
) {
    val count: Int get() = views.size + devices.size + folders.size + labels.size + tags.size

    fun matches(item: LibraryItem, now: ZonedDateTime = ZonedDateTime.now()): Boolean = !item.deleted &&
        (views.isEmpty() || views.any { Organise.inView(item, it, now) }) &&
        (devices.isEmpty() || SourceDevice.from(item.sourceDevice) in devices) &&
        (folders.isEmpty() || folders.any { Organise.inFolder(item, it) }) &&
        (labels.isEmpty() || labels.any { Organise.hasLabel(item, it) }) &&
        (tags.isEmpty() || tags.any { Bookmarks.matches(item, it) })

    companion object {
        fun <T> Set<T>.toggle(value: T): Set<T> = if (value in this) this - value else this + value
    }
}

object LibraryQuery {
    /** The list as shown: filtered, searched (titles, previews, clients, apps, tags) and sorted. */
    fun apply(
        items: List<LibraryItem>,
        filters: LibraryFilters,
        query: String,
        sort: LibrarySort,
        now: ZonedDateTime = ZonedDateTime.now()
    ): List<LibraryItem> {
        val q = query.trim().lowercase()
        val shown = items.filter { item ->
            filters.matches(item, now) &&
                (
                    q.isEmpty() ||
                        (
                            listOfNotNull(item.title, item.transcript?.preview, item.client?.name, SourceApp.from(item.sourceApp).label) +
                                item.tags
                            )
                            .any { it.lowercase().contains(q) }
                    )
        }
        return when (sort) {
            // Pinned recordings stay on top in every order, like the desktop and the console.
            LibrarySort.NEWEST -> shown.sortedWith(
                compareByDescending<LibraryItem> {
                    it.pinned
                }.thenByDescending { it.startedAt.orEmpty() }
            )

            LibrarySort.OLDEST -> shown.sortedWith(compareByDescending<LibraryItem> { it.pinned }.thenBy { it.startedAt.orEmpty() })

            LibrarySort.LONGEST -> shown.sortedWith(compareByDescending<LibraryItem> { it.pinned }.thenByDescending { it.durationMs ?: 0L })

            LibrarySort.TITLE -> shown.sortedWith(compareByDescending<LibraryItem> { it.pinned }.thenBy { (it.title ?: "").lowercase() })
        }
    }
}
