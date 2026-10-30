package com.echoledger.mobile.ui

import android.Manifest
import android.content.Intent
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithTag
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.lifecycle.ViewModelProvider
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.core.SharedNotes
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.delaySettingsEmission
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.simulateBiometrics
import com.echoledger.mobile.testsupport.storeRecording
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.android.controller.ActivityController
import org.robolectric.annotation.Config

/** The single activity: first screen, tab navigation, the system back button, messages and what happens on open. */
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi")
class MainActivityTest : AppTest() {
    @get:Rule
    val compose = createEmptyComposeRule()

    private fun serve() {
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(200, ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem("01JA", title = "Weekly sync"))))
        )
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        server.on("GET", "/api/sessions/.+", FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null)))
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
    }

    private fun launch(paired: Boolean = true, preload: Boolean = false): ActivityController<MainActivity> {
        serve()
        runBlocking {
            if (paired) app.pairLocally(server) else app.graph.settings.update { it.copy(onboardingDone = true) }
            if (paired && preload) app.graph.library.refresh()
        }
        val controller = buildActivity(MainActivity::class.java).create()
        controller.get().simulateBiometrics(false)
        controller.start().postCreate(null).resume().visible()
        return controller
    }

    private fun present(text: String) = compose.onAllNodesWithText(text).fetchSemanticsNodes().isNotEmpty()

    @Test
    fun `opening the app arms auto-record again when it is on but Android never re-armed it`() {
        runBlocking { app.graph.settings.update { it.copy(autoRecordArmed = true) } }
        RecordingService.isArmed = false
        launch()
        var started: Intent? = null
        pumpUntil("the arm request") {
            started =
                started ?: generateSequence { shadowOf(app).nextStartedService }.firstOrNull { it.action == RecordingService.ACTION_ARM }
            started != null
        }
        assertEquals("app opened", started?.getStringExtra(RecordingService.EXTRA_FROM))
    }

    @Test
    fun `opening the app leaves auto-record alone when it is off or already armed`() {
        RecordingService.isArmed = false
        launch()
        repeat(20) { shadowOf(android.os.Looper.getMainLooper()).idle() }
        Thread.sleep(200)
        shadowOf(android.os.Looper.getMainLooper()).idle()
        assertTrue(generateSequence { shadowOf(app).nextStartedService }.none { it.action == RecordingService.ACTION_ARM })
    }

    private fun awaitText(text: String) = pumpUntil("the text \"$text\"") {
        compose.waitForIdle()
        present(text)
    }

    private fun workStates(id: String) =
        WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$id").get(10, TimeUnit.SECONDS).map {
            it.state
        }

    // ---- first screen and navigation -------------------------------------------------------------------------------------------

    @Test
    fun `an unpaired phone opens on the pairing screen without any tabs`() {
        launch(paired = false)

        awaitText("Pair this phone")

        compose.onAllNodesWithContentDescription("Record").assertCountEquals(0)
        compose.onAllNodesWithContentDescription("Settings").assertCountEquals(0)
        assertTrue(server.requests.isEmpty())
    }

    @Test
    fun `a paired phone opens on the library with four tabs and the record button`() {
        launch(preload = true)

        awaitText("Weekly sync")

        listOf("Library", "Clients", "Record", "Activity", "You").forEach { compose.onNodeWithContentDescription(it).assertIsDisplayed() }
        compose.onNodeWithContentDescription("Library").assertIsSelected()
    }

    @Test
    fun `the tabs switch between library clients activity you and the record button`() {
        launch(preload = true)
        awaitText("Weekly sync")

        compose.onNodeWithContentDescription("Record").performClick()
        awaitText("Not recording")
        pumpUntil("the library to leave") {
            compose.waitForIdle()
            !present("Weekly sync")
        }

        compose.onNodeWithContentDescription("You").performClick()
        awaitText("Privacy & security")

        compose.onNodeWithContentDescription("Clients").performClick()
        awaitText("Clients")

        compose.onNodeWithContentDescription("Activity").performClick()
        compose.onNodeWithContentDescription("Activity").assertIsSelected()

        compose.onNodeWithContentDescription("Library").performClick()
        awaitText("Weekly sync")
    }

    @Test
    fun `a settings group closes with the system back button and the tab stays on the list`() {
        val controller = launch(preload = true)
        awaitText("Weekly sync")
        compose.onNodeWithContentDescription("You").performClick()
        awaitText("Privacy & security")

        compose.onNodeWithText("Storage").performClick()
        awaitText("Storage and retention")
        compose.onAllNodesWithText("Search settings").assertCountEquals(0)

        controller.get().onBackPressedDispatcher.onBackPressed()
        awaitText("Privacy & security")
        compose.onNodeWithContentDescription("You").assertIsDisplayed()
    }

    @Test
    fun `a fresh install opens the setup wizard and skipping it hands over to pairing for good`() {
        serve()
        val controller = buildActivity(MainActivity::class.java).create()
        controller.get().simulateBiometrics(false)
        controller.start().postCreate(null).resume().visible()

        awaitText("Welcome to EchoLedger")
        compose.onAllNodesWithContentDescription("Record").assertCountEquals(0)
        assertTrue(server.requests.isEmpty())

        compose.onNodeWithText("Skip setup").performClick()

        awaitText("Pair this phone")
        assertTrue(runBlocking { app.graph.settings.current().onboardingDone })
        assertTrue(!present("Welcome to EchoLedger"))
    }

    @Test
    fun `opening a recording shows its transcript and the system back button returns to the library`() {
        val controller = launch(preload = true)
        awaitText("Weekly sync")

        compose.onNodeWithText("Weekly sync").performClick()
        awaitText("Final transcript · AssemblyAI")
        compose.onAllNodesWithContentDescription("Record").assertCountEquals(0)

        controller.get().onBackPressedDispatcher.onBackPressed()
        awaitText("Weekly sync")
        compose.onNodeWithContentDescription("Record").assertIsDisplayed()
    }

    @Test
    fun `the call setup guide opens from the library banner and closes with its back arrow`() {
        launch(preload = true)
        awaitText("WhatsApp calls are not being recorded")

        compose.onNodeWithText("WhatsApp calls are not being recorded").performClick()
        awaitText("1 · Permissions")
        compose.onAllNodesWithContentDescription("Record").assertCountEquals(0)

        compose.onNodeWithContentDescription("Back").performClick()
        awaitText("Weekly sync")
    }

    @Test
    fun `messages from the view model appear as a snackbar and are then cleared`() {
        val controller = launch(preload = true)
        awaitText("Weekly sync")
        val vm = ViewModelProvider(controller.get())[MainViewModel::class.java]

        vm.showMessage("Copied with timestamps")
        awaitText("Copied with timestamps")
        compose.mainClock.advanceTimeBy(6_000)

        pumpUntil("the message to be consumed") {
            compose.waitForIdle()
            vm.message.value == null
        }
    }

    @Test
    fun `tapping Scan QR code asks for the camera permission without crashing`() {
        val controller = launch(paired = false)
        awaitText("Pair this phone")

        compose.onNodeWithText("Scan QR code").performClick()
        compose.waitForIdle()

        val request = shadowOf(controller.get()).lastRequestedPermission
        assertEquals(listOf(Manifest.permission.CAMERA), request?.requestedPermissions?.toList())
    }

    @Test
    fun `unpairing from settings returns to the pairing screen`() {
        launch(preload = true)
        awaitText("Weekly sync")
        compose.onNodeWithContentDescription("You").performClick()
        awaitText("Devices")
        compose.onNodeWithText("Devices").performClick()
        awaitText("Unpair")

        compose.onNodeWithText("Unpair").performScrollTo().performClick()
        compose.onNode(hasText("Unpair") and hasAnyAncestor(isDialog())).performClick()

        awaitText("Pair this phone")
        compose.onAllNodesWithContentDescription("Record").assertCountEquals(0)
    }

    // ---- what opening the app triggers -----------------------------------------------------------------------------------------------

    @Test
    fun `opening the app syncs the library without waiting for a second resume`() {
        launch(preload = false)

        pumpUntil("the library request", timeoutMs = 10_000) {
            compose.waitForIdle()
            server.requestsTo("GET", "/api/library").isNotEmpty()
        }
    }

    @Test
    fun `opening the app restarts an upload that is waiting out its back-off`() {
        app.storeRecording(id = "waiting", state = "queued", lastError = "Server Error")
        launch(preload = false)

        pumpUntil("the upload to be rescheduled", timeoutMs = 10_000) {
            compose.waitForIdle()
            workStates("waiting").isNotEmpty()
        }
    }

    @Test
    fun `coming back to the app syncs the library and restarts waiting uploads`() {
        app.storeRecording(id = "waiting", state = "queued", lastError = "Server Error")
        val controller = launch(preload = true)
        awaitText("Weekly sync")
        server.requests.clear()

        controller.pause().resume()
        pumpUntil("the sync after resuming") {
            compose.waitForIdle()
            server.requestsTo("GET", "/api/library").isNotEmpty() && workStates("waiting").isNotEmpty()
        }

        assertEquals(listOf(WorkInfo.State.ENQUEUED), workStates("waiting"))
    }

    @Test
    fun `an unpaired phone never contacts a server when it is opened or resumed`() {
        val controller = launch(paired = false)
        awaitText("Pair this phone")

        controller.pause().resume()
        compose.waitForIdle()

        assertTrue(server.requests.isEmpty())
    }

    // ---- a cold start: the stored settings arrive after the first frame ----------------------------------------------------------

    private fun launchBeforeSettingsLoad(appLock: Boolean = false, paired: Boolean = true): ActivityController<MainActivity> {
        serve()
        runBlocking {
            if (paired) app.pairLocally(server)
            app.graph.settings.update { it.copy(appLockEnabled = appLock, onboardingDone = true) }
        }
        app.delaySettingsEmission(1_500)
        val controller = buildActivity(MainActivity::class.java).create()
        controller.get().simulateBiometrics(true)
        controller.start().postCreate(null).resume().visible()
        return controller
    }

    @Test
    fun `a locked app is covered once its settings have loaded after the first frame`() {
        val controller = launchBeforeSettingsLoad(appLock = true)
        val vm = ViewModelProvider(controller.get())[MainViewModel::class.java]
        pumpUntil("the settings to load", timeoutMs = 10_000) {
            compose.waitForIdle()
            vm.settings.value.appLockEnabled
        }

        pumpUntil("the lock cover", timeoutMs = 5_000) {
            compose.waitForIdle()
            present("EchoLedger is locked")
        }
    }

    @Test
    fun `opening the app syncs the library once its settings have loaded after the first frame`() {
        launchBeforeSettingsLoad()

        pumpUntil("the library request", timeoutMs = 10_000) {
            compose.waitForIdle()
            server.requestsTo("GET", "/api/library").isNotEmpty()
        }
    }

    @Test
    fun `opening the app restarts a waiting upload once its settings have loaded after the first frame`() {
        app.storeRecording(id = "waiting", state = "queued", lastError = "Server Error")
        launchBeforeSettingsLoad()

        pumpUntil("the upload to be rescheduled", timeoutMs = 10_000) {
            compose.waitForIdle()
            workStates("waiting").isNotEmpty()
        }
    }

    @Test
    fun `the library appears once the settings have loaded`() {
        launchBeforeSettingsLoad()

        pumpUntil("the library", timeoutMs = 10_000) {
            compose.waitForIdle()
            present("Search transcripts and titles")
        }
    }

    @Test
    fun `a paired phone does not flash the pairing screen while its settings are loading`() {
        launchBeforeSettingsLoad()
        compose.waitForIdle()

        assertTrue("the pairing form must not be shown to an already paired phone", !present("Pair this phone"))
    }

    @Test
    fun `while the settings load only a neutral splash is shown`() {
        val controller = launchBeforeSettingsLoad(appLock = true)
        compose.waitForIdle()
        val vm = ViewModelProvider(controller.get())[MainViewModel::class.java]

        assertEquals(SettingsState.Loading, vm.settingsState.value)
        compose.onNodeWithTag("startup-splash").assertExists()
        compose.onAllNodesWithContentDescription("Library").assertCountEquals(0)
        compose.onAllNodesWithContentDescription("Record").assertCountEquals(0)
        assertTrue("no library, pairing form or cover yet", !present("Search transcripts and titles") && !present("EchoLedger is locked"))
        assertTrue("nothing is synced before the settings are known", server.requestsTo("GET", "/api/library").isEmpty())
    }

    @Test
    fun `an unpaired phone shows the pairing screen once its settings have loaded`() {
        launchBeforeSettingsLoad(paired = false)

        pumpUntil("the pairing screen", timeoutMs = 10_000) {
            compose.waitForIdle()
            present("Pair this phone")
        }
        compose.onAllNodesWithTag("startup-splash").assertCountEquals(0)
    }

    @Test
    fun `the on-open sync runs once for one opening of the app`() {
        launch(preload = false)
        awaitText("Weekly sync")
        compose.waitForIdle()

        assertEquals(1, server.requestsTo("GET", "/api/library").size)
    }

    // ---- what the launching intent asks for ------------------------------------------------------------------------------------

    private fun pendingIntent() = Intent(app, MainActivity::class.java).putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_PENDING)

    private fun openShared(vararg localIds: String) {
        val intent = Intent(app, MainActivity::class.java).putExtra(
            MainActivity.EXTRA_OPEN,
            MainActivity.OPEN_SHARED_PREFIX + localIds.joinToString(",")
        )
        val controller = buildActivity(MainActivity::class.java, intent).create()
        controller.get().simulateBiometrics(false)
        controller.start().postCreate(null).resume().visible()
    }

    @Test
    fun `a shared voice note opens straight on its page once transcribed, with Copy for AI and no tabs`() {
        serve()
        runBlocking {
            app.pairLocally(server)
            app.graph.library.refresh()
        }
        SharedNotes.remember(app, listOf("L1"))
        SharedNotes.linkSession(app, "L1", "01JA")

        openShared("L1")

        awaitText("Copy for AI (marks unsure words)")
        assertTrue("the tabs are hidden on a shared note", !present("Settings"))
    }

    @Test
    fun `a shared voice note still on its way shows its status instead of the library`() {
        serve()
        runBlocking { app.pairLocally(server) }
        SharedNotes.remember(app, listOf("L2"))

        openShared("L2")

        awaitText("Shared voice note")
        awaitText("Preparing…")
        assertTrue(!present("Weekly sync"))
    }

    @Test
    fun `opening with open=pending shows the Record tab`() {
        serve()
        runBlocking {
            app.pairLocally(server)
            app.graph.library.refresh()
        }
        val controller = buildActivity(MainActivity::class.java, pendingIntent()).create()
        controller.get().simulateBiometrics(false)
        controller.start().postCreate(null).resume().visible()

        awaitText("Not recording")
        assertTrue(!present("Weekly sync"))
    }

    @Test
    fun `a new intent with open=pending switches a running app to the Record tab`() {
        val controller = launch(preload = true)
        awaitText("Weekly sync")

        controller.newIntent(pendingIntent())

        awaitText("Not recording")
    }
}
