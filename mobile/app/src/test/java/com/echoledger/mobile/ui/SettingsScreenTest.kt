package com.echoledger.mobile.ui

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.fragment.app.FragmentActivity
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.simulateBiometrics
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.robolectric.annotation.Config

/** The Settings tab, hosted in a real FragmentActivity so the biometric-dependent app lock row can appear. */
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi")
class SettingsScreenTest : AppTest() {
    @get:Rule
    val compose = createEmptyComposeRule()

    private lateinit var vm: MainViewModel
    private var setupTaps = 0

    @Before
    fun createViewModel() {
        vm = MainViewModel(app).clearedAfterTest()
    }

    private fun launch(
        biometricsAvailable: Boolean = true,
        host: Class<out ComponentActivity> = FragmentActivity::class.java
    ): ComponentActivity {
        val controller = buildActivity(host).create()
        val activity = controller.get()
        activity.simulateBiometrics(biometricsAvailable)
        controller.start().postCreate(null).resume().visible()
        activity.setContent { EchoLedgerTheme { SettingsScreen(vm, onSetup = { setupTaps++ }) } }
        return activity
    }

    private fun pairAndSettle() {
        runBlocking {
            app.pairLocally(server, pins = listOf(ServerFixtures.PIN_A, ServerFixtures.PIN_B))
            app.graph.settings.update { it.copy(deviceName = "Sam's Pixel") }
        }
        pumpUntil("settings to load") { vm.settings.value.paired }
    }

    private fun settings() = runBlocking { app.graph.settings.current() }

    // ---- app lock -----------------------------------------------------------------------------------------------------------

    @Test
    fun `the app lock row is offered when the phone can authenticate`() {
        launch(biometricsAvailable = true)

        compose.onNodeWithText("App lock").assertIsDisplayed()
        compose.onNodeWithText("Require fingerprint, face or PIN to open EchoLedger").assertIsDisplayed()
    }

    @Test
    fun `the app lock row is hidden when nothing can unlock the phone`() {
        launch(biometricsAvailable = false)

        compose.onAllNodesWithText("App lock").assertCountEquals(0)
        compose.onNodeWithText("Settings").assertIsDisplayed()
    }

    @Test
    fun `the app lock row is hidden when the screen is not hosted by a fragment activity`() {
        launch(biometricsAvailable = true, host = ComponentActivity::class.java)

        compose.onAllNodesWithText("App lock").assertCountEquals(0)
        compose.onNodeWithText("Settings").assertIsDisplayed()
    }

    @Test
    fun `switching app lock on and off is saved`() {
        launch()
        compose.onAllNodes(isToggleable())[1].assertIsOff()

        compose.onAllNodes(isToggleable())[1].performClick()
        pumpUntil("app lock saved on") { settings().appLockEnabled }
        compose.onAllNodes(isToggleable())[1].assertIsOn()

        compose.onAllNodes(isToggleable())[1].performClick()
        pumpUntil("app lock saved off") { !settings().appLockEnabled }
        compose.onAllNodes(isToggleable())[1].assertIsOff()
    }

    @Test
    fun `a stored app lock setting is shown as on when the screen opens`() {
        runBlocking { app.graph.settings.update { it.copy(appLockEnabled = true) } }
        pumpUntil("settings") { vm.settings.value.appLockEnabled }
        launch()

        compose.onAllNodes(isToggleable())[1].assertIsOn()
    }

    // ---- other preferences ---------------------------------------------------------------------------------------------------

    @Test
    fun `live captions can be turned off and on again`() {
        launch()
        compose.onAllNodes(isToggleable())[0].assertIsOn()

        compose.onAllNodes(isToggleable())[0].performClick()
        pumpUntil("captions off") { !settings().captionsEnabled }
        compose.onAllNodes(isToggleable())[0].assertIsOff()

        compose.onAllNodes(isToggleable())[0].performClick()
        pumpUntil("captions on") { settings().captionsEnabled }
    }

    @Test
    fun `the microphone source toggles between echo-cancelled and raw`() {
        launch()
        compose.onNodeWithText("Voice communication (echo-cancelled)").assertIsDisplayed()

        compose.onNodeWithText("Change").performClick()
        pumpUntil("raw microphone") { settings().micSource == "mic" }
        compose.onNodeWithText("Raw microphone").assertIsDisplayed()

        compose.onNodeWithText("Change").performClick()
        pumpUntil("voice communication") { settings().micSource == "voice_communication" }
        compose.onNodeWithText("Voice communication (echo-cancelled)").assertIsDisplayed()
    }

    @Test
    fun `choosing a client for keyterms is saved and No client clears it`() {
        pairAndSettle()
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        val refresh = vm.refresh()
        pumpUntil("clients") { refresh.isCompleted && vm.clients.value.size == 2 }
        launch()

        compose.onNodeWithText("Acme Holdings").performClick()
        pumpUntil("client saved") { settings().clientId == "01JCLIENT0000000000000000A" }
        compose.onNodeWithText("Zed Logistics").performClick()
        pumpUntil("second client saved") { settings().clientId == "01JCLIENT0000000000000000B" }
        compose.onNodeWithText("No client").performClick()
        pumpUntil("client cleared") { settings().clientId == null }
    }

    // ---- device card -----------------------------------------------------------------------------------------------------------

    @Test
    fun `the device card shows the phone name server address and pin status`() {
        pairAndSettle()
        launch()

        compose.onNodeWithText("Sam's Pixel · ${server.baseUrl}").assertIsDisplayed()
        compose.onNodeWithText("Server certificate pinned (2 pins)").assertIsDisplayed()
    }

    @Test
    fun `a server without pins is flagged as not pinned`() {
        runBlocking { app.pairLocally(server, pins = emptyList()) }
        pumpUntil("settings") { vm.settings.value.paired }
        launch()

        compose.onNodeWithText("Certificate pinning not configured on the server").assertIsDisplayed()
    }

    @Test
    fun `Setup guide opens the setup screen`() {
        launch()

        compose.onNodeWithText("Setup guide").performClick()

        assertEquals(1, setupTaps)
    }

    @Test
    fun `Unpair forgets the pairing but keeps the preferences`() {
        pairAndSettle()
        runBlocking { app.graph.settings.update { it.copy(captionsEnabled = false) } }
        launch()

        compose.onNodeWithText("Unpair").performClick()
        pumpUntil("unpaired") { !settings().paired }

        assertNull(runBlocking { app.graph.settings.token() })
        assertFalse(settings().captionsEnabled)
        assertTrue(vm.library.value.isEmpty())
    }
}
