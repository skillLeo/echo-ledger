package com.echoledger.mobile.ui

import android.app.Activity
import android.content.Intent
import android.provider.Settings
import android.view.Window
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.isSelectable
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.ViewModelProvider
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.share.ShareImportActivity
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.simulateBiometrics
import com.echoledger.mobile.testsupport.simulateScreenLock
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config

/** The Settings tab, hosted in a real FragmentActivity so the app lock row can prompt, plus the secure-window setting. */
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi")
class SettingsScreenTest : AppTest() {
    @get:Rule
    val compose = createEmptyComposeRule()

    private lateinit var vm: MainViewModel
    private var setupTaps = 0
    private val privacyGroup = "General/App lock"

    @Before
    fun createViewModel() {
        vm = MainViewModel(app).clearedAfterTest()
    }

    private fun launch(
        biometricsAvailable: Boolean = true,
        host: Class<out ComponentActivity> = FragmentActivity::class.java,
        screenLock: Boolean = false,
        prompter: UnlockPrompter? = null,
        open: String? = null
    ): ComponentActivity {
        val controller = buildActivity(host).create()
        val activity = controller.get()
        activity.simulateBiometrics(biometricsAvailable)
        activity.simulateScreenLock(screenLock)
        controller.start().postCreate(null).resume().visible()
        activity.setContent {
            EchoLedgerTheme {
                if (prompter == null) {
                    SettingsScreen(vm, onSetup = { setupTaps++ })
                } else {
                    CompositionLocalProvider(LocalUnlockPrompter provides prompter) { SettingsScreen(vm, onSetup = { setupTaps++ }) }
                }
            }
        }
        // A path of rows to tap: "General/App lock" opens General, then its App lock page.
        open?.split("/")?.forEach { compose.onNodeWithText(it).performClick() }
        return activity
    }

    /** Answers every unlock prompt with [result] and counts the prompts. */
    private class FakePrompter(var result: Boolean) : UnlockPrompter {
        var prompts = 0

        override fun prompt(onResult: (Boolean) -> Unit) {
            prompts++
            onResult(result)
        }
    }

    private fun appLockSwitch() = compose.onAllNodes(isToggleable())[0]

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
        launch(open = privacyGroup, biometricsAvailable = true)

