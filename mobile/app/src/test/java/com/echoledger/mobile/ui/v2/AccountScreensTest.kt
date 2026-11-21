package com.echoledger.mobile.ui.v2

import android.content.Context
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTextReplacement
import com.echoledger.mobile.account.AccountActions
import com.echoledger.mobile.account.AccountProblem
import com.echoledger.mobile.account.PasskeyMaker
import com.echoledger.mobile.account.PasskeyOutcome
import com.echoledger.mobile.data.AccountDeviceDto
import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.AccountSecurityDto
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.BrowserDto
import com.echoledger.mobile.data.DeviceWorkspaceDto
import com.echoledger.mobile.data.GoogleLinkDto
import com.echoledger.mobile.data.PasswordRulesDto
import com.echoledger.mobile.data.Proof
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.account.AccountHubRows
import com.echoledger.mobile.ui.v2.account.AccountRoute
import com.echoledger.mobile.ui.v2.account.EmailPage
import com.echoledger.mobile.ui.v2.account.LocalAccountActions
import com.echoledger.mobile.ui.v2.account.PasswordPage
import com.echoledger.mobile.ui.v2.account.ProofAccount
import com.echoledger.mobile.ui.v2.account.ProofForm
import com.echoledger.mobile.ui.v2.account.SessionsPage
import com.echoledger.mobile.ui.v2.account.SignInMethodsPage
import com.echoledger.mobile.ui.v2.account.TwoStepPage
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.io.File
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The account and security screens of contract 2.0.0-l against a scripted server: the proof sheet, the email change
 * with its code and its watch, the password with its rules, two-step with its recovery codes, Google and passkeys,
 * browsers and devices, and each error in plain words.
 */
class AccountScreensTest : ScreenTest() {
    private val account =
        AccountDto(name = "Ayesha Siddiqui", email = "ayesha@bilaltraders.pk", hasPassword = true, passkeysAvailable = true)

    private fun sample(name: String) = File("../../docs/api-samples/$name.json").readText()

    private fun answer(code: Int, body: String, retryAfter: String? = null): MockResponse {
        val b = MockResponse.Builder().code(code).addHeader("Content-Type", "application/json").body(body)
        retryAfter?.let { b.addHeader("Retry-After", it) }
        return b.build()
    }

