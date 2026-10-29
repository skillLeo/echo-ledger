package com.echoledger.mobile.ui

import android.Manifest
import android.content.ClipboardManager
import android.provider.Settings
import androidx.activity.compose.LocalActivityResultRegistryOwner
import androidx.activity.result.ActivityResultRegistry
import androidx.activity.result.ActivityResultRegistryOwner
import androidx.activity.result.contract.ActivityResultContract
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.core.app.ActivityOptionsCompat
import com.echoledger.mobile.capture.ICaptureHelper
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/** The setup guide: permissions, the call capture helper, battery settings and consent. */
class SetupScreenTest : ScreenTest() {
    private var backTaps = 0
    private var rechecks = 0

    private class ScriptedPermissions(var grant: Boolean) : ActivityResultRegistryOwner {
        val requested = mutableListOf<String>()

        override val activityResultRegistry: ActivityResultRegistry = object : ActivityResultRegistry() {
            override fun <I : Any?, O : Any?> onLaunch(
                requestCode: Int,
                contract: ActivityResultContract<I, O>,
                input: I,
                options: ActivityOptionsCompat?
            ) {
                requested += input.toString()
                dispatchResult(requestCode, grant)
            }
        }
    }

    private fun caps(sdk: Int = 34, mic: Boolean = true, notif: Boolean = true, overlay: Boolean = true) =
        Capabilities(sdk, helperConnected = false, micPermission = mic, notificationPermission = notif, overlayPermission = overlay)

    private fun showSetup(capabilities: Capabilities = caps(), permissions: ScriptedPermissions? = null) = show {
        if (permissions == null) {
            SetupScreen(vm, capabilities, onBack = { backTaps++ }, onRecheck = { rechecks++ })
        } else {
            CompositionLocalProvider(LocalActivityResultRegistryOwner provides permissions) {
                SetupScreen(vm, capabilities, onBack = { backTaps++ }, onRecheck = { rechecks++ })
            }
        }
    }
    private class StubHelper : ICaptureHelper.Stub() {
        override fun version() = 1

        override fun uid() = 2000

        override fun capabilities() = ""

        override fun startCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true

        override fun startPhoneCapture(sink: android.os.ParcelFileDescriptor?, sampleRate: Int, micSource: Int) = true
        override fun arm(on: Boolean): String = if (on) "armed" else "disarmed"
        override fun setCallApps(packages: Array<out String>?) = Unit
        override fun selfTest(): String = "ok"
        override fun captureChannels(): Int = 2

        override fun stopCapture() = Unit

        override fun setCallListener(listener: com.echoledger.mobile.capture.ICallListener?) = Unit

        override fun destroy() = Unit
    }

    @Test
    fun `granted permissions are ticked and missing ones offer Allow`() {
        showSetup(caps(mic = true, notif = false, overlay = false))

        compose.onNodeWithText("✓  Microphone").assertIsDisplayed()
        compose.onNodeWithText("•  Notifications (Stop / Keep / Discard actions)").assertIsDisplayed()
        compose.onNodeWithText("•  Caption bubble (optional)").assertIsDisplayed()
        compose.onAllNodesWithText("Allow").assertCountEquals(2)
    }

    @Test
    fun `with every permission granted no Allow button is shown`() {
        showSetup(caps())

        compose.onAllNodesWithText("Allow").assertCountEquals(0)
    }

    @Test
    fun `Allow for the microphone asks for the microphone permission and rechecks afterwards`() {
        val permissions = ScriptedPermissions(grant = true)
        showSetup(caps(mic = false, notif = true, overlay = true), permissions)

        compose.onNodeWithText("Allow").performClick()
        compose.waitForIdle()

        assertEquals(listOf(Manifest.permission.RECORD_AUDIO), permissions.requested)
        assertEquals(1, rechecks)
    }

