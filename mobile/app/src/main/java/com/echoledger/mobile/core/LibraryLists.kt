package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ListDto
import java.util.Locale

/**
 * The Library's chip row (D14, contract 2.0.0-m): All · Unread · Important · the person's lists, one chosen at a time,
 * and what each leaves in the list. Pure; unit-tested.
 */
object LibraryLists {
    const val ALL = "all"
    const val UNREAD = "unread"
    const val IMPORTANT = "important"
    private const val LIST = "list:"

    /** The chip that chooses [list]. */
    fun chipOf(list: ListDto): String = LIST + list.key

    /** The list a chip chooses, or null for All, Unread and Important. */
    fun chosenList(chip: String, lists: List<ListDto>): ListDto? =
        if (chip.startsWith(LIST)) lists.firstOrNull { it.key == chip.removePrefix(LIST) } else null

    /** The chip still to show: a list that is gone (deleted here or elsewhere) goes back to All. */
    fun resolve(chip: String, lists: List<ListDto>): String = if (chip.startsWith(LIST) && chosenList(chip, lists) == null) ALL else chip

    /** Whether [item] carries the label of [list] (capitals ignored, as the server keys lists). */
    fun carries(item: LibraryItem, list: ListDto): Boolean = item.labels.any { key(it) == list.key }

    /** What [chip] leaves of [items]: Unread the ones not opened yet, Important the flagged ones, a list its recordings. */
    fun filter(items: List<LibraryItem>, chip: String, lists: List<ListDto>): List<LibraryItem> = when (chip) {
        ALL -> items
        UNREAD -> items.filter { it.opened == false }
        IMPORTANT -> items.filter { it.important }
        else -> chosenList(chip, lists)?.let { list -> items.filter { carries(it, list) } } ?: items
    }

    /** How many are not opened yet (the Unread chip shows it when above 0). */
    fun unread(items: List<LibraryItem>): Int = items.count { !it.deleted && it.opened == false }

    /** The keys in their new order after moving [key] by [delta] places (−1 earlier, +1 later); null when it can't move. */
    fun moved(lists: List<ListDto>, key: String, delta: Int): List<String>? {
        val keys = lists.map { it.key }.toMutableList()
        val from = keys.indexOf(key)
        val to = from + delta
        if (from < 0 || to !in keys.indices) return null
        keys.add(to, keys.removeAt(from))
        return keys
    }

    /** The recordings among [items] that would gain [list]'s label: only these change, so only these come back on Undo. */
    fun gaining(items: List<LibraryItem>, list: ListDto): List<String> = items.filterNot { carries(it, list) }.map { it.id }

    /** The names a list can't take: the fixed chips' words in English and in Urdu (as the server refuses them). */
    val RESERVED: List<String> = listOf("All", "Unread", "Important", "سب", "نہ پڑھی گئی", "اہم")

    /** A list's key: its name trimmed, single spaces, in lower case (the server's `key`). */
    fun key(name: String): String = name.trim().replace(Regex("\\s+"), " ").lowercase(Locale.ROOT)

    /** Whether [name] is one of the fixed chips' words (capitals and spaces ignored). */
    fun reserved(name: String, screenWords: List<String> = emptyList()): Boolean = (RESERVED + screenWords).any { key(it) == key(name) }
}