        compose.onAllNodesWithText("App lock").onLast().assertIsDisplayed()
        compose.onNodeWithText("Ask for your fingerprint, face or screen lock when EchoLedger opens.").assertIsDisplayed()
        appLockSwitch().assertIsEnabled()
        compose.onAllNodesWithText("Open security settings").assertCountEquals(0)
    }

    @Test
    fun `without a screen lock the app lock row stays visible, disabled, with the reason and a way to fix it`() {
        val activity = launch(open = privacyGroup, biometricsAvailable = false, screenLock = false)

        compose.onAllNodesWithText("App lock").onLast().assertIsDisplayed()
        compose.onNodeWithText("Set a screen lock on this phone to use App lock.").assertIsDisplayed()
        appLockSwitch().assertIsNotEnabled().assertIsOff()

        compose.onNodeWithText("Open security settings").performClick()

        assertEquals(Settings.ACTION_SECURITY_SETTINGS, shadowOf(activity).nextStartedActivity?.action)
    }

    @Test
    fun `a stored lock is shown as off while the phone has no screen lock`() {
        runBlocking { app.graph.settings.update { it.copy(appLockEnabled = true) } }
        pumpUntil("settings") { vm.settings.value.appLockEnabled }
        launch(open = privacyGroup, biometricsAvailable = false, screenLock = false)

        appLockSwitch().assertIsOff().assertIsNotEnabled()
        compose.onAllNodesWithText("Lock after").assertCountEquals(0)
    }

    @Test
    fun `a phone with only a screen lock can switch the lock on`() {
        launch(open = privacyGroup, biometricsAvailable = false, screenLock = true)

        compose.onAllNodesWithText("Set a screen lock on this phone to use App lock.").assertCountEquals(0)
        appLockSwitch().assertIsEnabled()
    }

    @Test
    fun `outside a fragment activity the row is shown but cannot be switched on`() {
        launch(open = privacyGroup, biometricsAvailable = true, host = ComponentActivity::class.java)

        compose.onAllNodesWithText("App lock").onLast().assertIsDisplayed()
        appLockSwitch().assertIsNotEnabled()
    }

    @Test
    fun `switching app lock on takes one successful unlock and switching it off does not`() {
        val prompter = FakePrompter(result = true)
        launch(open = privacyGroup, prompter = prompter)
        appLockSwitch().assertIsOff()

        appLockSwitch().performClick()
        pumpUntil("app lock saved on") { settings().appLockEnabled }
        appLockSwitch().assertIsOn()
        assertEquals(1, prompter.prompts)

        appLockSwitch().performClick()
        pumpUntil("app lock saved off") { !settings().appLockEnabled }
        appLockSwitch().assertIsOff()
        assertEquals("turning the lock off asks for nothing", 1, prompter.prompts)
    }

    @Test
    fun `a cancelled unlock leaves the app lock off without a message`() {
        val prompter = FakePrompter(result = false)
        launch(open = privacyGroup, prompter = prompter)

        appLockSwitch().performClick()
        compose.waitForIdle()

        assertEquals(1, prompter.prompts)
        assertFalse(settings().appLockEnabled)
        appLockSwitch().assertIsOff()
        assertNull(vm.message.value)
    }

    @Test
    fun `with the real prompt the switch waits for the user before saving`() {
        launch(open = privacyGroup, biometricsAvailable = true)

        appLockSwitch().performClick()
        compose.waitForIdle()

        assertFalse("nothing is saved before the user authenticates", settings().appLockEnabled)
        appLockSwitch().assertIsOff()
    }

    @Test
    fun `on a phone the biometric prompt cannot serve the screen lock is confirmed before the lock is saved`() {
        val activity = launch(open = privacyGroup, biometricsAvailable = false, screenLock = true)

        appLockSwitch().performClick()
        compose.waitForIdle()
        val started = shadowOf(activity).nextStartedActivityForResult
        assertNotNull("the confirm-credential screen is opened", started)
        assertFalse(settings().appLockEnabled)

        shadowOf(activity).receiveResult(started.intent, Activity.RESULT_OK, null)

        pumpUntil("app lock saved on") { settings().appLockEnabled }
    }

    @Test
    fun `a stored app lock setting is shown as on when the screen opens`() {
        runBlocking { app.graph.settings.update { it.copy(appLockEnabled = true) } }
        pumpUntil("settings") { vm.settings.value.appLockEnabled }
        launch(open = privacyGroup)

        appLockSwitch().assertIsOn()
    }

    @Test
    fun `with the lock on the grace period and the hide content option are offered and saved`() {
        runBlocking { app.graph.settings.update { it.copy(appLockEnabled = true) } }
        pumpUntil("settings") { vm.settings.value.appLockEnabled }
        launch(open = privacyGroup)

        compose.onNodeWithText("Lock after").assertIsDisplayed()
        compose.onNode(hasText("Immediately") and isSelectable()).assertIsSelected()
        compose.onNodeWithText("After 1 minute").performClick()
        pumpUntil("one minute saved") { settings().lockAfterMs == 60_000L }
        compose.onNodeWithText("After 5 minutes").performClick()
        pumpUntil("five minutes saved") { settings().lockAfterMs == 300_000L }
        compose.onNode(hasText("After 5 minutes") and isSelectable()).assertIsSelected()

        compose.onNodeWithText("Hide content in Recents and screenshots").assertIsDisplayed()
        // Screenshots are allowed by default: the option is off until the person turns it on.
        compose.onAllNodes(isToggleable())[1].assertIsOff().performClick()
        pumpUntil("hide content saved on") { settings().secureScreen }
        compose.onAllNodes(isToggleable())[1].assertIsOn().performClick()
        pumpUntil("hide content saved off again") { !settings().secureScreen }
    }

    @Test
    fun `with the lock off the grace period and the hide content option are not shown`() {
        launch(open = privacyGroup)

        compose.onAllNodesWithText("Lock after").assertCountEquals(0)
        compose.onAllNodesWithText("Hide content in Recents and screenshots").assertCountEquals(0)
    }

    // ---- hiding content in Recents and screenshots (FLAG_SECURE) ------------------------------------------------------------------

    private fun Window.secure() = attributes.flags and WindowManager.LayoutParams.FLAG_SECURE != 0

    @Test
    fun `the main window is secure while the lock and hide content are on and not otherwise`() {
        runBlocking { app.graph.settings.update { it.copy(appLockEnabled = true, secureScreen = true) } }
        val controller = buildActivity(MainActivity::class.java).create()
        controller.get().simulateBiometrics(true)
        controller.start().postCreate(null).resume().visible()
        val window = controller.get().window

        pumpUntil("FLAG_SECURE set") { window.secure() }

        runBlocking { app.graph.settings.update { it.copy(secureScreen = false) } }
        pumpUntil("FLAG_SECURE cleared when hide content is off") { !window.secure() }

        runBlocking { app.graph.settings.update { it.copy(secureScreen = true) } }
        pumpUntil("FLAG_SECURE set again") { window.secure() }

        runBlocking { app.graph.settings.update { it.copy(appLockEnabled = false) } }
        pumpUntil("FLAG_SECURE cleared when the lock is off") { !window.secure() }
    }

    @Test
    fun `the main window is not secure by default`() {
        val controller = buildActivity(MainActivity::class.java).create()
        controller.get().simulateBiometrics(true)
        controller.start().postCreate(null).resume().visible()
        val vm = ViewModelProvider(controller.get())[MainViewModel::class.java]
        pumpUntil("settings to load") { vm.settingsState.value is SettingsState.Loaded }

        assertFalse(controller.get().window.secure())
    }

    @Test
    fun `the share entry is kept out of Recents and screenshots while the lock hides content`() {
        runBlocking { app.graph.settings.update { it.copy(appLockEnabled = true, secureScreen = true) } }

        val share = buildActivity(ShareImportActivity::class.java, Intent(Intent.ACTION_SEND)).create().get()

        pumpUntil("FLAG_SECURE on the share entry") { share.window.secure() }
    }

    // ---- other preferences ---------------------------------------------------------------------------------------------------

    @Test
    fun `live captions can be turned off and on again`() {
        launch(open = "Calls & meetings")
        val captions = { compose.onNodeWithContentDescription("Live captions while recording").performScrollTo() }
        captions().assertIsOn()

        captions().performClick()
        pumpUntil("captions off") { !settings().captionsEnabled }
        captions().assertIsOff()

        captions().performClick()
        pumpUntil("captions on") { settings().captionsEnabled }
    }

    @Test
    fun `the microphone source toggles between echo-cancelled and raw`() {
        launch(open = "Calls & meetings")
        compose.onNodeWithText("Voice communication (echo-cancelled)").performScrollTo().assertIsDisplayed()

        // The microphone row's Change (the bitrate row below it has one too).
        compose.onAllNodesWithText("Change")[0].performScrollTo().performClick()
        pumpUntil("raw microphone") { settings().micSource == "mic" }
        compose.onNodeWithText("Raw microphone").performScrollTo().assertIsDisplayed()

        // The microphone row's Change (the bitrate row below it has one too).
        compose.onAllNodesWithText("Change")[0].performScrollTo().performClick()
        pumpUntil("voice communication") { settings().micSource == "voice_communication" }
        compose.onNodeWithText("Voice communication (echo-cancelled)").performScrollTo().assertIsDisplayed()
    }

    @Test
    fun `choosing a client for keyterms is saved and No client clears it`() {
        pairAndSettle()
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        val refresh = vm.refresh()
        pumpUntil("clients") { refresh.isCompleted && vm.clients.value.size == 2 }
        launch(open = "General/Client keyterms")

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
        launch(open = "General/This phone")

        compose.onNodeWithText("Sam's Pixel · ${server.baseUrl}").assertIsDisplayed()
        compose.onNodeWithText("Server certificate pinned (2 pins)").assertIsDisplayed()
    }

    @Test
    fun `a server without pins is flagged as not pinned`() {
        runBlocking { app.pairLocally(server, pins = emptyList()) }
        pumpUntil("settings") { vm.settings.value.paired }
        launch(open = "General/This phone")

        compose.onNodeWithText("Certificate pinning not configured on the server").assertIsDisplayed()
    }

    @Test
    fun `Setup guide opens the setup screen`() {
        launch(open = "General/This phone")

        compose.onNodeWithText("Setup guide").performClick()

        assertEquals(1, setupTaps)
    }

    @Test
    fun `Unpair forgets the pairing but keeps the preferences`() {
        pairAndSettle()
        runBlocking { app.graph.settings.update { it.copy(captionsEnabled = false) } }
        launch(open = "General/This phone")

        compose.onNodeWithText("Unpair").performClick()
        assertTrue("nothing changes before the user confirms", settings().paired)
        compose.onNodeWithText("Unpair this phone? Recordings waiting to upload stay on the phone.").assertExists()
        compose.onNode(hasText("Unpair") and hasAnyAncestor(isDialog())).performClick()
        pumpUntil("unpaired") { !settings().paired }

        assertNull(runBlocking { app.graph.settings.token() })
        assertFalse(settings().captionsEnabled)
        assertTrue(vm.library.value.isEmpty())
    }
}
