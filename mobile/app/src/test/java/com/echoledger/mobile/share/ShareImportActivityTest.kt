package com.echoledger.mobile.share

import android.content.ContentProvider
import android.content.ContentValues
import android.content.Intent
import android.database.Cursor
import android.database.MatrixCursor
import android.net.Uri
import android.os.ParcelFileDescriptor
import android.provider.OpenableColumns
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.sampleBytes
import java.io.File
import java.io.FileNotFoundException
import java.security.MessageDigest
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Ignore
import org.junit.Test
import org.robolectric.Robolectric
import org.robolectric.shadows.ShadowToast

/** Voice notes arriving through the Android share sheet: parsing, local encryption, queueing and the user's feedback. */
class ShareImportActivityTest : AppTest() {
    private fun sha(bytes: ByteArray) = MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

    /** A minimal provider standing in for the sender's: it serves file contents and the display name. */
    class SharedFiles : ContentProvider() {
        override fun onCreate() = true

        override fun query(
            uri: Uri,
            projection: Array<out String>?,
            selection: String?,
            selectionArgs: Array<out String>?,
            sortOrder: String?
        ): Cursor? {
            val entry = files[uri.lastPathSegment] ?: return null
            return MatrixCursor(arrayOf(OpenableColumns.DISPLAY_NAME)).apply { entry.first?.let { addRow(arrayOf(it)) } }
        }

        override fun openFile(uri: Uri, mode: String): ParcelFileDescriptor {
            val entry = files[uri.lastPathSegment] ?: throw FileNotFoundException(uri.toString())
            val file = File.createTempFile("shared", ".bin").apply {
                deleteOnExit()
                writeBytes(entry.second)
            }
            return ParcelFileDescriptor.open(file, ParcelFileDescriptor.MODE_READ_ONLY)
        }

        override fun getType(uri: Uri): String? = null

        override fun insert(uri: Uri, values: ContentValues?): Uri? = null

        override fun delete(uri: Uri, selection: String?, selectionArgs: Array<out String>?) = 0

        override fun update(uri: Uri, values: ContentValues?, selection: String?, selectionArgs: Array<out String>?) = 0

        companion object {
            val files = ConcurrentHashMap<String, Pair<String?, ByteArray>>()
        }
    }

    @Before
    fun installProvider() {
        SharedFiles.files.clear()
        Robolectric.setupContentProvider(SharedFiles::class.java, "test.audio")
    }

    private fun register(id: String, bytes: ByteArray, displayName: String? = null): Uri {
        SharedFiles.files[id] = displayName to bytes
        return Uri.parse("content://test.audio/files/$id")
    }
    private fun single(uri: Uri, mime: String) = Intent(Intent.ACTION_SEND).setType(mime).putExtra(Intent.EXTRA_STREAM, uri)

    private fun multiple(uris: List<Uri>, mime: String = "audio/ogg") =
        Intent(Intent.ACTION_SEND_MULTIPLE).setType(mime).putParcelableArrayListExtra(Intent.EXTRA_STREAM, ArrayList(uris))

    private fun share(intent: Intent): ShareImportActivity {
        val controller = buildActivity(ShareImportActivity::class.java, intent).create()
        val activity = controller.get()
        pumpUntil("the share to finish") { activity.isFinishing }
        return activity
    }

    private fun pair(clientId: String? = null) = runBlocking {
        app.pairLocally(server)
        if (clientId != null) app.graph.settings.update { it.copy(clientId = clientId) }
    }

