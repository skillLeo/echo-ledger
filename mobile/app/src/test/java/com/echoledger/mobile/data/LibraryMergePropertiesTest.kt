package com.echoledger.mobile.data

import com.echoledger.mobile.data.LibraryRepository.Companion.merge
import kotlin.random.Random
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** Algebraic properties of the last-write-wins library merge, checked against a plain reference implementation. */
class LibraryMergePropertiesTest {
    private fun item(id: String, updatedAt: String, startedAt: String? = null, title: String? = null, deleted: Boolean = false) =
        LibraryItem(id = id, updatedAt = updatedAt, startedAt = startedAt, title = title, deleted = deleted)

    private fun stamp(second: Int) = "2026-10-24T09:%02d:%02d+00:00".format(second / 60, second % 60)

    private fun randomItems(random: Random, count: Int): List<LibraryItem> = List(count) {
        val id = "id${random.nextInt(8)}"
        item(
            id = id,
            updatedAt = stamp(random.nextInt(600)),
            startedAt = if (random.nextInt(5) == 0) null else stamp(random.nextInt(600)),
            title = "t${random.nextInt(1000)}",
            deleted = random.nextInt(6) == 0
        )
    }

    /** The documented rules, written the slow obvious way. */
    private fun reference(existing: List<LibraryItem>, incoming: List<LibraryItem>): Set<LibraryItem> {
        val state = LinkedHashMap<String, LibraryItem>()
        existing.forEach { state[it.id] = it }
        incoming.forEach { next ->
            if (next.deleted) {
                state.remove(next.id)
            } else {
                val current = state[next.id]
                if (current == null || current.updatedAt <= next.updatedAt) state[next.id] = next
            }
        }
        return state.values.toSet()
    }

    @Test
    fun `merging matches the reference rules for random histories`() {
        val random = Random(1234)
        repeat(500) {
            val existing = randomItems(random, random.nextInt(0, 8)).filterNot { it.deleted }.associateBy { it.id }.values.toList()
            val incoming = randomItems(random, random.nextInt(0, 12))

            assertEquals(reference(existing, incoming), merge(existing, incoming).toSet())
        }
    }

    @Test
    fun `merging the same page twice changes nothing`() {
        val random = Random(99)
        repeat(200) {
            val page = randomItems(random, 10)
            val once = merge(emptyList(), page)
            assertEquals(once.toSet(), merge(once, page).toSet())
        }
    }

    @Test
    fun `the result never contains tombstones or duplicate ids and is ordered newest start first`() {
        val random = Random(7)
        repeat(300) {
            val merged = merge(randomItems(random, 5).filterNot { it.deleted }.distinctBy { it.id }, randomItems(random, 10))

            assertTrue(merged.none { it.deleted })
            assertEquals(merged.size, merged.map { it.id }.toSet().size)
            val starts = merged.map { it.startedAt.orEmpty() }
            assertEquals(starts.sortedDescending(), starts)
        }
    }

    @Test
    fun `an update carrying the same timestamp replaces the stored version`() {
        val merged = merge(listOf(item("a", stamp(10), title = "old")), listOf(item("a", stamp(10), title = "new")))

        assertEquals("new", merged.single().title)
    }

    @Test
    fun `the last of several versions of one id within a page wins when it is newest`() {
        val merged =
            merge(
                emptyList(),
                listOf(item("a", stamp(5), title = "first"), item("a", stamp(9), title = "second"), item("a", stamp(7), title = "stale"))
            )

        assertEquals("second", merged.single().title)
    }

    @Test
    fun `recordings without a start time sort after those that have one and keep their relative order`() {
        val merged = merge(
            emptyList(),
            listOf(item("none-1", stamp(1)), item("dated", stamp(2), startedAt = stamp(100)), item("none-2", stamp(3)))
        )

        assertEquals(listOf("dated", "none-1", "none-2"), merged.map { it.id })
    }

    @Test
    fun `a tombstone removes an item whatever its timestamps are`() {
        val merged = merge(listOf(item("a", stamp(500))), listOf(item("a", stamp(1), deleted = true)))

        assertTrue(merged.isEmpty())
    }

    @Test
    fun `merging thousands of items stays fast`() {
        val existing = (0 until 5_000).map { item("e$it", stamp(it % 600), startedAt = stamp(it % 600)) }
        val incoming = (2_500 until 7_500).map { item("e$it", stamp(600 + it % 600), startedAt = stamp(it % 600), title = "n") }
        val started = System.nanoTime()

        val merged = merge(existing, incoming)

        assertEquals(7_500, merged.size)
        assertTrue("took ${(System.nanoTime() - started) / 1_000_000} ms", (System.nanoTime() - started) < 3_000_000_000L)
    }
}
