package com.echoledger.mobile.net

/** Resumable upload planning (pure, unit-tested). docs/API.md "POST /api/recordings/{id}/chunks". */
object ChunkPlanner {
    data class Chunk(val index: Int, val offset: Long, val length: Int)

    fun plan(size: Long, chunkSize: Long): List<Chunk> {
        if (size <= 0 || chunkSize <= 0) return emptyList()
        val count = ((size + chunkSize - 1) / chunkSize).toInt()
        return (0 until count).map { i ->
            val offset = i * chunkSize
            Chunk(i, offset, minOf(chunkSize, size - offset).toInt())
        }
    }

    fun missing(total: Int, received: Collection<Int>): List<Int> = (0 until total).filterNot { it in received }
}