    private fun workStates(id: String) =
        WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$id").get(10, TimeUnit.SECONDS).map {
            it.state
        }

    @Test
    fun `a shared voice note is encrypted locally queued for upload and confirmed`() {
        pair()
        val bytes = sampleBytes(50_000)

        share(single(register("42", bytes), "audio/ogg"))

        val stored = app.graph.recordings.all().single()
        assertEquals("voice_note", stored.sourceApp)
        assertEquals("queued", stored.state)
        assertEquals("audio/ogg", stored.mime)
        assertEquals(1, stored.channels)
        assertEquals(0, stored.sampleRate)
        assertEquals(bytes.size.toLong(), stored.plainSize)
        assertEquals(sha(bytes), stored.sha256)
        assertEquals(listOf(WorkInfo.State.ENQUEUED), workStates(stored.id))
        assertEquals("Sent 1 voice note(s) for transcription", ShadowToast.getTextOfLatestToast())
    }

    @Test
    fun `the selected client is attached to the voice note`() {
        pair(clientId = "01JCLIENT0000000000000000A")

        share(single(register("7", sampleBytes(100)), "audio/ogg"))

        assertEquals("01JCLIENT0000000000000000A", app.graph.recordings.all().single().clientId)
    }

    @Test
    fun `container types are normalised to what the server accepts`() {
        pair()
        mapOf(
            "application/ogg" to "audio/ogg",
            "audio/opus" to "audio/ogg",
            "audio/x-m4a" to "audio/mp4",
            "audio/m4a" to "audio/mp4",
            "audio/mp3" to "audio/mpeg",
            "audio/mpeg" to "audio/mpeg",
            "audio/aac" to "audio/aac",
            "audio/wav" to "audio/wav",
            "AUDIO/OGG" to "audio/ogg"
        ).entries.forEachIndexed { index, (shared, expected) ->
            share(single(register("m$index", sampleBytes(64)), shared))

            val stored = app.graph.recordings.all().single()
            assertEquals("shared as $shared", expected, stored.mime)
            app.graph.recordings.delete(stored.id)
        }
    }

    @Ignore("A6.M7: a shared media type with codec parameters is not reduced to the bare type")
    @Test
    fun `a media type with codec parameters is reduced to the bare type the server accepts`() {
        pair()

        share(single(register("codec1", sampleBytes(64)), "audio/ogg; codecs=opus"))

        assertEquals("audio/ogg", app.graph.recordings.all().single().mime)
    }

    @Test
    fun `a file named dot opus is treated as Ogg whatever type the sender claims`() {
        pair()

        share(single(register("downloads9", sampleBytes(64), displayName = "PTT-20261024-WA0001.opus"), "audio/mpeg"))

        assertEquals("audio/ogg", app.graph.recordings.all().single().mime)
    }

    @Test
    fun `several shared notes are all imported and counted`() {
        pair()
        val uris = (1..3).map { register("s$it", sampleBytes(100 * it)) }

        share(multiple(uris))

        assertEquals(setOf(100L, 200L, 300L), app.graph.recordings.all().map { it.plainSize }.toSet())
        assertEquals("Sent 3 voice note(s) for transcription", ShadowToast.getTextOfLatestToast())
    }

    @Test
    fun `an unreadable item among readable ones is skipped and the rest are sent`() {
        pair()
        val good = register("good", sampleBytes(100))
        val bad = Uri.parse("content://test.audio/files/missing")

        share(multiple(listOf(bad, good)))

        assertEquals(1, app.graph.recordings.all().size)
        assertEquals("Sent 1 voice note(s) for transcription", ShadowToast.getTextOfLatestToast())
    }

    @Test
    fun `when nothing can be read the user is told`() {
        pair()

        share(single(Uri.parse("content://test.audio/files/missing"), "audio/ogg"))

        assertTrue(app.graph.recordings.all().isEmpty())
        assertEquals("Could not read the shared audio", ShadowToast.getTextOfLatestToast())
    }

    @Test
    fun `an unpaired phone asks the user to pair first and imports nothing`() {
        val activity = share(single(register("42", sampleBytes(100)), "audio/ogg"))

        assertTrue(activity.isFinishing)
        assertTrue(app.graph.recordings.all().isEmpty())
        assertEquals("Open EchoLedger and pair this phone first", ShadowToast.getTextOfLatestToast())
    }

    @Test
    fun `sharing something that is not audio is declined`() {
        pair()

        share(single(register("image1", sampleBytes(100)), "image/png"))

        assertTrue(app.graph.recordings.all().isEmpty())
        assertEquals("No audio found to transcribe", ShadowToast.getTextOfLatestToast())
    }

    @Test
    fun `an intent with no attachment is declined`() {
        pair()

        share(Intent(Intent.ACTION_SEND).setType("audio/ogg"))

        assertTrue(app.graph.recordings.all().isEmpty())
        assertEquals("No audio found to transcribe", ShadowToast.getTextOfLatestToast())
    }

    @Test
    fun `nothing is sent to the server by the import itself`() {
        pair()
        server.onAny(FakeServer.json(500, "{}"))

        share(single(register("42", sampleBytes(100)), "audio/ogg"))

        assertTrue("the upload runs later as queued work", server.requests.isEmpty())
    }

    @Ignore("A6.M3: the share entry accepts file:// URIs")
    @Test
    fun `a file URI that points into the app's own private storage is not imported`() {
        pair()
        val secret = File(app.filesDir, "private-note.txt").apply { writeText("settings and keys that must never leave the app") }

        share(single(Uri.fromFile(secret), "audio/ogg"))

        assertTrue("another app must not be able to make EchoLedger upload its private files", app.graph.recordings.all().isEmpty())
        assertFalse(ShadowToast.getTextOfLatestToast().orEmpty().startsWith("Sent"))
    }
}