    @Test
    fun `Allow for notifications asks for the notification permission`() {
        val permissions = ScriptedPermissions(grant = false)
        showSetup(caps(mic = true, notif = false, overlay = true), permissions)

        compose.onNodeWithText("Allow").performClick()
        compose.waitForIdle()

        assertEquals(listOf(Manifest.permission.POST_NOTIFICATIONS), permissions.requested)
        assertEquals("a denial is rechecked too so the row stays accurate", 1, rechecks)
    }

    @Test
    fun `Allow for the caption bubble opens the overlay settings page`() {
        showSetup(caps(mic = true, notif = true, overlay = false))

        compose.onNodeWithText("Allow").performClick()
        compose.waitForIdle()

        val opened = shadowOf(app).nextStartedActivity
        assertNotNull(opened)
        assertEquals(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, opened.action)
        assertEquals("package:${app.packageName}", opened.data.toString())
    }

    @Test
    fun `before Android 14 call capture is explained as unsupported`() {
        showSetup(caps(sdk = 33))

        compose.onNodeWithText("2 · Call capture (Android 14+)").assertIsDisplayed()
        compose.onNodeWithText(
            "Recording the other side of WhatsApp calls needs Android 14 or later.",
            substring = true
        ).assertIsDisplayed()
        compose.onAllNodesWithText("Connect via Shizuku").assertCountEquals(0)
    }

    @Test
    fun `on Android 14 the helper steps and the ADB command are offered`() {
        showSetup(caps(sdk = 34))

        compose.onNodeWithText("Not enabled yet.").assertIsDisplayed()
        compose.onNodeWithText("Connect via Shizuku").assertIsDisplayed()
        compose.onNodeWithText(vm.adbCommand()).assertIsDisplayed()
    }

    @Test
    fun `a running helper is reported with its source and user id`() {
        app.graph.helper.attach(StubHelper(), source = "shizuku")
        showSetup(caps(sdk = 34))

        compose.onNodeWithText("Capture helper is running (shizuku, uid 2000).").assertIsDisplayed()
        compose.onAllNodesWithText("Not enabled yet.").assertCountEquals(0)
    }

    @Test
    fun `Copy command puts the ADB command on the clipboard`() {
        showSetup(caps(sdk = 34))

        compose.onNodeWithText("Copy command").performClick()
        compose.waitForIdle()
        pumpUntil("the clipboard") { app.getSystemService(ClipboardManager::class.java).primaryClip != null }

        assertEquals(vm.adbCommand(), app.getSystemService(ClipboardManager::class.java).primaryClip!!.getItemAt(0).text.toString())
    }

    @Test
    fun `Connect via Shizuku explains when Shizuku is not running`() {
        showSetup(caps(sdk = 34))

        compose.onNodeWithText("Connect via Shizuku").performClick()

        assertEquals("Shizuku is not running. Start it (Wireless debugging) and try again.", vm.message.value)
    }

    @Test
    fun `Open app settings leads to the battery page for this app`() {
        showSetup()

        compose.onNodeWithText("Open app settings").performClick()

        val opened = shadowOf(app).nextStartedActivity
        assertEquals(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, opened.action)
        assertEquals("package:${app.packageName}", opened.data.toString())
    }

    @Test
    fun `the consent switch is off until acknowledged and the choice is saved`() {
        showSetup()
        compose.onNode(isToggleable()).assertIsOff()

        compose.onNode(isToggleable()).performClick()
        pumpUntil("consent saved") { runBlocking { app.graph.settings.current().consentAcknowledged } }
        compose.waitForIdle()

        compose.onNode(isToggleable()).assertIsOn()
        compose.onNode(isToggleable()).performClick()
        pumpUntil("consent withdrawn") { runBlocking { !app.graph.settings.current().consentAcknowledged } }
    }

    @Test
    fun `the guide states the recording notice and Back leaves it`() {
        showSetup()

        compose.onNodeWithText(
            "Call-recording laws differ by country. Tell participants you are recording where required. A recording notification is always shown."
        ).assertIsDisplayed()
        compose.onNodeWithContentDescription("Back").performClick()

        assertEquals(1, backTaps)
        assertTrue(vm.message.value == null)
    }
}
