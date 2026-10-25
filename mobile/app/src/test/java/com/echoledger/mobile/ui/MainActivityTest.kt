package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.lifecycle.ViewModelProvider
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.MainActivity
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
            if (paired) app.pairLocally(server)
            if (paired && preload) app.graph.library.refresh()
        }
        val controller = buildActivity(MainActivity::class.java).create()
        controller.get().simulateBiometrics(false)
        controller.start().postCreate(null).resume().visible()
        return controller
    }

    private fun present(text: String) = compose.onAllNodesWithText(text).fetchSemanticsNodes().isNotEmpty()

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
    fun `a paired phone opens on the library with the three tabs`() {
        launch(preload = true)

        awaitText("Weekly sync")

        compose.onNodeWithContentDescription("Library").assertIsDisplayed()
        compose.onNodeWithContentDescription("Record").assertIsDisplayed()
        compose.onNodeWithContentDescription("Settings").assertIsDisplayed()
    }

    @Test
    fun `the tabs switch between library record and settings`() {
        launch(preload = true)
        awaitText("Weekly sync")

        compose.onNodeWithContentDescription("Record").performClick()
        awaitText("Not recording")
        pumpUntil("the library to leave") {
            compose.waitForIdle()
            !present("Weekly sync")
        }

        compose.onNodeWithContentDescription("Settings").performClick()
        awaitText("Client keyterms")

        compose.onNodeWithContentDescription("Library").performClick()
        awaitText("Weekly sync")
    }

    @Test
    fun `opening a recording shows its transcript and the system back button returns to the library`() {
        val controller = launch(preload = true)
        awaitText("Weekly sync")

        compose.onNodeWithText("Weekly sync").performClick()
        awaitText("Final transcript · assemblyai")
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
    fun `unpairing from settings returns to the pairing screen`() {
        launch(preload = true)
        awaitText("Weekly sync")
        compose.onNodeWithContentDescription("Settings").performClick()
        awaitText("Unpair")

        compose.onNodeWithText("Unpair").performClick()

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
            app.graph.settings.update { it.copy(appLockEnabled = appLock) }
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
}
