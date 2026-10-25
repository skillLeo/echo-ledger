package com.echoledger.mobile.data

import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.sampleBytes
import com.echoledger.mobile.testsupport.storeRecording
import java.io.File
import java.io.IOException
import java.io.InputStream
import java.security.MessageDigest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test

/** Local encrypted recordings: import, slicing for chunked upload, metadata handling and cleanup. */
class RecordingStoreTest : AppTest() {
    private val store get() = app.graph.recordings
    private val dir get() = File(app.noBackupFilesDir, "recordings")

    private fun sha(bytes: ByteArray) = MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

    @Test
    fun `importing a stream records its size and checksum and keeps the bytes intact`() {
        val bytes = sampleBytes(10_000)

        val stored = app.storeRecording(id = "a", bytes = bytes)

        assertEquals(10_000L, stored.plainSize)
        assertEquals(sha(bytes), stored.sha256)
        assertEquals("queued", stored.state)
        assertTrue(bytes.contentEquals(store.encryptedFile("a").readBytes()))
        assertEquals(stored, store.get("a"))
    }

    @Test
    fun `an empty stream imports as a zero byte recording with the empty-input checksum`() {
        val stored = app.storeRecording(id = "empty", bytes = ByteArray(0))

        assertEquals(0L, stored.plainSize)
        assertEquals("e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", stored.sha256)
    }

    @Test
    fun `a stream of several megabytes is hashed correctly across read buffers`() {
        val bytes = sampleBytes(3 * 1024 * 1024 + 17)

        val stored = app.storeRecording(id = "big", bytes = bytes)

        assertEquals(bytes.size.toLong(), stored.plainSize)
        assertEquals(sha(bytes), stored.sha256)
    }

    @Test
    fun `importing a plain file encrypts it records the digest and deletes the plain copy`() {
        val plain = File(app.cacheDir, "rec-x.ogg").apply { writeBytes(sampleBytes(4_321)) }
        val meta = LocalRecording(
            id = "x",
            sourceApp = "other",
            clientId = null,
            startedAt = "2026-10-24T09:00:00Z",
            endedAt = "2026-10-24T09:01:00Z",
            durationMs = 1000,
            mime = "audio/ogg",
            codec = "opus",
            channels = 2,
            sampleRate = 48_000,
            plainSize = 0,
            sha256 = "",
            state = "pending_decision"
        )

        val stored = store.importPlainFile(meta, plain)

        assertFalse("the plaintext must not stay behind", plain.exists())
        assertEquals(4_321L, stored.plainSize)
        assertEquals(sha(sampleBytes(4_321)), stored.sha256)
        assertEquals("pending_decision", store.get("x")!!.state)
    }

    @Test
    fun `slices come from the right offsets including a short tail`() {
        val bytes = sampleBytes(2_500)
        app.storeRecording(id = "s", bytes = bytes)

        assertTrue(bytes.copyOfRange(0, 1_000).contentEquals(store.readSlice("s", 0, 1_000)))
        assertTrue(bytes.copyOfRange(1_000, 2_000).contentEquals(store.readSlice("s", 1_000, 1_000)))
        assertTrue(bytes.copyOfRange(2_000, 2_500).contentEquals(store.readSlice("s", 2_000, 1_000)))
        assertTrue(bytes.copyOfRange(2_499, 2_500).contentEquals(store.readSlice("s", 2_499, 1)))
        assertEquals(0, store.readSlice("s", 2_500, 100).size)
        assertEquals(0, store.readSlice("s", 0, 0).size)
    }

    @Test
    fun `reading a slice of a missing recording fails with an IO error`() {
        assertThrows(IOException::class.java) { store.readSlice("nope", 0, 10) }
    }

    @Test
    fun `an unknown or unreadable id has no metadata`() {
        assertNull(store.get("missing"))
        File(dir, "broken.json").writeText("{not json")
        assertNull(store.get("broken"))
        File(dir, "empty.json").writeText("")
        assertNull(store.get("empty"))
    }

    @Test
    fun `listing skips unreadable metadata and temporary files and orders newest first`() {
        app.storeRecording(id = "old", bytes = sampleBytes(10), startedAt = "2026-10-01T09:00:00Z")
        app.storeRecording(id = "new", bytes = sampleBytes(10), startedAt = "2026-10-24T09:00:00Z")
        app.storeRecording(id = "mid", bytes = sampleBytes(10), startedAt = "2026-10-12T09:00:00Z")
        File(dir, "junk.json").writeText("garbage")
        File(dir, "leftover.json.tmp").writeText("{}")
        File(dir, "notes.txt").writeText("x")

        assertEquals(listOf("new", "mid", "old"), store.all().map { it.id })
    }

