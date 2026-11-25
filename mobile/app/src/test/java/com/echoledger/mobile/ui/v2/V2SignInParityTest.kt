package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTextReplacement
import com.echoledger.mobile.core.SignInFlow
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.account.V2SignInScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Signing in on the new design does what classic's sign-in does (parity.csv PH-OTH-039): the email; the password and
 * the two-step code; an emailed code for an account without a password; a new account with its name and the Terms;
 * the passkey in the browser; and under Other ways to sign in, the one-time code. In Urdu too.
 */
class V2SignInParityTest : ScreenTest() {
    private fun page(language: EchoLanguage = EchoLanguage.English) = show {
        EchoV2Theme(darkTheme = false, language = language) {
            CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) { V2SignInScreen(vm) }
        }
    }

    private fun json(code: Int, body: String) = FakeServer.json(code, body)

    private fun registered() = MockResponse.Builder().code(201).addHeader("Content-Type", "application/json")
        .body(ServerFixtures.registerResponse()).build()

    private fun serveLibrary() {
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
    }

    private fun lastBody(path: String) = Json.parseToJsonElement(server.requestsTo("POST", path).last().bodyText()).jsonObject

    private fun field(label: String) = compose.onAllNodes(hasText(label) and hasSetTextAction()).onFirst()

    private fun press(words: String) {
        val node = compose.onAllNodes((hasText(words) or hasContentDescription(words)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    private fun seen(words: String) = compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()

    private fun step(s: SignInFlow.Step) = pumpUntil("the $s step") { vm.signInState.value.step == s }

    @Test
    fun aPasswordAccountThenItsTwoStepCodeSignsIn() {
        server.on("POST", "/api/auth/start", json(200, """{"account":"existing","methods":["password","code"],"sign_up":true}"""))
        server.on("POST", "/api/auth/password", json(401, """{"message":"Two-factor code needed.","code":"otp_required"}"""))
        serveLibrary()
        page()
        assertTrue(seen("Sign in or create an account"))
        compose.onAllNodes(hasText("Continue") and hasClickAction()).onFirst().assertIsNotEnabled()
        field("Email").performTextInput("ana@example.com")
        press("Continue")
        step(SignInFlow.Step.PASSWORD)
        assertTrue(seen("Welcome back") && seen("Forgot it? Email me a code instead"))
        field("Password").performTextInput("correct horse")
        press("Sign in")
        step(SignInFlow.Step.OTP)
        server.on("POST", "/api/auth/password", registered())
        field("Password").performTextInput("correct horse")
        field("Code from your authenticator app").performTextInput("123456")
        press("Sign in")
        pumpUntil("signed in") { runBlocking { app.graph.settings.current().paired } }
        val body = lastBody("/api/auth/password")
        assertEquals("ana@example.com", body["email"]!!.jsonPrimitive.content)
        assertEquals("123456", body["otp"]!!.jsonPrimitive.content)
    }

    @Test
    fun anAccountWithoutAPasswordGetsACodeAndAWrongOneSaysSo() {
        server.on("POST", "/api/auth/start", json(200, """{"account":"existing","methods":["code"],"sign_up":true}"""))
        server.on("POST", "/api/auth/code", json(202, """{"resend_after":60,"expires_in":600}"""))
        server.on(
            "POST",
            "/api/auth/code/verify",
            json(422, """{"message":"That code is not right: 4 tries left.","code":"invalid_code","attempts_left":4}""")
        )
        page()
        field("Email").performTextInput("ana@example.com")
        press("Continue")
        pumpUntil("the code") { server.requestsTo("POST", "/api/auth/code").isNotEmpty() }
        assertEquals("sign_in", lastBody("/api/auth/code")["purpose"]!!.jsonPrimitive.content)
        assertTrue(seen("We sent a 6-digit code to ana@example.com."))
        field("6-digit code").performTextInput("111111")
        press("Continue")
        pumpUntil("the error") { seen("That code is not right: 4 tries left.") }
        compose.onAllNodes(hasText("Send again in", substring = true) and hasClickAction()).onFirst().assertIsNotEnabled()
    }

    @Test
    fun aNewEmailGetsACodeANameAndTheTermsThenAnAccount() {
        server.on("POST", "/api/auth/start", json(200, """{"account":"new","methods":["code"],"sign_up":true}"""))
        server.on("POST", "/api/auth/code", json(202, """{"resend_after":60,"expires_in":600}"""))
        server.on("POST", "/api/auth/code/verify", json(200, """{"next":"profile","sign_up_token":"st_123"}"""))
        server.on("POST", "/api/auth/sign-up", registered())
        serveLibrary()
        page()
        field("Email").performTextInput("new@example.com")
        press("Continue")
        pumpUntil("the code") { server.requestsTo("POST", "/api/auth/code").isNotEmpty() }
        assertEquals("sign_up", lastBody("/api/auth/code")["purpose"]!!.jsonPrimitive.content)
        field("6-digit code").performTextInput("123456")
        press("Continue")
        step(SignInFlow.Step.PROFILE)
        field("Your name").performTextInput("Hassan")
        compose.onAllNodes(hasText("Create account") and hasClickAction()).onFirst().assertIsNotEnabled()
        compose.onNode(isToggleable()).performClick()
        press("Create account")
        pumpUntil("signed in") { runBlocking { app.graph.settings.current().paired } }
        val body = lastBody("/api/auth/sign-up")
        assertEquals("st_123", body["sign_up_token"]!!.jsonPrimitive.content)
        assertEquals("Hassan", body["name"]!!.jsonPrimitive.content)
        assertEquals("true", body["accept_terms"]!!.jsonPrimitive.content)
    }

    @Test
    fun thePasskeyOpensTheBrowserSignIn() {
        page()
        pumpUntil("the passkey") { compose.onAllNodes(hasContentDescription("Sign in with a passkey")).fetchSemanticsNodes().isNotEmpty() }
        press("Sign in with a passkey")
        val opened = shadowOf(app).nextStartedActivity
        assertEquals(android.content.Intent.ACTION_VIEW, opened.action)
        assertTrue(opened.dataString!!, opened.dataString!!.startsWith(server.baseUrl))
    }

    @Test
    fun theOneTimeCodeUnderOtherWaysSignsIn() {
        server.on("POST", "/api/devices/register", registered())
        serveLibrary()
        page()
        press("Other ways to sign in (QR code, one-time code)")
        assertTrue(seen("Scan QR code"))
        compose.onAllNodes(hasText("Sign in with the code") and hasClickAction()).onFirst().assertIsNotEnabled()
        field("One-time sign-in code").performTextInput("TEST-2345")
        press("Sign in with the code")
        pumpUntil("signed in") { runBlocking { app.graph.settings.current().paired } }
        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/devices/register").single().bodyText()).jsonObject
        assertEquals("TEST-2345", body["pairing_code"]!!.jsonPrimitive.content)
    }

    @Test
    fun inUrduTheScreenIsInUrdu() {
        page(EchoLanguage.Urdu)
        assertTrue(seen("سائن اِن کریں یا اکاؤنٹ بنائیں"))
        assertTrue(seen("سائن اِن کے دوسرے طریقے"))
    }

    @Test
    fun anEmailedCodeThenTheTwoStepCodeSignsInAndAWrongOneSaysSo() {
        server.on("POST", "/api/auth/start", json(200, """{"account":"existing","methods":["code"],"sign_up":true}"""))
        server.on("POST", "/api/auth/code", json(202, """{"resend_after":60,"expires_in":600}"""))
        server.on("POST", "/api/auth/code/verify", json(401, """{"message":"Two-factor code needed.","code":"otp_required"}"""))
        serveLibrary()
        page()
        field("Email").performTextInput("ana@example.com")
        press("Continue")
        pumpUntil("the code") { server.requestsTo("POST", "/api/auth/code").isNotEmpty() }
        field("6-digit code").performTextInput("123456")
        press("Continue")
        step(SignInFlow.Step.OTP)
        assertTrue(seen("Two-step sign-in"))
        // A wrong two-step code says so, in the screen's words.
        server.on("POST", "/api/auth/code/verify", json(422, """{"message":"Invalid code.","code":"invalid_otp"}"""))
        field("6-digit code from the email").performTextInput("123456")
        field("Code from your authenticator app").performTextInput("000000")
        press("Sign in")
        pumpUntil("the error") { seen("That two-step code is not right.") }
        server.on("POST", "/api/auth/code/verify", registered())
        compose.onAllNodes(hasText("000000") and hasSetTextAction()).onFirst().performTextReplacement("654321")
        press("Sign in")
        pumpUntil("signed in") { runBlocking { app.graph.settings.current().paired } }
        val body = lastBody("/api/auth/code/verify")
        assertEquals("123456", body["code"]!!.jsonPrimitive.content)
        assertEquals("654321", body["otp"]!!.jsonPrimitive.content)
    }

    @Test
    fun inUrduARefusedStepIsSaidInUrdu() {
        server.on("POST", "/api/auth/start", json(200, """{"account":"new","methods":[],"sign_up":false}"""))
        page(EchoLanguage.Urdu)
        field("ای میل").performTextInput("new@example.com")
        press("جاری رکھیں")
        pumpUntil("the Urdu message") { seen("یہ سرور صرف دعوت پر ہے۔") }
        assertTrue(!seen("This server is invite only."))
        assertTrue(server.requestsTo("POST", "/api/auth/code").isEmpty())
    }
}
