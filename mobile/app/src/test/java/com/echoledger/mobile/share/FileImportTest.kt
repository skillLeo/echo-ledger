package com.echoledger.mobile.share

import android.net.Uri
import androidx.work.WorkManager
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.sampleBytes
import com.echoledger.mobile.ui.ImportCopy
import com.echoledger.mobile.ui.UploadRow
import java.util.concurrent.TimeUnit
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Robolectric

/** Import from Voice notes & files (since 1.4): titles, types, one client and language for all, duplicates skipped. */
class FileImportTest : AppTest() {
    @Before
    fun installProvider() {
        ShareImportActivityTest.SharedFiles.files.clear()
        Robolectric.setupContentProvider(ShareImportActivityTest.SharedFiles::class.java, "test.audio")
    }

    private fun register(id: String, bytes: ByteArray, name: String): Uri {
        ShareImportActivityTest.SharedFiles.files[id] = name to bytes
        return Uri.parse("content://test.audio/files/$id")
    }

    private fun picked(id: String, bytes: ByteArray, name: String) = FileImport.describe(app, register(id, bytes, name))

    @Test
    fun `titles come from the file name and types from the name when the picker gives none`() {
        assertEquals("WhatsApp Ptt 2026-10-30 at 11.39.12", FileImport.titleOf("WhatsApp Ptt 2026-10-30 at 11.39.12.opus"))
        assertEquals("client call notes", FileImport.titleOf("client_call_notes.m4a"))
        assertEquals(".env", FileImport.titleOf(".env"))
        assertEquals("Imported file", FileImport.titleOf("   .mp3"))

        assertEquals("audio/ogg", FileImport.uploadMime("application/octet-stream", "a.opus"))
        assertEquals("audio/mp4", FileImport.uploadMime("audio/x-m4a", "a.m4a"))
        assertEquals("audio/mpeg", FileImport.uploadMime("application/octet-stream", "a.mp3"))
        assertEquals("audio/wav", FileImport.uploadMime("audio/x-wav", "a.wav"))
        assertEquals("audio/aac", FileImport.uploadMime("application/octet-stream", "a.aac"))
        assertEquals("audio/mp4", FileImport.uploadMime("video/mp4", "clip.mp4"))
        assertEquals("audio/webm", FileImport.uploadMime("video/webm", "clip.webm"))
        assertNull(FileImport.uploadMime("application/pdf", "invoice.pdf"))
    }

    @Test
    fun `each file becomes its own recording with the batch's client and language, and a repeat is skipped`() {
        val a = sampleBytes(3_000)
        val b = sampleBytes(4_000).also { it[0] = 7 }
        val files = listOf(
            picked("1", a, "Invoice call.opus"),
            picked("2", b, "kickoff.mp3"),
            picked("3", a, "Invoice call (copy).opus"),
            picked("4", sampleBytes(10), "notes.pdf")
        )
        assertEquals("Invoice call.opus", files[0].name)

        val summary = FileImport.import(app, app.graph, files, clientId = "01JCLIENT", language = "ur")

        assertEquals(2, summary.imported.size)
        assertEquals(1, summary.duplicates)
        assertEquals(1, summary.unreadable)
        assertEquals("Importing 2 files. 1 file already in your Library. 1 file could not be read.", summary.message())
        val stored = summary.imported.map { app.graph.recordings.get(it)!! }
        assertEquals(listOf("Invoice call", "kickoff"), stored.map { it.title })
        assertEquals(listOf("audio/ogg", "audio/mpeg"), stored.map { it.mime })
        assertTrue(stored.all { it.clientId == "01JCLIENT" && it.language == "ur" && it.skipDuplicate && it.state == "queued" })
        assertTrue(stored.all { it.sourceApp == "voice_note" })
        assertEquals(2, app.graph.recordings.all().size)
        summary.imported.forEach { id ->
            assertTrue(WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$id").get(10, TimeUnit.SECONDS).isNotEmpty())
        }
    }

    @Test
    fun `the sheet and the rows say what is happening`() {
        assertEquals("Import 1 file", ImportCopy.heading(1))
        assertEquals("Import all 3", ImportCopy.action(3))
        assertEquals("734 KB", ImportCopy.size(734_000))
        assertEquals("12.4 MB", ImportCopy.size(12_400_000))
        assertEquals("Already in your Library · Tap to dismiss", UploadRow.meta("duplicate"))
        assertEquals("Waiting to upload · Hold to cancel", UploadRow.meta("queued"))
        assertEquals("Uploading…", UploadRow.meta("uploading"))
        assertEquals("Nothing to import.", FileImport.Summary(emptyList(), 0, 0).message())
    }
}
