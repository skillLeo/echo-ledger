package com.echoledger.mobile.ui

import android.Manifest
import android.provider.Settings
import androidx.activity.compose.LocalActivityResultRegistryOwner
import androidx.activity.result.ActivityResultRegistry
import androidx.activity.result.ActivityResultRegistryOwner
import androidx.activity.result.contract.ActivityResultContract
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextClearance
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTextReplacement
import androidx.core.app.ActivityOptionsCompat
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pumpUntil
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Ignore
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/** The pairing form: manual entry, a pasted QR payload, the in-flight state and the camera permission outcomes. */
class PairingScreenTest : ScreenTest() {
    /** Answers permission requests immediately, the way the system dialog would after the user taps a button. */
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

    private fun showPairing(permissions: ScriptedPermissions? = null) = show {
        if (permissions == null) {
            PairingScreen(vm)
        } else {
            CompositionLocalProvider(LocalActivityResultRegistryOwner provides permissions) { PairingScreen(vm) }
        }
    }

    private fun serveRegistration(delayMs: Long = 0) {
        val builder = MockResponse.Builder().code(201).addHeader("Content-Type", "application/json").body(ServerFixtures.registerResponse())
        if (delayMs > 0) builder.headersDelay(delayMs, TimeUnit.MILLISECONDS)
        server.on("POST", "/api/devices/register", builder.build())
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
    }

    private fun typeCode(text: String) = compose.onNodeWithText("Pairing code").performTextInput(text)

    // ---- the form -------------------------------------------------------------------------------------------------------

    @Test
    fun `the form explains where the code comes from and starts with an https prefix`() {
        showPairing()

        compose.onNodeWithText("Pair this phone").assertIsDisplayed()
        compose.onNodeWithText(
            "In your EchoLedger admin open Devices → Pair a device, then scan the QR code shown there."
        ).assertIsDisplayed()
        compose.onNodeWithText("Scan QR code").assertIsDisplayed()
        compose.onNode(hasSetTextAction() and hasText("https://")).assertIsDisplayed()
        compose.onNodeWithText("Server address").assertIsDisplayed()
        compose.onNodeWithText("Pairing code").assertIsDisplayed()
        compose.onNodeWithText("Name for this phone (optional)").assertIsDisplayed()
    }

    @Test
    fun `Pair stays disabled until a code is typed and ignores a blank one`() {
        showPairing()
        compose.onNodeWithText("Pair").assertIsNotEnabled()

        typeCode("   ")
        compose.onNodeWithText("Pair").assertIsNotEnabled()

        compose.onNodeWithText("Pairing code").performTextClearance()
        typeCode("7KQ2-M9XD")
        compose.onNodeWithText("Pair").assertIsEnabled()
    }

    @Test
    fun `typing an address and a code pairs against that server`() {
        serveRegistration()
        showPairing()

        compose.onNode(hasSetTextAction() and hasText("https://")).performTextReplacement(server.baseUrl)
        typeCode("7KQ2-M9XD")
        compose.onNodeWithText("Pair").performClick()
        pumpUntil("pairing") { runBlocking { app.graph.settings.current().paired } && vm.message.value != null }

        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/devices/register").single().bodyText()).jsonObject
        assertEquals("7KQ2-M9XD", body["pairing_code"]!!.jsonPrimitive.content)
        assertEquals(server.baseUrl, runBlocking { app.graph.settings.current().serverUrl })
        assertTrue(vm.message.value.orEmpty().startsWith("Paired with"))
    }

    @Test
    fun `a phone name typed into the form is sent`() {
        serveRegistration()
        showPairing()

        compose.onNode(hasSetTextAction() and hasText("https://")).performTextReplacement(server.baseUrl)
        typeCode("7KQ2-M9XD")
        compose.onNodeWithText("Name for this phone (optional)").performTextInput("Sam's Pixel")
        compose.onNodeWithText("Pair").performClick()
        pumpUntil("pairing") { server.requestsTo("POST", "/api/devices/register").isNotEmpty() }

        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/devices/register").single().bodyText()).jsonObject
        assertEquals("Sam's Pixel", body["name"]!!.jsonPrimitive.content)
    }

    @Test
    fun `pasting the QR payload into the code field hides the address field and pairs with the payload`() {
        serveRegistration()
        showPairing()

        typeCode("""{"v":1,"url":"${server.baseUrl}","code":"7KQ2M9XD","pins":[]}""")

        compose.onAllNodesWithText("Server address").assertCountEquals(0)
        compose.onNodeWithText("Pair").performClick()
        pumpUntil("pairing") { runBlocking { app.graph.settings.current().paired } && vm.message.value != null }
        assertEquals(server.baseUrl, runBlocking { app.graph.settings.current().serverUrl })
    }