    private fun page(language: EchoLanguage = EchoLanguage.English, content: @Composable () -> Unit) {
        val client = ApiClient(server.baseUrl, emptyList(), token = "7|eld_test")
        show {
            EchoV2Theme(darkTheme = false, language = language) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts, LocalAccountActions provides AccountActions { client }) {
                    androidx.compose.foundation.layout.Box {
                        content()
                        EchoToastHost(toasts)
                    }
                }
            }
        }
        compose.waitForIdle()
    }

    private fun click(text: String) {
        compose.onAllNodes(hasText(text) and hasClickAction()).onFirst().performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    /** The last of several buttons with these words: the sheet's or the dialog's, drawn over the page. */
    private fun clickLast(text: String) {
        val nodes = compose.onAllNodes(hasText(text) and hasClickAction())
        nodes[nodes.fetchSemanticsNodes().size - 1].performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private fun type(label: String, text: String) {
        compose.onAllNodes(hasSetTextAction() and hasText(label, substring = true)).onFirst().performTextInput(text)
        compose.waitForIdle()
    }

    private fun seen(text: String, substring: Boolean = true) =
        compose.onAllNodesWithText(text, substring = substring, useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()

    private fun waitFor(text: String) = pumpUntil(text) { seen(text) }

    private fun button(text: String) = compose.onAllNodes(hasText(text) and hasClickAction()).onFirst()

    // ---- the proof sheet ------------------------------------------------------------------------------------------

    @Test
    fun withTwoStepOnTheProofAsksForThePasswordAndACode() {
        var sent: Proof? = null
        page {
            ProofForm(ProofAccount(hasPassword = true, twoFactor = true, email = null), "Continue", { p ->
                sent = p
                AccountProblem.InvalidOtp()
            }, onDone = {})
        }
        assertTrue(seen("Enter your password and the code from your authenticator app."))
        type("Password", "my password 2026")
        button("Continue").assertIsNotEnabled()
        type("Authenticator or recovery code", "123456")
        button("Continue").assertIsEnabled()
        click("Continue")
        waitFor("That code isn't right. Check the app and try again.")
        assertEquals(Proof(password = "my password 2026", otp = "123456"), sent)
    }

    @Test
    fun aRightPasswordWithoutTheCodeAsksForTheCodeToo() {
        page {
            ProofForm(ProofAccount(hasPassword = true, twoFactor = false, email = null), "Continue", {
                AccountProblem.InvalidOtp(missing = true)
            }, onDone = {})
        }
        assertTrue(compose.onAllNodesWithText("Authenticator or recovery code").fetchSemanticsNodes().isEmpty())
        type("Password", "my password 2026")
        click("Continue")
        waitFor("Two-step sign-in is on: enter the code from your authenticator app too.")
        assertTrue(seen("Authenticator or recovery code"))
    }

    @Test
    fun anAccountWithNeitherPasswordNorTwoStepProvesWithAnEmailedCode() {
        server.on("POST", "/api/account/proof-code", FakeServer.empty(202))
        var sent: Proof? = null
        page {
            ProofForm(ProofAccount(hasPassword = false, twoFactor = false, email = "ayesha@bilaltraders.pk"), "Continue", { p ->
                sent = p
                null
            }, onDone = {})
        }
        assertTrue(seen("ayesha@bilaltraders.pk"))
        click("Send code")
        pumpUntil("the code is asked for") { server.requestsTo("POST", "/api/account/proof-code").isNotEmpty() }
        waitFor("Code sent. Check your email.")
        assertTrue(seen("Send again"))
        type("Code from the email", "123456")
        click("Continue")
        pumpUntil("sent") { sent != null }
        assertEquals(Proof(emailCode = "123456"), sent)
    }

    @Test
    fun tooManyTriesSaysHowLongToWait() {
        page {
            ProofForm(ProofAccount(true, false, null), "Continue", { AccountProblem.TooManyAttempts(840) }, onDone = {})
        }
        type("Password", "x")
        click("Continue")
        waitFor("Too many tries. Try again in 14 minutes.")
    }

    // ---- email -----------------------------------------------------------------------------------------------------

    @Test
    fun anEmailChangeGoesThroughProofToTheCodeAndEndsWhenConfirmed() {
        server.on("POST", "/api/account/email", answer(202, sample("account_email")))
        server.on(
            "GET",
            "/api/account",
            answer(200, sample("account").replace("\"pending_email\": null", "\"pending_email\": \"sample.new@example.test\""))
        )
        server.on("POST", "/api/account/email/confirm", answer(200, sample("account_email_confirm")))
        var shown by mutableStateOf(account)
        page { EmailPage(shown, ProofAccount(true, false, shown.email), { shown = it }, onBack = {}) }
        type("New email", "ayesha@")
        click("Continue")
        assertTrue(seen("This doesn't look like an email address."))
        assertTrue("nothing sent for a mistyped address", server.requestsTo("POST", "/api/account/email").isEmpty())
        compose.onAllNodes(hasSetTextAction()).onFirst().performTextReplacement("sample.new@example.test")
        compose.waitForIdle()
        click("Continue")
        waitFor("Enter your password to continue.")
        type("Password", "my password 2026")
        clickLast("Continue")
        waitFor("Check your new address")
        val body = ApiJson.parseToJsonElement(server.requestsTo("POST", "/api/account/email").single().bodyText()).jsonObject
        assertEquals("sample.new@example.test", body["email"]!!.jsonPrimitive.content)
        assertEquals("my password 2026", body["password"]!!.jsonPrimitive.content)
        assertTrue(seen("We sent a 6-digit code to"))
        type("Code from the email", "123456")
        click("Confirm")
        pumpUntil("confirmed") { shown.email == "sample.new@example.test" }
        assertNull(shown.pendingEmail)
        waitFor("Your email is now")
    }

    @Test
    fun theCheckScreenNoticesALinkOpenedInABrowser() {
        server.on("GET", "/api/account", answer(200, sample("account_email_confirm")))
        var shown by mutableStateOf(
            account.copy(pendingEmail = "sample.new@example.test", pendingEmailExpiresAt = "2026-11-21T17:00:46+00:00")
        )
        page { EmailPage(shown, ProofAccount(true, false, shown.email), { shown = it }, onBack = {}) }
        pumpUntil("the account is read") { server.requestsTo("GET", "/api/account").isNotEmpty() }
        pumpUntil("the change is seen") { shown.pendingEmail == null }
        assertEquals("sample.new@example.test", shown.email)
    }

    @Test
    fun aLockedEmailSaysUntilWhen() {
        server.on(
            "POST",
            "/api/account/email",
            answer(409, """{"message":"x","code":"email_change_locked","until":"2026-11-28T16:00:46+00:00"}""")
        )
        page { EmailPage(account, ProofAccount(true, false, account.email), {}, onBack = {}) }
        type("New email", "new@bilaltraders.pk")
        click("Continue")
        type("Password", "pw")
        clickLast("Continue")
        waitFor("You can change it again after")
        assertTrue(seen("2026") && seen("28") && seen("Nov"))
    }

    @Test
    fun aWrongCodeAndAnOldOneAreSaidUnderTheField() {
        server.on("GET", "/api/account", answer(200, sample("account").replace("\"pending_email\": null", "\"pending_email\": \"n@x.pk\"")))
        server.on("POST", "/api/account/email/confirm", answer(422, """{"message":"x","code":"invalid_code"}"""))
        page { EmailPage(account.copy(pendingEmail = "n@x.pk"), ProofAccount(true, false, account.email), {}, onBack = {}) }
        type("Code from the email", "000000")
        click("Confirm")
        waitFor("That code isn't right. Check the email and try again.")
        server.on("POST", "/api/account/email/confirm", answer(410, """{"message":"x"}"""))
        click("Confirm")
        waitFor("This code has expired.")
    }

    // ---- password --------------------------------------------------------------------------------------------------

    @Test
    fun thePasswordIsCheckedAgainstTheRulesAndSignsOutOthersByDefault() {
        server.on("PUT", "/api/account/password", FakeServer.empty(204))
        var done = false
        page { PasswordPage(account, PasswordRulesDto(12, true, true), onDone = { done = true }, onBack = {}) }
        assertTrue(seen("At least 12 characters"))
        type("Current password", "old password 1")
        type("New password", "short")
        button("Change password").assertIsNotEnabled()
        assertTrue(compose.onAllNodesWithContentDescription("At least 12 characters, not yet").fetchSemanticsNodes().isNotEmpty())
        compose.onAllNodes(hasSetTextAction())[1].performTextInput(" and long 2026")
        compose.waitForIdle()
        assertTrue(compose.onAllNodesWithContentDescription("At least one number, done").fetchSemanticsNodes().isNotEmpty())
        click("Change password")
        pumpUntil("changed") { done }
        val body = ApiJson.parseToJsonElement(server.requestsTo("PUT", "/api/account/password").single().bodyText()).jsonObject
        assertEquals("old password 1", body["current"]!!.jsonPrimitive.content)
        assertEquals("short and long 2026", body["new"]!!.jsonPrimitive.content)
        assertEquals("true", body["sign_out_others"]!!.jsonPrimitive.content)
    }

    @Test
    fun theServersPasswordCodesAreSaidInPlainWords() {
        page { PasswordPage(account.copy(twoFactor = true), null, onDone = {}, onBack = {}) }
        type("Current password", "old password 1")
        type("New password", "a new password 2026")
        type("Code from your authenticator app", "123456")
        server.on("PUT", "/api/account/password", answer(422, """{"message":"x","code":"wrong_password"}"""))
        click("Change password")
        waitFor("That password isn't right.")
        server.on(
            "PUT",
            "/api/account/password",
            answer(422, """{"message":"x","code":"validation_failed","error_codes":{"new":[{"code":"same_as_current"}]}}""")
        )
        click("Change password")
        waitFor("Choose a new password, not the one you have now.")
        server.on("PUT", "/api/account/password", answer(422, """{"message":"x","code":"invalid_otp"}"""))
        click("Change password")
        waitFor("That code isn't right. Check the app and try again.")
        val body = ApiJson.parseToJsonElement(server.requestsTo("PUT", "/api/account/password").last().bodyText()).jsonObject
        assertEquals("123456", body["otp"]!!.jsonPrimitive.content)
    }

    @Test
    fun aGoogleAccountSetsAFirstPasswordWithAnEmailedCode() {
        server.on("POST", "/api/account/proof-code", FakeServer.empty(202))
        server.on("PUT", "/api/account/password", FakeServer.empty(204))
        var done = false
        page { PasswordPage(account.copy(hasPassword = false), null, onDone = { done = true }, onBack = {}) }
        assertTrue(seen("You sign in with Google now."))
        assertTrue(compose.onAllNodesWithText("Current password").fetchSemanticsNodes().isEmpty())
        type("New password", "a new password 2026")
        click("Set password")
        click("Send code")
        type("Code from the email", "123456")
        clickLast("Set password")
        pumpUntil("set") { done }
        val body = ApiJson.parseToJsonElement(server.requestsTo("PUT", "/api/account/password").single().bodyText()).jsonObject
        assertEquals("123456", body["email_code"]!!.jsonPrimitive.content)
        assertTrue(body["current"].toString() == "null")
    }

    // ---- two-step --------------------------------------------------------------------------------------------------

    @Test
    fun twoStepIsTurnedOnAndItsRecoveryCodesMustBeSaved() {
        server.on("POST", "/api/account/two-factor", answer(200, sample("account_two_factor").replace("sample-secret", "JBSWY3DPEHPK3PXP")))
        server.on(
            "POST",
            "/api/account/two-factor/confirm",
            answer(
                200,
                """{"recovery_codes":["aaaa-1111","bbbb-2222","cccc-3333","dddd-4444","eeee-5555","ffff-6666","gggg-7777","hhhh-8888"]}"""
            )
        )
        var changed = 0
        page { TwoStepPage(account, null, onChanged = { changed++ }, onBack = {}) }
        click("Turn on two-step sign-in")
        type("Password", "my password 2026")
        click("Continue")
        waitFor("Open in authenticator app")
        assertTrue(compose.onAllNodesWithContentDescription("QR code for your authenticator app").fetchSemanticsNodes().isNotEmpty())
        assertTrue(seen("JBSW Y3DP EHPK 3PXP"))
        assertTrue(compose.onAllNodesWithContentDescription("Key: J B S W Y 3 D P E H P K 3 P X P").fetchSemanticsNodes().isNotEmpty())
        // No authenticator app on the phone: it says so instead of failing.
        org.robolectric.Shadows.shadowOf(app).checkActivities(true)
        click("Open in authenticator app")
        waitFor("No authenticator app was found.")
        type("6-digit code", "123456")
        click("Turn on")
        waitFor("Save your recovery codes")
        assertTrue(seen("aaaa-1111"))
        assertTrue(compose.onAllNodesWithContentDescription("Code 1: a a a a - 1 1 1 1").fetchSemanticsNodes().isNotEmpty())
        button("Done").assertIsNotEnabled()
        click("I saved them")
        button("Done").assertIsEnabled()
        click("Done")
        assertEquals(1, changed)
        val confirm = ApiJson.parseToJsonElement(
            server.requestsTo("POST", "/api/account/two-factor/confirm").single().bodyText()
        ).jsonObject
        assertEquals("123456", confirm["code"]!!.jsonPrimitive.content)
    }

    @Test
    fun theKeyAndTheRecoveryCodesAreCoveredAndTheCodesOutliveARotation() {
        server.on("POST", "/api/account/two-factor", answer(200, sample("account_two_factor").replace("sample-secret", "JBSWY3DPEHPK3PXP")))
        server.on(
            "POST",
            "/api/account/two-factor/confirm",
            answer(
                200,
                """{"recovery_codes":["aaaa-1111","bbbb-2222","cccc-3333","dddd-4444","eeee-5555","ffff-6666","gggg-7777","hhhh-8888"]}"""
            )
        )
        var window: android.view.Window? = null
        // A configuration change: everything the page remembered in its composition is gone, its ViewModel is not.
        var generation by mutableStateOf(0)
        page {
            window = (androidx.compose.ui.platform.LocalContext.current as android.app.Activity).window
            androidx.compose.runtime.key(generation) { TwoStepPage(account, null, onChanged = {}, onBack = {}) }
        }
        fun covered() = window!!.attributes.flags and android.view.WindowManager.LayoutParams.FLAG_SECURE != 0
        assertFalse(covered())
        click("Turn on two-step sign-in")
        type("Password", "my password 2026")
        click("Continue")
        waitFor("Open in authenticator app")
        assertTrue(covered())
        type("6-digit code", "123456")
        click("Turn on")
        waitFor("Save your recovery codes")
        assertTrue(covered())
        generation++
        compose.waitForIdle()
        assertTrue(seen("Save your recovery codes"))
        assertTrue(seen("aaaa-1111"))
        assertTrue(seen("hhhh-8888"))
        assertTrue(covered())
        button("Done").assertIsNotEnabled()
        click("I saved them")
        click("Done")
        waitFor("Turn on two-step sign-in")
        assertFalse(covered())
    }

    @Test
    fun aSignOutForgetsTheKeyAndTheCodesSoTheSameEmailSigningInAgainStartsAtTheOverview() {
        server.on("POST", "/api/account/two-factor", answer(200, sample("account_two_factor").replace("sample-secret", "JBSWY3DPEHPK3PXP")))
        server.on(
            "POST",
            "/api/account/two-factor/confirm",
            answer(
                200,
                """{"recovery_codes":["aaaa-1111","bbbb-2222","cccc-3333","dddd-4444","eeee-5555","ffff-6666","gggg-7777","hhhh-8888"]}"""
            )
        )
        // The page as it is opened again after signing in: its composition new, the ViewModel the same (keyed by email).
        var generation by mutableStateOf(0)
        page { androidx.compose.runtime.key(generation) { TwoStepPage(account, null, onChanged = {}, onBack = {}) } }
        click("Turn on two-step sign-in")
        type("Password", "my password 2026")
        click("Continue")
        waitFor("Open in authenticator app")
        type("6-digit code", "123456")
        click("Turn on")
        waitFor("Save your recovery codes")
        assertTrue(seen("aaaa-1111"))
        // Signed out (the pairing cleared), then the same email back on this phone.
        runBlocking { app.graph.settings.clearPairing() }
        generation++
        compose.waitForIdle()
        waitFor("Turn on two-step sign-in")
        assertFalse("the codes are gone", seen("aaaa-1111"))
        assertFalse(seen("JBSW Y3DP EHPK 3PXP"))
        // While still signed in, a new page (a rotation) keeps them, as before.
        click("Turn on two-step sign-in")
        type("Password", "my password 2026")
        click("Continue")
        waitFor("Open in authenticator app")
        generation++
        compose.waitForIdle()
        assertTrue("the key outlives a rotation", seen("JBSW Y3DP EHPK 3PXP"))
    }

    @Test
    fun turningTwoStepOffTakesACodeAndStaffKeepIt() {
        server.on("DELETE", "/api/account/two-factor", answer(409, """{"message":"x","code":"required_for_staff"}"""))
        page { TwoStepPage(account.copy(twoFactor = true), 7, onChanged = {}, onBack = {}) }
        assertTrue(seen("Recovery codes left: 7"))
        click("Turn off two-step sign-in")
        assertTrue(compose.onAllNodesWithText("Password").fetchSemanticsNodes().isEmpty())
        type("Authenticator or recovery code", "123456")
        clickLast("Turn off two-step sign-in")
        waitFor("Staff accounts must keep two-step sign-in on.")
        val body = ApiJson.parseToJsonElement(server.requestsTo("DELETE", "/api/account/two-factor").single().bodyText()).jsonObject
        assertEquals("123456", body["otp"]!!.jsonPrimitive.content)
    }

    // ---- sign-in methods -------------------------------------------------------------------------------------------

    private val security = AccountSecurityDto(
        password = true,
        google = GoogleLinkDto(linked = true),
        passkeys = listOf(com.echoledger.mobile.data.PasskeyDto("p1", "Pixel 9", "2026-11-01T10:00:00+00:00")),
        browsers = listOf(BrowserDto("b1", "Chrome on macOS", "PK", "2026-11-21T10:00:00+00:00")),
        devices = listOf(
            AccountDeviceDto("d1", "Galaxy S25", "android", "2.0.0", current = true, workspace = DeviceWorkspaceDto(1, "Personal")),
            AccountDeviceDto("d2", "Work laptop", "windows", "1.0.0", workspace = DeviceWorkspaceDto(2, "Bilal Traders"))
        )
    )

    @Test
    fun withoutPhonePasskeysTheWebsiteManagesThem() {
        page { SignInMethodsPage(account.copy(passkeysAvailable = false), security, server.baseUrl, onChanged = {}, onBack = {}) }
        assertTrue(seen("Manage passkeys on the website"))
        assertTrue(compose.onAllNodesWithText("Add a passkey").fetchSemanticsNodes().isEmpty())
        assertTrue(seen("Pixel 9"))
    }

    @Test
    fun aPasskeyIsMadeWithCredentialManagerAndSaved() {
        server.on("POST", "/api/account/passkeys/options", answer(200, sample("account_passkey_options")))
        server.on("POST", "/api/account/passkeys", answer(201, sample("account_passkey")))
        var optionsSeen: String? = null
        val maker = PasskeyMaker { _: Context, options ->
            optionsSeen = options
            PasskeyOutcome.Made(ApiJson.parseToJsonElement("""{"id":"cred","type":"public-key","response":{}}""").jsonObject)
        }
        var changed = 0
        page { SignInMethodsPage(account, security, server.baseUrl, onChanged = { changed++ }, onBack = {}, maker = maker) }
        click("Add a passkey")
        type("Password", "pw")
        click("Continue")
        pumpUntil("saved") { server.requestsTo("POST", "/api/account/passkeys").isNotEmpty() }
        assertTrue(optionsSeen!!.contains("\"challenge\""))
        val saved = ApiJson.parseToJsonElement(server.requestsTo("POST", "/api/account/passkeys").single().bodyText()).jsonObject
        assertEquals("2T9YTik8IpKgIbXpwkGsBhD3xB2Pgyv9YdQwSqW6", saved["ceremony"]!!.jsonPrimitive.content)
        assertEquals("cred", saved["credential"]!!.jsonObject["id"]!!.jsonPrimitive.content)
        pumpUntil("reloaded") { changed == 1 }
    }

    @Test
    fun aPhoneWithoutAPasskeyManagerIsSentToTheWebsite() {
        server.on("POST", "/api/account/passkeys/options", answer(200, sample("account_passkey_options")))
        page {
            SignInMethodsPage(account, security, server.baseUrl, onChanged = {}, onBack = {}, maker = { _, _ -> PasskeyOutcome.NoProvider })
        }
        click("Add a passkey")
        type("Password", "pw")
        click("Continue")
        waitFor("This phone has no passkey manager.")
        assertTrue(seen("Manage passkeys on the website"))
    }

    @Test
    fun unlinkingTheLastWayInIsRefusedInPlainWords() {
        server.on("DELETE", "/api/account/google", answer(409, """{"message":"x","code":"last_sign_in_method"}"""))
        page { SignInMethodsPage(account, security, server.baseUrl, onChanged = {}, onBack = {}) }
        assertTrue(seen("Linked"))
        click("Unlink Google")
        type("Password", "pw")
        clickLast("Unlink Google")
        waitFor("This is your last way to sign in.")
    }

    // ---- browsers and devices --------------------------------------------------------------------------------------

    @Test
    fun browsersAndOtherDevicesSignOutButNotThisPhone() {
        server.on("DELETE", "/api/account/devices/d2", FakeServer.empty(204))
        server.on("DELETE", "/api/account/browsers/b1", FakeServer.empty(204))
        var changed = 0
        page { SessionsPage(security, onChanged = { changed++ }, onBack = {}) }
        assertTrue(seen("Chrome on macOS"))
        assertTrue(seen("This phone"))
        assertTrue(seen("Bilal Traders"))
        assertTrue(compose.onAllNodesWithContentDescription("Sign out Galaxy S25").fetchSemanticsNodes().isEmpty())
        compose.onNodeWithContentDescription("Sign out Work laptop").performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
        clickLast("Sign out")
        pumpUntil("signed out") { server.requestsTo("DELETE", "/api/account/devices/d2").isNotEmpty() }
        compose.onNodeWithContentDescription("Sign out Chrome on macOS").performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
        clickLast("Sign out")
        pumpUntil("browser signed out") { server.requestsTo("DELETE", "/api/account/browsers/b1").isNotEmpty() }
        pumpUntil("reloaded") { changed == 2 }
    }

    // ---- the hub, in Urdu ------------------------------------------------------------------------------------------

    @Test
    fun theHubReadsInUrduWithTheAddressKeptWhole() {
        var opened: AccountRoute? = null
        page(EchoLanguage.Urdu) {
            AccountHubRows(account.copy(twoFactor = true, pendingEmail = "n@x.pk"), security, lit = false, onOpen = {
                opened = it
            }, onYourData = {})
        }
        assertTrue(seen("دو مرحلوں والا سائن ان"))
        assertTrue(seen("انتظار میں"))
        assertTrue(seen("⁦ayesha@bilaltraders.pk⁩", substring = false))
        click("پاس ورڈ")
        assertEquals(AccountRoute.Password, opened)
    }
}
