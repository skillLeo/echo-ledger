package com.echoledger.mobile.data

import com.echoledger.mobile.data.LibraryRepository.Companion.merge
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class LibraryMergeTest {
    private fun item(id: String, updatedAt: String, startedAt: String? = null, title: String? = null, deleted: Boolean = false) =
        LibraryItem(id = id, updatedAt = updatedAt, startedAt = startedAt, title = title, deleted = deleted)

    @Test
    fun `unknown items are inserted`() {
        val merged = merge(emptyList(), listOf(item("a", "2026-01-01T00:00:00Z", startedAt = "2026-01-01T00:00:00Z")))
        assertEquals(listOf("a"), merged.map { it.id })
    }

    @Test
    fun `a newer version replaces an older one, an older update is ignored`() {
        val existing = listOf(item("a", "2026-01-01T00:00:00Z", title = "old"))
        val newer = merge(existing, listOf(item("a", "2026-02-01T00:00:00Z", title = "new")))
        assertEquals("new", newer.single().title)

        val stale = merge(newer, listOf(item("a", "2025-12-01T00:00:00Z", title = "stale")))
        assertEquals("new", stale.single().title)
    }

    @Test
    fun `a tombstone removes the item`() {
        val existing = listOf(item("a", "2026-01-01T00:00:00Z"), item("b", "2026-01-01T00:00:00Z"))
        val merged = merge(existing, listOf(item("a", "2026-03-01T00:00:00Z", deleted = true)))
        assertEquals(listOf("b"), merged.map { it.id })
    }

    @Test
    fun `a tombstone for an unknown item is a no-op`() {
        val merged = merge(emptyList(), listOf(item("ghost", "2026-03-01T00:00:00Z", deleted = true)))
        assertTrue(merged.isEmpty())
    }

    @Test
    fun `results are sorted by start time descending`() {
        val merged = merge(
            emptyList(),
            listOf(
                item("old", "2026-01-01T00:00:00Z", startedAt = "2026-01-01T09:00:00Z"),
                item("new", "2026-01-01T00:00:00Z", startedAt = "2026-01-02T09:00:00Z")
            )
        )
        assertEquals(listOf("new", "old"), merged.map { it.id })
    }
}