    @Test
    fun `a rejected code leaves the form available and reports the reason`() {
        server.on(
            "POST",
            "/api/devices/register",
            FakeServer.json(422, ServerFixtures.validationError("This pairing code is invalid, expired, or already used."))
        )
        showPairing()

        compose.onNode(hasSetTextAction() and hasText("https://")).performTextReplacement(server.baseUrl)
        typeCode("ZZZZ-ZZZZ")
        compose.onNodeWithText("Pair").performClick()
        pumpUntil("the error") { vm.message.value != null && !vm.busy.value }
        compose.waitForIdle()

        assertEquals("This pairing code is invalid, expired, or already used.", vm.message.value)
        compose.onNodeWithText("Pair").assertIsEnabled()
        compose.onNodeWithText("Pair this phone").assertIsDisplayed()
    }

    @Test
    fun `both buttons show Pairing and are disabled while the registration is in flight`() {
        serveRegistration(delayMs = 800)
        showPairing()
        compose.onNode(hasSetTextAction() and hasText("https://")).performTextReplacement(server.baseUrl)
        typeCode("7KQ2-M9XD")

        compose.onNodeWithText("Pair").performClick()
        pumpUntil("busy") { vm.busy.value }
        compose.waitForIdle()

        compose.onAllNodesWithText("Pairing…").assertCountEquals(2)
        compose.onAllNodesWithText("Pairing…").onFirst().assertIsNotEnabled()
        compose.onAllNodesWithText("Pairing…").onLast().assertIsNotEnabled()
        pumpUntil("done") { !vm.busy.value }
    }

    // ---- camera permission ------------------------------------------------------------------------------------------------

    @Test
    fun `the first tap on Scan asks for the camera permission`() {
        val permissions = ScriptedPermissions(grant = false)
        showPairing(permissions)

        compose.onNodeWithText("Scan QR code").performClick()
        compose.waitForIdle()

        assertEquals(listOf(Manifest.permission.CAMERA), permissions.requested)
    }

    @Test
    fun `a permanent denial explains how to enable the camera and the next tap opens app settings`() {
        val permissions = ScriptedPermissions(grant = false)
        showPairing(permissions)

        compose.onNodeWithText("Scan QR code").performClick()
        compose.waitForIdle()

        compose.onNodeWithText(
            "Camera access is turned off for EchoLedger. Tap Scan QR code to open the app settings and allow it, or enter the details by hand."
        ).assertIsDisplayed()

        compose.onNodeWithText("Scan QR code").performClick()
        compose.waitForIdle()

        val opened = shadowOf(app).nextStartedActivity
        assertNotNull("app settings should open", opened)
        assertEquals(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, opened.action)
        assertEquals("package:${app.packageName}", opened.data.toString())
        assertEquals("the permission is not requested again once blocked", 1, permissions.requested.size)
    }

    @Test
    fun `an ordinary denial keeps the manual form usable and says so`() {
        val permissions = ScriptedPermissions(grant = false)
        shadowOf(app.packageManager).setShouldShowRequestPermissionRationale(Manifest.permission.CAMERA, true)
        showPairing(permissions)

        compose.onNodeWithText("Scan QR code").performClick()
        pumpUntil("the hint") { vm.message.value != null }

        assertEquals("Camera access is needed to scan. You can still enter the server address and code by hand.", vm.message.value)
        compose.onAllNodesWithText("Tap Scan QR code to open the app settings", substring = true).assertCountEquals(0)
        compose.onNodeWithText("Pairing code").assertIsDisplayed()
    }

    @Test
    fun `with the permission already granted a tap goes straight to the scanner`() {
        shadowOf(app).grantPermissions(Manifest.permission.CAMERA)
        val permissions = ScriptedPermissions(grant = false)
        showPairing(permissions)

        compose.onNodeWithText("Scan QR code").performClick()
        compose.waitForIdle()

        assertTrue("no permission prompt when already granted", permissions.requested.isEmpty())
        pumpUntil("scanner or camera error") {
            compose.onAllNodesWithText("Scan the pairing QR code").fetchSemanticsNodes().isNotEmpty() ||
                vm.message.value?.startsWith("The camera could not be started") == true
        }
    }

    @Ignore("The confirmation dialog opens only from a decoded camera frame; the scanner cannot run under Robolectric")
    @Test
    fun `a scanned code asks for confirmation before pairing and shows the server address`() {
        showPairing()
    }
}
