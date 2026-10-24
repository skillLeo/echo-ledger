package com.echoledger.mobile.net

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ChunkPlannerTest {
    @Test
    fun `an exact multiple splits into equal chunks`() {
        val chunks = ChunkPlanner.plan(size = 300, chunkSize = 100)
        assertEquals(3, chunks.size)
        assertEquals(ChunkPlanner.Chunk(0, 0, 100), chunks[0])
        assertEquals(ChunkPlanner.Chunk(2, 200, 100), chunks[2])
    }

    @Test
    fun `a remainder becomes a short final chunk`() {
        val chunks = ChunkPlanner.plan(size = 250, chunkSize = 100)
        assertEquals(3, chunks.size)
        assertEquals(50, chunks.last().length)
        assertEquals(200, chunks.last().offset)
    }

    @Test
    fun `empty or non-positive inputs plan nothing`() {
        assertTrue(ChunkPlanner.plan(0, 100).isEmpty())
        assertTrue(ChunkPlanner.plan(100, 0).isEmpty())
    }

    @Test
    fun `missing returns only the indexes not yet received`() {
        assertEquals(listOf(1, 3), ChunkPlanner.missing(total = 4, received = listOf(0, 2)))
        assertTrue(ChunkPlanner.missing(total = 2, received = listOf(0, 1)).isEmpty())
    }
}

class PinsTest {
    @Test
    fun `only well-formed sha256 pins survive normalization`() {
        val good = "sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
        val pins = Pins.normalize(listOf("  $good  ", good, "sha256/short", "nonsense", ""))
        assertEquals(listOf(good), pins)
    }

    @Test
    fun `a pinner is built for a host only when a valid pin exists`() {
        val good = "sha256/BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB="
        assertNull(Pins.pinnerFor("https://echo.example.com", emptyList()))
        assertNull(Pins.pinnerFor("not a url", listOf(good)))
        val pinner = Pins.pinnerFor("https://echo.example.com", listOf(good))
        assertTrue(pinner != null)
    }

    @Test
    fun `local development hosts are recognised over http`() {
        assertTrue(Pins.isLocalDev("http://10.0.2.2:8000"))
        assertTrue(Pins.isLocalDev("http://localhost:8000"))
        assertTrue(Pins.isLocalDev("http://127.0.0.1"))
        assertEquals(false, Pins.isLocalDev("http://echo.example.com"))
        assertEquals(false, Pins.isLocalDev("https://localhost"))
    }
}
