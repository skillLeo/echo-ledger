package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem

/** Bookmark tags in the library (B3): the tag filter's chips, the filter itself and the row's count. Pure. */
object Bookmarks {
    /** The filter value for "any bookmark". */
    const val ANY = "*"

    /** Every tag in use, sorted, without deleted recordings. */
    fun tags(items: List<LibraryItem>): List<String> =
        items.filter { !it.deleted }.flatMap { it.tags }.distinct().sortedWith(String.CASE_INSENSITIVE_ORDER)

    /** `null` = everything, [ANY] = recordings with a bookmark, else recordings with that tag. */
    fun matches(item: LibraryItem, tag: String?): Boolean = when (tag) {
        null -> true
        ANY -> item.markerCount > 0
        else -> tag in item.tags
    }

    fun countLabel(count: Int): String? = when {
        count <= 0 -> null
        count == 1 -> "1 bookmark"
        else -> "$count bookmarks"
    }
}
