package com.echoledger.mobile.e2e.support

import android.content.ContentValues
import android.content.Intent
import android.net.Uri
import android.provider.MediaStore
import androidx.test.uiautomator.By

/**
 * Hands audio to the app the way another app (a recorder, a chat) does: a `content://` URI in ACTION_SEND, picked from the
 * system share sheet. The audio files are the ones pushed to [FIXTURE_DIR] with adb.
 */
object Sharing {
    const val FIXTURE_DIR = "/data/local/tmp/e2e"
    const val SHORT_NOTE = "speech-8s.wav"
    const val LONG_NOTE = "speech-40s.wav"
    const val SHARE_LABEL = "Transcribe with EchoLedger"
    private const val IMPORT_ACTIVITY = "com.echoledger.mobile.share.ShareImportActivity"

    private val inserted = mutableListOf<Uri>()
    private val resolver get() = Ui.context.contentResolver

    fun fixturesPresent(): Boolean = listOf(SHORT_NOTE, LONG_NOTE).all { Shell.fileExists("$FIXTURE_DIR/$it") }

    /** Copies a pushed fixture into the media library and returns its content URI. */
    fun importFixture(fixture: String): Uri {
        val bytes = Shell.readFile("$FIXTURE_DIR/$fixture")
        val values = ContentValues().apply {
            val name = fixture.substringBeforeLast('.')
            val extension = fixture.substringAfterLast('.')
            put(MediaStore.Audio.Media.DISPLAY_NAME, "$name-${System.nanoTime()}.$extension")
            put(MediaStore.Audio.Media.MIME_TYPE, "audio/wav")
            put(MediaStore.Audio.Media.RELATIVE_PATH, "Music/EchoLedgerE2E")
            put(MediaStore.Audio.Media.IS_PENDING, 1)
        }
        val collection = MediaStore.Audio.Media.getContentUri(MediaStore.VOLUME_EXTERNAL_PRIMARY)
        val uri = resolver.insert(collection, values) ?: error("could not create a media entry for $fixture")
        resolver.openOutputStream(uri)!!.use { it.write(bytes) }
        resolver.update(uri, ContentValues().apply { put(MediaStore.Audio.Media.IS_PENDING, 0) }, null, null)
        inserted += uri
        return uri
    }

    /**
     * Waits until [count] shared notes are queued on the phone or already uploaded. The confirmation toast is not visible
     * to accessibility tools, so the effect is what is observed.
     */
    fun awaitImported(count: Int = 1) {
        Ui.awaitCondition("$count shared note(s) to be queued or uploaded", Ui.NORMAL, 300) {
            AppState.recordings().size >= count || runCatching { Backend.liveSessions().size >= count }.getOrDefault(false)
        }
    }

    fun cleanUp() {
        inserted.forEach { runCatching { resolver.delete(it, null, null) } }
        inserted.clear()
    }

    private fun sendIntent(uris: List<Uri>, mime: String): Intent {
        val intent = if (uris.size == 1) {
            Intent(Intent.ACTION_SEND).putExtra(Intent.EXTRA_STREAM, uris.single())
        } else {
            Intent(Intent.ACTION_SEND_MULTIPLE).putParcelableArrayListExtra(Intent.EXTRA_STREAM, ArrayList(uris))
        }
        return intent.setType(mime).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    }

    /** Opens the system share sheet for [uris] and taps the EchoLedger entry. */
    fun shareThroughShareSheet(uris: List<Uri>, mime: String = "audio/wav") {
        Ui.context.startActivity(Intent.createChooser(sendIntent(uris, mime), "Share voice note").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        val entry = Ui.device.wait(androidx.test.uiautomator.Until.findObject(By.text(SHARE_LABEL)), Ui.NORMAL)
            ?: throw AssertionError("EchoLedger is not offered in the share sheet\n${Ui.hierarchySummary()}")
        entry.click()
    }

    /** Sends the intent straight to the app's share receiver (no chooser), for inputs a share sheet would not offer. */
    fun sendToReceiver(intent: Intent) {
        Ui.context.startActivity(intent.setClassName(Ui.PACKAGE, IMPORT_ACTIVITY).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }

    fun plainSend(uri: Uri?, mime: String): Intent = Intent(Intent.ACTION_SEND).setType(mime).apply {
        if (uri != null) putExtra(Intent.EXTRA_STREAM, uri)
    }
}