    @Test
    fun `only recordings awaiting a decision are returned as pending decisions`() {
        app.storeRecording(id = "a", bytes = sampleBytes(10), state = "pending_decision")
        app.storeRecording(id = "b", bytes = sampleBytes(10), state = "queued")
        app.storeRecording(id = "c", bytes = sampleBytes(10), state = "failed")

        assertEquals(listOf("a"), store.pendingDecisions().map { it.id })
    }

    @Test
    fun `update changes the stored state and returns the new value`() {
        app.storeRecording(id = "u", bytes = sampleBytes(10))

        val updated = store.update("u") { it.copy(state = "failed", lastError = "boom") }

        assertEquals("failed", updated!!.state)
        assertEquals("failed", store.get("u")!!.state)
        assertEquals("boom", store.get("u")!!.lastError)
    }

    @Test
    fun `updating an unknown recording does nothing`() {
        assertNull(store.update("nope") { it.copy(state = "failed") })
        assertNull(store.get("nope"))
    }

    @Test
    fun `repeated updates keep the latest value and leave no temporary file`() {
        app.storeRecording(id = "r", bytes = sampleBytes(10))
        repeat(5) { n -> store.update("r") { it.copy(lastError = "attempt $n") } }

        assertEquals("attempt 4", store.get("r")!!.lastError)
        assertFalse(File(dir, "r.json.tmp").exists())
    }

    @Test
    fun `delete removes the encrypted file and the metadata`() {
        app.storeRecording(id = "d", bytes = sampleBytes(10))

        store.delete("d")

        assertNull(store.get("d"))
        assertFalse(store.encryptedFile("d").exists())
        store.delete("d") // deleting twice is harmless
    }

    @Test
    fun `metadata from a newer version with extra fields is still read`() {
        File(dir, "n.json").writeText(
            """{"id":"n","sourceApp":"zoom","startedAt":"2026-10-24T09:00:00Z","endedAt":"2026-10-24T09:01:00Z",""" +
                """"durationMs":5,"mime":"audio/ogg","codec":"opus","channels":2,"sampleRate":48000,"plainSize":9,""" +
                """"sha256":"ab","state":"queued","futureField":{"x":1}}"""
        )
        val read = store.get("n")
        assertNotNull(read)
        assertEquals("zoom", read!!.sourceApp)
        assertNull(read.clientId)
        assertTrue(read.liveSegments.isEmpty())
    }

    @Test
    fun `a stream that fails halfway is reported and does not leave an orphaned encrypted file`() {
        val failing = object : InputStream() {
            var served = 0

            override fun read(): Int = throw UnsupportedOperationException()

            override fun read(b: ByteArray, off: Int, len: Int): Int {
                if (served >= 70_000) throw IOException("storage vanished")
                val n = minOf(len, 70_000 - served)
                served += n
                return n
            }
        }
        val meta = LocalRecording(
            id = "f",
            sourceApp = "voice_note",
            clientId = null,
            startedAt = "2026-10-24T09:00:00Z",
            endedAt = "2026-10-24T09:00:00Z",
            durationMs = 0,
            mime = "audio/ogg",
            codec = "unknown",
            channels = 1,
            sampleRate = 0,
            plainSize = 0,
            sha256 = "",
            state = "queued"
        )

        assertThrows(IOException::class.java) { store.importStream(meta, failing) }

        assertNull("no metadata may exist for a failed import", store.get("f"))
        assertFalse("the half-written encrypted file would never be listed or deleted", store.encryptedFile("f").exists())
    }

    @Test
    fun `unicode metadata and long live transcripts survive storage`() {
        val segments = List(500) {
            SegmentDto(
                1 + it % 2,
                if (it % 2 ==
                    0
                ) {
                    "Me"
                } else {
                    "Client"
                },
                it * 1000L,
                it * 1000L + 900,
                "Café 你好 😀 #$it"
            )
        }
        app.storeRecording(id = "t", bytes = ByteArray(3), liveSegments = segments, liveProvider = "assemblyai", sourceApp = "voice_note")

        val read = store.get("t")!!

        assertEquals(500, read.liveSegments.size)
        assertEquals("Café 你好 😀 #499", read.liveSegments.last().text)
    }
}
