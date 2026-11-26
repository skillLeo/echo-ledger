package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ListDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The Library's chip row (D14, contract 2.0.0-m): what each chip leaves, moving a list, Undo's recordings, the fixed words. */
class LibraryListsTest {
    private val clients = ListDto("clients", "Clients", "#e15f56", 0, 1)
    private val home = ListDto("home", "Home", null, 1, 0)
    private val family = ListDto("family", "Family", "#458cde", 2, 0)
    private val lists = listOf(clients, home, family)

    private fun item(id: String, labels: List<String> = emptyList(), opened: Boolean? = true, important: Boolean = false) =
        LibraryItem(id = id, updatedAt = "2026-11-21T10:00:00Z", labels = labels, opened = opened, important = important)

    private val items = listOf(
        item("a", listOf("CLIENTS")),
        item("b", opened = false),
        item("c", important = true),
        item("d", listOf("Home", "Clients"), opened = false)
    )

    @Test
    fun eachChipLeavesItsRecordings() {
        assertEquals(items, LibraryLists.filter(items, LibraryLists.ALL, lists))
        assertEquals(listOf("b", "d"), LibraryLists.filter(items, LibraryLists.UNREAD, lists).map { it.id })
        assertEquals(listOf("c"), LibraryLists.filter(items, LibraryLists.IMPORTANT, lists).map { it.id })
        // A list's recordings, capitals ignored as the server keys lists.
        assertEquals(listOf("a", "d"), LibraryLists.filter(items, LibraryLists.chipOf(clients), lists).map { it.id })
        assertEquals(emptyList<String>(), LibraryLists.filter(items, LibraryLists.chipOf(family), lists).map { it.id })
        assertEquals(2, LibraryLists.unread(items))
    }

    @Test
    fun aChosenListThatIsGoneGoesBackToAll() {
        assertEquals(LibraryLists.chipOf(home), LibraryLists.resolve(LibraryLists.chipOf(home), lists))
        assertEquals(LibraryLists.ALL, LibraryLists.resolve(LibraryLists.chipOf(home), listOf(clients, family)))
        assertEquals(LibraryLists.UNREAD, LibraryLists.resolve(LibraryLists.UNREAD, emptyList()))
    }

    @Test
    fun movingAListChangesOnlyItsPlace() {
        assertEquals(listOf("home", "clients", "family"), LibraryLists.moved(lists, "clients", +1))
        assertEquals(listOf("clients", "family", "home"), LibraryLists.moved(lists, "family", -1))
        assertNull(LibraryLists.moved(lists, "clients", -1))
        assertNull(LibraryLists.moved(lists, "family", +1))
        assertNull(LibraryLists.moved(lists, "nope", +1))
    }

    @Test
    fun undoAfterAddingRevertsOnlyTheRecordingsThatChanged() {
        assertEquals(listOf("b", "c"), LibraryLists.gaining(items.take(3), clients))
    }

    @Test
    fun theFixedChipsWordsAreNotNamesInEitherLanguage() {
        assertTrue(LibraryLists.reserved(" important "))
        assertTrue(LibraryLists.reserved("UNREAD"))
        assertTrue(LibraryLists.reserved("اہم"))
        assertTrue(LibraryLists.reserved("نہ  پڑھی گئی"))
        assertFalse(LibraryLists.reserved("Clients"))
        assertEquals("my  clients".let { LibraryLists.key(it) }, "my clients")
    }
}
