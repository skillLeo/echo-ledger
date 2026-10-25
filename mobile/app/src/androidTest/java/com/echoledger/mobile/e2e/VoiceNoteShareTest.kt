package com.echoledger.mobile.e2e

import android.net.Uri
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.uiautomator.By
import com.echoledger.mobile.e2e.support.AppState
import com.echoledger.mobile.e2e.support.Backend
import com.echoledger.mobile.e2e.support.E2eTest
import com.echoledger.mobile.e2e.support.Library
import com.echoledger.mobile.e2e.support.SessionPage
import com.echoledger.mobile.e2e.support.Sharing
import com.echoledger.mobile.e2e.support.Ui
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/** Voice notes arrive only through the share sheet: share, upload, transcript, read, copy, play, seek. */
@RunWith(AndroidJUnit4::class)
class VoiceNoteShareTest : E2eTest() {
    private val fullText = "This is a test of the EchoLedger transcription system. Loud and clear. Go ahead. " +
        "Please call me back tomorrow at three thirty. Will do, thanks."

    @Before
    fun fixtures() {
        assertTrue("audio fixtures are not pushed to ${Sharing.FIXTURE_DIR}", Sharing.fixturesPresent())
        Backend.wipeSessions()
    }

    /** Waits for the session of a shared note to show "Ready" in the library (syncing as a person would). */
    private fun awaitReadyRow() {
        Ui.awaitCondition("the voice note to be Ready in the library", 240_000, 6_000) {
            Library.sync()
            Thread.sleep(800)
            Ui.has("Ready")
        }
    }

    @Test
    fun aSharedVoiceNoteIsUploadedTranscribedAndCanBeReadCopiedAndPlayed() {
        launchPaired(viaFlaky = true) // the proxy passes everything through in "ok" mode and counts the chunk uploads
        Ui.awaitText("No recordings yet")
        Ui.device.pressHome()
        val uri = Sharing.importFixture(Sharing.SHORT_NOTE)
        Sharing.shareThroughShareSheet(listOf(uri))
        Sharing.awaitImported(1)

        Ui.launchApp()
        Ui.awaitText("Library")
        awaitReadyRow()
        assertTrue("voice note badge", Ui.has("Voice note"))
        assertTrue("phone badge", Ui.device.findObjects(By.text("Phone")).size >= 2)

        val server = Backend.liveSessions().single()
        assertEquals("voice_note", server.sourceApp)
        assertEquals("completed", server.finalStatus)
        assertEquals("the 345 KB note is two 256 KiB chunks", 2, Backend.stats().chunkPosts)
        assertTrue("the local encrypted copy is deleted once the server has it", AppState.recordings().isEmpty())

        Library.row("Voice note").click()
        SessionPage.awaitTranscript()
        assertTrue(Ui.hasContaining("This is a test of the EchoLedger transcription system."))
        Ui.clearClipboard()
        Ui.clickText("Copy text")
        Ui.awaitText("Copied")
        assertEquals(fullText, Ui.clipboardText())

        SessionPage.awaitPlayable()
        Ui.clickDescription("Play")
        Ui.awaitDescription("Pause")
        Ui.awaitCondition("playback to advance", Ui.NORMAL, 400) { SessionPage.elapsedSeconds() >= 1 }
        Ui.clickDescription("Pause")
    }

    @Test
    fun severalVoiceNotesSharedTogetherAreAllQueuedAndUploaded() {
        launchPaired()
        Ui.device.pressHome()
        val uris = listOf(Sharing.importFixture(Sharing.SHORT_NOTE), Sharing.importFixture(Sharing.SHORT_NOTE))
        Sharing.shareThroughShareSheet(uris)
        Sharing.awaitImported(2)
        Ui.launchApp()
        Ui.awaitCondition("both notes to reach the server", 90_000, 3_000) {
            Backend.liveSessions().count { it.finalStatus == "completed" } == 2
        }
        assertEquals(2, Backend.liveSessions().count { it.sourceApp == "voice_note" })
        assertEquals("one session per note, none duplicated", 2, Backend.liveSessions().map { it.clientRef }.distinct().size)
    }

    @Test
    fun sharingBeforePairingStoresAndUploadsNothing() {
        val uri = Sharing.importFixture(Sharing.SHORT_NOTE)
        Sharing.shareThroughShareSheet(listOf(uri))
        Thread.sleep(6_000)
        assertTrue(AppState.recordings().isEmpty())
        assertTrue(Backend.liveSessions().isEmpty())
    }

    @Test
    fun anAudioShareWithoutAnyFileQueuesNothingAndDoesNotCrash() {
        launchPaired()
        Sharing.sendToReceiver(Sharing.plainSend(null, "audio/wav"))
        Thread.sleep(4_000)
        assertTrue(AppState.recordings().isEmpty())
        assertTrue(Backend.liveSessions().isEmpty())
    }

    @Test
    fun aSharedFileThatCannotBeReadIsNotQueuedAndDoesNotCrash() {
        launchPaired()
        Sharing.sendToReceiver(Sharing.plainSend(Uri.parse("file:///sdcard/Music/no-such-voice-note.wav"), "audio/wav"))
        Thread.sleep(4_000)
        assertTrue(AppState.recordings().isEmpty())
        assertTrue(Backend.liveSessions().isEmpty())
    }

    @Test
    fun aFileUriPointingAtTheAppsOwnPrivateFilesIsNotImported() {
        launchPaired()
        val secret = File(Ui.context.filesDir, "private-notes.bin").apply { writeBytes(ByteArray(2048) { (it % 251).toByte() }) }
        try {
            Sharing.sendToReceiver(Sharing.plainSend(Uri.fromFile(secret), "audio/wav"))
            Thread.sleep(6_000)
            val queuedOrUploaded = AppState.recordings().size + Backend.liveSessions().size
            assertEquals("a file:// share of a private file must not be read and uploaded", 0, queuedOrUploaded)
        } finally {
            secret.delete()
        }
    }

    @Test
    fun theLocalCopyIsEncryptedAtRestWhileTheUploadIsWaiting() {
        launchPaired(viaFlaky = true)
        Backend.setFlakyMode("down")
        Ui.device.pressHome()
        val uri = Sharing.importFixture(Sharing.SHORT_NOTE)
        Sharing.shareThroughShareSheet(listOf(uri))
        Sharing.awaitImported(1)
        Ui.awaitCondition("the note to be stored locally", Ui.NORMAL) { AppState.recordings().isNotEmpty() }
        val stored = AppState.recordings().single()
        val file = File(Ui.context.noBackupFilesDir, "recordings/${stored.id}.elr")
        assertTrue(file.exists())
        val head = file.readBytes().copyOf(12)
        assertFalse("stored audio must not start with a RIFF/WAVE header", String(head, Charsets.ISO_8859_1).startsWith("RIFF"))
        assertEquals("plain size is recorded for chunking", 352_844L, stored.plainSize)
    }
}
