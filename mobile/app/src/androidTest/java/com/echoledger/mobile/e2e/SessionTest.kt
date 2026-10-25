package com.echoledger.mobile.e2e

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.uiautomator.By
import com.echoledger.mobile.e2e.support.Backend
import com.echoledger.mobile.e2e.support.E2eTest
import com.echoledger.mobile.e2e.support.Library
import com.echoledger.mobile.e2e.support.SeedData
import com.echoledger.mobile.e2e.support.SessionPage
import com.echoledger.mobile.e2e.support.Ui
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/** Opening a finished session: transcript, copy, audio playback and deleting. */
@RunWith(AndroidJUnit4::class)
class SessionTest : E2eTest() {
    private val conversation = SeedData.CONVERSATION

    @Before
    fun seededAndPaired() {
        SeedData.ensure()
        launchPaired()
        Library.awaitLoaded()
    }

    private fun openGoodSession() {
        Library.open(SeedData.GOOD_ONE)
        SessionPage.awaitTranscript()
    }

    @Test
    fun aSessionShowsItsTitleBadgesAndTheFinalTranscriptWithSpeakersAndTimestamps() {
        openGoodSession()
        assertTrue(Ui.has(SeedData.GOOD_ONE))
        assertTrue(Ui.hasContaining("Final transcript · assemblyai"))
        assertTrue(Ui.has("Desktop") && Ui.has("Zoom"))
        conversation.forEach { (stamp, speaker, text) ->
            assertTrue("stamp $stamp", Ui.has(stamp))
            assertTrue("speaker $speaker", Ui.has(speaker))
            assertTrue("text \"$text\"", Ui.has(text))
        }
        assertTrue(Ui.has("Copy text") && Ui.has("Copy with timestamps"))
        Ui.snapshot("session-transcript")
    }

    @Test
    fun copyTextPutsTheSpeakerLabelledTranscriptOnTheClipboard() {
        openGoodSession()
        Ui.clearClipboard()
        Ui.clickText("Copy text")
        Ui.awaitText("Copied")
        val expected = conversation.joinToString("\n") { (_, speaker, text) -> "$speaker: $text" }
        assertEquals(expected, Ui.clipboardText())
    }

    @Test
    fun copyWithTimestampsPrefixesEveryLineWithItsStart() {
        openGoodSession()
        Ui.clearClipboard()
        Ui.clickText("Copy with timestamps")
        Ui.awaitText("Copied with timestamps")
        val expected = conversation.joinToString("\n") { (stamp, speaker, text) -> "[$stamp] $speaker: $text" }
        assertEquals(expected, Ui.clipboardText())
    }

    @Test
    fun playbackStartsAndThePositionAdvances() {
        openGoodSession()
        SessionPage.awaitPlayable()
        assertEquals("a fresh player starts at the beginning", 0, SessionPage.elapsedSeconds())
        assertTrue("duration of the 8 second recording", SessionPage.totalSeconds() in 7..9)
        Ui.clickDescription("Play")
        Ui.awaitCondition("the playback position to move past 1 s", Ui.LONG, 500) { SessionPage.elapsedSeconds() >= 1 }
        Ui.snapshot("session-playing")
    }

    @Test
    fun pausingHoldsThePositionOfALongRecording() {
        Backend.dropSessionsTitled("Seeded other 1")
        val title = Backend.seedLongRecording("other")
        Library.sync()
        Library.open(title)
        SessionPage.awaitPlayable()
        Ui.clickDescription("Play")
        Ui.awaitCondition("the playback position to move past 1 s", Ui.LONG, 500) { SessionPage.elapsedSeconds() >= 1 }
        Ui.clickDescription("Pause")
        Ui.awaitDescription("Play")
        Thread.sleep(6_000)
        val paused = SessionPage.elapsedSeconds()
        Thread.sleep(6_000)
        assertEquals("position holds while paused", paused, SessionPage.elapsedSeconds())
    }

    @Test
    fun tappingATimestampSeeksToThatSegment() {
        openGoodSession()
        SessionPage.awaitPlayable()
        SessionPage.tapStamp("0:06")
        Ui.awaitCondition("the player to jump to the third segment", Ui.NORMAL, 300) { SessionPage.elapsedSeconds() >= 6 }
        assertTrue("playback starts after a seek", Ui.hasDescription("Pause") || Ui.hasDescription("Play"))
    }

    @Test
    fun draggingTheSeekBarMovesTheRecordingPosition() {
        openGoodSession()
        SessionPage.awaitPlayable()
        val bar = Ui.await(By.clazz("android.widget.SeekBar"), Ui.NORMAL, "the seek bar")
        val b = bar.visibleBounds
        Ui.device.click(b.left + (b.width() * 0.75).toInt(), b.centerY())
        Ui.awaitCondition("the seek bar tap to move playback past 4 s", Ui.NORMAL, 300) { SessionPage.elapsedSeconds() >= 4 }
    }

    @Test
    fun theBackButtonReturnsToTheLibrary() {
        openGoodSession()
        Ui.device.pressBack()
        Ui.awaitText("Library")
        assertFalse(Ui.hasContaining("Final transcript"))
        Ui.clickDescription("Library")
    }

    @Test
    fun aSessionPageStaysOpenWhenThePhoneIsRotated() {
        openGoodSession()
        Ui.device.setOrientationLeft()
        Thread.sleep(2500)
        assertTrue("session page after rotation", Ui.hasContaining("Final transcript") || Ui.has("Copy text"))
        Ui.device.setOrientationNatural()
    }

    @Test
    fun aFailedTranscriptionExplainsTheFailure() {
        Library.open(SeedData.FAILED)
        Ui.awaitText("Transcription failed", Ui.LONG)
        Ui.snapshot("session-failed")
        assertFalse("no transcript actions for a failed one", Ui.has("Copy text"))
    }

    @Test
    fun aRecordingWithoutSpeechSaysSo() {
        Library.open(SeedData.SILENT)
        Ui.awaitText("The transcript is empty: no speech was detected.", Ui.LONG)
        Ui.snapshot("session-empty")
    }

    @Test
    fun deletingFromTheSessionPageRemovesItEverywhere() {
        Backend.dropSessionsTitled("Seeded other 1")
        val title = Backend.seedDesktopSessions(1, "other").single()
        Library.sync()
        Library.open(title)
        Ui.clickDescription("Delete")
        Ui.awaitText("Library", Ui.LONG)
        Library.search(title)
        Ui.awaitText("Nothing matches", Ui.LONG)
        val row = Backend.sessions().single { it.title == title }
        assertTrue("session is tombstoned on the server", row.deleted)
    }
}
