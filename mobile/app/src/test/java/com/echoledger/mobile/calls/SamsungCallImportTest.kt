package com.echoledger.mobile.calls

import com.echoledger.mobile.calls.SamsungCallImport.MediaFile
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class SamsungCallImportTest {
    private val start = 1_700_000_000_000L
    private val end = start + 125_000L

    private fun file(id: Long, name: String, addedMs: Long, durationMs: Long, path: String = "Recordings/Call/") =
        MediaFile(id, name, path, addedMs, durationMs)

    @Test
    fun `the Phone app's recording that names the caller wins`() {
        val files = listOf(
            file(1, "Call recording Ali_261029_101500.m4a", end + 2_000, 124_000),
            file(2, "Call recording +92 300 1234567_261029_101500.m4a", end + 3_000, 90_000)
        )
        assertEquals(2L, SamsungCallImport.pick(files, start, end, "+923001234567", null)?.id)
        assertEquals(1L, SamsungCallImport.pick(files, start, end, "+923331112223", "Ali")?.id)
    }

    @Test
    fun `without a name in the file, the length closest to the call's is taken`() {
        val files = listOf(
            file(1, "Call_20261029_101500.m4a", end + 2_000, 30_000),
            file(2, "Call_20261029_101501.m4a", end + 4_000, 123_000)
        )
        assertEquals(2L, SamsungCallImport.pick(files, start, end, null, null)?.id)
    }

    @Test
    fun `files outside the call folder, before the call, long after it, or empty are never taken`() {
        val files = listOf(
            file(1, "voice memo.m4a", end + 2_000, 124_000, path = "Recordings/Voice Recorder/"),
            file(2, "Call_old.m4a", start - 60_000, 124_000),
            file(3, "Call_late.m4a", end + SamsungCallImport.AFTER_END_MS + 1, 124_000),
            file(4, "Call_empty.m4a", end + 1_000, 400)
        )
        assertNull(SamsungCallImport.pick(files, start, end, "+923001234567", "Ali"))
    }
}
