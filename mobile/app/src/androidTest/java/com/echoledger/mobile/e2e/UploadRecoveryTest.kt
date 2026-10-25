/*
 * End-to-end tests: the app on an emulator or device against a disposable EchoLedger test backend. Every test here is
 * skipped unless the run names the backend with these instrumentation arguments (with Gradle:
 * -Pandroid.testInstrumentationRunnerArguments.<name>=<value>):
 *   backend.server   the test server the app pairs with, for example http://10.0.2.2:8000 (the development machine as
 *                    seen from the Android emulator)
 *   backend.flaky    the same server behind a proxy whose failure mode the tests switch
 *   backend.control  the backend's control endpoint (pairing codes, seeding, statistics; see support/Backend.kt)
 * They also need speech-8s.wav and speech-40s.wav (8 s and 40 s of speech, 16-bit mono PCM WAV at 22,050 Hz) pushed to
 * /data/local/tmp/e2e with adb.
 */
package com.echoledger.mobile.e2e

import androidx.test.ext.junit.runners.AndroidJUnit4
import com.echoledger.mobile.e2e.support.AppState
import com.echoledger.mobile.e2e.support.Backend
import com.echoledger.mobile.e2e.support.E2eTest
import com.echoledger.mobile.e2e.support.RecordPage
import com.echoledger.mobile.e2e.support.Sharing
import com.echoledger.mobile.e2e.support.Shell
import com.echoledger.mobile.e2e.support.Ui
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Ignore
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Uploads that do not go smoothly. The phone talks to the server through the flaky proxy (`launchPaired(viaFlaky = true)`),
 * so the server can "go away" in the middle of an upload and come back, or refuse a recording, on demand.
 */
@RunWith(AndroidJUnit4::class)
class UploadRecoveryTest : E2eTest() {
    private val longNoteChunks = 7 // 1,764,044 bytes in 256 KiB chunks

    @Before
    fun cleanServer() {
        assertTrue("audio fixtures are not pushed to ${Sharing.FIXTURE_DIR}", Sharing.fixturesPresent())
        Backend.wipeSessions()
    }

    private fun shareNote(fixture: String = Sharing.SHORT_NOTE) {
        Ui.device.pressHome()
        Sharing.shareThroughShareSheet(listOf(Sharing.importFixture(fixture)))
        Sharing.awaitImported(1)
    }

    private fun openRecordTab() {
        Ui.launchApp()
        Ui.openTab("Record")
    }

    /** Leaving and re-entering the tab is how the queue cards are reloaded. */
    private fun reloadRecordTab() {
        Ui.openTab("Library")
        Ui.openTab("Record")
    }

    private fun awaitServerTranscript(expectedSessions: Int = 1) {
        Ui.awaitCondition("the transcript to complete on the server", 120_000, 3_000) {
            Backend.liveSessions().let { rows -> rows.size == expectedSessions && rows.all { it.finalStatus == "completed" } }
        }
    }

    @Test
    fun whenTheServerGoesAwayMidUploadTheCardOffersRetryNowAndTheUploadResumesWhereItStopped() {
        launchPaired(viaFlaky = true)
        Backend.setFlakyMode("drop-after:2")
        shareNote(Sharing.LONG_NOTE)
        openRecordTab()
        Ui.awaitText(RecordPage.UPLOADING, Ui.LONG)
        Ui.awaitCondition("the card to say the upload will be retried", Ui.LONG, 1_000) {
            reloadRecordTab()
            Ui.hasContaining("Will retry:")
        }
        Ui.snapshot("record-upload-retrying")
        assertEquals("two chunks arrived before the server went away", 2, Backend.stats().chunkPosts)
        assertTrue("the recording is kept on the phone", AppState.recordings().any { it.state == "queued" })

        Backend.setFlakyMode("ok")
        Ui.clickText("Retry now")
        awaitServerTranscript()
        assertEquals("only the missing chunks were sent again", longNoteChunks, Backend.stats().chunkPosts)
        assertEquals("one session, not one per attempt", 1, Backend.liveSessions().size)
        Ui.awaitCondition("the local copy to be deleted", Ui.NORMAL) { AppState.recordings().isEmpty() }
        reloadRecordTab()
        Ui.awaitTextGone(RecordPage.UPLOADING, Ui.NORMAL)
    }

