package com.echoledger.mobile.ui

import androidx.compose.runtime.collectAsState
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.CaptionStyle
import com.echoledger.mobile.data.MarkerDto
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.MicTestResult
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/** Capture and control screens on the phone (B1-B5): the live recording card, the settings page and the notification. */
class CaptureUiTest : ScreenTest() {
    private fun settings() = runBlocking { app.graph.settings.current() }

    @After
    fun resetLive() {
        RecordingService.live.value = LiveRecording()
    }

    // ---- live recording card --------------------------------------------------------------------------------------

    @Test
    fun `nothing shows while not recording`() {
        show { LiveRecordingCard(LiveRecording(), settings()) }
        compose.onNodeWithText("Mark").assertDoesNotExist()
    }

    @Test
    fun `Pause and Mark go to the recording service`() {
        show { LiveRecordingCard(LiveRecording(active = true, elapsedMs = 65_000), settings()) }
        compose.onNodeWithText("1:05").assertExists()

        compose.onNodeWithText("Pause").performClick()
        assertEquals(RecordingService.ACTION_PAUSE, shadowOf(app).nextStartedService.action)
        compose.onNodeWithText("Mark").performClick()
        assertEquals(RecordingService.ACTION_MARK, shadowOf(app).nextStartedService.action)
    }

    @Test
    fun `a paused recording offers Resume and says paused time is not recorded`() {
        show { LiveRecordingCard(LiveRecording(active = true, paused = true), settings()) }
        compose.onNodeWithText("Paused").assertExists()
        compose.onNodeWithText("Paused time is not recorded or sent for captions.").assertExists()
        compose.onNodeWithText("Resume").performClick()
        assertEquals(RecordingService.ACTION_RESUME, shadowOf(app).nextStartedService.action)
    }

    @Test
    fun `the latest bookmark can be tagged, untagged and undone`() {
        runBlocking { app.graph.settings.update { it.copy(markerTags = listOf("Scope", "Risk")) } }
        val live = LiveRecording(active = true, markers = 2, lastMarker = MarkerDto(65_000, "Risk", null))
        show { LiveRecordingCard(live, settings()) }
        compose.onNodeWithText("2 bookmarks").assertExists()
        compose.onNodeWithText("Last bookmark at 1:05 · Risk").assertExists()

        compose.onNodeWithText("Scope").performClick()
        val tagged = shadowOf(app).nextStartedService
        assertEquals(RecordingService.ACTION_TAG_LAST, tagged.action)
        assertEquals("Scope", tagged.getStringExtra(RecordingService.EXTRA_TAG))
        compose.onNodeWithText("Risk").performClick()
        assertEquals("", shadowOf(app).nextStartedService.getStringExtra(RecordingService.EXTRA_TAG))
        compose.onNodeWithText("Undo").performClick()
        assertEquals(RecordingService.ACTION_UNDO_MARK, shadowOf(app).nextStartedService.action)
    }

    @Test
    fun `the silent other side is called out with the meters`() {
        show { LiveRecordingCard(LiveRecording(active = true, twoSided = true, clientLevel = 0f, farSideSilentSeconds = 30), settings()) }
        compose.onNodeWithContentDescription("Warning: not hearing the other side for 30 seconds").assertExists()
        compose.onNodeWithContentDescription("Client level 0 percent").assertExists()
    }

    // ---- settings page --------------------------------------------------------------------------------------------

    @Test
    fun `Settings opens the capture page and back returns`() {
        show { SettingsScreen(vm, onSetup = {}) }
        compose.onNodeWithText("Capture and captions").performClick()
        compose.onNodeWithText("Auto-record apps").assertExists()
        compose.onNodeWithContentDescription("Back").performClick()
        compose.onNodeWithText("Auto-record apps").assertDoesNotExist()
    }

    @Test
    fun `switching an app off is saved and explained`() {
        runBlocking { app.graph.settings.update { it.copy(autoRecordArmed = true) } }
        pumpUntil("settings to load") { vm.settings.value.autoRecordArmed }
        show {
            CaptureSettingsPage(vm, vm.settings.collectAsState().value, helperConnected = true, recording = false, onSetup = {
            }, onBack = {})
        }
        compose.onNodeWithContentDescription("Auto-record WhatsApp").assertIsOn().performClick()
        pumpUntil("the switch to be saved") { settings().autoRecordApps["whatsapp"] == false }
        assertTrue(settings().autoRecordApps.getValue("whatsapp").not())
    }

    @Test
    fun `caption style choices are saved`() {
        show { CaptionStyleSection(vm, vm.settings.collectAsState().value) }
        compose.onNodeWithText("Large").performClick()
        pumpUntil("the size to be saved") { settings().captionStyle.size == "large" }
        compose.onNodeWithText("Top").performClick()
        pumpUntil("the position to be saved") { settings().captionStyle.position == "top" }
        assertEquals(CaptionStyle(size = "large", position = "top"), settings().captionStyle)
    }

    @Test
    fun `the microphone page lists the permissions and the silence warning`() {
        show { CaptureHealthSection(vm, settings(), helperConnected = false, recording = true, onSetup = {}) }
        compose.onNodeWithText("Call capture helper").assertExists()
        compose.onNodeWithText("Finish the current recording to run a test.").assertExists()
        compose.onNodeWithText("Warn me when the other side is silent").assertExists()
        compose.onNodeWithText("Automatic").performClick()
        assertEquals(null, settings().inputDevice)
    }

    @Test
    fun `the test verdict is plain`() {
        assertTrue(micTestVerdict(MicTestResult(0.2f, true, ShortArray(0))).startsWith("Your microphone is working"))
        assertTrue(micTestVerdict(MicTestResult(0f, false, ShortArray(0))).startsWith("We did not hear your microphone"))
    }

    // ---- notification -----------------------------------------------------------------------------------------------

    @Test
    fun `the recording notification offers Pause, Mark and Stop, and Resume while paused`() {
        val running = Notifications.recording(app, "Recording call", "Both sides")
        assertEquals(listOf("Pause", "Mark", "Stop"), running.actions.map { it.title.toString() })
        val paused = Notifications.recording(app, "Recording call", "Both sides", paused = true)
        assertEquals(listOf("Resume", "Mark", "Stop"), paused.actions.map { it.title.toString() })
        assertEquals("Paused · Recording call", shadowOf(paused).contentTitle.toString())
    }
}
