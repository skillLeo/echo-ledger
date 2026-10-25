package com.echoledger.mobile.net

import kotlin.random.Random
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** Chunk planning, the retry policy and checksums that the resumable upload relies on. */
class UploadPlanningTest {
    @Test
    fun `the planned chunks tile the recording exactly for random sizes`() {
        val random = Random(2026)
        repeat(500) {
            val size = random.nextLong(1, 50_000_000)
            val chunkSize = random.nextLong(1, 6_000_000)

            val plan = ChunkPlanner.plan(size, chunkSize)

            assertEquals(size, plan.sumOf { it.length.toLong() })
            assertEquals((0 until plan.size).toList(), plan.map { it.index })
            plan.forEachIndexed { i, chunk ->
                assertEquals(i * chunkSize, chunk.offset)
                if (i < plan.lastIndex) assertEquals(chunkSize.toInt(), chunk.length) else assertTrue(chunk.length in 1..chunkSize.toInt())
            }
            assertEquals((size + chunkSize - 1) / chunkSize, plan.size.toLong())
        }
    }

    @Test
    fun `single byte, exact fit and oversize chunk sizes`() {
        assertEquals(listOf(ChunkPlanner.Chunk(0, 0, 1)), ChunkPlanner.plan(1, 5_242_880))
        assertEquals(listOf(ChunkPlanner.Chunk(0, 0, 10)), ChunkPlanner.plan(10, 10))
        assertEquals(2, ChunkPlanner.plan(11, 10).size)
        assertEquals(listOf(ChunkPlanner.Chunk(0, 0, 10)), ChunkPlanner.plan(10, Long.MAX_VALUE / 2))
    }

    @Test
    fun `negative inputs plan nothing`() {
        assertTrue(ChunkPlanner.plan(-5, 100).isEmpty())
        assertTrue(ChunkPlanner.plan(100, -5).isEmpty())
    }

    @Test
    fun `a two gibibyte recording in five mebibyte chunks plans four hundred and ten chunks`() {
        val plan = ChunkPlanner.plan(2_147_483_648L, 5_242_880L)

        assertEquals(410, plan.size)
        assertEquals(2_147_483_648L - 409L * 5_242_880L, plan.last().length.toLong())
    }

    @Test
    fun `missing chunks ignore duplicates and indexes outside the plan`() {
        assertEquals(listOf(2, 4), ChunkPlanner.missing(5, listOf(0, 1, 1, 3, 3, 99, -1)))
        assertEquals(listOf(0, 1, 2), ChunkPlanner.missing(3, emptyList()))
        assertTrue(ChunkPlanner.missing(0, listOf(1)).isEmpty())
        assertTrue(ChunkPlanner.missing(-3, emptyList()).isEmpty())
        assertEquals(listOf(1), ChunkPlanner.missing(3, setOf(0, 2)))
    }

    @Test
    fun `only client errors other than timeout and rate limit are final across the whole status range`() {
        val transient = (100..599).filterNot { UploadWorker.isPermanent(it) }.toSet()
        val permanent = (100..599).filter { UploadWorker.isPermanent(it) }

        assertEquals((400..499).toSet() - setOf(408, 429), permanent.toSet())
        assertTrue(transient.containsAll(listOf(408, 429, 500, 502, 503, 504)))
        assertFalse(UploadWorker.isPermanent(200))
        assertFalse(UploadWorker.isPermanent(0))
        assertFalse(UploadWorker.isPermanent(-1))
    }

    @Test
    fun `checksums match the known SHA-256 vectors in lower case hex`() {
        assertEquals("e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", UploadWorker.sha256(ByteArray(0)))
        assertEquals("ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad", UploadWorker.sha256("abc".toByteArray()))
        assertEquals(
            "cdc76e5c9914fb9281a1c7e284d73e67f1809a48a497200e046d39ccc7112cd0",
            UploadWorker.sha256(
                ByteArray(1_000_000) {
                    'a'.code.toByte()
                }
            )
        )
        assertEquals(64, UploadWorker.sha256(ByteArray(7)).length)
    }
}