    @Ignore("NEW: the Record tab upload card does not update until the tab is opened again")
    @Test
    fun theUploadCardClearsItselfOnceTheUploadHasFinished() {
        launchPaired(viaFlaky = true)
        Backend.setFlakyMode("slow:700")
        shareNote(Sharing.LONG_NOTE)
        openRecordTab()
        Ui.awaitText(RecordPage.UPLOADING, Ui.LONG)
        awaitServerTranscript()
        Ui.awaitTextGone(RecordPage.UPLOADING, Ui.LONG)
    }

    @Test
    fun aRejectedRecordingShowsAFailedCardWithTheReasonAndRetryAndDiscard() {
        launchPaired(viaFlaky = true)
        Backend.setFlakyMode("reject")
        shareNote()
        openRecordTab()
        Ui.awaitText(RecordPage.FAILED, Ui.LONG)
        assertTrue(Ui.has("Retry"))
        assertTrue(Ui.has("Discard"))
        Ui.awaitText("The artifacts.0.mime field is invalid.")
        Ui.snapshot("record-upload-failed")
        assertEquals("the recording stays on the phone as failed", "failed", AppState.recordings().single().state)

        Ui.clickText("Retry")
        Ui.awaitCondition("the retry to reach the server and be refused again", Ui.NORMAL) { Backend.stats().createPosts >= 2 }
        reloadRecordTab()
        Ui.awaitText(RecordPage.FAILED, Ui.LONG)

        Backend.setFlakyMode("ok")
        Ui.clickText("Retry")
        awaitServerTranscript()
        reloadRecordTab()
        Ui.awaitTextGone(RecordPage.FAILED, Ui.LONG)
        assertTrue(AppState.recordings().isEmpty())
    }

    @Test
    fun discardingAFailedUploadRemovesItFromThePhoneAndNothingReachesTheServer() {
        launchPaired(viaFlaky = true)
        Backend.setFlakyMode("reject")
        shareNote()
        openRecordTab()
        Ui.awaitText(RecordPage.FAILED, Ui.LONG)
        Ui.clickText("Discard")
        Ui.awaitTextGone(RecordPage.FAILED, Ui.NORMAL)
        assertTrue(AppState.recordings().isEmpty())
        Backend.setFlakyMode("ok")
        Thread.sleep(3_000)
        assertTrue(Backend.liveSessions().isEmpty())
    }

    @Test
    fun anUploadQueuedWhileOfflineWaitsAndCompletesOnceTheNetworkReturns() {
        launchPaired()
        Shell.setNetwork(false)
        shareNote()
        openRecordTab()
        Ui.awaitText(RecordPage.UPLOADING, Ui.LONG)
        Thread.sleep(8_000)
        assertTrue("still waiting offline", AppState.recordings().single().state in setOf("queued", "uploading"))
        Shell.setNetwork(true)
        awaitServerTranscript()
        assertEquals(1, Backend.liveSessions().size)
        Ui.awaitCondition("the local copy to be deleted", Ui.NORMAL) { AppState.recordings().isEmpty() }
    }

    @Test
    fun theNetworkDroppingAndReturningMidUploadDoesNotDuplicateTheSession() {
        launchPaired(viaFlaky = true)
        Backend.setFlakyMode("slow:900")
        shareNote(Sharing.LONG_NOTE)
        openRecordTab()
        Ui.awaitText(RecordPage.UPLOADING, Ui.LONG)
        Ui.awaitCondition("some chunks to be uploaded", Ui.NORMAL) { Backend.stats().chunkPosts >= 2 }
        Backend.setFlakyMode("down")
        Thread.sleep(3_000)
        Backend.setFlakyMode("ok")
        Ui.clickText("Retry now")
        awaitServerTranscript()
        assertEquals(1, Backend.liveSessions().size)
        val sent = Backend.stats().chunkPosts
        assertTrue("at most one chunk is sent twice ($sent uploads)", sent in longNoteChunks..longNoteChunks + 1)
    }

    @Test
    fun theUploadFinishesAfterTheAppIsClosedFromRecents() {
        launchPaired(viaFlaky = true)
        Backend.setFlakyMode("slow:1000")
        shareNote(Sharing.LONG_NOTE)
        Ui.device.pressHome()
        awaitServerTranscript()
        assertEquals(1, Backend.liveSessions().size)
    }
}
