package com.echoledger.mobile.ui.v2

import android.content.Intent
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTextReplacement
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.share.FileImport
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.activity.ActivityActions
import com.echoledger.mobile.ui.v2.activity.V2ActivityScreen
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.live.V2ImportSheet
import com.echoledger.mobile.ui.v2.live.V2VoiceNoteScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * The record flow on the v2 components (docs/v2/audit/parity.csv PH-OTH-006, 007, 008, 010): the voice note's Record,
 * Pause and Resume, Stop, Discard and Undo reach the recording service; the timer and waveform; Import files with one
 * project and language for all; "Uploading 2 recordings…" with Retry now; the Bluetooth and never-automatic notes; a
 * viewer records nothing; and a call waiting in Activity is renamed and filed before Keep, which uploads it so.
 */
class V2RecordFlowParityTest : ScreenTest() {
    @After
    fun idle() {
        RecordingService.live.value = LiveRecording()
        RecordingService.pillState.value = PillState(mode = PillState.Mode.HIDDEN)
    }

    private fun voice(language: EchoLanguage = EchoLanguage.English, headset: Boolean = false) = show {
        EchoV2Theme(darkTheme = false, language = language) {
            val toasts = rememberEchoToastState()
            CompositionLocalProvider(LocalEchoToasts provides toasts) {
                androidx.compose.foundation.layout.Box {
                    V2VoiceNoteScreen(vm, onBack = {}, headsetConnected = { headset })
                    EchoToastHost(toasts)
                }
            }
        }
    }

