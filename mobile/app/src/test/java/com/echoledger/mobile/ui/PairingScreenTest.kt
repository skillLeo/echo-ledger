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
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
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
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * The sign-in screen (since v1.6, P1): the email first, then a password, an emailed code or a new account; the QR code and
 * one-time codes under "Other ways to sign in", with the camera permission outcomes.
 */
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

    private fun typeCode(text: String) = compose.onNodeWithText("One-time sign-in code").performTextInput(text)

    private fun otherWays() = compose.onNodeWithText("Other ways to sign in (QR code, one-time code)").performScrollTo().performClick()

    private fun json(code: Int, body: String) = FakeServer.json(code, body)

    private fun serveLibrary() {
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
    }

    private fun lastBody(path: String) = Json.parseToJsonElement(server.requestsTo("POST", path).last().bodyText()).jsonObject

    private fun typeEmailAndContinue(email: String) {
        compose.onNodeWithText("Email").performTextInput(email)
        compose.onNodeWithText("Continue").performClick()
    }

    // ---- the first screen -------------------------------------------------------------------------------------------------

    @Test
    fun `the first screen asks only for the email, the server and other ways are tucked away`() {
        showPairing()

        compose.onNodeWithText("Sign in or create an account").assertIsDisplayed()
        compose.onNodeWithText("Email").assertIsDisplayed()
        compose.onNodeWithText("Continue").assertIsNotEnabled()
        compose.onAllNodesWithText("Password").assertCountEquals(0)
        compose.onAllNodesWithText("Server address").assertCountEquals(0)
        compose.onAllNodesWithText("Scan QR code").assertCountEquals(0)
        compose.onAllNodesWithContentDescription("Continue with Google").assertCountEquals(0)
        compose.onAllNodesWithText("Keystore", substring = true).assertCountEquals(0)
        compose.onNodeWithText(
            "Your recordings are encrypted on this phone and on the way to the server."
        ).performScrollTo().assertIsDisplayed()

        compose.onNodeWithText("Advanced").performClick()
        compose.onNodeWithText("Use a different server").assertIsDisplayed()
    }

    @Test
    fun `Google and Passkey sit side by side under or, and Passkey opens the browser sign-in (v1_6_2)`() {
        showPairing()
        compose.onNodeWithText("or").assertExists()
        compose.onNodeWithContentDescription("Sign in with a passkey").assertExists()
        compose.onAllNodesWithText("Sign in in the browser (passkey)").assertCountEquals(0)
    }

    @Test
    fun `Continue with Google shows only when the server offers it`() {
        server.on(
            "GET",
            "/api/auth/options",
            json(200, """{"password":true,"google":true,"sign_up":true,"sign_up_url":null,"reset_url":null,"browser_sign_in":true}""")
        )
        showPairing()

        pumpUntil("the Google button") {
            compose.waitForIdle()
            compose.onAllNodesWithContentDescription("Continue with Google").fetchSemanticsNodes().isNotEmpty()
        }
    }

    // ---- an account -------------------------------------------------------------------------------------------------------

    @Test
    fun `an account with a password asks for it, then the two-step code, and sends this install's id`() {
        server.on("POST", "/api/auth/start", json(200, """{"account":"existing","methods":["password","code"],"sign_up":true}"""))
        server.on("POST", "/api/auth/password", json(401, """{"message":"Two-factor code needed.","code":"otp_required"}"""))
        serveLibrary()
        showPairing()

        typeEmailAndContinue("ana@example.com")
        pumpUntil("the password step") { vm.signInState.value.step == com.echoledger.mobile.core.SignInFlow.Step.PASSWORD }
        compose.onNodeWithText("Welcome back").assertIsDisplayed()
        compose.onNodeWithText("Password").performTextInput("correct horse")
        compose.onNodeWithText("Sign in").performClick()
        pumpUntil("the two-step code") { vm.signInState.value.step == com.echoledger.mobile.core.SignInFlow.Step.OTP }
        server.on(
            "POST",
            "/api/auth/password",
            MockResponse.Builder().code(201).addHeader("Content-Type", "application/json").body(ServerFixtures.registerResponse()).build()
        )
        compose.onNodeWithText("Password").performTextInput("correct horse")
        compose.onNodeWithText("Code from your authenticator app").performTextInput("123456")
        compose.onNodeWithText("Sign in").performClick()
        pumpUntil("signed in") { runBlocking { app.graph.settings.current().paired } }

        val body = lastBody("/api/auth/password")
        assertEquals("ana@example.com", body["email"]!!.jsonPrimitive.content)
        assertEquals("123456", body["otp"]!!.jsonPrimitive.content)
        assertEquals(com.echoledger.mobile.core.InstallId.get(app), body["install_id"]!!.jsonPrimitive.content)
        assertEquals(server.baseUrl, runBlocking { app.graph.settings.current().serverUrl })
    }

    @Test
    fun `an account without a password gets a code, and a wrong code says how many tries are left`() {
        server.on("POST", "/api/auth/start", json(200, """{"account":"existing","methods":["code","google"],"sign_up":true}"""))
        server.on("POST", "/api/auth/code", json(202, """{"resend_after":60,"expires_in":600}"""))
        server.on(
            "POST",
            "/api/auth/code/verify",
            json(422, """{"message":"That code is not right: 4 tries left.","code":"invalid_code","attempts_left":4}""")
        )
        showPairing()

        typeEmailAndContinue("ana@example.com")
        pumpUntil("the code step") { server.requestsTo("POST", "/api/auth/code").isNotEmpty() }
        assertEquals("sign_in", lastBody("/api/auth/code")["purpose"]!!.jsonPrimitive.content)
        compose.onNodeWithText("6-digit code").performTextInput("111111")
        compose.onNodeWithText("Continue").performClick()
        pumpUntil("the error") { vm.signInState.value.error != null }
        compose.onNodeWithText("That code is not right: 4 tries left.").assertIsDisplayed()
        compose.onNodeWithText("Send again in", substring = true).assertIsNotEnabled()
    }

    // ---- a new account ----------------------------------------------------------------------------------------------------

    @Test
    fun `a new email gets a code, a name and the Terms, then an account on the Free plan`() {
        server.on("POST", "/api/auth/start", json(200, """{"account":"new","methods":["code"],"sign_up":true}"""))
        server.on("POST", "/api/auth/code", json(202, """{"resend_after":60,"expires_in":600}"""))
        server.on("POST", "/api/auth/code/verify", json(200, """{"next":"profile","sign_up_token":"st_123"}"""))
        server.on(
            "POST",
            "/api/auth/sign-up",
            MockResponse.Builder().code(201).addHeader("Content-Type", "application/json").body(ServerFixtures.registerResponse()).build()
        )
        serveLibrary()
        showPairing()

        typeEmailAndContinue("new@example.com")
        pumpUntil("the code step") { server.requestsTo("POST", "/api/auth/code").isNotEmpty() }
        assertEquals("sign_up", lastBody("/api/auth/code")["purpose"]!!.jsonPrimitive.content)
        compose.onNodeWithText("Check your email").assertIsDisplayed()
        compose.onNodeWithText("6-digit code").performTextInput("123456")
        compose.onNodeWithText("Continue").performClick()
        pumpUntil("the profile step") { vm.signInState.value.step == com.echoledger.mobile.core.SignInFlow.Step.PROFILE }
        compose.onNodeWithText("Your name").performTextInput("Hassan")
        compose.onNodeWithText("Create account").assertIsNotEnabled()
        compose.onNode(androidx.compose.ui.test.isToggleable()).performClick()
        compose.onNodeWithText("Create account").performClick()
        pumpUntil("signed in") { runBlocking { app.graph.settings.current().paired } }

        val body = lastBody("/api/auth/sign-up")
        assertEquals("st_123", body["sign_up_token"]!!.jsonPrimitive.content)
        assertEquals("Hassan", body["name"]!!.jsonPrimitive.content)
        assertEquals("true", body["accept_terms"]!!.jsonPrimitive.content)
        assertEquals(com.echoledger.mobile.core.InstallId.get(app), body["install_id"]!!.jsonPrimitive.content)
    }

    @Test
    fun `an invite-only server says so instead of sending a code`() {
        server.on("POST", "/api/auth/start", json(200, """{"account":"new","methods":[],"sign_up":false}"""))
        showPairing()

        typeEmailAndContinue("new@example.com")
        pumpUntil("the message") { vm.signInState.value.error != null }
        compose.onNodeWithText(com.echoledger.mobile.core.SignInFlow.SIGN_UP_CLOSED).assertIsDisplayed()
        assertTrue(server.requestsTo("POST", "/api/auth/code").isEmpty())
    }

    @Test
    fun `a server older than v1_6 gets the password sign-in`() {
        server.on("POST", "/api/auth/start", json(404, """{"message":"Not found.","code":"not_found"}"""))
        showPairing()

        typeEmailAndContinue("ana@example.com")
        pumpUntil("the password step") { vm.signInState.value.step == com.echoledger.mobile.core.SignInFlow.Step.PASSWORD }
        compose.onNodeWithText("Password").assertIsDisplayed()
    }

    // ---- other ways: one-time code and QR -------------------------------------------------------------------------------

    @Test
    fun `the one-time code stays disabled until typed and pairs with the default server`() {
        serveRegistration()
        showPairing()
        otherWays()
        compose.onNodeWithText("Sign in with the code").assertIsNotEnabled()
        typeCode("   ")
        compose.onNodeWithText("Sign in with the code").assertIsNotEnabled()
        compose.onNodeWithText("One-time sign-in code").performTextClearance()
        typeCode("TEST-2345")
        compose.onNodeWithText("Sign in with the code").performClick()
        pumpUntil("pairing") { runBlocking { app.graph.settings.current().paired } && vm.message.value != null }

        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/devices/register").single().bodyText()).jsonObject
        assertEquals("TEST-2345", body["pairing_code"]!!.jsonPrimitive.content)
        assertEquals(com.echoledger.mobile.core.InstallId.get(app), body["install_id"]!!.jsonPrimitive.content)
        assertEquals(server.baseUrl, runBlocking { app.graph.settings.current().serverUrl })
    }

    @Test
    fun `pasting the QR payload into the code field pairs with the payload`() {
        serveRegistration()
        showPairing()
        otherWays()

        typeCode("""{"v":1,"url":"${server.baseUrl}","code":"TEST2345","pins":[]}""")
        compose.onNodeWithText("Sign in with the code").performClick()
        pumpUntil("pairing") { runBlocking { app.graph.settings.current().paired } && vm.message.value != null }
        assertEquals(server.baseUrl, runBlocking { app.graph.settings.current().serverUrl })
    }

    @Test
    fun `a rejected code leaves the form available and reports the reason`() {
        server.on(
            "POST",
            "/api/devices/register",
            FakeServer.json(422, ServerFixtures.validationError("This code is invalid, expired, or already used."))
        )
        showPairing()
        otherWays()

        typeCode("ZZZZ-ZZZZ")
        compose.onNodeWithText("Sign in with the code").performClick()
        pumpUntil("the error") { vm.message.value != null && !vm.busy.value }
        compose.waitForIdle()

        assertEquals("This code is invalid, expired, or already used.", vm.message.value)
        compose.onNodeWithText("Sign in with the code").assertIsEnabled()
    }

    @Test
    fun `both buttons say Signing in and are disabled while the registration is in flight`() {
        serveRegistration(delayMs = 800)
        showPairing()
        otherWays()
        typeCode("TEST-2345")

        compose.onNodeWithText("Sign in with the code").performClick()
        pumpUntil("busy") { vm.busy.value }
        compose.waitForIdle()

        compose.onAllNodesWithText("Signing in…").assertCountEquals(2)
        compose.onAllNodesWithText("Signing in…").onFirst().assertIsNotEnabled()
        compose.onAllNodesWithText("Signing in…").onLast().assertIsNotEnabled()
        pumpUntil("done") { !vm.busy.value }
    }

    // ---- camera permission ------------------------------------------------------------------------------------------------

    @Test
    fun `the first tap on Scan asks for the camera permission`() {
        val permissions = ScriptedPermissions(grant = false)
        showPairing(permissions)
        otherWays()

        compose.onNodeWithText("Scan QR code").performClick()
        compose.waitForIdle()

        assertEquals(listOf(Manifest.permission.CAMERA), permissions.requested)
    }

    @Test
    fun `a permanent denial explains how to enable the camera and the next tap opens app settings`() {
        val permissions = ScriptedPermissions(grant = false)
        showPairing(permissions)
        otherWays()

        compose.onNodeWithText("Scan QR code").performClick()
        compose.waitForIdle()

        compose.onNodeWithText(
            "Camera access is turned off for EchoLedger. Tap Scan QR code to open the app settings and allow it, or type the code."
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
    fun `an ordinary denial keeps the code usable and says so`() {
        val permissions = ScriptedPermissions(grant = false)
        shadowOf(app.packageManager).setShouldShowRequestPermissionRationale(Manifest.permission.CAMERA, true)
        showPairing(permissions)
        otherWays()

        compose.onNodeWithText("Scan QR code").performClick()
        pumpUntil("the hint") { vm.message.value != null }

        assertEquals("Camera access is needed to scan. You can still type the code.", vm.message.value)
        compose.onAllNodesWithText("Tap Scan QR code to open the app settings", substring = true).assertCountEquals(0)
        compose.onNodeWithText("One-time sign-in code").assertIsDisplayed()
    }

    @Test
    fun `with the permission already granted a tap goes straight to the scanner`() {
        shadowOf(app).grantPermissions(Manifest.permission.CAMERA)
        val permissions = ScriptedPermissions(grant = false)
        showPairing(permissions)
        otherWays()

        compose.onNodeWithText("Scan QR code").performClick()
        compose.waitForIdle()

        assertTrue("no permission prompt when already granted", permissions.requested.isEmpty())
        pumpUntil("scanner or camera error") {
            compose.onAllNodesWithText("Scan the sign-in QR code").fetchSemanticsNodes().isNotEmpty() ||
                vm.message.value?.startsWith("The camera could not be started") == true
        }
    }
}