    private fun shows(words: String) = pumpUntil("\"$words\"") {
        compose.mainClock.advanceTimeByFrame()
        compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    private fun press(words: String) {
        compose.onAllNodes(hasText(words) and hasClickAction()).onFirst().performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    /** The action the last control sent to the recording service. */
    private fun sent(): String? {
        var last: Intent? = null
        while (true) last = shadowOf(app).nextStartedService ?: break
        return last?.action
    }

    @Test
    fun recordPauseResumeStopAndDiscardWithUndoGoToTheRecordingService() {
        voice()
        shows("Not recording")
        shows("0:00")
        press("Record")
        assertEquals(RecordingService.ACTION_TOGGLE, sent())
        // Recording: the timer runs and the three controls are there.
        RecordingService.live.value = LiveRecording(active = true, elapsedMs = 134_000, meLevel = 0.6f)
        shows("Recording")
        shows("2:14")
        press("Pause")
        assertEquals(RecordingService.ACTION_PAUSE, sent())
        RecordingService.live.value = LiveRecording(active = true, paused = true, elapsedMs = 134_000)
        shows("Paused")
        press("Resume")
        assertEquals(RecordingService.ACTION_RESUME, sent())
        press("Discard")
        assertEquals(RecordingService.ACTION_DISCARD_NOW, sent())
        // Discarding: Undo in its place until the pill's time ends.
        RecordingService.pillState.value = PillState(mode = PillState.Mode.HIDDEN, discardAtMs = System.currentTimeMillis() + 5_000)
        shows("Undo")
        press("Undo")
        assertEquals(RecordingService.ACTION_UNDO_DISCARD, sent())
        press("Stop")
        assertEquals(RecordingService.ACTION_TOGGLE, sent())
        // The waveform is there, named for screen readers.
        assertEquals(1, compose.onAllNodes(hasContentDescription("Sound level")).fetchSemanticsNodes().size)
        // Import is offered only before recording.
        compose.onAllNodesWithText("Import files").assertCountEquals(0)
    }

    @Test
    fun beforeRecordingTheNotesAndImportFilesOpensThePicker() {
        voice(headset = true)
        shows("A Bluetooth headset is connected. Accuracy is lower with Bluetooth mics.")
        shows("Voice notes are never recorded automatically")
        press("Import files")
        val picker = shadowOf(app).nextStartedActivity
        assertEquals(Intent.ACTION_OPEN_DOCUMENT, picker.action)
        assertEquals(listOf("audio/*", "video/*"), picker.getStringArrayExtra(Intent.EXTRA_MIME_TYPES)!!.toList())
        assertTrue(picker.getBooleanExtra(Intent.EXTRA_ALLOW_MULTIPLE, false))
    }

    @Test
    fun withoutAHeadsetThereIsNoBluetoothNote() {
        voice()
        shows("Import files")
        compose.onAllNodesWithText("Bluetooth", substring = true).assertCountEquals(0)
    }

    @Test
    fun importTakesOneProjectAndLanguageForAllAndSaysWhatHappened() {
        val files = listOf(
            FileImport.Picked(android.net.Uri.parse("content://x/1"), "Rate call.m4a", "audio/mp4", 2_000_000),
            FileImport.Picked(android.net.Uri.parse("content://x/2"), "Visit.mp3", "audio/mpeg", 1_000_000)
        )
        var asked: Pair<String?, String?>? = null
        var closed: String? = "open"
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2ImportSheet(
                        files,
                        listOf(com.echoledger.mobile.data.ClientDto("01JCLIENT0000000000000000A", "Acme Holdings")),
                        onImport = { client, language ->
                            asked = client to language
                            FileImport.Summary(imported = listOf("a"), duplicates = 1, unreadable = 0)
                        },
                        onDone = { closed = it }
                    )
                }
            }
        }
        shows("Import 2 files")
        shows("Rate call")
        press("Acme Holdings")
        press("Urdu (with English words)")
        compose.onAllNodes(hasText("Import 2 files") and hasClickAction()).onFirst().performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("imported") { closed != "open" }
        assertEquals("01JCLIENT0000000000000000A" to "ur", asked)
        assertEquals("Importing 1 file. 1 file already in your Library.", closed)
    }

    @Test
    fun uploadingRecordingsSayHowManyAndRetryNowSendsThemAgain() {
        app.storeRecording(id = "a", state = "queued", lastError = "timeout")
        app.storeRecording(id = "b", state = "uploading")
        voice()
        shows("Uploading 2 recordings… Will retry: timeout")
        press("Retry now")
        pumpUntil("queued again") {
            WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-a").get(10, TimeUnit.SECONDS)
                .any { it.state == WorkInfo.State.ENQUEUED }
        }
    }

    @Test
    fun aViewerRecordsNothing() {
        vm.updateSettings { it.copy(plan = com.echoledger.mobile.core.PlanState(role = "viewer", workspace = "Acme team")) }
        pumpUntil("viewer") { vm.settings.value.plan.viewer }
        voice()
        shows("You are a viewer in Acme team")
        compose.onAllNodes(hasText("Record") and hasClickAction()).assertCountEquals(0)
    }

    @Test
    fun inUrduTheVoiceNoteSpeaksUrdu() {
        voice(EchoLanguage.Urdu)
        shows("ریکارڈنگ نہیں ہو رہی")
        shows("فائلیں امپورٹ کریں")
        // The timer stays in 0-9 digits.
        shows("0:00")
    }

    // A call waiting for Keep or Discard (Activity › Needs action).

    @Test
    fun aWaitingCallIsRenamedAndFiledBeforeKeepAndUploadsSo() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/activity.*", FakeServer.json(200, """{"data":[],"unread_count":0}"""))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        vm.refresh()
        pumpUntil("projects") { vm.clients.value.isNotEmpty() }
        val project = vm.clients.value.first()
        app.storeRecording(id = "call-1", state = "pending_decision", sourceApp = "whatsapp")
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2ActivityScreen(vm, ActivityActions(onOpenRecording = {}, onFix = {}, onOpenUntranscribed = {}))
                }
            }
        }
        shows("WhatsApp")
        // A tap on the call renames it.
        compose.onAllNodes(hasText("WhatsApp", substring = true) and hasClickAction()).onFirst()
            .performSemanticsAction(SemanticsActions.OnClick)
        compose.onAllNodes(hasSetTextAction()).onFirst().performTextReplacement("Rate for 40 cartons")
        press("Save")
        pumpUntil("renamed") { app.graph.recordings.get("call-1")?.title == "Rate for 40 cartons" }
        shows("Rate for 40 cartons")
        // Project ▾ files it.
        press("Project")
        press(project.name)
        pumpUntil("filed") { app.graph.recordings.get("call-1")?.clientId == project.id }
        shows(project.name)
        press("Keep")
        pumpUntil("kept") { app.graph.recordings.get("call-1")?.state == "queued" }
        val kept = app.graph.recordings.get("call-1")!!
        assertEquals("Rate for 40 cartons", kept.title)
        assertEquals(project.id, kept.clientId)
        assertNull("Keep in Activity leaves the server's default (transcribe)", kept.transcribe)
    }

    @Test
    fun theAfterCallCardsTitleOpensActivityWhereTheCallIsRenamedAndFiled() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/activity.*", FakeServer.json(200, """{"data":[],"unread_count":0}"""))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        app.storeRecording(id = "call-1", state = "pending_decision", sourceApp = "whatsapp")
        var open by androidx.compose.runtime.mutableIntStateOf(0)
        val classic = com.echoledger.mobile.ui.v2.shell.ClassicScreens(
            home = {},
            library = {},
            live = {},
            ask = {},
            settings = {},
            upgrade = {}
        )
        show {
            com.echoledger.mobile.ui.v2.shell.EchoV2Shell(
                vm,
                com.echoledger.mobile.ui.v2.shell.UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true)),
                darkTheme = false,
                classic = classic,
                onSwitchToClassic = {},
                language = EchoLanguage.English,
                openActivity = open
            )
        }
        compose.waitForIdle()
        compose.onAllNodesWithText("Keep").assertCountEquals(0)
        // The card's title (DecisionPopup opens the app at "pending"): in the v2 shell that is Activity.
        open = 1
        shows("Activity")
        shows("Keep")
        compose.onAllNodes(hasText("WhatsApp", substring = true) and hasClickAction()).onFirst()
            .performSemanticsAction(SemanticsActions.OnClick)
        shows("Rename")
    }

    @Suppress("unused")
    private val defaults = AppSettings()
}
